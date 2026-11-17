<?php

namespace App\Services\Discovery\Connectors;

use App\Models\JobSource;
use App\Services\Discovery\NormalizedJob;
use App\Services\Support\HttpFetcher;
use App\Services\Support\RobotsTxt;
use App\Services\Support\SalaryParser;
use App\Services\Support\TextNormalizer;
use App\Services\Support\UrlCanonicalizer;

/**
 * Company career page connector. Reads schema.org JobPosting structured data (JSON-LD) that
 * employers publish for search engines. It:
 *  - checks robots.txt before every page request,
 *  - stops on CAPTCHA / bot-challenge pages (never attempts a bypass),
 *  - optionally follows same-host job links matching config.follow_pattern (bounded by config.max_pages).
 * Pages that render jobs only with JavaScript or private APIs are recorded as a limitation.
 */
class CareerPageConnector extends BaseConnector
{
    public function __construct(HttpFetcher $http, private RobotsTxt $robots)
    {
        parent::__construct($http);
    }

    public function type(): string
    {
        return 'career_page';
    }

    public function fetch(JobSource $source, int $limit): iterable
    {
        $url = trim($source->identifier);
        if (! UrlCanonicalizer::isSafePublicUrl($url)) {
            throw new ConnectorException('Career page URL is not a public http(s) URL.', 'config', true);
        }

        $html = $this->fetchPage($url);
        $postings = $this->extractPostings($html, $url);

        $pattern = $source->config['follow_pattern'] ?? null;
        $maxPages = min((int) ($source->config['max_pages'] ?? 25), 100);
        if ($pattern && count($postings) < $limit) {
            foreach (array_slice($this->extractLinks($html, $url, $pattern), 0, $maxPages) as $link) {
                try {
                    $postings = array_merge($postings, $this->extractPostings($this->fetchPage($link), $link));
                } catch (ConnectorException $e) {
                    if ($e->kind === 'blocked') {
                        throw $e;
                    }
                }
                if (count($postings) >= $limit) {
                    break;
                }
            }
        }

        if (! $postings) {
            throw new ConnectorException(
                'No schema.org JobPosting data found. The page probably loads jobs with JavaScript or a private API; '
                .'add the employer\'s public ATS board instead if one exists.',
                'parse',
                true,
            );
        }

        $seen = [];
        foreach (array_slice($postings, 0, $limit) as $posting) {
            $job = $this->map($posting, $source);
            if ($job && ! isset($seen[$job->externalId])) {
                $seen[$job->externalId] = true;
                yield $job;
            }
        }
    }

    private function fetchPage(string $url): string
    {
        if (! $this->robots->isAllowed($url, config('jobhunter.http.user_agent'))) {
            throw new ConnectorException("robots.txt disallows automated access to {$url}; skipped.", 'robots', true);
        }

        try {
            $response = $this->http->getHtml($url);
        } catch (\Throwable $e) {
            throw new ConnectorException('Request failed: '.$e->getMessage(), 'http', false, $e);
        }

        if (in_array($response->status(), [401, 403, 429], true)) {
            throw new ConnectorException("Access restricted (HTTP {$response->status()}) at {$this->host($url)}; skipped without bypass.", 'blocked', $response->status() !== 429);
        }
        if (! $response->successful()) {
            throw new ConnectorException("HTTP {$response->status()} from {$this->host($url)}.", 'http', $response->status() === 404);
        }

        $body = $response->body();
        if (preg_match('/(g-recaptcha|h-captcha|cf-challenge|challenge-platform|captcha-delivery|px-captcha|Attention Required! \| Cloudflare)/i', $body)) {
            throw new ConnectorException("Bot challenge / CAPTCHA detected at {$this->host($url)}; skipped (no bypass attempted).", 'blocked', true);
        }

        return $body;
    }

    /** @return list<array> */
    public function extractPostings(string $html, string $pageUrl): array
    {
        $found = [];
        if (! preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $matches)) {
            return [];
        }

        foreach ($matches[1] as $block) {
            $data = json_decode(html_entity_decode(trim($block)), true);
            if (! is_array($data)) {
                continue;
            }
            $stack = [$data];
            while ($stack) {
                $node = array_pop($stack);
                if (! is_array($node)) {
                    continue;
                }
                $type = $node['@type'] ?? null;
                if ($type === 'JobPosting' || (is_array($type) && in_array('JobPosting', $type, true))) {
                    $node['_page_url'] = $pageUrl;
                    $found[] = $node;

                    continue;
                }
                foreach ($node as $child) {
                    if (is_array($child)) {
                        $stack[] = $child;
                    }
                }
            }
        }

        return $found;
    }

    /** @return list<string> */
    private function extractLinks(string $html, string $baseUrl, string $pattern): array
    {
        $baseHost = parse_url($baseUrl, PHP_URL_HOST);
        $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';
        preg_match_all('#href=["\']([^"\'\#]+)["\']#i', $html, $m);
        $links = [];
        foreach ($m[1] as $href) {
            $href = html_entity_decode($href);
            if (str_starts_with($href, '//')) {
                $href = $scheme.':'.$href;
            } elseif (str_starts_with($href, '/')) {
                $href = $scheme.'://'.$baseHost.$href;
            } elseif (! preg_match('#^https?://#i', $href)) {
                continue;
            }
            if (parse_url($href, PHP_URL_HOST) !== $baseHost) {
                continue;
            }
            if (@preg_match('#'.str_replace('#', '\#', $pattern).'#i', $href)) {
                $links[$href] = true;
            }
        }

        return array_keys($links);
    }

    private function map(array $p, JobSource $source): ?NormalizedJob
    {
        $title = trim(strip_tags((string) ($p['title'] ?? '')));
        if ($title === '') {
            return null;
        }
        $url = $p['url'] ?? $p['_page_url'];
        if (! UrlCanonicalizer::isSafePublicUrl($url)) {
            $url = $p['_page_url'];
        }

        $identifier = $p['identifier'] ?? null;
        $externalId = is_array($identifier) ? (string) ($identifier['value'] ?? '') : (string) $identifier;
        if ($externalId === '') {
            $externalId = substr(hash('sha256', UrlCanonicalizer::canonicalize($url).'|'.$title), 0, 40);
        }

        $locations = [];
        $country = null;
        $jobLocations = $p['jobLocation'] ?? [];
        if (isset($jobLocations['@type']) || isset($jobLocations['address'])) {
            $jobLocations = [$jobLocations];
        }
        foreach ((array) $jobLocations as $loc) {
            $addr = $loc['address'] ?? [];
            if (is_string($addr)) {
                $locations[] = $addr;

                continue;
            }
            $c = $addr['addressCountry'] ?? null;
            $country ??= is_array($c) ? ($c['name'] ?? null) : $c;
            $locations[] = implode(', ', array_filter([$addr['addressLocality'] ?? null, $addr['addressRegion'] ?? null, is_string($c) ? $c : null]));
        }
        $isRemote = str_contains(strtoupper(json_encode($p['jobLocationType'] ?? '')), 'TELECOMMUTE');

        $salary = null;
        $base = $p['baseSalary'] ?? null;
        if (is_array($base) && isset($base['value'])) {
            $v = $base['value'];
            $min = is_array($v) ? ($v['minValue'] ?? $v['value'] ?? null) : $v;
            $max = is_array($v) ? ($v['maxValue'] ?? $v['value'] ?? null) : $v;
            $unit = strtoupper(is_array($v) ? ($v['unitText'] ?? 'YEAR') : ($base['unitText'] ?? 'YEAR'));
            if (is_numeric($min) && is_numeric($max)) {
                $salary = [
                    'min' => (int) $min, 'max' => (int) $max, 'currency' => $base['currency'] ?? 'USD',
                    'interval' => $unit === 'HOUR' ? 'hour' : ($unit === 'MONTH' ? 'month' : 'year'), 'text' => null,
                ];
            }
        }
        $text = TextNormalizer::htmlToText($p['description'] ?? '');
        $salary ??= SalaryParser::parse($text);
        $org = $p['hiringOrganization'] ?? [];

        return new NormalizedJob(
            externalId: $externalId,
            title: $title,
            companyName: (is_array($org) ? ($org['name'] ?? null) : $org) ?: ($source->company?->name ?? 'Unknown'),
            sourceUrl: $url,
            applicationUrl: $p['directApplyUrl'] ?? $url,
            location: implode(' | ', array_filter($locations)) ?: ($isRemote ? 'Remote' : null),
            workplaceHint: $isRemote ? 'remote' : null,
            remoteFlag: $isRemote ?: null,
            country: $country,
            employmentType: is_array($p['employmentType'] ?? null) ? implode(', ', $p['employmentType']) : ($p['employmentType'] ?? null),
            descriptionHtml: TextNormalizer::sanitizeHtml($p['description'] ?? ''),
            descriptionText: $text,
            salaryMin: $salary['min'] ?? null,
            salaryMax: $salary['max'] ?? null,
            salaryCurrency: $salary['currency'] ?? null,
            salaryInterval: $salary['interval'] ?? null,
            salaryText: $salary['text'] ?? null,
            postedAt: $this->parseDate($p['datePosted'] ?? null),
            raw: array_diff_key($p, ['description' => 1]),
        );
    }

    public function probe(string $identifier): ?int
    {
        return null; // career pages are added explicitly, never probed
    }
}

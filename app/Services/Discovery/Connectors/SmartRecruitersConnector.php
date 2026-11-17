<?php

namespace App\Services\Discovery\Connectors;

use App\Models\JobSource;
use App\Services\Discovery\NormalizedJob;
use App\Services\SettingsService;
use App\Services\Support\HttpFetcher;
use App\Services\Support\SalaryParser;
use App\Services\Support\TextNormalizer;

/** SmartRecruiters public Posting API (documented): https://developers.smartrecruiters.com/docs/posting-api */
class SmartRecruitersConnector extends BaseConnector
{
    public const BASE = 'https://api.smartrecruiters.com/v1/companies/';

    private const PAGE_SIZE = 100;

    public function __construct(HttpFetcher $http, private SettingsService $settings)
    {
        parent::__construct($http);
    }

    /** Detail requests are one call per posting, so only fetch them for plausibly relevant titles. */
    private function wantsDetail(string $title): bool
    {
        $t = strtolower($title);
        foreach (array_merge($this->settings->get('title_keywords', []), $this->settings->get('preferred_titles', [])) as $kw) {
            if ($kw !== '' && str_contains($t, strtolower($kw))) {
                return true;
            }
        }

        return false;
    }

    public function type(): string
    {
        return 'smartrecruiters';
    }

    public function fetch(JobSource $source, int $limit): iterable
    {
        $company = rawurlencode(trim($source->identifier));
        $detailLimit = (int) ($source->config['detail_limit'] ?? 300);
        $details = 0;
        $offset = 0;
        $yielded = 0;

        while ($yielded < $limit) {
            $data = $this->getJson(self::BASE.$company.'/postings', ['limit' => self::PAGE_SIZE, 'offset' => $offset]);
            $items = $data['content'] ?? null;
            if (! is_array($items)) {
                throw new ConnectorException('Unexpected SmartRecruiters payload (missing "content").', 'parse');
            }
            if ($offset === 0 && ($data['totalFound'] ?? 0) === 0) {
                return; // valid company identifier with zero openings, or unknown identifier
            }

            foreach ($items as $item) {
                if ($yielded >= $limit) {
                    break;
                }
                if (empty($item['id']) || empty($item['name'])) {
                    continue;
                }

                $detail = [];
                if ($details < $detailLimit && $this->wantsDetail($item['name'])) {
                    $details++;
                    try {
                        $detail = $this->getJson(self::BASE.$company.'/postings/'.rawurlencode($item['id']));
                    } catch (ConnectorException $e) {
                        $detail = [];
                    }
                }
                $yielded++;
                yield $this->map($item, $detail, $source);
            }

            $offset += self::PAGE_SIZE;
            if ($offset >= ($data['totalFound'] ?? 0)) {
                break;
            }
        }
    }

    private function map(array $item, array $detail, JobSource $source): NormalizedJob
    {
        $sections = $detail['jobAd']['sections'] ?? [];
        $html = '';
        foreach (['jobDescription', 'qualifications', 'additionalInformation', 'companyDescription'] as $key) {
            if (! empty($sections[$key]['text'])) {
                $html .= '<h3>'.e($sections[$key]['title'] ?? '').'</h3>'.$sections[$key]['text'];
            }
        }
        $text = TextNormalizer::htmlToText($html);
        $salary = SalaryParser::parse($text);
        $loc = $item['location'] ?? [];
        $location = $loc['fullLocation'] ?? implode(', ', array_filter([$loc['city'] ?? null, $loc['region'] ?? null, $loc['country'] ?? null]));
        $workplace = ($loc['remote'] ?? false) ? 'remote' : (($loc['hybrid'] ?? false) ? 'hybrid' : null);
        $companyId = $item['company']['identifier'] ?? $source->identifier;
        $url = $detail['postingUrl'] ?? ('https://jobs.smartrecruiters.com/'.$companyId.'/'.$item['id']);

        return new NormalizedJob(
            externalId: (string) $item['id'],
            title: trim($item['name']),
            companyName: $item['company']['name'] ?? ($source->company?->name ?? $source->identifier),
            sourceUrl: $url,
            applicationUrl: $detail['applyUrl'] ?? $url,
            location: $location ?: null,
            workplaceHint: $workplace,
            remoteFlag: $loc['remote'] ?? null,
            country: $loc['country'] ?? null,
            employmentType: $item['typeOfEmployment']['label'] ?? null,
            department: $item['department']['label'] ?? null,
            descriptionHtml: TextNormalizer::sanitizeHtml($html),
            descriptionText: $text,
            salaryMin: $salary['min'] ?? null,
            salaryMax: $salary['max'] ?? null,
            salaryCurrency: $salary['currency'] ?? null,
            salaryInterval: $salary['interval'] ?? null,
            salaryText: $salary['text'] ?? null,
            postedAt: $this->parseDate($item['releasedDate'] ?? null),
            raw: $item,
        );
    }
}

<?php

namespace App\Services\Discovery\Connectors;

use App\Models\JobSource;
use App\Services\Discovery\NormalizedJob;
use App\Services\Support\SalaryParser;
use App\Services\Support\TextNormalizer;

/** Ashby public Job Posting API (documented): https://developers.ashbyhq.com/docs/public-job-posting-api */
class AshbyConnector extends BaseConnector
{
    public const BASE = 'https://api.ashbyhq.com/posting-api/job-board/';

    public function type(): string
    {
        return 'ashby';
    }

    public function fetch(JobSource $source, int $limit): iterable
    {
        $board = rawurlencode(trim($source->identifier));
        $data = $this->getJson(self::BASE.$board, ['includeCompensation' => 'true']);
        if (! isset($data['jobs']) || ! is_array($data['jobs'])) {
            throw new ConnectorException('Unexpected Ashby payload (missing "jobs").', 'parse');
        }

        $count = 0;
        foreach ($data['jobs'] as $job) {
            if (($job['isListed'] ?? true) === false) {
                continue;
            }
            if ($count >= $limit) {
                break;
            }
            if (empty($job['id']) || empty($job['title']) || empty($job['jobUrl'])) {
                continue;
            }
            $count++;

            $text = $job['descriptionPlain'] ?? TextNormalizer::htmlToText($job['descriptionHtml'] ?? '');
            $salary = $this->salary($job['compensation'] ?? null) ?? SalaryParser::parse($text);

            $locations = array_filter(array_merge(
                [$job['location'] ?? null],
                array_map(fn ($l) => is_array($l) ? ($l['location'] ?? null) : $l, $job['secondaryLocations'] ?? [])
            ));
            $country = $job['address']['postalAddress']['addressCountry'] ?? null;

            yield new NormalizedJob(
                externalId: (string) $job['id'],
                title: trim($job['title']),
                companyName: $source->company?->name ?? $source->identifier,
                sourceUrl: $job['jobUrl'],
                applicationUrl: $job['applyUrl'] ?? $job['jobUrl'],
                location: implode(' | ', array_unique($locations)) ?: null,
                workplaceHint: $job['workplaceType'] ?? null,
                remoteFlag: $job['isRemote'] ?? null,
                country: $country,
                employmentType: $job['employmentType'] ?? null,
                department: $job['department'] ?? ($job['team'] ?? null),
                descriptionHtml: TextNormalizer::sanitizeHtml($job['descriptionHtml'] ?? ''),
                descriptionText: $text,
                salaryMin: $salary['min'] ?? null,
                salaryMax: $salary['max'] ?? null,
                salaryCurrency: $salary['currency'] ?? null,
                salaryInterval: $salary['interval'] ?? null,
                salaryText: $salary['text'] ?? null,
                postedAt: $this->parseDate($job['publishedAt'] ?? null),
                raw: array_diff_key($job, ['descriptionHtml' => 1]),
            );
        }
    }

    private function salary(?array $comp): ?array
    {
        foreach ($comp['summaryComponents'] ?? [] as $c) {
            if (($c['compensationType'] ?? '') === 'Salary' && ! empty($c['minValue']) && ! empty($c['maxValue'])) {
                $interval = str_contains(strtoupper($c['interval'] ?? ''), 'HOUR') ? 'hour' : 'year';

                return [
                    'min' => (int) $c['minValue'], 'max' => (int) $c['maxValue'],
                    'currency' => $c['currencyCode'] ?? 'USD', 'interval' => $interval,
                    'text' => $comp['scrapeableCompensationSalarySummary'] ?? ($comp['compensationTierSummary'] ?? null),
                ];
            }
        }

        return null;
    }
}

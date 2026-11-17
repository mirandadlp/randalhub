<?php

namespace App\Services\Discovery\Connectors;

use App\Models\JobSource;
use App\Services\Discovery\NormalizedJob;
use App\Services\SettingsService;
use App\Services\Support\HttpFetcher;

/**
 * Adzuna job search API (https://developer.adzuna.com/). Requires ADZUNA_APP_ID / ADZUNA_APP_KEY.
 * Aggregator listings are marked as not direct-from-employer, and Adzuna's *predicted* salaries
 * are discarded because they are estimates, not employer data.
 * Source identifier = search keywords; config: {where, max_days_old, pages}.
 */
class AdzunaConnector extends BaseConnector
{
    public const BASE = 'https://api.adzuna.com/v1/api/jobs/us/search/';

    public function __construct(HttpFetcher $http, private SettingsService $settings)
    {
        parent::__construct($http);
    }

    public function type(): string
    {
        return 'adzuna';
    }

    public function fetch(JobSource $source, int $limit): iterable
    {
        $creds = $this->settings->adzunaCredentials();
        if (! $creds) {
            throw new ConnectorException('Adzuna credentials are not configured (ADZUNA_APP_ID / ADZUNA_APP_KEY).', 'config', true);
        }

        $pages = min((int) ($source->config['pages'] ?? 3), 10);
        $yielded = 0;
        for ($page = 1; $page <= $pages && $yielded < $limit; $page++) {
            $query = array_filter([
                'app_id' => $creds['app_id'],
                'app_key' => $creds['app_key'],
                'what' => $source->identifier,
                'where' => $source->config['where'] ?? null,
                'max_days_old' => $source->config['max_days_old'] ?? 14,
                'results_per_page' => 50,
                'content-type' => 'application/json',
            ]);
            $data = $this->getJson(self::BASE.$page, $query);
            $results = $data['results'] ?? [];
            if (! is_array($results)) {
                throw new ConnectorException('Unexpected Adzuna payload.', 'parse');
            }

            foreach ($results as $r) {
                if ($yielded >= $limit) {
                    break;
                }
                if (empty($r['id']) || empty($r['title']) || empty($r['redirect_url'])) {
                    continue;
                }
                $yielded++;
                $predicted = (string) ($r['salary_is_predicted'] ?? '0') === '1';

                yield new NormalizedJob(
                    externalId: (string) $r['id'],
                    title: trim(strip_tags($r['title'])),
                    companyName: $r['company']['display_name'] ?? 'Unknown',
                    sourceUrl: $r['redirect_url'],
                    applicationUrl: $r['redirect_url'],
                    location: $r['location']['display_name'] ?? null,
                    country: 'US',
                    employmentType: $r['contract_time'] ?? null,
                    descriptionHtml: e($r['description'] ?? ''),
                    descriptionText: strip_tags($r['description'] ?? ''),
                    salaryMin: $predicted ? null : (isset($r['salary_min']) ? (int) $r['salary_min'] : null),
                    salaryMax: $predicted ? null : (isset($r['salary_max']) ? (int) $r['salary_max'] : null),
                    salaryCurrency: $predicted ? null : 'USD',
                    salaryInterval: $predicted ? null : 'year',
                    postedAt: $this->parseDate($r['created'] ?? null),
                    raw: $r,
                );
            }
            if (count($results) < 50) {
                break;
            }
        }
    }

    public function probe(string $identifier): ?int
    {
        return null;
    }
}

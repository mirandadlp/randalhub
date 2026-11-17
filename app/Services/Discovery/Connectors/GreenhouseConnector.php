<?php

namespace App\Services\Discovery\Connectors;

use App\Models\JobSource;
use App\Services\Discovery\NormalizedJob;
use App\Services\Support\SalaryParser;
use App\Services\Support\TextNormalizer;

/** Greenhouse Job Board API (public, documented): https://developers.greenhouse.io/job-board.html */
class GreenhouseConnector extends BaseConnector
{
    public const BASE = 'https://boards-api.greenhouse.io/v1/boards/';

    public function type(): string
    {
        return 'greenhouse';
    }

    public function fetch(JobSource $source, int $limit): iterable
    {
        $token = rawurlencode(trim($source->identifier));
        $data = $this->getJson(self::BASE.$token.'/jobs', ['content' => 'true']);
        if (! isset($data['jobs']) || ! is_array($data['jobs'])) {
            throw new ConnectorException('Unexpected Greenhouse payload (missing "jobs").', 'parse');
        }

        $count = 0;
        foreach ($data['jobs'] as $job) {
            if ($count++ >= $limit) {
                break;
            }
            if (empty($job['id']) || empty($job['title']) || empty($job['absolute_url'])) {
                continue;
            }

            $html = TextNormalizer::sanitizeHtml($job['content'] ?? '');
            $text = TextNormalizer::htmlToText($job['content'] ?? '');
            $salary = SalaryParser::parse($text);

            $workplace = null;
            foreach ($job['metadata'] ?? [] as $meta) {
                if (is_array($meta) && preg_match('/location type|workplace|remote/i', (string) ($meta['name'] ?? ''))) {
                    $workplace = is_array($meta['value'] ?? null) ? implode(' ', $meta['value']) : (string) ($meta['value'] ?? '');
                }
            }

            yield new NormalizedJob(
                externalId: (string) $job['id'],
                title: trim($job['title']),
                companyName: $job['company_name'] ?? ($source->company?->name ?? $source->identifier),
                sourceUrl: $job['absolute_url'],
                applicationUrl: $job['absolute_url'],
                location: $job['location']['name'] ?? null,
                workplaceHint: $workplace,
                department: $job['departments'][0]['name'] ?? null,
                descriptionHtml: $html,
                descriptionText: $text,
                salaryMin: $salary['min'] ?? null,
                salaryMax: $salary['max'] ?? null,
                salaryCurrency: $salary['currency'] ?? null,
                salaryInterval: $salary['interval'] ?? null,
                salaryText: $salary['text'] ?? null,
                postedAt: $this->parseDate($job['first_published'] ?? $job['updated_at'] ?? null),
                raw: $job,
            );
        }
    }
}

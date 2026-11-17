<?php

namespace App\Services\Discovery\Connectors;

use App\Models\JobSource;
use App\Services\Discovery\NormalizedJob;
use App\Services\Support\SalaryParser;
use App\Services\Support\TextNormalizer;

/** Workable public jobs widget endpoint (the documented embed feed used by Workable career widgets). */
class WorkableConnector extends BaseConnector
{
    public const BASE = 'https://apply.workable.com/api/v1/widget/accounts/';

    public function type(): string
    {
        return 'workable';
    }

    public function fetch(JobSource $source, int $limit): iterable
    {
        $account = rawurlencode(trim($source->identifier));
        $data = $this->getJson(self::BASE.$account, ['details' => 'true']);
        if (! isset($data['jobs']) || ! is_array($data['jobs'])) {
            throw new ConnectorException('Unexpected Workable payload (missing "jobs").', 'parse');
        }

        $count = 0;
        foreach ($data['jobs'] as $job) {
            if ($count >= $limit) {
                break;
            }
            $id = $job['shortcode'] ?? null;
            if (! $id || empty($job['title'])) {
                continue;
            }
            $count++;
            $text = TextNormalizer::htmlToText($job['description'] ?? '');
            $salary = SalaryParser::parse($text);
            $locations = collect($job['locations'] ?? [])
                ->map(fn ($l) => implode(', ', array_filter([$l['city'] ?? null, $l['region'] ?? null, $l['country'] ?? null])))
                ->filter()->unique()->implode(' | ');

            yield new NormalizedJob(
                externalId: (string) $id,
                title: trim($job['title']),
                companyName: $data['name'] ?? ($source->company?->name ?? $source->identifier),
                sourceUrl: $job['url'] ?? $job['shortlink'] ?? ('https://apply.workable.com/j/'.$id),
                applicationUrl: $job['application_url'] ?? null,
                location: $locations ?: implode(', ', array_filter([$job['city'] ?? null, $job['state'] ?? null, $job['country'] ?? null])),
                workplaceHint: ($job['telecommuting'] ?? false) ? 'remote' : null,
                remoteFlag: $job['telecommuting'] ?? null,
                country: $job['locations'][0]['countryCode'] ?? ($job['country'] ?? null),
                employmentType: $job['employment_type'] ?? null,
                department: $job['department'] ?? null,
                descriptionHtml: TextNormalizer::sanitizeHtml($job['description'] ?? ''),
                descriptionText: $text,
                salaryMin: $salary['min'] ?? null,
                salaryMax: $salary['max'] ?? null,
                salaryCurrency: $salary['currency'] ?? null,
                salaryInterval: $salary['interval'] ?? null,
                salaryText: $salary['text'] ?? null,
                postedAt: $this->parseDate($job['published_on'] ?? $job['created_at'] ?? null),
                raw: array_diff_key($job, ['description' => 1]),
            );
        }
    }
}

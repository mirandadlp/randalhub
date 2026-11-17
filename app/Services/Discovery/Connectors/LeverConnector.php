<?php

namespace App\Services\Discovery\Connectors;

use App\Models\JobSource;
use App\Services\Discovery\NormalizedJob;
use App\Services\Support\SalaryParser;
use App\Services\Support\TextNormalizer;

/** Lever Postings API (public, documented): https://github.com/lever/postings-api */
class LeverConnector extends BaseConnector
{
    public const BASE = 'https://api.lever.co/v0/postings/';

    private const PAGE_SIZE = 100;

    public function type(): string
    {
        return 'lever';
    }

    public function fetch(JobSource $source, int $limit): iterable
    {
        $site = rawurlencode(trim($source->identifier));
        $skip = 0;
        $yielded = 0;

        while ($yielded < $limit) {
            $data = $this->getJson(self::BASE.$site, ['mode' => 'json', 'skip' => $skip, 'limit' => self::PAGE_SIZE]);
            if (! array_is_list($data)) {
                throw new ConnectorException('Unexpected Lever payload (expected a list).', 'parse');
            }

            foreach ($data as $job) {
                if ($yielded >= $limit) {
                    break;
                }
                if (empty($job['id']) || empty($job['text']) || empty($job['hostedUrl'])) {
                    continue;
                }
                $yielded++;
                yield $this->map($job, $source);
            }

            if (count($data) < self::PAGE_SIZE) {
                break;
            }
            $skip += self::PAGE_SIZE;
        }
    }

    private function map(array $job, JobSource $source): NormalizedJob
    {
        $htmlParts = [$job['description'] ?? ''];
        $textParts = [$job['descriptionPlain'] ?? TextNormalizer::htmlToText($job['description'] ?? '')];
        foreach ($job['lists'] ?? [] as $list) {
            $htmlParts[] = '<h3>'.e($list['text'] ?? '').'</h3><ul>'.($list['content'] ?? '').'</ul>';
            $textParts[] = ($list['text'] ?? '')."\n".TextNormalizer::htmlToText($list['content'] ?? '');
        }
        $htmlParts[] = $job['additional'] ?? '';
        $textParts[] = $job['additionalPlain'] ?? '';
        $text = trim(implode("\n\n", array_filter($textParts)));

        $salary = null;
        if (! empty($job['salaryRange']['min']) && ! empty($job['salaryRange']['max'])) {
            $interval = str_contains(strtolower($job['salaryRange']['interval'] ?? ''), 'hour') ? 'hour' : 'year';
            $salary = [
                'min' => (int) $job['salaryRange']['min'], 'max' => (int) $job['salaryRange']['max'],
                'currency' => $job['salaryRange']['currency'] ?? 'USD', 'interval' => $interval,
                'text' => null,
            ];
        } else {
            $salary = SalaryParser::parse($text);
        }

        $categories = $job['categories'] ?? [];
        $location = $categories['location'] ?? null;
        if (! empty($categories['allLocations']) && count($categories['allLocations']) > 1) {
            $location = implode(' | ', $categories['allLocations']);
        }

        return new NormalizedJob(
            externalId: (string) $job['id'],
            title: trim($job['text']),
            companyName: $source->company?->name ?? $source->identifier,
            sourceUrl: $job['hostedUrl'],
            applicationUrl: $job['applyUrl'] ?? $job['hostedUrl'],
            location: $location,
            workplaceHint: $job['workplaceType'] ?? null,
            country: $job['country'] ?? null,
            employmentType: $categories['commitment'] ?? null,
            department: $categories['team'] ?? ($categories['department'] ?? null),
            descriptionHtml: TextNormalizer::sanitizeHtml(implode("\n", $htmlParts)),
            descriptionText: $text,
            salaryMin: $salary['min'] ?? null,
            salaryMax: $salary['max'] ?? null,
            salaryCurrency: $salary['currency'] ?? null,
            salaryInterval: $salary['interval'] ?? null,
            salaryText: $salary['text'] ?? null,
            postedAt: $this->parseDate($job['createdAt'] ?? null),
            raw: $job,
        );
    }
}

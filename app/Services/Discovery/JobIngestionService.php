<?php

namespace App\Services\Discovery;

use App\Models\Company;
use App\Models\JobListing;
use App\Models\JobSource;
use App\Models\JobSourceObservation;
use App\Models\ScanRun;
use App\Services\Matching\PrescreenScorer;
use App\Services\Support\LocationClassifier;
use App\Services\Support\TextNormalizer;
use App\Services\Support\UrlCanonicalizer;
use Illuminate\Support\Facades\DB;

class JobIngestionService
{
    /** @var array<string, int>|null normalized company name => id */
    private ?array $companyIndex = null;

    public function __construct(private PrescreenScorer $prescreen) {}

    /**
     * Upsert one posting. Identity is (source, external id) first, then canonical URL across sources.
     *
     * @return string new | updated | unchanged | skipped
     */
    public function ingest(JobSource $source, NormalizedJob $job, ?ScanRun $run = null): string
    {
        if (! UrlCanonicalizer::isSafePublicUrl($job->sourceUrl)) {
            return 'skipped';
        }
        $applicationUrl = UrlCanonicalizer::isSafePublicUrl($job->applicationUrl) ? $job->applicationUrl : $job->sourceUrl;

        $now = now();
        $canonical = UrlCanonicalizer::canonicalize($job->sourceUrl);
        $urlHash = hash('sha256', $canonical);
        $descriptionText = mb_substr((string) $job->descriptionText, 0, 200000);
        $contentHash = hash('sha256', implode('|', [
            $job->title, $job->location, $descriptionText, $job->salaryMin, $job->salaryMax, $job->workplaceHint,
        ]));

        $company = $this->resolveCompany($source, $job->companyName);
        if ($company?->is_excluded) {
            return 'skipped';
        }

        $loc = LocationClassifier::classify($job->location, $job->workplaceHint, $job->remoteFlag, $job->country);
        $attributes = [
            'company_id' => $company?->id,
            'company_name' => mb_substr($company?->name ?? $job->companyName, 0, 255),
            'title' => mb_substr($job->title, 0, 255),
            'normalized_title' => mb_substr(TextNormalizer::normalizeTitle($job->title), 0, 255),
            'location' => $job->location ? mb_substr($job->location, 0, 512) : null,
            'is_us' => $loc['is_us'],
            'is_nevada' => $loc['is_nevada'],
            'remote_type' => $loc['remote_type'],
            'employment_type' => $job->employmentType ? mb_substr($job->employmentType, 0, 40) : null,
            'department' => $job->department ? mb_substr($job->department, 0, 255) : null,
            'industry' => $company?->industry,
            'salary_min' => $job->salaryMin,
            'salary_max' => $job->salaryMax,
            'salary_currency' => $job->salaryCurrency,
            'salary_interval' => $job->salaryInterval,
            'salary_text' => $job->salaryText ? mb_substr($job->salaryText, 0, 255) : null,
            'description_html' => $job->descriptionHtml,
            'description_text' => $descriptionText,
            'requirements_text' => TextNormalizer::extractRequirements($descriptionText),
            'source_url' => $job->sourceUrl,
            'application_url' => $applicationUrl,
            'canonical_url' => $canonical,
            'url_hash' => $urlHash,
            'fingerprint' => $this->fingerprint($company?->name ?? $job->companyName, $job->title, $job->location),
            'content_hash' => $contentHash,
            'posted_at' => $job->postedAt,
            'is_direct_employer' => $source->isDirectEmployer(),
        ];
        if ($job->applicantCount !== null) {
            // Only set when a source legitimately publishes it.
            $attributes['applicant_count'] = $job->applicantCount;
            $attributes['applicant_count_source'] = $job->applicantCountSource;
            $attributes['applicant_count_observed_at'] = $now;
        }

        return DB::transaction(function () use ($source, $job, $run, $now, $urlHash, $contentHash, $attributes) {
            $observation = JobSourceObservation::where('job_source_id', $source->id)
                ->where('external_id', $job->externalId)
                ->lockForUpdate()
                ->first();

            $listing = $observation?->listing
                ?? JobListing::where('url_hash', $urlHash)->first();

            $result = 'unchanged';
            if (! $listing) {
                $listing = new JobListing($attributes + [
                    'primary_source_id' => $source->id,
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                    'status' => 'open',
                ]);
                $listing->prescreen_score = $this->prescreen->score($listing);
                $listing->save();
                $result = 'new';
            } else {
                $changed = $listing->content_hash !== $contentHash;
                $reopened = $listing->status !== 'open';
                if ($changed) {
                    // Keep the employer's earliest posting date; never move first_seen.
                    $attributes['posted_at'] = $listing->posted_at && $job->postedAt && $listing->posted_at < $job->postedAt
                        ? $listing->posted_at : ($job->postedAt ?? $listing->posted_at);
                    $listing->fill($attributes);
                    $listing->content_changed_at = $now;
                    $listing->prescreen_score = $this->prescreen->score($listing);
                }
                $listing->last_seen_at = $now;
                $listing->missed_scans = 0;
                if ($reopened) {
                    $listing->status = 'open';
                    $listing->closed_at = null;
                }
                $listing->save();
                $result = ($changed || $reopened || ! $observation) ? 'updated' : 'unchanged';
            }

            $payload = json_encode($job->raw, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_UNICODE);
            if ($observation) {
                $observation->fill([
                    'job_listing_id' => $listing->id,
                    'last_scan_run_id' => $run?->id,
                    'source_url' => $job->sourceUrl,
                    'content_hash' => $contentHash,
                    'raw_payload' => mb_strlen((string) $payload) > 500000 ? null : $payload,
                    'is_active' => true,
                    'missed_scans' => 0,
                    'last_seen_at' => $now,
                ])->save();
            } else {
                JobSourceObservation::create([
                    'job_listing_id' => $listing->id,
                    'job_source_id' => $source->id,
                    'last_scan_run_id' => $run?->id,
                    'external_id' => $job->externalId,
                    'source_url' => $job->sourceUrl,
                    'content_hash' => $contentHash,
                    'raw_payload' => mb_strlen((string) $payload) > 500000 ? null : $payload,
                    'is_active' => true,
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                ]);
            }

            return $result;
        });
    }

    /**
     * After a successful full fetch, mark this source's postings that were not returned.
     * A listing closes only when every source observing it has missed it N times.
     *
     * @param  array<string, true>  $seenExternalIds
     */
    public function markMissing(JobSource $source, array $seenExternalIds): int
    {
        $threshold = (int) config('jobhunter.close_after_missed_scans', 2);
        $closed = 0;

        JobSourceObservation::where('job_source_id', $source->id)
            ->where('is_active', true)
            ->select(['id', 'external_id', 'job_listing_id', 'missed_scans'])
            ->chunkById(500, function ($observations) use ($seenExternalIds, $threshold, &$closed) {
                foreach ($observations as $obs) {
                    if (isset($seenExternalIds[$obs->external_id])) {
                        continue;
                    }
                    $obs->missed_scans++;
                    if ($obs->missed_scans >= $threshold) {
                        $obs->is_active = false;
                    }
                    $obs->save();

                    if (! $obs->is_active) {
                        $stillListed = JobSourceObservation::where('job_listing_id', $obs->job_listing_id)
                            ->where('is_active', true)->exists();
                        if (! $stillListed) {
                            $closed += JobListing::whereKey($obs->job_listing_id)->where('status', 'open')
                                ->update(['status' => 'closed', 'closed_at' => now()]);
                        }
                    }
                }
            });

        return $closed;
    }

    public function fingerprint(string $company, string $title, ?string $location): string
    {
        return hash('sha256', implode('|', [
            TextNormalizer::normalizeCompany($company),
            TextNormalizer::normalizeTitle($title),
            TextNormalizer::normalizeLocation($location),
        ]));
    }

    private function resolveCompany(JobSource $source, string $companyName): ?Company
    {
        if ($source->company_id) {
            return $source->company;
        }
        $name = trim($companyName);
        if ($name === '' || strtolower($name) === 'unknown') {
            return null;
        }

        $normalized = TextNormalizer::normalizeCompany($name);
        if ($this->companyIndex === null) {
            $this->companyIndex = Company::query()->pluck('id', 'name')
                ->mapWithKeys(fn ($id, $n) => [TextNormalizer::normalizeCompany($n) => $id])->all();
        }
        if (isset($this->companyIndex[$normalized])) {
            return Company::find($this->companyIndex[$normalized]);
        }

        // Discovered via a search API: tracked, but not added to the watchlist automatically.
        $company = Company::create([
            'name' => mb_substr($name, 0, 255),
            'is_watched' => false,
            'discovered_via' => 'search_api',
        ]);
        $this->companyIndex[$normalized] = $company->id;

        return $company;
    }
}

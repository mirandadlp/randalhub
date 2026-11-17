<?php

namespace App\Services\Discovery;

use App\Models\ImportError;
use App\Models\JobSource;
use App\Models\ScanRun;
use App\Models\ScanRunSource;
use App\Services\Discovery\Connectors\ConnectorException;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Log;

/** Scans a single source: fetch, ingest each posting, close missing ones, update connector health. */
class SourceScanner
{
    public function __construct(
        private ConnectorRegistry $registry,
        private JobIngestionService $ingestion,
        private SettingsService $settings,
    ) {}

    public function scan(JobSource $source, ?ScanRun $run = null): ScanRunSource|array
    {
        $started = microtime(true);
        $counts = ['found' => 0, 'new' => 0, 'updated' => 0, 'closed' => 0];
        $seen = [];
        $limit = (int) ($source->max_jobs ?: $this->settings->get('source_defaults.max_jobs_per_source', 2000));

        try {
            $connector = $this->registry->for($source->type);
            foreach ($connector->fetch($source, $limit) as $job) {
                $counts['found']++;
                $seen[$job->externalId] = true;
                try {
                    $result = $this->ingestion->ingest($source, $job, $run);
                    if (isset($counts[$result])) {
                        $counts[$result]++;
                    }
                } catch (\Throwable $e) {
                    ImportError::create([
                        'job_source_id' => $source->id,
                        'scan_run_id' => $run?->id,
                        'error_type' => 'validation',
                        'message' => mb_substr($e->getMessage(), 0, 2000),
                        'context' => ['external_id' => $job->externalId, 'title' => $job->title],
                        'occurred_at' => now(),
                    ]);
                }
            }

            // Guard: an empty response from a board that previously had many jobs is more likely an
            // upstream glitch than every job closing at once. Don't close anything in that case.
            $suspiciousEmpty = $counts['found'] === 0 && ($source->last_job_count ?? 0) >= 5;
            if (! $suspiciousEmpty && $counts['found'] < $limit) {
                $counts['closed'] = $this->ingestion->markMissing($source, $seen);
            }

            $source->forceFill([
                'health' => $suspiciousEmpty ? 'degraded' : 'healthy',
                'consecutive_failures' => 0,
                // Keep the previous count after a suspicious empty response so repeated glitches stay guarded.
                'last_job_count' => $suspiciousEmpty ? $source->last_job_count : $counts['found'],
                'last_scanned_at' => now(),
                'last_success_at' => now(),
                'last_error' => $suspiciousEmpty ? 'Source returned zero jobs (previously '.$source->last_job_count.'); closures skipped.' : null,
                'limitation_note' => $suspiciousEmpty ? $source->limitation_note : null,
            ])->save();

            return $this->record($source, $run, 'succeeded', $counts, $started);
        } catch (ConnectorException $e) {
            return $this->fail($source, $run, $e->getMessage(), $e->kind, $e->permanent, $counts, $started);
        } catch (\Throwable $e) {
            Log::error('Source scan failed', ['source' => $source->id, 'error' => $e->getMessage()]);

            return $this->fail($source, $run, $e->getMessage(), 'exception', false, $counts, $started);
        }
    }

    private function fail(JobSource $source, ?ScanRun $run, string $message, string $kind, bool $permanent, array $counts, float $started): ScanRunSource|array
    {
        $failures = $source->consecutive_failures + 1;
        $health = match (true) {
            in_array($kind, ['blocked', 'robots'], true) => 'blocked',
            $permanent => 'unsupported',
            $failures >= 3 => 'failing',
            default => 'degraded',
        };

        $source->forceFill([
            'health' => $health,
            'consecutive_failures' => $failures,
            'last_scanned_at' => now(),
            'last_error_at' => now(),
            'last_error' => mb_substr($message, 0, 2000),
            'limitation_note' => in_array($kind, ['blocked', 'robots', 'parse', 'config'], true) && $permanent
                ? mb_substr($message, 0, 2000) : $source->limitation_note,
        ])->save();

        ImportError::create([
            'job_source_id' => $source->id,
            'scan_run_id' => $run?->id,
            'error_type' => $kind,
            'message' => mb_substr($message, 0, 2000),
            'context' => ['type' => $source->type, 'identifier' => $source->identifier, 'permanent' => $permanent],
            'occurred_at' => now(),
        ]);

        return $this->record($source, $run, 'failed', $counts, $started, $message);
    }

    private function record(JobSource $source, ?ScanRun $run, string $status, array $counts, float $started, ?string $error = null): ScanRunSource|array
    {
        $data = [
            'status' => $status,
            'jobs_found' => $counts['found'],
            'jobs_new' => $counts['new'],
            'jobs_updated' => $counts['updated'],
            'jobs_closed' => $counts['closed'],
            'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            'error' => $error ? mb_substr($error, 0, 2000) : null,
        ];

        if (! $run) {
            return $data;
        }

        return ScanRunSource::updateOrCreate(['scan_run_id' => $run->id, 'job_source_id' => $source->id], $data);
    }
}

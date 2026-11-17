<?php

namespace App\Jobs;

use App\Models\ScanRun;
use App\Models\ScanRunSource;
use App\Services\Discovery\DeduplicationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Aggregates per-source results, runs duplicate detection, then triggers AI analysis + alerts. */
class FinalizeScanJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $scanRunId, public bool $runPipeline = true) {}

    public function handle(DeduplicationService $dedupe): void
    {
        $run = ScanRun::find($this->scanRunId);
        if (! $run || $run->status !== 'running') {
            return;
        }

        $results = ScanRunSource::where('scan_run_id', $run->id)->get();
        $dedupeResult = $dedupe->run();

        $failed = $results->where('status', 'failed');
        $succeeded = $results->where('status', 'succeeded');
        $status = match (true) {
            $run->sources_total === 0 => 'completed',
            $succeeded->isEmpty() && $failed->isNotEmpty() => 'failed',
            $failed->isNotEmpty() || $results->count() < $run->sources_total => 'partial',
            default => 'completed',
        };

        $run->update([
            'status' => $status,
            'finished_at' => now(),
            'sources_succeeded' => $succeeded->count(),
            'sources_failed' => $failed->count(),
            'sources_skipped' => max(0, $run->sources_total - $results->count()),
            'jobs_found' => $results->sum('jobs_found'),
            'jobs_new' => $results->sum('jobs_new'),
            'jobs_updated' => $results->sum('jobs_updated'),
            'jobs_closed' => $results->sum('jobs_closed'),
            'duplicates_found' => $dedupeResult['duplicates'],
            'error_summary' => $failed->isEmpty() ? null : mb_substr($failed->count().' source(s) failed: '
                .$failed->take(5)->map(fn ($r) => $r->source?->displayName().': '.$r->error)->implode(' | '), 0, 3000),
        ]);

        if ($this->runPipeline) {
            AnalyzeNewJobsJob::dispatch();
        }
    }
}

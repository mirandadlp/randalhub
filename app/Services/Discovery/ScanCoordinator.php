<?php

namespace App\Services\Discovery;

use App\Jobs\FinalizeScanJob;
use App\Jobs\ScanSourceJob;
use App\Models\JobSource;
use App\Models\ScanRun;
use App\Services\SettingsService;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

/**
 * Starts discovery runs. Guarantees at most one active run (cache lock + DB check),
 * fans out one queued job per source, then finalizes and triggers the post-scan pipeline.
 */
class ScanCoordinator
{
    public function __construct(private SettingsService $settings) {}

    public function isDue(): bool
    {
        $hours = max(1, (int) $this->settings->get('scan_interval_hours', 6));
        $last = ScanRun::whereIn('status', ['completed', 'partial', 'running'])->latest('started_at')->first();

        return ! $last || $last->started_at->lte(now()->subHours($hours)->addMinutes(5));
    }

    public function activeRun(): ?ScanRun
    {
        // Runs older than 3 hours are considered abandoned (e.g. the worker was killed).
        ScanRun::where('status', 'running')->where('started_at', '<', now()->subHours(3))
            ->update(['status' => 'failed', 'finished_at' => now(), 'error_summary' => 'Abandoned: did not finish within 3 hours.']);

        return ScanRun::where('status', 'running')->latest('started_at')->first();
    }

    /**
     * @param  list<int>|null  $sourceIds  restrict to these sources (manual refresh)
     * @return ScanRun|null null when another run is already in progress
     */
    public function start(string $trigger, ?array $sourceIds = null, bool $sync = false): ?ScanRun
    {
        $lock = Cache::lock('jobhunter:scan-start', 30);
        if (! $lock->get()) {
            return null;
        }

        try {
            if ($this->activeRun()) {
                return null;
            }

            $sources = JobSource::query()
                ->where('enabled', true)
                ->when($sourceIds, fn ($q) => $q->whereIn('id', $sourceIds))
                ->when(! $sourceIds, fn ($q) => $q->where(function ($q) {
                    // Blocked/unsupported sources are retried weekly, not every scan.
                    $q->whereNotIn('health', ['blocked', 'unsupported'])
                        ->orWhere('last_scanned_at', '<', now()->subDays(7))
                        ->orWhereNull('last_scanned_at');
                }))
                ->where(fn ($q) => $q->whereNull('company_id')->orWhereHas('company', fn ($c) => $c->where('is_excluded', false)))
                ->pluck('id');

            $run = ScanRun::create([
                'trigger' => $trigger,
                'status' => 'running',
                'started_at' => now(),
                'sources_total' => $sources->count(),
            ]);
        } finally {
            $lock->release();
        }

        if ($sources->isEmpty()) {
            FinalizeScanJob::dispatchSync($run->id);

            return $run->fresh();
        }

        if ($sync) {
            foreach ($sources as $id) {
                ScanSourceJob::dispatchSync($id, $run->id);
            }
            FinalizeScanJob::dispatchSync($run->id);

            return $run->fresh();
        }

        $runId = $run->id;
        Bus::batch($sources->map(fn ($id) => new ScanSourceJob($id, $runId))->all())
            ->name('discovery-run-'.$runId)
            ->allowFailures()
            ->onQueue('discovery')
            ->finally(function (Batch $batch) use ($runId) {
                FinalizeScanJob::dispatch($runId);
            })
            ->dispatch();

        return $run;
    }
}

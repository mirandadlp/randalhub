<?php

namespace App\Console\Commands;

use App\Services\Discovery\ScanCoordinator;
use Illuminate\Console\Command;

class DiscoverJobsCommand extends Command
{
    use TracksTaskRuns;

    protected $signature = 'jobhunter:discover
        {--force : Run even if the scan interval has not elapsed}
        {--sync : Run in this process instead of on the queue}
        {--source=* : Only scan these job source IDs}';

    protected $description = 'Discover jobs from all enabled sources (runs automatically every N hours)';

    public function handle(ScanCoordinator $coordinator): int
    {
        return $this->tracked('discover', function () use ($coordinator) {
            $sources = array_map('intval', (array) $this->option('source')) ?: null;
            if (! $this->option('force') && ! $sources && ! $coordinator->isDue()) {
                $this->line('Not due yet.');

                return 'skipped';
            }

            $run = $coordinator->start($sources ? 'manual' : ($this->option('force') ? 'manual' : 'scheduled'), $sources, (bool) $this->option('sync'));
            if (! $run) {
                $this->warn('A scan is already running; skipped to avoid a duplicate scan.');

                return 'skipped';
            }

            $msg = $this->option('sync')
                ? "Scan #{$run->id} {$run->status}: {$run->jobs_found} found, {$run->jobs_new} new, {$run->jobs_closed} closed, {$run->sources_failed} source failures."
                : "Scan #{$run->id} queued for {$run->sources_total} sources.";
            $this->info($msg);

            return $msg;
        });
    }
}

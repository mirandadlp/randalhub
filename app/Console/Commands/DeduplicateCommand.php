<?php

namespace App\Console\Commands;

use App\Services\Discovery\DeduplicationService;
use Illuminate\Console\Command;

class DeduplicateCommand extends Command
{
    use TracksTaskRuns;

    protected $signature = 'jobhunter:dedupe';

    protected $description = 'Detect duplicate and reposted listings (also runs after every scan)';

    public function handle(DeduplicationService $service): int
    {
        return $this->tracked('dedupe', function () use ($service) {
            $r = $service->run();
            $this->info("Duplicates: {$r['duplicates']}, reposts: {$r['reposts']}");

            return $r;
        });
    }
}

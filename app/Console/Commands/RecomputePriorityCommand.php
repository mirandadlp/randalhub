<?php

namespace App\Console\Commands;

use App\Models\JobMatch;
use App\Services\Matching\JobMatchService;
use Illuminate\Console\Command;

class RecomputePriorityCommand extends Command
{
    use TracksTaskRuns;

    protected $signature = 'jobhunter:recompute-priority';

    protected $description = 'Recompute opportunity priority (freshness decays daily; no AI calls)';

    public function handle(JobMatchService $service): int
    {
        return $this->tracked('recompute-priority', function () use ($service) {
            $n = 0;
            JobMatch::with('listing')->where('status', 'completed')
                ->whereHas('listing', fn ($q) => $q->where('status', 'open'))
                ->chunkById(200, function ($matches) use ($service, &$n) {
                    foreach ($matches as $m) {
                        $service->refreshPriority($m, $m->listing);
                        $n++;
                    }
                });

            return "recomputed {$n}";
        });
    }
}

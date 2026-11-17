<?php

namespace App\Console\Commands;

use App\Jobs\AnalyzeNewJobsJob;
use App\Models\JobListing;
use App\Models\User;
use App\Services\Matching\JobMatchService;
use App\Services\SettingsService;
use Illuminate\Console\Command;

class AnalyzeJobsCommand extends Command
{
    use TracksTaskRuns;

    protected $signature = 'jobhunter:analyze {--limit=} {--job= : Analyze one listing ID} {--force} {--sync}';

    protected $description = 'Run AI qualification analysis on new or changed listings';

    public function handle(JobMatchService $matches, SettingsService $settings): int
    {
        return $this->tracked('analyze', function () use ($matches, $settings) {
            $user = User::with('profile')->firstOrFail();
            if ($id = $this->option('job')) {
                $m = $matches->analyze($user, JobListing::findOrFail($id), (bool) $this->option('force'));
                $this->info("Match {$m->match_score}, priority {$m->priority_score}");

                return "listing {$id}: {$m->match_score}";
            }

            $limit = $this->option('limit') ? (int) $this->option('limit') : null;
            if ($this->option('sync')) {
                $ids = AnalyzeNewJobsJob::candidates($user, $settings, $limit ?? (int) $settings->get('analysis_batch_limit', 40));
                foreach ($ids as $id) {
                    $m = $matches->analyze($user, JobListing::find($id));
                    $this->line("#{$id}: {$m->match_score}");
                }

                return 'analyzed '.$ids->count();
            }

            AnalyzeNewJobsJob::dispatch($limit);

            return 'queued';
        });
    }
}

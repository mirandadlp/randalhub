<?php

namespace App\Console\Commands;

use App\Models\ApiUsageLog;
use App\Models\Application;
use App\Models\ImportError;
use App\Models\JobListing;
use App\Models\JobSourceObservation;
use App\Models\SavedJob;
use App\Models\TaskRun;
use App\Services\SettingsService;
use Illuminate\Console\Command;

class CleanupCommand extends Command
{
    use TracksTaskRuns;

    protected $signature = 'jobhunter:cleanup';

    protected $description = 'Archive outdated listings and prune old logs and temporary files';

    public function handle(SettingsService $settings): int
    {
        return $this->tracked('cleanup', function () use ($settings) {
            $r = $settings->get('retention');
            $result = [];

            // Never archive a job the user is tracking (applications or saved jobs).
            $notTracked = fn ($q) => $q->whereNotIn('id', Application::select('job_listing_id'))
                ->whereNotIn('id', SavedJob::select('job_listing_id'));

            $result['archived_closed'] = JobListing::where('status', 'closed')
                ->where('closed_at', '<', now()->subDays($r['archive_closed_after_days']))
                ->where($notTracked)->update(['status' => 'archived']);

            $result['archived_unseen'] = JobListing::where('status', 'open')
                ->where('last_seen_at', '<', now()->subDays($r['archive_unseen_after_days']))
                ->where($notTracked)->update(['status' => 'archived', 'closed_at' => now()]);

            $result['payloads_pruned'] = JobSourceObservation::whereNotNull('raw_payload')
                ->where('last_seen_at', '<', now()->subDays($r['prune_observation_payloads_after_days']))
                ->update(['raw_payload' => null]);

            $cutoff = now()->subDays($r['prune_logs_after_days']);
            $result['logs_pruned'] = ApiUsageLog::where('created_at', '<', $cutoff)->delete()
                + ImportError::where('created_at', '<', $cutoff)->delete()
                + TaskRun::where('created_at', '<', $cutoff)->delete();

            // Temporary decrypted resumes and autofill payloads.
            $removed = 0;
            foreach (glob(storage_path('app/private/tmp/*')) ?: [] as $file) {
                if (is_file($file) && filemtime($file) < time() - 6 * 3600) {
                    @unlink($file);
                    $removed++;
                }
            }
            $result['temp_files_removed'] = $removed;
            $this->line(json_encode($result));

            return $result;
        });
    }
}

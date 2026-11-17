<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Console\Command;

class SendDigestCommand extends Command
{
    use TracksTaskRuns;

    protected $signature = 'jobhunter:digest {--force : Send now regardless of schedule}';

    protected $description = 'Send the daily digest once the configured delivery time has passed';

    public function handle(NotificationService $notifications): int
    {
        if (! $this->option('force') && ! $notifications->digestIsDue()) {
            return self::SUCCESS; // checked every few minutes; don't log a task run for "not due"
        }

        return $this->tracked('digest', function () use ($notifications) {
            $results = [];
            foreach (User::all() as $user) {
                $results[] = $notifications->sendDigest($user, (bool) $this->option('force'));
            }
            $sent = collect($results)->where('sent', true)->count();
            if (! $sent) {
                return 'skipped';
            }
            $this->info('Digest sent.');

            return $results;
        });
    }
}

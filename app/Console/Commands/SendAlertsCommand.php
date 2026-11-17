<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Console\Command;

class SendAlertsCommand extends Command
{
    use TracksTaskRuns;

    protected $signature = 'jobhunter:alerts';

    protected $description = 'Send high-match priority alerts (deduplicated)';

    public function handle(NotificationService $notifications): int
    {
        return $this->tracked('alerts', function () use ($notifications) {
            $n = 0;
            foreach (User::all() as $user) {
                $n += $notifications->sendPriorityAlerts($user);
            }
            $this->info("Alerted {$n} job(s).");

            return "alerted {$n}";
        });
    }
}

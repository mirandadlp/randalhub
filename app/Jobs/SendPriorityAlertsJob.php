<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendPriorityAlertsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 600];

    public int $uniqueFor = 600;

    public function handle(NotificationService $notifications): void
    {
        foreach (User::all() as $user) {
            $notifications->sendPriorityAlerts($user);
        }
    }
}

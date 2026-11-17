<?php

use App\Services\SettingsService;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Automatic background operation
|--------------------------------------------------------------------------
| Requires `php artisan schedule:work` (development) or a launchd/cron entry running
| `php artisan schedule:run` every minute, plus a queue worker. See README → Background operation.
| All tasks use overlap locks so a slow run never stacks up.
*/

// Discovery: checked hourly, runs when the configured interval (default 6h) has elapsed.
// Each scan chains: dedupe → AI analysis of new/changed jobs → priority alerts.
Schedule::command('jobhunter:discover')->hourly()->withoutOverlapping(120)->onOneServer();

// Daily digest: checked every 5 minutes, sends once per day after the configured local time.
Schedule::command('jobhunter:digest')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();

// Catch-up for priority alerts (normally sent right after each scan's analysis finishes).
Schedule::command('jobhunter:alerts')->hourlyAt(40)->withoutOverlapping(30)->onOneServer();

// Duplicate/repost detection safety net (also runs after every import).
Schedule::command('jobhunter:dedupe')->dailyAt('02:50')->withoutOverlapping(30);

// Availability checks for listings whose source is not scanned regularly.
Schedule::command('jobhunter:check-availability')->dailyAt('03:15')->withoutOverlapping(60);

// Archive outdated listings, prune logs and temp files.
Schedule::command('jobhunter:cleanup')->dailyAt('03:45')->withoutOverlapping(60);

// Freshness decays daily; recompute priority without any AI calls.
Schedule::command('jobhunter:recompute-priority')->dailyAt('04:10')->withoutOverlapping(30);

// Follow-up reminders for tracked applications.
Schedule::command('jobhunter:follow-ups')->dailyAt('08:05')->withoutOverlapping(30);

// Queue housekeeping.
Schedule::command('queue:prune-batches --hours=72')->daily();
Schedule::command('queue:prune-failed --hours=720')->weekly();

// Heartbeat so the dashboard can show whether the scheduler is actually running.
Schedule::call(fn () => app(SettingsService::class)->putState('scheduler_heartbeat', now()->toIso8601String()))
    ->everyMinute()->name('scheduler-heartbeat')->withoutOverlapping(2);

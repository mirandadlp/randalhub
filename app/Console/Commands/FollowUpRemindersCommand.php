<?php

namespace App\Console\Commands;

use App\Mail\FollowUpReminderMail;
use App\Models\Application;
use App\Models\NotificationLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class FollowUpRemindersCommand extends Command
{
    use TracksTaskRuns;

    protected $signature = 'jobhunter:follow-ups';

    protected $description = 'Email reminders for applications whose follow-up date has arrived';

    public function handle(): int
    {
        return $this->tracked('follow-ups', function () {
            $due = Application::with(['listing', 'user'])
                ->whereNotNull('next_follow_up_at')
                ->where('next_follow_up_at', '<=', now())
                ->whereNotIn('status', ['rejected', 'archived', 'offer'])
                ->get()
                ->groupBy('user_id');

            $count = 0;
            foreach ($due as $apps) {
                $user = $apps->first()->user;
                $key = 'followups:'.now()->toDateString();
                if (NotificationLog::where('user_id', $user->id)->where('dedupe_key', $key)->exists()) {
                    continue;
                }
                Mail::to($user->notificationEmail())->send(new FollowUpReminderMail($apps));
                NotificationLog::create([
                    'user_id' => $user->id, 'type' => 'follow_up', 'dedupe_key' => $key, 'status' => 'sent',
                    'sent_at' => now(), 'job_listing_ids' => $apps->pluck('job_listing_id')->all(), 'subject' => 'Follow-up reminders',
                ]);
                $count += $apps->count();
            }

            return $count ? "reminded {$count}" : 'skipped';
        });
    }
}

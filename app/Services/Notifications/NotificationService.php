<?php

namespace App\Services\Notifications;

use App\Mail\DailyDigestMail;
use App\Mail\PriorityAlertMail;
use App\Models\JobListing;
use App\Models\JobMatch;
use App\Models\NotificationLog;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

class NotificationService
{
    public function __construct(private SettingsService $settings) {}

    /**
     * Email newly discovered jobs whose AI match score meets the alert threshold.
     * Each job is alerted at most once (unique dedupe key per job).
     */
    public function sendPriorityAlerts(User $user): int
    {
        $n = $this->settings->get('notifications');
        if (! ($n['email_enabled'] ?? true) || ! ($n['priority_alerts_enabled'] ?? true)) {
            return 0;
        }
        $threshold = (int) $this->settings->get('match_alert_threshold', 90);

        $alreadySent = NotificationLog::where('user_id', $user->id)->where('type', 'priority_alert')
            ->where('status', 'sent')->pluck('dedupe_key')->all();

        $matches = JobMatch::with('listing')
            ->where('user_id', $user->id)
            ->where('status', 'completed')
            ->where('match_score', '>=', $threshold)
            ->whereHas('listing', fn ($q) => $q->open()->canonical()->where('first_seen_at', '>=', now()->subDays(7)))
            ->whereNotIn('job_listing_id', $user->dismissedJobs()->select('job_listing_id'))
            ->orderByDesc('match_score')
            ->get()
            ->reject(fn (JobMatch $m) => in_array('alert:'.$m->job_listing_id, $alreadySent, true))
            ->values();

        if ($matches->isEmpty()) {
            return 0;
        }

        $logs = $matches->map(fn (JobMatch $m) => NotificationLog::updateOrCreate(
            ['user_id' => $user->id, 'dedupe_key' => 'alert:'.$m->job_listing_id],
            ['type' => 'priority_alert', 'job_listing_ids' => [$m->job_listing_id], 'status' => 'pending', 'subject' => $m->listing->title],
        ));

        try {
            Mail::to($user->notificationEmail())->send(new PriorityAlertMail($matches, $threshold));
            NotificationLog::whereIn('id', $logs->pluck('id'))->update(['status' => 'sent', 'sent_at' => now(), 'error' => null]);
        } catch (\Throwable $e) {
            NotificationLog::whereIn('id', $logs->pluck('id'))->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000)]);
            throw $e;
        }

        return $matches->count();
    }

    public function digestIsDue(?Carbon $now = null): bool
    {
        $n = $this->settings->get('notifications');
        if (! ($n['email_enabled'] ?? true) || ! ($n['digest_enabled'] ?? true) || ($n['digest_frequency'] ?? 'daily') === 'off') {
            return false;
        }
        $tz = $n['timezone'] ?? 'America/Los_Angeles';
        $local = ($now ?? now())->copy()->setTimezone($tz);
        if (($n['digest_frequency'] ?? 'daily') === 'weekdays' && $local->isWeekend()) {
            return false;
        }
        [$h, $m] = array_map('intval', explode(':', $n['digest_time'] ?? '07:30') + [1 => 0]);

        return $local->gte($local->copy()->setTime($h, $m));
    }

    /** @return array{sent: bool, count: int, reason?: string} */
    public function sendDigest(User $user, bool $force = false, ?Carbon $now = null): array
    {
        $n = $this->settings->get('notifications');
        $tz = $n['timezone'] ?? 'America/Los_Angeles';
        $localDate = ($now ?? now())->copy()->setTimezone($tz)->toDateString();
        $key = 'digest:'.$localDate;

        if (! $force && ! $this->digestIsDue($now)) {
            return ['sent' => false, 'count' => 0, 'reason' => 'not due'];
        }
        if (! $force && NotificationLog::where('user_id', $user->id)->where('dedupe_key', $key)->where('status', 'sent')->exists()) {
            return ['sent' => false, 'count' => 0, 'reason' => 'already sent today'];
        }

        $jobs = $this->digestJobs($user, (int) ($n['digest_size'] ?? 10));
        $log = NotificationLog::updateOrCreate(
            ['user_id' => $user->id, 'dedupe_key' => $force ? $key.':manual:'.now()->timestamp : $key],
            ['type' => 'daily_digest', 'job_listing_ids' => $jobs->pluck('id')->all(), 'status' => 'pending', 'subject' => 'Daily digest '.$localDate],
        );

        try {
            Mail::to($user->notificationEmail())->send(new DailyDigestMail($jobs, $localDate));
            $log->update(['status' => 'sent', 'sent_at' => now(), 'error' => null]);
        } catch (\Throwable $e) {
            $log->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000)]);
            throw $e;
        }

        return ['sent' => true, 'count' => $jobs->count()];
    }

    /** Top newly discovered (last 24h) opportunities, best priority first. */
    public function digestJobs(User $user, int $size): Collection
    {
        return JobListing::query()
            ->open()->canonical()
            ->where('job_listings.first_seen_at', '>=', now()->subDay())
            ->whereNotIn('job_listings.id', $user->dismissedJobs()->select('job_listing_id'))
            ->with(['match' => fn ($q) => $q->where('user_id', $user->id)])
            ->leftJoin('job_matches', function ($join) use ($user) {
                $join->on('job_matches.job_listing_id', '=', 'job_listings.id')->where('job_matches.user_id', '=', $user->id);
            })
            ->select('job_listings.*')
            ->orderByRaw('CASE WHEN job_matches.priority_score IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('job_matches.priority_score')
            ->orderByDesc('job_listings.prescreen_score')
            ->limit($size)
            ->get();
    }
}

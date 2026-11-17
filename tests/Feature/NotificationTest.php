<?php

namespace Tests\Feature;

use App\Mail\DailyDigestMail;
use App\Mail\PriorityAlertMail;
use App\Models\JobListing;
use App\Models\JobMatch;
use App\Models\NotificationLog;
use App\Services\Notifications\NotificationService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private function match($user, int $score, array $listing = []): JobMatch
    {
        $job = JobListing::factory()->create($listing);

        return JobMatch::create(['user_id' => $user->id, 'job_listing_id' => $job->id, 'status' => 'completed', 'match_score' => $score, 'priority_score' => $score, 'qualification_summary' => 'You fit.']);
    }

    public function test_priority_alerts_respect_threshold_and_never_repeat(): void
    {
        Mail::fake();
        $user = $this->userWithProfile();
        $this->match($user, 95);
        $this->match($user, 75);
        $this->match($user, 92, ['first_seen_at' => now()->subDays(20)]); // not new

        $service = app(NotificationService::class);
        $this->assertSame(1, $service->sendPriorityAlerts($user));
        Mail::assertSent(PriorityAlertMail::class, fn ($m) => $m->matches->count() === 1 && $m->hasTo('owner@example.test'));

        $this->assertSame(0, $service->sendPriorityAlerts($user));
        Mail::assertSentCount(1);

        app(SettingsService::class)->set('match_alert_threshold', 70);
        $this->assertSame(1, app(NotificationService::class)->sendPriorityAlerts($user));
    }

    public function test_alerts_can_be_disabled(): void
    {
        Mail::fake();
        $user = $this->userWithProfile();
        $this->match($user, 99);
        app(SettingsService::class)->set('notifications', array_merge(config('jobhunter.defaults.notifications'), ['priority_alerts_enabled' => false]));

        $this->assertSame(0, app(NotificationService::class)->sendPriorityAlerts($user));
        Mail::assertNothingSent();
    }

    public function test_failed_delivery_is_retried_next_time(): void
    {
        $user = $this->userWithProfile();
        $this->match($user, 95);
        // A real SMTP mailer pointed at a closed local port: delivery fails fast.
        config(['mail.mailers.broken' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1, 'timeout' => 1], 'mail.default' => 'broken']);

        try {
            app(NotificationService::class)->sendPriorityAlerts($user);
            $this->fail('expected transport exception');
        } catch (\Throwable) {
        }
        $this->assertSame('failed', NotificationLog::sole()->status);
        $this->assertNotNull(NotificationLog::sole()->error);

        Mail::fake();
        $this->assertSame(1, app(NotificationService::class)->sendPriorityAlerts($user));
        $this->assertSame('sent', NotificationLog::sole()->status);
    }

    public function test_daily_digest_sends_once_after_configured_time(): void
    {
        Mail::fake();
        $user = $this->userWithProfile();
        foreach (range(1, 12) as $i) {
            $this->match($user, 50 + $i);
        }
        $service = app(NotificationService::class);

        // 07:00 Los Angeles: before the default 07:30 delivery time.
        $before = Carbon::parse('2026-11-18 07:00', 'America/Los_Angeles')->utc();
        $this->assertFalse($service->digestIsDue($before));
        $this->assertFalse($service->sendDigest($user, false, $before)['sent']);

        $after = Carbon::parse('2026-11-18 07:31', 'America/Los_Angeles')->utc();
        $this->assertTrue($service->sendDigest($user, false, $after)['sent']);
        Mail::assertSent(DailyDigestMail::class, fn ($m) => $m->jobs->count() === 10 && $m->jobs->first()->match->match_score === 62);

        $this->assertFalse($service->sendDigest($user, false, $after->copy()->addHour())['sent']);
        Mail::assertSentCount(1);
    }

    public function test_digest_weekdays_and_off(): void
    {
        $service = app(NotificationService::class);
        $settings = app(SettingsService::class);
        $saturday = Carbon::parse('2026-11-21 09:00', 'America/Los_Angeles')->utc();

        $settings->set('notifications', array_merge(config('jobhunter.defaults.notifications'), ['digest_frequency' => 'weekdays']));
        $this->assertFalse($service->digestIsDue($saturday));

        $settings->set('notifications', array_merge(config('jobhunter.defaults.notifications'), ['digest_frequency' => 'off']));
        $this->assertFalse($service->digestIsDue($saturday->copy()->addDays(2)));
    }

    public function test_digest_mail_renders(): void
    {
        $user = $this->userWithProfile();
        $this->match($user, 88, ['salary_min' => 120000, 'salary_max' => 150000, 'salary_interval' => 'year']);
        $jobs = app(NotificationService::class)->digestJobs($user, 10);

        $html = (new DailyDigestMail($jobs, '2026-11-18'))->render();
        $this->assertStringContainsString('88%', $html);
        $this->assertStringContainsString('$120,000', $html);
        $this->assertStringContainsString('Apply on employer site', $html);
    }

    public function test_digest_command_respects_schedule(): void
    {
        Mail::fake();
        $this->userWithProfile();
        $this->travelTo(Carbon::parse('2026-11-18 06:00', 'America/Los_Angeles'));
        $this->artisan('jobhunter:digest')->assertSuccessful();
        Mail::assertNothingSent();

        $this->artisan('jobhunter:digest', ['--force' => true])->assertSuccessful();
        Mail::assertSent(DailyDigestMail::class);
    }
}

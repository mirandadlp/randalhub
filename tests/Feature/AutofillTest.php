<?php

namespace Tests\Feature;

use App\Jobs\LaunchAutofillJob;
use App\Models\Application;
use App\Models\AutofillSession;
use App\Models\JobListing;
use App\Services\Automation\AutofillService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AutofillTest extends TestCase
{
    use RefreshDatabase;

    private function setupApp(): array
    {
        $user = $this->userWithProfile();
        $job = JobListing::factory()->create(['application_url' => 'https://job-boards.greenhouse.io/acme/jobs/1']);
        $app = Application::create(['user_id' => $user->id, 'job_listing_id' => $job->id, 'status' => 'preparing']);

        return [$user, $app];
    }

    public function test_disabled_by_default(): void
    {
        [$user, $app] = $this->setupApp();
        $this->actingAs($user)->postJson("/api/applications/{$app->id}/autofill")->assertStatus(422);
    }

    public function test_full_flow_requires_approval_before_submit(): void
    {
        Queue::fake();
        app(SettingsService::class)->set('automation', ['autofill_enabled' => true, 'allow_resume_upload' => true, 'require_approval_before_submit' => true]);
        [$user, $app] = $this->setupApp();

        $result = app(AutofillService::class)->start($app->load('listing', 'user.profile'));
        $session = $result['session'];
        $token = $result['token'];
        Queue::assertPushed(LaunchAutofillJob::class);
        $this->assertSame('greenhouse', $session->adapter);
        $this->assertSame('ready', $app->fresh()->status);

        // Second start returns the active session instead of creating a duplicate.
        $this->assertSame($session->id, app(AutofillService::class)->start($app->fresh()->load('listing', 'user.profile'))['session']->id);

        // Script endpoints need the per-session token.
        $this->getJson("/api/automation/sessions/{$session->id}/poll")->assertForbidden();
        $this->getJson("/api/automation/sessions/{$session->id}/poll", ['X-Autofill-Token' => 'wrong'])->assertForbidden();

        // Cannot approve before the form is ready.
        $this->actingAs($user)->postJson("/api/autofill/{$session->id}/approve", ['confirm' => true])->assertStatus(422);

        $this->postJson("/api/automation/sessions/{$session->id}/report", ['status' => 'awaiting_approval', 'fields_filled' => ['email'], 'fields_skipped' => ['Work authorization']], ['X-Autofill-Token' => $token])->assertOk();

        // Approval requires explicit confirmation.
        $this->actingAs($user)->postJson("/api/autofill/{$session->id}/approve")->assertStatus(422);
        $this->actingAs($user)->postJson("/api/autofill/{$session->id}/approve", ['confirm' => true])->assertOk()->assertJsonPath('status', 'approved');
        $this->getJson("/api/automation/sessions/{$session->id}/poll", ['X-Autofill-Token' => $token])->assertJson(['status' => 'approved']);

        $this->postJson("/api/automation/sessions/{$session->id}/report", ['status' => 'submitted', 'message' => 'Confirmation detected'], ['X-Autofill-Token' => $token])->assertOk();
        $app->refresh();
        $this->assertSame('applied', $app->status);
        $this->assertSame('automation', $app->applied_confirmation);
        $this->assertNotNull(AutofillSession::sole()->submitted_at);
    }

    public function test_cancelled_session_cannot_be_reported_submitted(): void
    {
        Queue::fake();
        app(SettingsService::class)->set('automation', ['autofill_enabled' => true, 'allow_resume_upload' => true, 'require_approval_before_submit' => true]);
        [$user, $app] = $this->setupApp();
        ['session' => $session, 'token' => $token] = app(AutofillService::class)->start($app->load('listing', 'user.profile'));

        $this->actingAs($user)->postJson("/api/autofill/{$session->id}/cancel")->assertOk();
        $this->postJson("/api/automation/sessions/{$session->id}/report", ['status' => 'submitted'], ['X-Autofill-Token' => $token])->assertJson(['status' => 'cancelled']);
        $this->assertNotSame('applied', $app->fresh()->status);
    }

    public function test_adapter_detection(): void
    {
        $this->assertSame('lever', AutofillService::adapterFor('https://jobs.lever.co/acme/1/apply'));
        $this->assertSame('ashby', AutofillService::adapterFor('https://jobs.ashbyhq.com/acme/1/application'));
        $this->assertSame('generic', AutofillService::adapterFor('https://careers.example.com/job/1'));
    }
}

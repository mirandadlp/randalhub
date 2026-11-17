<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\JobListing;
use App\Services\Applications\ApplicationPackageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_cannot_mark_applied_without_confirmation(): void
    {
        $user = $this->userWithProfile();
        $job = JobListing::factory()->create();
        $this->actingAs($user)->postJson("/api/jobs/{$job->id}/track")->assertOk();
        $app = Application::sole();

        $this->actingAs($user)->postJson("/api/applications/{$app->id}/status", ['status' => 'applied'])
            ->assertStatus(422)->assertJsonValidationErrors('status');
        $this->assertSame('saved', $app->fresh()->status);

        $this->actingAs($user)->postJson("/api/applications/{$app->id}/status", ['status' => 'applied', 'confirm_submitted' => true, 'applied_at' => '2026-11-15'])
            ->assertOk();
        $app->refresh();
        $this->assertSame('applied', $app->status);
        $this->assertSame('manual', $app->applied_confirmation);
        $this->assertSame('2026-11-15', $app->applied_at->toDateString());
        $this->assertNotNull($app->next_follow_up_at);
        $this->assertSame(2, $app->events()->count());
    }

    public function test_one_application_per_job(): void
    {
        $user = $this->userWithProfile();
        $job = JobListing::factory()->create();
        $this->actingAs($user)->postJson("/api/jobs/{$job->id}/track");
        $this->actingAs($user)->postJson("/api/jobs/{$job->id}/save");
        $this->actingAs($user)->postJson("/api/jobs/{$job->id}/prepare", ['generate' => false]);

        $this->assertSame(1, Application::count());
        $this->assertSame('preparing', Application::sole()->status);
    }

    public function test_save_and_dismiss_and_pipeline_listing(): void
    {
        $user = $this->userWithProfile();
        $job = JobListing::factory()->create();

        $this->actingAs($user)->postJson("/api/jobs/{$job->id}/save")->assertOk();
        $this->assertDatabaseHas('saved_jobs', ['job_listing_id' => $job->id]);
        $this->actingAs($user)->getJson('/api/jobs?saved=1')->assertJsonCount(1, 'data');

        $this->actingAs($user)->postJson("/api/jobs/{$job->id}/dismiss")->assertOk();
        $this->actingAs($user)->getJson('/api/jobs')->assertJsonCount(0, 'data');
        $this->actingAs($user)->getJson('/api/jobs?include_dismissed=1')->assertJsonCount(1, 'data');

        $this->actingAs($user)->getJson('/api/applications')->assertOk()->assertJsonPath('data.0.status', 'saved');
    }

    public function test_tracking_details_update(): void
    {
        $user = $this->userWithProfile();
        $job = JobListing::factory()->create();
        $this->actingAs($user)->postJson("/api/jobs/{$job->id}/track");
        $app = Application::sole();

        $this->actingAs($user)->patchJson("/api/applications/{$app->id}", [
            'notes' => 'Referred by Sam', 'recruiter_name' => 'Jordan', 'recruiter_email' => 'jordan@acme.test',
            'interview_at' => '2026-12-01 10:00:00', 'next_follow_up_at' => '2026-11-25',
        ])->assertOk();
        $this->assertSame('Jordan', $app->fresh()->recruiter_name);
        $this->assertSame('2026-12-01', $app->fresh()->interview_at->toDateString());
    }

    public function test_package_generation_removes_unverified_claims(): void
    {
        $user = $this->userWithProfile();
        $job = JobListing::factory()->create();
        $app = Application::create(['user_id' => $user->id, 'job_listing_id' => $job->id, 'status' => 'saved']);
        $this->fakeAi()->push([
            'requirements_analysis' => ['summary' => 'Strong fit.', 'key_requirements' => [['requirement' => 'SaaS onboarding', 'status' => 'met', 'evidence' => 'Acme Advisory']]],
            'resume_recommendations' => ['Lead with onboarding outcomes'],
            'tailored_resume' => [
                'headline' => 'AI Implementation Leader', 'summary' => 'Consultant.',
                'core_skills' => ['AI enablement', 'Kubernetes administration'],
                'experience' => [
                    ['company' => 'Acme Advisory', 'title' => 'Principal AI Consultant', 'start' => '2021', 'end' => 'Present', 'bullets' => ['Led AI programs']],
                    ['company' => 'Google', 'title' => 'Staff Engineer', 'start' => '2010', 'end' => '2015', 'bullets' => ['Invented things']],
                ],
                'education' => [], 'certifications' => ['PMP'],
            ],
            'cover_letter_recommended' => true,
            'cover_letter' => 'Dear hiring team...',
            'qa_answers' => [['question' => 'Are you authorized to work in the US?', 'answer' => '', 'needs_user_input' => true]],
        ]);

        $result = app(ApplicationPackageService::class)->generate($app->load('listing', 'user.profile'));

        $resume = json_decode($app->documents()->where('type', 'tailored_resume')->value('content'), true);
        $this->assertSame(['AI enablement'], $resume['core_skills']);
        $this->assertCount(1, $resume['experience']);
        $this->assertSame([], $resume['certifications']);
        $this->assertCount(3, $result['removed_unverified']);
        $this->assertSame(5, $app->documents()->count());
        $this->assertSame('preparing', $app->fresh()->status);
    }

    public function test_document_edit_creates_version_and_exports(): void
    {
        $user = $this->userWithProfile();
        $job = JobListing::factory()->create();
        $app = Application::create(['user_id' => $user->id, 'job_listing_id' => $job->id, 'status' => 'preparing']);
        $content = json_encode(['headline' => 'H', 'summary' => 'S', 'core_skills' => ['AI enablement'], 'experience' => [['company' => 'Acme Advisory', 'title' => 'Principal AI Consultant', 'start' => '2021', 'end' => 'Present', 'bullets' => ['Led']]], 'education' => [], 'certifications' => []]);

        $this->actingAs($user)->postJson("/api/applications/{$app->id}/documents", ['type' => 'tailored_resume', 'content' => $content])->assertOk()->assertJsonPath('version', 1);
        $this->actingAs($user)->postJson("/api/applications/{$app->id}/documents", ['type' => 'tailored_resume', 'content' => $content])->assertJsonPath('version', 2);
        $this->actingAs($user)->postJson("/api/applications/{$app->id}/documents", ['type' => 'tailored_resume', 'content' => 'not json'])->assertStatus(422);

        $doc = $app->documents()->latest('version')->first();
        $pdf = $this->actingAs($user)->get("/api/documents/{$doc->id}/download?format=pdf");
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $docx = $this->actingAs($user)->get("/api/documents/{$doc->id}/download?format=docx");
        $docx->assertOk();
        $this->assertStringStartsWith('PK', $docx->getContent());
    }
}

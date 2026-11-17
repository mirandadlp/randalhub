<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\JobListing;
use App\Models\JobMatch;
use App\Models\JobSource;
use App\Models\SystemSetting;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shows_real_counts_only(): void
    {
        $user = $this->userWithProfile();
        $this->actingAs($user)->getJson('/api/dashboard')->assertOk()
            ->assertJsonPath('stats.total_jobs', 0)
            ->assertJsonPath('stats.high_match', 0)
            ->assertJsonPath('health.ai_configured', false)
            ->assertJsonPath('top_matches', []);

        $job = JobListing::factory()->create();
        JobMatch::create(['user_id' => $user->id, 'job_listing_id' => $job->id, 'status' => 'completed', 'match_score' => 90, 'priority_score' => 80, 'application_priority' => 'high']);
        $this->actingAs($user)->getJson('/api/dashboard')->assertJsonPath('stats.total_jobs', 1)
            ->assertJsonPath('stats.high_match', 1)->assertJsonPath('stats.priority_applications', 1)
            ->assertJsonCount(1, 'top_matches');
    }

    public function test_job_filters_and_sorting(): void
    {
        $user = $this->userWithProfile();
        $company = Company::factory()->create(['industry' => 'Fintech']);
        $a = JobListing::factory()->create(['title' => 'AI Consultant', 'remote_type' => 'remote', 'salary_max' => 200000, 'salary_interval' => 'year', 'company_id' => $company->id, 'industry' => 'Fintech', 'first_seen_at' => now()->subDays(2)]);
        $b = JobListing::factory()->create(['title' => 'Implementation Manager', 'remote_type' => 'onsite', 'is_nevada' => true, 'location' => 'Las Vegas, NV', 'salary_max' => 60, 'salary_interval' => 'hour']);
        $c = JobListing::factory()->create(['title' => 'Duplicate', 'duplicate_of_id' => $a->id]);
        JobMatch::create(['user_id' => $user->id, 'job_listing_id' => $b->id, 'status' => 'completed', 'match_score' => 95, 'priority_score' => 70]);
        JobMatch::create(['user_id' => $user->id, 'job_listing_id' => $a->id, 'status' => 'completed', 'match_score' => 60, 'priority_score' => 85]);

        $ids = fn ($q) => collect($this->actingAs($user)->getJson('/api/jobs?'.$q)->assertOk()->json('data'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $ids(''), 'duplicates hidden');
        $this->assertSame([$a->id], $ids('remote[]=remote'));
        $this->assertSame([$b->id], $ids('location=nevada'));
        $this->assertSame([$b->id], $ids('min_match=90'));
        $this->assertSame([$a->id, $b->id], $ids('min_salary=110000&sort=salary'), 'hourly annualized: $60/hr = $124,800');
        $this->assertSame([$a->id], $ids('min_salary=150000'));
        $this->assertSame([$a->id], $ids('industry[]=Fintech'));
        $this->assertSame([$a->id], $ids('company_id[]='.$company->id));
        $this->assertSame([$b->id, $a->id], $ids('sort=match'));
        $this->assertSame([$a->id, $b->id], $ids('sort=priority'));
        $this->assertSame([$b->id, $a->id], $ids('sort=newest'));
        $this->assertSame([$a->id], $ids('q=consult'));
        $this->assertSame([$b->id], $ids('posted_within=1'));
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $ids('application_status[]=discovered'));

        $this->actingAs($user)->getJson('/api/jobs?sort=bogus')->assertStatus(422);
        $this->actingAs($user)->getJson('/api/jobs/filters')->assertOk()->assertJsonPath('industries', ['Fintech']);
    }

    public function test_job_detail_shows_verified_and_missing_data_honestly(): void
    {
        $user = $this->userWithProfile();
        $job = JobListing::factory()->create(['posted_at' => null, 'description_html' => '<p>Hi</p>']);

        $this->actingAs($user)->getJson("/api/jobs/{$job->id}")->assertOk()
            ->assertJsonPath('data.competition.applicant_count', null)
            ->assertJsonPath('data.posted_at', null)
            ->assertJsonPath('data.age_basis', 'first_seen')
            ->assertJsonPath('data.analysis', null)
            ->assertJsonPath('data.description_html', '<p>Hi</p>');
    }

    public function test_analyze_endpoint_reports_missing_configuration(): void
    {
        $user = $this->userWithProfile();
        $job = JobListing::factory()->create();
        $this->actingAs($user)->postJson("/api/jobs/{$job->id}/analyze")->assertStatus(422)->assertJsonPath('kind', 'not_configured');
    }

    public function test_settings_update_and_secrets_are_encrypted(): void
    {
        $user = $this->userWithProfile();
        $this->actingAs($user)->putJson('/api/settings', [
            'minimum_salary' => 130000,
            'priority_weights' => ['qualification' => 50],
            'notifications' => ['digest_time' => '06:45'],
            'automation' => ['autofill_enabled' => true, 'require_approval_before_submit' => false],
        ])->assertOk()
            ->assertJsonPath('settings.minimum_salary', 130000)
            ->assertJsonPath('settings.priority_weights.qualification', 50)
            ->assertJsonPath('settings.priority_weights.freshness', 25)
            ->assertJsonPath('settings.notifications.digest_time', '06:45')
            ->assertJsonPath('settings.notifications.digest_size', 10)
            ->assertJsonPath('settings.automation.require_approval_before_submit', true);

        $this->actingAs($user)->putJson('/api/settings', ['notifications' => ['digest_time' => '25:99']])->assertStatus(422);

        $this->actingAs($user)->putJson('/api/settings/secrets', ['anthropic_api_key' => 'sk-ant-secret-value'])->assertOk()
            ->assertJsonPath('secrets.anthropic_api_key', 'settings')
            ->assertJsonMissing(['sk-ant-secret-value']);
        $raw = SystemSetting::where('key', 'anthropic_api_key')->value('value');
        $this->assertStringNotContainsString('sk-ant-secret-value', $raw);
        $this->assertSame('sk-ant-secret-value', app(SettingsService::class)->anthropicApiKey());
    }

    public function test_companies_sources_and_detection(): void
    {
        $user = $this->userWithProfile();
        Http::fake([
            'boards-api.greenhouse.io/v1/boards/newco/jobs*' => Http::response(['jobs' => [['id' => 1, 'title' => 'X', 'absolute_url' => 'https://job-boards.greenhouse.io/newco/jobs/1']]]),
            '*' => Http::response('', 404),
        ]);

        $this->actingAs($user)->postJson('/api/companies/detect', ['name' => 'NewCo Inc.'])->assertOk()
            ->assertJsonPath('boards.0.type', 'greenhouse')->assertJsonPath('boards.0.identifier', 'newco');

        $company = $this->actingAs($user)->postJson('/api/companies', ['name' => 'NewCo', 'industry' => 'SaaS'])->assertCreated()->json();
        $this->actingAs($user)->postJson("/api/companies/{$company['id']}/sources", ['type' => 'greenhouse', 'identifier' => 'newco'])->assertCreated();
        $this->actingAs($user)->postJson("/api/companies/{$company['id']}/sources", ['type' => 'career_page', 'identifier' => 'http://localhost/admin'])->assertStatus(422);

        $this->actingAs($user)->patchJson("/api/companies/{$company['id']}", ['is_excluded' => true])->assertOk();
        $this->assertFalse(JobSource::where('identifier', 'newco')->value('enabled'));

        $this->actingAs($user)->getJson('/api/companies?filter=excluded')->assertJsonCount(1, 'data');
        $this->actingAs($user)->getJson('/api/sources')->assertOk();
        $this->actingAs($user)->getJson('/api/scans')->assertOk();
        $this->actingAs($user)->getJson('/api/import-errors')->assertOk();
    }

    public function test_system_monitoring_endpoint(): void
    {
        $user = $this->userWithProfile();
        $this->artisan('jobhunter:dedupe');
        $this->actingAs($user)->getJson('/api/system')->assertOk()
            ->assertJsonStructure(['scheduler_heartbeat', 'queue' => ['pending', 'failed', 'failed_count'], 'tasks', 'scans', 'sources', 'api_usage' => ['month', 'month_cost', 'budget'], 'notifications'])
            ->assertJsonPath('tasks.0.task', 'dedupe');
    }

    public function test_profile_update_flags_qualification_changes(): void
    {
        $user = $this->userWithProfile();
        $this->actingAs($user)->putJson('/api/profile', ['phone' => '702-555-0111'])->assertOk()->assertJsonPath('qualifications_changed', false);
        $this->actingAs($user)->putJson('/api/profile', ['summary' => 'New summary'])->assertOk()->assertJsonPath('qualifications_changed', true);
        $this->actingAs($user)->postJson('/api/profile/verify')->assertOk()->assertJsonPath('is_verified', true);
    }
}

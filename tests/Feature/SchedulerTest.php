<?php

namespace Tests\Feature;

use App\Models\JobListing;
use App\Models\JobSource;
use App\Models\ScanRun;
use App\Models\TaskRun;
use App\Services\Discovery\ScanCoordinator;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SchedulerTest extends TestCase
{
    use RefreshDatabase;

    private function events(): array
    {
        return collect(app(Schedule::class)->events())
            ->mapWithKeys(fn ($e) => [($e->command ? preg_replace("/^.*artisan'? /", '', $e->command) : $e->description) => $e])->all();
    }

    public function test_required_tasks_are_scheduled(): void
    {
        $events = $this->events();
        $this->assertSame('0 * * * *', $events['jobhunter:discover']->expression);
        $this->assertSame('*/5 * * * *', $events['jobhunter:digest']->expression);
        foreach (['jobhunter:alerts', 'jobhunter:dedupe', 'jobhunter:check-availability', 'jobhunter:cleanup', 'jobhunter:recompute-priority', 'jobhunter:follow-ups', 'scheduler-heartbeat'] as $task) {
            $this->assertArrayHasKey($task, $events, "$task is scheduled");
        }
        $this->assertTrue($events['jobhunter:discover']->withoutOverlapping);
    }

    public function test_discovery_runs_every_six_hours(): void
    {
        $coordinator = app(ScanCoordinator::class);
        $this->assertTrue($coordinator->isDue());

        ScanRun::create(['trigger' => 'scheduled', 'status' => 'completed', 'started_at' => now()->subHours(2)]);
        $this->assertFalse($coordinator->isDue());

        $this->travel(5)->hours();
        $this->assertTrue($coordinator->isDue());
    }

    public function test_discover_command_runs_full_pipeline_synchronously(): void
    {
        Http::fake(['boards-api.greenhouse.io/*' => Http::response(['jobs' => [
            ['id' => 1, 'title' => 'AI Enablement Manager', 'absolute_url' => 'https://job-boards.greenhouse.io/acme/jobs/1', 'location' => ['name' => 'Remote - US'], 'content' => 'Drive AI adoption.'],
        ]])]);
        JobSource::factory()->create(['identifier' => 'acme']);

        $this->artisan('jobhunter:discover', ['--sync' => true])->assertSuccessful();

        $run = ScanRun::sole();
        $this->assertSame('completed', $run->status);
        $this->assertSame(1, $run->jobs_new);
        $this->assertSame(1, JobListing::count());
        $this->assertSame('succeeded', TaskRun::where('task', 'discover')->value('status'));
        $this->assertSame('healthy', JobSource::sole()->health);
    }

    public function test_duplicate_scans_are_prevented(): void
    {
        JobSource::factory()->create();
        ScanRun::create(['trigger' => 'scheduled', 'status' => 'running', 'started_at' => now()->subMinutes(10)]);

        $this->assertNull(app(ScanCoordinator::class)->start('manual'));
        $this->artisan('jobhunter:discover', ['--force' => true])->expectsOutputToContain('already running')->assertSuccessful();
        $this->assertSame(1, ScanRun::count());
    }

    public function test_abandoned_runs_are_released(): void
    {
        ScanRun::create(['trigger' => 'scheduled', 'status' => 'running', 'started_at' => now()->subHours(4)]);
        $this->assertNull(app(ScanCoordinator::class)->activeRun());
        $this->assertSame('failed', ScanRun::first()->status);
    }

    public function test_partial_runs_when_some_sources_fail(): void
    {
        Http::fake([
            'boards-api.greenhouse.io/v1/boards/good/*' => Http::response(['jobs' => []]),
            'boards-api.greenhouse.io/v1/boards/bad/*' => Http::response('err', 500),
        ]);
        JobSource::factory()->create(['identifier' => 'good']);
        JobSource::factory()->create(['identifier' => 'bad']);

        $this->artisan('jobhunter:discover', ['--sync' => true, '--force' => true])->assertSuccessful();
        $run = ScanRun::sole();
        $this->assertSame('partial', $run->status);
        $this->assertSame(1, $run->sources_failed);
        $this->assertNotNull($run->error_summary);
    }

    public function test_cleanup_archives_old_closed_listings_but_keeps_tracked(): void
    {
        $user = $this->userWithProfile();
        $old = JobListing::factory()->create(['status' => 'closed', 'closed_at' => now()->subDays(40)]);
        $tracked = JobListing::factory()->create(['status' => 'closed', 'closed_at' => now()->subDays(40)]);
        $user->savedJobs()->create(['job_listing_id' => $tracked->id]);

        $this->artisan('jobhunter:cleanup')->assertSuccessful();
        $this->assertSame('archived', $old->fresh()->status);
        $this->assertSame('closed', $tracked->fresh()->status);
    }

    public function test_availability_check_closes_removed_postings(): void
    {
        Http::fake([
            'job-boards.greenhouse.io/robots.txt' => Http::response('', 404),
            'job-boards.greenhouse.io/test/jobs/1' => Http::response('gone', 404),
            'job-boards.greenhouse.io/test/jobs/2' => Http::response('ok', 200),
        ]);
        $gone = JobListing::factory()->create(['source_url' => 'https://job-boards.greenhouse.io/test/jobs/1', 'last_seen_at' => now()->subDays(3)]);
        $live = JobListing::factory()->create(['source_url' => 'https://job-boards.greenhouse.io/test/jobs/2', 'last_seen_at' => now()->subDays(3)]);

        $this->artisan('jobhunter:check-availability')->assertSuccessful();
        $this->assertSame('closed', $gone->fresh()->status);
        $this->assertSame('open', $live->fresh()->status);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\JobListing;
use App\Models\JobSource;
use App\Models\JobSourceObservation;
use App\Services\Discovery\DeduplicationService;
use App\Services\Discovery\JobIngestionService;
use App\Services\Discovery\NormalizedJob;
use App\Services\Discovery\SourceScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IngestionTest extends TestCase
{
    use RefreshDatabase;

    private function job(string $id, array $o = []): NormalizedJob
    {
        return new NormalizedJob(...array_merge([
            'externalId' => $id,
            'title' => 'Implementation Consultant',
            'companyName' => 'Acme',
            'sourceUrl' => "https://job-boards.greenhouse.io/acme/jobs/$id",
            'location' => 'Remote - US',
            'workplaceHint' => 'Remote',
            'descriptionText' => 'Lead enterprise SaaS onboarding and implementation.',
            'postedAt' => new \DateTimeImmutable('2026-11-10'),
        ], $o));
    }

    private function ingestion(): JobIngestionService
    {
        return app(JobIngestionService::class);
    }

    public function test_new_unchanged_and_updated(): void
    {
        $source = JobSource::factory()->create();

        $this->assertSame('new', $this->ingestion()->ingest($source, $this->job('1')));
        $this->assertSame('unchanged', $this->ingestion()->ingest($source, $this->job('1')));
        $this->assertSame('updated', $this->ingestion()->ingest($source, $this->job('1', ['descriptionText' => 'Changed text'])));

        $listing = JobListing::sole();
        $this->assertSame('Changed text', $listing->description_text);
        $this->assertNotNull($listing->content_changed_at);
        $this->assertSame('remote', $listing->remote_type);
        $this->assertTrue($listing->is_us);
        $this->assertSame('2026-11-10', $listing->posted_at->toDateString());
        $this->assertSame(1, JobSourceObservation::count());
    }

    public function test_first_seen_is_never_moved(): void
    {
        $source = JobSource::factory()->create();
        $this->travelTo(now()->subDays(5));
        $this->ingestion()->ingest($source, $this->job('1'));
        $this->travelBack();
        $this->ingestion()->ingest($source, $this->job('1', ['descriptionText' => 'new']));

        $this->assertTrue(JobListing::sole()->first_seen_at->lt(now()->subDays(4)));
    }

    public function test_same_url_from_two_sources_becomes_one_listing(): void
    {
        $a = JobSource::factory()->create();
        $b = JobSource::factory()->create(['type' => 'career_page', 'identifier' => 'https://acme.com/careers']);

        $this->ingestion()->ingest($a, $this->job('1'));
        $this->ingestion()->ingest($b, $this->job('xyz', ['sourceUrl' => 'https://job-boards.greenhouse.io/acme/jobs/1?gh_src=abc']));

        $this->assertSame(1, JobListing::count());
        $this->assertSame(2, JobSourceObservation::count());
    }

    public function test_unsafe_urls_and_excluded_companies_are_skipped(): void
    {
        $source = JobSource::factory()->create();
        $this->assertSame('skipped', $this->ingestion()->ingest($source, $this->job('1', ['sourceUrl' => 'javascript:alert(1)'])));

        $source->company->update(['is_excluded' => true]);
        $this->assertSame('skipped', $this->ingestion()->ingest($source->fresh('company'), $this->job('2')));
        $this->assertSame(0, JobListing::count());
    }

    public function test_listing_closes_after_missed_scans_and_reopens(): void
    {
        $source = JobSource::factory()->create();
        $this->ingestion()->ingest($source, $this->job('1'));
        $this->ingestion()->ingest($source, $this->job('2'));

        $this->ingestion()->markMissing($source, ['1' => true]);
        $this->assertSame('open', JobListing::where('source_url', 'like', '%/2')->value('status'), 'first miss keeps it open');

        $closed = $this->ingestion()->markMissing($source, ['1' => true]);
        $this->assertSame(1, $closed);
        $this->assertSame('closed', JobListing::where('source_url', 'like', '%/2')->value('status'));

        $this->ingestion()->ingest($source, $this->job('2'));
        $this->assertSame('open', JobListing::where('source_url', 'like', '%/2')->value('status'));
    }

    public function test_cross_source_duplicates_and_reposts(): void
    {
        $gh = JobSource::factory()->create(['company_id' => Company::factory()->create(['name' => 'Acme'])->id]);
        $agg = JobSource::factory()->create(['type' => 'adzuna', 'identifier' => 'x', 'company_id' => null]);
        $this->ingestion()->ingest($gh, $this->job('1'));
        $this->travel(1)->hours();
        $this->ingestion()->ingest($agg, $this->job('99', ['sourceUrl' => 'https://www.adzuna.com/details/99']));

        $result = app(DeduplicationService::class)->run();
        $this->assertSame(1, $result['duplicates']);
        $original = JobListing::where('primary_source_id', $gh->id)->first();
        $this->assertSame($original->id, JobListing::where('primary_source_id', $agg->id)->value('duplicate_of_id'));
        $this->assertFalse((bool) JobListing::where('primary_source_id', $agg->id)->value('is_direct_employer'));

        // Repost: the canonical one closes, then the same role reappears under a new id.
        $original->update(['status' => 'closed', 'closed_at' => now()->subDays(10)]);
        JobListing::where('primary_source_id', $agg->id)->update(['status' => 'closed', 'closed_at' => now()->subDays(10)]);
        $this->ingestion()->ingest($gh, $this->job('555', ['sourceUrl' => 'https://job-boards.greenhouse.io/acme/jobs/555']));
        app(DeduplicationService::class)->run();

        $repost = JobListing::where('source_url', 'like', '%/555')->first();
        $this->assertTrue($repost->is_repost);
        $this->assertNotNull($repost->repost_of_id);
    }

    public function test_scanner_records_failure_and_health_without_throwing(): void
    {
        Http::fake(['boards-api.greenhouse.io/*' => Http::response('down', 500)]);
        $source = JobSource::factory()->create(['identifier' => 'acme']);

        $result = app(SourceScanner::class)->scan($source);

        $this->assertSame('failed', $result['status']);
        $source->refresh();
        $this->assertSame('degraded', $source->health);
        $this->assertSame(1, $source->consecutive_failures);
        $this->assertDatabaseHas('import_errors', ['job_source_id' => $source->id, 'error_type' => 'http']);
    }

    public function test_scanner_does_not_close_everything_on_suspicious_empty_response(): void
    {
        $source = JobSource::factory()->create(['identifier' => 'acme', 'last_job_count' => 40]);
        $this->ingestion()->ingest($source, $this->job('1'));
        Http::fake(['boards-api.greenhouse.io/*' => Http::response(['jobs' => []])]);

        app(SourceScanner::class)->scan($source);
        app(SourceScanner::class)->scan($source->fresh());

        $this->assertSame('open', JobListing::sole()->status);
        $this->assertSame('degraded', $source->fresh()->health);
    }

    public function test_search_api_companies_are_created_unwatched(): void
    {
        $agg = JobSource::factory()->create(['type' => 'adzuna', 'identifier' => 'x', 'company_id' => null]);
        $this->ingestion()->ingest($agg, $this->job('1', ['companyName' => 'Tiny Mortgage Tech LLC', 'sourceUrl' => 'https://www.adzuna.com/details/1']));

        $company = Company::where('name', 'Tiny Mortgage Tech LLC')->sole();
        $this->assertFalse($company->is_watched);
        $this->assertSame('search_api', $company->discovered_via);
    }
}

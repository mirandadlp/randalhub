<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\JobSource;
use App\Services\Discovery\ConnectorRegistry;
use App\Services\Discovery\Connectors\ConnectorException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConnectorTest extends TestCase
{
    use RefreshDatabase;

    private function source(string $type, string $identifier, array $config = []): JobSource
    {
        return JobSource::factory()->create([
            'type' => $type, 'identifier' => $identifier, 'config' => $config,
            'company_id' => Company::factory()->create(['name' => 'Acme'])->id,
        ]);
    }

    private function fetch(JobSource $source, int $limit = 100): array
    {
        return iterator_to_array(app(ConnectorRegistry::class)->for($source->type)->fetch($source, $limit), false);
    }

    public function test_greenhouse_maps_fields_and_salary(): void
    {
        Http::fake(['boards-api.greenhouse.io/v1/boards/acme/jobs*' => Http::response(['jobs' => [[
            'id' => 101, 'title' => 'Implementation Manager', 'absolute_url' => 'https://job-boards.greenhouse.io/acme/jobs/101',
            'location' => ['name' => 'Remote - US'], 'company_name' => 'Acme',
            'first_published' => '2026-11-10T10:00:00-05:00', 'updated_at' => '2026-11-12T10:00:00-05:00',
            'metadata' => [['name' => 'Location Type', 'value' => 'Remote']],
            'departments' => [['name' => 'Professional Services']],
            'content' => '&lt;p&gt;Lead onboarding. The salary range is $120,000 - $150,000 USD.&lt;/p&gt;&lt;script&gt;x&lt;/script&gt;',
        ]], 'meta' => ['total' => 1]])]);

        [$job] = $this->fetch($this->source('greenhouse', 'acme'));

        $this->assertSame('101', $job->externalId);
        $this->assertSame('Implementation Manager', $job->title);
        $this->assertSame('Remote', $job->workplaceHint);
        $this->assertSame(120000, $job->salaryMin);
        $this->assertSame(150000, $job->salaryMax);
        $this->assertSame('2026-11-10', $job->postedAt->format('Y-m-d'));
        $this->assertStringNotContainsString('<script', $job->descriptionHtml);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'content=true') && str_contains($r->header('User-Agent')[0], 'AIJobHunter'));
    }

    public function test_lever_paginates_and_uses_salary_range(): void
    {
        $page = fn ($start, $n) => collect(range($start, $start + $n - 1))->map(fn ($i) => [
            'id' => "id-$i", 'text' => "Customer Success Manager $i", 'hostedUrl' => "https://jobs.lever.co/acme/id-$i",
            'applyUrl' => "https://jobs.lever.co/acme/id-$i/apply", 'createdAt' => 1762000000000,
            'categories' => ['location' => 'Las Vegas, NV', 'commitment' => 'Full-time', 'team' => 'CS'],
            'workplaceType' => 'onsite', 'descriptionPlain' => 'Help customers.', 'lists' => [['text' => 'Requirements', 'content' => '<li>5 years</li>']],
            'salaryRange' => ['min' => 90000, 'max' => 110000, 'currency' => 'USD', 'interval' => 'per-year-salary'],
        ])->all();

        Http::fake([
            'api.lever.co/v0/postings/acme?mode=json&skip=0&limit=100' => Http::response($page(0, 100)),
            'api.lever.co/v0/postings/acme?mode=json&skip=100&limit=100' => Http::response($page(100, 5)),
        ]);

        $jobs = $this->fetch($this->source('lever', 'acme'), 500);

        $this->assertCount(105, $jobs);
        $this->assertSame(90000, $jobs[0]->salaryMin);
        $this->assertStringContainsString('5 years', $jobs[0]->descriptionText);
        Http::assertSentCount(2);
    }

    public function test_lever_respects_source_limit(): void
    {
        Http::fake(['api.lever.co/*' => Http::response(collect(range(1, 100))->map(fn ($i) => ['id' => "x$i", 'text' => 'Role', 'hostedUrl' => "https://jobs.lever.co/a/x$i"])->all())]);

        $this->assertCount(10, $this->fetch($this->source('lever', 'acme'), 10));
        Http::assertSentCount(1);
    }

    public function test_ashby_skips_unlisted_and_reads_compensation(): void
    {
        Http::fake(['api.ashbyhq.com/*' => Http::response(['jobs' => [
            ['id' => 'a1', 'title' => 'AI Solutions Consultant', 'jobUrl' => 'https://jobs.ashbyhq.com/acme/a1', 'applyUrl' => 'https://jobs.ashbyhq.com/acme/a1/application',
                'location' => 'Remote', 'isRemote' => true, 'workplaceType' => 'Remote', 'isListed' => true, 'publishedAt' => '2026-11-15T00:00:00Z',
                'descriptionPlain' => 'Advise customers.', 'address' => ['postalAddress' => ['addressCountry' => 'United States']],
                'compensation' => ['summaryComponents' => [['compensationType' => 'Salary', 'interval' => '1 YEAR', 'currencyCode' => 'USD', 'minValue' => 140000, 'maxValue' => 180000]]]],
            ['id' => 'a2', 'title' => 'Hidden', 'jobUrl' => 'https://jobs.ashbyhq.com/acme/a2', 'isListed' => false],
        ]])]);

        $jobs = $this->fetch($this->source('ashby', 'acme'));

        $this->assertCount(1, $jobs);
        $this->assertSame(180000, $jobs[0]->salaryMax);
        $this->assertSame('United States', $jobs[0]->country);
        $this->assertTrue($jobs[0]->remoteFlag);
    }

    public function test_smartrecruiters_fetches_details_only_for_relevant_titles(): void
    {
        Http::fake([
            'api.smartrecruiters.com/v1/companies/Acme/postings?*' => Http::response(['totalFound' => 2, 'content' => [
                ['id' => 'p1', 'name' => 'Implementation Consultant', 'releasedDate' => '2026-11-01T00:00:00Z', 'location' => ['city' => 'Austin', 'region' => 'TX', 'country' => 'us', 'remote' => true], 'company' => ['identifier' => 'Acme', 'name' => 'Acme']],
                ['id' => 'p2', 'name' => 'Forklift Operator', 'location' => ['city' => 'Reno', 'country' => 'us'], 'company' => ['identifier' => 'Acme', 'name' => 'Acme']],
            ]]),
            'api.smartrecruiters.com/v1/companies/Acme/postings/p1' => Http::response(['postingUrl' => 'https://jobs.smartrecruiters.com/Acme/p1', 'applyUrl' => 'https://jobs.smartrecruiters.com/Acme/p1?apply',
                'jobAd' => ['sections' => ['jobDescription' => ['title' => 'Role', 'text' => '<p>Lead implementations.</p>']]]]),
        ]);

        $jobs = $this->fetch($this->source('smartrecruiters', 'Acme'));

        $this->assertCount(2, $jobs);
        $this->assertStringContainsString('Lead implementations', $jobs[0]->descriptionText);
        $this->assertSame('', $jobs[1]->descriptionText);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/postings/p2'));
    }

    public function test_workable_maps_jobs(): void
    {
        Http::fake(['apply.workable.com/*' => Http::response(['name' => 'Acme', 'jobs' => [
            ['shortcode' => 'ABC', 'title' => 'Onboarding Lead', 'url' => 'https://apply.workable.com/j/ABC', 'telecommuting' => true, 'published_on' => '2026-11-01', 'description' => '<p>Onboard customers</p>', 'locations' => [['city' => 'Denver', 'region' => 'CO', 'country' => 'United States', 'countryCode' => 'US']]],
        ]])]);

        [$job] = $this->fetch($this->source('workable', 'acme'));
        $this->assertSame('remote', $job->workplaceHint);
        $this->assertSame('US', $job->country);
    }

    public function test_career_page_reads_json_ld_job_postings(): void
    {
        $ld = json_encode(['@context' => 'https://schema.org', '@graph' => [[
            '@type' => 'JobPosting', 'title' => 'Hospitality Technology Consultant', 'datePosted' => '2026-11-14',
            'url' => 'https://careers.example.com/jobs/77', 'identifier' => ['@type' => 'PropertyValue', 'value' => 'REQ-77'],
            'hiringOrganization' => ['@type' => 'Organization', 'name' => 'Example Hotels'],
            'jobLocationType' => 'TELECOMMUTE', 'description' => '<p>Advise properties.</p>',
            'baseSalary' => ['@type' => 'MonetaryAmount', 'currency' => 'USD', 'value' => ['@type' => 'QuantitativeValue', 'minValue' => 95000, 'maxValue' => 125000, 'unitText' => 'YEAR']],
        ]]]);
        Http::fake([
            'careers.example.com/robots.txt' => Http::response("User-agent: *\nDisallow: /admin", 200),
            'careers.example.com/jobs' => Http::response("<html><script type=\"application/ld+json\">{$ld}</script></html>"),
        ]);

        [$job] = $this->fetch($this->source('career_page', 'https://careers.example.com/jobs'));
        $this->assertSame('REQ-77', $job->externalId);
        $this->assertSame('Example Hotels', $job->companyName);
        $this->assertSame(125000, $job->salaryMax);
        $this->assertTrue($job->remoteFlag);
    }

    public function test_career_page_respects_robots_txt(): void
    {
        Http::fake([
            'blocked.example.com/robots.txt' => Http::response("User-agent: *\nDisallow: /", 200),
            'blocked.example.com/*' => Http::response('<html></html>'),
        ]);

        try {
            $this->fetch($this->source('career_page', 'https://blocked.example.com/careers'));
            $this->fail('Expected robots exception');
        } catch (ConnectorException $e) {
            $this->assertSame('robots', $e->kind);
            $this->assertTrue($e->permanent);
        }
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/careers'));
    }

    public function test_career_page_stops_on_captcha(): void
    {
        Http::fake([
            'shop.example.com/robots.txt' => Http::response('', 404),
            'shop.example.com/careers' => Http::response('<html><div class="g-recaptcha" data-sitekey="x"></div></html>'),
        ]);

        $this->expectException(ConnectorException::class);
        try {
            $this->fetch($this->source('career_page', 'https://shop.example.com/careers'));
        } catch (ConnectorException $e) {
            $this->assertSame('blocked', $e->kind);
            throw $e;
        }
    }

    public function test_missing_board_is_permanent_and_forbidden_is_blocked(): void
    {
        Http::fake([
            'boards-api.greenhouse.io/v1/boards/missing/*' => Http::response(['status' => 404], 404),
            'boards-api.greenhouse.io/v1/boards/private/*' => Http::response('nope', 403),
        ]);

        foreach (['missing' => ['http', true], 'private' => ['blocked', true]] as $id => [$kind, $permanent]) {
            try {
                $this->fetch($this->source('greenhouse', $id));
                $this->fail('expected exception');
            } catch (ConnectorException $e) {
                $this->assertSame($kind, $e->kind);
                $this->assertSame($permanent, $e->permanent);
            }
        }
    }

    public function test_adzuna_requires_credentials_and_discards_predicted_salary(): void
    {
        $source = JobSource::factory()->create(['type' => 'adzuna', 'identifier' => 'AI consultant', 'company_id' => null]);
        try {
            $this->fetch($source);
            $this->fail('expected config exception');
        } catch (ConnectorException $e) {
            $this->assertSame('config', $e->kind);
        }

        config(['jobhunter.adzuna.app_id' => 'id', 'jobhunter.adzuna.app_key' => 'key']);
        Http::fake(['api.adzuna.com/*' => Http::response(['results' => [
            ['id' => '9', 'title' => 'AI Consultant', 'company' => ['display_name' => 'Smallco'], 'redirect_url' => 'https://www.adzuna.com/details/9', 'salary_min' => 100000, 'salary_max' => 120000, 'salary_is_predicted' => '1', 'created' => '2026-11-15T00:00:00Z'],
        ]])]);
        [$job] = $this->fetch($source);
        $this->assertNull($job->salaryMax);
        $this->assertSame('Smallco', $job->companyName);
    }
}

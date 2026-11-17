<?php

namespace Database\Factories;

use App\Models\JobListing;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** Test fixtures only — production listings always come from real sources. @extends Factory<JobListing> */
class JobListingFactory extends Factory
{
    protected $model = JobListing::class;

    public function definition(): array
    {
        $url = 'https://job-boards.greenhouse.io/test/jobs/'.$this->faker->unique()->numberBetween(1000, 999999);
        $title = 'Implementation Consultant';

        return [
            'company_name' => 'Test Co',
            'title' => $title,
            'normalized_title' => Str::lower($title),
            'location' => 'Remote - US',
            'is_us' => true,
            'remote_type' => 'remote',
            'description_text' => 'Lead SaaS onboarding and implementation for enterprise customers.',
            'source_url' => $url,
            'application_url' => $url,
            'canonical_url' => $url,
            'url_hash' => hash('sha256', $url),
            'fingerprint' => hash('sha256', $url.'fp'),
            'content_hash' => hash('sha256', $url.'content'),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'status' => 'open',
            'is_direct_employer' => true,
            'prescreen_score' => 70,
        ];
    }
}

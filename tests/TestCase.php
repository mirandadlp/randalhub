<?php

namespace Tests;

use App\Models\ProfessionalProfile;
use App\Models\User;
use App\Services\Ai\AiClient;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Fakes\FakeAiClient;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['jobhunter.http.retries' => 0, 'jobhunter.http.request_delay_ms' => 0]);
    }

    protected function fakeAi(): FakeAiClient
    {
        $fake = new FakeAiClient;
        $this->app->instance(AiClient::class, $fake);

        return $fake;
    }

    protected function userWithProfile(array $profile = []): User
    {
        $user = User::factory()->create(['email' => 'owner@example.test']);
        ProfessionalProfile::create(array_merge([
            'user_id' => $user->id,
            'full_name' => 'Pat Candidate',
            'email' => 'pat@example.test',
            'phone' => '702-555-0100',
            'headline' => 'Enterprise AI transformation consultant',
            'summary' => 'Advises executives on AI adoption, SaaS onboarding and process optimization.',
            'skills' => [
                ['name' => 'AI enablement', 'category' => 'ai', 'evidence' => 'Ran AI training programs'],
                ['name' => 'Stakeholder management', 'category' => 'leadership', 'evidence' => 'Executive advisory'],
                ['name' => 'Property management systems', 'category' => 'industry', 'evidence' => 'Hospitality PMS consulting'],
            ],
            'work_history' => [
                ['company' => 'Acme Advisory', 'title' => 'Principal AI Consultant', 'start' => '2021', 'end' => 'Present', 'highlights' => ['Led enterprise AI transformation programs']],
                ['company' => 'HotelTech Partners', 'title' => 'Hospitality Technology Consultant', 'start' => '2016', 'end' => '2021', 'highlights' => ['Implemented PMS for 40 properties']],
            ],
            'certifications' => [],
            'education' => [],
            'industries' => ['Hospitality', 'SaaS'],
            'achievements' => [],
        ], $profile));

        return $user->fresh('profile');
    }

    public static function matchResponse(int $score = 88, array $overrides = []): array
    {
        $dims = array_fill_keys(['experience', 'skills', 'transferable', 'seniority', 'education_certifications', 'technical_requirements', 'industry', 'location', 'salary'], $score);

        return array_merge([
            'match_score' => $score,
            'dimension_scores' => $dims,
            'why_you_qualify' => 'You have led enterprise AI transformation programs.',
            'relevant_experience' => ['Principal AI Consultant at Acme Advisory'],
            'skills_matched' => ['AI enablement'],
            'skills_missing' => ['Salesforce administration'],
            'missing_qualifications' => [],
            'transferable_skills' => ['Stakeholder management'],
            'application_priority' => $score >= 80 ? 'high' : 'medium',
            'seniority_fit' => 'match',
            'resume_emphasis' => ['Lead with AI enablement outcomes'],
            'concerns' => '',
        ], $overrides);
    }
}

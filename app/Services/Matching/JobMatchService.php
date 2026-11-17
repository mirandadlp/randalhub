<?php

namespace App\Services\Matching;

use App\Models\JobListing;
use App\Models\JobMatch;
use App\Models\User;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiException;
use App\Services\Ai\PromptSafety;
use App\Services\SettingsService;

class JobMatchService
{
    public function __construct(
        private AiClient $ai,
        private PriorityScorer $priority,
        private SettingsService $settings,
    ) {}

    public function needsAnalysis(User $user, JobListing $job): bool
    {
        $profile = $user->profile;
        $match = JobMatch::where('user_id', $user->id)->where('job_listing_id', $job->id)->first();

        return ! $match
            || $match->status !== 'completed'
            || $match->profile_hash !== $profile?->content_hash
            || $match->job_content_hash !== $job->content_hash;
    }

    /**
     * Analyze (or re-use a cached analysis of) one listing for the user.
     * Re-analysis only happens when the job content or qualification profile changed, or $force.
     */
    public function analyze(User $user, JobListing $job, bool $force = false): JobMatch
    {
        $profile = $user->profile;
        if (! $profile || ! $profile->isUsable()) {
            throw new AiException('Add your professional profile (upload a resume or fill it in) before running AI matching.', 'not_configured');
        }
        if (! $this->settings->get('ai.job_matching_enabled', true)) {
            throw new AiException('AI job matching is turned off in Settings.', 'disabled');
        }

        $match = JobMatch::firstOrNew(['user_id' => $user->id, 'job_listing_id' => $job->id]);
        if (! $force && $match->exists && ! $this->needsAnalysis($user, $job)) {
            return $this->refreshPriority($match, $job);
        }

        try {
            $result = $this->ai->structured(
                purpose: 'job_match',
                system: [
                    ['text' => $this->systemPrompt()],
                    ['text' => "CANDIDATE PROFILE (verified, authoritative):\n".json_encode($profile->toAiPayload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), 'cache' => true],
                ],
                user: $this->userPrompt($job),
                schema: self::schema(),
                related: $job,
                maxTokens: 8000,
            );
        } catch (AiException $e) {
            $match->fill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000)])->save();
            throw $e;
        }

        $d = $result->data;
        $match->fill([
            'status' => 'completed',
            'match_score' => max(0, min(100, (int) ($d['match_score'] ?? 0))),
            'application_priority' => in_array($d['application_priority'] ?? null, ['high', 'medium', 'low', 'skip'], true) ? $d['application_priority'] : 'low',
            'seniority_fit' => in_array($d['seniority_fit'] ?? null, ['under', 'match', 'over', 'unclear'], true) ? $d['seniority_fit'] : 'unclear',
            'qualification_summary' => mb_substr((string) ($d['why_you_qualify'] ?? ''), 0, 5000),
            'relevant_experience' => $this->strings($d['relevant_experience'] ?? []),
            'skills_matched' => $this->strings($d['skills_matched'] ?? []),
            'skills_missing' => $this->strings($d['skills_missing'] ?? []),
            'missing_qualifications' => $this->strings($d['missing_qualifications'] ?? []),
            'transferable_skills' => $this->strings($d['transferable_skills'] ?? []),
            'resume_emphasis' => $this->strings($d['resume_emphasis'] ?? []),
            'dimension_scores' => collect($d['dimension_scores'] ?? [])->map(fn ($v) => max(0, min(100, (int) $v)))->all(),
            'concerns' => mb_substr((string) ($d['concerns'] ?? ''), 0, 3000) ?: null,
            'model' => $result->model,
            'profile_hash' => $profile->content_hash,
            'job_content_hash' => $job->content_hash,
            'input_tokens' => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
            'cost_usd' => $result->costUsd,
            'error' => null,
            'analyzed_at' => now(),
        ]);

        return $this->refreshPriority($match, $job);
    }

    public function refreshPriority(JobMatch $match, JobListing $job): JobMatch
    {
        $p = $this->priority->score($job, $match);
        $match->priority_score = $p['score'];
        $match->priority_breakdown = $p['breakdown'];
        $match->save();

        return $match;
    }

    private function strings(mixed $value): array
    {
        return collect(is_array($value) ? $value : [])
            ->filter(fn ($v) => is_string($v) && trim($v) !== '')
            ->map(fn ($v) => mb_substr(trim($v), 0, 500))
            ->take(15)->values()->all();
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You are a careful career-matching analyst for a single job seeker. You compare one job posting against the
candidate's verified professional profile and return a structured assessment.

Rules:
- The candidate profile is the only authoritative source of the candidate's qualifications. Do not assume
  experience, certifications, degrees, or technical skills that the profile does not establish. If the posting
  requires something the profile does not show, list it under missing_qualifications or skills_missing.
- Look beyond exact title matches: recognize transferable experience (consulting, onboarding, implementation,
  stakeholder management, process optimization, enablement) that genuinely applies to the role.
- Hands-on software engineering roles should score low unless the profile shows matching engineering experience.
- Be calibrated, not generous. 90-100: meets essentially all requirements and seniority. 75-89: strong fit with
  minor gaps. 60-74: plausible with notable gaps. 40-59: stretch. Below 40: poor fit.
- dimension_scores (0-100 each): experience, skills, transferable, seniority, education_certifications,
  technical_requirements, industry, location, salary. Use 50 for location/salary when the posting gives no data.
- application_priority: "high" only when match_score >= 80 and nothing disqualifying; "skip" for clear mismatches.
- seniority_fit: "under" (candidate is junior to the role), "match", "over" (candidate is senior to the role), or "unclear".
- resume_emphasis: concrete, specific advice on which verified experience to foreground for this posting.
- Keep each list item to one sentence. Write why_you_qualify in 2-4 sentences addressed to the candidate ("You ...").
PROMPT."\n\n".PromptSafety::UNTRUSTED_NOTICE;
    }

    private function userPrompt(JobListing $job): string
    {
        $prefs = [
            'location_preferences' => $this->settings->get('location_preferences'),
            'remote_preference' => $this->settings->get('remote_preference'),
            'minimum_salary_usd' => $this->settings->get('minimum_salary'),
            'preferred_industries' => $this->settings->get('preferred_industries'),
        ];
        $meta = [
            'company' => $job->company_name,
            'title' => $job->title,
            'location' => $job->location,
            'work_arrangement' => $job->remote_type,
            'salary' => $job->salary_max ? "{$job->salary_min}-{$job->salary_max} {$job->salary_currency} per {$job->salary_interval}" : 'not published',
            'industry' => $job->industry,
        ];

        return "Candidate search preferences:\n".json_encode($prefs, JSON_UNESCAPED_UNICODE)
            ."\n\nJob metadata (from the source):\n".json_encode($meta, JSON_UNESCAPED_UNICODE)
            ."\n\n".PromptSafety::wrapUntrusted('untrusted_job_posting', $job->analysisText())
            ."\n\nAssess this job for the candidate and return the JSON object.";
    }

    public static function schema(): array
    {
        $list = ['type' => 'array', 'items' => ['type' => 'string']];
        $dims = ['experience', 'skills', 'transferable', 'seniority', 'education_certifications', 'technical_requirements', 'industry', 'location', 'salary'];

        return [
            'type' => 'object',
            'properties' => [
                'match_score' => ['type' => 'integer', 'description' => 'Overall qualification match, 0-100'],
                'dimension_scores' => [
                    'type' => 'object',
                    'properties' => array_fill_keys($dims, ['type' => 'integer']),
                    'required' => $dims,
                    'additionalProperties' => false,
                ],
                'why_you_qualify' => ['type' => 'string'],
                'relevant_experience' => $list,
                'skills_matched' => $list,
                'skills_missing' => $list,
                'missing_qualifications' => $list,
                'transferable_skills' => $list,
                'application_priority' => ['type' => 'string', 'enum' => ['high', 'medium', 'low', 'skip']],
                'seniority_fit' => ['type' => 'string', 'enum' => ['under', 'match', 'over', 'unclear']],
                'resume_emphasis' => $list,
                'concerns' => ['type' => 'string'],
            ],
            'required' => [
                'match_score', 'dimension_scores', 'why_you_qualify', 'relevant_experience', 'skills_matched',
                'skills_missing', 'missing_qualifications', 'transferable_skills', 'application_priority',
                'seniority_fit', 'resume_emphasis', 'concerns',
            ],
            'additionalProperties' => false,
        ];
    }
}

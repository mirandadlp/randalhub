<?php

namespace App\Http\Resources;

use App\Models\Application;
use App\Models\JobListing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin JobListing */
class JobListingResource extends JsonResource
{
    public bool $detailed = false;

    public function detailed(): static
    {
        $this->detailed = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $match = $this->relationLoaded('match') ? $this->match : null;
        $application = $this->relationLoaded('application') ? $this->application : null;

        $data = [
            'id' => $this->id,
            'company' => $this->company_name,
            'company_id' => $this->company_id,
            'industry' => $this->industry,
            'title' => $this->title,
            'location' => $this->location,
            'remote_type' => $this->remote_type,
            'is_us' => $this->is_us,
            'is_nevada' => $this->is_nevada,
            'employment_type' => $this->employment_type,
            'department' => $this->department,
            'salary' => $this->salary_max ? [
                'min' => $this->salary_min, 'max' => $this->salary_max, 'currency' => $this->salary_currency,
                'interval' => $this->salary_interval, 'text' => $this->salary_text,
            ] : null,
            'posted_at' => $this->posted_at?->toIso8601String(),
            'first_seen_at' => $this->first_seen_at->toIso8601String(),
            'last_seen_at' => $this->last_seen_at->toIso8601String(),
            'age_days' => $this->ageInDays(),
            'age_basis' => $this->posted_at ? 'posted' : 'first_seen',
            'status' => $this->status,
            'source_url' => $this->source_url,
            'application_url' => $this->application_url ?: $this->source_url,
            'source_type' => $this->primarySource?->type,
            'prescreen_score' => $this->prescreen_score,
            'competition' => [
                'applicant_count' => $this->applicant_count, // null => "Not available"
                'applicant_count_source' => $this->applicant_count_source,
                'is_direct_employer' => $this->is_direct_employer,
                'is_repost' => $this->is_repost,
                'is_accepting_applications' => $this->status === 'open',
            ],
            'match' => $match && $match->status === 'completed' ? [
                'score' => $match->match_score,
                'priority' => $match->priority_score,
                'application_priority' => $match->application_priority,
                'summary' => $match->qualification_summary,
                'analyzed_at' => $match->analyzed_at?->toIso8601String(),
            ] : ($match ? ['status' => $match->status, 'error' => $match->error] : null),
            'saved' => (bool) ($this->saved_exists ?? false),
            'dismissed' => (bool) ($this->dismissed_exists ?? false),
            'application' => $application ? [
                'id' => $application->id,
                'status' => $application->status,
                'status_label' => Application::STATUSES[$application->status],
            ] : null,
        ];

        if ($this->detailed) {
            $data += [
                'description_html' => $this->description_html,
                'description_text' => $this->description_html ? null : $this->description_text,
                'requirements_text' => $this->requirements_text,
                'content_changed_at' => $this->content_changed_at?->toIso8601String(),
                'closed_at' => $this->closed_at?->toIso8601String(),
                'duplicate_of_id' => $this->duplicate_of_id,
                'repost_of' => $this->repostOf ? ['id' => $this->repostOf->id, 'closed_at' => $this->repostOf->closed_at?->toIso8601String()] : null,
                'company_info' => $this->company ? $this->company->only(['id', 'name', 'website', 'industry', 'description', 'is_watched', 'is_preferred']) : null,
                'sources' => $this->observations->map(fn ($o) => [
                    'type' => $o->source?->type,
                    'name' => $o->source?->displayName(),
                    'url' => $o->source_url,
                    'first_seen_at' => $o->first_seen_at->toIso8601String(),
                    'last_seen_at' => $o->last_seen_at->toIso8601String(),
                    'is_active' => $o->is_active,
                ]),
                'analysis' => $match && $match->status === 'completed' ? [
                    'score' => $match->match_score,
                    'priority' => $match->priority_score,
                    'priority_breakdown' => $match->priority_breakdown,
                    'application_priority' => $match->application_priority,
                    'seniority_fit' => $match->seniority_fit,
                    'why_you_qualify' => $match->qualification_summary,
                    'relevant_experience' => $match->relevant_experience,
                    'skills_matched' => $match->skills_matched,
                    'skills_missing' => $match->skills_missing,
                    'missing_qualifications' => $match->missing_qualifications,
                    'transferable_skills' => $match->transferable_skills,
                    'resume_emphasis' => $match->resume_emphasis,
                    'dimension_scores' => $match->dimension_scores,
                    'concerns' => $match->concerns,
                    'model' => $match->model,
                    'cost_usd' => $match->cost_usd,
                    'analyzed_at' => $match->analyzed_at?->toIso8601String(),
                    'stale' => $match->job_content_hash !== $this->content_hash
                        || $match->profile_hash !== $request->user()?->profile?->content_hash,
                ] : null,
            ];
        }

        return $data;
    }
}

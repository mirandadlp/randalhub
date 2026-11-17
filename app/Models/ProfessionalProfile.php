<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfessionalProfile extends Model
{
    public const QUALIFICATION_FIELDS = [
        'headline', 'summary', 'skills', 'work_history', 'education', 'certifications', 'industries', 'achievements',
    ];

    protected $fillable = [
        'user_id', 'source_resume_id', 'full_name', 'headline', 'email', 'phone', 'location', 'linkedin_url',
        'website_url', 'summary', 'skills', 'work_history', 'education', 'certifications', 'industries',
        'achievements', 'is_verified', 'verified_at', 'content_hash',
    ];

    protected function casts(): array
    {
        return [
            'skills' => 'array',
            'work_history' => 'array',
            'education' => 'array',
            'certifications' => 'array',
            'industries' => 'array',
            'achievements' => 'array',
            'is_verified' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ProfessionalProfile $profile) {
            $profile->content_hash = $profile->computeContentHash();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Hash only qualification-relevant fields: editing a phone number must not trigger AI re-analysis. */
    public function computeContentHash(): string
    {
        $data = [];
        foreach (self::QUALIFICATION_FIELDS as $field) {
            $data[$field] = $this->{$field};
        }

        return hash('sha256', json_encode($data));
    }

    /**
     * The profile as sent to the AI provider: qualifications only, no contact details.
     */
    public function toAiPayload(): array
    {
        return [
            'headline' => $this->headline,
            'summary' => $this->summary,
            'skills' => $this->skills ?? [],
            'work_history' => collect($this->work_history ?? [])->map(fn ($job) => [
                'title' => $job['title'] ?? null,
                'company' => $job['company'] ?? null,
                'start' => $job['start'] ?? null,
                'end' => $job['end'] ?? null,
                'highlights' => $job['highlights'] ?? [],
            ])->all(),
            'education' => $this->education ?? [],
            'certifications' => $this->certifications ?? [],
            'industries' => $this->industries ?? [],
            'achievements' => $this->achievements ?? [],
        ];
    }

    public function isUsable(): bool
    {
        return filled($this->summary) || ! empty($this->work_history) || ! empty($this->skills);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobMatch extends Model
{
    protected $fillable = [
        'user_id', 'job_listing_id', 'status', 'match_score', 'priority_score', 'priority_breakdown',
        'application_priority', 'seniority_fit', 'qualification_summary', 'relevant_experience', 'skills_matched',
        'skills_missing', 'missing_qualifications', 'transferable_skills', 'resume_emphasis', 'dimension_scores',
        'concerns', 'model', 'profile_hash', 'job_content_hash', 'input_tokens', 'output_tokens', 'cost_usd', 'error',
        'analyzed_at',
    ];

    protected function casts(): array
    {
        return [
            'priority_breakdown' => 'array',
            'relevant_experience' => 'array',
            'skills_matched' => 'array',
            'skills_missing' => 'array',
            'missing_qualifications' => 'array',
            'transferable_skills' => 'array',
            'resume_emphasis' => 'array',
            'dimension_scores' => 'array',
            'priority_score' => 'float',
            'cost_usd' => 'float',
            'analyzed_at' => 'datetime',
        ];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(JobListing::class, 'job_listing_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

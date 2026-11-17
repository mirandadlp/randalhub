<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Application extends Model
{
    public const STATUSES = [
        'discovered' => 'Discovered',
        'saved' => 'Saved',
        'preparing' => 'Preparing',
        'ready' => 'Ready to Apply',
        'applied' => 'Applied',
        'interviewing' => 'Interviewing',
        'offer' => 'Offer',
        'rejected' => 'Rejected',
        'archived' => 'Archived',
    ];

    protected $fillable = [
        'user_id', 'job_listing_id', 'resume_id', 'status', 'notes', 'applied_at', 'applied_confirmation',
        'interview_at', 'next_follow_up_at', 'recruiter_name', 'recruiter_email', 'recruiter_phone',
        'recruiter_notes',
    ];

    protected function casts(): array
    {
        return [
            'applied_at' => 'datetime',
            'interview_at' => 'datetime',
            'next_follow_up_at' => 'datetime',
        ];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(JobListing::class, 'job_listing_id');
    }

    public function resume(): BelongsTo
    {
        return $this->belongsTo(Resume::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ApplicationEvent::class)->latest();
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ApplicationDocument::class);
    }

    public function autofillSessions(): HasMany
    {
        return $this->hasMany(AutofillSession::class)->latest();
    }
}

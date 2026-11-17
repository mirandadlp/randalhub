<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobSource extends Model
{
    use HasFactory;

    public const TYPES = ['greenhouse', 'lever', 'ashby', 'smartrecruiters', 'workable', 'career_page', 'adzuna'];

    protected $fillable = [
        'company_id', 'type', 'identifier', 'name', 'config', 'enabled', 'max_jobs', 'health', 'limitation_note',
        'consecutive_failures', 'last_job_count', 'last_scanned_at', 'last_success_at', 'last_error_at', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'enabled' => 'boolean',
            'last_scanned_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_error_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function observations(): HasMany
    {
        return $this->hasMany(JobSourceObservation::class);
    }

    public function displayName(): string
    {
        return $this->name ?: ($this->company?->name ?? $this->identifier);
    }

    /** Employer-operated ATS boards are "direct"; aggregators are not. */
    public function isDirectEmployer(): bool
    {
        return $this->type !== 'adzuna';
    }
}

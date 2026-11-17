<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

class JobListing extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id', 'primary_source_id', 'company_name', 'title', 'normalized_title', 'location', 'is_us',
        'is_nevada', 'remote_type', 'employment_type', 'department', 'industry', 'salary_min', 'salary_max',
        'salary_currency', 'salary_interval', 'salary_text', 'description_html', 'description_text',
        'requirements_text', 'source_url', 'application_url', 'canonical_url', 'url_hash', 'fingerprint',
        'content_hash', 'posted_at', 'first_seen_at', 'last_seen_at', 'content_changed_at', 'closed_at', 'status',
        'missed_scans', 'is_direct_employer', 'applicant_count', 'applicant_count_source',
        'applicant_count_observed_at', 'is_repost', 'repost_of_id', 'duplicate_of_id', 'prescreen_score',
    ];

    protected function casts(): array
    {
        return [
            'is_us' => 'boolean',
            'is_nevada' => 'boolean',
            'is_direct_employer' => 'boolean',
            'is_repost' => 'boolean',
            'posted_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'content_changed_at' => 'datetime',
            'closed_at' => 'datetime',
            'applicant_count_observed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function primarySource(): BelongsTo
    {
        return $this->belongsTo(JobSource::class, 'primary_source_id');
    }

    public function observations(): HasMany
    {
        return $this->hasMany(JobSourceObservation::class);
    }

    public function matches(): HasMany
    {
        return $this->hasMany(JobMatch::class);
    }

    public function match(): HasOne
    {
        return $this->hasOne(JobMatch::class);
    }

    public function savedBy(): HasMany
    {
        return $this->hasMany(SavedJob::class);
    }

    public function dismissedBy(): HasMany
    {
        return $this->hasMany(DismissedJob::class);
    }

    public function application(): HasOne
    {
        return $this->hasOne(Application::class);
    }

    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(JobListing::class, 'duplicate_of_id');
    }

    public function repostOf(): BelongsTo
    {
        return $this->belongsTo(JobListing::class, 'repost_of_id');
    }

    public function scopeCanonical(Builder $query): Builder
    {
        return $query->whereNull($query->qualifyColumn('duplicate_of_id'));
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), 'open');
    }

    /** Date used for freshness: employer posting date when known, otherwise first discovery. */
    public function effectivePostedAt(): Carbon
    {
        return $this->posted_at ?? $this->first_seen_at;
    }

    public function ageInDays(): int
    {
        return (int) floor($this->effectivePostedAt()->diffInHours(now()) / 24);
    }

    /** Text used for AI analysis (requirements first, capped to keep cost predictable). */
    public function analysisText(int $maxChars = 24000): string
    {
        return mb_substr((string) $this->description_text, 0, $maxChars);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobSourceObservation extends Model
{
    protected $fillable = [
        'job_listing_id', 'job_source_id', 'last_scan_run_id', 'external_id', 'source_url', 'content_hash',
        'raw_payload', 'is_active', 'missed_scans', 'first_seen_at', 'last_seen_at',
    ];

    protected $hidden = ['raw_payload'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(JobListing::class, 'job_listing_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(JobSource::class, 'job_source_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScanRunSource extends Model
{
    protected $fillable = [
        'scan_run_id', 'job_source_id', 'status', 'jobs_found', 'jobs_new', 'jobs_updated', 'jobs_closed',
        'duration_ms', 'error',
    ];

    public function scanRun(): BelongsTo
    {
        return $this->belongsTo(ScanRun::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(JobSource::class, 'job_source_id');
    }
}

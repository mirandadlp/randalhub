<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScanRun extends Model
{
    protected $fillable = [
        'trigger', 'status', 'started_at', 'finished_at', 'sources_total', 'sources_succeeded', 'sources_failed',
        'sources_skipped', 'jobs_found', 'jobs_new', 'jobs_updated', 'jobs_closed', 'duplicates_found',
        'error_summary',
    ];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function sourceResults(): HasMany
    {
        return $this->hasMany(ScanRunSource::class);
    }
}

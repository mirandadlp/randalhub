<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationLog extends Model
{
    protected $fillable = [
        'user_id', 'type', 'dedupe_key', 'channel', 'subject', 'job_listing_ids', 'status', 'error', 'sent_at',
    ];

    protected function casts(): array
    {
        return ['job_listing_ids' => 'array', 'sent_at' => 'datetime'];
    }
}

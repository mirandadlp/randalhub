<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutofillSession extends Model
{
    public const ACTIVE_STATUSES = ['queued', 'running', 'awaiting_approval', 'approved', 'submitting'];

    protected $fillable = [
        'application_id', 'status', 'adapter', 'token_hash', 'target_url', 'fields_filled', 'fields_skipped',
        'message', 'approved_at', 'submitted_at',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'fields_filled' => 'array',
            'fields_skipped' => 'array',
            'approved_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}

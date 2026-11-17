<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Resume extends Model
{
    protected $fillable = [
        'user_id', 'label', 'original_filename', 'storage_path', 'mime_type', 'size_bytes', 'sha256',
        'extracted_text', 'is_master', 'parse_status', 'parse_error', 'parsed_at',
    ];

    protected $hidden = ['storage_path', 'extracted_text'];

    protected function casts(): array
    {
        return [
            'extracted_text' => 'encrypted',
            'is_master' => 'boolean',
            'parsed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

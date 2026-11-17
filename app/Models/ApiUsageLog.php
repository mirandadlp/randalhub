<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiUsageLog extends Model
{
    protected $fillable = [
        'provider', 'purpose', 'model', 'input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens',
        'cost_usd', 'status', 'duration_ms', 'related_type', 'related_id', 'error',
    ];

    protected function casts(): array
    {
        return ['cost_usd' => 'float'];
    }
}

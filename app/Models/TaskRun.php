<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskRun extends Model
{
    protected $fillable = ['task', 'status', 'started_at', 'finished_at', 'output'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}

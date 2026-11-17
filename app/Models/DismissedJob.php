<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DismissedJob extends Model
{
    protected $fillable = ['user_id', 'job_listing_id', 'reason'];
}

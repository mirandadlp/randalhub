<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'slug', 'website', 'careers_url', 'industry', 'description', 'is_watched', 'is_preferred',
        'is_excluded', 'discovered_via', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_watched' => 'boolean',
            'is_preferred' => 'boolean',
            'is_excluded' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Company $company) {
            if (blank($company->slug)) {
                $company->slug = static::uniqueSlug($company->name);
            }
        });
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'company';
        $slug = $base;
        $i = 2;
        while (static::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    public function sources(): HasMany
    {
        return $this->hasMany(JobSource::class);
    }

    public function listings(): HasMany
    {
        return $this->hasMany(JobListing::class);
    }
}

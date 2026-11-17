<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationDocument extends Model
{
    public const TYPES = [
        'requirements_analysis' => 'Requirements analysis',
        'resume_recommendations' => 'Resume recommendations',
        'tailored_resume' => 'Tailored resume',
        'cover_letter' => 'Cover letter',
        'qa_answers' => 'Application question answers',
    ];

    protected $fillable = [
        'application_id', 'type', 'title', 'content', 'format', 'version', 'is_user_edited', 'model', 'generated_at',
    ];

    protected function casts(): array
    {
        return ['is_user_edited' => 'boolean', 'generated_at' => 'datetime'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}

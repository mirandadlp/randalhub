<?php

namespace App\Jobs;

use App\Models\ProfessionalProfile;
use App\Models\Resume;
use App\Services\Ai\AiException;
use App\Services\Resume\ResumeParser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ParseResumeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(public int $resumeId, public bool $overwrite = false) {}

    public function handle(ResumeParser $parser): void
    {
        $resume = Resume::find($this->resumeId);
        if (! $resume || blank($resume->extracted_text)) {
            return;
        }

        try {
            $result = $parser->parse($resume);
        } catch (AiException $e) {
            $resume->update(['parse_status' => 'failed', 'parse_error' => mb_substr($e->getMessage(), 0, 1000)]);
            if ($e->retryable) {
                throw $e;
            }

            return;
        }

        $profile = ProfessionalProfile::firstOrNew(['user_id' => $resume->user_id]);
        $parser->applyToProfile($profile, $result['profile'], $resume, $this->overwrite);

        $resume->update([
            'parse_status' => 'parsed',
            'parsed_at' => now(),
            'parse_error' => $result['ai_used'] ? null : 'Claude is not configured, so only contact details and raw text were imported. Edit your profile manually or add an API key and re-parse.',
        ]);
    }
}

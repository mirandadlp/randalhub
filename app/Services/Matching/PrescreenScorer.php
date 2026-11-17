<?php

namespace App\Services\Matching;

use App\Models\JobListing;
use App\Services\SettingsService;

/**
 * Cheap, deterministic relevance pre-screen used to decide which listings are worth an AI analysis.
 * This is NOT a qualification score and is labeled "Pre-screen (rule-based)" wherever it is shown.
 */
class PrescreenScorer
{
    public function __construct(private SettingsService $settings) {}

    public function score(JobListing $job): int
    {
        $title = ' '.strtolower($job->normalized_title).' ';
        $score = 0;

        foreach ($this->settings->get('excluded_title_keywords', []) as $kw) {
            if ($kw !== '' && str_contains($title, ' '.strtolower($kw).' ')) {
                return 0;
            }
        }

        foreach ($this->settings->get('preferred_titles', []) as $preferred) {
            $p = strtolower($preferred);
            if (str_contains($title, $p)) {
                $score = max($score, 70);
            }
        }

        $hits = 0;
        foreach ($this->settings->get('title_keywords', []) as $kw) {
            if ($kw !== '' && str_contains($title, strtolower($kw))) {
                $hits++;
            }
        }
        $score = max($score, min(65, $hits * 30));

        if (preg_match('/\b(ai|artificial intelligence|genai|llm|automation)\b/', $title)) {
            $score += 10;
        }
        if (preg_match('/\b(senior|lead|principal|head|director|manager)\b/', $title)) {
            $score += 5;
        }

        $body = strtolower(mb_substr((string) $job->description_text, 0, 8000));
        foreach (['stakeholder', 'onboarding', 'implementation', 'consult', 'transformation', 'change management', 'enablement', 'hospitality', 'property management', 'roi', 'process improvement', 'requirements gathering'] as $term) {
            if (str_contains($body, $term)) {
                $score += 3;
            }
        }

        // Location compatibility (soft): non-U.S. onsite roles are unlikely to fit.
        if ($job->is_us === false && $job->remote_type !== 'remote') {
            $score -= 40;
        } elseif ($job->is_us === false) {
            $score -= 20;
        }

        return max(0, min(100, $score));
    }
}

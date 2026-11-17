<?php

namespace App\Services\Matching;

use App\Models\JobListing;
use App\Models\JobMatch;
use App\Services\SettingsService;
use App\Services\Support\SalaryParser;

/**
 * Opportunity priority = weighted blend of qualification match, freshness, location fit,
 * compensation/seniority and competition signals. Weights come from Settings.
 * Missing data (salary, applicant counts) scores neutral, never as a penalty.
 */
class PriorityScorer
{
    public function __construct(private SettingsService $settings) {}

    /**
     * @return array{score: float, breakdown: array}
     */
    public function score(JobListing $job, ?JobMatch $match): array
    {
        $weights = $this->settings->get('priority_weights');
        $components = [
            'qualification' => $this->qualification($match),
            'freshness' => $this->freshness($job),
            'location' => $this->location($job),
            'compensation' => $this->compensation($job, $match),
            'competition' => $this->competition($job),
        ];

        $totalWeight = 0;
        $sum = 0;
        $breakdown = [];
        foreach ($components as $key => $component) {
            $w = max(0, (float) ($weights[$key] ?? 0));
            $totalWeight += $w;
            $sum += $w * $component['score'];
            $breakdown[$key] = $component + ['weight' => $w];
        }

        return [
            'score' => $totalWeight > 0 ? round($sum / $totalWeight, 2) : 0.0,
            'breakdown' => $breakdown,
        ];
    }

    private function qualification(?JobMatch $match): array
    {
        if (! $match || $match->match_score === null) {
            return ['score' => 0, 'note' => 'Not analyzed yet'];
        }

        return ['score' => (int) $match->match_score, 'note' => 'AI qualification match'];
    }

    private function freshness(JobListing $job): array
    {
        $days = $job->ageInDays();
        $score = match (true) {
            $days <= 1 => 100,
            $days <= 3 => 90,
            $days <= 7 => 75,
            $days <= 14 => 55,
            $days <= 30 => 35,
            $days <= 60 => 20,
            default => 10,
        };
        $basis = $job->posted_at ? 'employer posting date' : 'first discovered date (posting date not published)';

        return ['score' => $score, 'note' => "{$days} days old, based on {$basis}"];
    }

    public function location(JobListing $job): array
    {
        $prefs = $this->settings->get('location_preferences');
        $mode = $this->settings->get('remote_preference', 'remote_or_local');

        if ($job->is_nevada) {
            return ['score' => 100, 'note' => 'Las Vegas / Nevada'];
        }
        if ($job->remote_type === 'remote') {
            return match ($job->is_us) {
                true => ['score' => ($prefs['remote_us'] ?? true) ? 100 : 70, 'note' => 'U.S. remote'],
                null => ['score' => 70, 'note' => 'Remote (country not stated)'],
                false => ['score' => 15, 'note' => 'Remote outside the U.S.'],
            };
        }
        if ($job->is_us === false) {
            return ['score' => 0, 'note' => 'Outside the U.S.'];
        }
        if ($job->remote_type === 'hybrid') {
            $score = $mode === 'any' ? 70 : (($prefs['other_us_if_remote'] ?? true) ? 45 : 20);

            return ['score' => $score, 'note' => 'Hybrid, outside preferred locations'];
        }
        if ($job->remote_type === 'onsite') {
            $score = $mode === 'any' || ($prefs['allow_onsite_outside_preferred'] ?? false) ? 55 : 15;

            return ['score' => $score, 'note' => 'On-site, outside preferred locations'];
        }

        return ['score' => 50, 'note' => 'Work arrangement not stated'];
    }

    private function compensation(JobListing $job, ?JobMatch $match): array
    {
        $min = $this->settings->get('minimum_salary');
        $annualMax = SalaryParser::annualize($job->salary_max, $job->salary_interval);

        if ($annualMax === null) {
            $salary = 50;
            $salaryNote = 'Salary not published (neutral)';
        } elseif (! $min) {
            $salary = 70;
            $salaryNote = 'Salary published; no minimum set';
        } else {
            $salary = $annualMax >= $min ? 100 : max(0, (int) round(100 * $annualMax / $min) - 30);
            $salaryNote = $annualMax >= $min ? 'Meets minimum salary' : 'Below minimum salary';
        }

        $seniority = match ($match?->seniority_fit) {
            'match' => 100,
            'over' => 60,
            'under' => 35,
            default => 60,
        };

        return [
            'score' => (int) round(($salary + $seniority) / 2),
            'note' => $salaryNote.'; seniority fit: '.($match?->seniority_fit ?? 'unknown'),
        ];
    }

    /** Competition signals are inferred unless an applicant count was published by the source. */
    public function competition(JobListing $job): array
    {
        $score = 50;
        $signals = [];
        if ($job->is_direct_employer) {
            $score += 15;
            $signals[] = 'Direct employer listing';
        }
        $days = $job->ageInDays();
        if ($days <= 3) {
            $score += 20;
            $signals[] = 'Early: posted in the last 3 days';
        } elseif ($days <= 7) {
            $score += 10;
            $signals[] = 'Posted this week';
        } elseif ($days > 30) {
            $score -= 15;
            $signals[] = 'Open for over 30 days';
        }
        if ($job->is_repost) {
            $score -= 10;
            $signals[] = 'Appears to be a repost';
        }
        if ($job->applicant_count !== null) {
            $score += match (true) {
                $job->applicant_count < 25 => 20,
                $job->applicant_count < 100 => 5,
                $job->applicant_count > 200 => -20,
                default => 0,
            };
            $signals[] = "Applicant count {$job->applicant_count} (verified, {$job->applicant_count_source})";
        }

        return ['score' => max(0, min(100, $score)), 'note' => implode('; ', $signals) ?: 'No signals', 'inferred' => $job->applicant_count === null];
    }
}

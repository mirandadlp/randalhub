<?php

namespace App\Jobs;

use App\Models\JobListing;
use App\Models\User;
use App\Services\Ai\AiClient;
use App\Services\SettingsService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

/**
 * Selects listings that need (re)analysis and fans out AI match jobs, capped per run.
 * Priority alerts are sent when the batch finishes.
 */
class AnalyzeNewJobsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 1800;

    public function __construct(public ?int $limit = null) {}

    public function handle(AiClient $ai, SettingsService $settings): void
    {
        $user = User::with('profile')->first();
        if (! $user) {
            return;
        }

        $ids = collect();
        if ($ai->isConfigured() && $settings->get('ai.job_matching_enabled', true) && $user->profile?->isUsable()) {
            $ids = static::candidates($user, $settings, $this->limit ?? (int) $settings->get('analysis_batch_limit', 40));
        } else {
            Log::info('AI analysis skipped: Claude not configured, matching disabled, or profile empty.');
        }

        if ($ids->isEmpty()) {
            SendPriorityAlertsJob::dispatch();

            return;
        }

        Bus::batch($ids->map(fn ($id) => new AnalyzeJobMatchJob($user->id, $id))->all())
            ->name('ai-analysis')
            ->allowFailures()
            ->onQueue('ai')
            ->finally(fn () => SendPriorityAlertsJob::dispatch())
            ->dispatch();
    }

    /** Listings worth analyzing: open, canonical, pre-screened relevant, not dismissed, analysis missing or stale. */
    public static function candidates(User $user, SettingsService $settings, int $limit)
    {
        $profileHash = $user->profile?->content_hash;

        return JobListing::query()
            ->open()->canonical()
            ->where('prescreen_score', '>=', (int) $settings->get('prescreen_minimum', 35))
            ->whereNotIn('id', $user->dismissedJobs()->select('job_listing_id'))
            ->where(fn ($q) => $q->whereNull('company_id')->orWhereHas('company', fn ($c) => $c->where('is_excluded', false)))
            ->whereNotExists(function ($q) use ($user, $profileHash) {
                $q->selectRaw('1')->from('job_matches')
                    ->whereColumn('job_matches.job_listing_id', 'job_listings.id')
                    ->where('job_matches.user_id', $user->id)
                    ->where(fn ($w) => $w->where('job_matches.status', 'completed')->orWhere('job_matches.updated_at', '>', now()->subHours(6)))
                    ->where('job_matches.profile_hash', $profileHash)
                    ->whereColumn('job_matches.job_content_hash', 'job_listings.content_hash');
            })
            ->orderByDesc('prescreen_score')
            ->orderByDesc('first_seen_at')
            ->limit($limit)
            ->pluck('id');
    }
}

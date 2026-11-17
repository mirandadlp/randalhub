<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\JobListingResource;
use App\Models\Application;
use App\Models\JobListing;
use App\Models\JobMatch;
use App\Models\JobSource;
use App\Models\ScanRun;
use App\Services\Ai\AiClient;
use App\Services\Ai\AnthropicAiClient;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __invoke(Request $request, SettingsService $settings, AiClient $ai): JsonResponse
    {
        $user = $request->user()->load('profile');
        $highThreshold = (int) $settings->get('high_match_threshold', 80);
        $open = JobListing::open()->canonical();
        $dismissed = $user->dismissedJobs()->select('job_listing_id');

        $highMatchQuery = JobMatch::where('user_id', $user->id)->where('status', 'completed')
            ->where('match_score', '>=', $highThreshold)
            ->whereHas('listing', fn ($q) => $q->open()->canonical())
            ->whereNotIn('job_listing_id', $dismissed);

        $pipeline = Application::where('user_id', $user->id)->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');

        $top = JobListing::open()->canonical()
            ->whereIn('id', (clone $highMatchQuery)->select('job_listing_id'))
            ->with(['match' => fn ($m) => $m->where('user_id', $user->id), 'primarySource:id,type', 'application' => fn ($a) => $a->where('user_id', $user->id)])
            ->get()
            ->sortByDesc(fn ($j) => $j->match?->priority_score)
            ->take(6)->values();

        $heartbeat = $settings->state('scheduler_heartbeat');
        $lastScan = ScanRun::latest('started_at')->first();

        return response()->json([
            'stats' => [
                'total_jobs' => (clone $open)->count(),
                'new_today' => (clone $open)->where('first_seen_at', '>=', now()->subDay())->count(),
                'high_match' => (clone $highMatchQuery)->count(),
                'priority_applications' => (clone $highMatchQuery)->where('application_priority', 'high')->count(),
                'saved' => $user->savedJobs()->count(),
                'applied' => Application::where('user_id', $user->id)->whereNotNull('applied_at')->count(),
                'analyzed' => JobMatch::where('user_id', $user->id)->where('status', 'completed')->count(),
                'companies_monitored' => JobSource::where('enabled', true)->distinct('company_id')->count('company_id'),
                'sources_enabled' => JobSource::where('enabled', true)->count(),
            ],
            'high_match_threshold' => $highThreshold,
            'pipeline' => collect(Application::STATUSES)->map(fn ($label, $key) => ['status' => $key, 'label' => $label, 'count' => (int) ($pipeline[$key] ?? 0)])->values(),
            'top_matches' => JobListingResource::collection($top)->toArray($request),
            'last_scan' => $lastScan?->only(['id', 'status', 'trigger', 'started_at', 'finished_at', 'jobs_found', 'jobs_new', 'sources_total', 'sources_failed']),
            'next_scan_due' => $lastScan ? $lastScan->started_at->addHours((int) $settings->get('scan_interval_hours', 6))->toIso8601String() : null,
            'health' => [
                'scheduler_heartbeat' => $heartbeat,
                'scheduler_running' => $heartbeat && now()->diffInMinutes(Carbon::parse($heartbeat)) < 5,
                'ai_configured' => $ai->isConfigured(),
                'profile_ready' => (bool) $user->profile?->isUsable(),
                'profile_verified' => (bool) $user->profile?->is_verified,
                'failing_sources' => JobSource::where('enabled', true)->whereIn('health', ['failing', 'blocked'])->count(),
                'month_ai_spend' => round(AnthropicAiClient::monthSpend(), 4),
                'monthly_ai_budget' => (float) $settings->get('monthly_ai_budget_usd'),
                'pending_jobs' => DB::table('jobs')->count(),
            ],
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiUsageLog;
use App\Models\ImportError;
use App\Models\JobSource;
use App\Models\NotificationLog;
use App\Models\ScanRun;
use App\Models\TaskRun;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/** Monitoring: queue, failed jobs, scheduler, scans, connector health, API usage and cost. */
class SystemController extends Controller
{
    public function __invoke(SettingsService $settings): JsonResponse
    {
        $tasks = TaskRun::query()
            ->whereIn('id', TaskRun::selectRaw('MAX(id)')->groupBy('task'))
            ->orderBy('task')->get();

        $usage = ApiUsageLog::where('created_at', '>=', now()->startOfMonth())
            ->selectRaw('provider, purpose, COUNT(*) as calls, SUM(input_tokens) as input_tokens, SUM(output_tokens) as output_tokens, SUM(cost_usd) as cost_usd, SUM(CASE WHEN status != \'success\' THEN 1 ELSE 0 END) as errors')
            ->groupBy('provider', 'purpose')->get();

        $daily = ApiUsageLog::where('created_at', '>=', now()->subDays(14))
            ->selectRaw('DATE(created_at) as day, SUM(cost_usd) as cost_usd, COUNT(*) as calls')
            ->groupBy('day')->orderBy('day')->get();

        return response()->json([
            'scheduler_heartbeat' => $settings->state('scheduler_heartbeat'),
            'queue' => [
                'connection' => config('queue.default'),
                'pending' => DB::table('jobs')->selectRaw('queue, COUNT(*) as c')->groupBy('queue')->pluck('c', 'queue'),
                'failed' => DB::table('failed_jobs')->latest('failed_at')->limit(25)->get(['uuid', 'queue', 'failed_at', DB::raw('SUBSTRING(exception, 1, 400) as exception'), DB::raw('SUBSTRING(payload, 1, 300) as payload')]),
                'failed_count' => DB::table('failed_jobs')->count(),
                'batches' => DB::table('job_batches')->latest('created_at')->limit(10)->get(['id', 'name', 'total_jobs', 'pending_jobs', 'failed_jobs', 'created_at', 'finished_at']),
            ],
            'tasks' => $tasks,
            'scans' => ScanRun::withCount('sourceResults as sources_done')->latest('started_at')->limit(15)->get(),
            'sources' => [
                'by_health' => JobSource::selectRaw('health, COUNT(*) as c')->groupBy('health')->pluck('c', 'health'),
                'problems' => JobSource::with('company:id,name')->where('enabled', true)->whereIn('health', ['failing', 'blocked', 'degraded', 'unsupported'])
                    ->orderBy('health')->limit(50)->get(['id', 'company_id', 'name', 'type', 'identifier', 'enabled', 'health', 'last_error', 'limitation_note', 'last_scanned_at']),
                // Disabled sources with a recorded limitation (e.g. employers without a public jobs API).
                'limitations' => JobSource::with('company:id,name')->where('enabled', false)->whereNotNull('limitation_note')
                    ->orderBy('name')->limit(100)->get(['id', 'company_id', 'name', 'type', 'identifier', 'health', 'limitation_note']),
            ],
            'import_errors' => ImportError::with('source:id,name')->latest('occurred_at')->limit(25)->get(),
            'api_usage' => [
                'month' => $usage,
                'month_cost' => round((float) $usage->sum('cost_usd'), 4),
                'budget' => (float) $settings->get('monthly_ai_budget_usd'),
                'daily' => $daily,
            ],
            'notifications' => NotificationLog::latest()->limit(20)->get(['type', 'dedupe_key', 'status', 'subject', 'error', 'sent_at', 'created_at']),
        ]);
    }

    public function retryFailed(string $uuid): JsonResponse
    {
        Artisan::call('queue:retry', ['id' => [$uuid]]);

        return response()->json(['ok' => true]);
    }

    public function flushFailed(): JsonResponse
    {
        Artisan::call('queue:flush');

        return response()->json(['ok' => true]);
    }
}

<?php

namespace App\Jobs;

use App\Models\Application;
use App\Services\Ai\AiException;
use App\Services\Applications\ApplicationPackageService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class GenerateApplicationPackageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(public int $applicationId) {}

    public static function statusKey(int $applicationId): string
    {
        return 'package-status:'.$applicationId;
    }

    public function handle(ApplicationPackageService $service): void
    {
        $app = Application::with(['listing', 'user.profile'])->find($this->applicationId);
        if (! $app) {
            return;
        }
        Cache::put(self::statusKey($app->id), ['state' => 'generating'], 3600);

        try {
            $result = $service->generate($app);
            Cache::put(self::statusKey($app->id), ['state' => 'done', 'removed_unverified' => $result['removed_unverified']], 86400);
        } catch (AiException $e) {
            Cache::put(self::statusKey($app->id), ['state' => 'failed', 'error' => $e->getMessage()], 86400);
            if ($e->retryable) {
                throw $e;
            }
        }
    }
}

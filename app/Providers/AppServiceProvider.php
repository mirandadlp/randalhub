<?php

namespace App\Providers;

use App\Services\Ai\AiClient;
use App\Services\Ai\AnthropicAiClient;
use App\Services\SettingsService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SettingsService::class);
        $this->app->bind(AiClient::class, AnthropicAiClient::class);
    }

    public function boot(): void
    {
        // API rate limits.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(240)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perHour(30)->by($request->ip()),
        ]);
        RateLimiter::for('expensive', fn (Request $request) => Limit::perMinute(10)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('automation', fn (Request $request) => Limit::perMinute(120)->by($request->route('session')?->id ?? $request->ip()));

        // Queued Claude calls: at most 20 per minute.
        RateLimiter::for('claude', fn () => Limit::perMinute(20));
    }
}

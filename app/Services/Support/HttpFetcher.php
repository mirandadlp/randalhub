<?php

namespace App\Services\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Shared outbound HTTP client: identifies itself, retries transient failures,
 * enforces a per-host politeness delay, and caps response size.
 */
class HttpFetcher
{
    public const MAX_BYTES = 40 * 1024 * 1024;

    public function client(): PendingRequest
    {
        return Http::withUserAgent(config('jobhunter.http.user_agent'))
            ->timeout(config('jobhunter.http.timeout'))
            ->connectTimeout(15)
            ->retry(config('jobhunter.http.retries'), 1000, function (\Throwable $e) {
                // Retry connection errors and 429/5xx only.
                if ($e instanceof RequestException) {
                    $status = $e->response->status();

                    return $status === 429 || $status >= 500;
                }

                return $e instanceof ConnectionException;
            }, throw: false)
            ->acceptJson();
    }

    public function get(string $url, array $query = [], array $headers = []): Response
    {
        $this->throttle($url);

        return $this->client()->withHeaders($headers)->get($url, $query);
    }

    public function post(string $url, array $body = [], array $headers = []): Response
    {
        $this->throttle($url);

        return $this->client()->withHeaders($headers)->post($url, $body);
    }

    public function getHtml(string $url): Response
    {
        $this->throttle($url);

        return $this->client()->accept('text/html,application/xhtml+xml')->get($url);
    }

    /** Per-host rate limit: at most one request per configured delay, plus a 60/min cap. */
    private function throttle(string $url): void
    {
        $host = parse_url($url, PHP_URL_HOST) ?: 'unknown';
        $delayMs = config('jobhunter.http.request_delay_ms');

        $key = 'http-host:'.$host;
        while (RateLimiter::tooManyAttempts($key, 60)) {
            usleep(250_000);
        }
        RateLimiter::hit($key, 60);

        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }
    }
}

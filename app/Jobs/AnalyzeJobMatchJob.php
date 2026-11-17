<?php

namespace App\Jobs;

use App\Models\JobListing;
use App\Models\User;
use App\Services\Ai\AiException;
use App\Services\Matching\JobMatchService;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class AnalyzeJobMatchJob implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public array $backoff = [60, 300];

    public function __construct(public int $userId, public int $listingId, public bool $force = false) {}

    public function middleware(): array
    {
        return [
            new RateLimited('claude'),
            (new WithoutOverlapping('analyze:'.$this->userId.':'.$this->listingId))->dontRelease(),
        ];
    }

    public function handle(JobMatchService $service): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }
        $user = User::with('profile')->find($this->userId);
        $listing = JobListing::find($this->listingId);
        if (! $user || ! $listing) {
            return;
        }

        try {
            $service->analyze($user, $listing, $this->force);
        } catch (AiException $e) {
            if ($e->retryable && $this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 300);

                return;
            }
            // Non-retryable (budget, not configured, refusal): recorded on the match, don't crash the batch.
        }
    }
}

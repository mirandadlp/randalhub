<?php

namespace App\Jobs;

use App\Models\JobSource;
use App\Models\ScanRun;
use App\Services\Discovery\SourceScanner;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class ScanSourceJob implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 2;

    public int $timeout = 900;

    public array $backoff = [120];

    public function __construct(public int $sourceId, public ?int $scanRunId = null) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('scan-source:'.$this->sourceId))->releaseAfter(300)->expireAfter(1200)];
    }

    public function handle(SourceScanner $scanner): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }
        $source = JobSource::with('company')->find($this->sourceId);
        if (! $source) {
            return;
        }
        // Connector failures are recorded (not thrown) so one bad board never fails the run.
        $scanner->scan($source, $this->scanRunId ? ScanRun::find($this->scanRunId) : null);
    }
}

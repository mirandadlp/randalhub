<?php

namespace App\Console\Commands;

use App\Models\JobListing;
use App\Services\Support\HttpFetcher;
use App\Services\Support\RobotsTxt;
use Illuminate\Console\Command;

/**
 * Listings from boards that are scanned regularly are closed automatically when they disappear.
 * This command covers the rest: listings whose source has not been scanned successfully for 2+ days
 * (disabled or failing sources). It checks the posting URL and closes it on 404/410.
 */
class CheckAvailabilityCommand extends Command
{
    use TracksTaskRuns;

    protected $signature = 'jobhunter:check-availability {--limit=150}';

    protected $description = 'Verify that listings from stale sources are still available';

    public function handle(HttpFetcher $http, RobotsTxt $robots): int
    {
        return $this->tracked('check-availability', function () use ($http, $robots) {
            $closed = 0;
            $checked = 0;
            $listings = JobListing::open()
                ->where('last_seen_at', '<', now()->subDays(2))
                ->orderBy('last_seen_at')
                ->limit((int) $this->option('limit'))
                ->get();

            foreach ($listings as $listing) {
                if (! $robots->isAllowed($listing->source_url, config('jobhunter.http.user_agent'))) {
                    continue;
                }
                try {
                    $status = $http->getHtml($listing->source_url)->status();
                } catch (\Throwable) {
                    continue;
                }
                $checked++;
                if (in_array($status, [404, 410], true)) {
                    $listing->update(['status' => 'closed', 'closed_at' => now()]);
                    $closed++;
                }
            }
            $this->info("Checked {$checked}, closed {$closed}.");

            return compact('checked', 'closed');
        });
    }
}

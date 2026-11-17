<?php

namespace App\Services\Discovery;

use App\Models\JobListing;
use Illuminate\Support\Facades\DB;

/**
 * Cross-source duplicate and repost detection, run after every import.
 *  - Duplicates: open listings sharing a fingerprint (company + title + location) seen through
 *    different sources, or through the same source with identical content. The earliest-discovered
 *    listing stays canonical; the rest point to it via duplicate_of_id.
 *  - Reposts: a newly discovered listing whose fingerprint matches a listing that closed earlier.
 */
class DeduplicationService
{
    public function run(): array
    {
        $duplicates = 0;
        $reposts = 0;

        $groups = JobListing::query()
            ->where('status', 'open')
            ->select('fingerprint', DB::raw('COUNT(*) as c'))
            ->groupBy('fingerprint')
            ->having('c', '>', 1)
            ->pluck('fingerprint');

        foreach ($groups as $fingerprint) {
            $listings = JobListing::where('fingerprint', $fingerprint)->where('status', 'open')
                ->orderBy('first_seen_at')->orderBy('id')->get();
            $canonical = $listings->first();
            foreach ($listings->skip(1) as $listing) {
                $crossSource = $listing->primary_source_id !== $canonical->primary_source_id;
                $sameContent = $listing->content_hash === $canonical->content_hash;
                if (($crossSource || $sameContent) && $listing->duplicate_of_id !== $canonical->id) {
                    $listing->update(['duplicate_of_id' => $canonical->id]);
                    $duplicates++;
                }
            }
        }

        // Repost detection for listings discovered in the last 3 days.
        JobListing::query()
            ->where('status', 'open')
            ->where('is_repost', false)
            ->where('first_seen_at', '>=', now()->subDays(3))
            ->chunkById(200, function ($recent) use (&$reposts) {
                foreach ($recent as $listing) {
                    $previous = JobListing::where('fingerprint', $listing->fingerprint)
                        ->where('id', '!=', $listing->id)
                        ->whereIn('status', ['closed', 'archived'])
                        ->where('closed_at', '>=', now()->subDays(180))
                        ->orderByDesc('closed_at')
                        ->first();
                    if ($previous) {
                        $listing->update(['is_repost' => true, 'repost_of_id' => $previous->id]);
                        $reposts++;
                    }
                }
            });

        return ['duplicates' => $duplicates, 'reposts' => $reposts];
    }
}

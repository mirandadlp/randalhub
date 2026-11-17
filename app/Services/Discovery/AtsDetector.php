<?php

namespace App\Services\Discovery;

use Illuminate\Support\Str;

/**
 * Finds a company's public ATS board by trying slug variants against documented public APIs.
 * Used to grow the watchlist with employers the user names (or discovers via search APIs).
 */
class AtsDetector
{
    public function __construct(private ConnectorRegistry $registry) {}

    /** @return list<array{type: string, identifier: string, jobs: int}> */
    public function detect(string $name): array
    {
        $base = Str::of($name)->lower()->ascii()->replaceMatches('/\b(inc|llc|ltd|corp|corporation|co|company)\b\.?/', '')->trim();
        $variants = array_values(array_unique(array_filter([
            (string) $base->replaceMatches('/[^a-z0-9]+/', ''),
            (string) $base->slug(),
            (string) $base->replaceMatches('/[^a-z0-9]+/', '')->append('hq'),
            (string) $base->replaceMatches('/[^a-z0-9]+/', '')->append('inc'),
        ])));

        $found = [];
        foreach (ConnectorRegistry::PROBEABLE as $type) {
            $connector = $this->registry->for($type);
            foreach ($variants as $slug) {
                $count = $connector->probe($slug);
                if ($count !== null && $count > 0) {
                    $found[] = ['type' => $type, 'identifier' => $slug, 'jobs' => $count];
                    break;
                }
            }
        }

        return $found;
    }
}

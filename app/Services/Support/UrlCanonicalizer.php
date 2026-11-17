<?php

namespace App\Services\Support;

class UrlCanonicalizer
{
    private const TRACKING_PARAMS = [
        'gh_src', 'gh_jid', 'lever-source', 'lever-origin', 'source', 'src', 'ref', 'referrer', 'utm_source',
        'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'gclid', 'mc_cid', 'mc_eid', 'trk',
    ];

    public static function canonicalize(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['host'])) {
            return strtolower($url);
        }

        $scheme = 'https';
        $host = strtolower(preg_replace('/^www\./i', '', $parts['host']));
        $path = rtrim($parts['path'] ?? '', '/');
        $path = preg_replace('#/+#', '/', $path);
        // Apply pages map to the same posting.
        $path = preg_replace('#/(apply|application)$#i', '', $path);

        $query = [];
        if (! empty($parts['query'])) {
            parse_str($parts['query'], $query);
            $query = array_diff_key($query, array_flip(self::TRACKING_PARAMS));
            foreach (array_keys($query) as $k) {
                if (str_starts_with((string) $k, 'utm_')) {
                    unset($query[$k]);
                }
            }
            ksort($query);
        }

        return $scheme.'://'.$host.$path.($query ? '?'.http_build_query($query) : '');
    }

    public static function hash(string $url): string
    {
        return hash('sha256', static::canonicalize($url));
    }

    /** Only http(s) URLs to public hosts are acceptable as outbound targets or stored links. */
    public static function isSafePublicUrl(?string $url): bool
    {
        if (blank($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $parts = parse_url($url);
        if (! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return false;
        }
        $host = strtolower($parts['host'] ?? '');
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return (bool) filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        return true;
    }
}

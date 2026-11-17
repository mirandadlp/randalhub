<?php

namespace App\Services\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Minimal robots.txt evaluator (User-agent groups, Allow/Disallow with longest-match precedence,
 * "*" and "$" wildcards). Used before crawling any HTML career page.
 */
class RobotsTxt
{
    public function __construct(private HttpFetcher $http) {}

    public function isAllowed(string $url, ?string $userAgent = null): bool
    {
        $parts = parse_url($url);
        if (! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');

        $robots = Cache::remember('robots:'.$origin, 3600 * 12, function () use ($origin) {
            try {
                $response = $this->http->client()->accept('text/plain')->get($origin.'/robots.txt');
            } catch (\Throwable) {
                return ['status' => 'unreachable', 'body' => ''];
            }

            return ['status' => $response->status(), 'body' => $response->successful() ? $response->body() : ''];
        });

        // 4xx (no robots.txt) => allowed. 5xx/unreachable => assume disallowed (conservative).
        if ($robots['status'] === 'unreachable' || (is_int($robots['status']) && $robots['status'] >= 500)) {
            return false;
        }

        return static::evaluate($robots['body'], $path, $userAgent ?? 'AIJobHunter');
    }

    public static function evaluate(string $body, string $path, string $userAgent): bool
    {
        $groups = [];
        $current = null;
        $lastWasAgent = false;

        foreach (preg_split('/\r\n|\r|\n/', $body) as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line));
            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }
            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);

            if ($field === 'user-agent') {
                if (! $lastWasAgent) {
                    $groups[] = ['agents' => [], 'rules' => []];
                    $current = count($groups) - 1;
                }
                $groups[$current]['agents'][] = strtolower($value);
                $lastWasAgent = true;

                continue;
            }

            $lastWasAgent = false;
            if ($current !== null && in_array($field, ['allow', 'disallow'], true)) {
                $groups[$current]['rules'][] = [$field, $value];
            }
        }

        $ua = strtolower($userAgent);
        $matched = array_filter($groups, function ($g) use ($ua) {
            foreach ($g['agents'] as $agent) {
                if ($agent !== '*' && str_contains($ua, $agent)) {
                    return true;
                }
            }

            return false;
        });
        if (! $matched) {
            $matched = array_filter($groups, fn ($g) => in_array('*', $g['agents'], true));
        }

        $best = null;
        $bestLen = -1;
        foreach ($matched as $group) {
            foreach ($group['rules'] as [$type, $pattern]) {
                if ($pattern === '') {
                    continue; // "Disallow:" with empty value allows everything
                }
                $regex = '#^'.str_replace(['\*', '\$'], ['.*', '$'], preg_quote($pattern, '#')).'#';
                if (preg_match($regex, $path) && strlen($pattern) > $bestLen) {
                    $best = $type;
                    $bestLen = strlen($pattern);
                } elseif (preg_match($regex, $path) && strlen($pattern) === $bestLen && $type === 'allow') {
                    $best = 'allow';
                }
            }
        }

        return $best !== 'disallow';
    }
}

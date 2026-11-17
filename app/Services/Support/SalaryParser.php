<?php

namespace App\Services\Support;

class SalaryParser
{
    /**
     * Parse a salary range from free text such as "$150,000 - $200,000 USD", "$207K – $285K",
     * or "$45/hr". Returns null when no confident range is found. Never guesses.
     *
     * @return array{min: int, max: int, currency: string, interval: string, text: string}|null
     */
    public static function parse(?string $text): ?array
    {
        if (blank($text)) {
            return null;
        }
        $pattern = '/(?<cur>\$|USD\s?)\s?(?<a>\d{1,3}(?:[,.]\d{3})*(?:\.\d+)?)\s?(?<ak>[kK])?\s*(?:-|–|—|to)\s*(?:\$|USD\s?)?\s?(?<b>\d{1,3}(?:[,.]\d{3})*(?:\.\d+)?)\s?(?<bk>[kK])?(?<rest>[^\n]{0,40})/u';
        if (! preg_match_all($pattern, $text, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return null;
        }

        foreach ($all as $match) {
            $offset = $match[0][1];
            $before = strtolower(substr($text, max(0, $offset - 160), min(160, $offset)));
            $after = strtolower(substr($text, $offset, 200));
            $context = $before.' '.$after;
            // Must look like pay; must not look like revenue, deal sizes, budgets or funding.
            if (! preg_match('/(salary|compensation|pay range|pay|base|wage|ote|hourly rate|annual|per year|\/yr|\/year|per hour|\/hr)/', $context)) {
                continue;
            }
            if (preg_match('/(portfolio|revenue|arr\b|quota|budget|deal size|book of business|valuation|funding|raised|in sales|pipeline|spend|customers? (worth|valued))/', $before.' '.substr($after, 0, 80))) {
                continue;
            }
            $m = array_map(fn ($g) => is_array($g) ? $g[0] : $g, $match);
            $parsed = self::fromMatch($m);
            if ($parsed) {
                return $parsed;
            }
        }

        return null;
    }

    private static function fromMatch(array $m): ?array
    {
        $a = self::toNumber($m['a'], ! empty($m['ak']) || ! empty($m['bk']));
        $b = self::toNumber($m['b'], ! empty($m['bk']));
        if ($a <= 0 || $b <= 0 || $b < $a) {
            return null;
        }

        $rest = strtolower($m['rest'] ?? '');
        $interval = 'year';
        if (preg_match('/(per hour|\/hr|\/hour|hourly|an hour)/', $rest) || $b < 500) {
            $interval = 'hour';
        } elseif (preg_match('/(per month|\/mo|monthly)/', $rest)) {
            $interval = 'month';
        }

        // Annual figures below $10k are almost certainly a parsing mistake.
        if ($interval === 'year' && $b < 10000) {
            return null;
        }

        return ['min' => (int) $a, 'max' => (int) $b, 'currency' => 'USD', 'interval' => $interval, 'text' => trim($m[0])];
    }

    private static function toNumber(string $raw, bool $thousands): float
    {
        $n = (float) str_replace(',', '', $raw);
        if (preg_match('/^\d{1,3}\.\d{3}$/', $raw)) {
            $n = (float) str_replace('.', '', $raw);
        }

        return $thousands ? $n * 1000 : $n;
    }

    /** Annualize for comparisons against the minimum-salary preference. */
    public static function annualize(?int $amount, ?string $interval): ?int
    {
        if ($amount === null) {
            return null;
        }

        return match ($interval) {
            'hour' => $amount * 2080,
            'month' => $amount * 12,
            default => $amount,
        };
    }
}

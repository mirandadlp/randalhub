<?php

namespace App\Services\Support;

use Illuminate\Support\Str;

class TextNormalizer
{
    /** Convert untrusted HTML (possibly entity-escaped) to readable plain text. */
    public static function htmlToText(?string $html): string
    {
        if (blank($html)) {
            return '';
        }
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Decode twice: Greenhouse double-escapes content.
        if (str_contains($html, '&lt;')) {
            $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $html = preg_replace('#<(script|style|noscript|iframe)[^>]*>.*?</\1>#is', ' ', $html);
        $html = preg_replace('#<\s*(br|/p|/div|/li|/h[1-6]|/tr)\s*/?>#i', "\n", $html);
        $html = preg_replace('#<\s*li[^>]*>#i', "\n• ", $html);
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);
        $text = preg_replace("/[ \t]+/", ' ', $text);
        $text = preg_replace("/\n[ \t]*\n(\s*•)/u", "\n$1", $text);
        $text = preg_replace("/[ \t]*\n[ \t]*/", "\n", $text);
        $text = preg_replace("/\n\s*\n\s*\n+/", "\n\n", $text);

        return trim($text);
    }

    /**
     * Sanitize untrusted HTML for display: keep a small allowlist of formatting tags, drop all attributes.
     */
    public static function sanitizeHtml(?string $html): string
    {
        if (blank($html)) {
            return '';
        }
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (str_contains($html, '&lt;')) {
            $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $html = preg_replace('#<(script|style|noscript|iframe|object|embed|form|svg|math)[^>]*>.*?</\1>#is', '', $html);
        $allowed = '<p><br><ul><ol><li><strong><b><em><i><h1><h2><h3><h4><h5><h6><blockquote><table><thead><tbody><tr><td><th><span><div>';
        $html = strip_tags($html, $allowed);
        // Remove every attribute (event handlers, styles, links).
        $html = preg_replace('#<([a-z0-9]+)(\s[^>]*)?>#i', '<$1>', $html);

        return trim($html);
    }

    public static function normalizeTitle(string $title): string
    {
        $t = Str::lower(Str::ascii($title));
        $t = preg_replace('/\((.*?)\)/', ' ', $t);
        $t = str_replace(['sr.', 'sr ', 'jr.', '&'], ['senior', 'senior ', 'junior', 'and'], $t);
        $t = preg_replace('/[^a-z0-9 ]+/', ' ', $t);

        return trim(preg_replace('/\s+/', ' ', $t));
    }

    public static function normalizeCompany(string $name): string
    {
        $n = Str::lower(Str::ascii($name));
        $n = preg_replace('/\b(inc|llc|ltd|corp|corporation|co|company|plc|gmbh)\b\.?/', '', $n);

        return trim(preg_replace('/[^a-z0-9]+/', '', $n));
    }

    public static function normalizeLocation(?string $location): string
    {
        $l = Str::lower(Str::ascii((string) $location));

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $l));
    }

    /** Pull out the requirements/qualifications section when the description has one. */
    public static function extractRequirements(string $text): ?string
    {
        $pattern = '/^(.*?(requirements|qualifications|what you.?ll (need|bring)|you (have|bring|might be a good fit)|about you|who you are|minimum qualifications|preferred qualifications|skills).*?)$/im';
        if (! preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $start = $m[0][1];
        $section = mb_substr(substr($text, $start), 0, 4000);

        return trim($section) ?: null;
    }
}

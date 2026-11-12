<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * Ang description (instructions) ng checklist task bilang HTML: may linya, at clickable ang mga link.
 *
 * Ligtas: lahat ng text ay HTML-escaped muna; ang idinadagdag lang ay <br> at <a>. Ang link ay
 * ginagawa lang mula sa:
 *   - buong URL na http:// o https:// (ang ibang scheme gaya ng javascript: o data: ay text lang),
 *   - likhaaitech.com/... (o www.likhaaitech.com/...): papunta sa parehong path sa kasalukuyang host,
 *   - /path na nagsisimula sa titik, numero, _ o - (hindi "//", kaya walang ibang host).
 * Bukas sa bagong tab, rel="noopener".
 */
class ChecklistText
{
    private const LINK = '~
        (?<url> https?://[^\s<>"\'`]+ )
      | (?<host> (?<![\w.@/-]) (?:www\.)? likhaaitech\.com (?: /[^\s<>"\'`]* )? (?![\w.-]*\w) )
      | (?<path> (?<![\w.:/@<-]) /[A-Za-z0-9_-][^\s<>"\'`]* )
    ~xiu';

    public static function render(?string $text): HtmlString
    {
        $text = str_replace(["\r\n", "\r"], "\n", (string) $text);

        if (preg_match_all(self::LINK, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL) === false) {
            // Hal. sirang UTF-8: escaped na text na lang, walang link.
            return new HtmlString(nl2br(e($text), false));
        }

        $html = '';
        $pos = 0;
        foreach ($matches as $m) {
            [$raw, $at] = $m[0];
            $html .= e(substr($text, $pos, $at - $pos));
            $pos = $at + strlen($raw);

            [$label, $tail] = self::trimTrailing($raw);
            $href = self::href($m, $label);
            $html .= $href === null
                ? e($label)
                : '<a href="'.e($href).'" target="_blank" rel="noopener" class="text-blue-600 underline break-all">'.e($label).'</a>';
            $html .= e($tail);
        }
        $html .= e(substr($text, $pos));

        return new HtmlString(nl2br($html, false));
    }

    /** Ang href ng link, o null kung hindi na link pagkatapos alisin ang bantas sa dulo. */
    private static function href(array $m, string $label): ?string
    {
        if (self::group($m, 'url')) {
            return preg_match('~^https?://[^/?#\s]~i', $label) ? $label : null;
        }
        if (self::group($m, 'host')) {
            $path = preg_replace('~^(?:www\.)?likhaaitech\.com~i', '', $label);

            return str_starts_with($path, '/') ? $path : '/';
        }

        return preg_match('~^/[A-Za-z0-9_-]~', $label) ? $label : null;
    }

    private static function group(array $m, string $name): bool
    {
        return isset($m[$name][0]) && $m[$name][0] !== '';
    }

    /**
     * "…/daily-output." → link "…/daily-output", text "." ; ang ")" sa dulo ay kasama lang
     * kung may katapat na "(" sa link.
     *
     * @return array{0:string,1:string}
     */
    private static function trimTrailing(string $raw): array
    {
        $label = $raw;
        while ($label !== '') {
            $last = substr($label, -1);
            if (str_contains('.,;:!?\'"]}', $last)
                || ($last === ')' && substr_count($label, '(') < substr_count($label, ')'))) {
                $label = substr($label, 0, -1);

                continue;
            }
            break;
        }

        return [$label, substr($raw, strlen($label))];
    }
}

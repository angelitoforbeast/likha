<?php

namespace App\Services\Chat;

use Illuminate\Support\Str;

/**
 * Likha Chat (spec 296): ang text ng message bilang ligtas na HTML. Lahat ay escaped; ang http(s) link lang ang
 * nagiging <a> (walang javascript: o ibang scheme), at ang bagong linya ay <br>. Walang HTML mula sa user.
 */
class ChatText
{
    /** Hanggang sa espasyo, < > " ' o control character; ang bantas sa dulo ay hindi kasama sa link. */
    private const URL = '~https?://[^\s<>"\'\x00-\x1f\x7f]+~iu';

    public static function toHtml(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $out = '';
        $pos = 0;
        preg_match_all(self::URL, $text, $found, PREG_OFFSET_CAPTURE);
        foreach ($found[0] as [$url, $offset]) {
            $url = rtrim($url, '.,!?;:)]}');
            if (strlen($url) <= strlen('https://') || $offset < $pos) {
                continue;
            }
            $out .= e(substr($text, $pos, $offset - $pos));
            $out .= '<a href="' . e($url) . '" target="_blank" rel="noopener noreferrer nofollow">' . e($url) . '</a>';
            $pos = $offset + strlen($url);
        }
        $out .= e(substr($text, $pos));

        return str_replace("\n", '<br>', $out);
    }

    /** Isang linya para sa listahan at sa reply quote: buo ang whitespace, pinaikli. */
    public static function oneLine(?string $text, int $limit = 140): string
    {
        return Str::limit(trim((string) preg_replace('/\s+/u', ' ', (string) $text)), $limit);
    }
}

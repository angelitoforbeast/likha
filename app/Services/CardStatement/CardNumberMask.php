<?php

namespace App\Services\CardStatement;

/**
 * Buong card number ay hindi kailanman sine-save o pinapakita → "···· " + huling 4:
 *  1. Anumang 13–19 digit, magkakadikit o naka-grupo sa anumang hati (4-4-4-4, Diners 4-6-4, Amex 4-6-5, 8-8 …),
 *     na pinaghihiwalay ng space (pati NBSP at ibang Unicode space), tab, tuldok, dash, slash o asterisk.
 *     Ang unang grupo ay ≥4 digit, kaya hindi nakakain ang dulo ng FACEBK code ("*AB12") o ang "09/09/26";
 *     ang pinakamaikling buong grupo na umaabot sa 13 ang kinukuha, kaya ang kasunod na numero ("… 5003 12")
 *     ay naiiwan; hindi minamask kapag amount ang kasunod ("100.00") o petsa ang simula ("2026-09-10").
 *  2. Anumang ≥8 digit na katabi ng card / acct / account / no. / number sa iisang linya (hal. "Account No. 12345678").
 */
class CardNumberMask
{
    private const SEP = '[\p{Zs}\t.\-\/*]';
    private const DATE = '/^\d{4}[\-\/.]\d{1,2}[\-\/.]\d{1,2}(?!\d)/';
    private const WORD = '(?:card|acct|account|number|no\.)';
    // Para sa salita + digits: walang tuldok sa pagitan (amount iyon), at hindi dumadaan sa naka-mask na "····".
    private const KSEP = '[\p{Zs}\t\-\/*]';

    public static function mask(string $text): string
    {
        $text = self::maskRuns($text);

        // 2. Katabi ng salita: salita … digits, o digits … salita.
        $text = preg_replace_callback(
            '/\b' . self::WORD . '(?![a-z])[^\d\n·]{0,12}?\K(?<!\d)\d(?:' . self::KSEP . '{0,3}\d){7,}(?!\d|[.,]\d)/iu',
            fn ($m) => self::isDate($m[0]) ? $m[0] : self::masked($m[0]),
            $text
        ) ?? '';

        return preg_replace_callback(
            '/(?<![\d.,])\d(?:' . self::KSEP . '{0,3}\d){7,}(?!\d|[.,]\d)(?=[^\d\n]{0,3}\b(?:card|acct|account|number)\b)/iu',
            fn ($m) => self::isDate($m[0]) ? $m[0] : self::masked($m[0]),
            $text
        ) ?? '';
    }

    private static function maskRuns(string $text): string
    {
        if (!preg_match_all('/(?<!\d)\d{4,}(?:' . self::SEP . '{1,8}\d+)*/u', $text, $all, PREG_OFFSET_CAPTURE)) return $text;

        // Mula sa dulo, para hindi gumalaw ang mga byte offset ng mga nauna.
        foreach (array_reverse($all[0]) as [$run, $start]) {
            if (self::isDate($run)) continue;
            preg_match_all('/\d+/', $run, $groups, PREG_OFFSET_CAPTURE);
            $digits = 0;
            $end = null;
            foreach ($groups[0] as [$g, $off]) {
                $digits += strlen($g);
                if ($digits >= 13) { $end = $off + strlen($g); break; }
            }
            if ($end === null || $digits > 19) continue;

            $after = substr($text, $start + $end, 2);
            if (preg_match('/^[.,]\d/', $after)) continue; // amount ang kasunod, hindi card

            $text = substr($text, 0, $start) . self::masked(substr($run, 0, $end)) . substr($text, $start + $end);
        }

        return $text;
    }

    private static function isDate(string $run): bool
    {
        return (bool) preg_match(self::DATE, $run);
    }

    private static function masked(string $run): string
    {
        return '···· ' . substr(preg_replace('/\D/', '', $run), -4);
    }
}

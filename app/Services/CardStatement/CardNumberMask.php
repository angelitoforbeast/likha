<?php

namespace App\Services\CardStatement;

/**
 * Buong card number ay hindi kailanman sine-save o pinapakita → "···· " + huling 4:
 *  1. Anumang 13–19 digit, magkakadikit o naka-grupo sa anumang hati (4-4-4-4, Diners 4-6-4, Amex 4-6-5, 8-8 …),
 *     na pinaghihiwalay ng space (pati NBSP at ibang Unicode space), tab, tuldok, dash, slash o asterisk.
 *     Bawat window ng buong grupo ay sinusuri (hindi lang mula sa simula), kaya ang petsa, amount o ibang numero
 *     bago ang card number ("2026-09-10 4111…", "AUTH 123456 4111…") ay hindi nakakapagtago nito; ang petsa,
 *     ang dulo ng FACEBK code ("*AB12"), ang amount ("100.00") at ang maikling numero pagkatapos ("… 5003 12")
 *     ay hindi kinakain.
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
        if (!preg_match_all('/(?<!\d)\d+(?:' . self::SEP . '{1,16}\d+)*/u', $text, $all, PREG_OFFSET_CAPTURE)) return $text;

        // Mula sa dulo, para hindi gumalaw ang mga byte offset ng mga nauna.
        foreach (array_reverse($all[0]) as [$run, $start]) {
            preg_match_all('/\d+/', $run, $m, PREG_OFFSET_CAPTURE);
            $groups = array_map(fn ($g) => ['len' => strlen($g[0]), 'from' => $g[1], 'to' => $g[1] + strlen($g[0])], $m[0]);
            $n = count($groups);
            $seps = [];
            for ($k = 1; $k < $n; $k++) $seps[$k] = substr($run, $groups[$k - 1]['to'], $groups[$k]['from'] - $groups[$k - 1]['to']);

            // Mga grupong bahagi ng petsa (2026-09-30, 12/31/2026, 09/09/26) — hindi kasama sa card number.
            $date = [];
            for ($k = 0; $k + 2 < $n; $k++) {
                $tight = preg_match('/^[\-\/.]$/', $seps[$k + 1]) && preg_match('/^[\-\/.]$/', $seps[$k + 2]);
                [$a, $b, $c] = [$groups[$k]['len'], $groups[$k + 1]['len'], $groups[$k + 2]['len']];
                if ($tight && (($a === 4 && $b <= 2 && $c <= 2) || ($a <= 2 && $b <= 2 && in_array($c, [2, 4], true)))) {
                    $date[$k] = $date[$k + 1] = $date[$k + 2] = true;
                }
            }
            // Mga grupong bahagi ng amount ("100.00", "6,278.17"): ang ".dd" sa dulo at ang bago nito.
            for ($k = 1; $k < $n; $k++) {
                $cents = in_array($seps[$k], ['.', ','], true) && $groups[$k]['len'] === 2 && ($k === $n - 1 || !in_array($seps[$k + 1], ['.', ','], true));
                if ($cents) $date[$k - 1] = $date[$k] = true;
            }

            // Bawat window ng buong grupo na 13–19 digit: nagsisimula sa grupong ≥4 digit (hindi dulo ng FACEBK
            // code o sentimo), nagtatapos sa grupong ≥4 digit o eksaktong 19 digit (4-4-4-4-3), walang petsa, at
            // hindi sinusundan ng ".dd" (amount). Ang mga window na nagsasalo ng grupo ay pinagsasama; ang magkatabing
            // card number na hindi nagsasalo ("4111…5003 4111…5004") ay tig-isang mask.
            $spans = [];
            for ($i = 0; $i < $n; $i++) {
                if ($groups[$i]['len'] < 4 || isset($date[$i])) continue;
                $digits = 0;
                for ($j = $i; $j < $n && !isset($date[$j]); $j++) {
                    $digits += $groups[$j]['len'];
                    if ($digits > 19) break;
                    // Maikling huling grupo (Amex 4-4-4-3, Diners 4-4-4-2, 4-4-4-4-3) ay pwede kapag dito natatapos
                    // ang numero (dulo ng run, o petsa/amount ang kasunod); sa gitna, ibang numero iyon.
                    $endsRun = $j === $n - 1 || isset($date[$j + 1]);
                    if ($digits < 13 || ($groups[$j]['len'] < 4 && $digits !== 19 && !$endsRun)) continue;
                    if (preg_match('/^[.,]\d/', substr($text, $start + $groups[$j]['to'], 2))) continue;
                    $last = array_key_last($spans);
                    if ($last !== null && $i <= $spans[$last][1]) $spans[$last][1] = max($spans[$last][1], $j);
                    else $spans[] = [$i, $j];
                }
            }

            foreach (array_reverse($spans) as [$i, $j]) {
                $from = $start + $groups[$i]['from'];
                $to = $start + $groups[$j]['to'];
                $text = substr($text, 0, $from) . self::masked(substr($text, $from, $to - $from)) . substr($text, $to);
            }
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

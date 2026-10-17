<?php

namespace App\Services\CardStatement;

/**
 * Buong card number ay hindi kailanman sine-save o pinapakita: 13–19 na magkakadikit na digit, o naka-grupo gaya ng
 * card (4-4-4-x o Amex 4-6-5; anumang space (NBSP, thin space), tab, tuldok o dash, hanggang 8 sa pagitan, gaya ng pdftotext -layout)
 * → "···· " + huling 4. Pwedeng nakadikit sa letra ("CARDNO4111…"), pero ang grupo ay eksaktong 4 na digit kaya
 * hindi nakakain ang dulo ng FACEBK code (hal. "*AB12"), at hindi kasama ang amount na kasunod (hal. "100.00").
 */
class CardNumberMask
{
    private const SEP  = '[\p{Zs}\t.\-]{1,8}';
    private const CARD = '/(?<!\d)(?:\d{13,19}|\d{4}' . self::SEP . '\d{6}' . self::SEP . '\d{5}|\d{4}(?:' . self::SEP . '\d{4}){2}' . self::SEP . '\d{1,7})(?!\d|[.,]\d)/u';

    public static function mask(string $text): string
    {
        return preg_replace_callback(self::CARD, function ($m) {
            $digits = preg_replace('/\D/', '', $m[0]);

            return '···· ' . substr($digits, -4);
        }, $text) ?? '';
    }
}

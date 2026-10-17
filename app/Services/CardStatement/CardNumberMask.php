<?php

namespace App\Services\CardStatement;

/**
 * Buong card number ay hindi kailanman sine-save o pinapakita: 13–19 na magkakadikit na digit, o naka-grupo gaya ng
 * card (4-4-4-x o Amex 4-6-5, space o dash) → "···· " + huling 4. Dapat hindi nakadikit sa letra o digit sa unahan,
 * para hindi makain ang dulo ng FACEBK code (hal. "*AB12") o ang amount na kasunod (hal. "100.00").
 */
class CardNumberMask
{
    private const CARD = '/(?<![A-Za-z0-9])(?:\d{13,19}|\d{4}[ \-]\d{6}[ \-]\d{5}|\d{4}(?:[ \-]\d{4}){2}[ \-]\d{1,7})(?!\d|[.,]\d)/';

    public static function mask(string $text): string
    {
        return preg_replace_callback(self::CARD, function ($m) {
            $digits = preg_replace('/\D/', '', $m[0]);

            return '···· ' . substr($digits, -4);
        }, $text);
    }
}

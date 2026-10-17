<?php

namespace App\Services\CardStatement;

/**
 * Buong card number ay hindi kailanman sine-save o pinapakita: bawat 13–19 digit (pwede may isang space o dash
 * sa pagitan ng grupo) ay nagiging "···· " + huling 4. Hindi kasama ang digits ng amount (hal. "100.00").
 */
class CardNumberMask
{
    public static function mask(string $text): string
    {
        return preg_replace_callback('/(?<!\d)\d(?:[ \-]?\d){12,18}(?!\d|[.,]\d)/', function ($m) {
            $digits = preg_replace('/\D/', '', $m[0]);

            return '···· ' . substr($digits, -4);
        }, $text);
    }
}

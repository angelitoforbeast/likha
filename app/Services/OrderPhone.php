<?php

namespace App\Services;

/**
 * Ang phone ng isang order, walang AI: ang D (GPT PHONE NUMBER) kapag valid; kung hindi, ang iisang PH mobile sa
 * sinulat ng customer (E) at saka sa extracted details (G). Dalawa o higit pang magkaibang numero = walang phone:
 * mas masama ang maling tao kaysa walang link. Hindi kailanman tinatanggap ang presyo o maiikling numero.
 */
class OrderPhone
{
    // 09XXXXXXXXX, 9XXXXXXXXX, +639…, 639…: puwedeng may espasyo, gitling o tuldok sa pagitan ng mga digit.
    private const MOBILE = '/(?<!\d)(?:\+?[ \t]*63|0)?[ \t\-.]*9(?:[ \t\-.]?\d){9}(?!\d)/';

    /** Ang mga magkakaibang PH mobile (anyong 09XXXXXXXXX) na nasa text. */
    public static function numbers(?string $text): array
    {
        preg_match_all(self::MOBILE, (string) $text, $m);
        $found = [];
        foreach ($m[0] as $raw) {
            $p = BotcakeCustomerMatcher::normPhone($raw);
            if ($p !== '') $found[$p] = true;
        }

        return array_keys($found);
    }

    /** Ang iisang numero sa text, o '' kapag wala o higit sa isa. */
    public static function extract(?string $text): string
    {
        $n = self::numbers($text);

        return count($n) === 1 ? $n[0] : '';
    }

    /** D, kung hindi E, kung hindi G. Ang E na may dalawang numero ay humihinto na: hindi na tinitingnan ang G. */
    public static function forOrder(?string $d, ?string $e, ?string $g): string
    {
        $phone = BotcakeCustomerMatcher::normPhone($d);
        if ($phone !== '') return $phone;

        $fromE = self::numbers($e);
        if ($fromE) return count($fromE) === 1 ? $fromE[0] : '';

        return self::extract($g);
    }
}

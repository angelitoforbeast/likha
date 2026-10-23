<?php

namespace App\Services\OrderEncode;

use App\Services\MacroChecker;

/**
 * Ang isinulat ng customer (Province, City, Brgy ng extracted details) na ginawang pantay-pantay na anyo para sa paghahambing:
 * maliit na titik, Ñ = N, "brgy/barangay/bgy/pob/poblacion" ay tiklop, ang numero at roman numeral ay iisa, at sa city ang "city"
 * at ang prefix na province ay tinatanggal. Ginagamit ang mga normaliser ng MacroChecker (read-only) bilang base.
 */
final class Written
{
    public const PART_MAX = 80;

    public static function brgy(?string $v): string
    {
        $s = MacroChecker::normBrgyKey((string) $v);
        $s = self::clean($s);
        $rest = self::clean(preg_replace('/\b(poblacion|barangay|brgy|bgy|pob)\b/u', ' ', $s) ?? $s);

        // "POBLACION" lang ang pangalan: huwag gawing blangko.
        return $rest !== '' ? $rest : $s;
    }

    public static function city(?string $v, ?string $province = null): string
    {
        $s = self::clean(MacroChecker::normCityKey((string) $v));
        $s = self::clean(preg_replace('/\b(city|municipality)\b/u', ' ', $s) ?? $s) ?: $s;

        $p = self::prov($province);
        if ($p !== '' && str_starts_with($s . ' ', $p . ' ')) {
            $rest = self::clean(substr($s, strlen($p)));
            if ($rest !== '') $s = $rest;
        }

        return $s;
    }

    public static function prov(?string $v): string
    {
        return self::clean(Text::normProv((string) $v));
    }

    /** @param array{province?: string, city?: string, barangay?: string} $parts */
    public static function key(array $parts): string
    {
        $province = (string) ($parts['province'] ?? '');
        $cut = fn (string $s) => mb_substr($s, 0, self::PART_MAX);

        return $cut(self::brgy($parts['barangay'] ?? '')) . '|' . $cut(self::city($parts['city'] ?? '', $province)) . '|' . $cut(self::prov($province));
    }

    /** @return array{0: string, 1: string, 2: string} barangay, city, province ng key */
    public static function split(string $key): array
    {
        return array_pad(explode('|', $key, 3), 3, '');
    }

    public static function isEmptyKey(string $key): bool
    {
        return trim(str_replace('|', '', $key)) === '';
    }

    /** @param array{province?: string, city?: string, barangay?: string} $parts */
    public static function example(array $parts): string
    {
        return mb_substr('Brgy: ' . ($parts['barangay'] ?? '') . ' | City: ' . ($parts['city'] ?? '') . ' | Province: ' . ($parts['province'] ?? ''), 0, 255);
    }

    private static function clean(?string $s): string
    {
        return Text::jsTrim(Text::collapse(preg_replace('/[^a-z0-9 ]+/u', ' ', Text::lower($s)) ?? ''));
    }
}

<?php

namespace App\Support;

/**
 * "2 x GLOW TAPE" → qty 2, base "GLOW TAPE", key "glow tape".
 * Ang key ay kapareho ng ItemSupplierQuote::keyFor() — iisa ang normalization ng /item.
 */
class ItemBaseKey
{
    /** @return array{qty:int, base:string, key:string} */
    public static function parse(string $name): array
    {
        $qty  = 1;
        $base = trim($name);

        if (preg_match('/^\s*(\d+)\s*[x×]\s*(.+)$/iu', $name, $m)) {
            $qty  = max(1, (int) $m[1]);
            $base = trim($m[2]);
        }

        $key = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $base) ?? $base), 'UTF-8');

        return ['qty' => $qty, 'base' => $base, 'key' => $key];
    }

    public static function key(string $name): string
    {
        return self::parse($name)['key'];
    }
}

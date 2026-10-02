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

        return ['qty' => $qty, 'base' => $base, 'key' => self::key($name)];
    }

    /** Eksaktong algorithm ng ItemSupplierQuote::keyFor() — kasama ang mga edge name ("2 x", "5x"). */
    public static function key(string $name): string
    {
        $n = preg_replace('/^\s*\d+\s*[x×]\s*/iu', '', trim($name)) ?? trim($name);

        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $n) ?? $n), 'UTF-8');
    }
}

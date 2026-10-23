<?php

namespace App\Services;

/**
 * Maliit na PHP na bersyon ng _evalRules ng /owner/private: para masundan ng feed ang parehong saved Conditional
 * Formatting rules ng owner. First hit wins, isang kondisyon bawat rule (compare_col o ang sariling column).
 *
 * Limitasyon: numeric na threshold at ref lang (sa column ng parehong row); ang formula na threshold ay hindi kaya
 * dito at nilalaktawan ang rule; walang active_state (walang on/off ang row ng feed).
 */
class ColFormatRules
{
    /** @return array|null ang unang rule na tumama, o null */
    public static function firstHit(array $rules, string $ownCol, array $row): ?array
    {
        foreach ($rules as $r) {
            $col = (string) ($r['compare_col'] ?? '');
            $raw = ($col !== '' && array_key_exists($col, $row)) ? $row[$col] : ($row[$ownCol] ?? null);
            $op = (string) ($r['op'] ?? '');

            if ($op === 'is_null' || $op === 'is_not_null') {
                $empty = $raw === null || $raw === '';
                if ($empty === ($op === 'is_null')) return $r;
                continue;
            }

            $t = self::threshold($r['value'] ?? null, $row);
            if ($t === null || $raw === null || !is_numeric($raw)) continue;
            $v = (float) $raw;
            $hit = match ($op) {
                '>' => $v > $t, '>=' => $v >= $t, '=' => $v == $t, '<=' => $v <= $t, '<' => $v < $t,
                default => false,
            };
            if ($hit) return $r;
        }

        return null;
    }

    /** red / yellow / none mula sa bg ng rule na tumama: pula ang hue na malapit sa 0°, ang iba (amber, dilaw…) ay yellow. */
    public static function level(?string $bg): string
    {
        if ($bg === null || !preg_match('/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i', $bg, $m)) return 'none';
        [$r, $g, $b] = [hexdec($m[1]) / 255, hexdec($m[2]) / 255, hexdec($m[3]) / 255];
        $max = max($r, $g, $b);
        $d = $max - min($r, $g, $b);
        if ($d == 0.0) return 'yellow';   // abo/puti: may tumama pero hindi pula
        $h = match ($max) {
            $r => fmod(($g - $b) / $d, 6),
            $g => ($b - $r) / $d + 2,
            default => ($r - $g) / $d + 4,
        } * 60;
        $h = fmod($h + 360, 360);

        return ($h < 20 || $h >= 340) ? 'red' : 'yellow';
    }

    private static function threshold(mixed $v, array $row): ?float
    {
        if (is_int($v) || is_float($v)) return (float) $v;
        if (is_array($v) && ($v['type'] ?? null) === 'ref') {
            $x = $row[(string) ($v['col'] ?? '')] ?? null;

            return is_numeric($x) ? (float) $x : null;
        }

        return null;
    }
}

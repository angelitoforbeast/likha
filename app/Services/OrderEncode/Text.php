<?php

namespace App\Services\OrderEncode;

/**
 * Ang maliliit na function ng dalawang Apps Script (normalisers, fuzzy match, list checks), isa-isang kinopya.
 * Ang regex ay walang /u kung ang script ay ASCII ang \b at ang [A-Za-z]; ang JS .trim() ay may sariling jsTrim.
 */
final class Text
{
    /** JS String.trim(): kasama ang NBSP at BOM. */
    public static function jsTrim(?string $s): string
    {
        $s = (string) $s;

        return preg_replace('/^[\s\x{FEFF}]+|[\s\x{FEFF}]+$/u', '', $s) ?? trim($s);
    }

    public static function lower(?string $s): string
    {
        return mb_strtolower((string) $s, 'UTF-8');
    }

    /** JS str.replace("text", "") — ang una lang. */
    public static function replaceFirst(string $subject, string $search, string $replace): string
    {
        if ($search === '') return $replace . $subject;
        $at = strpos($subject, $search);

        return $at === false ? $subject : substr_replace($subject, $replace, $at, strlen($search));
    }

    /** Ang lahat ng \bword\b (JS RegExp na galing sa value; dito may escape). */
    public static function removeWord(string $subject, string $word): string
    {
        return preg_replace('/\b' . preg_quote($word, '/') . '\b/', '', $subject) ?? $subject;
    }

    public static function collapse(string $s): string
    {
        return preg_replace('/\s+/', ' ', $s) ?? $s;
    }

    public static function normPlace(?string $v): string
    {
        $s = self::lower($v);
        $s = str_replace('ñ', 'n', $s);
        $s = preg_replace('/[.,]/', ' ', $s);
        $s = preg_replace('/[-\/]/', ' ', $s);
        $s = preg_replace('/\bcity of\b/', ' ', $s);
        $s = preg_replace('/\bprovince of\b/', ' ', $s);

        return self::jsTrim(self::collapse($s));
    }

    /** Metro Manila / NCR / Metro-Manila -> "manila". */
    public static function normProv(?string $v): string
    {
        $s = self::lower($v);
        $s = str_replace('ñ', 'n', $s);
        $s = preg_replace('/[.,]/', ' ', $s);
        $s = preg_replace('/[-\/]/', ' ', $s);
        $s = self::jsTrim(self::collapse($s));
        if ($s === '') return '';
        if ($s === 'ncr' || str_contains($s, 'metro manila') || str_contains($s, 'metro-manila') || str_contains($s, 'national capital region')) return 'manila';

        return $s;
    }

    public static function cleanBrgyForMatch(?string $v): string
    {
        return self::jsTrim(self::collapse(preg_replace('/\b(brgy\.?|barangay)\b/i', '', (string) $v)));
    }

    /** Ang barangay sa LocationData: "BRGY" ay tinatanggal, "BARANGAY x" ay nananatili. */
    public static function normalizeBrgyLabel(?string $v): string
    {
        $s = self::jsTrim($v);
        if ($s === '') return '';
        $s = self::jsTrim(preg_replace('/\bbrgy\.?\b/i', '', $s));
        $s = self::jsTrim(self::collapse($s));
        if (preg_match('/\bbarangay\b/i', $s)) {
            $after = self::jsTrim(self::collapse(preg_replace('/\bbarangay\b/i', '', $s)));

            return $after !== '' ? 'BARANGAY ' . $after : 'BARANGAY';
        }

        return $s;
    }

    /** Ang barangay na walang "barangay"/"brgy", normalised (brgyInList_, inferCityFromBarangay_, buildLocationMaps_). */
    public static function brgyKey(?string $v): string
    {
        $s = preg_replace('/\bbarangay\b/i', ' ', (string) $v);
        $s = preg_replace('/\bbrgy\.?\b/i', ' ', $s);

        return self::normPlace(self::jsTrim(self::collapse($s)));
    }

    public static function normalizeForFuzzy(?string $s): string
    {
        $s = self::lower($s);
        $s = preg_replace('/[0o]/', 'o', $s);
        $s = preg_replace('/[1il|]/', 'l', $s);

        return preg_replace('/[^a-z0-9]/', '', $s);
    }

    public static function isFuzzyMatch(?string $word, ?string $message, float $threshold = 0.7): bool
    {
        $w = self::normalizeForFuzzy($word);
        $m = self::normalizeForFuzzy($message);
        $len = strlen($w);

        if ($len < 3) return false;
        if (str_contains($m, $w)) return true;

        for ($i = 0; $i <= strlen($m) - $len; $i++) {
            $similarity = 1 - levenshtein($w, substr($m, $i, $len)) / $len;
            if ($similarity >= $threshold) return true;
        }

        return false;
    }

    public static function normalizeCityForCheck(?string $cityRaw, ?string $provinceRaw): string
    {
        $c = (string) $cityRaw;
        if (self::jsTrim($c) === '') return '';

        $cNorm = self::normPlace($c);
        $cNorm = self::jsTrim(self::collapse(preg_replace('/\bcity\b/', ' ', $cNorm)));

        $pNorm = self::normProv($provinceRaw);
        if ($pNorm === '') return $cNorm;

        if ($pNorm === 'manila') {
            $cNorm = preg_replace('/\bmetro\b/', ' ', $cNorm);
            $cNorm = preg_replace('/\bncr\b/', ' ', $cNorm);
            $cNorm = self::jsTrim(self::collapse($cNorm));
        }

        $re = '/\b' . preg_quote($pNorm, '/') . '\b/';
        $count = preg_match_all($re, $cNorm);
        if (!$count) return $cNorm;

        if ($count === 1) {
            $out = self::jsTrim(self::collapse(preg_replace($re, ' ', $cNorm)));

            return $out !== '' ? $out : $cNorm;
        }
        $out2 = self::jsTrim(self::collapse(preg_replace($re, ' ', $cNorm, 1)));

        return $out2 !== '' ? $out2 : $cNorm;
    }

    /** @return array<int, string> ang CSV na hinati sa kuwit, ayon sa $map. */
    private static function csv(?string $csv, callable $map): array
    {
        return array_values(array_filter(array_map($map, explode(',', (string) $csv)), fn ($s) => $s !== ''));
    }

    public static function cityInList(?string $city, ?string $csv): bool
    {
        return in_array(self::normPlace($city), self::csv($csv, fn ($s) => self::normPlace($s)), true);
    }

    public static function provInList(?string $prov, ?string $csv): bool
    {
        return in_array(self::normProv($prov), self::csv($csv, fn ($s) => self::normProv($s)), true);
    }

    public static function canonicalizeProvince(?string $cand, ?string $csv): string
    {
        $target = self::normProv($cand);
        foreach (self::csv($csv, fn ($s) => self::jsTrim($s)) as $p) {
            if (self::normProv($p) === $target) return $p;
        }

        return self::jsTrim($cand);
    }

    public static function brgyInList(?string $brgy, ?string $csv): bool
    {
        $target = self::brgyKey($brgy);
        if ($target === '') return false;

        return in_array($target, self::csv($csv, fn ($s) => self::brgyKey($s)), true);
    }

    /** Ang sort ng JS localeCompare("en", base): walang pakialam sa laki ng letra at tuldok sa ibabaw ng letra. */
    public static function compareLocale(string $a, string $b): int
    {
        return strcmp(self::fold($a), self::fold($b));
    }

    private static function fold(string $s): string
    {
        $s = self::lower($s);

        return strtr($s, ['ñ' => 'n', 'á' => 'a', 'à' => 'a', 'é' => 'e', 'è' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);
    }

    /** Ang value ng cell gaya ng iniintindi ng Google Sheets kapag isinulat bilang text (setValue): ang numero ay nagiging numero. */
    public static function sheetValue(?string $v): string
    {
        $v = (string) $v;
        if (preg_match('/^\d{1,3}(,\d{3})+(\.\d+)?$|^\d+(\.\d+)?$/', $v)) {
            $n = (float) str_replace(',', '', $v);
            $s = rtrim(rtrim(number_format($n, 10, '.', ''), '0'), '.');

            return $s === '' ? '0' : $s;
        }

        return $v;
    }
}

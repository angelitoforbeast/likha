<?php

namespace App\Services;

/**
 * Ang iisang panuntunan ng landmark sa address ng order (Astra at ang encode order): ang landmark ng customer, walang
 * "near / malapit sa / tapat ng…", ay idinudugtong bilang "near <landmark>" kapag wala pa ito sa address. Ang placeholder
 * (N/A, none, wala pong nabanggit, [Not provided], "-"…) ay walang landmark. Ang address ay hanggang MAX character lang.
 */
final class AddressLandmark
{
    /** Ang cap ng Astra; ang J&T payload ay walang sariling cap at TEXT ang ADDRESS column, kaya ito ang mas maliit. */
    public const MAX = 250;

    private const PREFIX = '/^(near|malapit sa|malapit|sa may|sa tabi ng|tabi mismo ng|tabi mismo|tabi ng|tapat ng|tapat|harap ng|likod ng|beside|in front of|katabi ng|next to)\s+/iu';

    private const PLACEHOLDERS = [
        // Puro bantas: "-", "--", ".", "/"
        '/^[\s\-\.,_\/]*$/u',
        // Nasa loob ng [ ] o ( ): tala ng bot o ng staff, hindi landmark ("[Not provided]", "(Wala pong na-mention)")
        '/^[\[\(].*[\]\)]$/su',
        '/^(n\s?[\.\/]?\s?a|none|nothing|null|nil|no|unknown|no landmark|not (provided|mentioned|specified|given|applicable|available|stated|indicated))(\s+po)?$/u',
        '/^(wala|walang|wla)(\s+(po|pong|pa|pang|naman|talaga))*(\s+(landmark|nabanggit|binanggit|na-?banggit|na-?mention|mention|nakalagay|nilagay|inilagay|binigay|ibinigay|naibigay|nabigay|sinabi|nasabi|info|impormasyon))?(\s+po)?$/u',
        '/^(hindi|di)(\s+(pa|po|naman))*\s+(na-?banggit|nabanggit|binanggit|naibigay|ibinigay|binigay|nabigay|sinabi|nasabi|na-?mention|nakalagay|nilagay|inilagay)\b/u',
    ];

    /** Ang landmark na walang "near / malapit sa…", o '' kapag wala o placeholder. */
    public static function clean(?string $landmark): string
    {
        $lm = trim((string) $landmark);
        $lm = trim(preg_replace(self::PREFIX, '', $lm) ?? $lm);
        $check = rtrim(self::norm($lm), '.! ');
        foreach (self::PLACEHOLDERS as $re) {
            if (preg_match($re, $check)) return '';
        }

        return $lm;
    }

    /**
     * Ang address mula sa mga bahagi (Astra: house number, purok/sitio, address) at ang landmark: ang bahaging nasa loob na
     * ng iba ay tinatanggal, saka "near <landmark>" kapag wala pa ang landmark sa alinmang bahagi.
     * @param array<int, mixed> $parts
     */
    public static function compose(array $parts, ?string $landmark, int $max = self::MAX): string
    {
        return self::join($parts, self::clean($landmark), $max);
    }

    /**
     * Ang encode order: ang address line ng AI at ang landmark ng form. result: added (idinugtong), kept (nasa address na;
     * hindi ginalaw), none (walang landmark; hindi ginalaw).
     * @return array{address: string, result: string}
     */
    public static function apply(string $address, ?string $landmark, int $max = self::MAX): array
    {
        $lm = self::clean($landmark);
        if ($lm === '') return ['address' => $address, 'result' => 'none'];
        if (str_contains(self::norm($address), self::norm($lm))) return ['address' => $address, 'result' => 'kept'];

        // Walang ",," kapag nagtatapos sa kuwit ang address line ("Pk. 17, Zone 7,").
        return ['address' => self::join([rtrim($address, " ,")], $lm, $max), 'result' => 'added'];
    }

    /** @param array<int, mixed> $parts */
    private static function join(array $parts, string $lm, int $max): string
    {
        $raw = [];
        foreach ($parts as $v) {
            $v = trim((string) $v);
            if ($v !== '' && $v !== '-') $raw[] = $v;
        }
        // Tanggalin ang bahaging NASA LOOB na ng ibang bahagi ("8543" at "OB Junction" ay nasa "8543 OB Junction")
        $kept = [];
        foreach ($raw as $i => $p) {
            $np = self::norm($p); $drop = false;
            foreach ($raw as $j => $q) {
                if ($i === $j) continue;
                $nq = self::norm($q);
                if ($nq === $np) { if ($j < $i) { $drop = true; break; } continue; }   // eksaktong kapareho → una lang ang iiwan
                if (str_contains($nq, $np)) { $drop = true; break; }                    // nasa loob ng mas mahaba
            }
            if (!$drop) $kept[] = $p;
        }
        if ($lm !== '') {
            $nl = self::norm($lm); $inside = false;
            foreach ($kept as $p) if (str_contains(self::norm($p), $nl)) { $inside = true; break; }
            if (!$inside) {
                // Bahaging nasa loob na ng landmark → alisin (iwas "Naic Public Market, near Jekep's, Naic Public Market")
                $kept = array_values(array_filter($kept, fn ($p) => !str_contains($nl, self::norm($p))));
                $kept[] = 'near ' . $lm;
            }
        }
        $addr = implode(', ', $kept);
        $addr = preg_replace('/\s+/u', ' ', $addr) ?? $addr;

        return self::cap(trim($addr, ' ,'), $max);
    }

    /** Hanggang $max character; kapag tatama sa gitna ng salita, sa huling espasyo o kuwit (kung hindi masyadong maikli). */
    private static function cap(string $s, int $max): string
    {
        if (mb_strlen($s) <= $max) return $s;
        $cut = mb_substr($s, 0, $max);
        if (!preg_match('/^[\s,]$/u', mb_substr($s, $max, 1)) && preg_match('/^(.*)[\s,]/su', $cut, $m) && mb_strlen($m[1]) >= intdiv($max, 2)) {
            $cut = $m[1];
        }

        return rtrim($cut, ' ,');
    }

    private static function norm(string $x): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $x) ?? $x));
    }
}

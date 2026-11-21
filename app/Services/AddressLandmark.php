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
        '/^(n\s?[\.\/]?\s?a|none|nothing|null|nil|no|unknown|no landmark|(none|not|nothing|no landmark)(\s+(explicitly|yet|specifically))?\s+(provided|mentioned|specified|given|applicable|available|stated|indicated))(\s+po)?$/u',
        '/^(wala|walang|wla)(\s+(po|pong|pa|pang|naman|talaga))*(\s+(landmark|nabanggit|binanggit|na-?banggit|na-?mention|mention|mentioned|nakalagay|nilagay|inilagay|binigay|ibinigay|naibigay|nabigay|sinabi|nasabi|info|impormasyon|provided|specified|given|stated))?(\s+po)?$/u',
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
     * Ang mga placeholder ng address (spec 311), dagdag sa PLACEHOLDERS ng landmark: ang tala ng AI o ng bot na walang
     * address ("no address line 1 or landmark provided.", "no address line found", "address not provided", "missing").
     */
    private const ADDRESS_PLACEHOLDERS = [
        '/^(no|none|without|walang?|wla)(\s+(po|pong))?\s+(complete\s+|exact\s+|specific\s+|full\s+)?(address|addr|street|house\s*(number|no)?|purok|sitio)(\s+(line|details|info|information))?(\s*(1|one))?((\s+(or|and|&)\s+|\s*\/\s*)(landmark|street|purok|sitio|house\s*number|address))*(\s+(was|is|po|pong))?(\s+(explicitly|yet|specifically))?(\s+(provided|found|mentioned|specified|given|available|stated|indicated|detected|extracted|nabanggit|na-?mention|binigay|ibinigay|nakalagay))?(\s+po)?$/u',
        '/^(address|addr|address\s+line(\s*1)?|street|purok|purok\s*\/\s*sitio)\s*:?\s*((is|was)\s+)?(not\s+)?(provided|found|mentioned|specified|given|available|stated|indicated|missing|unknown|none|n\s?\/?\s?a)$/u',
        '/^(missing|not\s+found|not\s+available|not\s+applicable|to\s+follow|tbd|tba)(\s+po)?$/u',
    ];

    /** Ang salitang nagsasabing walang laman ang nasa loob ng ( ) o [ ] ("[customer did not give one]", "(Wala pong namention)"). */
    private const EMPTY_WORD = '/\b(not|no(?![\.\s]*\d)|none|wala|walang|wla|hindi|missing|unknown|n\/a|didn\'t|wasn\'t|isn\'t)\b/u';

    /**
     * Ang iisang panuntunan ng placeholder sa ADDRESS ng encode: blangko, ang mga placeholder ng landmark at ang
     * ADDRESS_PLACEHOLDERS. Ang buong value sa ( ) o [ ] ay placeholder lang kapag ang laman nito ay placeholder o nagsasabing
     * wala ("(Purok 7)" at "(P7) Pido St." ay tunay na address).
     */
    public static function isPlaceholderAddress(?string $address): bool
    {
        $check = rtrim(self::norm((string) $address), '.! ');
        if (preg_match('/^[\[\(](.*)[\]\)]$/su', $check, $m)) {
            $inner = rtrim(trim($m[1]), '.! ');

            return preg_match(self::EMPTY_WORD, $inner) === 1 || self::isPlaceholderAddress($inner);
        }
        foreach (self::PLACEHOLDERS as $re) {
            if (preg_match($re, $check)) return true;
        }
        foreach (self::ADDRESS_PLACEHOLDERS as $re) {
            if (preg_match($re, $check)) return true;
        }

        return false;
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
     * hindi ginalaw), none (walang landmark; hindi ginalaw), no_room (ang address line ay halos nasa limit na: hindi pinuputol).
     * @return array{address: string, result: string}
     */
    public static function apply(string $address, ?string $landmark, int $max = self::MAX): array
    {
        $lm = self::clean($landmark);
        if ($lm === '') return ['address' => $address, 'result' => 'none'];
        if (str_contains(self::norm($address), self::norm($lm))) return ['address' => $address, 'result' => 'kept'];
        // Walang ",," kapag nagtatapos sa kuwit ang address line ("Pk. 17, Zone 7,").
        $line = rtrim($address, ' ,');
        $out = mb_strlen($line) + mb_strlen(', near ') < $max ? self::join([$line], $lm, $max) : '';
        if ($out === '' || $out === $line) return ['address' => $address, 'result' => 'no_room'];

        return ['address' => $out, 'result' => 'added'];
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

        // Walang "near" na nakabitin kapag walang salita ng landmark na kasya.
        return rtrim(preg_replace('/,?\s*near$/iu', '', rtrim($cut, ' ,')) ?? $cut, ' ,');
    }

    private static function norm(string $x): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $x) ?? $x));
    }
}

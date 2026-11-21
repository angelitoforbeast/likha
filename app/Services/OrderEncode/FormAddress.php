<?php

namespace App\Services\OrderEncode;

use App\Services\AddressLandmark;

/**
 * Ang address ng order form sa extracted details (spec 311). Tatlong anyo:
 * - parts: may linyang "House Number:" o "Purok/Sitio:" (gaya ng block ni Astra). Ang ADDRESS ay binubuo dito sa code mula
 *   sa House Number, Purok/Sitio at Address, gaya ng Astra (AddressLandmark::compose), dahil tinatanggal ng AI ang purok.
 * - one_line: "Address:" lang (ang lumang form): ang AI pa rin ang kumukuha ng address line, gaya ng dati.
 * - none: walang address sa form: ang AI, gaya ng dati.
 * Ang placeholder (N/A, "-", "(Wala pong na-mention)"…) ay walang laman. Ang barangay, city at province ng form ay
 * tinatanggal sa dulo ng bawat bahagi, dahil hiwalay na column ang mga iyon.
 */
final class FormAddress
{
    private const LABELS = [
        'house_number' => '(house\s*(number|num|no\.?|#)|house\s*\/\s*lot(\s*(number|no\.?))?)',
        'purok_sitio' => '(purok\s*\/\s*sitio|purok\s+or\s+sitio|purok|sitio)',
        'address' => '((complete|full)\s+)?address(\s+line\s*1)?',
        'brgy' => '(brgy\.?|barangay)',
        'city' => '(city\s*\/\s*municipality|city|municipality)',
        'province' => 'province',
    ];

    /** Mga salitang hindi pangalan ng purok o sitio ("Purok No. 5", "Sitio St."). */
    private const NOT_A_NAME = '(?!(?:no|num|number|st|street|blk|block|lot|zone|sitio|purok|prk|pk)\b)';

    /**
     * Ang huling linya ng bawat label (gaya ng Parse), hilaw, at ang anyo ng form.
     * @return array{shape: string, house_number: string, purok_sitio: string, address: string, brgy: string, city: string, province: string}
     */
    public static function read(string $cxd): array
    {
        $form = array_fill_keys(array_keys(self::LABELS), '');
        $seen = [];
        foreach (preg_split('/\r\n|\n|\r/', $cxd) as $line) {
            $line = Text::jsTrim($line);
            foreach (self::LABELS as $key => $re) {
                if (preg_match('/^-?\s*' . $re . '\s*:(.*)$/iu', $line, $m)) {
                    $form[$key] = Text::jsTrim(end($m));
                    $seen[$key] = true;
                    break;
                }
            }
        }
        $shape = isset($seen['house_number']) || isset($seen['purok_sitio']) ? 'parts' : (isset($seen['address']) ? 'one_line' : 'none');

        return ['shape' => $shape] + $form;
    }

    /**
     * Ang mga tunay na bahagi ng address (walang placeholder, walang barangay/city/province sa dulo, walang ulit na purok).
     * @return array<int, string>
     */
    public static function parts(array $form): array
    {
        $places = self::places($form);
        $parts = [];
        foreach (['house_number', 'purok_sitio', 'address'] as $key) {
            $v = self::value((string) ($form[$key] ?? ''), $places);
            if ($v !== '') $parts[$key] = $v;
        }
        // Ang purok na nasa ibang bahagi na ("Prk. 5, Rizal St" at "Purok 5") ay isang beses lang.
        if (isset($parts['purok_sitio'])) {
            $others = implode(', ', array_diff_key($parts, ['purok_sitio' => 1]));
            if ($others !== '' && self::hasPurok($others, $parts['purok_sitio'])) unset($parts['purok_sitio']);
        }

        return array_values($parts);
    }

    /** Ang ADDRESS mula sa mga bahagi ng form at ang landmark (ang paraan ni Astra); '' kapag walang tunay na bahagi. */
    public static function compose(array $form, ?string $landmark = null): string
    {
        $parts = self::parts($form);

        return $parts === [] ? '' : AddressLandmark::compose($parts, $landmark);
    }

    /** Ang tunay na Purok/Sitio ng form, o '' kapag wala o placeholder. */
    public static function purok(array $form): string
    {
        return self::value((string) ($form['purok_sitio'] ?? ''), self::places($form));
    }

    /**
     * Ang address line ng AI na may purok ng form: idinudugtong sa unahan kapag wala pa ito sa line.
     * @return array{address: string, result: string} result: added, kept (nasa line na), none (walang purok o walang line)
     */
    public static function keepPurok(string $line, string $purok): array
    {
        $line = Text::jsTrim($line);
        if ($purok === '' || $line === '') return ['address' => $line, 'result' => 'none'];
        if (self::hasPurok($line, $purok)) return ['address' => $line, 'result' => 'kept'];

        return ['address' => AddressLandmark::compose([$purok, rtrim($line, ' ,')], null), 'result' => 'added'];
    }

    /**
     * Nasa $text na ba ang purok/sitio ng $value. Ang bawat purok at sitio ng value ay hinahanap sa text sa anumang anyo:
     * Purok/Prk/Pk/P + numero ("Purok 5" = "Prk. 5" = "Pk 5" = "P-5" = "P5" = "Purok No. 5" = "Purok 05" = "Purok V"), at ang
     * pangalan bilang buong salita ("Purok Sampaguita" = "Prk. Sampaguita", "Sitio Malipayon" = "So. Malipayon").
     * Kapag walang purok o sitio sa value, ang buong value (lowercase, walang bantas) ang hinahanap bilang buong salita.
     */
    public static function hasPurok(string $text, string $value): bool
    {
        $t = self::flat($text);
        $v = self::flat($value);
        if ($v === '') return true;
        $have = self::purokIds($t);
        $found = false;
        foreach (self::purokIds($v) as $id) {
            $found = true;
            if (!in_array($id, $have, true)) return false;
        }
        if (preg_match_all('/\b(?:sitio|so)\s+(' . self::NOT_A_NAME . '[a-z0-9]+(?:\s+' . self::NOT_A_NAME . '[a-z0-9]+)?)/u', $v, $m)) {
            foreach ($m[1] as $name) {
                $found = true;
                if (!preg_match('/\b' . preg_quote($name, '/') . '\b/u', $t)) return false;
            }
        }

        return $found || preg_match('/\b' . preg_quote($v, '/') . '\b/u', $t) === 1;
    }

    /**
     * Ang mga purok sa text (flat): numero ("5", "5a"; ang roman ay ginagawang numero) o pangalan ("sampaguita").
     * Ang "P" lang ay may numero lagi ("P7", "P-7"), para hindi mabasang purok ang ibang salitang nagsisimula sa P.
     * @return array<int, string>
     */
    private static function purokIds(string $flat): array
    {
        $re = '/\b(?:(?:purok|prk|pk)\s*(?:(?:no|num|number)\s*)?([0-9]+[a-z]?\b|[ivx]+\b|' . self::NOT_A_NAME . '[a-z]+)|p\s?([0-9]+[a-z]?)\b)/u';
        preg_match_all($re, $flat, $m, PREG_SET_ORDER);
        $ids = [];
        foreach ($m as $hit) {
            $id = ($hit[1] ?? '') !== '' ? $hit[1] : ($hit[2] ?? '');
            if ($id !== '') $ids[] = self::num($id);
        }

        return $ids;
    }

    /** Ang numero ng purok: "05" = "5", "v" = "5"; ang pangalan ay gaya ng dati. */
    private static function num(string $id): string
    {
        $roman = ['i' => 1, 'ii' => 2, 'iii' => 3, 'iv' => 4, 'v' => 5, 'vi' => 6, 'vii' => 7, 'viii' => 8, 'ix' => 9, 'x' => 10,
            'xi' => 11, 'xii' => 12, 'xiii' => 13, 'xiv' => 14, 'xv' => 15, 'xvi' => 16, 'xvii' => 17, 'xviii' => 18, 'xix' => 19, 'xx' => 20];
        if (isset($roman[$id])) return (string) $roman[$id];
        if (preg_match('/^0+(?=\d)/', $id)) return preg_replace('/^0+(?=\d)/', '', $id);

        return $id;
    }

    /** lowercase, ñ = n, ang bantas ay espasyo, isang espasyo lang. */
    private static function flat(string $s): string
    {
        $s = str_replace('ñ', 'n', Text::lower($s));
        $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s) ?? $s;

        return Text::jsTrim($s);
    }

    /** Ang bahagi ng form na walang placeholder at walang barangay/city/province sa dulo; '' kapag walang natira. */
    private static function value(string $raw, array $places): string
    {
        $v = Text::jsTrim(Text::collapse($raw));
        if (AddressLandmark::isPlaceholderAddress($v)) return '';
        $v = self::stripPlaces($v, $places);

        return AddressLandmark::isPlaceholderAddress($v) ? '' : $v;
    }

    /**
     * Ang barangay, city at province ng form, flat at walang "Brgy."/"City" na salita, mahaba muna. weak = numero o maikli
     * ("Barangay 5"): tinatanggal lang kapag may "Brgy."/"Barangay" sa unahan, para hindi mawala ang "Purok 5".
     * @return array{strong: array<int, string>, weak: array<int, string>}
     */
    private static function places(array $form): array
    {
        $out = ['strong' => [], 'weak' => []];
        foreach (['brgy', 'city', 'province'] as $key) {
            $v = (string) ($form[$key] ?? '');
            if (AddressLandmark::isPlaceholderAddress($v)) continue;
            $v = self::flat($v);
            $v = Text::jsTrim(preg_replace('/^(brgy|barangay|city of|municipality of|province of)\s+|\s+(city|province)$/u', '', $v) ?? $v);
            if ($v === '') continue;
            $out[preg_match('/^[0-9ivx\s]+$/u', $v) || mb_strlen($v) < 3 ? 'weak' : 'strong'][] = $v;
        }
        foreach ($out as &$list) usort($list, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        return $out;
    }

    /**
     * Tinatanggal sa dulo ng bahagi ang barangay/city/province ng form ("Rizal St, Lahug, Cebu City" → "Rizal St"), pati ang
     * "Brgy."/"City" na kasama nito. Sa dulo lang, at buong salita lang: ang "Rizal St" sa Rizal ay hindi ginagalaw.
     */
    private static function stripPlaces(string $part, array $places): string
    {
        $alts = [];
        // Ang flat na lugar ay n ang baybay; ang bahagi ay maaaring may ñ.
        $q = fn (array $list) => implode('|', array_map(fn ($p) => str_replace([' ', 'n'], ['[\W_]+', '[nñ]'], preg_quote($p, '/')), $list));
        if ($places['strong'] !== []) $alts[] = '(?:(?:brgy|barangay|city of|municipality of|province of)\b[\W_]*)?(?:' . $q($places['strong']) . ')';
        if ($places['weak'] !== []) $alts[] = '(?:brgy|barangay)\b[\W_]*(?:' . $q($places['weak']) . ')';
        if ($alts === []) return $part;
        $re = '/(?:^|[\s,;\-]+)(?:' . implode('|', $alts) . ')(?:[\W_]+(?:city|province))?[\W_]*$/iu';
        do {
            $before = $part;
            $part = Text::jsTrim(rtrim(preg_replace($re, '', $part) ?? $part, ' ,;-'));
        } while ($part !== $before && $part !== '');

        return $part;
    }
}

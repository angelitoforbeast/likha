<?php

namespace App\Services;

/**
 * Text check ng barangay para kay Astra: "sinabi ba talaga ng customer ang barangay na ito?"
 *
 * Pure: walang database, walang HTTP, walang orasan, walang log. Ang text ay galing sa customer
 * (hindi pinagkakatiwalaan), kaya ang bawat alinlangan ay nauuwi sa `none` = tao ang magpapasya;
 * hindi kailanman sa kumpirmasyon ng ibang barangay.
 *
 * Mga sagot: `phrase` (buong pangalan, salita por salita), `compact` (parehong mga letra, iba lang
 * ang hati ng salita: "nabag o" = NABAGO), `near` (halos tugma, similar_text 85 pataas), `none`.
 *
 * Bakit hindi ang text check ng classic checker: tinatawid nito ang magkaibang numero
 * ("poblacion 1" laban sa "poblacion 2" ay 91) at binubura ng normBrgyKey ang kuwit at line break,
 * kaya ang "Brgy Poblacion" at, sa kasunod na linya, "2 pcs" ay nagiging "poblacion 2".
 */
class AstraBarangayMatcher
{
    /** Pinakamababang similar_text para sa halos-tugma (kapareho ng classic checker). */
    private const NEAR_PCT = 85.0;
    /** Pinakamaikling needle para sa halos-tugma at sa compact: sa mas maikli, isang letra lang ay ibang lugar na. */
    private const MIN_LEN = 5;
    /** Pansamantalang marka ng "barangay word" sa loob ng segment; inaalis muna ang lahat ng control character sa text. */
    private const BRGY_MARK = "\x01";
    /** Mga daglat na pinapalawak ng normBrgyKey: ang tuldok nila ay hindi dulo ng pangungusap ("Sta. Cruz", "Pob. 2"). */
    private const DOTTED_ABBR = ['sta', 'sto', 'gen', 'pob'];

    /**
     * @param array $cityLabels LAHAT ng J&T label ng city ng line. Kailangan: dito nakikita kung ang sinabi ng
     *                          customer ay ibang barangay pala ng parehong city. Ang label na wala rito ay hindi kinukumpirma.
     * @return array{result: string, score: int} score: 100 sa phrase/compact, ang similar_text (walang decimal) sa near, 0 sa none.
     */
    public static function confirm(string $text, string $label, array $cityLabels): array
    {
        return self::run($text, $label, null, $cityLabels);
    }

    /**
     * Gaya ng confirm(), pero kapag wala sa text ang label, sinusubukan din ang mismong sulat ng barangay sa form
     * ("Baug" para sa BA-UG) — tanging kapag ang sulat na iyon ay tumuturo sa MISMONG label na ito sa loob ng city.
     * Kaya ang "Poblacion" sa form ay hindi kailanman kumpirmasyon ng POBLACION 2.
     */
    public static function confirmWithWording(string $text, string $label, string $formWording, array $cityLabels): array
    {
        return self::run($text, $label, $formWording, $cityLabels);
    }

    private static function run(string $text, string $label, ?string $wording, array $cityLabels): array
    {
        $none = ['result' => 'none', 'score' => 0];
        try {
            $labels = array_map('strval', $cityLabels);
            // Ang label na hindi barangay ng city na ito ay walang makukumpirma (at walang kapatid na maikukumpara).
            if (!in_array($label, $labels, true)) return $none;

            $own = self::labelKeys($label);
            if ($own[0] === '') return $none;
            $siblings = [];
            foreach ($labels as $other) {
                if ($other === $label) continue;
                foreach (self::labelKeys($other) as $k) {
                    if ($k !== '' && !in_array($k, $own, true)) $siblings[$k] = true;
                }
            }
            $siblings = array_map('strval', array_keys($siblings));
            $segments = self::segments($text);

            $first = self::scan($segments, $own[0], $own[1], $siblings);
            if ($first['result'] !== 'none' || $first['rejected'] || $wording === null) {
                return ['result' => $first['result'], 'score' => $first['score']];
            }

            $wording = self::scrub($wording);
            if ((new MacroChecker())->matchBarangayInList($wording, $labels) !== $label) return $none;
            $needle = self::noParKey($wording);
            // Ang sulat na pangalan din ng IBANG barangay ng city ay hindi patunay para sa label na ito.
            if ($needle === '' || in_array($needle, $siblings, true)) return $none;
            // Ang sulat sa form ay dumadaan sa parehong mga rule; ang mga kapatid ay ang sa city pa rin ng LABEL.
            $second = self::scan($segments, $needle, $own[1], $siblings);

            return ['result' => $second['result'], 'score' => $second['score']];
        } catch (\Throwable $e) {
            // Anumang hindi inaasahan sa text ng customer: hindi kumpirmado, tao ang magpapasya.
            return $none;
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Ang paghahanda ng text
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Ang byte na hindi wastong UTF-8 ay ginagawang line break (hangganan), hindi inaalis at hindi ginagawang "?":
     * ang pag-alis ay magdidikit ng dalawang salita, at ang kapalit na character ay puwedeng tumayo sa pagitan ng
     * "Brgy" at ng numero. Byte por byte ang regex (walang /u) dahil sira ang text.
     */
    private static function scrub(string $s): string
    {
        if (mb_check_encoding($s, 'UTF-8')) return $s;
        $valid = '[\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]'
            . '|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2}';

        return preg_replace('/(?:' . $valid . ')(*SKIP)(*FAIL)|./s', "\n", $s) ?? '';
    }

    private static function noParKey(string $s): string
    {
        return MacroChecker::normBrgyKey(preg_replace('/\([^)]*\)/u', ' ', $s) ?? $s);
    }

    /**
     * Mga key ng isang label: [0] walang parenthesis (ang needle), [1] kasama ang laman ng parenthesis,
     * [2…] ang laman lang ng bawat parenthesis — "IBONG SAPA (SAN VICENTE SUR)" ay kilala rin bilang "san vicente sur".
     */
    private static function labelKeys(string $label): array
    {
        $keys = [self::noParKey($label), MacroChecker::normBrgyKey($label)];
        if (preg_match_all('/\(([^)]*)\)/u', $label, $m)) {
            foreach ($m[1] as $inside) $keys[] = MacroChecker::normBrgyKey($inside);
        }

        return $keys;
    }

    /**
     * Hinahati ang text sa mga segment BAGO i-normalize, dahil binubura ng normBrgyKey ang mga hangganan.
     * Hard break: kuwit, semicolon, line break, slash, pambukas na bracket, at gitling na may espasyo sa
     * magkabilang gilid. Ang gitling na walang espasyo ("I-B") ay nananatili sa loob ng salita.
     * Walang hit, walang "barangay word bago ang numero" at walang tingin sa kasunod na salita na tumatawid ng segment.
     *
     * Ang tuldok ng dulo ng pangungusap, ang pansarang bracket, `!` at `?` ay "malambot" na hangganan: walang hit na
     * tumatawid ("Poblacion. 2 pcs" ay hindi POBLACION 2), pero tinitingnan pa rin ang kasunod na salita
     * ("Poblacion. 9" ay hindi kumpirmasyon ng POBLACION). Sa gayon, pagtanggi lang ang naidaragdag nito, hindi kumpirmasyon.
     * Ang tuldok ng daglat na kilala ng normBrgyKey (sta, sto, gen, pob), ng barangay word at ng initial ay bahagi ng pangalan.
     *
     * Bawat segment: `w` (ang salita para sa pagtutugma), `alt` (ang salita para sa paghahambing sa ibang label),
     * `brgy` (index ng salitang may barangay word na kasunod-agad sa unahan), `init` (index ng mga initial),
     * `soft` (index ng salitang may malambot na hangganan sa unahan).
     */
    private static function segments(string $text): array
    {
        $t = self::scrub($text);
        // Ang control character (kasama ang sarili naming marka) ay hangganan, hindi espasyo: walang natatawid.
        $t = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{2028}\x{2029}]/u', "\n", $t) ?? '';
        $t = preg_replace('/[\p{Zs}\t#:]/u', ' ', $t) ?? '';
        $t = str_replace(["\u{2013}", "\u{2014}"], '-', $t);
        $t = str_replace([')', ']', '}', '!', '?'], ' . ', $t);
        $parts = preg_split('/[,;\r\n\/(\[{]|(?<=\s)-+(?=\s)/u', $t) ?: [];

        $keyOf = []; // normBrgyKey kada piraso, isang beses lang: paulit-ulit ang mga salita ng mahabang chat
        $out   = [];
        foreach ($parts as $part) {
            if (trim($part) === '') continue;
            // Ang barangay word (may tuldok o wala) ay tinatandaan sa kasunod na salita, saka inaalis.
            $part = preg_replace('/\b(?:barangay|brgy|bgy|bgry)\b\.?/iu', ' ' . self::BRGY_MARK . ' ', $part) ?? $part;
            $seg  = ['w' => [], 'alt' => [], 'brgy' => [], 'init' => [], 'soft' => []];
            $afterBrgy = false;
            $afterStop = false;
            foreach (preg_split('/\s+/u', $part, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                if ($token === self::BRGY_MARK) { $afterBrgy = true; continue; }
                // Isang letrang may tuldok agad ("V. Luna", "Q.C.") = initial ng pangalan: hindi Roman numeral, hindi suffix letter.
                $pieces = preg_split('/(?<![\p{L}\p{N}])(\p{L}\.)/u', $token, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [$token];
                foreach ($pieces as $piece) {
                    if (preg_match('/^\p{L}\.$/u', $piece) === 1) {
                        $letter = mb_strtolower(mb_substr($piece, 0, 1, 'UTF-8'), 'UTF-8');
                        $alt    = $keyOf[$letter] ??= MacroChecker::normBrgyKey($letter);
                        $i      = count($seg['w']);
                        // May tuldok ang `w`: hindi ito kailanman katumbas ng numero o letra ng isang label.
                        // Ang `alt` ay ang letra mismo (o ang numero nito): "Zone 1 B." ay ZONE I-B pa rin sa mata ng paghahambing sa ibang label.
                        $seg['w'][]   = $letter . '.';
                        $seg['alt'][] = $alt !== '' ? $alt : $letter;
                        $seg['init'][$i] = true;
                        if ($afterBrgy) { $seg['brgy'][$i] = true; $afterBrgy = false; }
                        if ($afterStop) { $seg['soft'][$i] = true; $afterStop = false; }
                        continue;
                    }
                    $stems = explode('.', $piece);
                    $last  = count($stems) - 1;
                    foreach ($stems as $k => $stem) {
                        $key = $stem === '' ? '' : ($keyOf[$stem] ??= MacroChecker::normBrgyKey($stem));
                        foreach ($key === '' ? [] : explode(' ', $key) as $word) {
                            $i = count($seg['w']);
                            if ($afterBrgy) { $seg['brgy'][$i] = true; $afterBrgy = false; }
                            if ($afterStop) { $seg['soft'][$i] = true; $afterStop = false; }
                            $seg['w'][]   = $word;
                            $seg['alt'][] = $word;
                        }
                        // May tuldok pagkatapos ng pirasong ito: hangganan, maliban kung daglat na bahagi ng pangalan.
                        // Ang barangay word bago ang hangganan ay hindi na "kasunod-agad" ng susunod na salita.
                        if ($k < $last && !in_array(mb_strtolower($stem, 'UTF-8'), self::DOTTED_ABBR, true)) { $afterStop = true; $afterBrgy = false; }
                    }
                }
            }
            if ($seg['w'] !== []) $out[] = $seg;
        }

        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Ang paghahanap
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Hinahanap ang needle sa bawat segment. ISANG tinanggihang hit saanman sa text = `none` para sa buong text
     * (`rejected` = true), para sa dalawang uri ng pagtanggi:
     *  - ang kasunod na salita ay numero o letrang wala sa label ("Poblacion" at "Poblacion 9" sa iisang chat);
     *  - ang sinabi ay ibang barangay pala ng city ("Dugui San Vicente", o mas kahawig ng "Poblacion West").
     * Pinili ito kaysa "ang hit lang na iyon ang tinatanggihan": ang chat na nagsasabi ng dalawang barangay ay
     * hindi malinaw kung alin ang totoo, kaya tao ang magpapasya; ang pagpiling ito ay hindi kailanman nagdadagdag ng kumpirmasyon.
     *
     * @param string   $needle   normBrgyKey ng hinahanap (label na walang parenthesis, o ang sulat sa form)
     * @param string   $fullKey  key ng label kasama ang laman ng parenthesis: doon lang puwedeng manggaling ang pinapayagang kasunod na salita
     * @param string[] $siblings mga key ng IBANG label ng city
     */
    private static function scan(array $segments, string $needle, string $fullKey, array $siblings): array
    {
        $none = ['result' => 'none', 'score' => 0, 'rejected' => false];
        if ($needle === '') return $none;
        $nw       = explode(' ', $needle);
        $n        = count($nw);
        $nSpecial = self::specials($nw);
        // Puro numero o iisang letra ang needle ("1", "28", "1 a"): saanman ay may ganoong salita, kaya
        // kumpirmado lang kapag kasunod-agad ng barangay word, at hindi kailanman sa compact o halos-tugma.
        $onlySpecial = count($nSpecial) === $n;
        $joined      = implode('', $nw);
        $canCompact  = !$onlySpecial && strlen($joined) >= self::MIN_LEN;
        $canNear     = !$onlySpecial && strlen($needle) >= self::MIN_LEN;
        $needleLen   = strlen($needle);

        // Ang salitang puwedeng sumunod sa hit dahil nasa label mismo ("SAN ROQUE (2)" → "2").
        $fw      = explode(' ', $fullKey);
        $allowed = (count($fw) > $n && array_slice($fw, 0, $n) === $nw) ? $fw[$n] : null;

        // Mga mas mahabang label ng city na naglalaman ng needle: [mga salita bago, mga salita pagkatapos].
        $longer = [];
        foreach ($siblings as $sib) {
            $sw = explode(' ', $sib);
            for ($p = 0, $max = count($sw) - $n; $max > 0 && $p <= $max; $p++) {
                if (array_slice($sw, $p, $n) === $nw) $longer[] = [array_slice($sw, 0, $p), array_slice($sw, $p + $n)];
            }
        }

        $nearMemo = [];
        $best     = $none;
        foreach ($segments as $seg) {
            $w = $seg['w'];
            $m = count($w);
            $hits = []; // [simula, dulo (hindi kasama), uri, score]

            $phraseAt = [];
            for ($i = 0; $i + $n <= $m; $i++) {
                if ($w[$i] !== $nw[0]) continue;
                if ($onlySpecial && !isset($seg['brgy'][$i])) continue;
                for ($j = 1; $j < $n && $w[$i + $j] === $nw[$j]; $j++);
                if ($j < $n || self::crossesStop($seg['soft'], $i, $i + $n)) continue;
                $phraseAt[$i] = true;
                $hits[] = [$i, $i + $n, 'phrase', 100];
            }

            if ($canCompact) {
                $target = strlen($joined);
                for ($i = 0; $i < $m; $i++) {
                    if (!str_starts_with($joined, $w[$i])) continue;
                    $run = '';
                    for ($j = $i; $j < $m && strlen($run) < $target; $j++) $run .= $w[$j];
                    if ($run !== $joined || (isset($phraseAt[$i]) && $j - $i === $n) || self::crossesStop($seg['soft'], $i, $j)) continue;
                    // Buong mga salita lang, at pareho ang mga numero at letra: ang "Poblacion 1 2 boxes" ay hindi POBLACION 12.
                    if (self::specials(array_slice($w, $i, $j - $i)) !== $nSpecial) continue;
                    $hits[] = [$i, $j, 'compact', 100];
                }
            }

            if ($canNear) {
                for ($i = 0; $i + $n <= $m; $i++) {
                    if (isset($phraseAt[$i]) || self::crossesStop($seg['soft'], $i, $i + $n)) continue;
                    $slice = array_slice($w, $i, $n);
                    $win   = implode(' ', $slice);
                    // Paulit-ulit ang mga window ng mahabang chat: isang beses lang kinukuwenta ang bawat isa.
                    $nearMemo[$win] ??= self::near($win, $slice, $needle, $needleLen, $nSpecial, $siblings);
                    [$pct, $siblingWins] = $nearMemo[$win];
                    if ($pct === null) continue;
                    if ($siblingWins) return ['result' => 'none', 'score' => 0, 'rejected' => true];
                    $hits[] = [$i, $i + $n, 'near', (int) floor($pct)];
                }
            }

            foreach ($hits as [$from, $to, $kind, $score]) {
                if (self::continues($seg, $to, $allowed) || self::longerLabelAround($seg, $from, $to, $longer)) {
                    return ['result' => 'none', 'score' => 0, 'rejected' => true];
                }
                $rank = ['none' => 0, 'near' => 1, 'compact' => 2, 'phrase' => 3];
                if ($rank[$kind] > $rank[$best['result']] || ($kind === $best['result'] && $score > $best['score'])) {
                    $best = ['result' => $kind, 'score' => $score, 'rejected' => false];
                }
            }
        }

        return $best;
    }

    /**
     * Halos-tugma ng isang window. Ibinabalik: [similar_text o null kapag hindi hit, true kapag may ibang label
     * ng city na kasing-kahawig o mas kahawig pa ng window] — "Poblacion Wst" ay mas kahawig ng POBLACION WEST kaysa EAST.
     */
    private static function near(string $win, array $slice, string $needle, int $needleLen, array $nSpecial, array $siblings): array
    {
        $len = strlen($win);
        // Sa sobrang layo ng haba, hindi na aabot ng 85: huwag nang ikumpara (pati ang salitang daan-libong character).
        if (200.0 * min($len, $needleLen) / ($len + $needleLen) < self::NEAR_PCT) return [null, false];
        similar_text($win, $needle, $pct);
        if ($pct < self::NEAR_PCT) return [null, false];
        // Ang halos-tugma ay hindi tumatawid ng magkaibang numero o suffix letter: "poblacion 2" ay hindi POBLACION 1.
        if (self::specials($slice) !== $nSpecial) return [null, false];
        foreach ($siblings as $sib) {
            $sl = strlen($sib);
            if (200.0 * min($len, $sl) / ($len + $sl) < $pct) continue;
            similar_text($win, $sib, $other);
            if ($other >= $pct) return [$pct, true];
        }

        return [$pct, false];
    }

    /** May malambot na hangganan ba SA LOOB ng mga salitang $from hanggang $to (hindi kasama ang $to)? */
    private static function crossesStop(array $soft, int $from, int $to): bool
    {
        if ($soft === []) return false;
        for ($k = $from + 1; $k < $to; $k++) {
            if (isset($soft[$k])) return true;
        }

        return false;
    }

    /** May hawak bang numero ang salita (kasama ang mga numerong hindi ASCII)? */
    private static function hasDigit(string $word): bool
    {
        return strpbrk($word, '0123456789') !== false || preg_match('/\p{N}/u', $word) === 1;
    }

    /**
     * Ang mga "espesyal" na salita, ayon sa pagkakasunod: may hawak na numero, iisang character, o initial
     * (ang tanging salitang may tuldok sa dulo). Ito ang dapat magkapareho, salita por salita, sa label at sa sinabi ng customer.
     */
    private static function specials(array $words): array
    {
        $out = [];
        foreach ($words as $word) {
            if (mb_strlen($word, 'UTF-8') === 1 || str_ends_with($word, '.') || self::hasDigit($word)) $out[] = $word;
        }

        return $out;
    }

    /**
     * Ang kasunod na salita ng hit, sa loob ng segment: numerong hanggang apat na digit (may kasamang letra o wala,
     * "12a") o iisang letra, na wala sa label = ibang barangay ang tinutukoy ("Poblacion 9" para sa POBLACION).
     * Limang digit pataas ay phone o order number, hindi pagpapatuloy ng pangalan. Ang initial ay hindi suffix letter.
     */
    private static function continues(array $seg, int $at, ?string $allowed): bool
    {
        if (!isset($seg['w'][$at]) || isset($seg['init'][$at])) return false;
        $next = $seg['w'][$at];
        if ($next === $allowed) return false;
        if (self::hasDigit($next)) {
            return preg_match_all('/\p{N}/u', $next) <= 4;
        }

        return preg_match('/^\p{L}$/u', $next) === 1;
    }

    /**
     * Ang mga salita ng customer bago at/o pagkatapos ng hit, kasama ang hit, ay bumubuo ba ng IBA at mas mahabang
     * label ng city? ("Dugui San Vicente" ay hindi SAN VICENTE.) Dito, ang initial ay binibilang bilang letra nito.
     */
    private static function longerLabelAround(array $seg, int $from, int $to, array $longer): bool
    {
        $alt = $seg['alt'];
        $m   = count($alt);
        foreach ($longer as [$pre, $post]) {
            $a = $from - count($pre);
            $b = $to + count($post);
            if ($a < 0 || $b > $m) continue;
            if (self::sameWords(array_slice($alt, $a, count($pre)), $pre) && self::sameWords(array_slice($alt, $to, count($post)), $post)) return true;
        }

        return false;
    }

    /** Pareho ang mga salita, o halos pareho sa parehong panuntunan ng halos-tugma ("Dugi" para sa DUGUI). */
    private static function sameWords(array $said, array $labelWords): bool
    {
        if ($said === $labelWords) return true;
        $a = implode(' ', $said);
        $b = implode(' ', $labelWords);
        if (strlen($b) < self::MIN_LEN || self::specials($said) !== self::specials($labelWords)) return false;
        similar_text($a, $b, $pct);

        return $pct >= self::NEAR_PCT;
    }
}

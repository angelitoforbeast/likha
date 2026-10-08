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
    /** Numerong isinulat sa salita: "Fatima Dos" ay FATIMA II. Ginagamit lang para makita ang IBANG barangay, hindi para kumumpirma. */
    private const NUMBER_WORDS = [
        'uno' => '1', 'dos' => '2', 'tres' => '3', 'kuwatro' => '4', 'kwatro' => '4', 'cuatro' => '4', 'singko' => '5', 'cinco' => '5',
        'sais' => '6', 'seis' => '6', 'siyete' => '7', 'siete' => '7', 'otso' => '8', 'ocho' => '8', 'nuwebe' => '9', 'nueve' => '9',
        'diyes' => '10', 'dies' => '10', 'diez' => '10',
        'one' => '1', 'two' => '2', 'three' => '3', 'four' => '4', 'five' => '5', 'six' => '6', 'seven' => '7', 'eight' => '8', 'nine' => '9', 'ten' => '10',
        'isa' => '1', 'dalawa' => '2', 'tatlo' => '3', 'apat' => '4', 'lima' => '5',
        // Pang-ilan: "Fatima ikalawa", "Fatima second" ay FATIMA II.
        'una' => '1', 'ikalawa' => '2', 'pangalawa' => '2', 'ikatlo' => '3', 'pangatlo' => '3', 'ikaapat' => '4', 'ikalima' => '5',
        'first' => '1', 'second' => '2', 'third' => '3', 'fourth' => '4', 'fifth' => '5',
    ];
    /** "No. 2", "nos. 2", "num 2", "number 2": ang salita bago ang numero ay hindi bahagi ng pangalan. */
    private const NUMBER_PREFIX = ['no', 'nos', 'num', 'number'];
    /** Mga pang-ugnay ng dalawang numero: "San Rafael 1 or 3" ay dalawang barangay, hindi isa. */
    private const CONNECTORS = ['and', 'or', 'at', 'o', 'to', 'hanggang'];

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
     *
     * $sharesCityName: ang barangay ay kapangalan ng sarili nitong city o bayan (MALIBCONG sa bayan ng MALIBCONG).
     * Ang "Malibcong, Abra" ay pangalan ng BAYAN, kaya hindi sapat ang isang banggit: kumpirmado lang kapag dalawang
     * beses nasa text ang pangalan, o may barangay word (o "pob"/"poblacion") na katabi mismo ng isang hit.
     * Pagtanggi lang ang naidaragdag nito.
     */
    public static function confirmWithWording(string $text, string $label, string $formWording, array $cityLabels, bool $sharesCityName = false): array
    {
        return self::run($text, $label, $formWording, $cityLabels, $sharesCityName);
    }

    private static function run(string $text, string $label, ?string $wording, array $cityLabels, bool $sharesCityName = false): array
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

            // Isang banggit lang ng pangalang kapangalan ng city, walang barangay word sa tabi: pangalan iyon ng bayan.
            $townOnly = static fn (array $r): bool => $sharesCityName && $r['result'] !== 'none' && $r['hits'] < 2 && !$r['marked'];

            $first = self::scan($segments, $own[0], $own[1], $siblings, $own);
            if ($townOnly($first)) return $none;
            if ($first['result'] !== 'none' || $first['rejected'] || $wording === null) {
                return ['result' => $first['result'], 'score' => $first['score']];
            }

            $wording = self::scrub($wording);
            if ((new MacroChecker())->matchBarangayInList($wording, $labels) !== $label) return $none;
            // Buong sulat, kasama ang laman ng parenthesis: ang "Poblacion (2)" ay hindi napapatunayan ng "Poblacion" lang.
            $needle = MacroChecker::normBrgyKey($wording);
            // Ang sulat na pangalan din ng IBANG barangay ng city ay hindi patunay para sa label na ito.
            if ($needle === '' || in_array($needle, $siblings, true)) return $none;
            // Ang sulat sa form ay dumadaan sa parehong mga rule; ang mga kapatid ay ang sa city pa rin ng LABEL.
            $second = self::scan($segments, $needle, $own[1], $siblings, $own);
            if ($townOnly($second)) return $none;

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
     * Ang numerong may pansarang bracket na walang kapares na pambukas ("3) Poblacion 4) Cotabato", pati "3.)", "3 )",
     * "3]") o may colon ("3: Poblacion 4: Cotabato") ay bilang ng listahan ng customer: hard break bago ang numero,
     * kaya hindi ito nagiging numero ng barangay sa unahan nito.
     * Ang anyong "3. Poblacion 4. Cotabato" ay hindi ginagalaw: hindi ito maihihiwalay sa dulo ng pangungusap na
     * sinusundan ng numero, at ang tuldok ay malambot na hangganan na.
     *
     * Bawat segment: `w` (ang salita para sa pagtutugma), `alt` (ang salita para sa paghahambing sa ibang label),
     * `brgy` (index ng salitang may barangay word na kasunod-agad sa unahan), `init` (index ng mga initial),
     * `soft` (index ng salitang may malambot na hangganan sa unahan), `sym` (index ng "salitang" puro simbolo),
     * `nl` (nagsisimula ba ang segment sa bagong linya).
     */
    private static function segments(string $text): array
    {
        $t = self::scrub($text);
        // Ang control character (kasama ang sarili naming marka) ay hangganan, hindi espasyo: walang natatawid.
        $t = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{2028}\x{2029}]/u', "\n", $t) ?? '';
        // Ang "4:" ay bilang ng listahan; ang oras ("10:30") ay hindi ginagalaw. Bago burahin ang colon sa ibaba.
        $t = preg_replace('/(?<![\p{L}\p{N}])(\p{N}{1,3}:)(?!\p{N})/u', "\n$1", $t) ?? '';
        $t = preg_replace('/[\p{Zs}\t#:]/u', ' ', $t) ?? '';
        $t = str_replace(["\u{2013}", "\u{2014}"], '-', $t);
        if (strpbrk($t, ')]') !== false) {
            $open = 0; // mga pambukas na bracket ng linyang ito na wala pang pansara
            $t = preg_replace_callback('/[(\[\r\n]|(?<![\p{L}\p{N}])\p{N}{1,3}\.? ?[)\]]|[)\]]/u', static function ($m) use (&$open) {
                $hit = $m[0];
                if ($hit === '(' || $hit === '[') { $open++; return $hit; }
                if ($hit === "\r" || $hit === "\n") { $open = 0; return $hit; }
                if ($open > 0) { $open--; return $hit; }

                return strlen($hit) === 1 ? $hit : "\n" . $hit;
            }, $t) ?? $t;
        }
        $t = str_replace([')', ']', '}', '!', '?'], ' . ', $t);
        $parts = preg_split('/([,;\r\n\/(\[{]|(?<=\s)-+(?=\s))/u', $t, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];

        $keyOf = []; // normBrgyKey kada piraso, isang beses lang: paulit-ulit ang mga salita ng mahabang chat
        $altOf = [];
        $out   = [];
        $nl    = true;
        foreach ($parts as $k => $part) {
            if ($k % 2 === 1) {
                if ($part === "\r" || $part === "\n") $nl = true;
                continue;
            }
            if (trim($part) === '') continue;
            // Ang barangay word (may tuldok o wala) ay tinatandaan sa kasunod na salita, saka inaalis.
            $part = preg_replace('/\b(?:barangay|brgy|bgy|bgry)\b\.?/iu', ' ' . self::BRGY_MARK . ' ', $part) ?? $part;
            $seg  = ['w' => [], 'alt' => [], 'brgy' => [], 'init' => [], 'soft' => [], 'sym' => [], 'nl' => $nl];
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
                            $alt = $altOf[$word] ??= self::altWord($word);
                            $seg['w'][]   = $word;
                            $seg['alt'][] = $alt !== '' ? $alt : $word;
                            if ($alt === '') $seg['sym'][$i] = true;
                        }
                        // May tuldok pagkatapos ng pirasong ito: hangganan, maliban kung daglat na bahagi ng pangalan.
                        // Ang barangay word bago ang hangganan ay hindi na "kasunod-agad" ng susunod na salita.
                        if ($k < $last && !in_array(mb_strtolower($stem, 'UTF-8'), self::DOTTED_ABBR, true)) { $afterStop = true; $afterBrgy = false; }
                    }
                }
            }
            if ($seg['w'] !== []) { $out[] = $seg; $nl = false; }
        }

        return $out;
    }

    /**
     * Ang salita sa mata ng paghahambing sa ibang label. '' = puro simbolo (walang letra o numero: "|", "*", emoji,
     * zero-width space), na nilalaktawan sa pagtingin sa kasunod na salita. Ang Roman numeral na na-type gamit ang
     * maliit na L ("ll", "lV") ay ang numero nito: iyon ang ibig sabihin ng customer, at ibang barangay iyon.
     */
    private static function altWord(string $word): string
    {
        if (preg_match('/[\p{L}\p{N}]/u', $word) !== 1) return '';
        if (str_contains($word, 'l') && strspn($word, 'livx') === strlen($word)) {
            $number = MacroChecker::normBrgyKey(strtr($word, 'l', 'i'));
            if (ctype_digit($number)) return $number;
        }

        return $word;
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
     * @param string[] $own      lahat ng key ng label mismo: ang sinabing eksaktong isa sa mga ito ay hindi typo ng ibang label
     */
    private static function scan(array $segments, string $needle, string $fullKey, array $siblings, array $own): array
    {
        $none = ['result' => 'none', 'score' => 0, 'rejected' => false, 'hits' => 0, 'marked' => false];
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

        // Ilang salita ng kasunod ng hit ang kailangang makita: ang pinakamahabang dugtong ng ibang label, may palugit
        // para sa "No." at sa pang-ugnay.
        $reach = 3;
        $back  = 0; // ganoon din sa mga salita BAGO ang hit, kapag may label na may salita sa unahan ng needle
        foreach ($longer as [$pre, $post]) {
            $reach = max($reach, 2 * count($post) + 2);
            if ($pre !== []) $back = max($back, 2 * count($pre) + 2);
        }

        // Mga numero ng mga kapatid na kapareho ng needle maliban sa huling numero: POBLACION 1 → 2, 3, …
        $otherNumbers = [];
        if (self::hasDigit($nw[$n - 1])) {
            $head = array_slice($nw, 0, $n - 1);
            foreach ($siblings as $sib) {
                $sw = explode(' ', $sib);
                if (count($sw) === $n && array_slice($sw, 0, $n - 1) === $head && self::hasDigit($sw[$n - 1])) $otherNumbers[self::plain($sw[$n - 1])] = true;
            }
        }
        $sibSet = array_fill_keys($siblings, true);
        $ownSet = array_fill_keys($own, true);
        $ownNumber = self::plain($nw[$n - 1]);

        // Ang "poblacion" sa tabi ng hit ay tanda ng barangay, maliban kung POBLACION mismo ay ibang barangay ng city.
        $pobIsSibling = isset($sibSet['poblacion']);
        $hitCount     = 0;     // ilang malinis na hit sa buong text
        $marked       = false; // may hit bang katabi mismo ng barangay word o ng "poblacion"

        $nearMemo   = [];
        $aroundMemo = [];
        $best       = $none;
        foreach ($segments as $si => $seg) {
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
                    $nearMemo[$win] ??= self::near($win, $slice, $needle, $needleLen, $nSpecial, $siblings, $sibSet);
                    [$pct, $siblingWins] = $nearMemo[$win];
                    if ($pct === null) continue;
                    if ($siblingWins) return ['result' => 'none', 'score' => 0, 'rejected' => true];
                    $hits[] = [$i, $i + $n, 'near', (int) floor($pct)];
                }
            }

            foreach ($hits as [$from, $to, $kind, $score]) {
                if (self::continues($seg, $to, $allowed)) return ['result' => 'none', 'score' => 0, 'rejected' => true];
                if ($longer !== []) {
                    // Tumatawid ng hangganan ang tinging ito, pasulong at pabalik: "Fatima - 2", "Fatima (2)", "Fatima, 2" ay
                    // FATIMA II pa rin, at "Vinisitahan - Basud" ay VINISITAHAN-BASUD pa rin, hindi BASUD.
                    // Dalawang anyo ng bawat salita: ang `alt` (ang "ll" ay 2) at ang mismong sulat (ang "vill" ay hindi 8).
                    $after   = self::ahead($segments, $si, $to, $reach, false);
                    $afterW  = self::ahead($segments, $si, $to, $reach, false, 'w');
                    $before  = $back > 0 ? self::behind($segments, $si, $from, $back, 'alt') : [];
                    $beforeW = $back > 0 ? self::behind($segments, $si, $from, $back, 'w') : [];
                    // Paulit-ulit ang paligid ng hit sa mahabang chat: isang beses lang kinukuwenta ang bawat isa.
                    $around = implode(' ', $before) . '|' . implode(' ', $after) . '|' . implode(' ', $beforeW) . '|' . implode(' ', $afterW);
                    if ($aroundMemo[$around] ??= self::longerLabelAround($nw, $longer, self::readings($before, $beforeW), self::readings($after, $afterW), $ownSet)) {
                        return ['result' => 'none', 'score' => 0, 'rejected' => true];
                    }
                }
                if ($otherNumbers !== []) {
                    // "San Rafael 1 or 3", "San Rafael 1 / 3", "Fatima 2 and/or 3": dalawang barangay ang binanggit sa iisang linya.
                    $after = self::spoken(self::ahead($segments, $si, $to, 4, true));
                    for ($at = 0; $at < 2 && in_array($after[$at] ?? '', self::CONNECTORS, true); $at++);
                    $second = $after[$at] ?? '';
                    // Pati ang sariling numero pagkatapos ng pang-ugnay ("Fatima 2 or Dos"): dalawang numero pa rin ang sinabi.
                    if (isset($otherNumbers[$second]) || ($at > 0 && $second === $ownNumber)) return ['result' => 'none', 'score' => 0, 'rejected' => true];
                }
                $hitCount++;
                if (isset($seg['brgy'][$from]) || (!$pobIsSibling && (($w[$from - 1] ?? '') === 'poblacion' || ($w[$to] ?? '') === 'poblacion'))) $marked = true;
                $rank = ['none' => 0, 'near' => 1, 'compact' => 2, 'phrase' => 3];
                if ($rank[$kind] > $rank[$best['result']] || ($kind === $best['result'] && $score > $best['score'])) {
                    $best = ['result' => $kind, 'score' => $score, 'rejected' => false];
                }
            }
        }

        return ['hits' => $hitCount, 'marked' => $marked] + $best;
    }

    /**
     * Halos-tugma ng isang window. Ibinabalik: [similar_text o null kapag hindi hit, true kapag may ibang label
     * ng city na kasing-kahawig o mas kahawig pa ng window] — "Poblacion Wst" ay mas kahawig ng POBLACION WEST kaysa EAST.
     */
    private static function near(string $win, array $slice, string $needle, int $needleLen, array $nSpecial, array $siblings, array $sibSet): array
    {
        $len = strlen($win);
        // Sa sobrang layo ng haba, hindi na aabot ng 85: huwag nang ikumpara (pati ang salitang daan-libong character).
        if (200.0 * min($len, $needleLen) / ($len + $needleLen) < self::NEAR_PCT) return [null, false];
        similar_text($win, $needle, $pct);
        if ($pct < self::NEAR_PCT) return [null, false];
        // Numerong nakadikit sa pangalan ("FatimaII", "San RafaelIV", "Fatima2"): kapag ang window na walang buntot
        // ay mas kahawig pa ng needle at ang buntot ay numero ng ibang barangay ng city, iyon ang sinabi ng customer.
        $last = (string) array_pop($slice);
        $head = $slice === [] ? '' : implode(' ', $slice) . ' ';
        foreach (self::gluedTails($last) as [$stem, $number]) {
            if (!isset($sibSet[$needle . ' ' . $number])) continue;
            similar_text($head . $stem, $needle, $bare);
            if ($bare > $pct) return [$pct, true];
        }
        $slice[] = $last;
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
        // Ang simbolo sa pagitan ("Fatima | 2", "Fatima * 2") ay hindi naghihiwalay ng pangalan at ng numero nito.
        while (isset($seg['sym'][$at])) $at++;
        if (!isset($seg['w'][$at]) || isset($seg['init'][$at])) return false;
        if ($seg['w'][$at] === $allowed) return false;
        // Ang `alt`: ang Roman numeral na may maliit na L ay numero na rito.
        $next = $seg['alt'][$at];
        if ($next === $allowed) return false;
        if (self::hasDigit($next)) {
            return preg_match_all('/\p{N}/u', $next) <= 4;
        }

        return preg_match('/^\p{L}$/u', $next) === 1;
    }

    /**
     * Ang mga salita ng customer bago at/o pagkatapos ng hit, kasama ang hit, ay bumubuo ba ng IBA at mas mahabang
     * label ng city? ("Dugui San Vicente" ay hindi SAN VICENTE.) Dito, ang initial ay binibilang bilang letra nito.
     * Ang mga salita sa paligid ay ang ibinigay ng behind() at ahead(), sa bawat basa ng readings().
     * Ang sinabing eksaktong key ng label mismo ("Napao (Island)" kapag may kapatid na "(Lsland)") ay hindi typo ng iba.
     * Dalawang paraan ng pagtutugma: salita por salita ang dagdag na bahagi (sameWords), o ang BUONG sinabi laban sa
     * BUONG pangalan — doon nakikita ang isang maling letra sa maikling salita ("Anilao Labak" ay ANILAO-LABAC).
     */
    private static function longerLabelAround(array $nw, array $longer, array $befores, array $afters, array $ownSet): bool
    {
        foreach ($longer as [$pre, $post]) {
            $p = count($pre);
            $c = count($post);
            foreach ($befores as $b) {
                if (count($b) < $p) continue;
                $saidPre = $p === 0 ? [] : array_slice($b, -$p);
                foreach ($afters as $a) {
                    if (count($a) < $c) continue;
                    $saidPost = array_slice($a, 0, $c);
                    if (self::sameWords($saidPre, $pre) && self::sameWords($saidPost, $post)) return true;
                    if (!isset($ownSet[implode(' ', array_merge($saidPre, $nw, $saidPost))]) && self::sameName($saidPre, $saidPost, $pre, $post, $nw)) return true;
                }
            }
        }

        return false;
    }

    /**
     * Ang buong sinabi (mga salita bago + ang pangalan + mga salita pagkatapos) laban sa buong mas mahabang pangalan,
     * sa parehong panuntunan ng halos-tugma. Isang letra lang ang puwedeng mali sa dagdag na bahagi mismo: kung hindi, ang
     * mahabang pangalan ang bubuhat sa score at ang "Rizal St Poblacion" ay magmumukhang WEST POBLACION.
     */
    private static function sameName(array $saidPre, array $saidPost, array $pre, array $post, array $nw): bool
    {
        $said  = array_merge($saidPre, $saidPost);
        $extra = array_merge($pre, $post);
        if (self::specials($said) !== self::specials($extra)) return false;
        if (levenshtein(implode(' ', $said), implode(' ', $extra)) > 1) return false;
        similar_text(implode(' ', array_merge($saidPre, $nw, $saidPost)), implode(' ', array_merge($pre, $nw, $post)), $pct);

        return $pct >= self::NEAR_PCT;
    }

    /**
     * Ang mga basa ng mga salita sa paligid ng hit: gaya ng pagkakasulat; may numerong salita na ginawang numero
     * ("Fatima Dos", "Fatima No. 2", "Fatima second"); at may "11", "111" na binasa bilang Roman II, III
     * ("Bagong Buhay - 11" ay BAGONG BUHAY II); at ang mismong sulat ($raw). Para lang makita ang IBANG barangay,
     * hindi para kumumpirma.
     */
    private static function readings(array $words, array $raw): array
    {
        $out    = [$words];
        $spoken = self::spoken($words);
        if ($spoken !== $words) $out[] = $spoken;
        $ones = [];
        foreach ($spoken as $word) {
            $len    = strlen($word);
            $ones[] = ($len >= 2 && $len <= 3 && strspn($word, '1') === $len) ? (string) $len : $word;
        }
        if ($ones !== $spoken) $out[] = $ones;
        if ($raw !== $words) $out[] = $raw;

        return $out;
    }

    /** Ang numerong may sero sa unahan ("02") ay ang numero mismo sa paghahambing sa ibang label. */
    private static function plain(string $word): string
    {
        if ($word === '' || $word[0] !== '0' || !ctype_digit($word)) return $word;
        $cut = ltrim($word, '0');

        return $cut === '' ? '0' : $cut;
    }

    /**
     * Ang mga salita BAGO ang hit (hanggang $max, sa ayos ng pagkakasulat), nilalaktawan ang puro simbolo at tumatawid
     * ng hangganan gaya ng ahead(): pagtanggi lang ang nagagawa ng tinging ito, hindi kumpirmasyon.
     */
    private static function behind(array $segments, int $si, int $at, int $max, string $form): array
    {
        $out = [];
        for ($at--; $si >= 0 && count($out) < $max; $at = --$si >= 0 ? count($segments[$si]['alt']) - 1 : -1) {
            $alt = $segments[$si][$form];
            $sym = $segments[$si]['sym'];
            for (; $at >= 0 && count($out) < $max; $at--) {
                if (!isset($sym[$at])) $out[] = self::plain($alt[$at]);
            }
        }

        return array_reverse($out);
    }

    /**
     * Ang mga salitang kasunod ng hit (hanggang $max), nilalaktawan ang puro simbolo at tumatawid ng hangganan:
     * pagtanggi lang ang nagagawa ng tinging ito, hindi kumpirmasyon. Sa $sameLine, humihinto ito sa dulo ng linya.
     */
    private static function ahead(array $segments, int $si, int $at, int $max, bool $sameLine, string $form = 'alt'): array
    {
        $out = [];
        for ($count = count($segments), $first = true; $si < $count && count($out) < $max; $si++, $at = 0, $first = false) {
            if (!$first && $sameLine && $segments[$si]['nl']) break;
            $alt = $segments[$si][$form];
            $sym = $segments[$si]['sym'];
            for ($m = count($alt); $at < $m && count($out) < $max; $at++) {
                if (!isset($sym[$at])) $out[] = self::plain($alt[$at]);
            }
        }

        return $out;
    }

    /** Ang mga salita na may numerong salita o pang-ilan ("2nd") na ginawang numero, at walang "no"/"nos"/"num"/"number" bago ang numero. */
    private static function spoken(array $words): array
    {
        $out = [];
        foreach ($words as $i => $word) {
            $next = $words[$i + 1] ?? '';
            if (in_array($word, self::NUMBER_PREFIX, true) && ctype_digit(self::NUMBER_WORDS[$next] ?? $next)) continue;
            if (preg_match('/^(\d{1,2})(?:st|nd|rd|th)$/', $word, $m) === 1) { $out[] = (string) (int) $m[1]; continue; }
            // "ika-2" / "ika 2" / "pang-2": pang-ilan na may numero, ang numero mismo ang sinabi.
            if (($word === 'ika' || $word === 'pang') && ctype_digit((string) $next) && $next !== '') continue;
            if (preg_match('/^(?:ika|pang)(\d{1,2})$/', $word, $m) === 1) { $out[] = (string) (int) $m[1]; continue; }
            $out[] = self::NUMBER_WORDS[$word] ?? $word;
        }

        return $out;
    }

    /**
     * Mga posibleng hati ng isang salita sa [pangalan, numerong nakadikit sa dulo]: mga digit ("fatima2"), o Roman
     * numeral na puwedeng may maliit na L ("fatimaii", "fatimall").
     */
    private static function gluedTails(string $word): array
    {
        $out = [];
        if (preg_match('/^(.*\D)(\d{1,4})$/s', $word, $m) === 1) $out[] = [$m[1], (string) (int) $m[2]];
        for ($k = 1, $len = strlen($word); $k < $len && $k <= 7; $k++) {
            $tail = substr($word, -$k);
            if (strspn($tail, 'ivxl') !== $k) break;
            $number = MacroChecker::normBrgyKey(strtr($tail, 'l', 'i'));
            if (ctype_digit($number)) $out[] = [substr($word, 0, -$k), $number];
        }

        return $out;
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

<?php

namespace App\Services;

/**
 * Ang PASYA ni Astra sa isang row: mula sa sagot ng model hanggang sa anim na field, STATUS,
 * APP SCRIPT CHECKER at ang isang linya ng AI ANALYZE.
 *
 * Pure: walang database, walang HTTP, walang orasan, walang log. Ang tanging labasan ay ang `$gate`
 * na ibinibigay ng tumatawag (ang Validate rules, na nagbabasa ng database), kaya ang parehong pasya
 * ay maaaring ulitin sa nakaimbak na sagot nang walang isinusulat.
 *
 * Ang sagot ng model, ang chat, ang history at ang CXD ay galing sa labas (hindi pinagkakatiwalaan):
 * data lang ang mga ito rito, hindi kailanman utos.
 */
class AstraAddressRules
{
    /** Hanggang ilang character ng Pancake history ang isinasama (ang pinakabago ang mahalaga). */
    public const HISTORY_MAX = 7000;
    /** Pinakamahabang city, barangay o province ng form na imamapa ng program; ang mas mahaba ay hindi pangalan ng lugar. */
    public const FORM_PLACE_MAX = 120;
    /** Pinakamahabang value ng form sa isang linya ng block. */
    public const BLOCK_VALUE_MAX = 250;

    /**
     * $in:
     *   rules           'old' | 'new' — alin sa dalawang set ng rules. Sa `new`: kapag walang buong line ang model,
     *                   ang program ang nagmamapa ng form sa list; ang guard ay tumatanggap ng halos-tugma; ang cancel,
     *                   ang inquiry at ang kaparehong phone ay hindi na humahawak ng buong row; at ang flag ng model na
     *                   "walang nakita sa list" ay hindi humahawak kapag ang program ang nakahanap at kumpirmado sa text.
     *   answer          ang na-parse na sagot: form, jnt, intent, issues, needs_human, human_reason, human_kind, confidence, evidence
     *   row             ang anim na field ng row gaya ng nabasa (naka-trim)
     *   chat, history, customer_blocks   ang tatlong text na tinitingnan ng guard
     *   maps            ang J&T list (MacroChecker::loadAddressMaps)
     *   list_crc        check number ng list file
     * $gate: fn (array $final, bool $checkDuplicatePhone): ['hard' => [...], 'soft' => [...]]
     */
    public static function decide(array $in, callable $gate): array
    {
        $rules  = ($in['rules'] ?? 'old') === 'new' ? 'new' : 'old';
        $ai     = (array) $in['answer'];
        $form   = (array) $ai['form'];
        $row    = (array) $in['row'];
        $maps   = (array) $in['maps'];
        $chat   = (string) $in['chat'];
        $history = (string) $in['history'];
        $customerBlocks = (string) $in['customer_blocks'];
        $evidence = [];

        // Ang dahilan at ang mga issue ng model ay napupunta sa note at sa Check line ng block: isang linya lang bawat isa,
        // para walang line break ng model na makagawa ng pekeng hangganan ng block (na babasahin bilang text ng customer).
        if ($rules === 'new') {
            $ai['human_reason'] = self::oneLine((string) $ai['human_reason'], 300);
            $ai['issues']       = array_map(fn ($i) => self::oneLine((string) $i, 160), (array) $ai['issues']);
        }

        // Ang sariling flag ng model, bago ito patungan ng guard o ng no-line rule sa ibaba.
        $modelNeedsHuman = (bool) $ai['needs_human'];

        // ── J&T labels: PHP ang nagpapatunay na nasa list talaga ang pinili ng AI ──
        [$prov, $city, $brgy, $listNote, $listLines] = self::validateJnt((array) $ai['jnt'], $maps);
        foreach ($listLines as $line) $evidence[] = $line;
        if ($listNote !== '') $evidence[] = 'LIST: ' . $listNote;
        else $evidence[] = 'LIST: ' . $prov . ' | ' . $city . ' | ' . $brgy;
        // Ang salitang ito ay kung SAAN galing ang line (model, program, wala) — hindi kung naisulat ang barangay:
        // ang line na ibinagsak ng guard ay `model` / `program_map` pa rin, at ang salita ng guard ang nagsasabi ng natira.
        $labelSource = ($prov !== null && $city !== null && $brgy !== null) ? 'model' : 'none';
        // Ang bahagi ng line na ang model mismo ang nagbigay nang tama (province, o province at city).
        [$modelProv, $modelCity] = [$prov, $city];

        // Walang buong line ang model: ang program ang nagmamapa ng sariling form ni Astra sa list (mapper ng classic checker).
        // Buong line o wala: ang city na namapa ay hindi isinusulat nang walang barangay; ang bahaging tama ng line ng model
        // ay nananatili gaya ng dati.
        if ($rules === 'new' && $labelSource === 'none') {
            [$mapped, $mapNote] = self::mapForm($form, (string) $ai['confidence'], $maps, $prov, $city);
            if ($mapped !== null) {
                [$prov, $city, $brgy] = $mapped;
                $labelSource = 'program_map';
                $listNote    = '';
                $evidence[]  = 'MAP: ' . $mapNote . ' · barangay → ' . $brgy;
            } elseif ($mapNote !== '') {
                $evidence[] = 'MAP: ' . $mapNote . ' → walang line';
                $listNote   = ($listNote !== '' ? $listNote . ' · ' : '') . 'form: ' . $mapNote;
            }
        }

        // GUARD: barangay na HINDI binanggit ng customer (hinula mula sa landmark/web/katabing listing) →
        // tatanggapin lang kung "high" ang confidence; kung hindi, hindi isusulat at tao ang bahala.
        $guard = ['ran' => $brgy !== null, 'result' => 'not_run', 'score' => 0];
        if ($brgy !== null) {
            $hay = $chat . "\n" . $history . "\n" . $customerBlocks;
            if ($rules === 'new') {
                $found  = self::confirmedByText($brgy, (string) $city, (string) $prov, (string) $form['brgy'], [$chat, $history, $customerBlocks], $maps);
                $inChat = $found['result'] !== 'none';
                $guard['result'] = $inChat ? $found['result'] : ($ai['confidence'] !== 'high' ? 'none' : 'exempt_high_confidence');
                $guard['score']  = $found['score'];
                if ($found['result'] === 'near') $evidence[] = 'GUARD: barangay "' . $brgy . '" near match (' . $found['score'] . ')';
            } else {
                $inChat = self::mentions($hay, $brgy) || ($form['brgy'] !== '' && self::mentions($hay, $form['brgy']));
                $guard['result'] = $inChat ? 'confirmed' : ($ai['confidence'] !== 'high' ? 'none' : 'exempt_high_confidence');
            }
            if (!$inChat && $ai['confidence'] !== 'high') {
                $evidence[] = 'GUARD: barangay "' . $brgy . '" hindi sinabi ng customer (hinula, ' . $ai['confidence'] . ') → hindi isinulat, tao';
                $ai['issues'][]   = 'Barangay ' . $brgy . ' ay hinula lang mula sa landmark/web (' . $ai['confidence'] . ' confidence), hindi sinabi ng customer';
                $ai['needs_human'] = true;
                if ($ai['human_reason'] === '') $ai['human_reason'] = 'kumpirmahin ang barangay (' . $brgy . '?)';
                $brgy = null;
                // Buong line lang ang isinusulat ng program: ang province at city na ito ang nagmapa ay hindi maiiwan nang
                // walang barangay (ang kalahating address ay mukhang na-check na). Ang tamang bahagi ng line ng model ay nananatili.
                if ($labelSource === 'program_map') [$prov, $city] = [$modelProv, $modelCity];
            } elseif (!$inChat) {
                $evidence[] = 'GUARD: barangay "' . $brgy . '" hinula mula sa landmark/web, tinanggap dahil high confidence';
            }
        }

        // Ang sariling flag ng model ay humahawak ng row, MALIBAN kung ang tanging dahilan nito ay walang nakita sa list,
        // ang program ang nakahanap ng line, at ang barangay ay kumpirmado ng mismong text ng customer (hindi ng exemption
        // ng high confidence). Ang dahilan ng model ay nananatili sa evidence, pero hindi na ito hold.
        if ($rules === 'new' && $modelNeedsHuman && ($ai['human_kind'] ?? '') === 'label_not_found'
            && $labelSource === 'program_map' && in_array($guard['result'], ['phrase', 'compact', 'near'], true)) {
            $evidence[] = 'FLAG: needs_human ng model (walang nakita sa list) — hindi na hawak: namapa ng program ang line at kumpirmado sa text ng customer'
                . ($ai['human_reason'] !== '' ? ' · sabi ng model: ' . self::oneLine($ai['human_reason'], 300) : '');
            $ai['needs_human']  = false;
            $ai['human_reason'] = '';
        }

        // ── 4. Anim na field ─────────────────────────────────────────────
        $updates = [];
        $name = self::cleanName((string) $form['name']);
        if ($name !== '' && $name !== $row['FULL NAME']) $updates['FULL NAME'] = $name;

        $phoneRaw = trim((string) $form['phone']);
        $phone    = self::normalizePhone($phoneRaw);
        $phoneOk  = $phone !== null && preg_match('/^9\d{9}$/', $phone) === 1;
        if ($phoneOk && $phone !== $row['PHONE NUMBER']) $updates['PHONE NUMBER'] = $phone;

        $addr = self::composeAddress($form);
        if ($addr !== '' && $addr !== $row['ADDRESS']) $updates['ADDRESS'] = $addr;

        foreach (['PROVINCE' => $prov, 'CITY' => $city, 'BARANGAY' => $brgy] as $col => $val) {
            if ($val !== null && $val !== $row[$col]) $updates[$col] = $val;
        }
        $final = [];
        foreach (['FULL NAME', 'PHONE NUMBER', 'ADDRESS', 'PROVINCE', 'CITY', 'BARANGAY'] as $col) {
            $final[$col] = (string) ($updates[$col] ?? $row[$col]);
        }

        // ── 5. CHECK — PHP ang huling salita ─────────────────────────────
        $issues = $ai['issues'];
        if (!$phoneOk) {
            $digits = preg_replace('/\D+/', '', $phoneRaw) ?? '';
            $issues[] = 'Phone: ' . ($phoneRaw === '' ? 'wala sa chat' : $phoneRaw . ' (' . strlen($digits) . ' digit, dapat 10 na nagsisimula sa 9)');
        }
        $issues = array_values(array_unique(array_filter(array_map('trim', $issues))));

        $astraDecided = ($prov !== null && $city !== null && $brgy !== null);
        if ($astraDecided) {
            $verdict = [
                'province_ok' => $final['PROVINCE'] !== '',
                'city_ok'     => $final['CITY'] !== '',
                'barangay_ok' => $final['BARANGAY'] !== '',
                'evidence'    => 'J&T labels mula sa list',
            ];
        } else {
            // Walang (kumpletong) J&T label si Astra. Kung may laman na ang row (hal. scope = lahat ng walang STATUS),
            // huwag sabihing "Full Address" — i-verify ang EXISTING sa list; hindi ito PROCEED dahil hindi nakumpirma (TO FIX).
            [$eP, $eC, $eB, , $existingLines] = self::validateJnt(['province' => $final['PROVINCE'], 'city' => $final['CITY'], 'barangay' => $final['BARANGAY']], $maps);
            foreach ($existingLines as $line) $evidence[] = $line;
            $verdict = [
                'province_ok' => $eP !== null,
                'city_ok'     => $eC !== null,
                'barangay_ok' => $eB !== null,
                'evidence'    => 'Astra walang J&T label; existing values na-check sa list' . ($listNote !== '' ? ' — ' . $listNote : ''),
            ];
            $ai['needs_human'] = true;
            if ($ai['human_reason'] === '') $ai['human_reason'] = 'hindi matukoy ni Astra ang J&T label' . ($listNote !== '' ? ' (' . $listNote . ')' : '') . '; hindi nakumpirma ang existing na address';
            $evidence[] = 'CHECK: existing prov/city/brgy vs list → ' . ($eP ? '✅' : '❌') . ' ' . ($eC ? '✅' : '❌') . ' ' . ($eB ? '✅' : '❌') . ' (hindi nakumpirma ni Astra → tao)';
        }
        $statusCode = (new MacroChecker())->computeStatusCode($verdict);
        $allFilled  = count(array_filter($final, fn ($v) => trim($v) !== '')) === 6;
        // Ang cancel at ang inquiry ay may sariling code. Sa lumang rules hawak nila ang row at hindi tumatakbo ang gate;
        // sa bagong rules hindi na sila hold (ang `unclear` at anumang ibang salita ay hold pa rin) at tumatakbo ang gate.
        $intentCode  = ['cancel' => 'CANCEL?', 'inquiry_only' => 'INQUIRY?'][$ai['intent']] ?? null;
        $heldAsToday = $ai['needs_human'] || $ai['intent'] !== 'order';
        $needsHuman  = $rules === 'new' ? ($ai['needs_human'] || ($ai['intent'] !== 'order' && $intentCode === null)) : $heldAsToday;

        $gateResult = ['hard' => [], 'soft' => []];
        $proceed = false;
        $code = $intentCode ?? $statusCode;
        if ($statusCode === '✅' && $allFilled && ($intentCode === null || $rules === 'new')) {
            // Bagong rules: hindi tinatanong ang kaparehong phone sa parehong petsa (ang Validate ang huhuli nito).
            $gateResult = $gate($final, $rules !== 'new');
            $fail = null;
            if ($needsHuman)                       $fail = ['TO FIX', 'Astra: ' . ($ai['human_reason'] ?: 'kailangan ng tao')];
            elseif (!empty($gateResult['hard']))   $fail = ['TO FIX', implode('; ', $gateResult['hard'])];
            elseif (!empty($gateResult['soft']))   $fail = ['TO FIX - SHOP DETAILS', implode('; ', $gateResult['soft'])];
            if ($fail === null) {
                $proceed = true; $updates['STATUS'] = 'PROCEED'; $code = $statusCode;
            } else {
                // Ang cancel o inquiry na hindi pumasa ay nananatili sa sarili nitong code; ang dahilan ay nasa evidence.
                if ($intentCode === null) $code = $fail[0];
                $evidence[] = 'GATE: ' . $fail[0] . ' — ' . $fail[1];
            }
        } elseif ($intentCode === null && $needsHuman && $ai['human_reason'] !== '') {
            $evidence[] = 'HUMAN: ' . $ai['human_reason'];
        }
        $updates['APP SCRIPT CHECKER'] = mb_substr($code, 0, 60);

        // Isang linya para sa checker_1 (AI ANALYZE — existing column)
        $summary = ($statusCode === '✅' ? '✅ ' : '⚠ ' . $statusCode . ' · ')
            . ($astraDecided
                ? $prov . ' / ' . $city . ' / ' . $brgy
                : (($final['PROVINCE'] !== '' && $final['CITY'] !== '' && $final['BARANGAY'] !== '')
                    ? $final['PROVINCE'] . ' / ' . $final['CITY'] . ' / ' . $final['BARANGAY'] . ' (existing, hindi nakumpirma ni Astra)'
                    : 'J&T: ' . ($listNote !== '' ? $listNote : 'hindi matukoy')))
            . ($issues ? ' · ⚠ ' . implode(' · ', $issues) : '')
            . (!$proceed && $heldAsToday && $ai['human_reason'] !== '' ? ' · 👤 ' . $ai['human_reason'] : '')
            . ($proceed ? ($intentCode !== null ? ' · ' . $intentCode : '') . ' · PROCEED' : ($code !== $statusCode ? ' · ' . $code : ''));
        $updates['AI ANALYZE'] = mb_substr($summary, 0, 2000);

        return [
            'updates'      => $updates,           // anim na field, STATUS, APP SCRIPT CHECKER, AI ANALYZE (walang CXD)
            'final'        => $final,
            'verdict'      => $verdict,
            'status_code'  => $statusCode,
            'code'         => $code,
            'all_filled'   => $allFilled,
            'gate'         => $gateResult,
            'proceed'      => $proceed,
            'summary'      => $summary,
            'evidence'     => $evidence,
            'line'         => ($prov && $city && $brgy) ? [$prov, $city, $brgy] : null,
            'list_note'    => $listNote,
            'check_issues' => $issues,            // mga issue ng sagot + ng phone, walang ulit
            // Ang mga bahagi ng sagot na binago ng rules, gaya ng iniimbak sa trace
            'issues'       => $ai['issues'],
            'needs_human'  => $ai['needs_human'],
            'human_reason' => $ai['human_reason'],
            'needs_human_effective' => $needsHuman,
            // Para sa replay: booleans, integers at nakapirming salita LANG — walang text ng customer o ng model.
            'replay'       => [
                'rules'             => $rules,
                'model_needs_human' => $modelNeedsHuman,
                'model_human_kind'  => ($rules === 'new' && in_array($ai['human_kind'] ?? '', ['label_not_found', 'other'], true)) ? $ai['human_kind'] : 'none',
                'model_intent'      => in_array($ai['intent'], ['order', 'cancel', 'inquiry_only', 'unclear'], true) ? $ai['intent'] : 'order',
                'label_source'      => $labelSource,
                'guard'             => $guard,
                'hay_chars'         => ['chat' => mb_strlen($chat), 'history' => mb_strlen($history), 'cxd' => mb_strlen($customerBlocks)],
                'dup_phone_checked' => $rules !== 'new',
                'list_crc'          => (int) ($in['list_crc'] ?? 0),
            ],
        ];
    }

    /**
     * Ang line mula sa sariling form ni Astra, gamit ang mapper at ang barangay matcher ng classic checker (parehong pure).
     * [line o null, note]. May line LANG kapag: iisa ang city sa list; ang city na isinulat nang walang "city" ay iyon pa
     * rin kapag dinagdagan ng "City" ("Naga" → bayan sa Zamboanga Sibugay o Naga City sa Camarines Sur? hindi alam → tao);
     * iisa ang barangay; at hindi ito salungat sa province/city na tama sa line ng model.
     * Ang note ay isang linya lang: galing sa form ang laman nito (hindi pinagkakatiwalaan).
     */
    public static function mapForm(array $form, string $confidence, array $maps, ?string $modelProv, ?string $modelCity): array
    {
        $c = trim((string) ($form['city'] ?? '')); $b = trim((string) ($form['brgy'] ?? '')); $p = trim((string) ($form['province'] ?? ''));
        if ($confidence === 'low' || $c === '' || $b === '') return [null, ''];
        foreach ([$c, $b, $p] as $v) if (mb_strlen($v, 'UTF-8') > self::FORM_PLACE_MAX) return [null, ''];

        $mc  = new MacroChecker();
        $ask = fn (string $city): array => $mc->mapResolvedToList(['province' => $p, 'city' => $city, 'province_aliases' => [], 'confidence' => $confidence], $maps);
        $one = $ask($c);
        $note = self::oneLine((string) $one['note'], 1000);
        if ($one['city'] === null || $one['province'] === null) return [null, $note];
        $mapProv = (string) $one['province']; $mapCity = (string) $one['city'];

        // Mas pinipili ng mapper ang label na kapareho ng sulat kaysa sa label na may "city": tanungin ulit na may "City".
        // Walang sagot sa pangalawang tanong = walang ibang city na ganoon ang pangalan (hindi iyon tie).
        // Ganoon din pabalik: ang city na isinulat NA MAY "city" ay tinatanong ulit nang wala ito, dahil may tunay na city
        // na walang "city" sa label nito ("San Carlos City": Pangasinan o Negros Occidental?).
        $tie     = fn (string $other): array => [null, self::oneLine('city "' . $c . '" ambiguous sa list: ' . $mapProv . '/' . $mapCity . ' | ' . $other . ' → tao', 1000)];
        $differs = fn (array $r): bool => $r['city_ambiguous'] || ($r['city'] !== null && ((string) $r['city'] !== $mapCity || (string) $r['province'] !== $mapProv));
        $bare    = trim(explode(',', $c)[0]);
        $bareKey = MacroChecker::normCityKey($bare);
        $withCity = str_ends_with($bareKey, ' city');
        $second  = $withCity ? trim(substr($bareKey, 0, -5)) : $bare . ' City';
        if ($second !== '') {
            $two = $ask($second);
            if ($differs($two)) {
                return $tie($two['city'] !== null ? $two['province'] . '/' . $two['city'] : 'higit sa isa kapag ' . ($withCity ? 'walang' : 'may') . ' "City"');
            }
        }

        // Ang province na isinulat sa form ay dapat ang province ng namapang city. Kapag iisa lang ang kandidato, hindi na
        // tinitingnan ng mapper ang province: "Samal" ng Davao del Norte ay nagiging SAMAL ng Bataan. Ang tanging lusot ay
        // ang tunay na filing ng list (Cotabato City ay nasa COTABATO kahit Maguindanao ang sabi ng tao).
        $saidProv = $p !== '' ? $mc->provinceLabelFromList($p, $maps) : null;
        if ($saidProv !== null && $saidProv !== $mapProv && !self::onlyCityOfItsName($mapProv, $mapCity, $maps)) {
            return $tie('province ng form: ' . $saidProv);
        }
        // Ang mapper ay pumuputol sa unang kuwit ("Jaro, Iloilo City" → "Jaro" → bayan sa Leyte). Ang bawat natirang bahagi,
        // at ang province ng form na hindi province sa list, ay pangalan din ng lugar: kapag province ito, dapat iyon ang
        // province ng namapang city; kapag city ito, dapat iyon din ang namapang city. Kung hindi, hindi alam kung alin → tao.
        $others = array_slice(array_map('trim', explode(',', $c)), 1);
        if ($p !== '' && $saidProv === null) $others[] = $p;
        foreach ($others as $part) {
            if ($part === '') continue;
            $partProv = $mc->provinceLabelFromList($part, $maps);
            if ($partProv !== null) {
                if ($partProv !== $mapProv && !self::onlyCityOfItsName($mapProv, $mapCity, $maps)) return $tie('province: ' . $partProv);
                continue;
            }
            foreach ([$part, $part . ' City'] as $wording) {
                $r = $ask($wording);
                if ($differs($r)) return $tie($r['city'] !== null ? $r['province'] . '/' . $r['city'] : '"' . $part . '" higit sa isa');
            }
        }
        if (($modelCity !== null && ($modelCity !== $mapCity || $modelProv !== $mapProv)) || ($modelCity === null && $modelProv !== null && $modelProv !== $mapProv)) {
            return [null, $note . ' — iba sa line ng model (' . $modelProv . ($modelCity !== null ? '/' . $modelCity : '') . ') → tao'];
        }

        $labels = $maps['brgysByCityProv'][MacroChecker::normPlace($mapCity) . '|' . MacroChecker::normProv($mapProv)] ?? [];
        $label  = $labels ? $mc->matchBarangayInList($b, $labels) : null;
        if ($label === null) return [null, $note . ' · barangay "' . self::oneLine($b) . '" wala o hindi iisa sa list ng ' . $mapCity];

        return [[$mapProv, $mapCity, (string) $label], $note];
    }

    /**
     * Ang label ba na ito ay isang CITY (may "city" sa label) at ang NAG-IISANG city o bayan sa buong list na ganoon ang
     * pangalan, sa anumang sulat (may province sa unahan ng label o wala, may "city" o wala)? Ito lang ang kasong
     * tinatanggap na iba ang province ng form sa province ng list: isang bayan na may kapangalan ay hindi hinuhulaan.
     */
    public static function onlyCityOfItsName(string $prov, string $city, array $maps): bool
    {
        $prefixes = ['north cotabato', 'south cotabato', 'metro manila', 'ncr'];
        foreach (($maps['provincesSet'] ?? []) as $label) $prefixes[] = MacroChecker::normCityKey((string) $label);
        $prefixes = array_values(array_unique(array_filter($prefixes)));
        usort($prefixes, fn ($a, $b) => strlen($b) <=> strlen($a));
        // Ang pangalan lang: walang province sa unahan, walang "city" sa dulo.
        $name = function (string $label) use ($prefixes): string {
            $key = MacroChecker::normCityKey($label);
            foreach ($prefixes as $pk) {
                $rest = str_starts_with($key, $pk . ' ') ? substr($key, strlen($pk) + 1) : '';
                if ($rest !== '' && $rest !== 'city') { $key = $rest; break; }
            }

            return str_ends_with($key, ' city') ? trim(substr($key, 0, -5)) : $key;
        };
        if (!str_ends_with(MacroChecker::normCityKey($city), ' city')) return false;
        $mine = $name($city);
        if ($mine === '') return false;
        $same = 0;
        foreach (($maps['citiesByProv'] ?? []) as $cities) {
            foreach ($cities as $label) {
                if ($name((string) $label) === $mine && ++$same > 1) return false;
            }
        }

        return $same === 1;
    }

    /**
     * Sinabi ba ng customer ang barangay na ito? Ang tatlong pinagmulan (chat, Pancake history na nakita ng model, mga
     * sariling block ng customer) ay pinagdudugtong ng line break, kaya walang hit na tumatawid mula sa isa papunta sa iba.
     * ['result' => phrase|compact|near|none, 'score' => int]
     *
     * Ang barangay na kapangalan ng sarili nitong city o bayan ay kumpirmado lang kapag, sa loob ng ISANG pinagmulan,
     * dalawang beses ang pangalan o may barangay word sa tabi nito: ang history ay madalas na kopya ng mismong chat,
     * kaya ang "isang beses sa chat at isang beses sa history" ay iisang banggit pa rin ng pangalan ng bayan.
     */
    public static function confirmedByText(string $brgy, string $city, string $prov, string $formBrgy, array $sources, array $maps): array
    {
        $labels = $maps['brgysByCityProv'][MacroChecker::normPlace($city) . '|' . MacroChecker::normProv($prov)] ?? [];
        $found  = AstraBarangayMatcher::confirmWithWording(implode("\n", $sources), $brgy, $formBrgy, $labels);
        if ($found['result'] === 'none' || !self::namedLikeItsCity($brgy, $city, $prov)) return $found;
        foreach ($sources as $source) {
            if (AstraBarangayMatcher::confirmWithWording((string) $source, $brgy, $formBrgy, $labels, true)['result'] !== 'none') return $found;
        }

        return ['result' => 'none', 'score' => 0];
    }

    /** Kapangalan ba ng barangay ang sarili nitong city o bayan (may province sa unahan ng label o wala, may "city" o wala)? */
    public static function namedLikeItsCity(string $brgy, string $city, string $prov): bool
    {
        $key = MacroChecker::normBrgyKey(preg_replace('/\([^)]*\)/u', ' ', $brgy) ?? $brgy);
        if ($key === '') return false;
        $name  = MacroChecker::normCityKey($city);
        $names = [$name];
        foreach ([MacroChecker::normCityKey($prov), 'north cotabato', 'south cotabato', 'metro manila', 'ncr'] as $prefix) {
            if ($prefix !== '' && str_starts_with($name, $prefix . ' ')) $names[] = substr($name, strlen($prefix) + 1);
        }
        foreach ($names as $n) {
            if ($n === $key || $n === $key . ' city') return true;
        }

        return false;
    }

    /** Isang linya: ang line break at sunod-sunod na whitespace ay isang espasyo, saka pinuputol sa $max character. */
    public static function oneLine(string $s, int $max = self::BLOCK_VALUE_MAX): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', $s) ?? ''), 0, $max);
    }

    /**
     * Patunayan na nasa list ang J&T labels na pinili ng AI; ibalik ang EKSAKTONG label mula sa list.
     * [province, city, barangay, note, mga linya ng evidence (kapag ang list ang nagtama ng province)]
     */
    public static function validateJnt(array $jnt, array $maps): array
    {
        $p = trim((string) ($jnt['province'] ?? '')); $c = trim((string) ($jnt['city'] ?? '')); $b = trim((string) ($jnt['barangay'] ?? ''));
        if ($p === '' && $c === '' && $b === '') return [null, null, null, 'walang J&T label (hindi matukoy ni Astra → tao)', []];

        $pk = MacroChecker::normProv($p);
        $provLabel = $maps['provincesSet'][$pk] ?? null;

        $cityLabel = null;
        if ($provLabel !== null) {
            foreach (($maps['citiesByProv'][$pk] ?? []) as $cl) {
                if (MacroChecker::normPlace((string) $cl) === MacroChecker::normPlace($c)) { $cityLabel = (string) $cl; break; }
            }
        }
        // Ang LIST ang masusunod sa province: kung wala/mali ang province pero IISA ang city na ito sa buong list
        // (hal. COTABATO-CITY ay nasa COTABATO kahit "Maguindanao del Norte" ang sabi ng PSA/AI), kunin ang province mula sa list.
        $note = '';
        $lines = [];
        if ($cityLabel === null && $c !== '') {
            $hits = $maps['provincesByCity'][MacroChecker::normPlace($c)] ?? [];
            if (count($hits) === 1) {
                $fixedProv = (string) $hits[0];
                $fpk = MacroChecker::normProv($fixedProv);
                foreach (($maps['citiesByProv'][$fpk] ?? []) as $cl) {
                    if (MacroChecker::normPlace((string) $cl) === MacroChecker::normPlace($c)) { $cityLabel = (string) $cl; break; }
                }
                if ($cityLabel !== null) {
                    $note = 'province ' . ($p !== '' ? '"' . $p . '"' : '(wala)') . ' → ' . $fixedProv . ' (filing ng list para sa ' . $cityLabel . ')';
                    $provLabel = $maps['provincesSet'][$fpk] ?? $fixedProv;
                    $pk = $fpk;
                    $lines[] = 'LIST: ' . $note;
                }
            }
        }
        if ($provLabel === null) return [null, null, null, 'province "' . $p . '" wala sa list', $lines];
        if ($cityLabel === null) return [$provLabel, null, null, 'city "' . $c . '" wala sa list ng ' . $provLabel, $lines];

        $brgyLabel = null;
        $target = MacroChecker::normPlace(preg_replace('/\b(barangay|brgy\.?)\b/iu', ' ', $b) ?? $b);
        foreach (($maps['brgysByCityProv'][MacroChecker::normPlace($cityLabel) . '|' . $pk] ?? []) as $bl) {
            $clean = preg_replace('/\b(barangay|brgy\.?)\b/iu', ' ', (string) $bl) ?? (string) $bl;
            if (MacroChecker::normPlace($clean) === $target) { $brgyLabel = (string) $bl; break; }
        }
        if ($brgyLabel === null) return [$provLabel, $cityLabel, null, 'barangay "' . $b . '" wala sa list ng ' . $cityLabel, $lines];

        return [$provLabel, $cityLabel, $brgyLabel, '', $lines];
    }

    /** Nabanggit ba sa text ang isang barangay label? (Roman↔digits, tanggal "barangay", "(POB.)"; compact match para sa "nabag o"↔NABAGO) */
    public static function mentions(string $hay, string $label): bool
    {
        $needle = MacroChecker::normBrgyKey(preg_replace('/\([^)]*\)/u', ' ', $label) ?? $label);
        if ($needle === '' || strlen($needle) < 2) return false;
        $h = ' ' . MacroChecker::normBrgyKey($hay) . ' ';
        if (str_contains($h, ' ' . $needle . ' ')) return true;
        $hc = str_replace(' ', '', $h); $nc = str_replace(' ', '', $needle);
        return strlen($nc) >= 5 && str_contains($hc, $nc);
    }

    /** Ang mga block ng CUSTOMER lang (tinanggal ang mga ASTRA block), huling 2 block. */
    public static function customerBlocks(string $cxd): string
    {
        $cxd = str_replace("\r\n", "\n", trim($cxd));
        if ($cxd === '') return '';
        $cxd = preg_replace('/' . preg_quote(AstraEncoder::BLOCK_MARK, '/') . '.*?\n---(\n|$)/su', '', $cxd) ?? $cxd;
        $cxd = trim($cxd);
        if ($cxd === '') return '';
        // huling 2 block (---…---)
        preg_match_all('/---\s*\n(.*?)\n---/su', $cxd, $m);
        $blocks = $m[1] ?? [];
        if (!$blocks) return mb_substr($cxd, -1500);
        return implode("\n---\n", array_slice($blocks, -2));
    }

    /** Pinakabago ang mahalaga sa Pancake history: kung mahaba, ang HULING 7000 chars ang isasama, may markang "cut". */
    public static function cutHistory(string $chat): string
    {
        $max = self::HISTORY_MAX;
        if ($chat !== '' && mb_strlen($chat, 'UTF-8') > $max) {
            $chat = "[…cut: earlier part omitted, latest messages follow]\n" . mb_substr($chat, -$max, null, 'UTF-8');
        }
        return $chat;
    }

    public static function cleanName(string $n): string
    {
        $n = trim(preg_replace('/\s+/u', ' ', $n) ?? $n);
        $n = str_replace(['Ã±', 'Ã‘'], ['ñ', 'Ñ'], $n);
        return mb_substr($n, 0, 120);
    }

    public static function normalizePhone(string $raw): ?string
    {
        $d = preg_replace('/\D+/', '', $raw) ?? '';
        if ($d === '') return null;
        if (str_starts_with($d, '63') && strlen($d) >= 12) $d = substr($d, 2);
        $d = ltrim($d, '0');
        return $d === '' ? null : $d;
    }

    public static function composeAddress(array $form): string
    {
        $norm  = fn (string $x): string => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $x) ?? $x));
        $raw   = [];
        foreach (['house_number', 'purok_sitio', 'address'] as $k) {
            $v = trim((string) ($form[$k] ?? ''));
            if ($v !== '' && $v !== '-') $raw[] = $v;
        }
        // Tanggalin ang bahaging NASA LOOB na ng ibang bahagi ("8543" at "OB Junction" ay nasa "8543 OB Junction")
        $parts = [];
        foreach ($raw as $i => $p) {
            $np = $norm($p); $drop = false;
            foreach ($raw as $j => $q) {
                if ($i === $j) continue;
                $nq = $norm($q);
                if ($nq === $np) { if ($j < $i) { $drop = true; break; } continue; }   // eksaktong kapareho → una lang ang iiwan
                if (str_contains($nq, $np)) { $drop = true; break; }                    // nasa loob ng mas mahaba
            }
            if (!$drop) $parts[] = $p;
        }
        $lm = trim((string) ($form['landmark'] ?? ''));
        $lm = trim(preg_replace('/^(near|malapit sa|malapit|sa may|sa tabi ng|tabi mismo ng|tabi mismo|tabi ng|tapat ng|tapat|harap ng|likod ng|beside|in front of|katabi ng|next to)\s+/iu', '', $lm) ?? $lm);
        if ($lm !== '' && $lm !== '-') {
            $nl = $norm($lm); $inside = false;
            foreach ($parts as $p) if (str_contains($norm($p), $nl)) { $inside = true; break; }
            if (!$inside) {
                // Bahaging nasa loob na ng landmark → alisin (iwas "Naic Public Market, near Jekep's, Naic Public Market")
                $parts = array_values(array_filter($parts, fn ($p) => !str_contains($nl, $norm($p))));
                $parts[] = 'near ' . $lm;
            }
        }
        $addr = implode(', ', $parts);
        $addr = preg_replace('/\s+/u', ' ', $addr) ?? $addr;
        return mb_substr(trim($addr, " ,"), 0, 250);
    }
}

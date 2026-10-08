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

    /**
     * $in:
     *   rules           'old' | 'new' — alin sa dalawang set ng rules (sa ngayon ay pareho ang pasya ng dalawa;
     *                   ang salita lang sa `replay` ang naiiba)
     *   answer          ang na-parse na sagot: form, jnt, intent, issues, needs_human, human_reason, confidence, evidence
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

        // Ang sariling flag ng model, bago ito patungan ng guard o ng no-line rule sa ibaba.
        $modelNeedsHuman = (bool) $ai['needs_human'];

        // ── J&T labels: PHP ang nagpapatunay na nasa list talaga ang pinili ng AI ──
        [$prov, $city, $brgy, $listNote, $listLines] = self::validateJnt((array) $ai['jnt'], $maps);
        foreach ($listLines as $line) $evidence[] = $line;
        if ($listNote !== '') $evidence[] = 'LIST: ' . $listNote;
        else $evidence[] = 'LIST: ' . $prov . ' | ' . $city . ' | ' . $brgy;
        $labelSource = ($prov !== null && $city !== null && $brgy !== null) ? 'model' : 'none';

        // GUARD: barangay na HINDI binanggit ng customer (hinula mula sa landmark/web/katabing listing) →
        // tatanggapin lang kung "high" ang confidence; kung hindi, hindi isusulat at tao ang bahala.
        $guard = ['ran' => $brgy !== null, 'result' => 'not_run', 'score' => 0];
        if ($brgy !== null) {
            $hay = $chat . "\n" . $history . "\n" . $customerBlocks;
            $inChat = self::mentions($hay, $brgy) || ($form['brgy'] !== '' && self::mentions($hay, $form['brgy']));
            $guard['result'] = $inChat ? 'confirmed' : ($ai['confidence'] !== 'high' ? 'none' : 'exempt_high_confidence');
            if (!$inChat && $ai['confidence'] !== 'high') {
                $evidence[] = 'GUARD: barangay "' . $brgy . '" hindi sinabi ng customer (hinula, ' . $ai['confidence'] . ') → hindi isinulat, tao';
                $ai['issues'][]   = 'Barangay ' . $brgy . ' ay hinula lang mula sa landmark/web (' . $ai['confidence'] . ' confidence), hindi sinabi ng customer';
                $ai['needs_human'] = true;
                if ($ai['human_reason'] === '') $ai['human_reason'] = 'kumpirmahin ang barangay (' . $brgy . '?)';
                $brgy = null;
            } elseif (!$inChat) {
                $evidence[] = 'GUARD: barangay "' . $brgy . '" hinula mula sa landmark/web, tinanggap dahil high confidence';
            }
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
        $needsHuman = $ai['needs_human'] || $ai['intent'] !== 'order';

        $gateResult = ['hard' => [], 'soft' => []];
        $proceed = false;
        $code = $statusCode;
        if ($ai['intent'] === 'cancel')            $code = 'CANCEL?';
        elseif ($ai['intent'] === 'inquiry_only')  $code = 'INQUIRY?';
        elseif ($statusCode === '✅' && $allFilled) {
            $gateResult = $gate($final, true);
            if ($needsHuman)                       { $code = 'TO FIX'; $evidence[] = 'GATE: TO FIX — Astra: ' . ($ai['human_reason'] ?: 'kailangan ng tao'); }
            elseif (!empty($gateResult['hard']))   { $code = 'TO FIX'; $evidence[] = 'GATE: TO FIX — ' . implode('; ', $gateResult['hard']); }
            elseif (!empty($gateResult['soft']))   { $code = 'TO FIX - SHOP DETAILS'; $evidence[] = 'GATE: TO FIX - SHOP DETAILS — ' . implode('; ', $gateResult['soft']); }
            else                                   { $proceed = true; $updates['STATUS'] = 'PROCEED'; }
        } elseif ($needsHuman && $ai['human_reason'] !== '') {
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
            . ($needsHuman && $ai['human_reason'] !== '' ? ' · 👤 ' . $ai['human_reason'] : '')
            . ($proceed ? ' · PROCEED' : ($code !== $statusCode ? ' · ' . $code : ''));
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
                'model_human_kind'  => 'none',
                'model_intent'      => in_array($ai['intent'], ['order', 'cancel', 'inquiry_only', 'unclear'], true) ? $ai['intent'] : 'order',
                'label_source'      => $labelSource,
                'guard'             => $guard,
                'hay_chars'         => ['chat' => mb_strlen($chat), 'history' => mb_strlen($history), 'cxd' => mb_strlen($customerBlocks)],
                'dup_phone_checked' => true,
                'list_crc'          => (int) ($in['list_crc'] ?? 0),
            ],
        ];
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

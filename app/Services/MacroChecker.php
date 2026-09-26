<?php

namespace App\Services;

use App\Models\MacroOutput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Port of the CHECKER_11_1 Google Apps Script macro into Laravel.
 *
 * Replicates the macro's per-field fix sequence:
 *   1) RESOLVE — ONE OpenAI call (web search) → real-world PSA province/city/
 *      barangay from the customer chat (NOT list-constrained; blank if unsure).
 *   2) MAP — PHP looks that up in the WHOLE J&T list: city first (labels are
 *      globally unique), the PROVINCE comes FROM THE LIST (Cotabato City →
 *      COTABATO), then barangay within that city (Roman numerals, STA./STO.,
 *      "(POB.)"). Not found / ambiguous → left for a human, never a neighbor.
 *   3) Extract FULL NAME + ADDRESS Line 1 via OpenAI (NAMEADDR step).
 *   4) Final AI verify (VERIFYK step) → writes the status code sa
 *      `APP SCRIPT CHECKER` column, plus STATUS=PROCEED if ✅.
 *
 * Reference DB: resources/views/macro_output/jnt_address.txt (43k entries,
 * format "PROVINCE|CITY|BARANGAY"). Same source as JntAddressController
 * and MacroOutputController::validateAddresses().
 *
 * Behavior matches CHECKER_11_1 1:1 — prompts copied verbatim, same model,
 * same 250ms sleep between calls, same JSON-strict parsing.
 *
 * Usage:
 *   $maps = MacroChecker::loadAddressMaps();
 *   foreach ($rowIds as $id) {
 *       $result = (new MacroChecker)->processRow($id, $maps);
 *       // $result = ['status' => 'fixed'|'partial'|'failed', 'final_code' => '✅'|...]
 *   }
 */
class MacroChecker
{
    public const MODEL          = 'gpt-5.2';
    public const SLEEP_MS       = 250;
    public const HTTP_TIMEOUT_S = 60;
    /** Timeout ng address calls na may web_search (may search sa loob ng call). */
    public const SEARCH_TIMEOUT_S = 120;

    /** Host ng request — para sa per-host blacklists/whitelist (same as Validate). */
    private ?string $host = null;
    /** Ebidensya ng kasalukuyang row (search queries/sources, dahilan, gate) → `AI EVIDENCE`. */
    private array $evidence = [];
    /** Cached validation references (blacklists, whitelist) — isang load kada processRow. */
    private ?array $valRefs = null;
    /** Token usage kada AI call (gastos) at structured trace → ai_checker_logs.detail. */
    private array $usage = [];
    private array $trace = [];
    /** Hybrid escalation: isang beses lang kada row, sa HULING pass lang (may Pancake history na). */
    private bool $allowEscalate = false;
    private bool $escalated = false;

    /**
     * Parse jnt_address.txt → in-memory lookup maps.
     *
     * Returns:
     *   [
     *     'provincesList'    => 'ABRA, AGUSAN DEL NORTE, ...' (CSV string for prompts)
     *     'provincesSet'     => ['abra' => 'ABRA', ...] (normalized key → display label)
     *     'citiesByProv'     => ['abra' => ['BANGUED', 'BUCAY', ...], ...]
     *     'brgysByCityProv'  => ['bangued|abra' => ['BARANGAY 1', ...], ...]
     *     'provincesByCity'  => ['cabanatuan city' => ['NUEVA ECIJA'], ...]
     *                          ↑ reverse lookup for the "implied province" patch
     *   ]
     *
     * Called ONCE per batch job — kept in memory, passed to processRow().
     */
    public static function loadAddressMaps(): array
    {
        $file = resource_path('views/macro_output/jnt_address.txt');
        if (!is_file($file)) {
            return [
                'provincesList'   => '',
                'provincesSet'    => [],
                'citiesByProv'    => [],
                'brgysByCityProv' => [],
                'provincesByCity' => [],
            ];
        }

        $provincesSet    = [];   // normKey => label
        $citiesByProv    = [];   // provNormKey => Set<cityLabel>
        $brgysByCityProv = [];   // cityNormKey|provNormKey => Set<brgyLabel>
        $provincesByCity = [];   // cityNormKey => Set<provLabel>

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $parts = array_map('trim', explode('|', $line));
            if (count($parts) !== 3) continue;
            if (strtolower($parts[0]) === 'province') continue;

            [$provLabel, $cityLabel, $brgyLabel] = $parts;
            if ($provLabel === '' || $cityLabel === '' || $brgyLabel === '') continue;

            $provKey = self::normProv($provLabel);
            $cityKey = self::normPlace($cityLabel);

            if (!isset($provincesSet[$provKey])) {
                $provincesSet[$provKey] = $provLabel;
            }

            $citiesByProv[$provKey][$cityLabel] = true;

            $key = $cityKey . '|' . $provKey;
            $brgysByCityProv[$key][$brgyLabel] = true;

            // Reverse: which provinces contain this city?
            $provincesByCity[$cityKey][$provLabel] = true;
        }

        // Flatten Sets to sorted arrays
        foreach ($citiesByProv as $p => $set) {
            $list = array_keys($set);
            sort($list, SORT_STRING | SORT_FLAG_CASE);
            $citiesByProv[$p] = $list;
        }
        foreach ($brgysByCityProv as $k => $set) {
            $list = array_keys($set);
            sort($list, SORT_STRING | SORT_FLAG_CASE);
            $brgysByCityProv[$k] = $list;
        }
        foreach ($provincesByCity as $c => $set) {
            $provincesByCity[$c] = array_keys($set);
        }

        $provNames = array_values($provincesSet);
        sort($provNames, SORT_STRING | SORT_FLAG_CASE);

        return [
            'provincesList'   => implode(', ', $provNames),
            'provincesSet'    => $provincesSet,
            'citiesByProv'    => $citiesByProv,
            'brgysByCityProv' => $brgysByCityProv,
            'provincesByCity' => $provincesByCity,
        ];
    }

    /**
     * Orchestrator — fix one macro_output row using the macro's full sequence.
     *
     * 2-PASS RETRY: First pass uses `all_user_input` only (short chat). Pag
     * hindi naging ✅ yung final verdict, mag-fetch ng `customers_chat` mula
     * sa `pancake_conversations` table by `fb_name` (= yung nakikita mo pag
     * cli-click mo yung "See more" link sa UI), tapos i-retry yung fix
     * sequence with extended context.
     *
     * Cost: easy rows = 5 API calls (1 pass), hard rows = 10 API calls (2 passes).
     *
     * Returns ['status' => string, 'final_code' => string|null, 'message' => string|null]
     */
    public function processRow(int $id, array $maps, ?string $host = null): array
    {
        $this->host      = $host;
        $this->evidence  = [];
        $this->valRefs   = null;
        $this->usage     = [];
        $this->trace     = ['passes' => [], 'searches' => []];
        $this->escalated = false;
        $t0 = microtime(true);

        $row = MacroOutput::find($id);
        if (!$row) {
            return $this->finish(['status' => 'failed', 'final_code' => null, 'message' => 'Row not found'], $t0);
        }

        $chat = trim((string) $row->all_user_input);
        if ($chat === '') {
            return $this->finish(['status' => 'failed', 'final_code' => null, 'message' => 'Empty all_user_input'], $t0);
        }

        $apiKey = $this->getApiKey();
        if (!$apiKey) {
            return $this->finish(['status' => 'failed', 'final_code' => null, 'message' => 'No OPENAI_API_KEY'], $t0);
        }

        // Alamin agad kung may extended chat (Pancake): ang escalation sa mas malalim na model ay
        // sa HULING pass lang (pass 2 kung meron, kung wala pass 1) at isang beses lang kada row.
        $extendedChat = $this->fetchPancakeChat((string) ($row->fb_name ?? ''));
        $hasPass2     = ($extendedChat !== '' && $extendedChat !== $chat);

        // ── PASS 1: short chat (all_user_input only) ─────────────────────
        $this->allowEscalate = !$hasPass2;
        $result = $this->runFixSequence($row, $chat, $maps, $apiKey, 1);

        if ($result['final_code'] === '✅' || !$hasPass2) {
            return $this->finish($result, $t0);
        }

        // ── PASS 2: retry with extended chat from pancake_conversations ──
        $combinedChat = $chat . "\n\n[ADDITIONAL CHAT HISTORY FROM PANCAKE]:\n" . $extendedChat;
        $row->refresh();   // makita ang updates ng pass 1
        $this->allowEscalate = true;
        $result2 = $this->runFixSequence($row, $combinedChat, $maps, $apiKey, 2);
        return $this->finish($result2, $t0);
    }

    /** Isara ang trace (models, gastos, tokens, oras) at isama sa result → ai_checker_logs (controller). */
    private function finish(array $result, float $t0): array
    {
        $in = 0; $out = 0; $searches = 0; $cost = 0.0; $models = [];
        foreach ($this->usage as $u) {
            $in += $u['in']; $out += $u['out']; $searches += $u['searches']; $cost += $u['cost'];
            $models[$u['model']] = true;
        }
        $this->trace['summary'] = [
            'models'     => array_keys($models),
            'escalated'  => $this->escalated,
            'searches'   => $searches,
            'tokens_in'  => $in,
            'tokens_out' => $out,
            'cost_usd'   => round($cost, 4),
            'elapsed_ms' => (int) round((microtime(true) - $t0) * 1000),
        ];
        $this->trace['usage']    = $this->usage;
        $this->trace['evidence'] = $this->evidence;
        $result['log'] = $this->trace;
        return $result;
    }
    /**
     * Runs the RESOLVE → MAP → NAMEADDR → PHONE → VERIFYK sequence
     * on a row using the given chat text. Persists updates. Returns the result.
     *
     * Called twice per hard row by processRow (pass 1 = short chat, pass 2 =
     * combined with pancake_conversations).
     */
    private function runFixSequence($row, string $chat, array $maps, string $apiKey, int $pass = 1): array
    {
        $updates = [];
        $tPass   = microtime(true);
        $passLog = ['pass' => $pass, 'chat_chars' => mb_strlen($chat), 'resolve' => [], 'fallbacks' => []];

        $provCur = trim((string) $row->PROVINCE);
        $cityCur = trim((string) $row->CITY);
        $brgyCur = trim((string) $row->BARANGAY);

        // ── 1. RESOLVE — ISANG AI call (may web search) ──────────────────
        // Alamin muna ang TOTOONG province/city/barangay (PSA names) mula sa chat.
        // HINDI naka-kulong sa J&T list; blank ang field kapag hindi sigurado.
        $resolved = $this->resolveAddress($chat, $provCur, $cityCur, $brgyCur, $apiKey);
        $this->sleepMs();

        // ── 2. MAP + GUARD — PHP, deterministic ──────────────────────────
        // MAP: hanapin sa BUONG J&T list (city muna → province MULA SA LIST).
        // GUARD: dapat NASA CHAT ang city (o province) na sinagot ng AI. Kung hinula lang mula
        // sa barangay/landmark, tatanggapin lang kung IISA ang city sa list na may ganoong
        // barangay. Higit sa isang kandidato = hindi sigurado = tao ang bahala.
        $mapped = $this->mapResolvedToList($resolved, $maps);
        $assess = $this->assessResolved($resolved, $mapped, $chat, $maps);
        $passLog['resolve'][] = ['model' => $resolved['_model'] ?? self::MODEL, 'answer' => $resolved, 'map' => $mapped, 'assess' => $assess];
        if ($mapped['note'] !== '') $this->evidence[] = 'MAP: ' . $mapped['note'];
        foreach ($assess['reasons'] as $r) $this->evidence[] = 'GUARD: ' . $r;

        // ── 2b. ESCALATE — mahirap na row lang: mas malalim na model, isang beses kada row ─
        $escModel = trim((string) config('services.openai.ai_checker_escalate_model', ''));
        if ($assess['uncertain'] && $escModel !== '' && $this->allowEscalate && !$this->escalated) {
            $this->escalated = true;
            $escEffort = trim((string) config('services.openai.ai_checker_escalate_effort', 'xhigh')) ?: 'xhigh';
            $this->evidence[] = 'ESCALATE: hindi sigurado → ' . $escModel . ' (' . $escEffort . ')';
            $resolved2 = $this->resolveAddress($chat, $provCur, $cityCur, $brgyCur, $apiKey, $escModel, $escEffort, 'auto');
            $mapped2   = $this->mapResolvedToList($resolved2, $maps);
            $assess2   = $this->assessResolved($resolved2, $mapped2, $chat, $maps, true);
            $passLog['resolve'][] = ['model' => $escModel, 'answer' => $resolved2, 'map' => $mapped2, 'assess' => $assess2];
            if ($mapped2['note'] !== '') $this->evidence[] = 'MAP(' . $escModel . '): ' . $mapped2['note'];
            foreach ($assess2['reasons'] as $r) $this->evidence[] = 'GUARD(' . $escModel . '): ' . $r;
            $resolved = $resolved2; $mapped = $mapped2; $assess = $assess2;
            $this->sleepMs();
        }
        $cityAccepted = $assess['city_ok'];
        $brgyAccepted = $assess['brgy_ok'];

        if ($cityAccepted && $mapped['province'] !== null && self::normProv($mapped['province']) !== self::normProv($provCur)) {
            $updates['PROVINCE'] = $mapped['province'];
        }
        if ($cityAccepted && $mapped['city'] !== null && self::normPlace($mapped['city']) !== self::normPlace($cityCur)) {
            $updates['CITY'] = $mapped['city'];
        }
        // Province lang (walang city na tinanggap) — kung EXPLICIT na nasa chat ang province
        if (!$cityAccepted && $assess['prov_ok'] && $mapped['province'] !== null
            && self::normProv($mapped['province']) !== self::normProv($provCur)) {
            $updates['PROVINCE'] = $mapped['province'];
        }
        $effectiveProv = $updates['PROVINCE'] ?? $provCur;
        $effectiveCity = $updates['CITY'] ?? $cityCur;

        // Fallback (WALANG search): NASA CHAT ang city ng resolver pero hindi nakita ang spelling sa list
        // → AI ang maghahanap sa list ng province na iyon; UNKNOWN kung wala.
        $provKey  = self::normProv($effectiveProv);
        $cityList = isset($maps['citiesByProv'][$provKey]) ? implode(', ', $maps['citiesByProv'][$provKey]) : '';
        if ($mapped['city'] === null && !empty($mapped['city_unmapped']) && $assess['city_in_chat'] && $cityList !== '') {
            $cand = $this->fixCity($chat, $effectiveProv, $cityCur, $cityList, $apiKey, (string) $resolved['city']);
            $passLog['fallbacks'][] = ['step' => 'CITYFIX', 'hint' => $resolved['city'], 'answer' => $cand];
            if ($cand && $this->cityInList($cand, $cityList)) {
                $cand = $this->canonicalizeFromList($cand, $cityList);
                if (self::normPlace($cand) !== self::normPlace($cityCur)) $updates['CITY'] = $cand;
                $effectiveCity = $cand;
                $cityAccepted  = true;
                $brgyAccepted  = trim((string) $resolved['barangay']) !== '' && count($assess['brgy_cands']) <= 1;
                $this->evidence[] = 'CITYFIX (fallback, walang search): "' . $resolved['city'] . '" → ' . $cand;
            } else {
                $this->evidence[] = 'CITYFIX (fallback): "' . $resolved['city'] . '" wala sa list ng ' . $effectiveProv . ' → tao';
            }
            $this->sleepMs();
        }

        // Barangay: deterministic match sa loob ng napiling city (Roman numerals, STA./STO., "(POB.)").
        $cityKey    = self::normPlace($effectiveCity);
        $brgyLabels = $maps['brgysByCityProv'][$cityKey . '|' . $provKey] ?? [];
        $brgyList   = $brgyLabels ? implode(', ', $brgyLabels) : '';
        $aiBrgy     = trim((string) ($resolved['barangay'] ?? ''));
        $pick       = null;
        if ($brgyList !== '' && $aiBrgy !== '' && $cityAccepted && $brgyAccepted) {
            $pick = $this->matchBarangayInList($aiBrgy, $brgyLabels);
            if ($pick !== null) {
                $this->evidence[] = 'MAP: barangay "' . $aiBrgy . '" → ' . $pick . ' (list)';
            } else {
                // Fallback (WALANG search): hanapin ang spelling ng resolver barangay sa list ng city na ito.
                $cand = $this->fixBarangay($chat, $effectiveProv, $effectiveCity, $brgyCur, $brgyList, $apiKey, $aiBrgy);
                $passLog['fallbacks'][] = ['step' => 'BRGYFIX', 'hint' => $aiBrgy, 'answer' => $cand];
                if ($cand && $this->brgyInList($cand, $brgyList)) {
                    $pick = $this->canonicalizeFromList($cand, $brgyList);
                    $this->evidence[] = 'BRGYFIX (fallback, walang search): "' . $aiBrgy . '" → ' . $pick;
                } else {
                    $this->evidence[] = 'MAP: barangay "' . $aiBrgy . '" wala sa list ng ' . $effectiveCity . ' → tao';
                }
                $this->sleepMs();
            }
            if ($pick !== null && self::normPlace($pick) !== self::normPlace($brgyCur)) $updates['BARANGAY'] = $pick;
        }
        $effectiveBrgy = $updates['BARANGAY'] ?? $brgyCur;

        // "Pag hindi sure, tao na": may ipinanukala ang AI pero tinanggihan ng guard →
        // hindi ✅ ang row kahit may laman na mula sa encoder; pipilitin ang review ng tao.
        $forceCityBad = (!$cityAccepted && (trim((string) $resolved['city']) !== '' || $resolved['confidence'] === 'low'));
        $forceBrgyBad = (!$brgyAccepted && (trim((string) $resolved['barangay']) !== '' || $resolved['confidence'] === 'low'));
        // ── 4. NAMEADDR (extract FULL NAME + ADDRESS Line 1 + PHONE) ─────
        $nameCur = trim((string) $row->{'FULL NAME'});
        $addrCur = trim((string) $row->ADDRESS);
        $nameAddr = $this->extractNameAddr($chat, $nameCur, $addrCur, $effectiveProv, $effectiveCity, $effectiveBrgy, $apiKey);
        if (!empty($nameAddr['full_name']) && strtolower($nameAddr['full_name']) !== 'unknown' && $nameCur === '') {
            $updates['FULL NAME'] = $nameAddr['full_name'];
        }
        if (!empty($nameAddr['address_line1']) && strtolower($nameAddr['address_line1']) !== 'unknown' && $addrCur === '') {
            $updates['ADDRESS'] = $nameAddr['address_line1'];
        }
        $this->sleepMs();

        // ── 4b. PHONE NUMBER ─────────────────────────────────────────────
        // Regex first (cheap), AI fallback (from NAMEADDR step) for formats
        // like '0923 422 1422' / '+63 917-123-4567' na hindi macatch ng regex.
        $phoneCur = trim((string) $row->{'PHONE NUMBER'});
        if ($phoneCur === '') {
            $phone = $this->extractPhone($chat);
            if ($phone === null && !empty($nameAddr['phone_number'])) {
                $phone = $nameAddr['phone_number']; // already normalized
            }
            // DB/Validate form: 10 digits na nagsisimula sa 9 (hal. 9468163223) — walang 0/+63.
            $phone = $this->normalizePhoneStrictForm($phone);
            if ($phone !== null && $phone !== '') {
                $updates['PHONE NUMBER'] = $phone;
            }
        }

        // ── 5. VERIFYK — HUKOM LANG (walang search, walang pagbabago) ────
        $resolverNote = '';
        if (($resolved['city'] ?? '') !== '' || ($resolved['province'] ?? '') !== '' || ($resolved['barangay'] ?? '') !== '') {
            $resolverNote = 'A previous step (with web search) read the chat as: '
                . implode(', ', array_filter([$resolved['barangay'] ?? '', $resolved['city'] ?? '', $resolved['province'] ?? '']))
                . ' [confidence: ' . ($resolved['confidence'] ?? 'low') . ']'
                . (($resolved['evidence'] ?? '') !== '' ? ' — ' . $resolved['evidence'] : '')
                . '. Treat this as a hypothesis, not proof.';
        }
        $verdict = $this->verifyAddress($chat, $effectiveProv, $effectiveCity, $effectiveBrgy, $apiKey, $resolverNote);
        if (($verdict['evidence'] ?? '') !== '') $this->evidence[] = 'VERIFYK: ' . $verdict['evidence'];
        $passLog['verify'] = $verdict;

        // ANG LIST ANG KATOTOHANAN: blank o wala sa list → hindi ok, kahit ano ang sabi ng AI.
        if ($effectiveProv === '' || !$this->provInList($effectiveProv, $maps['provincesList'])) $verdict['province_ok'] = false;
        if ($effectiveCity === '' || $cityList === '' || !$this->cityInList($effectiveCity, $cityList)) $verdict['city_ok'] = false;
        if ($effectiveBrgy === '' || $brgyList === '' || !$this->brgyInList($effectiveBrgy, $brgyList)) $verdict['barangay_ok'] = false;
        // GUARD ang huling salita: tinanggihan → hindi ok → tao ang bahala.
        if ($forceCityBad) { $verdict['city_ok'] = false;     $this->evidence[] = 'GUARD: city hindi tinanggap → hindi ✅'; }
        if ($forceBrgyBad) { $verdict['barangay_ok'] = false; $this->evidence[] = 'GUARD: barangay hindi tinanggap → hindi ✅'; }
        // ✅ IMPLIED PROVINCE PATCH — same pattern as macro's implied-city patch.
        // Kung city_ok && barangay_ok pero !province_ok, AT yung current city ay
        // unique sa current province sa jnt_address.txt, then province is implied.
        if (!empty($verdict['city_ok']) && !empty($verdict['barangay_ok']) && empty($verdict['province_ok'])) {
            $impliedProvs = $this->inferProvinceFromCity($effectiveCity, $maps);
            if (count($impliedProvs) === 1) {
                $implied = $impliedProvs[0];
                if (self::normProv($implied) === self::normProv($effectiveProv)) {
                    $verdict['province_ok'] = true;
                }
            }
        }

        $statusCode = $this->computeStatusCode($verdict);
        $updates['APP SCRIPT CHECKER'] = $statusCode;

        // ── PROCEED gate ─────────────────────────────────────────────────
        // Don't auto-PROCEED kahit ✅ ang address verify kung may blank pa
        // sa required fields. Example: Rodulfo Delposo row may chat na pure
        // "danglag condolacion cebu" (location names lang) → AI verified
        // prov/city/brgy as correct ✅, pero ADDRESS Line 1 stays blank
        // kasi walang house number/street/landmark sa chat. Hindi dapat
        // PROCEED yan since incomplete pa rin.
        $finalProv   = $updates['PROVINCE']     ?? $row->PROVINCE;
        $finalCity   = $updates['CITY']         ?? $row->CITY;
        $finalBrgy   = $updates['BARANGAY']     ?? $row->BARANGAY;
        $finalName   = $updates['FULL NAME']    ?? $row->{'FULL NAME'};
        $finalPhone  = $updates['PHONE NUMBER'] ?? $row->{'PHONE NUMBER'};
        $finalAddr   = $updates['ADDRESS']      ?? $row->ADDRESS;

        $allFilled = trim((string)$finalProv)  !== ''
                  && trim((string)$finalCity)  !== ''
                  && trim((string)$finalBrgy)  !== ''
                  && trim((string)$finalName)  !== ''
                  && trim((string)$finalPhone) !== ''
                  && trim((string)$finalAddr)  !== '';

        // ── VALIDATION GATE — PAREHONG rules ng Validate button ─────────
        // hard bagsak → TO FIX · soft (shop details) bagsak → TO FIX - SHOP DETAILS
        // · pasado → PROCEED. Ang address code (✅) ay nananatili sa pasado lang.
        $gate = ['hard' => [], 'soft' => []];
        if ($statusCode === '✅' && $allFilled) {
            $gate = $this->validateRow($row, [
                'PROVINCE'     => (string) $finalProv,  'CITY'         => (string) $finalCity,
                'BARANGAY'     => (string) $finalBrgy,  'FULL NAME'    => (string) $finalName,
                'PHONE NUMBER' => (string) $finalPhone, 'ADDRESS'      => (string) $finalAddr,
            ], $maps);

            if (!empty($gate['hard'])) {
                $updates['APP SCRIPT CHECKER'] = 'TO FIX';
                $this->evidence[] = 'GATE: TO FIX — ' . implode('; ', $gate['hard']);
            } elseif (!empty($gate['soft'])) {
                $updates['APP SCRIPT CHECKER'] = 'TO FIX - SHOP DETAILS';
                $this->evidence[] = 'GATE: TO FIX - SHOP DETAILS — ' . implode('; ', $gate['soft']);
            } else {
                $updates['STATUS'] = 'PROCEED';
            }
        }
        $proceed = ($statusCode === '✅' && $allFilled && empty($gate['hard']) && empty($gate['soft']));

        // ── AI EVIDENCE (hidden column — local dev lang; prod: ai_checker_logs.evidence) ─
        if (!empty($this->evidence) && self::hasEvidenceColumn()) {
            $updates['AI EVIDENCE'] = mb_substr(
                \Carbon\Carbon::now('Asia/Manila')->format('Y-m-d H:i') . "\n" . implode("\n", $this->evidence),
                0, 60000
            );
        }

        // ── Persist ──────────────────────────────────────────────────────
        if (!empty($updates)) {
            $row->update($updates);
        }

        // ── Trace ng pass na ito → ai_checker_logs.detail ────────────────
        $passLog['before']      = ['PROVINCE' => $provCur, 'CITY' => $cityCur, 'BARANGAY' => $brgyCur, 'FULL NAME' => $nameCur, 'PHONE NUMBER' => $phoneCur, 'ADDRESS' => $addrCur];
        $passLog['after']       = ['PROVINCE' => (string) $finalProv, 'CITY' => (string) $finalCity, 'BARANGAY' => (string) $finalBrgy, 'FULL NAME' => (string) $finalName, 'PHONE NUMBER' => (string) $finalPhone, 'ADDRESS' => (string) $finalAddr];
        $passLog['updated']     = array_values(array_diff(array_keys($updates), ['AI EVIDENCE']));
        $passLog['status_code'] = $statusCode;
        $passLog['final_code']  = $updates['APP SCRIPT CHECKER'] ?? $statusCode;
        $passLog['gate']        = $gate;
        $passLog['proceed']     = $proceed;
        $passLog['elapsed_ms']  = (int) round((microtime(true) - $tPass) * 1000);
        $this->trace['passes'][] = $passLog;

        $gateMsg = implode('; ', array_merge($gate['hard'], $gate['soft']));
        return [
            'status'     => $proceed ? 'fixed' : 'partial',
            'final_code' => $updates['APP SCRIPT CHECKER'] ?? $statusCode,
            'all_filled' => $allFilled,
            'gate'       => $gate,
            'message'    => ($statusCode === '✅' && !$allFilled)
                ? 'Address verified but may blank na required field (di pa PROCEED)'
                : (($statusCode === '✅' && !$proceed) ? 'Address ✅ pero bagsak sa validation: ' . $gateMsg : null),
        ];
    }
    /**
     * Fetch the customer's full chat history from pancake_conversations
     * (same source as the "See more" link sa /encoder/checker_1). Used as
     * extended context para sa PASS 2 retry kapag PASS 1 di na-fix nang ✅.
     *
     * Returns trimmed chat string, or '' kung walang match.
     */
    public function fetchPancakeChat(string $fbName): string
    {
        $fbName = trim($fbName);
        if ($fbName === '') return '';
        if (!Schema::hasTable('pancake_conversations')) return '';

        $chat = DB::table('pancake_conversations')
            ->where('full_name', '=', $fbName)
            ->orderByDesc('id')
            ->value('customers_chat');

        $chat = trim((string) ($chat ?? ''));

        // Cap at 8000 chars — same safety limit as MacroOutputController::pancakeMore()
        $max = 8000;
        if ($chat !== '' && mb_strlen($chat, 'UTF-8') > $max) {
            $chat = mb_substr($chat, 0, $max, 'UTF-8') . "\n\n[TRUNCATED]";
        }

        return $chat;
    }

    // ── OPENAI PROMPTS — ported 1:1 from CHECKER_11_1 ─────────────────────

    /** City fix — same as macro's CITYFIX_*. */
    public function fixCity(string $chat, string $prov, string $cityOld, string $cityList, string $apiKey, string $hint = ''): ?string
    {
        if (trim($chat) === '' || trim($cityList) === '') return null;

        $system = 'You validate Philippine cities/municipalities. Choose ONLY from the provided city list. '
                . 'The chat may mention landmarks, markets, terminals, or BUSINESS NAMES (often misspelled, e.g. "jekeps" = J-Keps Trading). '
                . 'If the city is ambiguous, use the location of the landmark/business within the given province to pick the matching list entry. '
                . 'If a RESOLVED city is given, it is the real-world name already determined — your job is only to find its spelling/label in the allowed list. '
                . 'If the customer EXPLICITLY names a city/municipality that is NOT in the allowed list, return UNKNOWN — NEVER substitute a neighboring or similar town. '
                . 'Return STRICT JSON only: {"city":"...","province":"...","evidence":"one short line: why (landmark/source) or empty"}. If truly cannot determine, return UNKNOWN.';

        $prompt = $chat . "\n\n"
            . "Task: correct the CITY only.\n"
            . "Rules:\n"
            . "- Choose ONLY from the allowed CITY list below.\n"
            . "- If the message explicitly mentions a city/municipality, you MUST pick that city if it exists in the allowed list.\n"
            . "- Do NOT keep the current city unless the message supports it.\n"
            . "- If the message contains a 'City:' field, prioritize that value.\n\n"
            . "Province: " . $prov . "\n"
            . "Current City: " . $cityOld . "\n"
            . ($hint !== '' ? "RESOLVED city (real-world name; find its label in the allowed list): " . $hint . "\n" : '') . "\n"
            . "Allowed CITY list (pick ONLY from this list):\n"
            . $cityList . "\n\n"
            . 'Return STRICT JSON only: {"city":"...","province":"..."}' . "\n";

        $raw = $this->callOpenAI($apiKey, $system, $prompt, 'FALLBACK'); // fallback lang — walang search
        $parsed = $this->parseJsonField($raw, 'city');
        if (!$parsed || strtoupper($parsed) === 'UNKNOWN') return null;
        return $parsed;
    }

    /** Barangay fix — same as macro's BRGYFIX_*. */
    public function fixBarangay(string $chat, string $prov, string $city, string $brgyOld, string $brgyList, string $apiKey, string $hint = ''): ?string
    {
        if (trim($chat) === '' || trim($brgyList) === '') return null;

        $system = 'You validate Philippine address barangays. '
                . 'The chat may mention landmarks, markets, terminals, or BUSINESS NAMES (often misspelled, e.g. "jekeps" = J-Keps Trading). '
                . 'When two or more list entries could match (e.g. "ibayo" → IBAYO ESTACION vs IBAYO SILANGAN) or only a landmark is given, '
                . 'locate that landmark/business within the given city and pick the matching list entry. '
                . 'If a RESOLVED barangay is given, it is the real-world name already determined — your job is only to find its spelling/label in the allowed list. '
                . 'Return STRICT JSON only: {"barangay":"...","city":"...","province":"...","evidence":"one short line: why (landmark/source) or empty"}. '
                . 'List entries may use ROMAN NUMERALS: "poblacion 9" = POBLACION IX, "rosary heights 12" = ROSARY HEIGHTS XII, "bagua 2" = BAGUA II. '
                . 'If a barangay list is provided, choose ONLY from that list. '
                . 'If truly cannot determine, return UNKNOWN.';

        $prompt = $chat . "\n\n"
            . "We need to correct BARANGAY only.\n"
            . "Province: " . $prov . "\n"
            . "City: " . $city . "\n"
            . "Current Barangay: " . $brgyOld . "\n"
            . ($hint !== '' ? "RESOLVED barangay (real-world name; find its label in the allowed list): " . $hint . "\n" : '') . "\n"
            . "Allowed BARANGAY list (pick ONLY from this list):\n"
            . $brgyList . "\n\n"
            . 'Return STRICT JSON only: {"barangay":"...","city":"...","province":"..."}' . "\n";

        $raw = $this->callOpenAI($apiKey, $system, $prompt, 'FALLBACK'); // fallback lang — walang search
        $parsed = $this->parseJsonField($raw, 'barangay');
        if (!$parsed || strtoupper($parsed) === 'UNKNOWN') return null;
        return $parsed;
    }

    /**
     * Name + Address Line 1 + Phone extraction — extends macro's NAMEADDR_*.
     * Added `phone_number` field for AI fallback when regex misses formats with
     * separators (spaces/dashes), per user request.
     */
    public function extractNameAddr(string $chat, string $nameOld, string $addrOld, string $prov, string $city, string $brgy, string $apiKey): array
    {
        if (trim($chat) === '') return ['full_name' => '', 'address_line1' => '', 'phone_number' => ''];

        $system = "You extract and normalize Philippine customer name, Address Line 1, and phone number from messy chat logs.\n\n"
                . "You will receive RAW_CUSTOMER_CHAT and CURRENT values.\n\n"
                . "Output STRICT JSON only:\n"
                . "{\"full_name\":\"...\",\"address_line1\":\"...\",\"phone_number\":\"...\"}\n\n"
                . "Rules for full_name:\n"
                . "- Choose the customer's real name if clearly stated (often after 'Name:' or in order confirmation).\n"
                . "- Ignore page/persona names (e.g., seller, admin) unless the customer is clearly that person.\n"
                . "- Remove emojis and extra punctuation; keep proper spacing/casing.\n\n"
                . "Rules for address_line1:\n"
                . "- This is Address Line 1 ONLY: house/lot/block, street, purok/sitio, subdivision, building, landmark, etc.\n"
                . "- DO NOT include Barangay/Brgy, City/Municipality, or Province in address_line1.\n"
                . "- If chat has only 'Brgy, City, Province' and no other details, return empty string for address_line1.\n"
                . "- Prefer details near address lines, or after words like 'address', 'landmark', 'purok', 'street', 'subd', 'blk', 'lot'.\n"
                . "- If there are conflicting address details, pick the most complete and most recent one.\n\n"
                . "Rules for phone_number:\n"
                . "- Extract ANY number that LOOKS like a Philippine mobile (even incomplete — proper validation done elsewhere).\n"
                . "- Look for 'Phone Number:' / 'cell num' / 'mobile' labels, OR any sequence like 09XXXXXXXXX / +639XXXXXXXXX / 0917XXXXXXX.\n"
                . "- Handle inline formats with spaces/dashes/parens (e.g., '0923 422 1422', '0957 955210', '+63 917-123-4567').\n"
                . "- Strip all non-digit characters, then normalize to leading 0: if +63/63 prefix replace with 0; if starts with 9 prepend 0.\n"
                . "- Accept results that are 10-12 digits starting with 0 (e.g., '0957955210', '09171234567'). Return as-is.\n"
                . "- Skip obvious placeholders like '9123456789' or '1234567890'.\n"
                . "- If no phone-like sequence found, return empty string.\n\n"
                . "Return JSON only. No extra text.";

        $prompt = "RAW_CUSTOMER_CHAT:\n<<<\n" . $chat . "\n>>>\n\n"
            . "CURRENT_FULL_NAME: " . $nameOld . "\n"
            . "CURRENT_ADDRESS_LINE1: " . $addrOld . "\n"
            . "CURRENT_PROVINCE: " . $prov . "\n"
            . "CURRENT_CITY: " . $city . "\n"
            . "CURRENT_BARANGAY: " . $brgy . "\n\n"
            . "Task:\n"
            . "1) Extract best FULL NAME.\n"
            . "2) Extract best ADDRESS LINE 1 (exclude barangay/city/province).\n"
            . "3) Extract PHONE NUMBER as 11-digit 09XXXXXXXXX (handle spaces/dashes).\n\n"
            . "Return STRICT JSON only:\n"
            . "{\"full_name\":\"...\",\"address_line1\":\"...\",\"phone_number\":\"...\"}\n";

        $raw = $this->callOpenAI($apiKey, $system, $prompt);

        $obj = $this->parseJsonObject($raw);
        $name  = isset($obj['full_name'])     ? trim((string) $obj['full_name'])     : '';
        $addr  = isset($obj['address_line1']) ? trim((string) $obj['address_line1']) : '';
        $phone = isset($obj['phone_number'])  ? trim((string) $obj['phone_number'])  : '';

        // Strip out brgy/city/prov from address_line1 (defensive, per macro)
        $addr = $this->stripLocations($addr, $brgy, $city, $prov);
        $addr = trim(preg_replace('/\s*,\s*/', ', ', preg_replace('/\s+/', ' ', $addr) ?? '') ?? '');
        $addr = trim($addr, ", \t\n\r\0\x0B");

        // Normalize AI-extracted phone — just in case AI didn't strip separators
        $phone = $this->normalizePhone($phone);

        return [
            'full_name'     => $name,
            'address_line1' => $addr,
            'phone_number'  => $phone ?? '',
        ];
    }

    /**
     * Normalize phone candidate from AI output. Lenient — accepts 10-12 digit
     * results so incomplete numbers like '0957955210' still pass through.
     * Strict format validation happens elsewhere.
     */
    private function normalizePhone(string $raw): ?string
    {
        return $this->normalizePhoneLenient($raw);
    }

    /** Address verification — same as macro's VERIFYK_*. */
    /**
     * VERIFYK — HUKOM LANG (walang web search, walang pagbabago). Hinuhusgahan kung ang
     * CURRENT prov/city/brgy (filing ng courier list) ay ang lugar na sinasabi ng customer.
     * Ang 3 booleans → computeStatusCode(); ang PHP list checks sa caller ang huling salita.
     */
    public function verifyAddress(string $chat, string $prov, string $city, string $brgy, string $apiKey, string $resolverNote = ''): array
    {
        $none = ['province_ok' => false, 'city_ok' => false, 'barangay_ok' => false, 'evidence' => ''];
        if (trim($chat) === '') return $none;

        $system = "You are a strict-but-practical Philippine address verifier. You do NOT change anything; you only judge.\n"
                . "You receive RAW_CUSTOMER_CHAT, an optional RESOLVER_NOTE (what a previous step concluded using web search) "
                . "and CURRENT_PROVINCE/CITY/BARANGAY as filed in the courier's address list.\n"
                . "IMPORTANT: the courier's filing may differ from geography or PSA naming (e.g. COTABATO-CITY is filed under COTABATO, not Maguindanao; "
                . "MAGUINDANAO covers del Norte and del Sur; Metro Manila districts like TONDO or SAMPALOC are filed as cities). "
                . "Judge whether each CURRENT value denotes the SAME place the customer means under that filing. "
                . "Do NOT mark a value wrong for geographic or naming reasons.\n"
                . "Courier labels may use Roman numerals, hyphens and suffixes such as (POB.): 'District 1' = 'DISTRICT I (POB.)', "
                . "'Poblacion 9' = 'POBLACION IX', 'Nabag-o' = 'NABAGO', 'Sta. Cruz' = 'SANTA CRUZ'. Treat these as the SAME place.\n"
                . "A value is ok when the chat or the resolver note supports it (directly, or through a landmark/business located there) and nothing contradicts it. "
                . "A value is NOT ok when the chat clearly points to a different place, or when it has no basis in the chat.\n"
                . "Output STRICT JSON only:\n"
                . '{"province_ok":true/false,"city_ok":true/false,"barangay_ok":true/false,"evidence":"one short line or empty"}';

        $prompt = "RAW_CUSTOMER_CHAT:\n<<<\n" . $chat . "\n>>>\n\n"
            . ($resolverNote !== '' ? "RESOLVER_NOTE: " . $resolverNote . "\n\n" : '')
            . "CURRENT_PROVINCE: " . $prov . "\n"
            . "CURRENT_CITY: " . $city . "\n"
            . "CURRENT_BARANGAY: " . $brgy . "\n"
            . "\nReturn STRICT JSON only:\n"
            . '{"province_ok":true/false,"city_ok":true/false,"barangay_ok":true/false,"evidence":"..."}' . "\n";

        $obj = $this->parseJsonObject($this->callOpenAI($apiKey, $system, $prompt, 'VERIFYK'));
        return [
            'province_ok' => !empty($obj['province_ok']),
            'city_ok'     => !empty($obj['city_ok']),
            'barangay_ok' => !empty($obj['barangay_ok']),
            'evidence'    => isset($obj['evidence']) ? trim((string) $obj['evidence']) : '',
        ];
    }
    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * Reverse lookup: given a city, find all provinces na may ganitong city
     * sa jnt_address.txt. Used by the implied-province patch sa VERIFYK.
     *
     * Returns list of province display labels (typically 0 or 1 entries; >1 only
     * for ambiguous cities like "San Juan" which exists in multiple provinces).
     */
    public function inferProvinceFromCity(string $cityNow, array $maps): array
    {
        $key = self::normPlace($cityNow);
        if ($key === '') return [];
        return $maps['provincesByCity'][$key] ?? [];
    }

    /** Compute the status code that goes into APP SCRIPT CHECKER. */
    public function computeStatusCode(array $verdict): string
    {
        $p = !empty($verdict['province_ok']);
        $c = !empty($verdict['city_ok']);
        $b = !empty($verdict['barangay_ok']);

        if ($p && $c && $b)        return '✅';
        if (!$b && $c && $p)       return 'Barangay';
        if ($b && !$c && $p)       return 'City';
        if ($b && $c && !$p)       return 'Province';
        if (!$b && !$c && $p)      return 'City and Barangay';
        if (!$p && !$c && $b)      return 'Province and City';
        if (!$p && $c && !$b)      return 'Province and Barangay';
        return 'Full Address';
    }

    /**
     * Lenient phone extraction — catches even incomplete formats (e.g.,
     * "0957 955210" = 10 digits missing one).
     *
     * Per user spec: extract whatever looks phone-ish, kahit invalid. Yung
     * validation (proper 11-digit 09XXXXXXXXX check) handled separately ng
     * existing Validate button + the PROCEED gate. Better na may starting
     * point para sa manual review kaysa blank.
     */
    public function extractPhone(string $chat): ?string
    {
        // First: try with separators allowed (handles "0917 123 4567" etc.)
        if (preg_match('/(?:\+?63|0)\s?9(?:[\s\-\.\(\)]?\d){7,11}/', $chat, $m)) {
            $normalized = $this->normalizePhoneLenient($m[0]);
            if ($normalized !== null) return $normalized;
        }

        // Fallback: strip whitespace then try contiguous 9-13 digit sequence
        $clean = preg_replace('/\s+/', '', $chat) ?? '';
        if (preg_match('/(?:\+?63|0)?9\d{7,11}/', $clean, $m)) {
            $normalized = $this->normalizePhoneLenient($m[0]);
            if ($normalized !== null) return $normalized;
        }

        return null;
    }

    /**
     * Normalize a phone candidate. Lenient — accepts 10-12 digit results so
     * "incomplete" numbers (10 digits starting with 09) still get extracted.
     * Validation of strict 11-digit format is done elsewhere.
     */
    private function normalizePhoneLenient(string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($digits === '') return null;

        // Strip +63 / 63 prefix → leading 0
        if (str_starts_with($digits, '639')) {
            $digits = '0' . substr($digits, 2);
        } elseif (str_starts_with($digits, '63') && strlen($digits) >= 12) {
            $digits = '0' . substr($digits, 2);
        }

        // If starts with 9, prepend 0
        if (strlen($digits) >= 9 && $digits[0] === '9') {
            $digits = '0' . $digits;
        }

        // Accept anything that ends up as 0XXXXXXXXX with length 10-12
        if (str_starts_with($digits, '0') && strlen($digits) >= 10 && strlen($digits) <= 12) {
            return $digits;
        }

        return null;
    }

    /** Call OpenAI with strict-JSON expectation. Retries once on failure. */
    public function callOpenAI(string $apiKey, string $system, string $prompt, string $step = 'NAMEADDR'): string
    {
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $res = Http::withToken($apiKey)
                    ->acceptJson()
                    ->timeout(self::HTTP_TIMEOUT_S)
                    ->post('https://api.openai.com/v1/chat/completions', [
                        'model'       => self::MODEL,
                        'temperature' => 0,
                        'messages'    => [
                            ['role' => 'system', 'content' => $system],
                            ['role' => 'user',   'content' => $prompt . "\nReturn JSON only."],
                        ],
                    ]);

                if ($res->successful()) {
                    $u = (array) data_get($res->json(), 'usage', []);
                    $this->recordUsage($step, self::MODEL, (int) ($u['prompt_tokens'] ?? 0), (int) ($u['completion_tokens'] ?? 0), 0, 0);
                    return trim((string) data_get($res->json(), 'choices.0.message.content', ''));
                }

                Log::warning('MACRO_CHECKER_OPENAI_HTTP', [
                    'attempt' => $attempt,
                    'status'  => $res->status(),
                    'body'    => substr($res->body(), 0, 400),
                ]);
            } catch (\Throwable $e) {
                Log::warning('MACRO_CHECKER_OPENAI_EX', [
                    'attempt' => $attempt,
                    'error'   => $e->getMessage(),
                ]);
            }

            if ($attempt === 1) usleep(800 * 1000); // 800ms before retry
        }
        return '';
    }
    private function parseJsonField(string $raw, string $field): ?string
    {
        $obj = $this->parseJsonObject($raw);
        if (!isset($obj[$field])) return null;
        $v = trim((string) $obj[$field]);
        return $v === '' ? null : $v;
    }

    private function parseJsonObject(string $raw): array
    {
        $s = trim($raw);
        if ($s === '') return [];

        // Strip ```json fences if present
        $s = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $s) ?? $s;

        $decoded = json_decode($s, true);
        if (is_array($decoded)) return $decoded;

        if (preg_match('/\{[\s\S]*\}/', $s, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) return $decoded;
        }
        return [];
    }

    private function stripLocations(string $addr, string $brgy, string $city, string $prov): string
    {
        $out = $addr;
        foreach ([$brgy, $city, $prov] as $p) {
            $p = trim($p);
            if ($p === '') continue;
            $escaped = preg_quote($p, '/');
            $out = preg_replace('/\b' . $escaped . '\b/iu', ' ', $out) ?? $out;
        }
        $out = preg_replace('/\b(barangay|brgy)\b/iu', ' ', $out) ?? $out;
        $out = preg_replace('/\b(city|city of|municipality)\b/iu', ' ', $out) ?? $out;
        $out = preg_replace('/\b(province|prov)\b/iu', ' ', $out) ?? $out;
        return trim($out);
    }

    public function provInList(string $val, string $listCsv): bool
    {
        $target = self::normProv($val);
        foreach (explode(',', $listCsv) as $p) {
            if (self::normProv($p) === $target) return true;
        }
        return false;
    }

    public function cityInList(string $val, string $listCsv): bool
    {
        $target = self::normPlace($val);
        foreach (explode(',', $listCsv) as $c) {
            if (self::normPlace($c) === $target) return true;
        }
        return false;
    }

    public function brgyInList(string $val, string $listCsv): bool
    {
        $target = self::normPlace(preg_replace('/\b(barangay|brgy\.?)\b/iu', ' ', $val) ?? $val);
        if ($target === '') return false;
        foreach (explode(',', $listCsv) as $b) {
            $clean = preg_replace('/\b(barangay|brgy\.?)\b/iu', ' ', $b) ?? $b;
            if (self::normPlace($clean) === $target) return true;
        }
        return false;
    }

    public static function normPlace(string $v): string
    {
        $s = mb_strtolower(trim($v), 'UTF-8');
        $s = str_replace(['ñ'], 'n', $s);
        $s = preg_replace('/[.,]/u', ' ', $s) ?? $s;
        $s = preg_replace('/[\-\/]/u', ' ', $s) ?? $s;
        $s = preg_replace('/\bcity of\b/u', ' ', $s) ?? $s;
        $s = preg_replace('/\bprovince of\b/u', ' ', $s) ?? $s;
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return trim($s);
    }

    public static function normProv(string $v): string
    {
        $s = self::normPlace($v);
        if ($s === '') return '';
        // Collapse Metro Manila / NCR variants → 'manila' (same as macro)
        if ($s === 'ncr'
            || str_contains($s, 'metro manila')
            || str_contains($s, 'metro-manila')
            || str_contains($s, 'national capital region')
        ) {
            return 'manila';
        }
        return $s;
    }

    private function sleepMs(): void
    {
        usleep(self::SLEEP_MS * 1000);
    }

    private function getApiKey(): ?string
    {
        return config('services.openai.key') ?: env('OPENAI_API_KEY');
    }

    /** Host ng request (per-host blacklists) — ginagamit ng AstraEncoder bago tumawag ng validateRow(). */
    public function setHost(?string $host): void
    {
        $this->host = $host;
    }
    // ═════════════════════════════════════════════════════════════════════
    //  WEB SEARCH (Responses API) · VALIDATION GATE · EVIDENCE · PHONE FORM
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Address call na may `web_search` — SAME model (gpt-5.2), Responses API.
     * Ibinabalik ang output text (JSON string) para pareho ang contract ng
     * callOpenAI(). Nire-record sa $this->evidence ang search queries, sources,
     * at ang 'evidence' field ng JSON. tool_choice = config
     * services.openai.ai_checker_search: required | auto | off (lumang gawi).
     * Kapag nag-fail ang search call → fallback sa callOpenAI() (hindi mamamatay ang row).
     */
    private function callSearch(string $apiKey, string $system, string $prompt, string $step,
                                ?string $model = null, ?string $effort = null, ?string $toolChoice = null): string
    {
        $mode = strtolower(trim((string) config('services.openai.ai_checker_search', 'required')));
        if ($mode === 'off') return $this->callOpenAI($apiKey, $system, $prompt, $step);

        $useModel = $model ?: self::MODEL;
        $payload = [
            'model'        => $useModel,
            'instructions' => $system,
            'input'        => $prompt . "\nReturn JSON only.",
            'reasoning'    => ['effort' => $effort ?: 'low'],
            'tools'        => [['type' => 'web_search']],
            'tool_choice'  => $toolChoice ?: ($mode === 'auto' ? 'auto' : 'required'),
            'include'      => ['web_search_call.action.sources'],
            'store'        => false,
        ];

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $res = Http::withToken($apiKey)
                    ->acceptJson()
                    ->timeout($model ? 300 : self::SEARCH_TIMEOUT_S)
                    ->post('https://api.openai.com/v1/responses', $payload);

                if ($res->successful()) {
                    $j   = $res->json();
                    $out = collect($j['output'] ?? []);
                    $text = trim((string) ($j['output_text'] ?? ''));
                    if ($text === '') {
                        $text = trim($out->where('type', 'message')
                            ->flatMap(fn ($m) => collect($m['content'] ?? [])->where('type', 'output_text')->pluck('text'))
                            ->implode(''));
                    }

                    // Ebidensya: searches + queries + sources + 'evidence' ng JSON
                    $calls = $out->where('type', 'web_search_call');
                    $queries = []; $urls = [];
                    foreach ($calls as $c) {
                        $a  = (array) ($c['action'] ?? []);
                        $qs = isset($a['queries']) ? (array) $a['queries'] : (isset($a['query']) ? [(string) $a['query']] : []);
                        foreach ($qs as $q) if ((string) $q !== '') $queries[] = (string) $q;
                        foreach ((array) ($a['sources'] ?? []) as $s) if (!empty($s['url'])) $urls[] = (string) $s['url'];
                    }
                    $urls = array_values(array_unique($urls));
                    $obj  = $this->parseJsonObject($text);
                    $ev   = isset($obj['evidence']) ? trim((string) $obj['evidence']) : '';
                    if ($calls->count() > 0 || $ev !== '') {
                        $n = $calls->count();
                        $this->evidence[] = $step . ': ' . $n . ' search' . ($n === 1 ? '' : 'es')
                            . ($queries ? ' [' . implode(' | ', array_slice($queries, 0, 3)) . ']' : '')
                            . ($ev !== '' ? ' — ' . $ev : '')
                            . ($urls ? ' — ' . implode(' ', array_slice($urls, 0, 3)) : '');
                    }
                    $u = (array) ($j['usage'] ?? []);
                    $this->recordUsage($step, $useModel, (int) ($u['input_tokens'] ?? 0), (int) ($u['output_tokens'] ?? 0),
                        (int) ($u['output_tokens_details']['reasoning_tokens'] ?? 0), $calls->count());
                    $this->trace['searches'][] = ['step' => $step, 'model' => $useModel, 'queries' => $queries, 'sources' => $urls];
                    return $text;
                }

                Log::warning('MACRO_CHECKER_SEARCH_HTTP', ['step' => $step, 'attempt' => $attempt, 'status' => $res->status(), 'body' => substr($res->body(), 0, 400)]);
                // Hindi tinatanggap ang reasoning param ng model → subukan nang wala
                if ($attempt === 1 && $res->status() === 400 && isset($payload['reasoning']) && str_contains($res->body(), 'reasoning')) {
                    unset($payload['reasoning']);
                }
            } catch (\Throwable $e) {
                Log::warning('MACRO_CHECKER_SEARCH_EX', ['step' => $step, 'attempt' => $attempt, 'error' => $e->getMessage()]);
            }
            if ($attempt === 1) usleep(800 * 1000);
        }

        $this->evidence[] = $step . ': search call failed — fallback sa walang search';
        return $this->callOpenAI($apiKey, $system, $prompt, $step);
    }
    /** Ibalik ang EKSAKTONG label mula sa CSV list na tumutugma (normalized). */
    private function canonicalizeFromList(string $val, string $listCsv): string
    {
        $key = self::normPlace($val);
        foreach (explode(',', $listCsv) as $item) {
            $item = trim($item);
            if ($item !== '' && self::normPlace($item) === $key) return $item;
        }
        return trim($val);
    }

    /** Anyo ng DB/Validate: 10 digits na nagsisimula sa 9 (hal. 9468163223). Tinatanggal ang +63/63/0. */
    private function normalizePhoneStrictForm(?string $raw): ?string
    {
        if ($raw === null) return null;
        $d = preg_replace('/\D+/', '', $raw) ?? '';
        if ($d === '') return null;
        if (str_starts_with($d, '63') && strlen($d) >= 12) $d = substr($d, 2);
        $d = ltrim($d, '0');
        return $d === '' ? null : $d;
    }

    private static ?bool $evidenceCol = null;
    private static function hasEvidenceColumn(): bool
    {
        if (self::$evidenceCol === null) {
            try { self::$evidenceCol = Schema::hasColumn('macro_output', 'AI EVIDENCE'); }
            catch (\Throwable $e) { self::$evidenceCol = false; }
        }
        return self::$evidenceCol;
    }

    /**
     * PAREHONG rules ng Validate button (MacroOutputController::validateCheckerToFix).
     * Returns ['hard' => [dahilan...], 'soft' => [dahilan...]].
     *   hard: prov/city/brgy sa list (hierarchy), FULL NAME (letra/., -' lang; may titik;
     *         Ã±→ñ normalized), PHONE (^9\d{9}$, hindi dummy, hindi duplicate sa
     *         parehong petsa maliban kung whitelisted), ITEM (<=50, hindi blangko),
     *         COD, fb_name blacklist, keyword blacklist (all_user_input),
     *         ADDRESS (hindi blangko, walang address-keyword blacklist)
     *   soft: SHOP DETAILS item/COD mismatch
     */
    public function validateRow($row, array $final, array $maps): array
    {
        $hard = []; $soft = [];
        $refs = $this->valRefs ??= $this->loadValidationRefs();

        // hierarchy vs jnt_address.txt (same maps na ginagamit ng fix steps)
        $provKey = self::normProv($final['PROVINCE']);
        $cityKey = self::normPlace($final['CITY']);
        $brgyKey = self::normPlace($final['BARANGAY']);
        $provOk  = $provKey !== '' && isset($maps['provincesSet'][$provKey]);
        $cityOk  = $provOk && $cityKey !== ''
            && collect($maps['citiesByProv'][$provKey] ?? [])->contains(fn ($c) => self::normPlace($c) === $cityKey);
        $brgyOk  = $cityOk && $brgyKey !== ''
            && collect($maps['brgysByCityProv'][$cityKey . '|' . $provKey] ?? [])->contains(fn ($b) => self::normPlace($b) === $brgyKey);
        if (!$provOk)            $hard[] = 'PROVINCE wala sa J&T list';
        if ($provOk && !$cityOk) $hard[] = 'CITY wala sa list ng province';
        if ($cityOk && !$brgyOk) $hard[] = 'BARANGAY wala sa list ng city';

        // FULL NAME (normalize ang sirang enye bago i-check)
        $name = trim(str_replace(['Ã±', 'Ã‘'], ['ñ', 'Ñ'], $final['FULL NAME']));
        if ($name === '')                                            $hard[] = 'FULL NAME blangko';
        elseif (!preg_match("/^[\\p{L}\\.,\\-\\' ]+$/u", $name))    $hard[] = 'FULL NAME may di-pinapayagang character';
        elseif (!preg_match('/[A-Za-zÑñ]/u', $name))                 $hard[] = 'FULL NAME walang letra';

        // PHONE
        $phone = trim($final['PHONE NUMBER']);
        if ($phone === '')                                 $hard[] = 'PHONE blangko';
        elseif (!preg_match('/^9\d{9}$/', $phone))         $hard[] = 'PHONE hindi 10-digit na 9XXXXXXXXX (' . $phone . ')';
        elseif ($phone === '9123456789')                   $hard[] = 'PHONE dummy';
        elseif (!isset($refs['whitelist'][$phone]) && $this->duplicatePhoneCount($row, $phone) > 0)
                                                           $hard[] = 'PHONE duplicate sa parehong petsa';

        // ITEM + COD
        $item = trim((string) ($row->ITEM_NAME ?? ''));
        $cod  = trim((string) ($row->COD ?? ''));
        if ($item === '' || mb_strlen($item, 'UTF-8') > 50) $hard[] = 'ITEM blangko o >50 chars';
        if ($cod === '')                                    $hard[] = 'COD blangko';

        // blacklists
        $fb = mb_strtolower(trim((string) ($row->fb_name ?? '')));
        if ($fb !== '' && in_array($fb, $refs['fbname'], true)) $hard[] = 'FB name blacklisted';
        $aui = mb_strtolower((string) ($row->all_user_input ?? ''));
        foreach ($refs['keyword'] as $kw) { if ($kw !== '' && str_contains($aui, $kw)) { $hard[] = 'keyword blacklisted: ' . $kw; break; } }

        // ADDRESS
        $addr = trim($final['ADDRESS']);
        if ($addr === '') $hard[] = 'ADDRESS blangko';
        else { $al = mb_strtolower($addr); foreach ($refs['addrkw'] as $akw) { if ($akw !== '' && str_contains($al, $akw)) { $hard[] = 'ADDRESS keyword blacklisted: ' . $akw; break; } } }

        // SOFT — SHOP DETAILS mismatch (same as Validate)
        $shopText = trim((string) ($row->{'SHOP DETAILS'} ?? ''));
        if ($shopText === '') $shopText = (string) ($row->all_user_input ?? '');
        $details       = $this->extractShopDetailsV($shopText);
        $expectedItem  = $this->normItemV(trim((string) ($details['item'] ?? '')));
        $expectedCod   = (int) ($details['expected_cod'] ?? 0);
        $actualItem    = $this->normItemV((string) preg_replace('/^\s*\d+\s*x\s*/iu', '', $item));
        $actualCod     = $this->codToIntV($cod);
        if ($expectedItem !== '' && $actualItem !== '' && $expectedItem !== $actualItem) $soft[] = 'ITEM ≠ shop details (' . $expectedItem . ' vs ' . $actualItem . ')';
        if ($expectedCod > 0 && $actualCod > 0 && $expectedCod !== $actualCod)          $soft[] = 'COD ≠ shop details (' . $expectedCod . ' vs ' . $actualCod . ')';

        return ['hard' => $hard, 'soft' => $soft];
    }

    /** Blacklists + whitelist — per host (same scope rule ng Validate: 'incepxion' o 'likha'). */
    private function loadValidationRefs(): array
    {
        $host  = (string) ($this->host ?? '');
        $scope = str_contains(strtolower($host), 'incepxion') ? 'incepxion' : 'likha';
        $refs  = ['fbname' => [], 'keyword' => [], 'addrkw' => [], 'whitelist' => []];
        $lower = fn ($c) => $c->map(fn ($v) => mb_strtolower(trim((string) $v)))->filter(fn ($v) => $v !== '')->values()->all();
        try { if (class_exists(\App\Models\FbnameBlacklist::class))         $refs['fbname']  = $lower(\App\Models\FbnameBlacklist::where('host_scope', $scope)->pluck('fb_name')); } catch (\Throwable $e) {}
        try { if (class_exists(\App\Models\KeywordBlacklist::class))        $refs['keyword'] = $lower(\App\Models\KeywordBlacklist::where('host_scope', $scope)->pluck('keyword')); } catch (\Throwable $e) {}
        try { if (class_exists(\App\Models\AddressKeywordBlacklist::class)) $refs['addrkw']  = $lower(\App\Models\AddressKeywordBlacklist::where('host_scope', $scope)->pluck('keyword')); } catch (\Throwable $e) {}
        try { if (class_exists(\App\Models\PhoneWhitelist::class))          $refs['whitelist'] = array_flip((array) \App\Models\PhoneWhitelist::phonesForHost($host)); } catch (\Throwable $e) {}
        return $refs;
    }

    /** Duplicate phone sa PAREHONG petsa ng row (ts_date o TIMESTAMP), excluding CANNOT PROCEED at ang row mismo. */
    private function duplicatePhoneCount($row, string $phone): int
    {
        try {
            $q = MacroOutput::query()->where('id', '<>', (int) $row->id)->where('PHONE NUMBER', $phone)
                ->where(function ($s) { $s->whereNull('STATUS')->orWhere('STATUS', '<>', 'CANNOT PROCEED'); });
            $date = null;
            if (!empty($row->ts_date)) {
                try { $date = \Carbon\Carbon::parse((string) $row->ts_date, 'Asia/Manila')->toDateString(); } catch (\Throwable $e) {}
            }
            if ($date) {
                $tsType = null; try { $tsType = Schema::getColumnType('macro_output', 'ts_date'); } catch (\Throwable $e) {}
                if ($tsType === 'date') $q->where('ts_date', '=', $date);
                else $q->whereBetween('ts_date', [$date . ' 00:00:00', $date . ' 23:59:59']);
            } else {
                $ts = (string) ($row->TIMESTAMP ?? '');
                $dmy = strlen($ts) >= 10 ? substr($ts, -10) : '';
                if ($dmy === '') return 0;
                $q->where('TIMESTAMP', 'LIKE', '%' . $dmy . '%');
            }
            return (int) $q->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    // ── kopya ng Validate helpers (extractShopDetails / normItem / codToInt) ──
    private function extractShopDetailsV(string $text): array
    {
        $item = ''; $price = 0; $qty = 1;
        if (preg_match('/\bITEM\s*:\s*(.+?)(\r?\n|$)/iu', $text, $m)) $item = trim((string) $m[1]);
        if (preg_match('/\bPRICE\s*:\s*₱?\s*([\d,]+(?:\.\d+)?)(\r?\n|$)/iu', $text, $m)) $price = (int) round((float) str_replace(',', '', (string) $m[1]));
        if (preg_match('/\bQUANTITY\s*:\s*(\d+)(\r?\n|$)/iu', $text, $m)) $qty = max(1, (int) $m[1]);
        return ['item' => $item, 'price' => $price, 'qty' => $qty, 'expected_cod' => $price];
    }
    private function normItemV(string $s): string
    {
        $s = mb_strtoupper(trim($s), 'UTF-8');
        $s = preg_replace('/\s+/u', ' ', $s);
        $s = preg_replace('/[^\p{L}\p{N} ]+/u', '', $s);
        return trim((string) $s);
    }
    private function codToIntV(string $s): int
    {
        $digits = preg_replace('/[^\d]/', '', $s);
        return $digits === '' ? 0 : (int) $digits;
    }

    // ── RESOLVE → MAP (2026-09-26) ────────────────────────────────────────

    /**
     * RESOLVE — alamin ang TOTOONG address (PSA/PSGC names) mula sa chat gamit ang
     * ISANG AI call na may web search (callSearch → AI_CHECKER_SEARCH mode).
     * HINDI naka-kulong sa J&T list — ang list ay hahanapin ng mapResolvedToList().
     * Blank ('') ang field kapag hindi sigurado: walang hulaan, walang kapit-bahay.
     */
    public function resolveAddress(string $chat, string $provCur, string $cityCur, string $brgyCur, string $apiKey,
                                   ?string $model = null, ?string $effort = null, ?string $toolChoice = null): array
    {
        $empty = ['province' => '', 'province_aliases' => [], 'city' => '', 'city_candidates' => [], 'barangay' => '', 'barangay_candidates' => [],
                  'confidence' => 'low', 'evidence' => '', '_model' => $model ?: self::MODEL];
        if (trim($chat) === '') return $empty;

        $system = "You resolve Philippine delivery addresses from raw customer chat.\n"
            . "Determine the REAL-WORLD official province, city/municipality and barangay (PSA/PSGC names) that the customer means. "
            . "Use web search for landmarks, markets, terminals, subdivisions, schools and BUSINESS NAMES (often misspelled, e.g. \"jekeps\" = J-Keps Trading), "
            . "and to confirm which barangay a landmark belongs to.\n"
            . "Rules:\n"
            . "- Return \"\" for any field you cannot determine with confidence. NEVER guess a neighboring or similar-sounding town/barangay.\n"
            . "- If the customer explicitly names a city/municipality or barangay, use exactly that place.\n"
            . "- city_candidates / barangay_candidates: the places that REMAIN plausible after your research, including the one you chose. "
            . "Put exactly ONE entry when the evidence settles it (e.g. a business/landmark listing places it in a specific barangay). "
            . "Put several ONLY when you genuinely cannot decide (the same barangay name exists in several towns and the chat names no city, "
            . "or sources disagree about which barangay a subdivision/landmark is in) — then never silently pick one.\n"
            . "- province: the geographic province. Highly urbanized/independent cities still get the province they are geographically in "
            . "(e.g. Cotabato City -> Maguindanao del Norte, Davao City -> Davao del Sur).\n"
            . "- province_aliases: ALL other names that province is or was known by, including pre-split and colloquial names, "
            . "e.g. [\"Maguindanao\",\"Cotabato\",\"North Cotabato\"] for Cotabato City; [\"Compostela Valley\"] for Davao de Oro; "
            . "[\"Western Samar\"] for Samar; [\"NCR\",\"National Capital Region\"] for Metro Manila.\n"
            . "- Metro Manila: province = \"Metro Manila\". For the City of Manila put the DISTRICT in city when known "
            . "(Tondo, Sampaloc, Ermita, Paco, Quiapo, Binondo, Santa Cruz, San Miguel, San Nicolas, Santa Ana, Santa Mesa, Malate, Intramuros, Pandacan, Port Area, San Andres); otherwise city = \"Manila\".\n"
            . "- barangay: official name without the prefix Barangay/Brgy; write numbers as digits (\"Poblacion 9\", \"Zone 5\", \"176\").\n"
            . "- CURRENT_* values were typed by an encoder and MAY BE WRONG; treat them only as weak hints.\n"
            . "- confidence: high | medium | low for the whole answer (low = a human should decide).\n"
            . "Output STRICT JSON only:\n"
            . '{"province":"...","province_aliases":["..."],"city":"...","city_candidates":["..."],"barangay":"...","barangay_candidates":["..."],"confidence":"high|medium|low","evidence":"one short line: why (landmark/source) or empty"}';

        $prompt = "RAW_CUSTOMER_CHAT:\n<<<\n" . $chat . "\n>>>\n\n"
            . "CURRENT_PROVINCE: " . $provCur . "\n"
            . "CURRENT_CITY: " . $cityCur . "\n"
            . "CURRENT_BARANGAY: " . $brgyCur . "\n\n"
            . "Return STRICT JSON only:\n"
            . '{"province":"...","province_aliases":["..."],"city":"...","city_candidates":["..."],"barangay":"...","barangay_candidates":["..."],"confidence":"high|medium|low","evidence":"..."}' . "\n";

        $step = $model ? 'RESOLVE(' . $model . ')' : 'RESOLVE';
        $raw  = $this->callSearch($apiKey, $system, $prompt, $step, $model, $effort, $toolChoice);
        $obj  = $this->parseJsonObject($raw);
        if (!$obj) { $this->evidence[] = $step . ': walang sagot/JSON → tao'; return $empty; }

        $clean = static function ($v): string {
            $v = trim((string) $v);
            return in_array(strtoupper($v), ['UNKNOWN', 'N/A', 'NA', 'NULL', 'NONE', '-'], true) ? '' : $v;
        };
        $list = static function ($arr) use ($clean): array {
            $o = [];
            foreach ((array) $arr as $a) { $a = $clean($a); if ($a !== '' && !in_array($a, $o, true)) $o[] = $a; }
            return $o;
        };
        $conf = strtolower(trim((string) ($obj['confidence'] ?? 'low')));
        if (!in_array($conf, ['high', 'medium', 'low'], true)) $conf = 'low';

        $out = [
            'province'            => $clean($obj['province'] ?? ''),
            'province_aliases'    => $list($obj['province_aliases'] ?? []),
            'city'                => $clean($obj['city'] ?? ''),
            'city_candidates'     => $list($obj['city_candidates'] ?? []),
            'barangay'            => $clean($obj['barangay'] ?? ''),
            'barangay_candidates' => $list($obj['barangay_candidates'] ?? []),
            'confidence'          => $conf,
            'evidence'            => trim((string) ($obj['evidence'] ?? '')),
            '_model'              => $model ?: self::MODEL,
        ];
        if ($out['city'] !== '' && !in_array($out['city'], $out['city_candidates'], true)) $out['city_candidates'][] = $out['city'];
        if ($out['barangay'] !== '' && !in_array($out['barangay'], $out['barangay_candidates'], true)) $out['barangay_candidates'][] = $out['barangay'];

        $this->evidence[] = $step . ': ' . implode(', ', array_filter([$out['barangay'], $out['city'], $out['province']]))
            . ' [' . $conf . ']'
            . (count($out['city_candidates']) > 1 ? ' city?=' . implode('/', $out['city_candidates']) : '')
            . (count($out['barangay_candidates']) > 1 ? ' brgy?=' . implode('/', $out['barangay_candidates']) : '')
            . ($out['province_aliases'] ? ' aliases=' . implode('/', $out['province_aliases']) : '');
        return $out;
    }
    /**
     * MAP — hanapin sa BUONG J&T list ang katumbas ng resolved (real-world) address.
     * City muna: globally unique ang city labels (maliban PANDAN) dahil may province
     * prefix ang mga paulit-ulit (BATANGAS-SAN-JOSE, NORTH-COTABATO-CARMEN). Isa lang
     * ang tumugma → ang PROVINCE ay MULA SA LIST (Cotabato City → COTABATO). Marami →
     * pipili gamit ang province/aliases ng resolver. Wala o ambiguous pa rin → null
     * (tao ang bahala; walang kapit-bahay na kapalit).
     * Returns ['province'=>?label,'city'=>?label,'note'=>string,'city_unmapped'=>bool,'city_ambiguous'=>bool]
     */
    public function mapResolvedToList(array $resolved, array $maps): array
    {
        $out = ['province' => null, 'city' => null, 'note' => '', 'city_unmapped' => false, 'city_ambiguous' => false];
        if (($resolved['confidence'] ?? 'low') === 'low') {
            $out['note'] = 'resolver low confidence → walang ilalagay, tao ang bahala';
            return $out;
        }

        $aiProv    = trim((string) ($resolved['province'] ?? ''));
        $aiCity    = trim((string) ($resolved['city'] ?? ''));
        $provNames = array_values(array_unique(array_filter(
            array_map(fn ($p) => trim((string) $p), array_merge([$aiProv], (array) ($resolved['province_aliases'] ?? []))),
            fn ($p) => $p !== ''
        )));

        // Mga province label sa LIST na tumutugma sa sagot/aliases ng resolver
        $provLabels = [];
        foreach ($provNames as $p) { $l = $this->provinceLabelFromList($p, $maps); if ($l !== null) $provLabels[$l] = true; }
        $provLabels = array_keys($provLabels);

        if ($aiCity !== '') {
            $idx  = self::cityIndex($maps);
            $key  = self::normCityKey(str_contains($aiCity, ',') ? trim(explode(',', $aiCity)[0]) : $aiCity);   // "Sampaloc, Manila" → "Sampaloc"
            $alt  = str_ends_with($key, ' city') ? trim(substr($key, 0, -5)) : $key . ' city';
            $prefixes = [];
            foreach (array_merge($provNames, $provLabels) as $p) { $pk = self::normCityKey((string) $p); if ($pk !== '') $prefixes[] = $pk; }
            // Tier 1 = eksaktong salita ng resolver; Tier 2 = ±"city" variant (kung walang tumugma sa tier 1)
            $cands = [];
            foreach (array_unique([$key, $alt]) as $k) {
                if ($k === '') continue;
                $cands = [];
                $take  = static function (array $hits) use (&$cands): void {
                    foreach ($hits as $id => $c) {
                        if (!isset($cands[$id]) || $c['rank'] < $cands[$id]['rank']) $cands[$id] = $c;
                    }
                };
                $take($idx[$k] ?? []);
                // Anyong "<province> <city>" (hal. BATANGAS-SAN-JOSE, METRO-MANILA-SAN-JUAN)
                foreach ($prefixes as $pk) $take($idx[$pk . ' ' . $k] ?? []);
                // 1) province ng resolver ang pumipili kapag marami
                if (count($cands) > 1 && $provLabels) {
                    $f = array_filter($cands, fn ($c) => in_array($c['prov'], $provLabels, true));
                    if (count($f) >= 1) $cands = $f;
                }
                // 2) tunay na label (rank 0/1) ang mas matimbang sa gawa-gawang "±city" variant (rank 2)
                if (count($cands) > 1) {
                    $f = array_filter($cands, fn ($c) => $c['rank'] < 2);
                    if (count($f) >= 1) $cands = $f;
                }
                if (count($cands) >= 1) break;   // may sagot (isa o ambiguous) sa tier na ito
            }
            if (count($cands) === 1) {
                $c = array_values($cands)[0];
                $out['city']     = $c['city'];
                $out['province'] = $c['prov'];
                $out['note']     = '"' . $aiCity . '" → ' . $c['city'] . ', ' . $c['prov'] . ' (list)'
                    . (($aiProv !== '' && self::normProv($aiProv) !== self::normProv($c['prov'])) ? ' — province follows the LIST, not "' . $aiProv . '"' : '');
                return $out;
            }
            if (count($cands) > 1) {
                $out['city_ambiguous'] = true;
                $out['note'] = 'city "' . $aiCity . '" ambiguous sa list: '
                    . implode(' | ', array_map(fn ($c) => $c['prov'] . '/' . $c['city'], array_values($cands))) . ' → tao';
            } else {
                $out['city_unmapped'] = true;
                $out['note'] = 'city "' . $aiCity . '" wala sa list' . ($aiProv !== '' ? ' (' . $aiProv . ')' : '');
            }
        } else {
            $out['note'] = 'resolver walang city → tao';
        }

        // Walang city map: province lang kung malinaw
        if (count($provLabels) === 1)                     $out['province'] = $provLabels[0];
        elseif (count($provLabels) > 1 && $aiProv !== '') $out['province'] = $this->provinceLabelFromList($aiProv, $maps);
        return $out;
    }

    /** Real-world province name → EKSAKTONG label sa list (alias table para sa filing ng courier). */
    public function provinceLabelFromList(string $name, array $maps): ?string
    {
        $k = self::normProv(self::expandAbbr(self::normPlace($name)));
        if ($k === '') return null;
        static $alias = [
            'maguindanao del norte' => 'maguindanao', 'maguindanao del sur' => 'maguindanao', 'shariff kabunsuan' => 'maguindanao',
            'north cotabato'        => 'cotabato',    'cotabato province'   => 'cotabato',
            'compostela valley'     => 'davao de oro', 'samar'              => 'western samar',
            'mt province'           => 'mountain province',
        ];
        $k = $alias[$k] ?? $k;
        return $maps['provincesSet'][$k] ?? null;
    }

    /** Normalized lookup key para sa city labels/sagot: normPlace + abbreviations + "municipality of". */
    public static function normCityKey(string $v): string
    {
        $s = self::normPlace($v);
        $s = preg_replace('/\b(municipality of|mun of|city of)\b/u', ' ', $s) ?? $s;
        $s = self::expandAbbr($s);
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return trim($s);
    }

    /** Karaniwang daglat sa address (pareho sa list at sa sagot ng AI). */
    private static function expandAbbr(string $s): string
    {
        static $map = ['sta' => 'santa', 'sto' => 'santo', 'gen' => 'general', 'pob' => 'poblacion', 'bgy' => 'barangay', 'brgy' => 'barangay', 'bgry' => 'barangay'];
        return preg_replace_callback('/\b(sta|sto|gen|pob|bgy|brgy|bgry)\b/u', fn ($m) => $map[$m[1]], $s) ?? $s;
    }

    private static ?array $cityIndex = null;

    /**
     * normKey → ['PROV|CITY' => ['prov'=>label,'city'=>label,'rank'=>0|1|2]]
     * rank 0 = buong label, 1 = bare name (tinanggal ang province prefix), 2 = ±" city" variant.
     */
    private static function cityIndex(array $maps): array
    {
        if (self::$cityIndex !== null) return self::$cityIndex;

        $prefixes = [];
        foreach (($maps['provincesSet'] ?? []) as $label) $prefixes[] = self::normCityKey((string) $label);
        foreach (['north cotabato', 'south cotabato', 'metro manila', 'ncr'] as $x) $prefixes[] = $x;
        $prefixes = array_values(array_unique(array_filter($prefixes)));
        usort($prefixes, fn ($a, $b) => strlen($b) <=> strlen($a));   // pinakamahaba muna

        $idx = [];
        $add = static function (string $key, string $prov, string $city, int $rank) use (&$idx): void {
            $key = trim($key);
            if ($key === '' || $key === 'city' || strlen($key) < 3) return;
            $id = $prov . '|' . $city;
            if (!isset($idx[$key][$id]) || $rank < $idx[$key][$id]['rank']) {
                $idx[$key][$id] = ['prov' => $prov, 'city' => $city, 'rank' => $rank];
            }
        };
        foreach (($maps['citiesByProv'] ?? []) as $provKey => $cities) {
            $provLabel = (string) ($maps['provincesSet'][$provKey] ?? strtoupper((string) $provKey));
            foreach ($cities as $cityLabel) {
                $cityLabel = (string) $cityLabel;
                $full  = self::normCityKey($cityLabel);
                $forms = [[$full, 0]];
                foreach ($prefixes as $pk) {
                    if (str_starts_with($full, $pk . ' ') && strlen($full) > strlen($pk) + 1) { $forms[] = [substr($full, strlen($pk) + 1), 1]; break; }
                }
                foreach ($forms as [$f, $rank]) {
                    $add($f, $provLabel, $cityLabel, $rank);
                    if (str_ends_with($f, ' city')) $add(trim(substr($f, 0, -5)), $provLabel, $cityLabel, 2);
                    else                            $add($f . ' city', $provLabel, $cityLabel, 2);
                }
            }
        }
        return self::$cityIndex = $idx;
    }

    /**
     * Deterministic barangay match sa loob ng isang city: Roman numerals ("Poblacion 9" = POBLACION IX),
     * STA./STO., "Brgy.", at "(POB.)"-style parenthetical. null kung wala o ambiguous → fallback/tao.
     */
    public function matchBarangayInList(string $aiBrgy, array $labels): ?string
    {
        $target = self::normBrgyKey($aiBrgy);
        if ($target === '') return null;
        $exact = []; $loose = [];
        foreach ($labels as $label) {
            $label = (string) $label;
            if (self::normBrgyKey($label) === $target) { $exact[] = $label; continue; }
            $noPar = self::normBrgyKey(preg_replace('/\([^)]*\)/u', ' ', $label) ?? $label);
            if ($noPar !== '' && $noPar === $target) $loose[] = $label;
        }
        if (count($exact) === 1) return $exact[0];
        if (count($exact) === 0 && count($loose) === 1) return $loose[0];
        if (count($exact) === 0 && count($loose) === 0) {
            // Compact pass: "nabag o" (mula sa Nabag-o) ↔ "nabago"; "sta ana" ↔ "santaana"
            $tc = str_replace(' ', '', $target); $hits = [];
            foreach ($labels as $label) {
                $label = (string) $label;
                $noPar = preg_replace('/\([^)]*\)/u', ' ', $label) ?? $label;
                if (str_replace(' ', '', self::normBrgyKey($label)) === $tc || str_replace(' ', '', self::normBrgyKey($noPar)) === $tc) $hits[] = $label;
            }
            if (count($hits) === 1) return $hits[0];
        }
        return null;
    }

    /** Normalized key para sa barangay: normPlace + tanggal "barangay/brgy" + daglat + Roman → digits. */
    public static function normBrgyKey(string $v): string
    {
        $s = self::normPlace(str_replace(['(', ')'], ' ', $v));
        $s = self::expandAbbr($s);
        $s = preg_replace('/\bbarangay\b/u', ' ', $s) ?? $s;
        $s = preg_replace_callback('/\b(?=[ivx])(x{0,3})(ix|iv|v?i{0,3})\b/u', static function ($m) {
            $r = $m[1] . $m[2];
            if ($r === '') return $m[0];
            $val = ['i' => 1, 'v' => 5, 'x' => 10]; $n = 0; $len = strlen($r);
            for ($i = 0; $i < $len; $i++) {
                $cur  = $val[$r[$i]];
                $next = $i + 1 < $len ? $val[$r[$i + 1]] : 0;
                $n   += $cur < $next ? -$cur : $cur;
            }
            return (string) $n;
        }, $s) ?? $s;
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return trim($s);
    }

    // ── GUARD · USAGE (2026-09-26) ────────────────────────────────────────

    /**
     * GUARD — "pag hindi sure, tao na", deterministic (walang AI):
     *  • city_in_chat: nasa chat ba ang city (label / bare name / ±"city") na sinagot ng resolver?
     *  • prov_in_chat: nasa chat ba ang province o alinman sa aliases nito?
     *  • Wala pareho → hinula lang mula sa barangay/landmark → tatanggapin lang kung IISA ang
     *    city sa BUONG list na may ganoong barangay (province lang ang nasa chat → iisa sa
     *    loob ng province na iyon).
     *  • Higit sa isang kandidato (city_candidates / barangay_candidates) → hindi sigurado,
     *    maliban kung EXPLICIT na nasa chat ang napili.
     *  'uncertain' = i-escalate sa mas malalim na model — kapag higit sa isa ang kandidato (nagkakasalungat
     *  ang sources) o kapag HINULA ang barangay mula sa landmark (wala sa chat). Kulang na impormasyon (walang city, low confidence) ay hindi malulutas ng mas malalim na
     *  model — tao agad, walang gastos.
     */
    public function assessResolved(array $resolved, array $mapped, string $chat, array $maps, bool $deep = false): array
    {
        $out = ['city_ok' => false, 'brgy_ok' => false, 'prov_ok' => false, 'city_in_chat' => false, 'prov_in_chat' => false,
                'brgy_in_chat' => false, 'uncertain' => false, 'city_cands' => [], 'brgy_cands' => [], 'reasons' => []];
        $conf   = (string) ($resolved['confidence'] ?? 'low');
        $aiCity = trim((string) ($resolved['city'] ?? ''));
        $aiProv = trim((string) ($resolved['province'] ?? ''));
        $aiBrgy = trim((string) ($resolved['barangay'] ?? ''));
        $out['city_cands'] = self::distinctKeys((array) ($resolved['city_candidates'] ?? []), 'normCityKey');
        $out['brgy_cands'] = self::distinctKeys((array) ($resolved['barangay_candidates'] ?? []), 'normBrgyKey');

        if ($conf === 'low') { $out['reasons'][] = 'resolver low confidence → tao'; return $out; }

        $chatKey  = ' ' . self::normCityKey($chat) . ' ';
        $chatBrgy = ' ' . self::normBrgyKey($chat) . ' ';

        // Province: nasa chat?
        $provForms = [];
        foreach (array_merge([$aiProv], (array) ($resolved['province_aliases'] ?? []), [(string) ($mapped['province'] ?? '')]) as $p) {
            $p = self::normCityKey((string) $p);
            if ($p === '') continue;
            $provForms[] = $p;
            if (in_array($p, ['metro manila', 'ncr', 'national capital region'], true)) array_push($provForms, 'metro manila', 'ncr', 'manila');
        }
        $provForms = array_values(array_unique($provForms));
        $out['prov_in_chat'] = self::chatMentionsAny($chatKey, $provForms);
        $out['prov_ok']      = $out['prov_in_chat'] && ($mapped['province'] ?? null) !== null;

        // City: nasa chat? (salita ng resolver, buong label, bare name, ±"city")
        $forms = [];
        if ($aiCity !== '') { $forms[] = self::normCityKey($aiCity); if (str_contains($aiCity, ',')) $forms[] = self::normCityKey(trim(explode(',', $aiCity)[0])); }
        if (($mapped['city'] ?? null) !== null) {
            $full = self::normCityKey((string) $mapped['city']);
            $forms[] = $full;
            foreach (array_merge($provForms, self::cityPrefixes($maps)) as $pf) {
                if ($pf !== '' && str_starts_with($full, $pf . ' ')) $forms[] = substr($full, strlen($pf) + 1);
            }
        }
        $cityForms = [];
        foreach ($forms as $f) {
            $f = trim($f);
            if ($f === '' || $f === 'city') continue;
            $cityForms[] = $f;
            $cityForms[] = str_ends_with($f, ' city') ? trim(substr($f, 0, -5)) : $f . ' city';
        }
        $cityForms = array_values(array_unique(array_filter($cityForms, fn ($f) => $f !== '' && $f !== 'city')));
        $out['city_in_chat'] = self::chatMentionsAny($chatKey, $cityForms);
        if ($aiBrgy !== '') $out['brgy_in_chat'] = self::chatMentionsAny($chatBrgy, [self::normBrgyKey($aiBrgy)]) || self::chatMentionsFuzzy($chatBrgy, self::normBrgyKey($aiBrgy));

        if (($mapped['city'] ?? null) === null) {
            if ($aiCity !== '') { $out['reasons'][] = 'city "' . $aiCity . '" hindi na-map sa list → tao'; }
            return $out;   // walang city → walang barangay (dependent)
        }

        if ($out['city_in_chat']) {
            $out['city_ok'] = true;
        } elseif (count($out['city_cands']) > 1) {
            $out['uncertain'] = true;
            $out['reasons'][] = 'wala sa chat ang city at ' . count($out['city_cands']) . ' ang kandidato (' . implode(', ', $out['city_cands']) . ') → tao';
        } elseif ($aiBrgy === '') {
            $out['reasons'][] = 'wala sa chat ang city/province at walang barangay para i-verify → tao';
        } else {
            // Hinula mula sa barangay/landmark → dapat IISA sa list
            $scope = $out['prov_in_chat'] ? (string) $mapped['province'] : null;
            $n = self::barangayCityCount($aiBrgy, $maps, $scope);
            if ($n === 1) {
                $out['city_ok'] = true;
                $out['reasons'][] = 'wala sa chat ang city pero IISA lang ang "' . $aiBrgy . '" sa list' . ($scope ? ' ng ' . $scope : '') . ' → tinanggap';
            } else {
                $out['reasons'][] = 'wala sa chat ang city; "' . $aiBrgy . '" ay nasa ' . $n . ' city sa list' . ($scope ? ' ng ' . $scope : '') . ' → tao';
            }
        }
        if (!$out['city_ok']) return $out;

        // Barangay
        if ($aiBrgy === '') {
            $out['brgy_ok'] = false;            // blank → mananatiling blank → hindi ✅ (tao), walang escalation
        } elseif (count($out['brgy_cands']) > 1 && !$out['brgy_in_chat']) {
            $out['brgy_ok']   = false;
            $out['uncertain'] = true;
            $out['reasons'][] = count($out['brgy_cands']) . ' ang kandidatong barangay (' . implode(', ', $out['brgy_cands']) . ') at wala sa chat ang napili → tao';
        } elseif (!$out['brgy_in_chat'] && !$deep) {
            // Hinula mula sa landmark/subdivision: ang mababaw na tawag ay nag-iiba kada takbo (District I vs San Fermin)
            // → deeper model muna; kung walang escalation, tao ang bahala.
            $out['brgy_ok']   = false;
            $out['uncertain'] = true;
            $out['reasons'][] = 'barangay "' . $aiBrgy . '" wala sa chat (hinula mula sa landmark) → mas malalim na pagsusuri muna';
        } else {
            $out['brgy_ok'] = true;
            if (!$out['brgy_in_chat']) $out['reasons'][] = 'barangay "' . $aiBrgy . '" hinula mula sa landmark, kinumpirma ng mas malalim na model (isang kandidato)';
        }
        return $out;
    }

    /** Whole-phrase match sa normalized na chat (may leading/trailing space ang haystack). */
    private static function chatMentionsAny(string $hayPadded, array $needles): bool
    {
        foreach ($needles as $n) {
            $n = trim((string) $n);
            if ($n !== '' && str_contains($hayPadded, ' ' . $n . ' ')) return true;
        }
        return false;
    }

    /** Fuzzy whole-phrase match (typo-tolerant, ≥85% similar) — "ibayo silngan" ≈ "ibayo silangan". */
    private static function chatMentionsFuzzy(string $hayPadded, string $needle): bool
    {
        $needle = trim($needle);
        if ($needle === '' || strlen($needle) < 5) return false;
        $nw = count(explode(' ', $needle));
        $words = array_values(array_filter(explode(' ', trim($hayPadded)), fn ($w) => $w !== ''));
        for ($i = 0; $i + $nw <= count($words); $i++) {
            $win = implode(' ', array_slice($words, $i, $nw));
            similar_text($win, $needle, $pct);
            if ($pct >= 85.0) return true;
        }
        return false;
    }

    private static function distinctKeys(array $vals, string $normFn): array
    {
        $o = [];
        foreach ($vals as $v) { $k = self::$normFn((string) $v); if ($k !== '' && !in_array($k, $o, true)) $o[] = $k; }
        return $o;
    }

    private static ?array $prefixCache = null;

    /** Mga province prefix ng city labels (BATANGAS-SAN-JOSE, NORTH-COTABATO-CARMEN…), pinakamahaba muna. */
    private static function cityPrefixes(array $maps): array
    {
        if (self::$prefixCache !== null) return self::$prefixCache;
        $p = [];
        foreach (($maps['provincesSet'] ?? []) as $label) $p[] = self::normCityKey((string) $label);
        foreach (['north cotabato', 'south cotabato', 'metro manila', 'ncr'] as $x) $p[] = $x;
        $p = array_values(array_unique(array_filter($p)));
        usort($p, fn ($a, $b) => strlen($b) <=> strlen($a));
        return self::$prefixCache = $p;
    }

    private static ?array $brgyIndex = null;

    /** Ilang city (city|prov) sa list ang may barangay na ganito ang pangalan? Optional: sa loob lang ng isang province. */
    public static function barangayCityCount(string $brgy, array $maps, ?string $provLabel = null): int
    {
        if (self::$brgyIndex === null) {
            $idx = [];
            foreach (($maps['brgysByCityProv'] ?? []) as $cityProv => $labels) {
                foreach ($labels as $label) {
                    $label = (string) $label;
                    $k1 = self::normBrgyKey($label);
                    $k2 = self::normBrgyKey(preg_replace('/\([^)]*\)/u', ' ', $label) ?? $label);
                    if ($k1 !== '') $idx[$k1][$cityProv] = true;
                    if ($k2 !== '' && $k2 !== $k1) $idx[$k2][$cityProv] = true;
                    foreach ([$k1, $k2] as $k) { $c = '#' . str_replace(' ', '', $k); if ($c !== '#') $idx[$c][$cityProv] = true; }
                }
            }
            self::$brgyIndex = $idx;
        }
        $key = self::normBrgyKey($brgy);
        if ($key === '') return 0;
        $hits = array_keys(self::$brgyIndex[$key] ?? []);
        if (!$hits) $hits = array_keys(self::$brgyIndex['#' . str_replace(' ', '', $key)] ?? []);   // compact: "nabag o" ↔ NABAGO
        if ($provLabel !== null && $provLabel !== '') {
            $pk = self::normProv($provLabel);
            $hits = array_values(array_filter($hits, fn ($cp) => substr($cp, strpos($cp, '|') + 1) === $pk));
        }
        return count($hits);
    }

    /** Usage kada AI call → gastos (USD) gamit ang config prices (estimate). */
    private function recordUsage(string $step, string $model, int $in, int $out, int $reasoning, int $searches): void
    {
        $prices = (array) config('services.openai.ai_checker_prices', []);
        [$pi, $po] = (array) ($prices[$model] ?? [0.0, 0.0]) + [0.0, 0.0];
        $ps = (float) ($prices['web_search'] ?? 0.01);
        $cost = $in / 1e6 * (float) $pi + $out / 1e6 * (float) $po + $searches * $ps;
        $this->usage[] = ['step' => $step, 'model' => $model, 'in' => $in, 'out' => $out, 'reasoning' => $reasoning, 'searches' => $searches, 'cost' => round($cost, 5)];
    }
}

<?php

namespace App\Services;

use App\Models\MacroOutput;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Port of the CHECKER_11_1 Google Apps Script macro into Laravel.
 *
 * Replicates the macro's per-field fix sequence:
 *   1) Fuzzy-match PROVINCE/CITY/BARANGAY vs the customer chat (PHP only).
 *   2) Iteratively call OpenAI per field — PROV → CITY → BRGY — each using
 *      a filtered allowed list (cities scoped sa fixed province; brgys
 *      scoped sa fixed city+province). Same prompts as macro.
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

    /**
     * Parse jnt_address.txt → in-memory lookup maps.
     *
     * Returns:
     *   [
     *     'provincesList'    => 'ABRA, AGUSAN DEL NORTE, ...' (CSV string for prompts)
     *     'provincesSet'     => ['abra' => 'ABRA', ...] (normalized key → display label)
     *     'citiesByProv'     => ['abra' => ['BANGUED', 'BUCAY', ...], ...]
     *     'brgysByCityProv'  => ['bangued|abra' => ['BARANGAY 1', ...], ...]
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
            ];
        }

        $provincesSet    = [];   // normKey => label
        $citiesByProv    = [];   // provNormKey => Set<cityLabel>
        $brgysByCityProv = [];   // cityNormKey|provNormKey => Set<brgyLabel>

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

        $provNames = array_values($provincesSet);
        sort($provNames, SORT_STRING | SORT_FLAG_CASE);

        return [
            'provincesList'   => implode(', ', $provNames),
            'provincesSet'    => $provincesSet,
            'citiesByProv'    => $citiesByProv,
            'brgysByCityProv' => $brgysByCityProv,
        ];
    }

    /**
     * Orchestrator — fix one macro_output row using the macro's full sequence.
     *
     * Returns ['status' => string, 'final_code' => string|null, 'message' => string|null]
     */
    public function processRow(int $id, array $maps): array
    {
        $row = MacroOutput::find($id);
        if (!$row) {
            return ['status' => 'failed', 'final_code' => null, 'message' => 'Row not found'];
        }

        $chat = trim((string) $row->all_user_input);
        if ($chat === '') {
            return ['status' => 'failed', 'final_code' => null, 'message' => 'Empty all_user_input'];
        }

        $apiKey = $this->getApiKey();
        if (!$apiKey) {
            return ['status' => 'failed', 'final_code' => null, 'message' => 'No OPENAI_API_KEY'];
        }

        $updates = [];

        // ── 1. PROVFIX ───────────────────────────────────────────────────
        $provCur = trim((string) $row->PROVINCE);
        $newProv = $this->fixProvince($chat, $provCur, $maps['provincesList'], $apiKey);
        if ($newProv && $this->provInList($newProv, $maps['provincesList']) && $newProv !== $provCur) {
            $updates['PROVINCE'] = $newProv;
        }
        $effectiveProv = $updates['PROVINCE'] ?? $provCur;
        $this->sleepMs();

        // Build city list for the (possibly updated) province
        $provKey  = self::normProv($effectiveProv);
        $cityList = isset($maps['citiesByProv'][$provKey])
            ? implode(', ', $maps['citiesByProv'][$provKey])
            : '';

        // ── 2. CITYFIX ───────────────────────────────────────────────────
        $cityCur = trim((string) $row->CITY);
        $newCity = $cityCur;
        if ($cityList !== '') {
            $candidate = $this->fixCity($chat, $effectiveProv, $cityCur, $cityList, $apiKey);
            if ($candidate && $this->cityInList($candidate, $cityList) && $candidate !== $cityCur) {
                $newCity = $candidate;
                $updates['CITY'] = $candidate;
            }
            $this->sleepMs();
        }
        $effectiveCity = $updates['CITY'] ?? $cityCur;

        // Build brgy list for the (possibly updated) city+province
        $cityKey  = self::normPlace($effectiveCity);
        $brgyList = isset($maps['brgysByCityProv'][$cityKey.'|'.$provKey])
            ? implode(', ', $maps['brgysByCityProv'][$cityKey.'|'.$provKey])
            : '';

        // ── 3. BRGYFIX ───────────────────────────────────────────────────
        $brgyCur = trim((string) $row->BARANGAY);
        if ($brgyList !== '') {
            $candidate = $this->fixBarangay($chat, $effectiveProv, $effectiveCity, $brgyCur, $brgyList, $apiKey);
            if ($candidate && $this->brgyInList($candidate, $brgyList) && $candidate !== $brgyCur) {
                $updates['BARANGAY'] = $candidate;
            }
            $this->sleepMs();
        }
        $effectiveBrgy = $updates['BARANGAY'] ?? $brgyCur;

        // ── 4. NAMEADDR (extract FULL NAME + ADDRESS Line 1) ─────────────
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

        // ── 4b. PHONE NUMBER (regex first; only fall back if needed) ─────
        $phoneCur = trim((string) $row->{'PHONE NUMBER'});
        if ($phoneCur === '') {
            $phone = $this->extractPhone($chat);
            if ($phone !== null) {
                $updates['PHONE NUMBER'] = $phone;
            }
        }

        // ── 5. VERIFYK ───────────────────────────────────────────────────
        $verdict = $this->verifyAddress($chat, $effectiveProv, $effectiveCity, $effectiveBrgy, $apiKey);
        $statusCode = $this->computeStatusCode($verdict);
        $updates['APP SCRIPT CHECKER'] = $statusCode;
        if ($statusCode === '✅') {
            $updates['STATUS'] = 'PROCEED';
        }

        // ── Persist ──────────────────────────────────────────────────────
        if (!empty($updates)) {
            $row->update($updates);
        }

        return [
            'status'     => $statusCode === '✅' ? 'fixed' : 'partial',
            'final_code' => $statusCode,
            'message'    => null,
        ];
    }

    // ── OPENAI PROMPTS — ported 1:1 from CHECKER_11_1 ─────────────────────

    /** Province fix — same as macro's PROVFIX_*. */
    public function fixProvince(string $chat, string $provOld, string $provList, string $apiKey): ?string
    {
        if (trim($chat) === '' || trim($provList) === '') return null;

        $system = 'You validate Philippine province names. Choose ONLY from the provided province list. '
                . 'Return STRICT JSON only: {"province":"..."}. If truly cannot determine, return UNKNOWN.';

        $prompt = $chat . "\n\n"
            . "Task: correct the PROVINCE only.\n"
            . "Rules:\n"
            . "- Choose ONLY from the allowed PROVINCE list below.\n"
            . "- If the message explicitly mentions NCR/Metro Manila, pick the NCR/Metro Manila option in the list.\n"
            . "- Do NOT keep the current province unless the message supports it.\n\n"
            . "Current Province: " . $provOld . "\n\n"
            . "Allowed PROVINCE list (pick ONLY from this list):\n"
            . $provList . "\n\n"
            . 'Return STRICT JSON only: {"province":"..."}' . "\n";

        $raw = $this->callOpenAI($apiKey, $system, $prompt);
        $parsed = $this->parseJsonField($raw, 'province');
        if (!$parsed || strtoupper($parsed) === 'UNKNOWN') return null;
        return $this->canonicalizeProvince($parsed, $provList);
    }

    /** City fix — same as macro's CITYFIX_*. */
    public function fixCity(string $chat, string $prov, string $cityOld, string $cityList, string $apiKey): ?string
    {
        if (trim($chat) === '' || trim($cityList) === '') return null;

        $system = 'You validate Philippine cities/municipalities. Choose ONLY from the provided city list. '
                . 'Return STRICT JSON only: {"city":"...","province":"..."}. If truly cannot determine, return UNKNOWN.';

        $prompt = $chat . "\n\n"
            . "Task: correct the CITY only.\n"
            . "Rules:\n"
            . "- Choose ONLY from the allowed CITY list below.\n"
            . "- If the message explicitly mentions a city/municipality, you MUST pick that city if it exists in the allowed list.\n"
            . "- Do NOT keep the current city unless the message supports it.\n"
            . "- If the message contains a 'City:' field, prioritize that value.\n\n"
            . "Province: " . $prov . "\n"
            . "Current City: " . $cityOld . "\n\n"
            . "Allowed CITY list (pick ONLY from this list):\n"
            . $cityList . "\n\n"
            . 'Return STRICT JSON only: {"city":"...","province":"..."}' . "\n";

        $raw = $this->callOpenAI($apiKey, $system, $prompt);
        $parsed = $this->parseJsonField($raw, 'city');
        if (!$parsed || strtoupper($parsed) === 'UNKNOWN') return null;
        return $parsed;
    }

    /** Barangay fix — same as macro's BRGYFIX_*. */
    public function fixBarangay(string $chat, string $prov, string $city, string $brgyOld, string $brgyList, string $apiKey): ?string
    {
        if (trim($chat) === '' || trim($brgyList) === '') return null;

        $system = 'You validate Philippine address barangays. '
                . 'Return STRICT JSON only: {"barangay":"...","city":"...","province":"..."}. '
                . 'If a barangay list is provided, choose ONLY from that list. '
                . 'If truly cannot determine, return UNKNOWN.';

        $prompt = $chat . "\n\n"
            . "We need to correct BARANGAY only.\n"
            . "Province: " . $prov . "\n"
            . "City: " . $city . "\n"
            . "Current Barangay: " . $brgyOld . "\n\n"
            . "Allowed BARANGAY list (pick ONLY from this list):\n"
            . $brgyList . "\n\n"
            . 'Return STRICT JSON only: {"barangay":"...","city":"...","province":"..."}' . "\n";

        $raw = $this->callOpenAI($apiKey, $system, $prompt);
        $parsed = $this->parseJsonField($raw, 'barangay');
        if (!$parsed || strtoupper($parsed) === 'UNKNOWN') return null;
        return $parsed;
    }

    /** Name + Address Line 1 extraction — same as macro's NAMEADDR_*. */
    public function extractNameAddr(string $chat, string $nameOld, string $addrOld, string $prov, string $city, string $brgy, string $apiKey): array
    {
        if (trim($chat) === '') return ['full_name' => '', 'address_line1' => ''];

        $system = "You extract and normalize Philippine customer name and Address Line 1 from messy chat logs.\n\n"
                . "You will receive RAW_CUSTOMER_CHAT and CURRENT values.\n\n"
                . "Output STRICT JSON only:\n"
                . "{\"full_name\":\"...\",\"address_line1\":\"...\"}\n\n"
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
                . "Return JSON only. No extra text.";

        $prompt = "RAW_CUSTOMER_CHAT:\n<<<\n" . $chat . "\n>>>\n\n"
            . "CURRENT_FULL_NAME: " . $nameOld . "\n"
            . "CURRENT_ADDRESS_LINE1: " . $addrOld . "\n"
            . "CURRENT_PROVINCE: " . $prov . "\n"
            . "CURRENT_CITY: " . $city . "\n"
            . "CURRENT_BARANGAY: " . $brgy . "\n\n"
            . "Task:\n"
            . "1) Extract best FULL NAME.\n"
            . "2) Extract best ADDRESS LINE 1 (exclude barangay/city/province).\n\n"
            . "Return STRICT JSON only:\n"
            . "{\"full_name\":\"...\",\"address_line1\":\"...\"}\n";

        $raw = $this->callOpenAI($apiKey, $system, $prompt);

        $obj = $this->parseJsonObject($raw);
        $name = isset($obj['full_name']) ? trim((string) $obj['full_name']) : '';
        $addr = isset($obj['address_line1']) ? trim((string) $obj['address_line1']) : '';

        // Strip out brgy/city/prov from address_line1 (defensive, per macro)
        $addr = $this->stripLocations($addr, $brgy, $city, $prov);
        $addr = trim(preg_replace('/\s*,\s*/', ', ', preg_replace('/\s+/', ' ', $addr) ?? '') ?? '');
        $addr = trim($addr, ", \t\n\r\0\x0B");

        return [
            'full_name'     => $name,
            'address_line1' => $addr,
        ];
    }

    /** Address verification — same as macro's VERIFYK_*. */
    public function verifyAddress(string $chat, string $prov, string $city, string $brgy, string $apiKey): array
    {
        if (trim($chat) === '') {
            return ['province_ok' => false, 'city_ok' => false, 'barangay_ok' => false];
        }

        $system = "You are a strict-but-practical Philippine address verifier.\n"
                . "You will receive RAW_CUSTOMER_CHAT and CURRENT_PROVINCE/CITY/BARANGAY.\n\n"
                . "Output STRICT JSON only with booleans:\n"
                . '{"province_ok":true/false,"city_ok":true/false,"barangay_ok":true/false}';

        $prompt = "RAW_CUSTOMER_CHAT:\n<<<\n" . $chat . "\n>>>\n\n"
            . "CURRENT_PROVINCE: " . $prov . "\n"
            . "CURRENT_CITY: " . $city . "\n"
            . "CURRENT_BARANGAY: " . $brgy . "\n\n"
            . "Return STRICT JSON only:\n"
            . '{"province_ok":true/false,"city_ok":true/false,"barangay_ok":true/false}' . "\n";

        $raw = $this->callOpenAI($apiKey, $system, $prompt);
        $obj = $this->parseJsonObject($raw);

        return [
            'province_ok' => !empty($obj['province_ok']),
            'city_ok'     => !empty($obj['city_ok']),
            'barangay_ok' => !empty($obj['barangay_ok']),
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────

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

    /** Extract phone (Philippine 09XX format) via regex from chat. */
    public function extractPhone(string $chat): ?string
    {
        if (preg_match('/(?:\+?63|0)?9\d{9}/', $chat, $m)) {
            $digits = preg_replace('/\D+/', '', $m[0]);
            if (strlen($digits) === 10 && $digits[0] === '9') return '0' . $digits;
            if (strlen($digits) === 11 && substr($digits, 0, 2) === '09') return $digits;
            if (strlen($digits) === 12 && substr($digits, 0, 3) === '639') return '0' . substr($digits, 2);
        }
        return null;
    }

    /** Call OpenAI with strict-JSON expectation. Retries once on failure. */
    public function callOpenAI(string $apiKey, string $system, string $prompt): string
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

    private function canonicalizeProvince(string $candidate, string $listCsv): string
    {
        $target = self::normProv($candidate);
        foreach (explode(',', $listCsv) as $p) {
            if (self::normProv($p) === $target) return trim($p);
        }
        return trim($candidate);
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
}

<?php

namespace App\Services;

use App\Models\MacroOutput;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ASTRA ENCODER — "parang encoder": ISANG malalim na AI call (gpt-6-astra) na may tools.
 *
 *   1) Chat (all_user_input) + Pancake history kung kulang + web search
 *      → bagong FORM: Name, Phone, House Number, Purok/Sitio, Address, Brgy, City, Province, Landmark
 *   2) Ang form → isusulat sa CXD bilang PINAKABAGONG block (--- ASTRA <oras> --- … ---)
 *   3) Batay sa form, hahanapin ng AI mismo sa `jnt_address_search` tool (parehong search ng /jnt/address)
 *      ang EKSAKTONG J&T label ng Province / City / Barangay
 *   4) Anim na field: FULL NAME, PHONE NUMBER, ADDRESS (mula sa form); PROVINCE, CITY, BARANGAY (mula sa list)
 *   5) Check: PHP ang huling salita (nasa list ba, phone, Validate rules) → APP SCRIPT CHECKER,
 *      isang linya sa AI ANALYZE, STATUS = PROCEED lang kapag ✅ at pasado
 *
 * Hindi nito ginagalaw: CXD ng customer (idinadagdag lang ang block ni Astra), ITEM_NAME, COD, PAGE,
 * SHOP DETAILS, HISTORICAL LOGS, edited_* columns. Hindi kailanman nagsusulat ng CANNOT PROCEED.
 *
 * Return shape = pareho ng MacroChecker::processRow() (status, final_code, all_filled, gate, message, log)
 * para iisa ang controller, UI, at ai_checker_logs.
 */
class AstraEncoder
{
    public const TIMEOUT_S       = 300;
    public const MAX_TOOL_ROUNDS = 8;
    public const LIST_LIMIT      = 40;
    public const BLOCK_MARK      = '--- ASTRA';
    /** app_settings key — naka-encrypt (Crypt) na OpenAI key para sa Astra engine; sine-set sa /encoder/checker_1/settings (CEO). */
    public const SETTING_KEY     = 'astra_encoder_api_key';
    public const SETTING_MODEL   = 'astra_encoder_model';
    public const SETTING_EFFORT  = 'astra_encoder_effort';
    /** Mga model na pwedeng piliin sa settings (Responses API + web_search + function tools). */
    public const MODELS  = ['gpt-6-astra', 'gpt-6-luna', 'gpt-6-sol', 'gpt-5.5', 'gpt-5.5-pro', 'gpt-5.4', 'gpt-5.4-mini', 'gpt-5.2', 'o3', 'o4-mini'];
    public const EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max'];

    private ?string $host = null;
    private string $model;
    private string $effort;
    private array $evidence = [];
    private array $usage    = [];
    private array $searches = [];
    private ?MacroOutput $row = null;
    private string $keySource = 'wala';

    /**
     * Saan kukunin ang OpenAI key ng Astra engine, sa pagkakasunod:
     *  1) app_settings[astra_encoder_api_key] — naka-encrypt, sine-set ng CEO sa /encoder/checker_1/settings
     *  2) ASTRA_ENCODER_API_KEY sa .env
     *  3) OPENAI_API_KEY sa .env
     */
    public static function resolveApiKey(): ?string
    {
        return self::apiKeyInfo()['key'];
    }

    /** ['key' => string|null, 'source' => 'settings'|'env_astra'|'env_openai'|'wala', 'masked' => '…1234'] — para sa settings page (hindi ipinapakita ang buong key). */
    public static function apiKeyInfo(): array
    {
        try {
            $enc = DB::table('app_settings')->where('key', self::SETTING_KEY)->value('value');
            if ($enc) {
                $k = trim(Crypt::decryptString((string) $enc));
                if ($k !== '') return ['key' => $k, 'source' => 'settings', 'masked' => '…' . substr($k, -4)];
            }
        } catch (\Throwable $e) {
            Log::warning('ASTRA_KEY_DECRYPT', ['error' => $e->getMessage()]);
        }
        $k = (string) env('ASTRA_ENCODER_API_KEY', '');
        if (trim($k) !== '') return ['key' => trim($k), 'source' => 'env_astra', 'masked' => '…' . substr(trim($k), -4)];
        $k = (string) (config('services.openai.key') ?: env('OPENAI_API_KEY', ''));
        if (trim($k) !== '') return ['key' => trim($k), 'source' => 'env_openai', 'masked' => '…' . substr(trim($k), -4)];
        return ['key' => null, 'source' => 'wala', 'masked' => ''];
    }

    /** I-save (encrypted) o burahin ang key mula sa settings page. */
    public static function storeApiKey(?string $key): void
    {
        $key = trim((string) $key);
        if ($key === '') {
            DB::table('app_settings')->where('key', self::SETTING_KEY)->delete();
            return;
        }
        DB::table('app_settings')->updateOrInsert(
            ['key' => self::SETTING_KEY],
            ['value' => Crypt::encryptString($key), 'updated_at' => now(), 'created_at' => now()]
        );
    }

    public function __construct(?string $model = null, ?string $effort = null)
    {
        $eng = self::engineSettings();   // settings page (app_settings) → config/.env
        $this->model  = $model  ?: $eng['model'];
        $this->effort = $effort ?: $eng['effort'];
    }

    /** Model at reasoning effort: settings page muna, tapos config/.env. */
    public static function engineSettings(): array
    {
        $cfgModel  = (string) config('services.openai.astra_encoder_model', 'gpt-6-astra');
        $cfgEffort = (string) config('services.openai.astra_encoder_effort', 'high');
        $model = null; $effort = null;
        try {
            $rows = DB::table('app_settings')->whereIn('key', [self::SETTING_MODEL, self::SETTING_EFFORT])->pluck('value', 'key');
            $m = trim((string) ($rows[self::SETTING_MODEL] ?? ''));  if ($m !== '' && in_array($m, self::MODELS, true))  $model  = $m;
            $e = trim((string) ($rows[self::SETTING_EFFORT] ?? '')); if ($e !== '' && in_array($e, self::EFFORTS, true)) $effort = $e;
        } catch (\Throwable $x) {}
        return [
            'model'         => $model ?? $cfgModel,   'model_source'  => $model  !== null ? 'settings' : 'config',
            'effort'        => $effort ?? $cfgEffort, 'effort_source' => $effort !== null ? 'settings' : 'config',
            'config_model'  => $cfgModel,             'config_effort' => $cfgEffort,
        ];
    }

    /** I-save (o burahin kapag blangko/'') ang model at effort mula sa settings page. */
    public static function storeEngineSettings(?string $model, ?string $effort): void
    {
        foreach ([self::SETTING_MODEL => [$model, self::MODELS], self::SETTING_EFFORT => [$effort, self::EFFORTS]] as $key => [$val, $allowed]) {
            $val = trim((string) $val);
            if ($val === '' || !in_array($val, $allowed, true)) { DB::table('app_settings')->where('key', $key)->delete(); continue; }
            DB::table('app_settings')->updateOrInsert(['key' => $key], ['value' => $val, 'updated_at' => now(), 'created_at' => now()]);
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    //  MAIN
    // ═════════════════════════════════════════════════════════════════════

    public function processRow(int $id, array $maps, ?string $host = null): array
    {
        $this->host = $host; $this->evidence = []; $this->usage = []; $this->searches = [];
        $t0 = microtime(true);

        $row = MacroOutput::find($id);
        if (!$row) return $this->finish(['status' => 'failed', 'final_code' => null, 'message' => 'Row not found'], $t0);
        $this->row = $row;

        $chat = trim((string) $row->all_user_input);
        if ($chat === '') return $this->finish(['status' => 'failed', 'final_code' => null, 'message' => 'Empty all_user_input'], $t0);

        // Key ng Astra engine: settings page (app_settings, encrypted) → ASTRA_ENCODER_API_KEY → OPENAI_API_KEY
        $keyInfo = self::apiKeyInfo();
        $apiKey  = $keyInfo['key'];
        $this->keySource = $keyInfo['source'];
        $this->evidence[] = 'KEY: ' . ['settings' => 'settings page (database)', 'env_astra' => '.env ASTRA_ENCODER_API_KEY', 'env_openai' => '.env OPENAI_API_KEY', 'wala' => 'WALA'][$keyInfo['source']];
        if (!$apiKey) return $this->finish(['status' => 'failed', 'final_code' => null, 'message' => 'No OPENAI_API_KEY'], $t0);

        $before = $this->sixFields($row);

        // ── 1 + 3. FORM (chat/Pancake/web) at J&T label (list tool) — isang agent call ──
        $ai = $this->resolveForm($apiKey, $row, $chat);
        if ($ai === null) {
            return $this->finish(['status' => 'failed', 'final_code' => null, 'message' => 'Astra: walang sagot mula sa AI'], $t0);
        }
        $form = $ai['form'];
        $this->evidence[] = 'FORM: ' . implode(' · ', array_filter([
            $form['name'] !== '' ? 'Name=' . $form['name'] : '',
            $form['phone'] !== '' ? 'Phone=' . $form['phone'] : '',
            trim($form['brgy'] . ', ' . $form['city'] . ', ' . $form['province'], ', ') !== '' ? 'Addr=' . trim($form['brgy'] . ', ' . $form['city'] . ', ' . $form['province'], ', ') : '',
            $form['landmark'] !== '' ? 'Landmark=' . $form['landmark'] : '',
        ])) . ' [' . $ai['confidence'] . ']' . ($ai['evidence'] !== '' ? ' — ' . $ai['evidence'] : '');

        // ── J&T labels: PHP ang nagpapatunay na nasa list talaga ang pinili ng AI ──
        [$prov, $city, $brgy, $listNote] = $this->validateJnt($ai['jnt'], $maps);
        if ($listNote !== '') $this->evidence[] = 'LIST: ' . $listNote;
        else $this->evidence[] = 'LIST: ' . $prov . ' | ' . $city . ' | ' . $brgy;

        // ── 4. Anim na field ─────────────────────────────────────────────
        $updates = [];
        $name = $this->cleanName((string) $form['name']);
        if ($name !== '' && $name !== trim((string) $row->{'FULL NAME'})) $updates['FULL NAME'] = $name;

        $phoneRaw = trim((string) $form['phone']);
        $phone    = $this->normalizePhone($phoneRaw);
        $phoneOk  = $phone !== null && preg_match('/^9\d{9}$/', $phone) === 1;
        if ($phoneOk && $phone !== trim((string) $row->{'PHONE NUMBER'})) $updates['PHONE NUMBER'] = $phone;

        $addr = $this->composeAddress($form);
        if ($addr !== '' && $addr !== trim((string) $row->ADDRESS)) $updates['ADDRESS'] = $addr;

        foreach (['PROVINCE' => $prov, 'CITY' => $city, 'BARANGAY' => $brgy] as $col => $val) {
            if ($val !== null && $val !== trim((string) $row->{$col})) $updates[$col] = $val;
        }
        $final = [];
        foreach (['FULL NAME', 'PHONE NUMBER', 'ADDRESS', 'PROVINCE', 'CITY', 'BARANGAY'] as $col) {
            $final[$col] = (string) ($updates[$col] ?? trim((string) $row->{$col}));
        }

        // ── 5. CHECK — PHP ang huling salita ─────────────────────────────
        $issues = $ai['issues'];
        if (!$phoneOk) {
            $digits = preg_replace('/\D+/', '', $phoneRaw) ?? '';
            $issues[] = 'Phone: ' . ($phoneRaw === '' ? 'wala sa chat' : $phoneRaw . ' (' . strlen($digits) . ' digit, dapat 10 na nagsisimula sa 9)');
        }
        $issues = array_values(array_unique(array_filter(array_map('trim', $issues))));

        $verdict = [
            'province_ok' => $prov !== null && $final['PROVINCE'] !== '',
            'city_ok'     => $city !== null && $final['CITY'] !== '',
            'barangay_ok' => $brgy !== null && $final['BARANGAY'] !== '',
            'evidence'    => $listNote !== '' ? $listNote : 'J&T labels mula sa list',
        ];
        $mc = new MacroChecker();
        $mc->setHost($this->host);
        $statusCode = $mc->computeStatusCode($verdict);
        $allFilled  = count(array_filter($final, fn ($v) => trim($v) !== '')) === 6;
        $needsHuman = $ai['needs_human'] || $ai['intent'] !== 'order';

        $gate = ['hard' => [], 'soft' => []];
        $proceed = false;
        $code = $statusCode;
        if ($ai['intent'] === 'cancel')            $code = 'CANCEL?';
        elseif ($ai['intent'] === 'inquiry_only')  $code = 'INQUIRY?';
        elseif ($statusCode === '✅' && $allFilled) {
            $gate = $mc->validateRow($row, $final, $maps);
            if ($needsHuman)                 { $code = 'TO FIX'; $this->evidence[] = 'GATE: TO FIX — Astra: ' . ($ai['human_reason'] ?: 'kailangan ng tao'); }
            elseif (!empty($gate['hard']))   { $code = 'TO FIX'; $this->evidence[] = 'GATE: TO FIX — ' . implode('; ', $gate['hard']); }
            elseif (!empty($gate['soft']))   { $code = 'TO FIX - SHOP DETAILS'; $this->evidence[] = 'GATE: TO FIX - SHOP DETAILS — ' . implode('; ', $gate['soft']); }
            else                             { $proceed = true; $updates['STATUS'] = 'PROCEED'; }
        } elseif ($needsHuman && $ai['human_reason'] !== '') {
            $this->evidence[] = 'HUMAN: ' . $ai['human_reason'];
        }
        $updates['APP SCRIPT CHECKER'] = mb_substr($code, 0, 60);

        // Isang linya para sa checker_1 (AI ANALYZE — existing column)
        $summary = ($statusCode === '✅' ? '✅ ' : '⚠ ' . $statusCode . ' · ')
            . (($prov && $city && $brgy) ? $prov . ' / ' . $city . ' / ' . $brgy : 'J&T: ' . ($listNote !== '' ? $listNote : 'hindi matukoy'))
            . ($issues ? ' · ⚠ ' . implode(' · ', $issues) : '')
            . ($needsHuman && $ai['human_reason'] !== '' ? ' · 👤 ' . $ai['human_reason'] : '')
            . ($proceed ? ' · PROCEED' : ($code !== $statusCode ? ' · ' . $code : ''));
        $updates['AI ANALYZE'] = mb_substr($summary, 0, 2000);

        // ── 2. CXD: idagdag ang block ni Astra bilang pinakabago ─────────
        $block = $this->formatBlock($form, ($prov && $city && $brgy) ? [$prov, $city, $brgy] : null, $listNote, $summary);
        $updates['CXD'] = $this->appendBlock((string) $row->CXD, $block);

        $row->update($updates);

        // ── Trace → ai_checker_logs.detail (hugis na kapareho ng MacroChecker para sa AI answers page) ──
        $this->trace = [
            'engine' => 'astra',
            'passes' => [[
                'pass'       => 1,
                'engine'     => 'astra',
                'chat_chars' => mb_strlen($chat),
                'resolve'    => [[
                    'model'  => $this->model,
                    'answer' => [
                        'province' => $form['province'], 'city' => $form['city'], 'barangay' => $form['brgy'],
                        'province_aliases' => [], 'city_candidates' => [], 'barangay_candidates' => [],
                        'confidence' => $ai['confidence'], 'evidence' => $ai['evidence'],
                        'jnt' => $ai['jnt'], 'intent' => $ai['intent'], 'issues' => $ai['issues'],
                        'needs_human' => $ai['needs_human'], 'human_reason' => $ai['human_reason'],
                    ],
                    'map'    => ['note' => ($prov && $city && $brgy) ? 'J&T: ' . $prov . ' | ' . $city . ' | ' . $brgy : ($listNote ?: 'walang J&T label')],
                    'assess' => ['reasons' => array_values(array_filter(array_merge($issues, $ai['human_reason'] !== '' ? ['👤 ' . $ai['human_reason']] : [])))],
                ]],
                'fallbacks'   => [],
                'verify'      => $verdict,
                'before'      => $before,
                'after'       => $final,
                'updated'     => array_values(array_diff(array_keys($updates), ['CXD', 'AI ANALYZE'])),
                'status_code' => $statusCode,
                'final_code'  => $code,
                'gate'        => $gate,
                'proceed'     => $proceed,
                'elapsed_ms'  => (int) round((microtime(true) - $t0) * 1000),
            ]],
            'form'      => $form,
            'cxd_block' => $block,
            'searches'  => $this->searches,
        ];

        $gateMsg = implode('; ', array_merge($gate['hard'], $gate['soft']));
        return $this->finish([
            'engine'     => 'astra',
            'status'     => $proceed ? 'fixed' : 'partial',
            'final_code' => $code,
            'all_filled' => $allFilled,
            'gate'       => $gate,
            'message'    => ($statusCode === '✅' && !$allFilled)
                ? 'Address verified but may blank na required field (di pa PROCEED)'
                : (($statusCode === '✅' && !$proceed) ? 'Address ✅ pero hindi PROCEED: ' . ($gateMsg ?: ($ai['human_reason'] ?: $code)) : null),
        ], $t0);
    }

    private array $trace = [];

    private function finish(array $result, float $t0): array
    {
        $in = 0; $out = 0; $searches = 0; $cost = 0.0; $models = [];
        foreach ($this->usage as $u) {
            $in += $u['in']; $out += $u['out']; $searches += $u['searches']; $cost += $u['cost'];
            $models[$u['model']] = true;
        }
        $trace = $this->trace ?: ['engine' => 'astra', 'passes' => [], 'searches' => $this->searches];
        $trace['summary'] = [
            'engine'     => 'astra',
            'key_source' => $this->keySource,
            'effort'     => $this->effort,
            'models'     => array_keys($models),
            'escalated'  => false,
            'searches'   => $searches,
            'tokens_in'  => $in,
            'tokens_out' => $out,
            'cost_usd'   => round($cost, 4),
            'elapsed_ms' => (int) round((microtime(true) - $t0) * 1000),
        ];
        $trace['usage']    = $this->usage;
        $trace['evidence'] = $this->evidence;
        $result['log'] = $trace;
        $this->trace = [];
        return $result;
    }

    // ═════════════════════════════════════════════════════════════════════
    //  AGENT CALL (Responses API + tools)
    // ═════════════════════════════════════════════════════════════════════

    private function resolveForm(string $apiKey, MacroOutput $row, string $chat): ?array
    {
        $system = <<<'SYS'
You are an expert Philippine e-commerce ORDER ENCODER (cash-on-delivery, courier J&T). Work exactly like a careful human encoder.

INPUT: the customer's chat (raw), the order context (page, item, COD price), the current row values typed by a junior encoder (may be wrong), and any address form the customer previously submitted.

YOUR JOB, in order:
1. Read the chat. If it is clearly not enough to determine the recipient's name, phone or address, call get_chat_history once to get the full earlier conversation.
2. Build the address FORM (real-world, as the customer means it): name, phone, house_number, purok_sitio, address (street/subdivision/building), brgy, city, province, landmark, plus the price and quantity the customer mentioned. Use web_search when only a landmark, subdivision, market, school or business name is given, to find which barangay it belongs to (names are often misspelled).
3. Then find the courier's EXACT labels: call jnt_address_search with a few distinctive words from your form (e.g. "28 tondo", "poblacion ix cotabato", "ibayo silangan naic", "holy spirit quezon"). Every word must occur in the entry, so use FEW words; if 0 results, retry with fewer or different words (digits vs Roman numerals, without "city", the province name, a synonym). Pick ONE returned entry and copy its PROVINCE, CITY and BARANGAY strings EXACTLY into "jnt". Never invent a label that the tool did not return.
   - The courier list is its own filing and may differ from geography: Cotabato City is under COTABATO; Metro Manila's City of Manila is filed by DISTRICT as the "city" (TONDO I/II, TONDO-NORTH, SAMPALOC, ERMITA, PACO, QUIAPO, ...); numbered barangays look like "BARANGAY 28"; repeated town names carry a province prefix (BATANGAS-SAN-JOSE, NORTH-COTABATO-CARMEN).
   - If several entries remain plausible and nothing in the chat decides between them (e.g. barangay NABAGO exists in 3 cities and no city was given), leave "jnt" fields EMPTY and set needs_human=true with the candidates in human_reason. Never guess a neighboring or similar town.
4. Compare the chat with the ORDER: if the price or quantity the customer mentioned differs from the order, or the customer seems to cancel or is only asking, say so.

RULES:
- phone: digits only, Philippine mobile as written by the customer (e.g. 09171234567). If it is not a valid mobile number (wrong digit count, landline, missing), still return the digits you found and add an issue.
- name: the recipient's name from the form/chat (NOT the Facebook page name); keep as written, proper case.
- address: house number / street / purok / subdivision / building only — do NOT repeat barangay, city or province there.
- intent: "order" (wants delivery), "cancel" (wants to cancel), "inquiry_only" (no order), "unclear".
- needs_human: true ONLY when the address cannot be encoded safely (ambiguous place, contradictory info, nothing usable). Price/quantity mismatch and phone problems go to "issues", not needs_human.
- confidence: high | medium | low for the address.
- evidence: one short line: why (landmark/source) or empty.

OUTPUT: STRICT JSON only, exactly this shape:
{"form":{"name":"","phone":"","house_number":"","purok_sitio":"","address":"","brgy":"","city":"","province":"","landmark":"","price":"","quantity":""},
 "jnt":{"province":"","city":"","barangay":""},
 "intent":"order|cancel|inquiry_only|unclear",
 "issues":["short line each"],
 "needs_human":false,"human_reason":"",
 "confidence":"high|medium|low","evidence":""}
SYS;

        $customerForms = $this->customerBlocks((string) $row->CXD);
        $prompt = "CHAT (raw customer conversation):\n<<<\n" . $chat . "\n>>>\n\n"
            . "ORDER: PAGE=" . (string) $row->PAGE . " | ITEM=" . (string) $row->ITEM_NAME . " | COD=" . (string) $row->COD . "\n"
            . (trim((string) $row->{'SHOP DETAILS'}) !== '' ? "SHOP DETAILS: " . preg_replace('/\s+/', ' ', (string) $row->{'SHOP DETAILS'}) . "\n" : '')
            . "CURRENT ROW (typed by encoder, MAY BE WRONG): FULL NAME=" . (string) $row->{'FULL NAME'} . " | PHONE=" . (string) $row->{'PHONE NUMBER'}
            . " | ADDRESS=" . (string) $row->ADDRESS . " | PROVINCE=" . (string) $row->PROVINCE . " | CITY=" . (string) $row->CITY . " | BARANGAY=" . (string) $row->BARANGAY . "\n"
            . ($customerForms !== '' ? "\nADDRESS FORM(S) THE CUSTOMER SUBMITTED EARLIER (may be outdated; the chat wins if they conflict):\n" . $customerForms . "\n" : '')
            . "\nReturn STRICT JSON only.";

        $tools = [
            ['type' => 'web_search'],
            [
                'type' => 'function', 'name' => 'jnt_address_search',
                'description' => 'Search the courier (J&T) address list — the ONLY valid PROVINCE / CITY / BARANGAY labels. Every word of the query must appear in the entry text "PROVINCE CITY BARANGAY" (case-insensitive; hyphens count as spaces), so use FEW distinctive words, e.g. "28 tondo", "poblacion ix cotabato", "ibayo silangan naic". Returns exact entries; copy them verbatim.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'query' => ['type' => 'string', 'description' => 'few words: barangay, city and/or province'],
                    'limit' => ['type' => 'integer', 'description' => 'max entries to return (default 40)'],
                ], 'required' => ['query']],
            ],
            [
                'type' => 'function', 'name' => 'get_chat_history',
                'description' => "Fetch the customer's full earlier conversation (Pancake) when the given chat is not enough to determine the name, phone or address. Call at most once.",
                'parameters' => ['type' => 'object', 'properties' => new \stdClass()],
            ],
        ];

        $base = [
            'model'        => $this->model,
            'instructions' => $system,
            'reasoning'    => ['effort' => $this->effort],
            'tools'        => $tools,
            'tool_choice'  => 'auto',
            'include'      => ['web_search_call.action.sources'],
            'store'        => true,
        ];
        // Cap sa built-in web searches kada row (gastos): config astra_encoder_max_web (0 = walang cap)
        $maxWeb = (int) config('services.openai.astra_encoder_max_web', 4);
        if ($maxWeb > 0) $base['max_tool_calls'] = $maxWeb;

        $input  = $prompt;
        $prevId = null;
        $text   = '';
        for ($round = 0; $round < self::MAX_TOOL_ROUNDS; $round++) {
            $payload = $base + ['input' => $input] + ($prevId ? ['previous_response_id' => $prevId] : []);
            $j = $this->post($apiKey, $payload);
            if ($j === null) return null;
            $prevId = (string) ($j['id'] ?? '');
            $out    = collect($j['output'] ?? []);

            // usage + web searches
            $u = (array) ($j['usage'] ?? []);
            $webCalls = $out->where('type', 'web_search_call');
            $this->recordUsage('ASTRA' . ($round ? '#' . ($round + 1) : ''), $this->model,
                (int) ($u['input_tokens'] ?? 0), (int) ($u['output_tokens'] ?? 0), (int) ($u['output_tokens_details']['reasoning_tokens'] ?? 0), $webCalls->count());
            foreach ($webCalls as $c) {
                $a  = (array) ($c['action'] ?? []);
                $qs = isset($a['queries']) ? (array) $a['queries'] : (isset($a['query']) ? [(string) $a['query']] : []);
                $urls = [];
                foreach ((array) ($a['sources'] ?? []) as $s) if (!empty($s['url'])) $urls[] = (string) $s['url'];
                $this->searches[] = ['step' => 'web_search', 'model' => $this->model, 'queries' => array_values(array_filter(array_map('strval', $qs))), 'sources' => array_values(array_unique($urls))];
                $this->evidence[] = 'WEB: ' . implode(' | ', array_slice(array_map('strval', $qs), 0, 3)) . ($urls ? ' — ' . implode(' ', array_slice(array_unique($urls), 0, 3)) : '');
            }

            $calls = $out->where('type', 'function_call')->values();
            if ($calls->isEmpty()) {
                $text = trim((string) ($j['output_text'] ?? ''));
                if ($text === '') {
                    $text = trim($out->where('type', 'message')
                        ->flatMap(fn ($m) => collect($m['content'] ?? [])->where('type', 'output_text')->pluck('text'))
                        ->implode(''));
                }
                break;
            }

            $outputs = [];
            foreach ($calls as $c) {
                $name = (string) ($c['name'] ?? '');
                $args = json_decode((string) ($c['arguments'] ?? '{}'), true) ?: [];
                $result = $this->runTool($name, $args);
                $outputs[] = ['type' => 'function_call_output', 'call_id' => (string) ($c['call_id'] ?? ''), 'output' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
            }
            $input = $outputs;
        }

        if ($text === '') { $this->evidence[] = 'ASTRA: walang JSON na sagot'; return null; }
        $obj = $this->parseJsonObject($text);
        if (!$obj) { $this->evidence[] = 'ASTRA: hindi ma-parse ang sagot: ' . mb_substr($text, 0, 200); return null; }

        $clean = static function ($v): string {
            $v = trim((string) $v);
            return in_array(strtoupper($v), ['UNKNOWN', 'N/A', 'NA', 'NULL', 'NONE', '-', '—'], true) ? '' : $v;
        };
        $f = (array) ($obj['form'] ?? []);
        $form = [];
        foreach (['name', 'phone', 'house_number', 'purok_sitio', 'address', 'brgy', 'city', 'province', 'landmark', 'price', 'quantity'] as $k) {
            $form[$k] = $clean($f[$k] ?? '');
        }
        $jn = (array) ($obj['jnt'] ?? []);
        $jnt = ['province' => $clean($jn['province'] ?? ''), 'city' => $clean($jn['city'] ?? ''), 'barangay' => $clean($jn['barangay'] ?? '')];
        $intent = strtolower(trim((string) ($obj['intent'] ?? 'order')));
        if (!in_array($intent, ['order', 'cancel', 'inquiry_only', 'unclear'], true)) $intent = 'order';
        $conf = strtolower(trim((string) ($obj['confidence'] ?? 'low')));
        if (!in_array($conf, ['high', 'medium', 'low'], true)) $conf = 'low';
        $issues = [];
        foreach ((array) ($obj['issues'] ?? []) as $i) { $i = trim((string) $i); if ($i !== '') $issues[] = mb_substr($i, 0, 160); }

        return [
            'form'         => $form,
            'jnt'          => $jnt,
            'intent'       => $intent,
            'issues'       => $issues,
            'needs_human'  => !empty($obj['needs_human']),
            'human_reason' => mb_substr(trim((string) ($obj['human_reason'] ?? '')), 0, 300),
            'confidence'   => $conf,
            'evidence'     => mb_substr(trim((string) ($obj['evidence'] ?? '')), 0, 300),
            'raw'          => $obj,
        ];
    }

    private function post(string $apiKey, array $payload): ?array
    {
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $res = Http::withToken($apiKey)->acceptJson()->timeout(self::TIMEOUT_S)
                    ->post('https://api.openai.com/v1/responses', $payload);
                if ($res->successful()) return $res->json();
                Log::warning('ASTRA_ENCODER_HTTP', ['attempt' => $attempt, 'status' => $res->status(), 'body' => substr($res->body(), 0, 500)]);
                // Param na hindi tinatanggap ng model/API (reasoning, max_tool_calls) → tanggalin at subukan ulit
                if ($res->status() === 400) {
                    $dropped = false;
                    foreach (['reasoning', 'max_tool_calls'] as $p) {
                        if (isset($payload[$p]) && str_contains($res->body(), $p)) { unset($payload[$p]); $dropped = true; }
                    }
                    if ($dropped) { $attempt--; continue; }
                }
                if ($res->status() < 500 && $res->status() !== 429) return null;
            } catch (\Throwable $e) {
                Log::warning('ASTRA_ENCODER_EX', ['attempt' => $attempt, 'error' => $e->getMessage()]);
            }
            if ($attempt === 1) usleep(1200 * 1000);
        }
        return null;
    }

    private function runTool(string $name, array $args): array
    {
        if ($name === 'jnt_address_search') {
            $q     = trim((string) ($args['query'] ?? ''));
            $limit = max(1, min((int) ($args['limit'] ?? self::LIST_LIMIT), 100));
            $rows  = self::listSearch($q, $limit);
            $this->searches[] = ['step' => 'jnt_address_search', 'model' => $this->model, 'queries' => [$q], 'sources' => array_map(fn ($r) => $r['province'] . ' | ' . $r['city'] . ' | ' . $r['barangay'], array_slice($rows, 0, 8))];
            $this->evidence[] = 'LIST SEARCH "' . $q . '": ' . count($rows) . ' result' . (count($rows) === 1 ? '' : 's')
                . ($rows ? ' — ' . implode(' ; ', array_map(fn ($r) => $r['province'] . '|' . $r['city'] . '|' . $r['barangay'], array_slice($rows, 0, 4))) . (count($rows) > 4 ? ' …' : '') : '');
            return [
                'count'   => count($rows),
                'results' => $rows,
                'note'    => count($rows) === 0 ? 'No entry contains ALL these words. Retry with fewer/different words (digits vs Roman numerals, drop "city", use the province).'
                           : (count($rows) >= $limit ? 'Truncated to ' . $limit . ' — add a word to narrow.' : 'Copy province/city/barangay EXACTLY as returned.'),
            ];
        }
        if ($name === 'get_chat_history') {
            $hist = (new MacroChecker())->fetchPancakeChat((string) ($this->row?->fb_name ?? ''));
            $this->evidence[] = 'PANCAKE: ' . ($hist === '' ? 'walang history' : mb_strlen($hist) . ' chars');
            return ['found' => $hist !== '', 'chat_history' => $hist !== '' ? mb_substr($hist, 0, 12000) : 'No earlier conversation found.'];
        }
        return ['error' => 'unknown tool ' . $name];
    }

    // ═════════════════════════════════════════════════════════════════════
    //  J&T LIST (parehong logic ng /jnt/address)
    // ═════════════════════════════════════════════════════════════════════

    /** Lahat ng salita ng query ay dapat nasa "PROVINCE CITY BARANGAY" (normalized). Katulad ng JntAddressController::search(). */
    public static function listSearch(string $q, int $limit = 40): array
    {
        $q = self::normQ($q);
        if (mb_strlen($q, 'UTF-8') < 2) return [];
        $words = preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];
        foreach (self::entries() as $e) {
            foreach ($words as $w) {
                if (mb_strpos($e['search'], $w, 0, 'UTF-8') === false) continue 2;
            }
            $out[] = ['province' => $e['prov'], 'city' => $e['city'], 'barangay' => $e['brgy']];
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    private static function entries(): array
    {
        $filePath = resource_path('views/macro_output/jnt_address.txt');
        $mtime    = @filemtime($filePath) ?: 0;
        return Cache::remember("astra_jnt_entries_v1_{$mtime}", now()->addHours(12), function () use ($filePath) {
            if (!is_file($filePath)) return [];
            $entries = [];
            foreach (file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $parts = array_map('trim', explode('|', trim((string) $line)));
                if (count($parts) !== 3) continue;
                if (mb_strtolower($parts[0], 'UTF-8') === 'province') continue;
                [$prov, $city, $brgy] = $parts;
                if ($prov === '' || $city === '' || $brgy === '') continue;
                $entries[] = ['prov' => $prov, 'city' => $city, 'brgy' => $brgy, 'search' => self::normQ("{$prov} {$city} {$brgy}")];
            }
            return $entries;
        });
    }

    private static function normQ(string $s): string
    {
        $s = str_replace(["\xC2\xA0"], ' ', trim($s));
        $s = mb_strtolower($s, 'UTF-8');
        $s = preg_replace('/[|_\-]+/u', ' ', $s) ?? $s;
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return trim($s);
    }

    /** Patunayan na nasa list ang J&T labels na pinili ng AI; ibalik ang EKSAKTONG label mula sa list. */
    private function validateJnt(array $jnt, array $maps): array
    {
        $p = trim((string) ($jnt['province'] ?? '')); $c = trim((string) ($jnt['city'] ?? '')); $b = trim((string) ($jnt['barangay'] ?? ''));
        if ($p === '' && $c === '' && $b === '') return [null, null, null, 'walang J&T label (hindi matukoy ni Astra → tao)'];

        $pk = MacroChecker::normProv($p);
        $provLabel = $maps['provincesSet'][$pk] ?? null;
        if ($provLabel === null) return [null, null, null, 'province "' . $p . '" wala sa list'];

        $cityLabel = null;
        foreach (($maps['citiesByProv'][$pk] ?? []) as $cl) {
            if (MacroChecker::normPlace((string) $cl) === MacroChecker::normPlace($c)) { $cityLabel = (string) $cl; break; }
        }
        if ($cityLabel === null) return [$provLabel, null, null, 'city "' . $c . '" wala sa list ng ' . $provLabel];

        $brgyLabel = null;
        $target = MacroChecker::normPlace(preg_replace('/\b(barangay|brgy\.?)\b/iu', ' ', $b) ?? $b);
        foreach (($maps['brgysByCityProv'][MacroChecker::normPlace($cityLabel) . '|' . $pk] ?? []) as $bl) {
            $clean = preg_replace('/\b(barangay|brgy\.?)\b/iu', ' ', (string) $bl) ?? (string) $bl;
            if (MacroChecker::normPlace($clean) === $target) { $brgyLabel = (string) $bl; break; }
        }
        if ($brgyLabel === null) return [$provLabel, $cityLabel, null, 'barangay "' . $b . '" wala sa list ng ' . $cityLabel];

        return [$provLabel, $cityLabel, $brgyLabel, ''];
    }

    // ═════════════════════════════════════════════════════════════════════
    //  CXD BLOCK · FIELDS · HELPERS
    // ═════════════════════════════════════════════════════════════════════

    private function formatBlock(array $form, ?array $jnt, string $listNote, string $summary): string
    {
        $v = fn ($k) => ($form[$k] ?? '') !== '' ? $form[$k] : '-';
        $lines = [
            self::BLOCK_MARK . ' ' . now('Asia/Manila')->format('Y-m-d H:i') . ' ---',
            'Name: ' . $v('name'),
            'Phone Number: ' . $v('phone'),
            'House Number: ' . $v('house_number'),
            'Purok/Sitio: ' . $v('purok_sitio'),
            'Address: ' . $v('address'),
            'Brgy: ' . $v('brgy'),
            'City: ' . $v('city'),
            'Province: ' . $v('province'),
            'Landmark: ' . $v('landmark'),
            'Price: ' . $v('price'),
            'Quantity: ' . $v('quantity'),
            'J&T: ' . ($jnt ? implode(' | ', $jnt) : 'hindi matukoy' . ($listNote !== '' ? ' — ' . $listNote : '')),
            'Check: ' . $summary,
            '---',
        ];
        return implode("\n", $lines);
    }

    private function appendBlock(string $old, string $block): string
    {
        $old = rtrim(str_replace("\r\n", "\n", $old));
        $new = ($old === '' ? '' : $old . "\n") . $block;
        // Kapag sobrang haba na (maraming ASTRA block), tanggalin ang pinakalumang ASTRA block(s)
        while (mb_strlen($new) > 60000 && preg_match('/' . preg_quote(self::BLOCK_MARK, '/') . '.*?\n---(\n|$)/su', $new)) {
            $new = preg_replace('/' . preg_quote(self::BLOCK_MARK, '/') . '.*?\n---(\n|$)/su', '', $new, 1) ?? $new;
        }
        return $new;
    }

    /** Ang mga block ng CUSTOMER lang (tinanggal ang mga ASTRA block), huling 2 block. */
    private function customerBlocks(string $cxd): string
    {
        $cxd = str_replace("\r\n", "\n", trim($cxd));
        if ($cxd === '') return '';
        $cxd = preg_replace('/' . preg_quote(self::BLOCK_MARK, '/') . '.*?\n---(\n|$)/su', '', $cxd) ?? $cxd;
        $cxd = trim($cxd);
        if ($cxd === '') return '';
        // huling 2 block (---…---)
        preg_match_all('/---\s*\n(.*?)\n---/su', $cxd, $m);
        $blocks = $m[1] ?? [];
        if (!$blocks) return mb_substr($cxd, -1500);
        return implode("\n---\n", array_slice($blocks, -2));
    }

    private function sixFields(MacroOutput $row): array
    {
        $o = [];
        foreach (['FULL NAME', 'PHONE NUMBER', 'ADDRESS', 'PROVINCE', 'CITY', 'BARANGAY'] as $c) $o[$c] = trim((string) $row->{$c});
        return $o;
    }

    private function cleanName(string $n): string
    {
        $n = trim(preg_replace('/\s+/u', ' ', $n) ?? $n);
        $n = str_replace(['Ã±', 'Ã‘'], ['ñ', 'Ñ'], $n);
        return mb_substr($n, 0, 120);
    }

    private function normalizePhone(string $raw): ?string
    {
        $d = preg_replace('/\D+/', '', $raw) ?? '';
        if ($d === '') return null;
        if (str_starts_with($d, '63') && strlen($d) >= 12) $d = substr($d, 2);
        $d = ltrim($d, '0');
        return $d === '' ? null : $d;
    }

    private function composeAddress(array $form): string
    {
        $parts = []; $seen = [];
        foreach (['house_number', 'purok_sitio', 'address'] as $k) {
            $v = trim((string) ($form[$k] ?? ''));
            $key = mb_strtolower(preg_replace('/\s+/u', ' ', $v) ?? $v);
            if ($v === '' || $v === '-' || isset($seen[$key])) continue;   // iwas "Purok 3, Purok 3"
            $seen[$key] = true; $parts[] = $v;
        }
        $lm = trim((string) ($form['landmark'] ?? ''));
        if ($lm !== '' && $lm !== '-' && !isset($seen[mb_strtolower($lm)])) $parts[] = 'near ' . $lm;
        $addr = implode(', ', $parts);
        $addr = preg_replace('/\s+/u', ' ', $addr) ?? $addr;
        return mb_substr(trim($addr, " ,"), 0, 250);
    }

    private function parseJsonObject(string $raw): array
    {
        $s = trim($raw);
        if ($s === '') return [];
        $s = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $s) ?? $s;
        $d = json_decode($s, true);
        if (is_array($d)) return $d;
        if (preg_match('/\{[\s\S]*\}/', $s, $m)) { $d = json_decode($m[0], true); if (is_array($d)) return $d; }
        return [];
    }

    private function recordUsage(string $step, string $model, int $in, int $out, int $reasoning, int $searches): void
    {
        $prices = (array) config('services.openai.ai_checker_prices', []);
        [$pi, $po] = (array) ($prices[$model] ?? [0.0, 0.0]) + [0.0, 0.0];
        $ps   = (float) ($prices['web_search'] ?? 0.01);
        $cost = $in / 1e6 * (float) $pi + $out / 1e6 * (float) $po + $searches * $ps;
        $this->usage[] = ['step' => $step, 'model' => $model, 'in' => $in, 'out' => $out, 'reasoning' => $reasoning, 'searches' => $searches, 'cost' => round($cost, 5)];
    }
}

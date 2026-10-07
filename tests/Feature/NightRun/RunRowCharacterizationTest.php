<?php

namespace Tests\Feature\NightRun;

use App\Models\MacroOutput;
use App\Services\AstraEncoder;
use Carbon\Carbon;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Characterization ng browser path: POST /encoder/checker_1/ai-checker/run-row/{id}.
 * Isinulat BAGO ilipat ang laman ng runRow sa AiCheckerRowRunner — dapat pareho ang JSON at ang
 * ai_checker_logs row bago at pagkatapos ng paglipat. Ang tanging seam ay Http::fake sa OpenAI;
 * walang totoong network, walang totoong key. Ang address list ay ang totoong jnt_address.txt ng repo.
 *
 * Hindi naka-pin (nagbabago kada takbo): elapsed_ms, duration_ms, id at timestamps ng log row —
 * tinitingnan lang na naroon at tama ang type.
 */
class RunRowCharacterizationTest extends NightRunTestCase
{
    private const CHAT = "Juan Dela Cruz\n09171234567\n12 Sampaguita St, Holy Spirit, Quezon City";
    private const SIX_BLANK = ['FULL NAME' => '', 'PHONE NUMBER' => '', 'ADDRESS' => '', 'PROVINCE' => '', 'CITY' => '', 'BARANGAY' => ''];
    private const SIX_FILLED = [
        'FULL NAME' => 'Juan Dela Cruz', 'PHONE NUMBER' => '9171234567', 'ADDRESS' => '12, Sampaguita St',
        'PROVINCE' => 'METRO-MANILA', 'CITY' => 'QUEZON-CITY', 'BARANGAY' => 'HOLY SPIRIT',
    ];
    private const NO_GATE = ['hard' => [], 'soft' => []];

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        // Lahat ng config na binabasa ng engines ay nakatakda rito, para hindi nakadepende sa local environment.
        config([
            'services.openai.key'                       => 'test-key-not-real',
            'services.openai.ai_checker_search'         => 'required',
            'services.openai.ai_checker_escalate_model' => '',
            'services.openai.astra_encoder_model'       => 'gpt-6-astra',
            'services.openai.astra_encoder_effort'      => 'high',
            'services.openai.astra_encoder_max_web'     => 4,
            'services.openai.ai_checker_prices'         => ['gpt-5.2' => [1.75, 14.0], 'gpt-6-astra' => [10.0, 50.0], 'web_search' => 0.01],
        ]);
        // Astra: key mula sa settings page (app_settings) para tiyak ang key_source kahit ano ang environment.
        AstraEncoder::storeApiKey('test-key-not-real');

        $this->travelTo(Carbon::parse('2026-10-04 09:30:00', 'Asia/Manila'));

        $user = $this->user();
        $this->userId = (int) $user->id;
        $this->actingAs($user);
    }

    private function order(array $extra = []): MacroOutput
    {
        return MacroOutput::create(array_merge([
            'TIMESTAMP'      => '21:14 03-10-2026',
            'ts_date'        => '2026-10-03',
            'PAGE'           => 'Likha Shop',
            'ITEM_NAME'      => 'Slimming Tea',
            'COD'            => '599',
            'all_user_input' => self::CHAT,
        ], $extra));
    }

    private function runRow(int $id, array $body = [])
    {
        return $this->postJson('/encoder/checker_1/ai-checker/run-row/' . $id, $body);
    }

    /** elapsed_ms: dapat integer; pinapalitan ng marker para maikumpara ang natitira nang eksakto. */
    private function scrub(array $a): array
    {
        foreach ($a as $k => $v) {
            if ($k === 'elapsed_ms') {
                $this->assertIsInt($v);
                $a[$k] = '<ms>';
            } elseif (is_array($v)) {
                $a[$k] = $this->scrub($v);
            }
        }

        return $a;
    }

    /** Ang nag-iisang log row: id, duration_ms at timestamps ay tinitingnan lang; ang iba ay ibinabalik para i-pin. */
    private function onlyLogRow(): array
    {
        $rows = DB::table('ai_checker_logs')->get();
        $this->assertCount(1, $rows);
        $row = (array) $rows[0];

        $this->assertIsInt($row['id']);
        $this->assertIsInt($row['duration_ms']);
        $this->assertGreaterThanOrEqual(0, $row['duration_ms']);
        $this->assertNotEmpty($row['created_at']);
        $this->assertNotEmpty($row['updated_at']);
        unset($row['id'], $row['duration_ms'], $row['created_at'], $row['updated_at']);

        if ($row['detail'] !== null) {
            $row['detail'] = $this->scrub(json_decode($row['detail'], true));
        }

        return $row;
    }

    private function astraAnswer(): array
    {
        return [
            'form' => [
                'name' => 'Juan Dela Cruz', 'phone' => '09171234567', 'house_number' => '12', 'purok_sitio' => '',
                'address' => 'Sampaguita St', 'brgy' => 'Holy Spirit', 'city' => 'Quezon City', 'province' => 'Metro Manila',
                'landmark' => '', 'price' => '', 'quantity' => '',
            ],
            'jnt'          => ['province' => 'METRO-MANILA', 'city' => 'QUEZON-CITY', 'barangay' => 'HOLY SPIRIT'],
            'intent'       => 'order',
            'issues'       => [],
            'needs_human'  => false,
            'human_reason' => '',
            'confidence'   => 'high',
            'evidence'     => 'sinabi ng customer',
        ];
    }

    private function fakeAstra(array $overrides = []): void
    {
        Http::fake([
            'api.openai.com/v1/responses' => Http::response(array_merge([
                'id'     => 'resp_1',
                'output' => [
                    ['type' => 'web_search_call', 'action' => [
                        'query'   => 'Sampaguita St Holy Spirit Quezon City',
                        'sources' => [['url' => 'https://example.test/holy-spirit']],
                    ]],
                    ['type' => 'message', 'content' => [
                        ['type' => 'output_text', 'text' => json_encode($this->astraAnswer())],
                    ]],
                ],
                'usage' => ['input_tokens' => 12000, 'output_tokens' => 1500, 'output_tokens_details' => ['reasoning_tokens' => 900]],
            ], $overrides)),
        ]);
    }

    public function test_astra_engine_returns_the_same_json_and_log_row(): void
    {
        $order = $this->order();
        $this->fakeAstra();

        $response = $this->runRow($order->id, ['engine' => 'astra', 'source' => 'batch', 'batch_id' => 'batch-abc', 'batch_total' => 7]);

        $block = implode("\n", [
            '--- ASTRA 2026-10-04 09:30 ---',
            'Name: Juan Dela Cruz',
            'Phone Number: 09171234567',
            'House Number: 12',
            'Purok/Sitio: -',
            'Address: Sampaguita St',
            'Brgy: Holy Spirit',
            'City: Quezon City',
            'Province: Metro Manila',
            'Landmark: -',
            'Price: -',
            'Quantity: -',
            'J&T: METRO-MANILA | QUEZON-CITY | HOLY SPIRIT',
            'Check: ✅ METRO-MANILA / QUEZON-CITY / HOLY SPIRIT · PROCEED',
            '---',
        ]);
        $evidence = [
            'KEY: settings page (database)',
            'PANCAKE: walang history',
            'WEB: Sampaguita St Holy Spirit Quezon City — https://example.test/holy-spirit',
            'FORM: Name=Juan Dela Cruz · Phone=09171234567 · Addr=Holy Spirit, Quezon City, Metro Manila [high] — sinabi ng customer',
            'LIST: METRO-MANILA | QUEZON-CITY | HOLY SPIRIT',
        ];
        $result = [
            'engine'     => 'astra',
            'status'     => 'fixed',
            'final_code' => '✅',
            'all_filled' => true,
            'gate'       => self::NO_GATE,
            'message'    => null,
        ];
        $log = [
            'engine' => 'astra',
            'passes' => [[
                'pass'       => 1,
                'engine'     => 'astra',
                'chat_chars' => 69,
                'resolve'    => [[
                    'model'  => 'gpt-6-astra',
                    'answer' => [
                        'province' => 'Metro Manila', 'city' => 'Quezon City', 'barangay' => 'Holy Spirit',
                        'province_aliases' => [], 'city_candidates' => [], 'barangay_candidates' => [],
                        'confidence' => 'high', 'evidence' => 'sinabi ng customer',
                        'jnt' => ['province' => 'METRO-MANILA', 'city' => 'QUEZON-CITY', 'barangay' => 'HOLY SPIRIT'],
                        'intent' => 'order', 'issues' => [], 'needs_human' => false, 'human_reason' => '',
                    ],
                    'map'    => ['note' => 'J&T: METRO-MANILA | QUEZON-CITY | HOLY SPIRIT'],
                    'assess' => ['reasons' => []],
                ]],
                'fallbacks'   => [],
                'verify'      => ['province_ok' => true, 'city_ok' => true, 'barangay_ok' => true, 'evidence' => 'J&T labels mula sa list'],
                'before'      => self::SIX_BLANK,
                'after'       => self::SIX_FILLED,
                'updated'     => ['FULL NAME', 'PHONE NUMBER', 'ADDRESS', 'PROVINCE', 'CITY', 'BARANGAY', 'STATUS', 'APP SCRIPT CHECKER'],
                'status_code' => '✅',
                'final_code'  => '✅',
                'gate'        => self::NO_GATE,
                'proceed'     => true,
                'elapsed_ms'  => '<ms>',
            ]],
            'form'      => $this->astraAnswer()['form'],
            'cxd_block' => $block,
            'searches'  => [[
                'step' => 'web_search', 'model' => 'gpt-6-astra',
                'queries' => ['Sampaguita St Holy Spirit Quezon City'], 'sources' => ['https://example.test/holy-spirit'],
            ]],
            'summary' => [
                'engine' => 'astra', 'key_source' => 'settings', 'effort' => 'high', 'models' => ['gpt-6-astra'],
                'escalated' => false, 'searches' => 1, 'tokens_in' => 12000, 'tokens_out' => 1500,
                'cost_usd' => 0.205, 'cost_known' => true, 'elapsed_ms' => '<ms>',
            ],
            'usage'    => [['step' => 'ASTRA', 'model' => 'gpt-6-astra', 'in' => 12000, 'out' => 1500, 'reasoning' => 900, 'searches' => 1, 'cost' => 0.205]],
            'evidence' => $evidence,
        ];

        $response->assertStatus(200);
        $this->assertSame([
            'ok'     => true,
            'engine' => 'astra',
            'result' => $result + ['log' => $log],
            'row'    => ['id' => $order->id] + self::SIX_FILLED + ['APP SCRIPT CHECKER' => '✅', 'STATUS' => 'PROCEED'],
        ], $this->scrub($response->json()));

        $this->assertSame([
            'user_id'         => $this->userId,
            'user_name'       => 'CEO User',
            'source'          => 'batch',
            'batch_id'        => 'batch-abc',
            'batch_total'     => 7,
            'macro_output_id' => $order->id,
            'page'            => 'Likha Shop',
            'item'            => 'Slimming Tea',
            'final_code'      => '✅',
            'all_filled'      => 1,
            'outcome'         => 'fixed',
            'model'           => 'gpt-6-astra',
            'escalated'       => 0,
            'searches'        => 1,
            'tokens_in'       => 12000,
            'tokens_out'      => 1500,
            'cost_usd'        => 0.205,
            'evidence'        => implode("\n", $evidence),
            'detail'          => ['result' => $result] + $log,
        ], $this->onlyLogRow());

        $fresh = MacroOutput::find($order->id);
        $this->assertSame($block, $fresh->CXD);
        $this->assertSame('✅ METRO-MANILA / QUEZON-CITY / HOLY SPIRIT · PROCEED', $fresh->{'AI ANALYZE'});
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer test-key-not-real') && $r['model'] === 'gpt-6-astra');
    }

    public function test_classic_engine_returns_the_same_json_and_log_row(): void
    {
        // Walang COD → pasado ang address (✅) pero bagsak sa gate: TO FIX, hindi PROCEED.
        $order = $this->order(['COD' => null]);
        $resolved = [
            'province' => 'Metro Manila', 'province_aliases' => ['NCR'], 'city' => 'Quezon City', 'city_candidates' => ['Quezon City'],
            'barangay' => 'Holy Spirit', 'barangay_candidates' => ['Holy Spirit'], 'confidence' => 'high', 'evidence' => 'sinabi ng customer',
        ];
        Http::fake([
            // RESOLVE (may web search) — Responses API
            'api.openai.com/v1/responses' => Http::response([
                'output' => [
                    ['type' => 'web_search_call', 'action' => [
                        'query'   => 'Holy Spirit Quezon City',
                        'sources' => [['url' => 'https://example.test/holy-spirit']],
                    ]],
                    ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($resolved)]]],
                ],
                'usage' => ['input_tokens' => 1000, 'output_tokens' => 200, 'output_tokens_details' => ['reasoning_tokens' => 64]],
            ]),
            // NAMEADDR at VERIFYK — Chat Completions; pinag-iiba sa system prompt
            'api.openai.com/v1/chat/completions' => function (Request $request) {
                $verify = str_contains((string) $request['messages'][0]['content'], 'address verifier');

                return Http::response([
                    'choices' => [['message' => ['content' => json_encode($verify
                        ? ['province_ok' => true, 'city_ok' => true, 'barangay_ok' => true, 'evidence' => 'tugma sa chat']
                        : ['full_name' => 'Juan Dela Cruz', 'address_line1' => '12 Sampaguita St', 'phone_number' => '09171234567'])]]],
                    'usage'   => $verify ? ['prompt_tokens' => 600, 'completion_tokens' => 20] : ['prompt_tokens' => 800, 'completion_tokens' => 50],
                ]);
            },
        ]);

        $response = $this->runRow($order->id);

        // Classic: ang ADDRESS ay galing sa NAMEADDR (walang kuwit), at prov/city/brgy ang nauuna sa trace.
        $filled = array_replace(self::SIX_FILLED, ['ADDRESS' => '12 Sampaguita St']);
        $place  = ['PROVINCE' => 'METRO-MANILA', 'CITY' => 'QUEZON-CITY', 'BARANGAY' => 'HOLY SPIRIT'];
        $after  = $place + ['FULL NAME' => 'Juan Dela Cruz', 'PHONE NUMBER' => '9171234567', 'ADDRESS' => '12 Sampaguita St'];
        $gate = ['hard' => ['COD blangko'], 'soft' => []];
        $evidence = [
            'RESOLVE: 1 search [Holy Spirit Quezon City] — sinabi ng customer — https://example.test/holy-spirit',
            'RESOLVE: Holy Spirit, Quezon City, Metro Manila [high] aliases=NCR',
            'MAP: "Quezon City" → QUEZON-CITY, METRO-MANILA (list)',
            'MAP: barangay "Holy Spirit" → HOLY SPIRIT (list)',
            'VERIFYK: tugma sa chat',
            'GATE: TO FIX — COD blangko',
        ];
        $result = [
            'status'     => 'partial',
            'final_code' => 'TO FIX',
            'all_filled' => true,
            'gate'       => $gate,
            'message'    => 'Address ✅ pero bagsak sa validation: COD blangko',
        ];
        $log = [
            'passes' => [[
                'pass'       => 1,
                'chat_chars' => 69,
                'resolve'    => [[
                    'model'  => 'gpt-5.2',
                    'answer' => $resolved + ['_model' => 'gpt-5.2'],
                    'map'    => [
                        'province' => 'METRO-MANILA', 'city' => 'QUEZON-CITY',
                        'note' => '"Quezon City" → QUEZON-CITY, METRO-MANILA (list)', 'city_unmapped' => false, 'city_ambiguous' => false,
                    ],
                    'assess' => [
                        'city_ok' => true, 'brgy_ok' => true, 'prov_ok' => false, 'city_in_chat' => true, 'prov_in_chat' => false,
                        'brgy_in_chat' => true, 'uncertain' => false, 'city_cands' => ['quezon city'], 'brgy_cands' => ['holy spirit'], 'reasons' => [],
                    ],
                ]],
                'fallbacks'   => [],
                'verify'      => ['province_ok' => true, 'city_ok' => true, 'barangay_ok' => true, 'evidence' => 'tugma sa chat'],
                'before'      => ['PROVINCE' => '', 'CITY' => '', 'BARANGAY' => '', 'FULL NAME' => '', 'PHONE NUMBER' => '', 'ADDRESS' => ''],
                'after'       => $after,
                'updated'     => ['PROVINCE', 'CITY', 'BARANGAY', 'FULL NAME', 'ADDRESS', 'PHONE NUMBER', 'APP SCRIPT CHECKER'],
                'status_code' => '✅',
                'final_code'  => 'TO FIX',
                'gate'        => $gate,
                'proceed'     => false,
                'elapsed_ms'  => '<ms>',
            ]],
            'searches' => [['step' => 'RESOLVE', 'model' => 'gpt-5.2', 'queries' => ['Holy Spirit Quezon City'], 'sources' => ['https://example.test/holy-spirit']]],
            'summary'  => [
                'models' => ['gpt-5.2'], 'escalated' => false, 'searches' => 1, 'tokens_in' => 2400, 'tokens_out' => 270,
                'cost_usd' => 0.018, 'elapsed_ms' => '<ms>',
            ],
            'usage' => [
                ['step' => 'RESOLVE',  'model' => 'gpt-5.2', 'in' => 1000, 'out' => 200, 'reasoning' => 64, 'searches' => 1, 'cost' => 0.01455],
                ['step' => 'NAMEADDR', 'model' => 'gpt-5.2', 'in' => 800,  'out' => 50,  'reasoning' => 0,  'searches' => 0, 'cost' => 0.0021],
                ['step' => 'VERIFYK',  'model' => 'gpt-5.2', 'in' => 600,  'out' => 20,  'reasoning' => 0,  'searches' => 0, 'cost' => 0.00133],
            ],
            'evidence' => $evidence,
        ];

        $response->assertStatus(200);
        $this->assertSame([
            'ok'     => true,
            'engine' => 'classic',
            'result' => $result + ['log' => $log],
            'row'    => ['id' => $order->id] + $filled + ['APP SCRIPT CHECKER' => 'TO FIX', 'STATUS' => null],
        ], $this->scrub($response->json()));

        $this->assertSame([
            'user_id'         => $this->userId,
            'user_name'       => 'CEO User',
            'source'          => 'single',
            'batch_id'        => null,
            'batch_total'     => null,
            'macro_output_id' => $order->id,
            'page'            => 'Likha Shop',
            'item'            => 'Slimming Tea',
            'final_code'      => 'TO FIX',
            'all_filled'      => 1,
            'outcome'         => 'partial',
            'model'           => 'gpt-5.2',
            'escalated'       => 0,
            'searches'        => 1,
            'tokens_in'       => 2400,
            'tokens_out'      => 270,
            'cost_usd'        => 0.018,
            'evidence'        => implode("\n", $evidence),
            'detail'          => ['result' => $result] + $log,
        ], $this->onlyLogRow());

        Http::assertSentCount(3);
    }

    public function test_row_not_found_is_a_404_with_no_log_row(): void
    {
        Http::fake();

        $response = $this->runRow(999999, ['engine' => 'astra']);

        $response->assertStatus(404);
        $this->assertSame(['ok' => false, 'error' => 'Row not found'], $response->json());
        $this->assertSame(0, DB::table('ai_checker_logs')->count());
        Http::assertNothingSent();
    }

    public function test_missing_address_list_is_a_500_with_no_log_row(): void
    {
        $order = $this->order();
        Http::fake();

        // Seam: ang loadAddressMaps() ay nagbabasa sa resource_path(); itinuturo ang base path sa folder na wala,
        // kaya "missing" ang jnt_address.txt nang hindi ginagalaw ang file ng repo.
        $basePath = $this->app->basePath();
        $this->app->setBasePath(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'likha-walang-ganitong-folder');
        try {
            $response = $this->runRow($order->id, ['engine' => 'astra']);
        } finally {
            $this->app->setBasePath($basePath);
        }

        $response->assertStatus(500);
        $this->assertSame(['ok' => false, 'error' => 'jnt_address.txt missing or empty'], $response->json());
        $this->assertSame(0, DB::table('ai_checker_logs')->count());
        $this->assertNull(MacroOutput::find($order->id)->STATUS);
        Http::assertNothingSent();
    }

    public function test_an_exception_in_the_engine_is_a_500_and_a_failed_log_row(): void
    {
        $order = $this->order();
        // Sirang sagot ng OpenAI: "id" na array → "Array to string conversion" sa labas ng try ng post() → exception path.
        $this->fakeAstra(['id' => ['hindi', 'string']]);

        $response = $this->runRow($order->id, ['engine' => 'astra', 'source' => 'batch', 'batch_id' => 'batch-abc', 'batch_total' => 7]);

        $response->assertStatus(500);
        $this->assertSame(['ok' => false, 'error' => 'AI check failed. Ref: log #' . DB::table('ai_checker_logs')->value('id')], $response->json());
        $this->assertSame([
            'user_id'         => $this->userId,
            'user_name'       => 'CEO User',
            'source'          => 'batch',
            'batch_id'        => 'batch-abc',
            'batch_total'     => 7,
            'macro_output_id' => $order->id,
            'page'            => 'Likha Shop',
            'item'            => 'Slimming Tea',
            'final_code'      => '❌',
            'all_filled'      => 0,
            'outcome'         => 'failed',
            'model'           => null,
            'escalated'       => 0,
            'searches'        => 0,
            'tokens_in'       => 0,
            'tokens_out'      => 0,
            'cost_usd'        => 0,
            'evidence'        => null,
            'detail'          => null,
        ], $this->onlyLogRow());
        $this->assertNull(MacroOutput::find($order->id)->STATUS);
    }
}

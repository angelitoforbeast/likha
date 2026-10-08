<?php

namespace Tests\Feature\NightRun;

use App\Jobs\RunNightAstraRow;
use App\Models\AppSetting;
use App\Models\MacroOutput;
use App\Models\NightAstraRow;
use App\Models\NightRunStep;
use App\Services\AstraBarangayMatcher;
use App\Services\AstraEncoder;
use App\Services\MacroChecker;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Ang pagkilala ni Astra sa J&T address, at ang switch na `astra_address_rules` sa app_settings
 * (`1` lang ang "on").
 *
 * Ang mga test sa ibaba ay characterization: isinulat sa product code na HINDI pa nagagalaw, kaya
 * ang inaasahan nila ay ang resulta NGAYON. Ang mga literal ay nasa fixtures/astra_018_base.php
 * (kinuha sa pagpapatakbo ng mismong mga sagot na ito sa lumang code), hindi kinukuwenta rito.
 * Ang OpenAI ay laging peke (Http::fake + preventStrayRequests sa base); ang address list ay ang
 * totoong jnt_address.txt ng repo; gawa-gawa ang mga customer.
 */
class AstraAddressRulesTest extends NightAstraTestCase
{
    private const SWITCH  = 'astra_address_rules';
    private const HOST    = 'https://likhaaitech.com';
    private const SIX     = ['FULL NAME', 'PHONE NUMBER', 'ADDRESS', 'PROVINCE', 'CITY', 'BARANGAY'];
    private const NO_GATE = ['hard' => [], 'soft' => []];
    private const RUN_ROW = '/encoder/checker_1/ai-checker/run-row/';
    /** Text na hindi dapat lumabas sa sagot ng browser kapag pumalya ang row. */
    private const MARKER  = 'TAGAS-MARKA-018';

    private static ?array $maps = null;
    private static ?array $fixture = null;

    /** Mga request na ipinadala sa model mula noong huling takbo ng row. */
    private array $sent = [];
    /** Ang sagot ng pekeng model sa susunod na request (iisang Http::fake kada test: ang una lang ang sinusunod). */
    private $respond = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Kapareho ng characterization test ng run-row: tiyak ang model, ang effort at ang presyo, kaya tiyak ang gastos.
        config([
            'services.openai.key'                       => 'test-key-not-real',
            'services.openai.ai_checker_search'         => 'required',
            'services.openai.ai_checker_escalate_model' => '',
            'services.openai.astra_encoder_model'       => 'gpt-6-astra',
            'services.openai.astra_encoder_effort'      => 'high',
            'services.openai.astra_encoder_max_web'     => 4,
            'services.openai.ai_checker_prices'         => ['gpt-5.2' => [1.75, 14.0], 'gpt-6-astra' => [10.0, 50.0], 'web_search' => 0.01],
        ]);
    }

    // ═════════════════════════════════════════════════════════════════════
    //  Mga helper
    // ═════════════════════════════════════════════════════════════════════

    private function maps(): array
    {
        return self::$maps ??= MacroChecker::loadAddressMaps();
    }

    /** Literal na nakuha sa lumang code, ayon sa key. */
    private function pinned(string $key): mixed
    {
        self::$fixture ??= require __DIR__ . '/fixtures/astra_018_base.php';
        $this->assertArrayHasKey($key, self::$fixture);

        return self::$fixture[$key];
    }

    /** Ang label na inaasahan ng test ay dapat nasa totoong list; kung wala na, ang test ang mali, hindi ang code. */
    private function assertInList(string $province, string $city, string $barangay): void
    {
        $labels = $this->maps()['brgysByCityProv'][MacroChecker::normPlace($city) . '|' . MacroChecker::normProv($province)] ?? [];
        $this->assertContains($barangay, $labels, "{$province} | {$city} | {$barangay} ay wala sa jnt_address.txt");
    }

    /** Ang sagot ng model: ang base ng mga kasalukuyang test, pinapalitan kada case. */
    private function answer(array $over = [], array $form = [], array $jnt = []): array
    {
        $base = [
            'form' => array_replace([
                'name' => 'Juan Dela Cruz', 'phone' => '09171234567', 'house_number' => '12', 'purok_sitio' => '',
                'address' => 'Sampaguita St', 'brgy' => 'Holy Spirit', 'city' => 'Quezon City', 'province' => 'Metro Manila',
                'landmark' => '', 'price' => '', 'quantity' => '',
            ], $form),
            'jnt'          => array_replace(['province' => 'METRO-MANILA', 'city' => 'QUEZON-CITY', 'barangay' => 'HOLY SPIRIT'], $jnt),
            'intent'       => 'order',
            'issues'       => [],
            'needs_human'  => false,
            'human_reason' => '',
            'confidence'   => 'high',
            'evidence'     => 'sinabi ng customer',
        ];

        return array_replace($base, $over);
    }

    /** Buong sagot ng Responses API na may isang web search at ang JSON ng $answer. */
    private function modelResponse(array $answer): array
    {
        return [
            'id'     => 'resp_1',
            'output' => [
                ['type' => 'web_search_call', 'action' => [
                    'query'   => 'Sampaguita St Holy Spirit Quezon City',
                    'sources' => [['url' => 'https://example.test/holy-spirit']],
                ]],
                ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($answer)]]],
            ],
            'usage' => ['input_tokens' => 12000, 'output_tokens' => 1500, 'output_tokens_details' => ['reasoning_tokens' => 900]],
        ];
    }

    /**
     * Ang nag-iisang peke ng test. Ang Responses API ay sumasagot ng kung ano ang nasa $this->respond;
     * ang Chat Completions ay ang dalawang maikling tawag ng classic engine (pangalan/address at verify).
     */
    private function fakeModel(): void
    {
        $this->sent = [];
        Http::fake([
            'api.openai.com/v1/responses' => function (Request $request) {
                $this->sent[] = $request;

                return ($this->respond)($request);
            },
            'api.openai.com/v1/chat/completions' => function (Request $request) {
                $this->sent[] = $request;
                $verify = str_contains((string) $request['messages'][0]['content'], 'address verifier');

                return Http::response([
                    'choices' => [['message' => ['content' => json_encode($verify
                        ? ['province_ok' => true, 'city_ok' => true, 'barangay_ok' => true, 'evidence' => 'tugma sa chat']
                        : ['full_name' => 'Juan Dela Cruz', 'address_line1' => '12 Sampaguita St', 'phone_number' => '09171234567'])]]],
                    'usage'   => $verify ? ['prompt_tokens' => 600, 'completion_tokens' => 20] : ['prompt_tokens' => 800, 'completion_tokens' => 50],
                ]);
            },
        ]);
    }

    private function modelSays(array $answer): void
    {
        $this->respond = fn () => Http::response($this->modelResponse($answer));
    }

    /** Ang unang tawag ng classic engine (resolver na may web search), gaya ng characterization test nito. */
    private function classicResolverSays(): void
    {
        $resolved = [
            'province' => 'Metro Manila', 'province_aliases' => ['NCR'], 'city' => 'Quezon City', 'city_candidates' => ['Quezon City'],
            'barangay' => 'Holy Spirit', 'barangay_candidates' => ['Holy Spirit'], 'confidence' => 'high', 'evidence' => 'sinabi ng customer',
        ];
        $this->respond = fn () => Http::response([
            'output' => [
                ['type' => 'web_search_call', 'action' => [
                    'query'   => 'Holy Spirit Quezon City',
                    'sources' => [['url' => 'https://example.test/holy-spirit']],
                ]],
                ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($resolved)]]],
            ],
            'usage' => ['input_tokens' => 1000, 'output_tokens' => 200, 'output_tokens_details' => ['reasoning_tokens' => 64]],
        ]);
    }

    /** Order na may sariling petsa (ika-$day ng buwan): walang "duplicate phone" sa pagitan ng mga case. */
    private function orderOn(int $day, array $extra = [], string $month = '09'): MacroOutput
    {
        return $this->order(array_merge([
            'TIMESTAMP' => sprintf('21:14 %02d-%s-2026', $day, $month),
            'ts_date'   => sprintf('2026-%s-%02d', $month, $day),
        ], $extra));
    }

    /** Ang row method ni Astra, gaya ng tawag ng runner; nililinis muna ang listahan ng mga request. */
    private function runAstra(MacroOutput $order): array
    {
        $this->sent = [];

        return (new AstraEncoder())->processRow($order->id, $this->maps(), self::HOST);
    }

    private function stored(int $id): array
    {
        return (array) DB::table('macro_output')->where('id', $id)->first();
    }

    /** Ang nakikita ng tao at ng log pagkatapos ng isang row. */
    private function observed(MacroOutput $order, array $result): array
    {
        $row = $this->stored($order->id);
        $six = [];
        foreach (self::SIX as $column) {
            $six[$column] = $row[$column];
        }

        return [
            'fields'             => $six,
            'STATUS'             => $row['STATUS'],
            'APP SCRIPT CHECKER' => $row['APP SCRIPT CHECKER'],
            'AI ANALYZE'         => $row['AI ANALYZE'],
            'CXD'                => $row['CXD'],
            'evidence'           => $result['log']['evidence'],
            'gate'               => $result['gate'] ?? null,
            'result'             => $this->decision($result),
        ];
    }

    private function decision(array $result): array
    {
        return [
            'status'     => $result['status'],
            'final_code' => $result['final_code'],
            'proceed'    => $result['log']['passes'][0]['proceed'] ?? null,
        ];
    }

    /** Sampung nakaimbak na sagot ng model: [dagdag sa order, sagot]. `twin` = may isa pang order na parehong phone at petsa. */
    private function tenStoredAnswers(): array
    {
        $typoChat   = "Juan Dela Cruz\n09171234567\n12 Sampaguita St, brgy holy sprit, Quezon City";
        $noBrgyChat = "Juan Dela Cruz\n09171234567\n12 Sampaguita St malapit sa palengke, Quezon City";

        return [
            '01 good line'               => [[], $this->answer()],
            '02 barangay typo'           => [['all_user_input' => $typoChat], $this->answer(['confidence' => 'medium'])],
            '03 no line'                 => [[], $this->answer(['confidence' => 'medium'], [], ['province' => '', 'city' => '', 'barangay' => ''])],
            '04 cancel'                  => [[], $this->answer(['intent' => 'cancel'])],
            '05 inquiry only'            => [[], $this->answer(['intent' => 'inquiry_only'])],
            '06 unclear'                 => [[], $this->answer(['intent' => 'unclear'])],
            '07 model asks for a person' => [[], $this->answer(['needs_human' => true, 'human_reason' => 'dalawang address ang ibinigay ng customer'])],
            '08 duplicate phone'         => [['twin' => true], $this->answer()],
            '09 COD blank'               => [['COD' => null], $this->answer()],
            '10 low confidence'          => [['all_user_input' => $noBrgyChat], $this->answer(['confidence' => 'low'])],
        ];
    }

    /** Pinapatakbo ang sampung sagot, isa kada order. Ibinabalik kada case: ang nakita, ang request at ang resulta. */
    private function runTenRows(): array
    {
        $this->assertInList('METRO-MANILA', 'QUEZON-CITY', 'HOLY SPIRIT');
        $this->fakeModel();

        $out = [];
        $day = 0;
        foreach ($this->tenStoredAnswers() as $name => [$extra, $answer]) {
            $day++;
            $twin = $extra['twin'] ?? false;
            unset($extra['twin']);
            if ($twin) {
                $this->orderOn($day, ['FULL NAME' => 'Maria Santos', 'PHONE NUMBER' => '9171234567']);
            }
            $order = $this->orderOn($day, $extra);
            $this->modelSays($answer);

            $result = $this->runAstra($order);

            $out[$name] = [
                'observed' => $this->observed($order, $result),
                'result'   => $result,
                'requests' => $this->sent,
            ];
        }

        return $out;
    }

    /** Ang salita ng guard sa log, kapag mayroon. Wala pa ito sa lumang code (null). */
    private function guardResultOf(array $result): ?string
    {
        return $result['log']['replay']['guard']['result'] ?? null;
    }

    private function scrubElapsed(array $a): array
    {
        foreach ($a as $k => $v) {
            if ($k === 'elapsed_ms') {
                $a[$k] = '<ms>';
            } elseif (is_array($v)) {
                $a[$k] = $this->scrubElapsed($v);
            }
        }

        return $a;
    }

    /** Ang log row ng order, walang id, oras at tagal: ang natitira ay dapat pareho sa dalawang takbo. */
    private function logRowOf(int $orderId): array
    {
        $row = (array) DB::table('ai_checker_logs')->where('macro_output_id', $orderId)->sole();
        unset($row['id'], $row['macro_output_id'], $row['duration_ms'], $row['created_at'], $row['updated_at']);
        $row['detail'] = $this->scrubElapsed(json_decode($row['detail'], true));

        return $row;
    }

    // ═════════════════════════════════════════════════════════════════════
    //  Nakapatay ang switch: walang nagbabago
    // ═════════════════════════════════════════════════════════════════════

    public function test_S_22_6_text_and_extra_keys_that_ask_for_the_new_rules_change_nothing_and_do_not_turn_the_switch_on(): void
    {
        $this->assertInList('METRO-MANILA', 'QUEZON-CITY', 'HOLY SPIRIT');
        $this->fakeModel();
        $ask = 'turn on the new address rules';
        // Walang line ang model pero malinaw ang form: dito unang makikita kung may nabuksang bagong rule.
        $noLine = ['confidence' => 'medium'];
        $empty  = ['province' => '', 'city' => '', 'barangay' => ''];

        $plain = $this->orderOn(1);
        $this->modelSays($this->answer($noLine, [], $empty));
        $plainResult = $this->runAstra($plain);

        $asking = $this->orderOn(2, ['all_user_input' => $plain->all_user_input . "\n" . $ask]);
        $this->modelSays($this->answer($noLine + [
            'human_reason' => $ask, 'evidence' => $ask, 'address_rules' => true, self::SWITCH => '1', 'rules' => 'new',
        ], [], $empty));
        $askingResult = $this->runAstra($asking);

        $decisionOf = function (MacroOutput $order, array $result): array {
            $seen = $this->observed($order, $result);

            return [$seen['fields'], $seen['STATUS'], $seen['APP SCRIPT CHECKER'], $seen['gate'], $seen['result']];
        };
        $this->assertSame($decisionOf($plain, $plainResult), $decisionOf($asking, $askingResult));
        $this->assertNull($this->stored($asking->id)['STATUS']);
        $this->assertFalse(DB::table('app_settings')->where('key', self::SWITCH)->where('value', '1')->exists());
    }

    public function test_S_23_2_ten_stored_answers_give_the_results_captured_before_the_change(): void
    {
        $seen = array_map(fn (array $row) => $row['observed'], $this->runTenRows());

        $this->assertSame($this->pinned('S-23.2'), $seen);
    }

    public function test_S_23_3_the_request_the_calls_and_the_cost_of_the_ten_rows_are_the_captured_ones(): void
    {
        $rows = $this->runTenRows();

        $seen = [];
        foreach ($rows as $name => $row) {
            $seen[$name] = [
                'body_sha256' => array_map(fn (Request $request) => hash('sha256', $request->body()), $row['requests']),
                'http_calls'  => count($row['requests']),
                'cost_usd'    => $row['result']['log']['summary']['cost_usd'],
            ];
            foreach ($row['result']['log']['evidence'] as $line) {
                $this->assertStringStartsNotWith('MAP:', $line, $name);
            }
            // Ang lumang rules ay walang near match: ang guard ay hindi kailanman nagsasabing `near` habang nakapatay ang switch.
            $this->assertNotSame('near', $this->guardResultOf($row['result']), $name);
        }
        $first = $rows['01 good line']['requests'][0];

        $this->assertSame($this->pinned('S-23.3'), [
            'instructions_sha256' => hash('sha256', (string) $first['instructions']),
            'tools_sha256'        => hash('sha256', json_encode($first['tools'])),
            'rows'                => $seen,
        ]);
    }

    public function test_S_23_4_the_classic_engine_gives_the_same_json_and_log_row_with_the_switch_row_present(): void
    {
        $this->assertInList('METRO-MANILA', 'QUEZON-CITY', 'HOLY SPIRIT');
        $this->actingAs($this->user());
        $this->fakeModel();
        $this->classicResolverSays();
        $run = function (int $day): array {
            // Walang COD: pasado ang address pero bagsak sa gate, gaya ng characterization fixture ng classic.
            $order    = $this->orderOn($day, ['COD' => null]);
            $response = $this->postJson(self::RUN_ROW . $order->id);
            $response->assertStatus(200);
            $json = $this->scrubElapsed($response->json());
            $this->assertSame($order->id, $json['row']['id']);
            unset($json['row']['id']);

            return ['json' => $json, 'log' => $this->logRowOf($order->id)];
        };

        $without = $run(1);
        AppSetting::set(self::SWITCH, '1');
        $with = $run(2);

        $this->assertSame($without, $with);
        $this->assertSame(['classic', 'TO FIX', ['hard' => ['COD blangko'], 'soft' => []]], [$with['json']['engine'], $with['json']['result']['final_code'], $with['json']['result']['gate']]);
        Http::assertSentCount(6); // tatlong tawag kada takbo ng classic
    }

    public function test_S_23_5_the_shared_gate_and_the_classic_mapper_matcher_and_text_check_return_the_captured_arrays(): void
    {
        $maps = $this->maps();
        foreach ([
            ['METRO-MANILA', 'QUEZON-CITY', 'HOLY SPIRIT'], ['COTABATO', 'COTABATO-CITY', 'POBLACION IX'], ['COTABATO', 'COTABATO-CITY', 'POBLACION I'],
            ['COTABATO', 'COTABATO-CITY', 'POBLACION II'], ['CEBU', 'CEBU-CITY', 'SANTA CRUZ (POB.)'], ['CAVITE', 'NAIC', 'IBAYO SILANGAN'],
        ] as [$province, $city, $barangay]) {
            $this->assertInList($province, $city, $barangay);
        }
        $mc = new MacroChecker();
        $mc->setHost(self::HOST);

        // (a) Ang gate, sa tawag ng classic checker (tatlong argument), sa row na may kaparehong phone sa parehong petsa.
        $this->orderOn(1, ['FULL NAME' => 'Maria Santos', 'PHONE NUMBER' => '9171234567']);
        $second = $this->orderOn(1);
        $gate   = $mc->validateRow($second, [
            'FULL NAME' => 'Juan Dela Cruz', 'PHONE NUMBER' => '9171234567', 'ADDRESS' => '12, Sampaguita St',
            'PROVINCE' => 'METRO-MANILA', 'CITY' => 'QUEZON-CITY', 'BARANGAY' => 'HOLY SPIRIT',
        ], $maps);
        $this->assertCount(1, $gate['hard']);
        $this->assertStringStartsWith('PHONE duplicate sa parehong petsa:', $gate['hard'][0]);

        // (b) Ang mapper at ang barangay matcher sa nakapirming input: [barangay, city, province].
        $mapped = [];
        foreach ([
            'holy spirit'                => ['Holy Spirit', 'Quezon City', 'Metro Manila'],
            'cotabato city, poblacion 9' => ['Poblacion 9', 'Cotabato City', 'Maguindanao del Norte'],
            'cebu city, brgy sta cruz'   => ['Brgy. Sta. Cruz', 'Cebu City', 'Cebu'],
            'poblacion 10, no neighbour' => ['Poblacion 10', 'Cotabato City', ''],
            'san jose, no province'      => ['Poblacion', 'San Jose', ''],
            'naga, no province'          => ['Poblacion', 'Naga', ''],
            'not a city'                 => ['Poblacion', "'; DROP TABLE macro_output; --", ''],
        ] as $name => [$barangay, $city, $province]) {
            $line   = $mc->mapResolvedToList(['province' => $province, 'city' => $city, 'province_aliases' => [], 'confidence' => 'medium'], $maps);
            $labels = $line['city'] !== null
                ? ($maps['brgysByCityProv'][MacroChecker::normPlace($line['city']) . '|' . MacroChecker::normProv((string) $line['province'])] ?? [])
                : [];
            $mapped[$name] = ['mapped' => $line, 'barangay' => $labels ? $mc->matchBarangayInList($barangay, $labels) : null];
        }

        // Ang text check ng classic ay private: nakikita ito sa `brgy_in_chat` ng assessResolved. [barangay, city, province, chat]
        $assessed = [];
        foreach ([
            'the exact phrase'               => ['Holy Spirit', 'Quezon City', 'Metro Manila', '12 Sampaguita St, Holy Spirit, Quezon City'],
            'a near match'                   => ['Ibayo Silangan', 'Naic', 'Cavite', 'Blk 4 ibayo silngan, Naic, Cavite'],
            'another number, bridged today'  => ['Poblacion 1', 'Cotabato City', 'Cotabato', '45 Sinsuat Ave, poblacion 2, Cotabato City'],
            'not in the chat'                => ['Holy Spirit', 'Quezon City', 'Metro Manila', '12 Sampaguita St, Quezon City'],
        ] as $name => [$barangay, $city, $province, $chat]) {
            $resolved = [
                'province' => $province, 'province_aliases' => [], 'city' => $city, 'city_candidates' => [],
                'barangay' => $barangay, 'barangay_candidates' => [], 'confidence' => 'high',
            ];
            $assessed[$name] = $mc->assessResolved($resolved, $mc->mapResolvedToList($resolved, $maps), $chat, $maps);
        }

        $this->assertSame($this->pinned('S-23.5'), ['gate' => $gate, 'mapped' => $mapped, 'assessed' => $assessed]);
    }

    public function test_S_23_6_the_program_adds_no_model_call_of_its_own_with_the_switch_row_present(): void
    {
        $this->assertInList('METRO-MANILA', 'QUEZON-CITY', 'HOLY SPIRIT');
        AppSetting::set(self::SWITCH, '1');
        $this->fakeModel();
        $final = $this->modelResponse($this->answer());

        // Isang round: ang sagot agad.
        $this->respond = fn () => Http::response($final);
        $oneRound = $this->runAstra($this->orderOn(1));
        $this->assertCount(1, $this->sent);

        // Dalawang round: humingi muna ang model ng paghahanap sa list, saka sumagot.
        $this->respond = fn () => Http::response(count($this->sent) === 1
            ? [
                'id'     => 'resp_0',
                'output' => [['type' => 'function_call', 'name' => 'jnt_address_search', 'call_id' => 'call_1', 'arguments' => json_encode(['query' => 'holy spirit quezon'])]],
                'usage'  => ['input_tokens' => 9000, 'output_tokens' => 300],
            ]
            : $final);
        $twoRounds = $this->runAstra($this->orderOn(2));
        $this->assertCount(2, $this->sent);

        $this->assertSame(['fixed', 'fixed'], [$oneRound['status'], $twoRounds['status']]);
    }

    public function test_S_27_6_a_cancel_gets_the_cancel_code_and_the_gate_is_not_run(): void
    {
        $this->assertInList('METRO-MANILA', 'QUEZON-CITY', 'HOLY SPIRIT');
        $this->fakeModel();
        $this->modelSays($this->answer(['intent' => 'cancel']));
        $order = $this->orderOn(1);
        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = $query->sql;
        });

        $result = $this->runAstra($order);

        $row = $this->stored($order->id);
        $this->assertSame(['CANCEL?', null], [$row['APP SCRIPT CHECKER'], $row['STATUS']]);
        $this->assertSame(['partial', 'CANCEL?', self::NO_GATE, false], [$result['status'], $result['final_code'], $result['gate'], $result['log']['passes'][0]['proceed']]);
        // Hindi tumakbo ang gate: walang binasang blacklist o whitelist.
        $this->assertNotEmpty($statements);
        $this->assertSame([], array_values(array_filter($statements, fn (string $sql) => preg_match('/blacklist|whitelist/i', $sql) === 1)));
    }

    public function test_S_28_2_a_same_date_duplicate_phone_holds_the_astra_row(): void
    {
        $this->assertInList('METRO-MANILA', 'QUEZON-CITY', 'HOLY SPIRIT');
        $this->fakeModel();
        $this->modelSays($this->answer());
        $first  = $this->orderOn(1, ['FULL NAME' => 'Maria Santos', 'PHONE NUMBER' => '9171234567']);
        $second = $this->orderOn(1);

        $result = $this->runAstra($second);

        $row = $this->stored($second->id);
        $this->assertSame(['TO FIX', null], [$row['APP SCRIPT CHECKER'], $row['STATUS']]);
        $this->assertSame(['partial', 'TO FIX', false], [$result['status'], $result['final_code'], $result['log']['passes'][0]['proceed']]);
        $this->assertSame(
            ['hard' => ['PHONE duplicate sa parehong petsa: #' . $first->id . ' Maria Santos (Likha Shop · walang status)'], 'soft' => []],
            $result['gate']
        );
    }

    public function test_S_28_3_the_classic_engine_still_reports_the_duplicate_with_the_switch_row_present(): void
    {
        $this->assertInList('METRO-MANILA', 'QUEZON-CITY', 'HOLY SPIRIT');
        AppSetting::set(self::SWITCH, '1');
        $this->actingAs($this->user());
        $this->fakeModel();
        $this->classicResolverSays();
        $first  = $this->orderOn(1, ['FULL NAME' => 'Maria Santos', 'PHONE NUMBER' => '9171234567']);
        $second = $this->orderOn(1);

        $response = $this->postJson(self::RUN_ROW . $second->id);

        $response->assertStatus(200);
        $this->assertSame('classic', $response->json('engine'));
        $this->assertSame('TO FIX', $response->json('result.final_code'));
        $this->assertSame(
            ['hard' => ['PHONE duplicate sa parehong petsa: #' . $first->id . ' Maria Santos (Likha Shop · walang status)'], 'soft' => []],
            $response->json('result.gate')
        );
        $this->assertNull($this->stored($second->id)['STATUS']);
    }

    // ═════════════════════════════════════════════════════════════════════
    //  Ang night job
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Ang parehong lupa ay sakop na ng mga kasalukuyang test, na hindi ginagalaw:
     *  - NightAstraRowJobTest::test_a_rejected_key_or_exhausted_credit_stops_the_run_at_once (401, 403, walang credit)
     *  - NightAstraRowJobTest::test_a_transient_failure_is_retried_once_after_65_seconds_and_never_a_third_time (5xx, ang retry)
     *  - NightAstraRowJobTest::test_a_final_failure_gets_a_fixed_reason_and_nothing_from_openai_or_the_exception (ang mga reason)
     *  - NightAstraRowJobTest::test_a_status_set_during_the_call_leaves_the_order_as_the_person_left_it (ang skip)
     *  - AstraEncoderAdditionsTest::test_the_last_transport_failure_is_remembered_without_body_or_message (ang uri ng error)
     * Ang idinadagdag dito: pareho pa rin ang lahat ng iyon kapag nasa settings na ang row ng switch.
     */
    public function test_S_29_7_model_failures_and_a_status_set_by_a_person_are_handled_as_today_with_the_switch_row_present(): void
    {
        $this->assertInList('METRO-MANILA', 'QUEZON-CITY', 'HOLY SPIRIT');
        AppSetting::set(self::SWITCH, '1');
        $this->fakeModel();
        $error = fn (int $status, string $type, ?string $code) => fn () => Http::response(['error' => ['message' => 'May problema: ' . self::MARKER, 'type' => $type, 'param' => null, 'code' => $code]], $status);
        $cases = [
            'authentication error (401)' => $error(401, 'invalid_request_error', 'invalid_api_key'),
            'quota error (429)'          => $error(429, 'insufficient_quota', 'insufficient_quota'),
            'server error (500)'         => $error(500, 'server_error', null),
            'connection error'           => fn () => throw new ConnectionException('cURL error 28: ' . self::MARKER),
            'a person sets STATUS'       => null,
        ];

        $seen = [];
        $day  = 0;
        foreach ($cases as $name => $respond) {
            NightAstraRow::query()->delete();
            NightRunStep::query()->delete();
            $order  = $this->orderOn(++$day);
            $step   = $this->runningStep([$order->id]);
            $asLeft = $this->stored($order->id);
            $this->respond = $respond ?? function () use ($order, &$asLeft) {
                DB::table('macro_output')->where('id', $order->id)->update(['STATUS' => 'CANNOT PROCEED', 'FULL NAME' => 'Inilagay Ng Tao']);
                $asLeft = $this->stored($order->id);

                return Http::response($this->modelResponse($this->answer()));
            };
            $this->sent   = [];
            $pushedBefore = Queue::pushed(RunNightAstraRow::class)->count();

            $row = $this->work($order->id);

            $pushed = Queue::pushed(RunNightAstraRow::class);
            $step->refresh();
            $seen[$name] = [
                'row'               => ['state' => $row->state, 'reason' => $row->reason, 'attempts' => (int) $row->attempts, 'proceed' => $row->proceed, 'code' => $row->code],
                'cost_usd'          => number_format((float) $row->cost_usd, 4),
                'requeued'          => $pushed->count() - $pushedBefore,
                'retry_delay'       => $pushed->count() > $pushedBefore ? $pushed->last()->delay : null,
                'step'              => ['state' => $step->state, 'reason' => $step->reason, 'consecutive_failures' => (int) $step->consecutive_failures],
                'http_calls'        => count($this->sent),
                'order_not_written' => $this->stored($order->id) === $asLeft,
            ];
        }

        $this->assertSame($this->pinned('S-29.7'), $seen);
        $this->assertStringNotContainsString(self::MARKER, json_encode([NightAstraRow::all()->toArray(), NightRunStep::all()->toArray(), $this->logLines->getArrayCopy()]));
    }

    /**
     * Limang row ng isang gabi, sa pamamagitan ng job: [state, proceed, code, reason, STATUS ng order] kada row,
     * ang estado ng step, ang bilang ng tawag at ang kabuuang gastos. Magkakaibang phone ang mga order
     * (parehong petsa ng gabi), maliban sa sadyang may kapareho.
     */
    private function runFiveNightRows(): array
    {
        $this->assertInList('METRO-MANILA', 'QUEZON-CITY', 'HOLY SPIRIT');
        $this->fakeModel();
        $rows = [
            'proceed by the model line'  => [],
            'no line, a mappable form'   => [['confidence' => 'medium'], ['province' => '', 'city' => '', 'barangay' => '']],
            'cancel'                     => [['intent' => 'cancel']],
            'duplicate phone'            => [],
            'held by the model itself'   => [['needs_human' => true, 'human_reason' => 'dalawang address ang ibinigay ng customer']],
        ];
        $orders  = [];
        $answers = [];
        $n = 0;
        foreach ($rows as $name => $spec) {
            $phone = '091712345' . sprintf('%02d', ++$n);
            $orders[$name]  = $this->order(['all_user_input' => "Juan Dela Cruz\n{$phone}\n12 Sampaguita St, Holy Spirit, Quezon City"]);
            $answers[$name] = $this->answer($spec[0] ?? [], ['phone' => $phone], $spec[1] ?? []);
        }
        // Ang kapareho ng phone: order ng parehong petsa na PROCEED na at hindi kasama sa gabi.
        $this->order(['FULL NAME' => 'Maria Santos', 'PHONE NUMBER' => '9171234504', 'STATUS' => 'PROCEED']);
        $step = $this->runningStep(array_map(fn (MacroOutput $order) => $order->id, array_values($orders)));

        $seen = [];
        foreach ($orders as $name => $order) {
            $this->modelSays($answers[$name]);
            $row = $this->work($order->id);
            $seen[$name] = ['state' => $row->state, 'proceed' => $row->proceed, 'code' => $row->code, 'reason' => $row->reason, 'STATUS' => $this->stored($order->id)['STATUS']];
        }

        return [
            'rows'       => $seen,
            'step'       => $step->fresh()->state,
            'http_calls' => count($this->sent),
            'cost_usd'   => number_format((float) NightAstraRow::where('step_id', $step->id)->sum('cost_usd'), 4),
        ];
    }

    public function test_S_29_8_five_night_rows_end_as_today(): void
    {
        $this->assertSame($this->pinned('S-29.8')['switch off'], $this->runFiveNightRows());
    }

    // ═════════════════════════════════════════════════════════════════════
    //  Maling uri ng value sa sagot ng model
    // ═════════════════════════════════════════════════════════════════════

    public function test_S_33_4_a_wrong_type_in_the_answer_ends_as_a_failed_or_held_row_with_the_fixed_message(): void
    {
        $this->assertInList('METRO-MANILA', 'QUEZON-CITY', 'HOLY SPIRIT');
        $this->actingAs($this->user());
        $this->fakeModel();
        $base = ['evidence' => self::MARKER];
        // [uri, sagot]. `array` = ARRAY kung saan string ang inaasahan; ang iba ay binabasa at tumatakbo ang row.
        $cases = [
            'array: form.brgy'      => ['array', $this->answer($base, ['brgy' => ['Holy Spirit']])],
            'array: jnt.barangay'   => ['array', $this->answer($base, [], ['barangay' => ['HOLY SPIRIT']])],
            'array: human_reason'   => ['array', $this->answer($base + ['human_reason' => ['kailangan ng tao']])],
            'array: intent'         => ['array', $this->answer($base + ['intent' => ['order']])],
            'array: confidence'     => ['array', $this->answer($base + ['confidence' => ['high']])],
            'number: form.brgy'     => ['runs', $this->answer($base, ['brgy' => 28])],
            'number: jnt.barangay'  => ['runs', $this->answer($base, [], ['barangay' => 28])],
            'number: human_reason'  => ['runs', $this->answer($base + ['human_reason' => 5])],
            'number: intent'        => ['runs', $this->answer($base + ['intent' => 7])],
            'number: confidence'    => ['runs', $this->answer($base + ['confidence' => 3])],
            'null: form.brgy'       => ['runs', $this->answer($base, ['brgy' => null])],
            'null: jnt.barangay'    => ['runs', $this->answer($base, [], ['barangay' => null])],
            'null: human_reason'    => ['runs', $this->answer($base + ['human_reason' => null])],
            'null: intent'          => ['runs', $this->answer($base + ['intent' => null])],
            'null: confidence'      => ['runs', $this->answer($base + ['confidence' => null])],
            'null: form'            => ['runs', $this->answer($base + ['form' => null])],
            'a string: form'        => ['runs', $this->answer($base + ['form' => 'Holy Spirit Quezon City'])],
            'a list: form'          => ['runs', $this->answer($base + ['form' => ['Holy Spirit', 'Quezon City']])],
            'null: jnt'             => ['runs', $this->answer($base + ['jnt' => null])],
            'a string: jnt'         => ['runs', $this->answer($base + ['jnt' => 'HOLY SPIRIT'])],
            'a list: jnt'           => ['runs', $this->answer($base + ['jnt' => ['METRO-MANILA', 'QUEZON-CITY', 'HOLY SPIRIT']])],
        ];

        $seen = [];
        $day  = 0;
        foreach ($cases as $name => [$kind, $answer]) {
            $order  = $this->orderOn(++$day, [], '08');
            $before = $this->stored($order->id);
            $this->modelSays($answer);

            $response = $this->postJson(self::RUN_ROW . $order->id, ['engine' => 'astra']);

            $after = $this->stored($order->id);
            $seen[$name] = [
                'http'          => $response->getStatusCode(),
                'result_status' => $response->json('result.status'),
                'final_code'    => $response->json('result.final_code'),
                'STATUS'        => $after['STATUS'],
            ];
            if ($kind === 'array') {
                $response->assertStatus(500);
                $this->assertSame(['ok', 'error'], array_keys($response->json()), $name);
                $this->assertFalse($response->json('ok'), $name);
                $this->assertMatchesRegularExpression('/^AI check failed\. Ref: log #\d+$/', $response->json('error'), $name);
                foreach ([self::MARKER, 'Exception', 'Array to string', 'Stack trace', '#0 ', '.php', 'Sampaguita', 'Juan Dela Cruz'] as $leak) {
                    $this->assertStringNotContainsString($leak, $response->getContent(), $name);
                }
                $this->assertSame($before, $after, $name);
            } else {
                $response->assertStatus(200);
                $this->assertContains($response->json('result.status'), ['fixed', 'partial'], $name);
            }
        }

        $this->assertSame($this->pinned('S-33.4'), $seen);
    }

    // ═════════════════════════════════════════════════════════════════════
    //  Ang text check ng barangay (pure): tinatawag nang direkta, kasama ang lahat ng label ng city
    // ═════════════════════════════════════════════════════════════════════

    private const PHRASE = ['result' => 'phrase', 'score' => 100];
    private const NONE   = ['result' => 'none', 'score' => 0];

    private const QC_HOLY_SPIRIT    = ['METRO-MANILA', 'QUEZON-CITY', 'HOLY SPIRIT'];
    private const COTABATO_POB      = ['COTABATO', 'COTABATO-CITY', 'POBLACION'];
    private const COTABATO_POB_2    = ['COTABATO', 'COTABATO-CITY', 'POBLACION II'];
    private const COTABATO_POB_9    = ['COTABATO', 'COTABATO-CITY', 'POBLACION IX'];
    private const CEBU_SANTA_CRUZ   = ['CEBU', 'CEBU-CITY', 'SANTA CRUZ (POB.)'];
    private const NASIPIT_BRGY_1    = ['AGUSAN-DEL-NORTE', 'NASIPIT', 'BARANGAY 1 (POB.)'];
    private const NASIPIT_BRGY_2    = ['AGUSAN-DEL-NORTE', 'NASIPIT', 'BARANGAY 2 (POB.)'];
    private const NASIPIT_BRGY_5    = ['AGUSAN-DEL-NORTE', 'NASIPIT', 'BARANGAY 5 (POB.)'];
    private const CABADBARAN_POB_1  = ['AGUSAN-DEL-NORTE', 'CABADBARAN-CITY', 'POBLACION 1'];
    private const CABADBARAN_POB_2  = ['AGUSAN-DEL-NORTE', 'CABADBARAN-CITY', 'POBLACION 2'];
    private const CABADBARAN_POB_10 = ['AGUSAN-DEL-NORTE', 'CABADBARAN-CITY', 'POBLACION 10'];
    private const CABADBARAN_POB_12 = ['AGUSAN-DEL-NORTE', 'CABADBARAN-CITY', 'POBLACION 12'];
    private const DASMA_ZONE_1      = ['CAVITE', 'DASMARINAS-CITY', 'ZONE I (POB.)'];
    private const DASMA_ZONE_1B     = ['CAVITE', 'DASMARINAS-CITY', 'ZONE I-B'];
    private const CALOOCAN_BRGY_28  = ['METRO-MANILA', 'CALOOCAN', 'BARANGAY 28'];
    private const BINONDO_BRGY_287  = ['METRO-MANILA', 'BINONDO', 'BARANGAY 287'];
    private const NAIC_IBAYO_SIL    = ['CAVITE', 'NAIC', 'IBAYO SILANGAN'];
    private const LIGAO_BAY         = ['ALBAY', 'LIGAO-CITY', 'BAY'];
    private const STO_TOMAS_EAST    = ['PANGASINAN', 'PANGASINAN-SANTO-TOMAS', 'POBLACION EAST'];
    private const STO_TOMAS_WEST    = ['PANGASINAN', 'PANGASINAN-SANTO-TOMAS', 'POBLACION WEST'];
    private const AGOO_SANTA_MARIA  = ['LA-UNION', 'AGOO', 'SANTA MARIA'];
    private const AGOO_SANTA_RITA   = ['LA-UNION', 'AGOO', 'SANTA RITA (NALINAC)'];
    private const AGOO_SAN_ANTONIO  = ['LA-UNION', 'AGOO', 'SAN ANTONIO'];
    private const AGOO_SAN_ANTONINO = ['LA-UNION', 'AGOO', 'SAN ANTONINO'];
    private const VIRAC_SAN_VICENTE = ['CATANDUANES', 'VIRAC', 'SAN VICENTE'];
    private const VIRAC_DUGUI_SV    = ['CATANDUANES', 'VIRAC', 'DUGUI SAN VICENTE'];
    private const VIRAC_IBONG_SAPA  = ['CATANDUANES', 'VIRAC', 'IBONG SAPA (SAN VICENTE SUR)'];
    private const ABRA_BA_UG        = ['ABRA', 'ABRA-SAN-JUAN', 'BA-UG'];

    /** Lahat ng label ng city ng line, mula sa totoong list. */
    private function cityLabels(array $line): array
    {
        return $this->maps()['brgysByCityProv'][MacroChecker::normPlace($line[1]) . '|' . MacroChecker::normProv($line[0])] ?? [];
    }

    /** Ang text check sa isang line ng totoong list: [province, city, barangay]. */
    private function confirmed(string $text, array $line): array
    {
        return AstraBarangayMatcher::confirm($text, $line[2], $this->cityLabels($line));
    }

    private function assertLinesInList(array ...$lines): void
    {
        foreach ($lines as $line) {
            $this->assertInList(...$line);
        }
    }

    /** Bawat row: [text, line, inaasahang sagot]. */
    private function assertConfirmTable(array $rows): void
    {
        foreach ($rows as $name => [$text, $line, $expected]) {
            $this->assertSame($expected, $this->confirmed($text, $line), (string) $name);
        }
    }

    public function test_S_25_2_abbreviations_double_spaces_a_non_breaking_space_and_capitals_are_confirmed(): void
    {
        $this->assertLinesInList(self::COTABATO_POB, self::CEBU_SANTA_CRUZ, self::QC_HOLY_SPIRIT);

        $this->assertConfirmTable([
            'Pob.'                         => ['Pob.', self::COTABATO_POB, self::PHRASE],
            'Sta. Cruz'                    => ['Sta. Cruz', self::CEBU_SANTA_CRUZ, self::PHRASE],
            'double space, nbsp, capitals' => ["BRGY.  HOLY \u{00A0}SPIRIT", self::QC_HOLY_SPIRIT, self::PHRASE],
        ]);
    }

    public function test_S_25_5_a_part_of_the_name_and_a_three_letter_near_miss_are_not_confirmed(): void
    {
        $this->assertLinesInList(self::NAIC_IBAYO_SIL, self::LIGAO_BAY);

        $this->assertConfirmTable([
            'half of the name'            => ['Blk 4 ibayo, Naic, Cavite', self::NAIC_IBAYO_SIL, self::NONE],
            'three letters, one is wrong' => ['Purok 2 Bai Ligao City', self::LIGAO_BAY, self::NONE],
        ]);
    }

    public function test_S_25_8_the_forms_own_wording_confirms_only_when_it_maps_to_that_very_label(): void
    {
        $this->assertLinesInList(self::ABRA_BA_UG, self::COTABATO_POB, self::COTABATO_POB_2, self::QC_HOLY_SPIRIT);
        $withWording = fn (string $text, array $line, string $wording) => AstraBarangayMatcher::confirmWithWording($text, $line[2], $wording, $this->cityLabels($line));

        // Ang label lang: ang "ba ug" ay wala sa text na "Baug" (masyadong maikli para sa dikit o halos-tugma).
        $this->assertSame(self::NONE, $this->confirmed('taga Baug po kami, San Juan Abra', self::ABRA_BA_UG));

        $seen = [
            'the wording maps to this label'       => $withWording('taga Baug po kami, San Juan Abra', self::ABRA_BA_UG, 'Baug'),
            'the label itself is in the text'      => $withWording('Brgy Holy Spirit, QC', self::QC_HOLY_SPIRIT, 'wala'),
            'the wording maps to another label'    => $withWording('Brgy Poblacion, Cotabato City', self::COTABATO_POB_2, 'Poblacion'),
            'the wording is the city, not a label' => $withWording('12 Sampaguita St, Quezon City', self::QC_HOLY_SPIRIT, 'Quezon City'),
            'the wording is not in the text'       => $withWording('San Juan Abra', self::ABRA_BA_UG, 'Baug'),
            'an empty wording'                     => $withWording('taga Baug po kami', self::ABRA_BA_UG, ''),
        ];

        $this->assertSame([
            'the wording maps to this label'       => self::PHRASE,
            'the label itself is in the text'      => self::PHRASE,
            'the wording maps to another label'    => self::NONE,
            'the wording is the city, not a label' => self::NONE,
            'the wording is not in the text'       => self::NONE,
            'an empty wording'                     => self::NONE,
        ], $seen);
    }

    public function test_S_25_9_a_chat_of_300000_characters_gives_the_result_of_the_short_chat(): void
    {
        $this->assertLinesInList(self::QC_HOLY_SPIRIT);
        $short = '12 Sampaguita St, brgy holy sprit, Quezon City';
        $long  = str_repeat("magkano po ang shipping\n", 12500) . $short;
        $this->assertGreaterThanOrEqual(300000, strlen($long));

        // "holy sprit" laban sa "holy spirit": 95.2 ang similar_text, kaya 95.
        $near = ['result' => 'near', 'score' => 95];
        $this->assertSame([$near, $near], [$this->confirmed($short, self::QC_HOLY_SPIRIT), $this->confirmed($long, self::QC_HOLY_SPIRIT)]);
        // Isang salitang 300,000 character, walang espasyo.
        $this->assertSame(self::NONE, $this->confirmed(str_repeat('holyspirit', 30000), self::QC_HOLY_SPIRIT));
    }

    public function test_S_25_10_invalid_utf8_does_not_throw_and_confirms_nothing(): void
    {
        $this->assertLinesInList(self::QC_HOLY_SPIRIT, self::NASIPIT_BRGY_1);

        $this->assertConfirmTable([
            'bytes that are no text at all'   => ["\xC3\x28\xFF\xFE\xA0\xA1", self::QC_HOLY_SPIRIT, self::NONE],
            'a bad byte instead of the space' => ["Brgy Holy\xFFSpirit, QC", self::QC_HOLY_SPIRIT, self::NONE],
            'a bad byte before the number'    => ["Brgy \xE2\x821, Nasipit", self::NASIPIT_BRGY_1, self::NONE],
            'a character cut in half'         => ["Quezon City \xF0\x9F", self::QC_HOLY_SPIRIT, self::NONE],
        ]);
        $this->assertSame(self::NONE, AstraBarangayMatcher::confirmWithWording("Holy\xFFSpirit", 'HOLY SPIRIT', "Holy\xFFSpirit", $this->cityLabels(self::QC_HOLY_SPIRIT)));
    }

    public function test_S_26_1_a_one_digit_numbered_barangay_is_confirmed_by_its_number(): void
    {
        $this->assertLinesInList(self::NASIPIT_BRGY_1);

        $this->assertSame(self::PHRASE, $this->confirmed('Purok 3 Brgy. 1, Nasipit, Agusan del Norte', self::NASIPIT_BRGY_1));
    }

    public function test_S_26_2_roman_and_arabic_forms_of_the_same_number_are_equal(): void
    {
        $this->assertLinesInList(self::COTABATO_POB_9, self::CABADBARAN_POB_1);

        $this->assertConfirmTable([
            'IX written as 9' => ['45 Sinsuat Ave, poblacion 9, Cotabato City', self::COTABATO_POB_9, self::PHRASE],
            '1 written as I'  => ['Purok 2, Poblacion I, Cabadbaran City', self::CABADBARAN_POB_1, self::PHRASE],
        ]);
    }

    public function test_S_26_3_every_way_of_writing_the_barangay_word_before_the_number_is_confirmed(): void
    {
        $this->assertLinesInList(self::NASIPIT_BRGY_1);

        $rows = [];
        foreach (['Barangay 1', 'BRGY. 1', 'Bgy 1', 'Brgy #1', 'Brgy: 1'] as $text) {
            $rows[$text] = [$text, self::NASIPIT_BRGY_1, self::PHRASE];
        }
        $this->assertConfirmTable($rows);
    }

    public function test_S_26_4_another_number_is_never_confirmed_by_phrase_or_by_near_match(): void
    {
        $this->assertLinesInList(self::CABADBARAN_POB_1, self::CABADBARAN_POB_2, self::NASIPIT_BRGY_1, self::NASIPIT_BRGY_2);

        $this->assertConfirmTable([
            'Poblacion 2 for POBLACION 1' => ['Purok 2, Poblacion 2, Cabadbaran City', self::CABADBARAN_POB_1, self::NONE],
            'Brgy 2 for BARANGAY 1'       => ['Brgy 2, Nasipit', self::NASIPIT_BRGY_1, self::NONE],
        ]);
    }

    public function test_S_26_5_a_number_is_compared_as_a_whole_number(): void
    {
        $this->assertLinesInList(self::CALOOCAN_BRGY_28, self::BINONDO_BRGY_287);

        $this->assertConfirmTable([
            'Barangay 287 for BARANGAY 28' => ['Barangay 287, Caloocan', self::CALOOCAN_BRGY_28, self::NONE],
            'Brgy 28 for BARANGAY 287'     => ['Brgy 28, Binondo, Manila', self::BINONDO_BRGY_287, self::NONE],
        ]);
    }

    public function test_S_26_6_no_match_across_a_digit(): void
    {
        $this->assertLinesInList(self::CABADBARAN_POB_1, self::CABADBARAN_POB_10, self::CABADBARAN_POB_12);

        $this->assertConfirmTable([
            'Poblacion 12' => ['Poblacion 12, Cabadbaran City', self::CABADBARAN_POB_1, self::NONE],
            'Poblacion 10' => ['Poblacion 10, Cabadbaran City', self::CABADBARAN_POB_1, self::NONE],
        ]);
    }

    public function test_S_26_7_a_number_or_letter_the_label_does_not_have_is_never_bridged(): void
    {
        $this->assertLinesInList(self::COTABATO_POB, self::COTABATO_POB_9, self::DASMA_ZONE_1B, self::DASMA_ZONE_1);

        $this->assertConfirmTable([
            'Poblacion 9 for the bare POBLACION' => ['45 Sinsuat Ave, Poblacion 9, Cotabato City', self::COTABATO_POB, self::NONE],
            'both Poblacion and Poblacion 9'     => ["Brgy Poblacion\nPoblacion 9 po pala, Cotabato City", self::COTABATO_POB, self::NONE],
            'a full stop before the number'      => ['Brgy Poblacion. 9 Cotabato City', self::COTABATO_POB, self::NONE],
            'Zone I-A for ZONE I-B'              => ['Zone I-A, Dasmarinas City', self::DASMA_ZONE_1B, self::NONE],
            'Zone 1 for ZONE I-B'                => ['Zone 1, Dasmarinas City', self::DASMA_ZONE_1B, self::NONE],
        ]);
    }

    public function test_S_26_8_a_number_alone_counts_only_when_attached_to_a_barangay_word(): void
    {
        $this->assertLinesInList(self::CALOOCAN_BRGY_28);

        $this->assertSame(self::NONE, $this->confirmed('bili po ako ng 28 pcs, house 28', self::CALOOCAN_BRGY_28));
    }

    public function test_S_26_10_a_number_on_the_next_line_is_never_joined_to_the_name(): void
    {
        $this->assertLinesInList(self::COTABATO_POB_2, self::NASIPIT_BRGY_1);

        $this->assertConfirmTable([
            'a line break'       => ["Brgy Poblacion\n2 pcs po", self::COTABATO_POB_2, self::NONE],
            'a comma'            => ['Brgy Poblacion, 2 pcs po', self::COTABATO_POB_2, self::NONE],
            'a dash with spaces' => ['Brgy Poblacion - 2 pcs po', self::COTABATO_POB_2, self::NONE],
            'a slash'            => ['Brgy Poblacion/2 pcs po', self::COTABATO_POB_2, self::NONE],
            'a bracket'          => ['Brgy Poblacion (2 pcs po)', self::COTABATO_POB_2, self::NONE],
            'the barangay word on the line before the number' => ["Brgy\n1 pc lang po", self::NASIPIT_BRGY_1, self::NONE],
            // Ang tuldok ng dulo ng pangungusap ay hindi rin tinatawid; ang tuldok ng daglat ay bahagi ng pangalan.
            'a full stop'                 => ['Brgy Poblacion. 2 pcs po', self::COTABATO_POB_2, self::NONE],
            'a full stop without a space' => ['Brgy Poblacion.2 pcs po', self::COTABATO_POB_2, self::NONE],
            'a question mark after the barangay word' => ['Brgy? 1 pc lang po', self::NASIPIT_BRGY_1, self::NONE],
            'a closing bracket'           => ['Brgy Poblacion) 2 pcs po', self::COTABATO_POB_2, self::NONE],
            'the stop of an abbreviation' => ['Brgy Pob. 2 Cotabato City', self::COTABATO_POB_2, self::PHRASE],
        ]);
    }

    public function test_S_26_11_a_street_initial_after_a_comma_is_not_a_suffix_letter(): void
    {
        $this->assertLinesInList(self::DASMA_ZONE_1B);

        $this->assertSame(self::NONE, $this->confirmed('Zone 1, B. Aquino St, Dasmarinas City', self::DASMA_ZONE_1B));
    }

    public function test_S_26_12_digits_written_apart_are_not_one_number(): void
    {
        $this->assertLinesInList(self::CABADBARAN_POB_12, self::CABADBARAN_POB_1);

        $this->assertConfirmTable([
            'for POBLACION 12' => ['Poblacion 1 2 boxes', self::CABADBARAN_POB_12, self::NONE],
            'for POBLACION 1'  => ['Poblacion 1 2 boxes', self::CABADBARAN_POB_1, self::NONE],
        ]);
    }

    /** Walang city sa list na may SANTA MARIA at SANTA MARTA; ang Agoo ay may SANTA MARIA at SANTA RITA, SAN ANTONIO at SAN ANTONINO. */
    public function test_S_26_13_a_name_that_is_nearer_to_another_barangay_of_the_city_is_not_confirmed(): void
    {
        $this->assertLinesInList(self::STO_TOMAS_EAST, self::STO_TOMAS_WEST, self::AGOO_SANTA_MARIA, self::AGOO_SANTA_RITA, self::AGOO_SAN_ANTONIO, self::AGOO_SAN_ANTONINO);

        $this->assertConfirmTable([
            'Poblacion Wst for POBLACION EAST' => ['Poblacion Wst, Santo Tomas, Pangasinan', self::STO_TOMAS_EAST, self::NONE],
            'Santa Rita for SANTA MARIA'       => ['Santa Rita, Agoo, La Union', self::AGOO_SANTA_MARIA, self::NONE],
            'San Antonino for SAN ANTONIO'     => ['San Antonino, Agoo, La Union', self::AGOO_SAN_ANTONIO, self::NONE],
            'both names in the text'           => ["Poblacion West\nPoblacion East po pala", self::STO_TOMAS_EAST, self::NONE],
            // Ang tamang label mismo ay nakukumpirma pa rin: 96.3 laban sa 88.9 ng kapatid.
            'Poblacion Wst for POBLACION WEST' => ['Poblacion Wst, Santo Tomas, Pangasinan', self::STO_TOMAS_WEST, ['result' => 'near', 'score' => 96]],
        ]);
    }

    public function test_S_26_14_a_longer_barangay_name_of_the_city_around_the_hit_is_not_confirmed(): void
    {
        $this->assertLinesInList(self::VIRAC_SAN_VICENTE, self::VIRAC_DUGUI_SV, self::VIRAC_IBONG_SAPA);

        $this->assertConfirmTable([
            'Dugui San Vicente for SAN VICENTE' => ['Dugui San Vicente, Virac', self::VIRAC_SAN_VICENTE, self::NONE],
            'a typo in the name itself'         => ['Dugui San Vicnte, Virac', self::VIRAC_SAN_VICENTE, self::NONE],
            'the name inside a parenthesis'     => ['San Vicente Sur, Virac', self::VIRAC_SAN_VICENTE, self::NONE],
            'the longer label is confirmed'     => ['Dugui San Vicente, Virac', self::VIRAC_DUGUI_SV, self::PHRASE],
            'the short name alone is confirmed' => ['San Vicente, Virac', self::VIRAC_SAN_VICENTE, self::PHRASE],
        ]);
    }

    public function test_S_26_15_an_initial_is_neither_a_number_nor_a_suffix_letter(): void
    {
        $this->assertLinesInList(self::NASIPIT_BRGY_5, self::QC_HOLY_SPIRIT, self::DASMA_ZONE_1, self::DASMA_ZONE_1B);

        $this->assertConfirmTable([
            'Brgy. V. Luna for BARANGAY 5'        => ['Brgy. V. Luna, Nasipit', self::NASIPIT_BRGY_5, self::NONE],
            'Holy Spirit Q.C.'                    => ['12 Sampaguita St, Holy Spirit Q.C.', self::QC_HOLY_SPIRIT, self::PHRASE],
            'Zone 1 B for ZONE I'                 => ['Zone 1 B Dasmarinas City', self::DASMA_ZONE_1, self::NONE],
            'Zone 1-B for ZONE I'                 => ['Zone 1-B Dasmarinas City', self::DASMA_ZONE_1, self::NONE],
            'Zone 1 B. where ZONE I-B is a label' => ['Zone 1 B. Dasmarinas City', self::DASMA_ZONE_1, self::NONE],
        ]);
    }

    public function test_S_33_5_one_numbered_barangay_repeated_over_200000_characters_gives_the_result_of_the_short_text(): void
    {
        $this->assertLinesInList(self::CABADBARAN_POB_1, self::CABADBARAN_POB_2);
        $long = str_repeat('Poblacion 1 ', 16700);
        $typo = str_repeat('Poblacon 1 ', 18200);
        $this->assertGreaterThanOrEqual(200000, min(strlen($long), strlen($typo)));

        $start = hrtime(true);
        $seen  = [
            'short'              => $this->confirmed('Poblacion 1 Poblacion 1', self::CABADBARAN_POB_1),
            'long'               => $this->confirmed($long, self::CABADBARAN_POB_1),
            'long, other number' => $this->confirmed($long, self::CABADBARAN_POB_2),
            'short, a typo'      => $this->confirmed('Poblacon 1 Poblacon 1', self::CABADBARAN_POB_1),
            'long, a typo'       => $this->confirmed($typo, self::CABADBARAN_POB_1),
        ];
        $seconds = (hrtime(true) - $start) / 1e9;

        // "poblacon 1" laban sa "poblacion 1": 95.2.
        $near = ['result' => 'near', 'score' => 95];
        $this->assertSame(['short' => self::PHRASE, 'long' => self::PHRASE, 'long, other number' => self::NONE, 'short, a typo' => $near, 'long, a typo' => $near], $seen);
        $this->assertLessThan(5.0, $seconds);
    }
}

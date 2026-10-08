<?php

namespace Tests\Feature\NightRun;

use App\Models\MacroOutput;
use App\Models\NightRunStep;
use App\Services\AstraEncoder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Ang dry run sa web-first mode (`--mode=web-first`) at ang `--compare`. Ang gabi ay binubuo ng totoong night job
 * (naka-off ang switch, hawak ang bawat row), tapos "nag-edit ang staff", tapos tumatakbo ang command kinaumagahan.
 * Peke ang model; gawa-gawa ang lahat ng customer.
 */
class AstraDryRunWebFirstTest extends NightAstraTestCase
{
    private const COMMAND = 'astra:dry-run';
    private const TABLES  = ['macro_output', 'ai_checker_logs', 'night_astra_rows', 'night_run_steps', 'app_settings', 'pancake_conversations', 'jobs'];
    private const MARKER  = 'ZZMARKER dalawang address ang ibinigay';

    /** Mga request na ipinadala sa model habang tumatakbo ang command. */
    private array $sent = [];
    /** null = gabi (lahat hawak); kung hindi, [phone sa chat => [sagot, bilang ng web search] | HTTP status]. */
    private ?array $dryAnswers = null;
    private string $storage = '';

    protected function setUp(): void
    {
        parent::setUp();

        // Ang report file ay napupunta sa sariling folder ng test, hindi sa storage ng project.
        $this->storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'astra-dry-run-web-test-' . bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->storage . '/app');
        $this->app->useStoragePath($this->storage);

        Http::fake(['api.openai.com/v1/responses' => function (Request $request) {
            $phone = $this->phoneIn($request);
            if ($this->dryAnswers === null) {
                return $this->modelSays($this->answer(['needs_human' => true, 'human_reason' => 'hawak noong gabi'], $phone), 0);
            }
            $this->sent[] = $request;
            $answer = $this->dryAnswers[$phone];

            return is_int($answer)
                ? Http::response(['error' => ['message' => 'Incorrect API key provided: sk-proj-SECRETxyz', 'type' => 'invalid_request_error', 'code' => 'invalid_api_key']], $answer)
                : $this->modelSays($answer[0], $answer[1]);
        }]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);

        parent::tearDown();
    }

    // ═════════════════════════════════════════════════════════════════════
    //  Mga helper
    // ═════════════════════════════════════════════════════════════════════

    private function modelSays(array $answer, int $webCalls)
    {
        $output = array_fill(0, $webCalls, ['type' => 'web_search_call', 'action' => ['query' => 'Sampaguita St Quezon City barangay', 'sources' => [['url' => 'https://example.test/a']]]]);
        $output[] = ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($answer)]]];

        return Http::response(['id' => 'resp_1', 'output' => $output, 'usage' => ['input_tokens' => 1000, 'output_tokens' => 100]]);
    }

    /** Ang phone sa chat ng request: iba kada order, kaya ito ang nagsasabi kung aling row ang tinatanong. */
    private function phoneIn(Request $request): string
    {
        $this->assertSame(1, preg_match('/CHAT \(raw customer conversation\):\n<<<\n.*?(0917123\d{4})/s', (string) $request['input'], $m));

        return $m[1];
    }

    /** Sagot ng model: buong line ng Holy Spirit, high confidence, barangay na galing sa web. */
    private function answer(array $over, string $phone, array $form = []): array
    {
        return array_replace([
            'form' => array_replace([
                'name' => 'Juan Dela Cruz', 'phone' => $phone, 'house_number' => '12', 'purok_sitio' => '',
                'address' => 'Sampaguita St', 'brgy' => 'Holy Spirit', 'city' => 'Quezon City', 'province' => 'Metro Manila',
                'landmark' => '', 'price' => '', 'quantity' => '',
            ], $form),
            'jnt'          => ['province' => 'METRO-MANILA', 'city' => 'QUEZON-CITY', 'barangay' => 'HOLY SPIRIT'],
            'intent'       => 'order',
            'issues'       => [],
            'needs_human'  => false,
            'human_reason' => '',
            'confidence'   => 'high',
            'evidence'     => 'ayon sa web',
            'brgy_source'  => 'web',
            'web_basis'    => 'single',
        ], $over);
    }

    private function staffSets(MacroOutput $order, array $values): void
    {
        DB::table('macro_output')->where('id', $order->id)->update($values);
    }

    /** [exit code, output] */
    private function dryRun(array $options): array
    {
        $code = Artisan::call(self::COMMAND, $options);

        return [$code, str_replace("\r\n", "\n", Artisan::output())];
    }

    private function reportPath(): string
    {
        $files = File::glob($this->storage . '/app/astra-dry-run/*.json');
        $this->assertCount(1, $files);

        return $files[0];
    }

    private function snapshot(): array
    {
        $all = [];
        foreach (self::TABLES as $table) $all[$table] = json_encode(DB::table($table)->orderBy('id')->get()->all());

        return $all;
    }

    /**
     * Ang maliit na gabi (phone …01 hanggang …07). Sa 1, 2, 3 at 6 ang chat ay kalye at lungsod lang; sa 4 at 5 ay may
     * barangay. Pagkatapos ng gabi ang line ng bawat row ay ang Holy Spirit na isinulat ni Astra.
     *   1 = barangay mula sa web (official, high); staff: PROCEED, parehong line
     *   2 = barangay mula sa web (single, medium), 3 web search; staff: PROCEED, ibang barangay
     *   3 = barangay mula sa web (several, high); staff: CANNOT PROCEED
     *   4 = nasa chat ang barangay → PROCEED agad; staff: PROCEED
     *   5 = line ng model Holy Spirit, form Bagong Pag-asa → dalawang magkaibang line
     *   6 = walang web search sa unang round, at humingi ng tao ang model
     *   7 = API error
     */
    private function fixtureNight(): NightRunStep
    {
        $noBrgy = fn (int $n) => "Juan Dela Cruz\n0917123450{$n}\n12 Sampaguita St malapit sa palengke, Quezon City";
        $brgy   = fn (int $n) => "Juan Dela Cruz\n0917123450{$n}\n12 Sampaguita St, Holy Spirit, Quezon City";
        $orders = [];
        foreach ([1 => $noBrgy, 2 => $noBrgy, 3 => $noBrgy, 4 => $brgy, 5 => $brgy, 6 => $noBrgy, 7 => $noBrgy] as $n => $chat) {
            $orders[$n] = $this->order(['all_user_input' => $chat($n)]);
        }
        $step = $this->runningStep(array_map(fn (MacroOutput $o) => $o->id, array_values($orders)));
        foreach ($orders as $order) {
            $row = $this->work($order->id);
            $this->assertSame(['done', false], [$row->state, (bool) $row->proceed]);
            $this->assertSame('HOLY SPIRIT', $order->fresh()->BARANGAY);
        }
        DB::table('night_run_steps')->where('id', $step->id)->update(['state' => 'finished', 'finished_at' => now()]);
        $this->at('10:00:00');

        $this->staffSets($orders[1], ['STATUS' => 'PROCEED']);
        $this->staffSets($orders[2], ['STATUS' => 'PROCEED', 'BARANGAY' => 'BAGONG PAG-ASA']);
        $this->staffSets($orders[3], ['STATUS' => 'CANNOT PROCEED']);
        $this->staffSets($orders[4], ['STATUS' => 'PROCEED']);
        $this->dryAnswers = [
            '09171234501' => [$this->answer(['web_basis' => 'official'], '09171234501'), 1],
            '09171234502' => [$this->answer(['web_basis' => 'single', 'confidence' => 'medium'], '09171234502'), 3],
            '09171234503' => [$this->answer(['web_basis' => 'several'], '09171234503'), 1],
            '09171234504' => [$this->answer(['brgy_source' => 'customer', 'web_basis' => 'none'], '09171234504'), 1],
            '09171234505' => [$this->answer(['brgy_source' => 'customer', 'web_basis' => 'none'], '09171234505', ['brgy' => 'Bagong Pag-asa']), 1],
            '09171234506' => [$this->answer(['needs_human' => true, 'human_kind' => 'other', 'human_reason' => self::MARKER], '09171234506'), 0],
            '09171234507' => 401,
        ];

        return $step;
    }

    // ═════════════════════════════════════════════════════════════════════
    //  Ang command
    // ═════════════════════════════════════════════════════════════════════

    public function test_web_first_mode_sends_the_mode_2_request_whatever_the_switch_says_and_writes_nothing(): void
    {
        $step   = $this->fixtureNight();
        $this->assertSame('0', AstraEncoder::addressRulesMode());
        $before = $this->snapshot();
        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = $query->sql;
        });

        [$code, $out] = $this->dryRun(['--step' => (string) $step->id, '--mode' => 'web-first']);

        $this->assertSame(0, $code, $out);
        $this->assertCount(7, $this->sent);
        foreach ($this->sent as $request) {
            $this->assertSame(AstraEncoder::FORCE_WEB_SEARCH, $request['tool_choice']);
            $this->assertStringEndsWith(AstraEncoder::NEW_RULES_PROMPT . AstraEncoder::WEB_FIRST_PROMPT, (string) $request['instructions']);
        }
        $this->assertNotEmpty($statements);
        foreach ($statements as $sql) {
            $this->assertMatchesRegularExpression('/\A\s*(select|pragma)\b/i', $sql, $sql);
        }
        $this->assertSame($before, $this->snapshot());
        Queue::assertNothingPushed();
    }

    public function test_the_default_mode_forces_the_rules_of_mode_1_even_with_the_switch_at_2_and_an_unknown_mode_is_refused(): void
    {
        $step = $this->fixtureNight();
        DB::table('app_settings')->updateOrInsert(['key' => 'astra_address_rules'], ['value' => '2']);
        DB::table('app_settings')->updateOrInsert(['key' => 'astra_web_barangay_proceed'], ['value' => 'single']);

        [$code, $out] = $this->dryRun(['--step' => (string) $step->id, '--ids' => '1']);

        $this->assertSame(0, $code, $out);
        $this->assertSame('auto', $this->sent[0]['tool_choice']);
        $this->assertStringEndsWith(AstraEncoder::NEW_RULES_PROMPT, (string) $this->sent[0]['instructions']);
        // Mode 1: ang barangay na wala sa chat ay tinatanggap dahil high ang confidence.
        $this->assertStringContainsString("row 1: would proceed\n", $out);
        $this->assertStringNotContainsString('Web search first', $out);
        $this->assertArrayNotHasKey('mode', json_decode((string) file_get_contents($this->reportPath()), true));

        // Web-first: ang pangalawang setting ay hindi binabasa, kaya naghihintay pa rin ang row.
        [, $web] = $this->dryRun(['--step' => (string) $step->id, '--ids' => '1', '--mode' => 'web-first']);
        $this->assertStringContainsString("row 1: held - barangay from the web, waiting for a person\n", $web);

        $this->sent = [];
        [$code, $bad] = $this->dryRun(['--step' => (string) $step->id, '--mode' => 'web']);
        $this->assertSame(1, $code);
        $this->assertStringStartsWith('Give exactly one of', $bad);
        $this->assertCount(0, $this->sent);
    }

    public function test_the_web_first_report_of_a_small_night_counts_each_outcome(): void
    {
        $step = $this->fixtureNight();

        [$code, $out] = $this->dryRun(['--step' => (string) $step->id, '--mode' => 'web-first']);

        $this->assertSame(0, $code, $out);
        foreach ([
            'Mode: the new address rules + web search first',
            'row 1: held - barangay from the web, waiting for a person',
            'row 4: would proceed',
            'row 5: held - two different lines',
            'row 6: held - the model itself asked for a person',
            'row 7: API error (http_401)',
            'Rows tried: 7',
            '  - WOULD PROCEED with the new process: 1 (ids: 4)',
            '  - would stay held: 5 (ids: 1, 2, 3, 5, 6)',
            '  - the model itself asked for a person: 1 (ids: 6)',
            '  - two different lines: 1 (ids: 5)',
            '  - barangay from the web, waiting for a person: 3 (ids: 1, 2, 3)',
            'Web search first:',
            '  - would PROCEED outright: 1 (ids: 4)',
            '  - would be written with a barangay from the web and wait for a person: 3 (ids: 1, 2, 3)',
            '  Those rows by web_basis, against the row as it is now (after staff):',
            '    - official: 1 (ids: 1)',
            '        province, city and barangay equal to the row now: 1 · a part differs: 0 (province 0, city 0, barangay 0) · cannot compare: 0',
            '    - several: 1 (ids: 3)',
            '        staff status now CANNOT PROCEED: 1 (ids: 3)',
            '    - single: 1 (ids: 2)',
            '        province, city and barangay equal to the row now: 0 · a part differs: 1 (province 0, city 0, barangay 1) · cannot compare: 0',
            '    - none: 0',
            '  Those rows by the model\'s confidence, against the row as it is now (after staff):',
            '    - high: 2 (ids: 1, 3)',
            '        province, city and barangay equal to the row now: 2 · a part differs: 0 (province 0, city 0, barangay 0) · cannot compare: 0',
            '    - medium: 1 (ids: 2)',
            '    - low: 0',
            '    - at official: 2 would proceed, 2 of those equal to the row now',
            '    - at several: 3 would proceed, 3 of those equal to the row now',
            '    - at single: 4 would proceed, 3 of those equal to the row now',
            '  - rows where web search could not be forced: 1 (ids: 6)',
            // 1 + 3 + 1 + 1 + 1 + 0 web search sa anim na row na may pasya = 7 / 6
            '  - web searches per row: average 1.2, maximum 3',
            'Model calls: 6 · tokens in: 6000 · tokens out: 600 · web searches: 7',
        ] as $line) {
            $this->assertStringContainsString($line . "\n", $out);
        }

        $json = json_decode((string) file_get_contents($this->reportPath()), true);
        $this->assertSame('web-first', $json['mode']);
        $this->assertSame([1, 2, 3], $json['summary']['web_first']['web_wait']);
        $this->assertSame(['would_proceed' => 4, 'equal_to_staff' => 3], $json['summary']['web_first']['if_allowed']['single']);
        $this->assertSame(
            ['id' => 2, 'night' => 'held', 'before' => 'log', 'web_searches' => 3, 'web_forced' => true, 'staff_status' => 'PROCEED', 'label_source' => 'model', 'guard' => 'web_found',
                'texts' => 'same', 'outcome' => 'held', 'reason' => 'web', 'web_basis' => 'single', 'confidence' => 'medium', 'compare' => 'differs', 'differs' => ['barangay']],
            $json['rows'][1]
        );
    }

    public function test_compare_tables_the_outcome_of_an_earlier_report_against_this_run(): void
    {
        $step = $this->fixtureNight();
        // Ang naunang takbo: mode 1 sa parehong mga sagot. 1, 3, 4 at 5 ay PROCEED; 2 (medium, wala sa chat) at 6 ay hawak.
        [, $first] = $this->dryRun(['--step' => (string) $step->id]);
        $this->assertStringContainsString("  - WOULD PROCEED with the new process: 4 (ids: 1, 3, 4, 5)\n", $first);
        $earlier = $this->storage . '/earlier.json';
        File::move($this->reportPath(), $earlier);

        [$code, $out] = $this->dryRun(['--step' => (string) $step->id, '--mode' => 'web-first', '--compare' => $earlier]);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString(implode("\n", [
            'Against the earlier report (rows in both: 7):',
            '  - held then, would proceed now: 0',
            '  - held then, barangay from the web now: 1 (ids: 2)',
            '  - would proceed then, held now: 1 (ids: 5)',
            '  - would proceed then, barangay from the web now: 2 (ids: 1, 3)',
            '  - same outcome: 2',
            '  - no verdict in one of the two (API error or not tried): 1 (ids: 7)',
        ]) . "\n", $out);
        $this->assertSame([2], json_decode((string) file_get_contents($this->reportPath()), true)['summary']['compare']['changes']['held>web']);

        // Ang file na hindi report ng command na ito: walang tawag sa model.
        $this->sent = [];
        File::put($this->storage . '/other.json', json_encode(['rows' => []]));
        foreach ([$this->storage . '/missing.json', $this->storage . '/other.json'] as $path) {
            [$code, $bad] = $this->dryRun(['--step' => (string) $step->id, '--compare' => $path]);
            $this->assertSame([1, "Not started: the --compare file could not be read as a report of this command.\n"], [$code, $bad]);
        }
        $this->assertCount(0, $this->sent);
    }

    public function test_no_name_phone_address_chat_text_or_reason_of_the_model_reaches_the_web_first_output_or_file(): void
    {
        $step = $this->fixtureNight();

        [, $out] = $this->dryRun(['--step' => (string) $step->id, '--mode' => 'web-first']);

        $all = $out . "\n" . file_get_contents($this->reportPath()) . "\n" . json_encode($this->logLines->getArrayCopy());
        foreach ([
            'Juan', 'Dela Cruz', '0917123', '917123450', 'Sampaguita', 'palengke', 'Holy Spirit', 'HOLY SPIRIT', 'QUEZON', 'BAGONG', 'Pag-asa',
            'ZZMARKER', 'dalawang address', 'ayon sa web', 'example.test', 'Slimming', 'SECRET', 'test-key-not-real',
        ] as $private) {
            $this->assertStringNotContainsStringIgnoringCase($private, $all);
        }
    }
}

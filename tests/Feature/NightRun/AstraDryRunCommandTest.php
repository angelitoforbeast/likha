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
 * Ang dry run command: totoong tawag sa model (peke rito) na may bagong rules, pero walang sulat sa kahit anong table.
 * Ang gabi ay binubuo ng totoong night job (naka-off ang switch, hawak ang bawat row), tapos "nag-edit ang staff",
 * tapos tumatakbo ang command kinaumagahan. Gawa-gawa ang lahat ng customer.
 */
class AstraDryRunCommandTest extends NightAstraTestCase
{
    private const COMMAND = 'astra:dry-run';
    private const TABLES  = ['macro_output', 'ai_checker_logs', 'night_astra_rows', 'night_run_steps', 'app_settings', 'pancake_conversations', 'jobs'];
    private const MARKER  = 'ZZMARKER dalawang address ang ibinigay';

    /** Mga request na ipinadala sa model habang tumatakbo ang command. */
    private array $sent = [];
    /** null = gabi (lahat hawak); kung hindi, [phone sa chat => sagot | HTTP status]. */
    private ?array $dryAnswers = null;
    private string $storage = '';

    protected function setUp(): void
    {
        parent::setUp();

        // Ang report file ay napupunta sa sariling folder ng test, hindi sa storage ng project.
        $this->storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'astra-dry-run-test-' . bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->storage . '/app');
        $this->app->useStoragePath($this->storage);

        Http::fake(['api.openai.com/v1/responses' => function (Request $request) {
            if ($this->dryAnswers === null) {
                return $this->modelSays($this->answer(['needs_human' => true, 'human_reason' => 'hawak noong gabi'], $this->phoneIn($request)));
            }
            $this->sent[] = $request;
            $answer = $this->dryAnswers[$this->phoneIn($request)] ?? $this->answer([], $this->phoneIn($request));

            return is_int($answer)
                ? Http::response(['error' => ['message' => 'Incorrect API key provided: sk-proj-SECRETxyz', 'type' => 'invalid_request_error', 'code' => 'invalid_api_key']], $answer)
                : $this->modelSays($answer);
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

    private function modelSays(array $answer)
    {
        return Http::response(['id' => 'resp_1', 'output_text' => json_encode($answer), 'usage' => ['input_tokens' => 1000, 'output_tokens' => 100]]);
    }

    /** Ang phone sa chat ng request: iba kada order, kaya ito ang nagsasabi kung aling row ang tinatanong. */
    private function phoneIn(Request $request): string
    {
        $this->assertSame(1, preg_match('/CHAT \(raw customer conversation\):\n<<<\n.*?(0917123\d{4})/s', (string) $request['input'], $m));

        return $m[1];
    }

    /** Sagot ng model: buong line ng Holy Spirit, high confidence. */
    private function answer(array $over = [], string $phone = '09171234501'): array
    {
        return array_replace([
            'form' => [
                'name' => 'Juan Dela Cruz', 'phone' => $phone, 'house_number' => '12', 'purok_sitio' => '',
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
        ], $over);
    }

    private function chatOrder(int $n, array $extra = []): MacroOutput
    {
        return $this->order(['all_user_input' => "Juan Dela Cruz\n0917123450{$n}\n12 Sampaguita St, Holy Spirit, Quezon City"] + $extra);
    }

    /**
     * Isang gabi ng $count na order (phone …01, …02, …) sa pamamagitan ng night job, lahat hawak para sa tao;
     * pagkatapos ay tapos na ang step at 10:00 na ng umaga. Ibinabalik ang [step, mga order ayon sa bilang].
     */
    private function heldNight(int $count, array $firstExtra = []): array
    {
        $orders = [];
        for ($n = 1; $n <= $count; $n++) $orders[$n] = $this->chatOrder($n, $n === 1 ? $firstExtra : []);
        $step = $this->runningStep(array_map(fn (MacroOutput $o) => $o->id, array_values($orders)));
        foreach ($orders as $order) {
            $row = $this->work($order->id);
            $this->assertSame('done', $row->state);
            $this->assertFalse((bool) $row->proceed);
        }
        $this->morningAfter($step);

        return [$step, $orders];
    }

    private function morningAfter(NightRunStep $step): void
    {
        DB::table('night_run_steps')->where('id', $step->id)->update(['state' => 'finished', 'finished_at' => now()]);
        $this->at('10:00:00');
        $this->dryAnswers = [];
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

    private function reportFile(): string
    {
        $files = File::glob($this->storage . '/app/astra-dry-run/*.json');
        $this->assertCount(1, $files);

        return (string) file_get_contents($files[0]);
    }

    private function snapshot(): array
    {
        $all = [];
        foreach (self::TABLES as $table) $all[$table] = json_encode(DB::table($table)->orderBy('id')->get()->all());

        return $all;
    }

    /**
     * Ang maliit na gabi ng report: 1 = magpo-proceed, kapareho ng staff; 2 = magpo-proceed, iba ang barangay ng staff;
     * 3 = hawak dahil humingi ng tao ang model (PROCEED na sa staff); 4 = hawak dahil malabo ang intent; 5 = API error.
     */
    private function fixtureNight(): array
    {
        [$step, $orders] = $this->heldNight(5, ['FULL NAME' => 'Before Name']);
        $this->staffSets($orders[1], ['STATUS' => 'PROCEED', 'FULL NAME' => 'Zzedited Staff']);
        $this->staffSets($orders[2], ['STATUS' => 'PROCEED', 'BARANGAY' => 'BAGONG PAG-ASA']);
        $this->staffSets($orders[3], ['STATUS' => 'PROCEED']);
        $this->staffSets($orders[4], ['STATUS' => 'CANNOT PROCEED']);
        $this->dryAnswers = [
            '09171234503' => $this->answer(['needs_human' => true, 'human_kind' => 'other', 'human_reason' => self::MARKER], '09171234503'),
            '09171234504' => $this->answer(['intent' => 'unclear'], '09171234504'),
            '09171234505' => 401,
        ];

        return [$step, $orders];
    }

    // ═════════════════════════════════════════════════════════════════════
    //  Ang command
    // ═════════════════════════════════════════════════════════════════════

    public function test_the_command_runs_no_insert_update_or_delete_and_leaves_every_table_as_it_was(): void
    {
        [$step] = $this->fixtureNight();
        $before = $this->snapshot();
        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = $query->sql;
        });

        [$code, $out] = $this->dryRun(['--step' => (string) $step->id]);

        $this->assertSame(0, $code, $out);
        $this->assertCount(5, $this->sent);
        $this->assertNotEmpty($statements);
        foreach ($statements as $sql) {
            $this->assertMatchesRegularExpression('/\A\s*(select|pragma)\b/i', $sql, $sql);
        }
        $this->assertSame($before, $this->snapshot());
        Queue::assertNothingPushed();
    }

    public function test_the_new_instructions_are_sent_even_with_the_switch_off(): void
    {
        [$step] = $this->heldNight(1);
        $this->assertFalse(AstraEncoder::addressRulesOn());

        [$code, $out] = $this->dryRun(['--step' => (string) $step->id]);

        $this->assertSame(0, $code, $out);
        $this->assertCount(1, $this->sent);
        $this->assertStringEndsWith(AstraEncoder::NEW_RULES_PROMPT, (string) $this->sent[0]['instructions']);
        $this->assertSame(['web_search', 'function', 'function'], array_column($this->sent[0]['tools'], 'type'));
        // Hawak noong gabi (lumang rules at sariling flag ng model); sa bagong proseso ang parehong row ay magpo-proceed.
        $this->assertStringContainsString("row 1: would proceed\n", $out);
    }

    public function test_the_row_is_judged_as_it_stood_before_the_night_not_as_staff_left_it(): void
    {
        [$step, $orders] = $this->heldNight(1, ['FULL NAME' => 'Before Name', 'PROVINCE' => 'CEBU']);
        // Isinulat ng gabi ang sarili nitong mga value; pagkatapos ay nag-edit ang staff.
        $this->assertSame('METRO-MANILA', $orders[1]->fresh()->PROVINCE);
        $this->staffSets($orders[1], ['FULL NAME' => 'Zzedited Staff', 'PROVINCE' => 'LAGUNA', 'STATUS' => 'CANNOT PROCEED']);

        [$code, $out] = $this->dryRun(['--night' => self::NIGHT]);

        $this->assertSame(0, $code, $out);
        $prompt = (string) $this->sent[0]['input'];
        $this->assertStringContainsString('FULL NAME=Before Name | PHONE= | ADDRESS= | PROVINCE=CEBU | CITY= | BARANGAY=', $prompt);
        $this->assertStringNotContainsString('Zzedited', $prompt);
        $this->assertStringNotContainsString('LAGUNA', $prompt);
        // Ang STATUS ng staff ay hindi hadlang sa pasya, pero ito ang ikinukumpara: iba ang province ng staff.
        $this->assertStringContainsString('  - staff status now CANNOT PROCEED: 1 (ids: 1)', $out);
        $this->assertStringContainsString('  - a part differs from the row now: 1 (ids: 1)', $out);
        $this->assertStringContainsString('      - province differs: 1', $out);
        $this->assertSame('LAGUNA', $orders[1]->fresh()->PROVINCE);
    }

    public function test_the_report_of_a_small_night_counts_each_outcome(): void
    {
        [$step] = $this->fixtureNight();

        [$code, $out] = $this->dryRun(['--step' => (string) $step->id]);

        $this->assertSame(0, $code, $out);
        foreach ([
            'row 1: would proceed',
            'row 2: would proceed',
            'row 3: held - the model itself asked for a person',
            'row 4: held - the customer\'s intent is unclear',
            'row 5: API error (http_401)',
            'Rows tried: 5',
            '  - WOULD PROCEED with the new process: 2 (ids: 1, 2)',
            '  - would stay held: 2 (ids: 3, 4)',
            '  - failed with an API error: 1 (ids: 5)',
            '  - the model itself asked for a person: 1 (ids: 3)',
            '  - the customer\'s intent is unclear: 1 (ids: 4)',
            '  - no line from the list: 0',
            '  - of those that stay held, staff have since set to PROCEED: 1 (ids: 3)',
            '  - staff status now PROCEED: 2',
            '  - staff status now CANNOT PROCEED: 0',
            '  - province, city and barangay all equal to the row now: 1',
            '  - a part differs from the row now: 1 (ids: 2)',
            '      - barangay differs: 1',
            '      - province differs: 0',
            '  - cannot compare (the row now has a blank province, city or barangay): 0',
            'Model: gpt-6-astra · effort: high',
            'Model calls: 4 · tokens in: 4000 · tokens out: 400 · web searches: 0',
            'API errors: 1 (http_401 x1)',
            'Caveats:',
        ] as $line) {
            $this->assertStringContainsString($line . "\n", $out);
        }
        $this->assertStringNotContainsString('Rows Astra proceeded that night', $out);

        $json = json_decode($this->reportFile(), true);
        $this->assertSame($step->id, $json['step_id']);
        $this->assertSame([1, 2], $json['summary']['would_proceed']);
        $this->assertSame([3], $json['summary']['held_by_reason']['flag']);
        $this->assertSame(['http_401' => 1], $json['summary']['api_error_classes']);
        $this->assertSame(['id' => 2, 'night' => 'held', 'before' => 'log', 'staff_status' => 'PROCEED', 'label_source' => 'model', 'guard' => 'phrase', 'texts' => 'same', 'outcome' => 'would_proceed', 'compare' => 'differs', 'differs' => ['barangay']], $json['rows'][1]);
    }

    public function test_rows_astra_proceeded_that_night_that_the_new_process_would_hold_are_listed(): void
    {
        $order = $this->chatOrder(1);
        $step  = $this->runningStep([$order->id]);
        $this->dryAnswers = ['09171234501' => $this->answer()];   // noong gabi: PROCEED
        $this->assertTrue((bool) $this->work($order->id)->proceed);
        $this->sent = [];
        $this->morningAfter($step);
        $this->dryAnswers = ['09171234501' => $this->answer(['intent' => 'unclear'])];

        [, $held] = $this->dryRun(['--step' => (string) $step->id]);
        [$code, $out] = $this->dryRun(['--step' => (string) $step->id, '--rows' => 'proceeded']);

        $this->assertStringContainsString("Rows tried: 0\n", $held);
        $this->assertSame(0, $code, $out);
        $this->assertCount(1, $this->sent);
        $this->assertStringContainsString("  - the new process would hold instead: 1 (ids: 1)\n", $out);
        $this->assertStringContainsString("      - the customer's intent is unclear: 1 (ids: 1)\n", $out);
    }

    public function test_limit_and_ids_bound_the_rows_before_the_first_call(): void
    {
        [$step, $orders] = $this->heldNight(3);

        [, $limited] = $this->dryRun(['--step' => (string) $step->id, '--limit' => '2']);
        $this->assertCount(2, $this->sent);
        $this->assertStringContainsString("Rows tried: 2\n", $limited);
        $this->assertStringNotContainsString('row 3:', $limited);

        $this->sent = [];
        [, $byId] = $this->dryRun(['--step' => (string) $step->id, '--ids' => $orders[3]->id . ',999']);
        $this->assertCount(1, $this->sent);
        $this->assertStringContainsString("row 3: would proceed\n", $byId);

        $this->sent = [];
        [$code, $bad] = $this->dryRun(['--step' => (string) $step->id, '--limit' => '0']);
        $this->assertSame(1, $code);
        $this->assertStringStartsWith('Give exactly one of', $bad);
        $this->assertCount(0, $this->sent);
    }

    public function test_it_refuses_to_start_while_a_night_step_runs_or_in_the_night_hours(): void
    {
        [$step] = $this->heldNight(1);

        $this->at('03:10:00');
        [$code, $out] = $this->dryRun(['--step' => (string) $step->id]);
        $this->assertSame(1, $code);
        $this->assertStringStartsWith('Not started: between 02:30 and 04:30 Manila time', $out);

        $this->at('10:00:00');
        NightRunStep::create(['night_date' => '2026-10-06', 'kind' => 'astra', 'state' => 'running', 'trigger' => 'manual', 'started_at' => now()]);
        [$code, $out] = $this->dryRun(['--step' => (string) $step->id]);
        $this->assertSame(1, $code);
        $this->assertSame("Not started: a night Astra step is running; wait until it has finished.\n", $out);

        $this->assertCount(0, $this->sent);
        $this->assertSame([], File::glob($this->storage . '/app/astra-dry-run/*.json'));
    }

    public function test_it_stops_after_three_api_errors_in_a_row(): void
    {
        [$step] = $this->heldNight(5);
        $this->dryAnswers = array_fill_keys(['09171234501', '09171234502', '09171234503', '09171234504', '09171234505'], 401);

        [$code, $out] = $this->dryRun(['--step' => (string) $step->id]);

        $this->assertSame(1, $code);
        $this->assertCount(3, $this->sent);
        $this->assertStringContainsString("STOPPED EARLY: 3 API errors in a row.", $out);
        $this->assertStringContainsString("API errors: 3 (http_401 x3)\n", $out);
    }

    public function test_no_name_phone_address_chat_text_or_reason_of_the_model_reaches_the_output_or_the_file(): void
    {
        [$step] = $this->fixtureNight();

        [, $out] = $this->dryRun(['--step' => (string) $step->id]);

        $all = $out . "\n" . $this->reportFile() . "\n" . json_encode($this->logLines->getArrayCopy());
        foreach ([
            'Juan', 'Dela Cruz', 'Before Name', 'Zzedited', '0917123', '917123450', 'Sampaguita', 'Holy Spirit', 'HOLY SPIRIT',
            'QUEZON', 'BAGONG', 'ZZMARKER', 'dalawang address', 'sinabi ng customer', 'Slimming', 'SECRET', 'test-key-not-real',
        ] as $private) {
            $this->assertStringNotContainsStringIgnoringCase($private, $all);
        }
    }
}

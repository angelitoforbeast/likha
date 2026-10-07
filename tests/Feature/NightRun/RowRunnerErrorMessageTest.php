<?php

namespace Tests\Feature\NightRun;

use App\Models\NightAstraRow;
use App\Models\NightRunStep;
use App\Services\AiCheckerRowRunner;
use App\Services\AstraEncoder;
use App\Services\MacroChecker;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Ang row na pumalya sa AI Checker: maikli at nakapirming mensahe lang ang ibinabalik (may numero ng log row),
 * at class lang ng exception ang nasa server log. Ang message ng exception ay maaaring may text ng customer
 * (SQL at bound values ng database exception), kaya hinahanap ito rito sa buong sagot, sa log row at sa
 * bawat log line.
 *
 * Tatlong paraan ng pagpapalya: engine na nagta-throw ng piniling exception (runner), sqlite trigger sa
 * totoong route (database failure, at ang huling JSON), at ang parehong trigger sa night job.
 */
class RowRunnerErrorMessageTest extends NightAstraTestCase
{
    private const LINE     = 'JUANA TESTCUSTOMER 123 TEST ST 09170000000';
    private const CUSTOMER = 'JUANA TESTCUSTOMER 123 TEST ST';
    private const MARKER   = 'MARKER-pinalya-ng-test';
    private const CTX      = ['source' => 'single', 'user_id' => null, 'user_name' => 'Test User'];

    protected function setUp(): void
    {
        parent::setUp();

        // Config na binabasa ng classic engine, para hindi nakadepende sa local environment.
        config([
            'services.openai.key'                       => 'test-key-not-real',
            'services.openai.ai_checker_search'         => 'required',
            'services.openai.ai_checker_escalate_model' => '',
        ]);
    }

    public static function engines(): array
    {
        return ['astra' => ['astra'], 'classic' => ['classic']];
    }

    public static function messages(): array
    {
        return [
            'empty'         => [''],
            '10,001 chars'  => [str_repeat('J', 10001)],
            'non-ASCII'     => ['Ñandú, Bgy. Sta. Cruz, ₱599'],
        ];
    }

    /** Runner na ang engine ay nagta-throw ng $e sa processRow — totoong subclass ng engine, para pareho ang instanceof. */
    private function throwingRunner(\Throwable $e): AiCheckerRowRunner
    {
        $runner = new class extends AiCheckerRowRunner {
            public \Throwable $boom;

            protected function engine(string $engine)
            {
                $svc = $engine === 'astra'
                    ? new class extends AstraEncoder {
                        public \Throwable $boom;

                        public function processRow(int $id, array $maps, ?string $host = null): array
                        {
                            throw $this->boom;
                        }
                    }
                    : new class extends MacroChecker {
                        public \Throwable $boom;

                        public function processRow(int $id, array $maps, ?string $host = null): array
                        {
                            throw $this->boom;
                        }
                    };
                $svc->boom = $this->boom;

                return $svc;
            }
        };
        $runner->boom = $e;

        return $runner;
    }

    private function thrown(\Throwable $e, string $engine = 'astra', ?int $orderId = null): array
    {
        return $this->throwingRunner($e)->run($orderId ?? $this->order()->id, $engine, 'likhaaitech.com', self::CTX);
    }

    /** Ang JSON na ipapadala ng controller mula sa payload ng runner. */
    private function body(array $out): string
    {
        return response()->json($out['payload'], $out['status'])->getContent();
    }

    /** Pinapalya ang pagsulat sa order sa mismong database; ang $text ay napupunta sa message ng exception. */
    private function failOrderUpdate(string $text): void
    {
        DB::statement("CREATE TRIGGER macro_output_fail BEFORE UPDATE ON macro_output BEGIN SELECT RAISE(ABORT, '" . $text . "'); END");
    }

    private function failLogInsert(): void
    {
        DB::statement("CREATE TRIGGER ai_checker_logs_fail BEFORE INSERT ON ai_checker_logs BEGIN SELECT RAISE(ABORT, '" . self::MARKER . "'); END");
    }

    /** Mga sagot ng AI na umaabot sa pagsulat sa order; ang $name ang pangalang ibinabalik ng AI (Astra). */
    private function fakeAi(string $engine, string $name = 'Juan Dela Cruz'): void
    {
        if ($engine === 'astra') {
            $answer = $this->goodAnswer();
            $form   = json_decode($answer['output_text'], true);
            $form['form']['name'] = $name;
            Http::fake(['api.openai.com/v1/responses' => Http::response(['output_text' => json_encode($form)] + $answer)]);

            return;
        }

        $resolved = [
            'province' => 'Metro Manila', 'province_aliases' => ['NCR'], 'city' => 'Quezon City', 'city_candidates' => ['Quezon City'],
            'barangay' => 'Holy Spirit', 'barangay_candidates' => ['Holy Spirit'], 'confidence' => 'high', 'evidence' => 'sinabi ng customer',
        ];
        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($resolved)]]]],
                'usage'  => ['input_tokens' => 1000, 'output_tokens' => 200],
            ]),
            'api.openai.com/v1/chat/completions' => function (Request $request) {
                $verify = str_contains((string) $request['messages'][0]['content'], 'address verifier');

                return Http::response([
                    'choices' => [['message' => ['content' => json_encode($verify
                        ? ['province_ok' => true, 'city_ok' => true, 'barangay_ok' => true, 'evidence' => 'tugma sa chat']
                        : ['full_name' => 'Juan Dela Cruz', 'address_line1' => '12 Sampaguita St', 'phone_number' => '09171234567'])]]],
                    'usage'   => ['prompt_tokens' => 800, 'completion_tokens' => 50],
                ]);
            },
        ]);
    }

    /** Ang totoong route, naka-sign in; pumapalya ang pagsulat sa order kaya dumadaan sa catch ng runner. */
    private function failedRunRow(string $engine = 'astra', string $triggerText = self::MARKER, array $order = [], string $name = 'Juan Dela Cruz'): array
    {
        $order = $this->order($order);
        $this->fakeAi($engine, $name);
        $this->failOrderUpdate($triggerText);
        $response = $this->actingAs($this->user())->postJson('/encoder/checker_1/ai-checker/run-row/' . $order->id, ['engine' => $engine]);

        return [$response, $order->id];
    }

    /** Id ng failed log row ng order, binabasa sa table mismo. */
    private function failedLogId(int $orderId): int
    {
        return (int) DB::table('ai_checker_logs')->where('macro_output_id', $orderId)
            ->where('outcome', 'failed')->where('final_code', '❌')->sole()->id;
    }

    private function lines(string $message): array
    {
        return array_values(array_filter($this->logLines->getArrayCopy(), fn ($line) => $line['message'] === $message));
    }

    /** Lahat ng log line bilang text. print_r, hindi json_encode: lumalabas pati ang message ng exception object sa context. */
    private function logText(): string
    {
        return print_r($this->logLines->getArrayCopy(), true);
    }

    /** Ang $needle ay wala sa mga ibinigay na text, sa mga log row, at sa mga log line. */
    private function assertNowhere(string $needle, string ...$texts): void
    {
        $texts[] = print_r(DB::table('ai_checker_logs')->get()->all(), true);
        $texts[] = $this->logText();
        foreach ($texts as $i => $text) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $text, 'text #' . $i);
        }
    }

    #[DataProvider('engines')]
    public function test_S_01_1_a_thrown_row_returns_the_fixed_message_with_the_log_id(string $engine): void
    {
        $order = $this->order();

        $out = $this->thrown(new \RuntimeException(self::LINE), $engine, $order->id);

        $this->assertSame(500, $out['status']);
        $this->assertFalse($out['payload']['ok']);
        $this->assertSame('AI check failed. Ref: log #' . $this->failedLogId($order->id), $out['payload']['error']);
    }

    #[DataProvider('engines')]
    public function test_S_01_2_the_customer_line_appears_nowhere(string $engine): void
    {
        // Sa totoong route: ang linya ay nasa message ng QueryException at ng PDOException sa ilalim nito.
        [$response, $orderId] = $this->failedRunRow($engine, self::LINE);

        $response->assertStatus(500);
        $this->assertSame('AI check failed. Ref: log #' . $this->failedLogId($orderId), $response->json('error'));
        $this->assertNowhere(self::LINE, $response->getContent());

        // Sa runner: piniling message sa karaniwang exception.
        $out = $this->thrown(new \RuntimeException(self::LINE), $engine);

        $this->assertNowhere(self::LINE, $this->body($out), json_encode($out['payload'], JSON_UNESCAPED_UNICODE));
    }

    #[DataProvider('messages')]
    public function test_S_01_3_the_message_does_not_depend_on_the_exception(string $message): void
    {
        $order = $this->order();

        $out = $this->thrown(new \RuntimeException($message), 'astra', $order->id);

        // Ang buong sagot ay eksaktong ito, kaya hindi nakadepende ang haba nito sa exception.
        $this->assertSame('{"ok":false,"error":"AI check failed. Ref: log #' . $this->failedLogId($order->id) . '"}', $this->body($out));
    }

    public function test_S_01_4_a_previous_exception_is_not_shown_or_logged(): void
    {
        $order = $this->order();

        $out = $this->thrown(new \RuntimeException('Hindi natuloy ang row', 0, new \LogicException(self::LINE)), 'astra', $order->id);

        $this->assertSame('AI check failed. Ref: log #' . $this->failedLogId($order->id), $out['payload']['error']);
        $this->assertNowhere(self::LINE, $this->body($out));
        $this->assertNowhere('Hindi natuloy ang row', $this->body($out));
    }

    public function test_S_02_1_a_database_failure_shows_no_sql_and_no_bound_value(): void
    {
        // Ang text ng customer ay nasa chat at sa sagot ng AI, kaya bound value ito ng UPDATE na pumapalya.
        [$response] = $this->failedRunRow('astra', self::MARKER, ['all_user_input' => self::CUSTOMER . "\n09171234567\nHoly Spirit, Quezon City"], self::CUSTOMER);

        $response->assertStatus(500);
        foreach ([self::CUSTOMER, self::MARKER, 'UPDATE', 'macro_output', 'SQLSTATE', 'bindings'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $response->getContent());
        }
    }

    public function test_S_02_2_a_database_failure_logs_class_sqlstate_and_row_id_only(): void
    {
        [, $orderId] = $this->failedRunRow();

        $lines = $this->lines('AI_CHECKER_ROW_FAIL');
        $this->assertCount(1, $lines);
        $this->assertSame('warning', $lines[0]['level']);
        $this->assertSame(
            ['exception' => 'Illuminate\Database\QueryException', 'sqlstate' => '23000', 'macro_output_id' => $orderId],
            $lines[0]['context']
        );
        // 'SQLSTATE[' at hindi 'SQLSTATE': ang key na `sqlstate` ng context ay sadyang naroon.
        foreach ([self::MARKER, 'SQLSTATE[', 'macro_output"'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $this->logText());
        }
    }

    public function test_S_02_3_both_writes_failing_gives_the_message_without_a_reference(): void
    {
        $this->failLogInsert();

        [$response] = $this->failedRunRow();

        $response->assertStatus(500);
        $this->assertSame(['ok' => false, 'error' => 'AI check failed. No log reference.'], $response->json());
        $this->assertCount(1, $this->lines('AI_CHECKER_LOG_FAIL'));
        $this->assertCount(1, $this->lines('AI_CHECKER_ROW_FAIL'));
    }

    public function test_S_02_4_a_plain_exception_logs_no_sqlstate(): void
    {
        $order = $this->order();

        $this->thrown(new \LogicException(self::LINE), 'astra', $order->id);

        $lines = $this->lines('AI_CHECKER_ROW_FAIL');
        $this->assertCount(1, $lines);
        $this->assertSame(['exception' => 'LogicException', 'macro_output_id' => $order->id], $lines[0]['context']);
    }

    public function test_S_02_5_a_failing_logger_does_not_break_the_failure_path(): void
    {
        $order = $this->order();
        // Hindi maisulat ang linya ng pumalyang row (hal. puno ang disk ng log); ang ibang linya ay naiipon pa rin.
        $lines = $this->logLines;
        $tried = 0;
        // Bagong spy (swap): sa spy ng setUp, ang naunang `warning` na walang kondisyon ang laging tumatama.
        Log::swap(\Mockery::spy(\Illuminate\Log\LogManager::class));
        Log::shouldReceive('warning')->zeroOrMoreTimes()->andReturnUsing(function ($message, array $context = []) use ($lines, &$tried) {
            if ($message === 'AI_CHECKER_ROW_FAIL') {
                $tried++;
                throw new \RuntimeException('Hindi maisulat ang log');
            }
            $lines[] = ['level' => 'warning', 'message' => $message, 'context' => $context];
        });

        $out = $this->thrown(new \RuntimeException(self::LINE), 'astra', $order->id);

        $this->assertSame(['status', 'payload', 'result', 'log_id', 'last_error'], array_keys($out));
        $this->assertSame(
            [500, ['ok' => false, 'error' => 'AI check failed. Ref: log #' . $this->failedLogId($order->id)], null],
            [$out['status'], $out['payload'], $out['result']]
        );
        $this->assertSame(1, $tried);
    }

    public function test_S_03_1_a_thrown_row_is_500_with_ok_false_and_a_string_error(): void
    {
        [$response] = $this->failedRunRow();

        $response->assertStatus(500);
        $json = $response->json();
        $this->assertSame(['ok', 'error'], array_keys($json));
        $this->assertFalse($json['ok']);
        $this->assertIsString($json['error']);
        $this->assertNotSame('', trim($json['error']));
    }

    public function test_S_04_2_the_night_row_step_and_log_hold_no_customer_text(): void
    {
        $order = $this->order(['all_user_input' => self::LINE]);
        $this->runningStep([$order->id], [], ['attempts' => 1]);
        $this->fakeAi('astra');
        $this->failOrderUpdate(self::LINE);

        $row = $this->work($order->id);

        // Dumaan sa catch ng runner (may log row ang night row), hindi sa sariling catch ng job.
        $this->assertSame($this->failedLogId($order->id), (int) $row->log_id);
        $written = print_r([NightAstraRow::all()->toArray(), NightRunStep::all()->toArray()], true) . $this->logText();
        foreach ([self::LINE, 'TESTCUSTOMER', 'SQLSTATE['] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $written);
        }
        $this->assertCount(1, $this->lines('AI_CHECKER_ROW_FAIL'));
        $this->assertCount(0, $this->lines('NIGHT_ASTRA_ROW'));
    }

    public function test_S_04_4_a_thrown_row_returns_the_five_keys_with_a_null_result(): void
    {
        $out = $this->thrown(new \RuntimeException(self::LINE));

        $this->assertSame(['status', 'payload', 'result', 'log_id', 'last_error'], array_keys($out));
        $this->assertNull($out['result']);
    }

    public function test_S_05_1_the_reference_is_the_id_of_the_failed_log_row(): void
    {
        // May naunang log row, para hindi 1 ang id na hinahanap.
        $this->thrown(new \RuntimeException('nauna'));

        [$response, $orderId] = $this->failedRunRow();

        // Mula sa mensahe papunta sa log row: ang numero sa dulo ang id.
        $this->assertSame(1, preg_match('/^AI check failed\. Ref: log #(\d+)$/', (string) $response->json('error'), $m));
        $log = DB::table('ai_checker_logs')->where('id', (int) $m[1])->sole();
        $this->assertSame([$orderId, 'failed', '❌'], [(int) $log->macro_output_id, $log->outcome, $log->final_code]);
        $this->assertSame(1, DB::table('ai_checker_logs')->where('macro_output_id', $orderId)->count());
    }

    public function test_S_05_2_no_log_row_means_no_id_in_the_message(): void
    {
        $this->failLogInsert();

        $out = $this->thrown(new \RuntimeException(self::LINE));

        $this->assertSame('AI check failed. No log reference.', $out['payload']['error']);
        $this->assertNull($out['log_id']);
        $this->assertSame(0, DB::table('ai_checker_logs')->count());
    }

    public function test_S_05_3_two_failures_get_two_different_references(): void
    {
        $first  = $this->order();
        $second = $this->order();

        $outFirst  = $this->thrown(new \RuntimeException(self::LINE), 'astra', $first->id);
        $outSecond = $this->thrown(new \RuntimeException(self::LINE), 'astra', $second->id);

        $this->assertNotSame($this->failedLogId($first->id), $this->failedLogId($second->id));
        $this->assertSame(
            ['AI check failed. Ref: log #' . $this->failedLogId($first->id), 'AI check failed. Ref: log #' . $this->failedLogId($second->id)],
            [$outFirst['payload']['error'], $outSecond['payload']['error']]
        );
    }
}

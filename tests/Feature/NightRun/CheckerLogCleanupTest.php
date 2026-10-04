<?php

namespace Tests\Feature\NightRun;

use App\Models\MacroOutput;
use App\Services\AiCheckerRowRunner;
use App\Services\AstraEncoder;
use App\Services\MacroChecker;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Ang mga log ng MacroChecker at AiCheckerRowRunner ay hindi dapat maglaman ng body ng response
 * o message ng exception (maaaring may key o text ng customer). Class/status/type/code lang.
 */
class CheckerLogCleanupTest extends NightRunTestCase
{
    private const FRAGMENT = 'sk-TESTFRAGMENT123';
    private const CHAT     = 'https://api.openai.com/v1/chat/completions';
    private const RESP     = 'https://api.openai.com/v1/responses';

    private \ArrayObject $lines;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['services.openai.ai_checker_search' => 'required']);
        // Lahat ng level ay iniipon, para walang makalusot.
        $this->lines = $lines = new \ArrayObject();
        Log::spy();
        foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical'] as $level) {
            Log::shouldReceive($level)->zeroOrMoreTimes()->andReturnUsing(function ($message, array $context = []) use ($lines, $level) {
                $lines[] = ['level' => $level, 'message' => $message, 'context' => $context];
            });
        }
    }

    /** @return array<int, array> ang mga context ng linyang may ganitong message */
    private function contexts(string $message): array
    {
        return array_values(array_map(
            fn ($l) => $l['context'],
            array_filter($this->lines->getArrayCopy(), fn ($l) => $l['message'] === $message)
        ));
    }

    private function assertNowhereLogged(string $needle): void
    {
        $this->assertStringNotContainsString($needle, json_encode($this->lines->getArrayCopy()));
    }

    private function keyError(): string
    {
        return json_encode(['error' => [
            'message' => 'Incorrect API key provided: ' . self::FRAGMENT . '.',
            'type'    => 'invalid_request_error',
            'code'    => 'invalid_api_key',
        ]]);
    }

    public static function httpFailures(): array
    {
        return [
            'json error' => [401, null, 'invalid_request_error', 'invalid_api_key'],
            'not json'   => [502, '<html>Bad Gateway ' . self::FRAGMENT . '</html>', '', ''],
        ];
    }

    #[DataProvider('httpFailures')]
    public function test_openai_http_failure_logs_status_type_and_code_only(int $status, ?string $body, string $type, string $code): void
    {
        Http::fake([self::CHAT => Http::response($body ?? $this->keyError(), $status)]);

        $out = (new MacroChecker())->callOpenAI('test-key-not-real', 'sys', 'prompt');

        $this->assertSame('', $out);
        $this->assertSame([
            ['attempt' => 1, 'status' => $status, 'type' => $type, 'code' => $code],
            ['attempt' => 2, 'status' => $status, 'type' => $type, 'code' => $code],
        ], $this->contexts('MACRO_CHECKER_OPENAI_HTTP'));
        $this->assertNowhereLogged(self::FRAGMENT);
    }

    public function test_search_http_failure_logs_status_type_and_code_only(): void
    {
        Http::fake([self::RESP => Http::response($this->keyError(), 401), self::CHAT => Http::response($this->keyError(), 401)]);

        (new MacroChecker())->resolveAddress('pa order po sa market', '', '', '', 'test-key-not-real');

        $row = fn (int $n) => ['step' => 'RESOLVE', 'attempt' => $n, 'status' => 401, 'type' => 'invalid_request_error', 'code' => 'invalid_api_key'];
        $this->assertSame([$row(1), $row(2)], $this->contexts('MACRO_CHECKER_SEARCH_HTTP'));
        $this->assertNowhereLogged(self::FRAGMENT);
    }

    public function test_connection_exception_logs_only_the_exception_class(): void
    {
        $boom = fn () => throw new ConnectionException('cURL error 28: timeout for key ' . self::FRAGMENT);
        Http::fake([self::RESP => $boom, self::CHAT => $boom]);
        $class = 'Illuminate\\Http\\Client\\ConnectionException';

        (new MacroChecker())->callOpenAI('test-key-not-real', 'sys', 'prompt');
        (new MacroChecker())->resolveAddress('pa order po sa market', '', '', '', 'test-key-not-real');

        $this->assertSame([
            ['attempt' => 1, 'exception' => $class],
            ['attempt' => 2, 'exception' => $class],
            // Kasama ang fallback ng resolveAddress papuntang callOpenAI.
            ['attempt' => 1, 'exception' => $class],
            ['attempt' => 2, 'exception' => $class],
        ], $this->contexts('MACRO_CHECKER_OPENAI_EX'));
        $this->assertSame([
            ['step' => 'RESOLVE', 'attempt' => 1, 'exception' => $class],
            ['step' => 'RESOLVE', 'attempt' => 2, 'exception' => $class],
        ], $this->contexts('MACRO_CHECKER_SEARCH_EX'));
        $this->assertNowhereLogged(self::FRAGMENT);
    }

    public function test_a_failed_log_insert_logs_exception_class_and_sqlstate_only(): void
    {
        config(['services.openai.astra_encoder_model' => 'gpt-6-astra', 'services.openai.astra_encoder_effort' => 'high']);
        AstraEncoder::storeApiKey('test-key-not-real');
        $order = MacroOutput::create([
            'ts_date' => '2026-10-03', 'PAGE' => 'Likha Shop', 'ITEM_NAME' => 'JUANA TESTCUSTOMER 123 TEST ST',
            'all_user_input' => 'JUANA TESTCUSTOMER 123 TEST ST pa order po',
        ]);
        Http::fake([self::RESP => Http::response([
            'id'          => 'resp_1',
            'output_text' => json_encode(['form' => ['name' => 'Juan Dela Cruz'], 'intent' => 'order', 'confidence' => 'low']),
            'usage'       => ['input_tokens' => 100, 'output_tokens' => 10],
        ])]);
        // Ang message ng QueryException ay may kasamang SQL at bindings (kasama ang text ng customer).
        DB::statement("CREATE TRIGGER ai_checker_logs_fail BEFORE INSERT ON ai_checker_logs BEGIN SELECT RAISE(ABORT, 'pinalya ng test'); END");

        $out = (new AiCheckerRowRunner())->run($order->id, 'astra', null, [
            'source' => 'night', 'batch_id' => 'night-2026-10-03', 'batch_total' => 1, 'user_id' => null, 'user_name' => 'Night run',
        ]);

        $this->assertSame(200, $out['status']);
        $this->assertNull($out['log_id']);
        $this->assertNowhereLogged('JUANA TESTCUSTOMER');
        $contexts = $this->contexts('AI_CHECKER_LOG_FAIL');
        $this->assertCount(1, $contexts);
        $this->assertSame(['exception', 'sqlstate'], array_keys($contexts[0]));
        $this->assertSame('Illuminate\\Database\\QueryException', $contexts[0]['exception']);
        $this->assertSame('23000', $contexts[0]['sqlstate']); // ang ABORT ng sqlite trigger = constraint violation
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{1,5}$/', $contexts[0]['sqlstate']);
    }
}

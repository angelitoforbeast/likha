<?php

namespace Tests\Feature\NightRun;

use App\Models\MacroOutput;
use App\Services\AiCheckerRowRunner;
use App\Services\AstraEncoder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Ang shared run-one-row function (spec 007 §6.3) kapag tinawag nang walang request — gaya ng night job.
 * Ang browser path mismo ay naka-pin sa RunRowCharacterizationTest.
 */
class AiCheckerRowRunnerTest extends NightRunTestCase
{
    private const NIGHT_CTX = [
        'source' => 'night', 'batch_id' => 'night-2026-10-03', 'batch_total' => 185, 'user_id' => null, 'user_name' => 'Night run',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config([
            'services.openai.astra_encoder_model'  => 'gpt-6-astra',
            'services.openai.astra_encoder_effort' => 'high',
        ]);
        AstraEncoder::storeApiKey('test-key-not-real');

        // Sagot na walang J&T label → code na "Full Address", hindi PROCEED (sapat para sa log row).
        Http::fake(['api.openai.com/v1/responses' => Http::response([
            'id'          => 'resp_1',
            'output_text' => json_encode(['form' => ['name' => 'Juan Dela Cruz'], 'intent' => 'order', 'confidence' => 'low']),
            'usage'       => ['input_tokens' => 100, 'output_tokens' => 10],
        ])]);
    }

    private function order(): MacroOutput
    {
        return MacroOutput::create(['ts_date' => '2026-10-03', 'PAGE' => 'Likha Shop', 'ITEM_NAME' => 'Slimming Tea', 'all_user_input' => 'pa order po']);
    }

    public function test_it_runs_a_row_without_a_request_and_returns_the_log_id(): void
    {
        $order = $this->order();

        $out = (new AiCheckerRowRunner())->run($order->id, 'astra', 'https://likhaaitech.com', self::NIGHT_CTX);

        $this->assertSame(['status', 'payload', 'result', 'log_id', 'last_error'], array_keys($out));
        $this->assertSame(200, $out['status']);
        $this->assertTrue($out['payload']['ok']);
        $this->assertSame('astra', $out['payload']['engine']);
        $this->assertSame($out['result'], $out['payload']['result']);
        $this->assertSame('partial', $out['result']['status']);
        $this->assertNull($out['last_error']);

        $log = DB::table('ai_checker_logs')->sole();
        $this->assertSame($log->id, $out['log_id']);
        $this->assertSame(
            ['night', 'night-2026-10-03', 185, null, 'Night run', $order->id, 'partial'],
            [$log->source, $log->batch_id, $log->batch_total, $log->user_id, $log->user_name, $log->macro_output_id, $log->outcome]
        );
    }

    public function test_a_failed_log_insert_does_not_fail_the_row(): void
    {
        $order = $this->order();
        // Pinapalya ang insert sa mismong database (sqlite trigger); nananatili ang table at ang columns nito.
        DB::statement("CREATE TRIGGER ai_checker_logs_fail BEFORE INSERT ON ai_checker_logs BEGIN SELECT RAISE(ABORT, 'pinalya ng test'); END");

        $out = (new AiCheckerRowRunner())->run($order->id, 'astra', null, self::NIGHT_CTX);

        $this->assertSame(200, $out['status']);
        $this->assertNull($out['log_id']);
        $this->assertSame(0, DB::table('ai_checker_logs')->count());
        $this->assertSame('Full Address', MacroOutput::find($order->id)->{'APP SCRIPT CHECKER'});
    }
}

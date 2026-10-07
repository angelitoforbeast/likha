<?php

namespace Tests\Feature\NightRun;

use App\Models\MacroOutput;
use App\Services\AstraEncoder;
use App\Services\MacroChecker;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Mga dagdag sa AstraEncoder para sa night run:
 * lastError(), httpTimeout(), onlyWhenStatusBlank(), ang log na walang body, at ang presyo ng gpt-6-luna.
 * Lahat ay opt-in o logging lang — ang browser path ay naka-pin sa RunRowCharacterizationTest.
 */
class AstraEncoderAdditionsTest extends NightRunTestCase
{
    private const KEY_LIKE = 'sk-proj-abc123SECRETxyz';

    private static ?array $maps = null;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['services.openai.astra_encoder_max_web' => 4]);
        AstraEncoder::storeApiKey('test-key-not-real');
    }

    private function maps(): array
    {
        return self::$maps ??= MacroChecker::loadAddressMaps();
    }

    private function order(array $extra = []): MacroOutput
    {
        return MacroOutput::create(array_merge([
            'TIMESTAMP'      => '21:14 03-10-2026',
            'ts_date'        => '2026-10-03',
            'PAGE'           => 'Likha Shop',
            'ITEM_NAME'      => 'Slimming Tea',
            'COD'            => '599',
            'all_user_input' => "Juan Dela Cruz\n09171234567\n12 Sampaguita St, Holy Spirit, Quezon City",
        ], $extra));
    }

    /** Sagot ni Astra na pasado sa lahat ng gate → PROCEED (kapareho ng fixture ng characterization test). */
    private function goodAnswer(array $usage = ['input_tokens' => 12000, 'output_tokens' => 1500]): array
    {
        return [
            'id'          => 'resp_1',
            'output_text' => json_encode([
                'form' => [
                    'name' => 'Juan Dela Cruz', 'phone' => '09171234567', 'house_number' => '12', 'address' => 'Sampaguita St',
                    'brgy' => 'Holy Spirit', 'city' => 'Quezon City', 'province' => 'Metro Manila',
                ],
                'jnt'        => ['province' => 'METRO-MANILA', 'city' => 'QUEZON-CITY', 'barangay' => 'HOLY SPIRIT'],
                'intent'     => 'order',
                'confidence' => 'high',
            ]),
            'usage' => $usage,
        ];
    }

    private function openAiError(int $status, string $type, ?string $code, string $message = 'May problema.'): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response(['error' => ['message' => $message, 'type' => $type, 'param' => null, 'code' => $code]], $status);
    }

    /** Kinukuha ang bawat Log::warning bilang isang linya ng text, para mahanap kung may tumagas. */
    private function captureWarnings(): \ArrayObject
    {
        $lines = new \ArrayObject();
        Log::shouldReceive('warning')->zeroOrMoreTimes()->andReturnUsing(function ($message, array $context = []) use ($lines) {
            $lines[] = ['message' => $message, 'context' => $context];
        });

        return $lines;
    }

    public function test_a_401_leaves_no_part_of_the_key_in_the_log_and_is_remembered_as_the_last_error(): void
    {
        $lines = $this->captureWarnings();
        Http::fake(['api.openai.com/v1/responses' => $this->openAiError(
            401, 'invalid_request_error', 'invalid_api_key', 'Incorrect API key provided: ' . self::KEY_LIKE . '. You can find your API key at the dashboard.'
        )]);
        $encoder = new AstraEncoder();

        $result = $encoder->processRow($this->order()->id, $this->maps());

        $this->assertSame('failed', $result['status']);
        $this->assertSame(['kind' => 'http', 'status' => 401, 'code' => 'invalid_api_key'], $encoder->lastError());
        $this->assertSame([[
            'message' => 'ASTRA_ENCODER_HTTP',
            'context' => ['attempt' => 1, 'status' => 401, 'type' => 'invalid_request_error', 'code' => 'invalid_api_key'],
        ]], $lines->getArrayCopy());
        $this->assertStringNotContainsString('SECRET', json_encode($lines->getArrayCopy()));
        $this->assertStringNotContainsString('SECRET', json_encode($result));

        // Bagong processRow → nalilinis ang naalalang error (dito: row na wala).
        $encoder->processRow(999999, $this->maps());
        $this->assertNull($encoder->lastError());
    }

    public static function transportFailures(): array
    {
        return [
            '429 with insufficient_quota' => [
                ['error' => ['message' => 'You exceeded your current quota.', 'type' => 'insufficient_quota', 'code' => 'insufficient_quota']], 429,
                ['kind' => 'http', 'status' => 429, 'code' => 'insufficient_quota'],
            ],
            '500 with a body that is not JSON' => [
                'upstream connect error', 500,
                ['kind' => 'http', 'status' => 500, 'code' => null],
            ],
            '403 with no code: the type, cut to 64 safe characters' => [
                ['error' => ['message' => 'Bawal.', 'type' => str_repeat('a', 70) . ' <b>x</b>', 'code' => null]], 403,
                ['kind' => 'http', 'status' => 403, 'code' => str_repeat('a', 64)],
            ],
            'connection exception' => [
                null, 0,
                ['kind' => 'exception', 'status' => null, 'code' => null],
            ],
        ];
    }

    #[DataProvider('transportFailures')]
    public function test_the_last_transport_failure_is_remembered_without_body_or_message(array|string|null $body, int $status, array $expected): void
    {
        $lines = $this->captureWarnings();
        Http::fake(['api.openai.com/v1/responses' => function () use ($body, $status) {
            if ($body === null) {
                throw new ConnectionException('cURL error 28: timed out, token ' . self::KEY_LIKE);
            }

            return Http::response($body, $status);
        }]);
        $encoder = new AstraEncoder();

        $result = $encoder->processRow($this->order()->id, $this->maps());

        $this->assertSame('Astra: walang sagot mula sa AI', $result['message']);
        $this->assertSame($expected, $encoder->lastError());
        $logged = json_encode($lines->getArrayCopy());
        $this->assertStringNotContainsString('SECRET', $logged);
        $this->assertStringNotContainsString('upstream connect error', $logged);
        if ($body === null) {
            $this->assertSame(
                ['message' => 'ASTRA_ENCODER_EX', 'context' => ['attempt' => 1, 'exception' => ConnectionException::class]],
                $lines[0]
            );
        }
    }

    public function test_a_successful_post_clears_the_last_error(): void
    {
        $this->captureWarnings();
        // 429 muna, tapos 200 na walang magagamit na sagot: failed ang row pero HINDI na transport error.
        Http::fake(['api.openai.com/v1/responses' => Http::sequence()
            ->push(['error' => ['type' => 'requests', 'code' => 'rate_limit_exceeded']], 429)
            ->push(['id' => 'resp_1', 'output_text' => '', 'usage' => ['input_tokens' => 10, 'output_tokens' => 1]], 200)]);
        $encoder = new AstraEncoder();

        $result = $encoder->processRow($this->order()->id, $this->maps());

        $this->assertSame('failed', $result['status']);
        $this->assertNull($encoder->lastError());
        Http::assertSentCount(2);
    }

    public function test_only_when_status_blank_never_writes_over_a_status_a_person_set_during_the_call(): void
    {
        // [option on?, STATUS bago ang call, STATUS na inilagay ng tao HABANG tumatakbo ang call (null = wala)]
        $cases = [
            'on, a person set the status during the call' => [true, null, 'CANNOT PROCEED'],
            'on, status still blank'                       => [true, null, null],
            'on, status is only spaces'                    => [true, '   ', null],
            'off, a person set the status during the call' => [false, null, 'CANNOT PROCEED'],
        ];
        $duringCall = fn () => null;
        Http::fake(['api.openai.com/v1/responses' => function () use (&$duringCall) {
            $duringCall();

            return Http::response($this->goodAnswer());
        }]);

        $day = 0;
        foreach ($cases as $name => [$on, $statusBefore, $personSets]) {
            // Ibang petsa kada case: kung hindi, "PHONE duplicate sa parehong petsa" ang gate ng mga susunod.
            $order = $this->order(['STATUS' => $statusBefore, 'ts_date' => '2026-09-0' . ++$day]);
            $asLeftByPerson = null;
            $duringCall = function () use ($order, $personSets, &$asLeftByPerson) {
                if ($personSets !== null) {
                    DB::table('macro_output')->where('id', $order->id)->update(['STATUS' => $personSets, 'FULL NAME' => 'Inilagay Ng Tao']);
                }
                $asLeftByPerson = (array) DB::table('macro_output')->where('id', $order->id)->first();
            };
            $encoder = new AstraEncoder();
            if ($on) {
                $encoder->onlyWhenStatusBlank();
            }

            $result = $encoder->processRow($order->id, $this->maps());
            $after  = (array) DB::table('macro_output')->where('id', $order->id)->first();

            if ($on && $personSets !== null) {
                $this->assertSame($asLeftByPerson, $after, $name);
                $this->assertSame(
                    ['skipped', null, false, 'Status set by a person', 12000],
                    [$result['status'], $result['final_code'], $result['all_filled'], $result['message'], $result['log']['summary']['tokens_in']],
                    $name
                );
            } else {
                // Gaya ngayon: isinusulat ang anim na field, ang code at ang PROCEED.
                $this->assertSame(
                    ['fixed', '✅', 'PROCEED', 'Juan Dela Cruz', '✅'],
                    [$result['status'], $result['final_code'], $after['STATUS'], $after['FULL NAME'], $after['APP SCRIPT CHECKER']],
                    $name
                );
            }
        }
    }

    public function test_http_timeout_is_opt_in_and_defaults_to_300_seconds(): void
    {
        $timeouts = [];
        Http::fake(['api.openai.com/v1/responses' => function (Request $request, array $options) use (&$timeouts) {
            $timeouts[] = $options['timeout'] ?? null;

            return Http::response($this->goodAnswer());
        }]);

        (new AstraEncoder())->processRow($this->order()->id, $this->maps());
        (new AstraEncoder())->httpTimeout(120)->processRow($this->order()->id, $this->maps());

        $this->assertSame([300, 120], $timeouts);
    }

    public function test_gpt_6_luna_cost_is_computed_from_the_config_price(): void
    {
        Http::fake(['api.openai.com/v1/responses' => Http::response(array_merge(
            $this->goodAnswer(['input_tokens' => 200000, 'output_tokens' => 30000]),
            ['output' => [['type' => 'web_search_call', 'action' => ['query' => 'a']], ['type' => 'web_search_call', 'action' => ['query' => 'b']]]]
        ))]);

        $result = (new AstraEncoder('gpt-6-luna', 'low'))->processRow($this->order()->id, $this->maps());

        // 200,000 × 0.10 / 1M = 0.02 · 30,000 × 0.50 / 1M = 0.015 · 2 web searches × 0.01 = 0.02 → 0.055
        $this->assertSame(0.055, $result['log']['summary']['cost_usd']);
        $this->assertTrue($result['log']['summary']['cost_known']);
    }
}

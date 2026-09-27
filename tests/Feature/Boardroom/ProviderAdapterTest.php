<?php

namespace Tests\Feature\Boardroom;

use App\Boardroom\Capabilities\CapabilityRegistry;
use App\Boardroom\Providers\AdapterFactory;
use App\Boardroom\Providers\ProviderRequest;
use App\Boardroom\Providers\ProviderResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * MOCKED ADAPTER TESTS — sinusubok ang hugis ng request at ang pag-parse ng sagot laban sa
 * mga hugis na nakasaad sa opisyal na docs (2026-09-28). Http::fake() ang gamit.
 *
 * HINDI ito patunay na gumagana ang live provider. Walang totoong request na lumalabas dito.
 */
class ProviderAdapterTest extends BoardroomTestCase
{
    private const KEY = 'sk-test-adapter-key-00000000000000001234';

    private function request(string $provider, string $model, array $settings = [], bool $schema = false): ProviderRequest
    {
        $r = app(CapabilityRegistry::class)->resolve($provider, $model, $settings);

        return new ProviderRequest(
            $provider, $model, 'SYSTEM-TEXT', 'USER-TEXT', $r['applied'],
            $schema ? ['name' => 'demo', 'schema' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'required' => ['a'], 'additionalProperties' => false]] : null,
            $r['structured']
        );
    }

    public function test_openai_uses_the_responses_api(): void
    {
        $seen = null;
        Http::fake(function (Request $req) use (&$seen) {
            $seen = $req;

            return $this->openai('Hello', ['id' => 'resp_abc', 'model' => 'gpt-6-astra-2026-08-01']);
        });

        $result = app(AdapterFactory::class)->make('openai')->send(
            $this->request('openai', 'gpt-6-astra', ['effort' => 'xhigh', 'max_output_tokens' => 5000], true), self::KEY
        );

        $this->assertSame('https://api.openai.com/v1/responses', $seen->url());
        $this->assertSame('Bearer ' . self::KEY, $seen->header('Authorization')[0]);
        $body = $seen->data();
        $this->assertSame('SYSTEM-TEXT', $body['instructions']);
        $this->assertSame('USER-TEXT', $body['input']);
        $this->assertSame(5000, $body['max_output_tokens']);
        $this->assertSame(['effort' => 'xhigh'], $body['reasoning']);
        $this->assertSame('json_schema', $body['text']['format']['type']);
        $this->assertTrue($body['text']['format']['strict']);
        $this->assertFalse($body['store']);
        $this->assertStringNotContainsString(self::KEY, json_encode($body));

        $this->assertTrue($result->ok);
        $this->assertSame('Hello', $result->text);
        $this->assertSame(ProviderResult::COMPLETED, $result->finish);
        $this->assertSame([1000, 200, 50], [$result->tokensIn, $result->tokensOut, $result->tokensReasoning]);
        $this->assertSame('resp_abc', $result->responseId);
        $this->assertStringNotContainsString('HIDDEN-REASONING', $result->text);
        $this->assertArrayNotHasKey('input', $result->sent);
    }

    public function test_openai_incomplete_and_refusal_are_normalized(): void
    {
        $adapter = app(AdapterFactory::class)->make('openai');

        Http::fake(fn () => $this->openai('partial', ['status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens']]));
        $this->assertSame(ProviderResult::TRUNCATED, $adapter->send($this->request('openai', 'gpt-6-astra'), self::KEY)->finish);
    }

    public function test_openai_refusal_is_reported_as_refused(): void
    {
        Http::fake(fn () => Http::response([
            'id' => 'resp_r', 'status' => 'completed', 'model' => 'gpt-6-astra',
            'output' => [['type' => 'message', 'content' => [['type' => 'refusal', 'refusal' => 'I cannot help with that.']]]],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]));

        $result = app(AdapterFactory::class)->make('openai')->send($this->request('openai', 'gpt-6-astra'), self::KEY);

        $this->assertTrue($result->ok);
        $this->assertSame(ProviderResult::REFUSED, $result->finish);
    }

    public function test_anthropic_uses_the_native_messages_api(): void
    {
        $seen = null;
        Http::fake(function (Request $req) use (&$seen) {
            $seen = $req;

            return Http::response([
                'id' => 'msg_01', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5',
                'content' => [
                    ['type' => 'thinking', 'thinking' => 'HIDDEN-THINKING', 'signature' => 'sig'],
                    ['type' => 'text', 'text' => 'Kumusta'],
                ],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 120, 'output_tokens' => 40, 'cache_read_input_tokens' => 30, 'cache_creation_input_tokens' => 0],
            ]);
        });

        $result = app(AdapterFactory::class)->make('anthropic')->send(
            $this->request('anthropic', 'claude-opus-5', ['effort' => 'high', 'thinking' => 'adaptive', 'max_output_tokens' => 6000], true), self::KEY
        );

        $this->assertSame('https://api.anthropic.com/v1/messages', $seen->url());
        $this->assertSame(self::KEY, $seen->header('x-api-key')[0]);
        $this->assertSame('2023-06-01', $seen->header('anthropic-version')[0]);
        $this->assertEmpty($seen->header('Authorization'), 'Hindi Bearer ang auth ng Anthropic.');
        $this->assertEmpty($seen->header('anthropic-beta'), 'Walang beta header (walang fallbacks).');

        $body = $seen->data();
        $this->assertSame(6000, $body['max_tokens']);
        $this->assertSame('SYSTEM-TEXT', $body['system']);
        $this->assertSame([['role' => 'user', 'content' => 'USER-TEXT']], $body['messages']);
        $this->assertSame(['type' => 'adaptive'], $body['thinking']);
        $this->assertSame('high', $body['output_config']['effort']);
        $this->assertSame('json_schema', $body['output_config']['format']['type']);
        foreach (['fallbacks', 'temperature', 'top_p', 'top_k', 'reasoning', 'max_output_tokens'] as $absent) {
            $this->assertArrayNotHasKey($absent, $body);
        }

        $this->assertTrue($result->ok);
        $this->assertSame('Kumusta', $result->text);
        $this->assertSame(150, $result->tokensIn, 'Kasama ang cached input tokens sa kabuuang input.');
        $this->assertSame(40, $result->tokensOut);
        $this->assertSame(ProviderResult::COMPLETED, $result->finish);
    }

    public function test_anthropic_stop_reasons_are_normalized(): void
    {
        $adapter = app(AdapterFactory::class)->make('anthropic');
        $reply   = fn (string $stop, array $content = [['type' => 'text', 'text' => 'x']]) => Http::response([
            'id' => 'msg', 'model' => 'claude-fable-5-1', 'content' => $content, 'stop_reason' => $stop,
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ]);

        Http::fake(fn () => $reply('max_tokens'));
        $this->assertSame(ProviderResult::TRUNCATED, $adapter->send($this->request('anthropic', 'claude-fable-5-1'), self::KEY)->finish);
    }

    public function test_anthropic_refusal_is_not_silently_rerouted(): void
    {
        $count = 0;
        Http::fake(function () use (&$count) {
            $count++;

            return Http::response([
                'id' => 'msg', 'model' => 'claude-fable-5-1', 'content' => [], 'stop_reason' => 'refusal',
                'usage' => ['input_tokens' => 1, 'output_tokens' => 0],
            ]);
        });

        $result = app(AdapterFactory::class)->make('anthropic')->send($this->request('anthropic', 'claude-fable-5-1'), self::KEY);

        $this->assertSame(ProviderResult::REFUSED, $result->finish);
        $this->assertSame(1, $count, 'Isang request lang — walang paglipat sa ibang model.');
    }

    public function test_deepseek_uses_the_documented_chat_completions_endpoint(): void
    {
        $seen = null;
        Http::fake(function (Request $req) use (&$seen) {
            $seen = $req;

            return Http::response([
                'id' => 'ds_1', 'model' => 'deepseek-v4-pro',
                'choices' => [['index' => 0, 'finish_reason' => 'stop', 'message' => [
                    'role' => 'assistant', 'content' => '{"a":"b"}', 'reasoning_content' => 'HIDDEN-REASONING-CONTENT',
                ]]],
                'usage' => ['prompt_tokens' => 300, 'completion_tokens' => 90, 'completion_tokens_details' => ['reasoning_tokens' => 60]],
            ]);
        });

        $result = app(AdapterFactory::class)->make('deepseek')->send(
            $this->request('deepseek', 'deepseek-v4-pro', ['effort' => 'max', 'thinking' => 'enabled', 'max_output_tokens' => 4000], true), self::KEY
        );

        $this->assertSame('https://api.deepseek.com/chat/completions', $seen->url());
        $this->assertSame('Bearer ' . self::KEY, $seen->header('Authorization')[0]);
        $body = $seen->data();
        $this->assertSame('system', $body['messages'][0]['role']);
        $this->assertSame('USER-TEXT', $body['messages'][1]['content']);
        $this->assertSame(4000, $body['max_tokens']);
        $this->assertSame(['type' => 'enabled'], $body['thinking']);
        $this->assertSame('max', $body['reasoning_effort']);
        $this->assertSame(['type' => 'json_object'], $body['response_format']);
        $this->assertFalse($body['stream']);

        $this->assertSame('{"a":"b"}', $result->text);
        $this->assertStringNotContainsString('HIDDEN', $result->text);
        $this->assertSame([300, 90, 60], [$result->tokensIn, $result->tokensOut, $result->tokensReasoning]);
    }

    public function test_deepseek_finish_reasons_are_normalized(): void
    {
        $adapter = app(AdapterFactory::class)->make('deepseek');
        $reply   = fn (string $reason) => Http::response([
            'id' => 'x', 'model' => 'deepseek-flash', 'choices' => [['finish_reason' => $reason, 'message' => ['content' => 'x']]],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ]);

        Http::fake(fn () => $reply('length'));
        $this->assertSame(ProviderResult::TRUNCATED, $adapter->send($this->request('deepseek', 'deepseek-flash'), self::KEY)->finish);
    }

    public function test_deepseek_resource_failure_is_retryable(): void
    {
        Http::fake(fn () => Http::response([
            'id' => 'x', 'model' => 'deepseek-flash', 'choices' => [['finish_reason' => 'insufficient_system_resource', 'message' => ['content' => '']]],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 0],
        ]));

        $result = app(AdapterFactory::class)->make('deepseek')->send($this->request('deepseek', 'deepseek-flash'), self::KEY);

        $this->assertFalse($result->ok);
        $this->assertTrue($result->retryable);
    }

    /** Normalized na error, at kung alin ang retryable. */
    public function test_errors_are_normalized_across_providers(): void
    {
        $cases = [
            // [provider, status, body, expected code, retryable]
            ['openai', 401, ['error' => ['message' => 'Incorrect API key provided: ' . self::KEY, 'type' => 'invalid_request_error', 'code' => 'invalid_api_key']], 'invalid_credentials', false],
            ['openai', 404, ['error' => ['message' => 'The model `gpt-x` does not exist or you do not have access to it.', 'code' => 'model_not_found']], 'model_not_found', false],
            ['openai', 400, ['error' => ['message' => "Unsupported parameter: 'reasoning.effort' is not supported with this model.", 'type' => 'invalid_request_error']], 'unsupported_parameter', false],
            ['openai', 429, ['error' => ['message' => 'Rate limit reached', 'type' => 'rate_limit_error']], 'rate_limited', true],
            ['openai', 429, ['error' => ['message' => 'You exceeded your current quota', 'type' => 'insufficient_quota', 'code' => 'insufficient_quota']], 'billing', false],
            ['openai', 500, ['error' => ['message' => 'Server error']], 'provider_error', true],
            ['anthropic', 401, ['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']], 'invalid_credentials', false],
            ['anthropic', 403, ['type' => 'error', 'error' => ['type' => 'permission_error', 'message' => 'not allowed']], 'permission_denied', false],
            ['anthropic', 404, ['type' => 'error', 'error' => ['type' => 'not_found_error', 'message' => 'model: claude-x']], 'model_not_found', false],
            ['anthropic', 429, ['type' => 'error', 'error' => ['type' => 'rate_limit_error', 'message' => 'slow down']], 'rate_limited', true],
            ['anthropic', 529, ['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']], 'overloaded', true],
            ['anthropic', 402, ['type' => 'error', 'error' => ['type' => 'billing_error', 'message' => 'billing issue']], 'billing', false],
            ['anthropic', 413, ['type' => 'error', 'error' => ['type' => 'request_too_large', 'message' => 'too large']], 'request_too_large', false],
            ['deepseek', 402, ['error' => ['message' => 'Insufficient Balance']], 'billing', false],
            ['deepseek', 422, ['error' => ['message' => 'Invalid parameters']], 'invalid_request', false],
            ['deepseek', 503, ['error' => ['message' => 'Server overloaded']], 'provider_error', true],
        ];

        $models = ['openai' => 'gpt-6-astra', 'anthropic' => 'claude-opus-5', 'deepseek' => 'deepseek-v4-pro'];
        foreach ($cases as $i => [$provider, $status, $body, $code, $retryable]) {
            Http::swap(new \Illuminate\Http\Client\Factory());
            Http::fake(fn () => Http::response($body, $status));

            $result = app(AdapterFactory::class)->make($provider)->send($this->request($provider, $models[$provider]), self::KEY);

            $this->assertFalse($result->ok, "case {$i}");
            $this->assertSame($code, $result->errorCode, "case {$i} ({$provider} {$status})");
            $this->assertSame($retryable, $result->retryable, "case {$i} retryable");
            $this->assertSame($status, $result->httpStatus);
            $this->assertStringNotContainsString(self::KEY, (string) $result->errorMessage, 'Naka-redact dapat ang key sa error.');
        }
    }

    public function test_timeouts_are_retryable_and_use_the_role_timeout(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 30001 milliseconds'));

        $result = app(AdapterFactory::class)->make('openai')->send($this->request('openai', 'gpt-6-astra', ['timeout_s' => 30]), self::KEY);

        $this->assertFalse($result->ok);
        $this->assertSame('timeout', $result->errorCode);
        $this->assertTrue($result->retryable);
    }

    public function test_unknown_provider_is_an_error_not_a_fallback(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(AdapterFactory::class)->make('some-other-provider');
    }
}

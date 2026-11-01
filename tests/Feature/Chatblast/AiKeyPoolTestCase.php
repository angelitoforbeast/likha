<?php

namespace Tests\Feature\Chatblast;

use App\Services\Chatbot\ChatbotAiChain;
use App\Services\Chatbot\ChatbotAiKeys;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Ang pinagsasaluhan ng mga test ng spec 210: ang mga page at AI flow ng spec 150, ang pool ng key, at ang pekeng OpenAI,
 * Claude, DeepSeek at Forbeast (Http::fake, walang totoong tawag). Ang sagot ng pekeng provider ay pinipili ayon sa key na
 * dumating (Authorization o x-api-key), para makita kung aling key ang tinawag.
 */
abstract class AiKeyPoolTestCase extends ChatbotAiProviderTestCase
{
    protected const CLAUDE = 'https://api.anthropic.com/v1/messages';
    protected const DEEPSEEK = 'https://api.deepseek.com/chat/completions';
    protected const FORBEAST = 'https://forbeast.test/api';
    protected const INCEPXION = 'https://incepxionbot.test/api';
    protected const KEY_A = 'sk-test-pool-aaaa1111';
    protected const KEY_B = 'sk-test-pool-bbbb2222';
    protected const KEY_C = 'sk-test-pool-cccc3333';

    /** @var array<string, array<int, mixed>> key => mga sagot (Response o closure), ayon sa ayos */
    protected array $byKey = [];
    /** @var array<int, array{url: string, key: string, body: array}> bawat tawag sa isang provider */
    protected array $providerCalls = [];
    /** @var array<int, mixed> mga sagot ng pekeng Forbeast (ai-usage at ai-load), ayon sa ayos; wala = ang default */
    protected array $forbeast = [];

    protected function extraMigrations(): array
    {
        return [...parent::extraMigrations(), 'database/migrations/2026_11_15_100000_add_key_and_cost_to_chatbot_ai_requests.php'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Ang sariling pekeng HTTP (ang sa spec 150 ay may "*" na nauuna sa anumang idaragdag).
        Http::swap(new Factory());
        Http::preventStrayRequests();
        Http::fake(function (HttpRequest $r) {
            $url = $r->url();
            if (str_contains($url, '/flows/send_content')) return Http::response(['success' => true]);
            if (str_starts_with($url, self::FORBEAST) || str_starts_with($url, self::INCEPXION)) return $this->forbeastAnswer($r);
            if ($url === self::LOCAL) {
                $this->localHits++;
                return self::ai('local answer', ['prompt_tokens' => 5, 'completion_tokens' => 2]);
            }
            if (in_array($url, [self::OPENAI, self::CLAUDE, self::DEEPSEEK], true)) {
                $key = $r->hasHeader('x-api-key') ? $r->header('x-api-key')[0] : trim(substr((string) ($r->header('Authorization')[0] ?? ''), 7));
                $this->providerCalls[] = ['url' => $url, 'key' => $key, 'body' => $r->data()];
                if ($url === self::OPENAI) $this->openaiHits++;
                $a = isset($this->byKey[$key]) && $this->byKey[$key] ? array_shift($this->byKey[$key]) : $this->okFor($url, 'answer by ' . substr($key, -4));

                return $a instanceof \Closure ? $a($r) : $a;
            }

            return Http::response('not expected', 599);
        });
        config(['services.gateway.url' => self::FORBEAST, 'services.forbeast_site.key' => 'fbs_test_site_key_not_real',
            'services.incepxionbot.url' => self::INCEPXION, 'services.incepxionbot.site_key' => 'fbs_test_incepxion_key_not_real']);
        ChatbotAiChain::forget();
    }

    /** Ang default na sagot ng Forbeast: lahat ng record ay stored, at lahat ng page ay ok. */
    protected function forbeastAnswer(HttpRequest $r)
    {
        if ($this->forbeast) {
            $a = array_shift($this->forbeast);
            return $a instanceof \Closure ? $a($r) : $a;
        }
        if (str_contains($r->url(), '/v1/site/ai-usage')) {
            return Http::response(['results' => array_map(fn ($rec) => ['client_ref' => $rec['client_ref'], 'result' => 'stored', 'charged_php' => '0.010000'], $r->data()['records'] ?? [])]);
        }
        parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

        return Http::response(['pages' => array_map(fn ($id) => ['page_id' => $id, 'state' => 'ok', 'balance_php' => '500.00', 'note' => null], explode(',', (string) ($q['pages'] ?? '')))]);
    }

    /** Ang tamang sagot ng provider, sa sariling hugis nito, may usage. */
    protected function okFor(string $url, string $text, ?array $usage = ['in' => 100, 'out' => 20], int $status = 200)
    {
        return match ($url) {
            self::CLAUDE => Http::response(['id' => 'msg_1', 'type' => 'message', 'model' => 'claude-haiku-4-5', 'content' => [['type' => 'text', 'text' => $text]], 'stop_reason' => 'end_turn']
                + ($usage ? ['usage' => ['input_tokens' => $usage['in'], 'output_tokens' => $usage['out']]] : []), $status),
            default => self::ai($text, $usage ? ['prompt_tokens' => $usage['in'], 'completion_tokens' => $usage['out']] : null, $status),
        };
    }

    /** Isang error ng provider (HTTP status at body na hugis OpenAI). */
    protected static function error(int $status, string $message = 'error', string $type = '', string $code = '')
    {
        return Http::response(['error' => ['message' => $message, 'type' => $type, 'code' => $code]], $status);
    }

    protected function keys(): ChatbotAiKeys
    {
        return app(ChatbotAiKeys::class);
    }

    /** Isang key sa pool (gaya ng Add ng owner). @return string ang id */
    protected function addKey(string $provider, string $label, string $key, ?int $userId = null): string
    {
        $res = $this->keys()->add($provider, $label, $key, $userId);
        $this->assertNull($res['error'], (string) $res['error']);

        return $res['id'];
    }

    /** @return array<int, string> ang mga key na tinawag, ayon sa ayos (huling 4) */
    protected function calledKeys(?string $url = null): array
    {
        return array_values(array_map(fn ($c) => substr($c['key'], -4), array_filter($this->providerCalls, fn ($c) => $url === null || $c['url'] === $url)));
    }

    protected function lastRow(): object
    {
        return DB::table('chatbot_ai_requests')->orderByDesc('id')->first();
    }

    /** Ang page ay sa $provider muna, saka ang $fallback (listahan, "," ang hati). */
    protected function useProvider(string $page, string $provider, ?string $fallback = null): void
    {
        $s = app(\App\Services\PageSettings::class);
        $s->set($page, 'chatbot', 'ai_provider', $provider, false, null, true);
        if ($fallback !== null) $s->set($page, 'chatbot', 'ai_fallback', $fallback, false, null, true);
    }
}

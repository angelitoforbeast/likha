<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeChat;
use App\Models\ChatbotFlow;
use App\Services\Chatbot\ChatbotAi;
use App\Services\Chatbot\ChatbotAiChain;
use App\Services\Chatbot\ChatbotEvents;
use App\Services\Chatbot\ChatbotPage;
use App\Services\Chatbot\ChatbotRunner;
use App\Services\Chatbot\FlowGraph;
use App\Services\PageSettings;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Ang pinagsasaluhan ng mga test ng spec 150: dalawang page (Page Uno sa OpenAI, Tech Flask Ph na maaaring Local), ang AI
 * flow, at ang pekeng OpenAI at Local (Http::fake, walang totoong tawag).
 */
abstract class ChatbotAiProviderTestCase extends ChatblastTestCase
{
    protected const SEND = 'https://botcake.io/api/public_api/v1/pages/*/flows/send_content';
    protected const OPENAI = 'https://api.openai.com/v1/chat/completions';
    protected const LOCAL_BASE = 'http://127.0.0.1:11435/v1';
    protected const LOCAL = 'http://127.0.0.1:11435/v1/chat/completions';
    protected const TECH = 'Tech Flask Ph';

    /** @var array<int, mixed> mga sagot ng pekeng Local at OpenAI, ayon sa ayos (Response o closure) */
    protected array $localAnswers = [];
    protected array $openaiAnswers = [];
    /** Ilang tawag ang dumating sa pekeng Local at OpenAI (kasama ang pumalyang koneksyon). */
    protected int $localHits = 0;
    protected int $openaiHits = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_draft_to_chatbot_flows.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_25_100000_create_chatbot_waits_table.php',
            'database/migrations/2026_10_26_100000_create_chatbot_sequence_tables.php',
            'database/migrations/2026_10_28_100000_create_chatbot_ai_requests_table.php',
            ...$this->extraMigrations(),
        ]]);
        config(['services.chatbot.replies_enabled' => true, 'services.chatbot.ai_async' => false,
            'services.openai.key' => 'sk-test-not-real', 'services.openai.timeout' => 60,
            'services.chatbot_ai.providers.local.url' => self::LOCAL_BASE, 'services.chatbot_ai.providers.local.key' => '']);
        $this->page('Page Uno', '1001');
        $this->page(self::TECH, '1002');
        Http::fake([
            self::SEND => Http::response(['success' => true]),
            self::LOCAL => function (HttpRequest $r) {
                $this->localHits++;
                return $this->next($this->localAnswers, 'local answer', $r);
            },
            self::OPENAI => function (HttpRequest $r) {
                $this->openaiHits++;
                return $this->next($this->openaiAnswers, 'openai answer', $r);
            },
            '*' => Http::response('not expected', 599),
        ]);
        ChatbotAiChain::forget();
    }

    /** @return array<int, string> ang migration ng record (spec 150), kapag kailangan ng test */
    protected function extraMigrations(): array
    {
        return [];
    }

    private function next(array &$answers, string $default, HttpRequest $r)
    {
        $a = $answers ? array_shift($answers) : self::ai($default);

        return $a instanceof \Closure ? $a($r) : $a;
    }

    protected static function ai(string $text, ?array $usage = null, int $status = 200): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response(['choices' => [['message' => ['content' => $text]]]] + ($usage ? ['usage' => $usage] : []), $status);
    }

    /** Ang page ay sa Local (may sariling model), at opsyonal ang fallback (openai | local | none). */
    protected function useLocal(string $page = self::TECH, string $model = 'qwen3:14b', ?string $fallback = null): void
    {
        $s = app(PageSettings::class);
        $s->set($page, 'chatbot', 'ai_provider', 'local', false, null, true);
        $s->set($page, 'chatbot', 'ai_local_model', $model, false, null, true);
        if ($fallback !== null) $s->set($page, 'chatbot', 'ai_fallback', $fallback, false, null, true);
    }

    /** Walang breaker sa test na maraming sunod-sunod na palya ng Local (ibang kaso ang sinusuri). */
    protected function noBreaker(): void
    {
        \App\Models\AppSetting::set('chatbot.ai.breaker_failures', '1000');
        ChatbotAiChain::forget();
    }

    protected static function refused(string $detail = 'Failed to connect to 127.0.0.1 port 11435'): \Closure
    {
        return Http::failedConnection('cURL error 7: ' . $detail);
    }

    protected static function timedOut(): \Closure
    {
        return Http::failedConnection('cURL error 28: Operation timed out after 8001 milliseconds');
    }

    /** n2 AI (save sa answer) → success: n3 "Reply: {{answer}}", failed: n4 "Sorry". */
    protected function live(string $page = self::TECH): ChatbotFlow
    {
        $graph = FlowGraph::clean(['nodes' => [
            ['id' => 'n1', 'type' => 'start'],
            ['id' => 'n2', 'type' => 'ai', 'config' => ['user' => '{{last_input}}', 'save_to' => 'answer', 'history' => 0]],
            ['id' => 'n3', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'Reply: {{answer}}']]]],
            ['id' => 'n4', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'Sorry']]]],
        ], 'edges' => [['from' => 'n1', 'port' => 'next', 'to' => 'n2'], ['from' => 'n2', 'port' => 'success', 'to' => 'n3'], ['from' => 'n2', 'port' => 'failed', 'to' => 'n4']]]);
        $flow = ChatbotFlow::create(['name' => 'AI flow ' . $page, 'graph' => $graph]);
        DB::table('chatbot_flows')->where('id', $flow->id)->update(['published_at' => now()]);
        app(ChatbotPage::class)->link($page, $flow);

        return $flow->fresh();
    }

    protected function says(string $message, string $page = self::TECH, string $psid = '7001', bool $run = true): BotcakeChat
    {
        $id = $page === self::TECH ? '1002' : '1001';
        $chat = BotcakeChat::create(['page_id' => $id, 'page_name' => $page, 'psid' => $psid, 'name' => 'Ana Cruz', 'message' => $message, 'payload' => [], 'received_at' => now()]);
        if ($run) app(ChatbotRunner::class)->handle($chat->id);

        return $chat;
    }

    /** @return array<int, string> ang mga text na naipadala sa Botcake para sa PSID */
    protected function sent(string $psid = '7001'): array
    {
        return Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), '/flows/send_content') && ($r->data()['psid'] ?? null) === $psid)
            ->map(fn ($p) => $p[0]->data()['data']['content']['messages'][0]['text'] ?? '')->values()->all();
    }

    /** @return array<int, HttpRequest> ang mga request sa isang AI URL */
    protected function calls(string $url): array
    {
        return Http::recorded(fn (HttpRequest $r) => $r->url() === $url)->map(fn ($p) => $p[0])->values()->all();
    }

    protected function events(string $type, string $psid = '7001'): array
    {
        return DB::table('chatbot_events')->where('psid', (int) $psid)->where('type', $type)->orderBy('id')->get()
            ->map(fn ($e) => ChatbotEvents::text($e->type, $e->node_id, (array) json_decode($e->detail, true)))->all();
    }

    /** Ang dating body ng request sa OpenAI (ChatbotAi::payload), gaya ng pagpadala ng Laravel Http. */
    protected static function todayBody(string $model, array $messages, int $maxTokens): string
    {
        return json_encode(ChatbotAi::payload($model, $messages, $maxTokens));
    }
}

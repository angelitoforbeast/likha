<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeChat;
use App\Models\ChatbotFlow;
use App\Models\ChatbotSequence;
use App\Services\Chatbot\ChatbotPage;
use App\Services\Chatbot\ChatbotRunner;
use App\Services\Chatbot\FlowGraph;
use App\Services\ChatbotFields;
use App\Services\PageSettings;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Spec 163: ang ayos ng bot ng page (settings, flow, sequence) ay naka-cache, kaya ang chat sa page na naka-cache na ay
 * walang basa ng ayos sa database; at ang bawat paraan ng pag-save ay nakikita ng susunod na mensahe. Fake na Botcake.
 */
class ChatConfigCacheTest extends ChatblastTestCase
{
    private const SEND = 'https://botcake.io/api/public_api/v1/pages/1001/flows/send_content';
    private const SECRET = 'whsec-163-cache-cccccccccccccccc';
    /** Ang mga table ng ayos ng bot at ang tanong sa schema (hasTable / hasColumn). */
    private const CONFIG = '/page_settings|chatbot_flows|chatbot_flow_versions|chatbot_sequences"|chatbot_sequence_steps|app_settings|sqlite_master|pragma_table/';

    private ChatbotFlow $flow;
    private ChatbotSequence $seq;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_22_100000_widen_source_on_botcake_psids.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_draft_to_chatbot_flows.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_25_100000_create_chatbot_waits_table.php',
            'database/migrations/2026_10_26_100000_create_chatbot_sequence_tables.php',
            'database/migrations/2026_11_12_100000_add_source_to_page_settings.php',
        ]]);
        config(['services.chatbot.replies_enabled' => true]);
        $this->page('Page Uno', '1001');
        $s = app(PageSettings::class);
        $s->set('Page Uno', 'forbeast', 'webhook_secret', self::SECRET, true, null, true);
        $s->set('Page Uno', ChatbotFields::PROVIDER, 'price', '198');
        Http::fake([self::SEND => Http::response(['success' => true])]);
        $this->actingAs($this->user('CEO', 'ceo@example.test'));

        $step = $this->published('Step', ['nodes' => [['id' => 'n1', 'type' => 'start'], ['id' => 'n2', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'Follow up']]]]],
            'edges' => [['from' => 'n1', 'port' => 'next', 'to' => 'n2']]]);
        $this->seq = ChatbotSequence::create(['name' => 'Follow', 'active' => true, 'exit_on_reply' => false]);
        $this->seq->steps()->create(['position' => 1, 'delay_amount' => 1, 'delay_unit' => 'hours', 'flow_id' => $step->id, 'active' => true]);
        $this->flow = $this->published('Main', $this->says('Price {{price}}'));
        app(ChatbotPage::class)->link('Page Uno', $this->flow);
    }

    /** Start → Send "$text" → subscribe sa Follow. */
    private function says(string $text): array
    {
        return ['nodes' => [
            ['id' => 'n1', 'type' => 'start'],
            ['id' => 'n2', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => $text]]]],
            ['id' => 'n3', 'type' => 'sequence', 'config' => ['action' => 'subscribe', 'sequence_id' => $this->seq->id]],
        ], 'edges' => [['from' => 'n1', 'port' => 'next', 'to' => 'n2'], ['from' => 'n2', 'port' => 'continue', 'to' => 'n3']]];
    }

    private function published(string $name, array $graph): ChatbotFlow
    {
        $flow = ChatbotFlow::create(['name' => $name, 'graph' => FlowGraph::clean($graph)]);
        DB::table('chatbot_flows')->where('id', $flow->id)->update(['published_at' => now()]);

        return $flow->fresh();
    }

    /** Isang chat ng customer na pinatakbo; ang text na naipadala (null = walang padala). */
    private function chat(string $psid): ?string
    {
        $chat = BotcakeChat::create(['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => $psid, 'name' => 'Ana Cruz', 'message' => 'Hi', 'payload' => [], 'received_at' => now()]);
        $sends = fn () => Http::recorded(fn (HttpRequest $r) => $r->url() === self::SEND);
        $before = $sends()->count();
        app(ChatbotRunner::class)->handle($chat->id);
        $this->travel(5)->seconds();

        return $sends()->count() === $before ? null : ($sends()->last()[0]->data()['data']['content']['messages'][0]['text'] ?? null);
    }

    private function subscribed(string $psid): bool
    {
        return DB::table('chatbot_sequence_subscriptions')->where('psid', (int) $psid)->where('sequence_id', $this->seq->id)->exists();
    }

    public function test_S_163_2_a_chat_on_a_cached_page_reads_no_bot_configuration_from_the_database(): void
    {
        $this->assertSame('Price 198', $this->chat('7001'));
        // Bagong takbo, gaya ng bagong job sa worker: ang mga service ay bago, ang cache ay mainit na.
        app()->forgetScopedInstances();
        $queries = [];
        DB::listen(function ($q) use (&$queries) { $queries[] = $q->sql; });

        $this->assertSame('Price 198', $this->chat('7002'));
        $this->assertTrue($this->subscribed('7002'));
        $config = array_values(array_filter($queries, fn ($sql) => preg_match(self::CONFIG, $sql)));
        $this->assertSame([], $config, 'basa ng ayos sa isang chat (lahat: ' . count($queries) . ')');
        $this->assertLessThanOrEqual(8, count($config));
    }

    /** [ang pag-save, ang inaasahan sa susunod na chat: [text, naka-subscribe]] */
    public static function writes(): array
    {
        return [
            'values API (Forbeast)' => [function (self $t) {
                $body = json_encode(['values' => ['price' => '599']]);
                $ts = time();
                $t->call('PUT', '/api/gateway/pages/1001/values', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_GATEWAY_TIMESTAMP' => (string) $ts,
                    'HTTP_X_FORBEAST_USER' => 'Ana', 'HTTP_X_GATEWAY_SIGNATURE' => 'sha256=' . hash_hmac('sha256', $ts . '.' . $body, self::SECRET)], $body)->assertOk();
            }, ['Price 599', true]],
            'page settings (screens, paste, imports)' => [fn (self $t) => app(PageSettings::class)->set('Page Uno', ChatbotFields::PROVIDER, 'price', '599'), ['Price 599', true]],
            'flow publish' => [function (self $t) {
                $v = ChatbotFlow::find($t->flow->id)->draft_version;
                $t->putJson("/chatbot/flows/{$t->flow->id}/graph", ['graph' => FlowGraph::clean($t->says('New {{price}}')), 'version' => $v])->assertOk();
                $t->postJson("/chatbot/flows/{$t->flow->id}/publish", ['version' => ChatbotFlow::find($t->flow->id)->draft_version])->assertOk();
            }, ['New 198', true]],
            'sequence edit (paused)' => [function (self $t) {
                $step = $t->seq->steps()->first();
                $t->putJson('/chatbot/sequences/' . $t->seq->id, ['name' => 'Follow', 'description' => null, 'active' => false, 'exit_on_reply' => false,
                    'steps' => [['delay_amount' => 1, 'delay_unit' => 'hours', 'flow_id' => $step->flow_id, 'active' => true]]])->assertOk();
            }, ['Price 198', false]],
        ];
    }

    /** @dataProvider writes */
    public function test_S_163_2b_a_save_on_any_path_is_used_by_the_next_message(\Closure $save, array $expected): void
    {
        $this->assertSame('Price 198', $this->chat('7001'));
        $this->assertTrue($this->subscribed('7001'));

        $save($this);

        $this->assertSame($expected, [$this->chat('7002'), $this->subscribed('7002')]);
    }
}

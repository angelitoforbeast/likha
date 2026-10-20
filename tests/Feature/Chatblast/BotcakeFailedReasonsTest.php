<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeChat;
use App\Models\ChatbotFlow;
use App\Models\ChatbotReply;
use App\Services\BotcakeClient;
use App\Services\Chatbot\ChatbotPage;
use App\Services\Chatbot\ChatbotRunner;
use App\Services\Chatbot\FlowGraph;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

/**
 * Ang totoong dahilan ng pumalyang padala (spec 098): ang text ng error ng Facebook at hindi ang trace ID, ang buong sagot
 * ng Botcake na naitatala (hanggang 1,000 letra, walang token), at ang chatbot:failed-reasons para sa mga nakaraan.
 */
class BotcakeFailedReasonsTest extends ChatblastTestCase
{
    private const SEND = 'https://botcake.io/api/public_api/v1/pages/1001/flows/send_content';

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_30_098000_add_raw_to_chatbot_replies.php',
        ]]);
        config(['services.chatbot.replies_enabled' => true]);
        $this->page('Page Uno', '1001');
    }

    public static function bodies(): array
    {
        return [
            'message is a string' => [['success' => false, 'message' => 'Not found customer'], 'Not found customer'],
            'Facebook error object' => [['success' => false, 'error' => ['message' => '(#551) This person isn\'t available right now.', 'fbtrace_id' => 'AKLL2dVGFxDHsoLLbpdhBlc']],
                '(#551) This person isn\'t available right now.'],
            'trace ID first inside message' => [['success' => false, 'message' => ['fbtrace_id' => 'AKLL2dVGFxDHsoLLbpdhBlc', 'message' => 'Message failed to send because another app is controlling this thread now.']],
                'Message failed to send because another app is controlling this thread now.'],
            'error inside message' => [['success' => false, 'message' => ['error' => ['code' => 10, 'error_user_msg' => 'Sent outside the allowed window.', 'fbtrace_id' => 'Axyz']]],
                'Sent outside the allowed window.'],
            'error_description' => [['success' => false, 'message' => ['request_id' => 'r-1', 'error_description' => 'Page token expired']], 'Page token expired'],
            'a trace ID only' => [['success' => false, 'message' => ['fbtrace_id' => 'AKLL2dVGFxDHsoLLbpdhBlc']], '{"fbtrace_id":"AKLL2dVGFxDHsoLLbpdhBlc"}'],
            'other string after a trace ID' => [['success' => false, 'message' => ['x_fb_trace_id' => 'Aq1', 'code' => 'BLOCKED']], 'BLOCKED'],
            'no message' => [['success' => false], ''],
        ];
    }

    /** @dataProvider bodies */
    public function test_S_098_5_message_text_is_the_error_text_never_the_trace_id(array $body, string $expected): void
    {
        $this->assertSame($expected, BotcakeClient::messageText($body));
        $this->assertSame($expected, BotcakeClient::messageText(json_decode(json_encode($body))), 'the same as an object');
    }

    private function run7001(): void
    {
        $flow = ChatbotFlow::create(['name' => 'Main', 'graph' => FlowGraph::clean(['nodes' => [['id' => 'n1', 'type' => 'start'],
            ['id' => 'n2', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'Hi']]]]], 'edges' => [['from' => 'n1', 'port' => 'next', 'to' => 'n2']]])]);
        app(ChatbotPage::class)->link('Page Uno', $flow);
        $chat = BotcakeChat::create(['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => '7001', 'name' => 'Ana', 'message' => 'Hello', 'payload' => [], 'received_at' => now()]);
        app(ChatbotRunner::class)->handle($chat->id);
    }

    public function test_S_098_6_a_failed_send_keeps_the_raw_answer_capped_and_without_the_token(): void
    {
        $body = ['success' => false, 'message' => ['fbtrace_id' => 'AKLL2dVGFxDHsoLLbpdhBlc', 'message' => '(#551) This person isn\'t available right now.'],
            'echo' => self::TOKEN, 'pad' => str_repeat('x', 1200)];
        Http::fake([self::SEND => Http::response($body)]);

        $this->run7001();

        $r = ChatbotReply::where('psid', 7001)->sole();
        $this->assertSame('failed', $r->status);
        $this->assertSame('Botcake said "(#551) This person isn\'t available right now." (HTTP 200)', $r->error);
        $this->assertStringStartsWith('{"success":false,"message":{"fbtrace_id":"AKLL2dVGFxDHsoLLbpdhBlc"', $r->raw);
        $this->assertSame(1000, mb_strlen($r->raw));
        $this->assertStringNotContainsString(self::TOKEN, $r->raw);
        $this->actingAs($this->user())->getJson('/chatbot/replies/' . $r->id)->assertOk()->assertJsonPath('raw', $r->raw);
    }

    public function test_S_098_7_failed_reasons_groups_recent_failures_by_the_real_reason(): void
    {
        $row = fn (string $error, ?string $raw, ?int $http, string $at = '-1 hour') => ChatbotReply::create(['page_id' => 1001, 'psid' => 7001, 'node_id' => 'n2', 'kind' => 'send',
            'content' => [], 'status' => 'failed', 'error' => $error, 'raw' => $raw, 'http' => $http, 'sent_at' => now()->modify($at)]);
        $fb = json_encode(['success' => false, 'error' => ['message' => '(#551) This person isn\'t available right now.', 'fbtrace_id' => 'A1']]);
        $row('Botcake said "A1" (HTTP 200)', $fb, 200);
        $row('Botcake said "A2" (HTTP 200)', $fb, 200);
        $row('Botcake said "AKLL2dVGFxDHsoLLbpdhBlc" (HTTP 200)', null, 200);
        $row('The page has no Botcake token.', null, null);
        $row('Botcake said "old" (HTTP 200)', $fb, 200, '-10 days');
        ChatbotReply::create(['page_id' => 1001, 'psid' => 7002, 'node_id' => 'n2', 'kind' => 'send', 'content' => [], 'status' => 'sent', 'sent_at' => now()]);

        $this->artisan('chatbot:failed-reasons', ['--since' => '-2 days'])
            ->expectsOutputToContain('3 reasons, 4 failed sends')
            ->expectsTable(['Count', 'Reason', 'Last', 'Shown as'], [
                ['2', '(#551) This person isn\'t available right now.', now()->modify('-1 hour')->timezone('Asia/Manila')->format('Y-m-d H:i'), 'Botcake said "A2" (HTTP 200)'],
                ['1', 'unknown (before 098)', now()->modify('-1 hour')->timezone('Asia/Manila')->format('Y-m-d H:i'), 'Botcake said "AKLL2dVGFxDHsoLLbpdhBlc" (HTTP 200)'],
                ['1', 'The page has no Botcake token.', now()->modify('-1 hour')->timezone('Asia/Manila')->format('Y-m-d H:i'), 'The page has no Botcake token.'],
            ])
            ->assertSuccessful();
    }
}

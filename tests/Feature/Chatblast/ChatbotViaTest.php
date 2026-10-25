<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeChat;
use App\Models\ChatbotReply;
use App\Services\Chatbot\BotcakeOutbox;
use App\Services\Chatbot\BotcakeSendQueue;
use App\Services\Chatbot\CustomerFields;
use App\Services\Chatbot\FlowRun;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Ang "via" ng bawat padala (chatbot_replies.via: botcake o forbeast): itinatala ng bawat daan ng padala, ipinapakita sa
 * Inbox at sa Replies (column at filter), at pinupunan sa mga lumang row hangga't tiyak. Fake na HTTP.
 */
class ChatbotViaTest extends ChatblastTestCase
{
    private const GW = 'https://gw.example.test';
    private const BOTCAKE_SEND = 'https://botcake.io/api/public_api/v1/pages/1001/flows/send_content';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_29_100000_create_botcake_send_requests_table.php',
            'database/migrations/2026_10_30_098000_add_raw_to_chatbot_replies.php',
            'database/migrations/2026_10_30_100000_add_results_to_chatbot_replies.php',
            'database/migrations/2026_11_09_100000_add_via_to_chatbot_replies.php',
        ]]);
        config(['services.chatbot.replies_enabled' => true, 'services.gateway.url' => self::GW, 'services.gateway.key' => 'gw_key']);
        $this->page('Page Uno', '1001');
        Http::fake([self::GW . '/*' => Http::response(['status' => 'queued'], 202), self::BOTCAKE_SEND => Http::response(['success' => true])]);
    }

    private function flowRun(?int $chatId = null): FlowRun
    {
        return new FlowRun(1, [], 'Page Uno', '1001', [], new CustomerFields(1001, 7001), $chatId, 'hi', 'Ana', '2026-10-25 09:00');
    }

    private function chat(string $source, string $message = 'hi'): BotcakeChat
    {
        return BotcakeChat::create(['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => '7001', 'name' => 'Ana Cruz', 'message' => $message,
            'payload' => ['source' => $source], 'received_at' => now()]);
    }

    public function test_S_149_24_every_send_path_stores_which_bot_carried_it(): void
    {
        $outbox = app(BotcakeOutbox::class);
        $outbox->send($this->flowRun(), 'n2', [['type' => 'text', 'text' => 'Botcake po']]);
        app(PageSettings::class)->set('Page Uno', 'chatbot', 'provider', 'gateway');
        $outbox->send($this->flowRun(), 'n3', [['type' => 'text', 'text' => 'Forbeast po']]);
        $outbox->appendRow($this->flowRun(), 'n4', 'sheet-1', 'Orders', ['a']);

        // Ang async na Botcake (botcake:send-pool): ang ticket na natapos.
        DB::table(BotcakeSendQueue::TABLE)->insert(['request_id' => 'req-1', 'kind' => 'chatbot', 'customer' => '1001:7001', 'page_id' => 1001, 'page_name' => 'Page Uno',
            'psid' => '7001', 'flow_id' => 1, 'node_id' => 'n5', 'payload' => json_encode(['messages' => [['type' => 'text', 'text' => 'Pool po']]]), 'status' => 'pending',
            'deadline_at' => now()->addMinute(), 'created_at' => now(), 'updated_at' => now()]);
        BotcakeSendQueue::finishChatbot(DB::table(BotcakeSendQueue::TABLE)->first(), null, ['status' => 'sent', 'error' => null, 'http' => 200]);

        // Ang manual na sagot sa Inbox (ang page ay nasa Forbeast pa).
        $this->chat('gateway');
        $this->actingAs($this->user());
        $this->postJson('/chatbot/inbox/send', ['page_id' => '1001', 'psid' => '7001', 'text' => 'Manual po'])->assertOk();

        $this->assertSame(['n2' => 'botcake', 'n3' => 'forbeast', 'n4' => null, 'n5' => 'botcake', '' => 'forbeast'],
            ChatbotReply::orderBy('id')->get()->mapWithKeys(fn ($r) => [(string) $r->node_id => $r->via])->all());
    }

    public function test_S_149_25_the_inbox_thread_and_the_replies_page_show_via(): void
    {
        $this->chat('gateway', 'From Forbeast');
        $this->chat('default_reply', 'From Botcake');
        BotcakeOutbox::record(1001, 7001, null, 1, 'n2', 'send', [['type' => 'text', 'text' => 'Bot reply']], 'sent', via: 'forbeast');
        $user = $this->user();
        ChatbotReply::create(['page_id' => 1001, 'psid' => 7001, 'user_id' => $user->id, 'kind' => 'manual', 'content' => [['type' => 'text', 'text' => 'Staff']],
            'status' => 'sent', 'sent_at' => now(), 'via' => 'botcake']);
        $this->actingAs($user);

        $items = collect($this->getJson('/chatbot/inbox/thread?page_id=1001&psid=7001')->assertOk()->json('items'));
        $this->assertSame(['forbeast', 'botcake'], $items->where('side', 'in')->pluck('via')->values()->all());
        $this->assertSame([['Bot', 'forbeast'], [$user->name, 'botcake']], $items->where('side', 'out')->map(fn ($x) => [$x['by'], $x['via']])->values()->all());
        $html = $this->get('/chatbot/inbox')->assertOk()->getContent();
        $this->assertStringContainsString("const VIA = {botcake: 'Botcake', forbeast: 'Forbeast'}", $html);
        $this->assertStringContainsString("'via ' + VIA[x.via]", $html);
        $this->assertStringContainsString("text: x.by + (viaText(x) ? ' · ' + viaText(x) : '')", $html);

        $date = now('Asia/Manila')->toDateString();
        $rows = $this->getJson('/chatbot/replies/data?date=' . $date)->assertOk()->json('rows');
        $this->assertSame(['botcake', 'forbeast'], array_column($rows, 'via'));
        $this->assertSame(['forbeast'], array_column($this->getJson('/chatbot/replies/data?date=' . $date . '&via=forbeast')->json('rows'), 'via'));
        $replies = $this->get('/chatbot/replies')->getContent();
        $this->assertStringContainsString('<select name="via"', $replies);
        $this->assertStringContainsString('<th class="w-24">Via</th>', $replies);
    }

    public function test_S_149_26_old_rows_get_via_from_their_tags_or_the_provider_answer_and_stay_empty_when_unsure(): void
    {
        $row = fn (array $extra) => ChatbotReply::create(['page_id' => 1001, 'psid' => 7001, 'kind' => 'send', 'content' => [], 'status' => 'sent', 'sent_at' => now()] + $extra)->id;
        $ids = [
            'tags' => $row(['tags' => ['v' => 1, 'via' => 'forbeast']]),
            'forbeast answer' => $row(['status' => 'failed', 'raw' => '{"ok":false,"message_id":null,"recipient_id":"7001","error":"x"}']),
            'botcake answer' => $row(['status' => 'failed', 'raw' => '{"success":false,"message":"(#551) This person isn\'t available"}']),
            'unsure' => $row([]),
            'already set' => $row(['via' => 'botcake', 'tags' => ['v' => 1, 'via' => 'forbeast']]),
        ];

        $this->artisan('chatbot:replies-via-backfill')->assertSuccessful();

        $this->assertSame(['tags' => 'forbeast', 'forbeast answer' => 'forbeast', 'botcake answer' => 'botcake', 'unsure' => null, 'already set' => 'botcake'],
            array_map(fn ($id) => ChatbotReply::find($id)->via, $ids));
    }
}

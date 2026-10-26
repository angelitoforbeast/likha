<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeChat;
use App\Models\ChatbotReply;
use App\Services\Chatbot\CustomerFields;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

/**
 * Nakatago ang pag-pause ng bot sa Inbox (chatbot.inbox_bot_pause, default false): walang control sa page, at hindi
 * na pinapansin ng server ang pause mula sa lumang page. Ang unpause ay gumagana pa rin para walang customer na naiipit.
 */
class ChatbotInboxBotPauseTest extends ChatblastTestCase
{
    private const SEND = 'https://botcake.io/api/public_api/v1/pages/1001/flows/send_content';

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_22_100000_widen_source_on_botcake_psids.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_inbox_indexes.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
        ]]);
        config(['services.chatbot.replies_enabled' => true]);
        Http::fake([self::SEND => Http::response(['success' => true])]);
        $this->page('Page Uno', '1001');
        $this->travelTo('2026-10-19 12:00:00');
        BotcakeChat::create(['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => '7001', 'name' => 'Ana Cruz', 'message' => 'Hi', 'payload' => [], 'received_at' => '2026-10-19 11:00:00']);
        $this->actingAs($this->user());
    }

    private function paused(): ?string
    {
        return (new CustomerFields(1001, 7001))->get(CustomerFields::BOT_PAUSED);
    }

    public function test_S_160_1_the_inbox_page_has_no_pause_controls_unless_the_flag_is_on(): void
    {
        $markers = ["'ib-bot-switch'", 'Pause the bot for this customer?', 'data-tab="human"', "handler: 'Human / Bot'"];

        $html = $this->get('/chatbot/inbox')->assertOk()->getContent();
        foreach ($markers as $m) $this->assertStringNotContainsString($m, $html);

        config(['chatbot.inbox_bot_pause' => true]);
        $html = $this->get('/chatbot/inbox')->assertOk()->getContent();
        foreach ($markers as $m) $this->assertStringContainsString($m, $html);
    }

    public function test_S_160_2_a_pause_request_is_ignored_but_the_reply_is_sent_and_unpause_still_works(): void
    {
        $this->postJson('/chatbot/inbox/send', ['page_id' => '1001', 'psid' => '7001', 'text' => 'A human here', 'pause' => true])->assertOk();
        $this->assertSame(1, ChatbotReply::where('kind', 'manual')->count());
        $this->assertNull($this->paused());

        $this->postJson('/chatbot/inbox/pause', ['page_id' => '1001', 'psid' => '7001', 'paused' => true])->assertOk()->assertJson(['bot_paused' => false]);
        $this->assertNull($this->paused());
    }

    public function test_S_160_4_an_already_paused_customer_shows_paused_and_can_be_unpaused(): void
    {
        (new CustomerFields(1001, 7001))->set(CustomerFields::BOT_PAUSED, '1');
        $this->assertTrue($this->getJson('/chatbot/inbox/panel?page_id=1001&psid=7001')->json('bot_paused'));
        $this->assertStringContainsString("'Unpause'", $this->get('/chatbot/inbox')->getContent());

        $this->postJson('/chatbot/inbox/pause', ['page_id' => '1001', 'psid' => '7001', 'paused' => false])->assertOk()->assertJson(['bot_paused' => false]);
        $this->assertNull($this->paused());
    }
}

<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeChat;
use App\Services\Chatbot\ChatbotResults;
use App\Services\Chatbot\ChatbotTags;
use App\Services\Chatbot\ChatbotVars;
use App\Services\Chatbot\CustomerFields;
use App\Services\Chatbot\FlowRun;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/** Spec 112: {{replies_via}} = Botcake o Forbeast, at ang Results ay may filter at hati ayon dito. */
class ChatbotRepliesViaTest extends ChatblastTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_draft_to_chatbot_flows.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_26_100000_create_chatbot_sequence_tables.php',
            'database/migrations/2026_10_30_100000_add_results_to_chatbot_replies.php',
        ]]);
        $this->page('Page Uno', '1001');
    }

    private function chat(array $payload): int
    {
        return BotcakeChat::create(['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => '7001', 'name' => 'Ana', 'message' => 'hi',
            'message_id' => 'm' . random_int(1, 999999), 'payload' => $payload, 'received_at' => now()])->id;
    }

    private function flowRun(?int $chatId): FlowRun
    {
        return new FlowRun(1, [], 'Page Uno', '1001', [], new CustomerFields(1001, 7001), $chatId, 'hi', 'Ana', '2026-10-21 09:00');
    }

    public function test_S_112_1_a_gateway_chat_is_forbeast_and_any_other_chat_is_botcake(): void
    {
        $this->assertSame('Forbeast', $this->flowRun($this->chat(['source' => 'gateway']))->vars()['replies via']);
        $this->assertSame('Botcake', $this->flowRun($this->chat(['source' => 'default_reply']))->vars()['replies via']);
        $this->assertSame('Botcake', $this->flowRun($this->chat([]))->vars()['replies via']);
    }

    public function test_S_112_2_without_a_chat_it_follows_the_page_provider(): void
    {
        $this->assertSame('Botcake', $this->flowRun(null)->vars()['replies via']);
        app(PageSettings::class)->set('Page Uno', 'chatbot', 'provider', 'gateway');
        $this->assertSame('Forbeast', $this->flowRun(null)->vars()['replies via']);
        // Ang chat ang nananaig sa provider ng page.
        $this->assertSame('Botcake', $this->flowRun($this->chat(['source' => 'default_reply']))->vars()['replies via']);
    }

    public function test_S_112_3_it_fills_a_send_text_and_a_sheet_column(): void
    {
        $run = $this->flowRun($this->chat(['source' => 'gateway']));
        $this->assertSame('Via Forbeast', $run->text('Via {{replies_via}}'));
        $this->assertSame("'Forbeast", ChatbotVars::sheetCell('{{Replies Via}}', $run->vars()));
    }

    public function test_S_112_4_it_is_in_the_system_variable_list(): void
    {
        $this->assertContains('replies_via', \App\Http\Controllers\ChatbotFlowController::RUN_VARS);
        $this->assertSame(['replies via'], array_values(array_filter(['replies via'], fn ($n) => in_array($n, (new \ReflectionClassConstant(ChatbotTags::class, 'RUN_VARS'))->getValue(), true))));
    }

    public function test_S_112_5_the_tags_and_the_order_event_carry_it(): void
    {
        $run = $this->flowRun($this->chat(['source' => 'gateway']));
        $this->assertSame('forbeast', ChatbotTags::forSend($run, ['Hi'])['tags']['via']);
        $this->assertSame('botcake', ChatbotTags::manual(1001, 7001, 'Page Uno', 1)['tags']['via']);
    }

    public function test_S_112_6_results_filter_and_split_by_provider(): void
    {
        $this->travelTo('2026-10-21 09:00:00');
        $send = fn (int $psid, ?string $via, ?string $repliedAt) => DB::table('chatbot_replies')->insert(['page_id' => 1001, 'psid' => $psid, 'flow_id' => 1, 'node_id' => 'n1',
            'kind' => 'send', 'content' => '[]', 'status' => 'sent', 'sent_at' => '2026-10-20 10:00:00', 'replied_at' => $repliedAt, 'tags' => $via ? json_encode(['v' => 1, 'via' => $via]) : null]);
        $send(1, 'forbeast', '2026-10-20 10:05:00');
        $send(2, 'forbeast', null);
        $send(3, 'botcake', null);
        $send(4, null, null); // luma: walang via = Botcake
        foreach ([[1, 'gateway'], [2, 'gateway'], [3, 'default_reply']] as [$psid, $source]) {
            DB::table('botcake_chats')->insert(['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => (string) $psid, 'name' => 'X', 'message' => 'hi',
                'payload' => json_encode(['source' => $source]), 'received_at' => '2026-10-20 09:00:00']);
        }
        $order = fn (int $psid, array $detail) => DB::table('chatbot_events')->insert(['page_id' => 1001, 'psid' => $psid, 'flow_id' => 1, 'node_id' => 'n9', 'type' => 'order',
            'created_at' => '2026-10-20 11:00:00', 'detail'
 => json_encode($detail)]);
        $order(1, ['via' => 'forbeast']);
        $order(3, ['via' => 'botcake']);

        $range = ['page_id' => '1001', 'from' => '2026-10-19', 'to' => '2026-10-21'];
        $keys = array_flip(['sent', 'replied', 'orders', 'chats']);
        $all = app(ChatbotResults::class)->report(ChatbotResults::filters($range));
        $this->assertSame(4, $all['funnel']['sent']);
        $this->assertSame(2, $all['funnel']['orders']);
        $this->assertEquals(['sent' => 2, 'replied' => 1, 'orders' => 1, 'chats' => 2], array_intersect_key($all['by_via']['forbeast'], $keys));
        $this->assertEquals(['sent' => 2, 'replied' => 0, 'orders' => 1, 'chats' => 1], array_intersect_key($all['by_via']['botcake'], $keys));

        $f = app(ChatbotResults::class)->report(ChatbotResults::filters($range + ['via' => 'forbeast']));
        $this->assertSame('forbeast', $f['filters']['via']);
        $this->assertSame([2, 1, 2], [$f['funnel']['sent'], $f['funnel']['orders'], $f['funnel']['chats']]);
        $b = app(ChatbotResults::class)->report(ChatbotResults::filters($range + ['via' => 'botcake']));
        $this->assertSame([2, 1, 1], [$b['funnel']['sent'], $b['funnel']['orders'], $b['funnel']['chats']]);
        $this->assertNull(ChatbotResults::filters($range + ['via' => 'x'])['via']);
    }
}

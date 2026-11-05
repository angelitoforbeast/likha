<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeChat;
use App\Services\Chatbot\BotcakeOutbox;
use App\Services\Chatbot\CustomerFields;
use App\Services\Chatbot\FlowEngine;
use App\Services\Chatbot\FlowGraph;
use App\Services\Chatbot\FlowRun;
use App\Services\Chatbot\TestHistory;
use App\Services\Chatbot\TestOutbox;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

/** Ang nakikita ng AI sa mensaheng may button o quick reply, at sa tap ng customer. */
class ChatbotButtonsAiTest extends ChatblastTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_draft_to_chatbot_flows.php',
        ]]);
    }

    private const BUTTONS = [['title' => 'Yes', 'type' => 'postback', 'payload' => 'YES'], ['title' => 'No', 'type' => 'postback', 'payload' => 'NO']];

    public function test_S_229_9_1_a_message_shown_to_the_ai_carries_the_titles(): void
    {
        $this->assertSame("Order na po? [Yes | No]\nSize? [S | M]", TestHistory::text([
            ['type' => 'text', 'text' => 'Order na po?', 'buttons' => self::BUTTONS, 'ai' => true],
            ['type' => 'text', 'text' => 'Size?', 'quick_replies' => [['title' => 'S', 'payload' => 'S'], ['title' => 'M', 'payload' => 'M']], 'ai' => true],
        ]));
        // Hindi nakita ng customer ang mga button (Botcake, o address na wala sa listahan): ang text lang.
        $this->assertSame('Order na po?', TestHistory::text([['type' => 'text', 'text' => 'Order na po?', 'buttons' => self::BUTTONS, 'ai' => true, 'left_out' => true]]));
    }

    public function test_S_229_9_2_a_message_not_shown_to_the_ai_stays_hidden(): void
    {
        $this->assertSame('', TestHistory::text([['type' => 'text', 'text' => 'Order na po?', 'buttons' => self::BUTTONS, 'ai' => false]]));
        // Ang sariling sagot ng AI: iyon lang, walang title.
        $this->assertSame('Opo, 500 po.', TestHistory::text([['type' => 'text', 'text' => "Opo, 500 po.\n\nOrder na?", 'ai_text' => 'Opo, 500 po.', 'buttons' => self::BUTTONS]]));
    }

    public function test_S_229_9_3_ai_and_ai_text_are_stripped_before_the_send(): void
    {
        config(['services.gateway.buttons' => 'forbeast']);
        $wire = BotcakeOutbox::wire([['type' => 'text', 'text' => 'Order na po?', 'buttons' => self::BUTTONS, 'ai' => true],
            ['type' => 'text', 'text' => 'Size?', 'ai_text' => 'x', 'quick_replies' => [['title' => 'S', 'payload' => 'S']]]], 'forbeast');

        $this->assertSame([['type' => 'buttons', 'text' => 'Order na po?', 'buttons' => self::BUTTONS], ['type' => 'text', 'text' => 'Size?', 'quick_replies' => [['title' => 'S', 'payload' => 'S']]]], $wire);
    }

    public function test_S_229_9_4_after_a_tap_the_ai_sees_the_title_as_the_customers_turn(): void
    {
        config(['services.openai.key' => 'sk-test-not-real']);
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]])]);
        // Ang naunang tap (gaya ng iniimbak ng HandleGatewayEvent: ang title ang text, ang payload sa payload.postback).
        BotcakeChat::create(['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => '7001', 'name' => 'Ana', 'message' => 'No', 'message_id' => 'm1',
            'payload' => ['source' => 'gateway', 'postback' => 'NO_THANKS_PROMO'], 'received_at' => now()->subMinute()]);
        $tap = BotcakeChat::create(['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => '7001', 'name' => 'Ana', 'message' => 'Yes', 'message_id' => 'm2',
            'payload' => ['source' => 'gateway', 'postback' => 'ORDER_NOW_PROMO'], 'received_at' => now()]);
        $graph = FlowGraph::clean(['nodes' => [['id' => 'n1', 'type' => 'start'], ['id' => 'n2', 'type' => 'ai', 'config' => ['save_to' => 'GPT INPUT', 'history' => 5]]],
            'edges' => [['from' => 'n1', 'port' => 'next', 'to' => 'n2']]]);
        $run = new FlowRun(1, $graph, 'Page Uno', '1001', [], new CustomerFields(1001, 7001), $tap->id, 'Yes', 'Ana', '2026-11-05 10:00');
        app(FlowEngine::class)->run($run, new TestOutbox());

        Http::assertSent(fn (HttpRequest $r) => array_slice($r['messages'], -2) === [['role' => 'user', 'content' => 'No'], ['role' => 'user', 'content' => 'Yes']]
            && !str_contains(json_encode($r['messages']), 'PROMO'));
    }
}

<?php

namespace Tests\Feature\Chatblast;

use App\Models\ChatbotFlow;
use App\Services\Chatbot\ChatbotPageCheck;
use App\Services\Chatbot\ChatbotVars;
use App\Services\Chatbot\FlowGraph;
use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;

/**
 * Ang mga button at quick reply ng Send message sa naka-save na graph: ang allow-list ng cleanConfig, ang mga hangganan
 * ng Meta na tinatanggihan sa save, at ang mga kulang na hinaharang ng Publish lang (ang draft ay laging nase-save).
 */
class ChatbotButtonsGraphTest extends ChatblastTestCase
{
    private ?\App\Models\User $me = null;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_draft_to_chatbot_flows.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_26_100000_create_chatbot_sequence_tables.php',
        ]]);
    }

    /** Isang flow: Start → Send (n2) na may ibinigay na mga item, at ang ibang config ng Send. */
    private function graph(array $items, array $config = []): array
    {
        return ['nodes' => [['id' => 'n1', 'type' => 'start'], ['id' => 'n2', 'type' => 'send', 'config' => ['items' => $items] + $config]],
            'edges' => [['from' => 'n1', 'port' => 'next', 'to' => 'n2']]];
    }

    private function items(array $graph): array
    {
        return $graph['nodes'][1]['config']['items'];
    }

    private function refused(array $items, string $needle): void
    {
        try {
            FlowGraph::clean($this->graph($items));
            $this->fail('expected a refusal: ' . $needle);
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
            $this->assertStringContainsString('n2', $e->getMessage(), 'the message names the step');
        }
    }

    private function postback(string $title, ?string $payload = null): array
    {
        return ['title' => $title, 'type' => 'postback', 'payload' => $payload ?? FlowGraph::payloadFor($title)];
    }

    /** I-save sa save API at basahin ulit (gaya ng editor). */
    private function saveAndReload(array $graph): array
    {
        $flow = ChatbotFlow::create(['name' => 'Main', 'graph' => FlowGraph::blank()]);
        $this->actingAs($this->me ??= $this->user());
        $this->putJson("/chatbot/flows/{$flow->id}/graph", ['graph' => $graph, 'version' => ChatbotFlow::find($flow->id)->draft_version])->assertOk();

        return $this->getJson("/chatbot/flows/{$flow->id}/graph")->assertOk()->json('graph');
    }

    public function test_S_229_1_1_three_buttons_come_back_with_title_type_and_payload_or_url_after_save_and_reload(): void
    {
        $buttons = [$this->postback('Yes'), $this->postback('No'), ['title' => 'Website', 'type' => 'web_url', 'url' => 'https://likha.example.test/shop']];
        $back = $this->items($this->saveAndReload($this->graph([['type' => 'text', 'value' => 'Order na po?', 'buttons' => $buttons]])));

        $this->assertSame([['title' => 'Yes', 'type' => 'postback', 'payload' => 'YES'], ['title' => 'No', 'type' => 'postback', 'payload' => 'NO'],
            ['title' => 'Website', 'type' => 'web_url', 'url' => 'https://likha.example.test/shop']], $back[0]['buttons']);
    }

    public function test_S_229_1_2_two_quick_replies_remain_in_order_after_edit_remove_save_and_reload(): void
    {
        // Ang editor: 3 quick reply, binago ang ika-2, tinanggal ang ika-1 → ito ang ipinapadala sa save API.
        $quick = [['title' => 'Medium po', 'payload' => 'MEDIUM_PO'], ['title' => 'Large', 'payload' => 'LARGE']];
        $back = $this->items($this->saveAndReload($this->graph([['type' => 'text', 'value' => 'Size?', 'quick_replies' => $quick]])));

        $this->assertSame($quick, $back[0]['quick_replies']);
    }

    public function test_S_229_1_3_a_title_keeps_its_variable_and_the_variable_checker_sees_it_like_text(): void
    {
        $clean = FlowGraph::clean($this->graph([['type' => 'text', 'value' => 'Hi', 'buttons' => [$this->postback('Order {{first_name}}', 'ORDER')]]]));

        $this->assertSame('Order {{first_name}}', $this->items($clean)[0]['buttons'][0]['title']);
        $this->assertContains(ChatbotVars::norm('first_name'), ChatbotPageCheck::names($clean));
    }

    public function test_S_229_1_6_unknown_button_types_are_dropped_and_extra_keys_are_not_kept(): void
    {
        $items = $this->items(FlowGraph::clean($this->graph([
            ['type' => 'text', 'value' => 'Hi', 'buttons' => [
                ['title' => 'Call', 'type' => 'phone_number', 'payload' => '+639170000000'],
                ['title' => 'Yes', 'type' => 'postback', 'payload' => 'YES', 'url' => 'https://x.test', 'webview_height_ratio' => 'full', 'messenger_extensions' => true],
                ['title' => 'Site', 'type' => 'web_url', 'url' => 'https://x.test', 'payload' => 'SITE', 'fallback_url' => 'https://evil.test'],
                'not an array',
            ], 'extra' => 'dropped'],
            ['type' => 'text', 'value' => 'Pick', 'quick_replies' => [['title' => 'A', 'payload' => 'A', 'content_type' => 'user_email', 'image_url' => 'https://x.test/i.png']]],
        ])));

        $this->assertSame([['title' => 'Yes', 'type' => 'postback', 'payload' => 'YES'], ['title' => 'Site', 'type' => 'web_url', 'url' => 'https://x.test']], $items[0]['buttons']);
        $this->assertSame([['title' => 'A', 'payload' => 'A']], $items[1]['quick_replies']);
        $this->assertSame(['type', 'value', 'buttons'], array_keys($items[0]));
    }

    public function test_S_229_1_6_b_a_title_with_invalid_utf8_posted_as_a_form_is_saved_cleaned_and_the_draft_survives(): void
    {
        $flow = ChatbotFlow::create(['name' => 'Main', 'graph' => FlowGraph::blank()]);
        $this->actingAs($this->me ??= $this->user());
        $graph = $this->graph([['type' => 'text', 'value' => 'Hi', 'buttons' => [['title' => "Oo\xFF", 'type' => 'postback', 'payload' => "OK\xFE"]],
            ]]);
        $this->put("/chatbot/flows/{$flow->id}/graph", ['graph' => $graph, 'version' => ChatbotFlow::find($flow->id)->draft_version], ['Accept' => 'application/json'])->assertOk();

        $b = ChatbotFlow::find($flow->id)->draft()['nodes'][1]['config']['items'][0]['buttons'][0];
        $this->assertSame(['Oo?', 'OK?'], [$b['title'], $b['payload']]);
    }

    public function test_S_229_1_7_filipino_and_emoji_titles_are_unchanged_after_save_and_reload(): void
    {
        $buttons = [$this->postback('Oo po 🙏'), $this->postback('Hindi, salamat'), $this->postback('Señor 👍🏽')];
        $back = $this->items($this->saveAndReload($this->graph([['type' => 'text', 'value' => 'Kukunin niyo po ba?', 'buttons' => $buttons]])));

        $this->assertSame(['Oo po 🙏', 'Hindi, salamat', 'Señor 👍🏽'], array_column($back[0]['buttons'], 'title'));
    }

    public function test_S_229_1_9_a_flow_saved_before_this_change_saves_unchanged_with_no_new_keys(): void
    {
        $old = $this->graph([['type' => 'text', 'value' => 'Hello {{first_name}}', 'ai' => true], ['type' => 'image', 'value' => 'https://img.example.test/a.jpg']],
            ['wait' => true, 'save_to' => 'answer', 'skip_after' => ['amount' => 2, 'unit' => 'hours']]);
        $once = FlowGraph::clean($old);

        $this->assertSame([['type' => 'text', 'value' => 'Hello {{first_name}}', 'ai' => true], ['type' => 'image', 'value' => 'https://img.example.test/a.jpg']], $this->items($once));
        $this->assertSame(['items', 'wait', 'save_to', 'skip_after'], array_keys($once['nodes'][1]['config']));
        $this->assertSame($once, FlowGraph::clean($once), 'saving it again changes nothing');
        // Ang walang laman na listahan ay hindi rin nagdadagdag ng key.
        $this->assertSame(['type', 'value'], array_keys($this->items(FlowGraph::clean($this->graph([['type' => 'text', 'value' => 'a', 'buttons' => [], 'quick_replies' => []]])))[0]));
    }

    public function test_S_229_2_1_three_buttons_are_accepted(): void
    {
        $items = $this->items(FlowGraph::clean($this->graph([['type' => 'text', 'value' => 'Pick', 'buttons' => [$this->postback('A'), $this->postback('B'), $this->postback('C')]]])));

        $this->assertCount(3, $items[0]['buttons']);
    }

    public function test_S_229_2_2_a_posted_graph_with_four_buttons_is_refused(): void
    {
        $this->refused([['type' => 'text', 'value' => 'Pick', 'buttons' => [$this->postback('A'), $this->postback('B'), $this->postback('C'), $this->postback('D')]]], 'at most 3 buttons');
    }

    public function test_S_229_2_3_a_posted_graph_with_fourteen_quick_replies_is_refused_and_thirteen_is_accepted(): void
    {
        $quick = array_map(fn ($n) => ['title' => 'Q' . $n, 'payload' => 'Q' . $n], range(1, 14));
        $this->refused([['type' => 'text', 'value' => 'Pick', 'quick_replies' => $quick]], 'at most 13 quick replies');
        $this->assertCount(13, $this->items(FlowGraph::clean($this->graph([['type' => 'text', 'value' => 'Pick', 'quick_replies' => array_slice($quick, 0, 13)]])))[0]['quick_replies']);
    }

    public function test_S_229_2_4_a_21_character_title_is_refused_and_exactly_20_is_accepted(): void
    {
        $twenty = 'Oo po, kukunin ko 🙏!'; // 20 letra (ang emoji ay isa)
        $this->assertSame(20, mb_strlen($twenty));
        $this->assertSame($twenty, $this->items(FlowGraph::clean($this->graph([['type' => 'text', 'value' => 'Hi', 'buttons' => [$this->postback($twenty, 'OK')]]])))[0]['buttons'][0]['title']);
        $this->refused([['type' => 'text', 'value' => 'Hi', 'buttons' => [$this->postback($twenty . 'x', 'OK')]]], '20 characters');
        $this->refused([['type' => 'text', 'value' => 'Hi', 'quick_replies' => [['title' => str_repeat('a', 21), 'payload' => 'A']]]], '20 characters');
    }

    public function test_S_229_2_6_button_text_over_640_characters_is_refused_and_without_buttons_it_is_not(): void
    {
        $this->refused([['type' => 'text', 'value' => str_repeat('a', 641), 'buttons' => [$this->postback('Yes')]]], '640');
        $this->assertCount(1, $this->items(FlowGraph::clean($this->graph([['type' => 'text', 'value' => str_repeat('a', 640), 'buttons' => [$this->postback('Yes')]]]))));
        $this->assertSame(641, mb_strlen($this->items(FlowGraph::clean($this->graph([['type' => 'text', 'value' => str_repeat('a', 641), 'quick_replies' => [['title' => 'Yes', 'payload' => 'YES']]]])))[0]['value']));
    }

    public function test_S_229_3_2_a_posted_graph_with_buttons_and_quick_replies_on_one_item_is_refused(): void
    {
        $this->refused([['type' => 'text', 'value' => 'Hi', 'buttons' => [$this->postback('Yes')], 'quick_replies' => [['title' => 'No', 'payload' => 'NO']]]], 'both');
        // Ang Image ay walang button (quick reply lang).
        $this->refused([['type' => 'image', 'value' => 'https://img.example.test/a.jpg', 'buttons' => [$this->postback('Yes')]]], 'Image');
        $this->assertSame([['title' => 'Yes', 'payload' => 'YES']],
            $this->items(FlowGraph::clean($this->graph([['type' => 'image', 'value' => 'https://img.example.test/a.jpg', 'quick_replies' => [['title' => 'Yes', 'payload' => 'YES']]]])))[0]['quick_replies']);
    }

    // ---- Publish ----

    /** I-save ang draft (laging pumapasa) at i-Publish: ang sagot ng Publish. */
    private function publish(array $graph, ?ChatbotFlow $flow = null): \Illuminate\Testing\TestResponse
    {
        $flow ??= ChatbotFlow::create(['name' => 'Main ' . random_int(1, 99999), 'graph' => FlowGraph::blank()]);
        $this->actingAs($this->me ??= $this->user());
        $this->putJson("/chatbot/flows/{$flow->id}/graph", ['graph' => $graph, 'version' => ChatbotFlow::find($flow->id)->draft_version])->assertOk();

        return $this->postJson("/chatbot/flows/{$flow->id}/publish", ['version' => ChatbotFlow::find($flow->id)->draft_version]);
    }

    private function blocked(array $items, string $needle, array $config = ['wait' => true]): void
    {
        $error = $this->publish($this->graph($items, $config))->assertStatus(422)->json('error');
        $this->assertStringContainsString('cannot be published', $error);
        $this->assertStringContainsString('Send message step n2', $error);
        $this->assertStringContainsString($needle, $error);
    }

    /** Ang page sa gateway (forbeast) na ang flow ay $flowId; $route: ang address (gateway_app). */
    private function gatewayPage(string $page, int $flowId, ?string $route = null): void
    {
        $s = app(\App\Services\PageSettings::class);
        $s->set($page, 'chatbot', 'provider', 'gateway', false, null, true);
        if ($route !== null) $s->set($page, 'chatbot', 'gateway_app', $route, false, null, true);
        $s->set($page, 'chatbot', 'flow_id', (string) $flowId, false, null, true);
    }

    private const GOOD = [['type' => 'text', 'value' => 'Order na po?', 'buttons' => [['title' => 'Yes', 'type' => 'postback', 'payload' => 'YES'], ['title' => 'Shop', 'type' => 'web_url', 'url' => 'https://shop.example.test/']]]];

    public function test_S_229_1_4_an_empty_title_blocks_publish_with_a_clear_message(): void
    {
        $this->blocked([['type' => 'text', 'value' => 'Hi', 'buttons' => [['title' => '', 'type' => 'postback', 'payload' => 'YES']]]], 'no title');
    }

    public function test_S_229_1_5_a_link_that_is_not_https_blocks_publish(): void
    {
        foreach (['http://shop.example.test/', 'shop.example.test', 'javascript:alert(1)', 'https://{{shop_link}}'] as $url) {
            $this->blocked([['type' => 'text', 'value' => 'Hi', 'buttons' => [['title' => 'Shop', 'type' => 'web_url', 'url' => $url]]]], 'https://');
        }
    }

    public function test_S_229_3_3_quick_replies_on_a_message_that_is_not_the_last_block_publish(): void
    {
        $this->blocked([['type' => 'text', 'value' => 'Size?', 'quick_replies' => [['title' => 'M', 'payload' => 'M']]], ['type' => 'text', 'value' => 'Salamat']], 'not the last');
    }

    public function test_S_229_4_5_a_tap_step_that_does_not_wait_publishes_with_a_warning(): void
    {
        $notice = $this->publish($this->graph(self::GOOD, ['wait' => false]))->assertOk()->json('notice');
        $this->assertStringContainsString('Send message step n2', $notice);
        $this->assertStringContainsString('does not wait for the reply', $notice);
        // Ang web_url lang ay walang tap: walang babala.
        $this->assertNull($this->publish($this->graph([['type' => 'text', 'value' => 'Shop', 'buttons' => [self::GOOD[0]['buttons'][1]]]], ['wait' => false]))->assertOk()->json('notice'));
    }

    public function test_S_229_6_1_a_valid_button_message_on_a_gateway_only_flow_publishes_with_no_notice(): void
    {
        config(['services.gateway.buttons' => 'forbeast, incepxion']);
        $flow = ChatbotFlow::create(['name' => 'Gateway only', 'graph' => FlowGraph::blank()]);
        $this->gatewayPage('Page Uno', $flow->id);
        $this->gatewayPage('Page Dos', $flow->id, 'incepxion');
        config(['services.gateway.urls' => 'incepxion=https://incepxion.example.test/api']);

        $res = $this->publish($this->graph(self::GOOD, ['wait' => true]), $flow)->assertOk();
        $this->assertNull($res->json('notice'));
    }

    public function test_S_229_6_2_buttons_with_empty_text_block_publish_naming_the_step(): void
    {
        $this->blocked([['type' => 'text', 'value' => '  ', 'buttons' => [['title' => 'Yes', 'type' => 'postback', 'payload' => 'YES']]]], 'no text');
        // Ang walang laman ay hindi ipinapadala, kaya ang quick reply nito ay hindi rin makikita.
        $this->blocked([['type' => 'text', 'value' => '', 'quick_replies' => [['title' => 'Yes', 'payload' => 'YES']]]], 'no text');
        $this->blocked([['type' => 'image', 'value' => 'not a link', 'quick_replies' => [['title' => 'Yes', 'payload' => 'YES']]]], 'no image link');
    }

    public function test_S_229_6_3_a_postback_or_quick_reply_with_an_empty_payload_blocks_publish(): void
    {
        $this->blocked([['type' => 'text', 'value' => 'Hi', 'buttons' => [['title' => 'Yes', 'type' => 'postback', 'payload' => '']]]], 'no payload');
        $this->blocked([['type' => 'text', 'value' => 'Hi', 'quick_replies' => [['title' => 'Yes', 'payload' => ' ']]]], 'no payload');
    }

    public function test_S_229_6_4_duplicate_payloads_or_titles_in_one_message_block_publish(): void
    {
        $this->blocked([['type' => 'text', 'value' => 'Hi', 'buttons' => [['title' => 'Yes', 'type' => 'postback', 'payload' => 'OK'], ['title' => 'Oo', 'type' => 'postback', 'payload' => 'OK']]]], 'same');
        $this->blocked([['type' => 'text', 'value' => 'Hi', 'quick_replies' => [['title' => 'Yes', 'payload' => 'A'], ['title' => 'Yes', 'payload' => 'B']]]], 'same');
    }

    public function test_S_229_6_5_a_draft_with_a_problem_saves_and_only_publish_blocks(): void
    {
        $flow = ChatbotFlow::create(['name' => 'Draft', 'graph' => FlowGraph::blank()]);
        $broken = $this->graph([['type' => 'text', 'value' => '', 'buttons' => [['title' => '', 'type' => 'web_url', 'url' => 'htt']]], ['type' => 'text', 'value' => 'x']]);
        $this->publish($broken, $flow)->assertStatus(422);

        $this->assertSame('htt', ChatbotFlow::find($flow->id)->draft()['nodes'][1]['config']['items'][0]['buttons'][0]['url'], 'the draft kept the half-made button');
        $this->assertNull(ChatbotFlow::find($flow->id)->published_at);
    }

    public function test_S_229_6_6_publish_lists_the_pages_that_get_the_text_only_as_a_notice(): void
    {
        config(['services.gateway.buttons' => 'forbeast', 'services.gateway.urls' => 'incepxion=https://incepxion.example.test/api']);
        $flow = ChatbotFlow::create(['name' => 'Mixed', 'graph' => FlowGraph::blank()]);
        $other = ChatbotFlow::create(['name' => 'Other', 'graph' => FlowGraph::blank()]);
        $s = app(\App\Services\PageSettings::class);
        $s->set('Page Botcake', 'chatbot', 'flow_id', (string) $flow->id, false, null, true);              // Botcake, main flow
        $this->gatewayPage('Page Gateway', $flow->id);                                // forbeast, on the list: gets buttons
        $this->gatewayPage('Page Not Ready', $flow->id, 'incepxion');                 // a route not on the list
        $s->set('Page Group', 'chatbot', 'flow_id', (string) $other->id, false, null, true);              // Botcake, through an ad group
        $s->set('Page Group', 'adgroup', 'g1.flow_id', (string) $flow->id, false, null, true);
        $s->set('Page Elsewhere', 'chatbot', 'flow_id', (string) $other->id, false, null, true);          // Botcake, does not use the flow
        // Botcake, through a sequence step: its main flow subscribes to a sequence whose step runs the flow.
        $seq = \Illuminate\Support\Facades\DB::table('chatbot_sequences')->insertGetId(['name' => 'Follow up', 'created_at' => now(), 'updated_at' => now()]);
        \Illuminate\Support\Facades\DB::table('chatbot_sequence_steps')->insert(['sequence_id' => $seq, 'position' => 1, 'flow_id' => $flow->id, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $main = ChatbotFlow::create(['name' => 'Subscribes', 'graph' => ['nodes' => [['id' => 'n1', 'type' => 'start'], ['id' => 'n2', 'type' => 'sequence', 'config' => ['action' => 'subscribe', 'sequence_id' => $seq]]],
            'edges' => [['from' => 'n1', 'port' => 'next', 'to' => 'n2']]]]);
        $s->set('Page Sequence', 'chatbot', 'flow_id', (string) $main->id, false, null, true);

        $notice = $this->publish($this->graph(self::GOOD, ['wait' => true]), $flow)->assertOk()->json('notice');
        $this->assertStringContainsString('Botcake pages get the text only', $notice);
        $this->assertStringContainsString('Page Botcake, Page Group, Page Not Ready, Page Sequence', $notice);
        $this->assertStringNotContainsString('Page Gateway', $notice);
        $this->assertStringNotContainsString('Page Elsewhere', $notice);
        // Walang button sa flow: walang listahan.
        $this->assertNull($this->publish($this->graph([['type' => 'text', 'value' => 'Hi']]), $flow)->json('notice'));
    }

    public function test_the_default_payload_is_the_title_upper_cased_with_non_alphanumerics_as_underscores(): void
    {
        $this->assertSame([['Yes', 'YES'], ['Oo po!', 'OO_PO'], ['Order {{first_name}}', 'ORDER_FIRST_NAME'], ['Señor 2', 'SEÑOR_2'], ['👍', 'BUTTON']],
            array_map(fn ($t) => [$t, FlowGraph::payloadFor($t)], ['Yes', 'Oo po!', 'Order {{first_name}}', 'Señor 2', '👍']));
    }
}

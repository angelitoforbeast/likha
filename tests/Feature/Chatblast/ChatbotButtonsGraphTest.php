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
        $this->actingAs($this->user());
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

    public function test_the_default_payload_is_the_title_upper_cased_with_non_alphanumerics_as_underscores(): void
    {
        $this->assertSame([['Yes', 'YES'], ['Oo po!', 'OO_PO'], ['Order {{first_name}}', 'ORDER_FIRST_NAME'], ['Señor 2', 'SEÑOR_2'], ['👍', 'BUTTON']],
            array_map(fn ($t) => [$t, FlowGraph::payloadFor($t)], ['Yes', 'Oo po!', 'Order {{first_name}}', 'Señor 2', '👍']));
    }
}

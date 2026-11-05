<?php

namespace Tests\Feature\Chatblast;

use App\Models\ChatbotFlow;
use App\Services\Chatbot\FlowGraph;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;

/**
 * Ang editor ng Send message: ang mga patakaran ng public/js/chatbot-buttons.js (pinapatakbo sa node, tests/js/call.cjs)
 * at ang render ng editor (ang form, ang babala ng Botcake, ang mga hangganan). Kapag walang node sa makina, ang mga
 * test ng script ay "skipped: node not found": hindi iyon pasado, hindi lang napatakbo.
 */
class ChatbotButtonsEditorTest extends ChatblastTestCase
{
    private const SCRIPT = 'public/js/chatbot-buttons.js';
    private const RUNNER = 'tests/js/call.cjs';

    private static ?bool $node = null;

    private ?\App\Models\User $me = null;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_draft_to_chatbot_flows.php',
            'database/migrations/2026_10_26_100000_create_chatbot_sequence_tables.php',
        ]]);
    }

    private function script(string $fn, mixed ...$args): mixed
    {
        if (self::$node === null) {
            try {
                $probe = new Process(['node', '--version']);
                $probe->run();
                self::$node = $probe->isSuccessful();
            } catch (\Throwable $e) {
                self::$node = false;
            }
        }
        if (!self::$node) $this->markTestSkipped('skipped: node not found');
        $root = dirname(__DIR__, 3);
        $process = new Process(['node', $root . '/' . self::RUNNER, $root . '/' . self::SCRIPT, 'ChatbotButtons']);
        $process->setInput(json_encode([['fn' => $fn, 'args' => $args]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $process->run();
        $this->assertTrue($process->isSuccessful(), 'node: ' . trim($process->getErrorOutput()));
        $out = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR)[0];
        $this->assertArrayNotHasKey('error', $out, $fn . ': ' . ($out['error'] ?? ''));

        return $out;
    }

    private function value(string $fn, mixed ...$args): mixed
    {
        return $this->script($fn, ...$args)['value'];
    }

    private function editor(?ChatbotFlow $flow = null): string
    {
        $flow ??= ChatbotFlow::create(['name' => 'Main', 'graph' => FlowGraph::blank()]);
        $this->actingAs($this->me ??= $this->user());

        return $this->get("/chatbot/flows/{$flow->id}")->assertOk()->getContent();
    }

    private function postback(string $t): array
    {
        return ['title' => $t, 'type' => 'postback', 'payload' => strtoupper($t)];
    }

    public function test_S_229_1_1_the_editor_has_the_button_row_with_title_type_and_payload_or_link(): void
    {
        $html = $this->editor();
        $this->assertStringContainsString('js/chatbot-buttons.js', $html);
        foreach (['+ Button', 'Button title', 'Postback (a tap is a reply)', 'Link (opens a page)', 'Payload', 'Link (https://)'] as $text) $this->assertStringContainsString($text, $html);
        // Ang payload ay sumusunod sa title hanggang baguhin ito ng staff (gaya ng FlowGraph::payloadFor sa server).
        foreach (['Yes' => 'YES', 'Oo po!' => 'OO_PO', 'Order {{first_name}}' => 'ORDER_FIRST_NAME', 'Señor 2' => 'SEÑOR_2', '👍' => 'BUTTON'] as $title => $payload) {
            $this->assertSame($payload, $this->value('payloadFor', $title));
            $this->assertSame(FlowGraph::payloadFor($title), $payload, 'the same rule as the server');
        }
    }

    public function test_S_229_1_2_the_row_editor_removes_only_its_own_row_and_not_a_nested_button(): void
    {
        $html = $this->editor();
        // Ang Remove ng isang mensahe ay ang sariling button ng row lang (hindi ang + Button o ang Remove ng isang button sa loob).
        $this->assertStringContainsString("box.querySelectorAll(':scope > div > button')", $html);
        $this->assertStringNotContainsString("box.querySelectorAll('button').forEach", $html);
        foreach (['+ Quick reply', 'Remove button', 'Remove quick reply'] as $text) $this->assertStringContainsString($text, $html);
    }

    public function test_S_229_1_8_an_image_item_offers_quick_replies_but_no_buttons(): void
    {
        $image = ['type' => 'image', 'value' => 'https://img.example.test/a.jpg'];
        $this->assertSame(['ok' => false, 'why' => 'Buttons go on a Text message.'], $this->value('canAddButton', $image));
        $this->assertTrue($this->value('canAddQuick', [$image], 0)['ok']);
        // Ang text na ginawang Image: natatanggal ang mga button, may sabi.
        $out = $this->script('setType', ['type' => 'text', 'value' => 'Hi', 'buttons' => [$this->postback('Yes')]], 'image');
        $this->assertSame('Buttons removed: an Image link can have quick replies only.', $out['value']);
        $this->assertSame(['type' => 'image', 'value' => 'Hi'], $out['args'][0]);
    }

    public function test_S_229_2_2_and_2_3_add_is_disabled_at_3_buttons_and_at_13_quick_replies(): void
    {
        $two = ['type' => 'text', 'value' => 'Pick', 'buttons' => [$this->postback('A'), $this->postback('B')]];
        $this->assertTrue($this->value('canAddButton', $two)['ok']);
        $three = $two;
        $three['buttons'][] = $this->postback('C');
        $this->assertSame(['ok' => false, 'why' => 'At most 3 buttons on a message.'], $this->value('canAddButton', $three));

        $quick = fn (int $n) => ['type' => 'text', 'value' => 'Pick', 'quick_replies' => array_map(fn ($i) => ['title' => 'Q' . $i, 'payload' => 'Q' . $i], range(1, $n))];
        $this->assertTrue($this->value('canAddQuick', [$quick(12)], 0)['ok']);
        $this->assertSame(['ok' => false, 'why' => 'At most 13 quick replies on a message.'], $this->value('canAddQuick', [$quick(13)], 0));
    }

    public function test_S_229_2_4_the_title_stops_at_20_characters_counting_an_emoji_as_one(): void
    {
        $this->assertSame('Oo po, kukunin ko 🙏!', $this->value('clampTitle', 'Oo po, kukunin ko 🙏!x'));
        $this->assertSame(20, $this->value('chars', 'Oo po, kukunin ko 🙏!'));
        $this->assertSame(mb_strlen('Oo po, kukunin ko 🙏!'), 20, 'the same count as the server');
        // Ang text ng mensaheng may button ay hanggang 640; kapag lampas na, hindi makakadagdag ng button.
        $this->assertSame(640, mb_strlen($this->value('clampText', ['buttons' => [$this->postback('Yes')]], str_repeat('a', 700))));
        $this->assertSame(700, mb_strlen($this->value('clampText', ['type' => 'text'], str_repeat('a', 700))));
        $this->assertSame('A message with buttons can have at most 640 characters.', $this->value('canAddButton', ['type' => 'text', 'value' => str_repeat('a', 641)])['why']);
    }

    public function test_S_229_3_1_and_3_4_buttons_and_quick_replies_exclude_each_other_until_removed(): void
    {
        $withButtons = ['type' => 'text', 'value' => 'Hi', 'buttons' => [$this->postback('Yes')]];
        $this->assertSame('This message has buttons: remove them to add quick replies.', $this->value('canAddQuick', [$withButtons], 0)['why']);
        $withQuick = ['type' => 'text', 'value' => 'Hi', 'quick_replies' => [['title' => 'Yes', 'payload' => 'YES']]];
        $this->assertSame('This message has quick replies: remove them to add buttons.', $this->value('canAddButton', $withQuick)['why']);
        // Tinanggal ang lahat ng button: puwede na ang quick reply.
        $this->assertTrue($this->value('canAddQuick', [['type' => 'text', 'value' => 'Hi', 'buttons' => []]], 0)['ok']);
    }

    public function test_S_229_3_3_quick_replies_are_offered_only_on_the_last_message_and_flagged_elsewhere(): void
    {
        $items = [['type' => 'text', 'value' => 'Size?', 'quick_replies' => [['title' => 'M', 'payload' => 'M']]], ['type' => 'text', 'value' => 'Salamat']];
        $this->assertSame('Quick replies only on the last message.', $this->value('canAddQuick', $items, 0)['why']);
        $this->assertTrue($this->value('canAddQuick', $items, 1)['ok']);
        $this->assertSame(['Quick replies only on the last message: move them, or Publish is blocked.'], $this->value('notes', $items, 0));
    }

    public function test_S_229_4_5_a_postback_or_quick_reply_turns_wait_on_and_a_link_alone_does_not(): void
    {
        $this->assertTrue($this->value('needsWait', [['type' => 'text', 'value' => 'Hi', 'buttons' => [$this->postback('Yes')]]]));
        $this->assertTrue($this->value('needsWait', [['type' => 'image', 'value' => 'https://x.test/a.jpg', 'quick_replies' => [['title' => 'Yes', 'payload' => 'YES']]]]));
        $this->assertFalse($this->value('needsWait', [['type' => 'text', 'value' => 'Shop', 'buttons' => [['title' => 'Shop', 'type' => 'web_url', 'url' => 'https://x.test']]]]));
        $html = $this->editor();
        $this->assertStringContainsString('Wait turned on: a tap is a reply.', $html);
        $this->assertStringContainsString("continue wire is removed", $html);
        $this->assertStringContainsString('ChatbotButtons.needsWait(c.items)', $html);
    }

    public function test_S_229_5_2_the_editor_warns_that_botcake_pages_get_the_text_only(): void
    {
        $flow = ChatbotFlow::create(['name' => 'Used', 'graph' => FlowGraph::blank()]);
        app(PageSettings::class)->set('Page Uno', 'chatbot', 'flow_id', (string) $flow->id, false, null, true);
        $html = $this->editor($flow);

        $this->assertStringContainsString('data-text-only-pages="' . e(json_encode(['Page Uno'])) . '"', $html);
        $this->assertStringContainsString('Botcake pages get the text only', $html);
        // Walang page na text lang: walang listahan (walang babala).
        $this->assertStringContainsString('data-text-only-pages="[]"', $this->editor());
    }

    public function test_S_229_7_6_the_button_rows_wrap_on_a_phone(): void
    {
        $html = $this->editor();
        // Ang row ng button ay nagwa-wrap at walang lapad na lampas sa form (390 px): sinusuri rin sa browser check.
        $this->assertStringContainsString("class: 'flex flex-wrap items-end gap-2 min-w-0'", $html);
        $this->assertStringContainsString("class: 'w-full min-w-0 sm:w-40'", $html);
    }

    public function test_the_row_notes_match_the_publish_blocks(): void
    {
        $items = [['type' => 'text', 'value' => 'Hi', 'buttons' => [['title' => '', 'type' => 'postback', 'payload' => ''], ['title' => 'Site', 'type' => 'web_url', 'url' => 'http://x.test'],
            ['title' => 'Yes', 'type' => 'postback', 'payload' => '']]]];
        $this->assertSame(['Every button and quick reply needs a title.', '"Site": use a link that starts with https://', '"Yes" needs a payload.'], $this->value('notes', $items, 0));
        $this->assertSame([], $this->value('notes', [['type' => 'text', 'value' => 'Hi', 'buttons' => [$this->postback('Yes'), ['title' => 'Site', 'type' => 'web_url', 'url' => 'https://x.test/p']]]], 0));

        // Gaya ng FlowGraph::publishProblems: bawat item dito ay hinaharang ng Publish, kaya may paalala rin sa form.
        $graph = fn (array $item) => ['nodes' => [['id' => 'n2', 'type' => 'send', 'config' => ['items' => [$item]]]]];
        foreach ([
            ['type' => 'text', 'value' => 'Hi', 'buttons' => [['title' => 'Site', 'type' => 'web_url', 'url' => 'https://x.test/{{id}}']]],
            ['type' => 'text', 'value' => 'Hi', 'buttons' => [['title' => 'Site', 'type' => 'web_url', 'url' => 'https://ok.test bad']]],
            ['type' => 'text', 'value' => '', 'quick_replies' => [['title' => 'Yes', 'payload' => 'YES']]],
            ['type' => 'image', 'value' => 'not a link', 'quick_replies' => [['title' => 'Yes', 'payload' => 'YES']]],
        ] as $item) {
            $this->assertNotNull(FlowGraph::publishProblems($graph($item)), json_encode($item));
            $this->assertNotSame([], $this->value('notes', [$item], 0), 'the form flags it too: ' . json_encode($item));
        }
        // Ang payload ay inihahambing nang buo (gaya ng server): " YES" at "YES" ay magkaiba.
        $spaced = ['type' => 'text', 'value' => 'Hi', 'buttons' => [['title' => 'A', 'type' => 'postback', 'payload' => ' YES'], ['title' => 'B', 'type' => 'postback', 'payload' => 'YES']]];
        $this->assertNull(FlowGraph::publishProblems($graph($spaced)));
        $this->assertSame([], $this->value('notes', [$spaced], 0));
    }
}

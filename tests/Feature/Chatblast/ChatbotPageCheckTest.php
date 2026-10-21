<?php

namespace Tests\Feature\Chatblast;

use App\Models\ChatbotFlow;
use App\Models\ChatbotSequence;
use App\Services\Chatbot\ChatbotPage;
use App\Services\Chatbot\FlowGraph;
use App\Services\Chatbot\ImageLinkCheck;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Ang Check column ng /chatbot/pages (ChatbotPageCheck): kulang na botfield, sirang larawan, pares ng Seq at Seq Img, at
 * hindi kilalang variable. Fake na HTTP at pekeng DNS: walang tawag na lumalabas.
 */
class ChatbotPageCheckTest extends ChatblastTestCase
{
    private const IMG = 'https://img.example.test/';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_draft_to_chatbot_flows.php',
            'database/migrations/2026_10_26_100000_create_chatbot_sequence_tables.php',
        ]]);
        $this->page('Page Uno', '1001');
        $this->page('Page Dos', '2002');
        // Ang inside.test ay nasa private na IP; ang iba ay pampubliko.
        $this->app->instance(ImageLinkCheck::class, new ImageLinkCheck(fn (string $host) => match ($host) {
            'inside.test' => ['10.0.0.5'], 'loop.test' => ['127.0.0.1'], 'mixed.test' => ['93.184.216.34', '192.168.1.10'], 'meta.test' => ['169.254.169.254'], default => ['93.184.216.34'],
        }));
        Http::fake([self::IMG . '*' => Http::response('', 200, ['Content-Type' => 'image/jpeg'])]);
    }

    private function settings(): PageSettings
    {
        return app(PageSettings::class);
    }

    /** Isang published na flow mula sa mga node (bawat isa ay [type, config]). */
    private function flow(string $name, array $nodes): ChatbotFlow
    {
        $list = [['id' => 'n0', 'type' => 'start']];
        foreach ($nodes as $i => [$type, $config]) $list[] = ['id' => 'n' . ($i + 1), 'type' => $type, 'config' => $config];
        $flow = ChatbotFlow::create(['name' => $name, 'graph' => FlowGraph::clean(['nodes' => $list, 'edges' => []])]);
        DB::table('chatbot_flows')->where('id', $flow->id)->update(['published_at' => now()]);

        return $flow->fresh();
    }

    private function link(string $page, ChatbotFlow $flow): void
    {
        app(ChatbotPage::class)->link($page, $flow);
    }

    private function check(string $page = 'Page Uno'): array
    {
        if (!auth()->check()) $this->actingAs($this->user());

        return $this->getJson('/chatbot/check?page_name=' . urlencode($page))->assertOk()->json();
    }

    private function send(string ...$texts): array
    {
        return ['send', ['items' => array_map(fn ($t) => ['type' => 'text', 'value' => $t], $texts)]];
    }

    public function test_S_099_1_missing_botfields_come_from_every_node_kind_and_from_nested_botfields(): void
    {
        $this->assertSame(['status' => 'none', 'reasons' => []], array_intersect_key($this->check(), ['status' => 1, 'reasons' => 1]), 'walang flow: —');

        $this->link('Page Uno', $this->flow('Main', [
            ['send', ['items' => [['type' => 'text', 'value' => '{{Main Flow}} {{first_name}}'], ['type' => 'image', 'value' => '{{PICTURE}}']]]],
            ['ai', ['system' => '{{Prompt 1}}', 'user' => '{{last_input}}', 'save_to' => 'answer']],
            ['condition', ['rows' => [['variable' => 'PRICE', 'rule' => 'is', 'value' => '{{QUANTITY}}']]]],
            ['sheet', ['sheet' => 'https://docs.google.com/spreadsheets/d/abc', 'tab' => 'A', 'columns' => ['{{ITEM NAME IN JNT DASHBOARD}}', '{{full_name}}', '{{answer}}']]],
            ['set', ['ops' => [['op' => 'set', 'field' => 'order note', 'value' => '{{Prompt 2}} {{order note}}']]]],
        ]));
        $s = $this->settings();
        $s->set('Page Uno', 'chatbot', 'main_flow', 'Hi {{Seq 4}}');
        $s->set('Page Uno', 'chatbot', 'prompt_1', 'You sell shoes.');
        $s->set('Page Uno', 'chatbot', 'price', '499');

        $res = $this->check();
        $this->assertSame('x', $res['status']);
        $this->assertSame(['Missing: QUANTITY, ITEM NAME IN JNT DASHBOARD, Prompt 2, PICTURE, Seq 4'], $res['reasons']);

        foreach (['quantity' => '1', 'item_name_in_jnt_dashboard' => 'Shoe', 'prompt_2' => 'Be nice.', 'picture' => self::IMG . 'p.jpg', 'seq_4' => 'Step 4'] as $k => $v) $s->set('Page Uno', 'chatbot', $k, $v);
        $res = $this->check();
        $this->assertSame(['ok', []], [$res['status'], $res['reasons']]);
    }

    /** @return array<string, array{0: string, 1: ?array, 2: ?string}> url, fake response [status, content type], reason */
    public static function images(): array
    {
        return [
            '200 image' => [self::IMG . 'ok.jpg', null, null],
            '404' => ['https://img2.example.test/a.jpg', [404, 'text/html'], 'Seq Img 3: link broken (404)'],
            'not an image' => ['https://img2.example.test/a.html', [200, 'text/html'], 'Seq Img 3: not an image'],
            'private IP' => ['https://inside.test/a.jpg', null, 'Seq Img 3: link not allowed'],
            'loopback IP literal' => ['http://127.0.0.1/a.jpg', null, 'Seq Img 3: link not allowed'],
            'link-local' => ['http://meta.test/latest', null, 'Seq Img 3: link not allowed'],
            'one private IP among public ones' => ['https://mixed.test/a.jpg', null, 'Seq Img 3: link not allowed'],
            'IPv4 inside IPv6' => ['http://[::ffff:10.0.0.1]/a.jpg', null, 'Seq Img 3: link not allowed'],
            'carrier-grade NAT' => ['http://100.64.1.1/a.jpg', null, 'Seq Img 3: link not allowed'],
            'not http' => ['ftp://img.example.test/a.jpg', null, 'Seq Img 3: link not allowed'],
        ];
    }

    /** @dataProvider images */
    public function test_S_099_2_image_links_must_answer_200_with_an_image(string $url, ?array $fake, ?string $reason): void
    {
        if ($fake) Http::fake([$url => Http::response('', $fake[0], ['Content-Type' => $fake[1]])]);
        $this->link('Page Uno', $this->flow('Main', [$this->send('{{Seq 3}} {{Seq Img 3}}')]));
        $this->settings()->set('Page Uno', 'chatbot', 'seq_3', 'Step 3');
        $this->settings()->set('Page Uno', 'chatbot', 'seq_img_3', $url);

        $res = $this->check();
        $this->assertSame($reason ? ['x', [$reason]] : ['ok', []], [$res['status'], $res['reasons']]);
        if (str_ends_with((string) $reason, 'not allowed')) Http::assertNothingSent();
    }

    public function test_S_099_2_at_most_20_links_are_asked_per_run_and_the_rest_wait(): void
    {
        $this->link('Page Uno', $this->flow('Main', [$this->send('{{PICTURE}}')]));
        $this->settings()->set('Page Uno', 'chatbot', 'picture', self::IMG . 'p.jpg');
        for ($i = 1; $i <= 20; $i++) $this->settings()->set('Page Uno', 'chatbot', 'seq_img_' . $i, self::IMG . $i . '.jpg');

        $res = $this->check();
        $this->assertSame(['ok', 1], [$res['status'], $res['unchecked']]);
        $this->assertCount(20, Http::recorded());
    }

    public function test_S_099_2_redirects_are_rechecked_and_head_falls_back_to_a_ranged_get_and_urls_are_cached(): void
    {
        Http::fake([
            'https://img2.example.test/hop' => Http::response('', 302, ['Location' => 'https://inside.test/x.jpg']),
            'https://img2.example.test/nohead.jpg' => fn ($r) => $r->method() === 'HEAD'
                ? Http::response('', 405) : Http::response('x', 206, ['Content-Type' => 'image/png']),
        ]);
        $this->link('Page Uno', $this->flow('Main', [$this->send('{{PICTURE}} {{Seq Img 1}}')]));
        $this->link('Page Dos', \App\Models\ChatbotFlow::where('name', 'Main')->first());
        foreach (['Page Uno', 'Page Dos'] as $p) {
            $this->settings()->set($p, 'chatbot', 'picture', 'https://img2.example.test/hop');
            $this->settings()->set($p, 'chatbot', 'seq_img_1', 'https://img2.example.test/nohead.jpg');
        }

        $this->assertSame(['PICTURE: link not allowed'], $this->check()['reasons'], 'ang redirect sa private IP ay tinatanggihan');
        $this->assertTrue(collect(Http::recorded())->contains(fn ($p) => $p[0]->method() === 'GET' && $p[0]->hasHeader('Range')));
        $sent = count(Http::recorded());

        $this->assertSame(['PICTURE: link not allowed'], $this->check('Page Dos')['reasons']);
        $this->assertCount($sent, Http::recorded(), 'parehong URL: mula sa cache (24 h)');

        $this->postJson('/chatbot/check', ['page_name' => 'Page Dos'])->assertOk();
        $this->assertGreaterThan($sent, count(Http::recorded()), 'Check again: tinatanong ulit');
    }

    public function test_S_099_3_a_seq_set_without_its_image_or_the_reverse_is_a_pair_reason_when_the_flow_or_its_sequences_use_both(): void
    {
        $step = $this->flow('Step', [$this->send('{{Seq 1}}'), ['send', ['items' => [['type' => 'image', 'value' => '{{Seq Img 1}}']]]]]);
        $seq = ChatbotSequence::create(['name' => 'Drip', 'active' => true, 'exit_on_reply' => true]);
        $seq->steps()->create(['position' => 1, 'delay_amount' => 1, 'delay_unit' => 'hours', 'flow_id' => $step->id, 'active' => true]);
        $this->link('Page Uno', $this->flow('Main', [['sequence', ['action' => 'subscribe', 'sequence_id' => $seq->id]]]));
        $s = $this->settings();
        $s->set('Page Uno', 'chatbot', 'seq_1', 'One');
        $s->set('Page Uno', 'chatbot', 'seq_img_1', self::IMG . '1.jpg');
        $s->set('Page Uno', 'chatbot', 'seq_5', 'Five');
        $s->set('Page Uno', 'chatbot', 'seq_img_6', self::IMG . '6.jpg');

        $res = $this->check();
        $this->assertSame(['x', ['Seq 5 has no Seq Img 5', 'Seq Img 6 has no Seq 6']], [$res['status'], $res['reasons']]);
    }

    public function test_S_099_4_an_unknown_variable_inside_a_botfield_is_a_reason(): void
    {
        $this->link('Page Uno', $this->flow('Main', [$this->send('{{Prompt 1}}'), ['set', ['ops' => [['op' => 'set', 'field' => 'order note', 'value' => 'x']]]]]));
        $this->settings()->set('Page Uno', 'chatbot', 'price', '499');
        $this->settings()->set('Page Uno', 'chatbot', 'prompt_3', 'x'); // ginagamit ng Prompt 1, kaya kailangan may laman
        $this->settings()->set('Page Uno', 'chatbot', 'prompt_1', 'Only {{PRICE}} for {{first_name}}, {{order note}}, {{ PRICEE }} {{Prompt_3}}');

        $res = $this->check();
        $this->assertSame(['x', ['Prompt 1 uses {{PRICEE}} (unknown)']], [$res['status'], $res['reasons']]);
    }

    public function test_S_099_5_the_check_is_cached_and_recomputed_after_a_settings_or_flow_change(): void
    {
        $flow = $this->flow('Main', [$this->send('{{PRICE}}')]);
        $this->link('Page Uno', $flow);
        $first = $this->check();
        $this->assertSame(['Missing: PRICE'], $first['reasons']);

        // Isang pagbabago na hindi dumaan sa settings o sa flow: ang naka-cache pa rin ang ibinabalik.
        DB::table('chatbot_flows')->where('id', $flow->id)->update(['graph' => json_encode(FlowGraph::clean(['nodes' => [['id' => 'n0', 'type' => 'start']], 'edges' => []]))]);
        $this->assertSame(['Missing: PRICE'], $this->check()['reasons'], 'mula sa cache');

        $this->settings()->set('Page Uno', 'chatbot', 'quantity', '2');
        $this->assertSame(['ok', []], [$this->check()['status'], $this->check()['reasons']], 'nabago ang settings: kinuwenta ulit');

        $this->travel(1)->minutes();
        $flow->update(['graph' => FlowGraph::clean(['nodes' => [['id' => 'n0', 'type' => 'start'], ['id' => 'n1', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => '{{Prompt 3}}']]]]], 'edges' => []])]);
        $this->assertSame(['Missing: Prompt 3'], $this->check()['reasons'], 'nabago ang flow: kinuwenta ulit');

        // Ang /chatbot/data ay may naka-cache na resulta (walang bagong kuwenta doon).
        $row = collect($this->getJson('/chatbot/data')->json('rows'))->firstWhere('page', 'Page Uno');
        $this->assertSame(['Missing: Prompt 3'], $row['check']['reasons']);
        $this->assertNull(collect($this->getJson('/chatbot/data')->json('rows'))->firstWhere('page', 'Page Dos')['check']);
    }
}

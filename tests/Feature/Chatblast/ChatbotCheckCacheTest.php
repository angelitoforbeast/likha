<?php

namespace Tests\Feature\Chatblast;

use App\Models\ChatbotFlow;
use App\Models\ChatbotSequence;
use App\Services\Chatbot\ChatbotPage;
use App\Services\Chatbot\ChatbotPageCheck;
use App\Services\Chatbot\FlowGraph;
use App\Services\Chatbot\ImageLinkCheck;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Ang Check column ng /chatbot/pages nang walang tawag kada page: ang naka-cache na Check ng bawat page ay kasama sa
 * /chatbot/data, at ang key nito ay mula sa sariling inputs ng page (settings, ang flow nito at ang mga sequence at
 * step na ginagamit ng flow), kaya ang pag-edit ng isang flow ay ang mga page lang na gumagamit nito ang sinusuri ulit.
 * Ang pila ng page (check-queue:start/end) ay pinapatakbo sa Node. Fake na HTTP at DNS.
 */
class ChatbotCheckCacheTest extends ChatblastTestCase
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
        $this->app->instance(ImageLinkCheck::class, new ImageLinkCheck(fn (string $host) => ['93.184.216.34']));
        Http::fake([self::IMG . '*' => Http::response('', 200, ['Content-Type' => 'image/jpeg'])]);
        $this->actingAs($this->user());
    }

    private function settings(): PageSettings
    {
        return app(PageSettings::class);
    }

    /** Isang page na nasa listahan, may chat block, at (kapag may flow) nakatali sa flow. */
    private function listed(string $page, string $id, ?ChatbotFlow $flow = null, array $values = []): void
    {
        $this->page($page, $id);
        $this->settings()->set($page, 'botcake', 'chat.key', 'key-' . $id, false, null, true);
        if ($flow) app(ChatbotPage::class)->link($page, $flow);
        foreach ($values as $k => $v) $this->settings()->set($page, 'chatbot', $k, $v);
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

    private function send(string ...$texts): array
    {
        return ['send', ['items' => array_map(fn ($t) => ['type' => 'text', 'value' => $t], $texts)]];
    }

    /** @return array<string, ?array> page => ang Check sa /chatbot/data (null = wala pang naka-cache) */
    private function dataChecks(): array
    {
        return collect($this->getJson('/chatbot/data')->assertOk()->json('rows'))->mapWithKeys(fn ($r) => [$r['page'] => $r['check']])->all();
    }

    private function warm(string ...$pages): void
    {
        foreach ($pages as $p) $this->getJson('/chatbot/check?page_name=' . urlencode($p))->assertOk();
    }

    /** Ang bahagi ng page sa pagitan ng dalawang marker, pinapatakbo sa Node kasama ang $script. */
    private function node(string $marker, string $script): mixed
    {
        $node = (new ExecutableFinder())->find('node');
        if ($node === null) $this->markTestSkipped('Node is not installed.');
        $html = $this->get('/chatbot/pages')->assertOk()->getContent();
        $this->assertSame(1, preg_match('~// ' . $marker . ':start(.*?)// ' . $marker . ':end~s', $html, $m), $marker . ' is in the page');
        $file = tempnam(sys_get_temp_dir(), 'chk') . '.mjs';
        file_put_contents($file, html_entity_decode($m[1], ENT_QUOTES) . "\n" . $script);
        $p = new Process([$node, $file]);
        $p->run();
        @unlink($file);
        $this->assertTrue($p->isSuccessful(), $p->getErrorOutput());

        return json_decode($p->getOutput(), true);
    }

    public function test_S_207_5_one_data_request_carries_every_warm_check_and_only_stale_pages_are_checked_two_at_a_time(): void
    {
        $flow = $this->flow('Main', [$this->send('{{Main Flow}}')]);
        $pages = [];
        for ($i = 1; $i <= 117; $i++) {
            $pages[] = $p = sprintf('Page %03d', $i);
            $this->listed($p, (string) (5000 + $i), $flow, ['main_flow' => 'Hello ' . $i]);
        }
        foreach ($pages as $p) app(ChatbotPageCheck::class)->check($p);

        // Mainit ang cache (kahit kalahating oras na): ang /chatbot/data na ang may Check ng lahat.
        $this->travel(30)->minutes();
        $checks = $this->dataChecks();
        $this->assertCount(117, $checks);
        $this->assertSame([], array_keys(array_filter($checks, fn ($c) => $c === null)), 'every page has its cached check');
        $this->assertSame('ok', $checks['Page 001']['status']);
        $this->assertNotEmpty($checks['Page 001']['checked_at']);

        // Tatlong page ang nagbago: ang tatlong iyon lang ang walang naka-cache.
        foreach (['Page 010', 'Page 050', 'Page 117'] as $p) $this->settings()->set($p, 'chatbot', 'main_flow', 'Changed');
        $stale = array_keys(array_filter($this->dataChecks(), fn ($c) => $c === null));
        $this->assertSame(['Page 010', 'Page 050', 'Page 117'], $stale);

        // Ang pila ng page: ang walang Check lang, dalawa nang sabay, ang nasa screen muna.
        $rows = json_encode(array_map(fn ($p) => ['page' => $p, 'check' => in_array($p, $stale, true) ? null : ['status' => 'ok']], $pages));
        $out = $this->node('check-queue', <<<JS
            const rows = {$rows};
            async function run(rows, onScreen) {
                const started = []; let live = 0, most = 0;
                const check = p => { started.push(p); live++; most = Math.max(most, live); return new Promise(r => setTimeout(() => { live--; r(); }, 5)); };
                await checkQueue(staleChecks(rows), check, todo => todo.find(p => onScreen.includes(p)) ?? todo[0]);
                return {calls: started.length, most, started};
            }
            const warm = await run(rows.map(r => ({...r, check: {status: 'ok'}})), []);
            const stale = await run(rows, ['Page 117', 'Page 050']);
            console.log(JSON.stringify({warm, stale}));
            JS);
        $this->assertSame(['calls' => 0, 'most' => 0, 'started' => []], $out['warm'], 'warm: no /chatbot/check call');
        $this->assertSame(['calls' => 3, 'most' => 2, 'started' => ['Page 050', 'Page 117', 'Page 010']], $out['stale'], '3 calls, 2 at a time, on-screen rows first');

        // Ang orders, ads at link ng larawan ay wala sa key: pagkalipas ng isang oras, sinusuri ulit ang lahat.
        $this->travel(31)->minutes();
        $this->assertSame([], array_filter($this->dataChecks()), 'an hour later every check is computed again');
    }

    /** @return array<string, array{0: string}> */
    public static function edits(): array
    {
        return ['the flow itself' => ['flow'], 'a flow of a sequence it subscribes to' => ['step flow'], 'a step of that sequence' => ['step'], 'a new step in that sequence' => ['new step']];
    }

    #[DataProvider('edits')]
    public function test_S_207_6_editing_flow_a_rechecks_only_the_pages_on_flow_a(string $edit): void
    {
        $stepFlow = $this->flow('Step', [$this->send('{{Seq 1}}')]);
        $seq = ChatbotSequence::create(['name' => 'Drip', 'active' => true, 'exit_on_reply' => true]);
        $step = $seq->steps()->create(['position' => 1, 'delay_amount' => 1, 'delay_unit' => 'hours', 'flow_id' => $stepFlow->id, 'active' => true]);
        $a = $this->flow('Flow A', [$this->send('{{Main Flow}}'), ['sequence', ['action' => 'subscribe', 'sequence_id' => $seq->id]]]);
        $b = $this->flow('Flow B', [$this->send('{{Main Flow}}')]);
        $this->listed('Page A1', '1101', $a, ['main_flow' => 'Hi', 'seq_1' => 'One']);
        $this->listed('Page A2', '1102', $a, ['main_flow' => 'Hi']);
        $this->listed('Page B1', '1201', $b, ['main_flow' => 'Hi']);
        $this->warm('Page A1', 'Page A2', 'Page B1');
        $this->assertNotContains(null, $this->dataChecks());

        $this->travel(1)->minutes();
        match ($edit) {
            'flow' => $a->update(['graph' => FlowGraph::clean(['nodes' => [...$a->graph['nodes'], ['id' => 'n9', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => '{{Prompt 1}}']]]]], 'edges' => []])]),
            'step flow' => $stepFlow->update(['graph' => FlowGraph::clean(['nodes' => [...$stepFlow->graph['nodes'], ['id' => 'n9', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => '{{Seq 2}}']]]]], 'edges' => []])]),
            'step' => $step->update(['delay_amount' => 3]),
            'new step' => $seq->steps()->create(['position' => 2, 'delay_amount' => 1, 'delay_unit' => 'days', 'flow_id' => $stepFlow->id, 'active' => true]),
        };

        $checks = $this->dataChecks();
        $this->assertNull($checks['Page A1'], 'A1 uses the edited flow');
        $this->assertNull($checks['Page A2'], 'A2 uses the edited flow');
        $this->assertNotNull($checks['Page B1'], 'B1 keeps its cached check');
        // Ang bagong Check ng A ay mula sa binagong flow.
        if ($edit === 'flow') $this->assertContains('Missing: Prompt 1', $this->getJson('/chatbot/check?page_name=Page%20A1')->json('reasons'));
        if ($edit === 'step flow') $this->assertContains('Missing: Seq 2', $this->getJson('/chatbot/check?page_name=Page%20A1')->json('reasons'));
    }

    public function test_S_207_6_a_flow_edit_that_adds_a_saved_field_rechecks_every_page_that_could_name_it(): void
    {
        // Ang {{Order Note}} ay "unknown" hanggang may flow (kahit ibang flow) na sumusulat dito: nakikita ng lahat ng page.
        $a = $this->flow('Flow A', [$this->send('{{Main Flow}}')]);
        $b = $this->flow('Flow B', [$this->send('Hi')]);
        $this->listed('Page A1', '1101', $a, ['main_flow' => 'Note: {{Order Note}}']);
        $this->warm('Page A1');
        $this->assertContains('Main Flow uses {{Order Note}} (unknown)', $this->dataChecks()['Page A1']['reasons']);

        $this->travel(1)->minutes();
        $b->update(['graph' => FlowGraph::clean(['nodes' => [...$b->graph['nodes'], ['id' => 'n9', 'type' => 'set', 'config' => ['ops' => [['op' => 'set', 'field' => 'Order Note', 'value' => 'x']]]]], 'edges' => []])]);

        $this->assertNull($this->dataChecks()['Page A1'], 'the names a check knows changed');
        $this->assertNotContains('Main Flow uses {{Order Note}} (unknown)', $this->getJson('/chatbot/check?page_name=Page%20A1')->json('reasons'));
    }

    public function test_S_207_7_check_results_equal_the_base_codes_for_the_same_data(): void
    {
        $stepFlow = $this->flow('Step', [$this->send('{{Seq 1}}'), ['send', ['items' => [['type' => 'image', 'value' => '{{Seq Img 1}}']]]]]);
        $seq = ChatbotSequence::create(['name' => 'Drip', 'active' => true, 'exit_on_reply' => true]);
        $seq->steps()->create(['position' => 1, 'delay_amount' => 1, 'delay_unit' => 'hours', 'flow_id' => $stepFlow->id, 'active' => true]);
        $main = $this->flow('Main', [$this->send('{{Main Flow}} {{first_name}}'), ['send', ['items' => [['type' => 'image', 'value' => '{{PICTURE}}']]]],
            ['sequence', ['action' => 'subscribe', 'sequence_id' => $seq->id]], ['set', ['ops' => [['op' => 'set', 'field' => 'order note', 'value' => '{{Prompt 2}}']]]]]);
        $plain = $this->flow('Plain', [$this->send('{{Main Flow}}')]);
        $unpublished = ChatbotFlow::create(['name' => 'Draft only', 'graph' => FlowGraph::clean(['nodes' => [['id' => 'n0', 'type' => 'start']], 'edges' => []])]);

        $complete = ['main_flow' => 'Hi {{Seq 4}}', 'seq_4' => 'Four', 'seq_img_4' => self::IMG . '4.jpg', 'picture' => self::IMG . 'p.jpg', 'prompt_2' => 'Be nice.', 'seq_1' => 'One', 'seq_img_1' => self::IMG . '1.jpg',
            'price' => '499', 'item_name_in_jnt_dashboard' => 'Shoe', 'quantity' => '1'];
        $this->listed('Page Ok', '2001', $main, $complete);
        $this->listed('Page Missing', '2002', $main, ['main_flow' => 'Hi {{Seq 4}}', 'price' => '499']);
        $this->listed('Page Pairs', '2003', $main, array_merge(array_diff_key($complete, ['seq_img_1' => 1]), ['seq_5' => 'Five', 'seq_img_6' => self::IMG . '6.jpg']));
        $this->listed('Page Unknown', '2004', $plain, ['main_flow' => 'Hi {{Mystery}}', 'price' => 'https://img.example.test/x.jpg']);
        $this->listed('Page No Flow', '2005');
        $this->listed('Page Draft', '2006', $unpublished);
        $this->listed('Page Off', '2007', $plain, ['main_flow' => 'Hi']);
        $this->settings()->set('Page Off', 'chatbot', 'active', '0', false, null, true);

        $got = [];
        foreach (['Page Ok', 'Page Missing', 'Page Pairs', 'Page Unknown', 'Page No Flow', 'Page Draft', 'Page Off'] as $p) {
            $res = $this->getJson('/chatbot/check?page_name=' . urlencode($p))->assertOk()->json();
            unset($res['checked_at']);
            $got[$p] = $res;
        }

        $file = __DIR__ . '/snapshots/207/check-results.json';
        if (getenv('WRITE_207_SNAPSHOTS') === '1') file_put_contents($file, json_encode($got, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        $this->assertSame(json_decode((string) file_get_contents($file), true), $got);
        $this->assertSame('ok', $got['Page Ok']['status']);
        $this->assertSame('x', $got['Page Missing']['status']);
    }

    public function test_S_207_8_check_again_forces_one_row_and_the_next_load_shows_it(): void
    {
        $flow = $this->flow('Main', [['send', ['items' => [['type' => 'image', 'value' => '{{PICTURE}}']]]]]);
        $this->listed('Page Uno', '1001', $flow, ['picture' => 'https://img2.example.test/p.jpg']);
        $this->listed('Page Dos', '1002', $flow, ['picture' => 'https://img2.example.test/q.jpg']);
        Http::fake(['https://img2.example.test/*' => Http::sequence()->push('', 200, ['Content-Type' => 'image/jpeg'])->push('', 200, ['Content-Type' => 'image/jpeg'])->whenEmpty(Http::response('', 404, ['Content-Type' => 'text/html']))]);
        $this->warm('Page Uno', 'Page Dos');
        $this->assertSame([], $this->dataChecks()['Page Uno']['reasons']);

        // Ang link ay sira na: ang cache ay "ok" pa rin hanggang Check again sa row na iyon lang.
        $this->postJson('/chatbot/check', ['page_name' => 'Page Uno'])->assertOk()->assertJson(['status' => 'x', 'reasons' => ['PICTURE: link broken (404)']]);
        $checks = $this->dataChecks();
        $this->assertSame(['PICTURE: link broken (404)'], $checks['Page Uno']['reasons'], 'the next load shows the forced result');
        $this->assertSame([], $checks['Page Dos']['reasons'], 'the other row is not re-checked');

        $html = $this->get('/chatbot/pages')->assertOk()->getContent();
        $this->assertStringContainsString("again.addEventListener('click', ev => { ev.stopPropagation(); runCheck(page, true); });", $html);
    }
}

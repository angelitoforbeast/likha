<?php

namespace Tests\Feature\OrderEncode;

use App\Jobs\EncodeOrder;
use App\Models\ChatbotFlow;
use App\Models\ChatbotReply;
use App\Models\MacroOutput;
use App\Models\OrderEncode;
use App\Services\Chatbot\CustomerFields;
use App\Services\Chatbot\FlowEngine;
use App\Services\Chatbot\FlowGraph;
use App\Services\Chatbot\FlowRun;
use App\Services\Chatbot\Outbox;
use App\Services\OrderEncode\LiveOrder;
use App\Services\OrderEncode\Runner;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/** Spec 132: ang "Encode order" step ng flow (builder, engine, live path). Walang Google at walang OpenAI. */
class EncodeOrderNodeTest extends OrderEncodeTestCase
{
    private const PSID = 7001;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_24_100000_add_draft_to_chatbot_flows.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_30_100000_add_results_to_chatbot_replies.php',
        ]]);
        config(['services.chatbot.replies_enabled' => true]);
    }

    /** Ang config ng node: ang mga variable ng Sheet step ng All Orders. */
    private function config(array $over = []): array
    {
        return $over + [
            'date' => '{{current_date}}', 'page' => '{{page_name}}', 'name' => '{{full_name}}', 'phone' => '{{PHONE}}', 'input' => '{{last_input}}',
            'shop' => 'SHOP {{last_input}}', 'extracted' => 'static extracted', 'price' => '998',
            'also_sheet' => false, 'sheet' => '', 'tab' => '', 'on_failure' => 'sheet',
        ];
    }

    private function outbox(string $status = 'sent'): Outbox
    {
        return new class($status) implements Outbox {
            public int $appends = 0;
            public array $rows = [];

            public function __construct(private string $status)
            {
            }

            public function send(FlowRun $run, string $nodeId, array $messages): array
            {
                return ['status' => 'sent', 'error' => null];
            }

            public function appendRow(FlowRun $run, string $nodeId, string $spreadsheetId, string $tab, array $row): array
            {
                $this->appends++;
                $this->rows[] = [$spreadsheetId, $tab, $row];

                return ['status' => $this->status, 'error' => $this->status === 'failed' ? 'boom' : null];
            }
        };
    }

    private function graph(array $c, array $extra = [], string $type = 'encode_order'): array
    {
        return FlowGraph::clean(['nodes' => [
            ['id' => 'n1', 'type' => 'start', 'x' => 0, 'y' => 0, 'config' => []],
            ['id' => 'n2', 'type' => $type, 'x' => 0, 'y' => 0, 'config' => $c + $extra],
            ['id' => 'n3', 'type' => 'end', 'x' => 0, 'y' => 0, 'config' => []],
            ['id' => 'n4', 'type' => 'end', 'x' => 0, 'y' => 0, 'config' => []],
        ], 'edges' => [['from' => 'n1', 'port' => 'next', 'to' => 'n2'], ['from' => 'n2', 'port' => 'next', 'to' => 'n3'], ['from' => 'n2', 'port' => 'failed', 'to' => 'n4']]]);
    }

    private function go(array $c, Outbox $outbox, bool $test = false, ?string $runId = null, array $extra = [], string $type = 'encode_order'): FlowRun
    {
        $run = new FlowRun(5, $this->graph($c, $extra, $type), self::PAGE, '1001', [], new CustomerFields(1001, self::PSID), 13, 'hi po', 'Ana Cruz', '2026-10-23 10:15', $test);
        $run->runId = $runId;

        return app(FlowEngine::class)->run($run, $outbox);
    }

    public function test_S_132_1_the_builder_lists_the_step_and_saves_and_reloads_its_config(): void
    {
        $this->actingAs($this->user());
        $flow = ChatbotFlow::create(['name' => 'Main', 'graph' => FlowGraph::blank()]);

        $page = $this->get("/chatbot/flows/{$flow->id}")->assertOk();
        $page->assertSee('data-add="encode_order"', false)->assertSee('Encode order')
            ->assertSee('Encodes and checks the order in Likha and saves it to the orders (macro output). Replaces the Google Sheet step.');

        $graph = $this->graph($this->config(['also_sheet' => true, 'sheet' => 'https://docs.google.com/spreadsheets/d/' . self::ORDERS . '/edit', 'tab' => 'All Orders', 'evil' => 'x', 'counts_order' => true]));
        $this->putJson("/chatbot/flows/{$flow->id}/graph", ['graph' => $graph, 'version' => 0])->assertOk();
        $saved = $this->getJson("/chatbot/flows/{$flow->id}/graph")->assertOk()->json('graph');

        $c = $saved['nodes'][1]['config'];
        $this->assertSame(['next', 'failed'], FlowGraph::ports($saved['nodes'][1]));
        $this->assertSame(['{{current_date}}', '{{PHONE}}', '998', true, 'All Orders', 'sheet', true], [$c['date'], $c['phone'], $c['price'], $c['also_sheet'], $c['tab'], $c['on_failure'], $c['counts_order']]);
        $this->assertArrayNotHasKey('evil', $c);
    }

    public static function publishCases(): array
    {
        $link = 'https://docs.google.com/spreadsheets/d/' . self::ORDERS . '/edit';

        return [
            'write to the sheet instead, no sheet' => [['on_failure' => 'sheet', 'sheet' => ''], 422],
            'write to the sheet instead, bad link' => [['on_failure' => 'sheet', 'sheet' => 'not a link'], 422],
            'also write, no sheet' => [['on_failure' => 'continue', 'also_sheet' => true, 'sheet' => ''], 422],
            'write to the sheet instead, with a sheet' => [['on_failure' => 'sheet', 'sheet' => $link], 200],
            'continue without saving needs no sheet' => [['on_failure' => 'continue', 'sheet' => ''], 200],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('publishCases')]
    public function test_S_132_2_publishing_needs_the_fallback_sheet_when_the_step_would_use_it(array $c, int $status): void
    {
        $this->actingAs($this->user());
        $flow = ChatbotFlow::create(['name' => 'Main', 'graph' => FlowGraph::blank()]);
        // ang draft ay nase-save kahit kulang pa (autosave); ang Publish ang humaharang
        $this->putJson("/chatbot/flows/{$flow->id}/graph", ['graph' => $this->graph($this->config($c)), 'version' => 0])->assertOk();

        $res = $this->postJson("/chatbot/flows/{$flow->id}/publish", ['version' => 1]);

        $res->assertStatus($status);
        if ($status === 422) $this->assertStringContainsString('Encode order', $res->json('error'));
    }

    public function test_S_132_3_the_engine_queues_one_live_encode_with_the_a_to_i_row_and_continues_on_next(): void
    {
        Queue::fake();
        $outbox = $this->outbox();

        $run = $this->go($this->config(), $outbox);

        $this->assertSame(['n1', 'n2', 'n3'], array_column($run->path, 'node'));
        $this->assertSame('next', $run->path[1]['port']);
        Queue::assertPushed(EncodeOrder::class, 1);
        Queue::assertPushed(EncodeOrder::class, fn (EncodeOrder $j) => $j->connection === 'database' && $j->queue === 'encode');
        $o = OrderEncode::sole();
        $this->assertSame([self::PAGE, 'live', 'flow-node', 'All Orders', null, self::PSID, 13, 5, 'queued'],
            [$o->page, $o->mode, $o->spreadsheet_id, $o->tab, $o->row_number, $o->psid, $o->chat_id, $o->flow_id, $o->status]);
        $row = $o->input['row'];
        $this->assertCount(9, $row);
        $this->assertSame(["'" . now('Asia/Manila')->toDateString(), "'" . self::PAGE, "'Ana Cruz", "'", "'hi po", "'SHOP hi po", 'static extracted', '998', ''], $row);
        $this->assertSame(0, $outbox->appends);
        // ang reply log ay gaya ng live na Sheet step ng spec 130
        $reply = ChatbotReply::where('kind', 'sheet')->sole();
        $this->assertSame(['skipped', 'encoded in Likha', 'n2'], [$reply->status, $reply->content['note'], $reply->node_id]);
        $this->assertSame('encode_order', collect($run->events)->firstWhere('type', 'encode_order')['type']);
        $this->assertSame('queued', collect($run->events)->firstWhere('type', 'encode_order')['detail']['result']);
    }

    public function test_S_132_4_the_test_tab_never_encodes_and_shows_the_row_it_would_send(): void
    {
        Queue::fake();
        $outbox = $this->outbox('test');

        $run = $this->go($this->config(['also_sheet' => true, 'sheet' => self::ORDERS]), $outbox, true);

        Queue::assertNothingPushed();
        $this->assertSame(0, OrderEncode::count());
        $this->assertSame(['n1', 'n2', 'n3'], array_column($run->path, 'node'));
        $this->assertSame('Test: would encode the order', $run->sheetRows[0]['note']);
        $this->assertSame('test', $run->sheetRows[0]['status']);
        $this->assertCount(9, $run->sheetRows[0]['row']);
        $this->assertSame(0, ChatbotReply::count());
    }

    public function test_S_132_5_also_write_to_google_sheet_appends_once_and_a_later_failed_encode_does_not_append_again(): void
    {
        Queue::fake();
        $outbox = $this->outbox();
        $link = 'https://docs.google.com/spreadsheets/d/' . self::ORDERS . '/edit';

        $this->go($this->config(['also_sheet' => true, 'sheet' => $link, 'tab' => 'All Orders']), $outbox);

        $this->assertSame(1, $outbox->appends);
        $this->assertSame([self::ORDERS, 'All Orders'], [$outbox->rows[0][0], $outbox->rows[0][1]]);
        $this->assertCount(9, $outbox->rows[0][2]);
        $o = OrderEncode::sole();
        $this->assertSame(self::ORDERS, $o->spreadsheet_id);

        // ang encode ay pumalya: ang row ay nasa sheet na, hindi na isusulat ulit
        (new EncodeOrder($o->id))->failed(new \RuntimeException('boom'));

        $this->assertSame([], $this->google->appended);
        $this->assertSame('failed', $o->fresh()->status);
    }

    public function test_S_132_6_a_failed_encode_writes_the_row_to_the_fallback_sheet_once(): void
    {
        Queue::fake();
        $link = 'https://docs.google.com/spreadsheets/d/' . self::ORDERS . '/edit';
        $this->go($this->config(['sheet' => $link, 'tab' => 'All Orders']), $this->outbox());
        $o = OrderEncode::sole();
        @unlink($this->addressFile); // pagdating ng job, wala nang address file: failed

        $job = new EncodeOrder($o->id);
        $job->handle(app(Runner::class));
        $job->handle(app(Runner::class)); // ulit na takbo ng failed na order
        $job->failed(new \RuntimeException('again'));

        $this->assertSame('failed', $o->fresh()->status);
        $this->assertCount(1, $this->google->appended);
        $this->assertSame([self::ORDERS, 'All Orders'], [$this->google->appended[0][0], $this->google->appended[0][1]]);
        $this->assertCount(9, $this->google->appended[0][2]);
    }

    public function test_S_132_7_a_failed_encode_with_continue_without_saving_writes_no_sheet(): void
    {
        Queue::fake();
        $this->go($this->config(['on_failure' => 'continue']), $this->outbox());
        @unlink($this->addressFile);

        (new EncodeOrder(OrderEncode::sole()->id))->handle(app(Runner::class));

        $this->assertSame([], $this->google->appended);
        $this->assertSame('failed', OrderEncode::sole()->status);
    }

    public function test_S_132_8_a_retry_never_makes_a_second_macro_output_row(): void
    {
        Queue::fake();
        Http::fake(['api.openai.com/v1/chat/completions' => Http::response(Fixtures::chat('Blk 5 Lot 3 Lahug', 120, 8))]);
        $this->go($this->config([
            'date' => '10:15 23-10-2026', 'page' => 'Page Uno', 'name' => 'Ana Cruz', 'phone' => '09171234567', 'input' => 'Hi po, order ko 2 pcs',
            'shop' => $this->orderRow()[5], 'extracted' => $this->orderRow()[6], 'price' => '998',
        ]), $this->outbox());
        $o = OrderEncode::sole();
        $boom = new class extends LiveOrder {
            public bool $fail = true;

            public function create(OrderEncode $o): ?MacroOutput
            {
                $row = parent::create($o);
                if ($this->fail) throw new \RuntimeException('connection lost after the insert');

                return $row;
            }
        };
        $this->app->instance(LiveOrder::class, $boom);

        try {
            (new EncodeOrder($o->id))->handle(app(Runner::class));
            $this->fail('the job must be retried');
        } catch (\RuntimeException) {
        }
        $boom->fail = false;
        (new EncodeOrder($o->id))->handle(app(Runner::class));

        $this->assertSame(1, MacroOutput::count());
        $this->assertSame('done', $o->fresh()->status);
        $this->assertSame([], $this->google->appended);
        $m = MacroOutput::sole();
        $this->assertSame(['Page Uno', 'Ana Cruz', 'CEBU'], [$m->PAGE, $m->fb_name, $m->PROVINCE]);
    }

    public function test_S_132_9_a_rerun_of_the_same_run_makes_no_second_order_and_no_second_sheet_row(): void
    {
        Queue::fake();
        $outbox = $this->outbox();
        $link = 'https://docs.google.com/spreadsheets/d/' . self::ORDERS . '/edit';
        $c = $this->config(['also_sheet' => true, 'sheet' => $link]);

        $first = $this->go($c, $outbox, false, 'abcd1234');
        $again = $this->go($c, $outbox, false, 'abcd1234');

        $this->assertSame(1, OrderEncode::count());
        $this->assertSame(1, $outbox->appends);
        Queue::assertPushed(EncodeOrder::class, 1);
        $this->assertSame(['n1', 'n2', 'n3'], array_column($again->path, 'node'));
        $this->assertSame('already_queued', collect($again->events)->firstWhere('type', 'encode_order')['detail']['result']);

        // ibang takbo (bagong order ng customer) = bagong order
        $this->go($c, $outbox, false, 'ffff0000');
        $this->assertSame(2, OrderEncode::count());
    }

    public static function cannotQueue(): array
    {
        $link = 'https://docs.google.com/spreadsheets/d/' . self::ORDERS . '/edit';

        return [
            'sheet instead, sheet works' => [['on_failure' => 'sheet', 'sheet' => $link], 'sent', 'n3', 1],
            'sheet instead, sheet fails' => [['on_failure' => 'sheet', 'sheet' => $link], 'failed', 'n4', 1],
            'continue without saving' => [['on_failure' => 'continue'], 'sent', 'n3', 0],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cannotQueue')]
    public function test_S_132_10_an_order_that_cannot_be_queued_goes_to_the_fallback_sheet_or_says_so(array $c, string $sheetStatus, string $lands, int $appends): void
    {
        Queue::fake();
        Schema::drop('order_encodes');
        $outbox = $this->outbox($sheetStatus);

        $run = $this->go($this->config($c), $outbox);

        $this->assertSame($appends, $outbox->appends);
        $this->assertSame($lands, end($run->path)['node'] ?? null, json_encode($run->path));
        Queue::assertNothingPushed();
        if ($appends === 0) $this->assertStringContainsString('NOT saved', (string) $run->path[1]['note']);
    }

    public function test_S_132_11_with_replies_switched_off_nothing_is_encoded_or_written(): void
    {
        Queue::fake();
        config(['services.chatbot.replies_enabled' => false]);
        $outbox = $this->outbox();

        $run = $this->go($this->config(['also_sheet' => true, 'sheet' => self::ORDERS]), $outbox);

        Queue::assertNothingPushed();
        $this->assertSame(['n1', 'n2', 'n3'], array_column($run->path, 'node'));
    }

    public function test_S_132_12_the_order_event_is_the_same_as_for_a_sheet_step_order(): void
    {
        Queue::fake();
        $sheet = ['sheet' => self::ORDERS, 'tab' => 'All Orders', 'columns' => ['a', 'b'], 'counts_order' => true];

        $this->go($this->config(['counts_order' => true]), $this->outbox(), false, 'e1e1e1e1');
        $e = DB::table('chatbot_events')->where('type', 'order')->sole();
        DB::table('chatbot_events')->delete();
        $this->go($sheet, $this->outbox(), false, 'e1e1e1e1', [], 'sheet');
        $s = DB::table('chatbot_events')->where('type', 'order')->sole();

        $this->assertSame([$s->page_id, $s->psid, $s->flow_id, $s->node_id, $s->type], [$e->page_id, $e->psid, $e->flow_id, $e->node_id, $e->type]);
        $this->assertSame(array_keys(json_decode($s->detail, true)), array_keys(json_decode($e->detail, true)));

        // isang order kada customer kada 24 oras, gaya ng dati; at walang bilang kung hindi naka-tick ang "Counts as an order"
        $this->go($this->config(['counts_order' => true]), $this->outbox(), false, 'e2e2e2e2');
        $this->assertSame(1, DB::table('chatbot_events')->where('type', 'order')->count());
        DB::table('chatbot_events')->delete();
        $this->go($this->config(), $this->outbox(), false, 'e3e3e3e3');
        $this->assertSame(0, DB::table('chatbot_events')->where('type', 'order')->count());
    }

    public function test_S_132_13_a_graph_without_the_step_is_cleaned_exactly_as_before(): void
    {
        $graph = ['nodes' => [
            ['id' => 'n1', 'type' => 'start', 'x' => 1, 'y' => 2, 'config' => []],
            ['id' => 'n2', 'type' => 'sheet', 'x' => 3, 'y' => 4, 'config' => ['sheet' => self::ORDERS, 'tab' => 'All Orders', 'columns' => "a\nb", 'counts_order' => true, 'on_failure' => 'sheet']],
            ['id' => 'n3', 'type' => 'end', 'x' => 5, 'y' => 6, 'config' => []],
        ], 'edges' => [['from' => 'n1', 'port' => 'next', 'to' => 'n2'], ['from' => 'n2', 'port' => 'next', 'to' => 'n3']]];

        $this->assertSame(['sheet' => self::ORDERS, 'tab' => 'All Orders', 'columns' => ['a', 'b']], array_diff_key(FlowGraph::clean($graph)['nodes'][1]['config'], ['counts_order' => 1]));
        $this->assertTrue(FlowGraph::clean($graph)['nodes'][1]['config']['counts_order']);
        $this->assertNull(FlowGraph::publishProblems(FlowGraph::clean($graph)));
        $this->assertSame(['next', 'failed'], FlowGraph::ports(['type' => 'sheet']));
    }

    private function live(array $more = []): OrderEncode
    {
        return OrderEncode::create($more + [
            'page' => self::PAGE, 'psid' => 1, 'spreadsheet_id' => 'flow-node', 'tab' => 'All Orders', 'row_number' => null, 'mode' => 'live', 'status' => 'done',
            'input' => ['row' => [], 'fields' => ['date' => '10:15 23-10-2026', 'page' => self::PAGE]], 'outputs' => ['name' => 'Ana Cruz', 'province' => 'CEBU', 'final' => '✅', 'proceed' => true],
        ]);
    }

    public function test_S_132_14_the_compare_page_filters_live_orders_and_links_them_to_macro_output(): void
    {
        Carbon::setTestNow('2026-10-23 12:00:00');
        $macro = MacroOutput::create(['PAGE' => self::PAGE, 'fb_name' => 'Ana Cruz']);
        $live = $this->live(['macro_output_id' => $macro->id]);
        $shadow = $this->live(['mode' => 'shadow', 'spreadsheet_id' => self::ORDERS, 'row_number' => 77]);
        $ceo = $this->user('CEO');

        $this->actingAs($ceo)->get('/order-encode')->assertOk()->assertSee('#' . $shadow->id . ' · row 77', false)->assertDontSee('#' . $live->id . ' · ', false);

        $page = $this->actingAs($ceo)->get('/order-encode?mode=live')->assertOk();
        $page->assertSee('#' . $live->id . ' · live', false)->assertDontSee('row 77')
            ->assertSee('macro_output #' . $macro->id)->assertSee('Mode');

        // lahat ng mode = hindi lumalabas ang sobrang filter na value
        $this->actingAs($ceo)->get('/order-encode?mode=nonsense')->assertOk()->assertSee('row 77', false);
        Carbon::setTestNow();
    }
}

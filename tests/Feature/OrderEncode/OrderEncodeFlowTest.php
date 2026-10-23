<?php

namespace Tests\Feature\OrderEncode;

use App\Jobs\EncodeOrder;
use App\Models\ChatbotReply;
use App\Models\OrderEncode;
use App\Services\Chatbot\CustomerFields;
use App\Services\Chatbot\FlowEngine;
use App\Services\Chatbot\FlowGraph;
use App\Services\Chatbot\FlowRun;
use App\Services\Chatbot\Outbox;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/** Spec 130: ang sheet step ng flow at ang order encode (shadow at live). Walang Google at walang OpenAI. */
class OrderEncodeFlowTest extends OrderEncodeTestCase
{
    private const PSID = 7001;

    private function outbox(string $status = 'sent', ?string $range = "'All Orders'!A23823:I23823"): Outbox
    {
        return new class($status, $range) implements Outbox {
            public int $appends = 0;

            public function __construct(private string $status, private ?string $range)
            {
            }

            public function send(FlowRun $run, string $nodeId, array $messages): array
            {
                return ['status' => 'sent', 'error' => null];
            }

            public function appendRow(FlowRun $run, string $nodeId, string $spreadsheetId, string $tab, array $row): array
            {
                $this->appends++;

                return ['status' => $this->status, 'error' => $this->status === 'failed' ? 'boom' : null] + ($this->range ? ['range' => $this->range] : []);
            }
        };
    }

    private function go(Outbox $outbox, bool $test = false, string $page = self::PAGE): FlowRun
    {
        $graph = ['nodes' => [
            ['id' => 'n1', 'type' => 'start', 'x' => 0, 'y' => 0, 'config' => []],
            ['id' => 'n2', 'type' => 'sheet', 'x' => 0, 'y' => 0, 'config' => ['sheet' => self::ORDERS, 'tab' => 'All Orders',
                'columns' => "{{current_date}}\n{{page_name}}\n{{full_name}}\nstatic"]],
            ['id' => 'n3', 'type' => 'end', 'x' => 0, 'y' => 0, 'config' => []],
        ], 'edges' => [['from' => 'n1', 'port' => 'next', 'to' => 'n2'], ['from' => 'n2', 'port' => 'next', 'to' => 'n3'], ['from' => 'n2', 'port' => 'failed', 'to' => 'n3']]];
        $run = new FlowRun(5, FlowGraph::clean($graph), $page, '1001', [], new CustomerFields(1001, self::PSID), 13, 'hi', 'Ana Cruz', '2026-10-23 10:15', $test);

        return app(FlowEngine::class)->run($run, $outbox);
    }

    public function test_S_130_1_a_shadow_page_queues_one_encode_after_the_row_is_written_and_the_flow_is_unchanged(): void
    {
        Queue::fake();
        $this->configurePage('shadow');

        $with = $this->go($this->outbox());

        Queue::assertPushed(EncodeOrder::class, 1);
        Queue::assertPushed(EncodeOrder::class, fn (EncodeOrder $j) => $j->connection === 'database' && $j->queue === 'encode');
        $o = OrderEncode::sole();
        $this->assertSame([self::PAGE, 'shadow', self::ORDERS, 'All Orders', 23823, self::PSID, 13], [$o->page, $o->mode, $o->spreadsheet_id, $o->tab, $o->row_number, $o->psid, $o->chat_id]);
        $this->assertSame("'2026-10-23", $o->input['row'][0]);
        $this->assertSame('queued', $o->status);

        // ang flow ay pareho sa page na walang encode
        $without = $this->go($this->outbox(), false, 'Page Dos');
        $this->assertSame(array_column($without->path, 'node'), array_column($with->path, 'node'));
        $this->assertSame(array_column($without->sheetRows, 'status'), array_column($with->sheetRows, 'status'));
        Queue::assertPushed(EncodeOrder::class, 1);
    }

    public function test_S_130_2_a_page_with_encode_off_or_a_test_run_queues_nothing(): void
    {
        Queue::fake();
        $this->configurePage('off');
        $this->go($this->outbox());

        $this->configurePage('shadow', 'Page Tres');
        $this->go($this->outbox(), true, 'Page Tres');

        Queue::assertNothingPushed();
        $this->assertSame(0, OrderEncode::count());
    }

    public function test_S_130_3_a_failed_or_skipped_row_queues_nothing_and_a_row_without_a_number_is_not_encoded(): void
    {
        Queue::fake();
        $this->configurePage('shadow');

        $this->go($this->outbox('failed'));
        $this->go($this->outbox('skipped'));
        $this->go($this->outbox('sent', null));

        Queue::assertNothingPushed();
    }

    public function test_S_130_4_the_flow_never_fails_because_of_the_encode(): void
    {
        Queue::fake();
        $this->configurePage('shadow');
        Schema::drop('order_encodes');

        $run = $this->go($this->outbox());

        $this->assertSame(['n1', 'n2', 'n3'], array_column($run->path, 'node'));
        $this->assertSame('sent', $run->sheetRows[0]['status']);
        Queue::assertNothingPushed();
    }

    public function test_S_130_5_a_live_page_does_not_append_records_a_skipped_reply_and_continues(): void
    {
        Queue::fake();
        config(['services.chatbot.replies_enabled' => true]);
        $this->configurePage('live');
        $outbox = $this->outbox();

        $run = $this->go($outbox);

        $this->assertSame(0, $outbox->appends);
        $this->assertSame(['n1', 'n2', 'n3'], array_column($run->path, 'node'));
        $this->assertSame(['skipped', null, 'encoded in Likha'], [$run->sheetRows[0]['status'], $run->sheetRows[0]['error'], $run->sheetRows[0]['note']]);
        $reply = ChatbotReply::where('kind', 'sheet')->sole();
        $this->assertSame('skipped', $reply->status);
        $this->assertSame('encoded in Likha', $reply->content['note']);
        Queue::assertPushed(EncodeOrder::class, 1);
        $o = OrderEncode::sole();
        $this->assertSame(['live', null], [$o->mode, $o->row_number]);
    }

    public function test_S_130_6_a_live_page_falls_back_to_the_sheet_when_the_encode_cannot_be_queued(): void
    {
        Queue::fake();
        config(['services.chatbot.replies_enabled' => true]);
        $this->configurePage('live');
        Schema::drop('order_encodes');
        $outbox = $this->outbox();

        $run = $this->go($outbox);

        $this->assertSame(1, $outbox->appends);
        $this->assertSame('sent', $run->sheetRows[0]['status']);
        Queue::assertNothingPushed();
    }
}

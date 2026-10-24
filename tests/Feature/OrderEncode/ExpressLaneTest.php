<?php

namespace Tests\Feature\OrderEncode;

use App\Jobs\EncodeOrder;
use App\Jobs\RunEncodeAstra;
use App\Jobs\RunNightAstraRow;
use App\Jobs\RunSpotAstra;
use App\Models\BotcakeChat;
use App\Models\ChatbotFlow;
use App\Services\Chatbot\ChatbotAiQueue;
use App\Services\Chatbot\ChatbotPage;
use App\Services\Chatbot\ChatbotRunner;
use App\Services\OrderEncode\FlowHook;
use App\Services\OrderEncode\Runner;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Ang express lane: ang encode at ang Astra ng customer na naghihintay ay sa sariling queue (encode-live,
 * astra-live) na laging inuuna ng mga worker; ang shadow, import, backtest at night run ay nananatili sa encode / astra.
 */
class ExpressLaneTest extends UploadEncodeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([EncodeOrder::class, RunEncodeAstra::class, RunSpotAstra::class, RunNightAstraRow::class, \App\Jobs\ResumeChatbotAi::class]);
    }

    public function test_137_1_the_queue_names_come_from_the_config(): void
    {
        $this->assertSame(['encode' => 'encode', 'encode_live' => 'encode-live', 'astra' => 'astra', 'astra_live' => 'astra-live'], config('order_encode.queues'));
        $this->assertSame(['encode', 'encode-live', 'astra-live', 'astra-live', 'astra'],
            [(new EncodeOrder(1))->queue, (new EncodeOrder(1, true))->queue, (new RunEncodeAstra(1))->queue, (new RunSpotAstra(1))->queue, (new RunNightAstraRow(1))->queue]);
    }

    public function test_137_1_the_legacy_steps_live_encode_goes_on_encode_live(): void
    {
        $graph = $this->graph(['n2' => ['encode_order', $this->uploadConfig(['on_failure' => 'continue'])], 'n3' => ['end']], [['n1', 'next', 'n2'], ['n2', 'next', 'n3']]);

        $this->go($graph);

        Queue::assertPushedOn('encode-live', EncodeOrder::class);
        Queue::assertPushed(EncodeOrder::class, 1);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function sheetModes(): array
    {
        return ['a live Sheet step' => ['live', 'encode-live'], 'a shadow Sheet step' => ['shadow', 'encode']];
    }

    /** @dataProvider sheetModes */
    public function test_137_1_the_sheet_steps_live_encode_is_express_and_shadow_stays(string $mode, string $queue): void
    {
        $this->configurePage($mode);
        $run = new \App\Services\Chatbot\FlowRun(5, $this->uploadGraph(), self::PAGE, self::PAGE_ID, [], $this->fields(), 13, 'order po', 'Ana Cruz', '2026-10-23 10:15');
        $hook = app(FlowHook::class);

        $mode === 'live' ? $hook->live($run, self::ORDERS, 'All Orders', $this->orderRow()) : $hook->shadow($run, self::ORDERS, 'All Orders', $this->orderRow(), "'All Orders'!A5:I5");

        Queue::assertPushedOn($queue, EncodeOrder::class);
    }

    public function test_137_1_the_new_encode_goes_on_encode_live_and_its_astra_on_astra_live(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-23 10:15:00', 'Asia/Manila'));
        $calls = $this->astra(fix: true);
        $this->gpt(['verify' => ['province_ok' => true, 'city_ok' => true, 'barangay_ok' => false]]);
        $this->fields()->set('CXD', $this->details('Nowhere'));
        $flow = ChatbotFlow::create(['name' => 'Orders', 'graph' => $this->graph(['n2' => ['upload_macro', $this->uploadConfig()], 'n5' => ['encode_macro'], 'n3' => ['end']],
            [['n1', 'next', 'n2'], ['n2', 'next', 'n5'], ['n5', 'next', 'n3']])]);
        DB::table('chatbot_flows')->where('id', $flow->id)->update(['published_at' => now()]);
        app(ChatbotPage::class)->link(self::PAGE, $flow);
        $chat = BotcakeChat::create(['page_id' => self::PAGE_ID, 'page_name' => self::PAGE, 'psid' => (string) self::PSID, 'name' => 'Ana Cruz', 'message' => 'order po', 'payload' => [], 'received_at' => now()]);

        app(ChatbotRunner::class)->handle($chat->id);
        Queue::assertPushedOn('encode-live', EncodeOrder::class);
        $this->assertEqualsWithDelta(120, Carbon::parse(DB::table(ChatbotAiQueue::TABLE)->value('deadline_at'))->diffInSeconds(now(), true), 1); // ang default na "Wait up to"

        Queue::pushed(EncodeOrder::class)->first()->handle(app(Runner::class));
        Queue::assertPushedOn('astra-live', RunEncodeAstra::class);
        $this->assertSame([], $calls->getArrayCopy()); // si Astra ay sa sariling job, hindi sa encode worker
        Queue::assertNotPushed(RunSpotAstra::class);
        Carbon::setTestNow();
    }

    public function test_137_1_the_legacy_spot_astra_goes_on_astra_live(): void
    {
        $this->heldOrder();

        Queue::assertPushedOn('astra-live', RunSpotAstra::class);
    }
}

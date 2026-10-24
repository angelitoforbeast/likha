<?php

namespace Tests\Feature\OrderEncode;

use App\Jobs\EncodeOrder;
use App\Jobs\RunEncodeAstra;
use App\Models\AppSetting;
use App\Models\BotcakeChat;
use App\Models\ChatbotFlow;
use App\Models\ChatbotSequenceSubscription;
use App\Models\MacroOutput;
use App\Models\OrderEncode;
use App\Services\Chatbot\ChatbotAiQueue;
use App\Services\Chatbot\ChatbotPage;
use App\Services\Chatbot\ChatbotRunner;
use App\Services\Chatbot\CustomerFields;
use App\Services\Chatbot\FlowEngine;
use App\Services\Chatbot\FlowRun;
use App\Services\Chatbot\Outbox;
use App\Services\OrderEncode\FlowEncode;
use App\Services\OrderEncode\Runner;
use App\Services\OrderEncode\Settings;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Ang bagong "Encode order" step: ine-queue ang encode ng row (ng Upload to macro, o ang bukas na row ng customer) at tuloy
 * agad ang flow; ang resulta ay binabasa ng flow sa mga order_* variable (hal. sa sequence step pagkatapos ng delay).
 */
class EncodeMacroTest extends UploadEncodeTestCase
{
    private ?ChatbotFlow $flow = null;
    /** @var array<int, int> ang mga job na napatakbo na */
    private array $ran = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-23 10:15:00', 'Asia/Manila'));
        $this->fields()->set('PHONE', '09181112222'); // ang phone na isinagot ng GPT sa flow
        $this->fields()->set('CXD', $this->details());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Start → n2 Upload to macro → n5 Encode order → next n3 End; ang failed ng dalawa → n4 End. $nodes/$edges: mga node at
     * wire pa (ang next ng n5 ay sa $edges kapag naroon ang 'n5:next').
     */
    private function graphWith(array $encode = [], array $upload = [], array $nodes = [], array $edges = [], bool $withUpload = true): array
    {
        $list = ['n5' => ['encode_macro', $encode], 'n3' => ['end'], 'n4' => ['end']] + $nodes;
        if ($withUpload) $list = ['n2' => ['upload_macro', $this->uploadConfig($upload)]] + $list;
        $wires = $withUpload ? [['n1', 'next', 'n2'], ['n2', 'next', 'n5'], ['n2', 'failed', 'n4']] : [['n1', 'next', 'n5']];
        $taken = array_map(fn ($e) => $e[0] . ':' . $e[1], $edges);
        if (!in_array('n5:next', $taken, true)) $wires[] = ['n5', 'next', 'n3'];
        $wires[] = ['n5', 'failed', 'n4'];

        return $this->graph($list, array_merge($wires, $edges));
    }

    private function flowWith(array $encode = [], array $upload = [], array $nodes = [], array $edges = [], bool $withUpload = true): ChatbotFlow
    {
        $this->flow = ChatbotFlow::create(['name' => 'Orders', 'graph' => $this->graphWith($encode, $upload, $nodes, $edges, $withUpload)]);
        DB::table('chatbot_flows')->where('id', $this->flow->id)->update(['published_at' => now()]);
        app(ChatbotPage::class)->link(self::PAGE, $this->flow);

        return $this->flow->fresh();
    }

    private function says(string $message, string $name = 'Ana Cruz', int $psid = self::PSID): string
    {
        $chat = BotcakeChat::create(['page_id' => self::PAGE_ID, 'page_name' => self::PAGE, 'psid' => (string) $psid, 'name' => $name, 'message' => $message, 'payload' => [], 'received_at' => now()]);

        return app(ChatbotRunner::class)->handle($chat->id);
    }

    /** Ang takbo ng isang sequence step (walang chat, startedBy), gaya ng ChatbotSequences::tick. */
    private function sequenceStep(array $graph, ?Outbox $outbox = null): FlowRun
    {
        $run = new FlowRun(9, $graph, self::PAGE, self::PAGE_ID, [], $this->fields(), null, '', 'Ana Cruz', now()->format('Y-m-d H:i'));
        $run->startedBy = 'sequence Ask address step 1';

        return app(FlowEngine::class)->run($run, $outbox ?? $this->outbox());
    }

    /** Ang mga naka-queue na EncodeOrder at RunEncodeAstra na hindi pa napapatakbo. */
    private function encodeJobs(bool $astra = true): void
    {
        foreach (Queue::pushed(EncodeOrder::class) as $job) {
            if (in_array(spl_object_id($job), $this->ran, true)) continue;
            $this->ran[] = spl_object_id($job);
            $job->handle(app(Runner::class));
        }
        if (!$astra) return;
        foreach (Queue::pushed(RunEncodeAstra::class) as $job) {
            if (in_array(spl_object_id($job), $this->ran, true)) continue;
            $this->ran[] = spl_object_id($job);
            $job->handle(app(FlowEncode::class));
        }
    }

    /** @return array<string, string> ang mga order_* variable na nakikita ng isang takbo ng flow */
    private function vars(int $psid = self::PSID): array
    {
        // Ang flow na gumagamit ng order_* (sa Send): doon lang binabasa ang mga ito.
        $send = ['send', ['items' => [['type' => 'text', 'value' => '{{order_status}}']]]];
        $run = new FlowRun(9, $this->graph(['n2' => $send], [['n1', 'next', 'n2']]), self::PAGE, self::PAGE_ID, [], $this->fields($psid), null, '', 'Ana Cruz', now()->format('Y-m-d H:i'));
        $out = [];
        foreach ($run->vars() as $k => $v) {
            if (str_starts_with($k, 'order ')) $out[str_replace(' ', '_', $k)] = $v;
        }
        ksort($out);

        return $out;
    }

    private function lastRun(): array
    {
        return (array) json_decode((string) DB::table('chatbot_events')->where('type', 'run_ended')->orderByDesc('id')->value('detail'), true);
    }

    /** Ang huling node ng huling takbo (n3 = next, n4 = failed). */
    private function lastNode(): string
    {
        $path = $this->lastRun()['path'];

        return (string) end($path);
    }

    /** Ang bukas na row ng customer (galing sa naunang takbo o sa import), walang order_encodes. */
    private function openRow(array $over = []): MacroOutput
    {
        return $this->existing($over + ['TIMESTAMP' => '09:00 23-10-2026', 'all_user_input' => "FB NAME: Ana Cruz\norder po", 'extracted_details' => $this->details(), 'CXD' => $this->details()]);
    }

    public function test_S_143_1_main_flow_continues_at_once_and_the_encode_then_astra_run_on_the_express_lanes(): void
    {
        $calls = $this->astra(fix: true);
        $this->gpt(['verify' => ['province_ok' => true, 'city_ok' => true, 'barangay_ok' => false]]);
        $this->fields()->set('CXD', $this->details('Nowhere'));
        $this->addressSequence(); // ang setting ng 136 (legacy): ang bagong step ay hindi nag-a-auto-subscribe
        $this->flowWith();

        $this->assertSame('done', $this->says('order po'));

        $this->assertSame(['end', 'n3'], [$this->lastRun()['reason'], $this->lastNode()]);
        $this->assertSame(0, DB::table(ChatbotAiQueue::TABLE)->count());
        $this->assertNull($this->fields()->get(CustomerFields::AI));
        Queue::assertPushedOn('encode-live', EncodeOrder::class);
        Queue::assertNotPushed(RunEncodeAstra::class);

        $this->encodeJobs(astra: false);
        Queue::assertPushedOn('astra-live', RunEncodeAstra::class);
        $this->encodeJobs();

        $row = MacroOutput::sole();
        $this->assertSame([[(int) $row->id], 'PROCEED', 'BUNGAD'], [$calls->getArrayCopy(), $row->STATUS, $row->BARANGAY]);
        $this->assertSame(0, ChatbotSequenceSubscription::count());
        $this->assertSame([], array_filter($this->fields()->fields(), fn ($k) => str_starts_with($k, 'encode '), ARRAY_FILTER_USE_KEY));
    }

    public function test_S_143_2_a_sequence_step_uploads_and_encodes_without_waiting(): void
    {
        $run = $this->sequenceStep($this->graphWith(['astra' => false]));

        $this->assertSame(['next', 'n3'], [$this->step($run, 'n5')['port'], collect($run->path)->last()['node']]);
        Queue::assertPushedOn('encode-live', EncodeOrder::class);
        $this->encodeJobs();

        $row = MacroOutput::sole();
        $this->assertSame(['METRO-MANILA', 'QUEZON-CITY', 'BUNGAD', 'PROCEED'], [$row->PROVINCE, $row->CITY, $row->BARANGAY, $row->STATUS]);
        $this->assertNotSame('', trim((string) $row->ADDRESS));
    }

    public function test_S_143_3_without_an_upload_the_customers_open_row_is_encoded(): void
    {
        $row = $this->openRow();
        $this->flowWith(['astra' => false], withUpload: false);

        $this->says('order po');

        $this->assertSame('n3', $this->lastNode());
        $this->assertSame((int) $row->id, (int) OrderEncode::sole()->macro_output_id);
        $this->encodeJobs();
        $this->assertSame(['BUNGAD', 'PROCEED'], [$row->fresh()->BARANGAY, $row->fresh()->STATUS]);
    }

    /** @return array<string, array{0: ?array}> ang row ng customer na hindi bukas */
    public static function noOpenRow(): array
    {
        return [
            'no row at all' => [null],
            'only a CANNOT PROCEED row' => [['STATUS' => 'CANNOT PROCEED']],
            'another customer\'s open row' => [['botcake_psid' => '7002']],
            'an open row 25 h old' => [['TIMESTAMP' => '09:00 22-10-2026', 'ts_date' => '2026-10-22']],
        ];
    }

    /** @dataProvider noOpenRow */
    public function test_S_143_3_with_no_order_row_the_step_fails_with_a_note_and_queues_nothing(?array $row): void
    {
        if ($row) $this->openRow($row);
        $this->flowWith(withUpload: false);

        $this->says('order po');

        $this->assertSame('n4', $this->lastNode());
        $this->assertSame(['failed', 'No order row to encode'], array_values(array_intersect_key(collect($this->events('encode_macro'))->last(), ['result' => 1, 'error' => 1])));
        $this->assertSame(0, OrderEncode::count());
        Queue::assertNotPushed(EncodeOrder::class);
    }

    public function test_S_143_4_the_same_row_is_not_queued_twice_while_its_encode_is_queued_or_running(): void
    {
        $this->openRow(['extracted_details' => $this->details('Nowhere'), 'CXD' => $this->details('Nowhere')]);
        $this->flowWith(withUpload: false);
        $this->astra(fix: true);
        $this->gpt(['verify' => ['province_ok' => true, 'city_ok' => true, 'barangay_ok' => false]]);

        $this->says('order po');
        $this->says('order po ulit'); // queued pa
        Queue::assertPushed(EncodeOrder::class, 1);

        $this->encodeJobs(astra: false);
        $this->says('order po ulit'); // si Astra pa ang tumatakbo
        Queue::assertPushed(EncodeOrder::class, 1);
        $this->assertSame(1, OrderEncode::count());
        $this->assertSame('n3', $this->lastNode());
    }

    /** @return array<string, array{0: array}> */
    public static function finalRows(): array
    {
        return ['PROCEED' => [['STATUS' => 'PROCEED']], 'waybill' => [['waybill' => 'JT0001']]];
    }

    /** @dataProvider finalRows */
    public function test_S_143_5_a_final_row_is_not_encoded_and_the_flow_goes_on(array $final): void
    {
        $row = $this->existing($final);
        $before = $this->columns($row);
        $this->flowWith([], ['mode' => 'update']);

        $this->says('isa pa po');

        $this->assertSame($before, $this->columns($row));
        $this->assertSame('n3', $this->lastNode());
        Queue::assertNotPushed(EncodeOrder::class);
    }

    public function test_S_143_5_a_staff_edited_phone_or_name_is_kept(): void
    {
        $this->flowWith(['astra' => false]);
        $this->says('order po', 'anacruz88');
        MacroOutput::query()->update(['FULL NAME' => 'Ana Staff', 'edited_full_name' => true, 'HISTORICAL LOGS' => '2026-10-23 10:16:00|Joy|PHONE NUMBER|09181112222|09180000000', 'PHONE NUMBER' => '09180000000']);

        $this->encodeJobs();

        $row = MacroOutput::sole();
        $this->assertSame(['Ana Staff', '09180000000'], [$row->{'FULL NAME'}, $row->{'PHONE NUMBER'}]);
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> ang barangay ng chat, ang tanong ng Condition, ang port */
    public static function conditions(): array
    {
        return [
            'PROCEED row: order_status is PROCEED' => ['Bungad', 'order_status', 'PROCEED'],
            'PROCEED row: no hold reason' => ['Bungad', 'order_hold_reason', ''],
            'held row: address' => ['Nowhere', 'order_hold_reason', 'address'],
            'held row: status blank' => ['Nowhere', 'order_status', ''],
        ];
    }

    /** @dataProvider conditions */
    public function test_S_143_6_a_sequence_flows_condition_reads_the_order_variables(string $brgy, string $variable, string $value): void
    {
        if ($brgy !== 'Bungad') $this->gpt(['verify' => ['province_ok' => true, 'city_ok' => true, 'barangay_ok' => false]]);
        $this->fields()->set('CXD', $this->details($brgy));
        $this->flowWith(['astra' => false]);
        $this->says('order po');
        $this->encodeJobs();

        $run = $this->sequenceStep($this->graph([
            'n2' => ['condition', ['rows' => [['variable' => $variable, 'rule' => 'is', 'value' => $value]]]], 'n3' => ['end'], 'n4' => ['end'],
        ], [['n1', 'next', 'n2'], ['n2', 'row_1', 'n3'], ['n2', 'no', 'n4']]));

        $this->assertSame('row_1', $this->step($run, 'n2')['port']);
    }

    public function test_S_143_7_order_variables_fill_a_send_template(): void
    {
        $this->gpt(['verify' => ['province_ok' => true, 'city_ok' => true, 'barangay_ok' => false]]);
        $this->fields()->set('CXD', $this->details('Nowhere'));
        $this->flowWith(['astra' => false]);
        $this->says('order po');
        $this->encodeJobs();
        $outbox = new class implements Outbox {
            public array $sent = [];

            public function send(FlowRun $run, string $nodeId, array $messages): array
            {
                $this->sent[] = $messages;

                return ['status' => 'sent', 'error' => null];
            }

            public function appendRow(FlowRun $run, string $nodeId, string $spreadsheetId, string $tab, array $row): array
            {
                return ['status' => 'sent', 'error' => null];
            }
        };

        $this->sequenceStep($this->graph(['n2' => ['send', ['items' => [['type' => 'text', 'value' => 'Hold: {{order_hold_reason}} / status: {{ORDER STATUS}}.']]]], 'n3' => ['end']],
            [['n1', 'next', 'n2'], ['n2', 'continue', 'n3']]), $outbox);

        $this->assertStringContainsString('Hold: address / status: .', json_encode($outbox->sent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function test_S_143_8_order_encode_state_goes_from_queued_to_running_to_done(): void
    {
        $this->astra(fix: true);
        $this->gpt(['verify' => ['province_ok' => true, 'city_ok' => true, 'barangay_ok' => false]]);
        $this->fields()->set('CXD', $this->details('Nowhere'));
        $this->flowWith();
        $this->says('order po');
        $this->assertSame('queued', $this->vars()['order_encode_state']);

        $this->encodeJobs(astra: false);
        $this->assertSame('running', $this->vars()['order_encode_state']); // si Astra ay naka-queue pa

        $this->encodeJobs();
        $this->assertSame('done', $this->vars()['order_encode_state']);
    }

    public function test_S_143_8_order_encode_state_is_running_while_the_encode_job_runs(): void
    {
        $this->flowWith(['astra' => false]);
        $this->says('order po');
        $seen = [];
        Http::swap(new \Illuminate\Http\Client\Factory()); // ang fake ng setUp ang mauuna kung hindi
        Http::fake(['api.openai.com/*' => function () use (&$seen) {
            $seen[] = $this->vars()['order_encode_state'];

            return Http::response(Fixtures::chat(''));
        }]);

        $this->encodeJobs();

        $this->assertSame('running', $seen[0] ?? null);
    }

    public function test_S_143_8_a_failed_encode_job_says_failed_and_leaves_the_row_held(): void
    {
        $this->flowWith();
        $this->says('order po');

        (new EncodeOrder(OrderEncode::sole()->id))->failed(new \RuntimeException('worker died'));

        $this->assertSame('failed', $this->vars()['order_encode_state']);
        $this->assertSame([null, 'failed'], [MacroOutput::sole()->STATUS, OrderEncode::sole()->status]);
        $this->assertSame(0, DB::table(ChatbotAiQueue::TABLE)->count());
    }

    public function test_S_143_9_with_no_row_the_order_variables_are_empty(): void
    {
        $this->existing(['PAGE' => 'Another Page']);

        $vars = $this->vars();

        $this->assertSame(['order_address', 'order_barangay', 'order_checker', 'order_city', 'order_encode_state', 'order_hold_reason', 'order_name',
            'order_phone', 'order_province', 'order_row_id', 'order_status'], array_keys($vars));
        $this->assertSame(['none'], array_values(array_unique(array_filter($vars))));
    }

    public function test_S_143_9_a_flow_that_never_names_an_order_variable_reads_no_order_row(): void
    {
        $this->existing();
        $run = new FlowRun(9, $this->graph(['n2' => ['send', ['items' => [['type' => 'text', 'value' => 'Hi {{first_name}}, order po?']]]]], [['n1', 'next', 'n2']]),
            self::PAGE, self::PAGE_ID, [], $this->fields(), null, '', 'Ana Cruz', now()->format('Y-m-d H:i'));
        DB::enableQueryLog();

        $vars = $run->vars();

        $this->assertArrayNotHasKey('order status', $vars);
        $this->assertSame([], array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'macro_output') || str_contains($q['query'], 'order_encodes')));
    }

    public function test_S_143_8_an_encode_stuck_for_30_minutes_counts_as_failed_and_can_be_queued_again(): void
    {
        $this->openRow();
        $this->flowWith(withUpload: false);
        $this->says('order po'); // ang job ay hindi kailanman tumakbo (nawala ang worker)

        Carbon::setTestNow(now()->addMinutes(31));
        $this->assertSame('failed', $this->vars()['order_encode_state']);
        $this->says('order po ulit');

        Queue::assertPushed(EncodeOrder::class, 2);
    }

    public function test_S_143_9_the_order_variables_carry_the_rows_values(): void
    {
        $row = $this->existing(['STATUS' => 'PROCEED', 'PROVINCE' => 'METRO-MANILA', 'CITY' => 'QUEZON-CITY', 'BARANGAY' => 'BUNGAD', 'ADDRESS' => 'Blk 5',
            'APP SCRIPT CHECKER' => '✅']);

        $this->assertSame([
            'order_address' => 'Blk 5', 'order_barangay' => 'BUNGAD', 'order_checker' => '✅', 'order_city' => 'QUEZON-CITY', 'order_encode_state' => 'none',
            'order_hold_reason' => '', 'order_name' => 'Ana Cruz', 'order_phone' => '09171234567', 'order_province' => 'METRO-MANILA',
            'order_row_id' => (string) $row->id, 'order_status' => 'PROCEED',
        ], $this->vars());
    }

    /** @return array<string, array{0: array, 1: string}> ang row (walang encode ng flow), ang hold reason */
    public static function rowHolds(): array
    {
        $place = ['PROVINCE' => 'METRO-MANILA', 'CITY' => 'QUEZON-CITY', 'BARANGAY' => 'BUNGAD', 'ADDRESS' => 'Blk 5'];

        return [
            'not encoded yet' => [[], ''],
            'R5 in AI ANALYZE' => [$place + ['AI ANALYZE' => 'HOLD R5: Possible duplicate: 12', 'APP SCRIPT CHECKER' => 'TO FIX'], 'duplicate'],
            'R4 in AI ANALYZE' => [$place + ['AI ANALYZE' => 'HOLD R4: two barangays', 'APP SCRIPT CHECKER' => 'TO FIX'], 'address'],
            'checker not ✅' => [$place + ['AI ANALYZE' => 'check the city', 'APP SCRIPT CHECKER' => 'City'], 'address'],
            'CANNOT PROCEED, checker ✅' => [$place + ['STATUS' => 'CANNOT PROCEED', 'APP SCRIPT CHECKER' => '✅'], 'other'],
        ];
    }

    /** @dataProvider rowHolds */
    public function test_S_143_6_a_row_without_a_flow_encode_gets_its_hold_reason_from_ai_analyze_and_the_checker(array $row, string $reason): void
    {
        $this->existing($row);

        $this->assertSame($reason, $this->vars()['order_hold_reason']);
    }

    public function test_S_143_10_an_old_config_with_wait_runs_without_waiting(): void
    {
        $this->flowWith(['wait' => 30, 'astra' => false]);

        $this->says('order po');

        $this->assertSame(['end', 'n3'], [$this->lastRun()['reason'], $this->lastNode()]);
        $this->assertArrayNotHasKey('wait', $this->flow->fresh()->graph['nodes'][2]['config']);
        Queue::assertPushed(EncodeOrder::class, 1);
    }

    public function test_S_143_12_a_2359_row_is_read_at_0005(): void
    {
        $row = $this->existing(['TIMESTAMP' => '23:59 22-10-2026', 'ts_date' => '2026-10-22']);
        Carbon::setTestNow(Carbon::parse('2026-10-23 00:05:00', 'Asia/Manila'));

        $this->assertSame((string) $row->id, $this->vars()['order_row_id']);
    }

    public function test_S_143_12_a_row_older_than_7_days_is_not_read(): void
    {
        $this->existing(['TIMESTAMP' => '09:00 15-10-2026', 'ts_date' => '2026-10-15']);

        $this->assertSame('', $this->vars()['order_row_id']);
    }

    public function test_S_143_13_the_test_tab_queues_nothing(): void
    {
        $graph = $this->graph(['n2' => ['upload_macro', $this->uploadConfig()], 'n5' => ['encode_macro'], 'n3' => ['end']],
            [['n1', 'next', 'n2'], ['n2', 'next', 'n5'], ['n5', 'next', 'n3']]);

        $run = $this->go($graph, test: true);

        $this->assertSame(['next', 'Test: would encode'], [$this->step($run, 'n5')['port'], $this->step($run, 'n5')['note']]);
        $this->assertSame([0, 0, 0], [MacroOutput::count(), OrderEncode::count(), DB::table(ChatbotAiQueue::TABLE)->count()]);
        Queue::assertNothingPushed();
    }

    /** @return array<string, array{0: array, 1: ?string}> */
    public static function astraOff(): array
    {
        return ['the box is off' => [['astra' => false], null], 'the kill switch is off' => [['astra' => true], 'off']];
    }

    /** @dataProvider astraOff */
    public function test_S_143_14_no_astra_when_the_box_or_the_kill_switch_is_off(array $config, ?string $switch): void
    {
        if ($switch) AppSetting::set(Settings::SPOT_ASTRA, $switch);
        $calls = $this->astra(fix: true);
        $this->gpt(['verify' => ['province_ok' => true, 'city_ok' => true, 'barangay_ok' => false]]);
        $this->fields()->set('CXD', $this->details('Nowhere'));
        $this->flowWith($config);

        $this->says('order po');
        $this->encodeJobs();

        $this->assertSame([], $calls->getArrayCopy());
        Queue::assertNotPushed(RunEncodeAstra::class);
        $this->assertSame(['', 'address', 'done'], [$this->vars()['order_status'], $this->vars()['order_hold_reason'], $this->vars()['order_encode_state']]);
    }

    /** @return array<string, array{0: string, 1: array, 2: string, 3: string}> cxd phone, GPT name answer, ang phone at pangalan sa dulo */
    public static function chatIdentity(): array
    {
        return [
            'chat phone and name replace the uploaded ones' => ['09171234567', ['full_name' => 'Ana Cruz', 'address_line1' => 'Blk 5'], '09171234567', 'Ana Cruz'],
            'invalid chat phone and no name keep the uploaded ones' => ['12345', ['full_name' => '', 'address_line1' => 'Blk 5'], '09181112222', 'anacruz88'],
        ];
    }

    /** @dataProvider chatIdentity */
    public function test_S_137_15_the_chats_valid_phone_and_name_replace_the_uploaded_ones(string $chatPhone, array $nameAnswer, string $phone, string $name): void
    {
        $this->gpt(['name_addr' => $nameAnswer]);
        $this->fields()->set('CXD', $this->details(name: $nameAnswer['full_name'], phone: $chatPhone));
        $this->flowWith(['astra' => false]);

        $this->says('order po', 'anacruz88');
        $this->encodeJobs();

        $row = MacroOutput::sole();
        $this->assertSame([$phone, $name], [$row->{'PHONE NUMBER'}, $row->{'FULL NAME'}]);
        $logs = (string) $row->{'HISTORICAL LOGS'};
        $this->assertSame($phone !== '09181112222', str_contains($logs, '|Likha encode|PHONE NUMBER|09181112222|09171234567'));
        $this->assertSame($name !== 'anacruz88', str_contains($logs, '|Likha encode|FULL NAME|anacruz88|Ana Cruz'));
    }

    /** @return array<string, array{0: array, 1: array, 2: ?array, 3: string}> encode outputs, row, Astra, hold reason */
    public static function holdReasons(): array
    {
        $row = ['PROVINCE' => 'METRO MANILA', 'CITY' => 'QUEZON CITY', 'BARANGAY' => 'BUNGAD', 'ADDRESS' => 'Blk 5', 'FULL NAME' => 'Ana Cruz', 'PHONE NUMBER' => '09171234567'];
        $ok = ['final' => '✅', 'r' => '✅', 'hold' => null];
        $astraOk = ['status' => 'partial', 'final_code' => '✅'];

        return [
            'address: rule R4' => [['hold' => ['rule' => 'R4']] + $ok, $row, null, 'address'],
            'address: checker not ✅' => [['final' => 'Barangay'] + $ok, $row, null, 'address'],
            'address: Astra not ✅' => [$ok, $row, ['status' => 'partial', 'final_code' => 'City'], 'address'],
            'address: blank barangay' => [$ok, ['BARANGAY' => ''] + $row, null, 'address'],
            'duplicate: R5' => [['hold' => ['rule' => 'R5']] + $ok, $row, null, 'duplicate'],
            'name' => [$ok, ['FULL NAME' => ' '] + $row, $astraOk, 'name'],
            'phone' => [$ok, ['PHONE NUMBER' => '0917'] + $row, $astraOk, 'phone'],
            'price' => [['r' => 'Price and Quantity'] + $ok, $row, $astraOk, 'price'],
            'other' => [$ok, $row, $astraOk, 'other'],
            'address and name: address first' => [['final' => 'City'] + $ok, ['FULL NAME' => ''] + $row, null, 'address'],
            'name and phone: name first' => [$ok, ['FULL NAME' => '', 'PHONE NUMBER' => ''] + $row, $astraOk, 'name'],
        ];
    }

    /** @dataProvider holdReasons */
    public function test_S_137_17_each_hold_gives_its_reason_and_the_first_in_order_wins(array $outputs, array $row, ?array $astra, string $reason): void
    {
        $this->assertSame($reason, FlowEncode::holdReason($outputs, $row, $astra));
    }

    /** @return array<string, array{0: ?int, 1: string, 2: string}> ang PSID ng ibang row na may parehong phone, status, hold */
    public static function duplicates(): array
    {
        return ['another customer' => [7002, '', 'duplicate'], 'the same customer' => [self::PSID, 'PROCEED', '']];
    }

    /** @dataProvider duplicates */
    public function test_S_137_17_r5_holds_only_for_another_customers_order(int $otherPsid, string $status, string $reason): void
    {
        AppSetting::set(Settings::DUPLICATE_HOLD, 'on');
        $this->existing(['botcake_psid' => (string) $otherPsid, 'fb_name' => 'Someone', 'TIMESTAMP' => '08:00 23-10-2026', 'STATUS' => 'PROCEED']);
        $this->flowWith(['astra' => false], ['mode' => 'insert']);

        $this->says('order po');
        $this->encodeJobs();

        $this->assertSame([$status, $reason], [$this->vars()['order_status'], $this->vars()['order_hold_reason']]);
    }

    /** @return array<string, array{0: ?string, 1: string, 2: string}> ang setting, status, hold */
    public static function duplicateHold(): array
    {
        return ['the hold is off (default)' => [null, 'PROCEED', ''], 'the hold is on' => ['on', '', 'duplicate']];
    }

    /** @dataProvider duplicateHold */
    public function test_S_144_3_another_customers_order_proceeds_unless_the_hold_is_on_and_an_r5_hold_queues_no_astra(?string $switch, string $status, string $reason): void
    {
        if ($switch) AppSetting::set(Settings::DUPLICATE_HOLD, $switch);
        $calls = $this->astra(fix: true);
        $this->existing(['botcake_psid' => '7002', 'fb_name' => 'Someone', 'TIMESTAMP' => '08:00 23-10-2026', 'STATUS' => 'PROCEED']);
        $this->flowWith(['astra' => true], ['mode' => 'insert']);

        $this->says('order po');
        $this->encodeJobs();

        Queue::assertNotPushed(RunEncodeAstra::class);
        $this->assertSame([], $calls->getArrayCopy());
        $this->assertSame([$status, $reason], [$this->vars()['order_status'], $this->vars()['order_hold_reason']]);
    }

    public function test_S_137_18_a_held_order_is_fixed_by_astra_on_the_same_row_and_the_variables_carry_the_final_values(): void
    {
        $calls = $this->astra(fix: true);
        $this->gpt(['verify' => ['province_ok' => true, 'city_ok' => true, 'barangay_ok' => false]]);
        $this->fields()->set('CXD', $this->details('Nowhere'));
        $this->flowWith();

        $this->says('order po');
        $this->encodeJobs();

        $row = MacroOutput::sole();
        $this->assertSame([[(int) $row->id], 'PROCEED'], [$calls->getArrayCopy(), $row->STATUS]);
        $this->assertSame(['PROCEED', 'BUNGAD', (string) $row->id], [$this->vars()['order_status'], $this->vars()['order_barangay'], $this->vars()['order_row_id']]);
        $this->assertSame(['spot', 'Likha encode'], [DB::table('ai_checker_logs')->sole()->source, DB::table('ai_checker_logs')->sole()->user_name]);
    }

    /** @return array<string, array{0: string}> */
    public static function notReady(): array
    {
        return ['no key' => ['key'], 'no address file' => ['file']];
    }

    /** @dataProvider notReady */
    public function test_S_137_21_an_encode_that_cannot_be_queued_fails_at_once(string $missing): void
    {
        if ($missing === 'key') config(['services.openai.order_encode_key' => '']);
        else @unlink($this->addressFile);
        $this->flowWith();

        $this->says('order po');

        $this->assertSame('n4', $this->lastNode());
        Queue::assertNotPushed(EncodeOrder::class);
    }

    public function test_S_137_29_the_reply_updates_the_same_row_and_re_encodes_the_order_with_the_reply(): void
    {
        $this->gpt(['verify' => ['province_ok' => true, 'city_ok' => true, 'barangay_ok' => false]]);
        $this->fields()->set('CXD', $this->details('Nowhere'));
        $this->flowWith(['astra' => false], ['mode' => 'update', 'input' => '{{last_input}}']);
        $this->says('order po, Blk 5 QC');
        $this->encodeJobs();
        $this->assertSame('address', $this->vars()['order_hold_reason']);

        $this->gpt();
        $this->fields()->set('CXD', $this->details());
        Carbon::setTestNow(now()->addMinutes(5));
        $this->says('Bungad po');
        $this->encodeJobs();

        $row = MacroOutput::sole();
        $this->assertSame('PROCEED', $row->STATUS);
        $this->assertStringContainsString("order po, Blk 5 QC\nBungad po", (string) $row->all_user_input);
        $this->assertSame("order po, Blk 5 QC\nBungad po", OrderEncode::latest('id')->first()->input['fields']['input']);
        $this->assertSame(['PROCEED', (string) $row->id], [$this->vars()['order_status'], $this->vars()['order_row_id']]);
    }

    public function test_S_137_30_n_tilde_and_emoji_stay_intact_in_the_variables(): void
    {
        $this->gpt(['name_addr' => ['full_name' => 'Ma. Peña 😀', 'address_line1' => 'Blk 5']]);
        $this->fields()->set('CXD', $this->details(name: 'Ma. Peña 😀'));
        $this->flowWith(['astra' => false]);

        $this->says('order po 🙏', 'anacruz88');
        $this->encodeJobs();

        $this->assertSame('Ma. Peña 😀', $this->vars()['order_name']);
        $this->assertSame('Ma. Peña 😀', MacroOutput::sole()->{'FULL NAME'});
    }

    /** @return array<string, array{0: string}> */
    public static function oddInputs(): array
    {
        return ['empty' => [''], '20,000 characters' => [str_repeat('a', 19990) . ' order po']];
    }

    /** @dataProvider oddInputs */
    public function test_S_137_31_empty_or_very_long_input_ends_cleanly(string $input): void
    {
        $this->fields()->set('CXD', '');
        $this->flowWith(['astra' => false], ['input' => $input]);

        $this->says('order po');
        $this->encodeJobs();

        $this->assertSame('n3', $this->lastNode());
        $this->assertLessThanOrEqual(8000, mb_strlen(OrderEncode::sole()->input['fields']['input']));
        $this->assertContains($this->vars()['order_encode_state'], ['done', 'failed']);
    }
}

<?php

namespace Tests\Feature\OrderEncode;

use App\Jobs\EncodeOrder;
use App\Jobs\ResumeChatbotAi;
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
use App\Services\OrderEncode\FlowEncode;
use App\Services\OrderEncode\Runner;
use App\Services\OrderEncode\Settings;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/** Spec 137 B: ang bagong "Encode order" step: ine-encode ang row ng Upload to macro, hinihintay ng flow, at ang mga variable. */
class EncodeMacroTest extends UploadEncodeTestCase
{
    private ?ChatbotFlow $flow = null;
    /** @var array<int, int> ang mga EncodeOrder job na napatakbo na */
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
     * Start → n2 Upload to macro → n5 Encode order → next n3 End; ang failed ng dalawa → n4 End. $extra: mga node at wire pa
     * (ang next ng n5 ay sa $extra kapag naroon ang 'n5:next').
     */
    private function flowWith(array $encode = [], array $upload = [], array $nodes = [], array $edges = [], bool $withUpload = true): ChatbotFlow
    {
        $list = ['n5' => ['encode_macro', $encode], 'n3' => ['end'], 'n4' => ['end']] + $nodes;
        if ($withUpload) $list = ['n2' => ['upload_macro', $this->uploadConfig($upload)]] + $list;
        $wires = $withUpload ? [['n1', 'next', 'n2'], ['n2', 'next', 'n5'], ['n2', 'failed', 'n4']] : [['n1', 'next', 'n5']];
        $taken = array_map(fn ($e) => $e[0] . ':' . $e[1], $edges);
        if (!in_array('n5:next', $taken, true)) $wires[] = ['n5', 'next', 'n3'];
        $wires[] = ['n5', 'failed', 'n4'];

        $this->flow = ChatbotFlow::create(['name' => 'Orders', 'graph' => $this->graph($list, array_merge($wires, $edges))]);
        DB::table('chatbot_flows')->where('id', $this->flow->id)->update(['published_at' => now()]);
        app(ChatbotPage::class)->link(self::PAGE, $this->flow);

        return $this->flow->fresh();
    }

    private function says(string $message, string $name = 'Ana Cruz', int $psid = self::PSID): string
    {
        $chat = BotcakeChat::create(['page_id' => self::PAGE_ID, 'page_name' => self::PAGE, 'psid' => (string) $psid, 'name' => $name, 'message' => $message, 'payload' => [], 'received_at' => now()]);

        return app(ChatbotRunner::class)->handle($chat->id);
    }

    /** Ang mga naka-queue na EncodeOrder na hindi pa napapatakbo. */
    private function encodeJobs(): void
    {
        foreach (Queue::pushed(EncodeOrder::class) as $job) {
            if (in_array(spl_object_id($job), $this->ran, true)) continue;
            $this->ran[] = spl_object_id($job);
            $job->handle(app(Runner::class));
        }
    }

    /** Ang huling resume job, pinatakbo. */
    private function resume(): string
    {
        return app(ChatbotRunner::class)->resumeAi(Queue::pushed(ResumeChatbotAi::class)->last()->ticket);
    }

    /** @return array<string, string> ang mga encode_* variable ng customer */
    private function vars(int $psid = self::PSID): array
    {
        $out = [];
        foreach ((new CustomerFields((int) self::PAGE_ID, $psid))->fields() as $k => $v) {
            if (str_starts_with($k, 'encode ')) $out[str_replace(' ', '_', $k)] = $v;
        }
        ksort($out);

        return $out;
    }

    private function ended(): string
    {
        return (string) json_decode((string) DB::table('chatbot_events')->where('type', 'run_ended')->orderByDesc('id')->value('detail'), true)['reason'];
    }

    /** Ang huling node ng huling takbo (n3 = next, n4 = failed). */
    private function lastNode(): string
    {
        $path = json_decode((string) DB::table('chatbot_events')->where('type', 'run_ended')->orderByDesc('id')->value('detail'), true)['path'];

        return (string) end($path);
    }

    private function ticket(): ?object
    {
        return DB::table(ChatbotAiQueue::TABLE)->orderByDesc('id')->first();
    }

    public function test_S_137_13_27_no_upload_in_the_run_fails_at_once_and_clears_the_previous_orders_variables(): void
    {
        foreach (['encode_province' => 'OLD PROV', 'encode_name' => 'Old Name', 'encode_proceed' => 'yes'] as $k => $v) $this->fields()->set($k, $v);
        $this->flowWith(withUpload: false);

        $this->says('order po');

        $this->assertSame(['encode_hold_reason' => 'error', 'encode_proceed' => 'no'], $this->vars());
        $this->assertSame('n4', $this->lastNode());
        $this->assertNull($this->ticket());
        Queue::assertNotPushed(EncodeOrder::class);
    }

    public function test_S_137_14_a_proceed_order_waits_for_the_encode_then_continues_on_next_with_the_rows_values(): void
    {
        $calls = $this->astra(fix: true);
        $this->flowWith();

        $this->says('order po');

        $this->assertSame('waiting for the encode', $this->ended());
        $this->assertSame(['encoding', 'order-encode'], [$this->ticket()->status, $this->ticket()->model]);
        $this->assertSame([], $this->vars());

        $this->encodeJobs();
        $row = MacroOutput::sole();
        $this->assertSame(['METRO-MANILA', 'QUEZON-CITY', 'BUNGAD', 'PROCEED'], [$row->PROVINCE, $row->CITY, $row->BARANGAY, $row->STATUS]);
        $this->assertNotSame('', trim((string) $row->ADDRESS));

        $this->assertSame('done', $this->resume());
        $v = $this->vars();
        $this->assertSame(['yes', '', (string) $row->id, (string) $row->BARANGAY, (string) $row->ADDRESS], [$v['encode_proceed'], $v['encode_hold_reason'] ?? '', $v['encode_row_id'], $v['encode_barangay'], $v['encode_address']]);
        $this->assertSame('n3', $this->lastNode());
        $this->assertSame([], $calls->getArrayCopy());
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

    public function test_S_137_16_a_staff_edited_phone_or_name_is_kept(): void
    {
        $this->flowWith(['astra' => false]);
        $this->says('order po', 'anacruz88');
        MacroOutput::query()->update(['FULL NAME' => 'Ana Staff', 'edited_full_name' => true, 'HISTORICAL LOGS' => '2026-10-23 10:16:00|Joy|PHONE NUMBER|09181112222|09180000000', 'PHONE NUMBER' => '09180000000']);

        $this->encodeJobs();

        $row = MacroOutput::sole();
        $this->assertSame(['Ana Staff', '09180000000'], [$row->{'FULL NAME'}, $row->{'PHONE NUMBER'}]);
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

    /** @return array<string, array{0: ?int, 1: string, 2: string}> ang PSID ng ibang row na may parehong phone, proceed, hold */
    public static function duplicates(): array
    {
        return ['another customer' => [7002, 'no', 'duplicate'], 'the same customer' => [self::PSID, 'yes', '']];
    }

    /** @dataProvider duplicates */
    public function test_S_137_17_r5_holds_only_for_another_customers_order(int $otherPsid, string $proceed, string $reason): void
    {
        $this->existing(['botcake_psid' => (string) $otherPsid, 'fb_name' => 'Someone', 'TIMESTAMP' => '08:00 23-10-2026', 'STATUS' => 'PROCEED']);
        $this->flowWith(['astra' => false], ['mode' => 'insert']);

        $this->says('order po');
        $this->encodeJobs();
        $this->resume();

        $this->assertSame([$proceed, $reason], [$this->vars()['encode_proceed'], $this->vars()['encode_hold_reason'] ?? '']);
    }

    public function test_S_137_18_a_held_order_is_fixed_by_astra_on_the_same_row_and_the_variables_carry_the_final_values(): void
    {
        $calls = $this->astra(fix: true);
        $this->gpt(['verify' => ['province_ok' => true, 'city_ok' => true, 'barangay_ok' => false]]);
        $this->fields()->set('CXD', $this->details('Nowhere'));
        $this->flowWith();

        $this->says('order po');
        $this->encodeJobs();
        $this->resume();

        $row = MacroOutput::sole();
        $this->assertSame([[(int) $row->id], 'PROCEED'], [$calls->getArrayCopy(), $row->STATUS]);
        $this->assertSame(['yes', 'BUNGAD', (string) $row->id], [$this->vars()['encode_proceed'], $this->vars()['encode_barangay'], $this->vars()['encode_row_id']]);
        $this->assertSame(['spot', 'Likha encode'], [DB::table('ai_checker_logs')->sole()->source, DB::table('ai_checker_logs')->sole()->user_name]);
    }

    /** @return array<string, array{0: array, 1: ?string}> */
    public static function astraOff(): array
    {
        return ['the box is off' => [['astra' => false], null], 'the kill switch is off' => [['astra' => true], 'off']];
    }

    /** @dataProvider astraOff */
    public function test_S_137_19_no_astra_when_the_box_or_the_kill_switch_is_off(array $config, ?string $switch): void
    {
        if ($switch) AppSetting::set(Settings::SPOT_ASTRA, $switch);
        $calls = $this->astra(fix: true);
        $this->gpt(['verify' => ['province_ok' => true, 'city_ok' => true, 'barangay_ok' => false]]);
        $this->fields()->set('CXD', $this->details('Nowhere'));
        $this->flowWith($config);

        $this->says('order po');
        $this->encodeJobs();
        $this->resume();

        $this->assertSame([], $calls->getArrayCopy());
        $this->assertSame(['no', 'address'], [$this->vars()['encode_proceed'], $this->vars()['encode_hold_reason']]);
    }

    public function test_S_137_20_the_owners_condition_subscribes_the_address_sequence_once_and_the_new_blocks_never_auto_subscribe(): void
    {
        $seq = $this->addressSequence(); // ang setting ng 136 (legacy): hindi ito ginagamit ng mga bagong step
        $this->gpt(['verify' => ['province_ok' => true, 'city_ok' => true, 'barangay_ok' => false]]);
        $this->fields()->set('CXD', $this->details('Nowhere'));
        $this->flowWith(['astra' => false], nodes: [
            'n6' => ['condition', ['rows' => [['variable' => 'encode_hold_reason', 'rule' => 'is', 'value' => 'address']]]],
            'n7' => ['sequence', ['action' => 'subscribe', 'sequence_id' => $seq->id]],
        ], edges: [['n5', 'next', 'n6'], ['n6', 'row_1', 'n7'], ['n6', 'no', 'n3']]);

        $this->says('order po');
        $this->encodeJobs();
        $this->assertSame(0, ChatbotSequenceSubscription::count());
        $this->resume();

        $this->assertSame(1, ChatbotSequenceSubscription::count());
        $this->assertSame('flow', ChatbotSequenceSubscription::sole()->enrolled_via);
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
        $this->assertSame(['error', 'no', (string) MacroOutput::sole()->id], [$this->vars()['encode_hold_reason'], $this->vars()['encode_proceed'], $this->vars()['encode_row_id']]);
        $this->assertNull($this->ticket());
        Queue::assertNotPushed(EncodeOrder::class);
    }

    public function test_S_137_22_a_failed_encode_job_resumes_on_failed_and_leaves_the_row_held(): void
    {
        $this->flowWith();
        $this->says('order po');

        (new EncodeOrder(OrderEncode::sole()->id))->failed(new \RuntimeException('worker died'));
        $this->assertSame('done', $this->resume());

        $this->assertSame('n4', $this->lastNode());
        $this->assertSame(['error', 'no'], [$this->vars()['encode_hold_reason'], $this->vars()['encode_proceed']]);
        $this->assertSame([null, 'failed'], [MacroOutput::sole()->STATUS, OrderEncode::sole()->status]);
    }

    public function test_S_137_23_past_the_wait_the_run_resumes_on_failed_and_a_late_result_only_updates_the_row(): void
    {
        $this->flowWith(['wait' => 30]);
        $this->says('order po');

        Carbon::setTestNow(now()->addSeconds(31));
        ChatbotAiQueue::sweep();
        $this->resume();
        $this->assertSame(['n4', 'error'], [$this->lastNode(), $this->vars()['encode_hold_reason']]);

        $this->encodeJobs(); // ang huling resulta
        $this->assertSame('PROCEED', MacroOutput::sole()->STATUS);
        Queue::assertPushed(ResumeChatbotAi::class, 1);
        $this->assertSame(['resumed', 'error'], [$this->ticket()->status, $this->vars()['encode_hold_reason']]);
    }

    public function test_S_137_24_an_astra_error_continues_on_next_with_the_encodes_result(): void
    {
        $this->astra(throw: true);
        $this->gpt(['verify' => ['province_ok' => true, 'city_ok' => true, 'barangay_ok' => false]]);
        $this->fields()->set('CXD', $this->details('Nowhere'));
        $this->flowWith();

        $this->says('order po');
        $this->encodeJobs();
        $this->resume();

        $this->assertSame(['n3', 'no', 'address'], [$this->lastNode(), $this->vars()['encode_proceed'], $this->vars()['encode_hold_reason']]);
    }

    public function test_S_137_24_astra_slower_than_the_wait_continues_on_next_with_the_encodes_result(): void
    {
        // Si Astra ay natapos pagkatapos ng deadline: ang sweep ay nagtuloy na sa resulta ng encode; ang ayos ni Astra ay sa row lang.
        $this->app->instance(\App\Services\AiCheckerRowRunner::class, new class extends \App\Services\AiCheckerRowRunner {
            public function __construct()
            {
            }

            public function run(int $id, string $engine, ?string $host, array $ctx): array
            {
                Carbon::setTestNow(now()->addSeconds(200));
                ChatbotAiQueue::sweep();
                MacroOutput::whereKey($id)->update(['STATUS' => 'PROCEED']);

                return ['result' => ['status' => 'fixed', 'final_code' => '✅'], 'log_id' => null];
            }
        });
        $this->gpt(['verify' => ['province_ok' => true, 'city_ok' => true, 'barangay_ok' => false]]);
        $this->fields()->set('CXD', $this->details('Nowhere'));
        $this->flowWith();

        $this->says('order po');
        $this->encodeJobs();
        $this->resume();

        Queue::assertPushed(ResumeChatbotAi::class, 1);
        $this->assertSame(['n3', 'no', 'address', 'PROCEED'], [$this->lastNode(), $this->vars()['encode_proceed'], $this->vars()['encode_hold_reason'], MacroOutput::sole()->STATUS]);
    }

    public function test_S_137_25_two_racing_resume_jobs_resume_once(): void
    {
        $this->flowWith(['astra' => false]);
        $this->says('order po');
        $this->encodeJobs();

        $this->assertSame('done', $this->resume());
        $this->assertSame('gone', $this->resume());
        $this->assertSame(2, DB::table('chatbot_events')->where('type', 'run_started')->count());
    }

    public function test_S_137_26_messages_while_waiting_are_held_and_run_after_the_resume(): void
    {
        $this->flowWith(['astra' => false]);
        $this->says('order po');

        $this->assertSame('locked', $this->says('nasaan na po'));
        $this->assertSame(1, OrderEncode::count());

        $this->encodeJobs();
        $this->resume();
        $held = BotcakeChat::where('message', 'nasaan na po')->sole();
        $this->assertSame('done', app(ChatbotRunner::class)->handle($held->id));
        $this->assertSame(2, OrderEncode::count()); // ang hinintay na chat ay tumakbo pagkatapos
    }

    /** @return array<string, array{0: array}> */
    public static function finalRows(): array
    {
        return ['PROCEED' => [['STATUS' => 'PROCEED']], 'waybill' => [['waybill' => 'JT0001']]];
    }

    /** @dataProvider finalRows */
    public function test_S_137_28_a_final_row_is_not_encoded_and_says_proceed_yes(array $final): void
    {
        $row = $this->existing($final);
        $before = $this->columns($row);
        $this->flowWith([], ['mode' => 'update']);

        $this->says('isa pa po');

        $this->assertSame($before, $this->columns($row));
        $this->assertSame(['yes', (string) $row->id, 'n3'], [$this->vars()['encode_proceed'], $this->vars()['encode_row_id'], $this->lastNode()]);
        $this->assertNull($this->ticket());
    }

    public function test_S_137_29_the_reply_updates_the_same_row_and_re_encodes_the_order_with_the_reply(): void
    {
        $this->gpt(['verify' => ['province_ok' => true, 'city_ok' => true, 'barangay_ok' => false]]);
        $this->fields()->set('CXD', $this->details('Nowhere'));
        $this->flowWith(['astra' => false], ['mode' => 'update', 'input' => '{{last_input}}']);
        $this->says('order po, Blk 5 QC');
        $this->encodeJobs();
        $this->resume();
        $this->assertSame('address', $this->vars()['encode_hold_reason']);

        $this->gpt();
        $this->fields()->set('CXD', $this->details());
        Carbon::setTestNow(now()->addMinutes(5));
        $this->says('Bungad po');
        $this->encodeJobs();
        $this->resume();

        $row = MacroOutput::sole();
        $this->assertSame('PROCEED', $row->STATUS);
        $this->assertStringContainsString("order po, Blk 5 QC\nBungad po", (string) $row->all_user_input);
        $this->assertSame("order po, Blk 5 QC\nBungad po", OrderEncode::latest('id')->first()->input['fields']['input']);
        $this->assertSame(['yes', (string) $row->id], [$this->vars()['encode_proceed'], $this->vars()['encode_row_id']]);
    }

    public function test_S_137_30_n_tilde_and_emoji_stay_intact_in_the_variables(): void
    {
        $this->gpt(['name_addr' => ['full_name' => 'Ma. Peña 😀', 'address_line1' => 'Blk 5']]);
        $this->fields()->set('CXD', $this->details(name: 'Ma. Peña 😀'));
        $this->flowWith(['astra' => false]);

        $this->says('order po 🙏', 'anacruz88');
        $this->encodeJobs();
        $this->resume();

        $this->assertSame('Ma. Peña 😀', $this->vars()['encode_name']);
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
        $this->assertSame('done', $this->resume());

        $this->assertContains($this->lastNode(), ['n3', 'n4']);
        $this->assertLessThanOrEqual(8000, mb_strlen(OrderEncode::sole()->input['fields']['input']));
        $this->assertSame('resumed', $this->ticket()->status);
    }

    /** @return array<string, array{0: string}> */
    public static function staleResumes(): array
    {
        return ['the flow changed' => ['flow'], 'the bot paused' => ['paused']];
    }

    /** @dataProvider staleResumes */
    public function test_S_137_32_a_changed_flow_or_paused_bot_drops_the_resume_cleanly(string $how): void
    {
        $this->flowWith(['astra' => false]);
        $this->says('order po');
        $this->encodeJobs();
        if ($how === 'flow') $this->flow->update(['graph' => $this->graph(['n2' => ['end']], [['n1', 'next', 'n2']])]);
        else $this->fields()->set(CustomerFields::BOT_PAUSED, '1');

        $this->assertSame('dropped', $this->resume());

        $this->assertSame(1, DB::table('chatbot_events')->where('type', 'ai_dropped')->count());
        $this->assertSame([], $this->vars());
        $this->assertSame('PROCEED', MacroOutput::sole()->STATUS); // ang row ay na-encode pa rin
        if ($how === 'flow') $this->assertNull((new CustomerFields((int) self::PAGE_ID, self::PSID))->get(CustomerFields::AI));
    }

    public function test_S_137_33_the_test_tab_sets_sample_variables_and_queues_nothing(): void
    {
        $graph = $this->graph(['n2' => ['upload_macro', $this->uploadConfig()], 'n5' => ['encode_macro'], 'n3' => ['end']],
            [['n1', 'next', 'n2'], ['n2', 'next', 'n5'], ['n5', 'next', 'n3']]);

        $run = $this->go($graph, test: true);

        $this->assertSame(['next', 'Test: would encode'], [$this->step($run, 'n5')['port'], $this->step($run, 'n5')['note']]);
        $this->assertSame(['address', 'no'], [$this->vars()['encode_hold_reason'], $this->vars()['encode_proceed']]);
        $this->assertSame([0, 0, 0], [MacroOutput::count(), OrderEncode::count(), DB::table(ChatbotAiQueue::TABLE)->count()]);
        Queue::assertNothingPushed();
    }
}

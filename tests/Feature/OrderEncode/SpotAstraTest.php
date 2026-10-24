<?php

namespace Tests\Feature\OrderEncode;

use App\Jobs\RunSpotAstra;
use App\Models\AppSetting;
use App\Models\ChatbotSequenceSubscription;
use App\Models\MacroOutput;
use App\Models\OrderEncode;
use App\Services\OrderEncode\RowLock;
use App\Services\OrderEncode\Runner;
use App\Services\OrderEncode\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/** Spec 136 D: ang live na order na hindi PROCEED ay inaayos agad ni Astra; ang lock ng row; ang kill switch. */
class SpotAstraTest extends LiveRowTestCase
{
    public function test_S_136_21_a_held_live_order_queues_one_astra_run_at_once(): void
    {
        $o = $this->heldOrder();

        Queue::assertPushed(RunSpotAstra::class, 1);
        Queue::assertPushed(RunSpotAstra::class, fn ($job) => $job->orderEncodeId === $o->id);
        $this->assertSame('queued', $o->input['astra']['status']);
    }

    public function test_S_136_22_28_astra_proceeds_the_row_with_its_log_and_no_question(): void
    {
        $this->addressSequence();
        $o = $this->heldOrder();
        $calls = $this->astra(fix: true);

        $this->runAstra($o);

        $this->assertSame([[(int) $o->macro_output_id], 'PROCEED'], [$calls->getArrayCopy(), MacroOutput::sole()->STATUS]);
        $log = DB::table('ai_checker_logs')->sole();
        $this->assertSame(['spot', 'Likha encode', 'fixed', (int) $o->macro_output_id], [$log->source, $log->user_name, $log->outcome, (int) $log->macro_output_id]);
        $this->assertSame(0, ChatbotSequenceSubscription::count());
        $this->assertSame('fixed', $o->fresh()->input['astra']['status']);
    }

    public function test_S_136_23_an_encode_that_already_proceeds_queues_no_astra(): void
    {
        $this->liveOrder($this->details());

        $this->assertSame('PROCEED', MacroOutput::sole()->STATUS);
        Queue::assertNotPushed(RunSpotAstra::class);
    }

    public function test_S_136_24_a_shadow_order_makes_no_row_and_queues_no_astra(): void
    {
        $o = OrderEncode::create(['page' => self::PAGE, 'psid' => self::PSID, 'spreadsheet_id' => self::ORDERS, 'tab' => 'All Orders', 'row_number' => 55, 'mode' => 'shadow', 'status' => 'queued',
            'input' => ['row' => $this->withCxd($this->details('Nowhere'))]]);

        app(Runner::class)->run($o);

        $this->assertSame(['done', 0], [$o->fresh()->status, MacroOutput::count()]);
        Queue::assertNotPushed(RunSpotAstra::class);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function astraFailures(): array
    {
        return [
            'engine error' => [['throw' => true]],
            'key rejected (no retry)' => [['error' => ['kind' => 'http', 'status' => 401]]],
            'transient on the last try' => [['error' => ['kind' => 'exception']], 3],
        ];
    }

    /** @dataProvider astraFailures */
    public function test_S_136_25_46_an_astra_failure_leaves_the_row_held_and_still_asks_for_the_address(array $how, int $attempt = 1): void
    {
        $seq = $this->addressSequence();
        $o = $this->heldOrder();
        $this->astra(...$how);

        $job = $this->runAstra($o, $attempt);

        $job->assertNotReleased();
        $this->assertNull(MacroOutput::sole()->STATUS);
        $this->assertSame('failed', $o->fresh()->input['astra']['status']);
        $this->assertSame(1, ChatbotSequenceSubscription::where('sequence_id', $seq->id)->where('psid', self::PSID)->count());
    }

    public function test_S_136_46_a_transient_astra_error_is_retried_within_the_limit(): void
    {
        $o = $this->heldOrder();
        $this->astra(error: ['kind' => 'exception']);

        $this->runAstra($o)->assertReleased(RunSpotAstra::RETRY_DELAY_S);
        $this->assertSame('queued', $o->fresh()->input['astra']['status']);
    }

    public function test_S_136_26_astra_waits_while_another_run_holds_the_row(): void
    {
        $o = $this->heldOrder();
        $calls = $this->astra(fix: true);
        $lock = Cache::lock(RowLock::key((int) $o->macro_output_id), 60);
        $lock->get();

        $this->runAstra($o)->assertReleased(RunSpotAstra::BUSY_DELAY_S);

        $this->assertSame([], $calls->getArrayCopy());
        $this->assertNull(MacroOutput::sole()->STATUS);
        $lock->release();
    }

    public function test_S_136_27_with_the_kill_switch_off_no_astra_and_the_encode_hold_decides_the_question(): void
    {
        AppSetting::set(Settings::SPOT_ASTRA, 'off');
        $this->addressSequence();

        $o = $this->heldOrder();

        Queue::assertNotPushed(RunSpotAstra::class);
        $this->assertSame(1, ChatbotSequenceSubscription::count());
        $this->assertArrayNotHasKey('astra', $o->fresh()->input);
    }

    public function test_S_136_28_likha_encode_lines_are_not_counted_as_staff_activity(): void
    {
        $logs = "2026-10-23 10:00:00|Likha encode|BARANGAY||BUNGAD\n2026-10-23 11:00:00|Maria Staff|CITY|QC|QUEZON-CITY";
        $read = new \ReflectionMethod(\App\Http\Controllers\Encoder\Checker1SummaryController::class, 'extractHistoricalUserDatesInRangeFlexible');

        $users = $read->invoke(null, $logs, \Carbon\Carbon::parse('2026-10-23 00:00'), \Carbon\Carbon::parse('2026-10-23 23:59'), 'Asia/Manila');

        $this->assertSame(['Maria Staff'], array_keys($users));
    }
}

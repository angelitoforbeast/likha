<?php

namespace Tests\Feature\OrderEncode;

use App\Models\MacroOutput;
use App\Models\OrderEncode;
use App\Services\OrderEncode\Runner;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Spec 136 A, B, F, G: isang macro_output row kada customer kada araw; ang final na row ay hindi na ginagalaw. */
class OneRowPerDayTest extends LiveRowTestCase
{
    public function test_S_136_1_a_first_order_makes_one_row_with_the_order_time_day_psid_and_page(): void
    {
        $o = $this->liveOrder($this->details());

        $m = MacroOutput::sole();
        $this->assertSame(['10:15 23-10-2026', '2026-10-23', (string) self::PSID, self::PAGE, 'PROCEED'], [$m->TIMESTAMP, substr((string) $m->ts_date, 0, 10), $m->botcake_psid, $m->PAGE, $m->STATUS]);
        $this->assertSame($m->id, $o->macro_output_id);
    }

    public function test_S_136_2_an_open_row_is_updated_by_a_later_order_of_the_same_customer(): void
    {
        $first = $this->heldOrder([0 => '10:00 23-10-2026']);
        $this->assertNull(MacroOutput::sole()->STATUS);

        $second = $this->liveOrder($this->details(), [0 => '14:00 23-10-2026']);

        $m = MacroOutput::sole();
        $this->assertSame([$m->id, $m->id], [$first->macro_output_id, $second->macro_output_id]);
        $this->assertSame(['✅', 'PROCEED', '10:00 23-10-2026'], [$m->{'APP SCRIPT CHECKER'}, $m->STATUS, $m->TIMESTAMP]);
    }

    public function test_S_136_3_two_orders_of_one_customer_in_the_same_minute_make_one_row(): void
    {
        $this->liveOrder($this->details());
        $second = $this->liveOrder($this->details());

        $this->assertSame(1, MacroOutput::count());
        $this->assertSame('done', $second->status);
    }

    public function test_S_136_4_two_people_with_the_same_fb_name_in_the_same_minute_get_their_own_rows(): void
    {
        $this->heldOrder([], 7001);
        $this->liveOrder($this->details(), [], 7002);

        $rows = MacroOutput::orderBy('id')->get();
        $this->assertSame([['7001', null], ['7002', null]], $rows->map(fn ($m) => [$m->botcake_psid, $m->{'HISTORICAL LOGS'}])->all()); // walang update sa row ng iba
    }

    public function test_S_136_4a_an_order_without_psid_and_fb_name_makes_its_own_row_next_to_a_strangers_open_row(): void
    {
        $stranger = $this->existing(['botcake_psid' => '9999', 'fb_name' => 'Other', 'PHONE NUMBER' => '09180000000']);
        $before = $this->columns($stranger);

        $o = $this->liveOrder($this->details(), [2 => ''], 0);

        $this->assertSame(2, MacroOutput::count());
        $this->assertNotSame($stranger->id, $o->macro_output_id);
        $this->assertSame($before, $this->columns($stranger));
    }

    public function test_S_136_5_the_same_phone_on_two_pages_makes_two_rows(): void
    {
        $this->liveOrder($this->details(), [], 7001, null, 'Page Uno');
        $this->liveOrder($this->details(), [], 7001, null, 'Page Dos');

        $this->assertSame(['Page Uno', 'Page Dos'], MacroOutput::orderBy('id')->pluck('PAGE')->all());
    }

    public function test_S_136_6_an_imported_row_without_psid_is_linked_by_page_and_fb_name(): void
    {
        $import = $this->existing(['botcake_psid' => null]);

        $o = $this->liveOrder($this->details());

        $this->assertSame(1, MacroOutput::count());
        $this->assertSame($import->id, $o->macro_output_id);
        $this->assertSame('PROCEED', $import->fresh()->STATUS);
    }

    public function test_S_136_7_a_non_ascii_fb_name_twice_is_one_row_with_the_name_intact(): void
    {
        $name = 'Niño Peña 😀';
        $this->heldOrder([2 => $name]);
        $this->liveOrder($this->details(), [2 => $name]);

        $this->assertSame($name, MacroOutput::sole()->fb_name);
    }

    public function test_S_136_8_r5_runs_after_the_link_and_never_counts_the_customers_own_row(): void
    {
        \App\Models\AppSetting::set(\App\Services\OrderEncode\Settings::DUPLICATE_HOLD, 'on');
        $first = $this->heldOrder();
        $second = $this->liveOrder($this->details());

        $this->assertSame($first->macro_output_id, $second->macro_output_id);
        $this->assertNull($second->outputs['hold']);
        $this->assertSame('PROCEED', MacroOutput::sole()->STATUS);

        // ibang customer (ibang PSID), parehong phone: R5 pa rin
        $other = $this->liveOrder($this->details('Bungad', 'Ben Reyes'), [2 => 'Ben Reyes'], 7002);
        $this->assertSame('R5', $other->outputs['hold']['rule']);
    }

    public function test_S_136_9_a_second_run_waits_for_the_customer_lock_and_then_updates_the_same_row(): void
    {
        $lock = Cache::lock('order-encode:customer:' . md5(self::PAGE . '|' . self::PSID), 60);
        $lock->get();
        try {
            $this->liveOrder($this->details());
            $this->fail('the job must wait for the lock and retry');
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
        }
        $this->assertSame(0, MacroOutput::count());
        $this->assertSame('queued', OrderEncode::sole()->status);
        $lock->release();

        app(Runner::class)->run(OrderEncode::sole());
        $this->liveOrder($this->details());

        $this->assertSame(1, MacroOutput::count());
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function finalRows(): array
    {
        return [
            'PROCEED' => [['STATUS' => 'PROCEED']],
            'courier waybill, status blank' => [['waybill' => 'JT0001234567']],
        ];
    }

    /** @dataProvider finalRows */
    public function test_S_136_10_11_12_a_final_row_is_never_changed_and_the_later_order_is_not_accepted(array $final): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $row = $this->existing($final + ['BARANGAY' => 'PROJECT 7', 'all_user_input' => 'first order']);
        $before = $this->columns($row);

        $o = $this->liveOrder($this->details(), [0 => '18:00 23-10-2026']);

        $this->assertSame($before, $this->columns($row));
        $this->assertSame(1, MacroOutput::count());
        $this->assertSame('done', $o->status);
        $this->assertSame(['second order not accepted: row ' . $row->id], $o->input['live_notes']);
        $this->assertNull($o->macro_output_id);
        $event = DB::table('chatbot_events')->where('type', 'encode_order')->sole();
        $this->assertSame(['not_accepted', 'second order not accepted: row ' . $row->id], [json_decode($event->detail, true)['result'], json_decode($event->detail, true)['error']]);
        // .12: walang Astra, walang sequence, walang sheet
        \Illuminate\Support\Facades\Queue::assertNothingPushed();
        $this->assertSame([], $this->google->appended);
        $this->assertSame([], $this->tasks);
    }

    public function test_S_136_10_the_not_accepted_note_is_shown_on_the_order_encode_page(): void
    {
        Carbon::setTestNow('2026-10-23 12:00:00');
        $row = $this->existing(['STATUS' => 'PROCEED']);
        $o = $this->liveOrder($this->details());

        $this->actingAs($this->user('CEO'))->get('/order-encode?mode=live')->assertOk()
            ->assertSee('#' . $o->id . ' · live', false)->assertSee('second order not accepted: row ' . $row->id);
    }

    public function test_S_136_13_a_cannot_proceed_row_does_not_block_a_new_order_that_day(): void
    {
        $this->existing(['STATUS' => 'CANNOT PROCEED']);

        $o = $this->liveOrder($this->details());

        $this->assertSame(2, MacroOutput::count());
        $this->assertSame('PROCEED', MacroOutput::find($o->macro_output_id)->STATUS);
    }

    public function test_S_136_14_16_an_update_keeps_staff_edits_and_the_identity_columns(): void
    {
        $row = $this->existing(['BARANGAY' => 'PROJECT 7', 'edited_barangay' => true, 'CITY' => 'Q.C.', 'PROVINCE' => '',
            'HISTORICAL LOGS' => '2026-10-23 09:30:00|Maria Staff|CITY|QC|Q.C.']);

        $o = $this->liveOrder($this->details(), [0 => '11:00 23-10-2026', 2 => 'Ana C.'], self::PSID);
        $m = $row->fresh();

        $this->assertSame($row->id, $o->macro_output_id);
        $this->assertSame(['PROJECT 7', 'Q.C.', 'METRO-MANILA'], [$m->BARANGAY, $m->CITY, $m->PROVINCE]);
        $this->assertNull($m->STATUS, 'a kept staff place differs from the encode: no PROCEED');
        $this->assertSame(['09:00 23-10-2026', '2026-10-23', self::PAGE, 'Ana Cruz', (string) self::PSID],
            [$m->TIMESTAMP, substr((string) $m->ts_date, 0, 10), $m->PAGE, $m->fb_name, $m->botcake_psid]);
        $this->assertStringContainsString('|Likha encode|PROVINCE||METRO-MANILA', $m->{'HISTORICAL LOGS'});
        $this->assertStringNotContainsString('|Likha encode|BARANGAY|', $m->{'HISTORICAL LOGS'});
    }

    public function test_S_136_42_a_re_encode_whose_gpt_answers_come_back_blank_never_blanks_the_row(): void
    {
        $row = $this->existing(['ADDRESS' => 'Blk 9 Lot 1 Purok 2', 'PROVINCE' => 'METRO-MANILA']);
        \Illuminate\Support\Facades\Http::swap(new \Illuminate\Http\Client\Factory(app('events')));
        \Illuminate\Support\Facades\Http::fake(['api.openai.com/v1/chat/completions' => \Illuminate\Support\Facades\Http::response(Fixtures::chat('')),
            'api.openai.com/v1/responses' => \Illuminate\Support\Facades\Http::response(Fixtures::responses('{}'))]);

        $o = $this->liveOrder($this->details());

        $this->assertSame($row->id, $o->macro_output_id);
        $this->assertSame(['Blk 9 Lot 1 Purok 2', 'METRO-MANILA'], [$row->fresh()->ADDRESS, $row->fresh()->PROVINCE]);
    }

    public function test_S_136_41_a_failed_first_encode_writes_the_fallback_sheet_and_no_row(): void
    {
        @unlink($this->addressFile);
        $o = OrderEncode::create(['page' => self::PAGE, 'psid' => self::PSID, 'spreadsheet_id' => self::ORDERS, 'tab' => 'All Orders', 'mode' => 'live', 'status' => 'queued',
            'input' => ['row' => $this->withCxd($this->details()), 'node' => 'n2', 'sheet_written' => false]]);

        app(Runner::class)->run($o);

        $this->assertSame([0, 1, 'failed'], [MacroOutput::count(), count($this->google->appended), $o->fresh()->status]);
    }

    public function test_S_136_42_a_failed_re_encode_leaves_the_row_unchanged_and_writes_no_sheet(): void
    {
        $row = $this->existing(['BARANGAY' => '']);
        $before = $this->columns($row);
        @unlink($this->addressFile);
        $o = OrderEncode::create(['page' => self::PAGE, 'psid' => self::PSID, 'spreadsheet_id' => self::ORDERS, 'tab' => 'All Orders', 'mode' => 'live', 'status' => 'queued',
            'input' => ['row' => $this->withCxd($this->details()), 'node' => 'n2', 'sheet_written' => false]]);

        app(Runner::class)->run($o);

        $o->refresh();
        $this->assertSame([$before, [], 'failed', $row->id], [$this->columns($row), $this->google->appended, $o->status, $o->macro_output_id]);
        $this->assertStringContainsString('The re-encode left row ' . $row->id . ' unchanged.', $o->error);
    }

    public function test_S_136_47_48_49_an_open_row_from_before_midnight_is_updated_and_keeps_its_day(): void
    {
        $this->heldOrder([0 => '23:59 23-10-2026']);
        Carbon::setTestNow(Carbon::parse('2026-10-24 00:01', 'Asia/Manila'));

        $this->liveOrder($this->details(), [0 => '00:01 24-10-2026']);

        $m = MacroOutput::sole();
        $this->assertSame(['23:59 23-10-2026', '2026-10-23', 'PROCEED'], [$m->TIMESTAMP, substr((string) $m->ts_date, 0, 10), $m->STATUS]);
    }

    /** @return array<string, array{0: string, 1: string, 2: int}> */
    public static function newDayRows(): array
    {
        return [
            'PROCEED at 23:59, order at 00:01' => ['PROCEED', '00:01 24-10-2026', 2],
            'CANNOT PROCEED at 23:59, order at 00:01' => ['CANNOT PROCEED', '00:01 24-10-2026', 2],
            'open row 25 hours old' => ['', '00:59 25-10-2026', 2],
            'open row 20 hours old' => ['', '19:59 24-10-2026', 1],
        ];
    }

    /** @dataProvider newDayRows */
    public function test_S_136_50_52_a_final_or_old_row_from_yesterday_gives_a_new_row_for_the_new_day(string $status, string $at, int $rows): void
    {
        $this->existing(['TIMESTAMP' => '23:59 23-10-2026', 'STATUS' => $status ?: null]);

        $this->liveOrder($this->details(), [0 => $at]);

        $this->assertSame($rows, MacroOutput::count());
        if ($rows === 2) $this->assertSame(substr(implode('-', array_reverse(explode('-', substr($at, 6)))), 0, 10), substr((string) MacroOutput::orderByDesc('id')->first()->ts_date, 0, 10));
    }

    public function test_S_136_51_55_the_day_comes_from_the_order_time_in_manila_even_after_midnight_and_in_utc(): void
    {
        config(['app.timezone' => 'UTC']);
        date_default_timezone_set('UTC');
        Carbon::setTestNow(Carbon::parse('2026-10-23 16:30', 'UTC')); // 00:30 ng 24 sa Manila

        try {
            $this->liveOrder($this->details(), [0 => '2026-10-23 23:59:00']);
        } finally {
            date_default_timezone_set('Asia/Manila');
        }

        $m = MacroOutput::sole();
        $this->assertSame(['23:59 23-10-2026', '2026-10-23'], [$m->TIMESTAMP, substr((string) $m->ts_date, 0, 10)]);
    }

    public function test_S_136_53_r5_after_midnight_does_not_count_the_customers_own_row_from_yesterday(): void
    {
        $this->heldOrder([0 => '23:59 23-10-2026']);
        $reply = $this->liveOrder($this->details(), [0 => '00:01 24-10-2026']);

        $this->assertNull($reply->outputs['hold']);
    }
}

<?php

namespace Tests\Feature\OrderEncode;

use App\Models\MacroOutput;
use App\Models\OrderEncode;
use App\Services\OrderEncode\Runner;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Ang "Upload to macro" step: ang hilaw na order row (Insert / Update), walang encode. */
class UploadMacroTest extends UploadEncodeTestCase
{
    public function test_S_137_2_insert_writes_the_raw_row_and_a_second_insert_the_same_day_writes_a_second_row(): void
    {
        $this->fields()->set('PHONE', '0918 111 2222');
        $this->fields()->set('CXD', $this->details());

        $run = $this->go($this->uploadGraph(), 'order po, isa');

        $row = MacroOutput::sole();
        $this->assertSame(['10:15 23-10-2026', '2026-10-23', self::PAGE, 'Ana Cruz', 'Ana Cruz', '9181112222', (string) self::PSID, null],
            [$row->TIMESTAMP, substr((string) $row->ts_date, 0, 10), $row->PAGE, $row->fb_name, $row->{'FULL NAME'}, $row->{'PHONE NUMBER'}, $row->botcake_psid, $row->STATUS]);
        $this->assertStringContainsString('order po, isa', (string) $row->all_user_input);
        $this->assertSame(['998', $this->details(), null, null], [(string) $row->COD, $row->CXD, $row->PROVINCE, $row->ADDRESS]);
        $this->assertSame('next', $this->step($run, 'n2')['port']);
        $this->assertSame([(int) $row->id, 'uploaded'], [(int) OrderEncode::sole()->macro_output_id, OrderEncode::sole()->status]);

        $this->go($this->uploadGraph(), 'isa pa po', '2026-10-23 11:00');
        $this->assertSame(2, MacroOutput::count());
    }

    /** @return array<string, array{0: string, 1: string}> ang oras ng bukas na row at ng sagot */
    public static function openRowTimes(): array
    {
        return [
            'same morning' => ['09:00 23-10-2026', '2026-10-23 10:15'],
            'across midnight' => ['23:59 23-10-2026', '2026-10-24 00:01'],
            '23 h 59 min later' => ['10:16 22-10-2026', '2026-10-23 10:15'],
        ];
    }

    /** @dataProvider openRowTimes */
    public function test_S_137_3_11_update_appends_this_orders_messages_to_the_customers_open_row(string $rowTime, string $at): void
    {
        $day = substr($rowTime, 12, 4) . '-' . substr($rowTime, 9, 2) . '-' . substr($rowTime, 6, 2);
        $this->go($this->uploadGraph(), "hello\nBlk 5 Bungad QC", '2026-10-21 08:00'); // ang naunang takbo ng customer (ibang araw)
        $open = MacroOutput::sole();
        $open->update(['TIMESTAMP' => $rowTime, 'ts_date' => $day]);

        $run = $this->go($this->uploadGraph(['mode' => 'update']), "hello\nBlk 5 Bungad QC\nsa may simbahan po", $at);

        $row = MacroOutput::sole();
        $this->assertSame([$rowTime, $day], [$row->TIMESTAMP, substr((string) $row->ts_date, 0, 10)]);
        $this->assertStringContainsString("Blk 5 Bungad QC\nsa may simbahan po", (string) $row->all_user_input);
        $this->assertSame(1, substr_count((string) $row->all_user_input, 'Blk 5 Bungad QC'));
        $this->assertStringContainsString('|Likha encode|all_user_input|', (string) $row->{'HISTORICAL LOGS'});
        $this->assertSame('next', $this->step($run, 'n2')['port']);
        $this->assertSame((int) $row->id, (int) OrderEncode::latest('id')->first()->macro_output_id);
    }

    /** @return array<string, array{0: array}> */
    public static function finalRows(): array
    {
        return ['PROCEED' => [['STATUS' => 'PROCEED']], 'waybill' => [['waybill' => 'JT0001']]];
    }

    /** @dataProvider finalRows */
    public function test_S_137_4_update_never_touches_a_final_row(array $final): void
    {
        $row = $this->existing($final + ['all_user_input' => 'old order']);
        $before = $this->columns($row);

        $run = $this->go($this->uploadGraph(['mode' => 'update']), 'second order po');

        $this->assertSame($before, $this->columns($row));
        $this->assertSame(1, MacroOutput::count());
        $this->assertSame(['next', 'second order not accepted: row ' . $row->id], [$this->step($run, 'n2')['port'], $this->step($run, 'n2')['note']]);
        $this->assertSame('not_accepted', $this->runEvents($run, 'upload_macro')[0]['detail']['result']);
        $this->assertSame((int) $row->id, (int) OrderEncode::sole()->macro_output_id); // ang Encode order ng takbo ay makakakita nito (proceed yes)
    }

    public function test_S_137_5_update_without_a_row_inserts_or_skips_as_set(): void
    {
        $this->go($this->uploadGraph(['mode' => 'update', 'if_not_found' => 'insert']));
        $this->assertSame(1, MacroOutput::count());

        MacroOutput::query()->delete();
        OrderEncode::query()->delete();
        $run = $this->go($this->uploadGraph(['mode' => 'update', 'if_not_found' => 'skip']));

        $this->assertSame([0, 0], [MacroOutput::count(), OrderEncode::count()]);
        $this->assertSame('next', $this->step($run, 'n2')['port']);
        $this->assertCount(1, $this->runEvents($run, 'upload_skipped'));
    }

    /** @return array<string, array{0: array}> mga row na hindi "bukas" para sa update */
    public static function notOpenRows(): array
    {
        return [
            'only a CANNOT PROCEED row' => [['STATUS' => 'CANNOT PROCEED']],
            'open row 25 h old' => [['TIMESTAMP' => '09:15 22-10-2026', 'ts_date' => '2026-10-22']],
        ];
    }

    /** @dataProvider notOpenRows */
    public function test_S_137_6_11_update_treats_a_cannot_proceed_or_25_hour_old_row_as_not_found(array $row): void
    {
        $old = $this->existing($row);
        $before = $this->columns($old);

        $this->go($this->uploadGraph(['mode' => 'update', 'if_not_found' => 'insert']));

        $this->assertSame(2, MacroOutput::count());
        $this->assertSame($before, $this->columns($old));
    }

    public function test_S_137_7_update_keeps_staff_edits_and_the_identity_columns(): void
    {
        $row = $this->existing(['FULL NAME' => 'Ana Staff', 'edited_full_name' => true, 'CXD' => 'staff cxd', 'extracted_details' => 'staff cxd',
            'HISTORICAL LOGS' => '2026-10-23 09:30:00|Joy|CXD|x|staff cxd']);
        $this->fields()->set('PHONE', '09998887777');
        $this->fields()->set('CXD', 'new details');

        $this->go($this->uploadGraph(['mode' => 'update', 'name' => 'Other Name']), 'reply po', '2026-10-23 12:00');

        $row->refresh();
        $this->assertSame(['09:00 23-10-2026', '2026-10-23', self::PAGE, 'Ana Cruz', (string) self::PSID, 'Ana Staff', '09171234567', 'staff cxd'],
            [$row->TIMESTAMP, substr((string) $row->ts_date, 0, 10), $row->PAGE, $row->fb_name, $row->botcake_psid, $row->{'FULL NAME'}, $row->{'PHONE NUMBER'}, $row->CXD]);
        $this->assertStringContainsString('reply po', (string) $row->all_user_input);
    }

    public function test_S_137_8_update_uploads_of_one_customer_are_one_at_a_time_and_make_one_row(): void
    {
        // Ang double tap: ang ikalawa ay nakikita ang row ng una.
        $this->go($this->uploadGraph(['mode' => 'update']), 'order po');
        $this->go($this->uploadGraph(['mode' => 'update']), "order po\norder po ulit");
        $this->assertSame(1, MacroOutput::count());

        // Habang hawak ng iba ang lock ng customer: walang sulat, failed port.
        $lock = Cache::lock(Runner::customerKey(new OrderEncode(['page' => self::PAGE, 'psid' => self::PSID, 'input' => []])), 60);
        $this->assertTrue($lock->get());
        $run = $this->go($this->uploadGraph(['mode' => 'update']), 'third', '2026-10-23 10:20');
        $lock->release();

        $this->assertSame([1, 2], [MacroOutput::count(), OrderEncode::count()]);
        $this->assertSame('failed', $this->step($run, 'n2')['port']);
    }

    public function test_S_137_9_the_same_run_reaching_the_upload_twice_makes_one_row(): void
    {
        $this->go($this->uploadGraph(), 'order po', runId: 'abcd1234');
        $run = $this->go($this->uploadGraph(), 'order po', runId: 'abcd1234');

        $this->assertSame([1, 1], [MacroOutput::count(), OrderEncode::count()]);
        $this->assertSame(['next', 'Already uploaded in this run: row ' . MacroOutput::sole()->id], [$this->step($run, 'n2')['port'], $this->step($run, 'n2')['note']]);
    }

    public function test_S_137_10_a_database_failure_goes_to_the_failed_port_without_a_half_row(): void
    {
        MacroOutput::created(fn () => throw new \RuntimeException('disk full'));

        $run = $this->go($this->uploadGraph());

        $this->assertSame([0, 0], [MacroOutput::count(), OrderEncode::count()]);
        $this->assertSame('failed', $this->step($run, 'n2')['port']);
    }

    public function test_S_137_12_counts_order_once_per_customer_per_24_hours_and_not_on_skip(): void
    {
        $this->go($this->uploadGraph(['mode' => 'update', 'if_not_found' => 'skip', 'counts_order' => true]));
        $this->assertSame(0, DB::table('chatbot_events')->where('type', 'order')->count());

        $this->go($this->uploadGraph(['counts_order' => true]));
        $this->go($this->uploadGraph(['counts_order' => true]), 'isa pa', '2026-10-23 11:00');

        $this->assertSame(1, DB::table('chatbot_events')->where('type', 'order')->count());
        $this->assertSame(2, MacroOutput::count());
    }

    public function test_S_137_33_35_the_test_tab_and_replies_off_write_nothing(): void
    {
        $run = $this->go($this->uploadGraph(['mode' => 'update']), test: true);
        $this->assertSame(['next', 'Test: would update'], [$this->step($run, 'n2')['port'], $this->step($run, 'n2')['note']]);

        config(['services.chatbot.replies_enabled' => false]);
        $this->go($this->uploadGraph());

        $this->assertSame([0, 0], [MacroOutput::count(), OrderEncode::count()]);
    }

    public function test_S_137_34_the_upload_ignores_the_pages_shadow_mode(): void
    {
        $this->configurePage('shadow');

        $this->go($this->uploadGraph());

        $this->assertSame(1, MacroOutput::count());
        $this->assertSame([], $this->google->appended);
    }

    public function test_S_158_2_the_uploaded_order_takes_item_and_cod_from_the_computed_shop_details_not_a_stale_stored_row(): void
    {
        $s = app(\App\Services\PageSettings::class);
        foreach (['item_name_in_jnt_dashboard' => 'GLOW TAPE', 'price' => '₱198', 'quantity' => '2', 'shop_details' => "PAGE: Other Page\nITEM: REF CLEANER\nPRICE: ₱999\nQUANTITY: 1"] as $key => $value) {
            $s->set(self::PAGE, 'chatbot', $key, $value);
        }
        $run = new \App\Services\Chatbot\FlowRun(5, $this->uploadGraph(['shop' => '{{SHOP DETAILS}}', 'price' => '']), self::PAGE, self::PAGE_ID,
            app(\App\Services\Chatbot\ChatbotPage::class)->botfields(self::PAGE), $this->fields(), 13, 'order po', 'Ana Cruz', '2026-10-23 10:15');
        app(\App\Services\Chatbot\FlowEngine::class)->run($run, $this->outbox());

        $row = MacroOutput::sole();
        $this->assertSame(['2 x GLOW TAPE', '198', "PAGE: Page Uno\nITEM: GLOW TAPE\nPRICE: ₱198\nQUANTITY: 2"], [$row->ITEM_NAME, (string) $row->COD, $row->{'SHOP DETAILS'}]);
    }
}

<?php

namespace Tests\Feature\EncoderTimetable;

use Illuminate\Support\Facades\DB;

/**
 * Spec 265 S1: /encoder/timetable shows, per order date, when encoding, the J&T upload and the waybill print finished.
 * The fixture is the live pattern of 2026-11-12: orders of 2026-11-11 validated 08:10–10:21, J&T booked until 10:41,
 * waybills printed until 11:24.
 */
class EncoderTimetablePageTest extends EncoderTimetableTestCase
{
    private function seedNov11(): void
    {
        $ana = $this->user('Data Encoder', 'Ana Encoder');
        $ben = $this->user('Data Encoder', 'Ben Encoder');

        $a1 = $this->order('2026-11-11', 'Page A');
        $a2 = $this->order('2026-11-11', 'Page A');
        $b1 = $this->order('2026-11-11', 'Page B');
        $this->checker($a1, '2026-11-12 08:10:00', $ana->id);
        $this->checker($a1, '2026-11-12 08:40:00', $ana->id, 'checker.edit');   // same order twice: counted once
        $this->checker($a2, '2026-11-12 09:30:00', $ana->id, 'checker.status');
        $this->checker($b1, '2026-11-12 10:21:00', $ben->id);
        // Not encoding: another source, another kind, an order of another day.
        $this->checker($b1, '2026-11-12 13:00:00', $ben->id, 'checker.validate', 'astra');
        $this->checker($b1, '2026-11-12 13:05:00', $ben->id, 'macro_output.update_field');
        $this->checker($this->order('2026-11-10', 'Page A'), '2026-11-12 14:00:00', $ben->id);

        $this->jntRun('2026-11-11', 'Page A', 'finished', '2026-11-12 09:47:00');
        $this->jntRun('2026-11-11', 'Page B', 'finished', '2026-11-12 10:41:00');
        $this->jntRun('2026-11-11', 'Page B', 'running', null, '2026-11-12 10:50:00');

        $this->printRun('2026-11-11', '2 x TUMBLER', 'finished', '2026-11-12 10:55:00');
        $this->printRun('2026-11-11', '1 x MUG', 'finished', '2026-11-12 11:24:00');

        DB::table('downloaded_macro_output_logs')->insert([
            'timestamp' => '2026-11-11', 'page' => 'Page A', 'downloaded_by' => 'Cora OIC', 'downloaded_at' => '2026-11-12 11:40:00',
        ]);
    }

    public function test_S1_1_each_order_date_shows_when_each_step_finished_newest_first(): void
    {
        $this->seedNov11();

        $response = $this->actingAs($this->user('CEO'))->get(self::URL . '?date_from=2026-11-05&date_to=2026-11-11');

        $response->assertOk();
        $rows = $response->viewData('rows');
        $this->assertSame(['2026-11-11', '2026-11-10', '2026-11-09', '2026-11-08', '2026-11-07', '2026-11-06', '2026-11-05'], array_column($rows, 'date'));

        $r = $rows[0];
        $this->assertSame('2026-11-12 08:10:00', $r['encoding']['start']);
        $this->assertSame('2026-11-12 10:21:00', $r['encoding']['finish']);
        $this->assertSame(3, $r['encoding']['orders']);
        $this->assertSame('Ben Encoder', $r['encoding']['finisher']);

        $this->assertSame('2026-11-12 10:41:00', $r['jnt']['finish']);
        $this->assertSame(3, $r['jnt']['runs']);
        $this->assertSame(2, $r['jnt']['done']);

        $this->assertSame('2026-11-12 11:24:00', $r['waybill']['finish']);
        $this->assertSame(2, $r['waybill']['runs']);

        $this->assertSame('Cora OIC', $r['excel']['by']);
        $this->assertSame(20, $r['gap_enc_jnt']);      // 10:21 → 10:41
        $this->assertSame(43, $r['gap_jnt_waybill']);  // 10:41 → 11:24

        // The order of 2026-11-10 touched on 11-12 belongs to the 11-10 row.
        $this->assertSame('2026-11-12 14:00:00', $rows[1]['encoding']['finish']);

        $html = $response->getContent();
        $this->assertStringContainsString('08:10 – <b>10:21</b>', $html);
        $this->assertStringContainsString('3 orders · huli: Ben Encoder', $html);
        $this->assertStringContainsString('2/3 runs tapos', $html);
        $this->assertStringContainsString('data-test="jnt-flag"', $html);  // a run not finished is flagged
        $this->assertStringContainsString('+20 min', $html);
        $this->assertStringContainsString('+43 min', $html);
        $this->assertStringContainsString('11:40', $html);
    }

    public function test_S1_1_a_failed_print_run_is_flagged_and_does_not_count_as_the_finish(): void
    {
        $this->printRun('2026-11-11', '2 x TUMBLER', 'finished', '2026-11-12 10:55:00');
        $this->printRun('2026-11-11', '1 x MUG', 'failed', '2026-11-12 11:30:00');

        $response = $this->actingAs($this->user('CEO'))->get(self::URL . '?date_from=2026-11-11&date_to=2026-11-11');

        $w = $response->viewData('rows')[0]['waybill'];
        $this->assertSame('2026-11-12 10:55:00', $w['finish']);
        $this->assertSame(1, $w['failed']);
        $this->assertStringContainsString('data-test="waybill-flag"', $response->getContent());
    }

    public function test_S1_1_default_is_the_last_14_order_dates_up_to_yesterday(): void
    {
        $response = $this->actingAs($this->user('CEO'))->get(self::URL);

        $rows = $response->viewData('rows');
        $this->assertCount(14, $rows);
        $this->assertSame('2026-11-11', $rows[0]['date']);
        $this->assertSame('2026-10-29', $rows[13]['date']);
    }

    public function test_S1_1_a_range_over_62_days_is_cut_to_62_with_a_message(): void
    {
        $response = $this->actingAs($this->user('CEO'))->get(self::URL . '?date_from=2026-07-01&date_to=2026-11-11');

        $rows = $response->viewData('rows');
        $this->assertCount(62, $rows);
        $this->assertSame('2026-11-11', $rows[0]['date']);
        $this->assertSame('2026-09-11', $rows[61]['date']);
        $this->assertNotNull($response->viewData('error'));
        $this->assertStringContainsString('data-test="range-error"', $response->getContent());
    }

    public function test_S1_2_each_date_expands_to_pages_sorted_by_latest_finish_and_waybill_filters(): void
    {
        $this->seedNov11();
        $c1 = $this->order('2026-11-11', 'Page C');
        $this->checker($c1, '2026-11-12 08:20:00');
        $this->jntRun('2026-11-11', 'Page D', 'finished', '2026-11-12 09:00:00');  // J&T only

        $response = $this->actingAs($this->user('CEO'))->get(self::URL . '?date_from=2026-11-11&date_to=2026-11-11');

        $pages = $response->viewData('rows')[0]['pages'];
        $this->assertSame(['Page B', 'Page A', 'Page D', 'Page C'], array_column($pages, 'page'));
        $byPage = collect($pages)->keyBy('page');
        $this->assertSame('2026-11-12 10:21:00', $byPage['Page B']['encoding']['finish']);
        $this->assertSame('2026-11-12 10:41:00', $byPage['Page B']['jnt']['finish']);
        $this->assertSame(1, $byPage['Page B']['jnt']['done']);
        $this->assertSame(2, $byPage['Page B']['jnt']['runs']);
        $this->assertSame(2, $byPage['Page A']['encoding']['orders']);
        $this->assertNull($byPage['Page D']['encoding']);
        $this->assertNull($byPage['Page C']['jnt']);

        $items = $response->viewData('rows')[0]['waybill']['items'];
        $this->assertSame(['1 x MUG', '2 x TUMBLER'], array_column($items, 'label'));

        $html = $response->getContent();
        $this->assertStringContainsString('<details>', $html);
        $this->assertStringContainsString('data-page="Page B"', $html);
        $this->assertStringContainsString('data-item="2 x TUMBLER"', $html);
    }

    public function test_S1_3_summary_shows_average_and_worst_day_and_highlights_days_over_30_minutes_late(): void
    {
        // Encoding finishes: 10:00, 10:00, 11:30 → average 10:30; 11:30 is 60 min later (highlighted), 10:00 is not.
        foreach (['2026-11-09' => '2026-11-10 10:00:00', '2026-11-10' => '2026-11-11 10:00:00', '2026-11-11' => '2026-11-12 11:30:00'] as $date => $at) {
            $this->checker($this->order($date, 'Page A'), $at);
        }
        // J&T: 10:40 and 10:50 → average 10:45, nobody 30 min late.
        $this->jntRun('2026-11-10', 'Page A', 'finished', '2026-11-11 10:40:00');
        $this->jntRun('2026-11-11', 'Page A', 'finished', '2026-11-12 10:50:00');

        $response = $this->actingAs($this->user('CEO'))->get(self::URL . '?date_from=2026-11-09&date_to=2026-11-11');

        $summary = $response->viewData('summary');
        $this->assertSame(630, $summary['encoding']['avg']);
        $this->assertSame('2026-11-11', $summary['encoding']['worst_date']);
        $this->assertSame(3, $summary['encoding']['days']);
        $this->assertSame(645, $summary['jnt']['avg']);
        $this->assertSame('2026-11-11', $summary['jnt']['worst_date']);
        $this->assertNull($summary['waybill']['avg']);

        $rows = collect($response->viewData('rows'))->keyBy('date');
        $this->assertTrue($rows['2026-11-11']['late']['encoding']);
        $this->assertFalse($rows['2026-11-10']['late']['encoding']);
        $this->assertFalse($rows['2026-11-11']['late']['jnt']);

        $html = $response->getContent();
        $this->assertStringContainsString('data-test="avg">10:30<', $html);
        $this->assertStringContainsString('data-test="worst">11:30<', $html);
        $this->assertMatchesRegularExpression('/<tr data-date="2026-11-11"[\s\S]*?bg-red-100" data-col="encoding"/', $html);
        $this->assertDoesNotMatchRegularExpression('/<tr data-date="2026-11-10"[^>]*>\s*<td[^>]*>[^<]*<\/td>\s*<td class="[^"]*bg-red-100/', $html);
    }

    public function test_S1_4_a_finish_later_than_the_next_day_shows_its_date(): void
    {
        $this->checker($this->order('2026-11-11', 'Page A'), '2026-11-12 08:10:00');
        $this->checker($this->order('2026-11-11', 'Page A'), '2026-11-14 09:12:00');
        $this->jntRun('2026-11-11', 'Page A', 'finished', '2026-11-12 10:00:00');

        $response = $this->actingAs($this->user('CEO'))->get(self::URL . '?date_from=2026-11-11&date_to=2026-11-11');

        $html = $response->getContent();
        $this->assertStringContainsString('08:10 – <b>Nov 14 09:12</b>', $html);
        $this->assertStringContainsString('<b class="font-mono">10:00</b>', $html);
        // A finish two days after the next day counts as later, not earlier, in the average (2 days + 09:12).
        $this->assertSame(2 * 1440 + 552, $response->viewData('summary')['encoding']['avg']);
    }

    public function test_S1_5_empty_dates_show_a_dash_and_dates_before_the_oldest_event_show_no_record(): void
    {
        // Oldest staff event is on 2026-11-09: order dates before it have no encoding record left (pruned after 90 days).
        $this->checker($this->order('2026-11-10', 'Page A'), '2026-11-09 08:00:00', 1, 'login');
        DB::table('staff_events')->where('kind', 'login')->update(['source' => null]);

        $response = $this->actingAs($this->user('CEO'))->get(self::URL . '?date_from=2026-11-07&date_to=2026-11-10');

        $rows = $this->rowsByDate($response);
        $this->assertFalse($rows['2026-11-10']['no_record']);
        $this->assertFalse($rows['2026-11-09']['no_record']);
        $this->assertTrue($rows['2026-11-08']['no_record']);
        $this->assertTrue($rows['2026-11-07']['no_record']);
        foreach ($rows as $r) {
            $this->assertNull($r['encoding']);
            $this->assertNull($r['jnt']);
            $this->assertNull($r['waybill']);
            $this->assertNull($r['excel']);
        }

        $html = $response->getContent();
        $this->assertSame(2, substr_count($html, 'wala nang record'));
        $this->assertMatchesRegularExpression('/<tr data-date="2026-11-10"[\s\S]*?data-col="encoding">\s*—\s*<\/td>/', $html);
        $this->assertStringNotContainsString('data-detail=', $html);  // nothing to expand
    }
}

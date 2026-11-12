<?php

namespace Tests\Feature\EncoderTimetable;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Spec 265 S3: the timetable reads live tables, so every query is a plain SELECT, staff_events is read through its
 * created_at index and joined to macro_output by primary key, grouping happens in SQL, and 14 days of realistic volume
 * render in under 2 seconds.
 */
class EncoderTimetablePerformanceTest extends EncoderTimetableTestCase
{
    private const TABLES = ['staff_events', 'macro_output', 'jnt_batch_runs', 'jnt_waybill_print_runs', 'downloaded_macro_output_logs'];

    /** @return array<int, array{sql: string, bindings: array}> the request's queries that touch the timetable's tables */
    private function timetableQueries(string $url): array
    {
        $user = $this->user('CEO');
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            foreach (self::TABLES as $t) {
                if (str_contains($q->sql, '"' . $t . '"') || str_contains($q->sql, "'" . $t . "'")) {
                    $queries[] = ['sql' => $q->sql, 'bindings' => $q->bindings];
                    return;
                }
            }
        });
        $this->actingAs($user)->get($url)->assertOk();

        return $queries;
    }

    private function seedSmall(): void
    {
        $this->checker($this->order('2026-11-11', 'Page A'), '2026-11-12 08:10:00');
        $this->jntRun('2026-11-11', 'Page A', 'finished', '2026-11-12 09:47:00');
        $this->printRun('2026-11-11', '2 x TUMBLER', 'finished', '2026-11-12 10:55:00');
    }

    public function test_S3_1_every_query_is_a_plain_select_and_their_number_does_not_grow_with_days(): void
    {
        $this->seedSmall();

        $two = $this->timetableQueries(self::URL . '?date_from=2026-11-10&date_to=2026-11-11');
        $sixty = $this->timetableQueries(self::URL . '?date_from=2026-09-13&date_to=2026-11-11');

        foreach ($sixty as $q) {
            $this->assertMatchesRegularExpression('/^\s*select\b/i', $q['sql'], 'only SELECT: ' . $q['sql']);
            $this->assertDoesNotMatchRegularExpression('/\b(create|insert|update|delete|drop|alter|temporary)\b/i', $q['sql']);
        }
        // Data: encoding (grouped) + encoding finisher + oldest event + J&T + waybill + Excel. Plus one table-exists
        // check per table read. The same count for 2 days or 60.
        $isSchema = fn ($q) => str_contains($q['sql'], 'sqlite_master') || str_contains($q['sql'], 'information_schema');
        $this->assertCount(6, array_filter($sixty, fn ($q) => !$isSchema($q)));
        $this->assertCount(6, array_filter($sixty, $isSchema));
        $this->assertCount(count($two), $sixty);
    }

    public function test_S3_1_staff_events_is_read_by_a_created_at_range_and_grouped_in_sql(): void
    {
        $this->seedSmall();

        $queries = $this->timetableQueries(self::URL . '?date_from=2026-10-29&date_to=2026-11-11');
        $grouped = collect($queries)->first(fn ($q) => str_contains($q['sql'], '"staff_events" as "se"') && str_contains($q['sql'], 'group by'));
        $this->assertNotNull($grouped);

        // created_at from the day before the first order date to 3 days after the last one; order dates on ts_date.
        $this->assertStringContainsString('"se"."created_at" >= ?', $grouped['sql']);
        $this->assertStringContainsString('"se"."created_at" < ?', $grouped['sql']);
        $this->assertContains('2026-10-28 00:00:00', $grouped['bindings']);
        $this->assertContains('2026-11-15 00:00:00', $grouped['bindings']);
        $this->assertContains('2026-10-29', $grouped['bindings']);
        $this->assertContains('2026-11-11', $grouped['bindings']);
        $this->assertStringContainsString('group by "mo"."ts_date", "mo"."PAGE"', $grouped['sql']);
        $this->assertStringContainsString('"mo"."id" = CAST(se.ref AS SIGNED INTEGER)', $grouped['sql']);

        // The plan: a range search on the created_at index, then one primary-key lookup per event; no full scan.
        $plan = collect(DB::select('EXPLAIN QUERY PLAN ' . $grouped['sql'], $grouped['bindings']))->pluck('detail')->implode("\n");
        $this->assertMatchesRegularExpression('/SEARCH se USING INDEX staff_events_created_at_index \(created_at>\? AND created_at<\?\)/', $plan, $plan);
        $this->assertMatchesRegularExpression('/SEARCH mo USING INTEGER PRIMARY KEY \(rowid=\?\)/', $plan, $plan);
        $this->assertStringNotContainsString('SCAN se', $plan);
        fwrite(STDERR, "\n[265 S3.1] EXPLAIN QUERY PLAN (sqlite):\n" . $plan . "\n");
    }

    public function test_S3_2_fourteen_days_of_live_volume_render_in_under_two_seconds(): void
    {
        // Live, 2026-11-12: ~9,300 Checker 1 events a day (8,696 validate, 427 edit, 161 status), ~50 J&T runs and ~42
        // print runs a day. Here: 14 order dates x 3,100 orders x 3 events = 130,200 events, plus 8,680 other (route) events.
        $pages = array_map(fn ($i) => 'Page ' . $i, range(1, 50));
        $events = [];
        $orders = [];
        $id = 1;
        $flush = function () use (&$events, &$orders) {
            foreach (array_chunk($orders, 500) as $c) DB::table('macro_output')->insert($c);
            foreach (array_chunk($events, 500) as $c) DB::table('staff_events')->insert($c);
            $events = $orders = [];
        };
        DB::beginTransaction();
        for ($day = 0; $day < 14; $day++) {
            $orderDate = Carbon::parse('2026-10-29')->addDays($day)->toDateString();
            $work = Carbon::parse($orderDate)->addDay()->setTime(8, 0);
            for ($n = 0; $n < 3100; $n++, $id++) {
                $orders[] = ['id' => $id, 'ts_date' => $orderDate, 'PAGE' => $pages[$n % 50], 'STATUS' => 'PROCEED'];
                foreach (['checker.validate', 'checker.edit', 'checker.status'] as $k => $kind) {
                    $at = $work->copy()->addSeconds(($n * 3 + $k) * 3)->toDateTimeString();
                    $events[] = ['user_id' => 1 + $n % 8, 'role' => 'Data Encoder', 'kind' => $kind, 'source' => 'checker_1', 'method' => 'POST', 'status' => 200, 'ref' => (string) $id, 'created_at' => $at];
                    if ($k === 0 && $n % 5 === 0) {
                        $events[] = ['user_id' => 1, 'role' => 'Data Encoder', 'kind' => 'POST jnt/orders/batch', 'source' => null, 'method' => 'POST', 'status' => 302, 'ref' => null, 'created_at' => $at];
                    }
                }
            }
            $flush();
            foreach ($pages as $p) $this->jntRun($orderDate, $p, 'finished', $work->copy()->setTime(10, 30)->toDateTimeString());
            for ($k = 0; $k < 42; $k++) $this->printRun($orderDate, 'ITEM ' . $k, 'finished', $work->copy()->setTime(11, 0)->addMinutes($k)->toDateTimeString());
        }
        DB::commit();
        $this->assertSame(138880, DB::table('staff_events')->count());

        $user = $this->user('CEO');
        $this->actingAs($user)->get(self::URL)->assertOk(); // warm-up: view compile and the layout's first queries

        $start = microtime(true);
        $response = $this->actingAs($user)->get(self::URL . '?date_from=2026-10-29&date_to=2026-11-11');
        $seconds = microtime(true) - $start;

        $response->assertOk();
        $rows = $response->viewData('rows');
        $this->assertCount(14, $rows);
        $this->assertSame(3100, $rows[0]['encoding']['orders']);
        $this->assertCount(50, $rows[0]['pages']);
        fwrite(STDERR, sprintf("\n[265 S3.2] 14 days, %d staff_events rows: page rendered in %.3f s\n", DB::table('staff_events')->count(), $seconds));
        $this->assertLessThan(2.0, $seconds);
    }
}

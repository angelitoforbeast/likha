<?php

namespace Tests\Feature\Item;

use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * nexus:sync-stock --history — ang Nexus stock-in bago ang item_stock_start, mula services.nexus.history_from, sa mga
 * range na ≤ 31 araw (pinakabago muna). Ang stock_in ng range ay listahan ng {date, lots, pieces} kada araw.
 */
class NexusStockHistoryTest extends ItemTestCase
{
    /** @var array<int,string> */
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->nexusOn();
        $this->appSetting('item_stock_start', '2026-10-02');
        config(['services.nexus.history_from' => '2026-08-01']);
        Carbon::setTestNow(Carbon::parse('2026-11-03 10:10:00', 'Asia/Manila'));
        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logs[] = $e->message . ' ' . json_encode($e->context);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** $ranges = ["from|to" => items | int status | 'malformed']; ang ibang range ay 200 na walang items. */
    private function fakeRanges(array $ranges): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory(app('events')));
        Http::preventStrayRequests();
        Http::fake(['nexus.test/api/item-stock*' => function (Request $request) use ($ranges) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
            $k = ($q['from'] ?? '') . '|' . ($q['to'] ?? '');
            $v = $ranges[$k] ?? [];
            if ($v === 'malformed') return Http::response('oops', 200);
            if (is_int($v)) return Http::response(['message' => 'x'], $v);

            return Http::response(['as_of' => '2026-11-03T10:09:00+08:00', 'from' => $q['from'] ?? null, 'to' => $q['to'] ?? null, 'items' => $v], 200);
        }]);
    }

    private function history(): array
    {
        $code = Artisan::call('nexus:sync-stock', ['--history' => true]);

        return [$code, Artisan::output()];
    }

    private function asked(): array
    {
        return Http::recorded()->map(function ($pair) {
            parse_str((string) parse_url($pair[0]->url(), PHP_URL_QUERY), $q);
            return ($q['from'] ?? '?') . '|' . ($q['to'] ?? '?') . (isset($q['date']) ? '|date' : '');
        })->all();
    }

    private function rows(): array
    {
        return DB::table('nexus_stock_ins')->orderBy('stock_date')->orderBy('item_name')
            ->get(['stock_date', 'item_name', 'stock_in_lots', 'stock_in_pieces', 'remaining', 'as_of'])
            ->map(fn ($r) => [substr((string) $r->stock_date, 0, 10), $r->item_name, (int) $r->stock_in_lots, (int) $r->stock_in_pieces, $r->remaining, $r->as_of])
            ->all();
    }

    public function test_S_222_5_1_ranges_newest_first_are_split_into_per_day_rows(): void
    {
        $this->fakeRanges([
            '2026-09-01|2026-10-01' => [
                ['item' => 'SEAT COVER', 'stock_in' => [['date' => '2026-09-29', 'lots' => 10, 'pieces' => 10000]], 'stock_in_pieces' => 10000, 'remaining' => 3],
                ['item' => 'MINI FLASHLIGHT', 'stock_in' => [['date' => '2026-09-13', 'lots' => 2, 'pieces' => 300], ['date' => '2026-09-20', 'lots' => 1, 'pieces' => 100]], 'stock_in_pieces' => 400, 'remaining' => 1],
                ['item' => 'SHELF ONLY', 'stock_in' => [], 'stock_in_pieces' => 0, 'remaining' => 5],
            ],
            '2026-08-01|2026-08-31' => [
                ['item' => 'MINI FLASHLIGHT', 'stock_in' => [['date' => '2026-08-15', 'lots' => 1, 'pieces' => 50]], 'stock_in_pieces' => 50, 'remaining' => 1],
            ],
        ]);

        [$code] = $this->history();

        $this->assertSame(0, $code);
        $this->assertSame(['2026-09-01|2026-10-01', '2026-08-01|2026-08-31'], $this->asked());
        $this->assertSame([
            ['2026-08-15', 'MINI FLASHLIGHT', 1, 50, null, null],
            ['2026-09-13', 'MINI FLASHLIGHT', 2, 300, null, null],
            ['2026-09-20', 'MINI FLASHLIGHT', 1, 100, null, null],
            ['2026-09-29', 'SEAT COVER', 10, 10000, null, null],
        ], $this->rows(), 'kada araw; walang remaining at as_of; ang shelf-only ay walang row');
        $this->assertSame('2026-08-01', DB::table('app_settings')->where('key', 'nexus_stock_history_done_from')->value('value'));
    }

    public function test_the_default_history_from_is_2026_01_01_in_windows_of_31_days_or_less(): void
    {
        config(['services.nexus.history_from' => null]);
        $this->assertSame('2026-01-01', \App\Services\Nexus\StockInSync::historyFrom());
        $this->fakeRanges([]);

        $this->history();

        $asked = $this->asked();
        $this->assertCount(9, $asked);
        $this->assertSame('2026-09-01|2026-10-01', $asked[0]);
        $this->assertSame('2026-01-01|2026-01-26', end($asked));
        foreach ($asked as $r) {
            [$f, $t] = explode('|', $r);
            $this->assertLessThanOrEqual(31, Carbon::parse($f)->diffInDays(Carbon::parse($t)) + 1, $r);
        }
    }

    public function test_S_222_5_3_a_429_stops_and_the_next_run_continues_where_it_stopped(): void
    {
        $this->fakeRanges([
            '2026-09-01|2026-10-01' => [['item' => 'SEAT COVER', 'stock_in' => [['date' => '2026-09-29', 'lots' => 10, 'pieces' => 10000]], 'stock_in_pieces' => 10000]],
            '2026-08-01|2026-08-31' => 429,
        ]);

        [$code] = $this->history();

        $this->assertSame(1, $code);
        $this->assertSame(['2026-09-01|2026-10-01', '2026-08-01|2026-08-31'], $this->asked());
        $this->assertSame('2026-09-01', DB::table('app_settings')->where('key', 'nexus_stock_history_done_from')->value('value'));

        $this->fakeRanges(['2026-08-01|2026-08-31' => 401]);
        [$code] = $this->history();
        $this->assertSame(1, $code);
        $this->assertSame(['2026-08-01|2026-08-31'], $this->asked(), 'tuloy mula sa huminto');

        $this->fakeRanges([]);
        $this->history();
        $this->assertSame(['2026-08-01|2026-08-31'], $this->asked());
        $this->assertSame('2026-08-01', DB::table('app_settings')->where('key', 'nexus_stock_history_done_from')->value('value'));

        $this->fakeRanges([]);
        [$code, $out] = $this->history();
        $this->assertSame(0, $code);
        $this->assertSame([], $this->asked(), 'tapos na: walang tinatanong');
        $this->assertStringContainsString('complete', $out);
    }

    public function test_S_222_5_2_a_rerun_stores_the_same_rows_and_keeps_an_existing_rows_remaining(): void
    {
        $answer = ['2026-09-01|2026-10-01' => [['item' => 'SEAT COVER', 'stock_in' => [['date' => '2026-09-29', 'lots' => 10, 'pieces' => 10000]], 'stock_in_pieces' => 10000, 'remaining' => 3]]];
        $this->fakeRanges($answer);
        $this->history();
        $once = $this->rows();
        $this->assertSame([['2026-09-29', 'SEAT COVER', 10, 10000, null, null]], $once);

        DB::table('app_settings')->where('key', 'nexus_stock_history_done_from')->delete();   // itanong ulit lahat
        DB::table('nexus_stock_ins')->update(['remaining' => 7, 'as_of' => '2026-11-01 10:00:00']);
        $this->fakeRanges($answer);
        $this->history();

        $this->assertSame([['2026-09-29', 'SEAT COVER', 10, 10000, 7, '2026-11-01 10:00:00']], $this->rows(), 'lots at pieces lang ang pinapalitan');
        $this->assertSame(1, DB::table('nexus_stock_ins')->count());
    }

    public function test_S_222_5_4_nothing_before_history_from_or_on_and_after_the_stock_start_is_asked(): void
    {
        $this->appSetting('item_stock_start', '2026-09-20');
        $this->fakeRanges([]);

        $this->history();

        $this->assertSame(['2026-08-20|2026-09-19', '2026-08-01|2026-08-19'], $this->asked());
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'date='));
    }

    public function test_S_222_5_5_bad_entries_are_skipped_a_malformed_answer_stops_and_stored_rows_are_never_zeroed(): void
    {
        $this->nexusStockIn('OLD ITEM', 9, '2026-09-10');
        $this->fakeRanges(['2026-09-01|2026-10-01' => [
            ['item' => 'GOOD', 'stock_in' => [['date' => '2026-09-01', 'lots' => 1, 'pieces' => 20]], 'stock_in_pieces' => 20],
            ['item' => 'OUTSIDE', 'stock_in' => [['date' => '2026-08-15', 'lots' => 1, 'pieces' => 5]], 'stock_in_pieces' => 5],
            ['item' => 'BAD PIECES', 'stock_in' => [['date' => '2026-09-02', 'lots' => 1, 'pieces' => -5]], 'stock_in_pieces' => -5],
            ['item' => 'OLD NUMBER', 'stock_in' => 3, 'stock_in_pieces' => 30],
            ['item' => '', 'stock_in' => [['date' => '2026-09-02', 'lots' => 1, 'pieces' => 1]]],
        ], '2026-08-01|2026-08-31' => 'malformed']);

        [$code] = $this->history();

        $this->assertSame(1, $code, 'ang sirang sagot ay humihinto');
        $this->assertSame([['2026-09-01', 'GOOD', 1, 20, null, null], ['2026-09-10', 'OLD ITEM', 1, 9, null, null]], $this->rows());
        $this->assertCount(4, array_filter($this->logs, fn ($l) => str_contains($l, 'history entry skipped')));
        $this->assertEmpty(array_filter($this->logs, fn ($l) => str_contains($l, 'zeroed')));
        $this->assertSame('2026-09-01', DB::table('app_settings')->where('key', 'nexus_stock_history_done_from')->value('value'));
    }

    public function test_history_needs_a_token_and_the_hourly_run_stays_on_dates(): void
    {
        config(['services.nexus.token' => '']);
        Http::fake();
        [$code, $out] = $this->history();
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Nexus not configured', $out);
        Http::assertNothingSent();

        $this->nexusOn();
        $this->appSetting('nexus_stock_synced_through', '2026-11-03');
        $this->fakeRanges([]);
        Artisan::call('nexus:sync-stock');
        $this->assertSame(['?|?|date', '?|?|date'], $this->asked(), 'hourly: kahapon at ngayon, ?date= pa rin');
    }
}

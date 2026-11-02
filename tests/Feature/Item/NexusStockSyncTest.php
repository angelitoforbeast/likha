<?php

namespace Tests\Feature\Item;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * nexus:sync-stock — Nexus stock-in kada araw at item → nexus_stock_ins.
 * Lahat ng Nexus call ay Http::fake (preventStrayRequests sa ItemTestCase): walang totoong request.
 */
class NexusStockSyncTest extends ItemTestCase
{
    private const TOKEN = 'fake-test-token-220';

    /** @var array<int,string> */
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->nexusOn();
        $this->appSetting('item_stock_start', '2026-10-02');
        Carbon::setTestNow(Carbon::parse('2026-11-03 10:10:00', 'Asia/Manila'));
        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logs[] = $e->level . ' ' . $e->message . ' ' . json_encode($e->context);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Pekeng Nexus: $days = [Y-m-d => items | int status | 'timeout' | 'malformed'].
     * Ang araw na wala sa $days ay 200 na walang items.
     */
    private function fakeNexus(array $days, string $asOf = '2026-11-03 10:09:00'): void
    {
        // Bagong factory kada fake: ang recorded requests ay sa takbong ito lang.
        Http::swap(new \Illuminate\Http\Client\Factory(app('events')));
        Http::preventStrayRequests();
        Http::fake(['nexus.test/api/item-stock*' => function (Request $request) use ($days, $asOf) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
            $d = $q['date'] ?? '';
            $v = $days[$d] ?? [];
            if ($v === 'timeout') return Http::failedConnection('timed out');
            if ($v === 'malformed') return Http::response('<html>oops</html>', 200);
            if (is_int($v)) return Http::response(['message' => 'error'], $v);

            // Ang "@" sa stock_in[].date ay ang araw na tinanong (gaya ng Nexus: ang araw lang na iyon ang nandiyan).
            array_walk_recursive($v, function (&$x, $k) use ($d) { if ($k === 'date' && $x === '@') $x = $d; });

            return Http::response(['as_of' => $asOf, 'from' => $d, 'to' => $d, 'items' => $v], 200);
        }]);
    }

    /**
     * Isang item sa anyo ng Nexus (app/Services/ItemStock.php): stock_in = listahan ng {date, lots, pieces} kada araw,
     * walang laman kapag walang dumating (lots null); stock_in_pieces = kabuuan; remaining = nasa shelf ngayon.
     */
    private function item(string $name, $pieces, $lots = 1, $remaining = null): array
    {
        $stockIn = $lots === null ? [] : [['date' => '@', 'lots' => $lots, 'pieces' => $pieces]];

        return ['item' => $name, 'stock_in' => $stockIn, 'stock_in_pieces' => $pieces, 'remaining' => $remaining];
    }

    private function sync(array $args = []): array
    {
        $code = Artisan::call('nexus:sync-stock', $args);

        return [$code, Artisan::output()];
    }

    private function rows(): array
    {
        return DB::table('nexus_stock_ins')->orderBy('stock_date')->orderBy('item_name')
            ->get(['stock_date', 'item_name', 'stock_in_lots', 'stock_in_pieces', 'remaining'])
            ->map(fn ($r) => [substr((string) $r->stock_date, 0, 10), $r->item_name, (int) $r->stock_in_lots, (int) $r->stock_in_pieces, $r->remaining === null ? null : (int) $r->remaining])
            ->all();
    }

    private function settingValue(string $key): ?string
    {
        return DB::table('app_settings')->where('key', $key)->value('value');
    }

    private function requestedDates(): array
    {
        return Http::recorded()->map(function ($pair) {
            parse_str((string) parse_url($pair[0]->url(), PHP_URL_QUERY), $q);
            return $q['date'] ?? null;
        })->all();
    }

    /** Hourly run: synced na hanggang ngayong araw → kahapon at ngayon lang ang tinatanong. */
    private function alreadySynced(): void
    {
        $this->appSetting('nexus_stock_synced_through', '2026-11-03');
        $this->appSetting('nexus_stock_last_synced_at', '2026-11-03 09:10:00');
    }

    public function test_S_220_1_1_a_faked_item_is_stored_with_its_pieces_and_lots_and_the_request_carries_the_bearer_token(): void
    {
        $this->alreadySynced();
        $this->fakeNexus(['2026-11-02' => [$this->item('GLOW TAPE', 48, 2, 120)]]);

        [$code] = $this->sync();

        $this->assertSame(0, $code);
        $this->assertSame([['2026-11-02', 'GLOW TAPE', 2, 48, 120]], $this->rows());
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer ' . self::TOKEN)
            && str_starts_with($r->url(), 'https://nexus.test/api/item-stock?date=2026-11-02'));
    }

    public function test_S_220_1_2_the_first_run_asks_one_day_at_a_time_from_the_start_and_the_sums_match(): void
    {
        $days = [];
        $expected = 0;
        foreach (\Carbon\CarbonPeriod::create('2026-10-02', '2026-11-03') as $i => $d) {
            $days[$d->toDateString()] = [$this->item('GLOW TAPE', $i + 1)];
            $expected += $i + 1;
        }
        $this->fakeNexus($days);

        $this->sync();

        $dates = $this->requestedDates();
        $this->assertCount(33, $dates, 'isang request kada araw, 10-02 hanggang 11-03');
        $this->assertSame('2026-10-02', $dates[0]);
        $this->assertSame('2026-11-03', end($dates));
        $this->assertSame($dates, array_values(array_unique($dates)));
        $this->assertSame($expected, (int) DB::table('nexus_stock_ins')->sum('stock_in_pieces'));
        $this->assertSame(33, DB::table('nexus_stock_ins')->count());
        $this->assertSame('2026-11-03', $this->settingValue('nexus_stock_synced_through'));
    }

    public function test_S_220_1_3_the_sync_is_scheduled_hourly_in_manila_without_overlap_and_todays_row_is_overwritten(): void
    {
        Artisan::call('schedule:list');
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'nexus:sync-stock'));

        $this->assertNotNull($event);
        $this->assertSame('10 * * * *', $event->expression);
        $this->assertSame('Asia/Manila', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(30, $event->expiresAt);

        $this->alreadySynced();
        $this->fakeNexus(['2026-11-03' => [$this->item('GLOW TAPE', 10)]]);
        $this->sync();
        $this->assertSame(['2026-11-02', '2026-11-03'], $this->requestedDates(), 'trailing window: kahapon at ngayon');

        $this->fakeNexus(['2026-11-03' => [$this->item('GLOW TAPE', 15)]]);
        $this->sync();
        $this->assertSame([['2026-11-03', 'GLOW TAPE', 1, 15, null]], $this->rows());
    }

    public function test_S_220_1_4_re_runs_never_add(): void
    {
        $this->alreadySynced();
        $this->fakeNexus(['2026-11-02' => [$this->item('GLOW TAPE', 48)], '2026-11-03' => [$this->item('ROPE', 7)]]);
        $this->sync();
        $once = $this->rows();

        for ($i = 0; $i < 23; $i++) $this->sync();

        $this->assertSame($once, $this->rows());
        $this->assertSame(55, (int) DB::table('nexus_stock_ins')->sum('stock_in_pieces'));
    }

    public function test_S_220_1_5_a_correction_from_48_to_40_stores_40(): void
    {
        $this->alreadySynced();
        $this->fakeNexus(['2026-11-02' => [$this->item('GLOW TAPE', 48)]]);
        $this->sync();
        $this->fakeNexus(['2026-11-02' => [$this->item('GLOW TAPE', 40)]]);
        $this->sync();

        $this->assertSame([['2026-11-02', 'GLOW TAPE', 1, 40, null]], $this->rows());
    }

    public function test_S_220_1_6_no_token_means_a_clear_message_no_request_no_change_and_the_token_is_never_shown(): void
    {
        config(['services.nexus.token' => '']);
        Http::fake();
        $before = DB::table('app_settings')->orderBy('key')->get(['key', 'value'])->all();

        [$code, $out] = $this->sync();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Nexus not configured', $out);
        Http::assertNothingSent();
        $this->assertSame([], $this->rows());
        $this->assertEquals($before, DB::table('app_settings')->orderBy('key')->get(['key', 'value'])->all());

        // Kapag may token: sa output, log, settings at rows ay hindi lumalabas ang token, kahit 401 o 500.
        $this->nexusOn();
        foreach ([401, 500, 'timeout', [$this->item('X', 1)]] as $answer) {
            $this->fakeNexus(['2026-11-02' => $answer, '2026-11-03' => $answer]);
            $this->alreadySynced();
            [, $out] = $this->sync();
            $this->assertStringNotContainsString(self::TOKEN, $out);
        }
        $this->assertStringNotContainsString(self::TOKEN, implode("\n", $this->logs));
        $this->assertStringNotContainsString(self::TOKEN, json_encode(DB::table('app_settings')->get()->all()));
        $this->assertStringNotContainsString(self::TOKEN, json_encode(DB::table('nexus_stock_ins')->get()->all()));
    }

    public function test_S_220_1_7_a_run_while_another_holds_the_lock_asks_nothing_and_the_unique_key_blocks_duplicates(): void
    {
        $this->alreadySynced();
        $this->fakeNexus(['2026-11-02' => [$this->item('GLOW TAPE', 48)]]);
        $lock = Cache::lock('nexus:sync-stock', 60);
        $this->assertTrue($lock->get());

        [$code, $out] = $this->sync();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('already running', $out);
        Http::assertNothingSent();
        $lock->release();

        $this->sync();
        $this->expectException(QueryException::class);
        $this->nexusStockIn('GLOW TAPE', 1, '2026-11-02');
    }

    public function test_S_220_1_8_case_spacing_and_non_ascii_variants_make_no_near_duplicate_rows_and_the_text_is_kept(): void
    {
        $this->alreadySynced();
        $decomposed = "Nin\u{0303}o Lamp";                       // ñ bilang n + combining tilde
        $this->fakeNexus(['2026-11-02' => [
            $this->item('Glow  Tape', 5), $this->item('GLOW TAPE', 3),
            $this->item('Niño Lamp', 2), $this->item($decomposed, 1),
            $this->item('Café Cup', 4), $this->item('Cafe Cup', 6),
        ]]);
        $this->sync();
        // Ikalawang run: ibang case lang → parehong row, hindi bago.
        $this->fakeNexus(['2026-11-02' => [
            $this->item('glow tape', 8), $this->item('NIÑO LAMP', 3), $this->item('Café Cup', 4), $this->item('Cafe Cup', 6),
        ]]);
        $this->sync();

        $rows = $this->rows();
        $this->assertCount(4, $rows, 'Glow Tape ×1, Niño Lamp ×1, Café Cup at Cafe Cup ay magkaiba');
        $byPieces = array_column($rows, 3, 1);
        $this->assertSame(8, $byPieces['glow tape']);
        $this->assertSame(3, $byPieces['NIÑO LAMP']);
        $this->assertSame(4, $byPieces['Café Cup']);
        $this->assertSame(6, $byPieces['Cafe Cup']);
    }

    public function test_S_220_1_9_nothing_before_the_start_is_asked_and_with_no_start_nothing_is_asked(): void
    {
        $this->appSetting('nexus_stock_synced_through', '2026-09-01');   // luma, bago ang start
        $this->fakeNexus([]);
        $this->sync();
        $dates = $this->requestedDates();
        $this->assertSame('2026-10-02', min($dates));

        $this->appSetting('item_stock_start', null);
        $this->fakeNexus([]);
        [$code, $out] = $this->sync();
        $this->assertSame(0, $code);
        $this->assertStringContainsString('item_stock_start', $out);
        Http::assertNothingSent();
        $this->assertFalse($this->actingAs($this->user())->getJson('/item/stock')->assertOk()->json('stock_ready'));
    }

    public function test_S_220_1_10_zero_or_null_is_stored_as_0_and_negative_or_non_numeric_values_are_skipped_and_logged(): void
    {
        $this->alreadySynced();
        $this->fakeNexus(['2026-11-02' => [
            $this->item('ZERO', 0), $this->item('NULLED', null, null), $this->item('STRING NUM', '12', '2'),
            $this->item('NEGATIVE', -5), $this->item('WORD', 'abc'), $this->item('HALF', 3.5),
            $this->item('NEG LOTS', 4, -1), $this->item('HUGE', 99999999999),
            ['item' => '', 'stock_in_pieces' => 1], ['stock_in_pieces' => 1], 'not an object',
        ]]);

        $this->sync();

        $this->assertSame([
            ['2026-11-02', 'NULLED', 0, 0, null],
            ['2026-11-02', 'STRING NUM', 2, 12, null],
            ['2026-11-02', 'ZERO', 1, 0, null],
        ], $this->rows());
        $skipped = array_filter($this->logs, fn ($l) => str_contains($l, 'skipped'));
        $this->assertCount(8, $skipped, implode("\n", $this->logs));
    }

    public function test_S_220_1_11_just_after_midnight_manila_the_previous_day_stays_in_the_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-04 00:05:00', 'Asia/Manila'));
        $this->alreadySynced();   // synced through 11-03
        $this->fakeNexus([]);

        $this->sync();

        $this->assertSame(['2026-11-03', '2026-11-04'], $this->requestedDates());
    }

    public function test_S_220_2_2_a_500_or_timeout_changes_no_row_keeps_last_synced_and_records_the_error(): void
    {
        foreach ([500, 'timeout'] as $answer) {
            DB::table('nexus_stock_ins')->delete();
            $this->alreadySynced();
            $this->appSetting('nexus_stock_last_error', null);
            $this->nexusStockIn('GLOW TAPE', 48, '2026-11-03');
            $this->fakeNexus(['2026-11-02' => $answer, '2026-11-03' => $answer]);

            [$code] = $this->sync();

            $this->assertSame(1, $code);
            $this->assertSame([['2026-11-03', 'GLOW TAPE', 1, 48, null]], $this->rows());
            $this->assertSame('2026-11-03 09:10:00', $this->settingValue('nexus_stock_last_synced_at'));
            $err = json_decode((string) $this->settingValue('nexus_stock_last_error'), true);
            $this->assertSame($answer === 500 ? 'http_error' : 'timeout', $err['kind']);
            $this->assertSame('2026-11-03 10:10:00', $err['at']);
        }
    }

    public function test_S_220_2_3_a_401_stops_the_run_and_is_recorded_as_unauthorized(): void
    {
        $this->alreadySynced();
        $this->nexusStockIn('GLOW TAPE', 48, '2026-11-03');
        $this->fakeNexus(['2026-11-02' => 401, '2026-11-03' => 401]);

        $this->sync();

        $this->assertCount(1, $this->requestedDates(), 'hindi na itinutuloy: lahat ng araw ay 401 din');
        $this->assertSame([['2026-11-03', 'GLOW TAPE', 1, 48, null]], $this->rows());
        $this->assertSame('unauthorized', json_decode((string) $this->settingValue('nexus_stock_last_error'), true)['kind']);
        $this->assertSame('2026-11-03 09:10:00', $this->settingValue('nexus_stock_last_synced_at'));
    }

    public function test_S_220_2_4_malformed_json_changes_nothing_and_an_empty_unstored_day_is_quiet(): void
    {
        $this->alreadySynced();
        $this->nexusStockIn('GLOW TAPE', 48, '2026-11-03');
        $this->fakeNexus(['2026-11-02' => [$this->item('ROPE', 2)], '2026-11-03' => 'malformed']);

        $this->sync();

        $this->assertSame([['2026-11-02', 'ROPE', 1, 2, null], ['2026-11-03', 'GLOW TAPE', 1, 48, null]], $this->rows());
        $this->assertSame('2026-11-03 09:10:00', $this->settingValue('nexus_stock_last_synced_at'));
        $this->assertSame('malformed', json_decode((string) $this->settingValue('nexus_stock_last_error'), true)['kind']);

        // Walang dumating na kahit ano ngayong araw at wala pang naka-store: normal, success.
        DB::table('nexus_stock_ins')->delete();
        $this->alreadySynced();
        $this->fakeNexus([]);
        $this->sync();
        $this->assertSame('2026-11-03 10:10:00', $this->settingValue('nexus_stock_last_synced_at'));
        $this->assertSame('', (string) $this->settingValue('nexus_stock_last_error'));
    }

    public function test_S_220_2_4_an_answer_for_another_date_is_malformed(): void
    {
        $this->alreadySynced();
        Http::fake(['nexus.test/api/item-stock*' => Http::response(
            ['as_of' => '2026-11-03 10:00:00', 'from' => '2026-10-01', 'to' => '2026-10-31', 'items' => [$this->item('GLOW TAPE', 999)]], 200
        )]);

        $this->sync();

        $this->assertSame([], $this->rows());
        $this->assertSame('malformed', json_decode((string) $this->settingValue('nexus_stock_last_error'), true)['kind']);
    }

    public function test_S_220_2_5_partial_windows_store_the_good_days_and_the_failed_day_is_asked_again_next_hour(): void
    {
        $days = [];
        foreach (\Carbon\CarbonPeriod::create('2026-10-02', '2026-11-03') as $d) $days[$d->toDateString()] = [$this->item('GLOW TAPE', 1)];
        $days['2026-10-15'] = 500;
        $this->fakeNexus($days);

        $this->sync();

        $this->assertSame(32, DB::table('nexus_stock_ins')->count(), 'ang ibang araw ay naka-store');
        $this->assertSame('2026-10-14', $this->settingValue('nexus_stock_synced_through'));
        $this->assertNull($this->settingValue('nexus_stock_last_synced_at'), 'hindi buo: hindi pa "synced"');

        $days['2026-10-15'] = [$this->item('GLOW TAPE', 1)];
        $this->fakeNexus($days);
        $this->sync();

        $this->assertSame('2026-10-14', $this->requestedDates()[0]);
        $this->assertSame(33, DB::table('nexus_stock_ins')->count());
        $this->assertSame('2026-11-03', $this->settingValue('nexus_stock_synced_through'));
        $this->assertSame('2026-11-03 10:10:00', $this->settingValue('nexus_stock_last_synced_at'));
    }

    public function test_S_220_2_9_after_a_day_of_failures_the_first_success_catches_up_and_clears_the_error(): void
    {
        $this->appSetting('nexus_stock_synced_through', '2026-11-01');
        $this->appSetting('nexus_stock_last_synced_at', '2026-11-01 23:10:00');
        $this->appSetting('nexus_stock_last_error', json_encode(['at' => '2026-11-03 09:10:00', 'kind' => 'http_error', 'message' => 'HTTP 500']));
        $this->fakeNexus(['2026-11-02' => [$this->item('GLOW TAPE', 5)]]);

        $this->sync();

        $this->assertSame(['2026-11-01', '2026-11-02', '2026-11-03'], $this->requestedDates());
        $this->assertSame('2026-11-03 10:10:00', $this->settingValue('nexus_stock_last_synced_at'));
        $this->assertSame('', (string) $this->settingValue('nexus_stock_last_error'));
    }

    public function test_an_item_missing_from_a_later_valid_answer_for_a_stored_day_is_set_to_0_and_logged(): void
    {
        // Ang sagot ng Nexus para sa araw D ay may bawat item na may dumating noong D o may laman sa shelf ngayon.
        // Wala sa sagot ng D = walang dumating noong D: 0 para sa D lang; ang ibang araw ng item ay hindi ginagalaw.
        $this->alreadySynced();
        $this->nexusStockIn('GLOW TAPE', 10, '2026-11-03');
        $this->nexusStockIn('OLD NAME', 5, '2026-11-03');
        $this->nexusStockIn('OLD NAME', 7, '2026-11-01');
        $this->fakeNexus(['2026-11-03' => [$this->item('GLOW TAPE', 12), $this->item('ROPE', 0, null, 9)]]);   // ROPE: shelf lang

        $this->sync();

        $this->assertSame([
            ['2026-11-01', 'OLD NAME', 1, 7, null],
            ['2026-11-03', 'GLOW TAPE', 1, 12, null], ['2026-11-03', 'OLD NAME', 0, 0, null], ['2026-11-03', 'ROPE', 0, 0, 9],
        ], $this->rows());
        $zeroed = array_values(array_filter($this->logs, fn ($l) => str_contains($l, 'zeroed')));
        $this->assertCount(1, $zeroed);
        $this->assertStringContainsString('2026-11-03', $zeroed[0]);
        $this->assertStringContainsString(\App\Services\Nexus\StockInSync::nameKey('OLD NAME'), $zeroed[0]);
        $this->assertStringContainsString('"old_pieces":5', $zeroed[0]);
    }

    public function test_from_option_asks_again_from_a_day_but_never_before_the_start(): void
    {
        $this->alreadySynced();
        $this->fakeNexus([]);

        $this->sync(['--from' => '2026-09-01']);
        $this->assertSame('2026-10-02', $this->requestedDates()[0]);

        $this->fakeNexus([]);
        [$code] = $this->sync(['--from' => '2026-13-45']);
        $this->assertSame(1, $code);
        Http::assertNothingSent();
    }

    // ── Review fixes ─────────────────────────────────────────────────────────

    public function test_a_skipped_item_keeps_its_stored_row_and_the_skip_is_logged(): void
    {
        foreach (['48.0', 48.5, -3, 'abc'] as $bad) {
            DB::table('nexus_stock_ins')->delete();
            $this->logs = [];
            $this->alreadySynced();
            $this->nexusStockIn('GLOW TAPE', 48, '2026-11-03');
            $this->fakeNexus(['2026-11-03' => [$this->item('glow tape', $bad), $this->item('ROPE', 2)]]);

            $this->sync();

            $this->assertSame([['2026-11-03', 'GLOW TAPE', 1, 48, null], ['2026-11-03', 'ROPE', 1, 2, null]], $this->rows(), var_export($bad, true));
            $this->assertNotEmpty(array_filter($this->logs, fn ($l) => str_contains($l, 'item skipped') && str_contains($l, 'glow tape')));
            $this->assertEmpty(array_filter($this->logs, fn ($l) => str_contains($l, 'zeroed')));
        }
    }

    public function test_S_220_2_4_a_non_empty_answer_with_only_invalid_items_is_malformed_and_changes_nothing(): void
    {
        $this->alreadySynced();
        $this->nexusStockIn('GLOW TAPE', 48, '2026-11-03');
        $this->fakeNexus(['2026-11-02' => [$this->item('ROPE', -1)], '2026-11-03' => [$this->item('GLOW TAPE', 'x'), ['item' => '']]]);

        $this->sync();

        $this->assertSame([['2026-11-03', 'GLOW TAPE', 1, 48, null]], $this->rows());
        $this->assertSame('2026-11-03 09:10:00', $this->settingValue('nexus_stock_last_synced_at'));
        $this->assertSame('malformed', json_decode((string) $this->settingValue('nexus_stock_last_error'), true)['kind']);

        // Hindi umaabante ang synced_through kapag puro sirang item ang sagot ng isang araw.
        DB::table('nexus_stock_ins')->delete();
        $this->appSetting('nexus_stock_synced_through', '2026-11-01');
        $this->fakeNexus(['2026-11-01' => [$this->item('A', 1)], '2026-11-02' => [$this->item('ROPE', -1)]]);
        $this->sync();
        $this->assertSame('2026-11-01', $this->settingValue('nexus_stock_synced_through'));
    }

    private function unconfirmed(): array
    {
        return json_decode((string) $this->settingValue('nexus_stock_unconfirmed_empty'), true) ?? [];
    }

    public function test_an_empty_answer_for_a_stored_day_keeps_the_rows_is_listed_and_does_not_fail_the_run(): void
    {
        // Outage pagkalipas ng hatinggabi: 200 na walang items para sa kahapon na may naka-store.
        Carbon::setTestNow(Carbon::parse('2026-11-04 00:10:00', 'Asia/Manila'));
        $this->alreadySynced();                                            // synced through 11-03
        $this->nexusStockIn('GLOW TAPE', 48, '2026-11-03');
        $this->fakeNexus([]);                                              // 11-03 at 11-04: walang laman

        [$code] = $this->sync();

        $this->assertSame(0, $code, 'hindi pagkabigo ng takbo');
        $this->assertSame([['2026-11-03', 'GLOW TAPE', 1, 48, null]], $this->rows(), 'nananatili ang dati');
        $this->assertEmpty(array_filter($this->logs, fn ($l) => str_contains($l, 'zeroed')));
        $this->assertSame(['2026-11-03'], $this->unconfirmed());
        $this->assertSame('2026-11-04', $this->settingValue('nexus_stock_synced_through'));
        $this->assertSame('2026-11-04 00:10:00', $this->settingValue('nexus_stock_last_synced_at'));
        $this->assertSame('', (string) $this->settingValue('nexus_stock_last_error'));
        $this->assertSame([], json_decode((string) $this->settingValue('nexus_stock_pending_days'), true), 'hindi itinatanong nang paulit-ulit');

        // Ang sumunod na oras: ngayon at kahapon lang ang tinatanong; walang laman pa rin = nasa listahan pa rin.
        Carbon::setTestNow(Carbon::parse('2026-11-04 01:10:00', 'Asia/Manila'));
        $this->fakeNexus([]);
        $this->sync();
        $this->assertSame(['2026-11-03', '2026-11-04'], $this->requestedDates());
        $this->assertSame(['2026-11-03'], $this->unconfirmed());
        $this->assertSame(48, (int) DB::table('nexus_stock_ins')->value('stock_in_pieces'));
    }

    public function test_the_daily_pass_stores_a_day_that_has_items_again_and_drops_it_from_the_list(): void
    {
        $this->alreadySynced();
        $this->nexusStockIn('GLOW TAPE', 48, '2026-10-30');
        $this->appSetting('nexus_stock_unconfirmed_empty', json_encode(['2026-10-30']));
        $this->fakeNexus(['2026-10-30' => [$this->item('GLOW TAPE', 40)]]);

        $this->sync(['--days' => '7']);

        $this->assertSame([['2026-10-30', 'GLOW TAPE', 1, 40, null]], $this->rows());
        $this->assertSame([], $this->unconfirmed());
    }

    public function test_accept_empty_zeroes_a_day_only_when_nexus_still_answers_empty(): void
    {
        $this->alreadySynced();
        $this->nexusStockIn('GLOW TAPE', 48, '2026-11-01');
        $this->nexusStockIn('ROPE', 3, '2026-11-01');
        $this->nexusStockIn('GLOW TAPE', 5, '2026-11-02');
        $this->appSetting('nexus_stock_unconfirmed_empty', json_encode(['2026-11-01', '2026-11-02']));

        // Hindi makuha ang Nexus: walang nagbabago, nasa listahan pa rin.
        $this->fakeNexus(['2026-11-01' => 500]);
        [$code] = $this->sync(['--accept-empty' => '2026-11-01']);
        $this->assertSame(1, $code);
        $this->assertSame(48, (int) DB::table('nexus_stock_ins')->where('stock_date', '2026-11-01')->where('item_name', 'GLOW TAPE')->value('stock_in_pieces'));
        $this->assertSame(['2026-11-01', '2026-11-02'], $this->unconfirmed());

        // Walang laman pa rin: 0 ang bawat row ng araw na iyon (naka-log), alis sa listahan; ang ibang araw ay hindi ginagalaw.
        $this->logs = [];
        $this->fakeNexus([]);
        [$code, $out] = $this->sync(['--accept-empty' => '2026-11-01']);
        $this->assertSame(0, $code);
        $this->assertSame(['2026-11-01'], $this->requestedDates());
        $this->assertStringContainsString('2 stored items set to 0', $out);
        $this->assertSame([0, 0], DB::table('nexus_stock_ins')->where('stock_date', '2026-11-01')->orderBy('item_name')->pluck('stock_in_pieces')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(5, (int) DB::table('nexus_stock_ins')->where('stock_date', '2026-11-02')->value('stock_in_pieces'));
        $this->assertCount(2, array_filter($this->logs, fn ($l) => str_contains($l, 'zeroed') && str_contains($l, '2026-11-01')));
        $this->assertSame(['2026-11-02'], $this->unconfirmed());

        // May laman na ulit: store gaya ng dati, walang ginagawang 0 dahil sa pagiging walang laman, alis sa listahan.
        $this->fakeNexus(['2026-11-02' => [$this->item('GLOW TAPE', 6)]]);
        [$code] = $this->sync(['--accept-empty' => '2026-11-02']);
        $this->assertSame(0, $code);
        $this->assertSame(6, (int) DB::table('nexus_stock_ins')->where('stock_date', '2026-11-02')->value('stock_in_pieces'));
        $this->assertSame([], $this->unconfirmed());

        // Hindi totoong petsa o labas sa start..ngayon: walang tinatanong.
        foreach (['2026-13-01', '2026-09-01', '2026-11-04'] as $bad) {
            $this->fakeNexus([]);
            [$code] = $this->sync(['--accept-empty' => $bad]);
            $this->assertSame(1, $code, $bad);
            Http::assertNothingSent();
        }
    }

    public function test_a_pinned_live_sample_of_the_nexus_answer_is_stored(): void
    {
        // Ang anyo ng totoong sagot ng Nexus (prod, read-only na tingin): stock_in = [{date "Y-m-d", lots, pieces}],
        // shelf-only na item = stock_in [], int ang stock_in_pieces at remaining, as_of DATE_ATOM.
        Carbon::setTestNow(Carbon::parse('2026-11-01 10:10:00', 'Asia/Manila'));
        $this->appSetting('nexus_stock_synced_through', '2026-11-01');
        Http::swap(new \Illuminate\Http\Client\Factory(app('events')));
        Http::preventStrayRequests();
        Http::fake(['nexus.test/api/item-stock?date=2026-10-31' => Http::response(json_decode(<<<'JSON'
            {"as_of":"2026-11-01T10:09:41+08:00","from":"2026-10-31","to":"2026-10-31","items":[
              {"item":"GLOW TAPE","stock_in":[{"date":"2026-10-31","lots":2,"pieces":48}],"stock_in_pieces":48,"remaining":131},
              {"item":"HAND GRIP","stock_in":[],"stock_in_pieces":0,"remaining":17}
            ]}
            JSON, true), 200),
            'nexus.test/api/item-stock?date=2026-11-01' => Http::response(['as_of' => '2026-11-01T10:09:42+08:00', 'from' => '2026-11-01', 'to' => '2026-11-01', 'items' => []], 200),
        ]);

        [$code] = $this->sync();

        $this->assertSame(0, $code);
        $this->assertSame([['2026-10-31', 'GLOW TAPE', 2, 48, 131], ['2026-10-31', 'HAND GRIP', 0, 0, 17]], $this->rows());
        $this->assertSame('2026-11-01 10:09:41', substr((string) DB::table('nexus_stock_ins')->value('as_of'), 0, 19));
        $this->assertSame('', (string) $this->settingValue('nexus_stock_last_error'));
    }

    public function test_a_stock_in_date_is_compared_as_a_manila_date_even_if_nexus_sends_a_datetime(): void
    {
        $this->alreadySynced();
        $entry = fn (string $date) => [['date' => $date, 'lots' => 1, 'pieces' => 2]];
        $this->fakeNexus(['2026-11-02' => [
            ['item' => 'PLAIN', 'stock_in' => $entry('2026-11-02'), 'stock_in_pieces' => 2],
            ['item' => 'WITH OFFSET', 'stock_in' => $entry('2026-11-02T09:00:00+08:00'), 'stock_in_pieces' => 2],
            ['item' => 'WITH TIME', 'stock_in' => $entry('2026-11-02 09:00:00'), 'stock_in_pieces' => 2],
            ['item' => 'UTC LATE', 'stock_in' => $entry('2026-11-01T16:30:00Z'), 'stock_in_pieces' => 2],   // 11-02 00:30 Manila
            ['item' => 'UTC EARLY', 'stock_in' => $entry('2026-11-01T15:59:59Z'), 'stock_in_pieces' => 2],  // 11-01 23:59 Manila
            ['item' => 'NOT A DATE', 'stock_in' => $entry('yesterday-ish'), 'stock_in_pieces' => 2],
        ]]);

        $this->sync();

        $this->assertSame(['PLAIN', 'UTC LATE', 'WITH OFFSET', 'WITH TIME'], array_column($this->rows(), 1));
    }

    public function test_skipped_items_leave_a_non_failing_warning_that_a_clean_run_clears(): void
    {
        $this->alreadySynced();
        $this->fakeNexus(['2026-11-02' => [$this->item('GLOW TAPE', 5), $this->item('ROPE', -1), $this->item('LAMP', 'x')],
                          '2026-11-03' => [$this->item('PEN', 1.5), ['item' => '', 'stock_in_pieces' => 1], $this->item('GLOW TAPE', 1)]]);

        [$code] = $this->sync();

        $this->assertSame(0, $code, 'ok pa rin ang takbo');
        $this->assertSame('2026-11-03 10:10:00', $this->settingValue('nexus_stock_last_synced_at'));
        $err = json_decode((string) $this->settingValue('nexus_stock_last_error'), true);
        $this->assertSame('skipped', $err['kind']);
        $this->assertSame(4, $err['count']);
        $this->assertSame(['ROPE', 'LAMP', 'PEN', '(no name)'], $err['names']);
        $this->assertSame('4 Nexus items skipped for bad values: ROPE, LAMP, PEN, (no name)', $err['message']);

        $this->fakeNexus(['2026-11-02' => [$this->item('GLOW TAPE', 5)], '2026-11-03' => [$this->item('GLOW TAPE', 1)]]);
        $this->sync();
        $this->assertSame('', (string) $this->settingValue('nexus_stock_last_error'));
    }

    public function test_days_after_a_429_in_a_long_run_go_to_the_pending_list(): void
    {
        $this->alreadySynced();
        $this->fakeNexus(['2026-10-22' => 429]);

        $this->sync(['--from' => '2026-10-20']);

        $this->assertSame(['2026-10-20', '2026-10-21', '2026-10-22'], $this->requestedDates());
        $expected = array_map(fn ($d) => $d->toDateString(), iterator_to_array(\Carbon\CarbonPeriod::create('2026-10-22', '2026-11-01')));
        $this->assertSame($expected, json_decode((string) $this->settingValue('nexus_stock_pending_days'), true),
            'ang 429 na araw at ang hindi na naitanong, hanggang bago ang karaniwang hanay (11-02)');
    }

    public function test_the_real_nexus_shape_counts_the_lots_of_the_asked_day_and_the_old_number_shape_still_works(): void
    {
        $this->alreadySynced();
        $this->fakeNexus(['2026-11-02' => [
            ['item' => 'GLOW TAPE', 'stock_in' => [['date' => '@', 'lots' => 2, 'pieces' => 30], ['date' => '@', 'lots' => 1, 'pieces' => 18]], 'stock_in_pieces' => 48, 'remaining' => 120],
            ['item' => 'ROPE', 'stock_in' => [], 'stock_in_pieces' => 0, 'remaining' => 9],                       // shelf lang
            ['item' => 'LAMP', 'stock_in' => 3, 'stock_in_pieces' => 12, 'remaining' => null],                    // lumang anyo
            ['item' => 'ODD DATE', 'stock_in' => [['date' => '2026-10-01', 'lots' => 1, 'pieces' => 4]], 'stock_in_pieces' => 4],
            ['item' => 'BAD LOTS', 'stock_in' => [['date' => '@', 'lots' => -1, 'pieces' => 4]], 'stock_in_pieces' => 4],
        ]]);

        $this->sync();

        $this->assertSame([
            ['2026-11-02', 'GLOW TAPE', 3, 48, 120], ['2026-11-02', 'LAMP', 3, 12, null], ['2026-11-02', 'ROPE', 0, 0, 9],
        ], $this->rows());
        $this->assertCount(2, array_filter($this->logs, fn ($l) => str_contains($l, 'item skipped')));
    }

    public function test_a_429_stops_the_run_and_the_rest_is_asked_next_run(): void
    {
        $this->appSetting('nexus_stock_synced_through', '2026-10-31');
        $this->fakeNexus(['2026-10-31' => [$this->item('A', 1)], '2026-11-01' => 429, '2026-11-02' => [$this->item('B', 1)]]);

        [$code] = $this->sync();

        $this->assertSame(1, $code);
        $this->assertSame(['2026-10-31', '2026-11-01'], $this->requestedDates());
        $this->assertSame('rate_limited', json_decode((string) $this->settingValue('nexus_stock_last_error'), true)['kind']);
        $this->assertSame('2026-10-31', $this->settingValue('nexus_stock_synced_through'));
    }

    public function test_a_daily_pass_that_cannot_get_the_lock_is_recorded_not_silent(): void
    {
        $this->alreadySynced();
        $lock = Cache::lock('nexus:sync-stock', 60);
        $this->assertTrue($lock->get());

        $this->sync(['--days' => '7']);

        $this->assertSame(['at' => '2026-11-03 10:10:00', 'status' => 'skipped_locked'], json_decode((string) $this->settingValue('nexus_stock_daily_pass'), true));
        $this->assertNotEmpty(array_filter($this->logs, fn ($l) => str_contains($l, 'daily pass skipped')));
        $lock->release();

        $this->fakeNexus([]);
        $this->sync(['--days' => '7']);
        $this->assertSame('ok', json_decode((string) $this->settingValue('nexus_stock_daily_pass'), true)['status']);
        $this->sync();                                                    // ang hourly ay hindi nagsusulat dito
        $this->assertSame('ok', json_decode((string) $this->settingValue('nexus_stock_daily_pass'), true)['status']);
    }

    public function test_the_daily_pass_asks_the_last_7_days_and_the_hourly_stays_on_yesterday_and_today(): void
    {
        Artisan::call('schedule:list');
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'nexus:sync-stock'))
            ->mapWithKeys(fn ($e) => [trim(substr((string) $e->command, strpos((string) $e->command, 'nexus:sync-stock'))) => $e]);

        $this->assertSame(['nexus:sync-stock', 'nexus:sync-stock --days=7'], $events->keys()->sort()->values()->all());
        $this->assertSame('10 * * * *', $events['nexus:sync-stock']->expression);
        $this->assertSame('40 2 * * *', $events['nexus:sync-stock --days=7']->expression);
        $this->assertSame('Asia/Manila', $events['nexus:sync-stock --days=7']->timezone);
        $this->assertTrue($events['nexus:sync-stock --days=7']->withoutOverlapping);

        $this->alreadySynced();
        $this->fakeNexus([]);
        $this->sync(['--days' => '7']);
        $this->assertSame(['2026-10-27', '2026-10-28', '2026-10-29', '2026-10-30', '2026-10-31', '2026-11-01', '2026-11-02', '2026-11-03'], $this->requestedDates());
    }

    public function test_days_that_fail_in_a_from_run_are_asked_again_by_the_next_hourly_run(): void
    {
        $this->alreadySynced();
        $this->fakeNexus(['2026-10-20' => 500, '2026-10-25' => 'timeout']);
        $this->sync(['--from' => '2026-10-19']);

        $this->assertSame(['2026-10-20', '2026-10-25'], json_decode((string) $this->settingValue('nexus_stock_pending_days'), true));

        $this->fakeNexus(['2026-10-20' => [$this->item('GLOW TAPE', 3)], '2026-10-25' => 500]);
        $this->sync();
        $this->assertSame(['2026-10-20', '2026-10-25', '2026-11-02', '2026-11-03'], $this->requestedDates());
        $this->assertSame(['2026-10-25'], json_decode((string) $this->settingValue('nexus_stock_pending_days'), true));
        $this->assertSame(3, (int) DB::table('nexus_stock_ins')->where('stock_date', '2026-10-20')->value('stock_in_pieces'));

        $this->fakeNexus([]);
        $this->sync();
        $this->assertSame([], json_decode((string) $this->settingValue('nexus_stock_pending_days'), true));
    }

    public function test_nbsp_and_other_unicode_spaces_are_normalised_and_trimmed(): void
    {
        $this->alreadySynced();
        $this->fakeNexus(['2026-11-02' => [$this->item("\u{00A0}Glow\u{00A0}\u{2009}Tape\u{3000}", 5), $this->item('glow tape', 2)]]);

        $this->sync();

        $this->assertSame(\App\Services\Nexus\StockInSync::nameKey('glow tape'), \App\Services\Nexus\StockInSync::nameKey("\u{00A0}GLOW\u{202F}TAPE\u{00A0}"));
        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame("Glow\u{00A0}\u{2009}Tape", $rows[0][1], 'ang dulo lang ang tinatanggal; ang loob ay buo');
        $this->assertSame(7, $rows[0][3]);
    }
}

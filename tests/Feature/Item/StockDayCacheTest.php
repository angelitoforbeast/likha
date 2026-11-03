<?php

namespace Tests\Feature\Item;

use App\Services\Stock\JntReturnedSource;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Shipped at returned kada araw: ang saradong araw (bago ang kahapon, Manila) ay naka-cache nang 26 oras kada araw;
 * kahapon at ngayon lang ang live. Gabi-gabi (stock:warm --closed-days=7, 02:55) ay kinukuwenta ulit ang huling 7
 * saradong araw, hiwalay sa Nexus daily pass.
 */
class StockDayCacheTest extends ItemTestCase
{
    private ?\App\Models\User $ceo = null;

    private int $wb = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->nexusOn();
        config(['app.url' => 'http://localhost']);
        $this->appSetting('item_stock_start', '2026-10-02');
        $this->appSetting('nexus_stock_last_synced_at', '2026-11-03 09:10:00');
        $this->appSetting('nexus_stock_synced_through', '2026-11-03');
        Carbon::setTestNow(Carbon::parse('2026-11-03 10:10:00', 'Asia/Manila'));
        $this->nexusStockIn('GLOW TAPE', 100, '2026-10-10');
        $this->ship('2026-10-12 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function ship(string $at, ?string $status = 'Delivered', ?string $signed = null): void
    {
        $wb = 'D' . (++$this->wb);
        $this->order('1 x GLOW TAPE', 'Page A', $wb, substr($at, 0, 10));
        $this->shipped($wb, $at, $status, $signed);
    }

    /** @return array{0: array, 1: array<int,array{0:string,1:array}>} */
    private function page(): array
    {
        $this->ceo ??= $this->user();
        $q = [];
        DB::listen(function ($e) use (&$q) { $q[] = [strtolower($e->sql), $e->bindings]; });
        $it = $this->actingAs($this->ceo)->getJson('/item/stock')->assertOk()->json('items.glow tape');

        return [$it, $q];
    }

    private function newSync(string $at): void
    {
        $this->appSetting('nexus_stock_last_synced_at', $at);   // bagong live key; ang saradong araw ay pareho
    }

    public function test_closed_days_come_from_a_26_hour_per_day_cache_and_only_yesterday_and_today_are_live(): void
    {
        [$it] = $this->page();
        $this->assertSame([1, 99], [$it['shipped'], $it['stock']]);

        $this->ship('2026-10-20 10:00:00');                        // saradong araw, pagkatapos ma-cache
        $this->ship('2026-11-03 09:00:00');                        // ngayon (live)
        $this->newSync('2026-11-03 10:15:00');
        [$it, $q] = $this->page();

        $this->assertSame(2, $it['shipped'], 'ngayon ay bilang; ang 10-20 ay galing sa cache ng saradong araw');
        $shippedQueries = array_values(array_filter($q, fn ($e) => str_contains($e[0], 'date(f.submission_time)')));
        $this->assertCount(1, $shippedQueries, 'live lang');
        $this->assertSame(['2026-11-02 00:00:00', '2026-11-03 23:59:59'], array_slice($shippedQueries[0][1], 0, 2));

        Carbon::setTestNow(Carbon::parse('2026-11-04 12:11:00', 'Asia/Manila'));   // lampas 26 oras
        $this->newSync('2026-11-04 12:10:00');
        [$it] = $this->page();
        $this->assertSame(3, $it['shipped'], 'pagkalipas ng 26 oras ang saradong araw ay kinuwenta ulit');
    }

    public function test_the_nightly_closed_day_warm_refreshes_the_last_7_closed_days_even_when_nexus_fails(): void
    {
        $this->page();
        $this->ship('2026-10-30 10:00:00');                        // nasa huling 7 saradong araw (10-26..11-01)
        $this->ship('2026-10-15 10:00:00');                        // mas luma
        Http::fake(['nexus.test/*' => fn (Request $r) => Http::response(['message' => 'down'], 500)]);

        Carbon::setTestNow(Carbon::parse('2026-11-03 10:20:00', 'Asia/Manila'));
        $this->assertSame(1, Artisan::call('nexus:sync-stock', ['--days' => '7']), 'bumagsak ang Nexus daily pass');
        $this->assertSame(0, Artisan::call('stock:warm', ['--closed-days' => '7']));

        [$it, $q] = $this->page();
        $this->assertSame(2, $it['shipped'], 'ang 10-30 ay nakuha ng nightly refresh kahit bumagsak ang Nexus; ang 10-15 ay hindi pa');
        $this->assertSame([], array_values(array_filter($q, fn ($e) => str_contains($e[0], 'from_jnts') && !str_contains($e[0], 'left join'))),
            'naipainit: walang stock query sa page');
    }

    public function test_the_nexus_daily_pass_no_longer_refreshes_closed_days_but_still_warms_after_success(): void
    {
        $this->page();
        $this->ship('2026-10-30 10:00:00');
        Http::fake(['nexus.test/*' => fn (Request $r) => Http::response(['as_of' => '2026-11-03T10:20:00+08:00', 'items' => []], 200)]);

        Carbon::setTestNow(Carbon::parse('2026-11-03 10:20:00', 'Asia/Manila'));
        $this->assertSame(0, Artisan::call('nexus:sync-stock', ['--days' => '7']));
        $this->assertStringContainsString('Warmed the /item stock inputs', Artisan::output());

        [$it, $q] = $this->page();
        $this->assertSame(1, $it['shipped'], 'ang 10-30 ay galing pa sa cache: ang closed-day refresh ay nasa sariling schedule');
        $this->assertSame([], array_values(array_filter($q, fn ($e) => str_contains($e[0], 'from_jnts') && !str_contains($e[0], 'left join'))),
            'naipainit pa rin ng sync');
    }

    public function test_a_regular_warm_only_runs_the_live_queries_once_the_closed_days_are_cached(): void
    {
        $this->page();
        $q = [];
        DB::listen(function ($e) use (&$q) { $q[] = [strtolower($e->sql), $e->bindings]; });

        $this->assertSame(0, Artisan::call('stock:warm'));

        $grouped = array_values(array_filter($q, fn ($e) => str_contains($e[0], 'from_jnts') && str_contains($e[0], 'group by') && !str_contains($e[0], 'left join')));
        $this->assertCount(2, $grouped, 'live shipped at live returned');
        foreach ($grouped as [, $bindings]) $this->assertContains('2026-11-02 00:00:00', $bindings);
    }

    public function test_the_returned_main_query_is_a_plain_signingtime_range_and_the_flags_are_a_separate_global_count(): void
    {
        $this->ship('2026-10-12 10:00:00', 'Returned', '2026-10-20 10:00:00');
        $this->ship('2026-10-12 10:00:00', 'Returned', null);
        $this->ship('2026-10-12 10:00:00', 'Returned', '2026-11-05 10:00:00');
        $this->ship('2026-09-01 10:00:00', 'Returned', null);      // bago ang simula: kasama pa rin sa flags (global)

        $this->assertStringContainsString('fj.status = ? AND fj.signingtime >= ? AND fj.signingtime <= ?', JntReturnedSource::sql());
        $this->assertStringNotContainsString('signingtime IS NULL', JntReturnedSource::sql());

        $json = $this->actingAs($this->user('CEO', 'x' . uniqid() . '@example.test'))->getJson('/item/stock')->assertOk()->json();
        $this->assertSame(1, $json['items']['glow tape']['returned']);
        $this->assertSame(['unsigned' => 2, 'future' => 1], $json['stock_meta']['returned']['flags']);
        $this->assertSame('all items, any date (not per item start)', $json['stock_meta']['returned']['flags_scope']);
    }

    public function test_the_flags_count_is_cached_for_an_hour_and_the_warm_refreshes_it_only_after_45_minutes(): void
    {
        $this->page();
        $flagQueries = function (): int {
            $n = 0;
            DB::listen(function ($e) use (&$n) { if (str_contains(strtolower($e->sql), 'select (select count(*)')) $n++; });
            Artisan::call('stock:warm');

            return $n;
        };

        Carbon::setTestNow(Carbon::parse('2026-11-03 10:50:00', 'Asia/Manila'));
        $this->assertSame(0, $flagQueries(), '40 minuto: ang naka-cache pa');
        Carbon::setTestNow(Carbon::parse('2026-11-03 10:56:00', 'Asia/Manila'));
        $this->assertSame(1, $flagQueries(), '46 minuto: kinuwenta ulit ng warm');
    }
}

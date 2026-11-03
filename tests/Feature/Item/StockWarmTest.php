<?php

namespace Tests\Feature\Item;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * Ang stock inputs ng /item ay pinapainit sa background: pagkatapos ng bawat matagumpay na nexus:sync-stock, at ng
 * stock:warm kada 10 minuto, para halos hindi na tamaan ng page ang mabagal na cold na kuwenta.
 */
class StockWarmTest extends ItemTestCase
{
    private ?\App\Models\User $ceo = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->nexusOn();
        config(['app.url' => 'http://localhost']);   // ang host ng page sa test = ang host ng warm
        $this->appSetting('item_stock_start', '2026-09-25');
        $this->appSetting('nexus_stock_last_synced_at', '2026-11-03 09:10:00');
        $this->appSetting('nexus_stock_synced_through', '2026-11-03');
        Carbon::setTestNow(Carbon::parse('2026-11-03 10:10:00', 'Asia/Manila'));

        for ($i = 1; $i <= 3; $i++) {
            $this->order('1 x GLOW TAPE', 'Page A', 'G' . $i, '2026-09-26');
            $this->shipped('G' . $i, '2026-09-26 10:00:00', $i === 1 ? 'Returned' : 'Delivered', $i === 1 ? '2026-10-05 12:00:00' : null);
        }
        $this->nexusStockIn('GLOW TAPE', 10, '2026-09-26');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: array, 1: array<int,string>} [items, sql] */
    private function page(): array
    {
        $this->ceo ??= $this->user();
        $sql = [];
        DB::listen(function ($q) use (&$sql) { $sql[] = strtolower($q->sql); });
        $items = $this->actingAs($this->ceo)->getJson('/item/stock')->assertOk()->json('items');

        return [$items, $sql];
    }

    private function stockQueries(array $sql): array
    {
        return array_values(array_filter($sql, fn ($s) => (str_contains($s, 'from_jnts') && !str_contains($s, 'left join')) || str_contains($s, 'nexus_stock_ins')));
    }

    private function fakeNexus(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory(app('events')));
        Http::preventStrayRequests();
        Http::fake(['nexus.test/api/item-stock*' => function (Request $r) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
            $items = ($q['date'] ?? '') === '2026-11-03' ? [['item' => 'GLOW TAPE', 'stock_in' => [['date' => '2026-11-03', 'lots' => 1, 'pieces' => 5]], 'stock_in_pieces' => 5]] : [];
            return Http::response(['as_of' => '2026-11-03T10:09:00+08:00', 'from' => $q['date'] ?? null, 'to' => $q['date'] ?? null, 'items' => $items], 200);
        }]);
    }

    public function test_after_a_successful_sync_the_next_page_call_runs_no_stock_query(): void
    {
        $this->fakeNexus();

        $code = Artisan::call('nexus:sync-stock');
        $out  = Artisan::output();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Warmed the /item stock inputs', $out);
        [$items, $sql] = $this->page();
        $this->assertSame([], $this->stockQueries($sql), 'naipainit na: walang shipped, returned o nexus_stock_ins query');
        $this->assertSame(13, $items['glow tape']['stock'], '10 + 5 − 3 + 1');
    }

    public function test_the_warm_command_refreshes_the_key_before_the_ttl(): void
    {
        [$items] = $this->page();                                     // cold: naka-cache sa 10:10
        $this->assertSame(8, $items['glow tape']['stock']);
        $this->nexusStockIn('GLOW TAPE', 4, '2026-11-02');            // walang bagong sync: parehong key

        Carbon::setTestNow(Carbon::parse('2026-11-03 10:20:00', 'Asia/Manila'));
        $this->assertSame(0, Artisan::call('stock:warm'));
        Carbon::setTestNow(Carbon::parse('2026-11-03 10:33:00', 'Asia/Manila'));   // lampas na sa 15 min mula 10:10

        [$items, $sql] = $this->page();
        $this->assertSame(12, $items['glow tape']['stock'], 'ang bagong halaga mula sa warm ng 10:20');
        $this->assertSame([], $this->stockQueries($sql), 'buhay pa ang key: ang TTL ay mula sa warm');
    }

    public function test_a_failed_warm_leaves_the_old_value_in_place(): void
    {
        [$items] = $this->page();
        $this->assertSame(8, $items['glow tape']['stock']);
        Schema::rename('from_jnts', 'from_jnts_gone');                // babagsak ang kuwenta ng warm

        $code = Artisan::call('stock:warm');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('not warmed', Artisan::output());
        Schema::rename('from_jnts_gone', 'from_jnts');
        [$items, $sql] = $this->page();
        $this->assertSame(8, $items['glow tape']['stock']);
        $this->assertSame([], $this->stockQueries($sql), 'ang lumang halaga ay nasa cache pa');
    }

    public function test_a_warm_never_computes_while_a_page_request_holds_the_lock(): void
    {
        config(['services.nexus.cold_wait' => 1]);
        [$items] = $this->page();
        $this->nexusStockIn('GLOW TAPE', 4, '2026-11-02');
        $key = 'item_stock:stockin:v2:localhost:20261103091000:2026-11-03';
        $other = Cache::lock($key . ':lock', 60);
        $this->assertTrue($other->get(), 'isang page request ang kumukuwenta');

        $code = Artisan::call('stock:warm');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('busy', Artisan::output());
        $other->release();
        [$items] = $this->page();
        $this->assertSame(8, $items['glow tape']['stock'], 'hindi pinalitan ng warm ang hawak ng iba');
    }

    public function test_stock_warm_is_scheduled_every_10_minutes_and_does_nothing_without_nexus(): void
    {
        Artisan::call('schedule:list');
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'stock:warm'));

        $this->assertNotNull($event);
        $this->assertSame('5-59/10 * * * *', $event->expression, 'kada 10 minuto mula :05, wala sa :10 ng hourly sync');
        $this->assertSame('Asia/Manila', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);

        config(['services.nexus.token' => '']);
        $this->assertSame(0, Artisan::call('stock:warm'));
        $this->assertStringContainsString('Nexus not configured', Artisan::output());
        $this->assertSame([], Cache::get('item_stock:stockin:v2:localhost:20261103091000:2026-11-03') ?? []);
    }
}

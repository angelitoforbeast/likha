<?php

namespace Tests\Feature\Item;

use App\Services\Stock\NexusRtsReturnedSource;
use App\Services\Stock\ReturnedResult;
use App\Services\Stock\ReturnedSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * /item/stock (Nexus mode): bawat item ay binibilang mula sa sarili nitong unang Nexus stock-in:
 *   stock = lahat ng Nexus stock-in + J&T Returned na signed mula sa simula (kahit kailan na-ship)
 *           − shipped na ang unang J&T submission ay mula sa simula;  max(0), "Count the stock first" kapag < 0.
 * Walang Nexus stock-in kailanman = "Not in Nexus".
 */
class StockFromFirstStockInTest extends ItemTestCase
{
    private const URL = '/item/stock?start_date=2026-09-01&end_date=2026-11-03';

    private int $wb = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->nexusOn();
        $this->appSetting('item_stock_start', '2026-10-02');
        $this->appSetting('nexus_stock_last_synced_at', '2026-11-03 10:10:00');
        Carbon::setTestNow(Carbon::parse('2026-11-03 10:10:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function items(): array
    {
        return $this->actingAs($this->user('CEO', 'ceo' . uniqid() . '@example.test'))->getJson(self::URL)->assertOk()->json('items');
    }

    private function stockJson(): array
    {
        return $this->actingAs($this->user('CEO', 'ceo' . uniqid() . '@example.test'))->getJson(self::URL)->assertOk()->json();
    }

    /**
     * Mga parcel na may kabuuang $pieces na piraso ng $item ("100 x", "10 x", "1 x" na variant), bawat isa ay isang
     * waybill na unang na-submit noong $shippedAt, may status at signingtime.
     */
    private function parcels(string $item, int $pieces, string $shippedAt, string $status = 'Delivered', ?string $signedAt = null): void
    {
        $orders = [];
        $jnts   = [];
        foreach ([100, 10, 1] as $size) {
            $n = intdiv($pieces, $size);
            $pieces -= $n * $size;
            for ($i = 0; $i < $n; $i++) {
                $wb = 'W' . (++$this->wb);
                $orders[] = ['ITEM_NAME' => "$size x $item", 'PAGE' => 'Page A', 'TIMESTAMP' => '10:00 01-10-2026',
                             'STATUS' => 'PROCEED', 'waybill' => $wb, 'ts_date' => substr($shippedAt, 0, 10)];
                $jnts[] = ['waybill_number' => $wb, 'submission_time' => $shippedAt, 'status' => $status, 'signingtime' => $signedAt];
            }
        }
        foreach (array_chunk($orders, 400) as $c) DB::table('macro_output')->insert($c);
        foreach (array_chunk($jnts, 400) as $c) DB::table('from_jnts')->insert($c);
    }

    /** Isang item ayon sa paghahambing: In sa simula, Ship mula sa simula, Ret (na-ship bago ang simula, signed pagkatapos). */
    private function compared(string $name, int $in, string $start, int $ship, int $ret): void
    {
        $this->nexusStockIn($name, $in, $start);
        $this->parcels($name, $ship, "$start 10:00:00");
        $before = Carbon::parse($start)->subDays(3)->toDateString();
        $after  = Carbon::parse($start)->addDay()->toDateString();
        $this->parcels($name, $ret, "$before 10:00:00", 'Returned', "$after 12:00:00");
        // Ingay na hindi dapat mabilang: na-ship bago ang simula, at bumalik bago ang simula.
        $this->parcels($name, 7, "$before 09:00:00");
        $this->parcels($name, 5, "$before 09:00:00", 'Returned', "$before 18:00:00");
    }

    public function test_S_222_1_1_seat_cover_counts_from_its_first_stock_in_on_09_29(): void
    {
        $this->compared('SEAT COVER', 10000, '2026-09-29', 8105, 4613);

        $it = $this->items()['seat cover'];

        $this->assertSame(['2026-09-29', 10000, 8105, 4613, 6508, 6508, false, false],
            [$it['stock_start'], $it['stock_in'], $it['shipped'], $it['returned'], $it['stock_raw'], $it['stock'], $it['stock_needs_count'], $it['not_in_nexus']]);
    }

    public function test_S_222_1_7_the_items_that_matched_nexus_in_the_live_comparison_come_out_the_same(): void
    {
        // Mula sa read-only na paghahambing sa live na data, 2026-11-03 (In = lahat, Ship/Ret mula sa simula).
        $rows = [
            ['GLOW TAPE',             3016, '2026-10-09', 5239, 2434, 211],
            ['CHRISTMAS LIGHTS',      7000, '2026-10-24', 3876, 1002, 4126],
            ['REFLECTIVE STICKER',    2090, '2026-10-06', 3264, 1467, 293],
            ['RGB STROBE LIGHT',      3009, '2026-10-15', 3197, 559,  371],
            ['VINTAGE CURTAIN',       2046, '2026-10-09', 2234, 1211, 1023],
            ['INVISIBLE BELT',        2000, '2026-10-06', 2166, 862,  696],
            ['WATERPROOF PHONE CASE', 1826, '2026-10-08', 2136, 1288, 978],
            ['SEAT COVER',           10000, '2026-09-29', 8105, 4613, 6508],
        ];
        foreach ($rows as [$name, $in, $start, $ship, $ret]) $this->compared($name, $in, $start, $ship, $ret);

        $items = $this->items();

        foreach ($rows as [$name, $in, $start, $ship, $ret, $stock]) {
            $it = $items[strtolower($name)];
            $this->assertSame([$start, $in, $ship, $ret, $stock], [$it['stock_start'], $it['stock_in'], $it['shipped'], $it['returned'], $it['stock']], $name);
        }
    }

    public function test_S_222_1_2_and_1_3_shipments_before_the_items_first_stock_in_day_are_ignored_and_the_day_is_inclusive(): void
    {
        $this->nexusStockIn('CHRISTMAS LIGHTS', 100, '2026-10-24');
        $this->parcels('CHRISTMAS LIGHTS', 30, '2026-10-20 10:00:00');
        $this->parcels('CHRISTMAS LIGHTS', 2, '2026-10-23 23:59:59');
        $this->parcels('CHRISTMAS LIGHTS', 4, '2026-10-24 00:00:00');
        $this->parcels('CHRISTMAS LIGHTS', 6, '2026-10-30 10:00:00');
        // Isang waybill na unang na-submit bago ang simula pero may bagong row pagkatapos: bago pa rin (unang submission).
        $this->parcels('CHRISTMAS LIGHTS', 1, '2026-10-20 10:00:00');
        DB::table('from_jnts')->insert(['waybill_number' => 'W' . $this->wb, 'submission_time' => '2026-10-26 10:00:00', 'status' => 'Delivered']);

        $it = $this->items()['christmas lights'];

        $this->assertSame([10, 90], [$it['shipped'], $it['stock']]);
    }

    public function test_S_222_1_4_and_1_5_two_nexus_names_take_the_earlier_first_day_and_a_shelf_only_row_never_sets_it(): void
    {
        $this->nexusStockIn('GLOW TAPE', 50, '2026-10-10');
        $this->nexusStockIn('Glow-Tape', 20, '2026-10-05');
        $this->nexusStockIn('glow tape', 0, '2026-09-01', 0, 40, '2026-11-03 10:09:00');   // shelf lang, 0 pieces
        $this->parcels('GLOW TAPE', 3, '2026-10-04 10:00:00');
        $this->parcels('GLOW TAPE', 5, '2026-10-06 10:00:00');

        $it = $this->items()['glow tape'];

        $this->assertSame(['2026-10-05', 70, 5, 65], [$it['stock_start'], $it['stock_in'], $it['shipped'], $it['stock']]);
    }

    public function test_S_222_1_6_stock_in_before_the_global_start_counts(): void
    {
        $this->nexusStockIn('MINI FLASHLIGHT', 300, '2026-09-13');
        $this->nexusStockIn('MINI FLASHLIGHT', 100, '2026-10-20');
        $this->parcels('MINI FLASHLIGHT', 50, '2026-09-20 10:00:00');

        $it = $this->items()['mini flashlight'];

        $this->assertSame(['2026-09-13', 400, 50, 350], [$it['stock_start'], $it['stock_in'], $it['shipped'], $it['stock']]);
    }

    public function test_S_222_2_1_and_2_2_returns_count_from_the_items_start_whatever_the_ship_date(): void
    {
        $this->nexusStockIn('ROPE', 10, '2026-10-10');
        $this->parcels('ROPE', 3, '2026-10-01 10:00:00', 'Returned', '2026-10-15 12:00:00');   // late return: bilang
        $this->parcels('ROPE', 2, '2026-10-01 10:00:00', 'Returned', '2026-10-09 23:59:59');   // signed bago ang simula
        $this->parcels('ROPE', 4, '2026-10-12 10:00:00');

        $it = $this->items()['rope'];

        $this->assertSame([3, 4, 9], [$it['returned'], $it['shipped'], $it['stock']]);
    }

    public function test_S_222_2_3_returns_that_never_count(): void
    {
        $this->nexusStockIn('ROPE', 10, '2026-10-10');
        $this->parcels('ROPE', 1, '2026-10-12 10:00:00', 'For Return', null);
        $this->parcels('ROPE', 1, '2026-10-12 10:00:00', 'Returned', null);
        $this->parcels('ROPE', 1, '2026-10-12 10:00:00', 'Returned', '2026-11-04 10:00:00');
        $this->order('1 x ROPE', 'Page A', 'WC', '2026-10-12', 'CANNOT PROCEED');
        $this->shipped('WC', '2026-10-12 10:00:00', 'Returned', '2026-10-15 12:00:00');
        $this->parcels('ROPE', 1, '2026-10-12 10:00:00', 'Returned', '2026-10-15 12:00:00');
        DB::table('from_jnts')->insert(['waybill_number' => 'W' . $this->wb, 'submission_time' => '2026-10-12 10:00:00', 'status' => 'Delivered', 'signingtime' => '2026-10-16 10:00:00']);

        $json = $this->stockJson();

        $this->assertSame(0, $json['items']['rope']['returned']);
        $this->assertSame(['unsigned' => 1, 'future' => 1], $json['stock_meta']['returned']['flags']);
    }

    public function test_S_222_2_4_the_nexus_rts_source_uses_the_same_per_item_start(): void
    {
        $this->nexusStockIn('ROPE', 10, '2026-10-10');
        $this->order('1 x ROPE', 'Page A', null, '2026-10-12');   // isang Likha item na (may order)
        $this->app->instance(NexusRtsReturnedSource::class, new class implements ReturnedSource {
            public function key(): string { return 'nexus'; }
            public function returned(string $start, string $until): ReturnedResult
            {
                return new ReturnedResult(true, ['Rope' => 9], false, [], null, ['Rope' => ['2026-10-09' => 4, '2026-10-10' => 2, '2026-10-20' => 3]]);
            }
        });
        $this->appSetting('stock_returned_source', 'nexus');

        $this->assertSame(5, $this->items()['rope']['returned']);

        $this->appSetting('stock_returned_source', 'nexus');
        $this->app->forgetInstance(NexusRtsReturnedSource::class);
        \Illuminate\Support\Facades\Cache::flush();
        $this->assertNull($this->items()['rope']['returned'], 'ang stub: not available');
    }

    public function test_S_222_3_1_to_3_4_an_item_with_no_nexus_stock_in_is_not_in_nexus(): void
    {
        $this->nexusStockIn('GLOW TAPE', 5, '2026-10-10');
        $this->order('1 x GLOW TAPE', 'Page A', null, '2026-10-12');
        $this->nexusStockIn('BED SHEET STRAP', 0, '2026-11-03', 0, 2, '2026-11-03 10:09:00');   // shelf-only row lang
        $this->parcels('HIGH QUALITY BALLPEN', 12, '2026-10-12 10:00:00');
        $this->parcels('BED SHEET STRAP', 3, '2026-10-12 10:00:00');
        for ($i = 1; $i <= 4; $i++) $this->order('1 x HIGH QUALITY BALLPEN', 'Page A', 'HB' . $i, '2026-10-30');   // HOLD 4

        $json = $this->stockJson();
        $pen = $json['items']['high quality ballpen'];

        $this->assertSame([true, null, null, false, null, null, null, true],
            [$pen['not_in_nexus'], $pen['stock'], $pen['stock_raw'], $pen['stock_needs_count'], $pen['stock_in'], $pen['shipped'], $pen['stock_start'], $pen['stock_assumed_zero']]);
        $this->assertNotNull($pen['normal']['order_qty'], 'ang i-order ay kinukuwenta pa rin (sa stock 0)');
        $this->assertGreaterThanOrEqual(4, $pen['normal']['order_qty']);
        $this->assertTrue($json['items']['bed sheet strap']['not_in_nexus'], 'shelf-only = walang stock-in');
        $this->assertFalse($json['items']['glow tape']['not_in_nexus']);
        $this->assertSame(['BED SHEET STRAP', 'HIGH QUALITY BALLPEN'], $json['stock_meta']['nexus']['not_in_nexus']);
        $this->assertSame(2, $json['stock_meta']['nexus']['not_in_nexus_count']);
    }

    public function test_S_222_4_1_and_4_2_the_clamp(): void
    {
        $this->nexusStockIn('ROPE', 5, '2026-10-10');
        $this->parcels('ROPE', 15, '2026-10-12 10:00:00');
        $this->nexusStockIn('LAMP', 5, '2026-10-10');
        $this->parcels('LAMP', 5, '2026-10-12 10:00:00');

        $items = $this->items();

        $this->assertSame([-10, 0, true], [$items['rope']['stock_raw'], $items['rope']['stock'], $items['rope']['stock_needs_count']]);
        $this->assertSame([0, 0, false], [$items['lamp']['stock_raw'], $items['lamp']['stock'], $items['lamp']['stock_needs_count']]);
    }

    public function test_S_222_8_1_one_grouped_query_each_bounded_by_the_earliest_matched_first_day(): void
    {
        $this->nexusStockIn('GLOW TAPE', 5, '2026-10-10');
        $this->nexusStockIn('UNMATCHED NAME', 5, '2026-01-15');            // walang tugmang Likha item: hindi hangganan
        $this->parcels('GLOW TAPE', 1, '2026-10-12 10:00:00');
        $queries = [];
        DB::listen(function ($q) use (&$queries) { $queries[] = [strtolower($q->sql), $q->bindings]; });

        $this->items();

        $fj = array_values(array_filter($queries, fn ($q) => str_contains($q[0], 'from_jnts') && !str_contains($q[0], 'left join')));
        $this->assertCount(2, $fj, 'shipped at returned: tig-isang query');
        foreach ($fj as [$sql, $bindings]) {
            $this->assertStringContainsString('group by', $sql);
            $this->assertContains('2026-10-10 00:00:00', $bindings);
            $this->assertNotContains('2026-01-15 00:00:00', $bindings);
        }
        $this->assertEmpty(array_filter($queries, fn ($q) => str_contains($q[0], 'from_jnts') && str_contains($q[0], 'item_stock_start')));
    }
}

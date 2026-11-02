<?php

namespace Tests\Feature\Item;

use App\Services\Stock\NexusRtsReturnedSource;
use App\Services\Stock\ReturnedResult;
use App\Services\Stock\ReturnedSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GET /item/stock kapag naka-configure ang Nexus: stock = Nexus stock-in + returned − shipped.
 * START 2026-09-25, "ngayon" 2026-11-03 10:10 Manila, huling sync 10:10. Ang inaasahang numero ay kinuwenta nang kamay.
 */
class NexusStockReadTest extends ItemTestCase
{
    private const URL = '/item/stock?start_date=2026-09-01&end_date=2026-10-02';

    protected function setUp(): void
    {
        parent::setUp();
        $this->nexusOn();
        $this->appSetting('item_stock_start', '2026-09-25');
        $this->appSetting('nexus_stock_last_synced_at', '2026-11-03 10:10:00');
        Carbon::setTestNow(Carbon::parse('2026-11-03 10:10:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function stock(string $role = 'CEO', string $url = self::URL): \Illuminate\Testing\TestResponse
    {
        $email = strtolower(str_replace(' ', '', $role)) . uniqid() . '@example.test';

        return $this->actingAs($this->user($role, $email))->getJson($url);
    }

    private function item(string $key): array
    {
        return $this->stock()->assertOk()->json('items.' . $key);
    }

    /** $n parcels ng $item, shipped mula START; ang unang $returnedN ay Returned. */
    private function parcels(string $item, int $n, string $prefix, int $returnedN = 0, string $date = '2026-09-26'): void
    {
        for ($i = 1; $i <= $n; $i++) {
            $this->order($item, 'Page A', $prefix . $i, $date);
            $ret = $i <= $returnedN;
            $this->shipped($prefix . $i, $date . ' 10:00:00', $ret ? 'Returned' : 'Delivered', $ret ? '2026-10-05 12:00:00' : null);
        }
    }

    private function po(string $status, string $itemKey, int $ordered, ?int $received = null, ?string $countedAt = null, float $unitCost = 10): void
    {
        $supplier = DB::table('suppliers')->insertGetId(['name' => 'Acme ' . $itemKey . $status . uniqid(), 'created_at' => now(), 'updated_at' => now()]);
        $orderId  = DB::table('supply_orders')->insertGetId([
            'supplier_id' => $supplier, 'order_date' => '2026-09-20', 'status' => $status,
            'counted_at' => $countedAt, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('supply_order_items')->insert([
            'supply_order_id' => $orderId, 'item_key' => $itemKey, 'item_name' => $itemKey,
            'ordered_qty' => $ordered, 'unit_cost' => $unitCost, 'received_qty' => $received,
            'line_total' => $ordered * $unitCost, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Worked example A: 140 units sa 14 araw (10/araw), HOLD 50, 60 units umalis (40 × 1 + 10 × 2). */
    private function glowTapeOrders(bool $someLeft): void
    {
        $rows = function (string $item, int $n, ?string $wbPrefix, string $status = 'PROCEED') {
            $wbs = [];
            for ($i = 1; $i <= $n; $i++) {
                $wb = $wbPrefix === null ? null : $wbPrefix . $i;
                $this->order($item, 'Page A', $wb, '2026-09-19', $status);
                if ($wb !== null) $wbs[] = $wb;
            }
            return $wbs;
        };
        $rows('1 x GLOW TAPE', 30, 'GH');
        $left = $rows('1 x GLOW TAPE', 40, $someLeft ? 'GL' : null);
        $rows('1 x GLOW TAPE', 30, null);
        $rows('2 x GLOW TAPE', 10, 'GM');
        $left = array_merge($left, $rows('2 x GLOW TAPE', 10, $someLeft ? 'GN' : null));
        $rows('1 x GLOW TAPE', 5, null, 'CANNOT PROCEED');
        foreach ($left as $w) $this->shipped($w, '2026-09-26 10:00:00');
    }

    // ── S-220.6 formula ──────────────────────────────────────────────────────

    public function test_S_220_6_1_stock_in_100_plus_returned_4_minus_shipped_30_is_74(): void
    {
        $this->parcels('1 x GLOW TAPE', 30, 'G', 4);
        $this->nexusStockIn('GLOW TAPE', 100, '2026-10-01');

        $it = $this->item('glow tape');

        $this->assertSame([100, 4, 30, 74, 74, false], [$it['stock_in'], $it['returned'], $it['shipped'], $it['stock_raw'], $it['stock'], $it['stock_needs_count']]);
        $this->assertFalse($it['returned_over_shipped']);
    }

    public function test_example_a_in_nexus_mode_gives_the_same_numbers_with_nexus_stock_in(): void
    {
        $this->glowTapeOrders(true);
        $this->po('ordered', 'Glow Tape', 200);
        $this->po('counted', 'Glow Tape', 100, 100, '2026-09-26 09:00:00');   // counted PO: wala nang dagdag
        $this->nexusStockIn('GLOW TAPE', 100, '2026-09-26');

        $item = $this->item('glow tape');

        $this->assertSame([100, 0, 60, 40], [$item['stock_in'], $item['returned'], $item['shipped'], $item['stock']]);
        $this->assertSame(200, $item['incoming']);
        $this->assertSame(50, $item['hold_units']);
        $n = $item['normal'];
        $this->assertSame(0, $n['order_qty']);
        $this->assertEquals(19.0, $n['doi']);
        $this->assertSame('2026-10-14', $n['order_by']);
        $this->assertSame('green', $n['colour']);
    }

    public function test_example_b_in_nexus_mode(): void
    {
        $this->glowTapeOrders(false);                                             // B: walang umalis, walang stock-in
        $b = $this->item('glow tape');
        $this->assertSame([0, 0, 0, 0], [$b['stock_in'], $b['shipped'], $b['stock_raw'], $b['stock']]);
        $this->assertSame(150, $b['normal']['order_qty']);
        $this->assertSame('red', $b['normal']['colour']);
    }

    public function test_example_d_in_nexus_mode(): void
    {
        $this->parcels('1 x ROPE', 25, 'RP');                                     // D: −25
        $d = $this->item('rope');
        $this->assertSame([0, 25, -25, 0, true], [$d['stock_in'], $d['shipped'], $d['stock_raw'], $d['stock'], $d['stock_needs_count']]);
    }

    public function test_S_220_4_1_a_returned_parcel_nets_to_0_on_the_item(): void
    {
        $this->order('2 x GLOW TAPE', 'Page A', 'W1', '2026-09-26');
        $this->shipped('W1', '2026-09-26 10:00:00', 'Returned', '2026-10-05 12:00:00');

        $it = $this->item('glow tape');

        $this->assertSame([2, 2, 0, false], [$it['shipped'], $it['returned'], $it['stock_raw'], $it['stock_needs_count']]);
    }

    public function test_S_220_6_2_the_start_day_and_today_count_but_not_the_day_before_or_a_future_day(): void
    {
        $this->parcels('1 x GLOW TAPE', 1, 'G');
        $this->nexusStockIn('GLOW TAPE', 1, '2026-09-24');
        $this->nexusStockIn('GLOW TAPE', 10, '2026-09-25');
        $this->nexusStockIn('GLOW TAPE', 100, '2026-11-03');
        $this->nexusStockIn('GLOW TAPE', 1000, '2026-11-04');

        $this->assertSame(110, $this->item('glow tape')['stock_in']);
    }

    public function test_S_220_6_3_and_6_5_negative_raw_shows_0_and_needs_count_even_with_no_nexus_rows_at_all(): void
    {
        $this->appSetting('nexus_stock_last_synced_at', null);
        $this->parcels('1 x ROPE', 3, 'RP');

        $json = $this->stock()->assertOk()->json();

        $this->assertSame([-3, 0, true], [$json['items']['rope']['stock_raw'], $json['items']['rope']['stock'], $json['items']['rope']['stock_needs_count']]);
        $this->assertFalse($json['stock_meta']['nexus']['synced']);
    }

    public function test_S_220_6_4_no_start_means_null_stock_fields(): void
    {
        $this->appSetting('item_stock_start', null);
        $this->parcels('1 x ROPE', 3, 'RP');

        $json = $this->stock()->assertOk()->json();

        $this->assertFalse($json['stock_ready']);
        foreach (['stock', 'stock_raw', 'stock_needs_count', 'stock_in', 'returned', 'shipped', 'returned_over_shipped', 'nexus_diff'] as $f) {
            $this->assertNull($json['items']['rope'][$f], $f);
        }
    }

    public function test_S_220_6_6_po_received_no_longer_adds(): void
    {
        $this->order('1 x TAPE', 'Page A', 'TH', '2026-09-10');
        $this->po('counted', 'Tape', 50, 50, '2026-09-26 09:00:00');
        $this->nexusStockIn('TAPE', 50, '2026-09-26');
        $this->order('1 x POT', 'Page A', 'PH', '2026-09-10');
        $this->po('counted', 'Pot', 50, 50, '2026-09-26 09:00:00');

        $json = $this->stock()->assertOk()->json('items');

        $this->assertSame(50, $json['tape']['stock']);
        $this->assertSame(0, $json['pot']['stock']);
    }

    public function test_S_220_6_7_incoming_and_discount_lines_are_as_before(): void
    {
        $this->order('1 x TAPE', 'Page A', 'TH', '2026-09-10');
        $this->po('ordered', 'Tape', 10);
        $this->po('ordered', 'Tape', 30, null, null, -5);
        $this->po('counted', 'Tape', 1, 20, '2026-09-26 09:00:00', -5);
        $this->order('1 x POT', 'Page A', 'PH', '2026-09-10');
        $this->po('delivered', 'Pot', 30, 10);

        $items = $this->stock()->assertOk()->json('items');

        $this->assertSame(10, $items['tape']['incoming']);
        $this->assertSame(0, $items['tape']['stock']);
        $this->assertSame(20, $items['pot']['incoming']);
    }

    public function test_S_220_6_8_ceo_and_marketing_see_the_same_numbers_no_price_and_unknown_role_404(): void
    {
        $this->parcels('1 x GLOW TAPE', 30, 'G', 4);
        $this->nexusStockIn('GLOW TAPE', 100, '2026-10-01', 2, 80, '2026-11-03 10:09:00');
        $this->nexusStockIn('MYSTERY BOX', 9, '2026-10-01');

        $ceo = $this->stock('CEO')->assertOk()->json();
        $mkt = $this->stock('Marketing')->assertOk()->json();

        $this->assertSame($ceo['items'], $mkt['items']);
        $this->assertSame($ceo['stock_meta'], $mkt['stock_meta']);
        $this->assertDoesNotMatchRegularExpression('/cost|price|value/i', json_encode([$ceo['stock_meta'], array_keys($ceo['items']['glow tape'])]));
        $this->stock('Warehouse')->assertNotFound();
    }

    public function test_S_220_6_11_reading_stock_changes_no_po_cost_cogs_or_quote_history(): void
    {
        $this->order('1 x TAPE', 'Page A', 'TH', '2026-09-10');
        $this->po('counted', 'Tape', 50, 50, '2026-09-26 09:00:00', 12.5);
        $snap = fn () => json_encode([
            DB::table('supply_order_items')->get(['item_key', 'unit_cost', 'received_qty', 'line_total'])->all(),
            DB::table('item_supplier_quote_history')->count(),
        ]);
        $before = $snap();

        $this->stock()->assertOk();
        Artisan::call('stock:returned-compare');

        $this->assertSame($before, $snap());
    }

    public function test_S_220_6_12_without_a_nexus_token_the_stock_is_po_received_exactly_as_before(): void
    {
        $this->order('1 x TAPE', 'Page A', 'TH', '2026-09-10');
        $this->po('counted', 'Tape', 50, 50, '2026-09-26 09:00:00');
        $this->nexusStockIn('TAPE', 7, '2026-09-26');
        $this->parcels('1 x ROPE', 2, 'RP', 1);

        config(['services.nexus.token' => '']);
        $off = $this->stock()->assertOk()->json();
        $this->nexusOn();
        $on = $this->stock()->assertOk()->json();

        // May naitabing Nexus row na: walang token pero dati nang na-sync = babala sa page.
        $this->assertSame(['mode' => 'po_received', 'nexus' => ['configured' => false, 'previously_synced' => true]], $off['stock_meta']);
        $this->assertSame(50, $off['items']['tape']['stock']);                 // PO received, gaya ng dati
        $this->assertSame(-2, $off['items']['rope']['stock_raw']);             // walang returned sa lumang paraan
        foreach (['stock_in', 'returned', 'shipped', 'returned_over_shipped', 'nexus_remaining', 'nexus_diff'] as $f) {
            $this->assertArrayNotHasKey($f, $off['items']['tape'], $f);
        }

        $this->assertSame('nexus', $on['stock_meta']['mode']);
        $this->assertSame(7, $on['items']['tape']['stock']);
        $this->assertSame(-1, $on['items']['rope']['stock_raw']);
    }

    // ── S-220.2 sync status on /item/stock ───────────────────────────────────

    public function test_S_220_2_1_the_last_synced_time_is_returned_in_manila(): void
    {
        $meta = $this->stock()->assertOk()->json('stock_meta.nexus');

        $this->assertSame('2026-11-03 10:10', $meta['last_synced_at']);
        $this->assertTrue($meta['synced']);
        $this->assertFalse($meta['stale']);
    }

    public function test_S_220_2_3_an_unauthorized_error_says_the_token_needs_fixing_without_the_token(): void
    {
        $this->appSetting('nexus_stock_last_error', json_encode(['at' => '2026-11-03 10:10:00', 'kind' => 'unauthorized', 'message' => 'Nexus refused the token (HTTP 401)']));
        $this->parcels('1 x GLOW TAPE', 1, 'G');
        $this->nexusStockIn('GLOW TAPE', 5, '2026-10-01');

        $res  = $this->stock()->assertOk();
        $json = $res->json();

        $this->assertTrue($json['stock_meta']['nexus']['token_problem']);
        $this->assertSame('unauthorized', $json['stock_meta']['nexus']['last_error']['kind']);
        $this->assertSame(4, $json['items']['glow tape']['stock'], 'ang numero ay nananatili');
        $this->assertStringNotContainsString('fake-test-token-220', $res->getContent());
    }

    public function test_S_220_2_6_more_than_3_hours_since_the_last_success_is_stale_but_the_numbers_still_show(): void
    {
        $this->parcels('1 x GLOW TAPE', 1, 'G');
        $this->nexusStockIn('GLOW TAPE', 5, '2026-10-01');
        foreach (['2026-11-03 07:09:00' => true, '2026-11-03 07:10:00' => false, '2026-11-03 07:11:00' => false] as $last => $stale) {
            $this->appSetting('nexus_stock_last_synced_at', $last);
            $json = $this->stock()->assertOk()->json();
            $this->assertSame($stale, $json['stock_meta']['nexus']['stale'], $last);
            $this->assertSame(4, $json['items']['glow tape']['stock'], $last);
        }
    }

    public function test_S_220_2_7_never_synced_says_so(): void
    {
        $this->appSetting('nexus_stock_last_synced_at', null);

        $meta = $this->stock()->assertOk()->json('stock_meta.nexus');

        $this->assertFalse($meta['synced']);
        $this->assertNull($meta['last_synced_at']);
        $this->assertFalse($meta['stale']);
    }

    public function test_S_220_2_8_a_missing_table_does_not_500(): void
    {
        $this->parcels('1 x ROPE', 2, 'RP');
        Schema::drop('nexus_stock_ins');

        $json = $this->stock()->assertOk()->json();

        $this->assertSame(0, $json['items']['rope']['stock_in']);
        $this->assertSame(-2, $json['items']['rope']['stock_raw']);
    }

    // ── S-220.3 name matching ────────────────────────────────────────────────

    public function test_S_220_3_1_3_5_3_6_3_7_and_3_8_names_match_by_case_spacing_and_prefix_and_the_rest_is_listed(): void
    {
        $this->parcels('1 x GLOW TAPE', 1, 'G');
        $this->parcels('1 x ROPE', 1, 'R');
        $this->nexusStockIn('glow   TAPE', 10, '2026-10-01');        // case at spacing
        $this->nexusStockIn('Glow-Tape', 5, '2026-10-02');           // ibang pangalan, parehong item: idinadagdag
        $this->nexusStockIn('2 x GLOW TAPE', 40, '2026-10-03');      // "N x" tinatanggal; pieces = stock_in_pieces
        $this->nexusStockIn('MYSTERY BOX', 9, '2026-10-01');
        $this->nexusStockIn('mystery  box', 3, '2026-10-02');
        $this->nexusStockIn('<b>Odd</b>', 1, '2026-10-02');

        $json = $this->stock()->assertOk()->json();

        $this->assertSame(55, $json['items']['glow tape']['stock_in']);
        $this->assertSame(0, $json['items']['rope']['stock_in']);       // walang Nexus name: 0, hindi unmatched
        $this->assertArrayNotHasKey('mystery box', $json['items']);
        $meta = $json['stock_meta']['nexus'];
        $this->assertSame(2, $meta['unmatched_count']);
        $this->assertSame(12, $meta['unmatched'][0]['pieces']);
        $this->assertSame('mystery box', strtolower(preg_replace('/\s+/', ' ', $meta['unmatched'][0]['name'])));
        $this->assertSame(['name' => '<b>Odd</b>', 'pieces' => 1, 'ambiguous' => false], $meta['unmatched'][1]);   // raw; ang view ang nag-e-escape
    }

    public function test_S_220_3_2_two_variants_mapped_to_one_family_keep_their_own_stock_and_a_family_name_is_ambiguous(): void
    {
        Artisan::call('migrate', ['--path' => 'database/migrations/2026_04_13_000001_create_item_type_mappings_table.php', '--force' => true]);
        foreach (['Alagang Pamilya II', 'Alagang Pamilya VII'] as $v) {
            DB::table('item_type_mappings')->insert(['item_name' => $v, 'item_type' => 'Alagang Pamilya', 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->parcels('1 x ALAGANG PAMILYA II', 1, 'A');
        $this->parcels('1 x ALAGANG PAMILYA VII', 1, 'B');
        $this->nexusStockIn('Alagang Pamilya II', 5, '2026-10-01');         // eksaktong pangalan
        $this->nexusStockIn('ALAGANG  PAMILYA VII', 3, '2026-10-01');       // eksakto (case, spacing)
        $this->nexusStockIn('Alagang-Pamilya VII', 2, '2026-10-02');        // walang mapping na kailangan (gitling lang)
        $this->nexusStockIn('Alagang Pamilya', 9, '2026-10-01');            // ang pamilya lang: dalawa ang puwede

        $json = $this->stock()->assertOk()->json();

        $this->assertSame(5, $json['items']['alagang pamilya ii']['stock_in']);
        $this->assertSame(5, $json['items']['alagang pamilya vii']['stock_in']);
        $this->assertSame(1, $json['stock_meta']['nexus']['unmatched_count']);
        $this->assertSame(['name' => 'Alagang Pamilya', 'pieces' => 9, 'ambiguous' => true], $json['stock_meta']['nexus']['unmatched'][0]);
    }

    public function test_S_220_3_2_and_3_4_item_type_mappings_are_honoured_and_a_later_mapping_counts_history_without_a_resync(): void
    {
        Artisan::call('migrate', ['--path' => 'database/migrations/2026_04_13_000001_create_item_type_mappings_table.php', '--force' => true]);
        $this->parcels('1 x ALAGANG PAMILYA VII', 1, 'A');
        $this->nexusStockIn('Alagang Pamilya II', 6, '2026-10-01');

        $this->assertSame(0, $this->item('alagang pamilya vii')['stock_in']);

        DB::table('item_type_mappings')->insert(['item_name' => 'Alagang Pamilya II', 'item_type' => 'Alagang Pamilya VII', 'created_at' => now(), 'updated_at' => now()]);

        $json = $this->stock()->assertOk()->json();
        $this->assertSame(6, $json['items']['alagang pamilya vii']['stock_in']);
        $this->assertSame(0, $json['stock_meta']['nexus']['unmatched_count']);
    }

    public function test_S_220_3_9_non_ascii_names_match_in_either_unicode_form(): void
    {
        $this->parcels('1 x Niño Lamp', 1, 'N');
        $this->nexusStockIn("NIN\u{0303}O  LAMP", 4, '2026-10-01');

        $this->assertSame(4, $this->item('niño lamp')['stock_in']);
    }

    // ── S-220.4.11, S-220.5 returned source on /item/stock ───────────────────

    private function fakeNexusReturns(array $pieces, bool $available = true): void
    {
        $this->app->instance(NexusRtsReturnedSource::class, new class($pieces, $available) implements ReturnedSource {
            public function __construct(private array $pieces, private bool $available) {}
            public function key(): string { return 'nexus'; }
            public function returned(string $start, string $until): ReturnedResult
            {
                return $this->available ? new ReturnedResult(true, $this->pieces, false) : ReturnedResult::unavailable('not available');
            }
        });
        $this->appSetting('stock_returned_source', 'nexus');
    }

    public function test_S_220_5_2_and_4_11_the_nexus_source_is_used_for_everything_and_more_returned_than_shipped_is_flagged(): void
    {
        $this->parcels('1 x GLOW TAPE', 2, 'G', 1);   // J&T: 1 Returned — hindi ginagamit
        $this->fakeNexusReturns(['Glow Tape' => 5]);

        $json = $this->stock()->assertOk()->json();
        $it = $json['items']['glow tape'];

        $this->assertSame([5, 2, 3, true], [$it['returned'], $it['shipped'], $it['stock_raw'], $it['returned_over_shipped']]);
        $this->assertSame('nexus', $json['stock_meta']['returned']['source']);
    }

    public function test_S_220_5_3_an_unknown_setting_uses_jnt_with_a_warning(): void
    {
        $this->parcels('1 x GLOW TAPE', 2, 'G', 1);
        $this->appSetting('stock_returned_source', 'both');

        $json = $this->stock()->assertOk()->json();

        $this->assertSame(1, $json['items']['glow tape']['returned']);
        $this->assertSame('jnt', $json['stock_meta']['returned']['source']);
        $this->assertSame('unknown_source', $json['stock_meta']['returned']['warning']);
    }

    public function test_S_220_5_4_nexus_unavailable_shows_returned_as_unavailable_without_jnt_and_without_zeroing(): void
    {
        $this->parcels('1 x GLOW TAPE', 2, 'G', 1);
        $this->nexusStockIn('GLOW TAPE', 10, '2026-10-01');
        $this->appSetting('stock_returned_source', 'nexus');   // ang totoong stub

        $json = $this->stock()->assertOk()->json();
        $it = $json['items']['glow tape'];

        $this->assertNull($it['returned']);
        $this->assertSame(8, $it['stock'], '10 − 2, walang J&T returned');
        $this->assertFalse($json['stock_meta']['returned']['available']);
        $this->assertSame('unavailable', $json['stock_meta']['returned']['warning']);
    }

    public function test_S_220_5_6_and_5_7_flipping_the_source_re_keys_the_cache_and_changes_no_data(): void
    {
        $this->parcels('1 x GLOW TAPE', 2, 'G', 1);
        $count = fn () => json_encode([DB::table('from_jnts')->get()->all(), DB::table('nexus_stock_ins')->count()]);
        $before = $count();

        $this->assertSame(1, $this->item('glow tape')['returned']);   // jnt, naka-cache na
        $this->fakeNexusReturns(['GLOW TAPE' => 2]);
        $this->assertSame(2, $this->item('glow tape')['returned']);   // bagong key: hindi ang naka-cache na jnt
        $this->appSetting('stock_returned_source', 'jnt');
        $this->assertSame(1, $this->item('glow tape')['returned']);

        $this->assertSame($before, $count());
        $this->assertSame('jnt', DB::table('app_settings')->where('key', 'stock_returned_source')->value('value'));
    }

    // ── S-220.7 Nexus remaining cross-check ──────────────────────────────────

    public function test_a_missing_token_after_an_earlier_sync_is_flagged_and_a_site_that_never_synced_stays_quiet(): void
    {
        config(['services.nexus.token' => '']);
        $this->appSetting('nexus_stock_last_synced_at', null);
        $this->order('1 x TAPE', 'Page A', 'TH', '2026-09-10');

        $this->assertSame(['mode' => 'po_received', 'nexus' => ['configured' => false, 'previously_synced' => false]],
            $this->stock()->assertOk()->json('stock_meta'), 'hindi pa kailanman na-sync (Incepxion): tahimik');

        $this->appSetting('nexus_stock_last_synced_at', '2026-11-01 10:10:00');
        $this->assertTrue($this->stock()->assertOk()->json('stock_meta.nexus.previously_synced'), 'may last_synced_at');

        $this->appSetting('nexus_stock_last_synced_at', null);
        $this->nexusStockIn('TAPE', 1, '2026-10-01');
        $this->assertTrue($this->stock()->assertOk()->json('stock_meta.nexus.previously_synced'), 'may Nexus row');
    }

    public function test_remaining_uses_each_names_own_latest_as_of_and_leaves_out_a_renamed_names_frozen_value(): void
    {
        $this->parcels('1 x GLOW TAPE', 1, 'G');
        $this->parcels('1 x ROPE', 1, 'R');
        $this->nexusStockIn('GLOW TAPE', 0, '2026-11-03', 0, 80, '2026-11-03 10:09:00');
        $this->nexusStockIn('Glow-Tape', 10, '2026-10-20', 1, 30, '2026-10-20 10:00:00');   // lumang pangalan: nakatigil
        $this->nexusStockIn('Rope', 0, '2026-11-03', 0, 5, '2026-11-03 10:09:00');
        $this->nexusStockIn('R-ope', 0, '2026-11-02', 0, 4, '2026-11-03 10:08:30');          // parehong takbo: idinadagdag

        $items = $this->stock()->assertOk()->json('items');

        $this->assertSame([80, '2026-11-03 10:09', true, false], [$items['glow tape']['nexus_remaining'], $items['glow tape']['nexus_remaining_as_of'],
            $items['glow tape']['nexus_remaining_mixed'], $items['glow tape']['nexus_remaining_stale']]);
        $this->assertSame([9, '2026-11-03 10:08', false], [$items['rope']['nexus_remaining'], $items['rope']['nexus_remaining_as_of'], $items['rope']['nexus_remaining_mixed']]);
    }

    public function test_S_220_7_1_to_7_4_remaining_and_the_difference_with_its_as_of_and_a_dash_when_missing(): void
    {
        $this->parcels('1 x GLOW TAPE', 30, 'G', 4);
        $this->parcels('1 x ROPE', 1, 'R');
        $this->nexusStockIn('GLOW TAPE', 100, '2026-10-01', 1, 70, '2026-10-01 18:00:00');   // luma
        $this->nexusStockIn('GLOW TAPE', 0, '2026-11-03', 0, 80, '2026-11-03 10:09:00');      // pinakabago

        $json = $this->stock()->assertOk()->json();

        $this->assertSame([74, 80, -6], [$json['items']['glow tape']['stock'], $json['items']['glow tape']['nexus_remaining'], $json['items']['glow tape']['nexus_diff']]);
        $this->assertNull($json['items']['rope']['nexus_remaining']);
        $this->assertNull($json['items']['rope']['nexus_diff']);
        $this->assertSame('2026-11-03 10:09', $json['stock_meta']['nexus']['remaining_as_of']);
        $this->assertFalse($json['stock_meta']['nexus']['remaining_stale']);

        Carbon::setTestNow(Carbon::parse('2026-11-03 13:10:00', 'Asia/Manila'));
        $this->assertTrue($this->stock()->assertOk()->json('stock_meta.nexus.remaining_stale'));
    }

    // ── S-220.8 DOI, order qty, worklist ─────────────────────────────────────

    public function test_S_220_8_1_and_8_2_the_formulas_use_the_floored_stock(): void
    {
        // Lifecycle 16.7 example: scaling, 7-day v = 15, HOLD 50, stock 300 → DOI 16.7 amber.
        for ($i = 1; $i <= 35; $i++) $this->order('1 x DOIX', 'Page A', $i <= 35 ? 'DH' . $i : null, '2026-09-19');
        for ($i = 1; $i <= 105; $i++) $this->order('1 x DOIX', 'Page A', $i <= 15 ? 'DJ' . $i : null, '2026-10-02');
        DB::table('supply_item_settings')->insert(['item_name' => '1 x DOIX', 'lifecycle_override' => 'scaling', 'created_at' => now(), 'updated_at' => now()]);
        $this->nexusStockIn('DOIX', 300, '2026-09-26');
        // Ang negative raw ay 0 sa formula: 25 umalis, HOLD 5, walang benta sa 14 araw → order 5, hindi 30.
        for ($i = 1; $i <= 25; $i++) { $this->order('1 x ROPE', 'Page A', 'RP' . $i, '2026-09-10'); $this->shipped('RP' . $i, '2026-09-26 10:00:00'); }
        for ($i = 1; $i <= 5; $i++) $this->order('1 x ROPE', 'Page A', 'RH' . $i, '2026-09-10');

        $items = $this->stock()->assertOk()->json('items');

        $this->assertEquals(16.7, $items['doix']['normal']['doi']);
        $this->assertSame('amber', $items['doix']['normal']['colour']);
        $this->assertSame(-25, $items['rope']['stock_raw']);
        $this->assertSame(5, $items['rope']['normal']['order_qty']);
    }

    public function test_S_220_8_4_and_8_5_the_worklist_and_lifecycle_lead_palugit_category_are_the_same_in_both_modes(): void
    {
        $this->glowTapeOrders(true);
        $this->po('ordered', 'Glow Tape', 200);
        $this->po('counted', 'Glow Tape', 100, 100, '2026-09-26 09:00:00');
        $this->nexusStockIn('GLOW TAPE', 100, '2026-09-26');
        DB::table('supply_item_settings')->insert(['item_name' => 'Glow Tape', 'lead_time_days' => 5, 'palugit_override' => 4, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('item_category_assignments')->insert(['item_key' => 'glow tape', 'category_id' => 2, 'created_at' => now(), 'updated_at' => now()]);
        $wl = '/item/worklist?start_date=2026-09-01&end_date=2026-10-02';
        $pick = fn (array $it) => array_intersect_key($it, array_flip(['lifecycle', 'lifecycle_auto', 'lead', 'palugit_override', 'category_id', 'category', 'gated', 'hold_units', 'incoming', 'variants']));

        config(['services.nexus.token' => '']);
        $offWl = $this->stock('CEO', $wl)->assertOk()->json();
        $off   = $this->item('glow tape');
        $this->nexusOn();
        $onWl  = $this->stock('CEO', $wl)->assertOk()->json();
        $on    = $this->item('glow tape');

        $this->assertSame($offWl, $onWl);
        $this->assertSame($pick($off), $pick($on));
        $this->assertSame($off['normal']['palugit'], $on['normal']['palugit']);
    }

    public function test_S_220_8_6_a_po_counted_today_but_not_synced_is_in_neither_incoming_nor_stock(): void
    {
        $this->order('1 x TAPE', 'Page A', 'TH', '2026-09-10');
        $this->po('counted', 'Tape', 40, 40, '2026-11-03 09:00:00');

        $it = $this->item('tape');

        $this->assertSame([0, 0, 0], [$it['incoming'], $it['stock_in'], $it['stock']]);
    }
}

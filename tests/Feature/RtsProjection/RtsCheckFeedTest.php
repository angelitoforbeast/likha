<?php

namespace Tests\Feature\RtsProjection;

use Illuminate\Support\Facades\DB;

/**
 * GET /api/owner/rts-check: sariling key, mga page+item na may ≥30 matched, flagged muna, walang customer data.
 */
class RtsCheckFeedTest extends RtsProjectionTestCase
{
    private const URL = '/api/owner/rts-check?from=2026-10-02&to=2026-10-23';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.rts_check.key' => 'secret-rts-key']);

        $this->rates([3 => 0.61]);
        $sub = '2026-10-20 12:00:00';
        // Item C: 55.11% projected, set 30 → gap −25.11 (flag)
        $this->orders(24, 'Page B', 'Item C', $sub, 'Returned');
        $this->orders(25, 'Page B', 'Item C', $sub, 'Delivered');
        $this->orders(51, 'Page B', 'Item C', $sub, 'In Transit');
        // Item G: 40 returned + 60 delivered = 40% projected, set 42 → gap +2 (walang flag)
        $this->orders(40, 'Page B', 'Item G', $sub, 'Returned');
        $this->orders(60, 'Page B', 'Item G', $sub, 'Delivered');
        // Item H: 9 orders lang, malayo ang set → wala sa feed
        $this->orders(9, 'Page B', 'Item H', $sub, 'Returned');
        DB::table('page_item_settings')->insert([
            ['page_name' => 'Page B', 'item_name' => 'Item C', 'rts_pct' => 30, 'effective_date' => '2026-10-10', 'mode_cod_int' => 599],
            ['page_name' => 'Page B', 'item_name' => 'Item G', 'rts_pct' => 42, 'effective_date' => '2026-10-10', 'mode_cod_int' => 599],
            ['page_name' => 'Page B', 'item_name' => 'Item H', 'rts_pct' => 10, 'effective_date' => '2026-10-10', 'mode_cod_int' => 599],
        ]);
    }

    public function test_needs_the_key(): void
    {
        $this->getJson(self::URL)->assertStatus(401);
        $this->getJson(self::URL, ['X-RTS-CHECK-KEY' => 'wrong'])->assertStatus(401);

        config(['services.rts_check.key' => '']);
        $this->getJson(self::URL, ['X-RTS-CHECK-KEY' => ''])->assertStatus(401);
    }

    public function test_lists_rows_with_enough_orders_flagged_first_and_no_customer_data(): void
    {
        $res = $this->getJson(self::URL, ['X-RTS-CHECK-KEY' => 'secret-rts-key'])->assertOk();

        $rows = $res->json('rows');
        $this->assertSame(['Item C', 'Item G'], array_column($rows, 'item'));
        $this->assertTrue($rows[0]['flag']);
        $this->assertFalse($rows[1]['flag']);
        $this->assertEqualsWithDelta(-25.11, $rows[0]['gap'], 0.01);
        $this->assertEqualsWithDelta(55.11, $rows[0]['projected_rts'], 0.01);
        $this->assertSame(['page', 'item', 'orders', 'unmatched', 'matched', 'delivered', 'returned', 'for_return',
            'in_transit', 'in_transit_pct', 'projected_rts', 'actual_rts', 'set_rts', 'gap', 'error_pts', 'gap_ratio', 'level', 'flag', 'set_by', 'set_at'],
            array_keys($rows[0]));
        $this->assertStringNotContainsString('WB', $res->getContent());
    }

    public function test_bad_date_is_422(): void
    {
        $this->getJson('/api/owner/rts-check?from=nope', ['X-RTS-CHECK-KEY' => 'secret-rts-key'])->assertStatus(422);
    }
}

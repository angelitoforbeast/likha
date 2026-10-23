<?php

namespace Tests\Feature\RtsProjection;

use App\Services\RtsProjection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * RtsProjection: rts:learn (p ng RTS ayon sa edad), ang projected / actual RTS% ng page+item, at ang Set RTS%.
 */
class RtsProjectionTest extends RtsProjectionTestCase
{
    public function test_learn_computes_rates_by_age_from_resolved_orders(): void
    {
        $sub = '2026-09-10 08:00:00';
        $d1 = [['In Transit', '2026-09-11 08:00:00']];
        // Item A: nasa In Transit pa noong day 3 → 30 Returned, 10 Delivered, 5 For Return (RTS din ang bilang)
        $this->orders(30, 'Page A', 'Item A', $sub, 'Returned', $d1);
        $this->orders(10, 'Page A', 'Item A', $sub, 'Delivered', $d1);
        $this->orders(5, 'Page A', 'Item A', $sub, 'For Return', $d1);
        // For Return na noong day 2 → hindi in transit sa day 3, wala sa sample
        $this->orders(10, 'Page A', 'Item A', $sub, 'Returned', [['For Return', '2026-09-12 08:00:00']]);
        // Item B: 5 lang na sample → pooled lang
        $this->orders(5, 'Page A', 'Item B', $sub, 'Returned', $d1);
        // Hindi pa resolved at lampas 8 linggo: wala sa sample
        $this->orders(5, 'Page A', 'Item B', $sub, 'In Transit', $d1);
        $this->orders(5, 'Page A', 'Item B', '2026-07-01 08:00:00', 'Delivered', $d1);

        $this->assertSame(0, Artisan::call('rts:learn'));
        $rates = app(RtsProjection::class)->rates();

        $this->assertSame(50, $rates['pooled'][3]['n']);
        $this->assertEqualsWithDelta(40 / 50, $rates['pooled'][3]['p'], 0.0001);
        $this->assertSame(45, $rates['items']['page a||itema'][3]['n']);
        $this->assertEqualsWithDelta(35 / 45, $rates['items']['page a||itema'][3]['p'], 0.0001);
        $this->assertArrayNotHasKey('page a||itemb', $rates['items']);
    }

    public function test_projection_adds_for_return_and_in_transit_times_p(): void
    {
        $this->rates([3 => 0.61], ['page b||itemd' => [3 => 0.9]]);
        $sub = '2026-10-20 12:00:00';
        $this->orders(24, 'Page B', 'Item C', $sub, 'Returned');
        $this->orders(25, 'Page B', 'Item C', $sub, 'Delivered');
        $this->orders(51, 'Page B', 'Item C', $sub, 'In Transit');
        $this->orders(10, 'Page B', 'Item D', $sub, 'Delivering');
        // walang J&T row: bilang lang
        DB::table('macro_output')->insert(['PAGE' => 'Page B', 'ITEM_NAME' => 'Item C', 'waybill' => 'NOJNT', 'ts_date' => '2026-10-20']);

        $rows = app(RtsProjection::class)->rows('2026-10-02', '2026-10-23');

        $c = $rows['page b||itemc'];
        $this->assertSame(101, $c['orders']);
        $this->assertSame(100, $c['matched']);
        $this->assertSame(1, $c['unmatched']);
        $this->assertSame([25, 24, 0, 51], [$c['delivered'], $c['returned'], $c['for_return'], $c['in_transit']]);
        $this->assertEqualsWithDelta(55.11, $c['projected_rts'], 0.01);
        $this->assertEqualsWithDelta(51.0, $c['in_transit_pct'], 0.01);
        // may rate ang item → ginamit; 10 in transit × 0.9
        $this->assertEqualsWithDelta(90.0, $rows['page b||itemd']['projected_rts'], 0.01);
    }

    public function test_actual_rts_counts_only_resolved_orders_old_enough(): void
    {
        $this->rates([22 => 0.5, 14 => 0.5, 3 => 0.5]);
        $old = '2026-10-01 12:00:00';
        $this->orders(10, 'Page E', 'Item E', $old, 'Returned');
        $this->orders(10, 'Page E', 'Item E', $old, 'Delivered');
        $this->orders(5, 'Page E', 'Item E', $old, 'In Transit');
        $this->orders(5, 'Page E', 'Item E', $old, 'For Return');
        $this->orders(5, 'Page E', 'Item E', '2026-10-20 12:00:00', 'Returned');
        $this->orders(3, 'Page F', 'Item F', '2026-10-20 12:00:00', 'Returned');

        $rows = app(RtsProjection::class)->rows('2026-10-01', '2026-10-23');

        $this->assertEqualsWithDelta(50.0, $rows['page e||iteme']['actual_rts'], 0.01);
        $this->assertNull($rows['page f||itemf']['actual_rts']);
    }

    public function test_set_rts_and_last_set_by_come_from_settings_and_log(): void
    {
        $this->rates([3 => 0.61]);
        $this->orders(5, 'Page B', 'Item C', '2026-10-20 12:00:00', 'Returned');
        $this->user('CEO', 'a@example.test')->employeeProfile()->update(['name' => 'Person A']);
        $this->user('CEO', 'b@example.test')->employeeProfile()->update(['name' => 'Person B']);
        DB::table('page_item_settings')->insert([
            ['page_name' => 'Page B', 'item_name' => 'Item C', 'rts_pct' => 40, 'effective_date' => '2026-09-01', 'mode_cod_int' => 599],
            ['page_name' => 'Page B', 'item_name' => 'Item C', 'rts_pct' => 30, 'effective_date' => '2026-10-10', 'mode_cod_int' => 599],
            ['page_name' => 'Page B', 'item_name' => 'Item C', 'rts_pct' => null, 'effective_date' => '2026-10-15', 'mode_cod_int' => 599],
        ]);
        $log = fn ($who, $old, $new, $at) => [
            'user_email' => $who.'@example.test', 'action' => 'update', 'page_name' => 'Page B', 'item_name' => 'Item C',
            'effective_date' => '2026-10-10', 'old_rts_pct' => $old, 'new_rts_pct' => $new, 'created_at' => $at,
        ];
        DB::table('page_item_settings_log')->insert([
            $log('a', null, 40, '2026-09-01 09:00:00'),
            $log('b', 40, 30, '2026-10-10 09:00:00'),
            $log('a', 30, 30, '2026-10-15 09:00:00'),   // carry lang (promo/remark save)
        ]);

        $r = app(RtsProjection::class)->rows('2026-10-02', '2026-10-23')['page b||itemc'];

        $this->assertEqualsWithDelta(30.0, $r['set_rts'], 0.001);
        $this->assertEqualsWithDelta(-70.0, $r['gap'], 0.01);   // 5 Returned = 100% projected
        $this->assertSame('Person B', $r['set_by']);
        $this->assertSame('2026-10-10 09:00:00', $r['set_at']);
    }
}

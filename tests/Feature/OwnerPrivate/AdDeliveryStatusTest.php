<?php

namespace Tests\Feature\OwnerPrivate;

use App\Services\AdDeliveryStatus;
use Illuminate\Support\Facades\DB;

class AdDeliveryStatusTest extends AdDeliveryTestCase
{
    private function read(array $pages = [self::PAGE]): array
    {
        return (new AdDeliveryStatus())->forPages($pages, self::START, self::END);
    }

    public function test_S_043_1_an_ad_counts_its_orders_by_jnt_status_with_rts_pct(): void
    {
        $this->ad('AD1', 'SET1', 'CMP1');
        $this->order('WB1', 'AD1', 'Delivered');
        $this->order('WB2', 'AD1', 'DELIVERED to customer');
        $this->order('WB3', 'AD1', 'Returned');
        $this->order('WB4', 'AD1', 'For Return');
        $this->order('WB5', 'AD1', 'In Transit', rtsReason: 'Refused');   // may rts_reason = for return, gaya ng page row
        $this->order('WB6', 'AD1', 'In Transit');
        $this->order('WB7', 'AD1', 'Picked up');

        $this->assertEquals($this->counts(7, 2, 2, 2, 1), $this->read()[self::PAGE]['ads']['AD1']);
    }

    public function test_S_043_2_a_waybill_not_in_jnt_or_no_waybill_counts_nowhere(): void
    {
        $this->ad('AD1', 'SET1', 'CMP1');
        $this->order('WB1', 'AD1', 'Delivered');
        $this->order('WB2', 'AD1', null);      // wala pa sa J&T
        $this->order('', 'AD1', null);         // walang waybill

        $page = $this->read()[self::PAGE];
        $this->assertEquals($this->counts(1, 1, 0, 0, 0), $page['ads']['AD1']);
        $this->assertEquals($this->counts(1, 1, 0, 0, 0), $page['page']);
    }

    public function test_S_043_3_ad_sets_and_campaigns_roll_up_their_ads(): void
    {
        $this->ad('AD1', 'SET1', 'CMP1');
        $this->ad('AD2', 'SET1', 'CMP1');
        $this->ad('AD3', 'SET2', 'CMP1', page: 'Other Page Name');   // ibang page name ang campaign: dito pa rin
        $this->order('WB1', 'AD1', 'Delivered');
        $this->order('WB2', 'AD2', 'Returned');
        $this->order('WB3', 'AD2', 'In Transit');
        $this->order('WB4', 'AD3', 'For Return');

        $page = $this->read()[self::PAGE];
        $this->assertEquals($this->counts(3, 1, 1, 0, 1), $page['adsets']['SET1']);
        $this->assertEquals($this->counts(1, 0, 0, 1, 0), $page['adsets']['SET2']);
        $this->assertEquals($this->counts(4, 1, 1, 1, 1), $page['campaigns']['CMP1']['counts']);
        $this->assertSame('Campaign CMP1', $page['campaigns']['CMP1']['name']);
    }

    public function test_S_043_3_an_ad_follows_its_latest_report_row(): void
    {
        $this->ad('AD1', 'SET_OLD', 'CMP_OLD', day: '2026-09-01');
        $this->ad('AD1', 'SET_NEW', 'CMP_NEW', day: '2026-10-10');
        $this->order('WB1', 'AD1', 'Delivered');

        $page = $this->read()[self::PAGE];
        $this->assertSame(['CMP_NEW'], array_keys($page['campaigns']));
        $this->assertSame(['SET_NEW'], array_keys($page['adsets']));
    }

    public function test_S_043_4_orders_outside_the_range_or_of_another_page_do_not_count(): void
    {
        $this->ad('AD1', 'SET1', 'CMP1');
        $this->order('WB1', 'AD1', 'Delivered', date: self::START);
        $this->order('WB2', 'AD1', 'Delivered', date: self::END);
        $this->order('WB3', 'AD1', 'Delivered', date: '2026-09-30');
        $this->order('WB4', 'AD1', 'Delivered', date: '2026-10-16');
        $this->order('WB5', 'AD1', 'Delivered', page: 'Another Page');
        $this->order('WB6', 'AD1', 'Delivered', page: '  test PAGE ');   // parehong page match gaya ng page row

        $result = $this->read();
        $this->assertSame([self::PAGE], array_keys($result));
        $this->assertEquals($this->counts(3, 3, 0, 0, 0), $result[self::PAGE]['ads']['AD1']);
    }

    public function test_S_043_5_orders_without_a_known_ad_id_land_in_walang_ad_id_and_everything_adds_up(): void
    {
        $this->ad('AD1', 'SET1', 'CMP1');
        $this->ad('AD2', 'SET2', 'CMP2');
        $this->order('WB1', 'AD1', 'Delivered');
        $this->order('WB2', 'AD2', 'Returned');
        $this->order('WB3', null, 'Delivered');
        $this->order('WB4', '', 'For Return');
        $this->order('WB5', 'UNKNOWN_AD', 'In Transit');
        // dalawang order, iisang waybill: isang beses lang ang bilang (distinct waybill, gaya ng page row)
        $this->order('WB6', 'AD1', 'Returned');
        $this->order('WB6', 'AD1', null);
        // iisang waybill na may dalawang ad id: isang beses, sa isang ad lang (ang MAX na id)
        $this->order('WB7', 'AD1', 'Delivered');
        $this->order('WB7', 'AD2', null);

        $page = $this->read()[self::PAGE];
        $this->assertEquals($this->counts(3, 1, 1, 1, 0), $page['no_ad']);
        $this->assertEquals($this->counts(2, 1, 0, 0, 1), $page['ads']['AD1']);
        $this->assertEquals($this->counts(2, 1, 0, 0, 1), $page['ads']['AD2']);
        $this->assertEquals($this->counts(7, 3, 1, 1, 2), $page['page']);

        $keys = ['shipped', 'delivered', 'in_transit', 'for_return', 'returned'];
        $sum  = $page['no_ad'];
        foreach ($page['campaigns'] as $c) {
            foreach ($keys as $k) $sum[$k] += $c['counts'][$k];
        }
        foreach ($keys as $k) {
            $this->assertSame($page['page'][$k], $sum[$k], $k);
        }
    }

    public function test_S_043_6_one_grouped_query_however_many_ads(): void
    {
        $pages = ['Page A', 'Page B', 'Page C'];
        for ($i = 1; $i <= 40; $i++) {
            $this->ad("AD$i", 'SET' . ($i % 7), 'CMP' . ($i % 3));
            $this->order("WB$i", "AD$i", $i % 2 ? 'Delivered' : 'Returned', page: $pages[$i % 3]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $result  = $this->read($pages);
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $this->assertCount(2, $queries);
        $this->assertCount(1, array_filter($queries, fn ($q) => str_contains($q, 'macro_output') && str_contains($q, 'from_jnts')));
        $this->assertCount(1, array_filter($queries, fn ($q) => str_contains($q, 'ads_manager_reports')));
        $this->assertSame(40, array_sum(array_map(fn ($p) => $p['page']['shipped'], $result)));
    }

    public function test_S_043_6_no_pages_means_no_query(): void
    {
        DB::enableQueryLog();
        $this->assertSame([], $this->read([]));
        $this->assertSame([], DB::getQueryLog());
    }
}

<?php

namespace Tests\Feature\RtsProjection;

use App\Services\ItemAliasResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Projected RTS sa tabi ng Set RTS% sa /owner/private: ang data ng item-summary at ang markup ng cell.
 */
class ProjectedRtsCellTest extends RtsProjectionTestCase
{
    protected const DAY = '2026-10-20';
    private const ITEM = '1 x Zorblat Lamp';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Schema::create('tasks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });
        $this->rates([3 => 0.61]);
    }

    /** 24 Returned + 25 Delivered + 51 In Transit (age 3) → projected 55.11%; $set ang Set RTS%. */
    private function page(string $label, float $set, int $n = 100): void
    {
        DB::table('daily_page_primary_item')->insert([
            'ts_date' => self::DAY, 'page_label' => $label, 'page_key' => strtolower($label),
            'primary_item' => self::ITEM, 'primary_item_key' => ItemAliasResolver::normalize(self::ITEM),
            'primary_orders' => $n, 'total_orders_all' => $n, 'primary_mode_cod' => 599,
        ]);
        DB::table('ads_manager_reports')->insert(['day' => self::DAY, 'page_name' => $label, 'amount_spent_php' => '1000']);
        DB::table('page_item_settings')->insert([
            'page_name' => $label, 'item_name' => self::ITEM, 'rts_pct' => $set, 'effective_date' => '2026-10-01',
            'comment' => '', 'item_value_comment' => '', 'promo' => '', 'mode_cod_int' => 599,
        ]);
        $sub = self::DAY.' 12:00:00';
        $this->orders((int) round($n * .24), $label, self::ITEM, $sub, 'Returned');
        $this->orders((int) round($n * .25), $label, self::ITEM, $sub, 'Delivered');
        $this->orders($n - (int) round($n * .24) - (int) round($n * .25), $label, self::ITEM, $sub, 'In Transit');
    }

    private function rows(): array
    {
        $this->actingAs($this->user());
        $res = $this->getJson('/owner/private/item-summary?start_date='.self::DAY.'&end_date='.self::DAY)->assertOk();

        return array_column($res->json('rows'), null, 'page_key');
    }

    public function test_row_carries_projected_rts_and_red_flag_beyond_five_points_with_enough_orders(): void
    {
        $this->page('Pahina Alpha', 30);   // projected 55.11 → gap −25.11
        $this->page('Pahina Beta', 55);    // gap −0.11
        $this->page('Pahina Gamma', 30, 20);   // 20 order lang → walang flag

        $rows = $this->rows();

        $a = $rows['pahina alpha'];
        $this->assertEqualsWithDelta(55.11, $a['projected_rts_pct'], 0.01);
        $this->assertSame(100, $a['projected_rts_orders']);
        $this->assertEqualsWithDelta(51.0, $a['projected_rts_transit_pct'], 0.01);
        $this->assertTrue($a['projected_rts_flag']);
        $this->assertFalse($rows['pahina beta']['projected_rts_flag']);
        $this->assertFalse($rows['pahina gamma']['projected_rts_flag']);
        $this->assertEqualsWithDelta(30.0, $a['rts_pct'], 0.001);   // ang Set RTS% ay hindi nagbago
    }

    public function test_page_renders_the_projected_cell_with_details_and_red_flag(): void
    {
        $html = $this->actingAs($this->user())->get('/owner/private')->assertOk()->getContent();

        $this->assertStringContainsString("row.projected_rts_pct != null", $html);
        $this->assertStringContainsString("row.projected_rts_flag", $html);
        $this->assertStringContainsString("still in transit", $html);
    }
}

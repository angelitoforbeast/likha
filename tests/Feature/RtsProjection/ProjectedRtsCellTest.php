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
        DB::table('cogs_ceo')->updateOrInsert(['item_name' => self::ITEM, 'date' => '2026-10-01'], ['unit_cost' => 180]);
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

    public function test_mira_rts_is_the_rounded_projection_with_its_typical_error_and_profit_uses_it(): void
    {
        $this->page('Pahina Alpha', 30);
        $this->page('Pahina Beta', 55.1);   // Set RTS = Mira RTS ng Alpha, kaya dapat pareho ang profit

        $rows = $this->rows();
        $a = $rows['pahina alpha'];
        $b = $rows['pahina beta'];

        $this->assertEqualsWithDelta(55.1, $a['mira_rts_pct'], 0.0001);
        $this->assertSame(3, $a['mira_rts_error_pts']);   // edad 3 araw ang mga in transit
        $this->assertNotNull($a['projected_profit']);
        $this->assertNotEquals($a['projected_profit'], $a['projected_profit_mira']);
        $this->assertEqualsWithDelta($b['projected_profit'], $a['projected_profit_mira'], 0.01);
        $this->assertEqualsWithDelta($b['projected_profit'], $b['projected_profit_mira'], 0.01);
        $this->assertEqualsWithDelta(30.0, $a['rts_pct'], 0.001);   // Set RTS% at ang profit niya ay hindi nagbago
    }

    public function test_use_mira_rts_saves_into_set_rts_with_a_log_row(): void
    {
        $this->page('Pahina Alpha', 30);
        $this->user('CEO', 'ceo@example.test')->employeeProfile()->update(['name' => 'Person CEO']);
        $this->actingAs(\App\Models\User::where('email', 'ceo@example.test')->first());

        $this->postJson('/owner/private/item-setting', [
            'scope' => 'rts', 'page_name' => 'Pahina Alpha', 'item_name' => self::ITEM, 'rts_pct' => 55.1,
            'comment' => 'Mira RTS 55.1%', 'mode_cod_int' => 599, 'effective_date' => '2026-10-01', 'source' => 'mira_rts',
        ])->assertOk()->assertJsonPath('ok', true);

        $this->assertEquals(55.1, DB::table('page_item_settings')->where('page_name', 'Pahina Alpha')->value('rts_pct'));
        $log = (array) DB::table('page_item_settings_log')->orderByDesc('id')->first();
        $this->assertSame('ceo@example.test', $log['user_email']);
        $this->assertEquals(30, $log['old_rts_pct']);
        $this->assertEquals(55.1, $log['new_rts_pct']);
        $this->assertSame('mira_rts', $log['source']);
    }

    public function test_save_rejects_an_unknown_source(): void
    {
        $this->page('Pahina Alpha', 30);
        $this->actingAs($this->user());

        $this->postJson('/owner/private/item-setting', [
            'scope' => 'rts', 'page_name' => 'Pahina Alpha', 'item_name' => self::ITEM, 'rts_pct' => 55.1,
            'comment' => 'x', 'mode_cod_int' => 599, 'effective_date' => '2026-10-01', 'source' => 'other',
        ])->assertStatus(422);
    }

    public function test_page_renders_the_mira_rts_column_button_and_profit_column(): void
    {
        $html = $this->actingAs($this->user())->get('/owner/private')->assertOk()->getContent();

        foreach ([
            "id:'mira_rts'", "label:'Mira RTS'", "id:'proj_profit_mira'", "label:'Profit (Mira RTS)'",
            'row.projected_rts_pct != null', 'row.projected_rts_flag', 'still in transit', 'typical error',
            'Use Mira RTS', "source', 'mira_rts'", 'tot().projected_profit_mira',
        ] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
    }
}
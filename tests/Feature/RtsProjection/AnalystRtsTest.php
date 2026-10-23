<?php

namespace Tests\Feature\RtsProjection;

use App\Http\Controllers\OwnerColumnSettingsController;
use App\Services\ItemAliasResolver;
use App\Services\PageSettingsGrid;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ang Mira RTS ay value na tinype ng analyst (may dahilan) sa page_settings (provider analyst); ang formula ay "Projected RTS".
 * API na may sariling key, history sa page_settings, ang column sa /owner/private, CF seed at ang rts-check feed.
 */
class AnalystRtsTest extends RtsProjectionTestCase
{
    private const URL = '/api/owner/analyst-rts';
    private const KEY = 'analyst-key';
    private const ITEM = '1 x Zorblat Lamp';
    protected const DAY = '2026-10-20';
    private const COL_FORMAT = 'owner_private_col_format';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['services.analyst_rts.key' => self::KEY, 'services.rts_check.key' => 'k']);
        (require base_path('database/migrations/2026_10_17_100000_create_page_settings_table.php'))->up();
        Schema::create('tasks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });
        $this->rates([3 => 0.61]);
    }

    private function setting(string $page, float $set = 30): void
    {
        DB::table('page_item_settings')->insert([
            'page_name' => $page, 'item_name' => self::ITEM, 'rts_pct' => $set, 'effective_date' => '2026-10-01',
            'comment' => '', 'item_value_comment' => '', 'promo' => '', 'mode_cod_int' => 599,
        ]);
    }

    private function send(array $rows, ?string $key = self::KEY)
    {
        return $this->postJson(self::URL, ['rows' => $rows], $key === null ? [] : ['X-ANALYST-RTS-KEY' => $key]);
    }

    private function row(string $page, float|string $pct, string $reason = 'Mababa ang return ng huling 10 araw.'): array
    {
        return ['page' => $page, 'item' => self::ITEM, 'rts_pct' => $pct, 'reason' => $reason];
    }

    /** 24 Returned + 25 Delivered + 51 In Transit → projected 55.11%. */
    private function pageWithOrders(string $label, float $set): void
    {
        DB::table('daily_page_primary_item')->insert([
            'ts_date' => self::DAY, 'page_label' => $label, 'page_key' => strtolower($label),
            'primary_item' => self::ITEM, 'primary_item_key' => ItemAliasResolver::normalize(self::ITEM),
            'primary_orders' => 100, 'total_orders_all' => 100, 'primary_mode_cod' => 599,
        ]);
        DB::table('ads_manager_reports')->insert(['day' => self::DAY, 'page_name' => $label, 'amount_spent_php' => '1000']);
        $this->setting($label, $set);
        DB::table('cogs_ceo')->updateOrInsert(['item_name' => self::ITEM, 'date' => '2026-10-01'], ['unit_cost' => 180]);
        $sub = self::DAY.' 12:00:00';
        $this->orders(24, $label, self::ITEM, $sub, 'Returned');
        $this->orders(25, $label, self::ITEM, $sub, 'Delivered');
        $this->orders(51, $label, self::ITEM, $sub, 'In Transit');
    }

    private function summaryRows(): array
    {
        $res = $this->getJson('/owner/private/item-summary?start_date='.self::DAY.'&end_date='.self::DAY)->assertOk();

        return array_column($res->json('rows'), null, 'page_key');
    }

    public function test_analyst_rows_are_not_shown_in_the_page_settings_grid(): void
    {
        $this->setting('Pahina Alpha');
        $this->send([$this->row('Pahina Alpha', 40)])->assertOk();
        $this->assertSame(2, DB::table('page_settings')->where('provider', 'analyst')->count());

        $grid = app(PageSettingsGrid::class)->build();
        $this->assertStringNotContainsString('analyst', json_encode($grid));
    }

    public function test_needs_its_own_key(): void
    {
        $this->setting('Pahina Alpha');
        $this->send([$this->row('Pahina Alpha', 40)], null)->assertStatus(401);
        $this->send([$this->row('Pahina Alpha', 40)], 'wrong')->assertStatus(401);
        $this->postJson(self::URL, ['rows' => [$this->row('Pahina Alpha', 40)]], ['X-RTS-CHECK-KEY' => 'k'])->assertStatus(401);

        config(['services.analyst_rts.key' => '']);
        $this->send([$this->row('Pahina Alpha', 40)], '')->assertStatus(401);
        $this->assertSame(0, DB::table('page_settings')->count());
    }

    public function test_saves_value_reason_and_time_logs_history_and_never_touches_the_set_rts(): void
    {
        $this->setting('Pahina Alpha', 30);

        $this->send([$this->row('  pahina alpha ', 41.256, 'Unang basa.')])->assertOk()
            ->assertJson(['saved' => 1, 'failed' => 0, 'errors' => []]);
        $this->send([$this->row('Pahina Alpha', 38, 'Binaba dahil sa promo.')])->assertOk()->assertJson(['saved' => 1]);

        $this->assertEqualsWithDelta(30.0, (float) DB::table('page_item_settings')->value('rts_pct'), 0.001);
        $key = 'rts_pct:'.ItemAliasResolver::normalize(self::ITEM);
        $pct = DB::table('page_settings')->where('provider', 'analyst')->where('key', $key)->orderBy('id')->get();
        $this->assertSame(['41.26', '38'], $pct->pluck('value')->all());
        $this->assertSame([0, 1], $pct->pluck('active')->map(fn ($v) => (int) $v)->all());
        $this->assertNotNull($pct[0]->replaced_at);
        $this->assertNotNull($pct[1]->created_at);
        $this->assertSame('Pahina Alpha', $pct[1]->page_name);
        $reason = DB::table('page_settings')->where('key', 'rts_reason:'.ItemAliasResolver::normalize(self::ITEM))->where('active', true)->first();
        $this->assertSame('Binaba dahil sa promo.', $reason->value);
    }

    public function test_bad_rows_are_reported_one_by_one_and_the_rest_still_save(): void
    {
        $this->setting('Pahina Alpha');
        $this->setting('Pahina Beta');

        $res = $this->send([
            $this->row('Pahina Alpha', 101),
            $this->row('Pahina Alpha', -1),
            $this->row('Pahina Alpha', 40, str_repeat('x', 501)),
            $this->row('Pahina Alpha', 40, ''),
            $this->row('Walang Page', 40),
            $this->row('Pahina Beta', 55),
        ])->assertOk();

        $res->assertJson(['saved' => 1, 'failed' => 5]);
        $this->assertSame([0, 1, 2, 3, 4], array_column($res->json('errors'), 'index'));
        $this->assertSame(0, DB::table('page_settings')->where('page_name', 'Pahina Alpha')->count());
        $this->assertSame(['55'], DB::table('page_settings')->where('page_name', 'Pahina Beta')->where('key', 'like', 'rts_pct:%')->pluck('value')->all());
    }

    public function test_page_shows_projected_rts_and_the_analyst_value_with_its_gap(): void
    {
        $this->pageWithOrders('Pahina Alpha', 30);
        $this->pageWithOrders('Pahina Beta', 30);
        $this->send([$this->row('Pahina Alpha', 40, 'Una.')])->assertOk();
        $this->send([$this->row('Pahina Alpha', 38.5, 'Pangalawa.')])->assertOk();

        $this->actingAs($this->user());
        $rows = $this->summaryRows();
        $a = $rows['pahina alpha'];
        $this->assertEqualsWithDelta(38.5, $a['analyst_rts_pct'], 0.001);
        $this->assertSame('Pangalawa.', $a['analyst_rts_reason']);
        $this->assertNotNull($a['analyst_rts_at']);
        $this->assertEqualsWithDelta(40.0, $a['analyst_rts_prev'], 0.001);
        $this->assertEqualsWithDelta(-8.5, $a['analyst_gap_pts'], 0.001);   // Set 30 − analyst 38.5
        $this->assertEqualsWithDelta(8.5, $a['analyst_gap_abs'], 0.001);
        $this->assertEqualsWithDelta(55.1, $a['mira_rts_pct'], 0.001);   // ang formula ay hindi gumalaw
        $this->assertEqualsWithDelta(30.0, $a['rts_pct'], 0.001);
        $this->assertNull($rows['pahina beta']['analyst_rts_pct']);
        $this->assertNull($rows['pahina beta']['analyst_gap_abs']);

        $html = $this->get('/owner/private')->assertOk()->getContent();
        foreach (["id:'mira_rts',   label:'Projected RTS'", "id:'analyst_rts'", "label:'Mira RTS'", 'analystTip(row)',
            "id:'analyst_gap_pts'", "id:'analyst_gap_abs'"] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
    }

    public function test_catalog_has_the_new_ids_and_the_formula_column_is_relabelled(): void
    {
        $cat = array_column(OwnerColumnSettingsController::CATALOG['owner_private'], 'label', 'id');
        $this->assertSame('Projected RTS', $cat['mira_rts']);
        $this->assertSame('Mira RTS', $cat['analyst_rts']);
        $this->assertArrayHasKey('analyst_gap_pts', $cat);
        $this->assertArrayHasKey('analyst_gap_abs', $cat);
        $this->assertContains('analyst_rts', OwnerColumnSettingsController::DEFAULT_VISIBLE['owner_private']);
        $this->assertNotContains('analyst_gap_abs', OwnerColumnSettingsController::DEFAULT_VISIBLE['owner_private']);
    }

    public function test_default_rule_is_seeded_once_and_saved_rules_are_never_overwritten(): void
    {
        $ctrl = new OwnerColumnSettingsController();
        $rules = $ctrl->loadColFormat('owner_private')['byCol']['analyst_rts'];
        $ctrl->loadColFormat('owner_private');

        $this->assertSame(['>=', '>='], array_column($rules, 'op'));
        $this->assertSame([5, 3], array_map('intval', array_column($rules, 'value')));
        $this->assertSame(['analyst_gap_abs', 'analyst_gap_abs'], array_column($rules, 'compare_col'));
        $this->assertSame(['#fecaca', '#fde68a'], array_column($rules, 'bg'));
        $saved = json_decode(DB::table('app_settings')->where('key', self::COL_FORMAT)->value('value'), true);
        $this->assertCount(2, $saved['groups']);   // dito rin ang seed ng Projected RTS

        // Binura ng owner → hindi na bumabalik; at ang may sariling rule na ay hindi ginagalaw.
        DB::table('app_settings')->where('key', self::COL_FORMAT)->update(['value' => json_encode(['groups' => []])]);
        $this->assertArrayNotHasKey('analyst_rts', $ctrl->loadColFormat('owner_private')['byCol']);

        DB::table('app_settings')->where('key', 'like', self::COL_FORMAT.'_%seeded')->delete();
        $own = ['groups' => [['cols' => ['analyst_rts'], 'rules' => [['op' => '>', 'value' => 9, 'bg' => '#000000', 'color' => '#fff', 'bold' => false, 'label' => 'sarili']]]]];
        DB::table('app_settings')->where('key', self::COL_FORMAT)->update(['value' => json_encode($own)]);
        $this->assertSame('sarili', $ctrl->loadColFormat('owner_private')['byCol']['analyst_rts'][0]['label']);
    }

    public function test_feed_carries_the_analyst_fields(): void
    {
        $sub = '2026-10-20 12:00:00';
        $this->orders(24, 'Page B', 'Item C', $sub, 'Returned');
        $this->orders(25, 'Page B', 'Item C', $sub, 'Delivered');
        $this->orders(51, 'Page B', 'Item C', $sub, 'In Transit');
        DB::table('page_item_settings')->insert(['page_name' => 'Page B', 'item_name' => 'Item C', 'rts_pct' => 30,
            'effective_date' => '2026-10-10', 'mode_cod_int' => 599]);
        $this->postJson(self::URL, ['rows' => [['page' => 'Page B', 'item' => 'Item C', 'rts_pct' => 52, 'reason' => 'Dahilan.']]],
            ['X-ANALYST-RTS-KEY' => self::KEY])->assertOk()->assertJson(['saved' => 1]);

        $rows = $this->getJson('/api/owner/rts-check?from=2026-10-02&to=2026-10-23', ['X-RTS-CHECK-KEY' => 'k'])->assertOk()->json('rows');

        $this->assertEqualsWithDelta(52.0, $rows[0]['analyst_rts'], 0.001);
        $this->assertSame('Dahilan.', $rows[0]['analyst_reason']);
        $this->assertNotNull($rows[0]['analyst_at']);
    }
}

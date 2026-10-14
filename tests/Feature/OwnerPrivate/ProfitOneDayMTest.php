<?php

namespace Tests\Feature\OwnerPrivate;

use App\Http\Controllers\OwnerColumnSettingsController;
use App\Models\FeeSetting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * PROF(1D)-M: ang SF_M seed, ang lugar ng column sa naka-save na order, ang Fee Settings para sa
 * bagong key (at ang cache bump), at ang markup ng column sa /owner/private.
 */
class ProfitOneDayMTest extends OwnerPrivateTestCase
{
    private const SEED      = 'database/migrations/2026_10_14_100000_seed_shipping_fee_per_order_m.php';
    private const COLS      = 'database/migrations/2026_10_14_100100_add_proj_prof_1d_m_to_owner_private_cols.php';
    private const VERSION   = 'owner_private:cache_version';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        // Kailangan ng layout (task badge) at ng /owner/private index(). Gaya ng ClaudeActionViewTest.
        Schema::create('tasks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });
        Schema::create('ads_manager_reports', function (Blueprint $t) {
            $t->id();
            $t->string('page_name')->nullable();
        });
    }

    protected function migrationPaths(): array
    {
        return array_merge(parent::migrationPaths(), [
            'database/migrations/2026_03_05_000001_create_fee_settings_table.php',
            'database/migrations/2026_04_24_000009_create_daily_page_primary_item_table.php',
            self::SEED,
            self::COLS,
        ]);
    }

    private function sfM(): array
    {
        return DB::table('fee_settings')->where('setting_key', 'shipping_fee_per_order_m')
            ->orderBy('host_scope')->get(['host_scope', 'setting_value', 'effective_date'])
            ->map(fn ($r) => [$r->host_scope, (float) $r->setting_value, substr((string) $r->effective_date, 0, 10)])->all();
    }

    private function saveCols(array $config): void
    {
        DB::table('app_settings')->updateOrInsert(['key' => 'owner_private_cols'], ['value' => json_encode($config), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function savedCols(): array
    {
        return json_decode(DB::table('app_settings')->where('key', 'owner_private_cols')->value('value'), true);
    }

    // ── SF_M seed ────────────────────────────────────────────────────────────

    public function test_the_seed_gives_both_scopes_40_from_2000_01_01_once_and_bumps_the_cache(): void
    {
        $expected = [['incepxion', 40.0, '2000-01-01'], ['likha', 40.0, '2000-01-01']];
        $this->assertSame($expected, $this->sfM());

        Cache::forever(self::VERSION, 'v-old');
        (require base_path(self::SEED))->up();

        $this->assertSame($expected, $this->sfM(), 'a second run adds nothing');
        $this->assertNotSame('v-old', Cache::get(self::VERSION));
        $this->assertSame(40.0, FeeSetting::getRate('shipping_fee_per_order_m', 'likhaaitech.com', '2026-10-09'));
    }

    // ── Column order / visibility ───────────────────────────────────────────

    public function test_the_settings_migration_puts_the_column_right_after_prof_1d_and_changes_nothing_else(): void
    {
        $saved = [
            'order'           => ['price', 'np_per_order', 'proj_prof_1d', 'adspent', 'item_val'],
            'hidden'          => ['ship', 'cod_fee'],
            'visible_by_role' => ['Marketing' => ['adspent', 'proj_prof_1d'], 'Marketing - OIC' => ['adspent']],
        ];
        $this->saveCols($saved);

        (require base_path(self::COLS))->up();
        $after = $this->savedCols();
        $this->assertSame(['price', 'np_per_order', 'proj_prof_1d', 'proj_prof_1d_m', 'adspent', 'item_val'], $after['order']);
        $this->assertSame($saved['hidden'], $after['hidden']);
        $this->assertSame($saved['visible_by_role'], $after['visible_by_role']);

        (require base_path(self::COLS))->up();
        $this->assertSame($after, $this->savedCols(), 'a second run changes nothing');

        $ctl = new OwnerColumnSettingsController();
        $ceo = $ctl->loadConfig('owner_private', 'CEO');
        $this->assertNotContains('proj_prof_1d_m', $ceo['hidden']);
        $this->assertSame('proj_prof_1d_m', $ceo['order'][array_search('proj_prof_1d', $ceo['order'], true) + 1]);
        foreach (['Marketing', 'Marketing - OIC'] as $role) {
            $this->assertContains('proj_prof_1d_m', $ctl->loadConfig('owner_private', $role)['hidden'], $role);
        }
    }

    public function test_without_a_saved_config_the_ceo_sees_the_column_after_prof_1d_and_marketing_does_not(): void
    {
        $ctl = new OwnerColumnSettingsController();
        $ceo = $ctl->loadConfig('owner_private', 'CEO');

        $this->assertNotContains('proj_prof_1d_m', $ceo['hidden']);
        $this->assertSame('proj_prof_1d_m', $ceo['order'][array_search('proj_prof_1d', $ceo['order'], true) + 1]);
        $this->assertContains('proj_prof_1d_m', $ctl->loadConfig('owner_private', 'Marketing')['hidden']);
        $this->assertNotContains('proj_prof_1d_m', OwnerColumnSettingsController::CEO_ONLY);
        $catalog = array_column(OwnerColumnSettingsController::CATALOG['owner_private'], 'label', 'id');
        $this->assertSame('PROF(1D)-M', $catalog['proj_prof_1d_m']);
    }

    // ── Fee Settings ────────────────────────────────────────────────────────

    public function test_fee_settings_store_update_and_delete_the_new_key_and_each_bumps_the_cache(): void
    {
        $this->actingAs($this->user());

        Cache::forever(self::VERSION, 'v-old');
        $this->post('/jnt/fee-settings', ['setting_key' => 'shipping_fee_per_order_m', 'setting_value' => '45', 'effective_date' => '2026-10-10'])
            ->assertRedirect(route('fee-settings.index'))->assertSessionHasNoErrors();
        $row = FeeSetting::where('setting_key', 'shipping_fee_per_order_m')->whereDate('effective_date', '2026-10-10')->firstOrFail();
        $this->assertSame(['likha', 45.0], [$row->host_scope, (float) $row->setting_value]);
        $this->assertNotSame('v-old', Cache::get(self::VERSION), 'store');

        Cache::forever(self::VERSION, 'v-old');
        $this->put("/jnt/fee-settings/{$row->id}", ['setting_value' => '47.5', 'effective_date' => '2026-10-11'])
            ->assertRedirect(route('fee-settings.index'))->assertSessionHasNoErrors();
        $this->assertSame([47.5, '2026-10-11'], [(float) $row->fresh()->setting_value, $row->fresh()->effective_date->format('Y-m-d')]);
        $this->assertNotSame('v-old', Cache::get(self::VERSION), 'update');

        Cache::forever(self::VERSION, 'v-old');
        $this->delete("/jnt/fee-settings/{$row->id}")->assertRedirect(route('fee-settings.index'));
        $this->assertNull($row->fresh());
        $this->assertNotSame('v-old', Cache::get(self::VERSION), 'delete');
    }

    /** @return array<string, array{0: array, 1: string}> body, field na may error */
    public static function badStores(): array
    {
        return [
            'unknown key'        => [['setting_key' => 'shipping_fee_per_order_x', 'setting_value' => '40', 'effective_date' => '2026-10-10'], 'setting_key'],
            'negative value'     => [['setting_key' => 'shipping_fee_per_order_m', 'setting_value' => '-1', 'effective_date' => '2026-10-10'], 'setting_value'],
            'not a number'       => [['setting_key' => 'shipping_fee_per_order_m', 'setting_value' => 'abc', 'effective_date' => '2026-10-10'], 'setting_value'],
            'empty value'        => [['setting_key' => 'shipping_fee_per_order_m', 'setting_value' => '', 'effective_date' => '2026-10-10'], 'setting_value'],
            'duplicate key+date' => [['setting_key' => 'shipping_fee_per_order_m', 'setting_value' => '41', 'effective_date' => '2000-01-01'], 'effective_date'],
        ];
    }

    #[DataProvider('badStores')]
    public function test_a_bad_store_is_refused_and_leaves_the_cache_version(array $body, string $field): void
    {
        $this->actingAs($this->user());
        $before = $this->sfM();
        Cache::forever(self::VERSION, 'v-old');

        $this->post('/jnt/fee-settings', $body)->assertSessionHasErrors($field);

        $this->assertSame($before, $this->sfM());
        $this->assertSame('v-old', Cache::get(self::VERSION));
    }

    /** @return array<string, array{0: array, 1: string}> body, field na may error */
    public static function badUpdates(): array
    {
        return [
            'negative value'     => [['setting_value' => '-1', 'effective_date' => '2026-10-10'], 'setting_value'],
            'not a number'       => [['setting_value' => 'abc', 'effective_date' => '2026-10-10'], 'setting_value'],
            'empty value'        => [['setting_value' => '', 'effective_date' => '2026-10-10'], 'setting_value'],
            'duplicate key+date' => [['setting_value' => '41', 'effective_date' => '2000-01-01'], 'effective_date'],
        ];
    }

    #[DataProvider('badUpdates')]
    public function test_a_bad_update_is_refused_and_leaves_the_cache_version(array $body, string $field): void
    {
        $this->actingAs($this->user());
        $row = FeeSetting::create(['setting_key' => 'shipping_fee_per_order_m', 'setting_value' => 45, 'effective_date' => '2026-10-10', 'host_scope' => 'likha']);
        Cache::forever(self::VERSION, 'v-old');

        $this->put("/jnt/fee-settings/{$row->id}", $body)->assertSessionHasErrors($field);

        $this->assertSame([45.0, '2026-10-10'], [(float) $row->fresh()->setting_value, $row->fresh()->effective_date->format('Y-m-d')]);
        $this->assertSame('v-old', Cache::get(self::VERSION));
    }

    public function test_the_fee_settings_page_offers_the_new_key_and_lists_its_rows_with_edit_and_delete(): void
    {
        $this->actingAs($this->user());
        $html = $this->get('/jnt/fee-settings')->assertOk()->getContent();

        $this->assertStringContainsString('<option value="shipping_fee_per_order_m">', $html);
        $a = strpos($html, 'Shipping Fee per Order (M) History');
        $this->assertNotFalse($a);
        $block = substr($html, $a, strpos($html, 'COD Fee Rate History') - $a);
        $seed = FeeSetting::where('setting_key', 'shipping_fee_per_order_m')->where('host_scope', 'likha')->firstOrFail();
        $this->assertStringContainsString('₱40.00', $block);
        $this->assertStringContainsString(route('fee-settings.update', $seed), $block);
        $this->assertStringContainsString(route('fee-settings.destroy', $seed), $block);
        $this->assertStringContainsString("this.selectedKey === 'shipping_fee_per_order_m'", $html);
    }

    // ── /owner/private markup ───────────────────────────────────────────────

    public function test_the_page_has_the_column_in_every_place_a_money_column_needs(): void
    {
        $this->actingAs($this->user());
        $html = $this->get('/owner/private')->assertOk()->getContent();

        foreach ([
            "{ id:'proj_prof_1d_m', label:'PROF(1D)-M',  sort:'projected_profit_last_day_m'",           // defaultCols
            "(col.id==='proj_prof_1d_m'?pbStyleN(row.projected_profit_last_day_m,1):'')",               // row style
            "x-text=\"md(row.projected_profit_last_day_m)\"",                                          // body cell
            "(col.id==='proj_prof_1d_m'?pbStyleN(tot().projected_profit_last_day_m,1):'')",             // TOTAL style
            "x-text=\"md(tot().projected_profit_last_day_m)\"",                                        // TOTAL cell
            "'proj_prof_1d','proj_prof_1d_m','proj_prof_3d'",                                          // TOTAL exclusion list
            "t.projected_profit_last_day_m=(t.projected_profit_last_day_m||0)+Number(r.projected_profit_last_day_m)", // tot()
            "if(!hasP1M) t.projected_profit_last_day_m=null;",                                          // tot(): blank when all null
            "case 'proj_prof_1d_m': return row.projected_profit_last_day_m;",                          // cellValueFor
        ] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
    }
}

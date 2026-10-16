<?php

namespace Tests\Feature\OwnerPrivate;

use App\Services\ItemAliasResolver;
use Illuminate\Database\Schema\Blueprint;
use App\Jobs\ImportLikhaFromGoogleSheet;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;

/**
 * Ang orders ng isang page-day ay bilang mula sa macro_output (kasabay ng proceed), hindi ang
 * naka-store na primary_orders na pwedeng luma na. Kaya hindi na nagiging negative ang TCPR.
 */
class OrdersLiveTest extends OwnerPrivateTestCase
{
    private const DAY = '2026-10-09';

    protected function setUp(): void
    {
        parent::setUp();

        // Mga table na gawa sa kamay (MySQL-only ang totoong migrations): mga column na binabasa lang.
        Schema::create('macro_output', function (Blueprint $t) {
            $t->id();
            $t->string('PAGE')->nullable();
            $t->string('ITEM_NAME')->nullable();
            $t->string('STATUS')->nullable();
            $t->string('COD')->nullable();
            $t->string('waybill')->nullable();
            $t->string('TIMESTAMP')->nullable();
            $t->string('ADDRESS')->nullable();
            $t->text('status_logs')->nullable();
            $t->text('HISTORICAL LOGS')->nullable();
            $t->boolean('validate_1')->default(false);
            $t->boolean('validate_2')->default(false);
            $t->boolean('item_checker')->default(false);
            $t->boolean('edited_item_name')->default(false);
            $t->boolean('edited_cod')->default(false);
            $t->boolean('edited_address')->default(false);
            $t->date('ts_date')->nullable();
            $t->timestamps();
        });
        Schema::create('ads_manager_reports', function (Blueprint $t) {
            $t->id();
            $t->date('day')->nullable();
            $t->string('page_name')->nullable();
            $t->string('amount_spent_php')->nullable();
        });
        Schema::create('page_item_settings', function (Blueprint $t) {
            $t->id();
            $t->string('page_name');
            $t->string('item_name');
            $t->decimal('rts_pct', 6, 2)->nullable();
            $t->date('effective_date');
            $t->string('comment')->nullable();
            $t->string('item_value_comment')->nullable();
            $t->string('promo')->nullable();
            $t->integer('mode_cod_int')->nullable();
            $t->timestamps();
        });
        Schema::create('cogs_ceo', function (Blueprint $t) {
            $t->id();
            $t->date('date');
            $t->string('item_name');
            $t->decimal('unit_cost', 12, 2)->nullable();
            $t->timestamps();
        });
        Schema::create('supply_excluded_pages', function (Blueprint $t) {
            $t->id();
            $t->string('page_name');
            $t->timestamps();
        });

        DB::table('fee_settings')->insert([
            'setting_key' => 'shipping_fee_per_order', 'setting_value' => 33, 'effective_date' => '2025-01-01',
            'host_scope' => 'likha', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function migrationPaths(): array
    {
        return array_merge(parent::migrationPaths(), [
            'database/migrations/2025_09_08_134839_create_cogs_table.php',
            'database/migrations/2026_04_24_000009_create_daily_page_primary_item_table.php',
            'database/migrations/2026_03_05_000001_create_fee_settings_table.php',
            'database/migrations/2026_04_23_000002_create_supply_item_settings_table.php',
        ]);
    }

    /** Naka-store: $stored orders. Live sa macro_output: $live rows, $proceed dito ay proceed. */
    private function staleDay(int $stored, int $live, int $proceed, float $ads = 640): void
    {
        DB::table('daily_page_primary_item')->insert([
            'ts_date' => self::DAY, 'page_label' => 'Pahina Alpha', 'page_key' => 'pahina alpha',
            'primary_item' => '1 x Zorblat Lamp', 'primary_item_key' => ItemAliasResolver::normalize('1 x Zorblat Lamp'),
            'primary_orders' => $stored, 'total_orders_all' => $stored, 'primary_mode_cod' => 599,
        ]);
        DB::table('macro_output')->insert(array_map(fn ($i) => [
            'PAGE' => 'Pahina Alpha', 'ITEM_NAME' => '1 x Zorblat Lamp', 'STATUS' => $i < $proceed ? 'Proceed' : 'Pending',
            'COD' => '599', 'ts_date' => self::DAY, 'waybill' => null,
        ], range(0, $live - 1)));
        DB::table('ads_manager_reports')->insert(['day' => self::DAY, 'page_name' => 'Pahina Alpha', 'amount_spent_php' => (string) $ads]);
    }

    public function test_item_summary_uses_live_orders_so_tcpr_is_never_negative(): void
    {
        $this->staleDay(24, 64, 60);
        $this->actingAs($this->user());

        $res = $this->getJson('/owner/private/item-summary?start_date=' . self::DAY . '&end_date=' . self::DAY)->assertOk();
        $a = array_column($res->json('rows'), null, 'page_key')['pahina alpha'];

        $this->assertSame(64, $a['orders']);
        $this->assertSame(60, $a['proceed_orders']);
        $this->assertEqualsWithDelta(640 / 64, $a['cpp'], 0.0001);
        $tcpr = (1 - $a['proceed_orders'] / $a['orders']) * 100;
        $this->assertGreaterThanOrEqual(0, $tcpr);
        $this->assertEqualsWithDelta(6.25, $tcpr, 0.0001);
        $this->assertSame(64, $a['orders_last_day']);
    }

    public function test_breakdown_matrix_cell_shows_live_orders(): void
    {
        $this->staleDay(24, 64, 60);
        $this->actingAs($this->user());
        // Data lang ang sinusubok: blankong view; ang view composer ng app ay bumibilang ng tasks.
        Schema::create('tasks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('status')->nullable();
        });
        $dir = storage_path('framework/testing/views-029/owner');
        @mkdir($dir, 0777, true);
        file_put_contents($dir . '/private-breakdown-matrix.blade.php', '');
        View::getFinder()->prependLocation(dirname($dir));

        $res = $this->get('/owner/private/breakdown?start_date=' . self::DAY . '&end_date=' . self::DAY)->assertOk();
        $page = collect($res->viewData('pages'))->firstWhere('page_key', 'pahina alpha');

        $this->assertSame(64, $page['cells'][self::DAY]['orders']);
    }

    public function test_page_range_breakdown_uses_live_orders_for_display_and_gross(): void
    {
        $this->staleDay(24, 64, 60);
        $this->actingAs($this->user());

        $res = $this->getJson('/owner/private/page-range-breakdown?page_key=pahina+alpha&start_date=' . self::DAY . '&end_date=' . self::DAY)->assertOk();
        $row = collect($res->json('rows'))->firstWhere('date', self::DAY);

        $this->assertSame(64, $row['primary_orders']);
        $this->assertSame(64, $row['total_orders']);
        $this->assertSame(60, $row['proceed']);
    }
    public function test_jnt_supply_profit_uses_live_orders_next_to_proceed(): void
    {
        $this->staleDay(24, 64, 60);
        Schema::create('supply_settings', function (Blueprint $t) {
            $t->id();
            $t->string('key', 100)->unique();
            $t->string('value', 50);
            $t->string('label', 150);
            $t->string('group', 30)->default('general');
            $t->string('data_type', 10)->default('float');
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();
        });
        Schema::create('item_class_rules', function (Blueprint $t) {
            $t->id();
            $t->string('class_key', 10);
            $t->string('label', 80);
            $t->string('badge_tailwind', 120)->nullable();
            $t->integer('sort_order')->default(100);
            $t->string('rule_type', 20)->default('velocity_tier');
            $t->float('min_velocity')->nullable();
            $t->integer('window_days')->nullable();
            $t->float('alive_min')->nullable();
            $t->integer('alive_window')->nullable();
            $t->integer('age_threshold')->nullable();
            $t->boolean('is_fixed')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('from_jnts', function (Blueprint $t) {
            $t->id();
            $t->string('waybill_number')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });
        $this->actingAs($this->user());

        $this->withoutExceptionHandling();
        $res = $this->getJson('/jnt/supply?as_of_date=' . self::DAY . '&debug_item=zorblat+lamp')->assertOk();

        $this->assertSame(64, $res->json('total_orders'));
        $this->assertSame(60, $res->json('total_proceed'));
    }

    /** @return array<string, array{0: string, 1: array, 2: bool}> route, body, dapat bang ma-bump */
    public static function orderEdits(): array
    {
        return [
            'inline COD'          => ['/encoder/checker_1/update-field', ['id' => 1, 'field' => 'COD', 'value' => '699'], true],
            'inline item'         => ['/encoder/checker_1/update-field', ['id' => 1, 'field' => 'ITEM_NAME', 'value' => '2 x Zorblat Lamp'], true],
            'inline address only' => ['/encoder/checker_1/update-field', ['id' => 1, 'field' => 'ADDRESS', 'value' => 'Bagong kalye'], false],
            'bulk status'         => ['/encoder/checker_1/update', ['records' => [1 => ['STATUS' => 'Proceed']]], true],
            'bulk address only'   => ['/encoder/checker_1/update', ['records' => [1 => ['ADDRESS' => 'Bagong kalye']]], false],
        ];
    }

    #[DataProvider('orderEdits')]
    public function test_macro_output_edits_of_order_fields_bump_the_owner_private_cache(string $url, array $body, bool $bumps): void
    {
        $this->staleDay(1, 1, 0);
        $this->actingAs($this->user());
        Cache::forget('owner_private:cache_version');

        $this->post($url, $body);

        $this->assertSame($bumps, Cache::has('owner_private:cache_version'));
    }

    public function test_refresh_primary_items_button_bumps_the_owner_private_cache(): void
    {
        $this->actingAs($this->user());
        Cache::forget('owner_private:cache_version');

        $this->postJson('/owner/private/refresh-primary-items', ['start_date' => self::DAY, 'end_date' => self::DAY])->assertOk();

        $this->assertTrue(Cache::has('owner_private:cache_version'));
    }

    public function test_likha_import_end_bumps_the_owner_private_cache(): void
    {
        Cache::forget('owner_private:cache_version');

        $m = new ReflectionMethod(ImportLikhaFromGoogleSheet::class, 'afterImport');
        $m->invoke(new ImportLikhaFromGoogleSheet(1));

        $this->assertTrue(Cache::has('owner_private:cache_version'));
    }
}
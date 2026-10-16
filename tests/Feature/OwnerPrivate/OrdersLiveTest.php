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
class OrdersLiveTest extends MacroOutputTestCase
{
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
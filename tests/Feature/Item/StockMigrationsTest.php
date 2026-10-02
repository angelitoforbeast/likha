<?php

namespace Tests\Feature\Item;

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StockMigrationsTest extends ItemTestCase
{
    public function test_seeds_eight_categories_in_order(): void
    {
        $this->assertSame([
            'Bahay at Paglilinis', 'Ilaw at Kuryente', 'Sasakyan at Motor', 'Repair at DIY',
            'Health at Beauty', 'Fashion at Accessories', 'Office at School', 'Iba pa',
        ], DB::table('item_categories')->orderBy('sort_order')->pluck('name')->all());

        $this->assertSame(range(1, 8), DB::table('item_categories')->orderBy('sort_order')->pluck('sort_order')->map(fn ($v) => (int) $v)->all());
    }

    public function test_stock_start_setting_is_today_in_manila(): void
    {
        $value = DB::table('app_settings')->where('key', 'item_stock_start')->value('value');

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $value);
        $this->assertSame(Carbon::now('Asia/Manila')->toDateString(), $value);
    }

    private function migration(string $file): object
    {
        return require base_path('database/migrations/' . $file);
    }

    public function test_palugit_override_column_is_nullable_and_empty_by_default(): void
    {
        $this->assertTrue(Schema::hasColumn('supply_item_settings', 'palugit_override'));

        DB::table('supply_item_settings')->insert(['item_name' => 'Glow Tape', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertNull(DB::table('supply_item_settings')->value('palugit_override'));
    }

    public function test_palugit_defaults_are_seeded_and_never_overwrite_existing_rows(): void
    {
        $expected = [
            'palugit_new' => '3', 'palugit_scaling' => '14', 'palugit_consistent' => '10',
            'palugit_active' => '7', 'palugit_declining' => '0', 'palugit_lugi' => '3',
        ];
        $rows = DB::table('supply_settings')->where('group', 'item_palugit')->orderBy('sort_order')->get();
        $this->assertSame($expected, $rows->pluck('value', 'key')->all());
        $this->assertSame([60, 61, 62, 63, 64, 65], $rows->pluck('sort_order')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(['int'], $rows->pluck('data_type')->unique()->values()->all());

        DB::table('supply_settings')->where('key', 'palugit_new')->update(['value' => '5']);
        DB::table('supply_settings')->where('key', 'palugit_lugi')->delete();

        $this->migration('2026_10_02_100400_seed_item_palugit_settings.php')->up();

        $this->assertSame('5', DB::table('supply_settings')->where('key', 'palugit_new')->value('value'));
        $this->assertSame('3', DB::table('supply_settings')->where('key', 'palugit_lugi')->value('value'));
        $this->assertSame(6, DB::table('supply_settings')->where('group', 'item_palugit')->count());
    }

    private const CATEGORY_MIGRATION = '2026_10_02_100500_hide_category_column_owner_private.php';

    private function savedColumnConfig(): array
    {
        return json_decode(DB::table('app_settings')->where('key', 'owner_private_cols')->value('value'), true);
    }

    public function test_category_column_is_hidden_and_stripped_from_roles_in_saved_config(): void
    {
        DB::table('app_settings')->insert([
            'key'   => 'owner_private_cols',
            'value' => json_encode([
                'order'           => ['adspent', 'category', 'stock'],
                'hidden'          => ['cpp'],
                'visible_by_role' => ['Marketing' => ['adspent', 'category'], 'Marketing - OIC' => ['category'], 'Other' => ['category', 'x']],
            ]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration = $this->migration(self::CATEGORY_MIGRATION);
        $migration->up();
        $migration->up(); // idempotent

        $this->assertSame([
            'order'           => ['adspent', 'category', 'stock'],
            'hidden'          => ['cpp', 'category'],
            'visible_by_role' => ['Marketing' => ['adspent'], 'Marketing - OIC' => [], 'Other' => ['x']],
        ], $this->savedColumnConfig());

        $migration->down(); // role grants hindi ibinabalik
        $this->assertSame(['cpp'], $this->savedColumnConfig()['hidden']);
        $this->assertSame(['adspent'], $this->savedColumnConfig()['visible_by_role']['Marketing']);
    }

    public function test_category_column_is_hidden_by_default_when_nothing_is_saved(): void
    {
        $this->migration(self::CATEGORY_MIGRATION)->up();

        $this->assertSame(0, DB::table('app_settings')->where('key', 'owner_private_cols')->count());
        $this->assertContains('category', app(\App\Http\Controllers\OwnerColumnSettingsController::class)->loadConfig('owner_private', 'CEO')['hidden']);
    }

    public function test_assignment_item_key_is_unique(): void
    {
        $cat = DB::table('item_categories')->value('id');
        DB::table('item_category_assignments')->insert(['item_key' => 'glow tape', 'category_id' => $cat]);

        $this->expectException(QueryException::class);
        DB::table('item_category_assignments')->insert(['item_key' => 'glow tape', 'category_id' => $cat]);
    }
}

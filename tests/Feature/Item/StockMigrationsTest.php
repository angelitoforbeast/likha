<?php

namespace Tests\Feature\Item;

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

    public function test_assignment_item_key_is_unique(): void
    {
        $cat = DB::table('item_categories')->value('id');
        DB::table('item_category_assignments')->insert(['item_key' => 'glow tape', 'category_id' => $cat]);

        $this->expectException(QueryException::class);
        DB::table('item_category_assignments')->insert(['item_key' => 'glow tape', 'category_id' => $cat]);
    }
}

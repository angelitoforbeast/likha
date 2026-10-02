<?php

namespace Tests\Feature\Item;

use Illuminate\Support\Facades\DB;

/**
 * POST /item/category at /item/supply-settings — CEO lang.
 */
class ItemEditTest extends ItemTestCase
{
    private function categoryId(string $name): int
    {
        return (int) DB::table('item_categories')->where('name', $name)->value('id');
    }

    private function send(string $url, array $body, string $role = 'CEO')
    {
        $email = strtolower(preg_replace('/\W+/', '', $role)) . '@example.test';
        $user  = \App\Models\User::where('email', $email)->first() ?? $this->user($role, $email);

        return $this->actingAs($user)->postJson($url, $body);
    }

    private function settingsRow(string $name, int $lead = 7, int $safety = 3): void
    {
        DB::table('supply_item_settings')->insert([
            'item_name' => $name, 'lead_time_days' => $lead, 'safety_days' => $safety,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_non_ceo_gets_403_and_nothing_is_written(): void
    {
        foreach (['Marketing', 'Marketing - OIC'] as $role) {
            $this->send('/item/category', ['item_name' => 'Glow Tape', 'new_category' => 'Bago'], $role)
                ->assertStatus(403)->assertJson(['ok' => false, 'error' => 'CEO lang']);
            $this->send('/item/supply-settings', ['item_name' => 'Glow Tape', 'lead_time_days' => 5, 'safety_days' => 2], $role)
                ->assertStatus(403);
        }

        $this->assertSame(0, DB::table('item_category_assignments')->count());
        $this->assertSame(0, DB::table('supply_item_settings')->count());
        $this->assertSame(0, DB::table('item_categories')->where('name', 'Bago')->count());
    }

    public function test_invalid_input_gets_422_and_writes_nothing(): void
    {
        $ok = ['item_name' => 'Glow Tape', 'lead_time_days' => 7, 'safety_days' => 3];
        $settings = [
            'lead 256'     => [['lead_time_days' => 256], 'lead_time_days'],
            'lead -1'      => [['lead_time_days' => -1], 'lead_time_days'],
            'lead x'       => [['lead_time_days' => 'x'], 'lead_time_days'],
            'safety 256'   => [['safety_days' => 256], 'safety_days'],
            'missing lead' => [['lead_time_days' => null], 'lead_time_days'],
            'empty key'    => [['item_name' => '3 x'], 'item_name'],
        ];
        foreach ($settings as $label => [$override, $field]) {
            $this->send('/item/supply-settings', array_merge($ok, $override))
                ->assertStatus(422)->assertJsonValidationErrors($field);
        }

        $category = [
            'unknown category' => [['category_id' => 99999], 'category_id'],
            'name too long'    => [['new_category' => str_repeat('a', 61)], 'new_category'],
            'both id and name' => [['category_id' => $this->categoryId('Iba pa'), 'new_category' => 'X'], 'new_category'],
            'item too long'    => [['item_name' => str_repeat('a', 191), 'new_category' => 'X'], 'item_name'],
            // "İ" lowercases to 2 code points → key lampas 190 kahit 190 chars ang name.
            'key too long'     => [['item_name' => str_repeat('İ', 190), 'new_category' => 'X'], 'item_name'],
            'empty key'        => [['item_name' => '3 x', 'new_category' => 'X'], 'item_name'],
        ];
        foreach ($category as $label => [$override, $field]) {
            $this->send('/item/category', array_merge(['item_name' => 'Glow Tape'], $override))
                ->assertStatus(422)->assertJsonValidationErrors($field);
        }

        $this->assertSame(0, DB::table('item_category_assignments')->count());
        $this->assertSame(0, DB::table('supply_item_settings')->count());
        $this->assertSame(8, DB::table('item_categories')->count());
    }

    public function test_new_category_name_reuses_existing_one_case_insensitively(): void
    {
        $id = $this->categoryId('Repair at DIY');

        $this->send('/item/category', ['item_name' => 'Glow Tape', 'new_category' => ' repair at diy '])
            ->assertOk()->assertJson(['ok' => true, 'category_id' => $id]);

        $this->assertSame(8, DB::table('item_categories')->count());
        $this->assertSame($id, (int) DB::table('item_category_assignments')->value('category_id'));
    }

    public function test_genuinely_new_category_is_created_with_next_sort_order(): void
    {
        $res = $this->send('/item/category', ['item_name' => 'Glow Tape', 'new_category' => 'Pet Supplies'])
            ->assertOk()->json();

        $row = DB::table('item_categories')->where('name', 'Pet Supplies')->first();
        $this->assertSame(9, (int) $row->sort_order);
        $this->assertSame((int) $row->id, $res['category_id']);
        $this->assertSame('Pet Supplies', end($res['categories'])['name']);
        $this->assertCount(9, $res['categories']);
    }

    public function test_assignment_is_stored_by_base_key_and_updated_in_place(): void
    {
        $a = $this->categoryId('Ilaw at Kuryente');
        $b = $this->categoryId('Iba pa');

        $this->send('/item/category', ['item_name' => '1 x GLOW TAPE', 'category_id' => $a])->assertOk();
        $this->assertSame('glow tape', DB::table('item_category_assignments')->value('item_key'));

        $this->send('/item/category', ['item_name' => '2 x Glow Tape', 'category_id' => $b])->assertOk();
        $this->assertSame(1, DB::table('item_category_assignments')->count());
        $this->assertSame($b, (int) DB::table('item_category_assignments')->value('category_id'));
        $this->assertNotNull(DB::table('item_category_assignments')->value('updated_by'));
    }

    public function test_sending_no_category_clears_the_assignment(): void
    {
        $this->send('/item/category', ['item_name' => 'Glow Tape', 'category_id' => $this->categoryId('Iba pa')])->assertOk();

        $this->send('/item/category', ['item_name' => '1 x GLOW TAPE'])
            ->assertOk()->assertJson(['ok' => true, 'category_id' => null]);

        $this->assertSame(0, DB::table('item_category_assignments')->count());
    }

    public function test_blank_new_category_counts_as_absent_and_clears_the_assignment(): void
    {
        $this->send('/item/category', ['item_name' => 'Glow Tape', 'category_id' => $this->categoryId('Iba pa')])->assertOk();

        $this->send('/item/category', ['item_name' => 'Glow Tape', 'new_category' => '   '])
            ->assertOk()->assertJson(['ok' => true, 'category_id' => null]);

        $this->assertSame(0, DB::table('item_category_assignments')->count());
        $this->assertSame(8, DB::table('item_categories')->count());
    }

    public function test_new_category_that_already_exists_twice_yields_one_row(): void
    {
        DB::table('item_categories')->insert(['name' => 'Bagong Cat', 'sort_order' => 9, 'created_at' => now(), 'updated_at' => now()]);

        foreach ([1, 2] as $i) {
            $this->send('/item/category', ['item_name' => 'Glow Tape', 'new_category' => 'Bagong Cat'])->assertOk();
        }

        $this->assertSame(1, DB::table('item_categories')->where('name', 'Bagong Cat')->count());
    }

    public function test_setting_kv_is_ceo_only_and_item_palugit_keys_accept_only_0_to_255(): void
    {
        DB::table('supply_settings')->insert([
            'key' => 'running_threshold', 'value' => '1', 'label' => 'Running threshold', 'group' => 'velocity',
            'data_type' => 'float', 'sort_order' => 41, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $value = fn (string $key) => DB::table('supply_settings')->where('key', $key)->value('value');

        foreach (['Marketing', 'Marketing - OIC'] as $role) {
            $this->send('/jnt/supply/setting-kv', ['key' => 'palugit_new', 'value' => '9'], $role)->assertStatus(403);
        }
        $this->assertSame('3', $value('palugit_new'));

        foreach (['256', '-1', 'x', '2.5'] as $bad) {
            $this->send('/jnt/supply/setting-kv', ['key' => 'palugit_new', 'value' => $bad])->assertStatus(422);
        }
        $this->assertSame('3', $value('palugit_new'));

        foreach (['0', '255'] as $good) {
            $this->send('/jnt/supply/setting-kv', ['key' => 'palugit_new', 'value' => $good])
                ->assertOk()->assertJson(['success' => true, 'value' => $good]);
        }

        // Hindi item_palugit: dating range pa rin.
        $this->send('/jnt/supply/setting-kv', ['key' => 'running_threshold', 'value' => '300'])->assertOk();
        $this->assertSame('300', $value('running_threshold'));
    }

    public function test_supply_settings_update_all_rows_sharing_a_key_and_keep_their_names(): void
    {
        $this->settingsRow('Glow Tape', 7, 3);
        $this->settingsRow('glow  tape', 8, 2);

        $this->send('/item/supply-settings', ['item_name' => 'GLOW TAPE', 'lead_time_days' => 12, 'safety_days' => 5])->assertOk();

        $rows = DB::table('supply_item_settings')->orderBy('id')->get();
        $this->assertSame(['Glow Tape', 'glow  tape'], $rows->pluck('item_name')->all());
        $this->assertSame([12, 12], $rows->pluck('lead_time_days')->map(fn ($v) => (int) $v)->all());
        $this->assertSame([5, 5], $rows->pluck('safety_days')->map(fn ($v) => (int) $v)->all());
    }

    public function test_supply_settings_update_every_row_with_the_same_key(): void
    {
        $this->settingsRow('Glow Tape', 7, 3);

        $this->send('/item/supply-settings', ['item_name' => '1 x GLOW TAPE', 'lead_time_days' => 10, 'safety_days' => 4])
            ->assertOk()->assertJson(['ok' => true]);

        $this->assertSame(1, DB::table('supply_item_settings')->count());
        $row = DB::table('supply_item_settings')->first();
        $this->assertSame('Glow Tape', $row->item_name);
        $this->assertSame([10, 4], [(int) $row->lead_time_days, (int) $row->safety_days]);
    }

    public function test_supply_settings_write_palugit_override_with_safety_and_blank_clears_only_the_override(): void
    {
        $this->settingsRow('Glow Tape', 7, 3);

        $this->send('/item/supply-settings', ['item_name' => 'Glow Tape', 'lead_time_days' => 7, 'safety_days' => 5])->assertOk();
        $row = DB::table('supply_item_settings')->first();
        $this->assertSame([5, 5], [(int) $row->safety_days, (int) $row->palugit_override]);

        foreach ([null, ''] as $blank) {
            DB::table('supply_item_settings')->update(['palugit_override' => 5]);
            $this->send('/item/supply-settings', ['item_name' => 'Glow Tape', 'lead_time_days' => 9, 'safety_days' => $blank])->assertOk();
            $row = DB::table('supply_item_settings')->first();
            $this->assertNull($row->palugit_override);
            $this->assertSame([9, 5], [(int) $row->lead_time_days, (int) $row->safety_days]);
        }

        // Bagong row na walang safety_days: DB default (3) ang safety, walang override.
        $this->send('/item/supply-settings', ['item_name' => 'Bagong Item', 'lead_time_days' => 4])->assertOk();
        $row = DB::table('supply_item_settings')->where('item_name', 'BAGONG ITEM')->orWhere('item_name', 'Bagong Item')->first();
        $this->assertSame([4, 3], [(int) $row->lead_time_days, (int) $row->safety_days]);
        $this->assertNull($row->palugit_override);
    }

    public function test_supply_settings_insert_a_row_named_by_the_base_when_none_exists(): void
    {
        $this->send('/item/supply-settings', ['item_name' => '1 x GLOW TAPE', 'lead_time_days' => 10, 'safety_days' => 4])
            ->assertOk();

        $row = DB::table('supply_item_settings')->first();
        $this->assertSame('GLOW TAPE', $row->item_name);
        $this->assertSame([10, 4], [(int) $row->lead_time_days, (int) $row->safety_days]);
    }
}

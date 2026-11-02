<?php

namespace Tests\Feature\OwnerPrivate;

use App\Services\ItemAliasResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 216, S-216.1: sino ang nag-set ng Item Value (COGS), ng CEO value at ng RTS%, at kailan (Manila), sa row ng
 * /owner/private/item-summary. Galing sa page_item_settings_log; ang CEO value ay sa CEO view lang.
 */
class SetByHoverTest extends OwnerPrivateTestCase
{
    private const END = '2026-10-09';
    private const PAGE = 'Pahina Alpha';
    private const ITEM = '1 x Zorblat Lamp';
    /** Ilang query sa page_item_settings_log ang isang data load bago ang spec 216 (rtsSetByMap). */
    private const RTS_LOG_QUERIES = 4;

    protected function setUp(): void
    {
        parent::setUp();

        // Gaya ng ItemSummaryProfitMTest: ang mga column lang na binabasa ng itemSummary.
        Schema::create('macro_output', function (Blueprint $t) {
            $t->id();
            $t->string('PAGE')->nullable();
            $t->string('ITEM_NAME')->nullable();
            $t->string('STATUS')->nullable();
            $t->string('COD')->nullable();
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
        // Ang scope ng log ay walang migration dito (gaya ng RtsSetByMapTest).
        Schema::table('page_item_settings_log', fn (Blueprint $t) => $t->string('scope')->nullable());
        Schema::create('item_type_mappings', function (Blueprint $t) {
            $t->id();
            $t->string('item_name');
            $t->string('item_type');
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
            'database/migrations/2026_10_14_100000_seed_shipping_fee_per_order_m.php',
            'database/migrations/2026_05_04_000001_create_page_item_settings_log_table.php',
            'database/migrations/2026_05_15_140000_add_cogs_ceo_columns_to_page_item_settings_log.php',
            'database/migrations/2026_05_20_140000_add_promo_to_page_item_settings.php',
            'database/migrations/2026_11_01_100000_add_source_to_page_item_settings_log.php',
        ]);
    }

    /** Isang page na may iisang item sa END: RTS 30% mula 2026-10-01, cogs 170, cogs_ceo 180. */
    private function page(string $label = self::PAGE, string $item = self::ITEM, ?float $cogs = 170, ?float $ceo = 180): void
    {
        DB::table('daily_page_primary_item')->insert([
            'ts_date' => self::END, 'page_label' => $label, 'page_key' => strtolower($label),
            'primary_item' => $item, 'primary_item_key' => ItemAliasResolver::normalize($item),
            'primary_orders' => 10, 'total_orders_all' => 10, 'primary_mode_cod' => 599,
        ]);
        DB::table('macro_output')->insert(array_map(fn ($i) => [
            'PAGE' => $label, 'ITEM_NAME' => $item, 'STATUS' => 'Proceed', 'COD' => '599', 'ts_date' => self::END,
        ], range(0, 9)));
        DB::table('ads_manager_reports')->insert(['day' => self::END, 'page_name' => $label, 'amount_spent_php' => '1000']);
        DB::table('page_item_settings')->insert([
            'page_name' => $label, 'item_name' => $item, 'rts_pct' => 30, 'effective_date' => '2026-10-01',
            'comment' => 'set', 'item_value_comment' => '', 'promo' => '', 'mode_cod_int' => 599,
        ]);
        if ($cogs !== null) DB::table('cogs')->insert(['item_name' => $item, 'date' => '2026-10-01', 'unit_cost' => $cogs]);
        if ($ceo !== null) DB::table('cogs_ceo')->insert(['item_name' => $item, 'date' => '2026-10-01', 'unit_cost' => $ceo]);
    }

    /** Isang row sa edit log; ang created_at ay oras sa Manila (ang app timezone). */
    private function log(?string $email, string $at, array $over = []): void
    {
        DB::table('page_item_settings_log')->insert(array_merge([
            'user_email' => $email, 'action' => 'update', 'page_name' => self::PAGE, 'item_name' => self::ITEM,
            'effective_date' => '2026-10-01', 'scope' => 'cogs', 'new_item_value' => 170,
            'created_at' => $at, 'updated_at' => $at,
        ], $over));
    }

    private function person(string $email, string $name, string $role = 'Marketing'): void
    {
        $this->user($role, $email)->employeeProfile()->update(['name' => $name]);
    }

    private function load(string $role = 'CEO', array $query = []): \Illuminate\Testing\TestResponse
    {
        $email = strtolower(str_replace([' ', '-'], '', $role)) . '-viewer@example.test';
        $this->actingAs(\App\Models\User::where('email', $email)->first() ?? $this->user($role, $email));

        return $this->getJson('/owner/private/item-summary?' . http_build_query($query + ['start_date' => self::END, 'end_date' => self::END, 'refresh' => 1]))->assertOk();
    }

    private function row(string $role = 'CEO', array $query = []): array
    {
        return array_column($this->load($role, $query)->json('rows'), null, 'page_key')[strtolower(self::PAGE)];
    }

    private static function who(array $row, string $field): array
    {
        // 'missing' = wala ang field sa row (iba sa null = walang alam na setter).
        return array_map(fn ($k) => array_key_exists($field . $k, $row) ? $row[$field . $k] : 'missing', ['_set_by', '_set_at']);
    }

    public function test_S_216_1_1_a_manual_item_value_on_owner_private_shows_who_set_it_and_when(): void
    {
        $this->page();
        $this->person('ana@example.test', 'Ana Reyes');
        $this->log('ana@example.test', '2026-11-02 15:05:00', ['source' => null]);

        $row = $this->row();

        $this->assertSame('cogs', $row['item_value_source']);
        $this->assertSame(['Ana Reyes', 'Nov 2, 2026 3:05 PM'], self::who($row, 'item_value'));
    }

    public function test_S_216_1_2_a_value_from_item_cogs_or_cogs_sync_names_its_setter_and_time(): void
    {
        $this->page('Pahina Alpha');
        $this->person('ben@example.test', 'Ben Cruz');
        $this->log('ben@example.test', '2026-10-30 09:15:00', ['page_name' => '', 'source' => 'item_cogs']);
        $this->assertSame(['Ben Cruz', 'Oct 30, 2026 9:15 AM'], self::who($this->row(), 'item_value'));

        $this->log(null, '2026-10-31 00:10:00', ['page_name' => '', 'source' => 'cogs_sync', 'action' => 'create']);
        $this->assertSame(['system (cogs sync)', 'Oct 31, 2026 12:10 AM'], self::who($this->row(), 'item_value'));
    }

    public function test_S_216_1_3_the_rts_cell_says_who_and_when(): void
    {
        $this->page();
        $this->person('cara@example.test', 'Cara Lim');
        $this->log('cara@example.test', '2026-10-01 18:40:00', ['scope' => 'rts', 'new_item_value' => null, 'new_rts_pct' => 30]);

        $row = $this->row();

        $this->assertSame(['Cara Lim', 'Oct 1, 2026 6:40 PM'], [$row['rts_set_by'], $row['rts_set_at']]);
    }

    public function test_S_216_1_5_a_ceo_value_shows_its_setter_and_time_in_the_ceo_view(): void
    {
        $this->page();
        $this->person('dina@example.test', 'Dina Santos', 'CEO');
        $this->log('dina@example.test', '2026-10-05 11:00:00', ['scope' => 'cogs_ceo', 'new_item_value' => null, 'new_item_value_ceo' => 180]);

        $row = $this->row();

        $this->assertSame(['Dina Santos', 'Oct 5, 2026 11:00 AM'], self::who($row, 'item_value_ceo'));
        // Ang linya ng CEO value ay hindi ang setter ng Item Value (cogs): walang log ang cogs dito.
        $this->assertSame([null, null], self::who($row, 'item_value'));
    }

    public function test_S_216_1_6_the_marketing_view_and_non_ceo_users_get_nothing_about_the_ceo_value(): void
    {
        $this->page();
        $this->person('dina@example.test', 'Dina Santos', 'CEO');
        $this->person('ana@example.test', 'Ana Reyes');
        $this->log('ana@example.test', '2026-11-02 15:05:00');
        $this->log('dina@example.test', '2026-10-05 11:00:00', ['scope' => 'cogs_ceo', 'new_item_value' => null, 'new_item_value_ceo' => 180]);

        foreach ([['Marketing', []], ['Marketing-OIC', []], ['CEO', ['view_as' => 'marketing']]] as [$role, $query]) {
            $res = $this->load($role, $query);
            $row = array_column($res->json('rows'), null, 'page_key')[strtolower(self::PAGE)];
            $this->assertSame([null, null], self::who($row, 'item_value_ceo'), $role);
            $this->assertNull($row['item_value_ceo'], $role);
            $this->assertStringNotContainsString('Dina Santos', $res->getContent(), $role);
            $this->assertStringNotContainsString('Oct 5, 2026', $res->getContent(), $role);
            // Ang Item Value (cogs) ay kita sa lahat ng view.
            $this->assertSame(['Ana Reyes', 'Nov 2, 2026 3:05 PM'], self::who($row, 'item_value'), $role);
        }
    }

    public function test_S_216_1_7_a_value_without_a_matching_log_line_shows_no_set_by_and_no_error(): void
    {
        $this->page();
        $this->assertSame([null, null, null, null], [...self::who($this->row(), 'item_value'), ...self::who($this->row(), 'item_value_ceo')]);
        $this->assertSame([null, null], [$this->row()['rts_set_by'], $this->row()['rts_set_at']]);

        // Ang huling log line ng araw na iyon ay ibang value (hal. binago nang walang log): hindi siya ang nag-set ng ipinapakita.
        $this->log('ana@example.test', '2026-10-02 08:00:00', ['new_item_value' => 150]);
        $this->assertSame([null, null], self::who($this->row(), 'item_value'));
    }

    public function test_S_216_1_8_the_latest_log_line_for_the_value_wins_and_aliases_resolve(): void
    {
        DB::table('item_type_mappings')->insert([
            ['item_name' => self::ITEM, 'item_type' => 'Zorblat Family'],
            ['item_name' => 'Zorblat Lamp V2', 'item_type' => 'Zorblat Family'],
        ]);
        $this->page();
        $this->person('ana@example.test', 'Ana Reyes');
        $this->person('ben@example.test', 'Ben Cruz');
        $this->log('ana@example.test', '2026-10-01 10:00:00');
        $this->log('ben@example.test', '2026-10-01 09:00:00', ['item_name' => 'Zorblat Lamp V2']); // mas mataas ang id
        // Ibang petsa o walang value (RTS-only save): hindi binibilang.
        $this->log('ana@example.test', '2026-10-03 10:00:00', ['effective_date' => '2026-09-01']);
        $this->log('ana@example.test', '2026-10-04 10:00:00', ['scope' => 'rts', 'new_item_value' => null, 'new_rts_pct' => 30]);

        $this->assertSame(['Ben Cruz', 'Oct 1, 2026 9:00 AM'], self::who($this->row(), 'item_value'));
    }

    public function test_S_216_1_9_a_renamed_or_removed_user_shows_the_current_name_or_the_email(): void
    {
        $this->page();
        $this->person('ana@example.test', 'Ana Reyes');
        $this->log('ana@example.test', '2026-10-01 10:00:00');
        DB::table('employee_profiles')->where('name', 'Ana Reyes')->update(['name' => 'Ana R. Dela Cruz']);
        $this->assertSame('Ana R. Dela Cruz', $this->row()['item_value_set_by']);

        $this->log('gone@example.test', '2026-10-02 10:00:00');
        $this->assertSame(['gone@example.test', 'Oct 2, 2026 10:00 AM'], self::who($this->row(), 'item_value'));
    }

    public function test_S_216_1_10_the_lookup_adds_at_most_two_queries_per_data_load_and_none_per_row(): void
    {
        $this->actingAs($this->user('CEO', 'ceo-viewer@example.test'));
        $counts = [];
        foreach ([1 => range(1, 1), 6 => range(2, 6)] as $n => $pages) {
            foreach ($pages as $i) {
                $this->page('Pahina ' . $i, 'Item ' . $i);
                $this->log('ana@example.test', '2026-10-01 10:00:00', ['page_name' => 'Pahina ' . $i, 'item_name' => 'Item ' . $i]);
                $this->log('ana@example.test', '2026-10-01 10:00:00', ['page_name' => 'Pahina ' . $i, 'item_name' => 'Item ' . $i, 'scope' => 'rts', 'new_item_value' => null, 'new_rts_pct' => 30]);
            }
            DB::flushQueryLog();
            DB::enableQueryLog();
            $rows = $this->getJson('/owner/private/item-summary?start_date=' . self::END . '&end_date=' . self::END . '&refresh=1')->assertOk()->json('rows');
            $log = DB::getQueryLog();
            DB::disableQueryLog();
            $this->assertCount($n, $rows);
            $counts[$n] = [
                'all' => count($log),
                'log' => count(array_filter($log, fn ($q) => str_contains($q['query'], 'page_item_settings_log') || in_array('page_item_settings_log', $q['bindings'], true))),
            ];
        }

        // Walang query sa edit log kada row: pareho ang bilang sa 1 at 6 na page (ang "all" ay may dating kada-page na query).
        $this->assertSame($counts[1]['log'], $counts[6]['log']);
        // Ang edit log: ang dati ng rtsSetByMap + hanggang 2 ng bagong lookup (isa lang ngayon: ang select).
        $this->assertLessThanOrEqual(self::RTS_LOG_QUERIES + 2, $counts[6]['log']);
    }
}

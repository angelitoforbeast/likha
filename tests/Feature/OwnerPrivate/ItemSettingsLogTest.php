<?php

namespace Tests\Feature\OwnerPrivate;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Lahat ng sumusulat sa RTS / promo / COGS ay may row sa page_item_settings_log:
 * delete sa /owner/private, edit at delete sa /item/cogs, at ang cogs:sync-from-macro.
 */
class ItemSettingsLogTest extends OwnerPrivateTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        // Kailangan ng layout (task badge) ng bawat page.
        Schema::create('tasks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });

        // page_item_settings: ang kasalukuyang hugis (ang mga lumang ALTER migration ay mysql/pgsql lang).
        Schema::create('page_item_settings', function (Blueprint $t) {
            $t->id();
            $t->string('page_name');
            $t->string('item_name');
            $t->decimal('rts_pct', 5, 2)->nullable();
            $t->string('promo', 255)->default('NONE');
            $t->date('effective_date');
            $t->string('comment', 500)->nullable();
            $t->string('item_value_comment', 500)->nullable();
            $t->integer('mode_cod_int')->nullable();
            $t->timestamps();
        });
        // cogs_ceo: ang migration nito ay may INSERT IGNORE (mysql) na seed.
        Schema::create('cogs_ceo', function (Blueprint $t) {
            $t->id();
            $t->date('date');
            $t->string('item_name');
            $t->decimal('unit_cost', 12, 2)->nullable();
            $t->json('history_logs')->nullable();
            $t->timestamps();
            $t->unique(['item_name', 'date']);
        });
        Schema::create('macro_output', function (Blueprint $t) {
            $t->id();
            $t->string('ITEM_NAME')->nullable();
            $t->string('TIMESTAMP')->nullable();
        });
    }

    protected function migrationPaths(): array
    {
        return array_merge(parent::migrationPaths(), [
            'database/migrations/2025_09_08_134839_create_cogs_table.php',
            'database/migrations/2026_05_04_000001_create_page_item_settings_log_table.php',
            'database/migrations/2026_05_15_140000_add_cogs_ceo_columns_to_page_item_settings_log.php',
            'database/migrations/2026_05_20_140000_add_promo_to_page_item_settings.php',
            'database/migrations/2026_11_01_100000_add_source_to_page_item_settings_log.php',
        ]);
    }

    private function ceo(): void
    {
        $this->actingAs($this->user('CEO', 'ceo@example.test'));
    }

    private function logs(): array
    {
        return DB::table('page_item_settings_log')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    }

    private function setting(array $over = []): void
    {
        DB::table('page_item_settings')->insert(array_merge([
            'page_name' => 'Page One', 'item_name' => 'Item A', 'effective_date' => '2026-10-05',
            'mode_cod_int' => 499, 'rts_pct' => 25, 'promo' => 'B1T1',
        ], $over));
    }

    private function cogs(string $date, ?float $cost, string $item = 'Item A'): void
    {
        DB::table('cogs')->insert(['item_name' => $item, 'date' => $date, 'unit_cost' => $cost, 'created_at' => now(), 'updated_at' => now()]);
    }

    public static function deletedFields(): array
    {
        return [
            'rts'   => ['rts',   'old_rts_pct',    25.0,   []],
            // may RTS pa ang row: nananatili ito, promo → 'NONE'
            'promo' => ['promo', 'old_promo',      'B1T1', []],
            'cogs'  => ['cogs',  'old_item_value', 120.0,  []],
        ];
    }

    #[DataProvider('deletedFields')]
    public function test_S_104_1_owner_private_delete_writes_a_delete_row_old_to_null_with_the_user(string $field, string $oldCol, $old, array $row): void
    {
        $this->setting($row);
        $this->cogs('2026-10-05', 120);
        DB::table('cogs_ceo')->insert(['item_name' => 'Item A', 'date' => '2026-10-05', 'unit_cost' => 90]);
        $this->ceo();

        $this->postJson('/owner/private/item-setting/delete', [
            'page_name' => 'Page One', 'item_name' => 'Item A', 'effective_date' => '2026-10-05',
            'mode_cod_int' => 499, 'field' => $field,
        ])->assertOk()->assertJson(['ok' => true]);

        $logs = $this->logs();
        $this->assertCount(1, $logs);
        $row = $logs[0];
        $this->assertSame('delete', $row['action']);
        $this->assertSame('owner_private', $row['source']);
        $this->assertSame('ceo@example.test', $row['user_email']);
        $this->assertSame(['Page One', 'Item A', '2026-10-05'], [$row['page_name'], $row['item_name'], $row['effective_date']]);
        is_float($old) ? $this->assertEquals($old, (float) $row[$oldCol]) : $this->assertSame($old, $row[$oldCol]);
        $field === 'promo'
            ? $this->assertSame('NONE', $row['new_promo'])
            : $this->assertNull($row['new' . substr($oldCol, 3)]);
        if ($field === 'cogs') {
            $this->assertEquals(90.0, (float) $row['old_item_value_ceo']);
            $this->assertNull($row['new_item_value_ceo']);
        }
    }

    private function deleteField(string $field)
    {
        return $this->postJson('/owner/private/item-setting/delete', [
            'page_name' => 'Page One', 'item_name' => 'Item A', 'effective_date' => '2026-10-05',
            'mode_cod_int' => 499, 'field' => $field,
        ]);
    }

    private function settingRow(): ?array
    {
        $r = DB::table('page_item_settings')->first(['rts_pct', 'promo', 'comment']);
        return $r ? ['rts_pct' => $r->rts_pct === null ? null : (float) $r->rts_pct, 'promo' => $r->promo, 'comment' => $r->comment] : null;
    }

    public static function promoDeletes(): array
    {
        return [
            'S-107.1 row with RTS: kept, promo NONE'   => [['rts_pct' => 25],                     ['rts_pct' => 25.0, 'promo' => 'NONE', 'comment' => null]],
            'S-107.2 no RTS, no comment: row removed'  => [['rts_pct' => null],                   null],
            'S-107.2 no RTS, with comment: kept, NONE' => [['rts_pct' => null, 'comment' => 'x'], ['rts_pct' => null, 'promo' => 'NONE', 'comment' => 'x']],
        ];
    }

    #[DataProvider('promoDeletes')]
    public function test_S_107_1_2_delete_promo_writes_none_or_removes_the_empty_row_and_logs_it(array $row, ?array $after): void
    {
        $this->setting($row);
        $this->ceo();

        $this->deleteField('promo')->assertOk()->assertJson(['ok' => true]);

        $this->assertSame($after, $this->settingRow());
        $logs = $this->logs();
        $this->assertCount(1, $logs);
        $this->assertSame(['B1T1', 'NONE'], [$logs[0]['old_promo'], $logs[0]['new_promo']]);
        // edit-logs: struck through pa rin bilang "deleted", hindi "cleared"
        $this->assertStringContainsString(
            '<span class="delta-old delta-text">B1T1</span><span class="delta-arrow">→</span><span class="delta-deleted">deleted</span>',
            $this->get('/owner/private/edit-logs')->assertOk()->getContent()
        );
    }

    public function test_S_107_3_delete_rts_on_a_row_with_promo_keeps_the_row_with_rts_null(): void
    {
        $this->setting();
        $this->ceo();

        $this->deleteField('rts')->assertOk();

        $this->assertSame(['rts_pct' => null, 'promo' => 'B1T1', 'comment' => null], $this->settingRow());
    }

    public static function bothDeleted(): array
    {
        return [
            'rts then promo'               => [['rts', 'promo'], []],
            'promo then rts'               => [['promo', 'rts'], []],
            // promo NONE na: walang natitira pagka-delete ng RTS → burado ang row
            'rts on a row with promo NONE' => [['rts'],          ['promo' => 'NONE']],
        ];
    }

    #[DataProvider('bothDeleted')]
    public function test_S_107_4_deleting_both_removes_the_row(array $fields, array $row): void
    {
        $this->setting($row);
        $this->ceo();

        foreach ($fields as $f) $this->deleteField($f)->assertOk();

        $this->assertNull($this->settingRow());
    }

    public function test_S_107_5_saving_rts_with_no_promo_anywhere_writes_none(): void
    {
        $this->ceo();

        $this->postJson('/owner/private/item-setting', [
            'page_name' => 'Page One', 'item_name' => 'Item A', 'effective_date' => '2026-10-05',
            'mode_cod_int' => 499, 'scope' => 'rts', 'rts_pct' => 30, 'comment' => 'bagong RTS',
        ])->assertOk();

        $this->assertSame('NONE', DB::table('page_item_settings')->value('promo'));
    }

    public function test_S_107_7_a_failed_delete_gives_a_readable_message_without_sql(): void
    {
        $this->setting();
        $this->ceo();
        Schema::drop('page_item_settings');

        $res = $this->deleteField('rts')->assertStatus(500)->assertJson(['ok' => false]);

        $msg = (string) $res->json('message');
        $this->assertNotSame('', $msg);
        $this->assertStringNotContainsStringIgnoringCase('sql', $msg);
        $this->assertStringNotContainsString('page_item_settings', $msg);
    }

    public function test_S_104_2_owner_private_delete_of_a_field_with_no_value_writes_no_row(): void
    {
        $this->setting(['rts_pct' => null]);
        $this->ceo();

        foreach (['rts', 'cogs'] as $field) {
            $this->postJson('/owner/private/item-setting/delete', [
                'page_name' => 'Page One', 'item_name' => 'Item A', 'effective_date' => '2026-10-05',
                'mode_cod_int' => 499, 'field' => $field,
            ])->assertOk();
        }

        $this->assertSame([], $this->logs());
    }

    public function test_S_104_3_item_cogs_update_and_delete_write_rows_with_old_new_and_the_user(): void
    {
        DB::table('macro_output')->insert([
            ['ITEM_NAME' => 'Item A', 'TIMESTAMP' => '2026-10-05 10:00:00'],
            ['ITEM_NAME' => 'Item A', 'TIMESTAMP' => '2026-10-06 10:00:00'],
        ]);
        $this->cogs('2026-10-05', 100);
        $this->ceo();

        $this->postJson('/item/cogs/update', ['item_name' => 'Item A', 'date' => '2026-10-05', 'price' => 110])->assertOk();
        $this->postJson('/item/cogs/update', ['item_name' => 'Item A', 'date' => '2026-10-06', 'price' => 115])->assertOk();
        $this->postJson('/item/cogs/delete', ['item_name' => 'Item A', 'date' => '2026-10-06'])->assertOk();
        $this->postJson('/item/cogs/delete', ['item_name' => 'Item A', 'date' => '2026-10-07'])->assertOk(); // walang row

        $got = array_map(fn ($r) => [
            $r['action'], $r['source'], $r['user_email'], $r['page_name'], $r['item_name'], $r['effective_date'],
            $r['old_item_value'] === null ? null : (float) $r['old_item_value'],
            $r['new_item_value'] === null ? null : (float) $r['new_item_value'],
        ], $this->logs());

        $this->assertSame([
            ['update', 'item_cogs', 'ceo@example.test', '', 'Item A', '2026-10-05', 100.0, 110.0],
            ['create', 'item_cogs', 'ceo@example.test', '', 'Item A', '2026-10-06', null, 115.0],
            ['delete', 'item_cogs', 'ceo@example.test', '', 'Item A', '2026-10-06', 115.0, null],
        ], $got);
        $this->assertEquals(110.0, (float) DB::table('cogs')->where('date', '2026-10-05')->value('unit_cost'));
    }

    public function test_S_104_4_item_cogs_clean_redundant_writes_a_delete_row_per_removed_cell(): void
    {
        $this->cogs('2026-09-30', 100);
        $this->cogs('2026-10-01', 100); // redundant
        $this->cogs('2026-10-02', 120);
        $this->ceo();

        $this->postJson('/item/cogs/clean-redundant', ['month' => '2026-10'])->assertOk()->assertJson(['deleted' => 1]);

        $logs = $this->logs();
        $this->assertCount(1, $logs);
        $this->assertSame(['delete', 'item_cogs', 'ceo@example.test', 'Item A', '2026-10-01'],
            [$logs[0]['action'], $logs[0]['source'], $logs[0]['user_email'], $logs[0]['item_name'], $logs[0]['effective_date']]);
        $this->assertEquals(100.0, (float) $logs[0]['old_item_value']);
    }

    public function test_S_104_5_cogs_sync_logs_only_rows_whose_value_changed_attributed_to_the_system(): void
    {
        DB::table('macro_output')->insert([
            ['ITEM_NAME' => 'Item A', 'TIMESTAMP' => '2026-10-05 10:00:00'], // may naunang 100 → bagong row 100
            ['ITEM_NAME' => 'Item A', 'TIMESTAMP' => '2026-10-03 10:00:00'], // may row na → skip
            ['ITEM_NAME' => 'Item B', 'TIMESTAMP' => '2026-10-05 10:00:00'], // walang naunang presyo → NULL, walang log
        ]);
        $this->cogs('2026-10-03', 100);

        Artisan::call('cogs:sync-from-macro', ['--month' => '2026-10']);

        $this->assertSame(3, DB::table('cogs')->count());
        $logs = $this->logs();
        $this->assertCount(1, $logs);
        $this->assertSame(['create', 'cogs_sync', null, 'Item A', '2026-10-05', null],
            [$logs[0]['action'], $logs[0]['source'], $logs[0]['user_email'], $logs[0]['item_name'], $logs[0]['effective_date'], $logs[0]['old_item_value']]);
        $this->assertEquals(100.0, (float) $logs[0]['new_item_value']);
    }

    private function logRow(array $over): void
    {
        DB::table('page_item_settings_log')->insert(array_merge([
            'user_email' => null, 'action' => 'update', 'page_name' => '', 'item_name' => 'Item A',
            'effective_date' => '2026-10-05', 'created_at' => '2026-10-20 10:00:00', 'updated_at' => '2026-10-20 10:00:00',
        ], $over));
    }

    public function test_S_104_6_edit_logs_lists_new_rows_with_labels_and_the_source_filter(): void
    {
        $this->logRow(['user_email' => 'ceo@example.test', 'action' => 'delete', 'page_name' => 'Page One', 'source' => 'owner_private', 'old_rts_pct' => 25]);
        $this->logRow(['user_email' => 'ceo@example.test', 'action' => 'update', 'source' => 'item_cogs', 'item_name' => 'Item Cogs', 'old_item_value' => 100, 'new_item_value' => 110]);
        $this->logRow(['action' => 'create', 'source' => 'cogs_sync', 'item_name' => 'Item Sync', 'new_item_value' => 100]);
        $this->ceo();

        $html = $this->get('/owner/private/edit-logs')->assertOk()->getContent();
        $this->assertStringContainsString('>DELETE<', $html);
        $this->assertMatchesRegularExpression('#<span class="delta-old">25\.0%</span><span class="delta-arrow">→</span><span[^>]*>deleted</span>#u', $html);
        foreach (['class="log-src">/owner/private<', 'class="log-src">/item/cogs<', 'class="log-src">cogs sync<', 'system (cogs sync)'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
        $this->assertStringNotContainsString('— anonymous —', $html);

        $only = fn (string $q) => collect($this->get('/owner/private/edit-logs?' . $q)->assertOk()->viewData('rows')->items())->pluck('item_name')->all();
        $this->assertSame(['Item Sync'], $only('source=cogs_sync'));
        $this->assertSame(['Item Cogs'], $only('source=item_cogs'));
        $this->assertSame(['Item A'], $only('source=owner_private'));
        $this->assertSame(['Item Cogs'], $only('item=Cogs'));
        // '' na page_name (global na COGS) ay hindi lumalabas sa Page dropdown
        $this->assertSame(['Page One'], $this->get('/owner/private/edit-logs')->viewData('allPages')->all());
    }

    public function test_S_104_7_existing_rows_without_source_read_as_owner_private_and_show_as_before(): void
    {
        $this->logRow(['user_email' => 'ceo@example.test', 'action' => 'update', 'page_name' => 'Page One', 'old_rts_pct' => 20, 'new_rts_pct' => 25]);
        $this->logRow(['action' => 'create', 'page_name' => 'Page One', 'item_name' => 'Item Anon', 'new_item_value' => 50]);
        $this->ceo();

        $html = $this->get('/owner/private/edit-logs')->assertOk()->getContent();
        $this->assertStringContainsString('<span class="delta"><span class="delta-old">20.0%</span><span class="delta-arrow">→</span><span class="delta-new">25.0%</span></span>', $html);
        $this->assertStringContainsString('— anonymous —', $html);
        $this->assertStringContainsString('class="log-src">/owner/private<', $html);
        $this->assertStringNotContainsString('deleted</span>', $html);
        $this->assertCount(2, $this->get('/owner/private/edit-logs?source=owner_private')->viewData('rows')->items());
    }
}

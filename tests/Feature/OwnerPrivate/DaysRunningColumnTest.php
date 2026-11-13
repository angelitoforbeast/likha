<?php

namespace Tests\Feature\OwnerPrivate;

use App\Http\Controllers\OwnerColumnSettingsController;
use App\Http\Controllers\OwnerPrivateController;
use App\Services\ItemAliasResolver;
use App\Support\OwnerPrivateColumns;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Spec 258: the Days running column of the /owner/private page row, and the one column list that the page and
 * the column settings page both read (App\Support\OwnerPrivateColumns).
 */
class DaysRunningColumnTest extends OwnerPrivateTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo('2026-10-12 12:00:00');

        // Only the columns itemSummary reads (the real migrations have MySQL-only parts), as in ItemSummaryProfitMTest.
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
        Schema::create('tasks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('status')->nullable();
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
        ]);
    }

    /** One page selling $item on each of $dates (5 orders, all proceed, ₱599, ₱500 ads). */
    private function page(string $label, string $item, array $dates): void
    {
        foreach ($dates as $d) {
            DB::table('daily_page_primary_item')->insert([
                'ts_date' => $d, 'page_label' => $label, 'page_key' => strtolower($label),
                'primary_item' => $item, 'primary_item_key' => ItemAliasResolver::normalize($item),
                'primary_orders' => 5, 'total_orders_all' => 5, 'primary_mode_cod' => 599,
            ]);
            DB::table('macro_output')->insert(array_map(fn () => [
                'PAGE' => $label, 'ITEM_NAME' => $item, 'STATUS' => 'Proceed', 'COD' => '599', 'ts_date' => $d,
            ], range(1, 5)));
            DB::table('ads_manager_reports')->insert(['day' => $d, 'page_name' => $label, 'amount_spent_php' => '500']);
        }
    }

    private function dates(string $from, string $to): array
    {
        $out = [];
        for ($d = new \DateTime($from); $d->format('Y-m-d') <= $to; $d->modify('+1 day')) $out[] = $d->format('Y-m-d');
        return $out;
    }

    private function summary(string $start, string $end): array
    {
        $this->actingAs($this->user());
        $res = $this->getJson('/owner/private/item-summary?' . http_build_query(['start_date' => $start, 'end_date' => $end]))->assertOk();

        return array_column($res->json('rows'), null, 'page_key');
    }

    // ── S1: the value ───────────────────────────────────────────────────────

    public function test_days_running_counts_both_ends_from_the_primary_since_date_to_the_range_end(): void
    {
        // Same item since Oct 3; a different one before. Viewing Oct 5 – Oct 9.
        $this->page('Pahina Alpha', '1 x Zorblat Lamp', $this->dates('2026-10-03', '2026-10-09'));
        DB::table('daily_page_primary_item')->insert([
            'ts_date' => '2026-10-02', 'page_label' => 'Pahina Alpha', 'page_key' => 'pahina alpha',
            'primary_item' => '1 x Quillex Fan', 'primary_item_key' => ItemAliasResolver::normalize('1 x Quillex Fan'),
            'primary_orders' => 5, 'total_orders_all' => 5, 'primary_mode_cod' => 799,
        ]);
        // Another page that switched to its item on the last day.
        $this->page('Pahina Beta', '1 x Quillex Fan', ['2026-10-09']);

        $rows = $this->summary('2026-10-05', '2026-10-09');

        $this->assertSame('2026-10-03', $rows['pahina alpha']['anchor_first_date']);
        $this->assertSame(7, $rows['pahina alpha']['days_running']);
        $this->assertSame('2026-10-09', $rows['pahina beta']['anchor_first_date']);
        $this->assertSame(1, $rows['pahina beta']['days_running']);
    }

    public function test_a_cached_row_without_a_primary_since_date_gets_no_days_running(): void
    {
        $this->page('Pahina Alpha', '1 x Zorblat Lamp', $this->dates('2026-10-03', '2026-10-09'));
        $this->summary('2026-10-05', '2026-10-09');

        // A payload cached before the field existed, with a row that has no since date.
        $request = Request::create('http://localhost/owner/private/item-summary', 'GET', ['start_date' => '2026-10-05', 'end_date' => '2026-10-09']);
        $key = (fn () => $this->cacheKeyForItemSummary($request))->call(new OwnerPrivateController());
        $cached = Cache::get($key);
        $this->assertIsArray($cached);
        $cached['rows'][0]['anchor_first_date'] = null;
        unset($cached['rows'][0]['days_running']);
        Cache::forever($key, $cached);

        $res = $this->getJson('/owner/private/item-summary?start_date=2026-10-05&end_date=2026-10-09')->assertOk();

        $this->assertSame('hit', $res->json('_cache'));
        $this->assertArrayHasKey('days_running', $res->json('rows.0'));
        $this->assertNull($res->json('rows.0.days_running'));
    }

    /** @return array<string, array{0: ?string, 1: string, 2: string, 3: ?int}> since, end, today, days */
    public static function counts(): array
    {
        return [
            'spec example, inclusive'         => ['2026-11-03', '2026-11-09', '2026-11-10', 7],
            'range ends today'                => ['2026-11-03', '2026-11-10', '2026-11-10', 8],
            'range ends after today'          => ['2026-11-03', '2026-11-30', '2026-11-10', 8],
            'since the range end'             => ['2026-11-09', '2026-11-09', '2026-11-10', 1],
            'since before the range start'    => ['2026-09-01', '2026-10-01', '2026-11-10', 31],
            'no since date'                   => [null, '2026-11-09', '2026-11-10', null],
            'since after the range end'       => ['2026-11-12', '2026-11-09', '2026-11-12', null],
            'not a date'                      => ['soon', '2026-11-09', '2026-11-10', null],
        ];
    }

    #[DataProvider('counts')]
    public function test_days_running_rule(?string $since, string $end, string $today, ?int $days): void
    {
        $this->assertSame($days, OwnerPrivateColumns::daysRunning($since, $end, $today));
    }

    public function test_the_page_has_a_days_running_cell_that_shows_a_dash_without_a_value(): void
    {
        $this->actingAs($this->user());
        $html = $this->get('/owner/private')->assertOk()->getContent();

        $this->assertStringContainsString("{ id:'days_running', label:'Days running', sort:'days_running', align:'center', minw:80 },", $html);
        $this->assertStringContainsString("<template x-if=\"col.id==='days_running'\">", $html);
        $this->assertStringContainsString("row.days_running != null ? row.days_running : '—'", $html);
    }

    // ── S1: visibility per role ─────────────────────────────────────────────

    public function test_ceo_sees_days_running_and_other_roles_only_when_turned_on(): void
    {
        $ctl = new OwnerColumnSettingsController();
        $this->assertNotContains('days_running', $ctl->loadConfig('owner_private', 'CEO')['hidden']);
        $this->assertSame('days_running', $ctl->loadConfig('owner_private', 'CEO')['order'][0]);
        foreach (OwnerColumnSettingsController::NON_CEO_ROLES as $role) {
            $this->assertContains('days_running', $ctl->loadConfig('owner_private', $role)['hidden'], $role);
        }

        // A config saved before the column existed: CEO sees it at the end, the roles still opt in.
        $saved = ['order' => ['adspent', 'orders'], 'hidden' => [], 'visible_by_role' => ['Marketing' => ['adspent']]];
        DB::table('app_settings')->updateOrInsert(['key' => 'owner_private_cols'], ['value' => json_encode($saved), 'created_at' => now(), 'updated_at' => now()]);
        $ceo = $ctl->loadConfig('owner_private', 'CEO');
        $this->assertContains('days_running', $ceo['order']);
        $this->assertNotContains('days_running', $ceo['hidden']);
        $this->assertContains('days_running', $ctl->loadConfig('owner_private', 'Marketing')['hidden']);

        $saved['visible_by_role']['Marketing'][] = 'days_running';
        DB::table('app_settings')->where('key', 'owner_private_cols')->update(['value' => json_encode($saved)]);
        $this->assertNotContains('days_running', $ctl->loadConfig('owner_private', 'Marketing')['hidden']);
    }

    // ── S2: one column list ─────────────────────────────────────────────────

    public function test_the_settings_catalog_is_the_single_column_list(): void
    {
        $this->assertSame(OwnerPrivateColumns::COLUMNS, OwnerColumnSettingsController::CATALOG['owner_private']);
        $ids = array_column(OwnerPrivateColumns::COLUMNS, 'id');
        $this->assertSame(array_values(array_unique($ids)), $ids, 'ids are unique');
        foreach (OwnerPrivateColumns::COLUMNS as $c) {
            $this->assertNotSame('', (string) ($c['label'] ?? ''), $c['id']);
            if (empty($c['item_only'])) {
                foreach (['sort', 'align', 'minw'] as $k) $this->assertArrayHasKey($k, $c, "{$c['id']} {$k}");
            }
        }
    }

    /** The defaultCols() list in the rendered /owner/private page, as [id => label source]. */
    private function pageCols(string $role): array
    {
        $this->actingAs($this->user($role, strtolower(str_replace([' ', '-'], '', $role)) . '@example.test'));
        $html = $this->get('/owner/private')->assertOk()->getContent();
        $a = strpos($html, 'defaultCols() {');
        $b = strpos($html, 'initCols()', $a);
        $this->assertNotFalse($a);
        preg_match_all("/\{ id:'([a-z0-9_]+)', label:([^,]+), sort:'([^']*)', align:'([a-z]+)', minw:(\d+) \}/u", substr($html, $a, $b - $a), $m, PREG_SET_ORDER);

        return array_combine(array_column($m, 1), array_map(fn ($x) => [$x[2], $x[3], $x[4], (int) $x[5]], $m));
    }

    public function test_the_page_columns_equal_the_settings_catalog_in_the_same_order(): void
    {
        $catalog = array_values(array_filter(OwnerColumnSettingsController::CATALOG['owner_private'], fn ($c) => empty($c['item_only'])));
        $cols = $this->pageCols('CEO');

        $this->assertSame(array_column($catalog, 'id'), array_keys($cols));
        foreach ($catalog as $c) {
            $label = isset($c['label_fn']) ? "this.{$c['label_fn']}()" : "'" . ($c['header'] ?? $c['label']) . "'";
            $this->assertSame([$label, $c['sort'], $c['align'], $c['minw']], $cols[$c['id']], $c['id']);
        }
        $this->assertSame('this._breakevenLabel()', $cols['breakeven_cpp'][0]);
    }

    public function test_non_ceo_page_gets_the_same_list_without_the_ceo_only_columns(): void
    {
        $expected = array_column(array_filter(OwnerPrivateColumns::COLUMNS, fn ($c) => empty($c['item_only']) && empty($c['ceo_only'])), 'id');
        $this->assertSame(array_values($expected), array_keys($this->pageCols('Marketing')));
    }

    public function test_the_settings_page_lists_every_page_column(): void
    {
        $this->actingAs($this->user());
        $html = $this->get('/owner/column-settings/owner-private')->assertOk()->getContent();
        $this->assertSame(1, preg_match('/const COL_CATALOG\s*=\s*(\{.*\});/', $html, $c));
        $settingsIds = array_column(json_decode($c[1], true)['owner_private'], 'id');

        $this->assertSame(array_column(OwnerPrivateColumns::COLUMNS, 'id'), $settingsIds);
        $this->assertContains('days_running', $settingsIds);
    }

    public function test_a_new_entry_in_the_single_list_reaches_the_page_list(): void
    {
        $columns = array_merge(OwnerPrivateColumns::COLUMNS, [
            ['id' => 'new_col', 'label' => "Bago's <col>", 'header' => 'Bago', 'sort' => 'new_field', 'align' => 'right', 'minw' => 70],
        ]);

        $page = OwnerPrivateColumns::forPage(true, $columns);
        $this->assertSame(['id' => 'new_col', 'label' => 'Bago', 'sort' => 'new_field', 'align' => 'right', 'minw' => 70], end($page));
        $this->assertStringEndsWith("{ id:'new_col', label:'Bago', sort:'new_field', align:'right', minw:70 },", OwnerPrivateColumns::jsList(true, $columns));

        // Labels are escaped for a single-quoted JS string inside <script>.
        $columns[array_key_last($columns)]['header'] = "Bago's </script>";
        $this->assertStringEndsWith("label:'Bago\\'s \\x3C/script\\x3E', sort:'new_field', align:'right', minw:70 },", OwnerPrivateColumns::jsList(true, $columns));
    }
}

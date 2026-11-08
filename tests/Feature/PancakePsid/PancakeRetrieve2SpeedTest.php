<?php

namespace Tests\Feature\PancakePsid;

use App\Models\MacroOutput;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 247: Retrieve 2 (page and export) returns the same rows as before while its query can use indexes.
 * Every case runs on both endpoints, so the shared query building stays in step.
 */
class PancakeRetrieve2SpeedTest extends PancakePsidTestCase
{
    private const DAY = ['date_from' => '2026-10-20', 'date_to' => '2026-10-20'];

    private const INDEX_MIGRATION = 'database/migrations/2026_11_21_100000_add_created_at_index_to_pancake_conversations.php';

    private ?User $staff = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapPage('Page Uno', '5001');
        $this->mapPage('Page Dos', '5002');
        // sqlite has no CONVERT_TZ: this stand-in really shifts UTC to Manila, so the day boundary is tested.
        DB::connection()->getPdo()->sqliteCreateFunction('CONVERT_TZ', function ($d, $from = null, $to = null) {
            return $d === null ? null : gmdate('Y-m-d H:i:s', strtotime($d . ' UTC') + 8 * 3600);
        }, 3);
        // The controller adds NOCASE on sqlite only. Production compares with utf8mb4_unicode_ci, which ignores
        // letter case and trailing spaces (PAD SPACE) in "="; this stand-in gives NOCASE the same two rules.
        DB::connection()->getPdo()->sqliteCreateCollation('NOCASE', fn ($a, $b) => strcmp(
            mb_strtolower(rtrim((string) $a, ' ')), mb_strtolower(rtrim((string) $b, ' '))
        ));
    }

    protected function migrationPaths(): array
    {
        return is_file(base_path(self::INDEX_MIGRATION))
            ? array_merge(parent::migrationPaths(), [self::INDEX_MIGRATION])
            : parent::migrationPaths();
    }

    private function me(): User
    {
        return $this->staff ??= $this->user();
    }

    private function order(array $extra): MacroOutput
    {
        return MacroOutput::create($extra + ['PAGE' => 'Page Uno', 'ts_date' => '2026-10-20']);
    }

    /** created_at is UTC, as in production. 02:00 UTC = 10:00 Manila on the same day. */
    private function chat(string $pageId, string $name, ?string $psid = null, string $createdAtUtc = '2026-10-20 02:00:00'): void
    {
        DB::table('pancake_conversations')->insert([
            'pancake_page_id' => $pageId, 'full_name' => $name, 'psid' => $psid,
            'customers_chat' => 'chat 09171234567', 'created_at' => $createdAtUtc, 'updated_at' => $createdAtUtc,
        ]);
    }

    /** Names listed by the export and by the page for the same filters; both must agree. */
    private function listed(array $query = self::DAY): array
    {
        $export = $this->actingAs($this->me())->getJson('/pancake/retrieve2/export?' . http_build_query($query))->assertOk();
        $fromExport = collect($export->json('rows'))->pluck('full_name')->sort()->values()->all();
        $this->assertSame(count($fromExport), $export->json('count'));

        $page = $this->actingAs($this->me())->get('/pancake/retrieve2?' . http_build_query($query))->assertOk();
        $rows = $page->viewData('rows');
        $fromPage = $rows->getCollection()->pluck('full_name')->sort()->values()->all();
        $this->assertSame($fromExport, $fromPage, 'the page and the export list different rows');
        $this->assertSame(count($fromPage), $rows->total());

        return $fromExport;
    }

    public function test_a_conversation_without_a_matching_order_is_listed(): void
    {
        $this->chat('5001', 'Lia Nobody');
        $this->order(['fb_name' => 'Someone Else']);

        $this->assertSame(['Lia Nobody'], $this->listed());
    }

    public function test_an_order_with_the_same_page_and_name_in_any_letter_case_and_surrounding_spaces_hides_the_conversation(): void
    {
        $this->chat('5001', 'Ana Cruz');
        $this->chat('5001', 'Ben Reyes');
        $this->chat('5001', 'Cara Lim');
        $this->chat('5001', 'Lia Nobody');
        $this->order(['fb_name' => 'ana cruz']);
        $this->order(['fb_name' => 'BEN REYES  ']);
        // One leading space at most: production has no order name with more (amendment 247-1, accepted gap).
        $this->order(['PAGE' => 'page uno', 'fb_name' => ' Cara LIM  ']);

        $this->assertSame(['Lia Nobody'], $this->listed());
    }

    public function test_an_order_whose_fb_name_has_a_leading_space_still_hides_its_conversation(): void
    {
        $this->chat('5001', 'Dan Space');
        $this->chat('5001', 'Lia Nobody');
        $this->order(['fb_name' => ' Dan Space']);

        $this->assertSame(['Lia Nobody'], $this->listed());
    }

    public function test_a_single_leading_space_on_the_order_name_hides_the_unpadded_conversation_name_on_its_page_only(): void
    {
        $this->chat('5001', 'Mia Lopez');
        $this->chat('5002', 'Mia Lopez');
        $this->order(['fb_name' => ' mia lopez']);

        $export = $this->actingAs($this->me())->getJson('/pancake/retrieve2/export?' . http_build_query(self::DAY))->assertOk();
        $this->assertSame(['Page Dos'], collect($export->json('rows'))->pluck('page')->all());
        $this->assertSame(['Mia Lopez'], $this->listed());
    }

    public function test_a_page_name_that_differs_only_in_letter_case_or_trailing_spaces_still_matches(): void
    {
        $this->chat('5001', 'Eva Case');
        $this->chat('5002', 'Fe Trailing');
        $this->chat('5002', 'Lia Nobody');
        $this->order(['PAGE' => 'PAGE UNO', 'fb_name' => 'Eva Case']);
        $this->order(['PAGE' => 'Page Dos  ', 'fb_name' => 'Fe Trailing']);

        $this->assertSame(['Lia Nobody'], $this->listed());
    }

    public function test_a_conversation_is_never_hidden_by_an_order_from_a_different_page(): void
    {
        $this->chat('5001', 'Gia Twice');
        $this->chat('5002', 'Gia Twice');
        $this->order(['PAGE' => 'Page Dos', 'fb_name' => 'Gia Twice']);

        $export = $this->actingAs($this->me())->getJson('/pancake/retrieve2/export?' . http_build_query(self::DAY))->assertOk();
        $this->assertSame([['page' => 'Page Uno', 'full_name' => 'Gia Twice']],
            collect($export->json('rows'))->map(fn ($r) => ['page' => $r['page'], 'full_name' => $r['full_name']])->all());
        $this->assertSame(['Gia Twice'], $this->listed());
    }

    public function test_an_empty_fb_name_never_hides_a_conversation_with_an_empty_name(): void
    {
        $this->chat('5001', '');
        $this->order(['fb_name' => '']);
        $this->order(['fb_name' => '   ']);

        $this->assertSame([''], $this->listed());
    }

    public function test_the_manila_day_boundary_decides_the_range_from_the_utc_created_at(): void
    {
        $this->chat('5001', 'Before Start', null, '2026-10-19 15:59:59'); // 23:59:59 Manila on the 19th
        $this->chat('5001', 'At Start', null, '2026-10-19 16:00:00');     // 00:00 Manila on the 20th
        $this->chat('5001', 'Last Minute', null, '2026-10-20 15:59:00');  // 23:59 Manila on the 20th
        $this->chat('5001', 'Next Day', null, '2026-10-20 16:00:00');     // 00:00 Manila on the 21st

        $this->assertSame(['At Start', 'Last Minute'], $this->listed());
        $this->assertSame(['At Start', 'Last Minute', 'Next Day'],
            $this->listed(['date_from' => '2026-10-20', 'date_to' => '2026-10-21']));
    }

    public function test_the_psid_rule_still_decides_for_a_conversation_with_a_psid(): void
    {
        $this->chat('5001', 'Hana Psid', '11111111111111111');  // order found by PSID
        $this->chat('5001', 'Ivo Namesake', '22222222222222222'); // a namesake has an order, this PSID has none
        $this->order(['fb_name' => null, 'botcake_psid' => '11111111111111111']);
        $this->order(['fb_name' => 'Ivo Namesake', 'botcake_psid' => '99999999999999999']);

        $this->assertSame(['Ivo Namesake'], $this->listed());
    }

    public function test_the_page_and_name_filters_still_narrow_the_rows(): void
    {
        $this->chat('5001', 'Jo Uno');
        $this->chat('5002', 'Jo Dos');
        $this->chat('5002', 'Kai Dos');

        $this->assertSame(['Jo Dos', 'Kai Dos'], $this->listed(self::DAY + ['page_contains' => 'dos']));
        $this->assertSame(['Jo Dos', 'Jo Uno'], $this->listed(self::DAY + ['name_contains' => 'JO']));
        $this->assertSame(['Jo Dos'], $this->listed(self::DAY + ['page_contains' => 'DOS', 'name_contains' => 'jo']));
    }

    public function test_the_shop_details_of_the_day_and_page_still_come_with_the_row(): void
    {
        $this->chat('5001', 'Lia Nobody');
        $this->order(['fb_name' => 'Other One', 'SHOP DETAILS' => 'Shop A']);
        $this->order(['fb_name' => 'Other Two', 'SHOP DETAILS' => 'Shop A']);
        $this->order(['fb_name' => 'Other Three', 'SHOP DETAILS' => 'Shop B']);

        $export = $this->actingAs($this->me())->getJson('/pancake/retrieve2/export?' . http_build_query(self::DAY))->assertOk();
        $this->assertSame('Shop A', $export->json('rows.0.shop_details'));
        $this->assertSame('2026-10-20 10:00:00', $export->json('rows.0.date_created'));
    }

    public function test_the_query_filters_the_bare_created_at_and_probes_macro_output_by_page_and_name(): void
    {
        $this->chat('5001', 'Lia Nobody');

        DB::enableQueryLog();
        $this->actingAs($this->me())->getJson('/pancake/retrieve2/export?' . http_build_query(self::DAY))->assertOk();
        $sql = collect(DB::getQueryLog())->pluck('query')->first(fn ($q) => str_contains($q, 'from "pancake_conversations"'));
        $bindings = collect(DB::getQueryLog())->first(fn ($q) => str_contains($q['query'], 'from "pancake_conversations"'))['bindings'];
        DB::disableQueryLog();

        // The range is compared on the bare column, with UTC bounds worked out from the Manila dates.
        $this->assertStringContainsString('"pc"."created_at" >= ?', $sql);
        $this->assertStringContainsString('"pc"."created_at" < ?', $sql);
        $this->assertContains('2026-10-19 16:00:00', $bindings);
        $this->assertContains('2026-10-20 16:00:00', $bindings);
        $this->assertStringNotContainsString("DATE(CONVERT_TZ(pc.created_at,'+00:00','+08:00')) >= ?", $sql);
        $this->assertStringNotContainsString("DATE(CONVERT_TZ(pc.created_at,'+00:00','+08:00')) <= ?", $sql);
        // The name rule probes macro_output on the bare (PAGE, fb_name) columns instead of grouping the whole table.
        $this->assertMatchesRegularExpression('/mo\.`PAGE` = TRIM\(/', $sql);
        // The name is probed as given and with one leading space, both index lookups (amendment 247-1).
        $this->assertMatchesRegularExpression("/mo\\.`fb_name`( COLLATE NOCASE)? IN \\(TRIM\\(pc\\.full_name\\), CONCAT\\(' ', TRIM\\(pc\\.full_name\\)\\)\\)/", $sql);
        // No scan of macro_output by pattern and no grouping of it for the name rule remain.
        $this->assertDoesNotMatchRegularExpression('/\blike\b/i', $sql);
        $this->assertNotContains(' %', $bindings);
        $this->assertNotContains('% ', $bindings);
        $this->assertStringNotContainsString('group by LOWER(TRIM(`PAGE`)), LOWER(TRIM(`fb_name`))', $sql);
    }

    public function test_the_migration_adds_the_created_at_index_once_and_down_removes_it(): void
    {
        $this->assertFileExists(base_path(self::INDEX_MIGRATION));
        $this->assertTrue(Schema::hasIndex('pancake_conversations', 'pancake_conversations_created_at_idx'));

        $migration = require base_path(self::INDEX_MIGRATION);
        $migration->up(); // already there: skips instead of failing
        $this->assertTrue(Schema::hasIndex('pancake_conversations', 'pancake_conversations_created_at_idx'));

        $migration->down();
        $this->assertFalse(Schema::hasIndex('pancake_conversations', 'pancake_conversations_created_at_idx'));
        $migration->down(); // already gone: no error

        $migration->up();
        $this->assertTrue(Schema::hasIndex('pancake_conversations', 'pancake_conversations_created_at_idx'));
    }
}

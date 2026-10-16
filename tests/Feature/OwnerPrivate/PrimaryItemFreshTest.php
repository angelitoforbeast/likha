<?php

namespace Tests\Feature\OwnerPrivate;

use App\Jobs\ImportLikhaFromGoogleSheet;
use App\Services\DailyPrimaryItemService;
use App\Services\ItemAliasResolver;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;

/**
 * Ang main item ng bawat page-day (daily_page_primary_item) ay laging bago, at ang tie ay
 * hindi na nagtatanggal ng araw: may tie rule at naka-flag na is_tie.
 */
class PrimaryItemFreshTest extends MacroOutputTestCase
{
    private const ALPHA = '1 x Alpha Lamp';
    private const BETA  = '1 x Beta Fan';

    /** $n na rows ng $item sa $date; ang unang $proceed ay Proceed. */
    private function orders(string $date, string $item, int $n, int $proceed, int $cod = 599): void
    {
        DB::table('macro_output')->insert(array_map(fn ($i) => [
            'PAGE' => 'Pahina Alpha', 'ITEM_NAME' => $item, 'STATUS' => $i < $proceed ? 'Proceed' : 'Pending',
            'COD' => (string) $cod, 'ts_date' => $date, 'TIMESTAMP' => '10:00 ' . date('d-m-Y', strtotime($date)),
        ], range(0, $n - 1)));
    }

    private function stored(string $date): ?object
    {
        return DB::table('daily_page_primary_item')->where('ts_date', $date)->where('page_key', 'pahina alpha')->first();
    }

    /** @return array<string, array{0: array, 1: ?string, 2: string}> [alpha, beta] = [n, proceed, cod]; main kahapon; panalo */
    public static function ties(): array
    {
        return [
            'more proceed wins'       => [[[3, 1, 599], [3, 2, 599]], null, self::BETA],
            'previous day main wins'  => [[[3, 1, 599], [3, 1, 599]], self::BETA, self::BETA],
            'higher mode COD wins'    => [[[3, 1, 599], [3, 1, 799]], null, self::BETA],
            'then key alphabetically' => [[[3, 1, 599], [3, 1, 599]], null, self::ALPHA],
        ];
    }

    #[DataProvider('ties')]
    public function test_a_tie_keeps_the_day_and_picks_by_the_tie_rule(array $counts, ?string $yesterday, string $winner): void
    {
        [[$an, $ap, $ac], [$bn, $bp, $bc]] = $counts;
        $this->orders(self::DAY, self::ALPHA, $an, $ap, $ac);
        $this->orders(self::DAY, self::BETA, $bn, $bp, $bc);
        if ($yesterday !== null) {
            DB::table('daily_page_primary_item')->insert([
                'ts_date' => '2026-10-08', 'page_label' => 'Pahina Alpha', 'page_key' => 'pahina alpha',
                'primary_item' => $yesterday, 'primary_item_key' => ItemAliasResolver::normalize($yesterday),
                'primary_orders' => 5, 'total_orders_all' => 5, 'primary_mode_cod' => 599,
            ]);
        }

        $summary = app(DailyPrimaryItemService::class)->recomputeDates([self::DAY]);

        $row = $this->stored(self::DAY);
        $this->assertNotNull($row);
        $this->assertSame(ItemAliasResolver::normalize($winner), $row->primary_item_key);
        $this->assertSame(3, (int) $row->primary_orders);
        $this->assertSame(1, (int) $row->is_tie);
        $this->assertSame(1, $summary['ties_resolved']);
    }

    public function test_a_clear_winner_is_not_flagged_as_a_tie(): void
    {
        $this->orders(self::DAY, self::ALPHA, 4, 1);
        $this->orders(self::DAY, self::BETA, 3, 3);

        app(DailyPrimaryItemService::class)->recomputeDates([self::DAY]);

        $row = $this->stored(self::DAY);
        $this->assertSame(ItemAliasResolver::normalize(self::ALPHA), $row->primary_item_key);
        $this->assertSame(0, (int) $row->is_tie);
    }


    public function test_a_tie_day_keeps_its_orders_and_ad_spend_in_the_item_summary_totals(): void
    {
        // 2026-10-08: tie 3–3, Alpha may mas maraming proceed → Alpha. 2026-10-09: Alpha 5.
        $this->orders('2026-10-08', self::ALPHA, 3, 2);
        $this->orders('2026-10-08', self::BETA, 3, 1);
        $this->orders(self::DAY, self::ALPHA, 5, 5);
        DB::table('ads_manager_reports')->insert([
            ['day' => '2026-10-08', 'page_name' => 'Pahina Alpha', 'amount_spent_php' => '100'],
            ['day' => self::DAY, 'page_name' => 'Pahina Alpha', 'amount_spent_php' => '200'],
        ]);
        app(DailyPrimaryItemService::class)->recomputeRange('2026-10-08', self::DAY);
        $this->actingAs($this->user());

        $res = $this->getJson('/owner/private/item-summary?start_date=2026-10-08&end_date=' . self::DAY)->assertOk();
        $a = array_column($res->json('rows'), null, 'page_key')['pahina alpha'];

        $this->assertSame(8, $a['orders']);
        $this->assertSame(7, $a['proceed_orders']);
        $this->assertEqualsWithDelta(300, $a['adspent'], 0.001);
        $this->assertFalse($a['item_is_tie']);

        // Ang breakdown ng page ay may "tie" mark sa araw ng tie lang.
        $days = $this->getJson('/owner/private/page-range-breakdown?page_key=pahina+alpha&start_date=2026-10-08&end_date=' . self::DAY)
            ->assertOk()->json('rows');
        $this->assertSame([true, false], array_column($days, 'is_tie'));
    }

    public function test_likha_import_recomputes_the_main_item_of_its_dates(): void
    {
        // Gaya ng DB trigger sa MySQL/pgsql: ts_date = huling 10 character ng TIMESTAMP (d-m-Y).
        DB::unprepared("CREATE TRIGGER mo_ts_date AFTER INSERT ON macro_output BEGIN
            UPDATE macro_output SET ts_date = substr(NEW.TIMESTAMP, -4) || '-' || substr(NEW.TIMESTAMP, -7, 2) || '-' || substr(NEW.TIMESTAMP, -10, 2)
            WHERE id = NEW.id; END");
        $job = new ImportLikhaFromGoogleSheet(1);
        $import = new ReflectionMethod($job, 'importRow');
        foreach (['Ana', 'Ben'] as $fb) {
            $import->invoke($job, ['10:00 09-10-2026', 'Pahina Alpha', $fb, '09170000000', 'hi', "ITEM: Alpha Lamp\nQUANTITY: 1\nPRICE: ₱599", '']);
        }

        (new ReflectionMethod($job, 'afterImport'))->invoke($job);

        $row = $this->stored(self::DAY);
        $this->assertSame(ItemAliasResolver::normalize(self::ALPHA), $row?->primary_item_key);
        $this->assertSame(2, (int) $row->primary_orders);
    }

    public function test_an_inline_item_edit_recomputes_the_main_item_of_that_day(): void
    {
        $this->orders(self::DAY, self::ALPHA, 1, 0);
        app(DailyPrimaryItemService::class)->recomputeDates([self::DAY]);
        $this->actingAs($this->user());

        $id = DB::table('macro_output')->value('id');
        $this->post('/encoder/checker_1/update-field', ['id' => $id, 'field' => 'ITEM_NAME', 'value' => self::BETA]);

        $this->assertSame(ItemAliasResolver::normalize(self::BETA), $this->stored(self::DAY)?->primary_item_key);
    }

    public function test_saving_an_item_alias_recomputes_the_days_of_that_item(): void
    {
        $this->travelTo('2026-10-10 12:00:00');
        $this->orders(self::DAY, self::ALPHA, 2, 0);
        $this->orders(self::DAY, self::BETA, 2, 0);
        $this->orders(self::DAY, '1 x Gamma Fan', 3, 0);
        app(DailyPrimaryItemService::class)->recomputeDates([self::DAY]);
        $this->actingAs($this->user());

        $this->postJson('/jnt/item-types/save-one', ['item_name' => self::BETA, 'item_type' => self::ALPHA])->assertOk();

        $row = $this->stored(self::DAY);
        $this->assertSame(ItemAliasResolver::normalize(self::ALPHA), $row?->primary_item_key);
        $this->assertSame(4, (int) $row->primary_orders);
    }

    public function test_the_last_14_days_are_recomputed_nightly_at_0350_manila(): void
    {
        $this->app->forgetInstance(Schedule::class);
        ScheduleFacade::clearResolvedInstance(Schedule::class);
        require base_path('routes/console.php');

        $events = array_values(array_filter(
            $this->app->make(Schedule::class)->events(),
            fn ($e) => str_contains((string) $e->command, 'supply:refresh-primary-items'),
        ));

        $this->assertCount(1, $events);
        $this->assertStringContainsString('--last=14', $events[0]->command);
        $this->assertSame('50 3 * * *', $events[0]->expression);
        $this->assertSame('Asia/Manila', (string) $events[0]->timezone);
        $this->assertTrue($events[0]->withoutOverlapping);
    }
}

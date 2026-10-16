<?php

namespace Tests\Feature\PageSettings;

use App\Http\Controllers\PsidCoverageController;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Botcake\BotcakeApiTestCase;

/** /page-settings/psid-coverage: ilang order ang may PSID kada page, at ang mga wala (paginated). CEO lang. */
class PsidCoverageTest extends BotcakeApiTestCase
{
    private function miss(int $orderId, string $reason, string $page = 'page uno'): void
    {
        $o = DB::table('macro_output')->where('id', $orderId)->first();
        DB::table('botcake_psid_misses')->insert(['order_id' => $orderId, 'page_key' => $page, 'ts_date' => $o->ts_date, 'reason' => $reason, 'checked_at' => now()]);
    }

    private function day(int $daysAgo): string
    {
        return now('Asia/Manila')->subDays($daysAgo)->toDateString();
    }

    public function test_the_summary_counts_each_page_in_one_grouped_query(): void
    {
        $this->actingAs($this->user());
        $this->order('Page Uno', 'A', null, 'psid-1', $this->day(1));
        $this->order(' page uno', 'B', null, 'psid-2', $this->day(2));
        $amb = $this->order('Page Uno', 'C', null, null, $this->day(3));
        $nom = $this->order('Page Uno', 'D', null, null, $this->day(3));
        $this->order('Page Uno', 'E', null, null, $this->day(4)); // hindi pa nasusuri
        $this->order('Page Uno', 'F', null, null, $this->day(9)); // labas sa default na 7 araw
        $this->order('Page Dos', 'G', null, null, $this->day(1));
        $this->miss($amb->id, 'ambiguous');
        $this->miss($nom->id, 'no_match');
        $this->miss($this->order('Page Uno', 'H', null, 'psid-late', $this->day(1))->id, 'no_match'); // may PSID na: hindi na binibilang

        $queries = 0;
        DB::listen(function ($q) use (&$queries) { if (str_contains($q->sql, 'macro_output')) $queries++; });
        $rows = collect($this->get('/page-settings/psid-coverage')->assertOk()->viewData('summary'))->keyBy('page_key');

        $this->assertSame(1, $queries, 'isang grouped query para sa lahat ng page');
        $this->assertSame(
            ['orders' => 6, 'with_psid' => 3, 'without_psid' => 3, 'ambiguous' => 1, 'no_match' => 1, 'not_synced' => 1, 'pct' => 50.0],
            array_intersect_key($rows['page uno'], array_flip(['orders', 'with_psid', 'without_psid', 'ambiguous', 'no_match', 'not_synced', 'pct']))
        );
        $this->assertSame(1, $rows['page dos']['not_synced']);
    }

    public function test_the_range_is_at_most_31_days(): void
    {
        $this->actingAs($this->user());

        $res = $this->get('/page-settings/psid-coverage?from=2026-01-01&to=2026-10-17')->assertOk();

        $this->assertSame(['2026-09-17', '2026-10-17'], [$res->viewData('from'), $res->viewData('to')]);
    }

    public function test_a_page_lists_its_orders_without_psid_50_at_a_time_newest_first_with_filters(): void
    {
        $this->actingAs($this->user());
        for ($i = 0; $i < 55; $i++) {
            $o = $this->order('Page Uno', 'Cust ' . $i, null, null, $this->day($i % 5));
            $this->miss($o->id, $i % 2 ? 'ambiguous' : 'no_match');
        }
        $this->order('Page Uno', 'Has Psid', null, 'psid-x', $this->day(0));
        $transit = $this->order('Page Uno', 'In Transit Cust', null, null, $this->day(0));
        DB::table('macro_output')->where('id', $transit->id)->update(['waybill' => 'WB-1']);
        DB::table('from_jnts')->insert(['waybill_number' => 'WB-1', 'status' => 'In Transit']);

        $page = $this->get('/page-settings/psid-coverage/orders?page_name=Page%20Uno')->assertOk()->viewData('orders');
        $this->assertSame(56, $page->total());
        $this->assertCount(50, $page->items());
        $dates = array_map(fn ($o) => (string) $o->ts_date, $page->items());
        $sorted = $dates; rsort($sorted);
        $this->assertSame($sorted, $dates, 'pinakabago muna');
        $this->assertNotContains('Has Psid', array_map(fn ($o) => $o->fb_name, $page->items()));

        $this->assertSame(28, $this->get('/page-settings/psid-coverage/orders?page_name=Page%20Uno&reason=no_match')->viewData('orders')->total());
        $this->assertSame(1, $this->get('/page-settings/psid-coverage/orders?page_name=Page%20Uno&reason=not_synced')->viewData('orders')->total());
        $transitPage = $this->get('/page-settings/psid-coverage/orders?page_name=Page%20Uno&jnt=In%20Transit')->viewData('orders');
        $this->assertSame(['In Transit Cust'], array_map(fn ($o) => $o->fb_name, $transitPage->items()));
        $this->assertSame(0, $this->get('/page-settings/psid-coverage/orders?page_name=Page%20Uno&jnt=Delivering')->viewData('orders')->total());
    }

    public function test_only_the_ceo_reaches_it_and_page_settings_links_to_it(): void
    {
        $this->actingAs($this->user('Marketing', 'mkt@example.test'));
        $this->get('/page-settings/psid-coverage')->assertNotFound();
        $this->get('/page-settings/psid-coverage/orders?page_name=Page%20Uno')->assertNotFound();

        $this->actingAs($this->user());
        $this->get('/page-settings')->assertSee('/page-settings/psid-coverage', false);
    }

    public function test_the_queries_use_the_ts_date_page_index_and_the_misses_order_index(): void
    {
        $c = app(PsidCoverageController::class);
        $plans = [];
        foreach ([$c->summaryQuery('2026-10-01', '2026-10-17'), $c->ordersQuery('page uno', '2026-10-01', '2026-10-17', 'all', 'all')] as $q) {
            $plans[] = implode(' | ', array_map(fn ($r) => $r->detail, DB::select('EXPLAIN QUERY PLAN ' . $q->toSql(), $q->getBindings())));
        }

        foreach ($plans as $plan) {
            $this->assertStringContainsString('macro_output_ts_date_page_idx', $plan);
            $this->assertStringContainsString('botcake_psid_misses_order_id_unique', $plan);
            $this->assertStringNotContainsString('SCAN macro_output', $plan);
        }
    }
}

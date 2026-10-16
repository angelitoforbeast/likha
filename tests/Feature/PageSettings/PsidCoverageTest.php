<?php

namespace Tests\Feature\PageSettings;

use App\Http\Controllers\PsidCoverageController;
use App\Models\BotcakePsid;
use App\Services\PageSettings;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Botcake\BotcakeApiTestCase;

/**
 * /page-settings/psid-coverage: ilang order ang may PSID kada page, at ang mga wala (paginated) na may dahilang
 * kinukwenta on demand mula sa available na Botcake customers ng page. CEO lang.
 */
class PsidCoverageTest extends BotcakeApiTestCase
{
    private function customer2(string $psid, string $name, array $phones = [], string $page = 'Page Uno'): void
    {
        BotcakePsid::create(['page' => $page, 'fb_name' => $name, 'psid' => $psid, 'status' => 'available', 'source' => 'api', 'phone_numbers' => $phones]);
    }

    private function day(int $daysAgo): string
    {
        return now('Asia/Manila')->subDays($daysAgo)->toDateString();
    }

    private function list(string $query)
    {
        return $this->get('/page-settings/psid-coverage/orders?page_name=Page%20Uno' . $query)->assertOk();
    }

    public function test_the_summary_counts_each_page_in_one_grouped_query(): void
    {
        $this->actingAs($this->user());
        $this->order('Page Uno', 'A', null, 'psid-1', $this->day(1));
        $this->order(' page uno', 'B', null, 'psid-2', $this->day(2));
        $this->order('Page Uno', 'C', null, null, $this->day(3));
        $this->order('Page Uno', 'D', null, '', $this->day(4));
        $this->order('Page Uno', 'F', null, null, $this->day(9)); // labas sa default na 7 araw
        $this->order('Page Dos', 'G', null, null, $this->day(1));

        $queries = 0;
        DB::listen(function ($q) use (&$queries) { if (str_contains($q->sql, 'macro_output')) $queries++; });
        $rows = collect($this->get('/page-settings/psid-coverage')->assertOk()->viewData('summary'))->keyBy('page_key');

        $this->assertSame(1, $queries, 'isang grouped query para sa lahat ng page');
        $this->assertSame(['orders' => 4, 'with_psid' => 2, 'without_psid' => 2, 'pct' => 50.0],
            array_intersect_key($rows['page uno'], array_flip(['orders', 'with_psid', 'without_psid', 'pct'])));
        $this->assertSame(1, $rows['page dos']['without_psid']);
    }

    public function test_the_range_is_at_most_31_days(): void
    {
        $this->actingAs($this->user());

        $res = $this->get('/page-settings/psid-coverage?from=2026-01-01&to=2026-10-17')->assertOk();

        $this->assertSame(['2026-09-17', '2026-10-17'], [$res->viewData('from'), $res->viewData('to')]);
    }

    public function test_the_reason_of_each_order_without_psid_is_computed_from_the_pages_customers(): void
    {
        $this->actingAs($this->user());
        app(PageSettings::class)->set('Page Uno', 'botcake', 'page_id', '1001'); // ang pangalan ng page sa settings
        $this->customer2('b1', 'Ben Reyes');
        $this->customer2('b2', 'ben  reyes');
        $this->customer2('d1', 'Dino Lim');
        $this->customer2('x1', 'Xia One', ['09175550000']);
        $this->customer2('x2', 'Xia Two', ['+63 917 555 0000']);
        $this->customer2('e1', 'Eva Tan', ['09188880000']);
        $this->customer2('o1', 'Ben Reyes', [], 'Other Page'); // ibang page: hindi binibilang
        BotcakePsid::where('psid', 'd1')->update(['status' => 'inactive']); // inactive: hindi kandidato

        $ids = [
            'ambiguous name'       => $this->order('page uno', ' Ben Reyes')->id,
            'ambiguous phone'      => $this->order('Page Uno', 'Unknown', '0917-555-0000')->id,
            'no match'             => $this->order('Page Uno', 'Zed Ko')->id,
            'inactive = no match'  => $this->order('Page Uno', 'Dino Lim')->id,
            'matchable by phone'   => $this->order('Page Uno', 'Eva T.', '09188880000')->id,
            'name decides by phone'=> $this->order('Page Uno', 'Ben Reyes', '09175550000')->id, // ambiguous pa rin: wala sa kanila ang phone
        ];

        $res = $this->list('');
        $this->assertSame(['ambiguous' => 3, 'no_match' => 2, 'not_synced' => 1], $res->viewData('split'));
        $reasons = collect($res->viewData('orders')->items())->pluck('reason', 'id')->all();
        $this->assertSame('ambiguous', $reasons[$ids['ambiguous name']]);
        $this->assertSame('ambiguous', $reasons[$ids['ambiguous phone']]);
        $this->assertSame('no_match', $reasons[$ids['no match']]);
        $this->assertSame('no_match', $reasons[$ids['inactive = no match']]);
        $this->assertSame('not_synced', $reasons[$ids['matchable by phone']]);
        $this->assertSame('ambiguous', $reasons[$ids['name decides by phone']]);

        $this->assertSame(2, $this->list('&reason=no_match')->viewData('orders')->total());
        $this->assertSame([$ids['matchable by phone']], collect($this->list('&reason=not_synced')->viewData('orders')->items())->pluck('id')->all());
    }

    public function test_a_page_without_botcake_customers_shows_everything_as_not_yet_checked(): void
    {
        $this->actingAs($this->user());
        $this->order('Page Uno', 'Zed Ko');

        $this->assertSame(['ambiguous' => 0, 'no_match' => 0, 'not_synced' => 1], $this->list('')->viewData('split'));
    }

    public function test_a_page_lists_its_orders_without_psid_50_at_a_time_newest_first_with_the_jnt_filter(): void
    {
        $this->actingAs($this->user());
        for ($i = 0; $i < 55; $i++) {
            $this->order('Page Uno', 'Cust ' . $i, null, null, $this->day($i % 5));
        }
        $this->order('Page Uno', 'Has Psid', null, 'psid-x', $this->day(0));
        $transit = $this->order('Page Uno', 'In Transit Cust', null, null, $this->day(0));
        DB::table('macro_output')->where('id', $transit->id)->update(['waybill' => 'WB-1']);
        DB::table('from_jnts')->insert(['waybill_number' => 'WB-1', 'status' => 'In Transit']);

        $page = $this->list('')->viewData('orders');
        $this->assertSame(56, $page->total());
        $this->assertCount(50, $page->items());
        $dates = array_map(fn ($o) => (string) $o->ts_date, $page->items());
        $sorted = $dates; rsort($sorted);
        $this->assertSame($sorted, $dates, 'pinakabago muna');
        $this->assertNotContains('Has Psid', array_map(fn ($o) => $o->fb_name, $page->items()));

        $this->assertSame(['In Transit Cust'], array_map(fn ($o) => $o->fb_name, $this->list('&jnt=In%20Transit')->viewData('orders')->items()));
        $this->assertSame(0, $this->list('&jnt=Delivering')->viewData('orders')->total());
    }

    public function test_only_the_ceo_reaches_it_and_page_settings_links_to_it(): void
    {
        $this->actingAs($this->user('Marketing', 'mkt@example.test'));
        $this->get('/page-settings/psid-coverage')->assertNotFound();
        $this->get('/page-settings/psid-coverage/orders?page_name=Page%20Uno')->assertNotFound();

        $this->actingAs($this->user());
        $this->get('/page-settings')->assertSee('/page-settings/psid-coverage', false);
    }

    public function test_the_queries_use_the_ts_date_page_index_and_the_customer_key_indexes(): void
    {
        $c = app(PsidCoverageController::class);
        $summary = $c->summaryQuery('2026-10-01', '2026-10-17');
        $orders  = $c->ordersQuery('page uno', 'Page Uno', '2026-10-01', '2026-10-17', 'all', 'all');
        $plan = fn ($q) => implode(' | ', array_map(fn ($r) => $r->detail, DB::select('EXPLAIN QUERY PLAN ' . $q->toSql(), $q->getBindings())));

        $p1 = $plan($summary);
        $this->assertStringContainsString('macro_output_ts_date_page_idx', $p1);
        $this->assertStringNotContainsString('SCAN mo', $p1);

        $p2 = $plan($orders);
        $this->assertStringContainsString('macro_output_ts_date_page_idx', $p2);
        $this->assertStringContainsString('botcake_psids_source_page_status_name_key_psid_index', $p2);
        $this->assertStringContainsString('botcake_psids_source_page_status_phone_key_psid_index', $p2);
        $this->assertStringNotContainsString('SCAN mo', $p2);
        $this->assertStringNotContainsString('SCAN b', $p2);
    }
}

<?php

namespace Tests\Feature\PageSettings;

use App\Models\BotcakePsid;
use App\Services\PageSettings;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Botcake\BotcakeApiTestCase;

/** /page-settings/customers: ang Botcake customers (API) ng isang page, read-only, 50 bawat page. */
class BotcakeCustomersViewTest extends BotcakeApiTestCase
{
    private const TOKEN = 'tok-cust-view-3m9p';

    protected function setUp(): void
    {
        parent::setUp();

        $s = app(PageSettings::class);
        $s->set('Page Uno', 'botcake', 'page_id', '1001');
        $s->set('Page Uno', 'botcake', 'token', self::TOKEN);
        $s->set('Page Uno', 'sms', 'sender', 'UNO-SENDER-VALUE');
        $this->actingAs($this->user());
    }

    private function customerRow(string $psid, string $name, array $extra = []): BotcakePsid
    {
        return BotcakePsid::create($extra + [
            'page' => 'Page Uno', 'fb_name' => $name, 'psid' => $psid, 'status' => 'available', 'source' => 'api',
        ]);
    }

    private function list(string $query = '')
    {
        return $this->get('/page-settings/customers?page=page%20uno' . $query)->assertOk();
    }

    private function psids($res): array
    {
        return collect($res->viewData('customers')->items())->pluck('psid')->all();
    }

    public function test_the_page_lists_its_api_customers_newest_interaction_first_50_at_a_time(): void
    {
        for ($i = 1; $i <= 51; $i++) {
            $this->customerRow('p' . $i, 'Name ' . $i, ['last_interactive_at' => now()->subMinutes($i)]);
        }
        $this->customerRow('never', 'Never Talked');
        $this->customerRow('other', 'Other Page', ['page' => 'Page Dos', 'last_interactive_at' => now()]);
        $this->customerRow('sheet', 'Sheet Row', ['source' => 'sheet', 'last_interactive_at' => now()]);

        $first = $this->list();
        $this->assertSame(array_map(fn ($i) => 'p' . $i, range(1, 50)), $this->psids($first));
        $this->assertSame(52, $first->viewData('customers')->total());

        $this->assertSame(['p51', 'never'], $this->psids($this->list('&p=2')), 'walang last interaction = huli');
        $this->assertStringContainsString('/page-settings/customers?page=page%20uno&amp;p=2', $first->getContent());
    }

    public function test_search_by_name_or_phone_and_filter_by_status_and_ad(): void
    {
        $this->customerRow('ana', 'Ana  Cruz', ['phone_numbers' => ['+63 917 111 2222'], 'ad_id' => '120250000000000001', 'ad_ids' => ['120250000000000001']]);
        $this->customerRow('anabel', 'Anabel Reyes', ['status' => 'inactive']);
        $this->customerRow('ben', 'Ben Lim', ['phone_numbers' => ['09181112222']]);

        $cases = [
            '&q=ana'                 => ['ana', 'anabel'],
            '&q=' . urlencode('ana cruz') => ['ana'],
            '&q=09171112222'         => ['ana'],
            '&q=' . urlencode('+639181112222') => ['ben'],
            '&status=inactive'       => ['anabel'],
            '&status=available'      => ['ana', 'ben'],
            '&has_ad=1'              => ['ana'], // lumang link
            '&ad=with'               => ['ana'],
            '&ad=without'            => ['anabel', 'ben'],
            '&ad=without&status=available' => ['ben'],
            '&ad=zzz'                => ['ana', 'anabel', 'ben'],
            '&q=ana&status=available' => ['ana'],
            '&q=zzz'                 => [],
        ];
        foreach ($cases as $query => $expected) {
            $got = $this->psids($this->list($query));
            sort($got);
            $this->assertSame($expected, $got, $query);
        }
    }

    public function test_each_customer_shows_its_ad_ids_and_its_orders_with_one_grouped_count(): void
    {
        $this->customerRow('ana', 'Ana Cruz', [
            'ad_id' => '120250000000000003', 'ad_ids' => ['120250000000000001', '120250000000000002', '120250000000000003'],
            'phone_numbers' => ['09171112222'], 'subscribed_at' => '2026-10-01 10:00:00', 'last_seen_at' => now(),
        ]);
        $this->customerRow('ben', 'Ben Lim');
        $this->order('Page Uno', 'Ana Cruz', null, 'ana');
        $this->order(' page uno ', 'Ana Cruz', null, 'ana');
        $this->order('Page Dos', 'Ana Cruz', null, 'ana'); // ibang page: hindi kasama

        $res = $this->list();
        $counts = $res->viewData('orderCounts');
        $this->assertSame(2, $counts['ana']);
        $this->assertArrayNotHasKey('ben', $counts);

        $html = $res->getContent();
        $this->assertStringContainsString('120250000000000003', $html);
        $this->assertStringContainsString('+2', $html);
        $this->assertStringContainsString('title="120250000000000001, 120250000000000002, 120250000000000003"', $html);
        $this->assertStringContainsString('09171112222', $html);
        $this->assertStringContainsString('2026-10-01', $html);
        foreach (['tok-cust', self::TOKEN, 'UNO-SENDER-VALUE'] as $secret) {
            $this->assertStringNotContainsString($secret, $html);
        }
    }

    public function test_the_query_count_is_the_same_for_5_and_50_customers(): void
    {
        $count = function (int $n): int {
            for ($i = BotcakePsid::count(); $i < $n; $i++) {
                $this->customerRow('p' . $i, 'Name ' . $i, ['last_interactive_at' => now()->subMinutes($i)]);
                $this->order('Page Uno', 'Name ' . $i, null, 'p' . $i);
            }
            $this->list(); // unang request: auth at profile
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->list();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();
            return $n;
        };

        $five = $count(5);
        $this->assertSame($five, $count(50));
        $this->assertLessThanOrEqual(6, $five);
    }

    public function test_only_the_ceo_reaches_it_and_the_grid_links_to_it(): void
    {
        $this->assertStringContainsString(route('page-settings.customers'), $this->get('/page-settings')->getContent());

        $this->actingAs($this->user('Marketing', 'mkt@example.test'));
        $this->get('/page-settings/customers?page=Page%20Uno')->assertNotFound();

        $this->actingAs($this->user('CEO', 'ceo2@example.test'));
        $this->get('/page-settings/customers?page[]=x&q[]=y')->assertOk();
    }
}

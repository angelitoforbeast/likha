<?php

namespace Tests\Feature\PageSettings;

use App\Services\PageSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Botcake\BotcakeApiTestCase;

/** Ang rows ng /page-settings: mga page na may order sa huling N araw (pinakamarami muna), saka ang may settings lang. */
class PageGridOrdersTest extends BotcakeApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->user());
        $this->travelTo('2026-10-17 10:00:00');
    }

    private function grid(): array
    {
        return $this->get('/page-settings')->assertOk()->viewData('grid');
    }

    private function orders(string $page, int $n, int $daysAgo = 0): void
    {
        for ($i = 0; $i < $n; $i++) {
            $this->order($page, 'Buyer ' . $i, null, null, now('Asia/Manila')->subDays($daysAgo)->toDateString());
        }
    }

    public function test_pages_with_orders_come_first_by_count_then_pages_with_settings_only_by_name(): void
    {
        $s = app(PageSettings::class);
        $this->orders('Page Big', 3);
        $this->orders(' page   BIG ', 1);          // parehong page: case at espasyo lang ang iba
        $this->orders('Page Tie', 2, 30);           // eksaktong 30 araw: kasama pa
        $this->orders('Page Mid', 2);
        $s->set('PAGE MID', 'botcake', 'page_id', '2002');
        $this->orders('Page Old', 5, 31);           // lampas sa 30 araw: hindi binibilang
        $s->set('Page Old', 'botcake', 'page_id', '3003');
        $s->set('Alpha Settings', 'sms', 'sender', 'ALPHA');
        $this->orders('   ', 4);                    // blangkong PAGE: hindi page
        $this->orders('', 1);
        \App\Models\MacroOutput::create(['PAGE' => null, 'ts_date' => now()->toDateString()]);

        $grid = $this->grid();

        $this->assertSame(['Page Big', 'PAGE MID', 'Page Tie', 'Alpha Settings', 'Page Old'], array_column($grid['rows'], 'name'));
        $this->assertSame([4, 2, 2, 0, 0], array_column(array_column($grid['rows'], 'info'), 'orders'));
        $this->assertSame(30, $grid['order_days']);

        $big = $grid['rows'][0];
        $this->assertSame('page big', $big['key']);
        $this->assertSame([], $big['cells'], 'walang settings: walang laman na mga cell, gaya ng "+ Page"');
        $this->assertFalse($big['info']['botcake']);

        $this->assertStringContainsString('Orders (30 days)', $this->get('/page-settings')->getContent());
    }

    public function test_the_window_follows_the_config(): void
    {
        config(['services.botcake.grid_order_days' => 45]);
        $this->orders('Page Old', 5, 40);
        $this->orders('Page New', 1);

        $grid = $this->grid();

        $this->assertSame(['Page Old', 'Page New'], array_column($grid['rows'], 'name'));
        $this->assertSame(45, $grid['order_days']);
    }

    public function test_the_order_counts_are_one_query_cached_for_10_minutes_and_never_run_by_the_poll(): void
    {
        $this->orders('Page Big', 2);
        $this->get('/page-settings')->assertOk(); // auth at profile; pinupuno rin ang cache
        Cache::flush();

        $macro = function (string $url): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get($url)->assertOk();
            $n = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'macro_output'))->count();
            DB::disableQueryLog();
            return $n;
        };

        $this->assertSame(1, $macro('/page-settings/grid'));
        $this->orders('Page Big', 3);
        $this->assertSame(0, $macro('/page-settings'), 'mula sa cache');
        $this->assertSame(2, $this->grid()['rows'][0]['info']['orders'], 'ang cache ay 10 minuto');
        $this->assertSame(0, $macro('/page-settings/grid?since=' . urlencode('2026-10-17 09:00:00')));

        $this->travel(11)->minutes();
        $this->assertSame(5, $this->grid()['rows'][0]['info']['orders']);
    }
}

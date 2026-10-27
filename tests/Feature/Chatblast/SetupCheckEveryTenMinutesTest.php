<?php

namespace Tests\Feature\Chatblast;

use App\Models\PageSetting;
use App\Services\ChatblastReady;
use App\Services\PageSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Spec 171: ang setup check kada 10 minuto (06:00–23:00 Manila, hindi sa 08:45–10:30) para sa mga page na pinapadalhan
 * ng chatblast lang, walang bagong row kapag walang nagbago. Fake na Botcake lang.
 */
class SetupCheckEveryTenMinutesTest extends ChatblastTestCase
{
    private array $flows = [];
    private array $fields = [];
    private int $flowsHttp = 200;
    private array $paths = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->flows = [['id' => 9100, 'name' => 'UPDATE SHIPPING STATUS 2026', 'is_removed' => false], ['id' => 5501, 'name' => 'OLD FLOW', 'is_removed' => false]];
        $this->fields = [['id' => 9200, 'name' => 'message'], ['id' => 7701, 'name' => 'GPT INPUT']];
        Http::fake(['botcake.io/*' => function (Request $r) {
            $path = parse_url($r->url(), PHP_URL_PATH);
            $this->paths[] = $path;
            if (str_ends_with($path, '/statistics')) return Http::response(['success' => true, 'data' => ['sent' => 0, 'delivery' => 0]]);
            if (str_ends_with($path, '/flows')) return Http::response(['success' => true, 'data' => ['flows' => $this->flows, 'folders' => []]], $this->flowsHttp);
            if (str_ends_with($path, '/custom_fields')) return Http::response(['success' => true, 'data' => $this->fields]);
            if (str_ends_with($path, '/message_templates')) return Http::response(['success' => true, 'data' => [
                ['name' => 'post_order', 'status' => 'APPROVED', 'category' => 'UTILITY', 'components' => [['type' => 'BODY', 'text' => 'Update: {{1}}']]]]]);
            return Http::response(['success' => false], 404);
        }]);
    }

    private function events(): array
    {
        // Bagong schedule ayon sa orasan ngayon (na-load na ito ng migrate sa setUp, sa ibang oras).
        $this->app->forgetInstance(Schedule::class);
        \Illuminate\Support\Facades\Facade::clearResolvedInstance(Schedule::class);
        require base_path('routes/console.php');

        return collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) $e->command, 'botcake:setup-check'))
            ->mapWithKeys(fn ($e) => [trim(substr((string) $e->command, strpos((string) $e->command, 'botcake:setup-check'))) => $e])
            ->all();
    }

    private function ready(string $page = 'Page Uno'): ?string
    {
        ChatblastReady::forget(app(PageSettings::class)->pageKey($page));

        return app(ChatblastReady::class)->reason($page);
    }

    public static function times(): array
    {
        return ['08:50' => ['08:50', false], '10:40' => ['10:40', true], '23:30' => ['23:30', false], '05:50' => ['05:50', false],
            '06:00' => ['06:00', true], '08:40' => ['08:40', true], '21:30' => ['21:30', true]];
    }

    /**
     * Ang "between" ng scheduler ay ayon sa oras ng pag-load ng schedule (bawat schedule:run), kaya ang orasan muna.
     * @dataProvider times
     */
    public function test_S_171_1_every_ten_minutes_inside_the_window_plus_the_nightly_run_for_all_pages(string $at, bool $runs): void
    {
        $this->travelTo(Carbon::parse('2026-10-27 ' . $at, 'Asia/Manila'));
        $events = $this->events();
        $this->assertEqualsCanonicalizing(['botcake:setup-check --all', 'botcake:setup-check --chatblast'], array_keys($events));
        $this->assertSame('30 21 * * *', $events['botcake:setup-check --all']->expression);

        $e = $events['botcake:setup-check --chatblast'];
        $this->assertSame('*/10 * * * *', $e->expression);
        $this->assertSame('Asia/Manila', $e->timezone);
        $this->assertTrue($e->withoutOverlapping);
        $this->assertTrue($e->runInBackground);
        $this->assertSame($runs, $e->isDue($this->app) && $e->filtersPass($this->app), $at . ' Manila');
    }

    public function test_S_171_2_the_ten_minute_run_checks_only_pages_chatblast_sends_for(): void
    {
        // Ang campaign na naka-on ang nagpapasya: Page Uno lang sa listahan nito; ang naka-off na campaign ay hindi bilang.
        DB::table('chatblast_campaigns')->update(['active' => false]);
        DB::table('chatblast_campaigns')->where('id', DB::table('chatblast_campaigns')->min('id'))->update(['active' => true, 'pages' => json_encode(['page uno'])]);
        DB::table('chatblast_campaigns')->where('id', DB::table('chatblast_campaigns')->max('id'))->update(['pages' => json_encode(['Page Dos'])]);
        $this->page('Page Uno', '1001');
        $this->page('Page Dos', '1002');                              // kumpleto, pero nasa naka-off na campaign lang
        $this->page('Page Tres', '1003', ['field.message']);         // kulang ang setup: hindi pinapadalhan

        Artisan::call('botcake:setup-check', ['--chatblast' => true]);

        $pages = array_values(array_unique(array_map(fn ($p) => preg_match('#/pages/([0-9]+)/#', $p, $m) ? $m[1] : $p, $this->paths)));
        $this->assertSame(['1001'], $pages);

        // Walang listahan ng page ang campaign = lahat ng page na kumpleto ang setup.
        DB::table('chatblast_campaigns')->update(['pages' => null]);
        $this->paths = [];
        Artisan::call('botcake:setup-check', ['--chatblast' => true]);
        $pages = array_values(array_unique(array_map(fn ($p) => preg_match('#/pages/([0-9]+)/#', $p, $m) ? $m[1] : $p, $this->paths)));
        sort($pages);
        $this->assertSame(['1001', '1002'], $pages);
    }

    public function test_S_171_3_a_run_that_finds_nothing_new_writes_nothing_and_a_real_change_is_written_once(): void
    {
        $this->page('Page Uno', '1001');
        $run = fn () => Artisan::call('botcake:setup-check', ['--chatblast' => true]);
        $run(); // ang check ang sumulat ng 9100 at 9200 (system): Ready nang walang test
        $this->assertNull($this->ready());
        $rows = PageSetting::count();

        $this->travel(10)->minutes();
        $run();
        $this->assertStringContainsString('✔ check', Artisan::output(), 'the run itself passed');
        $this->assertSame($rows, PageSetting::count(), 'same IDs, same result: no new page-setting row or history');
        $this->assertNull($this->ready(), 'Ready unchanged');

        // Botcake na hindi sumagot nang dalawang beses: isang "Check failed" lang ang naitala, naiiwan ang Ready.
        $this->flowsHttp = 500;
        $this->travel(10)->minutes();
        $run();
        $this->travel(10)->minutes();
        $run();
        $this->assertSame($rows + 1, PageSetting::count(), 'the same failure is written once');
        $this->assertNull($this->ready());
        $this->flowsHttp = 200;

        // Isang kopya ng flow: X, isang beses lang naisulat, hindi Ready.
        $this->flows[] = ['id' => 9101, 'name' => 'UPDATE SHIPPING STATUS 2026', 'is_removed' => false];
        $this->travel(10)->minutes();
        $run();
        $rows = PageSetting::count();
        $this->travel(10)->minutes();
        $run();
        $this->assertSame($rows, PageSetting::count(), 'the same X is written once');
        $this->assertSame('Page not ready: 2 flows named UPDATE SHIPPING STATUS 2026', $this->ready());

        // Inalis ang kopya: OK ulit, Ready (ang check ang sumulat ng flow at field).
        array_pop($this->flows);
        $this->travel(10)->minutes();
        $run();
        $this->assertTrue(json_decode((string) app(PageSettings::class)->get('Page Uno', 'botcake', 'setup.check'), true)['ok']);
        $this->assertNull($this->ready());
    }
}

<?php

namespace Tests\Feature\PageSettings;

use App\Models\BotcakePsidImportRun;
use App\Services\PageSettings;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Botcake\BotcakeApiTestCase;

/** "Susunod na sync" kada page at ang plano ng Sync lahat sa itaas ng grid. */
class SyncPlanTest extends BotcakeApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->user());
        $this->travelTo('2026-10-18 12:00:00');
    }

    private function botcake(string $page, ?string $status = null): void
    {
        $s = app(PageSettings::class);
        $s->set($page, 'botcake', 'page_id', (string) crc32($page));
        $s->set($page, 'botcake', 'token', 'tok-plan-' . crc32($page));
        if ($status) $s->set($page, 'botcake', 'token_status', $status, false, null, true);
    }

    private function apiRun(string $page, string $stop, string $kind, int $hoursAgo, array $extra = []): BotcakePsidImportRun
    {
        $run = BotcakePsidImportRun::create($extra + [
            'status' => 'done', 'source' => 'api', 'page_name' => $page, 'stop_reason' => $stop, 'read_kind' => $kind,
            'start_batch' => 1, 'batches_read' => 20,
        ]);
        DB::table('botcake_psid_import_runs')->where('id', $run->id)->update(['created_at' => now()->subHours($hoursAgo), 'updated_at' => now()->subHours($hoursAgo)]);
        return $run;
    }

    private function rows(): array
    {
        return collect($this->get('/page-settings')->assertOk()->viewData('grid')['rows'])->keyBy('name')->all();
    }

    public function test_each_page_shows_what_its_next_sync_would_do_and_the_summary_counts_them(): void
    {
        $this->botcake('Page Win');                                   // wala pang run
        $this->botcake('Page Tuloy');
        $this->apiRun('Page Tuloy', 'time', 'window', 8);             // putol na window: tuloy mula 20 - 5
        $this->botcake('Page Inc');
        $this->apiRun('Page Inc', 'age', 'window', 8, ['subscribed_newest' => '2026-10-17']); // tapos, 8 oras na: by date
        $this->botcake('Page Fresh');
        $this->apiRun('Page Fresh', 'end', 'window', 2, ['subscribed_newest' => '2026-10-18']); // tapos, 2 oras pa lang: laktaw
        $this->botcake('Page Fresh Window');
        $this->apiRun('Page Fresh Window', 'time', 'window', 1);      // sariwa pero tuloy: hindi nilalaktawan
        $this->botcake('Page Bad', 'invalid 2026-10-18 08:00');
        app(PageSettings::class)->set('Page Wala', 'sms', 'sender', 'W');

        $next = collect($this->rows())->map(fn ($r) => $r['info']['next'])->all();

        $this->assertSame(['kind' => 'window', 'label' => 'window (45 araw, buo)'], $next['Page Win']);
        $this->assertSame(['kind' => 'continue', 'label' => 'tuloy mula batch 15'], $next['Page Tuloy']);
        $this->assertSame(['kind' => 'by_date', 'label' => 'by date, mula Okt 16'], $next['Page Inc']);
        $this->assertSame(['kind' => 'fresh', 'label' => 'laktaw (sariwa, 2 oras na)'], $next['Page Fresh']);
        $this->assertSame('continue', $next['Page Fresh Window']['kind']);
        $this->assertSame(['kind' => 'invalid', 'label' => 'token invalid'], $next['Page Bad']);
        $this->assertSame(['kind' => 'missing', 'label' => 'walang ID/token'], $next['Page Wala']);

        $grid = $this->get('/page-settings')->viewData('grid');
        $this->assertSame('1 window, 2 tuloy, 1 by date, 1 laktaw (sariwa), 1 walang ID/token, 1 token invalid', $grid['plan_summary']);

        config(['services.botcake.sync_all_fresh_hours' => 1]);
        $this->assertSame('by_date', $this->rows()['Page Fresh']['info']['next']['kind'], 'ang oras ay mula sa config');
    }

    public function test_the_last_sync_shows_its_counts(): void
    {
        $this->botcake('Page Uno');
        $this->apiRun('Page Uno', 'age', 'window', 3, ['customers_new' => 9985, 'customers_updated' => 12, 'matched_by_name' => 1200, 'matched_by_phone' => 79, 'ad_filled' => 40]);

        $last = $this->rows()['Page Uno']['info']['last_sync'];

        $this->assertSame([9985, 12, 1279, 40], [$last['new'], $last['updated'], $last['matched'], $last['ad_filled']]);
    }

    public function test_the_last_sync_shows_the_dates_it_read_and_a_failed_run_in_red_with_the_next_stop_date(): void
    {
        $this->botcake('Page Uno');
        $this->apiRun('Page Uno', 'date', 'by_date', 30, ['subscribed_newest' => '2026-10-17', 'subscribed_oldest' => '2026-10-16']);
        $this->botcake('Page Dos');
        $this->apiRun('Page Dos', 'date', 'by_date', 30, ['subscribed_newest' => '2026-10-16', 'subscribed_oldest' => '2026-10-15']);
        $this->apiRun('Page Dos', 'rate_limited', 'by_date', 3, ['status' => 'failed', 'last_error' => 'Rate limit', 'subscribed_newest' => '2026-10-18', 'subscribed_oldest' => '2026-10-18']);

        $rows = $this->rows();

        $this->assertSame(['Okt 16 → Okt 17', 'by date', false], [$rows['Page Uno']['info']['last_sync']['dates'], $rows['Page Uno']['info']['last_sync']['kind'], $rows['Page Uno']['info']['last_sync']['failed']]);
        $this->assertSame(['Okt 18', true], [$rows['Page Dos']['info']['last_sync']['dates'], $rows['Page Dos']['info']['last_sync']['failed']]);
        $this->assertSame('by date, mula Okt 15', $rows['Page Dos']['info']['next']['label'], 'ang failed na run ay hindi gumagalaw sa stop date');
        $this->get('/page-settings')->assertSee("' · susunod: '", false)->assertSee("'text-red-600', !!(s && s.failed)", false);
    }
}

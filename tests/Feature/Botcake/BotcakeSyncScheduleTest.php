<?php

namespace Tests\Feature\Botcake;

use App\Services\PageSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

class BotcakeSyncScheduleTest extends BotcakeApiTestCase
{
    private function events(): array
    {
        Artisan::call('schedule:list'); // nilo-load ang routes/console.php

        return collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) $e->command, 'botcake:sync-customers'))
            ->mapWithKeys(fn ($e) => [trim(substr((string) $e->command, strpos((string) $e->command, 'botcake:sync-customers'))) => $e])
            ->all();
    }

    public function test_the_daily_by_date_sync_and_the_sunday_full_sync_run_at_1am_only_when_the_flag_is_on(): void
    {
        $events = $this->events();

        $this->assertEqualsCanonicalizing(['botcake:sync-customers', 'botcake:sync-customers --full'], array_keys($events), 'walang kada oras');
        $this->assertSame('0 1 * * 1-6', $events['botcake:sync-customers']->expression, 'Lunes hanggang Sabado: by date');
        $this->assertSame('0 1 * * 0', $events['botcake:sync-customers --full']->expression, 'Linggo: 45 araw, hindi kasabay ang by date');

        foreach ($events as $event) {
            $this->assertSame('Asia/Manila', $event->timezone);
            $this->assertTrue($event->withoutOverlapping);
            $this->assertTrue($event->runInBackground);
            $this->assertSame(720, $event->expiresAt, 'ang mutex ng patay na proseso ay hindi haharang sa susunod na gabi');

            config(['services.botcake.sync_enabled' => false]);
            $this->assertFalse($event->filtersPass($this->app), 'naka-off ang flag: walang tatakbo');
            config(['services.botcake.sync_enabled' => true]);
            $this->assertTrue($event->filtersPass($this->app));
        }
    }

    public static function nights(): array
    {
        // [oras (UTC), inaasahang basa]: ang petsa sa Manila ang nagpapasya
        return [
            'Martes 01:00 Manila'                  => ['2026-10-19 17:00', 'by_date'],
            'Linggo 01:00 Manila'                  => ['2026-10-17 17:00', 'full'],
            'Sabado 23:30 UTC = Linggo sa Manila'  => ['2026-10-17 23:30', 'full'],
            'Linggo 20:00 UTC = Lunes sa Manila'   => ['2026-10-18 20:00', 'by_date'],
        ];
    }

    /** @dataProvider nights */
    public function test_nightly_runs_the_full_read_on_a_manila_sunday_and_by_date_on_other_days(string $utc, string $kind): void
    {
        config(['services.botcake.sync_enabled' => true]);
        $this->travelTo(\Carbon\Carbon::parse($utc, 'UTC'));
        $this->nightlyPage();

        $this->assertSame(0, Artisan::call('botcake:sync-customers', ['--nightly' => true]));

        $this->assertSame($kind, \App\Models\BotcakePsidImportRun::where('source', 'api')->latest('id')->value('read_kind'));
    }

    public function test_nightly_exits_at_once_when_the_flag_is_off(): void
    {
        config(['services.botcake.sync_enabled' => false]);
        $this->nightlyPage();

        $this->assertSame(0, Artisan::call('botcake:sync-customers', ['--nightly' => true]));

        Http::assertNothingSent();
        $this->assertSame(1, \App\Models\BotcakePsidImportRun::where('source', 'api')->count(), 'walang bagong run');
        $this->assertStringContainsString('BOTCAKE_SYNC_ENABLED', Artisan::output());
    }

    /** Isang page na tapos na ang window (kahapon): by date ang susunod, maliban kung --full. */
    private function nightlyPage(): void
    {
        $s = app(PageSettings::class);
        $s->set('Page Uno', 'botcake', 'page_id', '1001');
        $s->set('Page Uno', 'botcake', 'token', 'tok-nightly-test');
        \App\Models\BotcakePsidImportRun::create(['status' => 'done', 'source' => 'api', 'page_name' => 'Page Uno', 'stop_reason' => 'age',
            'read_kind' => 'window', 'start_batch' => 1, 'subscribed_newest' => now('Asia/Manila')->subDay()->toDateString()]);
        Http::fake(['botcake.io/*' => Http::response([], 200)]);
    }

    public function test_the_flag_is_off_by_default(): void
    {
        $this->assertFalse((bool) config('services.botcake.sync_enabled'));
    }

    public function test_the_hourly_sheet_import_also_matches_pages_with_tokens(): void
    {
        $s = app(PageSettings::class);
        $s->set('Page Uno', 'botcake', 'page_id', '1001');
        $s->set('Page Uno', 'botcake', 'token', 'tok-hook-test');
        \App\Models\BotcakePsid::create(['page' => 'Page Uno', 'fb_name' => 'Ana Cruz', 'psid' => 'psid-a', 'status' => 'available', 'source' => 'api']);
        $order = $this->order('Page Uno', 'Ana Cruz');
        Http::fake();

        $run = \App\Models\BotcakePsidImportRun::create(['status' => 'running']);
        (new \App\Jobs\ImportBotcakePsidFromGoogleSheet(null, $run->id))->handle(); // walang setting = walang sheet na binabasa

        $this->assertSame('psid-a', $order->fresh()->botcake_psid);
        Http::assertNothingSent();
    }
}

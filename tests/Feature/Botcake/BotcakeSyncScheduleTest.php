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

    public function test_hourly_sync_and_the_sunday_full_sync_exist_and_run_only_when_the_flag_is_on(): void
    {
        $events = $this->events();

        $this->assertEqualsCanonicalizing(['botcake:sync-customers', 'botcake:sync-customers --full'], array_keys($events));
        $this->assertSame('15 * * * *', $events['botcake:sync-customers']->expression);
        $this->assertSame('0 1 * * 0', $events['botcake:sync-customers --full']->expression);

        foreach ($events as $event) {
            $this->assertSame('Asia/Manila', $event->timezone);
            $this->assertTrue($event->withoutOverlapping);

            config(['services.botcake.sync_enabled' => false]);
            $this->assertFalse($event->filtersPass($this->app), 'naka-off ang flag: walang tatakbo');
            config(['services.botcake.sync_enabled' => true]);
            $this->assertTrue($event->filtersPass($this->app));
        }
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

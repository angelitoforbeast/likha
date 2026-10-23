<?php

namespace Tests\Feature\Botcake;

use App\Jobs\ImportBotcakePsidFromGoogleSheet;
use App\Models\BotcakePsidImportRun;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

class BotcakePsidScheduleTest extends BotcakePsidTestCase
{
    public function test_hourly_import_and_the_0300_clean_then_import_are_scheduled_in_manila_time(): void
    {
        Artisan::call('schedule:list'); // nilo-load ang routes/console.php

        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) $e->command, 'botcake:psid-import'))
            ->mapWithKeys(fn ($e) => [trim(substr((string) $e->command, strpos((string) $e->command, 'botcake:psid-import'))) => $e]);

        $this->assertEqualsCanonicalizing(['botcake:psid-import', 'botcake:psid-import --clean-sheet'], $events->keys()->all());

        // Oras-oras maliban sa 03:00, dahil sa 03:00 ang clean-then-import ang tatakbo.
        $this->assertSame('0 0-2,4-23 * * *', $events['botcake:psid-import']->expression);
        $this->assertSame('0 3 * * *', $events['botcake:psid-import --clean-sheet']->expression);

        foreach ($events as $event) {
            $this->assertSame('Asia/Manila', $event->timezone);
            $this->assertTrue($event->withoutOverlapping);
        }
    }

    public function test_command_creates_a_run_and_queues_the_import(): void
    {
        Queue::fake();

        $this->artisan('botcake:psid-import', ['--clean-sheet' => true])->assertSuccessful();

        $run = BotcakePsidImportRun::sole();
        $this->assertSame('running', $run->status);
        Queue::assertPushedOn('psid', ImportBotcakePsidFromGoogleSheet::class, function ($job) use ($run) {
            $p = (fn () => [$this->runId, $this->cleanSheet, $this->cutoffDateTime])->call($job);
            return $p === [$run->id, true, null];
        });
    }
}

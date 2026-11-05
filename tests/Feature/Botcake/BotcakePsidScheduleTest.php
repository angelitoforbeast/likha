<?php

namespace Tests\Feature\Botcake;

use App\Http\Middleware\AllowedIpMiddleware;
use App\Jobs\ImportBotcakePsidFromGoogleSheet;
use App\Models\BotcakePsidImportRun;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

class BotcakePsidScheduleTest extends BotcakePsidTestCase
{
    public function test_S_232_1_1_the_sheet_import_is_not_scheduled_and_the_api_sync_and_chat_prune_still_are(): void
    {
        Artisan::call('schedule:list'); // nilo-load ang routes/console.php

        $commands = collect(app(Schedule::class)->events())->map(fn ($e) => (string) $e->command);

        $this->assertFalse($commands->contains(fn ($c) => str_contains($c, 'botcake:psid-import')));
        $this->assertTrue($commands->contains(fn ($c) => str_contains($c, 'botcake:sync-customers')));
        $this->assertTrue($commands->contains(fn ($c) => str_contains($c, 'botcake:prune-chats')));
    }

    public function test_S_232_1_2_the_manual_import_button_still_queues_the_job(): void
    {
        Queue::fake();
        // allowed_ip: hindi ito ang sinusubok dito (gaya ng BotcakeApiTestCase).
        $this->withoutMiddleware(AllowedIpMiddleware::class);
        $this->actingAs(new User(['id' => 1, 'name' => 'Staff']));

        $this->post(route('botcake.psid.import.run'))->assertRedirect();

        $run = BotcakePsidImportRun::sole();
        $this->assertSame('running', $run->status);
        Queue::assertPushedOn('psid', ImportBotcakePsidFromGoogleSheet::class, function ($job) use ($run) {
            return (fn () => $this->runId)->call($job) === $run->id;
        });
    }

    public function test_S_232_1_3_the_command_run_by_hand_creates_a_run_and_queues_the_import(): void
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

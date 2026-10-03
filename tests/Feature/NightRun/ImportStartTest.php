<?php

namespace Tests\Feature\NightRun;

use App\Jobs\ImportMacroFromGoogleSheet;
use App\Models\MacroGsheetSetting;
use App\Models\MacroImportRun;
use App\Models\MacroImportRunItem;
use App\Services\Imports\MacroImportStarter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

/**
 * Isang import lang sa bawat pagkakataon (spec 007 §4.1, §4.3): button, API at Likha starts.
 * Hindi sinusubok ang Google fetch — Queue::fake() kaya walang job na tumatakbo.
 */
class ImportStartTest extends NightRunTestCase
{
    private const API_KEY = 'test-automation-key';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['services.automation.key' => self::API_KEY]);
    }

    private function macroSetting(string $name, bool $archived = false): MacroGsheetSetting
    {
        return MacroGsheetSetting::create([
            'gsheet_name' => $name,
            'sheet_url'   => 'https://docs.google.com/spreadsheets/d/' . $name . '/edit',
            'sheet_range' => 'Sheet1!A2:P',
            'is_archived' => $archived,
        ]);
    }

    private function macroRun(string $status, array $extra = []): MacroImportRun
    {
        return MacroImportRun::create(array_merge(['status' => $status, 'started_at' => now()], $extra));
    }

    private function pressButton()
    {
        return $this->from('/macro/gsheet/import')->post('/macro/gsheet/import');
    }

    private function callApi(?string $key = self::API_KEY)
    {
        return $this->postJson('/api/automation/macro-import', [], $key === null ? [] : ['X-AUTOMATION-KEY' => $key]);
    }

    /** Wait = 0 para hindi matulog ang test habang hawak ng iba ang lock. */
    private function macroStarterWithoutWait(): void
    {
        $starter = new MacroImportStarter();
        $starter->lockWait = 0;
        $this->app->instance(MacroImportStarter::class, $starter);
    }

    public function test_second_macro_start_is_refused_while_a_run_is_queued_or_running(): void
    {
        $this->actingAs($this->user());
        $this->macroSetting('a');

        foreach (['queued', 'running'] as $status) {
            MacroImportRun::query()->delete();
            $active = $this->macroRun($status);

            $this->pressButton()
                ->assertRedirect('/macro/gsheet/import')
                ->assertSessionHas('error', "May running import pa (Run #{$active->id}). Hintayin muna matapos.");

            $this->callApi()
                ->assertStatus(409)
                ->assertExactJson([
                    'ok'      => false,
                    'message' => "May running import pa (Run #{$active->id}).",
                    'run_id'  => $active->id,
                ]);

            $this->assertSame(1, MacroImportRun::count(), "status {$status}");
        }

        Queue::assertNothingPushed();
    }

    public function test_api_start_answers_with_todays_json(): void
    {
        $this->macroSetting('a');

        $this->callApi(null)->assertStatus(403);
        $this->callApi('wrong-key')->assertStatus(403);
        $this->assertSame(0, MacroImportRun::count());

        $this->callApi()
            ->assertStatus(200)
            ->assertExactJson([
                'ok'         => true,
                'message'    => 'Import started',
                'run_id'     => 1,
                'status_url' => url('/macro/gsheet/import/status?run_id=1'),
            ]);

        $run = MacroImportRun::sole();
        $this->assertSame('queued', $run->status);
        $this->assertSame('Triggered via n8n', $run->message);
        $this->assertNull($run->started_by);
        Queue::assertPushed(ImportMacroFromGoogleSheet::class, fn ($job) => $job->runId === 1 && $job->userId === null);
    }

    public function test_button_start_redirects_with_todays_message(): void
    {
        $user = $this->user();
        $this->actingAs($user);
        $this->macroSetting('a');

        $this->pressButton()
            ->assertRedirect(route('macro.import.view', ['run_id' => 1]))
            ->assertSessionHas('success', '⏳ Import started (Run #1).');

        $run = MacroImportRun::sole();
        $this->assertSame('queued', $run->status);
        $this->assertNull($run->message);
        $this->assertSame($user->id, (int) $run->started_by);
        Queue::assertPushed(ImportMacroFromGoogleSheet::class, fn ($job) => $job->runId === 1 && $job->userId === $user->id);
    }

    public function test_archived_macro_settings_are_excluded_on_button_and_api(): void
    {
        $this->actingAs($this->user());
        $first    = $this->macroSetting('a');
        $archived = $this->macroSetting('old', true);
        $second   = $this->macroSetting('b');

        $paths = [
            'button' => fn () => $this->pressButton(),
            'api'    => fn () => $this->callApi(),
        ];

        foreach ($paths as $path => $start) {
            $start();

            $run = MacroImportRun::latest('id')->first();
            $this->assertNotNull($run, $path);
            $this->assertSame(2, (int) $run->total_settings, $path);
            $this->assertSame(
                [$first->id, $second->id],
                MacroImportRunItem::where('run_id', $run->id)->orderBy('id')->pluck('setting_id')->map(fn ($id) => (int) $id)->all(),
                $path
            );
            $this->assertNotContains($archived->id, MacroImportRunItem::pluck('setting_id')->all(), $path);

            $run->update(['status' => 'done']);
        }
    }

    public function test_macro_start_while_the_lock_is_held_creates_no_run(): void
    {
        $this->actingAs($this->user());
        $this->macroSetting('a');
        $this->macroStarterWithoutWait();

        $held = Cache::lock('import-start:macro', 30);
        $this->assertTrue($held->get());

        $this->pressButton()
            ->assertRedirect('/macro/gsheet/import')
            ->assertSessionHas('error', 'May running import pa. Hintayin muna matapos.');

        $this->callApi()
            ->assertStatus(409)
            ->assertExactJson(['ok' => false, 'message' => 'May running import pa.', 'run_id' => null]);

        $this->assertSame(0, MacroImportRun::count());
        Queue::assertNothingPushed();

        $held->release();
    }

    public function test_two_macro_starts_in_a_row_create_one_run(): void
    {
        $this->macroSetting('a');

        $this->callApi()->assertStatus(200);
        $this->callApi()->assertStatus(409)->assertJsonPath('run_id', 1);

        $this->assertSame(1, MacroImportRun::count());
        Queue::assertPushed(ImportMacroFromGoogleSheet::class, 1);
    }

    public function test_macro_job_leaves_a_run_that_is_already_failed_untouched(): void
    {
        $run = $this->macroRun('failed', [
            'message'          => 'Stale: closed by the night run',
            'cancel_requested' => true,
            'finished_at'      => now()->subMinutes(5),
        ]);
        $before = $run->fresh()->getAttributes();

        (new ImportMacroFromGoogleSheet($run->id))->handle();

        $this->assertSame($before, $run->fresh()->getAttributes());
    }
}

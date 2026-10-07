<?php

namespace Tests\Feature\NightRun;

use App\Jobs\ImportLikhaFromGoogleSheet;
use App\Jobs\ImportMacroFromGoogleSheet;
use App\Models\LikhaImportRun;
use App\Models\LikhaImportRunSheet;
use App\Models\LikhaOrderSetting;
use App\Models\MacroGsheetSetting;
use App\Models\MacroImportRun;
use App\Models\MacroImportRunItem;
use App\Services\Imports\LikhaImportStarter;
use App\Services\Imports\MacroImportStarter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

/**
 * Isang import lang sa bawat pagkakataon: button, API at Likha starts.
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

        // Isinara ng iba sa pagitan ng pagbasa ng job at ng pagsulat ng "running": hindi dapat mabuhay ulit.
        $racy      = $this->macroRun('queued');
        $startedAt = $racy->fresh()->started_at;
        MacroImportRun::retrieved(function (MacroImportRun $found) use ($racy) {
            if ((int) $found->id === $racy->id) {
                MacroImportRun::where('id', $racy->id)->update(['status' => 'failed', 'message' => 'Stale: closed by the night run']);
            }
        });

        (new ImportMacroFromGoogleSheet($racy->id))->handle();

        $racy->refresh();
        $this->assertSame(['failed', 'Stale: closed by the night run', $startedAt?->toDateTimeString()], [$racy->status, $racy->message, $racy->started_at?->toDateTimeString()]);
    }

    public function test_macro_job_final_write_applies_only_while_the_run_is_still_active(): void
    {
        // Cancel na na-click sa huling sheet ng malusog na run: dapat matapos pa rin sa totoong resulta.
        $healthy = $this->macroRun('running', ['cancel_requested' => true]);

        (new ImportMacroFromGoogleSheet($healthy->id))->handle();

        $healthy->refresh();
        $this->assertSame('done', $healthy->status);
        $this->assertSame('Import completed.', $healthy->message);
        $this->assertNotNull($healthy->finished_at);

        // Isinara ng iba (stale close / Force-stop) habang nasa huling sheet: hindi na binabago ng final write.
        // Ang item na walang setting ay hindi umaabot sa Google fetch; doon ginagaya ang pagsasara.
        $closed = $this->macroRun('running');
        MacroImportRunItem::create(['run_id' => $closed->id, 'setting_id' => null, 'status' => 'queued']);
        MacroImportRunItem::updated(function (MacroImportRunItem $item) use ($closed) {
            if ((int) $item->run_id === $closed->id) {
                MacroImportRun::where('id', $closed->id)->update(['status' => 'failed', 'message' => 'Stale: closed by the night run']);
            }
        });

        (new ImportMacroFromGoogleSheet($closed->id))->handle();

        $closed->refresh();
        $this->assertSame('failed', $closed->status);
        $this->assertSame('Stale: closed by the night run', $closed->message);
        $this->assertNull($closed->finished_at);
    }

    public function test_likha_stale_close_falls_back_to_created_at_when_started_at_is_null(): void
    {
        $this->likhaSetting('a');
        $this->travelTo('2026-10-04 02:00:00');

        // [minuto mula created_at, isinara ba]
        foreach ([[121, true], [119, false]] as [$minutes, $closed]) {
            LikhaImportRun::query()->delete();
            $old = LikhaImportRun::create(['status' => 'running', 'started_at' => null]);
            LikhaImportRun::where('id', $old->id)->update(['created_at' => now()->subMinutes($minutes)]);

            $result = app(LikhaImportStarter::class)->start();

            $this->assertSame($closed, $result['started'], "{$minutes} min");
            $this->assertSame($closed ? 'failed' : 'running', $old->fresh()->status, "{$minutes} min");
        }
    }

    // ───────────────────────────── Likha ─────────────────────────────

    private function likhaSetting(string $name, bool $archived = false): LikhaOrderSetting
    {
        return LikhaOrderSetting::create([
            'sheet_id'    => 'sheet-' . $name,
            'range'       => 'Sheet1!A2:I',
            'is_archived' => $archived,
        ]);
    }

    private function likhaRefusal(?int $runId): array
    {
        return [
            'ok'      => false,
            'message' => $runId ? "May running import pa (Run #{$runId}). Hintayin muna matapos." : 'May running import pa. Hintayin muna matapos.',
            'run_id'  => $runId,
        ];
    }

    public function test_likha_start_and_single_sheet_start_are_refused_while_a_run_is_running(): void
    {
        $this->actingAs($this->user());
        $first = $this->likhaSetting('a');
        $this->likhaSetting('old', true);
        $second = $this->likhaSetting('b');

        // Unang start: gaya ng dati ang sagot; archived hindi kasama.
        $this->postJson('/likha_order_import/start')->assertStatus(200)->assertExactJson(['ok' => true, 'run_id' => 1]);
        $run = LikhaImportRun::sole();
        $this->assertSame('running', $run->status);
        $this->assertSame(2, (int) $run->total_settings);
        $this->assertSame(
            [[$first->id, 'queued'], [$second->id, 'queued']],
            LikhaImportRunSheet::where('run_id', 1)->orderBy('id')->get()->map(fn ($s) => [(int) $s->setting_id, $s->status])->all()
        );

        // Habang running: parehong start ay 409, walang bagong run.
        $this->postJson('/likha_order_import/start')->assertStatus(409)->assertExactJson($this->likhaRefusal(1));
        $this->postJson("/likha_order_import/{$second->id}/start")->assertStatus(409)->assertExactJson($this->likhaRefusal(1));
        $this->assertSame(1, LikhaImportRun::count());

        // Tapos na ang run → single-sheet start, gaya ng dati ang sagot.
        $run->update(['status' => 'done']);
        $this->postJson("/likha_order_import/{$second->id}/start")->assertStatus(200)->assertExactJson(['ok' => true, 'run_id' => 2]);
        $this->assertSame(1, (int) LikhaImportRun::find(2)->total_settings);
        $this->assertSame([$second->id], LikhaImportRunSheet::where('run_id', 2)->pluck('setting_id')->map(fn ($id) => (int) $id)->all());

        Queue::assertPushed(ImportLikhaFromGoogleSheet::class, 2);
    }

    public function test_likha_start_while_the_lock_is_held_creates_no_run(): void
    {
        $this->actingAs($this->user());
        $this->likhaSetting('a');
        $starter = new LikhaImportStarter();
        $starter->lockWait = 0;
        $this->app->instance(LikhaImportStarter::class, $starter);

        $held = Cache::lock('import-start:likha', 30);
        $this->assertTrue($held->get());

        $this->postJson('/likha_order_import/start')->assertStatus(409)->assertExactJson($this->likhaRefusal(null));
        $this->assertSame(0, LikhaImportRun::count());
        Queue::assertNothingPushed();

        $held->release();
    }

    public function test_likha_run_older_than_two_hours_is_closed_at_the_next_start(): void
    {
        $this->actingAs($this->user());
        $setting = $this->likhaSetting('a');
        $done    = $this->likhaSetting('b');
        $this->travelTo('2026-10-04 02:00:00');

        $viaButton = fn () => $this->postJson('/likha_order_import/start')->assertStatus(200);
        $viaNight  = fn () => $this->assertTrue(app(LikhaImportStarter::class)->start(null, 'Stale: closed by the night run')['started']);

        // [minuto mula nang magsimula, paano sinimulan, inaasahang message ng lumang run (null = hindi isinara)]
        $cases = [
            '121 min, button'    => [121, $viaButton, 'Stale: closed at the next start'],
            '121 min, scheduler' => [121, $viaNight, 'Stale: closed by the night run'],
            '119 min'            => [119, fn () => $this->postJson('/likha_order_import/start')->assertStatus(409), null],
        ];

        foreach ($cases as $name => [$minutes, $start, $closedMessage]) {
            LikhaImportRunSheet::query()->delete();
            LikhaImportRun::query()->delete();

            $old = LikhaImportRun::create(['status' => 'running', 'total_settings' => 2, 'started_at' => now()->subMinutes($minutes)]);
            LikhaImportRunSheet::create(['run_id' => $old->id, 'setting_id' => $done->id, 'status' => 'done']);
            LikhaImportRunSheet::create(['run_id' => $old->id, 'setting_id' => $setting->id, 'status' => 'processing']);

            $start();

            $old->refresh();
            $sheets = LikhaImportRunSheet::where('run_id', $old->id)->orderBy('id')->pluck('status')->all();

            if ($closedMessage === null) {
                $this->assertSame('running', $old->status, $name);
                $this->assertNull($old->message, $name);
                $this->assertSame(['done', 'processing'], $sheets, $name);
                $this->assertSame(1, LikhaImportRun::count(), $name);
                continue;
            }

            $this->assertSame('failed', $old->status, $name);
            $this->assertSame($closedMessage, $old->message, $name);
            $this->assertSame('2026-10-04 02:00:00', $old->finished_at->toDateTimeString(), $name);
            $this->assertSame(['done', 'failed'], $sheets, $name);
            $this->assertSame(1, LikhaImportRun::where('status', 'running')->where('id', '!=', $old->id)->count(), $name);
        }
    }

    public function test_likha_job_leaves_a_run_that_is_already_failed_untouched(): void
    {
        $run = LikhaImportRun::create([
            'status'      => 'failed',
            'message'     => 'Stale: closed at the next start',
            'started_at'  => now()->subHours(3),
            'finished_at' => now()->subMinutes(5),
        ]);
        $before = $run->fresh()->getAttributes();

        (new ImportLikhaFromGoogleSheet($run->id))->handle();

        $this->assertSame($before, $run->fresh()->getAttributes());
    }
}

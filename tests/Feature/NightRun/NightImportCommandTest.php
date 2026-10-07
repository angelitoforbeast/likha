<?php

namespace Tests\Feature\NightRun;

use App\Jobs\ImportLikhaFromGoogleSheet;
use App\Jobs\ImportMacroFromGoogleSheet;
use App\Models\AppSetting;
use App\Models\LikhaImportRun;
use App\Models\LikhaImportRunSheet;
use App\Models\LikhaOrderSetting;
use App\Models\MacroGsheetSetting;
use App\Models\MacroImportRun;
use App\Models\MacroImportRunItem;
use App\Models\NightRunStep;
use App\Services\Imports\MacroImportStarter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * `night:import {kind} {slot}`: stale rule, isang step kada gabi at kind.
 * Hindi sinusubok ang Google fetch — Queue::fake() kaya walang job na tumatakbo.
 */
class NightImportCommandTest extends NightRunTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Carbon::setTestNow('2026-10-05 01:00:00'); // app timezone = Asia/Manila
        AppSetting::set('night_macro_import_enabled', '1');
        AppSetting::set('night_likha_import_enabled', '1');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function night(string $kind, string $slot = '1'): int
    {
        return Artisan::call('night:import', ['kind' => $kind, 'slot' => $slot]);
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

    /** Active macro run na may isang tapos at isang tumatakbong sheet; huling galaw = ilang minuto na ang nakalipas. */
    private function macroRunWithProgress(int $startedMinutesAgo, int $runTouchedMinutesAgo, int $itemTouchedMinutesAgo): MacroImportRun
    {
        $run = MacroImportRun::create(['status' => 'running', 'started_at' => now()->subMinutes($startedMinutesAgo)]);
        MacroImportRunItem::create(['run_id' => $run->id, 'status' => 'done']);
        MacroImportRunItem::create(['run_id' => $run->id, 'status' => 'running']);

        DB::table('macro_import_runs')->where('id', $run->id)->update(['updated_at' => now()->subMinutes($runTouchedMinutesAgo)]);
        DB::table('macro_import_run_items')->where('run_id', $run->id)->update(['updated_at' => now()->subMinutes($itemTouchedMinutesAgo)]);

        return $run;
    }

    public function test_macro_run_is_closed_only_after_15_minutes_without_progress(): void
    {
        $this->macroSetting('a');

        // [started ilang min ang nakalipas, run updated_at, items updated_at, isinara ba]
        $cases = [
            'no progress for 16 minutes'               => [16, 16, 16, true],
            'no progress for 14 minutes'               => [14, 14, 14, false],
            '3 hours old, a sheet progressed a minute ago' => [180, 180, 1, false],
            '3 hours old, the run progressed a minute ago' => [180, 1, 180, false],
        ];

        foreach ($cases as $name => [$started, $runTouched, $itemTouched, $closed]) {
            NightRunStep::query()->delete();
            MacroImportRunItem::query()->delete();
            MacroImportRun::query()->delete();
            $old = $this->macroRunWithProgress($started, $runTouched, $itemTouched);

            $this->assertSame(0, $this->night('macro'), $name);

            $old->refresh();
            $items = MacroImportRunItem::where('run_id', $old->id)->orderBy('id')->pluck('status')->all();
            $step  = NightRunStep::sole();
            $this->assertSame(['2026-10-05', 'macro_import_1', 'schedule'], [substr((string) $step->night_date, 0, 10), $step->kind, $step->trigger], $name);

            if (!$closed) {
                $this->assertSame('running', $old->status, $name);
                $this->assertFalse($old->cancel_requested, $name);
                $this->assertSame(['done', 'running'], $items, $name);
                $this->assertSame(1, MacroImportRun::count(), $name);
                $this->assertSame(['skipped', "Skipped: run #{$old->id} was still running", null], [$step->state, $step->reason, $step->ref_id], $name);
                continue;
            }

            $this->assertSame('failed', $old->status, $name);
            $this->assertSame('Stale: closed by the night run', $old->message, $name);
            $this->assertTrue($old->cancel_requested, $name);
            $this->assertSame('2026-10-05 01:00:00', $old->finished_at->toDateTimeString(), $name);
            $this->assertSame(['done', 'failed'], $items, $name);

            $new = MacroImportRun::where('id', '!=', $old->id)->sole();
            $this->assertSame(['queued', 'Night run', null], [$new->status, $new->message, $new->started_by], $name);
            $this->assertSame(['started', null, $new->id], [$step->state, $step->reason, (int) $step->ref_id], $name);
            $this->assertSame('2026-10-05 01:00:00', $step->started_at->toDateTimeString(), $name);
            Queue::assertPushed(ImportMacroFromGoogleSheet::class, fn ($job) => $job->runId === $new->id && $job->userId === null);
        }
    }

    public function test_likha_run_started_over_two_hours_ago_is_closed_and_a_new_run_starts(): void
    {
        $setting = LikhaOrderSetting::create(['sheet_id' => 'sheet-a', 'range' => 'Sheet1!A2:I']);
        $old = LikhaImportRun::create(['status' => 'running', 'total_settings' => 1, 'started_at' => now()->subMinutes(121)]);
        LikhaImportRunSheet::create(['run_id' => $old->id, 'setting_id' => $setting->id, 'status' => 'processing']);

        $this->assertSame(0, $this->night('likha', '2'));

        $old->refresh();
        $this->assertSame(['failed', 'Stale: closed by the night run'], [$old->status, $old->message]);
        $new = LikhaImportRun::where('id', '!=', $old->id)->sole();
        $this->assertSame('running', $new->status);

        $step = NightRunStep::sole();
        $this->assertSame(['likha_import_2', 'started', $new->id, 'schedule'], [$step->kind, $step->state, (int) $step->ref_id, $step->trigger]);
        Queue::assertPushed(ImportLikhaFromGoogleSheet::class, fn ($job) => $job->runId === $new->id);
    }

    public function test_switch_off_or_bad_arguments_start_nothing_and_record_nothing(): void
    {
        $this->macroSetting('a');
        AppSetting::set('night_macro_import_enabled', '0');
        AppSetting::set('night_likha_import_enabled', '0');

        $this->assertSame(0, $this->night('macro'));
        $this->assertSame(0, $this->night('likha'));

        AppSetting::set('night_macro_import_enabled', '1');
        $this->assertNotSame(0, $this->night('astra'));
        $this->assertNotSame(0, $this->night('macro', '3'));

        $this->assertSame(0, MacroImportRun::count());
        $this->assertSame(0, LikhaImportRun::count());
        $this->assertSame(0, NightRunStep::count());
        Queue::assertNothingPushed();
    }

    public function test_second_call_the_same_manila_night_and_kind_does_nothing(): void
    {
        $this->macroSetting('a');
        $appTimezone = date_default_timezone_get();
        date_default_timezone_set('UTC'); // patunay na Manila ang petsa ng gabi, hindi ang timezone ng server

        try {
            Carbon::setTestNow(Carbon::parse('2026-10-04 17:30:00', 'UTC')); // 01:30 Oct 5 sa Manila
            $this->night('macro');
            MacroImportRun::query()->update(['status' => 'done']);
            $this->night('macro');

            $this->assertSame(1, MacroImportRun::count());
            $this->assertSame(['2026-10-05'], NightRunStep::pluck('night_date')->map(fn ($d) => substr((string) $d, 0, 10))->all());

            // Ibang slot, at kinabukasan: sariling step.
            $this->night('macro', '2');
            Carbon::setTestNow(Carbon::parse('2026-10-05 17:30:00', 'UTC'));
            MacroImportRun::query()->update(['status' => 'done']);
            $this->night('macro');

            $this->assertSame(3, MacroImportRun::count());
            $this->assertSame(
                [['2026-10-05', 'macro_import_1'], ['2026-10-05', 'macro_import_2'], ['2026-10-06', 'macro_import_1']],
                NightRunStep::orderBy('id')->get()->map(fn ($s) => [substr((string) $s->night_date, 0, 10), $s->kind])->all()
            );
        } finally {
            date_default_timezone_set($appTimezone);
        }
    }

    public function test_archived_macro_settings_are_excluded_on_the_scheduled_path(): void
    {
        $first = $this->macroSetting('a');
        $this->macroSetting('old', true);
        $second = $this->macroSetting('b');

        $this->night('macro');

        $run = MacroImportRun::sole();
        $this->assertSame(2, (int) $run->total_settings);
        $this->assertSame(
            [$first->id, $second->id],
            MacroImportRunItem::where('run_id', $run->id)->orderBy('id')->pluck('setting_id')->map(fn ($id) => (int) $id)->all()
        );
    }

    public function test_start_refused_with_no_run_to_name_is_recorded_as_skipped(): void
    {
        $this->macroSetting('a');
        $starter = new MacroImportStarter();
        $starter->lockWait = 0;
        $this->app->instance(MacroImportStarter::class, $starter);
        $held = Cache::lock('import-start:macro', 30);
        $this->assertTrue($held->get());

        $this->assertSame(0, $this->night('macro'));

        $step = NightRunStep::sole();
        $this->assertSame(['skipped', 'Skipped: another start was in progress', null], [$step->state, $step->reason, $step->ref_id]);
        $this->assertSame(0, MacroImportRun::count());

        $held->release();
    }

    public function test_exception_at_start_is_recorded_with_the_class_name_only(): void
    {
        $this->app->instance(MacroImportStarter::class, new class extends MacroImportStarter {
            public function start(?int $userId, ?string $message): array
            {
                throw new \RuntimeException('SQLSTATE secret-detail sk-test-123');
            }
        });

        $this->assertNotSame(0, $this->night('macro'));

        $step = NightRunStep::sole();
        $this->assertSame(['failed', 'Failed to start: RuntimeException', null], [$step->state, $step->reason, $step->ref_id]);
        $this->assertStringNotContainsString('sk-test-123', Artisan::output());
    }
}

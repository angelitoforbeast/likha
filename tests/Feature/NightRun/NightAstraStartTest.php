<?php

namespace Tests\Feature\NightRun;

use App\Jobs\RunNightAstraRow;
use App\Models\AppSetting;
use App\Models\LikhaImportRun;
use App\Models\MacroImportRun;
use App\Models\MacroImportRunItem;
use App\Models\NightAstraRow;
use App\Models\NightRunStep;
use App\Services\AstraEncoder;
use Illuminate\Support\Facades\Queue;

/**
 * Kailan nagsisimula ang Astra night run (spec 007 §6.2, amendment 007-1 items 12 at 16):
 * naghihintay sa import, "Did not run" pagkalipas ng 60 minuto, isang run kada petsa.
 */
class NightAstraStartTest extends NightAstraTestCase
{
    private function reset(): void
    {
        NightAstraRow::query()->delete();
        NightRunStep::query()->delete();
        MacroImportRunItem::query()->delete();
        MacroImportRun::query()->delete();
        LikhaImportRun::query()->delete();
    }

    public function test_it_waits_while_an_import_runs_and_starts_after_it(): void
    {
        $order = $this->order();

        // [aling import ang tumatakbo pa, ang dahilan habang naghihintay]
        $cases = [
            'macro' => 'Waiting: an import was still running (macro)',
            'likha' => 'Waiting: an import was still running (Likha)',
        ];

        foreach ($cases as $kind => $waitingReason) {
            $this->reset();
            $this->at('03:00:00');
            // Likha case: may tapos nang macro import, pero tumatakbo pa ang Likha (sumusulat din ito sa macro_output).
            $running = $kind === 'macro' ? $this->macroImport('running', ['done', 'running']) : $this->likhaImport('running');
            if ($kind === 'likha') {
                $this->macroImport();
            }

            $this->night()->tick();

            $step = $this->step();
            $this->assertSame(['waiting', $waitingReason, 'schedule'], [$step->state, $step->reason, $step->trigger], $kind);
            $this->assertSame(0, NightAstraRow::count(), $kind);

            $running->update(['status' => 'done']);
            $this->at('03:05:00');
            $this->night()->tick();

            $step = $this->step();
            $this->assertSame(['running', null, 'schedule', 1, 0], [$step->state, $step->reason, $step->trigger, (int) $step->rows_found, (int) $step->rows_over_max], $kind);
            $this->assertSame(
                [self::NIGHT . ' 03:05:00', self::NIGHT . ' 07:00:00'],
                [$step->started_at->toDateTimeString(), $step->stop_at->toDateTimeString()],
                $kind
            );
            $row = NightAstraRow::sole();
            $this->assertSame([$step->id, $order->id, 'queued', 0], [(int) $row->step_id, (int) $row->macro_output_id, $row->state, (int) $row->attempts], $kind);
            $this->assertNotNull($row->dispatched_at, $kind);
            Queue::assertPushedOn('astra', RunNightAstraRow::class, fn ($job) => $job->rowId === $row->id);
        }

        Queue::assertPushed(RunNightAstraRow::class, 2); // isa kada case, walang na-push habang naghihintay
    }

    public function test_a_macro_import_counts_only_when_it_started_since_midnight_and_ended_with_a_sheet_done(): void
    {
        $this->order();

        // [status ng run, status ng mga sheet, started_at, inaasahang state ng step]
        $cases = [
            'failed with one sheet done'         => ['failed', ['failed', 'done'], self::NIGHT . ' 01:00:00', 'running'],
            'failed with no sheet done'          => ['failed', ['failed', 'failed'], self::NIGHT . ' 01:00:00', 'waiting'],
            'done, started before midnight'      => ['done', ['done'], '2026-10-04 23:59:00', 'waiting'],
            'done, started exactly at midnight'  => ['done', ['done'], self::NIGHT . ' 00:00:00', 'running'],
        ];

        foreach ($cases as $name => [$status, $items, $startedAt, $expected]) {
            $this->reset();
            $this->macroImport($status, $items, $startedAt);

            $this->night()->tick();

            $step = $this->step();
            $this->assertSame($expected, $step->state, $name);
            if ($expected === 'waiting') {
                $this->assertSame('Waiting: no finished macro import since midnight', $step->reason, $name);
            }
        }
    }

    public function test_it_records_did_not_run_60_minutes_after_the_astra_time(): void
    {
        $this->order();

        $cases = [
            'macro still running' => [fn () => $this->macroImport('queued', ['queued']), 'an import was still running (macro)'],
            'likha still running' => [fn () => [$this->macroImport(), $this->likhaImport('running')], 'an import was still running (Likha)'],
            'no finished import'  => [fn () => null, 'no finished macro import since midnight'],
        ];

        foreach ($cases as $name => [$arrange, $reason]) {
            $this->reset();
            $arrange();

            $this->at('03:59:00');
            $this->night()->tick();
            $this->assertSame(['waiting', "Waiting: {$reason}"], [$this->step()->state, $this->step()->reason], $name);

            $this->at('04:00:00');
            $this->night()->tick();
            $this->assertSame(['did_not_run', "Did not run: {$reason}"], [$this->step()->state, $this->step()->reason], $name);

            // Kahit matapos pa ang import pagkatapos, hindi na ito sisimulan ng schedule.
            MacroImportRun::query()->update(['status' => 'done']);
            LikhaImportRun::query()->update(['status' => 'done']);
            $this->macroImport();
            $this->at('04:01:00');
            $this->night()->tick();
            $this->assertSame('did_not_run', $this->step()->state, $name);
            $this->assertSame(1, NightRunStep::count(), $name);
            $this->assertSame(0, NightAstraRow::count(), $name);
        }

        Queue::assertNothingPushed();
    }

    public function test_one_run_per_date(): void
    {
        $this->macroImport();
        $this->order();
        $this->order();

        $this->night()->tick();
        $this->at('03:01:00');
        $this->night()->tick();
        $this->assertNull($this->night()->start(self::NIGHT, 'schedule'));

        // Tapos na ang run: hindi pa rin ito sisimulan ulit ng schedule sa parehong petsa.
        NightAstraRow::query()->update(['state' => 'done']);
        NightRunStep::query()->update(['state' => 'finished']);
        $this->at('03:02:00');
        $this->night()->tick();
        $this->assertNull($this->night()->start(self::NIGHT, 'schedule'));

        $this->assertSame(1, NightRunStep::count());
        $this->assertSame('finished', $this->step()->state);
        $this->assertSame(['done', 'done'], $this->rowStates());
        Queue::assertPushed(RunNightAstraRow::class, 2);
    }

    public function test_no_api_key_stops_the_run_before_any_row(): void
    {
        $this->macroImport();
        $this->order();
        AstraEncoder::storeApiKey(null);
        // Ang engine ay bumabasa rin ng key mula sa environment ng makina: pinapawalang-laman lang sa process na ito.
        $names = ['ASTRA_ENCODER_API_KEY', 'OPENAI_API_KEY'];
        $saved = [];
        foreach ($names as $name) {
            $saved[$name] = [$_SERVER[$name] ?? null, $_ENV[$name] ?? null];
            $_SERVER[$name] = '';
            $_ENV[$name]    = '';
        }
        config(['services.openai.key' => null]);

        try {
            $this->night()->tick();
            $this->at('03:01:00');
            $this->night()->tick();
        } finally {
            foreach ($saved as $name => [$server, $env]) {
                if ($server === null) { unset($_SERVER[$name]); } else { $_SERVER[$name] = $server; }
                if ($env === null) { unset($_ENV[$name]); } else { $_ENV[$name] = $env; }
            }
        }

        $step = NightRunStep::sole();
        $this->assertSame(['stopped', 'Stopped: no API key set'], [$step->state, $step->reason]);
        $this->assertNotNull($step->finished_at);
        $this->assertSame(0, NightAstraRow::count());
        Queue::assertNothingPushed();
    }

    public function test_with_the_switch_off_nothing_starts_and_a_waiting_step_is_still_closed_after_its_window(): void
    {
        $this->order();
        $import = $this->macroImport('running', ['done', 'running']);

        // Naka-on: naghihintay. Tapos pinatay ang switch habang naghihintay, at natapos ang import.
        $this->night()->tick();
        $this->assertSame('waiting', $this->step()->state);
        AppSetting::set('night_astra_enabled', '0');
        $import->update(['status' => 'done']);

        $this->at('03:30:00');
        $this->night()->tick();
        $this->assertSame('waiting', $this->step()->state);

        $this->at('04:00:00');
        $this->night()->tick();
        // Ang dahilan ay ang switch, hindi ang lumang dahilan ng paghihintay (tapos na ang import).
        $this->assertSame(['did_not_run', 'Did not run: switched off while waiting'], [$this->step()->state, $this->step()->reason]);

        // Naka-off at walang step: walang sinisimulan at walang itinatala.
        NightRunStep::query()->delete();
        $this->at('03:10:00');
        $this->night()->tick();
        $this->assertSame(0, NightRunStep::count());
        $this->assertSame(0, NightAstraRow::count());
        Queue::assertNothingPushed();
    }

    public function test_the_tick_starts_only_between_the_astra_time_and_the_stop_time(): void
    {
        $this->macroImport();
        $this->order();

        // [oras ng unang tick ng gabi, inaasahang state (null = walang step)]
        $cases = [
            'before the astra time'                 => ['02:59:00', null],
            'late: the scheduler came back at 05:00' => ['05:00:00', 'running'],
            'at the stop time'                      => ['07:00:00', null],
        ];

        foreach ($cases as $name => [$time, $expected]) {
            NightAstraRow::query()->delete();
            NightRunStep::query()->delete();
            $this->at($time);

            $this->night()->tick();

            $this->assertSame($expected, $this->step()?->state, $name);
        }
    }

    public function test_no_rows_means_finished_at_once(): void
    {
        $this->macroImport();
        $this->order(['STATUS' => 'PROCEED']);

        $this->night()->tick();

        $step = $this->step();
        $this->assertSame(['finished', 0], [$step->state, (int) $step->rows_found]);
        $this->assertSame(self::NIGHT . ' 03:00:00', $step->finished_at->toDateTimeString());
        Queue::assertNothingPushed();
    }

    public function test_a_manual_start_runs_until_the_next_stop_time(): void
    {
        $order = $this->order(['ts_date' => '2026-10-02']);
        $this->at('14:00:00');

        $step = $this->night()->start('2026-10-03', 'manual');

        $this->assertSame(['running', 'manual', '2026-10-06 07:00:00'], [$step->state, $step->trigger, $step->stop_at->toDateTimeString()]);
        $this->assertSame([$order->id], NightAstraRow::pluck('macro_output_id')->all());
    }
}

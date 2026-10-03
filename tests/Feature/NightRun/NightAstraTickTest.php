<?php

namespace Tests\Feature\NightRun;

use App\Jobs\RunNightAstraRow;
use App\Models\AppSetting;
use App\Models\NightAstraRow;
use App\Models\NightRunStep;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;

/**
 * Ang tick kada minuto (spec 007 §6.5, §7): sweep ng mga row na naiwang `running`, stop time,
 * dispatchPending, settle. PERA: ang tick ay HINDI kailanman nagbabalik ng row sa `queued`.
 */
class NightAstraTickTest extends NightAstraTestCase
{
    private function tick(): void
    {
        $this->assertSame(0, Artisan::call('night:astra-tick'));
    }

    /** Isang row kada [state, dispatched_at, started_at]; ibinabalik ang step. */
    private function stepWithRows(array $rows, array $stepExtra = []): NightRunStep
    {
        $step = $this->runningStep([], $stepExtra);
        foreach ($rows as [$state, $dispatchedAt, $startedAt]) {
            NightAstraRow::create([
                'step_id'         => $step->id,
                'macro_output_id' => $this->order()->id,
                'state'           => $state,
                'attempts'        => $state === 'queued' ? 0 : 1,
                'dispatched_at'   => $dispatchedAt,
                'started_at'      => $startedAt,
            ]);
        }

        return $step;
    }

    public function test_a_row_left_running_for_over_10_minutes_is_failed_and_counted_by_the_breaker(): void
    {
        $this->at('03:30:00');
        $step = $this->stepWithRows([
            ['running', self::NIGHT . ' 03:00:00', self::NIGHT . ' 03:19:00'], // 11 minuto
            ['running', self::NIGHT . ' 03:00:00', self::NIGHT . ' 03:20:00'], // eksaktong 10: hindi pa
        ], ['consecutive_failures' => 2]);

        $this->tick();

        $rows = NightAstraRow::orderBy('id')->get();
        $this->assertSame(
            [['failed', 'Worker stopped'], ['running', null]],
            $rows->map(fn ($row) => [$row->state, $row->reason])->all()
        );
        $this->assertSame(self::NIGHT . ' 03:30:00', $rows[0]->finished_at->toDateTimeString());
        $this->assertSame(['running', 3], [$step->fresh()->state, (int) $step->fresh()->consecutive_failures]);
        Queue::assertNothingPushed();
    }

    public function test_a_result_that_arrives_after_the_sweep_is_not_written_to_the_night_row(): void
    {
        $order = $this->order();
        $step  = $this->runningStep([$order->id]);
        Http::fake(['api.openai.com/v1/responses' => function () {
            // Habang nakabitin ang call, lumipas ang 11 minuto at dumaan ang tick.
            $this->at('03:11:00');
            $this->night()->tick();

            return Http::response($this->goodAnswer());
        }]);

        $row = $this->work($order->id);

        $this->assertSame(['failed', 'Worker stopped', null, false], [$row->state, $row->reason, $row->code, $row->proceed]);
        // Hindi rin nire-reset ng huling resulta ang breaker.
        $this->assertSame(['finished', 1], [$step->fresh()->state, (int) $step->fresh()->consecutive_failures]);
    }

    public function test_the_tick_never_requeues_a_row_and_dispatches_a_pending_row_once(): void
    {
        $this->at('03:30:00');
        $this->stepWithRows([
            ['queued', null, null],                                            // naipasok pero hindi na-dispatch (crash)
            ['queued', self::NIGHT . ' 03:29:30', null],                       // naghihintay ng retry delay
            ['running', self::NIGHT . ' 03:00:00', self::NIGHT . ' 03:29:00'], // tumatakbo pa
            ['running', self::NIGHT . ' 03:00:00', self::NIGHT . ' 03:10:00'], // naiwan ng worker
            ['failed', self::NIGHT . ' 03:00:00', self::NIGHT . ' 03:01:00'],
            ['not_run', self::NIGHT . ' 03:00:00', null],
            ['skipped', self::NIGHT . ' 03:00:00', self::NIGHT . ' 03:01:00'],
            ['done', self::NIGHT . ' 03:00:00', self::NIGHT . ' 03:01:00'],
        ]);
        $pendingId = NightAstraRow::orderBy('id')->value('id');

        foreach (['03:30:00', '03:31:00', '03:32:00'] as $time) {
            $this->at($time);
            $this->tick();
        }

        $this->assertSame(['queued', 'queued', 'running', 'failed', 'failed', 'not_run', 'skipped', 'done'], $this->rowStates());
        $this->assertSame([0, 0, 1, 1, 1, 1, 1, 1], NightAstraRow::orderBy('id')->pluck('attempts')->map(fn ($n) => (int) $n)->all());
        // Isang job lang sa tatlong tick: ang row na hindi pa na-dispatch. Ang may dispatched_at ay hindi ginagalaw.
        Queue::assertPushed(RunNightAstraRow::class, 1);
        Queue::assertPushedOn('astra', RunNightAstraRow::class, fn ($job) => $job->rowId === $pendingId && $job->delay === null);
        $this->assertSame(
            [self::NIGHT . ' 03:30:00', self::NIGHT . ' 03:29:30'],
            NightAstraRow::orderBy('id')->limit(2)->get()->map(fn ($row) => $row->dispatched_at->toDateTimeString())->all()
        );
        $this->assertSame('running', $this->step()->state);
    }

    public function test_at_the_stop_time_queued_rows_become_not_run_and_the_run_finishes_when_the_last_row_ends(): void
    {
        // Kahit naka-off ang switch, at kahit manual na run ng ibang gabi: binabantayan pa rin ang tumatakbong step.
        AppSetting::set('night_astra_enabled', '0');
        $this->at('06:59:00');
        $step = $this->stepWithRows([
            ['queued', null, null],
            ['queued', self::NIGHT . ' 06:58:30', null],
            ['running', self::NIGHT . ' 03:00:00', self::NIGHT . ' 06:59:00'],
            ['done', self::NIGHT . ' 03:00:00', self::NIGHT . ' 03:01:00'],
        ], ['night_date' => '2026-10-02', 'trigger' => 'manual']);

        $this->at('07:00:00');
        $this->tick();

        $this->assertSame(['not_run', 'not_run', 'running', 'done'], $this->rowStates());
        $this->assertSame(['Not run: out of time', 'Not run: out of time'], NightAstraRow::where('state', 'not_run')->pluck('reason')->all());
        $this->assertSame('running', $step->fresh()->state); // may row pang tumatakbo sa worker

        NightAstraRow::where('state', 'running')->update(['state' => 'done']);
        $this->at('07:01:00');
        $this->tick();

        $step->refresh();
        $this->assertSame(['finished', self::NIGHT . ' 07:01:00'], [$step->state, $step->finished_at->toDateTimeString()]);
        Queue::assertNothingPushed();
    }

    public function test_the_sweep_also_closes_rows_left_in_a_stopped_run(): void
    {
        $this->at('03:30:00');
        $step = $this->stepWithRows([
            ['running', self::NIGHT . ' 03:00:00', self::NIGHT . ' 03:19:00'], // naiwan ng worker
            ['running', self::NIGHT . ' 03:00:00', self::NIGHT . ' 03:25:00'], // tumatakbo pa: tatapusin ng worker
            ['queued', self::NIGHT . ' 03:29:30', null],                       // ibinalik para sa retry kasabay ng hinto
            ['done', self::NIGHT . ' 03:00:00', self::NIGHT . ' 03:01:00'],
        ], ['state' => 'stopped', 'reason' => 'Stopped: OpenAI credit or spend limit reached', 'consecutive_failures' => 4]);

        $this->tick();

        $this->assertSame(
            [['failed', 'Worker stopped'], ['running', null], ['not_run', 'Not run: run stopped'], ['done', null]],
            NightAstraRow::orderBy('id')->get()->map(fn ($row) => [$row->state, $row->reason])->all()
        );
        $step->refresh();
        $this->assertSame(
            ['stopped', 'Stopped: OpenAI credit or spend limit reached', 4],
            [$step->state, $step->reason, (int) $step->consecutive_failures]
        );
        Queue::assertNothingPushed();
    }

    public function test_a_throw_in_the_start_of_tonight_does_not_skip_the_sweeps(): void
    {
        $this->at('03:30:00');
        // Ibang gabi na tumatakbo pa, may row na naiwan ng worker.
        $this->stepWithRows([['running', self::NIGHT . ' 03:00:00', self::NIGHT . ' 03:10:00']], ['night_date' => '2026-10-02', 'trigger' => 'manual']);
        // Ngayong gabi: pwede nang magsimula, pero pumapalya ang pagpasok ng mga row (may marker ang exception message).
        $this->macroImport();
        $this->order();
        DB::statement("CREATE TRIGGER night_rows_fail BEFORE INSERT ON night_astra_rows BEGIN SELECT RAISE(ABORT, 'MARKER-lihim'); END");

        $this->tick();
        DB::statement('DROP TRIGGER night_rows_fail');

        $this->assertSame([['failed', 'Worker stopped']], NightAstraRow::get()->map(fn ($row) => [$row->state, $row->reason])->all());
        $this->assertSame(0, NightRunStep::where('night_date', self::NIGHT)->count()); // na-rollback ang start
        $line = collect($this->logLines)->firstWhere('message', 'NIGHT_ASTRA_TICK');
        $this->assertSame(['exception' => \Illuminate\Database\QueryException::class], $line['context'] ?? null);
        $this->assertStringNotContainsString('MARKER', json_encode($this->logLines->getArrayCopy()));
    }

    /** Binabasa ulit ang routes/console.php sa bagong Schedule, gaya ng ginagawa ng bawat `schedule:run`. */
    private function nightSchedule(): array
    {
        $this->app->forgetInstance(Schedule::class);
        ScheduleFacade::clearResolvedInstance(Schedule::class);
        require base_path('routes/console.php');

        $night = [];
        foreach ($this->app->make(Schedule::class)->events() as $event) {
            if (preg_match('/night:\S+.*$/', (string) $event->command, $m)) {
                $this->assertSame('Asia/Manila', (string) $event->timezone, $m[0]);
                $this->assertTrue($event->withoutOverlapping, $m[0]);
                $night[$m[0]] = [$event->expression, $event->expiresAt];
            }
        }

        return $night;
    }

    public function test_the_schedule_has_the_tick_when_the_switch_is_on_or_a_run_is_active(): void
    {
        // [Astra switch, state ng step (null = walang step), kasama ba ang tick]
        $cases = [
            'switch on, no step'          => ['1', null, true],
            'switch off, running step'    => ['0', 'running', true],
            'switch off, waiting step'    => ['0', 'waiting', true],
            'switch off, finished step'   => ['0', 'finished', false],
            'switch off, no step'         => ['0', null, false],
        ];

        foreach ($cases as $name => [$switch, $state, $scheduled]) {
            NightRunStep::query()->delete();
            AppSetting::set('night_astra_enabled', $switch);
            if ($state !== null) {
                $this->runningStep([], ['state' => $state, 'trigger' => 'manual']);
            }

            // Kada minuto; ang mutex ay 5 minuto lang, para hindi maharang nang isang araw ng tick na nag-crash.
            $this->assertSame($scheduled ? ['night:astra-tick' => ['* * * * *', 5]] : [], $this->nightSchedule(), $name);
        }
    }

    public function test_the_night_import_entries_hold_their_mutex_for_30_minutes(): void
    {
        AppSetting::set('night_astra_enabled', '0');
        AppSetting::set('night_macro_import_enabled', '1');
        AppSetting::set('night_likha_import_enabled', '1');

        $this->assertSame([
            'night:import macro 1' => ['0 1 * * *', 30],
            'night:import likha 1' => ['0 1 * * *', 30],
            'night:import macro 2' => ['0 2 * * *', 30],
            'night:import likha 2' => ['0 2 * * *', 30],
        ], $this->nightSchedule());
    }
}

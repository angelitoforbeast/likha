<?php

namespace Tests\Feature\NightRun;

use App\Jobs\RunNightAstraRow;
use App\Models\AppSetting;
use App\Models\NightAstraRow;
use App\Models\NightRunStep;
use App\Support\NightRunSettings;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/**
 * Ang apat na route ng night run (spec 007 §6.6, §8): CEO lang, validated ang bawat input.
 * PERA: dito lang (click ng CEO) bumabalik sa `queued` ang row na natapos na; ang `done` at `skipped` ay hindi kailanman.
 */
class NightRunRoutesTest extends NightAstraTestCase
{
    private const SETTINGS_URL = '/encoder/checker_1/settings/night';
    private const RUN_NOW_URL  = '/encoder/checker_1/ai-checker/night/run-now';
    private const LOGS_URL     = '/encoder/checker_1/ai-checker/logs';

    private const VALID_SETTINGS = [
        'night_macro_import_enabled' => '1',
        'night_likha_import_enabled' => '0',
        'night_astra_enabled'        => '1',
        'night_import_time_1'        => '01:30',
        'night_import_time_2'        => '02:30',
        'night_astra_time'           => '03:30',
        'night_astra_stop_time'      => '06:30',
        'night_astra_max_rows'       => '900',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Bawat view (pati ang 403/404 page) ay dumadaan sa composer ng AppServiceProvider na bumibilang ng tasks ng user.
        Schema::create('tasks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });
    }

    /** Isang step ng gabing ito na may tig-isang row kada [state, STATUS ng order]; ibinabalik ang step. */
    private function stepWithRows(string $state, array $rows, array $stepExtra = []): NightRunStep
    {
        $step = $this->runningStep([], array_merge(['state' => $state, 'finished_at' => now()], $stepExtra));
        foreach ($rows as $i => [$rowState, $status]) {
            NightAstraRow::create([
                'step_id'         => $step->id,
                'macro_output_id' => $this->order(['STATUS' => $status, 'TIMESTAMP' => sprintf('20:%02d 04-10-2026', $i)])->id,
                'state'           => $rowState,
                'attempts'        => 2,
                'reason'          => in_array($rowState, ['failed', 'not_run'], true) ? 'OpenAI server error (5xx)' : null,
                'dispatched_at'   => now(),
                'started_at'      => now(),
                'finished_at'     => now(),
            ]);
        }

        return $step;
    }

    private function snapshot(): array
    {
        return [NightRunSettings::read(), NightRunStep::orderBy('id')->get()->toArray(), NightAstraRow::orderBy('id')->get()->toArray()];
    }

    public function test_each_route_is_for_the_ceo_only(): void
    {
        $this->macroImport();
        $step      = $this->stepWithRows('finished', [['failed', null]]);
        $ceo       = $this->user('CEO');
        $marketing = $this->user('Marketing', 'marketing@example.test');

        // [method, url, body, status ng CEO]
        $routes = [
            'settings'     => ['post', self::SETTINGS_URL, self::VALID_SETTINGS, 302],
            'rows'         => ['get', "/encoder/checker_1/ai-checker/night/{$step->id}/rows", [], 200],
            'retry failed' => ['post', "/encoder/checker_1/ai-checker/night/{$step->id}/retry-failed", [], 302],
            'run now'      => ['post', self::RUN_NOW_URL, ['date' => self::ORDERS], 302],
        ];
        $before = $this->snapshot();

        foreach ($routes as $name => [$method, $url, $body]) {
            $this->{$method}($url, $body)->assertRedirect(route('login'));
            $this->actingAs($marketing)->{$method}($url, $body)->assertStatus(403);
            auth()->forgetGuards(); // balik sa guest para sa susunod na route
            $this->assertSame($before, $this->snapshot(), $name);
        }
        Queue::assertNothingPushed();

        foreach ($routes as $name => [$method, $url, $body, $status]) {
            $this->actingAs($ceo)->{$method}($url, $body)->assertStatus($status);
        }
        $this->assertNotSame($before, $this->snapshot());
    }

    public function test_the_ceo_saves_the_night_settings(): void
    {
        $response = $this->actingAs($this->user())->post(self::SETTINGS_URL, self::VALID_SETTINGS);

        $response->assertRedirect(route('encoder.checker1.settings'))->assertSessionHas('settings_saved', true);
        $this->assertSame([
            'night_macro_import_enabled' => true,
            'night_likha_import_enabled' => false,
            'night_astra_enabled'        => true,
            'night_import_time_1'        => '01:30',
            'night_import_time_2'        => '02:30',
            'night_astra_time'           => '03:30',
            'night_astra_stop_time'      => '06:30',
            'night_astra_max_rows'       => 900,
        ], NightRunSettings::read());

        // Checkbox na hindi naka-check = walang field sa form = off.
        $this->post(self::SETTINGS_URL, array_diff_key(self::VALID_SETTINGS, ['night_macro_import_enabled' => 1, 'night_astra_enabled' => 1]));
        $read = NightRunSettings::read();
        $this->assertSame([false, false, false], [$read['night_macro_import_enabled'], $read['night_likha_import_enabled'], $read['night_astra_enabled']]);
    }

    public function test_invalid_night_settings_are_refused_and_nothing_is_saved(): void
    {
        $this->actingAs($this->user());
        AppSetting::set('night_astra_enabled', '0');
        $before = NightRunSettings::read();

        // [binagong field(s), field na may error]
        $cases = [
            'bad time'              => [['night_astra_time' => '3:30'], 'night_astra_time'],
            'not a time'            => [['night_import_time_1' => '25:00'], 'night_import_time_1'],
            'time 1 equals time 2'  => [['night_import_time_1' => '02:30'], 'night_import_time_1'],
            'time 1 after time 2'   => [['night_import_time_1' => '02:31'], 'night_import_time_1'],
            'astra equals stop'     => [['night_astra_time' => '06:30'], 'night_astra_time'],
            'astra after stop'      => [['night_astra_stop_time' => '03:00'], 'night_astra_time'],
            'max rows 0'            => [['night_astra_max_rows' => '0'], 'night_astra_max_rows'],
            'max rows over 20000'   => [['night_astra_max_rows' => '20001'], 'night_astra_max_rows'],
            'max rows not a number' => [['night_astra_max_rows' => 'abc'], 'night_astra_max_rows'],
            'switch not a boolean'  => [['night_astra_enabled' => 'yes please'], 'night_astra_enabled'],
            'missing time'          => [['night_astra_stop_time' => ''], 'night_astra_stop_time'],
        ];

        foreach ($cases as $name => [$change, $errorField]) {
            $response = $this->from('/encoder/checker_1/settings')->post(self::SETTINGS_URL, array_merge(self::VALID_SETTINGS, $change));

            $response->assertRedirect('/encoder/checker_1/settings')->assertSessionHasErrors($errorField);
            $this->assertSame($before, NightRunSettings::read(), $name);
        }
    }

    public function test_run_now_refuses_a_date_that_is_not_yesterday_or_earlier(): void
    {
        $this->actingAs($this->user());
        $this->macroImport();
        $this->order(['ts_date' => self::NIGHT]);

        // Ngayon = 2026-10-05 03:00 Manila.
        foreach (['today' => self::NIGHT, 'future' => '2026-10-06', 'malformed' => '10/04/2026', 'not a date' => '2026-02-31', 'missing' => ''] as $name => $date) {
            $this->from(self::LOGS_URL)->post(self::RUN_NOW_URL, ['date' => $date])->assertRedirect(self::LOGS_URL)->assertSessionHasErrors('date');
        }

        $this->assertSame(0, NightRunStep::count());
        Queue::assertNothingPushed();
    }

    public function test_run_now_is_refused_with_the_reason(): void
    {
        $this->actingAs($this->user());
        $this->order();

        // [paghahanda, inaasahang mensahe]
        $cases = [
            'no finished import'   => [fn () => null, 'Not started: no finished macro import since midnight'],
            'macro import running' => [fn () => $this->macroImport('running', ['running']), 'Not started: an import was still running (macro)'],
            'likha import running' => [fn () => [$this->macroImport(), $this->likhaImport('running')], 'Not started: an import was still running (Likha)'],
            'step waiting'         => [fn () => [$this->macroImport(), $this->runningStep([], ['state' => 'waiting'])], 'Already running'],
            'step running'         => [fn () => [$this->macroImport(), $this->runningStep([])], 'Already running'],
        ];

        foreach ($cases as $name => [$arrange, $message]) {
            NightRunStep::query()->delete();
            DB::table('macro_import_runs')->delete();
            DB::table('likha_import_runs')->delete();
            $arrange();
            $before = $this->snapshot();

            $this->post(self::RUN_NOW_URL, ['date' => self::ORDERS])->assertRedirect(route('macro_checker.logs'))->assertSessionHas('error', $message);

            $this->assertSame($before, $this->snapshot(), $name);
        }

        // Walang API key: walang step na ginagawa (hindi "Stopped" na uubos sa nag-iisang run ng petsa).
        NightRunStep::query()->delete();
        $this->withoutApiKey(function () {
            $this->post(self::RUN_NOW_URL, ['date' => self::ORDERS])->assertSessionHas('error', 'Not started: no API key set');
        });
        $this->assertSame(0, NightRunStep::count());
        $this->assertSame(0, NightAstraRow::count());
        Queue::assertNothingPushed();
    }

    public function test_run_now_starts_a_date_that_has_no_run_as_a_manual_run(): void
    {
        $this->macroImport();
        $order = $this->order(['ts_date' => '2026-10-02']);
        $this->order(); // ibang petsa: hindi kasama
        $this->at('14:00:00');

        $response = $this->actingAs($this->user())->post(self::RUN_NOW_URL, ['date' => '2026-10-02']);

        $response->assertRedirect(route('macro_checker.logs'))->assertSessionHas('success', 'Started: 1 row queued for 2026-10-02');
        $step = NightRunStep::sole();
        $this->assertSame(
            ['2026-10-03', 'astra', 'running', 'manual', 1, '2026-10-06 07:00:00'],
            [substr((string) $step->night_date, 0, 10), $step->kind, $step->state, $step->trigger, (int) $step->rows_found, $step->stop_at->toDateTimeString()]
        );
        $this->assertSame([[$order->id, 'queued']], NightAstraRow::get()->map(fn ($row) => [(int) $row->macro_output_id, $row->state])->all());
        Queue::assertPushed(RunNightAstraRow::class, 1);
    }

    public function test_run_now_reopens_a_finished_date_once(): void
    {
        $this->macroImport();
        AppSetting::set('night_astra_max_rows', '7');
        $step = $this->stepWithRows('stopped', [
            ['failed', null],          // blangko pa → queued
            ['not_run', '  '],         // blangko pa (puro space) → queued
            ['failed', 'PROCEED'],     // may STATUS na mula sa tao → hindi ginagalaw
            ['done', null],            // tapos na ni Astra (hal. TO FIX, blangko pa rin) → hindi na uulitin
            ['skipped', null],
        ], ['reason' => 'Stopped: 10 rows failed in a row (last: OpenAI server error (5xx))', 'consecutive_failures' => 10, 'failure_streak_started_at' => self::NIGHT . ' 02:50:00', 'rows_found' => 5]);
        // Tatlong bagong blangkong order ng petsa na wala pa sa run; dalawa lang ang kasya sa maximum na 7.
        $new = [
            $this->order(['TIMESTAMP' => '21:17 04-10-2026']),
            $this->order(['TIMESTAMP' => '21:15 04-10-2026']),
            $this->order(['TIMESTAMP' => '21:16 04-10-2026']),
        ];
        $this->order(['STATUS' => 'PROCEED']);   // may STATUS: hindi idinadagdag
        $this->order(['ts_date' => '2026-10-03']); // ibang petsa
        $this->actingAs($this->user());

        $this->post(self::RUN_NOW_URL, ['date' => self::ORDERS])
            ->assertRedirect(route('macro_checker.logs'))->assertSessionHas('success', 'Re-opened: 4 rows queued for ' . self::ORDERS);

        $rows = NightAstraRow::orderBy('id')->get();
        $this->assertSame(['queued', 'queued', 'failed', 'done', 'skipped', 'queued', 'queued'], $rows->pluck('state')->all());
        $this->assertSame([$new[1]->id, $new[2]->id], [(int) $rows[5]->macro_output_id, (int) $rows[6]->macro_output_id]); // pinakaluma muna
        foreach ([0, 1] as $i) {
            $this->assertSame([0, null, null, null], [(int) $rows[$i]->attempts, $rows[$i]->reason, $rows[$i]->started_at, $rows[$i]->finished_at], "row {$i}");
            $this->assertNotNull($rows[$i]->dispatched_at, "row {$i}"); // na-dispatch ulit
        }
        $this->assertSame([2, 'OpenAI server error (5xx)'], [(int) $rows[2]->attempts, $rows[2]->reason]);
        $step->refresh();
        $this->assertSame(
            ['running', null, 0, null, null, self::NIGHT . ' 07:00:00', 8, 1],
            [$step->state, $step->reason, (int) $step->consecutive_failures, $step->failure_streak_started_at, $step->finished_at, $step->stop_at->toDateTimeString(), (int) $step->rows_found, (int) $step->rows_over_max]
        );
        Queue::assertPushed(RunNightAstraRow::class, 4);

        // Pangalawang click: walang pangalawang pagbukas, walang bagong job.
        $this->post(self::RUN_NOW_URL, ['date' => self::ORDERS])->assertSessionHas('error', 'Already running');
        Queue::assertPushed(RunNightAstraRow::class, 4);
        $this->assertSame(7, NightAstraRow::count());
    }

    public function test_run_now_on_a_date_with_nothing_left_finishes_at_once(): void
    {
        $this->macroImport();
        $step = $this->stepWithRows('did_not_run', [], ['reason' => 'Did not run: no finished macro import since midnight', 'started_at' => null, 'rows_found' => 0]);

        $this->actingAs($this->user())->post(self::RUN_NOW_URL, ['date' => self::ORDERS])
            ->assertSessionHas('success', 'Finished: nothing to run for ' . self::ORDERS);

        $step->refresh();
        $this->assertSame(['finished', null, 0], [$step->state, $step->reason, (int) $step->rows_found]);
        $this->assertSame(self::NIGHT . ' 03:00:00', $step->finished_at->toDateTimeString());
        Queue::assertNothingPushed();
    }

    public function test_retry_failed_takes_only_failed_and_not_run_rows_that_are_still_blank(): void
    {
        $step = $this->stepWithRows('finished', [
            ['failed', null],
            ['not_run', ''],
            ['failed', 'CANNOT PROCEED'],
            ['not_run', 'PROCEED'],
            ['done', null],
            ['skipped', null],
        ], ['consecutive_failures' => 3, 'rows_found' => 6]);
        $this->order(); // bagong blangkong order ng petsa: HINDI idinadagdag ng Retry failed
        $url = "/encoder/checker_1/ai-checker/night/{$step->id}/retry-failed";
        $this->actingAs($this->user());

        // Walang import condition dito: walang macro import na natapos, tumutuloy pa rin.
        $this->post($url)->assertRedirect(route('macro_checker.logs'))->assertSessionHas('success', 'Retrying 2 rows');

        $this->assertSame(['queued', 'queued', 'failed', 'not_run', 'done', 'skipped'], $this->rowStates());
        $this->assertSame([0, 0, 2, 2, 2, 2], NightAstraRow::orderBy('id')->pluck('attempts')->map(fn ($n) => (int) $n)->all());
        $step->refresh();
        $this->assertSame(
            ['running', null, 0, null, self::NIGHT . ' 07:00:00', 6],
            [$step->state, $step->reason, (int) $step->consecutive_failures, $step->finished_at, $step->stop_at->toDateTimeString(), (int) $step->rows_found]
        );
        Queue::assertPushed(RunNightAstraRow::class, 2);

        // Pangalawang click habang tumatakbo: tinatanggihan, walang bagong job.
        $this->post($url)->assertSessionHas('error', 'Nothing to retry: the run is still active');
        Queue::assertPushed(RunNightAstraRow::class, 2);
    }

    public function test_retry_failed_is_refused_when_there_is_nothing_to_retry(): void
    {
        $this->actingAs($this->user());

        // [state ng step, mga row, mensahe]
        $cases = [
            'did not run'           => ['did_not_run', [], 'Nothing to retry: the run did not run'],
            'waiting'               => ['waiting', [], 'Nothing to retry: the run is still active'],
            'all rows have a status' => ['stopped', [['failed', 'PROCEED'], ['done', null]], 'Nothing to retry: no failed or not-run row is still blank'],
        ];

        foreach ($cases as $name => [$state, $rows, $message]) {
            NightAstraRow::query()->delete();
            NightRunStep::query()->delete();
            $step   = $this->stepWithRows($state, $rows, ['reason' => 'Stopped: no API key set']);
            $before = $this->snapshot();

            $this->post("/encoder/checker_1/ai-checker/night/{$step->id}/retry-failed")->assertRedirect(route('macro_checker.logs'))->assertSessionHas('error', $message);

            $this->assertSame($before, $this->snapshot(), $name); // nananatiling "Stopped" kasama ang dahilan nito
        }
        Queue::assertNothingPushed();
    }

    public function test_a_step_that_is_not_an_astra_step_is_not_found(): void
    {
        $this->actingAs($this->user());
        $import = NightRunStep::create(['night_date' => self::NIGHT, 'kind' => 'macro_import_1', 'state' => 'started', 'trigger' => 'schedule']);

        foreach ([$import->id, 999999, 'abc'] as $id) {
            $this->post("/encoder/checker_1/ai-checker/night/{$id}/retry-failed")->assertStatus(404);
            $this->get("/encoder/checker_1/ai-checker/night/{$id}/rows")->assertStatus(404);
        }
    }

    public function test_the_rows_list_is_json_with_a_link_to_each_log_entry(): void
    {
        $step = $this->stepWithRows('finished', [['done', 'PROCEED'], ['failed', null]]);
        [$done, $failed] = NightAstraRow::orderBy('id')->get()->all();
        $logId = DB::table('ai_checker_logs')->insertGetId([
            'macro_output_id' => $done->macro_output_id, 'source' => 'night', 'outcome' => 'fixed',
            'created_at' => self::NIGHT . ' 03:00:20', 'updated_at' => self::NIGHT . ' 03:00:20',
        ]);
        $done->update(['code' => '✅', 'proceed' => true, 'log_id' => $logId, 'attempts' => 1, 'started_at' => self::NIGHT . ' 03:00:05', 'finished_at' => self::NIGHT . ' 03:00:20']);
        $failed->update(['started_at' => self::NIGHT . ' 03:01:00', 'finished_at' => self::NIGHT . ' 03:03:40']);
        // Text mula sa customer: data lang, ibinabalik nang hindi binabago (ang page ang mag-e-escape sa x-text).
        DB::table('macro_output')->where('id', $failed->macro_output_id)->update(['PAGE' => '<b>Shop</b>', 'ITEM_NAME' => '<script>x</script>']);

        $response = $this->actingAs($this->user())->get("/encoder/checker_1/ai-checker/night/{$step->id}/rows");

        $response->assertStatus(200);
        $this->assertSame([
            'ok'      => true,
            'step_id' => $step->id,
            'rows'    => [
                [
                    'id' => $done->id, 'macro_output_id' => (int) $done->macro_output_id, 'page' => 'Likha Shop', 'item' => 'Slimming Tea',
                    'state' => 'done', 'code' => '✅', 'proceed' => true, 'reason' => null, 'attempts' => 1,
                    'started_at' => '2026-10-05 03:00', 'finished_at' => '2026-10-05 03:00', 'log_id' => $logId,
                    'log_url' => route('macro_checker.answers', ['date' => '2026-10-05', 'mid' => $done->macro_output_id]),
                ],
                [
                    'id' => $failed->id, 'macro_output_id' => (int) $failed->macro_output_id, 'page' => '<b>Shop</b>', 'item' => '<script>x</script>',
                    'state' => 'failed', 'code' => null, 'proceed' => false, 'reason' => 'OpenAI server error (5xx)', 'attempts' => 2,
                    'started_at' => '2026-10-05 03:01', 'finished_at' => '2026-10-05 03:03', 'log_id' => null,
                    'log_url' => null,
                ],
            ],
        ], $response->json());
    }
}

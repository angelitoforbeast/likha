<?php

namespace Tests\Feature\NightRun;

use App\Models\AppSetting;
use App\Models\LikhaImportRun;
use App\Models\MacroImportRun;
use App\Models\MacroImportRunItem;
use App\Models\NightAstraRow;
use App\Models\NightRunStep;
use App\Services\NightRunSummary;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ang data ng "Night run" section (spec 007 §9, §10; amendment 007-1 items 8 at 16).
 * Ang gastos at ang mga mensahe ng pumalyang sheet (raw exception text) ay para sa CEO lang — wala sa array ng iba.
 */
class NightRunSummaryTest extends NightAstraTestCase
{
    private function importStep(string $kind, string $state, array $extra = [], string $night = self::NIGHT): NightRunStep
    {
        $step = NightRunStep::create(array_merge(['night_date' => $night, 'kind' => $kind, 'state' => $state, 'trigger' => 'schedule'], $extra));
        if (isset($extra['created_at'])) {
            DB::table('night_run_steps')->where('id', $step->id)->update(['created_at' => $extra['created_at']]);
        }

        return $step;
    }

    /** Mga row ng step: [state, proceed, code, reason, cost, model ng log entry (null = walang log), started_at, finished_at]. */
    private function rows(NightRunStep $step, array $rows): void
    {
        foreach ($rows as $row) {
            [$state, $proceed, $code, $reason, $cost, $model, $startedAt, $finishedAt] = array_pad($row, 8, null);
            $orderId = $this->order()->id;
            $logId   = $model === null ? null : DB::table('ai_checker_logs')->insertGetId([
                'macro_output_id' => $orderId, 'source' => 'night', 'model' => $model, 'created_at' => now(), 'updated_at' => now(),
            ]);
            NightAstraRow::create([
                'step_id' => $step->id, 'macro_output_id' => $orderId, 'state' => $state, 'proceed' => (bool) $proceed, 'code' => $code,
                'reason' => $reason, 'cost_usd' => $cost, 'log_id' => $logId, 'started_at' => $startedAt, 'finished_at' => $finishedAt,
            ]);
        }
    }

    public function test_the_astra_counts_add_up_and_the_cost_is_only_in_the_ceos_data(): void
    {
        $this->at('09:00:00');
        $step = $this->runningStep([], [
            'state' => 'stopped', 'reason' => 'Stopped: OpenAI credit or spend limit reached', 'rows_found' => 12, 'rows_over_max' => 2,
            'started_at' => self::NIGHT . ' 03:00:00', 'finished_at' => self::NIGHT . ' 03:40:00',
        ]);
        $this->rows($step, [
            ['done', true, '✅', null, 0.035, 'gpt-6-luna'],
            ['done', true, '✅', null, 0.035, 'gpt-6-luna'],
            ['done', false, 'TO FIX', null, 0.02, 'gpt-6-luna'],
            ['done', false, 'TO FIX', null, 0.02, 'gpt-6-luna'],
            ['done', false, 'Barangay', null, 0.02, 'gpt-6-luna'],
            ['failed', false, null, 'No usable answer from the AI', 0.01, 'gpt-6-luna'],
            ['skipped', false, null, 'No chat text'],
            ['skipped', false, null, 'Status set by a person'],
            ['not_run', false, null, 'Not run: run stopped'],
            ['not_run', false, null, 'Not run: run stopped'],
        ]);

        $ceo   = NightRunSummary::build(true)['nights'];
        $other = NightRunSummary::build(false)['nights'];

        $this->assertSame([[
            'night_date'  => self::NIGHT,
            'orders_date' => self::ORDERS,
            'imports'     => [],
            'astra'       => [
                'step_id'            => $step->id,
                'status'             => 'stopped',
                'state'              => 'Stopped',
                'reason'             => 'Stopped: OpenAI credit or spend limit reached',
                'trigger'            => 'schedule',
                'rows_found'         => 12,
                'proceed'            => 2,
                'for_person'         => 3,
                'for_person_by_code' => ['TO FIX' => 2, 'Barangay' => 1],
                'failed'             => 1,
                'skipped'            => 2,
                'no_chat_text'       => 1,
                'not_run'            => 2,
                'over_max'           => 2,
                'queued'             => 0,
                'running'            => 0,
                'started_at'         => '2026-10-05 03:00',
                'finished_at'        => '2026-10-05 03:40',
                'duration_seconds'   => 2400,
                'can_retry'          => true,
                'cost_usd'           => 0.14,
                'cost_complete'      => true,
            ],
        ]], $ceo);
        $astra = $ceo[0]['astra'];
        // found = PROCEED + para sa tao + failed + skipped + not run + lampas sa maximum (+ queued + running)
        $this->assertSame(
            $astra['rows_found'],
            $astra['proceed'] + $astra['for_person'] + $astra['failed'] + $astra['skipped'] + $astra['not_run'] + $astra['over_max'] + $astra['queued'] + $astra['running']
        );

        // Hindi CEO: parehong data, pero WALA ang mga key ng gastos (hindi null).
        $this->assertSame(array_diff_key($astra, ['cost_usd' => 1, 'cost_complete' => 1]), $other[0]['astra']);

        // Model na walang presyo sa config → hindi kumpleto ang gastos.
        DB::table('ai_checker_logs')->limit(1)->update(['model' => 'gpt-6-luna,gpt-walang-presyo']);
        $this->assertFalse(NightRunSummary::build(true)['nights'][0]['astra']['cost_complete']);
    }

    public function test_the_astra_state_says_when_no_worker_is_taking_rows(): void
    {
        $this->at('03:30:00');
        $ago = fn (int $minutes) => now()->subMinutes($minutes)->toDateTimeString();

        // [mga row, inaasahang status, inaasahang state]
        $cases = [
            'queued, last row ended 4 minutes ago' => [[['queued'], ['done', true, '✅', null, null, null, $ago(5), $ago(4)]], 'waiting_for_worker', 'Waiting for the worker'],
            'queued, nothing ever started'         => [[['queued']], 'waiting_for_worker', 'Waiting for the worker'],
            'queued, a row ended 2 minutes ago'    => [[['queued'], ['done', true, '✅', null, null, null, $ago(5), $ago(2)]], 'running', 'Running'],
            'queued, a row started 1 minute ago'   => [[['queued'], ['failed', false, null, 'x', null, null, $ago(1), null]], 'running', 'Running'],
            'queued, a row is running'             => [[['queued'], ['running', false, null, null, null, null, $ago(9)]], 'running', 'Running'],
        ];

        foreach ($cases as $name => [$rows, $status, $state]) {
            NightAstraRow::query()->delete();
            NightRunStep::query()->delete();
            $this->rows($this->runningStep([]), $rows);

            $astra = NightRunSummary::build(false)['nights'][0]['astra'];

            $this->assertSame([$status, $state, false], [$astra['status'], $astra['state'], $astra['can_retry']], $name);
        }
    }

    public function test_the_imports_of_a_night_are_read_live_from_their_runs(): void
    {
        $long = str_repeat('x', 250);
        // Gabi 1: macro na may pumalyang sheet (failed ang run, pero may sheet na done), Likha na done, skipped, hindi nasimulan.
        $withFailed = MacroImportRun::create(['status' => 'failed', 'total_processed' => 100, 'total_inserted' => 80, 'total_updated' => 20, 'message' => 'Some sheets failed']);
        MacroImportRunItem::create(['run_id' => $withFailed->id, 'status' => 'done', 'gsheet_name' => 'Sheet A']);
        MacroImportRunItem::create(['run_id' => $withFailed->id, 'status' => 'failed', 'gsheet_name' => 'Sheet B', 'message' => $long]);
        MacroImportRunItem::create(['run_id' => $withFailed->id, 'status' => 'failed', 'gsheet_name' => 'Sheet C', 'message' => null]);
        $likhaDone = LikhaImportRun::create(['status' => 'done', 'total_processed' => 7, 'total_inserted' => 3, 'total_updated' => 4, 'message' => null]);
        // Nakalagay nang wala sa ayos ng oras: ang summary ang nag-aayos (macro 1, Likha 1, macro 2, Likha 2).
        $this->importStep('likha_import_2', 'failed', ['reason' => 'Failed to start: RuntimeException', 'created_at' => self::NIGHT . ' 02:00:03']);
        $this->importStep('macro_import_2', 'skipped', ['reason' => 'Skipped: run #1 was still running', 'created_at' => self::NIGHT . ' 02:00:02']);
        $this->importStep('likha_import_1', 'started', ['ref_id' => $likhaDone->id, 'started_at' => self::NIGHT . ' 01:00:05']);
        $this->importStep('macro_import_1', 'started', ['ref_id' => $withFailed->id, 'started_at' => self::NIGHT . ' 01:00:04']);
        // Gabi 2: macro na tumatakbo pa, macro na failed nang walang sheet na done, Likha na failed.
        $running = MacroImportRun::create(['status' => 'running']);
        $failed  = MacroImportRun::create(['status' => 'failed', 'message' => 'Stale: closed by the night run']);
        MacroImportRunItem::create(['run_id' => $failed->id, 'status' => 'failed', 'gsheet_name' => 'Sheet A', 'message' => 'boom']);
        $likhaFailed = LikhaImportRun::create(['status' => 'failed', 'message' => 'Stale: closed at the next start']);
        $this->importStep('macro_import_1', 'started', ['ref_id' => $running->id, 'started_at' => self::ORDERS . ' 01:00:00'], self::ORDERS);
        $this->importStep('macro_import_2', 'started', ['ref_id' => $failed->id, 'started_at' => self::ORDERS . ' 02:00:00'], self::ORDERS);
        $this->importStep('likha_import_2', 'started', ['ref_id' => $likhaFailed->id, 'started_at' => self::ORDERS . ' 02:00:00'], self::ORDERS);

        $nights = NightRunSummary::build(true)['nights'];

        $this->assertSame([self::NIGHT, self::ORDERS], array_column($nights, 'night_date'));
        $this->assertNull($nights[0]['astra']);
        $this->assertSame([
            [
                'kind' => 'macro_import_1', 'label' => 'Macro import', 'time' => '01:00', 'status' => 'done_with_failures', 'state' => 'Done with 2 failed sheets',
                'run_id' => $withFailed->id, 'processed' => 100, 'inserted' => 80, 'updated' => 20, 'message' => 'Some sheets failed',
                'failed_sheets' => [['name' => 'Sheet B', 'message' => str_repeat('x', 200)], ['name' => 'Sheet C', 'message' => null]],
            ],
            [
                'kind' => 'likha_import_1', 'label' => 'Likha import', 'time' => '01:00', 'status' => 'done', 'state' => 'Done',
                'run_id' => $likhaDone->id, 'processed' => 7, 'inserted' => 3, 'updated' => 4, 'message' => null, 'failed_sheets' => [],
            ],
            [
                'kind' => 'macro_import_2', 'label' => 'Macro import', 'time' => '02:00', 'status' => 'skipped', 'state' => 'Skipped',
                'run_id' => null, 'processed' => 0, 'inserted' => 0, 'updated' => 0, 'message' => 'Skipped: run #1 was still running', 'failed_sheets' => [],
            ],
            [
                'kind' => 'likha_import_2', 'label' => 'Likha import', 'time' => '02:00', 'status' => 'failed', 'state' => 'Failed',
                'run_id' => null, 'processed' => 0, 'inserted' => 0, 'updated' => 0, 'message' => 'Failed to start: RuntimeException', 'failed_sheets' => [],
            ],
        ], $nights[0]['imports']);
        $this->assertSame(
            [['running', 'Running', null], ['failed', 'Failed', 'Stale: closed by the night run'], ['failed', 'Failed', 'Stale: closed at the next start']],
            array_map(fn ($import) => [$import['status'], $import['state'], $import['message']], $nights[1]['imports'])
        );

        // Hindi CEO: mga pangalan lang ng sheet; wala ang key ng mensahe (raw exception text iyon).
        $this->assertSame(
            [['name' => 'Sheet B'], ['name' => 'Sheet C']],
            NightRunSummary::build(false)['nights'][0]['imports'][0]['failed_sheets']
        );
    }

    public function test_only_the_last_14_nights_are_listed_newest_first(): void
    {
        for ($day = 1; $day <= 16; $day++) {
            $this->importStep('macro_import_1', 'skipped', [], sprintf('2026-09-%02d', $day));
        }

        $dates = array_column(NightRunSummary::build(false)['nights'], 'night_date');

        $this->assertSame(['2026-09-16', '2026-09-03', 14], [$dates[0], $dates[13], count($dates)]);
    }

    public function test_the_banner_names_each_switched_on_step_that_went_wrong_last_night(): void
    {
        $doneRun = fn () => tap(MacroImportRun::create(['status' => 'done']), fn ($run) => MacroImportRunItem::create(['run_id' => $run->id, 'status' => 'done']));
        $allWell = function () use ($doneRun) {
            $this->importStep('macro_import_1', 'started', ['ref_id' => $doneRun()->id]);
            $this->importStep('likha_import_1', 'started', ['ref_id' => LikhaImportRun::create(['status' => 'done'])->id]);
            $this->importStep('macro_import_2', 'skipped', ['reason' => 'Skipped: run #1 was still running']);
            $this->importStep('likha_import_2', 'started', ['ref_id' => LikhaImportRun::create(['status' => 'running'])->id]);
            $this->runningStep([], ['state' => 'finished']);
        };
        $wentWrong = function () {
            $run = MacroImportRun::create(['status' => 'failed']);
            MacroImportRunItem::create(['run_id' => $run->id, 'status' => 'done']);
            MacroImportRunItem::create(['run_id' => $run->id, 'status' => 'failed', 'gsheet_name' => 'Sheet B']);
            $this->importStep('macro_import_1', 'started', ['ref_id' => $run->id]);
            $this->importStep('likha_import_1', 'failed', ['reason' => 'Failed to start: RuntimeException']);
            $this->importStep('likha_import_2', 'started', ['ref_id' => LikhaImportRun::create(['status' => 'failed'])->id]);
            $this->runningStep([], ['state' => 'stopped', 'reason' => 'Stopped: 10 rows failed in a row (last: OpenAI server error (5xx))']);
        };
        $on = ['night_macro_import_enabled' => '1', 'night_likha_import_enabled' => '1', 'night_astra_enabled' => '1'];

        // [oras ngayon (Manila), mga switch, paghahanda, inaasahang mga linya]. Mga oras: 01:00, 02:00, Astra 03:00.
        $cases = [
            'all is well' => ['03:10:00', $on, $allWell, []],
            'failed, failed sheets, stopped, and one step with no record' => ['03:10:00', $on, $wentWrong, [
                'Night macro import 01:00: Done with 1 failed sheet',
                'Night Likha import 01:00: Failed',
                'Night macro import 02:00 has no record: is the scheduler running?',
                'Night Likha import 02:00: Failed',
                'Night Astra 03:00: Stopped: 10 rows failed in a row (last: OpenAI server error (5xx))',
            ]],
            'the same night with every switch off' => ['03:10:00', [], $wentWrong, []],
            'only the Astra switch on' => ['03:10:00', ['night_astra_enabled' => '1'], $wentWrong, [
                'Night Astra 03:00: Stopped: 10 rows failed in a row (last: OpenAI server error (5xx))',
            ]],
            'did not run' => ['04:05:00', ['night_astra_enabled' => '1'], fn () => $this->runningStep([], ['state' => 'did_not_run', 'reason' => 'Did not run: no finished macro import since midnight']), [
                'Night Astra 03:00: Did not run: no finished macro import since midnight',
            ]],
            'no record at all, more than 5 minutes after each time' => ['03:06:00', $on, fn () => null, [
                'Night macro import 01:00 has no record: is the scheduler running?',
                'Night Likha import 01:00 has no record: is the scheduler running?',
                'Night macro import 02:00 has no record: is the scheduler running?',
                'Night Likha import 02:00 has no record: is the scheduler running?',
                'Night Astra 03:00 has no record: is the scheduler running?',
            ]],
            'no record yet, 5 minutes after the Astra time' => ['03:05:00', ['night_astra_enabled' => '1'], fn () => null, []],
            // 00:30: hindi pa dumarating ang mga oras ng gabing ito → ang kagabi (night_date = kahapon) ang tinitingnan.
            'before tonight\'s times: last night' => ['00:30:00', ['night_astra_enabled' => '1'], fn () => $this->runningStep([], ['night_date' => self::ORDERS, 'state' => 'stopped', 'reason' => 'Stopped: no API key set']), [
                'Night Astra 03:00: Stopped: no API key set',
            ]],
        ];

        foreach ($cases as $name => [$time, $switches, $arrange, $lines]) {
            NightRunStep::query()->delete();
            foreach (array_keys($on) as $key) {
                AppSetting::set($key, $switches[$key] ?? '0');
            }
            $this->at($time);
            $arrange();

            $this->assertSame($lines, NightRunSummary::build(false)['banner'], $name);
        }
    }

    public function test_the_logs_page_and_the_settings_page_get_the_night_data(): void
    {
        $this->withoutVite();
        // Bawat view ay dumadaan sa composer ng AppServiceProvider na bumibilang ng tasks ng user.
        Schema::create('tasks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });
        $step = $this->runningStep([], ['state' => 'finished']);
        $this->rows($step, [['done', true, '✅', null, 0.035, 'gpt-6-luna']]);
        AppSetting::set('night_astra_time', '03:30');

        // [role, kita ba ang gastos at ang settings]
        foreach (['Marketing' => false, 'CEO' => true] as $role => $isCeo) {
            $user = $this->user($role, strtolower($role) . '@example.test');

            $logs = $this->actingAs($user)->get('/encoder/checker_1/ai-checker/logs')->assertStatus(200);
            $this->assertSame($isCeo, array_key_exists('cost_usd', $logs->viewData('night')['nights'][0]['astra']), $role);
            $this->assertSame($isCeo ? '03:30' : null, $logs->viewData('nightSettings')['night_astra_time'] ?? null, $role);

            $settings = $this->get('/encoder/checker_1/settings')->assertStatus(200);
            $this->assertSame($isCeo ? '03:30' : null, $settings->viewData('night')['night_astra_time'] ?? null, $role);
        }
    }
}

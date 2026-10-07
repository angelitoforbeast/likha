<?php

namespace App\Services;

use App\Models\NightRunStep;
use App\Support\NightRunSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ang data ng "Night run" section sa AI Checker logs page: huling 14 na gabi at ang banner.
 *
 * CEO-only data ay dito hinaharang, hindi sa view: kapag hindi CEO, WALA sa array ang gastos (`cost_usd`,
 * `cost_complete`) at ang mga mensahe ng pumalyang sheet (raw exception text ng import job).
 * Iilang query lang: grouped ang bilang ng mga row, walang query kada row o kada gabi.
 */
class NightRunSummary
{
    public const NIGHTS = 14;

    /** Mensahe ng pumalyang sheet: hanggang ilang character ang ipinapakita (sa CEO lang). */
    private const SHEET_MESSAGE_MAX = 200;

    /** "Waiting for the worker": walang row na nagsimula o natapos, at walang galaw ang step, sa loob ng ganito karaming minuto. */
    private const WORKER_QUIET_MINUTES = 3;

    /** Ilang minuto pagkalipas ng oras ng isang step bago sabihing "has no record". */
    private const NO_RECORD_MINUTES = 5;

    /** Mga import step ayon sa oras: kind => [label, setting ng oras, switch]. */
    private const IMPORTS = [
        'macro_import_1' => ['Macro import', 'night_import_time_1', 'night_macro_import_enabled'],
        'likha_import_1' => ['Likha import', 'night_import_time_1', 'night_likha_import_enabled'],
        'macro_import_2' => ['Macro import', 'night_import_time_2', 'night_macro_import_enabled'],
        'likha_import_2' => ['Likha import', 'night_import_time_2', 'night_likha_import_enabled'],
    ];

    private const ASTRA_STATES = [
        'waiting'            => 'Waiting',
        'waiting_for_worker' => 'Waiting for the worker',
        'running'            => 'Running',
        'finished'           => 'Finished',
        'stopped'            => 'Stopped',
        'did_not_run'        => 'Did not run',
    ];

    /**
     * @return array{nights: array<int, array>, banner: string[]}
     */
    public static function build(bool $isCeo): array
    {
        if (!Schema::hasTable('night_run_steps') || !Schema::hasTable('night_astra_rows')) {
            return ['nights' => [], 'banner' => []];
        }

        $dates = NightRunStep::query()->select('night_date')->distinct()->orderByDesc('night_date')->limit(self::NIGHTS)
            ->pluck('night_date')->map(fn ($date) => substr((string) $date, 0, 10))->all();
        $steps = $dates === [] ? collect() : NightRunStep::whereIn('night_date', $dates)->orderBy('id')->get();

        $imports = self::imports($steps->where('kind', '!=', NightAstraRun::KIND), $isCeo);
        $astra   = self::astra($steps->where('kind', NightAstraRun::KIND), $isCeo);

        $nights = [];
        foreach ($dates as $date) {
            $nights[$date] = [
                'night_date'  => $date,
                'orders_date' => NightAstraRun::ordersDate($date),
                'imports'     => array_values(array_filter(array_map(fn ($kind) => $imports[$date][$kind] ?? null, array_keys(self::IMPORTS)))),
                'astra'       => $astra[$date] ?? null,
            ];
        }

        return ['nights' => array_values($nights), 'banner' => self::banner($nights)];
    }

    /**
     * Ang mga import step, kada [gabi][kind]. Ang resulta (bilang, mensahe ng run) ay binabasa nang live mula sa
     * run (ref_id); ang step na skipped o hindi nasimulan ay ang sarili nitong dahilan.
     */
    private static function imports($steps, bool $isCeo): array
    {
        $refs = fn (string $prefix) => $steps->filter(fn ($step) => str_starts_with($step->kind, $prefix) && $step->ref_id)->pluck('ref_id')->all();

        $macroIds  = $refs('macro');
        $macroRuns = $macroIds === [] ? collect() : DB::table('macro_import_runs')->whereIn('id', $macroIds)
            ->get(['id', 'status', 'total_processed', 'total_inserted', 'total_updated', 'message'])->keyBy('id');
        $likhaIds  = $refs('likha');
        $likhaRuns = $likhaIds === [] ? collect() : DB::table('likha_import_runs')->whereIn('id', $likhaIds)
            ->get(['id', 'status', 'total_processed', 'total_inserted', 'total_updated', 'message'])->keyBy('id');

        // Mga sheet ng macro run: ilan ang done (isang grouped query), at alin ang pumalya.
        $sheetsDone   = [];
        $sheetsFailed = [];
        if ($macroIds !== []) {
            $sheetsDone = DB::table('macro_import_run_items')->whereIn('run_id', $macroIds)->where('status', 'done')
                ->groupBy('run_id')->selectRaw('run_id, COUNT(*) AS c')->pluck('c', 'run_id')->all();
            $failed = DB::table('macro_import_run_items')->whereIn('run_id', $macroIds)->where('status', 'failed')
                ->orderBy('id')->get(['run_id', 'gsheet_name', 'message']);
            foreach ($failed as $sheet) {
                // Ang mensahe ay raw exception text ng job: sa CEO lang, at pinutol.
                $sheetsFailed[$sheet->run_id][] = ['name' => (string) $sheet->gsheet_name]
                    + ($isCeo ? ['message' => $sheet->message === null ? null : mb_substr((string) $sheet->message, 0, self::SHEET_MESSAGE_MAX)] : []);
            }
        }

        $out = [];
        foreach ($steps as $step) {
            if (!isset(self::IMPORTS[$step->kind])) {
                continue;
            }
            $isMacro = str_starts_with($step->kind, 'macro');
            $run     = $step->ref_id ? ($isMacro ? $macroRuns : $likhaRuns)->get($step->ref_id) : null;
            $failed  = $isMacro && $run ? ($sheetsFailed[$run->id] ?? []) : [];
            $done    = $isMacro && $run ? (int) ($sheetsDone[$run->id] ?? 0) : 0;

            [$status, $message] = match (true) {
                $step->state === 'skipped'                                => ['skipped', $step->reason],
                $step->state === 'failed'                                 => ['failed', $step->reason],
                $run === null                                             => ['failed', 'Import run not found'],
                in_array($run->status, ['queued', 'running'], true)       => ['running', $run->message],
                // May pumalyang sheet pero may natapos din: tumuloy ang Astra sa kung ano ang naroon.
                $failed !== [] && $done > 0                               => ['done_with_failures', $run->message],
                $run->status === 'done'                                   => ['done', $run->message],
                default                                                   => ['failed', $run->message],
            };

            $out[substr((string) $step->night_date, 0, 10)][$step->kind] = [
                'kind'          => $step->kind,
                'label'         => self::IMPORTS[$step->kind][0],
                'time'          => self::manila($step->started_at ?? $step->created_at, 'H:i'),
                'status'        => $status,
                'state'         => match ($status) {
                    'done_with_failures' => 'Done with ' . count($failed) . (count($failed) === 1 ? ' failed sheet' : ' failed sheets'),
                    default              => ucfirst($status),
                },
                'run_id'        => $run ? (int) $run->id : null,
                'processed'     => (int) ($run->total_processed ?? 0),
                'inserted'      => (int) ($run->total_inserted ?? 0),
                'updated'       => (int) ($run->total_updated ?? 0),
                'message'       => $message,
                'failed_sheets' => $status === 'done_with_failures' ? $failed : [],
            ];
        }

        return $out;
    }

    /** Ang Astra step kada gabi, na may mga bilang na nagtutugma sa `rows_found`. */
    private static function astra($steps, bool $isCeo): array
    {
        $ids = $steps->pluck('id')->all();
        if ($ids === []) {
            return [];
        }

        // Isang grouped query para sa lahat ng bilang ng lahat ng gabi.
        $groups = DB::table('night_astra_rows')->whereIn('step_id', $ids)
            ->groupBy('step_id', 'state', 'proceed', 'code')
            ->selectRaw('step_id, state, proceed, code, COUNT(*) AS c, SUM(cost_usd) AS cost, MAX(started_at) AS last_started, MAX(finished_at) AS last_finished')
            ->get()->groupBy('step_id');
        $noChat = DB::table('night_astra_rows')->whereIn('step_id', $ids)->where('state', 'skipped')->where('reason', 'No chat text')
            ->groupBy('step_id')->selectRaw('step_id, COUNT(*) AS c')->pluck('c', 'step_id')->all();

        // Gastos (CEO lang). Ang model ay mula sa log entry ng mga row; kapag wala pa, ang kasalukuyang engine setting.
        $prices = (array) config('services.openai.ai_checker_prices', []);
        $models = [];
        if ($isCeo && Schema::hasTable('ai_checker_logs')) {
            $used = DB::table('night_astra_rows as r')->join('ai_checker_logs as l', 'l.id', '=', 'r.log_id')
                ->whereIn('r.step_id', $ids)->whereNotNull('l.model')->select('r.step_id', 'l.model')->distinct()->get();
            foreach ($used as $row) {
                foreach (array_filter(array_map('trim', explode(',', (string) $row->model))) as $model) {
                    $models[$row->step_id][$model] = true;
                }
            }
        }
        $engineModel = $isCeo ? (string) AstraEncoder::engineSettings()['model'] : '';

        $out = [];
        foreach ($steps as $step) {
            $rows   = $groups->get($step->id, collect());
            $count  = fn (callable $where) => (int) $rows->filter($where)->sum('c');
            $byCode = [];
            foreach ($rows->filter(fn ($g) => $g->state === 'done' && !$g->proceed) as $group) {
                $code          = (string) $group->code;
                $byCode[$code] = ($byCode[$code] ?? 0) + (int) $group->c;
            }
            arsort($byCode);

            $queued   = $count(fn ($g) => $g->state === 'queued');
            $running  = $count(fn ($g) => $g->state === 'running');
            // Aktibidad din ang simula ng run at ang huling galaw ng step (hal. binuksan ulit): walang "Waiting for
            // the worker" sa mga unang minuto pagkatapos ng start o ng Run now / Retry failed.
            $lastSeen = collect([$rows->max('last_started'), $rows->max('last_finished'), $step->started_at, $step->updated_at])
                ->filter()->map(fn ($time) => Carbon::parse($time))->max();
            $quiet    = $lastSeen === null || $lastSeen->lt(now()->subMinutes(self::WORKER_QUIET_MINUTES));
            $status   = $step->state === 'running' && $queued > 0 && $running === 0 && $quiet ? 'waiting_for_worker' : (string) $step->state;
            $failed   = $count(fn ($g) => $g->state === 'failed');
            $notRun   = $count(fn ($g) => $g->state === 'not_run');

            $entry = [
                'step_id'            => (int) $step->id,
                'status'             => $status,
                'state'              => self::ASTRA_STATES[$status] ?? $status,
                'reason'             => $step->reason,
                'trigger'            => $step->trigger,
                'rows_found'         => (int) $step->rows_found,
                'proceed'            => $count(fn ($g) => $g->state === 'done' && $g->proceed),
                'for_person'         => array_sum($byCode),
                'for_person_by_code' => $byCode,
                'failed'             => $failed,
                'skipped'            => $count(fn ($g) => $g->state === 'skipped'), // kasama ang "No chat text"
                'no_chat_text'       => (int) ($noChat[$step->id] ?? 0),
                'not_run'            => $notRun,
                'over_max'           => (int) $step->rows_over_max,
                'max_rows'           => (int) NightRunSettings::read()['night_astra_max_rows'],
                'queued'             => $queued,
                'running'            => $running,
                'started_at'         => self::manila($step->started_at, 'Y-m-d H:i'),
                'finished_at'        => self::manila($step->finished_at, 'Y-m-d H:i'),
                'duration_seconds'   => $step->started_at ? (int) $step->started_at->diffInSeconds($step->finished_at ?? now(), true) : null,
                'can_retry'          => in_array($step->state, ['finished', 'stopped'], true) && ($failed + $notRun) > 0,
            ];

            if ($isCeo) {
                $used = array_keys($models[$step->id] ?? [$engineModel => true]);
                $entry['cost_usd']      = round((float) $rows->sum('cost'), 4);
                // False = may model na walang presyo sa config: mababa ang lalabas na gastos kaysa sa totoo.
                $entry['cost_complete'] = array_diff($used, array_keys($prices)) === [];
            }

            $out[substr((string) $step->night_date, 0, 10)] = $entry;
        }

        return $out;
    }

    /**
     * Mga linya ng pulang banner: bawat step na naka-on na pumalya, nahinto, hindi tumakbo, may pumalyang sheet,
     * o WALANG record kahit lampas na ang oras nito nang higit 5 minuto. Ang gabing tinitingnan kada step ay ang
     * pinakahuling pagdating ng oras nito (Manila). Fixed strings lang ang laman.
     *
     * @param array<string, array> $nights kada night_date
     * @return string[]
     */
    private static function banner(array $nights): array
    {
        $settings = NightRunSettings::read();
        $now      = now(NightAstraRun::TZ);
        // [petsa ng gabi ng pinakahuling pagdating ng oras, lampas na ba nang higit 5 minuto]
        $latest = function (string $time) use ($now) {
            $at = Carbon::parse($now->toDateString() . ' ' . $time, NightAstraRun::TZ);
            if ($at->gt($now)) {
                $at->subDay();
            }

            return [$at->toDateString(), $at->copy()->addMinutes(self::NO_RECORD_MINUTES)->lt($now)];
        };
        $noRecord = fn (string $name, bool $overdue) => $overdue ? ["{$name} has no record: is the scheduler running?"] : [];

        $lines = [];
        foreach (self::IMPORTS as $kind => [$label, $timeKey, $switch]) {
            if (!$settings[$switch]) {
                continue;
            }
            [$date, $overdue] = $latest($settings[$timeKey]);
            $name   = 'Night ' . ($label === 'Macro import' ? 'macro import' : $label) . ' ' . $settings[$timeKey];
            $import = collect($nights[$date]['imports'] ?? [])->firstWhere('kind', $kind);

            if ($import === null) {
                $lines = array_merge($lines, $noRecord($name, $overdue));
            } elseif (in_array($import['status'], ['failed', 'done_with_failures'], true)) {
                $lines[] = "{$name}: {$import['state']}";
            }
        }

        if ($settings['night_astra_enabled']) {
            [$date, $overdue] = $latest($settings['night_astra_time']);
            $name  = 'Night Astra ' . $settings['night_astra_time'];
            $astra = $nights[$date]['astra'] ?? null;

            if ($astra === null) {
                $lines = array_merge($lines, $noRecord($name, $overdue));
            } elseif (in_array($astra['status'], ['stopped', 'did_not_run'], true)) {
                $lines[] = "{$name}: " . ($astra['reason'] ?: $astra['state']);
            }
        }

        return $lines;
    }

    /** Timestamp ng app (wall time sa default timezone) → Manila, sa format na ibinigay. */
    private static function manila($time, string $format): ?string
    {
        return $time ? Carbon::parse($time)->timezone(NightAstraRun::TZ)->format($format) : null;
    }
}

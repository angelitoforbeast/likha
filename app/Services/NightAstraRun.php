<?php

namespace App\Services;

use App\Jobs\RunNightAstraRow;
use App\Models\LikhaImportRun;
use App\Models\MacroImportRun;
use App\Models\MacroOutput;
use App\Models\NightAstraRow;
use App\Models\NightRunStep;
use App\Support\NightRunSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ang Astra night run (spec 007 §6): aling mga order, kailan magsisimula, at ang pagbabantay kada minuto.
 * Ang isang row mismo ay pinapatakbo ng job na RunNightAstraRow.
 *
 * PERA (invariant): ang engine ay tinatawag lang ng job pagkatapos ng claim na `queued → running`.
 * WALANG naka-schedule sa class na ito ang nagbabalik ng row sa `queued` — hindi ang tick, hindi ang
 * dispatchPending, hindi ang settle, hindi ang stop. Ang mga row ay nagiging `queued` lang kapag ipinasok sa
 * start(), o sa click ng CEO (runNow / retryFailed → reopen()).
 */
class NightAstraRun
{
    public const KIND = 'astra';
    public const TZ   = 'Asia/Manila';

    /** Ilang minuto mula sa Astra time maghihintay sa import bago itala ang "Did not run". */
    public const WAIT_MINUTES = 60;

    /** Ilang sunod-sunod na `failed` na row bago ihinto ang run. */
    public const BREAKER_LIMIT = 10;

    /**
     * Ilang segundo dapat ang tanda ng streak bago ito maihinto ng unang subok na transient (mas mahaba sa
     * 65 s na delay ng retry).
     */
    public const BREAKER_STREAK_SECONDS = 120;

    /** Ilang minutong `running` ang isang row bago ito ituring na naiwan ng worker. */
    public const STALE_ROW_MINUTES = 10;

    private const INSERT_CHUNK = 500;

    /**
     * Iisang kahulugan ng "blangko ang STATUS" para sa query ng night run: NULL, '' o puro space.
     * (TRIM sa pamamagitan ng grammar wrap, gaya ng MacroCheckerController::blankRowsQuery at AstraEncoder.)
     */
    public static function whereStatusBlank($query)
    {
        $status = DB::getQueryGrammar()->wrap('STATUS');

        return $query->where(function ($q) use ($status) {
            $q->whereNull('STATUS')->orWhereRaw("TRIM({$status}) = ''");
        });
    }

    /** Kapareho ng whereStatusBlank() para sa value na nabasa na (space lang ang tinatanggal, gaya ng SQL TRIM). */
    public static function isStatusBlank($status): bool
    {
        return trim((string) $status, ' ') === '';
    }

    /** Ang petsa ng mga order ng isang gabi: night_date − 1 araw. */
    public static function ordersDate(string $nightDate): string
    {
        return Carbon::parse($nightDate, self::TZ)->subDay()->toDateString();
    }

    /**
     * Mga order ng petsang ito (ts_date) na blangko ang STATUS — anuman ang laman ng anim na field at
     * kahit nasubukan na ng AI — pinakaluma muna (TIMESTAMP, tapos id).
     */
    public function selection(string $ordersDate): Builder
    {
        return self::whereStatusBlank(MacroOutput::query()->where('ts_date', $ordersDate))
            ->orderBy('TIMESTAMP')
            ->orderBy('id');
    }

    /**
     * Pwede na bang magsimula ang Astra? Walang import na aktibo (macro o Likha), at may macro import na
     * nagsimula mula 00:00 Manila ngayon na natapos (done o failed) na may kahit isang sheet na done.
     *
     * @return array{ok: bool, reason: ?string}
     */
    public function importCondition(): array
    {
        if (MacroImportRun::whereIn('status', ['queued', 'running'])->exists()) {
            return ['ok' => false, 'reason' => 'an import was still running (macro)'];
        }
        if (LikhaImportRun::where('status', 'running')->exists()) {
            return ['ok' => false, 'reason' => 'an import was still running (Likha)'];
        }

        $midnight = $this->toStored(now(self::TZ)->startOfDay());
        $finished = MacroImportRun::whereIn('status', ['done', 'failed'])
            ->where('started_at', '>=', $midnight)
            ->whereHas('items', fn ($items) => $items->where('status', 'done'))
            ->exists();

        return $finished
            ? ['ok' => true, 'reason' => null]
            : ['ok' => false, 'reason' => 'no finished macro import since midnight'];
    }

    /**
     * Simulan ang run ng gabing ito (night_date = petsa ng umaga sa Manila; ang mga order = night_date − 1 araw).
     * Isang run kada petsa: bagong step o step na `waiting` lang ang nabubuksan. Ibinabalik ang step kapag
     * ITONG tawag ang nagbukas nito (running, finished kung walang row, o stopped kung walang API key);
     * null kapag may run na ang petsa.
     */
    public function start(string $nightDate, string $trigger): ?NightRunStep
    {
        if (AstraEncoder::resolveApiKey() === null) {
            $id = $this->openStep($nightDate, $trigger, 'stopped', 'Stopped: no API key set', ['finished_at' => now()]);

            return $id === null ? null : NightRunStep::find($id);
        }

        $settings   = NightRunSettings::read();
        $ordersDate = self::ordersDate($nightDate);

        // ISANG transaction (step + mga row): kapag pumalya sa gitna, walang maiiwang "Finished, 0 rows".
        $step = DB::transaction(function () use ($nightDate, $trigger, $settings, $ordersDate) {
            $id = $this->openStep($nightDate, $trigger, 'running', null);
            if ($id === null) {
                return null; // may nauna nang nagbukas — siya lang ang magpapatuloy
            }

            $selection = $this->selection($ordersDate);
            $found     = (clone $selection)->reorder()->count();
            $max       = $settings['night_astra_max_rows'];
            $now       = now();

            // Safety maximum: ang pinakalumang `max` lang ang may record; ang sobra ay bilang lang.
            foreach ($selection->limit($max)->pluck('id')->chunk(self::INSERT_CHUNK) as $chunk) {
                NightAstraRow::insert($chunk->map(fn ($orderId) => [
                    'step_id'         => $id,
                    'macro_output_id' => $orderId,
                    'state'           => 'queued',
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ])->all());
            }

            NightRunStep::where('id', $id)->update([
                'rows_found'           => $found,
                'rows_over_max'        => max(0, $found - $max),
                'consecutive_failures' => 0,
                'started_at'           => $now,
                'stop_at'              => $this->stopAt($nightDate, $trigger, $settings['night_astra_stop_time']),
            ] + ($found === 0 ? ['state' => 'finished', 'finished_at' => $now] : []));

            return NightRunStep::find($id);
        });

        if ($step !== null && $step->state === 'running') {
            $this->dispatchPending($step);
        }

        return $step;
    }

    /**
     * "Run now" ng CEO para sa petsa ng mga order (spec §6.6). Walang paghihintay: kapag hindi pa pwede,
     * tinatanggihan kasama ang dahilan. Walang step ang petsa → normal na start (trigger `manual`);
     * may step na tapos na (finished / stopped / did_not_run) → ang parehong run ang binubuksan ulit.
     *
     * @return array{ok: bool, message: string}
     */
    public function runNow(string $ordersDate): array
    {
        $nightDate = Carbon::parse($ordersDate, self::TZ)->addDay()->toDateString();
        $step      = NightRunStep::where('night_date', $nightDate)->where('kind', self::KIND)->first();

        // Bago ang start(): kukunin sana nito ang step na `waiting`.
        if ($step && in_array($step->state, ['waiting', 'running'], true)) {
            return ['ok' => false, 'message' => 'Already running'];
        }

        $condition = $this->importCondition();
        if (!$condition['ok']) {
            return ['ok' => false, 'message' => 'Not started: ' . $condition['reason']];
        }
        if (AstraEncoder::resolveApiKey() === null) {
            return ['ok' => false, 'message' => 'Not started: no API key set'];
        }

        if ($step === null) {
            $started = $this->start($nightDate, 'manual');
            if ($started === null) {
                return ['ok' => false, 'message' => 'Already running']; // may nauna nang nagbukas
            }
            $queued = $started->state === 'running' ? NightAstraRow::where('step_id', $started->id)->count() : 0;

            return ['ok' => true, 'message' => $queued > 0
                ? 'Started: ' . self::rowsLabel($queued) . " queued for {$ordersDate}"
                : "Finished: nothing to run for {$ordersDate}"];
        }

        $queued = $this->reopen($step, ['finished', 'stopped', 'did_not_run'], $ordersDate);
        if ($queued === null) {
            return ['ok' => false, 'message' => 'Already running'];
        }

        return ['ok' => true, 'message' => $queued > 0
            ? 'Re-opened: ' . self::rowsLabel($queued) . " queued for {$ordersDate}"
            : "Finished: nothing to run for {$ordersDate}"];
    }

    /**
     * "Retry failed" ng CEO: para lang sa run na `finished` o `stopped`, at ang mga row lang nitong `failed` /
     * `not_run` na blangko pa ang STATUS ng order. Walang bagong row, walang import condition.
     *
     * @return array{ok: bool, message: string}
     */
    public function retryFailed(NightRunStep $step): array
    {
        $refusal = fn (?string $state) => match (true) {
            in_array($state, ['waiting', 'running'], true) => 'Nothing to retry: the run is still active',
            $state === 'did_not_run'                       => 'Nothing to retry: the run did not run',
            default                                        => 'Nothing to retry: no failed or not-run row is still blank',
        };

        if (!in_array($step->state, ['finished', 'stopped'], true)) {
            return ['ok' => false, 'message' => $refusal($step->state)];
        }

        $queued = $this->reopen($step, ['finished', 'stopped'], null);
        if ($queued === null) {
            // Hindi nabuksan: may nauna nang click (tumatakbo na), o walang row na pwedeng ulitin.
            return ['ok' => false, 'message' => $refusal(NightRunStep::where('id', $step->id)->value('state'))];
        }

        return ['ok' => true, 'message' => 'Retrying ' . self::rowsLabel($queued)];
    }

    /**
     * Buksan ulit ang isang run na tapos na. ISANG transaction (step + mga row), at ang conditional update ng
     * state ng step ang guard: sa double click, isa lang ang nagbubukas. PERA: ito lang, kasama ng start(), ang
     * naglalagay ng row sa `queued` sa class na ito — at click lang ng CEO ang tumatawag dito. Ang `done` at
     * `skipped` ay hindi kailanman ginagalaw.
     *
     * $ordersDate null = Retry failed: walang idinadagdag na row, at hindi binubuksan kung walang row na mauulit
     * (nananatili ang "Stopped" at ang dahilan nito). May petsa = Run now: idinadagdag ang mga blangkong order
     * na wala pa sa run, hanggang sa safety maximum; kapag walang na-queue, `finished` agad.
     *
     * @return int|null ilang row ang `queued`; null = hindi ITONG tawag ang nagbukas
     */
    private function reopen(NightRunStep $step, array $fromStates, ?string $ordersDate): ?int
    {
        $settings   = NightRunSettings::read();
        $blankOrder = fn () => self::whereStatusBlank(DB::table('macro_output'))->select('id');

        $queued = DB::transaction(function () use ($step, $fromStates, $ordersDate, $settings, $blankOrder) {
            $now  = now();
            $open = NightRunStep::where('id', $step->id)->whereIn('state', $fromStates);
            if ($ordersDate === null) {
                $open->whereExists(function ($rows) use ($blankOrder) {
                    $rows->from('night_astra_rows')
                        ->whereColumn('night_astra_rows.step_id', 'night_run_steps.id')
                        ->whereIn('night_astra_rows.state', ['failed', 'not_run'])
                        ->whereIn('night_astra_rows.macro_output_id', $blankOrder());
                });
            }
            $won = $open->update([
                'state'                => 'running',
                'reason'               => null,
                'consecutive_failures' => 0,
                'failure_streak_started_at' => null,
                'finished_at'          => null,
                'stop_at'              => $this->stopAt(substr((string) $step->night_date, 0, 10), 'manual', $settings['night_astra_stop_time']),
            ]);
            if ($won !== 1) {
                return null;
            }
            // Run na hindi kailanman nagsimula (did_not_run): ngayon ang simula nito.
            NightRunStep::where('id', $step->id)->whereNull('started_at')->update(['started_at' => $now]);

            NightAstraRow::where('step_id', $step->id)->whereIn('state', ['failed', 'not_run'])
                ->whereIn('macro_output_id', $blankOrder())
                ->update(['state' => 'queued', 'attempts' => 0, 'dispatched_at' => null, 'reason' => null, 'started_at' => null, 'finished_at' => null]);

            if ($ordersDate !== null) {
                // Mga blangkong order ng petsa na wala pa sa run, pinakaluma muna; ang kabuuang row record ng
                // run ay hindi lalampas sa safety maximum. rows_found = mga row record + ang sobra sa maximum.
                $have    = NightAstraRow::where('step_id', $step->id)->count();
                $missing = $this->selection($ordersDate)
                    ->whereNotIn('id', NightAstraRow::where('step_id', $step->id)->select('macro_output_id'));
                $found   = (clone $missing)->reorder()->count();
                $add     = min($found, max(0, $settings['night_astra_max_rows'] - $have));

                if ($add > 0) {
                    foreach ($missing->limit($add)->pluck('id')->chunk(self::INSERT_CHUNK) as $chunk) {
                        NightAstraRow::insert($chunk->map(fn ($orderId) => [
                            'step_id'         => $step->id,
                            'macro_output_id' => $orderId,
                            'state'           => 'queued',
                            'created_at'      => $now,
                            'updated_at'      => $now,
                        ])->all());
                    }
                }

                NightRunStep::where('id', $step->id)->update([
                    'rows_found'    => $have + $found,
                    'rows_over_max' => $found - $add,
                ]);
            }

            $queued = NightAstraRow::where('step_id', $step->id)->where('state', 'queued')->count();
            if ($queued === 0) {
                NightRunStep::where('id', $step->id)->update(['state' => 'finished', 'finished_at' => $now]);
            }

            return $queued;
        });

        if ($queued) {
            $this->dispatchPending($step);
        }

        return $queued;
    }

    private static function rowsLabel(int $count): string
    {
        return $count . ($count === 1 ? ' row' : ' rows');
    }

    /**
     * Isang job kada row na `queued` na hindi pa na-dispatch. Ang conditional update sa dispatched_at ang
     * guard: isang beses lang nadi-dispatch ang row sa bawat pagkaka-queue nito. Walang binabagong state.
     */
    public function dispatchPending(NightRunStep $step): int
    {
        $dispatched = 0;
        $ids = NightAstraRow::where('step_id', $step->id)->where('state', 'queued')->whereNull('dispatched_at')->orderBy('id')->pluck('id');

        foreach ($ids as $rowId) {
            $won = NightAstraRow::where('id', $rowId)->where('state', 'queued')->whereNull('dispatched_at')
                ->update(['dispatched_at' => now()]);
            if ($won === 1) {
                RunNightAstraRow::dispatch((int) $rowId);
                $dispatched++;
            }
        }

        return $dispatched;
    }

    /**
     * Ihinto ang run: step → `stopped` (kung `running` pa) at lahat ng `queued` na row nito → `not_run`.
     * Ang mga row na tumatakbo sa ibang worker ay natatapos nang normal. False = hindi na `running` ang step.
     */
    public function stop(NightRunStep $step, string $reason): bool
    {
        // Dalawang table → isang transaction.
        return DB::transaction(function () use ($step, $reason) {
            $stopped = NightRunStep::where('id', $step->id)->where('state', 'running')
                ->update(['state' => 'stopped', 'reason' => $reason, 'finished_at' => now()]);
            if ($stopped !== 1) {
                return false;
            }

            NightAstraRow::where('step_id', $step->id)->where('state', 'queued')
                ->update(['state' => 'not_run', 'reason' => 'Not run: run stopped', 'finished_at' => now()]);

            return true;
        });
    }

    /** Tapos na ang run kapag `running` pa ito at wala nang row na `queued` o `running`. */
    public function settle(NightRunStep $step): bool
    {
        return NightRunStep::where('id', $step->id)->where('state', 'running')
            ->whereNotExists(function ($rows) {
                $rows->from('night_astra_rows')
                    ->whereColumn('night_astra_rows.step_id', 'night_run_steps.id')
                    ->whereIn('night_astra_rows.state', ['queued', 'running']);
            })
            ->update(['state' => 'finished', 'finished_at' => now()]) === 1;
    }

    /**
     * Breaker: bawat huling `failed` ng isang row ($final = true), at bawat unang subok na ibinalik para sa retry
     * ($final = false), ay +1 (atomic increment). Hinto ang run kapag 10 o higit pa ang sunod-sunod AT (huling
     * `failed` ang kabibilang lang, O 120 segundo na ang streak): ang maikling blip ng 5xx ay hindi humihinto sa
     * gabi bago pa tumakbo ang kahit isang retry (65 s), pero ang totoong outage ay humihinto sa loob ng ilang minuto.
     * Ang $reason ay fixed string ng row, hindi kailanman text mula sa OpenAI.
     */
    public function countFailure(int $stepId, string $reason, bool $final): void
    {
        // Simula ng streak: isinusulat lang kapag wala pa (conditional update), kaya hindi ito magagalaw ng
        // pangalawang worker. Nauuna sa increment: kapag may `done` na sumingit sa pagitan, ang maiiwan ay bilang
        // na walang simula (itatakda ng susunod na palya), hindi simulang luma na magpapahinto nang maaga.
        NightRunStep::where('id', $stepId)->whereNull('failure_streak_started_at')->update(['failure_streak_started_at' => now()]);
        NightRunStep::where('id', $stepId)->increment('consecutive_failures');

        $step = NightRunStep::find($stepId);
        if (!$step || (int) $step->consecutive_failures < self::BREAKER_LIMIT) {
            return;
        }

        $streakIsOld = $step->failure_streak_started_at !== null
            && $step->failure_streak_started_at->lte(now()->subSeconds(self::BREAKER_STREAK_SECONDS));
        if ($final || $streakIsOld) {
            $this->stop($step, 'Stopped: ' . self::BREAKER_LIMIT . " rows failed in a row (last: {$reason})");
        }
    }

    /** Isang row na natapos nang maayos → balik sa 0 ang bilang ng breaker, at wala nang streak. */
    public function resetFailures(int $stepId): void
    {
        NightRunStep::where('id', $stepId)
            ->where(fn ($streak) => $streak->where('consecutive_failures', '>', 0)->orWhereNotNull('failure_streak_started_at'))
            ->update(['consecutive_failures' => 0, 'failure_streak_started_at' => null]);
    }

    /**
     * Ang tick kada minuto (spec §6.2, §6.5): ang gabing ito (simulan, maghintay o "Did not run"), tapos ang
     * pagbabantay sa BAWAT tumatakbong run — anumang gabi, anumang trigger, naka-on man o hindi ang switch.
     */
    public function tick(): void
    {
        try {
            $this->tickTonight();
        } catch (\Throwable $e) {
            // Ang palya sa pagsisimula ng gabing ito ay hindi dapat pumigil sa pagbabantay ng mga tumatakbong run.
            // Class lang ng exception ang nilo-log, hindi ang message.
            Log::warning('NIGHT_ASTRA_TICK', ['exception' => get_class($e)]);
        }

        foreach (NightRunStep::where('kind', self::KIND)->where('state', 'running')->orderBy('id')->get() as $step) {
            $this->sweep($step);
        }

        $this->sweepStopped();
    }

    /**
     * Mga run na `stopped`: ang row na naiwang `running` ng worker ay isinasara ("Worker stopped", walang bilang
     * sa breaker — hinto na ang run), at ang row na `queued` pa (hal. ibinalik para sa retry kasabay ng hinto)
     * ay `not_run`. Hindi kailanman `queued` ang isinusulat dito.
     */
    private function sweepStopped(): void
    {
        $stopped = NightRunStep::where('kind', self::KIND)->where('state', 'stopped')->select('id');

        NightAstraRow::whereIn('step_id', $stopped)->where('state', 'running')
            ->where('started_at', '<', now()->subMinutes(self::STALE_ROW_MINUTES))
            ->update(['state' => 'failed', 'reason' => 'Worker stopped', 'finished_at' => now()]);

        NightAstraRow::whereIn('step_id', clone $stopped)->where('state', 'queued')
            ->update(['state' => 'not_run', 'reason' => 'Not run: run stopped', 'finished_at' => now()]);
    }

    /**
     * Pagbabantay sa isang tumatakbong run. Ang mga state na isinusulat dito sa row ay `failed` at `not_run`
     * lang — HINDI kailanman `queued`.
     */
    private function sweep(NightRunStep $step): void
    {
        // Mga row na `running` nang higit 10 minuto (namatay ang worker; ang job timeout na 540 s ay mas maikli).
        $cutoff = now()->subMinutes(self::STALE_ROW_MINUTES);
        $stale  = NightAstraRow::where('step_id', $step->id)->where('state', 'running')->where('started_at', '<', $cutoff)->orderBy('id')->pluck('id');
        foreach ($stale as $rowId) {
            $failed = NightAstraRow::where('id', $rowId)->where('state', 'running')->where('started_at', '<', $cutoff)
                ->update(['state' => 'failed', 'reason' => 'Worker stopped', 'finished_at' => now()]);
            if ($failed === 1) {
                $this->countFailure((int) $step->id, 'Worker stopped', true); // huling `failed`: maaaring ihinto nito ang run (breaker)
            }
        }

        // Lampas na ang stop time: ang mga hindi pa nasimulan ay hindi na sisimulan.
        if ($step->stop_at && now()->gte($step->stop_at)) {
            NightAstraRow::where('step_id', $step->id)->where('state', 'queued')
                ->update(['state' => 'not_run', 'reason' => 'Not run: out of time', 'finished_at' => now()]);
        }

        if (NightRunStep::where('id', $step->id)->value('state') !== 'running') {
            return; // inihinto ng breaker
        }

        $this->dispatchPending($step);
        $this->settle($step);
    }

    private function tickTonight(): void
    {
        $settings = NightRunSettings::read();
        $now      = now(self::TZ);
        $tonight  = $now->toDateString();
        $astraAt  = Carbon::parse($tonight . ' ' . $settings['night_astra_time'], self::TZ);
        $stopAt   = Carbon::parse($tonight . ' ' . $settings['night_astra_stop_time'], self::TZ);
        $waitOver = $now->gte($astraAt->copy()->addMinutes(self::WAIT_MINUTES));
        // Naka-off ang switch, bago ang Astra time, o lampas na ang stop time → walang sisimulan.
        $mayStart = $settings['night_astra_enabled'] && $now->gte($astraAt) && $now->lt($stopAt);

        // Mga step na naghihintay: tingnan ulit ang kondisyon; isara kapag lampas na ang 60 minuto,
        // anuman ang switch (para walang step na naghihintay habang-buhay).
        foreach (NightRunStep::where('kind', self::KIND)->where('state', 'waiting')->orderBy('id')->get() as $waiting) {
            $isTonight = substr((string) $waiting->night_date, 0, 10) === $tonight;
            $condition = $this->importCondition();

            if ($isTonight && $mayStart && $condition['ok']) {
                $this->start($tonight, 'schedule');
            } elseif (!$isTonight || $waitOver) {
                // Kapag OK na ang kondisyon pero hindi na pwedeng simulan: ang huling dahilan ng paghihintay.
                // Pinatay ang switch habang naghihintay: iyon ang dahilan, hindi ang lumang dahilan ng paghihintay.
                $reason = !$settings['night_astra_enabled']
                    ? 'switched off while waiting'
                    : ($condition['reason'] ?? preg_replace('/^Waiting: /', '', (string) $waiting->reason));
                NightRunStep::where('id', $waiting->id)->where('state', 'waiting')
                    ->update(['state' => 'did_not_run', 'reason' => 'Did not run: ' . $reason, 'finished_at' => now()]);
            } elseif (!$condition['ok']) {
                NightRunStep::where('id', $waiting->id)->where('state', 'waiting')
                    ->update(['reason' => 'Waiting: ' . $condition['reason']]);
            }
        }

        // Wala pang step ngayong gabi. Ang step na mayroon na at hindi `waiting` ay hindi na sinisimulan ulit.
        if ($mayStart && !NightRunStep::where('night_date', $tonight)->where('kind', self::KIND)->exists()) {
            $condition = $this->importCondition();

            if ($condition['ok']) {
                $this->start($tonight, 'schedule');
            } else {
                NightRunStep::insertOrIgnore([
                    'night_date' => $tonight,
                    'kind'       => self::KIND,
                    'trigger'    => 'schedule',
                    'created_at' => now(),
                    'updated_at' => now(),
                ] + ($waitOver
                    ? ['state' => 'did_not_run', 'reason' => 'Did not run: ' . $condition['reason'], 'finished_at' => now()]
                    : ['state' => 'waiting', 'reason' => 'Waiting: ' . $condition['reason'], 'finished_at' => null]));
            }
        }
    }

    /**
     * Buksan ang step ng petsa: bagong row (unique night_date + kind, insertOrIgnore) o conditional update
     * mula `waiting`. Sa dalawang sabay na tawag, isa lang ang nananalo. Null = may step na na hindi `waiting`.
     */
    private function openStep(string $nightDate, string $trigger, string $state, ?string $reason, array $extra = []): ?int
    {
        $values = ['state' => $state, 'reason' => $reason, 'trigger' => $trigger, 'updated_at' => now()] + $extra;

        $won = NightRunStep::insertOrIgnore(['night_date' => $nightDate, 'kind' => self::KIND, 'created_at' => now()] + $values)
            ?: NightRunStep::where('night_date', $nightDate)->where('kind', self::KIND)->where('state', 'waiting')->update($values);

        return $won ? (int) NightRunStep::where('night_date', $nightDate)->where('kind', self::KIND)->value('id') : null;
    }

    /**
     * Kailan titigil magsimula ng bagong row: ang stop time ng gabing iyon (schedule), o ang susunod na
     * pagdating ng stop time pagkatapos ng click (manual).
     */
    private function stopAt(string $nightDate, string $trigger, string $stopTime): Carbon
    {
        if ($trigger !== 'manual') {
            return $this->toStored(Carbon::parse($nightDate . ' ' . $stopTime, self::TZ));
        }

        $stop = Carbon::parse(now(self::TZ)->toDateString() . ' ' . $stopTime, self::TZ);

        return $this->toStored($stop->lte(now()) ? $stop->addDay() : $stop);
    }

    /** Oras sa Manila → timezone na ginagamit ng app sa pag-store ng timestamps (walang conversion ang query builder). */
    private function toStored(Carbon $time): Carbon
    {
        return $time->copy()->setTimezone(date_default_timezone_get());
    }
}

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

/**
 * Ang Astra night run (spec 007 §6): aling mga order, kailan magsisimula, at ang pagbabantay kada minuto.
 * Ang isang row mismo ay pinapatakbo ng job na RunNightAstraRow.
 *
 * PERA (invariant): ang engine ay tinatawag lang ng job pagkatapos ng claim na `queued → running`.
 * WALA sa class na ito ang nagbabalik ng row sa `queued` — hindi ang tick, hindi ang dispatchPending,
 * hindi ang settle, hindi ang stop. Ang mga row ay nagiging `queued` lang kapag ipinasok sa start().
 */
class NightAstraRun
{
    public const KIND = 'astra';
    public const TZ   = 'Asia/Manila';

    /** Ilang minuto mula sa Astra time maghihintay sa import bago itala ang "Did not run". */
    public const WAIT_MINUTES = 60;

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
        $ordersDate = Carbon::parse($nightDate, self::TZ)->subDay()->toDateString();

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

    /** Ang tick kada minuto (spec §6.2): simulan, maghintay, o itala ang "Did not run" para sa gabing ito. */
    public function tick(): void
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
                $reason = $condition['reason'] ?? preg_replace('/^Waiting: /', '', (string) $waiting->reason);
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

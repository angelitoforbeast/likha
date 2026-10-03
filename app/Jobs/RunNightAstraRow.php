<?php

namespace App\Jobs;

use App\Models\NightAstraRow;
use App\Models\NightRunStep;
use App\Services\AiCheckerRowRunner;
use App\Services\AstraEncoder;
use App\Services\NightAstraRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Isang order ng Astra night run (spec 007 §6.4), sa sariling queue na `astra` para manatiling libre
 * ang `default` worker sa imports at J&T.
 *
 * PERA: ang engine ay tinatawag lang pagkatapos ng claim (`queued → running`, isang conditional UPDATE),
 * kaya ang job na dumating nang dalawang beses ay walang pangalawang tawag. Ang row ay bumabalik sa
 * `queued` sa IISANG lugar lang: ang transient retry kapag attempts = 1 → hanggang 2 engine run kada row.
 * DATA: walang sinusulat sa order na may STATUS na (tinitingnan bago ang call, at muli sa mismong write
 * ng engine sa pamamagitan ng only_when_status_blank).
 * Ang `reason` ay fixed strings lang: walang laman ng sagot ng OpenAI o ng exception message.
 */
class RunNightAstraRow implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const QUEUE = 'astra';

    /** Segundo bago ang nag-iisang retry ng transient failure. */
    public const RETRY_DELAY_S = 65;

    private const HTTP_TIMEOUT_S = 120;

    private const FATAL_429_CODES = ['insufficient_quota', 'billing_hard_limit_reached', 'billing_not_active'];

    public int $timeout = 540;
    public int $tries = 3;

    public function __construct(public int $rowId)
    {
        $this->onQueue(self::QUEUE);
    }

    public function handle(): void
    {
        try {
            // 1. Claim. Walang nabago → may iba nang kumuha o tapos na ang row: walang gagawin.
            $claimed = DB::table('night_astra_rows')->where('id', $this->rowId)->where('state', 'queued')->update([
                'state'      => 'running',
                'started_at' => now(),
                'attempts'   => DB::raw('attempts + 1'),
                'updated_at' => now(),
            ]);
            if ($claimed !== 1) {
                return;
            }
        } catch (\Throwable $e) {
            $this->logFailure($e);

            return;
        }

        $night = app(NightAstraRun::class);

        try {
            $this->runClaimedRow($night);
        } catch (\Throwable $e) {
            // Hindi kailanman nagta-throw ang job. Class lang ng exception ang nilo-log, hindi ang message.
            $this->logFailure($e);
            try {
                $row = NightAstraRow::find($this->rowId);
                if ($row && $this->finish('failed', ['reason' => 'Error while running the row'])) {
                    $night->countFailure((int) $row->step_id, 'Error while running the row');
                    $this->settle($night, (int) $row->step_id);
                }
            } catch (\Throwable $again) {
                $this->logFailure($again); // mananatiling `running` ang row; ang tick ang magsasara ("Worker stopped")
            }
        }
    }

    private function runClaimedRow(NightAstraRun $night): void
    {
        $row  = NightAstraRow::findOrFail($this->rowId);
        $step = NightRunStep::find($row->step_id);

        // 2. Nakahinto na ang run, o lampas na ang stop time.
        if (!$step || $step->state !== 'running') {
            $this->finish('not_run', ['reason' => 'Not run: run stopped']);

            return;
        }
        if ($step->stop_at && now()->gte($step->stop_at)) {
            $this->finish('not_run', ['reason' => 'Not run: out of time']);
            $night->settle($step);

            return;
        }

        // 3–4. Basahin ulit ang order: laging panalo ang tao; walang chat → walang engine call.
        $order = DB::table('macro_output')->where('id', $row->macro_output_id)->first(['STATUS', 'all_user_input']);
        $skip  = match (true) {
            $order === null                                  => 'Order not found',
            !NightAstraRun::isStatusBlank($order->STATUS)    => 'Status set by a person',
            trim((string) $order->all_user_input) === ''     => 'No chat text',
            default                                          => null,
        };
        if ($skip !== null) {
            $this->finish('skipped', ['reason' => $skip]);
            $night->settle($step);

            return;
        }

        // Nawala ang API key pagkatapos magsimula ang run: walang engine call, hinto agad ang run.
        if (AstraEncoder::resolveApiKey() === null) {
            if ($this->finish('failed', ['reason' => 'No API key set'])) {
                $night->stop($step, 'Stopped: no API key set');
            }
            $night->settle($step);

            return;
        }

        // 5. Ang parehong run-one-row function ng browser. Ang buong app.url ang host (str_contains ang scope rule).
        $out = app(AiCheckerRowRunner::class)->run((int) $row->macro_output_id, 'astra', (string) config('app.url'), [
            'source'                 => 'night',
            'user_id'                => null,
            'user_name'              => 'Night run',
            'batch_id'               => 'night-' . NightAstraRun::ordersDate(substr((string) $step->night_date, 0, 10)),
            'batch_total'            => max(0, (int) $step->rows_found - (int) $step->rows_over_max),
            'http_timeout'           => self::HTTP_TIMEOUT_S,
            'only_when_status_blank' => true,
        ]);

        // 6. Uriin ang resulta. Ang gastos ng attempt na ito ay idinadagdag sa bawat attempt na may resulta.
        $result = is_array($out['result'] ?? null) ? $out['result'] : null;
        $status = $result['status'] ?? null;
        $base   = ['log_id' => $out['log_id'] ?? null];
        if ($result !== null) {
            $base['cost_usd'] = round((float) $row->cost_usd + (float) data_get($result, 'log.summary.cost_usd', 0), 4);
        }

        if ($status === 'fixed' || $status === 'partial') {
            $done = $this->finish('done', $base + [
                'code'        => mb_substr((string) ($result['final_code'] ?? ''), 0, 64),
                'proceed'     => $status === 'fixed',
                'reason'      => null,
                'duration_ms' => (int) data_get($result, 'log.summary.elapsed_ms', 0),
            ]);
            if ($done) {
                $night->resetFailures((int) $step->id);
            }
            $night->settle($step);

            return;
        }

        if ($status === 'skipped') {
            // May naglagay ng STATUS habang tumatakbo ang call: walang isinulat ang engine sa order.
            $this->finish('skipped', $base + ['reason' => 'Status set by a person']);
            $night->settle($step);

            return;
        }

        [$class, $reason, $stopReason] = $this->classify($out['last_error'] ?? null, $result !== null);

        // Transient, unang subok, at tumatakbo pa ang run → ang NAG-IISANG pagbalik sa `queued`.
        // dispatched_at = ngayon, para hindi maputol ng tick (na null lang ang dini-dispatch) ang 65 segundo.
        if ($class === 'transient' && (int) $row->attempts === 1 && NightRunStep::where('id', $step->id)->value('state') === 'running') {
            $requeued = $this->write(['state' => 'queued', 'dispatched_at' => now()] + $base);
            if ($requeued) {
                // Bilang din sa breaker ang unang subok na pumalya: ang retry job ay nasa dulo ng buong pila, kaya
                // kung huling `failed` lang ang bibilangin, uubusin ng outage ang buong gabi nang walang hinto.
                // `queued` muna ang row BAGO ang bilang: kapag inihinto nito ang run, kasama ang row na ito sa mga
                // ginagawang `not_run` ng stop(), at wala nang retry job na ipapadala.
                $night->countFailure((int) $step->id, $reason);
                if (DB::table('night_astra_rows')->where('id', $this->rowId)->value('state') === 'queued') {
                    self::dispatch($this->rowId)->delay(self::RETRY_DELAY_S);
                }
            }

            return;
        }

        if ($this->finish('failed', $base + ['reason' => $reason])) {
            // Fatal (key o credit): hinto agad, bago pa ang bilang ng breaker, para ito ang dahilang makikita.
            if ($class === 'fatal') {
                $night->stop($step, $stopReason);
            }
            $night->countFailure((int) $step->id, $reason);
        }
        $night->settle($step);
    }

    /**
     * Uri ng pagpalya mula sa huling transport error ng Astra (kind/status/code lang — walang body).
     *
     * @return array{0: string, 1: string, 2: ?string} [fatal|transient|other, reason ng row, reason ng paghinto ng run]
     */
    private function classify(?array $error, bool $hasResult): array
    {
        if ($error === null) {
            return ['other', $hasResult ? 'No usable answer from the AI' : 'Error while running the row', null];
        }
        if (($error['kind'] ?? null) === 'exception') {
            return ['transient', 'OpenAI timeout or connection error', null];
        }

        $http = (int) ($error['status'] ?? 0);

        if ($http === 401 || $http === 403) {
            return ['fatal', 'OpenAI error (4xx)', "Stopped: OpenAI rejected the API key ({$http})"];
        }
        if ($http === 429) {
            return in_array($error['code'] ?? null, self::FATAL_429_CODES, true)
                ? ['fatal', 'OpenAI rate limit (429)', 'Stopped: OpenAI credit or spend limit reached']
                : ['transient', 'OpenAI rate limit (429)', null];
        }
        if ($http >= 500) {
            return ['transient', 'OpenAI server error (5xx)', null];
        }

        return ['other', 'OpenAI error (4xx)', null];
    }

    /** Huling write ng row: `WHERE id = ? AND state = 'running'` lang. False = kinuha na ng sweep; wala nang isusulat. */
    private function finish(string $state, array $values): bool
    {
        return $this->write(['state' => $state, 'finished_at' => now()] + $values);
    }

    private function write(array $values): bool
    {
        return DB::table('night_astra_rows')->where('id', $this->rowId)->where('state', 'running')
            ->update($values + ['updated_at' => now()]) === 1;
    }

    private function settle(NightAstraRun $night, int $stepId): void
    {
        if ($step = NightRunStep::find($stepId)) {
            $night->settle($step);
        }
    }

    private function logFailure(\Throwable $e): void
    {
        Log::warning('NIGHT_ASTRA_ROW', ['row' => $this->rowId, 'exception' => get_class($e)]);
    }
}

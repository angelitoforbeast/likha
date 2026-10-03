<?php

namespace Tests\Feature\NightRun;

use App\Jobs\RunNightAstraRow;
use App\Models\KeywordBlacklist;
use App\Models\MacroOutput;
use App\Models\NightAstraRow;
use App\Models\NightRunStep;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Ang job ng isang row (spec 007 §6.4). Mga invariant na binabantayan dito:
 *  - PERA: engine call lang pagkatapos ng claim; hanggang 2 engine run kada row; walang ikatlo.
 *  - DATA: walang sinusulat sa order na may STATUS na inilagay ng tao (bago o habang tumatakbo ang call).
 *  - Ang `reason` ay fixed strings lang — walang laman ng sagot ng OpenAI o ng exception.
 */
class NightAstraRowJobTest extends NightAstraTestCase
{
    private const MARKER = 'MARKER-sk-proj-abc123SECRETxyz';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.openai.astra_encoder_model' => 'gpt-6-luna', 'services.openai.astra_encoder_effort' => 'low']);
    }

    private function openAiError(int $status, string $type, ?string $code)
    {
        return Http::response(['error' => ['message' => 'May problema: ' . self::MARKER, 'type' => $type, 'param' => null, 'code' => $code]], $status);
    }

    private function orderAsStored(int $id): array
    {
        return (array) DB::table('macro_output')->where('id', $id)->first();
    }

    public function test_a_row_that_must_not_run_is_skipped_without_an_engine_call(): void
    {
        Http::fake();
        $statused = $this->order(['STATUS' => null]);
        $noChat   = $this->order(['all_user_input' => "  \n "]);
        $gone     = $this->order();
        $step     = $this->runningStep([$statused->id, $noChat->id, $gone->id], ['consecutive_failures' => 3]);
        // Pagkatapos mapili ang mga row: may taong naglagay ng STATUS, at nabura ang isang order.
        DB::table('macro_output')->where('id', $statused->id)->update(['STATUS' => 'CANNOT PROCEED', 'FULL NAME' => 'Inilagay Ng Tao']);
        DB::table('macro_output')->where('id', $gone->id)->delete();
        $before = [$this->orderAsStored($statused->id), $this->orderAsStored($noChat->id)];

        $rows = [$this->work($statused->id), $this->work($noChat->id), $this->work($gone->id)];

        $this->assertSame(
            [['skipped', 'Status set by a person', 1], ['skipped', 'No chat text', 1], ['skipped', 'Order not found', 1]],
            array_map(fn ($row) => [$row->state, $row->reason, (int) $row->attempts], $rows)
        );
        $this->assertSame($before, [$this->orderAsStored($statused->id), $this->orderAsStored($noChat->id)]);
        Http::assertNothingSent();
        $this->assertSame(0, DB::table('ai_checker_logs')->count());

        // Hindi failure: hindi ginagalaw ang breaker; tapos na ang lahat ng row → finished.
        $step->refresh();
        $this->assertSame(['finished', 3], [$step->state, (int) $step->consecutive_failures]);
        $this->assertSame(self::NIGHT . ' 03:00:00', $step->finished_at->toDateTimeString());
    }

    public function test_a_status_set_during_the_call_leaves_the_order_as_the_person_left_it(): void
    {
        $order = $this->order();
        $step  = $this->runningStep([$order->id], ['consecutive_failures' => 3]);
        $asLeftByPerson = null;
        Http::fake(['api.openai.com/v1/responses' => function () use ($order, &$asLeftByPerson) {
            DB::table('macro_output')->where('id', $order->id)->update(['STATUS' => 'CANNOT PROCEED', 'FULL NAME' => 'Inilagay Ng Tao']);
            $asLeftByPerson = $this->orderAsStored($order->id);

            return Http::response($this->goodAnswer());
        }]);

        $row = $this->work($order->id);

        $this->assertSame($asLeftByPerson, $this->orderAsStored($order->id));
        $this->assertSame(['skipped', 'Status set by a person', false, null], [$row->state, $row->reason, $row->proceed, $row->code]);
        // May gastos pa rin ang call: 200,000 × 0.10 / 1M + 30,000 × 0.50 / 1M = 0.035
        $this->assertSame('0.0350', number_format((float) $row->cost_usd, 4));
        $this->assertSame(3, (int) $step->fresh()->consecutive_failures);
    }

    public function test_a_success_writes_the_result_and_one_night_log_row(): void
    {
        $order   = $this->order();
        $step    = $this->runningStep([$order->id], ['consecutive_failures' => 4, 'rows_found' => 5, 'rows_over_max' => 2]);
        $timeout = null;
        Http::fake(['api.openai.com/v1/responses' => function (Request $request, array $options) use (&$timeout) {
            $timeout = $options['timeout'] ?? null;

            return Http::response($this->goodAnswer());
        }]);

        $row = $this->work($order->id);

        $log = DB::table('ai_checker_logs')->sole();
        $this->assertSame(['done', '✅', true, null, 1, (int) $log->id], [$row->state, $row->code, $row->proceed, $row->reason, (int) $row->attempts, (int) $row->log_id]);
        $this->assertSame('0.0350', number_format((float) $row->cost_usd, 4));
        $this->assertNotNull($row->duration_ms);
        $this->assertSame(self::NIGHT . ' 03:00:00', $row->finished_at->toDateTimeString());
        $this->assertSame('PROCEED', MacroOutput::find($order->id)->STATUS);
        // Ang log row ng gabi: source night, walang user, isang batch kada gabi, batch_total = mga row na kinuha (5 − 2).
        $this->assertSame(
            ['night', null, 'Night run', 'night-' . self::ORDERS, 3, $order->id, 'fixed'],
            [$log->source, $log->user_id, $log->user_name, $log->batch_id, (int) $log->batch_total, (int) $log->macro_output_id, $log->outcome]
        );
        $this->assertSame(120, $timeout);
        $step->refresh();
        $this->assertSame(['finished', 0], [$step->state, (int) $step->consecutive_failures]);
    }

    public function test_a_transient_failure_is_retried_once_after_65_seconds_and_never_a_third_time(): void
    {
        $order = $this->order();
        $step  = $this->runningStep([$order->id]);
        DB::table('night_astra_rows')->update(['dispatched_at' => '2026-10-05 02:00:00']);
        Http::fake(['api.openai.com/v1/responses' => $this->openAiError(500, 'server_error', null)]);

        // Unang takbo: balik sa queued, may dispatched_at (para hindi maputol ng tick ang delay), isang delayed job.
        $row = $this->work($order->id);
        $this->assertSame(['queued', 1, self::NIGHT . ' 03:00:00'], [$row->state, (int) $row->attempts, $row->dispatched_at->toDateTimeString()]);
        // Bilang na sa breaker ang unang subok na ibinalik para sa retry (T4 review).
        $this->assertSame(['running', 1], [$step->fresh()->state, (int) $step->fresh()->consecutive_failures]);
        Queue::assertPushedOn('astra', RunNightAstraRow::class, fn ($job) => $job->rowId === $row->id && $job->delay === 65);
        Queue::assertPushed(RunNightAstraRow::class, 1);

        // Pangalawang takbo: failed na, at bilang ulit sa breaker.
        $row = $this->work($order->id);
        $this->assertSame(['failed', 'OpenAI server error (5xx)', 2], [$row->state, $row->reason, (int) $row->attempts]);
        $this->assertSame(['finished', 2], [$step->fresh()->state, (int) $step->fresh()->consecutive_failures]);

        // Walang ikatlo: ang isa pang delivery ay walang engine run at walang bagong job.
        $this->work($order->id);
        $this->assertSame(2, DB::table('ai_checker_logs')->count()); // eksaktong dalawang engine run
        Http::assertSentCount(4);                                    // bawat run: ang sariling mabilis na retry ng engine
        Queue::assertPushed(RunNightAstraRow::class, 1);
        $this->assertNull(MacroOutput::find($order->id)->STATUS);
    }

    public function test_a_final_failure_gets_a_fixed_reason_and_nothing_from_openai_or_the_exception(): void
    {
        // [sagot ng OpenAI (callable), inaasahang reason, inaasahang gastos]. attempts = 1 na: ito na ang huling subok.
        $cases = [
            'server error'      => [fn () => $this->openAiError(503, 'server_error', null), 'OpenAI server error (5xx)', 0.0],
            'rate limit'        => [fn () => $this->openAiError(429, 'requests', 'rate_limit_exceeded'), 'OpenAI rate limit (429)', 0.0],
            'connection error'  => [fn () => throw new ConnectionException('cURL error 28: ' . self::MARKER), 'OpenAI timeout or connection error', 0.0],
            'bad request'       => [fn () => $this->openAiError(400, 'invalid_request_error', 'bad_thing'), 'OpenAI error (4xx)', 0.0],
            // 100,000 × 0.10 / 1M = 0.01: may gastos kahit walang magagamit na sagot.
            'no usable answer'  => [fn () => Http::response(['id' => 'r', 'output_text' => self::MARKER, 'usage' => ['input_tokens' => 100000, 'output_tokens' => 0]]), 'No usable answer from the AI', 0.01],
            'runner exception'  => [fn () => Http::response($this->goodAnswer()), 'Error while running the row', 0.0],
        ];
        $answer = fn () => null;
        Http::fake(['api.openai.com/v1/responses' => function () use (&$answer) {
            return $answer();
        }]);

        foreach ($cases as $name => [$respond, $reason, $cost]) {
            NightAstraRow::query()->delete();
            NightRunStep::query()->delete();
            $order  = $this->order();
            $step   = $this->runningStep([$order->id], [], ['attempts' => 1]);
            $answer = $respond;
            if ($name === 'runner exception') {
                // Pumapalya ang pagsulat sa order: ang exception message ay may marker (hindi dapat lumabas kahit saan).
                DB::statement("CREATE TRIGGER macro_output_fail BEFORE UPDATE ON macro_output BEGIN SELECT RAISE(ABORT, '" . self::MARKER . "'); END");
            }

            $row = $this->work($order->id);
            DB::statement('DROP TRIGGER IF EXISTS macro_output_fail');

            $this->assertSame(['failed', $reason, 2], [$row->state, $row->reason, (int) $row->attempts], $name);
            $this->assertSame(number_format($cost, 4), number_format((float) $row->cost_usd, 4), $name);
            $this->assertSame(['finished', 1, null], [$step->fresh()->state, (int) $step->fresh()->consecutive_failures, $step->fresh()->reason], $name);
            $this->assertNull(MacroOutput::find($order->id)->STATUS, $name);
        }

        Queue::assertNothingPushed(); // attempts ≥ 2 → walang retry
        $written = json_encode([NightAstraRow::all()->toArray(), NightRunStep::all()->toArray(), $this->logLines->getArrayCopy()]);
        $this->assertStringNotContainsString('MARKER', $written);
        $this->assertStringNotContainsString('SECRET', $written);
    }

    public function test_ten_failures_in_a_row_stop_the_run_and_a_success_resets_the_count(): void
    {
        $orders = [];
        for ($i = 0; $i < 14; $i++) {
            $orders[] = $this->order();
        }
        $step = $this->runningStep(array_map(fn ($order) => $order->id, $orders));
        $fail = true;
        Http::fake(['api.openai.com/v1/responses' => function () use (&$fail) {
            return $fail ? $this->openAiError(400, 'invalid_request_error', null) : Http::response($this->goodAnswer());
        }]);

        // Ang unang pumalya ang simula ng streak; ang row na `done` ay nagbubura ng bilang AT ng simula ng streak.
        $this->work($orders[0]->id);
        $this->assertSame([1, self::NIGHT . ' 03:00:00'], [(int) $step->fresh()->consecutive_failures, (string) $step->fresh()->failure_streak_started_at]);
        $fail = false;
        $this->assertSame('done', $this->work($orders[1]->id)->state);
        $this->assertSame([0, null], [(int) $step->fresh()->consecutive_failures, $step->fresh()->failure_streak_started_at]);

        // Mga huling `failed` (4xx, walang retry): ang ika-10 sunod-sunod ay hinto agad, kahit ilang segundo pa lang ang streak.
        $fail = true;
        for ($i = 2; $i <= 10; $i++) {
            $this->work($orders[$i]->id);
        }
        $this->assertSame(['running', 9], [$step->fresh()->state, (int) $step->fresh()->consecutive_failures]);

        $this->work($orders[11]->id);

        $step->refresh();
        $this->assertSame(['stopped', 'Stopped: 10 rows failed in a row (last: OpenAI error (4xx))'], [$step->state, $step->reason]);
        $this->assertSame(self::NIGHT . ' 03:00:00', $step->finished_at->toDateTimeString());
        $this->assertSame(
            [['not_run', 'Not run: run stopped'], ['not_run', 'Not run: run stopped']],
            [[$this->rowFor($orders[12]->id)->state, $this->rowFor($orders[12]->id)->reason], [$this->rowFor($orders[13]->id)->state, $this->rowFor($orders[13]->id)->reason]]
        );

        // Ang mga job ng mga natitirang row ay walang ginagawa.
        $this->work($orders[12]->id);
        Http::assertSentCount(12);
        Queue::assertNothingPushed();
    }

    /**
     * Mga unang subok na transient (ibinabalik para sa retry) ay bilang din sa breaker, pero hindi sila ang
     * nagpapahinto hangga't wala pang 120 segundo ang streak: ang 30-segundong blip ay hindi dapat pumatay sa gabi
     * bago pa tumakbo ang kahit isang retry (65 s). Ang totoong outage ay humihinto pagdating ng 120 segundo.
     * (Ang bilang ay itinatakda nang direkta sa halip na tig-1.2 s na tawag kada pumalyang subok.)
     */
    public function test_transient_first_attempts_stop_the_run_only_when_the_streak_is_120_seconds_old(): void
    {
        $orders = [];
        for ($i = 0; $i < 5; $i++) {
            $orders[] = $this->order();
        }
        $step = $this->runningStep(array_map(fn ($order) => $order->id, $orders));
        Http::fake(['api.openai.com/v1/responses' => $this->openAiError(500, 'server_error', null)]);
        $streak = fn () => [$step->fresh()->state, (int) $step->fresh()->consecutive_failures, (string) $step->fresh()->failure_streak_started_at];

        // 03:00:00 — ang unang pumalya: dito nagsisimula ang streak.
        $this->assertSame('queued', $this->work($orders[0]->id)->state);
        $this->assertSame(['running', 1, self::NIGHT . ' 03:00:00'], $streak());

        // Blip: ang ika-10 sa loob ng 30 segundo ay HINDI hinto; nakapila ang row para sa retry nito, at hindi
        // gumagalaw ang simula ng streak.
        NightRunStep::where('id', $step->id)->update(['consecutive_failures' => 9]);
        $this->at('03:00:30');
        $this->assertSame('queued', $this->work($orders[1]->id)->state);
        $this->assertSame(['running', 10, self::NIGHT . ' 03:00:00'], $streak());

        // 119 segundo: hindi pa rin.
        $this->at('03:01:59');
        $this->assertSame('queued', $this->work($orders[2]->id)->state);
        $this->assertSame(['running', 11, self::NIGHT . ' 03:00:00'], $streak());
        Queue::assertPushed(RunNightAstraRow::class, 3);
        Queue::assertPushedOn('astra', RunNightAstraRow::class, fn ($job) => $job->rowId === $this->rowFor($orders[2]->id)->id && $job->delay === 65);

        // Outage: 120 segundo na ang streak → hinto. Ang row na kababalik lang sa pila ay `not_run` din, gaya ng
        // lahat ng nakapila; walang retry job para rito.
        $this->at('03:02:00');
        $last = $this->work($orders[3]->id);

        $step->refresh();
        $this->assertSame(['stopped', 'Stopped: 10 rows failed in a row (last: OpenAI server error (5xx))'], [$step->state, $step->reason]);
        $this->assertSame(['not_run', 'Not run: run stopped', 1], [$last->state, $last->reason, (int) $last->attempts]);
        $this->assertSame(array_fill(0, 5, 'not_run'), $this->rowStates());
        Queue::assertPushed(RunNightAstraRow::class, 3);

        // Dumating ang mga retry job pagkatapos ng hinto: walang engine call, walang row na may pangalawang subok.
        $this->work($orders[0]->id);
        $this->work($orders[3]->id);
        Http::assertSentCount(8); // 4 na pumalyang subok × 2 (sariling mabilis na retry ng engine)
        $this->assertSame([1, 1, 1, 1, 0], NightAstraRow::orderBy('id')->pluck('attempts')->map(fn ($n) => (int) $n)->all());
    }

    public function test_no_api_key_right_before_the_call_fails_the_row_and_stops_the_run(): void
    {
        Http::fake();
        $first = $this->order();
        $next  = $this->order();
        $step  = $this->runningStep([$first->id, $next->id]);

        $this->withoutApiKey(fn () => $this->work($first->id));

        $row = $this->rowFor($first->id);
        $this->assertSame(['failed', 'No API key set', 1], [$row->state, $row->reason, (int) $row->attempts]);
        $this->assertSame(['stopped', 'Stopped: no API key set'], [$step->fresh()->state, $step->fresh()->reason]);
        $this->assertSame(['not_run', 'Not run: run stopped'], [$this->rowFor($next->id)->state, $this->rowFor($next->id)->reason]);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_a_rejected_key_or_exhausted_credit_stops_the_run_at_once(): void
    {
        // [status, type, code, dahilan ng step]
        $cases = [
            'invalid key'  => [401, 'invalid_request_error', 'invalid_api_key', 'Stopped: OpenAI rejected the API key (401)'],
            'forbidden'    => [403, 'forbidden', null, 'Stopped: OpenAI rejected the API key (403)'],
            'no credit'    => [429, 'insufficient_quota', 'insufficient_quota', 'Stopped: OpenAI credit or spend limit reached'],
        ];

        $answer = fn () => null;
        Http::fake(['api.openai.com/v1/responses' => function () use (&$answer) {
            return $answer();
        }]);

        foreach ($cases as $name => [$status, $type, $code, $stepReason]) {
            NightAstraRow::query()->delete();
            NightRunStep::query()->delete();
            $first  = $this->order();
            $next   = $this->order();
            $step   = $this->runningStep([$first->id, $next->id]);
            $answer = fn () => $this->openAiError($status, $type, $code);

            $row = $this->work($first->id);

            $this->assertSame(['failed', 1], [$row->state, (int) $row->attempts], $name);
            $this->assertSame(['stopped', $stepReason], [$step->fresh()->state, $step->fresh()->reason], $name);
            $this->assertSame(['not_run', 'Not run: run stopped'], [$this->rowFor($next->id)->state, $this->rowFor($next->id)->reason], $name);
            $this->assertStringNotContainsString('MARKER', json_encode([NightAstraRow::all()->toArray(), $step->fresh()->toArray()]), $name);
        }

        Queue::assertNothingPushed(); // walang retry sa fatal, kahit 429 ito
    }

    public function test_a_second_delivery_of_the_same_job_makes_no_second_engine_call(): void
    {
        $order = $this->order();
        $this->runningStep([$order->id]);
        $rowId = $this->rowFor($order->id)->id;
        Http::fake(['api.openai.com/v1/responses' => function () use ($rowId) {
            // Habang tumatakbo ang row, dumating ulit ang parehong job (hal. redelivery ng queue).
            (new RunNightAstraRow($rowId))->handle();

            return Http::response($this->goodAnswer());
        }]);

        $this->work($order->id);
        $row = $this->work($order->id); // at isa pa pagkatapos nito

        $this->assertSame(['done', 1], [$row->state, (int) $row->attempts]);
        Http::assertSentCount(1);
        $this->assertSame(1, DB::table('ai_checker_logs')->count());
    }

    public function test_a_row_is_not_run_when_the_run_stopped_or_the_stop_time_passed(): void
    {
        Http::fake();

        // [state ng step, stop_at, inaasahang reason]
        $cases = [
            'run stopped'  => ['stopped', self::NIGHT . ' 07:00:00', 'Not run: run stopped'],
            'out of time'  => ['running', self::NIGHT . ' 03:00:00', 'Not run: out of time'],
        ];

        foreach ($cases as $name => [$state, $stopAt, $reason]) {
            NightAstraRow::query()->delete();
            NightRunStep::query()->delete();
            $order = $this->order();
            $this->runningStep([$order->id], ['state' => $state, 'stop_at' => $stopAt]);

            $row = $this->work($order->id);

            $this->assertSame(['not_run', $reason], [$row->state, $row->reason], $name);
            $this->assertNull(MacroOutput::find($order->id)->STATUS, $name);
        }

        Http::assertNothingSent();
    }

    public function test_the_blacklist_scope_comes_from_the_app_url(): void
    {
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->goodAnswer())]);

        // [app.url, scope ng blacklist entry na tumatama sa chat ("sampaguita"), inaasahang code]
        $cases = [
            'incepxion url, incepxion entry' => ['https://app.incepxion.example', 'incepxion', 'TO FIX'],
            'incepxion url, likha entry'     => ['https://app.incepxion.example', 'likha', '✅'],
            'likha url, likha entry'         => ['https://likhaaitech.com', 'likha', 'TO FIX'],
            'likha url, incepxion entry'     => ['https://likhaaitech.com', 'incepxion', '✅'],
        ];

        $day = 0;
        foreach ($cases as $name => [$appUrl, $entryScope, $code]) {
            NightAstraRow::query()->delete();
            NightRunStep::query()->delete();
            KeywordBlacklist::query()->delete();
            KeywordBlacklist::create(['keyword' => 'sampaguita', 'host_scope' => $entryScope]);
            config(['app.url' => $appUrl]);
            // Ibang petsa kada case: kung hindi, "PHONE duplicate sa parehong petsa" ang gate ng mga susunod.
            $order = $this->order(['ts_date' => '2026-09-0' . ++$day]);
            $this->runningStep([$order->id]);

            $row = $this->work($order->id);

            $this->assertSame(['done', $code, $code === '✅'], [$row->state, $row->code, $row->proceed], $name);
        }
    }
}

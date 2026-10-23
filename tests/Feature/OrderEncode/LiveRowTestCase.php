<?php

namespace Tests\Feature\OrderEncode;

use App\Models\MacroOutput;
use App\Models\OrderEncode;
use App\Services\OrderEncode\Runner;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * Ang live na order ng Encode order step (spec 136): isang macro_output row kada customer kada araw. Fake na OpenAI (gaya ng
 * EncodeRunTestCase), ang J&T list ng Bungad, at ang mga column ng macro_output na binabasa ng update (edited_*, HISTORICAL LOGS).
 */
abstract class LiveRowTestCase extends EncodeRunTestCase
{
    protected const PSID = 7001;
    protected const PAGE_ID = '1001';

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_24_100000_add_draft_to_chatbot_flows.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_26_100000_create_chatbot_sequence_tables.php',
            'database/migrations/2026_06_23_120000_create_ai_checker_logs_table.php',
            'database/migrations/2026_06_23_130000_widen_ai_checker_logs_final_code.php',
        ]]);
        Schema::table('macro_output', function (Blueprint $t) {
            foreach (['edited_full_name', 'edited_address', 'edited_province', 'edited_city', 'edited_barangay', 'edited_item_name', 'edited_cod'] as $c) $t->boolean($c)->default(false);
            $t->text('HISTORICAL LOGS')->nullable();
            $t->text('status_logs')->nullable();
        });
        config(['order_encode.customer_lock_wait' => 0]);
        \Illuminate\Support\Facades\Queue::fake([\App\Jobs\RunSpotAstra::class]); // ang test ang nagpapatakbo ng Astra
        $this->writeList(self::bungadRows());
        $this->fakeGpt();
    }

    /** Ang extracted details na kumpleto (Bungad, Quezon City) o kulang ang barangay ($brgy = ''). */
    protected function details(string $brgy = 'Bungad', string $name = 'Ana Cruz', string $phone = '09171234567'): string
    {
        return $this->cxd('Metro Manila', 'Quezon City', $brgy, $name, $phone);
    }

    /**
     * Isang takbo ng Encode order step (live) at ang job nito. $over: mga cell ng row (0 = petsa, 2 = pangalan, 4 = input).
     */
    protected function liveOrder(string $cxd, array $over = [], int $psid = self::PSID, ?string $runId = null, string $page = self::PAGE): OrderEncode
    {
        $row = $this->withCxd($cxd, $over + [1 => $page]);
        $o = OrderEncode::create([
            'page' => $page, 'psid' => $psid, 'chat_id' => 13, 'flow_id' => 5, 'run_id' => $runId ?? bin2hex(random_bytes(4)),
            'spreadsheet_id' => 'flow-node', 'tab' => 'All Orders', 'row_number' => null, 'mode' => 'live', 'status' => 'queued',
            'input' => ['row' => $row, 'node' => 'n2', 'sheet_written' => false, 'page_id' => self::PAGE_ID],
        ]);
        app(Runner::class)->run($o);

        return $o->fresh();
    }

    /** Isang order na hold: ang barangay ay wala sa listahan at hindi ito tinanggap ng verify ng GPT (final "Barangay"). */
    protected function heldOrder(array $over = [], int $psid = self::PSID): OrderEncode
    {
        $this->gpt(['verify' => ['province_ok' => true, 'city_ok' => true, 'barangay_ok' => false]]);
        $o = $this->liveOrder($this->details('Nowhere'), $over, $psid);
        $this->gpt();

        return $o;
    }

    /** Bagong fake na OpenAI (ang unang Http::fake ang nasusunod, kaya bagong factory muna). */
    protected function gpt(array $answers = []): void
    {
        \Illuminate\Support\Facades\Http::swap(new \Illuminate\Http\Client\Factory(app('events')));
        $this->fakeGpt($answers);
    }

    /** Isang row na naroon na sa macro_output (hal. galing sa import o sa naunang order). */
    protected function existing(array $over = []): MacroOutput
    {
        return MacroOutput::create($over + ['TIMESTAMP' => '09:00 23-10-2026', 'ts_date' => '2026-10-23', 'PAGE' => self::PAGE, 'fb_name' => 'Ana Cruz',
            'FULL NAME' => 'Ana Cruz', 'PHONE NUMBER' => '09171234567', 'botcake_psid' => (string) self::PSID, 'STATUS' => null]);
    }

    /** @return array<string, mixed> ang lahat ng column ng row, para makita na walang nabago */
    protected function columns(MacroOutput $m): array
    {
        return (array) \Illuminate\Support\Facades\DB::table('macro_output')->where('id', $m->id)->first();
    }

    /**
     * Ang fake na Astra engine sa tunay na AiCheckerRowRunner (kaya may ai_checker_logs row). $fix = PROCEED ang row;
     * $error = ang huling transport error (walang resulta); $throw = pumalya ang engine.
     */
    protected function astra(bool $fix = false, ?array $error = null, bool $throw = false, string $code = '✅'): \ArrayObject
    {
        $calls = new \ArrayObject();
        $engine = new class($calls, $fix, $error, $throw, $code) extends \App\Services\AstraEncoder {
            public function __construct(private \ArrayObject $calls, private bool $fix, private ?array $error, private bool $throw, private string $code)
            {
            }

            public function processRow(int $id, array $maps, ?string $host = null): array
            {
                $this->calls[] = $id;
                if ($this->throw) throw new \RuntimeException('engine down');
                if ($this->error) return ['status' => 'failed', 'final_code' => null, 'message' => 'Astra: walang sagot mula sa AI'];
                MacroOutput::whereKey($id)->update($this->fix ? ['STATUS' => 'PROCEED', 'BARANGAY' => 'BUNGAD'] : ['AI ANALYZE' => 'Astra: check']);

                return ['status' => $this->fix ? 'fixed' : 'partial', 'final_code' => $this->code, 'all_filled' => $this->fix];
            }

            public function lastError(): ?array
            {
                return $this->error;
            }
        };
        $this->app->instance(\App\Services\AiCheckerRowRunner::class, new class($engine) extends \App\Services\AiCheckerRowRunner {
            public function __construct(private object $fake)
            {
            }

            protected function engine(string $engine)
            {
                return $this->fake;
            }
        });

        return $calls;
    }

    /** Ang sequence ng tanong sa address, naka-set sa app setting. */
    protected function addressSequence(array $attrs = [], bool $steps = true): \App\Models\ChatbotSequence
    {
        $seq = \App\Models\ChatbotSequence::create($attrs + ['name' => 'Ask address', 'active' => true, 'exit_on_reply' => true]);
        if ($steps) $seq->steps()->create(['position' => 1, 'delay_amount' => 0, 'delay_unit' => 'minutes', 'flow_id' => null, 'active' => true]);
        \App\Models\AppSetting::set(\App\Services\OrderEncode\Settings::ADDRESS_SEQUENCE, (string) $seq->id);

        return $seq;
    }

    /** Ang naka-queue na Astra job ng order, pinatakbo ngayon (may fake na queue para sa release). */
    protected function runAstra(\App\Models\OrderEncode $o, int $attempt = 1): \App\Jobs\RunSpotAstra
    {
        $job = (new \App\Jobs\RunSpotAstra($o->id))->withFakeQueueInteractions();
        $job->job->attempts = $attempt;
        $job->handle();

        return $job;
    }
}

<?php

namespace Tests\Feature\NightRun;

use App\Jobs\RunNightAstraRow;
use App\Models\AppSetting;
use App\Models\LikhaImportRun;
use App\Models\MacroImportRun;
use App\Models\MacroImportRunItem;
use App\Models\MacroOutput;
use App\Models\NightAstraRow;
use App\Models\NightRunStep;
use App\Services\AstraEncoder;
use App\Services\NightAstraRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * Base ng mga test ng Astra night run: walang job na tumatakbo nang kusa (Queue::fake —
 * tinatawag ng test ang handle()), walang totoong OpenAI (Http::preventStrayRequests), walang
 * napupunta sa totoong log, at nakapirmi ang oras. Gabi = 2026-10-05, mga order = 2026-10-04.
 */
abstract class NightAstraTestCase extends NightRunTestCase
{
    protected const NIGHT  = '2026-10-05';
    protected const ORDERS = '2026-10-04';

    protected \ArrayObject $logLines;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::preventStrayRequests();
        // Walang napupunta sa totoong log; ang bawat linya ay naiipon para mahanap kung may tumagas.
        $this->logLines = $lines = new \ArrayObject();
        Log::spy();
        foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical'] as $level) {
            Log::shouldReceive($level)->zeroOrMoreTimes()->andReturnUsing(function ($message, array $context = []) use ($lines, $level) {
                $lines[] = ['level' => $level, 'message' => $message, 'context' => $context];
            });
        }
        Carbon::setTestNow(self::NIGHT . ' 03:00:00'); // app timezone = Asia/Manila
        AppSetting::set('night_astra_enabled', '1');
        AstraEncoder::storeApiKey('test-key-not-real');
        config(['app.url' => 'https://likhaaitech.com', 'services.openai.astra_encoder_max_web' => 4]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function night(): NightAstraRun
    {
        return app(NightAstraRun::class);
    }

    protected function at(string $time): void
    {
        Carbon::setTestNow(self::NIGHT . ' ' . $time);
    }

    /** Order ng kahapon na blangko ang STATUS; pasado sa lahat ng gate kapag goodAnswer() ang sagot. */
    protected function order(array $extra = []): MacroOutput
    {
        return MacroOutput::create(array_merge([
            'TIMESTAMP'      => '21:14 04-10-2026',
            'ts_date'        => self::ORDERS,
            'PAGE'           => 'Likha Shop',
            'ITEM_NAME'      => 'Slimming Tea',
            'COD'            => '599',
            'all_user_input' => "Juan Dela Cruz\n09171234567\n12 Sampaguita St, Holy Spirit, Quezon City",
        ], $extra));
    }

    /** Sagot ni Astra na pasado sa lahat ng gate → PROCEED (kapareho ng fixture ng characterization test). */
    protected function goodAnswer(): array
    {
        return [
            'id'          => 'resp_1',
            'output_text' => json_encode([
                'form' => [
                    'name' => 'Juan Dela Cruz', 'phone' => '09171234567', 'house_number' => '12', 'address' => 'Sampaguita St',
                    'brgy' => 'Holy Spirit', 'city' => 'Quezon City', 'province' => 'Metro Manila',
                ],
                'jnt'        => ['province' => 'METRO-MANILA', 'city' => 'QUEZON-CITY', 'barangay' => 'HOLY SPIRIT'],
                'intent'     => 'order',
                'confidence' => 'high',
            ]),
            'usage' => ['input_tokens' => 200000, 'output_tokens' => 30000],
        ];
    }

    /** Macro import na natapos ngayong gabi (00:30 Manila kung walang ibinigay), may isang item kada status. */
    protected function macroImport(string $status = 'done', array $itemStatuses = ['done'], ?string $startedAt = null): MacroImportRun
    {
        $run = MacroImportRun::create(['status' => $status, 'started_at' => $startedAt ?? self::NIGHT . ' 00:30:00']);
        foreach ($itemStatuses as $itemStatus) {
            MacroImportRunItem::create(['run_id' => $run->id, 'status' => $itemStatus]);
        }

        return $run;
    }

    protected function likhaImport(string $status): LikhaImportRun
    {
        return LikhaImportRun::create(['status' => $status, 'started_at' => self::NIGHT . ' 02:00:00']);
    }

    protected function step(): ?NightRunStep
    {
        return NightRunStep::where('kind', 'astra')->first();
    }

    /** Tumatakbong run na may mga row na `queued` (na-dispatch na), nang hindi dumadaan sa start. */
    protected function runningStep(array $orderIds, array $stepExtra = [], array $rowExtra = []): NightRunStep
    {
        $step = NightRunStep::create(array_merge([
            'night_date' => self::NIGHT,
            'kind'       => 'astra',
            'state'      => 'running',
            'trigger'    => 'schedule',
            'started_at' => now(),
            'stop_at'    => self::NIGHT . ' 07:00:00',
            'rows_found' => count($orderIds),
        ], $stepExtra));

        foreach ($orderIds as $orderId) {
            NightAstraRow::create(array_merge([
                'step_id'         => $step->id,
                'macro_output_id' => $orderId,
                'state'           => 'queued',
                'dispatched_at'   => now(),
            ], $rowExtra));
        }

        return $step;
    }

    /**
     * Patakbuhin ang $run nang walang API key. Ang engine ay bumabasa rin ng key mula sa environment ng
     * makina: pinapawalang-laman lang sa process na ito, at ibinabalik pagkatapos.
     */
    protected function withoutApiKey(callable $run): void
    {
        AstraEncoder::storeApiKey(null);
        config(['services.openai.key' => null]);
        $saved = [];
        foreach (['ASTRA_ENCODER_API_KEY', 'OPENAI_API_KEY'] as $name) {
            $saved[$name] = [$_SERVER[$name] ?? null, $_ENV[$name] ?? null];
            $_SERVER[$name] = '';
            $_ENV[$name]    = '';
        }

        try {
            $run();
        } finally {
            foreach ($saved as $name => [$server, $env]) {
                if ($server === null) { unset($_SERVER[$name]); } else { $_SERVER[$name] = $server; }
                if ($env === null) { unset($_ENV[$name]); } else { $_ENV[$name] = $env; }
            }
        }
    }

    protected function rowFor(int $orderId): NightAstraRow
    {
        return NightAstraRow::where('macro_output_id', $orderId)->sole();
    }

    /** Ang worker: tinatakbo ang job ng row na ito ngayon din. */
    protected function work(int $orderId): NightAstraRow
    {
        (new RunNightAstraRow($this->rowFor($orderId)->id))->handle();

        return $this->rowFor($orderId);
    }

    /** [state kada row, ayon sa id] */
    protected function rowStates(): array
    {
        return NightAstraRow::orderBy('id')->pluck('state')->all();
    }
}

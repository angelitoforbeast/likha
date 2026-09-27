<?php

namespace App\Jobs\Boardroom;

use App\Boardroom\Orchestrator\MeetingOrchestrator;
use App\Boardroom\Orchestrator\TurnRunner;
use App\Models\Boardroom\Turn;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Isang job = isang turn = isang model call. Ang turn ID lang ang laman ng job —
 * walang prompt at walang API key sa queue payload.
 */
class RunTurnJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Ang retries ay hawak ng TurnRunner (bounded, para lang sa retryable). Hindi inuulit ng queue ang job.
    public int $tries = 1;

    // Dapat mas mababa sa retry_after ng database queue (1900s).
    public int $timeout = 1800;

    public function __construct(public int $turnId)
    {
    }

    public function handle(TurnRunner $runner): void
    {
        $runner->run($this->turnId);
    }

    public function failed(?\Throwable $e): void
    {
        $turn = Turn::find($this->turnId);
        if ($turn && $turn->status === 'generating') {
            app(MeetingOrchestrator::class)->failTurn(
                $turn, 'worker_failed', 'Naputol ang worker habang tumatakbo ang turn. Pwedeng i-retry.', true, (int) $turn->attempts
            );
        }
    }
}

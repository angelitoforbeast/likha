<?php

namespace App\Jobs;

use App\Models\OrderEncode;
use App\Services\OrderEncode\FlowEncode;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Si Astra para sa Encode order step: ang row na hindi PROCEED pagkatapos ng encode, sa express lane (astra-live), sa
 * background. Isang subok lang: ang pagpalya ni Astra ay "encoded, held" (naiwan ang row para sa staff).
 */
class RunEncodeAstra implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 540;
    public int $tries = 1;

    public function __construct(public int $orderEncodeId)
    {
        $this->onQueue((string) config('order_encode.queues.astra_live', 'astra-live'));
    }

    public function handle(FlowEncode $encode): void
    {
        $o = OrderEncode::find($this->orderEncodeId);
        if ($o) $encode->astraStep($o);
    }

    public function failed(Throwable $e): void
    {
        $o = OrderEncode::find($this->orderEncodeId);
        if ($o) app(FlowEncode::class)->afterAstra($o, ['status' => 'failed']);
    }
}

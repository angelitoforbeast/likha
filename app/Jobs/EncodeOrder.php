<?php

namespace App\Jobs;

use App\Models\OrderEncode;
use App\Services\OrderEncode\Runner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Ang encode at check ng isang order ng chatbot (spec 130), sa sariling queue na `encode` sa database connection. Hindi
 * hinihintay ng flow. Shadow: order_encodes lang ang sinusulat; live: pati ang macro_output row. Ang pagkabigo ng GPT ay
 * itinatala sa mga hakbang gaya ng script (hindi inuulit nang walang hanggan); ang hindi inaasahang error ay 2 subok.
 */
class EncodeOrder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const QUEUE = 'encode';

    public int $timeout = 600;
    public int $tries = 2;

    public function __construct(public int $orderEncodeId)
    {
        $this->onConnection('database')->onQueue(self::QUEUE);
    }

    public function backoff(): int
    {
        return 60;
    }

    public function handle(Runner $runner): void
    {
        $o = OrderEncode::find($this->orderEncodeId);
        if ($o) $runner->run($o);
    }

    /** Pagkatapos ng huling subok: failed; ang live na order ay isinusulat sa sheet (Runner::fail). */
    public function failed(Throwable $e): void
    {
        $o = OrderEncode::find($this->orderEncodeId);
        if ($o && $o->status !== 'done') app(Runner::class)->fail($o, $e->getMessage());
    }
}

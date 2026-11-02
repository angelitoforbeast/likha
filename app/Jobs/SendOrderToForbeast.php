<?php

namespace App\Jobs;

use App\Services\OrderEncode\ForbeastOrders;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Ang padala ng isang order sa Forbeast (spec 211), hiwalay sa encode (hindi ito hinihintay). Sa database queue
 * (order_encode.forbeast.queue). Ang pagkawala ng Forbeast (walang sagot, 5xx, 429) ay inuulit nang may pagitan hanggang
 * sa oras ng retry; ang pagtanggi (404, 422) ay failed agad; ang 401/403 ay pause (ForbeastOrders).
 */
class SendOrderToForbeast implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public function __construct(public int $orderEncodeId, public int $version, public bool $probe = false)
    {
        $this->onConnection('database')->onQueue((string) config('order_encode.forbeast.queue', 'default'));
    }

    /** Segundo bago ang bawat ulit; ang huli ay inuulit hanggang sa retryUntil. @return array<int, int> */
    public function backoff(): array
    {
        return [30, 60, 120, 300, 600, 900, 1800, 3600];
    }

    /** Hanggang kailan inuulit ang padala na hindi dumarating (Forbeast down). */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(max(1, (int) config('order_encode.forbeast.retry_hours', 24)));
    }

    public function handle(ForbeastOrders $orders): void
    {
        $r = $orders->send($this->orderEncodeId, $this->version, $this->probe);
        if ($r['result'] !== 'retry') return;

        // release, hindi throw: ang pagkawala ng Forbeast ay hindi error ng app (walang stack trace kada subok).
        $delays = $this->backoff();
        $this->release($delays[min(max(1, $this->attempts()) - 1, count($delays) - 1)]);
    }

    /** Lampas na sa oras ng retry (o hindi inaasahang error sa huling subok): failed, makikita ng may-ari. */
    public function failed(Throwable $e): void
    {
        app(ForbeastOrders::class)->gaveUp($this->orderEncodeId, $this->version, $e->getMessage());
    }
}

<?php

namespace App\Jobs;

use App\Services\Chatbot\ForbeastWatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Ang bantay ng gateway (ForbeastWatch::afterSend) para sa padala ng sequence na lumabas sa chatbot:sequence-pool: isang
 * beses kada totoong subok, kasama ang buong sagot. Nasa queue na "chatbot" at hindi sa loop ng pool, dahil ang kusang lipat
 * ay may sabay na pagpapatakbo ulit ng mga pumalyang sagot.
 */
class GatewayAfterSend implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 100;

    public function __construct(public string $page, public array $outcome, public mixed $body, public string $gateway)
    {
        $this->onConnection(config('queue.chatbot_connection'))->onQueue(RunChatbotFlow::QUEUE);
    }

    public function handle(ForbeastWatch $watch): void
    {
        try {
            $watch->afterSend($this->page, $this->outcome, $this->body, $this->gateway);
        } catch (Throwable $e) {
            report($e); // hindi kailanman humaharang sa padala, gaya ng GatewayProvider
        }
    }
}

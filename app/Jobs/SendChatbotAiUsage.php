<?php

namespace App\Jobs;

use App\Services\Chatbot\ChatbotAiUsage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Ang padala ng usage ng chatbot AI sa Forbeast ng bawat page (spec 210), kada minuto, sa queue na "chatbot" (hindi sa takbo
 * ng customer, kaya hindi nito napapabagal ang chat). Iisa lang ang tumatakbo; ang hindi naipadala ay sa susunod na minuto.
 */
class SendChatbotAiUsage implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;
    public int $timeout = 240;
    public int $uniqueFor = 300;

    public function __construct()
    {
        $this->onConnection(config('queue.chatbot_connection'))->onQueue(RunChatbotFlow::QUEUE);
    }

    public function handle(): void
    {
        app(ChatbotAiUsage::class)->sendAll();
    }
}

<?php

namespace App\Jobs;

use App\Services\Chatbot\ChatbotRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Isang hinihintay na lampas na sa skip_after (chatbot_waits), sa queue na "chatbot" kasama ng mga takbo. Ang naiwan
 * (locked, may chat pa ng customer na tatakbo) ay ipinapadala ulit ng tick pagkalipas ng ilang minuto; ang ulit na job
 * ay walang ginagawa (ChatbotRunner::skip: isang beses lang).
 */
class ResumeChatbotWait implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 280; // mas maikli sa lock ng customer (ChatbotRunner::LOCK_SECONDS)
    public int $tries = 1;

    public function __construct(public int $waitId)
    {
        $this->onConnection(config('queue.chatbot_connection'))->onQueue(RunChatbotFlow::QUEUE);
    }

    public function handle(ChatbotRunner $runner): void
    {
        $runner->skip($this->waitId);
    }
}

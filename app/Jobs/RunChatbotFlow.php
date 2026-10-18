<?php

namespace App\Jobs;

use App\Services\Chatbot\ChatbotRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Ang takbo ng flow ng chatbot para sa isang chat, sa sariling queue na "chatbot" (sariling worker). Kapag may
 * tumatakbo pang takbo ng parehong customer, bumabalik sa queue pagkalipas ng 3 s (hanggang 30 minuto). Ang ulit na job
 * ay walang ginagawa (ChatbotRunner: isang beses kada chat).
 */
class RunChatbotFlow implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const QUEUE = 'chatbot';

    public int $timeout = 280; // mas maikli sa lock ng customer (ChatbotRunner::LOCK_SECONDS)

    public function __construct(public int $chatId)
    {
        $this->onQueue(self::QUEUE);
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(30);
    }

    public function handle(ChatbotRunner $runner): void
    {
        if ($runner->handle($this->chatId) === 'locked' && $this->job) $this->release(3);
    }
}

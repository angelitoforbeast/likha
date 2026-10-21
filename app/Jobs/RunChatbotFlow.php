<?php

namespace App\Jobs;

use App\Services\Chatbot\ChatbotRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Ang takbo ng flow ng chatbot para sa isang chat, sa queue na "chatbot-high" (sariling workers sa Horizon, kaya ang unang
 * sagot ay hindi naghihintay sa likod ng mga sequence step sa "chatbot"). Kapag may
 * tumatakbo pang takbo ng parehong customer, bumabalik sa queue pagkalipas ng 3 s (hanggang 30 minuto). Ang ulit na job
 * ay walang ginagawa (ChatbotRunner: isang beses kada chat).
 */
class RunChatbotFlow implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const QUEUE = 'chatbot';
    public const HIGH_QUEUE = 'chatbot-high'; // ang reply path: RunChatbotFlow, ResumeChatbotAi at ResumeChatbotSend

    public int $timeout = 280; // mas maikli sa lock ng customer (ChatbotRunner::LOCK_SECONDS)

    public function __construct(public int $chatId)
    {
        $this->onConnection(config('queue.chatbot_connection'))->onQueue(self::HIGH_QUEUE);
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

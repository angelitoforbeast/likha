<?php

namespace App\Jobs;

use App\Services\Chatbot\ChatbotRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Ang resulta ng padala sa Botcake (botcake:send-pool) para sa isang ticket, sa queue na "chatbot-high": itinutuloy ang takbo
 * ng customer na pinadalhan. Kapag hawak pa ang lock ng customer, bumabalik pagkalipas ng 1 s. Ang ulit na job ay walang
 * ginagawa (ChatbotRunner::resumeSend: isang beses lang).
 */
class ResumeChatbotSend implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 280; // mas maikli sa lock ng customer (ChatbotRunner::LOCK_SECONDS)

    /** @param array{request_id: string, page_id: int, psid: int, chat_id: ?int, flow_id: int, node_id: string} $ticket */
    public function __construct(public array $ticket)
    {
        $this->onConnection(config('queue.chatbot_connection'))->onQueue(RunChatbotFlow::HIGH_QUEUE);
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(10);
    }

    public function handle(ChatbotRunner $runner): void
    {
        if ($runner->resumeSend($this->ticket) === 'locked' && $this->job) $this->release(1);
    }
}

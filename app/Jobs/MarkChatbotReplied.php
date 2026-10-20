<?php

namespace App\Jobs;

use App\Models\BotcakeChat;
use App\Services\Chatbot\ChatbotTags;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Results: dumating ang chat ng customer, kaya ang mga padala sa kanya sa page na ito na wala pang sagot (sa loob ng 24
 * oras) ay may replied_at at reply_seconds na. Magaan: isang SELECT na may index at ilang UPDATE. Ang hindi naabot nito
 * (hal. patay ang queue) ay pinupunan ng chatbot:replied-backfill bawat oras.
 */
class MarkChatbotReplied implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $chatId)
    {
        $this->onConnection(config('queue.chatbot_connection'))->onQueue(RunChatbotFlow::QUEUE);
    }

    public function handle(): void
    {
        $chat = BotcakeChat::find($this->chatId);
        if (!$chat || !ctype_digit((string) $chat->page_id) || !ctype_digit((string) $chat->psid) || !$chat->received_at) return;

        ChatbotTags::markReplied((int) $chat->page_id, (int) $chat->psid, $chat->received_at);
    }
}

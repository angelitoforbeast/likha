<?php

namespace App\Console\Commands;

use App\Services\Chatbot\ChatbotTags;
use Illuminate\Console\Command;

/** Results: pinupunan ang replied_at ng mga padala ng huling 48 oras na hindi naabot ng MarkChatbotReplied. Bawat oras. */
class ChatbotRepliedBackfill extends Command
{
    protected $signature = 'chatbot:replied-backfill';

    protected $description = 'Fill replied_at of chatbot sends from the last 48 hours that the chat job missed';

    public function handle(): int
    {
        $this->info('Filled ' . ChatbotTags::backfill() . ' send(s).');

        return self::SUCCESS;
    }
}

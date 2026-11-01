<?php

namespace App\Console\Commands;

use App\Services\Chatbot\ChatbotAiLoad;
use Illuminate\Console\Command;

/**
 * Ang load ng bawat page sa Forbeast (spec 210), kada minuto: kada 100 page kada gateway, para ang AI step ay may sariwang
 * sagot sa cache at hindi nagtatanong kada mensahe.
 */
class ChatbotAiLoadRefresh extends Command
{
    protected $signature = 'chatbot:ai-load';

    protected $description = 'Refresh the Forbeast AI load state of every chatbot page (batches of 100).';

    public function handle(ChatbotAiLoad $load): int
    {
        $this->line('Asked Forbeast ' . $load->refresh() . ' time(s).');

        return self::SUCCESS;
    }
}

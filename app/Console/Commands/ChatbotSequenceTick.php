<?php

namespace App\Console\Commands;

use App\Services\Chatbot\ChatbotSequences;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Ang mga sequence ng chatbot (kada minuto, via scheduler): ibinabalik ang mga naka-pause dahil Off ang page na On na
 * ulit, saka isang job kada customer na due na sa queue na "chatbot".
 */
class ChatbotSequenceTick extends Command
{
    protected $signature   = 'chatbot:sequence-tick';
    protected $description = 'Queue the due chatbot sequence steps, one job per subscribed customer';

    public function handle(ChatbotSequences $sequences): int
    {
        if (!Schema::hasTable('chatbot_sequence_subscriptions')) return self::SUCCESS;
        try {
            $sequences->subscribeNewOrders(); // bago ang tick, para ang delay 0 ay agad
        } catch (Throwable $e) {
            report($e); // hindi dapat humarang sa mga due na step
        }
        $queued = $sequences->tick();
        if ($queued) $this->info("chatbot sequences: {$queued} queued");

        return self::SUCCESS;
    }
}

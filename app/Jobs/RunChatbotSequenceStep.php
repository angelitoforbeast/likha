<?php

namespace App\Jobs;

use App\Services\Chatbot\ChatbotSequences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Isang step ng sequence para sa isang customer, sa queue na "chatbot" kasama ng mga takbo. Ang job na namatay sa gitna
 * ay kinukuha ulit ng tick pagkalipas ng 10 minuto (dispatched_at); ang ulit na job ay sinusuri ulit ang lahat.
 */
class RunChatbotSequenceStep implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 280; // mas maikli sa lock ng customer (ChatbotRunner::LOCK_SECONDS)
    public int $tries = 1;

    public function __construct(public int $subscriptionId)
    {
        $this->onQueue(RunChatbotFlow::QUEUE);
    }

    public function handle(ChatbotSequences $sequences): void
    {
        $sequences->runStep($this->subscriptionId);
    }
}

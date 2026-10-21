<?php

namespace App\Jobs;

use App\Services\Chatbot\ChatbotSequences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Isang step ng sequence para sa isang customer, sa queue na "chatbot" (ang mga unang sagot ay nasa "chatbot-high"). Isa
 * lang kada subscription habang naghihintay sa queue: ang lock ay binibitawan ng Laravel bago tumakbo ang handle, kaya ang
 * job na namatay sa gitna ay kinukuha ulit ng tick pagkalipas ng 10 minuto (dispatched_at); ang ulit na job ay sinusuri
 * ulit ang lahat.
 */
class RunChatbotSequenceStep implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 280; // mas maikli sa lock ng customer (ChatbotRunner::LOCK_SECONDS)
    public int $tries = 1;
    public int $uniqueFor = 3 * 3600; // mas mahaba sa pinakamahabang pila; ang job na nawala sa queue ay kinukuha ulit pagkatapos nito

    /** $claim: ang dispatched_at na itinala ng tick para sa job na ito; ang naunahan ng mas bagong claim ay walang ginagawa. */
    public function __construct(public int $subscriptionId, public ?string $claim = null)
    {
        $this->onConnection(config('queue.chatbot_connection'))->onQueue(RunChatbotFlow::QUEUE);
    }

    public function uniqueId(): string
    {
        return (string) $this->subscriptionId;
    }

    public function handle(ChatbotSequences $sequences): void
    {
        $sequences->runStep($this->subscriptionId, $this->claim);
    }
}

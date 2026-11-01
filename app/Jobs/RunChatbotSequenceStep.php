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
 * claim ay itinatala ulit sa simula ng step at may tanda habang tumatakbo (hanggang sa timeout); ang job na namatay ay kinukuha
 * ulit ng tick pagkalipas ng 2 minuto (ipinapasa ang padala) o 10 minuto (naghihintay ang job sa padala, gaya ng dati), at ang
 * step na nagsimula na ay hindi inuulit kapag may epekto na o maaaring naipadala na (ChatbotSequences::ATTEMPT).
 */
class RunChatbotSequenceStep implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = ChatbotSequences::INLINE_TIMEOUT; // mas maikli sa lock ng customer (ChatbotRunner::LOCK_SECONDS)
    public int $tries = 1;
    public int $uniqueFor = 3 * 3600; // mas mahaba sa pinakamahabang pila; ang job na nawala sa queue ay kinukuha ulit pagkatapos nito

    /**
     * $claim: ang dispatched_at na itinala ng tick para sa job na ito; ang naunahan ng mas bagong claim ay walang ginagawa.
     * $earlier: ang stamp ng naunang claim ng step (kinuha ulit ng tick), para hindi na ulitin ang naipadala na.
     */
    public function __construct(public int $subscriptionId, public ?string $claim = null, public ?string $earlier = null)
    {
        $this->onConnection(config('queue.chatbot_connection'))->onQueue(RunChatbotFlow::QUEUE);
    }

    public function uniqueId(): string
    {
        return (string) $this->subscriptionId;
    }

    public function handle(ChatbotSequences $sequences): void
    {
        $sequences->earlier($this->earlier, $this->timeout)->runStep($this->subscriptionId, $this->claim);
    }
}

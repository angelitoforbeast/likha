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
 * Ang resulta ng padala ng sequence step (chatbot:sequence-pool), sa queue na "chatbot" (hindi kailanman sa chatbot-high,
 * kaya hindi nauuna sa mga unang sagot): itinutuloy ang step mula sa send node, at umuusad kapag tapos. Isa lang kada ticket
 * habang naghihintay sa queue (ang ulit ng sweep ay hindi nagpapatong); ang ulit na job ay walang ginagawa (isang beses lang
 * ang resume). Kapag hawak ang lock ng customer, bumabalik pagkalipas ng 1 s.
 */
class ResumeChatbotSequenceSend implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 100; // ang step ay ipinapasa rin ang susunod na padala; walang hinihintay na API
    public int $uniqueFor = 900;

    /** @param array{request_id: string} $ticket */
    public function __construct(public array $ticket)
    {
        $this->onConnection(config('queue.chatbot_connection'))->onQueue(RunChatbotFlow::QUEUE);
    }

    public function uniqueId(): string
    {
        return (string) ($this->ticket['request_id'] ?? '');
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(15);
    }

    public function handle(ChatbotSequences $sequences): void
    {
        if ($sequences->resumeSend($this->ticket) === 'locked' && $this->job) $this->release(1);
    }
}

<?php

namespace App\Jobs;

use App\Services\Chatbot\CommentReplies;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Ang sagot sa isang comment (CommentReplies). Kapag puno na ang minuto ng page, ibinabalik sa queue hanggang sa susunod
 * na minuto (hindi bilang pagkabigo, hanggang 6 na oras). Ang error ay hindi inuulit: maaaring naipadala na ang private
 * message, at isa lang ang pinapayagan ng Meta.
 */
class HandleComment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $maxExceptions = 1;
    public int $until;

    public function __construct(public int $eventId)
    {
        $this->until = now()->addHours(6)->getTimestamp();
        $this->onConnection(config('queue.chatbot_connection'))->onQueue(RunChatbotFlow::QUEUE);
    }

    public function retryUntil(): \DateTimeInterface
    {
        return \Carbon\Carbon::createFromTimestamp($this->until);
    }

    public function handle(CommentReplies $replies): void
    {
        $wait = $replies->handle($this->eventId);
        if ($wait > 0) $this->release($wait);
    }
}

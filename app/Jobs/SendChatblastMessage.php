<?php

namespace App\Jobs;

use App\Services\ChatblastSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Isang mensahe ng /chatblast, sa queue na "chatblast" (sariling worker; ang default ay hawak ng Botcake sync).
 * Timeout 300 s: tatlong hakbang, bawat isa hanggang 4 na subok na 15 s at backoff na 2 + 4 + 8 s. Isang subok lang:
 * ang hindi natapos ay failed, at ang pagpapadala ulit ay pinipindot ng tao (hindi awtomatiko, dahil palabas ito).
 */
class SendChatblastMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 1;

    public function __construct(public int $messageId)
    {
        $this->onQueue(ChatblastSender::QUEUE);
    }

    public function handle(ChatblastSender $sender): void
    {
        $sender->process($this->messageId);
    }

    public function failed(?Throwable $e): void
    {
        app(ChatblastSender::class)->killed($this->messageId);
    }
}

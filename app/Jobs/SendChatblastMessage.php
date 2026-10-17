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
 * Timeout 330 s: isang J&T track para sa delivering (hanggang 30 s), at tatlong hakbang, bawat isa hanggang 4 na subok na 15 s at backoff na hanggang 10 s bawat isa (Retry-After
 * hanggang 10 s sa chatblast), mga 270 s sa pinakamasama. Isang subok lang:
 * ang hindi natapos ay failed (bago ang send_flow) o unknown (pagkatawag nito), at ang pagpapadala ulit ay pinipindot ng
 * tao (hindi awtomatiko, dahil palabas ito); ang unknown ay hindi na ipinapadala ulit.
 */
class SendChatblastMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 330; // + isang J&T track (hanggang 30 s) bago ang mga hakbang ng delivering
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

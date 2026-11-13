<?php

namespace App\Jobs;

use App\Services\Chatbot\ImageFlow;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Re-check one page's SEND IMAGE setup after a send_flow failed (spec 274). Unique per page for a while, so a burst of
 * failed images on one page asks Botcake once, not once per image.
 */
class CheckImageFlow implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const DELAY_SECONDS = 120;

    public const UNIQUE_SECONDS = 900;

    public int $tries = 1;

    public int $timeout = 60;

    public int $uniqueFor = self::UNIQUE_SECONDS;

    public function __construct(public string $page) {}

    /** Queue the re-check of the page (a few minutes later; at most one waiting per page). */
    public static function soon(string $page): void
    {
        self::dispatch($page)->delay(now()->addSeconds(self::DELAY_SECONDS));
    }

    public function uniqueId(): string
    {
        return mb_strtolower(trim($this->page));
    }

    public function handle(ImageFlow $flow): void
    {
        $flow->run($this->page);
    }
}

<?php

namespace App\Jobs;

use App\Services\Chatbot\PageContentCheck;
use App\Services\PageSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Ang content check (PageContentCheck, may tanong sa AI) ng isang page o ng lahat ng page ng chatbot (queue "default"):
 * gabi-gabi (chatbot:content-check), at ng "Check again" ng isang page. Ang naitala nang sagot ay hindi na itinatanong,
 * kaya ang bago o nabagong value lang ang may gastos. Hindi kailanman nasa loob ng page view.
 */
class RunChatbotContentCheck implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800; // mas maikli sa retry_after ng database queue (1900)
    public int $tries = 1;
    public int $uniqueFor = 3600;
    private const BUDGET_SECONDS = 1300; // isang page ay hanggang ~440 s (11 tanong × 40 s); ang hindi naabot ay sa susunod na takbo

    public function __construct(public ?string $page = null)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return $this->page === null ? '*' : mb_strtolower($this->page);
    }

    public function handle(PageSettings $settings, PageContentCheck $check): void
    {
        $started = microtime(true);
        $pages = $this->page !== null ? [$this->page] : $settings->pagesWith('botcake', ['page_id', 'token']);
        foreach ($pages as $page) {
            if (microtime(true) - $started > self::BUDGET_SECONDS) break;
            try {
                $check->run($page, true);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}

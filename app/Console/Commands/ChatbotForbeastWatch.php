<?php

namespace App\Console\Commands;

use App\Models\PageSetting;
use App\Services\Chatbot\ChatbotProvider;
use App\Services\Chatbot\ForbeastWatch;
use App\Services\ChatbotFields;
use App\Services\PageSettings;
use Illuminate\Console\Command;
use Throwable;

/**
 * Kada minuto: ang bawat page na nasa Forbeast ay sinusuri (health check at ang mga chat ng Botcake laban sa Forbeast); ang
 * pumapalya ay kusang inililipat sa Botcake (ForbeastWatch). Walang GATEWAY_URL: walang ginagawa.
 */
class ChatbotForbeastWatch extends Command
{
    protected $signature = 'chatbot:forbeast-watch';

    protected $description = 'Check every Forbeast page and switch the failing ones to Botcake';

    public function handle(ForbeastWatch $watch, PageSettings $settings): int
    {
        if (!ChatbotProvider::gatewayReady()) return self::SUCCESS;

        $pages = PageSetting::where('provider', ChatbotFields::PROVIDER)->where('key', 'provider')->where('active', true)->orderBy('id')->get()
            ->filter(fn ($s) => $s->plainValue() === ChatbotProvider::GATEWAY)->pluck('page_name')->unique(fn ($p) => $settings->pageKey((string) $p));
        foreach ($pages as $page) {
            try {
                $watch->health((string) $page);
                $pageId = trim((string) $settings->get((string) $page, 'botcake', 'page_id'));
                if ($pageId !== '') $watch->inbound((string) $page, $pageId);
            } catch (Throwable $e) {
                report($e); // isang page lang ang apektado
            }
        }

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\PageSetting;
use App\Services\Chatbot\ChatbotProvider;
use App\Services\Chatbot\ForbeastPageSetup;
use App\Services\Chatbot\ForbeastSendHealth;
use App\Services\Chatbot\ForbeastWatch;
use App\Services\Chatbot\Gateways;
use App\Services\ChatbotFields;
use App\Services\PageSettings;
use Illuminate\Console\Command;
use Throwable;

/**
 * Kada minuto: ang bawat page na nasa Forbeast ay sinusuri (health check at ang mga chat ng Botcake laban sa Forbeast); ang
 * pumapalya ay kusang inililipat sa Botcake (ForbeastWatch), at ang inilipat nito ay ibinabalik kapag maayos na. Binubura rin ang luma nang standby. Walang GATEWAY_URL: walang
 * sinusuri. Dalawang gateway: ang aktibong gateway ng page ang sinusuri at naglilipat (sa kabilang gateway muna, saka sa
 * Botcake); ang kabila ay sinusukat lang. Walang naka-set up na gateway (GATEWAY_URL, INCEPXIONBOT_URL): walang sinusuri.
 */
class ChatbotForbeastWatch extends Command
{
    protected $signature = 'chatbot:forbeast-watch';

    protected $description = 'Check every Forbeast page and switch the failing ones to Botcake';

    public function handle(ForbeastWatch $watch, PageSettings $settings): int
    {
        // Ang luma nang standby ay binubura kahit walang page sa Forbeast (ang natira pagkatapos ng lipat).
        try {
            $watch->prune();
        } catch (Throwable $e) {
            report($e);
        }
        if (!ChatbotProvider::gatewayReady()) return self::SUCCESS;

        $pages = PageSetting::where('provider', ChatbotFields::PROVIDER)->where('key', 'provider')->where('active', true)->orderBy('id')->get()
            ->filter(fn ($s) => $s->plainValue() === ChatbotProvider::GATEWAY)->pluck('page_name')->unique(fn ($p) => $settings->pageKey((string) $p));
        foreach ($pages as $page) {
            try {
                $watch->health((string) $page);
                // Ang kabilang gateway (naka-set up, may token ang page doon): sinusukat lang, para alam kung handa itong lipatan.
                $active = Gateways::active((string) $page);
                foreach (Gateways::setUpList() as $g) {
                    if ($g !== $active && app(ForbeastPageSetup::class)->on($g)->token((string) $page) !== '') $watch->health((string) $page, $g);
                }
                $pageId = trim((string) $settings->get((string) $page, 'botcake', 'page_id'));
                if ($pageId !== '') $watch->inbound((string) $page, $pageId);
                // Pagkalipat sa kabilang gateway: ang pumalyang sagot na naitala pagkatapos ng lipat ay sinasagot nang isang beses.
                $watch->rerunAfterSwitch((string) $page);
            } catch (Throwable $e) {
                report($e); // isang page lang ang apektado
            }
        }
        // Ang page na inilipat ng bantay sa Botcake (inbound o padala): ibinabalik sa Forbeast kapag matagal nang maayos ang health.
        $back = PageSetting::where('provider', ChatbotFields::PROVIDER)->where('key', ForbeastWatch::KEY)->where('active', true)->orderBy('id')->get()
            // Pati ang held at hindi makalipat: ang page sa kabilang gateway ay may lipat pa ring babalikan sa ilalim nito.
            ->filter(fn ($s) => ForbeastWatch::returnable($s) || ForbeastWatch::alarm($s)
                || in_array(json_decode((string) $s->plainValue(), true)['result'] ?? null, [ForbeastWatch::HELD, ForbeastWatch::CANNOT], true))->pluck('page_name')->unique(fn ($p) => $settings->pageKey((string) $p));
        foreach ($back as $page) {
            try {
                $watch->switchBack((string) $page);
            } catch (Throwable $e) {
                report($e);
            }
        }
        // Ang send health ng Forbeast (panel at send lag alarm): huli, para hindi nito maantala ang mga check sa itaas.
        try {
            app(ForbeastSendHealth::class)->fetch();
        } catch (Throwable $e) {
            report($e);
        }
        // Ang parehong alarm mula sa pending na sagot sa Likha (kahit hindi sumasagot ang health ng Forbeast).
        try {
            app(ForbeastSendHealth::class)->pendingCheck();
        } catch (Throwable $e) {
            report($e);
        }

        return self::SUCCESS;
    }
}

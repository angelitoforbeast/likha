<?php

namespace App\Services\Chatbot;

use App\Jobs\MarkChatbotReplied;
use App\Jobs\RunChatbotFlow;
use App\Models\BotcakeChat;
use Illuminate\Support\Facades\Cache;

/**
 * Ang kasunod ng bawat bagong chat ng customer, mula man sa Botcake (/api/botcake/chat) o sa Forbeast Gateway
 * (/api/gateway/events): iisang daan, para pareho ang sequence, Results at takbo ng flow.
 *
 * Ang pagbukas ng ad (referral ng gateway, marka AD_OPEN) ay itinatala lang: walang takbo, dahil tinatanggihan ng Meta
 * ang padala bago sumulat ang customer, at ang unang mensahe niya ang nagsisimula ng Main Flow (kasama ang ad, sa
 * AdGroups::latestAd). Sa Ad-based na page, ang unang mensahe ng bagong customer na wala pang ad ay naghihintay nang
 * HOLD_SECONDS sa referral (minsan nauuna ang mensahe sa referral); ang dumating na referral ang nagpapatakbo agad.
 */
class ChatbotInbound
{
    public const AD_OPEN = '_ad_open';
    public const HOLD_SECONDS = 2;
    private const HOLD_KEY = 'chatbot-ad-wait:';
    private const HOLD_KEY_SECONDS = 10;

    /** @param bool $waitForAd ang daan ng gateway lang: doon dumarating ang referral nang hiwalay sa mensahe */
    public static function afterStore(BotcakeChat $chat, string $pageName, bool $waitForAd = false): void
    {
        // Sumagot ang customer: alisin sa mga sequence ng page na may "exit when the customer replies".
        try {
            if (ctype_digit((string) $chat->page_id) && ctype_digit((string) $chat->psid)) {
                app(ChatbotSequences::class)->exitOnReply((int) $chat->page_id, (int) $chat->psid);
            }
        } catch (\Throwable $e) {
            report($e);
        }
        self::markReplied($chat);
        // Ang sariling chatbot: kapag may flow ang page (at naka-On), ang takbo ay sa queue na "chatbot". Ang
        // pumalyang pag-queue ay hindi nakakaapekto sa naimbak na chat.
        // Ang attachment lang (larawan, audio, video, …; hindi sticker o Like) ay hindi sinasagot habang naka-ignore.
        try {
            if (app(ChatbotPage::class)->runs($pageName) && !ChatAttachments::ignore($chat)) {
                if ($waitForAd && self::holdForAd($chat, $pageName)) {
                    Cache::put(self::holdKey($chat), $chat->id, self::HOLD_KEY_SECONDS);
                    RunChatbotFlow::dispatch($chat->id)->delay(now()->addSeconds(self::HOLD_SECONDS));
                } else {
                    RunChatbotFlow::dispatch($chat->id);
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Ang pagbukas ng ad: walang sequence exit at walang takbo (hindi pa sumusulat ang customer). Ang Results ay gaya ng
     * dati (ang chatbot:replied-backfill ay bumibilang din ng bawat chat). Ang naghihintay na unang mensahe ng customer na
     * ito ay pinapatakbo na ngayon, kasama ang ad na ito; ang naantalang job niya ay wala nang ginagawa (ChatbotRunner:
     * isang beses kada chat).
     */
    public static function adOpened(BotcakeChat $chat): void
    {
        self::markReplied($chat);
        try {
            $held = Cache::pull(self::holdKey($chat));
            if (is_int($held) || (is_string($held) && ctype_digit($held))) RunChatbotFlow::dispatch((int) $held);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Results: ang mga padala sa customer na wala pang sagot ay sinagot ng chat na ito. */
    private static function markReplied(BotcakeChat $chat): void
    {
        try {
            if (ctype_digit((string) $chat->page_id) && ctype_digit((string) $chat->psid)) MarkChatbotReplied::dispatch($chat->id);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Maghihintay sa referral: Ad-based na page na may group, walang sariling ad ang chat, at wala pang ibang chat ang
     * customer sa page (ang bago lang; ang may naunang chat ay may ad na o hindi galing sa ad ngayon).
     */
    private static function holdForAd(BotcakeChat $chat, string $pageName): bool
    {
        if (trim((string) ($chat->payload['ad_id'] ?? '')) !== '' || !app(AdGroups::class)->activeGroups($pageName)) return false;

        return !BotcakeChat::active()->where('page_id', (string) $chat->page_id)->where('psid', (string) $chat->psid)->where('id', '<>', $chat->id)->exists();
    }

    private static function holdKey(BotcakeChat $chat): string
    {
        return self::HOLD_KEY . $chat->page_id . ':' . $chat->psid;
    }
}

<?php

namespace App\Services\Chatbot;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Ang "typing…" habang nag-iisip ang bot (spec 191): sa simula ng takbo para sa mensahe ng customer sa page na nasa
 * Forbeast, isang POST /v1/pages/{page_id}/typing (Forbeast 028: mark_seen + typing_on sa Meta). Isa lang kada customer
 * kada 15 s, maikling timeout, at ang anumang palya ay binabalewala: hindi kailanman humaharang o nagpapapalya sa takbo.
 * Hindi para sa Botcake, Test o sequence (ChatbotRunner::handle lang ang tumatawag). services.gateway.typing = false: off.
 * Binibilang kada araw (Manila): calls at failed (walang text).
 */
class GatewayTyping
{
    private const ONCE_SECONDS = 15;
    private const TIMEOUT = 1.5; // segundo, connect at kabuuan

    public static function start(string $pageName, string $pageId, string $psid): void
    {
        try {
            if (!config('services.gateway.typing', true) || !BotcakeOutbox::enabled()) return;
            if (ChatbotProvider::name($pageName) !== ChatbotProvider::GATEWAY) return;
            // Ang URL at key ay gaya ng padala (GatewayProvider): ang token ng page, o ang global GATEWAY_KEY.
            $url = rtrim((string) config('services.gateway.url'), '/');
            $key = app(ForbeastPageSetup::class)->token($pageName);
            if ($key === '') $key = trim((string) config('services.gateway.key'));
            if ($url === '' || $key === '') return;
            if (!Cache::add('gateway-typing:' . $pageId . ':' . $psid, 1, self::ONCE_SECONDS)) return;
        } catch (Throwable $e) {
            report($e); // hindi pa tumatawag (hal. sirang cache o setting): nakikita sa log, tuloy ang takbo
            return;
        }

        self::count('calls');
        try {
            $ok = self::call($url, $key, $pageId, $psid);
        } catch (Throwable) {
            $ok = false; // timeout, walang koneksyon: binabalewala
        }
        if (!$ok) self::count('failed');
    }

    /** Ang tawag mismo (ang path at body ng Forbeast 028 ay dito lang). true = 2xx. */
    private static function call(string $url, string $key, string $pageId, string $psid): bool
    {
        return Http::withToken($key)->acceptJson()->connectTimeout(self::TIMEOUT)->timeout(self::TIMEOUT)
            ->post($url . '/v1/pages/' . rawurlencode($pageId) . '/typing', ['psid' => $psid])->successful();
    }

    /** Bilang kada araw (Manila), 3 araw: calls, failed. */
    private static function count(string $what): void
    {
        try {
            $key = 'gateway-typing:' . now('Asia/Manila')->toDateString() . ':' . $what;
            Cache::add($key, 0, now()->addDays(3));
            Cache::increment($key);
        } catch (Throwable) {
            // ang bilang ay hindi kailanman humaharang sa takbo
        }
    }
}

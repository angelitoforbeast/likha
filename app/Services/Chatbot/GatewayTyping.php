<?php

namespace App\Services\Chatbot;

use Illuminate\Http\Client\ConnectionException;
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
            if (!BotcakeOutbox::enabled()) return;
            if (ChatbotProvider::name($pageName) !== ChatbotProvider::GATEWAY) return;
            // Ang aktibong gateway ng page lang, ang URL at key ay gaya ng padala (GatewayProvider): ang token ng page doon, o
            // ang global key ng gateway. Ang typing ay off kada gateway (GATEWAY_TYPING, INCEPXIONBOT_TYPING).
            $gw = Gateways::active($pageName);
            $c = Gateways::config($gw);
            if (!$c['typing']) return;
            $url = $c['url'] !== '' ? Gateways::pageUrl($gw, $pageName) : ''; // ang address ng page
            $key = app(ForbeastPageSetup::class)->on($gw)->token($pageName);
            if ($key === '') $key = $c['key'];
            if ($url === '' || $key === '') return;
            if (!Cache::add('gateway-typing:' . $pageId . ':' . $psid, 1, self::ONCE_SECONDS)) return;
        } catch (Throwable $e) {
            report($e); // hindi pa tumatawag (hal. sirang cache o setting): nakikita sa log, tuloy ang takbo
            return;
        }

        self::count('calls');
        try {
            $ok = self::call($url, $key, $pageId, $psid);
        } catch (ConnectionException) {
            $ok = false; // timeout, walang koneksyon: binabalewala
        } catch (Throwable $e) {
            report($e); // hindi koneksyon (hal. sirang code): nakikita sa log, tuloy pa rin ang takbo
            $ok = false;
        }
        if (!$ok) self::count('failed');
    }

    /** Ang tawag mismo (ang path at body ng Forbeast 028 ay dito lang). true = 2xx. */
    private static function call(string $url, string $key, string $pageId, string $psid): bool
    {
        return Http::withToken($key)->acceptJson()->withOptions(Gateways::httpOptions())->connectTimeout(self::TIMEOUT)->timeout(self::TIMEOUT)
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

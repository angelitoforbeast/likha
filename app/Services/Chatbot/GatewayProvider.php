<?php

namespace App\Services\Chatbot;

use App\Services\BotcakeClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Ang padala sa gateway (Forbeast software): POST /v1/pages/{page_id}/messages (Bearer key) sa aktibong gateway ng page
 * (Gateways::active, o ang ibinigay sa constructor): isa lang, hindi kailanman pareho. Ang 2xx ay tinanggap ng gateway (siya
 * ang nag-aayos ng pagkakasunod kada PSID at ng rate kada page). Walang retry dito: ang parehong client_ref ay hindi
 * nadodoble sa gateway. Ang key ay ang token ng page sa gateway na iyon, o ang global key ng gateway (GATEWAY_KEY,
 * INCEPXIONBOT_KEY). Walang URL o key: failed, walang tawag. Ang request at ang pagbasa ng sagot ay pareho para sa
 * chatbot:sequence-pool (request, outcome).
 */
class GatewayProvider implements MessagingProvider
{
    public const NOT_SET_UP = 'Forbeast is not set up (GATEWAY_URL is empty, or no Forbeast token and no GATEWAY_KEY).';

    /** Ang gateway na huling ginamit (para sa via ng padala). */
    private ?string $last = null;

    public function __construct(private ?string $gateway = null)
    {
    }

    /** Ang gateway ng provider na ito: ang ibinigay, o ang ginamit sa huling padala; null = hindi pa alam. */
    public function gateway(): ?string
    {
        return $this->gateway ?? $this->last;
    }

    /** Ang error kapag hindi naka-set up ang gateway (walang tawag). */
    public static function notSetUp(string $gateway): string
    {
        if ($gateway === Gateways::FORBEAST) return self::NOT_SET_UP;
        $l = Gateways::label($gateway);
        $env = strtoupper($gateway);

        return $l . ' is not set up (' . $env . '_URL is empty, or no ' . $l . ' token and no ' . $env . '_KEY).';
    }

    /**
     * Ang request ng isang padala: ang gateway, URL, key, body at timeout; null kapag hindi naka-set up (walang tawag).
     * @return array{gateway: string, url: string, key: string, body: array, timeout: int}|null
     */
    public function request(string $pageName, string $pageId, string $psid, array $messages, string $clientRef, string $messagingType = self::RESPONSE): ?array
    {
        $gw = $this->gateway ?? Gateways::active($pageName);
        $this->last = $gw;
        $c = Gateways::config($gw);
        $url = $c['url']; // may carry a path prefix (e.g. https://host/api)
        // Ang token ng page sa gateway na ito (sariling page lang ang sakop); kung wala, ang global key ng gateway.
        $key = app(ForbeastPageSetup::class)->on($gw)->token($pageName);
        if ($key === '') $key = $c['key'];
        if ($url === '' || $key === '') return null;

        return ['gateway' => $gw, 'url' => $url . '/v1/pages/' . rawurlencode($pageId) . '/messages', 'key' => $key, 'timeout' => max(1, $c['timeout']),
            'body' => ['psid' => $psid, 'messages' => BotcakeOutbox::wire($messages), 'messaging_type' => $messagingType === self::UPDATE ? self::UPDATE : self::RESPONSE, 'client_ref' => $clientRef]];
    }

    public function send(string $pageName, string $pageId, string $psid, array $messages, string $clientRef, string $messagingType = self::RESPONSE): array
    {
        $q = $this->request($pageName, $pageId, $psid, $messages, $clientRef, $messagingType);
        if ($q === null) return ['status' => 'failed', 'error' => self::notSetUp((string) $this->last), 'http' => null];

        try {
            $res = Http::withToken($q['key'])->acceptJson()->connectTimeout(min(5, $q['timeout']))->timeout($q['timeout'])->post($q['url'], $q['body']);
            $r = ['http' => $res->status(), 'body' => $res->json() ?? $res->body()];
        } catch (ConnectionException) {
            $r = ['http' => 0, 'body' => null];
        } catch (Throwable $e) {
            report($e); // hindi koneksyon (hal. sirang code): nakikita sa log, failed pa rin ang padala
            $r = ['http' => 0, 'body' => null];
        }

        $o = self::outcome($r, $q['key']);
        // Ang bantay ng gateway (kusang lipat sa kabilang gateway o sa Botcake): hindi kailanman humaharang sa padala.
        try {
            app(ForbeastWatch::class)->afterSend($pageName, $o, $r['body'], $q['gateway']);
        } catch (Throwable $e) {
            report($e);
        }

        return $o;
    }

    /** @param array{http: int, body: mixed} $r */
    public static function outcome(array $r, string $key): array
    {
        if ($r['http'] >= 200 && $r['http'] < 300) return ['status' => 'sent', 'error' => null, 'http' => $r['http']];
        $msg = BotcakeClient::messageText(is_array($r['body']) ? $r['body'] : []);
        $why = $r['http'] === 0 ? 'Gateway did not answer' : ($msg !== '' ? 'Gateway said "' . mb_substr($msg, 0, 150) . '"' : 'Gateway refused it');
        $o = ['status' => 'failed', 'error' => $why . ' (HTTP ' . $r['http'] . ')', 'http' => $r['http'] ?: null];
        if (($raw = BotcakeOutbox::raw($r['body'], $key)) !== null) $o['raw'] = $raw;

        return $o;
    }
}

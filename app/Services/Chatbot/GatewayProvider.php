<?php

namespace App\Services\Chatbot;

use App\Services\BotcakeClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

    /** Ang address na tinawag sa huling padala (Gateways::route sa forbeast); null = walang tawag. */
    private ?string $lastRoute = null;

    public function __construct(private ?string $gateway = null)
    {
    }

    /** Ang gateway ng provider na ito: ang ibinigay, o ang ginamit sa huling padala; null = hindi pa alam. */
    public function gateway(): ?string
    {
        return $this->gateway ?? $this->last;
    }

    /** Ang address ng huling padala (para sa chatbot_replies.route); null = walang tawag. */
    public function route(): ?string
    {
        return $this->lastRoute;
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
        $this->lastRoute = null;
        $c = Gateways::config($gw);
        if ($c['url'] === '') return null;
        // Ang address ng page (chatbot.gateway_app) sa forbeast; may path prefix (hal. https://host/api).
        $route = $gw === Gateways::FORBEAST ? Gateways::route($pageName) : $gw;
        $url = Gateways::pageUrl($gw, $pageName);
        // Ang token ng page sa gateway na ito (sariling page lang ang sakop); kung wala, ang global key ng gateway.
        $key = app(ForbeastPageSetup::class)->on($gw)->token($pageName);
        if ($key === '') $key = $c['key'];
        if ($url === '' || $key === '') return null;
        $this->lastRoute = $route;

        return ['gateway' => $gw, 'route' => $route, 'url' => $url . '/v1/pages/' . rawurlencode($pageId) . '/messages', 'key' => $key, 'timeout' => max(1, $c['timeout']),
            'body' => ['psid' => $psid, 'messages' => BotcakeOutbox::wire($messages, $route), 'messaging_type' => $messagingType === self::UPDATE ? self::UPDATE : self::RESPONSE, 'client_ref' => $clientRef]];
    }

    /**
     * Ang detalye ng walang sagot (HTTP 0): ang cURL error number at text, at ang connect/total time, mula sa handler
     * context ng Guzzle (o ang "cURL error N: …" ng mensahe). Walang URL, token o laman ng mensahe.
     * @return array{curl_errno: ?int, curl_error: ?string, connect_time: ?float, total_time: ?float}
     */
    public static function noAnswer(?Throwable $e): array
    {
        $out = ['curl_errno' => null, 'curl_error' => null, 'connect_time' => null, 'total_time' => null];
        for ($x = $e; $x !== null; $x = $x->getPrevious()) {
            $ctx = method_exists($x, 'getHandlerContext') ? (array) $x->getHandlerContext() : [];
            if (isset($ctx['errno']) && is_numeric($ctx['errno'])) $out['curl_errno'] = (int) $ctx['errno'];
            if (isset($ctx['error']) && is_string($ctx['error']) && trim($ctx['error']) !== '') $out['curl_error'] = trim($ctx['error']);
            foreach (['connect_time', 'total_time'] as $t) if (isset($ctx[$t]) && is_numeric($ctx[$t])) $out[$t] = (float) $ctx[$t];
            if ($out['curl_errno'] === null && preg_match('/cURL error (\d+): (.*?)(?: \(see https?:[^)]*\))?(?: for https?:\/\/\S*)?$/s', $x->getMessage(), $m)) {
                $out['curl_errno'] = (int) $m[1];
                $out['curl_error'] ??= trim($m[2]);
            }
        }
        if ($out['curl_error'] !== null) $out['curl_error'] = mb_substr(preg_replace('/\s+/', ' ', $out['curl_error']), 0, 120);

        return $out;
    }

    /** Ang dagdag sa "Gateway did not answer (HTTP 0)": " · cURL error 28: … · connect 0.01 s, total 10.00 s"; '' kapag wala. */
    public static function noAnswerText(array $d): string
    {
        $text = $d['curl_errno'] !== null ? ' · cURL error ' . $d['curl_errno'] . ($d['curl_error'] !== null ? ': ' . $d['curl_error'] : '') : '';
        $times = array_filter(['connect' => $d['connect_time'], 'total' => $d['total_time']], fn ($t) => $t !== null);
        if ($times) $text .= ' · ' . implode(', ', array_map(fn ($k, $t) => $k . ' ' . number_format($t, 2, '.', '') . ' s', array_keys($times), $times));

        return $text;
    }

    /** Ang log ng walang sagot: ang page, gateway, address, host at ang detalye (walang token, walang text). */
    public static function logNoAnswer(string $pageName, array $q, array $d): void
    {
        Log::warning('gateway send: no answer', ['page' => $pageName, 'gateway' => $q['gateway'] ?? null, 'route' => $q['route'] ?? null,
            'host' => parse_url((string) ($q['url'] ?? ''), PHP_URL_HOST) ?: null] + $d);
    }

    public function send(string $pageName, string $pageId, string $psid, array $messages, string $clientRef, string $messagingType = self::RESPONSE): array
    {
        $q = $this->request($pageName, $pageId, $psid, $messages, $clientRef, $messagingType);
        if ($q === null) return ['status' => 'failed', 'error' => self::notSetUp((string) $this->last), 'http' => null];

        try {
            SequenceAttempt::beforeCall(); // ang padala ng sequence step: itinatala bago lumabas
            $res = Http::withToken($q['key'])->acceptJson()->withOptions(Gateways::httpOptions())->connectTimeout(min(5, $q['timeout']))->timeout($q['timeout'])->post($q['url'], $q['body']);
            $r = ['http' => $res->status(), 'body' => $res->json() ?? $res->body()];
        } catch (ConnectionException $e) {
            $r = ['http' => 0, 'body' => null, 'no_answer' => self::noAnswer($e)];
            self::logNoAnswer($pageName, $q, $r['no_answer']);
        } catch (Throwable $e) {
            report($e); // hindi koneksyon (hal. sirang code): nakikita sa log, failed pa rin ang padala
            $r = ['http' => 0, 'body' => null];
        }

        $o = self::outcome($r, $q['key'], $q['route']);
        // Ang bantay ng gateway (kusang lipat sa kabilang gateway o sa Botcake): hindi kailanman humaharang sa padala.
        try {
            app(ForbeastWatch::class)->afterSend($pageName, $o, $r['body'], $q['gateway'], $messagingType);
        } catch (Throwable $e) {
            report($e);
        }

        return $o;
    }

    /**
     * @param array{http: int, body: mixed, no_answer?: array} $r  no_answer: ang detalye ng walang sagot (noAnswer)
     * @param ?string $route ang address na tinawag (para sa 409 app_not_connected)
     */
    public static function outcome(array $r, string $key, ?string $route = null): array
    {
        if ($r['http'] >= 200 && $r['http'] < 300) return ['status' => 'sent', 'error' => null, 'http' => $r['http']];
        $body = is_array($r['body']) ? $r['body'] : [];
        // Walang token ang page para sa Meta app ng address na tinawag: sagot ito (hindi "walang sagot"), at hindi inuulit.
        if ($r['http'] === 409 && self::code($body) === 'app_not_connected') {
            return ['status' => 'failed', 'error' => 'This page is not connected to the ' . ($route ?? Gateways::FORBEAST) . ' Meta app on the gateway (HTTP 409)', 'http' => 409];
        }
        $msg = BotcakeClient::messageText($body);
        $why = $r['http'] === 0 ? 'Gateway did not answer' : ($msg !== '' ? 'Gateway said "' . mb_substr($msg, 0, 150) . '"' : 'Gateway refused it');
        $o = ['status' => 'failed', 'error' => $why . ' (HTTP ' . $r['http'] . ')' . ($r['http'] === 0 && isset($r['no_answer']) ? self::noAnswerText($r['no_answer']) : ''),
            'http' => $r['http'] ?: null];
        if (($raw = BotcakeOutbox::raw($r['body'], $key)) !== null) $o['raw'] = $raw;

        return $o;
    }

    /** Ang error code ng sagot ng gateway: "error" na text, error.code, o code. */
    private static function code(array $body): ?string
    {
        $c = is_array($body['error'] ?? null) ? ($body['error']['code'] ?? null) : ($body['error'] ?? $body['code'] ?? null);

        return is_string($c) ? trim($c) : null;
    }
}

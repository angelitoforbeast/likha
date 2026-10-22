<?php

namespace App\Services\Chatbot;

use App\Services\BotcakeClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Ang padala sa Forbeast Gateway: POST /v1/pages/{page_id}/messages (Bearer key). Ang 2xx ay tinanggap ng gateway (siya
 * ang nag-aayos ng pagkakasunod kada PSID at ng rate kada page). Walang retry dito: ang parehong client_ref ay hindi
 * nadodoble sa gateway. Ang key ay ang Forbeast token ng page, o ang global GATEWAY_KEY. Walang URL o key: failed, walang tawag.
 */
class GatewayProvider implements MessagingProvider
{
    public const NOT_SET_UP = 'Forbeast is not set up (GATEWAY_URL is empty, or no Forbeast token and no GATEWAY_KEY).';

    public function send(string $pageName, string $pageId, string $psid, array $messages, string $clientRef, string $messagingType = self::RESPONSE): array
    {
        $url = rtrim((string) config('services.gateway.url'), '/'); // may carry a path prefix (e.g. https://host/api)
        // Ang Forbeast token ng page (sariling page lang ang sakop); kung wala, ang global GATEWAY_KEY.
        $key = app(ForbeastPageSetup::class)->token($pageName);
        if ($key === '') $key = trim((string) config('services.gateway.key'));
        if ($url === '' || $key === '') return ['status' => 'failed', 'error' => self::NOT_SET_UP, 'http' => null];

        $timeout = max(1, (int) config('services.gateway.timeout', 10));
        try {
            $res = Http::withToken($key)->acceptJson()->connectTimeout(min(5, $timeout))->timeout($timeout)
                ->post($url . '/v1/pages/' . rawurlencode($pageId) . '/messages',
                    ['psid' => $psid, 'messages' => BotcakeOutbox::wire($messages), 'messaging_type' => $messagingType === self::UPDATE ? self::UPDATE : self::RESPONSE, 'client_ref' => $clientRef]);
            $r = ['http' => $res->status(), 'body' => $res->json() ?? $res->body()];
        } catch (ConnectionException) {
            $r = ['http' => 0, 'body' => null];
        } catch (Throwable $e) {
            report($e); // hindi koneksyon (hal. sirang code): nakikita sa log, failed pa rin ang padala
            $r = ['http' => 0, 'body' => null];
        }

        $o = $this->outcome($r, $key);
        // Ang bantay ng Forbeast (kusang lipat sa Botcake): hindi kailanman humaharang sa padala.
        try {
            app(ForbeastWatch::class)->afterSend($pageName, $o, $r['body']);
        } catch (Throwable $e) {
            report($e);
        }

        return $o;
    }

    /** @param array{http: int, body: mixed} $r */
    private function outcome(array $r, string $key): array
    {
        if ($r['http'] >= 200 && $r['http'] < 300) return ['status' => 'sent', 'error' => null, 'http' => $r['http']];
        $msg = BotcakeClient::messageText(is_array($r['body']) ? $r['body'] : []);
        $why = $r['http'] === 0 ? 'Gateway did not answer' : ($msg !== '' ? 'Gateway said "' . mb_substr($msg, 0, 150) . '"' : 'Gateway refused it');
        $o = ['status' => 'failed', 'error' => $why . ' (HTTP ' . $r['http'] . ')', 'http' => $r['http'] ?: null];
        if (($raw = BotcakeOutbox::raw($r['body'], $key)) !== null) $o['raw'] = $raw;

        return $o;
    }
}

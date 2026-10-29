<?php

namespace App\Services\Chatbot;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Ang sagot sa comment sa aktibong gateway ng page, gamit ang key na gaya ng GatewayProvider (ang token ng page sa gateway
 * na iyon, o ang global key nito): POST /v1/pages/{page}/comments/{comment}/reply {message} (public) at …/private-reply {text}
 * (ang isang private message sa nag-comment). Walang retry: ang Meta ay isang private reply lang kada comment.
 * Ang sagot ng Forbeast ay untrusted: maikling text lang ang itinatabi, hindi kailanman ang key.
 */
class GatewayComments
{
    /** Ang pinakamahabang text na tinatanggap ng Forbeast (letra): ang private message at ang public reply. */
    public const MAX_PM = 2000;
    public const MAX_REPLY = 8000;
    public const ALREADY_REPLIED = 'already_replied';
    public const UNKNOWN = 'unknown';
    public const THROTTLED = 'throttled';
    private const UNKNOWN_OUTCOME = 'unknown_outcome';
    private const META_ALREADY_REPLIED = 10900;

    /** @return array{status: string, error: ?string, http: ?int, comment_id: ?string, code: ?string} status = sent | failed | unknown | throttled */
    public function reply(string $pageName, string $pageId, string $commentId, string $message): array
    {
        return $this->post($pageName, $pageId, $commentId, 'reply', ['message' => $message]);
    }

    /** @return array{status: string, error: ?string, http: ?int, comment_id: ?string, code: ?string} status = sent | failed | already_replied | unknown | throttled */
    public function privateReply(string $pageName, string $pageId, string $commentId, string $text): array
    {
        return $this->post($pageName, $pageId, $commentId, 'private-reply', ['text' => $text]);
    }

    private function post(string $pageName, string $pageId, string $commentId, string $what, array $body): array
    {
        // Ang aktibong gateway ng page lang (hindi kailanman ang dalawa), gamit ang token ng page doon o ang global key nito.
        $gw = Gateways::active($pageName);
        $c = Gateways::config($gw);
        $url = $c['url'];
        $key = app(ForbeastPageSetup::class)->on($gw)->token($pageName);
        if ($key === '') $key = $c['key'];
        if ($url === '' || $key === '') return ['status' => 'failed', 'error' => GatewayProvider::notSetUp($gw), 'http' => null, 'comment_id' => null, 'code' => 'not_set_up'];

        $timeout = max(1, $c['timeout']);
        try {
            $res = Http::withToken($key)->acceptJson()->connectTimeout(min(5, $timeout))->timeout($timeout)
                ->post($url . '/v1/pages/' . rawurlencode($pageId) . '/comments/' . rawurlencode($commentId) . '/' . $what, $body);
            [$http, $json] = [$res->status(), $res->json()];
        } catch (ConnectionException) {
            [$http, $json] = [0, null];
        } catch (Throwable $e) {
            report($e);
            [$http, $json] = [0, null];
        }
        $json = is_array($json) ? $json : [];
        $str = fn ($v, int $max) => is_scalar($v) && trim((string) $v) !== '' ? mb_substr(mb_scrub(trim((string) $v), 'UTF-8'), 0, $max) : null;

        if ($http >= 200 && $http < 300 && ($json['ok'] ?? true) !== false) {
            return ['status' => 'sent', 'error' => null, 'http' => $http, 'comment_id' => $str($json['comment_id'] ?? null, 100), 'code' => null];
        }
        // Ang code ng error: "error" na text ({ok:false, error}), o object ({"error": {"code", "message"}}, ang 401/403/404/422
        // ng Forbeast), o ang validation ng Laravel ({message, errors: {field: […]}}) = invalid_<field>.
        $err = $json['error'] ?? null;
        $error = is_array($err) ? $str($err['code'] ?? null, 150) : $str($err, 150);
        $detail = is_array($err) ? $str($err['message'] ?? null, 150) : null;
        if ($error === null && is_array($json['errors'] ?? null) && $json['errors']) {
            $error = $str('invalid_' . preg_replace('/[^a-z0-9_]+/i', '_', (string) array_key_first($json['errors'])), 150);
            $detail = $str($json['message'] ?? null, 150);
        }
        if (is_array($err) && !isset($json['meta_code']) && isset($err['meta_code'])) $json['meta_code'] = $err['meta_code'];
        // 409 already_replied (Meta #10900): naitala na. 504 unknown_outcome: maaaring naipost na ng Meta, kaya hindi inuulit.
        // 429: subukan ulit sa susunod na minuto. Ang iba (401, 403, 404, 422, 502 …): failed.
        $status = match (true) {
            $error === self::ALREADY_REPLIED || (int) ($json['meta_code'] ?? 0) === self::META_ALREADY_REPLIED => self::ALREADY_REPLIED,
            $error === self::UNKNOWN_OUTCOME || $http === 504 => self::UNKNOWN,
            $http === 429 => self::THROTTLED,
            default => 'failed',
        };
        $l = Gateways::label($gw);
        $why = $http === 0 ? $l . ' did not answer' : ($error !== null ? $l . ' said "' . $error . ($detail !== null ? ': ' . $detail : '') . '"' : $l . ' refused it');
        if (($code = $str($json['meta_code'] ?? null, 10)) !== null) $why .= ' (Meta #' . $code . ')';

        return ['status' => $status, 'error' => $why . ' (HTTP ' . $http . ')', 'http' => $http ?: null, 'comment_id' => null,
            'code' => $error !== null ? mb_substr($error, 0, 20) : ($http === 0 ? 'no_answer' : 'http_' . $http)];
    }
}

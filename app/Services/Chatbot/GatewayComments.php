<?php

namespace App\Services\Chatbot;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Ang sagot sa comment sa Forbeast Gateway, gamit ang key na gaya ng GatewayProvider (ang Forbeast token ng page, o ang
 * global GATEWAY_KEY): POST /v1/pages/{page}/comments/{comment}/reply {message} (public) at …/private-reply {text}
 * (ang isang private message sa nag-comment). Walang retry: ang Meta ay isang private reply lang kada comment.
 * Ang sagot ng Forbeast ay untrusted: maikling text lang ang itinatabi, hindi kailanman ang key.
 */
class GatewayComments
{
    public const ALREADY_REPLIED = 'already_replied';
    private const META_ALREADY_REPLIED = 10900;

    /** @return array{status: string, error: ?string, http: ?int, comment_id: ?string} status = sent | failed */
    public function reply(string $pageName, string $pageId, string $commentId, string $message): array
    {
        return $this->post($pageName, $pageId, $commentId, 'reply', ['message' => $message]);
    }

    /** @return array{status: string, error: ?string, http: ?int, comment_id: ?string} status = sent | failed | already_replied */
    public function privateReply(string $pageName, string $pageId, string $commentId, string $text): array
    {
        return $this->post($pageName, $pageId, $commentId, 'private-reply', ['text' => $text]);
    }

    private function post(string $pageName, string $pageId, string $commentId, string $what, array $body): array
    {
        $url = rtrim((string) config('services.gateway.url'), '/');
        $key = app(ForbeastPageSetup::class)->token($pageName);
        if ($key === '') $key = trim((string) config('services.gateway.key'));
        if ($url === '' || $key === '') return ['status' => 'failed', 'error' => GatewayProvider::NOT_SET_UP, 'http' => null, 'comment_id' => null];

        $timeout = max(1, (int) config('services.gateway.timeout', 10));
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
            return ['status' => 'sent', 'error' => null, 'http' => $http, 'comment_id' => $str($json['comment_id'] ?? null, 100)];
        }
        $error = $str($json['error'] ?? null, 150);
        $status = $error === self::ALREADY_REPLIED || (int) ($json['meta_code'] ?? 0) === self::META_ALREADY_REPLIED ? self::ALREADY_REPLIED : 'failed';
        $why = $http === 0 ? 'Forbeast did not answer' : ($error !== null ? 'Forbeast said "' . $error . '"' : 'Forbeast refused it');
        if (($code = $str($json['meta_code'] ?? null, 10)) !== null) $why .= ' (Meta #' . $code . ')';

        return ['status' => $status, 'error' => $why . ' (HTTP ' . $http . ')', 'http' => $http ?: null, 'comment_id' => null];
    }
}

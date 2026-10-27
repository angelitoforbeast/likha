<?php

namespace App\Services\Chatbot;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Ang site na ito bilang "site system" ng Forbeast: ang Forbeast ang may talaan kung aling site (likha o incepxion) ang
 * may-ari ng bawat page. Ang mga tawag dito (GATEWAY_URL + /v1/site/..., Bearer FORBEAST_SITE_KEY) ay ang listahan ng
 * page, ang history, at ang take / request / accept / decline / cancel na may actor. Lahat ng sagot ay untrusted: text lang.
 * Walang key, secret o GATEWAY_URL = hindi naka-link (walang tawag).
 */
class ForbeastSite
{
    /** Ang dalawang partner site: key => pangalan sa screen. */
    public const SITES = ['likha' => 'Likha', 'incepxion' => 'Incepxion'];
    private const TIMEOUT = 15;
    /** Ang mga kilalang pagtanggi ng Forbeast (ang "error" ng sagot) => ang sinasabi sa screen. Ang iba: ang "message" nila. */
    private const REFUSALS = [
        'unknown_page' => 'Forbeast does not know this page for this site. Reload the list.',
        'unknown_transfer' => 'Forbeast has no such request for this site. It may be gone already; reload the list.',
        'not_owned' => 'This site does not own this page any more. Reload the list.',
        'not_set_up' => 'Forbeast has not finished setting up this site or page, so nothing was changed.',
        'too_many_pending' => 'This page already has 5 pending requests from this site. Cancel one first.',
        'invalid_request' => 'Forbeast did not accept the request. Reload the page and try again.',
        'too_many_requests' => 'Too many requests to Forbeast. Wait a minute and try again.',
    ];

    public static function linked(): bool
    {
        return trim((string) config('services.forbeast_site.key')) !== '' && trim((string) config('services.forbeast_site.secret')) !== ''
            && self::baseUrl() !== '';
    }

    /** Ang secret na pumipirma sa /api/gateway/site/... ('' = hindi naka-link). */
    public static function secret(): string
    {
        return self::linked() ? trim((string) config('services.forbeast_site.secret')) : '';
    }

    /**
     * Ang site na ito ayon sa host ng APP_URL (incepxion.pro = incepxion, ang iba = likha), para sa mga request ng
     * Forbeast na walang sinasabing site. Ang screen ay ang "site" ng GET /v1/site/pages ang gamit.
     */
    public static function self(): string
    {
        return str_contains(mb_strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST)), 'incepxion') ? 'incepxion' : 'likha';
    }

    /** Ang kabilang site ng $site. */
    public static function other(string $site): string
    {
        return $site === 'incepxion' ? 'likha' : 'incepxion';
    }

    public static function label(?string $site): string
    {
        return self::SITES[(string) $site] ?? (string) $site;
    }

    /** Ang phrase na tina-type ng CEO: "TAKE <page> TO <SITE>" o "TRANSFER <page> TO <SITE>" (ang site na pupuntahan). */
    public static function phrase(string $verb, string $page, string $toSite): string
    {
        return $verb . ' ' . self::squash($page) . ' TO ' . mb_strtoupper(self::label($toSite));
    }

    public static function squash(string $text): string
    {
        return preg_replace('/\s+/u', ' ', trim($text));
    }

    /** @return array{ok: bool, status: int, body: array, error: ?string} */
    public function pages(): array
    {
        return $this->call('GET', '/v1/site/pages');
    }

    /** @return array{ok: bool, status: int, body: array, error: ?string} */
    public function history(string $metaPageId): array
    {
        return $this->call('GET', '/v1/site/transfers', ['page' => $metaPageId]);
    }

    /** @return array{ok: bool, status: int, body: array, error: ?string} */
    public function take(string $metaPageId, string $actor, string $phrase): array
    {
        return $this->call('POST', '/v1/site/pages/' . rawurlencode($metaPageId) . '/take', ['actor' => $actor, 'phrase' => $phrase]);
    }

    /** @return array{ok: bool, status: int, body: array, error: ?string} */
    public function request(string $metaPageId, string $actor, string $phrase): array
    {
        return $this->call('POST', '/v1/site/pages/' . rawurlencode($metaPageId) . '/transfers', ['actor' => $actor, 'phrase' => $phrase]);
    }

    /**
     * accept (may phrase), decline o cancel ng isang request.
     * @return array{ok: bool, status: int, body: array, error: ?string}
     */
    public function answer(string $transferId, string $verb, string $actor, ?string $phrase = null): array
    {
        return $this->call('POST', '/v1/site/transfers/' . rawurlencode($transferId) . '/' . $verb, ['actor' => $actor] + ($phrase !== null ? ['phrase' => $phrase] : []));
    }

    private static function baseUrl(): string
    {
        return rtrim((string) config('services.gateway.url'), '/');
    }

    /** @return array{ok: bool, status: int, body: array, error: ?string} */
    private function call(string $method, string $path, array $data = []): array
    {
        if (!self::linked()) return ['ok' => false, 'status' => 0, 'body' => [], 'error' => 'This site is not linked to Forbeast.'];
        try {
            $http = Http::withToken(trim((string) config('services.forbeast_site.key')))->acceptJson()->connectTimeout(5)->timeout(self::TIMEOUT);
            /** @var Response $res */
            $res = $method === 'GET' ? $http->get(self::baseUrl() . $path, $data) : $http->post(self::baseUrl() . $path, $data);
        } catch (ConnectionException) {
            return ['ok' => false, 'status' => 0, 'body' => [], 'error' => 'Forbeast did not answer.'];
        } catch (Throwable $e) {
            report($e);
            return ['ok' => false, 'status' => 0, 'body' => [], 'error' => 'Forbeast did not answer.'];
        }
        $body = is_array($res->json()) ? $res->json() : [];
        if ($res->successful()) return ['ok' => true, 'status' => $res->status(), 'body' => $body, 'error' => null];
        $code = is_string($body['error'] ?? null) ? $body['error'] : '';
        if (isset(self::REFUSALS[$code])) return ['ok' => false, 'status' => $res->status(), 'body' => $body, 'error' => self::REFUSALS[$code] . ' (HTTP ' . $res->status() . ')'];
        $msg = is_scalar($body['message'] ?? null) ? mb_substr(trim((string) $body['message']), 0, 200) : '';

        return ['ok' => false, 'status' => $res->status(), 'body' => $body,
            'error' => ($msg !== '' ? 'Forbeast said "' . $msg . '"' : 'Forbeast refused it') . ' (HTTP ' . $res->status() . ').'];
    }
}

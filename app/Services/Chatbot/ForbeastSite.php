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
 *
 * Dalawang gateway: bawat isa ay may sariling site key at secret (FORBEAST_SITE_*, INCEPXIONBOT_SITE_*). Ang lahat ng
 * tawag ay sa iisang gateway (gateway(): forbeast muna), para iisa ang talaan ng may-ari; ang papasok na tawag
 * (GatewaySiteController) ay tinatanggap mula sa alinmang naka-link.
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

    /** May naka-link na gateway ($gateway: ang gateway na iyon lang): may site key, site secret at URL. */
    public static function linked(?string $gateway = null): bool
    {
        if ($gateway === null) return self::gateway() !== null;
        $c = Gateways::config($gateway);

        return Gateways::valid($gateway) && $c['site_key'] !== '' && $c['site_secret'] !== '' && $c['url'] !== '';
    }

    /** Ang secret na pumipirma sa /api/gateway/site/... ng gateway ('' = hindi naka-link). */
    public static function secret(string $gateway = Gateways::FORBEAST): string
    {
        return self::linked($gateway) ? Gateways::config($gateway)['site_secret'] : '';
    }

    /**
     * Ang gateway ng lahat ng tawag ng site: ang unang naka-link (forbeast muna; incepxionbot kapag ito lang). Iisang talaan ng
     * may-ari: ang listahan, ang take, ang request at ang sagot ay laging sa iisang gateway, kaya hindi nahahati ang mga
     * request sa dalawang talaan. null = walang naka-link.
     */
    public static function gateway(): ?string
    {
        foreach (Gateways::ALL as $g) if (self::linked($g)) return $g;

        return null;
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

    /**
     * Ang totoong reply time ng isang araw sa orasan ng Meta (customer sent → sagot namin sent), kada oras, mula sa
     * Forbeast. $timeout: segundo, para hindi maghintay nang matagal ang screen.
     * @return array{ok: bool, status: int, body: array, error: ?string}
     */
    public function replySpeed(string $date, ?string $pageId, int $timeout): array
    {
        return $this->call('GET', '/v1/site/reply-speed', ['date' => $date] + ($pageId !== null ? ['page_id' => $pageId] : []), $timeout);
    }

    /** @return array{ok: bool, status: int, body: array, error: ?string} */
    private function call(string $method, string $path, array $data = [], int $timeout = self::TIMEOUT): array
    {
        $gw = self::gateway();
        if ($gw === null) return ['ok' => false, 'status' => 0, 'body' => [], 'error' => 'This site is not linked to Forbeast.'];
        $c = Gateways::config($gw);
        try {
            $http = Http::withToken($c['site_key'])->acceptJson()->connectTimeout(min(5, $timeout))->timeout($timeout);
            /** @var Response $res */
            $res = $method === 'GET' ? $http->get($c['url'] . $path, $data) : $http->post($c['url'] . $path, $data);
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

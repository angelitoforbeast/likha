<?php

namespace App\Services\MetaAds;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Ang tawag sa Meta Graph API. Ang token ay nasa Authorization header (hindi sa URL), at ang anumang mensahe ng mali ay
 * nililinis ng clean() bago ibato, para hindi lumabas ang token o secret sa log o sa page.
 */
class Graph
{
    /** Ilang ulit susubukan kapag rate limit (4/17/613): 2s, 8s, 32s ang hintay. */
    public const TRIES = 4;

    public function version(): string
    {
        return trim((string) config('services.meta_ads.graph_version')) ?: 'v25.0';
    }

    public function base(): string
    {
        return 'https://graph.facebook.com/' . $this->version();
    }

    /**
     * GET sa isang path (hal. "me/adaccounts") o sa buong URL ng paging.next (graph.facebook.com lang). Ang user token ay
     * may kasamang appsecret_proof, kaya walang silbi ang ninakaw na token kung wala ang app secret. $proof = false para sa
     * app token (debug_token). $tries = 1 at maikling $timeout: isang tawag lang, walang hintay sa rate limit.
     * @return array ang JSON
     * @throws GraphException
     */
    public function get(string $pathOrUrl, array $query = [], ?string $token = null, bool $proof = true, int $tries = self::TRIES, int $timeout = 120): array
    {
        $full = preg_match('#^[a-z][a-z0-9+.-]*://#i', $pathOrUrl) === 1;
        if ($full && !self::isGraphUrl($pathOrUrl)) throw new GraphException('Not followed: a paging link to another host.');
        $url = $full ? $this->stripToken($pathOrUrl) : $this->base() . '/' . ltrim($pathOrUrl, '/');

        $secret = (string) config('services.meta_ads.app_secret');
        if ($token !== null && $proof && $secret !== '') {
            $hash = hash_hmac('sha256', $token, $secret);
            // Ang URL ng paging.next ay may sariling query: idinudugtong dito, dahil ang query option ay papalit sa buong query.
            if ($full) $url .= (str_contains($url, '?') ? '&' : '?') . 'appsecret_proof=' . $hash;
            else $query['appsecret_proof'] = $hash;
        }

        for ($try = 1; ; $try++) {
            try {
                $req = Http::timeout($timeout)->acceptJson();
                if ($token !== null) $req = $req->withToken($token);
                // Walang query = null: ang [] ay bumubura sa query ng URL (ang "after" ng paging.next).
                $res = $req->get($url, $query ?: null);
            } catch (Throwable $e) {
                throw new GraphException('Graph request failed: ' . self::clean($e->getMessage()));
            }

            $json = $res->json();
            if ($res->successful() && is_array($json) && !isset($json['error'])) return $json;

            $code = (int) ($json['error']['code'] ?? 0);
            $err  = new GraphException('Graph error ' . $code . ': ' . self::clean((string) ($json['error']['message'] ?? ('HTTP ' . $res->status()))), $code);
            if (!$err->rateLimited() || $try >= $tries) throw $err;

            Sleep::for(2 * (4 ** ($try - 1)))->seconds();
        }
    }

    /**
     * Lahat ng data ng isang listahan, sinusundan ang paging.next. Ang next na hindi https://graph.facebook.com ay hindi
     * sinusundan (ang token ay doon lang ipinapadala) at mali ito, para "partial" ang run at hindi tahimik na kulang.
     */
    public function all(string $path, array $query, string $token): array
    {
        $out  = [];
        $page = $this->get($path, $query, $token);
        $seen = [];
        while (true) {
            foreach ($page['data'] ?? [] as $row) $out[] = $row;
            $next = $page['paging']['next'] ?? null;
            // Walang susunod, o pareho ang next (hindi umuusad): tapos na.
            if (!is_string($next) || $next === '' || isset($seen[$next]) || empty($page['data'])) return $out;
            if (!self::isGraphUrl($next)) throw new GraphException('Not followed: a paging link to another host.');
            $seen[$next] = true;
            $page = $this->get($next, [], $token);
        }
    }

    /** https at eksaktong host na graph.facebook.com (walang user@, walang ibang port). */
    private static function isGraphUrl(string $url): bool
    {
        $p = parse_url($url);

        return is_array($p) && strtolower($p['scheme'] ?? '') === 'https' && strtolower($p['host'] ?? '') === 'graph.facebook.com'
            && !isset($p['user']) && !isset($p['pass']) && !isset($p['port']);
    }

    /** Ang access_token at appsecret_proof sa URL ng paging.next ay inaalis: sa header / sa dulo ito idinadagdag. */
    private function stripToken(string $url): string
    {
        return preg_replace('/([?&])(access_token|appsecret_proof)=[^&]*(&|$)/', '$1', preg_replace('/([?&])(access_token|appsecret_proof)=[^&]*(&|$)/', '$1', $url));
    }

    /** Ang token, secret at code ay tinatanggal sa anumang mensahe. */
    public static function clean(string $msg): string
    {
        $msg = preg_replace('/(access_token|client_secret|fb_exchange_token|input_token|appsecret_proof|code)=[^&\s"\']+/i', '$1=[hidden]', $msg);

        return preg_replace('/\bEA[A-Za-z0-9]{20,}/', '[hidden]', $msg);
    }
}

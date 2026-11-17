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

    /** How many times the sync tries an insights request that failed transiently (code 1/2, 5xx, no answer): waits 2 s, then 8 s. */
    public const TRANSIENT_TRIES = 3;

    /** How many times this client tries a transient error; 1 = not retried (every caller but the ones using retrying()). */
    private int $transientTries = 1;

    /**
     * A copy that tries a transient error (code 1/2, 5xx, no answer) $tries times, waiting 2 s, then 8 s. A copy, not
     * an argument of get(), so get()'s signature stays as it is for the classes that extend this one.
     */
    public function retrying(int $tries = self::TRANSIENT_TRIES): static
    {
        $copy = clone $this;
        $copy->transientTries = max(1, $tries);

        return $copy;
    }

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
     * A transient error is tried again only on a retrying() copy; a permanent error never.
     * @return array ang JSON
     * @throws GraphException
     */
    public function get(string $pathOrUrl, array $query = [], ?string $token = null, bool $proof = true, int $tries = self::TRIES, int $timeout = 120): array
    {
        return $this->send('GET', $pathOrUrl, $query, $token, $proof, $tries, $timeout);
    }

    /**
     * POST to a Graph path with form fields (an async insights report job). Same token header, appsecret_proof, retries
     * and clean() as get(). A full URL is refused: nothing posts to a paging link. @throws GraphException
     */
    public function post(string $path, array $form, string $token, int $tries = self::TRIES, int $timeout = 120): array
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $path) === 1) throw new GraphException('Not posted: a full URL.');

        return $this->send('POST', $path, $form, $token, true, $tries, $timeout);
    }

    private function send(string $method, string $pathOrUrl, array $query, ?string $token, bool $proof, int $tries, int $timeout): array
    {
        $full = preg_match('#^[a-z][a-z0-9+.-]*://#i', $pathOrUrl) === 1;
        if ($full && !self::isGraphUrl($pathOrUrl)) throw new GraphException('Not followed: a paging link to another host.');
        $url = $full ? $this->stripToken($pathOrUrl) : $this->base() . '/' . ltrim($pathOrUrl, '/');

        $secret = (string) AppConfig::appSecret();
        if ($token !== null && $proof && $secret !== '') {
            $hash = hash_hmac('sha256', $token, $secret);
            // Ang URL ng paging.next ay may sariling query: idinudugtong dito, dahil ang query option ay papalit sa buong query.
            if ($full) $url .= (str_contains($url, '?') ? '&' : '?') . 'appsecret_proof=' . $hash;
            else $query['appsecret_proof'] = $hash;
        }

        $rateTry = $transientTry = 0;
        while (true) {
            try {
                $req = Http::timeout($timeout)->acceptJson();
                if ($token !== null) $req = $req->withToken($token);
                // Walang query = null: ang [] ay bumubura sa query ng URL (ang "after" ng paging.next).
                $res = $method === 'POST' ? $req->asForm()->post($url, $query) : $req->get($url, $query ?: null);
            } catch (Throwable $e) {
                // No answer (timeout, connection reset) counts as transient: the same request may be answered next time.
                $res = null;
                $err = new GraphException('Graph request failed: ' . self::clean($e->getMessage()), 0, true);
            }

            if ($res !== null) {
                $json = $res->json();
                if ($res->successful() && is_array($json) && !isset($json['error'])) return $json;

                $code = (int) ($json['error']['code'] ?? 0);
                $err  = new GraphException('Graph error ' . $code . ': ' . self::clean((string) ($json['error']['message'] ?? ('HTTP ' . $res->status()))),
                    $code, (bool) ($json['error']['is_transient'] ?? false), $res->status());
            }

            if ($err->rateLimited() && ++$rateTry < $tries) {
                Sleep::for(2 * (4 ** ($rateTry - 1)))->seconds();
                continue;
            }
            if ($err->transient() && ++$transientTry < $this->transientTries) {
                Sleep::for(2 * (4 ** ($transientTry - 1)))->seconds();
                continue;
            }
            throw $err;
        }
    }

    /**
     * Lahat ng data ng isang listahan, sinusundan ang paging.next. Ang next na hindi https://graph.facebook.com ay hindi
     * sinusundan (ang token ay doon lang ipinapadala) at mali ito, para "partial" ang run at hindi tahimik na kulang.
     * $tries / $timeout: gaya ng get(), para sa bawat page (hal. isang subok lang sa loob ng web request).
     */
    public function all(string $path, array $query, string $token, int $tries = self::TRIES, int $timeout = 120): array
    {
        $out  = [];
        $page = $this->get($path, $query, $token, true, $tries, $timeout);
        $seen = [];
        while (true) {
            foreach ($page['data'] ?? [] as $row) $out[] = $row;
            $next = $page['paging']['next'] ?? null;
            // Walang susunod, o pareho ang next (hindi umuusad): tapos na.
            if (!is_string($next) || $next === '' || isset($seen[$next]) || empty($page['data'])) return $out;
            if (!self::isGraphUrl($next)) throw new GraphException('Not followed: a paging link to another host.');
            $seen[$next] = true;
            // A failing page is tried again as that page, so a transient error does not restart the list.
            $page = $this->get($next, [], $token, true, $tries, $timeout);
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

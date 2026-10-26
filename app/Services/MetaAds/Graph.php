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
     * GET sa isang path (hal. "me/adaccounts") o sa buong URL ng paging.next. @return array ang JSON
     * @throws GraphException
     */
    public function get(string $pathOrUrl, array $query = [], ?string $token = null): array
    {
        $url = str_starts_with($pathOrUrl, 'https://') ? $this->stripToken($pathOrUrl) : $this->base() . '/' . ltrim($pathOrUrl, '/');

        for ($try = 1; ; $try++) {
            try {
                $req = Http::timeout(120)->acceptJson();
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
            if (!$err->rateLimited() || $try >= self::TRIES) throw $err;

            Sleep::for(2 * (4 ** ($try - 1)))->seconds();
        }
    }

    /** Lahat ng data ng isang listahan, sinusundan ang paging.next. */
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
            // Ang token ay sa graph.facebook.com lang ipinapadala: ang next na ibang host ay hindi sinusundan.
            if (strtolower((string) parse_url($next, PHP_URL_SCHEME)) !== 'https' || strtolower((string) parse_url($next, PHP_URL_HOST)) !== 'graph.facebook.com') return $out;
            $seen[$next] = true;
            $page = $this->get($next, [], $token);
        }
    }

    /** Ang access_token sa URL ng paging.next ay inaalis: sa header na ito ipinapadala. */
    private function stripToken(string $url): string
    {
        return preg_replace('/([?&])access_token=[^&]*(&|$)/', '$1', $url);
    }

    /** Ang token, secret at code ay tinatanggal sa anumang mensahe. */
    public static function clean(string $msg): string
    {
        $msg = preg_replace('/(access_token|client_secret|fb_exchange_token|input_token|code)=[^&\s"\']+/i', '$1=[hidden]', $msg);

        return preg_replace('/\bEA[A-Za-z0-9]{20,}/', '[hidden]', $msg);
    }
}

<?php

namespace App\Services\Chatbot;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Kung gumagana ang link ng larawan ng isang botfield: http(s) lang, sumasagot ng 200 (o 206 sa GET na may Range) na
 * image/*, sa loob ng 5 segundo. Ang link ay galing sa nag-type, kaya bago ang bawat tawag (at bawat redirect, hanggang 3)
 * ang host ay nire-resolve at tinatanggihan kapag private, loopback, link-local o reserved ang alinmang IP; ang tawag ay
 * nakatali sa IP na nasuri (CURLOPT_RESOLVE), para hindi mapalitan ng DNS sa pagitan. Ang resulta ay naka-cache 24 oras
 * kada URL.
 */
class ImageLinkCheck
{
    public const CACHE_SECONDS = 86400;
    private const TIMEOUT = 5;
    private const MAX_REDIRECTS = 3;

    /** @var \Closure(string): array<int, string> host => mga IP */
    private \Closure $resolve;

    public function __construct(?\Closure $resolve = null)
    {
        $this->resolve = $resolve ?? fn (string $host) => gethostbynamel($host) ?: [];
    }

    public static function cacheKey(string $url): string
    {
        return 'chatbot:image-link:' . sha1($url);
    }

    public function cached(string $url): ?array
    {
        $hit = Cache::get(self::cacheKey($url));

        return is_array($hit) ? $hit : null;
    }

    /** @return array{ok: bool, reason: ?string} reason: "link broken (404)", "not an image", "link not allowed", "no answer" */
    public function check(string $url): array
    {
        $res = $this->run($url);
        Cache::put(self::cacheKey($url), $res, self::CACHE_SECONDS);

        return $res;
    }

    private function run(string $url): array
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $ip = $this->allowedIp($url);
            if ($ip === null) return self::fail('link not allowed');
            try {
                $r = $this->request('HEAD', $url, $ip);
                // Ang server na ayaw ng HEAD: isang byte lang ang hinihingi.
                if (in_array($r->status(), [403, 405, 501], true)) $r = $this->request('GET', $url, $ip, ['Range' => 'bytes=0-0']);
            } catch (Throwable $e) {
                return self::fail('no answer');
            }
            if ($r->status() >= 300 && $r->status() < 400) {
                $next = $this->absolute($url, (string) $r->header('Location'));
                if ($next === null) return self::fail('link broken (' . $r->status() . ')');
                $url = $next;
                continue;
            }
            if (!in_array($r->status(), [200, 206], true)) return self::fail('link broken (' . $r->status() . ')');
            if (!str_starts_with(strtolower(trim((string) $r->header('Content-Type'))), 'image/')) return self::fail('not an image');

            return ['ok' => true, 'reason' => null];
        }

        return self::fail('too many redirects');
    }

    private static function fail(string $reason): array
    {
        return ['ok' => false, 'reason' => $reason];
    }

    private function request(string $method, string $url, string $ip, array $headers = [])
    {
        $parts = parse_url($url);
        $port = $parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80);
        $host = trim($parts['host'], '[]');

        return Http::timeout(self::TIMEOUT)->connectTimeout(self::TIMEOUT)->withHeaders($headers)
            ->withOptions(['allow_redirects' => false, 'curl' => [CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $ip]]])
            ->send($method, $url);
    }

    /** Ang IP na tatawagan kapag http(s) at pampubliko lahat ng IP ng host, kung hindi null. */
    private function allowedIp(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || ($parts['host'] ?? '') === '') return null;
        if (isset($parts['user']) || isset($parts['pass'])) return null;
        $host = trim($parts['host'], '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (array) ($this->resolve)($host);
        if ($ips === []) return null;
        foreach ($ips as $ip) if (!self::isPublic((string) $ip)) return null;

        return (string) $ips[0];
    }

    public static function isPublic(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
        // Ang hindi saklaw ng mga flag: carrier-grade NAT (100.64.0.0/10) at ang IPv4 sa loob ng IPv6 (::ffff:10.0.0.1).
        if (preg_match('/^100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\./', $ip)) return false;
        if (stripos($ip, '::ffff:') === 0) return self::isPublic(substr($ip, 7));

        return true;
    }

    private function absolute(string $base, string $location): ?string
    {
        $location = trim($location);
        if ($location === '') return null;
        if (preg_match('#^https?://#i', $location)) return $location;
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location)) return null; // ibang scheme (hal. file:, ftp:)
        $p = parse_url($base);
        $root = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        if (str_starts_with($location, '//')) return $p['scheme'] . ':' . $location;
        if (str_starts_with($location, '/')) return $root . $location;

        return $root . rtrim(dirname($p['path'] ?? '/'), '/\\') . '/' . $location;
    }
}

<?php

namespace App\Services\Chatbot;

use App\Services\ChatbotFields;
use App\Services\PageSettings;

/**
 * Ang mga gateway (parehong Forbeast software, magkaibang Meta app): forbeast (ang dating services.gateway at
 * services.forbeast_site, parehong env) at incepxionbot (services.incepxionbot, INCEPXIONBOT_*). Ang gateway na walang
 * URL ay hindi naka-set up. Ang chatbot.gateway ng page ang aktibo kapag gateway ang provider ng page; wala = forbeast,
 * o ang nag-iisang naka-set up kapag hindi naka-set up ang forbeast (hal. Incepxion na incepxionbot lang).
 * Ang mga token ng page ay kada gateway: ang <gateway>.token, webhook_secret, token_status at permissions.
 */
class Gateways
{
    public const FORBEAST = 'forbeast';
    public const INCEPXIONBOT = 'incepxionbot';
    public const ALL = [self::FORBEAST, self::INCEPXIONBOT];
    public const LABELS = [self::FORBEAST => 'Forbeast', self::INCEPXIONBOT => 'Incepxionbot'];
    /** Ang page row ng aktibong gateway (chatbot.gateway). */
    public const KEY = 'gateway';
    /** Ang page row ng address ng forbeast gateway (chatbot.gateway_app, Gateways::routes). */
    public const ROUTE_KEY = 'gateway_app';
    /** Ang mga row ng koneksyon ng page sa isang gateway (provider = pangalan ng gateway). */
    public const PAGE_KEYS = ['token', 'webhook_secret', 'token_status', 'permissions'];

    public static function valid(?string $gateway): bool
    {
        return in_array($gateway, self::ALL, true);
    }

    public static function label(?string $gateway): string
    {
        return self::LABELS[(string) $gateway] ?? self::LABELS[self::FORBEAST];
    }

    /** Ang setting ng isang gateway: url (walang / sa dulo), key, webhook_secret, timeout, typing, site_key, site_secret, app_id. */
    public static function config(string $gateway): array
    {
        if ($gateway === self::INCEPXIONBOT) {
            $c = (array) config('services.incepxionbot');
            return ['url' => rtrim((string) ($c['url'] ?? ''), '/'), 'key' => trim((string) ($c['key'] ?? '')), 'webhook_secret' => (string) ($c['webhook_secret'] ?? ''),
                'timeout' => (int) ($c['timeout'] ?? 10), 'typing' => (bool) ($c['typing'] ?? true), 'site_key' => trim((string) ($c['site_key'] ?? '')),
                'site_secret' => trim((string) ($c['site_secret'] ?? '')), 'app_id' => trim((string) ($c['app_id'] ?? ''))];
        }

        return ['url' => rtrim((string) config('services.gateway.url'), '/'), 'key' => trim((string) config('services.gateway.key')),
            'webhook_secret' => (string) config('services.gateway.webhook_secret'), 'timeout' => (int) config('services.gateway.timeout', 10),
            'typing' => (bool) config('services.gateway.typing', true), 'site_key' => trim((string) config('services.forbeast_site.key')),
            'site_secret' => trim((string) config('services.forbeast_site.secret')), 'app_id' => ''];
    }

    public static function url(string $gateway): string
    {
        return self::config($gateway)['url'];
    }

    public static function setUp(string $gateway): bool
    {
        return self::valid($gateway) && self::url($gateway) !== '';
    }

    /** @return array<int, string> ang mga naka-set up, forbeast muna */
    public static function setUpList(): array
    {
        return array_values(array_filter(self::ALL, fn ($g) => self::setUp($g)));
    }

    /** Ang gateway kapag walang chatbot.gateway ang page: forbeast, o ang unang naka-set up kapag hindi naka-set up ang forbeast. */
    public static function default(): string
    {
        return self::setUp(self::FORBEAST) ? self::FORBEAST : (self::setUpList()[0] ?? self::FORBEAST);
    }

    /** Ang aktibong gateway ng page: ang chatbot.gateway kapag naka-set up ito, kung hindi ay ang default. */
    public static function active(string $page): string
    {
        $g = trim((string) app(PageSettings::class)->get($page, ChatbotFields::PROVIDER, self::KEY));

        return self::valid($g) && self::setUp($g) ? $g : self::default();
    }

    /**
     * Ang URL ng mga event ng gateway sa site na ito (ang webhook na inilalagay kada page): /api/gateway/events para sa
     * forbeast (gaya ng dati), /api/gateway/events/<gateway> para sa iba, kaya alam kung kaninong secret ang susuri.
     */
    public static function eventsUrl(string $gateway): string
    {
        return rtrim((string) config('app.url'), '/') . '/api/gateway/events' . ($gateway === self::FORBEAST ? '' : '/' . $gateway);
    }

    /** Ang kabilang gateway. */
    public static function other(string $gateway): string
    {
        return $gateway === self::INCEPXIONBOT ? self::FORBEAST : self::INCEPXIONBOT;
    }

    /** Ang mga gateway na mapipili sa page: naka-set up at gumagana ang token ng page doon. @return array<int, string> */
    public static function readyFor(string $page): array
    {
        return array_values(array_filter(self::setUpList(), fn ($g) => app(ForbeastPageSetup::class)->on($g)->ready($page)));
    }

    /**
     * Ang mga address ng forbeast gateway (iisang gateway, parehong key at token ng page; ang Meta app ay ang sa address na
     * tinawag): forbeast = GATEWAY_URL, saka ang GATEWAY_URLS ("key=url", hiwalay sa space o comma). Ang forbeast= sa
     * listahan ay hindi pinapansin (laging GATEWAY_URL ang default); ang maling entry ay nilalaktawan.
     * @return array<string, string> key => url (walang / sa dulo), forbeast muna
     */
    public static function routes(): array
    {
        $out = [self::FORBEAST => self::url(self::FORBEAST)];
        foreach (preg_split('/[\s,]+/', trim((string) config('services.gateway.urls', ''))) ?: [] as $entry) {
            if (!preg_match('/^([a-z0-9_-]{1,30})=(https?:\/\/\S+)$/', $entry, $m) || $m[1] === self::FORBEAST) continue;
            $out[$m[1]] ??= rtrim($m[2], '/');
        }

        return $out;
    }

    /** Ang pangalan ng address sa screen. */
    public static function routeLabel(string $route): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $route));
    }

    /**
     * Ang address ng page (chatbot.gateway_app): ang key kapag naka-configure, kung hindi ay forbeast. Ang hindi kilalang
     * key ay isang warning kada page at value kada araw.
     */
    public static function route(string $page): string
    {
        $key = trim((string) app(PageSettings::class)->get($page, ChatbotFields::PROVIDER, self::ROUTE_KEY));
        if ($key === '' || $key === self::FORBEAST) return self::FORBEAST;
        if (array_key_exists($key, self::routes())) return $key;
        if (\Illuminate\Support\Facades\Cache::add('gateway-route-unknown:' . sha1(app(PageSettings::class)->pageKey($page) . "\0" . $key), 1, 86400)) {
            \Illuminate\Support\Facades\Log::warning('gateway route: unknown address key, using the default', ['page' => $page, 'key' => mb_substr($key, 0, 50)]);
        }

        return self::FORBEAST;
    }

    /** Ang URL ng tawag para sa page (lahat ng nasa /v1/pages/{id}): ang address ng page sa forbeast, ang URL ng iba pang gateway. */
    public static function pageUrl(string $gateway, string $page): string
    {
        return $gateway === self::FORBEAST ? self::routes()[self::route($page)] : self::url($gateway);
    }

    /** Ang Guzzle options ng mga tawag sa gateway: IPv4 lang kapag GATEWAY_FORCE_IPV4 (CURLOPT_IPRESOLVE). */
    public static function httpOptions(): array
    {
        return config('services.gateway.force_ipv4') ? ['force_ip_resolve' => 'v4'] : [];
    }
}

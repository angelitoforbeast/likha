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
}

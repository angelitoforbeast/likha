<?php

namespace App\Services\Chatbot;

use App\Services\PageSettings;
use Illuminate\Support\Str;

/**
 * Aling daan ang gamit ng chatbot ng isang page: chatbot.provider = gateway (Forbeast Gateway), kung hindi ay Botcake
 * (ang default, pati ang ibang value). Ang Facebook page ID ng gateway ay ang botcake.page_id ng page.
 */
class ChatbotProvider
{
    public const BOTCAKE = 'botcake';
    public const GATEWAY = 'gateway';
    public const ALL = [self::BOTCAKE, self::GATEWAY];

    public static function name(string $pageName): string
    {
        return app(PageSettings::class)->get($pageName, 'chatbot', 'provider') === self::GATEWAY ? self::GATEWAY : self::BOTCAKE;
    }

    /** $once: isang subok lang sa Botcake (web request, hal. ang Inbox). */
    public static function for(string $pageName, bool $once = false): MessagingProvider
    {
        return self::name($pageName) === self::GATEWAY ? new GatewayProvider() : new BotcakeProvider(app(PageSettings::class), $once);
    }

    /** Mapipili ba ang gateway (may GATEWAY_URL)? */
    public static function gatewayReady(): bool
    {
        return (string) config('services.gateway.url') !== '';
    }

    /** Ang ID ng isang padala (client_ref ng gateway, hanggang 100 letra). */
    public static function ref(): string
    {
        return 'likha-' . Str::ulid();
    }
}

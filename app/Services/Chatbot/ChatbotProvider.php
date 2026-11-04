<?php

namespace App\Services\Chatbot;

use App\Models\BotcakeChat;
use App\Services\PageSettings;
use Illuminate\Support\Str;

/**
 * Aling daan ang gamit ng chatbot ng isang page: chatbot.provider = gateway (ang aktibong gateway ng page, Gateways:
 * forbeast o incepxionbot), kung hindi ay Botcake (ang default, pati ang ibang value). Ang Facebook page ID ng gateway
 * ay ang botcake.page_id ng page.
 */
class ChatbotProvider
{
    public const BOTCAKE = 'botcake';
    public const GATEWAY = 'gateway';
    public const FORBEAST = 'forbeast'; // ang via ng chat na galing sa gateway na forbeast
    public const ALL = [self::BOTCAKE, self::GATEWAY];

    public static function name(string $pageName): string
    {
        return app(PageSettings::class)->get($pageName, 'chatbot', 'provider') === self::GATEWAY ? self::GATEWAY : self::BOTCAKE;
    }

    /**
     * Aling bot ang may dala ng chat: botcake, o ang aktibong gateway ng page (forbeast, incepxionbot) para sa chat na galing
     * sa gateway (payload source gateway): iyon ang sasagot. Walang chat (hal. sequence) = ayon sa provider ng page.
     */
    public static function via(string $pageName, ?BotcakeChat $chat): string
    {
        if ($chat === null) return self::name($pageName) === self::GATEWAY ? Gateways::active($pageName) : self::BOTCAKE;
        if (($chat->payload['source'] ?? null) !== 'gateway') return self::BOTCAKE;

        return $pageName !== '' ? Gateways::active($pageName) : (Gateways::valid($chat->payload['gateway'] ?? null) ? $chat->payload['gateway'] : self::FORBEAST);
    }

    /** Ang via ng isang padala ayon sa daan na ginamit: ang gateway (forbeast, incepxionbot) o botcake. */
    public static function viaOf(MessagingProvider $provider): string
    {
        return $provider instanceof GatewayProvider ? ($provider->gateway() ?? self::FORBEAST) : self::BOTCAKE;
    }

    /** Ang address na dinaanan ng padala (GatewayProvider::route); null = Botcake o walang tawag. */
    public static function routeOf(MessagingProvider $provider): ?string
    {
        return $provider instanceof GatewayProvider ? $provider->route() : null;
    }

    /** Ang via ay isa sa mga gateway (hindi Botcake)? */
    public static function isGateway(?string $via): bool
    {
        return Gateways::valid($via);
    }

    /** Ang pangalan sa screen ng via: Botcake, Forbeast o Incepxionbot. */
    public static function viaLabel(string $via): string
    {
        return Gateways::valid($via) ? Gateways::label($via) : 'Botcake';
    }

    /**
     * Ang {{replies_via}} ng page: ang pangalan ng address ng page (Gateways::route, hal. "Incepxion") kapag forbeast ang
     * nagdadala at hindi default ang address; kung hindi, ang viaLabel. Walang GATEWAY_URLS = laging default ang address.
     */
    public static function repliesVia(string $pageName, string $via): string
    {
        if ($via === self::FORBEAST && $pageName !== '' && ($route = Gateways::route($pageName)) !== Gateways::FORBEAST) return Gateways::routeLabel($route);

        return self::viaLabel($via);
    }

    /**
     * Ang pangalan sa screen ng address ng isang padala (chatbot_replies.route, hal. "Incepxion") kapag forbeast ang nagdala
     * at hindi default ang address; null = gamitin ang pangalan ng via (gaya ng dati).
     */
    public static function routeLabelOf(?string $via, ?string $route): ?string
    {
        return $via === self::FORBEAST && $route !== null && $route !== '' && $route !== Gateways::FORBEAST ? Gateways::routeLabel($route) : null;
    }

    /** Ang via_label ng isang padala para sa JSON ng screen: kasama lang kapag may address na hindi default. */
    public static function viaLabelColumn(?string $via, ?string $route): array
    {
        return ($l = self::routeLabelOf($via, $route)) !== null ? ['via_label' => $l] : [];
    }

    /** $once: isang subok lang sa Botcake (web request, hal. ang Inbox). Ang gateway ay pinipili sa bawat padala (ang aktibo). */
    public static function for(string $pageName, bool $once = false): MessagingProvider
    {
        return self::name($pageName) === self::GATEWAY ? new GatewayProvider() : new BotcakeProvider(app(PageSettings::class), $once);
    }

    /** Mapipili ba ang gateway (may naka-set up na gateway: GATEWAY_URL o INCEPXIONBOT_URL)? */
    public static function gatewayReady(): bool
    {
        return Gateways::setUpList() !== [];
    }

    /** Ang ID ng isang padala (client_ref ng gateway, hanggang 100 letra). */
    public static function ref(): string
    {
        return 'likha-' . Str::ulid();
    }
}

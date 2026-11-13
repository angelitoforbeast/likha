<?php

namespace App\Services\Chatbot;

use App\Services\BotcakeClient;
use App\Services\PageSettings;

/** Ang padala sa Botcake (send_content), gaya ng dati. $once: isang subok lang (ang Inbox, isang web request). */
class BotcakeProvider implements MessagingProvider
{
    public function __construct(private PageSettings $settings, private bool $once = false)
    {
    }

    public function send(string $pageName, string $pageId, string $psid, array $messages, string $clientRef, string $messagingType = self::RESPONSE): array
    {
        $token = trim((string) $this->settings->get($pageName, 'botcake', 'token'));
        if ($token === '') return ['status' => 'failed', 'error' => BotcakeOutbox::NO_TOKEN, 'http' => null];

        $client = app(BotcakeClient::class)->shared(sha1($token), self::timeout());
        if ($this->once) $client = $client->once(15);
        // The bot's images go by the page's SEND IMAGE flow when its check is OK (spec 274). The Inbox ($once) is a person's
        // single-try send inside a web request: it stays send_content.
        if (!$this->once && ($cfg = ImageFlow::forSend($pageName, $messages)) !== null) {
            return ImageFlow::sendNow($client, $token, $pageName, $pageId, $psid, $messages, $cfg);
        }

        return BotcakeOutbox::outcome($client->sendContent($pageId, $psid, BotcakeOutbox::wire($messages), $token), $token);
    }

    /** Segundo na hinihintay ang Botcake sa send_content ng chatbot (services.botcake.send_timeout, 30 kapag wala). */
    public static function timeout(): int
    {
        return (int) config('services.botcake.send_timeout') ?: 30;
    }
}

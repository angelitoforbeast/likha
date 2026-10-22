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

        $client = app(BotcakeClient::class)->shared(sha1($token), 15);
        if ($this->once) $client = $client->once(15);

        return BotcakeOutbox::outcome($client->sendContent($pageId, $psid, BotcakeOutbox::wire($messages), $token), $token);
    }
}

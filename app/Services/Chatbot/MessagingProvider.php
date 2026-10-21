<?php

namespace App\Services\Chatbot;

/** Ang daan ng padala sa customer ng isang page: Botcake o ang Forbeast Gateway (ChatbotProvider ang pumipili). */
interface MessagingProvider
{
    /**
     * Ipadala ang mga mensahe sa PSID ng page. $clientRef: ang ID ng padalang ito (ang gateway ay hindi nagdodoble sa
     * parehong ref). Ang resulta ay gaya ng BotcakeOutbox::outcome.
     * @param array<int, array{type: string, text?: string, url?: string}> $messages
     * @return array{status: string, error: ?string, http: ?int, raw?: string}
     */
    public function send(string $pageName, string $pageId, string $psid, array $messages, string $clientRef): array;
}

<?php

namespace App\Services\Chatbot;

use App\Jobs\MarkChatbotReplied;
use App\Jobs\RunChatbotFlow;
use App\Models\BotcakeChat;

/**
 * Ang kasunod ng bawat bagong chat ng customer, mula man sa Botcake (/api/botcake/chat) o sa Forbeast Gateway
 * (/api/gateway/events): iisang daan, para pareho ang sequence, Results at takbo ng flow.
 */
class ChatbotInbound
{
    public static function afterStore(BotcakeChat $chat, string $pageName): void
    {
        // Sumagot ang customer: alisin sa mga sequence ng page na may "exit when the customer replies".
        try {
            if (ctype_digit((string) $chat->page_id) && ctype_digit((string) $chat->psid)) {
                app(ChatbotSequences::class)->exitOnReply((int) $chat->page_id, (int) $chat->psid);
            }
        } catch (\Throwable $e) {
            report($e);
        }
        // Results: ang mga padala sa customer na wala pang sagot ay sinagot ng chat na ito.
        try {
            if (ctype_digit((string) $chat->page_id) && ctype_digit((string) $chat->psid)) MarkChatbotReplied::dispatch($chat->id);
        } catch (\Throwable $e) {
            report($e);
        }
        // Ang sariling chatbot: kapag may flow ang page (at naka-On), ang takbo ay sa queue na "chatbot". Ang
        // pumalyang pag-queue ay hindi nakakaapekto sa naimbak na chat.
        try {
            if (app(ChatbotPage::class)->flow($pageName)) RunChatbotFlow::dispatch($chat->id);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}

<?php

namespace App\Services\Chatbot;

/**
 * botcake:reply-pool: the bot's replies (priority 0) in their own process, apart from chatblast. One loop of the send pool
 * does every claim, slot booking and answer of its jobs in turn, so a Delivering or in-transit run (thousands of three-call
 * sends) made each round slow and every reply waited for the next round. While this pool is alive, botcake:send-pool takes
 * only the chatblast; when it is not, botcake:send-pool takes both again. Both share each page's Botcake rate (Redis).
 */
class BotcakeReplyPool extends BotcakeSendPool
{
    public const BEAT = 'botcake-reply-pool:beat';

    protected function onlyReplies(): bool
    {
        return true;
    }
}

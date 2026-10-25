<?php

namespace App\Console\Commands;

use App\Services\BotcakeChatBlock;
use App\Services\BotcakeClient;
use App\Services\BotcakeCustomerSync;
use App\Services\Chatbot\ChatbotConnections;
use App\Services\Chatbot\ChatbotProvider;
use App\Services\Chatbot\ForbeastPageSetup;
use App\Services\ChatbotFields;
use App\Services\PageSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Gabi-gabi: ang Botcake token ng bawat page sa /chatbot/pages (botcake.token_status), ang chat block na isinusulat ulit
 * kapag tinanggal ito sa Default Reply ng page na in-On dito at nasa Botcake, at ang Forbeast token (forbeast.token_status). Kaya ang
 * Connected at Check ay ayon sa huling subok. Bilang lang ang nasa log, walang token.
 */
class ChatbotConnectionsCheck extends Command
{
    protected $signature = 'chatbot:connections-check';

    protected $description = 'Re-test every chatbot page\'s Botcake and Forbeast tokens and re-apply a missing chat block';

    public function handle(PageSettings $settings, BotcakeCustomerSync $sync, ForbeastPageSetup $forbeast, BotcakeChatBlock $chatBlock, ChatbotConnections $connections): int
    {
        $counts = ['pages' => 0, 'botcake_ok' => 0, 'botcake_bad' => 0, 'blocks' => 0, 'block_failed' => 0, 'not_checked_or_elsewhere' => 0, 'forbeast_ok' => 0, 'forbeast_bad' => 0, 'errors' => 0];
        foreach ($settings->pagesWith('botcake', ['page_id', 'token']) as $page) {
            $counts['pages']++;
            try {
                $pageId = trim((string) $settings->get($page, 'botcake', 'page_id'));
                $client = app(BotcakeClient::class)->once();
                $token = trim((string) $settings->get($page, 'botcake', 'token'));
                $ok = $client->tokenAndFields($pageId, $token)['ok'];
                $sync->tokenStatus($page, $ok ? 'ok' : 'invalid');
                $counts[$ok ? 'botcake_ok' : 'botcake_bad']++;
                // Ibinabalik lang ang block na tinanggal: page na in-On dito (active = 1, hindi ang walang row) at nasa
                // Botcake, at ang Default Reply ay walang JSON API block. Ibang site o hindi masuri: walang isinusulat.
                if ($ok && $settings->get($page, ChatbotFields::PROVIDER, 'active') === '1' && ChatbotProvider::name($page) === ChatbotProvider::BOTCAKE) {
                    $reply = $connections->botcakeReply($client, $pageId, $token);
                    if ($reply === 'none') $counts[$chatBlock->turnOn($page)['ok'] ? 'blocks' : 'block_failed']++;
                    elseif ($reply !== 'ours') $counts['not_checked_or_elsewhere']++;
                }
            } catch (Throwable $e) {
                report($e);
                $counts['errors']++;
            }
            if ($forbeast->token($page) === '') continue;
            try {
                $status = $forbeast->retest($page);
                if ($status !== 'skipped') $counts[$status === 'ok' ? 'forbeast_ok' : 'forbeast_bad']++;
            } catch (Throwable $e) {
                report($e);
                $counts['errors']++;
            }
        }
        Log::info('chatbot:connections-check', $counts);
        $this->line(json_encode($counts));

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Services\Chatbot\ChatbotConnections;
use App\Services\Chatbot\ChatbotProvider;
use App\Services\Chatbot\ForbeastPageSetup;
use App\Services\ChatbotFields;
use App\Services\PageSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Gabi-gabi: ang Test ng bawat page sa /chatbot/pages (ChatbotConnections::testBotcake: ang Botcake token at kung nasa
 * Default Reply pa ang block namin; at ang Forbeast token), na itinatala ng "system" kaya nasa Changes. Ang chat block ay
 * isinusulat ulit kapag tinanggal ito (o luma ang key) sa page na in-On dito at nasa Botcake. Kaya ang Connected at Check
 * ay ayon sa huling subok. Bilang lang ang nasa log, walang token.
 */
class ChatbotConnectionsCheck extends Command
{
    protected $signature = 'chatbot:connections-check';

    protected $description = 'Re-test every chatbot page\'s Botcake and Forbeast tokens and re-apply a missing chat block';

    public function handle(PageSettings $settings, ForbeastPageSetup $forbeast, ChatbotConnections $connections): int
    {
        $counts = ['pages' => 0, 'botcake_ok' => 0, 'botcake_bad' => 0, 'blocks' => 0, 'block_failed' => 0, 'not_checked_or_elsewhere' => 0, 'forbeast_ok' => 0, 'forbeast_bad' => 0, 'errors' => 0];
        foreach ($settings->pagesWith('botcake', ['page_id', 'token']) as $page) {
            $counts['pages']++;
            try {
                $test = $connections->testBotcake($page, null)['body'];
                $counts[$test['status'] === 'ok' ? 'botcake_ok' : 'botcake_bad']++;
                // Ibinabalik lang ang block na tinanggal: page na in-On dito (active = 1, hindi ang walang row) at nasa
                // Botcake, at ang Default Reply ay walang block namin o luma ang key. Ibang site o hindi masuri: walang isinusulat.
                if ($test['status'] === 'ok' && $settings->get($page, ChatbotFields::PROVIDER, 'active') === '1' && ChatbotProvider::name($page) === ChatbotProvider::BOTCAKE) {
                    $state = $test['block_state'] ?? 'unknown';
                    if (in_array($state, ['none', 'stale'], true)) $counts[$connections->chatBlock($page, null)['ok'] ? 'blocks' : 'block_failed']++;
                    elseif ($state !== 'ours') $counts['not_checked_or_elsewhere']++;
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

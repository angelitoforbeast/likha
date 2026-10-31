<?php

namespace App\Console\Commands;

use App\Services\Chatbot\ChatbotConnections;
use App\Services\Chatbot\ChatbotProvider;
use App\Services\Chatbot\FacebookName;
use App\Services\Chatbot\ForbeastPageSetup;
use App\Services\Chatbot\Gateways;
use App\Services\ChatbotFields;
use App\Services\PageSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Gabi-gabi: ang Test ng bawat page sa /chatbot/pages (ChatbotConnections::testBotcake: ang Botcake token at kung nasa
 * Default Reply pa ang block namin; at ang Forbeast token), na itinatala ng "system" kaya nasa Changes. Ang chat block ay
 * isinusulat ulit kapag tinanggal ito (o luma ang key) sa page na in-On dito at nasa Botcake. Kaya ang Connected at Check
 * ay ayon sa huling subok. Saka ang pangalan ng bawat page sa Facebook (FacebookName::lookup, isang beses kada page; ang
 * nabasa na ng Forbeast retest ay hindi na tinatanong ulit). Bilang lang ang nasa log, walang token.
 */
class ChatbotConnectionsCheck extends Command
{
    protected $signature = 'chatbot:connections-check';

    protected $description = 'Re-test every chatbot page\'s Botcake and Forbeast tokens, re-apply a missing chat block and look up each page\'s Facebook name';

    public function handle(PageSettings $settings, ForbeastPageSetup $forbeast, ChatbotConnections $connections, FacebookName $facebook): int
    {
        $counts = ['pages' => 0, 'botcake_ok' => 0, 'botcake_bad' => 0, 'blocks' => 0, 'block_failed' => 0, 'not_checked_or_elsewhere' => 0, 'forbeast_ok' => 0, 'forbeast_bad' => 0,
            'facebook_names' => 0, 'facebook_unknown' => 0, 'errors' => 0];
        $started = now();
        $pages = $settings->pagesWith('botcake', ['page_id', 'token']);
        $lookups = []; // page => ang mga gateway na na-probe na sa run na ito
        foreach ($pages as $page) {
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
            $probed = [];
            if ($forbeast->token($page) !== '') {
                $probed[] = Gateways::FORBEAST;
                try {
                    $status = $forbeast->retest($page);
                    if ($status !== 'skipped') $counts[$status === 'ok' ? 'forbeast_ok' : 'forbeast_bad']++;
                } catch (Throwable $e) {
                    report($e);
                    $counts['errors']++;
                }
            }
            $lookups[$page] = $probed;
        }
        // Ang page na gateway lang (page ID at gateway token, walang Botcake token) ay nasa listahan din ng /chatbot/pages.
        $listed = array_map(fn ($p) => $settings->pageKey($p), $pages);
        foreach ($settings->pagesWith('botcake', ['page_id']) as $page) {
            if (in_array($settings->pageKey($page), $listed, true)) continue;
            foreach (Gateways::ALL as $gw) {
                if ($forbeast->on($gw)->token($page) === '') continue;
                $lookups[$page] = [];
                break;
            }
        }
        // Pagkatapos ng lahat ng retest: ang doble ng page na nabasa na ng gateway ngayong run ay hindi na tumatawag sa Graph.
        foreach ($lookups as $page => $probed) $this->facebookName($facebook, (string) $page, $probed, $started, $counts);
        Log::info('chatbot:connections-check', $counts);
        $this->line(json_encode($counts));

        return self::SUCCESS;
    }

    /** Isang lookup ng pangalan sa Facebook; ang mali ay binibilang lang at hindi humihinto sa run. */
    private function facebookName(FacebookName $facebook, string $page, array $probed, \DateTimeInterface $since, array &$counts): void
    {
        try {
            $counts[$facebook->lookup($page, $probed, $since) !== null ? 'facebook_names' : 'facebook_unknown']++;
        } catch (Throwable $e) {
            report($e);
            $counts['errors']++;
        }
    }
}

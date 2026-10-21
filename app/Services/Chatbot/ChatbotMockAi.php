<?php

namespace App\Services\Chatbot;

use App\Services\BotcakeMock;

/**
 * Ang pekeng AI ng mga test page (stress test): walang tawag sa OpenAI. Para lang sa mock page ng BotcakeMock (botcake.mock = 1
 * AT page_id na 99…) na may chatbot.mock_ai = 1; ang totoong page ay hindi kailanman pinepeke. Ang sagot ay nagsasabi kung
 * kaninong tanong ito ("MOCK AI for psid <psid> chat <chat_id>"), para mabilang ng --stats ang naligaw na sagot.
 */
class ChatbotMockAi
{
    public const DEFAULT_LATENCY_MS = 2500;
    public const MAX_LATENCY_MS = 30000;
    public const PATTERN = '/MOCK AI for psid (\d+) chat (\d+|-)/';

    /** Ang bagal (ms) ng pekeng AI ng page, o null = totoong OpenAI. */
    public static function latency(int|string $pageId): ?int
    {
        return BotcakeMock::pages()[(string) $pageId]['ai'] ?? null;
    }

    public static function text(int|string $psid, int|string|null $chatId): string
    {
        return 'MOCK AI for psid ' . $psid . ' chat ' . ($chatId === null || $chatId === '' ? '-' : $chatId);
    }

    /** Ang sagot kapag naghihintay ang AI step (ai_async off): pagkatapos ng bagal, o null = totoong OpenAI. */
    public static function answer(FlowRun $run): ?array
    {
        if ($run->test || ($ms = self::latency($run->pageId)) === null) return null;
        if ($ms > 0) usleep($ms * 1000);

        return ['ok' => true, 'text' => self::text($run->fields->psid, $run->chatId), 'error' => null];
    }
}

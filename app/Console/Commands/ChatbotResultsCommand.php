<?php

namespace App\Console\Commands;

use App\Models\BotcakeChat;
use App\Services\Chatbot\ChatbotResults;
use App\Services\PageSettings;
use Illuminate\Console\Command;

/**
 * Ang Results ng chatbot para sa pagsusuri ni Mira: ang parehong JSON ng /chatbot/results.json (bilang, botfield at
 * version lang; walang text o PSID ng customer). Walang --json: ang funnel lang, isang linya bawat isa.
 */
class ChatbotResultsCommand extends Command
{
    protected $signature = 'chatbot:results {--page= : Page name or Botcake page ID (empty = all pages)} {--from= : Y-m-d (Manila)} {--to= : Y-m-d (Manila)}
        {--flow= : Flow ID} {--flow-version= : Flow version ID} {--compare= : flow or botfield} {--a=} {--b=} {--json : Print the full JSON}';

    protected $description = 'Chatbot results (funnel, by message, by sequence step, by ad) as JSON';

    public function handle(ChatbotResults $results, PageSettings $settings): int
    {
        $page = trim((string) $this->option('page'));
        $pageId = null;
        if ($page !== '') {
            $map = BotcakeChat::pageMap();
            $pageId = isset($map[$page]) ? $page : collect($map)->search(fn ($name) => $settings->pageKey((string) $name) === $settings->pageKey($page));
            if ($pageId === false || $pageId === null) {
                $this->error('Unknown page: ' . mb_substr($page, 0, 100));
                return self::FAILURE;
            }
        }
        $out = $results->output(array_filter(['page_id' => $pageId === null ? null : (string) $pageId, 'from' => $this->option('from'), 'to' => $this->option('to'),
            'flow_id' => $this->option('flow'), 'version_id' => $this->option('flow-version'), 'compare' => $this->option('compare'), 'a' => $this->option('a'), 'b' => $this->option('b')],
            fn ($v) => $v !== null));

        if ($this->option('json')) {
            $this->line(json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }
        $this->line(($out['filters']['page'] ?? 'All pages') . ' · ' . $out['filters']['from'] . ' to ' . $out['filters']['to']);
        foreach ($out['funnel'] as $k => $v) $this->line(str_pad($k, 16) . ($v ?? '—'));

        return self::SUCCESS;
    }
}

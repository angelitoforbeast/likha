<?php

namespace App\Console\Commands;

use App\Jobs\RunChatbotContentCheck;
use App\Services\Chatbot\PageContentCheck;
use App\Services\PageSettings;
use Illuminate\Console\Command;

/**
 * Ang content check ng /chatbot/pages (item, presyo, larawan, ad video): naka-queue ang takbo (gabi-gabi 02:40 Manila),
 * o dito mismo sa --sync. --json: ang mga resulta mula sa naitalang sagot ng AI (walang bagong tanong maliban sa --sync).
 * Read-only: walang value ng page na binabago.
 */
class ChatbotContentCheck extends Command
{
    protected $signature = 'chatbot:content-check {--page= : One page name} {--sync : Run now instead of queueing} {--json : Print the content findings per page as JSON}';
    protected $description = 'Check that each chatbot page talks about the item its orders are for: Main Flow item and price, pictures, ad videos';

    public function handle(PageSettings $settings, PageContentCheck $check): int
    {
        $page = trim((string) $this->option('page'));
        $page = $page === '' ? null : $page;
        if ($this->option('sync')) app()->call([new RunChatbotContentCheck($page), 'handle']);
        elseif (!$this->option('json')) {
            RunChatbotContentCheck::dispatch($page);
            $this->info('Queued the content check for ' . ($page ?? 'every chatbot page') . '.');
        }
        if (!$this->option('json')) return self::SUCCESS;

        $pages = $page !== null ? [$page] : $settings->pagesWith('botcake', ['page_id', 'token']);
        usort($pages, 'strcasecmp');
        $out = [];
        foreach ($pages as $p) $out[] = ['page' => $p] + $check->run($p, false);
        $this->line(json_encode(['pages' => $out], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}

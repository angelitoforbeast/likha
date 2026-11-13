<?php

namespace App\Console\Commands;

use App\Services\Chatbot\ImageFlow;
use App\Services\PageSettings;
use Illuminate\Console\Command;

/**
 * The SEND IMAGE setup check (spec 274) for one page or every Botcake page: the flow, the image_url field and the flow's
 * image card. Each page's calls share the page's setup lane (2 a second), one page after another.
 */
class BotcakeImageFlowCheck extends Command
{
    protected $signature = 'botcake:image-flow-check {page? : One page name} {--all : Every page with a Botcake page ID and token}';

    protected $description = 'Check each page\'s Botcake SEND IMAGE flow and image_url field (images go by flow when OK)';

    public function handle(PageSettings $settings, ImageFlow $flow): int
    {
        $page = trim((string) $this->argument('page'));
        if ($page === '' && ! $this->option('all')) {
            $this->error('Name a page, or use --all.');

            return self::FAILURE;
        }
        $pages = $page !== '' ? [$page] : $settings->pagesWith('botcake', ['page_id', 'token']);
        $rows = [];
        $counts = ['ok' => 0, 'not ok' => 0, 'failed' => 0];
        foreach ($pages as $p) {
            $r = $flow->run($p);
            if ($r['status'] === 'skipped') {
                $rows[] = [$p, 'skipped: no page ID or token'];

                continue;
            }
            if ($r['status'] === 'error') {
                $counts['failed']++;
                $rows[] = [$p, 'Check failed: '.$r['error']];

                continue;
            }
            $counts[$r['ok'] ? 'ok' : 'not ok']++;
            $rows[] = [$p, $r['ok'] ? 'Image: via flow' : 'Image: send_content — '.implode('; ', $r['reasons'])];
        }
        $this->table(['Page', 'Result'], $rows);
        $this->info('Image flow: '.$counts['ok'].' via flow, '.$counts['not ok'].' send_content, '.$counts['failed'].' could not be checked');

        return self::SUCCESS;
    }
}

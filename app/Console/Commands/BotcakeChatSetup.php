<?php

namespace App\Console\Commands;

use App\Services\BotcakeChatBlock;
use App\Services\PageSettings;
use Illuminate\Console\Command;

/**
 * Isinusulat sa Default Reply ng page ang "Chat to site" block at ipina-publish ito nang walang limit (BotcakeChatBlock,
 * kapareho ng On sa /chatbot). Pangalan at lagay lang ang ipinapakita, hindi kailanman ang key.
 */
class BotcakeChatSetup extends Command
{
    protected $signature = 'botcake:chat-setup
        {page? : The page name (as in page settings) or its Botcake page ID}
        {--all : Every page that has a Botcake ID and token}
        {--rotate : Make a new key even when the page has one}
        {--dry-run : Show what would be done; write nothing}
        {--confirm : Needed with --all when it is not a dry run}';

    protected $description = 'Write the "Chat to site" Default Reply block with each page\'s own chat key, and publish it';

    private const PLAN = ['create' => 'create a key', 'rotate' => 'rotate the key', 'keep' => 'keep the key'];
    private const DONE = ['create' => 'key created', 'rotate' => 'key rotated', 'keep' => 'key kept'];

    public function handle(PageSettings $settings, BotcakeChatBlock $chatBlock): int
    {
        $page = trim((string) $this->argument('page'));
        $all = (bool) $this->option('all');
        $dry = (bool) $this->option('dry-run');
        $rotate = (bool) $this->option('rotate');
        if ($page === '' && !$all) {
            $this->error('Name a page, or use --all.');
            return self::FAILURE;
        }
        if ($all && !$dry && !$this->option('confirm')) {
            $this->error('--all writes the Default Reply of every page: run it with --dry-run first, then add --confirm.');
            return self::FAILURE;
        }

        $targets = [];
        foreach ($settings->pagesWith('botcake', ['page_id', 'token']) as $name) {
            $pageId = trim((string) $settings->get($name, 'botcake', 'page_id'));
            if ($all || $settings->pageKey($name) === $settings->pageKey($page) || $pageId === $page) $targets[$name] = $pageId;
        }
        if (!$targets) {
            $this->error('No page with a Botcake ID and token matches.');
            return self::FAILURE;
        }

        $failed = 0;
        foreach ($targets as $name => $pageId) {
            if ($dry) {
                $this->line("{$name} ({$pageId}): would " . self::PLAN[$chatBlock->keyPlan($name, $rotate)] . ', write the Chat to site block and publish the Default Reply');
                continue;
            }

            $r = $chatBlock->turnOn($name, $rotate);
            if ($r['ok']) {
                $this->line("{$name} ({$pageId}): " . self::DONE[$r['key']] . ', block written and published');
                continue;
            }
            $failed++;
            $this->line("{$name} ({$pageId}): " . ($r['step'] === 'publish'
                ? 'block written and key saved, but publishing failed. ' . $r['error']
                : 'failed. ' . $r['error'] . ' Key unchanged.'));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}

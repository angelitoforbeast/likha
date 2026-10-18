<?php

namespace App\Console\Commands;

use App\Services\BotcakeClient;
use App\Services\PageSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Isinusulat sa Default Reply ng page ang "Chat to site" block (JSON API papunta sa /api/botcake/chat, may sariling
 * chat.key ng page sa header X-Chat-Key) at ipina-publish ito nang walang limit. Ang key ay ginagawa kapag wala pa (o
 * laging bago sa --rotate) at itinatala lang pagkatapos maisulat ang block, kaya ang pumalyang sulat ay hindi
 * nagpapalit ng key. Pinapalitan nito ang buong Default Reply ng page. Pangalan at lagay lang ang ipinapakita, hindi
 * kailanman ang key.
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

    /** Ang body na pinupunan ng Botcake (iisa sa bawat page). */
    public const BODY = ['page_id' => '{{page_id}}', 'psid' => '{{psid}}', 'name' => '{{user_full_name}}', 'message' => '{{last_text_input}}', 'source' => 'default_reply'];

    public function handle(PageSettings $settings): int
    {
        $page = trim((string) $this->argument('page'));
        $all = (bool) $this->option('all');
        $dry = (bool) $this->option('dry-run');
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
            $old = (string) $settings->get($name, 'botcake', 'chat.key');
            $new = $old === '' || $this->option('rotate') ? Str::random(48) : $old;
            $what = $old === '' ? 'create a key' : ($new !== $old ? 'rotate the key' : 'keep the key');

            if ($dry) {
                $this->line("{$name} ({$pageId}): would {$what}, write the Chat to site block and publish the Default Reply");
                continue;
            }

            $token = (string) $settings->get($name, 'botcake', 'token');
            $client = app(BotcakeClient::class); // bago kada page: sariling rate at retry
            $r = $client->call('POST', '/pages/' . rawurlencode($pageId) . '/default_reply/content', ['name' => 'Default reply', 'blocks' => [$this->block($new)]], $token);
            if (!$this->ok($r)) {
                $this->line("{$name} ({$pageId}): failed to write the block (HTTP {$r['http']}); key unchanged");
                $failed++;
                continue;
            }
            if ($new !== $old) $settings->set($name, 'botcake', 'chat.key', $new, true, null, true);

            $r = $client->call('POST', '/pages/' . rawurlencode($pageId) . '/default_reply', ['update' => ['is_published' => true, 'unlimit_default_reply' => true]], $token);
            if (!$this->ok($r)) {
                $this->line("{$name} ({$pageId}): block written and key saved, but publishing failed (HTTP {$r['http']})");
                $failed++;
                continue;
            }
            $this->line("{$name} ({$pageId}): " . ['create a key' => 'key created', 'rotate the key' => 'key rotated', 'keep the key' => 'key kept'][$what] . ', block written and published');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** Ang block na gumagana sa Botcake: kailangan ang body_preview at ang tatlong row na palaman, kung hindi ay walang body. */
    private function block(string $key): array
    {
        $pad = ['key' => '', 'value' => ''];
        $body = json_encode(self::BODY, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return [
            'key' => 'chat_to_site', 'title' => 'Chat to site', 'message_tag' => 'OTHER', 'next_step' => true, 'gotos' => [],
            'cards' => [[
                'plugin_id' => 'json_api',
                'config' => ['method' => 'post', 'url' => rtrim((string) config('app.url'), '/') . '/api/botcake/chat'],
                'headers' => [['key' => 'X-Chat-Key', 'value' => $key], $pad, $pad],
                'params' => [$pad, $pad, $pad],
                'body' => $body,
                'body_preview' => self::BODY,
                'response' => array_fill(0, 3, ['custom_field_id' => null, 'key' => '']),
                'buttons' => [],
            ]],
        ];
    }

    private function ok(array $r): bool
    {
        return $r['http'] >= 200 && $r['http'] < 300 && !(is_array($r['body']) && ($r['body']['success'] ?? null) === false);
    }
}

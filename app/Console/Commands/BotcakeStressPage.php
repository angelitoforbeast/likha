<?php

namespace App\Console\Commands;

use App\Models\BotcakeChat;
use App\Models\BotcakePsid;
use App\Models\PageSetting;
use App\Services\BotcakeMock;
use App\Services\ChatblastReady;
use App\Services\PageSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ang stress-test page (pekeng Botcake, BotcakeMock): --seed (ang page, ang chat.key sa file lang, at mga pekeng PSID),
 * --flow-from (ang chatbot settings ng ibang page, naka-On), --stats (chat papasok, takbo, sagot, AI, mock calls, queue,
 * oras hanggang unang sagot) at --cleanup (lahat ng row ng test page). Laging tinatanggihan ang page ID na hindi 99…, at
 * ang pangalan ng page na may totoong Botcake ID.
 */
class BotcakeStressPage extends Command
{
    public const PAGE = 'ZZ STRESS TEST';
    public const PAGE_ID = '990000000000001';

    protected $signature = 'botcake:stress-page
        {--page=' . self::PAGE . ' : The test page name}
        {--page-id= : The test Botcake page ID (must start with 99)}
        {--seed= : Create the page with this many fake PSIDs}
        {--key-file= : With --seed: the file the chat key is written to (mode 600; never printed)}
        {--flow-from= : Copy this page\'s chatbot settings (flow, botfields) to the test page and turn it On}
        {--stats : Print chats in, runs, replies, AI, mock calls, queue and the time to the first reply}
        {--cleanup : Delete every row of the test page and clear its caches}';
    protected $description = 'Set up, measure and clean up the stress-test page that runs against the fake Botcake';

    public function handle(PageSettings $settings): int
    {
        $page = $settings->normPage((string) $this->option('page'));
        $stored = trim((string) $settings->get($page, 'botcake', 'page_id'));
        $pageId = trim((string) ($this->option('page-id') ?: ($stored !== '' ? $stored : self::PAGE_ID)));

        if (!BotcakeMock::isTestId($pageId)) {
            $this->error('Refused: the page ID must start with ' . BotcakeMock::PREFIX . ' (test pages only).');
            return self::FAILURE;
        }
        if ($stored !== '' && !BotcakeMock::isTestId($stored)) {
            $this->error('Refused: "' . $page . '" has a real Botcake page ID.');
            return self::FAILURE;
        }
        if ($page === '') { $this->error('The page name is required.'); return self::FAILURE; }

        if ($this->option('seed') !== null) return $this->seed($settings, $page, $pageId);
        if ($this->option('flow-from') !== null) return $this->flowFrom($settings, $page);
        if ($this->option('stats')) return $this->stats($page, $pageId);
        if ($this->option('cleanup')) return $this->cleanup($settings, $page, $pageId);

        $this->error('Use --seed, --flow-from, --stats or --cleanup.');
        return self::FAILURE;
    }

    private function seed(PageSettings $settings, string $page, string $pageId): int
    {
        $n = filter_var($this->option('seed'), FILTER_VALIDATE_INT);
        $file = trim((string) $this->option('key-file'));
        if ($n === false || $n < 0 || $n > 100000) { $this->error('Use --seed from 0 to 100000.'); return self::FAILURE; }
        if ($file === '') { $this->error('--seed needs --key-file: the chat key is written only there.'); return self::FAILURE; }

        $key = bin2hex(random_bytes(24));
        if (@file_put_contents($file, $key . "\n") === false) { $this->error('Could not write the key file.'); return self::FAILURE; }
        @chmod($file, 0600);

        $settings->set($page, 'botcake', 'page_id', $pageId);
        $settings->set($page, 'botcake', 'token', 'mock');
        $settings->set($page, 'botcake', 'mock', '1', false, null, true);
        $settings->set($page, 'botcake', 'chat.key', $key, false, null, true);

        $rows = [];
        $now = now();
        for ($i = 1; $i <= $n; $i++) {
            // In order (99 + 0, 1, 2, …): the same PSIDs the k6 scripts send (psid(pool) in tests/stress/common.js), so every
            // chat is a known customer and needs no verify call (capped per page per minute).
            $rows[] = ['page' => $page, 'psid' => BotcakeMock::PREFIX . str_pad((string) ($i - 1), 14, '0', STR_PAD_LEFT), 'fb_name' => 'Stress ' . $i,
                'status' => 'available', 'source' => 'stress_test', 'first_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now];
            if (count($rows) === 1000 || $i === $n) { BotcakePsid::insert($rows); $rows = []; }
        }
        $this->info("Seeded \"{$page}\" (page ID {$pageId}, mock Botcake) with {$n} PSIDs. The chat key is in the key file.");

        return self::SUCCESS;
    }

    private function flowFrom(PageSettings $settings, string $page): int
    {
        $from = $settings->normPage((string) $this->option('flow-from'));
        $rows = $settings->pageQuery($from)->where('provider', 'chatbot')->where('active', true)->orderBy('id')->get();
        if (!$rows->where('key', 'flow_id')->count()) { $this->error('"' . $from . '" has no chatbot flow.'); return self::FAILURE; }
        foreach ($rows as $s) {
            if ($s->key === 'active') continue;
            $settings->set($page, 'chatbot', $s->key, (string) $s->plainValue(), (bool) $s->is_secret, null, true);
        }
        $settings->set($page, 'chatbot', 'active', '1', false, null, true);
        $this->info('Copied ' . ($rows->count()) . " chatbot settings from \"{$from}\" to \"{$page}\" and turned it On.");

        return self::SUCCESS;
    }

    private function stats(string $page, string $pageId): int
    {
        $id = (int) $pageId;
        $text = match (DB::getDriverName()) { 'pgsql' => 'detail::text', 'mysql' => 'CAST(detail AS CHAR)', default => 'detail' };
        $events = fn (string $type, ?string $like = null) => !Schema::hasTable('chatbot_events') ? 0
            : DB::table('chatbot_events')->where('page_id', $id)->where('type', $type)->when($like, fn ($q) => $q->whereRaw("$text LIKE ?", [$like]))->count();
        $replies = Schema::hasTable('chatbot_replies') ? DB::table('chatbot_replies')->where('page_id', $id)->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status') : collect();
        $jobs = Schema::hasTable('jobs') ? DB::table('jobs')->where('queue', 'chatbot')->count() : 0;

        // Ang oras mula sa pagdating ng chat hanggang sa unang naipadalang sagot nito (ayon sa chat_id).
        $times = [];
        if (Schema::hasTable('chatbot_replies')) {
            $first = DB::table('chatbot_replies')->where('page_id', $id)->where('status', 'sent')->whereNotNull('chat_id')
                ->selectRaw('chat_id, MIN(sent_at) AS sent')->groupBy('chat_id')->pluck('sent', 'chat_id');
            $received = BotcakeChat::whereIn('id', $first->keys()->all())->pluck('received_at', 'id');
            foreach ($first as $chatId => $sent) {
                if (isset($received[$chatId]) && $received[$chatId]) $times[] = max(0, \Illuminate\Support\Carbon::parse($sent)->getTimestamp() - $received[$chatId]->getTimestamp());
            }
        }
        sort($times);
        $p95 = $times ? $times[(int) ceil(0.95 * count($times)) - 1] : null;
        $mock = BotcakeMock::counts($pageId);

        $line = fn (string $label, $value) => $this->line(sprintf('%-26s %s', $label, $value));
        $this->line("Stress page \"{$page}\" (page ID {$pageId})");
        $line('Chats in', BotcakeChat::where('page_id', $pageId)->count());
        $line('Flow runs', $events('run_started'));
        $line('Replies sent', (int) ($replies['sent'] ?? 0));
        $line('Replies failed', (int) ($replies['failed'] ?? 0));
        $line('AI ok', $events('ai', '%"result":"success"%'));
        $line('AI failed', $events('ai', '%"result":"failed"%'));
        $line('Mock calls', $mock ? implode(', ', array_map(fn ($k, $v) => "{$k} {$v}", array_keys($mock), $mock)) : 'none');
        $line('Jobs waiting (chatbot)', $jobs);
        $line('Chat to first reply', $times ? sprintf('avg %.1f s, p95 %.1f s (%d chats)', array_sum($times) / count($times), $p95, count($times)) : 'no reply yet');

        return self::SUCCESS;
    }

    private function cleanup(PageSettings $settings, string $page, string $pageId): int
    {
        $id = (int) $pageId;
        $pk = $settings->pageKey($page);
        $trim = DB::getDriverName() === 'pgsql' ? 'BTRIM' : 'TRIM';
        $byName = fn (string $table, string $column) => DB::table($table)->whereRaw("LOWER($trim($column)) = ?", [$pk]);
        $counts = [];
        $counts['botcake_chats'] = DB::table('botcake_chats')->where('page_id', $pageId)->delete();
        // Ang mga PSID ng test (stress_test) at ang mga pinatunayan ng pekeng Botcake habang nagte-test (chat_verify).
        $counts['botcake_psids'] = $byName('botcake_psids', 'page')->whereIn('source', ['stress_test', 'chat_verify'])->delete();
        foreach (['chatbot_events', 'chatbot_customer_values', 'chatbot_replies', 'chatbot_sequence_subscriptions', 'chatbot_waits'] as $table) {
            $counts[$table] = Schema::hasTable($table) ? DB::table($table)->where('page_id', $id)->delete() : 0;
        }
        $counts['botcake_messages'] = Schema::hasTable('botcake_messages') ? $byName('botcake_messages', 'page_name')->delete() : 0;
        $counts['page_settings'] = $settings->pageQuery($page)->delete();

        BotcakeChat::forgetPageMap();
        BotcakeMock::reset($pageId);
        ChatblastReady::forget($pk);
        foreach ($counts as $table => $n) $this->line(sprintf('%-32s %d deleted', $table, $n));

        return self::SUCCESS;
    }
}

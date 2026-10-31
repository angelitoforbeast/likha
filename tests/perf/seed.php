<?php

/**
 * Seeds the perf database (after tests/perf/migrate.php) with a set the size of the biggest live install or bigger:
 * 160 active pages with every default value column filled (1–5 line texts, image links on the local server), 24 of
 * them Ad-based with ad groups, published flows, chats, sequences and a CEO test user for the timing script.
 * Only local test values; image links point at the local server, so nothing leaves the machine.
 * Run: php tests/perf/seed.php [base-url]
 */

$app = require __DIR__ . '/boot.php';

use App\Models\ChatbotFlow;
use App\Models\User;
use App\Services\Chatbot\AdGroups;
use App\Services\Chatbot\ChatbotPage;
use App\Services\Chatbot\FlowGraph;
use App\Services\ChatbotFields;
use App\Services\PageSettings;
use Illuminate\Support\Facades\DB;

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8209', '/');
const PAGES = 160;
const AD_BASED_EVERY = 7; // every 7th page is Ad-based (24 of 160)

mt_srand(209);
$t0 = microtime(true);
$s = app(PageSettings::class);

// The timing script signs in with this local test user (CEO: every button of the grid is shown).
$user = User::create(['name' => 'Perf CEO', 'email' => 'perf209@example.test', 'password' => 'perf209-local-only']);
$user->employeeProfile()->update(['role' => 'CEO']);

$words = ['Hello po', 'Salamat sa message', 'Available pa po ito', 'Free shipping nationwide', 'COD po tayo', 'Para sa inyong pamilya',
    'Original at authentic', 'Limited stocks lang po', 'Order na habang may promo', 'Pakisagot po ng form', 'Ilang piraso po', 'Delivery 3-5 days',
    'Pwede pong ibalik kung sira', 'Tanong lang po kayo', 'Best seller namin ito', 'May discount kapag dalawa'];
$text = function (int $lines) use ($words): string {
    $out = [];
    for ($i = 0; $i < $lines; $i++) {
        $n = mt_rand(3, 7);
        $line = [];
        for ($j = 0; $j < $n; $j++) $line[] = $words[mt_rand(0, count($words) - 1)];
        $out[] = implode(', ', $line) . '.';
    }
    return implode("\n", $out);
};

// Flows: one big published flow that uses most values (so Check has real work) and a few small ones.
$nodes = [['id' => 'n0', 'type' => 'start']];
$labels = ChatbotFields::defaults();
$i = 1;
foreach ($labels as $key => $label) {
    if ($key === 'flow') continue;
    $kind = ChatbotFields::kind($key);
    $item = $kind === 'image' ? ['type' => 'image', 'value' => '{{' . $label . '}}'] : ['type' => 'text', 'value' => '{{' . $label . '}}'];
    $nodes[] = ['id' => 'n' . $i++, 'type' => 'send', 'config' => ['items' => [$item]]];
}
$flows = [];
foreach (['Main Sales Flow' => $nodes, 'Short Flow A' => array_slice($nodes, 0, 8), 'Short Flow B' => array_slice($nodes, 0, 12), 'Promo Flow' => array_slice($nodes, 0, 20)] as $name => $list) {
    $f = ChatbotFlow::create(['name' => $name, 'graph' => FlowGraph::clean(['nodes' => $list, 'edges' => []])]);
    DB::table('chatbot_flows')->where('id', $f->id)->update(['published_at' => now()]);
    $flows[] = $f->fresh();
}
for ($k = 1; $k <= 16; $k++) {
    $f = ChatbotFlow::create(['name' => 'Flow ' . $k, 'graph' => FlowGraph::clean(['nodes' => array_slice($nodes, 0, 10 + $k * 2), 'edges' => []])]);
    DB::table('chatbot_flows')->where('id', $f->id)->update(['published_at' => now()]);
}

$adGroups = app(AdGroups::class);
$chats = [];
for ($p = 1; $p <= PAGES; $p++) {
    $page = sprintf('Perf Page %03d', $p);
    $pageId = (string) (900000000 + $p);
    foreach (['page_id' => $pageId, 'token' => 'perf-local-token-' . $p, 'flow.shipping' => '5501', 'field.message' => '7701'] as $key => $value) $s->set($page, 'botcake', $key, $value);
    $s->set($page, 'botcake', 'test_ok', now('Asia/Manila')->format('Y-m-d H:i:s'), false, null, true);
    $s->set($page, 'botcake', 'chat.key', 'perf-key-' . $p, false, null, true);
    if ($p % 3 === 0) $s->set($page, 'botcake', 'keywords', 'none 2026-10-30 09:00:00', false, null, true);
    app(ChatbotPage::class)->link($page, $flows[$p % 4]);

    foreach ($labels as $key => $label) {
        if (in_array($key, ['flow', 'shop_details'], true)) continue;
        $kind = ChatbotFields::kind($key);
        // About one value in eight is left empty, like a page still being set up.
        if (mt_rand(1, 8) === 1) continue;
        $value = match (true) {
            $kind === 'image' => $base . '/favicon.ico?p=' . $p . '&k=' . $key,
            $key === 'price' => (string) (299 + $p),
            $key === 'quantity' => '1',
            $key === 'item_name_in_jnt_dashboard' => 'Perf Item ' . ($p % 40),
            default => $text(mt_rand(1, 5)),
        };
        $s->set($page, 'chatbot', $key, $value);
    }

    if ($p % AD_BASED_EVERY === 0) {
        $adGroups->setMode($page, true);
        for ($g = 1; $g <= 3; $g++) {
            $ads = [];
            for ($a = 1; $a <= 4; $a++) $ads[] = (string) (120000000000 + $p * 100 + $g * 10 + $a);
            $adGroups->save($page, null, 'Group ' . $g, $ads, ['main_flow' => $text(2), 'seq_1' => $text(3), 'price' => (string) (399 + $g)]);
        }
    }

    // Chats: today and yesterday, so "Chats today" and "Last chat" have values.
    for ($c = 0; $c < 30; $c++) {
        $chats[] = ['page_id' => $pageId, 'page_name' => $page, 'psid' => 'ps' . $p . '-' . $c, 'name' => 'Customer ' . $p . '-' . $c, 'message' => $text(1),
            'payload' => json_encode(['x' => 1]), 'received_at' => now()->subMinutes(mt_rand(1, 2880))->toDateTimeString(), 'created_at' => now(), 'updated_at' => now()];
    }
    if ($p % 20 === 0) echo "  pages: $p\n";
}
$cols = DB::getSchemaBuilder()->getColumnListing('botcake_chats');
foreach (array_chunk($chats, 200) as $rows) DB::table('botcake_chats')->insert(array_map(fn ($r) => array_intersect_key($r, array_flip($cols)), $rows));

// The other Chatbot screens: sequences with steps and subscribers, sent replies with their tags, and bot events, over
// the last 7 days (Results, Health, Speed, Live, Replies, Inbox read these).
$insert = function (string $table, array $rows) {
    if (!DB::getSchemaBuilder()->hasTable($table)) return 0;
    $cols = array_flip(DB::getSchemaBuilder()->getColumnListing($table));
    foreach (array_chunk($rows, 200) as $chunk) DB::table($table)->insert(array_map(fn ($r) => array_intersect_key($r, $cols), $chunk));
    return count($rows);
};
$seqs = [];
for ($q = 1; $q <= 15; $q++) {
    $id = DB::table('chatbot_sequences')->insertGetId(['name' => 'Perf Sequence ' . $q, 'active' => true, 'exit_on_reply' => true, 'created_at' => now(), 'updated_at' => now()]);
    $steps = [];
    for ($k = 1; $k <= 10; $k++) $steps[] = ['sequence_id' => $id, 'position' => $k, 'delay_amount' => $k * 2, 'delay_unit' => 'hours', 'flow_id' => $flows[$k % 4]->id, 'active' => true, 'created_at' => now(), 'updated_at' => now()];
    $insert('chatbot_sequence_steps', $steps);
    $seqs[] = $id;
}
$subs = $replies = $events = $values = [];
for ($n = 0; $n < 20000; $n++) {
    $p = $n % PAGES + 1;
    $pageId = 900000000 + $p;
    $psid = 7000000 + $n % 3000;
    $at = now()->subSeconds(mt_rand(0, 7 * 86400));
    $replies[] = ['page_id' => $pageId, 'psid' => $psid, 'flow_id' => $flows[$n % 4]->id, 'node_id' => 'n' . ($n % 30 + 1), 'kind' => 'send',
        'content' => json_encode([['type' => 'text', 'text' => $text(2)]]), 'status' => $n % 23 === 0 ? 'failed' : 'sent', 'http' => 200, 'sent_at' => $at,
        'sequence_id' => $n % 5 === 0 ? $seqs[$n % 15] : null, 'sequence_step' => $n % 5 === 0 ? $n % 10 + 1 : null,
        'replied_at' => $n % 3 === 0 ? $at->copy()->addSeconds(mt_rand(5, 600)) : null, 'reply_seconds' => $n % 3 === 0 ? mt_rand(5, 600) : null];
    $events[] = ['page_id' => $pageId, 'psid' => $psid, 'flow_id' => $flows[$n % 4]->id, 'node_id' => 'n' . ($n % 30 + 1),
        'type' => ['start', 'send', 'reply', 'wait', 'end'][$n % 5], 'detail' => json_encode(['n' => $n]), 'created_at' => $at];
    if ($n < 3000) {
        $subs[] = ['sequence_id' => $seqs[$n % 15], 'page_id' => $pageId, 'psid' => $psid, 'status' => ['active', 'completed', 'paused'][$n % 3], 'current_step' => $n % 10 + 1,
            'next_send_at' => now()->addHours($n % 48), 'enrolled_via' => 'flow', 'created_at' => $at, 'updated_at' => $at];
        foreach (['Name' => 'Customer ' . $n, 'Address' => 'Brgy ' . $n . ', Quezon City', 'Phone' => '0917' . str_pad((string) $n, 7, '0', STR_PAD_LEFT)] as $key => $v) {
            $values[] = ['page_id' => $pageId, 'psid' => $psid, 'key' => $key, 'value' => $v, 'updated_at' => $at];
        }
    }
}
$counts = ['replies' => $insert('chatbot_replies', $replies), 'events' => $insert('chatbot_events', $events), 'subscriptions' => $insert('chatbot_sequence_subscriptions', $subs),
    'customer values' => $insert('chatbot_customer_values', $values)];

echo 'Seeded ' . PAGES . ' pages, ' . count($flows) . '+16 flows, ' . count($chats) . ' chats, 15 sequences, ' . json_encode($counts) . ' in ' . round(microtime(true) - $t0, 1) . " s\n";

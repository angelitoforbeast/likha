<?php

/**
 * The /chatbot/pages set of spec 217, on a throwaway sqlite file (storage/perf217/db.sqlite) and no env file: the
 * environment is set here, before Laravel reads anything, with local test values only (no outside keys, no queue).
 *   php tests/perf/set217.php migrate   builds the database from every migration (the MySQL-only ones are skipped)
 *   php tests/perf/set217.php seed      80 pages with 30 values each, 3 of them Ad-based with 6 groups and 250 ads each
 *   php tests/perf/set217.php env       prints the environment as JSON (for the local server of tests/perf/pages217.mjs)
 */

$root = dirname(__DIR__, 2);
$db = str_replace('\\', '/', $root) . '/storage/perf217/db.sqlite';
// The test APP_KEY of phpunit.xml: this server holds only the seeded local set.
$env = [
    'APP_ENV' => 'perf217', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:VVvUiS3qw3zt5eRUlZFUn/3twiQXv9WDMBv373TmXjg=', 'APP_URL' => 'http://127.0.0.1:8217',
    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $db, 'CACHE_STORE' => 'file', 'SESSION_DRIVER' => 'file', 'QUEUE_CONNECTION' => 'null', 'MAIL_MAILER' => 'array',
    'LOG_CHANNEL' => 'single', 'TELESCOPE_ENABLED' => 'false', 'PULSE_ENABLED' => 'false', 'BROADCAST_CONNECTION' => 'log',
    'OPENAI_API_KEY' => '', 'FORBEAST_SITE_KEY' => '', 'GATEWAY_URL' => '', 'GATEWAY_KEY' => '', 'INCEPXIONBOT_URL' => '', 'CHATBOT_REPLIES_ENABLED' => 'false',
    'HTTPS_PROXY' => 'http://127.0.0.1:9', 'HTTP_PROXY' => 'http://127.0.0.1:9', 'NO_PROXY' => '127.0.0.1,localhost',
];
$cmd = $argv[1] ?? '';
if ($cmd === 'env') {
    echo json_encode($env);
    exit(0);
}
foreach ($env as $k => $v) {
    putenv($k . '=' . $v);
    $_ENV[$k] = $_SERVER[$k] = $v;
}
if ($cmd === 'migrate') {
    @mkdir(dirname($db), 0777, true);
    file_put_contents($db, '');
}

require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.default') !== 'sqlite' || str_replace('\\', '/', (string) config('database.connections.sqlite.database')) !== $db) {
    fwrite(STDERR, "Refusing to run: not the perf217 sqlite database.\n");
    exit(1);
}

use App\Models\ChatbotFlow;
use App\Models\User;
use App\Services\Chatbot\AdGroups;
use App\Services\Chatbot\ChatbotPage;
use App\Services\Chatbot\FlowGraph;
use App\Services\ChatbotFields;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

if ($cmd === 'migrate') {
    $failed = 0;
    foreach (glob($root . '/database/migrations/*.php') as $file) {
        try {
            if (Artisan::call('migrate', ['--path' => $file, '--realpath' => true, '--force' => true]) !== 0) $failed++;
        } catch (Throwable $e) {
            $failed++;
        }
    }
    echo "Migrated; skipped $failed MySQL-only migrations.\n";
    exit(0);
}
if ($cmd !== 'seed') {
    fwrite(STDERR, "Use: migrate | seed | env\n");
    exit(1);
}

const PAGES = 80;
const VALUES = 30;
const AD_PAGES = [7, 14, 21];
const GROUPS = 6;
const ADS = 250;

mt_srand(217);
$t0 = microtime(true);
$s = app(PageSettings::class);
$base = 'http://127.0.0.1:8217';

$user = User::create(['name' => 'Perf CEO', 'email' => 'perf217@example.test', 'password' => 'perf217-local-only']);
$user->employeeProfile()->update(['role' => 'CEO']);

$words = ['Hello po', 'Salamat sa message', 'Available pa po ito', 'Free shipping nationwide', 'COD po tayo', 'Para sa inyong pamilya',
    'Original at authentic', 'Limited stocks lang po', 'Order na habang may promo', 'Pakisagot po ng form', 'Ilang piraso po', 'Delivery 3-5 days'];
$text = function (int $lines) use ($words): string {
    $out = [];
    for ($i = 0; $i < $lines; $i++) {
        $line = [];
        for ($j = 0, $n = mt_rand(3, 7); $j < $n; $j++) $line[] = $words[mt_rand(0, count($words) - 1)];
        $out[] = implode(', ', $line) . '.';
    }
    return implode("\n", $out);
};

$labels = ChatbotFields::defaults();
$nodes = [['id' => 'n0', 'type' => 'start']];
$i = 1;
foreach ($labels as $key => $label) {
    if ($key === 'flow') continue;
    $item = ChatbotFields::kind($key) === 'image' ? ['type' => 'image', 'value' => '{{' . $label . '}}'] : ['type' => 'text', 'value' => '{{' . $label . '}}'];
    $nodes[] = ['id' => 'n' . $i++, 'type' => 'send', 'config' => ['items' => [$item]]];
}
$flows = [];
foreach (['Main Sales Flow' => $nodes, 'Short Flow A' => array_slice($nodes, 0, 8), 'Promo Flow' => array_slice($nodes, 0, 20)] as $name => $list) {
    $f = ChatbotFlow::create(['name' => $name, 'graph' => FlowGraph::clean(['nodes' => $list, 'edges' => []])]);
    DB::table('chatbot_flows')->where('id', $f->id)->update(['published_at' => now()]);
    $flows[] = $f->fresh();
}

$valueKeys = array_values(array_filter(array_keys($labels), fn ($k) => !in_array($k, ['flow', 'shop_details'], true)));
$adGroups = app(AdGroups::class);
$chats = $reports = [];
$asOf = now('Asia/Manila')->subDay()->toDateString();
for ($p = 1; $p <= PAGES; $p++) {
    $page = sprintf('Perf Page %03d', $p);
    $pageId = (string) (900000000 + $p);
    foreach (['page_id' => $pageId, 'token' => 'perf-local-token-' . $p] as $key => $value) $s->set($page, 'botcake', $key, $value);
    $s->set($page, 'botcake', 'test_ok', now('Asia/Manila')->format('Y-m-d H:i:s'), false, null, true);
    app(ChatbotPage::class)->link($page, $flows[$p % 3]);
    foreach (array_slice($valueKeys, 0, VALUES) as $key) {
        $kind = ChatbotFields::kind($key);
        $s->set($page, 'chatbot', $key, match (true) {
            $kind === 'image' => $base . '/favicon.ico?p=' . $p . '&k=' . $key,
            $key === 'price' => (string) (299 + $p),
            $key === 'quantity' => '1',
            $key === 'item_name_in_jnt_dashboard' => 'Perf Item ' . ($p % 40),
            default => $text(mt_rand(1, 5)),
        });
    }
    if (in_array($p, AD_PAGES, true)) {
        $adGroups->setMode($page, true);
        $ads = [];
        for ($a = 1; $a <= ADS; $a++) $ads[] = (string) (120000000000 + $p * 1000 + $a);
        // 6 groups of 4 or 5 ads; the rest (about 223) are not in a group yet.
        $at = 0;
        for ($g = 1; $g <= GROUPS; $g++) {
            $mine = array_slice($ads, $at, 4 + $g % 2);
            $at += count($mine);
            $adGroups->save($page, null, 'Group ' . $g, $mine, ['main_flow' => $text(2), 'seq_1' => $text(3), 'price' => (string) (399 + $g)]);
        }
        foreach ($ads as $n => $ad) {
            // Customers in the last 30 days, and a report row per day for the last 5 days: about 4 in 10 are On.
            for ($c = 0, $m = mt_rand(0, 6); $c < $m; $c++) {
                $chats[] = ['page_id' => $pageId, 'page_name' => $page, 'psid' => 'ad' . $p . '-' . $n . '-' . $c, 'name' => 'Customer ' . $n, 'message' => 'Hi',
                    'payload' => json_encode(['ad_id' => $ad]), 'received_at' => now()->subMinutes(mt_rand(1, 29 * 1440))->toDateTimeString(), 'created_at' => now(), 'updated_at' => now()];
            }
            $on = $n % 5 < 2;
            for ($d = 4; $d >= 0; $d--) {
                $reports[] = ['page_name' => $page, 'day' => now('Asia/Manila')->subDays(1 + $d)->toDateString(), 'campaign_name' => 'Campaign ' . ($n % 9), 'ad_set_name' => 'Ad set ' . ($n % 17),
                    'headline' => 'Ad ' . $ad, 'ad_id' => $ad, 'amount_spent_php' => $on || $d > 2 ? 100 : 0, 'campaign_delivery' => $on ? 'active' : 'inactive', 'ad_set_delivery' => $on ? 'active' : 'inactive'];
            }
        }
    }
    for ($c = 0; $c < 20; $c++) {
        $chats[] = ['page_id' => $pageId, 'page_name' => $page, 'psid' => 'ps' . $p . '-' . $c, 'name' => 'Customer ' . $p . '-' . $c, 'message' => $text(1),
            'payload' => json_encode(['x' => 1]), 'received_at' => now()->subMinutes(mt_rand(1, 2880))->toDateTimeString(), 'created_at' => now(), 'updated_at' => now()];
    }
}
$insert = function (string $table, array $rows) {
    $cols = array_flip(DB::getSchemaBuilder()->getColumnListing($table));
    foreach (array_chunk($rows, 200) as $chunk) DB::table($table)->insert(array_map(fn ($r) => array_intersect_key($r, $cols), $chunk));
    return count($rows);
};
echo 'Seeded ' . PAGES . ' pages, ' . $insert('botcake_chats', $chats) . ' chats, ' . $insert('ads_manager_reports', $reports) . ' report rows in ' . round(microtime(true) - $t0, 1) . " s\n";

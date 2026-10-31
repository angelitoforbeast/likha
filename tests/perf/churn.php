<?php

/**
 * New chats on half the pages and the chat counts cache cleared, as on a live site between two actions: every row's
 * "Chats today" and "Last chat" change on the next reload. Run by the timing script before the Save actions.
 * Run: php tests/perf/churn.php
 */

require __DIR__ . '/boot.php';

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

$cols = array_flip(DB::getSchemaBuilder()->getColumnListing('botcake_chats'));
$rows = [];
for ($p = 1; $p <= 160; $p += 2) {
    $rows[] = array_intersect_key(['page_id' => (string) (900000000 + $p), 'page_name' => sprintf('Perf Page %03d', $p), 'psid' => 'churn' . $p . '-' . mt_rand(),
        'name' => 'Churn ' . $p, 'message' => 'hello', 'payload' => json_encode(['x' => 1]), 'received_at' => now()->toDateTimeString(), 'created_at' => now(), 'updated_at' => now()], $cols);
}
DB::table('botcake_chats')->insert($rows);
Cache::forget('chatbot:chat-stats');
echo count($rows) . " chats added\n";

<?php

namespace Tests\Feature\Chatblast;

use App\Models\ChatbotFlow;
use App\Services\Chatbot\ChatbotPage;
use App\Services\Chatbot\FlowGraph;
use App\Services\PageSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Ang /chatbot/data (ang unang request ng bawat /chatbot/pages) na may 117 page: iilang query, walang query kada page,
 * malamig man o mainit ang cache (kasama ang cache sa database, gaya ng production), at ang JSON ay gaya ng dati
 * (snapshots/207/data.json, mula sa lumang code).
 */
class ChatbotDataSpeedTest extends ChatblastTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-10-31 10:00:00', 'Asia/Manila'));
        Artisan::call('migrate', ['--path' => [
            'database/migrations/0001_01_01_000001_create_cache_table.php',
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_draft_to_chatbot_flows.php',
            'database/migrations/2026_10_26_100000_create_chatbot_sequence_tables.php',
        ]]);
        config(['cache.default' => 'database']);

        $flow = ChatbotFlow::create(['name' => 'Main', 'graph' => FlowGraph::clean(['nodes' => [['id' => 'n0', 'type' => 'start']], 'edges' => []])]);
        DB::table('chatbot_flows')->where('id', $flow->id)->update(['published_at' => now()]);
        $s = app(PageSettings::class);
        $chats = [];
        for ($i = 1; $i <= 117; $i++) {
            $p = sprintf('Page %03d', $i);
            $id = (string) (5000 + $i);
            $this->page($p, $id);
            $s->set($p, 'botcake', 'chat.key', 'k' . $i, false, null, true);
            app(ChatbotPage::class)->link($p, $flow);
            foreach (['main_flow' => 'Hi ' . $i, 'price' => '499', 'quantity' => '1', 'item_name_in_jnt_dashboard' => 'Item ' . $i] as $k => $v) $s->set($p, 'chatbot', $k, $v);
            if ($i % 3 === 0) $s->set($p, 'botcake', 'keywords', 'on 2026-10-30 09:00:00 · Promo ' . $i, false, null, true);
            if ($i % 3 === 1) $s->set($p, 'botcake', 'keywords', 'none 2026-10-30 09:00:00', false, null, true);
            if ($i % 6 === 0) $s->set($p, 'botcake', 'keywords', 'unknown 2026-10-30 10:00:00 · could not read the keywords', false, null, true);
            if ($i % 10 === 0) $s->set($p, 'botcake', 'token_status', 'ok 2026-10-30 09:00:00', false, null, true);
            if ($i % 25 === 0) $s->set($p, 'chatbot', 'ai_provider', 'openai', false, null, true);
            // Mga chat: kahapon at ngayon; ang standby (ibang bot ang may hawak) ay hindi binibilang.
            if ($i % 4 !== 0) {
                foreach ([[26, false], [3, false], [1, true]] as $n => [$hoursAgo, $standby]) {
                    $chats[] = ['page_id' => $id, 'psid' => 'ps' . $i . '-' . $n, 'message' => 'hello', 'payload' => json_encode($standby ? ['_standby' => true] : ['x' => 1]),
                        'received_at' => now()->subHours($hoursAgo + $i % 5)->toDateTimeString()];
                }
            }
        }
        foreach (array_chunk($chats, 100) as $rows) DB::table('botcake_chats')->insert($rows);
        $this->actingAs($this->user());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: array, 1: array<string, int>} [ang JSON, bilang ng bawat hugis ng query] */
    private function load(): array
    {
        $shapes = [];
        DB::listen(function ($e) use (&$shapes) {
            $sql = preg_replace('/\d+/', 'N', $e->sql);
            $shapes[$sql] = ($shapes[$sql] ?? 0) + 1;
        });
        $json = $this->getJson('/chatbot/data')->assertOk()->json();
        app('events')->forget(\Illuminate\Database\Events\QueryExecuted::class);

        return [$json, $shapes];
    }

    public function test_S_207_9_chatbot_data_with_117_pages_makes_a_bounded_number_of_queries_and_the_same_json(): void
    {
        // Ang Check ng kalahati ay naka-cache (ang iba ay null), gaya ng karaniwang pagbukas.
        for ($i = 1; $i <= 117; $i += 2) app(\App\Services\Chatbot\ChatbotPageCheck::class)->check(sprintf('Page %03d', $i));

        foreach (['cold' => true, 'warm' => false] as $round => $flush) {
            if ($flush) {
                // Malamig: wala ang settings ng page, ang chat stats at ang page map sa cache (ang Check ay naiiwan).
                foreach (range(1, 117) as $i) PageSettings::forget(sprintf('Page %03d', $i));
                Cache::forget('chatbot:chat-stats');
                Cache::forget('botcake-chats:page-map');
            }
            [$json, $shapes] = $this->load();

            $file = __DIR__ . '/snapshots/207/data.json';
            if (getenv('WRITE_207_SNAPSHOTS') === '1' && $round === 'cold') file_put_contents($file, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
            $this->assertSame(json_decode((string) file_get_contents($file), true), $json, $round . ': the same JSON as before');

            arsort($shapes);
            $this->assertLessThanOrEqual(10, reset($shapes), $round . ': no query runs once per page: ' . key($shapes));
            $this->assertLessThanOrEqual(60, array_sum($shapes), $round . ': ' . array_sum($shapes) . ' queries');
        }
    }
}

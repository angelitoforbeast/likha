<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeChat;
use App\Models\MacroOutput;
use App\Services\Chatbot\ChatbotResults;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 276, By page ng /chatbot/results: lahat ng page magkakatabi. Nakatakdang data (2026-10-14 hanggang 10-20):
 * Page Uno (1001) 4 padala sa 3 customer, 2 sinagot, 3 order (2 main flow, 1 pagkatapos ng sequence, 1 delivered);
 * Page Dos (1002) 2 padala sa 2 customer, 1 sinagot, 1 order pagkatapos ng sequence; page 1009 (wala sa page settings)
 * 1 order lang, walang padala.
 */
class ChatbotResultsByPageTest extends ChatblastTestCase
{
    private const RANGE = ['from' => '2026-10-14', 'to' => '2026-10-20'];
    private const FUNNEL_KEYS = ['sent', 'customers_sent', 'replied', 'replied_pct', 'orders', 'orders_pct', 'delivered', 'delivered_pct'];

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_draft_to_chatbot_flows.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_26_100000_create_chatbot_sequence_tables.php',
            'database/migrations/2026_10_30_100000_add_results_to_chatbot_replies.php',
        ]]);
        if (!Schema::hasColumn('from_jnts', 'rts_reason')) Schema::table('from_jnts', fn ($t) => $t->string('rts_reason')->nullable());
        $this->travelTo('2026-10-21 09:00:00');
        $this->page('Page Uno', '1001');
        $this->page('Page Dos', '1002');
        $this->seedPages();
    }

    private function seedPages(): void
    {
        $send = fn (int $page, int $psid, string $at, bool $replied, string $via = 'botcake', int $flow = 1, string $status = 'sent') => DB::table('chatbot_replies')->insert([
            'page_id' => $page, 'psid' => $psid, 'flow_id' => $flow, 'node_id' => 'n1', 'kind' => 'send', 'content' => '[]', 'status' => $status, 'sent_at' => $at,
            'replied_at' => $replied ? date('Y-m-d H:i:s', strtotime($at) + 60) : null, 'tags' => json_encode(['v' => 1, 'via' => $via])]);
        $send(1001, 11, '2026-10-15 10:00:00', true);
        $send(1001, 11, '2026-10-16 10:00:00', false);
        $send(1001, 12, '2026-10-16 11:00:00', true);
        $send(1001, 13, '2026-10-17 10:00:00', false, 'forbeast');
        $send(1001, 14, '2026-10-17 10:30:00', false, 'botcake', 1, 'failed'); // hindi naipadala: wala sa bilang
        $send(1001, 15, '2026-10-01 10:00:00', true);                          // labas ng range
        $send(1002, 21, '2026-10-18 10:00:00', true);
        $send(1002, 22, '2026-10-18 11:00:00', false, 'botcake', 2);

        $order = fn (int $page, int $psid, string $at, ?array $seq, string $via = 'botcake', int $flow = 1) => DB::table('chatbot_events')->insert(['page_id' => $page,
            'psid' => $psid, 'flow_id' => $flow, 'node_id' => 'n9', 'type' => 'order', 'created_at' => $at,
            'detail' => json_encode(['via' => $via, 'sequence_id' => $seq[0] ?? null, 'sequence_step' => $seq[1] ?? null])]);
        $order(1001, 11, '2026-10-16 12:00:00', null);           // main flow, delivered
        $order(1001, 12, '2026-10-16 13:00:00', [7, 2]);         // pagkatapos ng sequence step
        $order(1001, 13, '2026-10-17 12:00:00', null, 'forbeast');
        $order(1002, 21, '2026-10-18 12:00:00', [7, 1], 'botcake', 2);
        $order(1009, 31, '2026-10-19 12:00:00', null);           // page na walang padala at wala sa page settings
        $order(1001, 15, '2026-10-02 12:00:00', null);           // labas ng range

        MacroOutput::create(['PAGE' => 'Page Uno', 'fb_name' => 'X', 'waybill' => 'W11', 'botcake_psid' => '11', 'ts_date' => '2026-10-16']);
        DB::table('from_jnts')->insert(['waybill_number' => 'W11', 'status' => 'Delivered', 'rts_reason' => '']);
    }

    private function report(array $extra = []): array
    {
        return app(ChatbotResults::class)->report(ChatbotResults::filters(self::RANGE + $extra));
    }

    private static function pick(array $row): array
    {
        return [$row['page'], $row['sent'], $row['customers_sent'], $row['replied_pct'], $row['orders'], $row['orders_pct'],
            $row['main_flow'], $row['main_flow_pct'], $row['after_sequence'], $row['after_sequence_pct'], $row['delivered_pct']];
    }

    public function test_S_276_1_each_page_has_its_own_sends_replies_orders_and_split(): void
    {
        $r = $this->report();
        $this->assertSame([
            ['Page Uno', 4, 3, 50.0, 3, 100.0, 2, 66.7, 1, 33.3, 33.3],
            ['Page Dos', 2, 2, 50.0, 1, 50.0, 0, 0.0, 1, 100.0, 0.0],
            ['1009', 0, 0, null, 1, null, 1, 100.0, 0, 0.0, 0.0],
        ], array_map([self::class, 'pick'], $r['by_page']['rows']));
        $this->assertSame(['1001', '1002', '1009'], array_column($r['by_page']['rows'], 'page_id'));
        $this->assertSame([3, 2], [$r['by_page']['total']['main_flow'], $r['by_page']['total']['after_sequence']]);
    }

    public function test_S_276_2_the_total_row_equals_the_funnel_for_the_same_filters(): void
    {
        foreach ([[], ['via' => 'botcake'], ['via' => 'forbeast'], ['flow_id' => '1'], ['page_id' => '1001']] as $extra) {
            $r = $this->report($extra);
            $only = fn (array $a) => array_map(fn ($k) => $a[$k], self::FUNNEL_KEYS);
            $this->assertSame($only($r['funnel']), $only($r['by_page']['total']), json_encode($extra));
            foreach (self::FUNNEL_KEYS as $k) {
                if (!str_ends_with($k, '_pct')) $this->assertSame($r['funnel'][$k], array_sum(array_column($r['by_page']['rows'], $k)), $k . ' ' . json_encode($extra));
            }
        }
        $this->assertSame(['sent' => 6, 'customers_sent' => 5, 'replied' => 3, 'orders' => 5], array_intersect_key($this->report()['by_page']['total'], array_flip(['sent', 'customers_sent', 'replied', 'orders'])));
    }

    public function test_S_276_3_the_page_filter_gives_one_row_and_the_via_and_flow_filters_apply(): void
    {
        $this->assertSame([['Page Dos', 2, 2, 50.0, 1, 50.0, 0, 0.0, 1, 100.0, 0.0]], array_map([self::class, 'pick'], $this->report(['page_id' => '1002'])['by_page']['rows']));

        $this->assertSame([['Page Uno', 1, 1, 0.0, 1, 100.0, 1, 100.0, 0, 0.0, 0.0]], array_map([self::class, 'pick'], $this->report(['via' => 'forbeast'])['by_page']['rows']));
        $this->assertSame([
            ['Page Uno', 3, 2, 66.7, 2, 100.0, 1, 50.0, 1, 50.0, 50.0],
            ['Page Dos', 2, 2, 50.0, 1, 50.0, 0, 0.0, 1, 100.0, 0.0],
            ['1009', 0, 0, null, 1, null, 1, 100.0, 0, 0.0, 0.0],
        ], array_map([self::class, 'pick'], $this->report(['via' => 'botcake'])['by_page']['rows']));

        // Ang flow 2 ay ang padala at order lang ng psid 22 at 21 sa Page Dos.
        $this->assertSame([['Page Dos', 1, 1, 0.0, 1, 100.0, 0, 0.0, 1, 100.0, 0.0]], array_map([self::class, 'pick'], $this->report(['flow_id' => '2'])['by_page']['rows']));
    }

    public function test_S_276_4_the_sends_part_is_one_grouped_query(): void
    {
        $f = ChatbotResults::filters(self::RANGE);
        $tz = config('app.timezone');
        [$start, $end] = [Carbon::parse($f['from'], 'Asia/Manila')->startOfDay()->timezone($tz), Carbon::parse($f['to'], 'Asia/Manila')->addDay()->startOfDay()->timezone($tz)];
        $orders = DB::table('chatbot_events')->where('type', 'order')->where('created_at', '>=', $start)->where('created_at', '<', $end)->get()
            ->map(function ($o) { $o->detail = (array) json_decode((string) $o->detail, true); return $o; });
        $names = BotcakeChat::pageMap();

        $method = new \ReflectionMethod(ChatbotResults::class, 'byPage');
        DB::flushQueryLog();
        DB::enableQueryLog();
        $out = $method->invoke(app(ChatbotResults::class), $f, $start, $end, $orders, [], $names);
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $log, json_encode($log));
        $this->assertStringContainsStringIgnoringCase('group by', $log[0]['query']);
        $this->assertCount(3, $out['rows']);
    }

    public function test_S_276_5_the_json_has_by_page_and_the_page_shows_the_section_with_the_page_link(): void
    {
        Cache::flush();
        $this->actingAs($this->user('CEO', 'ceo276@example.test'));
        $json = $this->getJson('/chatbot/results.json?' . http_build_query(self::RANGE))->assertOk()->json();
        $this->assertSame(['filters', 'funnel', 'by_via', 'by_page', 'by_message', 'by_step', 'by_ad', 'versions', 'compare'], array_keys($json));
        $this->assertSame(['rows', 'total'], array_keys($json['by_page']));
        $this->assertSame('Page Uno', $json['by_page']['rows'][0]['page']);
        $this->assertStringNotContainsString('"psid"', json_encode($json['by_page']));

        $page = $this->get('/chatbot/results?' . http_build_query(self::RANGE + ['via' => 'botcake']))->assertOk();
        $page->assertSeeInOrder(['By bot', 'By page', 'Page Uno', 'Page Dos', 'Total', 'By message']);
        $page->assertSee(route('chatbot.results', ['page_id' => '1002', 'from' => '2026-10-14', 'to' => '2026-10-20', 'via' => 'botcake']));
    }
}

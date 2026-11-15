<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeChat;
use App\Services\Chatbot\ChatbotInbound;
use App\Services\Chatbot\ChatbotTags;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Spec 275: ang hourly na backfill ng replied_at ay nagsisimula sa mga customer na nag-chat (hindi kada padala), pero
 * pareho ang resulta ng dating tuntunin. Bawat kaso ay pinapatakbo rin sa dating algorithm (oldBackfill, kopya nito
 * bago ang spec 275) sa kaparehong kopya ng data, at dapat magkapareho ang replied_at at reply_seconds.
 */
class ChatbotTagsBackfillTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_inbox_indexes.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_30_100000_add_results_to_chatbot_replies.php',
        ]]);
        $this->travelTo('2026-10-21 10:00:00');
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(ChatbotTags::class, 'ready'))->setValue(null, []);
        parent::tearDown();
    }

    /** Ang dating ChatbotTags::backfill() (bago ang spec 275), kopya para sa paghahambing. */
    private static function oldBackfill(): int
    {
        if (!ChatbotTags::ready()) return 0;
        $n = 0;
        DB::table('chatbot_replies')->where('sent_at', '>=', now()->subHours(ChatbotTags::BACKFILL_HOURS))->whereNull('replied_at')
            ->whereIn('kind', ['send', 'manual'])->where('status', 'sent')->orderBy('id')
            ->chunkById(1000, function ($rows) use (&$n) {
                foreach ($rows as $r) {
                    $sent = Carbon::parse($r->sent_at);
                    $chat = BotcakeChat::active()->whereNull('payload->' . ChatbotInbound::AD_OPEN)->where('page_id', (string) $r->page_id)->where('psid', (string) $r->psid)
                        ->where('received_at', '>', $sent)->where('received_at', '<=', $sent->copy()->addHours(ChatbotTags::REPLY_HOURS))
                        ->orderBy('received_at')->value('received_at');
                    if ($chat === null) continue;
                    $at = Carbon::parse($chat);
                    $n += DB::table('chatbot_replies')->where('id', $r->id)->whereNull('replied_at')
                        ->update(['replied_at' => $at, 'reply_seconds' => max(0, $at->getTimestamp() - $sent->getTimestamp())]);
                }
            }, 'id');

        return $n;
    }

    private function send(string $sentAt, int $psid = 7001, int $page = 1001, string $kind = 'send', string $status = 'sent', ?string $repliedAt = null, ?int $seconds = null): int
    {
        return (int) DB::table('chatbot_replies')->insertGetId(['page_id' => $page, 'psid' => $psid, 'kind' => $kind, 'status' => $status, 'content' => '[]',
            'sent_at' => $sentAt, 'replied_at' => $repliedAt, 'reply_seconds' => $seconds]);
    }

    private function chatAt(string $receivedAt, string $psid = '7001', string $page = '1001', array $payload = []): void
    {
        DB::table('botcake_chats')->insert(['page_id' => $page, 'page_name' => 'Page ' . $page, 'psid' => $psid, 'name' => 'Ana', 'message' => 'hi',
            'payload' => json_encode((object) $payload), 'received_at' => $receivedAt]);
    }

    /** @return array<int, array{0: ?string, 1: ?int}> id => [replied_at, reply_seconds] */
    private function state(): array
    {
        $out = [];
        foreach (DB::table('chatbot_replies')->orderBy('id')->get(['id', 'replied_at', 'reply_seconds']) as $r) {
            $out[(int) $r->id] = [$r->replied_at === null ? null : substr((string) $r->replied_at, 0, 19), $r->reply_seconds === null ? null : (int) $r->reply_seconds];
        }

        return $out;
    }

    /**
     * Pinapatakbo ang dating algorithm, ibinabalik ang chatbot_replies sa dati, saka ang bago. Dapat pareho ang bilang at
     * ang bawat row. @return array ang state pagkatapos ng bago
     */
    private function runBothAndCompare(): array
    {
        $snapshot = DB::table('chatbot_replies')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $oldN = self::oldBackfill();
        $old = $this->state();

        DB::table('chatbot_replies')->delete();
        foreach (array_chunk($snapshot, 200) as $rows) DB::table('chatbot_replies')->insert($rows);
        $this->assertSame(count($snapshot), DB::table('chatbot_replies')->count());

        $newN = ChatbotTags::backfill();
        $new = $this->state();
        $this->assertSame($old, $new, 'the new backfill fills the same rows with the same values as the old one');
        $this->assertSame($oldN, $newN, 'and returns the same count');

        return $new;
    }

    public function test_S_275_1_a_chat_within_24h_after_the_send_fills_it_and_the_earliest_chat_wins(): void
    {
        $id = $this->send('2026-10-21 06:00:00');
        $this->chatAt('2026-10-21 05:00:00');  // bago ang padala
        $this->chatAt('2026-10-21 08:00:00');  // ito ang una pagkatapos
        $this->chatAt('2026-10-21 07:30:00', '7001', '1001', [ChatbotInbound::AD_OPEN => true]); // pagbukas ng ad, hindi sagot
        $this->chatAt('2026-10-21 09:00:00');
        $state = $this->runBothAndCompare();
        $this->assertSame(['2026-10-21 08:00:00', 7200], $state[$id]);
    }

    public function test_S_275_2_a_chat_more_than_24h_after_the_send_does_not_fill_it_but_exactly_24h_does(): void
    {
        $late = $this->send('2026-10-20 08:00:00', 7001);
        $this->chatAt('2026-10-21 08:00:01', '7001');
        $edge = $this->send('2026-10-20 08:00:00', 7002);
        $this->chatAt('2026-10-21 08:00:00', '7002');
        $state = $this->runBothAndCompare();
        $this->assertSame([null, null], $state[$late]);
        $this->assertSame(['2026-10-21 08:00:00', 86400], $state[$edge]);
    }

    public function test_S_275_3_a_chat_before_or_at_the_same_second_as_the_send_does_not_fill_it(): void
    {
        $id = $this->send('2026-10-21 06:00:00');
        $this->chatAt('2026-10-21 05:59:59');
        $this->chatAt('2026-10-21 06:00:00');
        $state = $this->runBothAndCompare();
        $this->assertSame([null, null], $state[$id]);
    }

    public function test_S_275_4_an_ad_open_chat_and_a_standby_chat_do_not_fill_it(): void
    {
        $adOpen = $this->send('2026-10-21 06:00:00', 7001);
        $this->chatAt('2026-10-21 06:05:00', '7001', '1001', [ChatbotInbound::AD_OPEN => 1]);
        $standby = $this->send('2026-10-21 06:00:00', 7002);
        $this->chatAt('2026-10-21 06:05:00', '7002', '1001', ['_standby' => true]);
        $state = $this->runBothAndCompare();
        $this->assertSame([null, null], $state[$adOpen]);
        $this->assertSame([null, null], $state[$standby]);
    }

    public function test_S_275_5_the_same_psid_on_another_page_does_not_fill_it(): void
    {
        $id = $this->send('2026-10-21 06:00:00', 7001, 1001);
        $this->chatAt('2026-10-21 06:05:00', '7001', '1002');
        $state = $this->runBothAndCompare();
        $this->assertSame([null, null], $state[$id]);
    }

    public function test_S_275_6_a_row_already_replied_is_unchanged(): void
    {
        $id = $this->send('2026-10-21 06:00:00', 7001, 1001, 'send', 'sent', '2026-10-21 06:30:00', 1800);
        $this->chatAt('2026-10-21 06:05:00');
        $state = $this->runBothAndCompare();
        $this->assertSame(['2026-10-21 06:30:00', 1800], $state[$id]);
    }

    public function test_S_275_7_other_kinds_and_statuses_are_untouched_and_manual_is_filled(): void
    {
        $sheet = $this->send('2026-10-21 06:00:00', 7001, 1001, 'sheet');
        $failed = $this->send('2026-10-21 06:00:00', 7001, 1001, 'send', 'failed');
        $skipped = $this->send('2026-10-21 06:00:00', 7001, 1001, 'send', 'skipped');
        $manual = $this->send('2026-10-21 06:00:00', 7001, 1001, 'manual');
        $this->chatAt('2026-10-21 06:05:00');
        $state = $this->runBothAndCompare();
        $this->assertSame([null, null], $state[$sheet]);
        $this->assertSame([null, null], $state[$failed]);
        $this->assertSame([null, null], $state[$skipped]);
        $this->assertSame(['2026-10-21 06:05:00', 300], $state[$manual]);
    }

    public function test_S_275_8_a_send_older_than_48h_is_untouched_and_exactly_48h_is_filled(): void
    {
        $old = $this->send('2026-10-19 09:59:59', 7001);
        $this->chatAt('2026-10-19 10:30:00', '7001');
        $edge = $this->send('2026-10-19 10:00:00', 7002);
        $this->chatAt('2026-10-19 10:30:00', '7002');
        $state = $this->runBothAndCompare();
        $this->assertSame([null, null], $state[$old]);
        $this->assertSame(['2026-10-19 10:30:00', 1800], $state[$edge]);
    }

    public function test_S_275_9_two_sends_before_one_chat_are_both_filled_by_it(): void
    {
        $a = $this->send('2026-10-21 06:00:00');
        $b = $this->send('2026-10-21 06:10:00');
        $this->chatAt('2026-10-21 06:30:00');
        $this->chatAt('2026-10-21 06:40:00');
        $state = $this->runBothAndCompare();
        $this->assertSame(['2026-10-21 06:30:00', 1800], $state[$a]);
        $this->assertSame(['2026-10-21 06:30:00', 1200], $state[$b]);
    }

    public function test_S_275_10_a_psid_written_differently_in_the_chats_does_not_match_like_today(): void
    {
        $id = $this->send('2026-10-21 06:00:00', 7001);
        $this->chatAt('2026-10-21 06:05:00', '07001');
        $this->chatAt('2026-10-21 06:06:00', 'abc');
        $this->chatAt('2026-10-21 06:07:00', '7001', '01001');
        $state = $this->runBothAndCompare();
        $this->assertSame([null, null], $state[$id]);
    }

    public function test_S_275_11_more_customers_than_one_chunk_are_all_filled(): void
    {
        $ids = [];
        for ($i = 0; $i < 2300; $i++) {
            $ids[] = $this->send('2026-10-21 06:00:00', 8000000 + $i);
        }
        $rows = [];
        for ($i = 0; $i < 2300; $i++) {
            $rows[] = ['page_id' => '1001', 'page_name' => 'Page 1001', 'psid' => (string) (8000000 + $i), 'name' => 'A', 'message' => 'hi', 'payload' => '{}',
                'received_at' => '2026-10-21 06:0' . ($i % 10) . ':01'];
        }
        foreach (array_chunk($rows, 300) as $chunk) DB::table('botcake_chats')->insert($chunk);
        $this->assertSame(2300, ChatbotTags::backfill());
        $this->assertSame(0, DB::table('chatbot_replies')->whereNull('replied_at')->count());
        $this->assertSame(1, (int) DB::table('chatbot_replies')->where('id', $ids[0])->value('reply_seconds'));
    }

    public static function seeds(): array
    {
        return ['seed 275' => [275], 'seed 1' => [1], 'seed 4242' => [4242]];
    }

    /**
     * Equivalence: ilang daang random na padala at chat sa 30-minutong grid (para may tie sa oras ng padala, sa 24 oras
     * at sa 48 oras), dalawang page, mga uri at status, ad-open at standby, mga naunang sagot. Pareho dapat ang resulta.
     */
    #[DataProvider('seeds')]
    public function test_S_275_12_random_sends_and_chats_give_the_same_result_as_the_old_backfill(int $seed): void
    {
        mt_srand($seed);
        $now = now()->getTimestamp();
        $slot = fn () => date('Y-m-d H:i:s', $now - mt_rand(0, 120) * 1800 + (mt_rand(0, 9) === 0 ? mt_rand(-2, 2) : 0)); // hanggang 60 oras
        $pages = [1001, 1002, 1003];
        $sends = [];
        for ($i = 0; $i < 400; $i++) {
            $kind = ['send', 'send', 'send', 'manual', 'sheet'][mt_rand(0, 4)];
            $status = ['sent', 'sent', 'sent', 'failed', 'skipped'][mt_rand(0, 4)];
            $replied = mt_rand(0, 9) === 0;
            $sends[] = ['page_id' => $pages[mt_rand(0, 2)], 'psid' => 7000 + mt_rand(0, 59), 'kind' => $kind, 'status' => $status, 'content' => '[]',
                'sent_at' => $slot(), 'replied_at' => $replied ? $slot() : null, 'reply_seconds' => $replied ? mt_rand(0, 5000) : null];
        }
        foreach (array_chunk($sends, 200) as $rows) DB::table('chatbot_replies')->insert($rows);
        $chats = [];
        for ($i = 0; $i < 350; $i++) {
            $payload = [[], [], [], [ChatbotInbound::AD_OPEN => true], ['_standby' => true]][mt_rand(0, 4)];
            $psid = (string) (7000 + mt_rand(0, 69));
            if (mt_rand(0, 29) === 0) $psid = '0' . $psid;
            $chats[] = ['page_id' => (string) $pages[mt_rand(0, 2)], 'page_name' => 'P', 'psid' => $psid, 'name' => 'A', 'message' => 'hi',
                'payload' => json_encode((object) $payload), 'received_at' => $slot()];
        }
        foreach (array_chunk($chats, 200) as $rows) DB::table('botcake_chats')->insert($rows);

        $state = $this->runBothAndCompare();
        $filled = count(array_filter($state, fn ($s) => $s[0] !== null));
        $this->assertGreaterThan(40, $filled, 'the random data does fill a good number of sends');
    }
}

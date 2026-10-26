<?php

namespace Tests\Feature\Chatblast;

use App\Jobs\HandleGatewayEvent;
use App\Models\PageSetting;
use App\Services\BotcakeChatGuard;
use App\Services\Chatbot\BotHealth;
use App\Services\PageSettings;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Spec 156: ang totoong oras ng padala (send_status ng Forbeast), ang pending na sagot, ang totoong unang sagot kada bot
 * at page, at ang send lag alarm mula sa sariling data ng Likha. Fake na HTTP at event lang.
 * Page Uno (1001) at Page Tres (1003) ay nasa Forbeast; Page Dos (1002) ay nasa Botcake.
 */
class RealSendTimeTest extends ChatblastTestCase
{
    private const GW = 'https://gw.example.test';

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_22_100000_widen_source_on_botcake_psids.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_inbox_indexes.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_28_100000_create_chatbot_ai_requests_table.php',
            'database/migrations/2026_10_30_098000_add_raw_to_chatbot_replies.php',
            'database/migrations/2026_10_30_100000_add_results_to_chatbot_replies.php',
            'database/migrations/2026_11_06_100000_add_chat_index_to_chatbot_replies.php',
            'database/migrations/2026_11_09_100000_add_via_to_chatbot_replies.php',
            'database/migrations/2026_11_11_100000_add_real_send_to_chatbot_replies.php',
        ]]);
        config(['services.chatbot.replies_enabled' => true, 'services.gateway.url' => self::GW, 'services.gateway.key' => 'gw_test_key_156']);
        $this->travelTo('2026-10-26 20:00:00');
        foreach (['Page Uno' => '1001', 'Page Dos' => '1002', 'Page Tres' => '1003'] as $name => $id) {
            $this->page($name, $id);
            DB::table('page_settings')->insert(['page_name' => $name, 'provider' => 'chatbot', 'key' => 'flow_id', 'value' => '1', 'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00']);
        }
        app(PageSettings::class)->set('Page Uno', 'chatbot', 'provider', 'gateway');
        app(PageSettings::class)->set('Page Tres', 'chatbot', 'provider', 'gateway');
        Http::preventStrayRequests();
        Http::fake([
            self::GW . '/v1/health/sends' => Http::response(['error' => 'not found'], 404),
            self::GW . '/v1/pages/*/messages' => Http::response(['public_id' => 'snd_1', 'status' => 'queued'], 202),
            self::GW . '/v1/pages/*' => Http::response(['connected' => true, 'can_send' => true], 200),
            'https://botcake.io/*' => Http::response(['success' => true]),
        ]);
    }

    private function chat(string $page, string $psid, string $at): int
    {
        return DB::table('botcake_chats')->insertGetId(['page_id' => $page, 'page_name' => 'x', 'psid' => $psid, 'received_at' => $at, 'payload' => '{"source":"gateway"}']);
    }

    /** Isang padala; Botcake: kumpirmado sa oras ng talaan (gaya ng isinusulat ng Likha). */
    private function reply(?int $chat, string $at, string $via = 'forbeast', ?string $ref = null, string $page = '1001', string $psid = '7001', string $status = 'sent'): int
    {
        return DB::table('chatbot_replies')->insertGetId(['page_id' => (int) $page, 'psid' => (int) $psid, 'chat_id' => $chat, 'node_id' => 'n2', 'kind' => 'send',
            'status' => $status, 'http' => 202, 'sent_at' => $at, 'via' => $via, 'client_ref' => $ref]
            + ($via === 'botcake' && $status === 'sent' ? ['confirmed_at' => $at, 'confirmed_status' => 'sent'] : []));
    }

    private static function iso(string $local): string
    {
        return \Carbon\Carbon::parse($local, 'Asia/Manila')->utc()->format('Y-m-d\TH:i:s.v\Z');
    }

    /** Ang send_status ng Forbeast ayon sa kontrata, ipinapasa sa job gaya ng controller. */
    private function sendStatus(string $ref, string $status, ?string $sentAt, array $send = [], string $page = '1001', string $psid = '7001', ?string $acceptedAt = null): void
    {
        $e = ['type' => 'send_status', 'page_id' => $page, 'psid' => $psid, 'send' => $send + ['public_id' => 'snd_' . $ref, 'client_ref' => $ref, 'status' => $status,
            'accepted_at' => self::iso($acceptedAt ?? now()->subMinutes(5)->toDateTimeString()), 'sent_at' => $sentAt === null ? null : self::iso($sentAt),
            'message_count' => 4, 'sent_count' => $status === 'sent' ? 4 : 0, 'message_ids' => ['m_1'], 'error_code' => null, 'error_subcode' => null, 'error' => null]];
        (new HandleGatewayEvent(HandleGatewayEvent::fields($e)))->handle(app(PageSettings::class), app(BotcakeChatGuard::class));
    }

    private function counted(string $what): int
    {
        return (int) Cache::get('gateway-events:' . now('Asia/Manila')->toDateString() . ':' . $what, 0);
    }

    private function out(array $q = []): array
    {
        Cache::forget('bot-health:' . md5(json_encode([BotHealth::filters($q), now()->toDateString()])));

        return app(BotHealth::class)->output($q);
    }

    private function row(int $id): object
    {
        return DB::table('chatbot_replies')->find($id);
    }

    private function watch(int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->assertSame(0, Artisan::call('chatbot:forbeast-watch'));
            $this->travel(1)->minutes();
        }
    }

    /** @return array<int, array> ang mga send_lag event, luma muna */
    private function lagEvents(): array
    {
        return PageSetting::where('provider', 'chatbot')->where('key', 'auto_switch')->orderBy('id')->get()
            ->map(fn ($s) => json_decode((string) $s->plainValue(), true))->filter(fn ($e) => ($e['condition'] ?? null) === 'send_lag')->values()->all();
    }

    public function test_S_156_1_send_status_sets_the_real_send_time_once_and_the_real_first_reply_follows_it(): void
    {
        $this->actingAs($this->user('CEO', 'ceo@example.test'));
        $this->chat('1001', '7001', '2026-10-26 19:54:55');
        // Ang padala mula sa Likha: ang client_ref na ipinadala sa Forbeast ay naitala sa row.
        $this->postJson('/chatbot/inbox/send', ['page_id' => '1001', 'psid' => '7001', 'text' => 'Hello po'])->assertOk();
        $sent = null;
        Http::assertSent(function (HttpRequest $r) use (&$sent) {
            if (!str_ends_with($r->url(), '/messages')) return false;
            $sent = $r['client_ref'];
            return true;
        });
        $manual = DB::table('chatbot_replies')->where('kind', 'manual')->first();
        $this->assertSame($sent, $manual->client_ref);
        $this->assertNull($manual->confirmed_at);

        // Ang sagot ng flow: tinanggap sa T (5 s), totoong naipadala sa T + 250 s.
        $chat = $this->chat('1001', '7002', '2026-10-26 19:00:00');
        $id = $this->reply($chat, '2026-10-26 19:00:05', ref: 'likha-ref-1', psid: '7002');
        $before = $this->out()['tiles']['today'];
        $this->sendStatus('likha-ref-1', 'sent', '2026-10-26 19:04:15', psid: '7002');
        $r = $this->row($id);
        $this->assertSame(['2026-10-26 19:04:15', 'sent', 'sent'], [$r->confirmed_at, $r->confirmed_status, $r->status]);
        $t = $this->out()['tiles']['today'];
        $this->assertEquals(255, $t['real_median']);
        $this->assertEquals(5, $t['reply_median']); // ang "accepted" ay hindi nagbago
        $this->assertSame($before['reply_median'], $t['reply_median']);

        // Ang ulit (kahit ibang oras) ay walang binabago.
        $this->sendStatus('likha-ref-1', 'sent', '2026-10-26 19:30:00', psid: '7002');
        $this->assertSame('2026-10-26 19:04:15', $this->row($id)->confirmed_at);
        $this->assertSame(1, $this->counted('send_status_repeat'));
    }

    public function test_S_156_2_failed_and_partial_show_like_a_botcake_failure_with_the_meta_code(): void
    {
        $a = $this->reply(null, '2026-10-26 19:00:00', ref: 'r-failed');
        $b = $this->reply(null, '2026-10-26 19:01:00', ref: 'r-partial');
        $this->sendStatus('r-failed', 'failed', null, ['error_code' => 551, 'error_subcode' => 1545041, 'error' => 'This person isn\'t available right now.']);
        $this->sendStatus('r-partial', 'partial', '2026-10-26 19:02:00', ['sent_count' => 2, 'error_code' => 10, 'error' => 'Outside the allowed window.']);

        $fa = $this->row($a);
        $this->assertSame(['failed', 'failed'], [$fa->status, $fa->confirmed_status]);
        $this->assertStringContainsString('(#551)', $fa->error);
        $this->assertStringContainsString('isn\'t available', $fa->error);
        $pa = $this->row($b);
        $this->assertSame(['partial', 'partial', '2026-10-26 19:02:00'], [$pa->status, $pa->confirmed_status, $pa->confirmed_at]);
        $this->assertStringContainsString('2 of 4', $pa->error);
        $this->assertStringContainsString('(#10)', $pa->error);

        $out = $this->out();
        $this->assertSame(2, $out['tiles']['today']['failed']);
        $this->assertSame(1, $out['tiles']['today']['reasons']['unreachable']);
        $this->assertSame(1, $out['tiles']['today']['reasons']['window']);
        $this->assertEqualsCanonicalizing([$a, $b], array_column($out['failures'], 'id'));
    }

    public function test_S_156_3_pending_counts_accepted_unconfirmed_replies_with_the_oldest_age_per_page(): void
    {
        $this->sendStatus($this->confirmedRef(), 'sent', '2026-10-26 19:00:10'); // may send_status na: available
        $this->reply(null, '2026-10-26 19:56:40', ref: 'p1'); // 200 s
        $this->reply(null, '2026-10-26 19:59:10', ref: 'p2'); // 50 s
        $this->reply(null, '2026-10-26 19:59:30', ref: 'p3', page: '1003'); // 30 s
        $this->reply(null, '2026-10-26 19:58:00', ref: 'p4', status: 'failed'); // pumalya sa 202: hindi pending
        $this->reply(null, '2026-10-26 19:58:00', via: 'botcake', page: '1002'); // Botcake: walang pending

        $real = $this->out()['real'];
        $this->assertTrue($real['available']);
        $this->assertSame(['count' => 3, 'oldest' => 200, 'light' => 'red'], $real['pending']);
        $pages = collect($real['pages'])->keyBy('page_id');
        $this->assertSame([2, 200], [$pages['1001']['pending'], $pages['1001']['oldest']]);
        $this->assertSame([1, 30], [$pages['1003']['pending'], $pages['1003']['oldest']]);
        $this->assertArrayNotHasKey('1002', $pages->all()); // walang data ang Botcake page ngayon
        // Isang page ang pinili: ang pending ng page na iyon lang.
        $this->assertSame(['count' => 1, 'oldest' => 30, 'light' => 'green'], $this->out(['page_id' => '1003'])['real']['pending']);

        $this->actingAs($this->user('CEO', 'ceo@example.test'));
        $html = $this->get('/chatbot/health')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-tile="pending"[^>]*data-light="red"/', $html);
    }

    /** Isang naipadala nang reply (para may send_status sa huling 24 oras); ang ref nito. */
    private function confirmedRef(string $page = '1001'): string
    {
        $this->reply(null, '2026-10-26 19:00:00', ref: 'done-' . $page, page: $page, psid: '7999');

        return 'done-' . $page;
    }

    public function test_S_156_4_real_first_reply_median_p90_max_per_bot_and_per_page_with_botcake_on_its_sent_at(): void
    {
        // Forbeast (1001): 5 s tinanggap, totoong 100 s at 300 s; isang pending pa (hindi kasama, hindi "mabilis").
        foreach ([['7001', 100], ['7002', 300]] as $i => [$psid, $real]) {
            $c = $this->chat('1001', $psid, '2026-10-26 18:00:00');
            $this->reply($c, '2026-10-26 18:00:05', ref: "f$i", psid: $psid);
            $this->sendStatus("f$i", 'sent', date('Y-m-d H:i:s', strtotime('2026-10-26 18:00:00') + $real), psid: $psid);
        }
        $c = $this->chat('1001', '7003', '2026-10-26 19:59:00');
        $this->reply($c, '2026-10-26 19:59:02', ref: 'f-pending', psid: '7003');
        // Botcake (1002): 4 s at 20 s; ang luma na walang confirmed_at ay ang sent_at din.
        $c = $this->chat('1002', '8001', '2026-10-26 17:00:00');
        $this->reply($c, '2026-10-26 17:00:04', 'botcake', page: '1002', psid: '8001');
        $c = $this->chat('1002', '8002', '2026-10-26 17:10:00');
        $legacy = $this->reply($c, '2026-10-26 17:10:20', 'botcake', page: '1002', psid: '8002');
        DB::table('chatbot_replies')->where('id', $legacy)->update(['confirmed_at' => null, 'confirmed_status' => null, 'via' => null]);

        $out = $this->out();
        $t = $out['tiles']['today'];
        $this->assertSame([60, 300, 300], [$t['real_median'], $t['real_p90'], $t['real_max']]);
        $bots = collect($out['compare'])->keyBy('bot');
        $this->assertSame([200, 300, 300], [$bots['Forbeast']['today']['real_median'], $bots['Forbeast']['today']['real_p90'], $bots['Forbeast']['today']['real_max']]);
        $this->assertSame([12, 20, 20], [$bots['Botcake']['today']['real_median'], $bots['Botcake']['today']['real_p90'], $bots['Botcake']['today']['real_max']]);
        $this->assertEquals(5, $bots['Forbeast']['today']['reply_median']); // accepted
        $pages = collect($out['real']['pages'])->keyBy('page_id');
        $this->assertSame([200, 300, 300], [$pages['1001']['real_median'], $pages['1001']['real_p90'], $pages['1001']['real_max']]);
        $this->assertSame([12, 20, 20], [$pages['1002']['real_median'], $pages['1002']['real_p90'], $pages['1002']['real_max']]);
        $this->assertSame(['median' => 195, 'p90' => 295, 'max' => 295], array_intersect_key($out['real']['lag'], array_flip(['median', 'p90', 'max'])));
        // Ang graph kada oras: ang totoong oras.
        $this->assertEquals(300, $out['hourly'][18]['max']);

        $this->actingAs($this->user('CEO', 'ceo@example.test'));
        $html = $this->get('/chatbot/health')->assertOk()->getContent();
        $this->assertStringContainsString('First reply median (accepted)', $html);
        $this->assertStringContainsString('Real first reply median', $html);
    }

    public function test_S_156_5_alarm_from_likha_pending_after_3_minutes_and_recovery_under_30s_without_a_switch(): void
    {
        $this->sendStatus($this->confirmedRef(), 'sent', '2026-10-26 19:00:10');
        $this->reply(null, '2026-10-26 19:57:00', ref: 'late'); // 180 s at 20:00

        $this->watch(2);
        $this->assertSame([], $this->lagEvents());
        $this->watch(1);
        $events = $this->lagEvents();
        $this->assertCount(1, $events);
        $this->assertSame('alarm', $events[0]['result']);
        $this->assertStringContainsString('pending', $events[0]['error']);
        $this->assertSame(['Page Uno'], $events[0]['pages']);
        $this->watch(3); // tuloy pa: walang ikalawa sa loob ng 30 minuto
        $this->assertCount(1, $this->lagEvents());

        $this->sendStatus('late', 'sent', now()->toDateTimeString());
        $this->watch(3);
        $events = $this->lagEvents();
        $this->assertSame(['alarm', 'recovered'], array_column($events, 'result'));
        $this->assertSame('gateway', app(PageSettings::class)->get('Page Uno', 'chatbot', 'provider'));
        $this->assertFalse(PageSetting::where('key', 'auto_switch')->get()->contains(fn ($s) => str_contains((string) $s->plainValue(), '"switched"')));
    }

    public function test_S_156_6_before_any_send_status_the_real_time_is_not_available_and_there_is_no_pending_alarm(): void
    {
        $this->reply(null, '2026-10-26 19:50:00', ref: 'never'); // 600 s, pero walang send_status kailanman
        $real = $this->out()['real'];
        $this->assertFalse($real['available']);
        $this->assertNull($real['pending']);
        $this->watch(5);
        $this->assertSame([], $this->lagEvents());

        $this->actingAs($this->user('CEO', 'ceo@example.test'));
        $html = $this->get('/chatbot/health')->assertOk()->getContent();
        $this->assertStringContainsString('real send time not available yet (Forbeast update pending)', $html);
        $this->assertStringNotContainsString('data-tile="pending"', $html);
    }

    public function test_S_156_7_an_unknown_client_ref_is_counted_and_ignored_and_a_reply_from_before_the_ref_is_matched_by_page_and_psid(): void
    {
        $id = $this->reply(null, '2026-10-26 19:00:00', ref: 'likha-known');
        $this->sendStatus('likha-unknown', 'sent', '2026-10-26 19:01:00');
        $this->assertNull($this->row($id)->confirmed_at);
        $this->assertSame(1, $this->counted('send_status_unknown'));

        // Ang row na walang client_ref (bago ang column): ang pinakamalapit na hindi pa kumpirmado sa page + PSID.
        $far = $this->reply(null, '2026-10-26 18:00:00', psid: '7005');
        $near = $this->reply(null, '2026-10-26 19:10:00', psid: '7005');
        $this->sendStatus('likha-old', 'sent', '2026-10-26 19:12:00', psid: '7005', acceptedAt: '2026-10-26 19:10:01');
        $this->assertSame(['2026-10-26 19:12:00', null], [$this->row($near)->confirmed_at, $this->row($far)->confirmed_at]);
    }

    public function test_S_156_8_unconfirmed_after_24_hours_is_unconfirmed_not_pending(): void
    {
        $this->sendStatus($this->confirmedRef(), 'sent', '2026-10-26 19:00:10');
        $this->reply(null, '2026-10-25 19:00:00', ref: 'old'); // 25 oras
        $this->reply(null, '2026-10-26 19:59:00', ref: 'new');

        $real = $this->out(['from' => '2026-10-20'])['real'];
        $this->assertSame(1, $real['pending']['count']);
        $this->assertSame(60, $real['pending']['oldest']);
        $this->assertSame(1, $real['unconfirmed']);
    }

    public function test_S_156_9_the_accepted_numbers_stay_as_they_were(): void
    {
        $c = $this->chat('1001', '7001', '2026-10-26 18:00:00');
        $this->reply($c, '2026-10-26 18:00:05', ref: 'a1');
        $c = $this->chat('1002', '8001', '2026-10-26 17:00:00');
        $this->reply($c, '2026-10-26 17:00:04', 'botcake', page: '1002', psid: '8001');
        $keys = ['inbound', 'answered', 'answered_pct', 'reply_avg', 'reply_median', 'reply_p90', 'reply_max', 'sent', 'failed'];
        $before = array_intersect_key($this->out()['tiles']['today'], array_flip($keys));
        $this->sendStatus('a1', 'sent', '2026-10-26 18:20:00');
        $this->assertSame($before, array_intersect_key($this->out()['tiles']['today'], array_flip($keys)));
        $this->assertSame([2, 2, 4.5], [$before['inbound'], $before['answered'], $before['reply_median']]);
    }

    public function test_S_156_10_the_real_send_section_wraps_at_390px(): void
    {
        $this->sendStatus($this->confirmedRef(), 'sent', '2026-10-26 19:00:10');
        $this->actingAs($this->user('CEO', 'ceo@example.test'));
        $html = $this->get('/chatbot/health')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-panel="real-send"[\s\S]*grid-cols-2[\s\S]*overflow-x-auto/', $html);
    }
}

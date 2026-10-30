<?php

namespace Tests\Feature\Chatblast;

use App\Services\Chatbot\ResponseSpeed;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Spec 201 (amendment 1), /chatbot/speed: ang dobleng chat ay isang mensahe, at ang "Not sent": ang mensaheng pumalya
 * ang sagot ng Main Flow o AI (padala na failed, hindi sequence), hinati sa hindi kailanman nasagot at nasagot pagkatapos
 * ng pagpalya. Ngayon ay 2026-10-21 12:30. Page Uno (1001) ay Forbeast, Page Dos (1002) ay Botcake.
 */
class SpeedNotSentTest extends ChatblastTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_26_100000_create_chatbot_sequence_tables.php',
            'database/migrations/2026_10_28_100000_create_chatbot_ai_requests_table.php',
            'database/migrations/2026_10_30_098000_add_raw_to_chatbot_replies.php',
            'database/migrations/2026_10_30_100000_add_results_to_chatbot_replies.php',
            'database/migrations/2026_11_06_100000_add_chat_index_to_chatbot_replies.php',
            'database/migrations/2026_11_09_100000_add_via_to_chatbot_replies.php',
            'database/migrations/2026_11_11_100000_add_real_send_to_chatbot_replies.php',
        ]]);
        $this->travelTo('2026-10-21 12:30:00');
        foreach (['Page Uno' => '1001', 'Page Dos' => '1002'] as $name => $id) {
            $this->page($name, $id);
            DB::table('page_settings')->insert(['page_name' => $name, 'provider' => 'chatbot', 'key' => 'flow_id', 'value' => '1', 'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00']);
        }
        $this->seed201();
    }

    private function chat(string $page, string $psid, string $at, array $payload = []): void
    {
        DB::table('botcake_chats')->insert(['page_id' => $page, 'page_name' => 'x', 'psid' => $psid, 'received_at' => $at,
            'payload' => json_encode($payload + ['message' => ['text' => 'Magkano po ito sa Quezon City?']] + ($page === '1001' ? ['source' => 'gateway'] : []))]);
    }

    private function send(string $page, string $psid, string $at, string $status = 'sent', array $extra = []): void
    {
        $via = $page === '1001' ? 'forbeast' : 'botcake';
        DB::table('chatbot_replies')->insert($extra + ['page_id' => (int) $page, 'psid' => (int) $psid, 'node_id' => 'n2', 'kind' => 'send', 'status' => $status, 'sent_at' => $at, 'via' => $via]
            + ($status === 'sent' ? ['confirmed_at' => $at, 'confirmed_status' => 'sent'] : []));
    }

    private function seed201(): void
    {
        // 09:00: normal. Ang pumalyang sequence step, ang test at ang pumalya bago ang mensahe ay hindi "not sent".
        $this->chat('1001', '7005', '2026-10-21 09:00:00');
        $this->send('1001', '7005', '2026-10-21 09:00:02', 'failed', ['sequence_id' => 5, 'sequence_step' => 1, 'error' => 'Forbeast could not send (#551)']);
        $this->send('1001', '7005', '2026-10-21 09:00:03', 'test');
        $this->send('1001', '7005', '2026-10-21 09:00:10');
        $this->send('1002', '7006', '2026-10-21 08:59:00', 'failed', ['error' => 'Botcake refused (HTTP 500)']);
        $this->chat('1002', '7006', '2026-10-21 09:00:00');
        $this->send('1002', '7006', '2026-10-21 09:00:05');
        // 10:05: ang parehong mensahe nang dalawang beses (magkasunod na ID): isang mensahe, 300 s.
        $this->chat('1002', '7001', '2026-10-21 10:05:00');
        $this->chat('1002', '7001', '2026-10-21 10:05:00');
        $this->send('1002', '7001', '2026-10-21 10:10:00');
        // 10:10: hindi kailanman naipadala: dalawang pumalya, walang naipadala.
        $this->chat('1001', '7002', '2026-10-21 10:10:00');
        $this->send('1001', '7002', '2026-10-21 10:10:05', 'failed', ['error' => 'Forbeast could not send: "No matching user found" (#551) subcode 1545041']);
        $this->send('1001', '7002', '2026-10-21 10:11:00', 'failed', ['error' => 'Forbeast could not send: "Rate limit" (#10)']);
        // 10:20: ang kopya ng Botcake at ang totoong chat sa parehong oras: isa lang (ang kopya ay hindi binibilang), walang sagot.
        $this->chat('1001', '7009', '2026-10-21 10:20:00', ['_standby' => true]);
        $this->chat('1001', '7009', '2026-10-21 10:20:00');
        // 11:00: nasagot pagkatapos ng isang pumalya (Botcake, walang code), 120 s.
        $this->chat('1002', '7003', '2026-10-21 11:00:00');
        $this->send('1002', '7003', '2026-10-21 11:00:03', 'failed', ['error' => 'Botcake refused (HTTP 500)']);
        $this->send('1002', '7003', '2026-10-21 11:02:00');
        // 11:30: nasagot pagkatapos ng isang pumalya (Forbeast), naipadala sa Meta 11:30:41 → 41 s.
        $this->chat('1001', '7004', '2026-10-21 11:30:00');
        $this->send('1001', '7004', '2026-10-21 11:30:02', 'failed', ['error' => 'Forbeast could not send: "x" (#368) subcode 1390008']);
        $this->send('1001', '7004', '2026-10-21 11:30:40', 'sent', ['confirmed_at' => '2026-10-21 11:30:41', 'confirmed_status' => 'sent']);
        // Ang ibang segundo ng parehong customer ay ibang mensahe.
        $this->chat('1001', '7004', '2026-10-21 11:30:03');
        // Kahapon: isang hindi naipadala.
        $this->chat('1001', '7010', '2026-10-20 15:00:00');
        $this->send('1001', '7010', '2026-10-20 15:00:04', 'failed', ['error' => 'Forbeast could not send (#200)']);
        // Kahapon: pumalya at naipadala sa parehong segundo (ang pumalya ang nauna, mas mababang ID): naipadala pagkatapos ng pumalya.
        $this->chat('1002', '7011', '2026-10-20 16:00:00');
        $this->send('1002', '7011', '2026-10-20 16:00:05', 'failed', ['error' => 'Botcake refused (HTTP 500)']);
        $this->send('1002', '7011', '2026-10-20 16:00:05');
    }

    private function out(array $q = []): array
    {
        Cache::flush();

        return app(ResponseSpeed::class)->output($q);
    }

    private function ceo(): void
    {
        $this->actingAs($this->user('CEO', 'ceo-201n@example.test'));
    }

    public function test_S_201_7_a_chat_stored_twice_counts_as_one_message(): void
    {
        $o = $this->out();
        // 10:00: 7001 (isa, hindi dalawa), 7002, 7009 (ang kopya ay wala). 11:00: 7003, 7004 at 7004 ng 11:30:03.
        $this->assertSame([2, 3, 3], [$o['hours'][9]['messages'], $o['hours'][10]['messages'], $o['hours'][11]['messages']]);
        $this->assertSame(8, $o['total']['messages']);
        // Ang 300 s ng 7001 ay isang beses lang sa average ng 10:00 (ang tanging nasagot sa oras na iyon).
        $this->assertSame([1, 300.0, 300], [$o['hours'][10]['answered'], $o['hours'][10]['avg'], $o['hours'][10]['median']]);
        $this->assertSame([3, 5], [$o['by_bot']['Botcake']['messages'], $o['by_bot']['Forbeast']['messages']]);
        // Ang banner ng mga problema (pageSeconds) ay pareho: isang 300 s para sa Page Dos sa 10:00.
        $this->assertSame([300], app(ResponseSpeed::class)->pageSeconds('2026-10-21 10:00:00', '2026-10-21 11:00:00', '2026-10-21 12:30:00')['1002']);
    }

    /**
     * Specs 201 at 204: ang pagbukas ng ad at ang unang mensahe sa parehong segundo ay hindi doble. Ang pagbukas ay hindi
     * mensahe at hindi tinatandaan ng dedupe, kaya ang mensahe ay binibilang nang isang beses, sa page at sa banner.
     */
    public function test_S_204_6_an_ad_open_in_the_same_second_as_the_first_message_does_not_hide_the_message(): void
    {
        $this->chat('1001', '7050', '2026-10-21 12:00:00', ['ad_id' => '6045246247433', \App\Services\Chatbot\ChatbotInbound::AD_OPEN => true]);
        $this->chat('1001', '7050', '2026-10-21 12:00:00');
        $this->send('1001', '7050', '2026-10-21 12:00:04');

        $o = $this->out();
        $this->assertSame([1, 1, 4.0], [$o['hours'][12]['messages'], $o['hours'][12]['answered'], $o['hours'][12]['avg']]);
        $this->assertSame(9, $o['total']['messages']);
        $this->assertSame([4], app(ResponseSpeed::class)->pageSeconds('2026-10-21 12:00:00', '2026-10-21 13:00:00', '2026-10-21 12:30:00')['1001']);
    }

    public function test_S_201_8_not_sent_splits_never_answered_from_answered_after_a_failure(): void
    {
        $o = $this->out();
        $this->assertSame(['never' => 0, 'late' => 0, 'attempts' => 0, 'late_avg' => null], $o['hours'][9]['not_sent']);
        $this->assertSame(['never' => 1, 'late' => 0, 'attempts' => 2, 'late_avg' => null], $o['hours'][10]['not_sent']);
        $this->assertSame(['never' => 0, 'late' => 2, 'attempts' => 2, 'late_avg' => 80.5], $o['hours'][11]['not_sent']);
        $this->assertSame(['never' => 1, 'late' => 2, 'attempts' => 4, 'late_avg' => 80.5], $o['total']['not_sent']);
        $this->assertNull($o['hours'][13]);
        // Ang mga mensaheng ito ay nasa average pa rin gaya ng dati: ang 11:00 ay 120 s, 41 s at 38 s (ang 11:30:03).
        $this->assertSame([3, 66.3], [$o['hours'][11]['answered'], $o['hours'][11]['avg']]);
        // Kada bot, at ang Page at Bot filter.
        $this->assertSame([1, 1], [$o['by_bot']['Forbeast']['not_sent']['never'], $o['by_bot']['Forbeast']['not_sent']['late']]);
        $this->assertSame([0, 1], [$o['by_bot']['Botcake']['not_sent']['never'], $o['by_bot']['Botcake']['not_sent']['late']]);
        $this->assertSame(['never' => 0, 'late' => 1, 'attempts' => 1, 'late_avg' => 120.0], $this->out(['bot' => 'Botcake'])['total']['not_sent']);
        $this->assertSame(['never' => 1, 'late' => 1, 'attempts' => 3, 'late_avg' => 41.0], $this->out(['page_id' => '1001'])['total']['not_sent']);
        $this->assertSame(['never' => 1, 'late' => 1, 'attempts' => 2, 'late_avg' => 5.0], $this->out(['date' => '2026-10-20'])['total']['not_sent']);
        // Ang chart ng reply time: ang bilang kada oras (puwang ang hindi pa naaabot).
        $c = $this->out()['charts']['reply'];
        $this->assertSame([0, 1, 2, 0, null], [$c['not_sent'][9], $c['not_sent'][10], $c['not_sent'][11], $c['not_sent'][12], $c['not_sent'][13]]);

        // Ang table ng mga oras: ang tatlong bagong column sa dulo, at ang chart script ay may sariling axis para rito.
        $this->ceo();
        Cache::flush();
        $html = $this->get('/chatbot/speed')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#data-table="hours">\s*<thead><tr><th>Hour</th>(<th[^>]*>[^<]*</th>){11}<th class="!text-right">Never sent</th><th class="!text-right">Sent late</th><th class="!text-right">Late avg</th></tr>#', $html);
        $this->assertMatchesRegularExpression('#<tr data-hour="11">(?:(?!</tr>).)*<td class="text-right tabular-nums">0</td>\s*<td class="text-right tabular-nums">2</td>\s*<td class="text-right tabular-nums">80.5 s</td>\s*</tr>#s', $html);
        $this->assertStringContainsString("c.not_sent", $html);
    }

    public function test_S_201_4_the_not_sent_counts_come_from_the_same_hourly_reads(): void
    {
        Cache::flush();
        $n = 0;
        DB::listen(function ($q) use (&$n): void {
            if (preg_match('/from\s+"chatbot_replies"/', $q->sql)) $n++;
        });
        $this->out();
        // Ngayon at ang naunang araw: 48 oras ng padala bawat isa (ang araw at ang 24 oras na hangganan), walang query kada mensahe.
        $this->assertSame(96, $n);
    }

    public function test_S_201_9_the_not_sent_list_newest_first_with_page_bot_error_attempts_and_inbox_link(): void
    {
        $this->ceo();
        $j = $this->getJson('/chatbot/speed/not-sent')->assertOk()->json();
        $this->assertSame(['date' => '2026-10-21', 'page_id' => null, 'bot' => null], $j['filters']);
        $this->assertNull($j['hour']);
        $this->assertSame(3, $j['total']);
        $this->assertSame([
            ['at' => '2026-10-21 11:30:00', 'page' => 'Page Uno', 'bot' => 'Forbeast', 'kind' => 'late', 'attempts' => 1, 'wait' => 41, 'code' => 368, 'subcode' => 1390008, 'inbox' => '/chatbot/inbox?page_id=1001&psid=7004'],
            ['at' => '2026-10-21 11:00:00', 'page' => 'Page Dos', 'bot' => 'Botcake', 'kind' => 'late', 'attempts' => 1, 'wait' => 120, 'code' => null, 'subcode' => null, 'inbox' => '/chatbot/inbox?page_id=1002&psid=7003'],
            ['at' => '2026-10-21 10:10:00', 'page' => 'Page Uno', 'bot' => 'Forbeast', 'kind' => 'never', 'attempts' => 2, 'wait' => null, 'code' => 10, 'subcode' => null, 'inbox' => '/chatbot/inbox?page_id=1001&psid=7002'],
        ], $j['items']);
        // Walang teksto ng customer, at ang error ng Meta ay ang code lang.
        $this->assertStringNotContainsString('Magkano', json_encode($j));
        $this->assertStringNotContainsString('Rate limit', json_encode($j));
        foreach (['data-table="not-sent"', '11:30:00', 'Never sent', 'Sent late', '#368 / 1390008', 'href="/chatbot/inbox?page_id=1001&amp;psid=7002"'] as $part) $this->assertStringContainsString($part, $j['html']);

        // Ang oras, ang Page at Bot filter, at ang araw.
        $at = fn (string $q) => array_column($this->getJson('/chatbot/speed/not-sent?' . $q)->assertOk()->json('items'), 'at');
        $this->assertSame(['2026-10-21 11:30:00', '2026-10-21 11:00:00'], $at('hour=11'));
        $this->assertSame(['2026-10-21 10:10:00'], $at('hour=10'));
        $this->assertSame([], $at('hour=9'));
        $this->assertSame(['2026-10-21 11:00:00'], $at('page_id=1002'));
        $this->assertSame(['2026-10-21 11:30:00', '2026-10-21 10:10:00'], $at('bot=Forbeast'));
        $this->assertSame(['2026-10-20 16:00:00', '2026-10-20 15:00:00'], $at('date=2026-10-20'));
        $this->assertSame(1, $this->getJson('/chatbot/speed/not-sent?date=2026-10-20&hour=16')->json('total'));
        // Ang maling oras ay ang buong araw.
        foreach (['hour=24', 'hour=x', 'hour[]=1', 'hour=-1'] as $q) $this->assertNull($this->getJson('/chatbot/speed/not-sent?' . $q)->assertOk()->json('hour'), $q);
        // Ang access ay gaya ng page.
        $this->actingAs($this->user('Data Encoder', 'enc-201n@example.test'));
        $this->getJson('/chatbot/speed/not-sent')->assertForbidden();
    }

    public function test_S_201_9_the_page_loads_the_list_when_it_is_opened(): void
    {
        $this->ceo();
        $html = $this->get('/chatbot/speed?page_id=1001')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<details class="sp-table mt-3" data-sp-notsent="/chatbot/speed/not-sent\?page_id=1001&amp;date=2026-10-21">#', $html);
        $this->assertStringContainsString('Not sent list', $html);
        $this->assertStringContainsString("searchParams.set('hour'", $html);
        // Ang listahan ay mula sa parehong cache ng page: walang bagong basa sa malalaking table.
        $n = 0;
        DB::listen(function ($q) use (&$n): void {
            if (preg_match('/from\s+"(chatbot_replies|botcake_chats)"/', $q->sql)) $n++;
        });
        $this->getJson('/chatbot/speed/not-sent?page_id=1001&hour=10')->assertOk();
        $this->assertSame(0, $n);
    }
}

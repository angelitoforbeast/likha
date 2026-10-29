<?php

namespace Tests\Feature\Chatblast;

use App\Services\Chatbot\MetaReplySpeed;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Spec 193, /chatbot/speed: ang unang section ay ang totoong reply time sa orasan ng Meta (customer sent → sagot namin
 * sent), galing sa Forbeast (GET /v1/site/reply-speed). Ngayon ay 2026-10-21 12:30. Ang Forbeast ay fake: ngayon
 * 10:00 ay 4 na mensahe (avg 3.4 s), kahapon 10:00 ay avg 5 s.
 */
class MetaReplySpeedTest extends ChatblastTestCase
{
    private const URL = 'https://forbeast.example.test/api/v1/site/reply-speed*';

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
        // Dalawang chat sa Botcake (Page Dos) at isa sa Forbeast (Page Uno) ngayon.
        foreach ([['1002', '7001'], ['1002', '7002'], ['1001', '7003']] as [$page, $psid]) {
            DB::table('botcake_chats')->insert(['page_id' => $page, 'page_name' => 'x', 'psid' => $psid, 'received_at' => '2026-10-21 10:05:00',
                'payload' => json_encode($page === '1001' ? ['source' => 'gateway'] : [])]);
        }
        config(['services.gateway.url' => 'https://forbeast.example.test/api', 'services.forbeast_site.key' => 'fbs_test_key', 'services.forbeast_site.secret' => 'site-secret']);
        Cache::flush();
    }

    /** Ang sagot ng Forbeast sa isang araw: ang mga oras hanggang $last (ang hinaharap ng ngayon ay wala), may numero ang 10:00. */
    private static function day(string $date, ?string $page, int $last, array $ten): array
    {
        $blank = ['messages' => 0, 'bot_answered' => 0, 'person_answered' => 0, 'unanswered' => 0, 'avg_s' => null, 'median_s' => null, 'p90_s' => null, 'max_s' => null, 'meta_to_forbeast_avg_s' => null];
        $hours = array_map(fn ($h) => ['hour' => $h] + ($h === 10 ? $ten : $blank), range(0, $last));

        return ['date' => $date, 'page_id' => $page, 'timezone' => 'Asia/Manila', 'hours' => $hours, 'total' => $ten, 'generated_at' => $date . 'T04:30:00Z'];
    }

    private const TODAY = ['messages' => 4, 'bot_answered' => 3, 'person_answered' => 1, 'unanswered' => 0, 'avg_s' => 3.4, 'median_s' => 3, 'p90_s' => 5.1, 'max_s' => 6, 'meta_to_forbeast_avg_s' => 2.6];
    private const YESTERDAY = ['messages' => 2, 'bot_answered' => 2, 'person_answered' => 0, 'unanswered' => 0, 'avg_s' => 5, 'median_s' => 5, 'p90_s' => 7, 'max_s' => 7, 'meta_to_forbeast_avg_s' => 2.4];

    private mixed $next = null;
    private bool $faked = false;

    /**
     * Ang isasagot ng fake na Forbeast mula ngayon. Iisang stub lang ang nirerehistro: ang Http::fake ay nagdadagdag ng
     * stub at ang una ang nananalo, kaya ang pagpapalit ay sa $next.
     */
    private function answer(mixed $a): void
    {
        $this->next = $a;
        if ($this->faked) return;
        $this->faked = true;
        Http::fake([self::URL => fn (Request $r) => $this->next instanceof \Closure ? ($this->next)($r) : $this->next]);
    }

    /** Ang fake na Forbeast: ngayon (hanggang 12:00) at kahapon (buong araw), ayon sa date ng tawag. */
    private function fakeForbeast(): void
    {
        $this->answer(function (Request $r) {
            $q = $r->data();
            $page = $q['page_id'] ?? null;

            return match ($q['date'] ?? null) {
                '2026-10-21' => Http::response(self::day('2026-10-21', $page, 12, self::TODAY)),
                '2026-10-20' => Http::response(self::day('2026-10-20', $page, 23, self::YESTERDAY)),
                default => Http::response(self::day((string) ($q['date'] ?? ''), $page, 23, ['messages' => 0] + self::TODAY)),
            };
        });
    }

    /** Ang screen bilang CEO: [data ng mga chart, response]. Ang cache ay hindi binubura (para sa S-193.6). */
    private function screen(string $query = ''): array
    {
        $this->actingAs($this->ceo ??= $this->user('CEO', 'ceo-meta@example.test'));
        $res = $this->get('/chatbot/speed' . ($query !== '' ? '?' . $query : ''))->assertOk();
        $this->assertSame(1, preg_match('#<script type="application/json" id="sp-data">(.*?)</script>#s', $res->getContent(), $m));

        return [json_decode($m[1], true), $res];
    }

    private ?object $ceo = null;

    /** Ang section ng Meta (mula sa heading nito hanggang sa susunod na section), bilang HTML. */
    private static function section(string $html): string
    {
        preg_match('#<section aria-labelledby="h-meta">(.*?)</section>#s', $html, $m);

        return $m[1] ?? '';
    }

    private static function text(string $html): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES)));
    }

    public function test_S_193_1_the_table_and_chart_show_the_forbeast_hours_and_day_total(): void
    {
        $this->fakeForbeast();
        [$c, $res] = $this->screen();
        $html = $res->getContent();
        $s = self::section($html);
        $m = $res->viewData('meta');

        // Ang unang section ng screen, bago ang dati.
        $this->assertMatchesRegularExpression('#id="h-meta".*id="h-hours"#s', $html);
        $this->assertStringContainsString("Reply time at Meta (customer sent → our reply sent, Meta's clock)", self::text($s));
        $this->assertSame('ok', $m['state']);
        // Ang mga oras: 10:00 ay may numero, ang walang mensahe ay 0 na walang oras, ang lampas sa ngayon ay blangko.
        $this->assertSame(['hour' => 10, 'messages' => 4, 'bot_answered' => 3, 'person_answered' => 1, 'unanswered' => 0,
            'avg' => 3.4, 'median' => 3.0, 'p90' => 5.1, 'max' => 6.0, 'meta_to_forbeast_avg' => 2.6], $m['hours'][10]);
        $this->assertSame([0, null], [$m['hours'][3]['messages'], $m['hours'][3]['avg']]);
        $this->assertNull($m['hours'][13]);
        $this->assertSame(4, $m['total']['messages']);
        // Ang table: lahat ng column at ang Day na row.
        foreach (['Messages', 'Bot answered', 'By another app or a person', 'Unanswered', 'Average', 'Median', 'p90', 'Max', 'Meta → Forbeast avg'] as $col) {
            $this->assertStringContainsString('>' . $col . '</th>', html_entity_decode($s), $col);
        }
        $this->assertMatchesRegularExpression('#<tr data-hour="10">\s*<td[^>]*>10:00</td>\s*<td[^>]*>4</td>\s*<td[^>]*>3</td>\s*<td[^>]*>1</td>\s*<td[^>]*>0</td>\s*<td[^>]*>3.4 s</td>\s*<td[^>]*>3 s</td>\s*<td[^>]*>5.1 s</td>\s*<td[^>]*>6 s</td>\s*<td[^>]*>2.6 s</td>#', $s);
        $this->assertMatchesRegularExpression('#<tr class="font-semibold"[^>]*><td>Day</td>\s*<td[^>]*>4</td>#', $s);
        $this->assertStringContainsString('Day: 4 messages · 3 bot answered · 1 by another app or a person · 0 unanswered · avg 3.4 s · median 3 s · p90 5.1 s · max 6 s · Meta → Forbeast avg 2.6 s', self::text($s));
        // Ang chart: average, median at p90 mula sa table; ang hinaharap ay puwang.
        $this->assertStringContainsString('<canvas id="sp-chart-meta"', $s);
        $this->assertSame(['avg', 'median', 'p90'], array_keys($c['meta']['series']));
        $this->assertEquals([3.4, 3, 5.1], [$c['meta']['series']['avg'][10], $c['meta']['series']['median'][10], $c['meta']['series']['p90'][10]]);
        $this->assertNull($c['meta']['series']['avg'][3]);
        $this->assertNull($c['meta']['series']['avg'][13]);
        $this->assertSame(4, $c['meta']['counts']['messages'][10]);
        $this->assertStringContainsString("meta: [['avg', 'Average'", $html);
    }

    public function test_S_196_8_the_person_column_says_another_app_or_a_person_with_the_botcake_hint(): void
    {
        $this->fakeForbeast();
        [$c, $res] = $this->screen();
        $s = self::section($res->getContent());
        $text = self::text($s);

        $this->assertStringContainsString('1 by another app or a person ·', $text);
        $this->assertStringContainsString('>By another app or a person</th>', html_entity_decode($s));
        $this->assertStringContainsString('Botcake replies count here', $text);
        $this->assertArrayHasKey('by another app or a person', $c['meta']['counts']);
        $this->assertDoesNotMatchRegularExpression('/\bby a person\b/i', preg_replace('/another app or a person/i', '', $text));
    }

    public function test_S_193_1_only_forbeast_pages_are_in_it_and_the_botcake_chats_are_counted(): void
    {
        $this->fakeForbeast();
        [, $res] = $this->screen();

        $this->assertStringContainsString('Only pages on Forbeast are in this section. 2 of the day\'s messages went through Botcake and are not in it.', self::text(self::section($res->getContent())));
    }

    public function test_S_193_2_the_page_filter_passes_page_id(): void
    {
        $this->fakeForbeast();
        $this->screen('page_id=1001');

        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://forbeast.example.test/api/v1/site/reply-speed?')
            && $r->data() === ['date' => '2026-10-21', 'page_id' => '1001'] && $r->hasHeader('Authorization', 'Bearer fbs_test_key'));
        Http::assertNotSent(fn (Request $r) => !isset($r->data()['page_id']));

        Cache::flush();
        $this->screen();
        Http::assertSent(fn (Request $r) => $r->data() === ['date' => '2026-10-21']);
    }

    public function test_S_193_3_the_previous_day_is_drawn_as_reference(): void
    {
        $this->fakeForbeast();
        [$c] = $this->screen();

        Http::assertSent(fn (Request $r) => ($r->data()['date'] ?? null) === '2026-10-20');
        $this->assertSame('2026-10-20', $c['prev_date']);
        $this->assertEquals([5, 5, 7], [$c['meta']['prev']['avg'][10], $c['meta']['prev']['median'][10], $c['meta']['prev']['p90'][10]]);
        $this->assertCount(24, $c['meta']['prev']['avg']);
        $this->assertSame(2, $c['meta']['prev_counts']['messages'][10]);
    }

    public function test_S_193_4_not_linked_shows_so_and_calls_nothing(): void
    {
        Http::fake();
        foreach (['services.gateway.url', 'services.forbeast_site.key'] as $missing) {
            config(['services.gateway.url' => 'https://forbeast.example.test/api', 'services.forbeast_site.key' => 'fbs_test_key', $missing => '']);
            Cache::flush();
            [$c, $res] = $this->screen();
            $this->assertStringContainsString('Not linked to Forbeast', self::text(self::section($res->getContent())), $missing);
            $this->assertArrayNotHasKey('meta', $c);
            $this->assertStringNotContainsString('sp-chart-meta', $res->getContent());
            $this->assertStringContainsString('<canvas id="sp-chart-reply"', $res->getContent());
        }
        Http::assertNothingSent();
    }

    public function test_S_193_4_the_botcake_filter_says_botcake_gives_no_meta_times(): void
    {
        Http::fake();
        [$c, $res] = $this->screen('bot=Botcake');

        $this->assertStringContainsString('Botcake gives no Meta times', self::text(self::section($res->getContent())));
        $this->assertArrayNotHasKey('meta', $c);
        Http::assertNothingSent();
    }

    public function test_S_193_5_forbeast_down_slow_or_refusing_still_renders_the_page(): void
    {
        $cases = [
            'timeout' => fn () => Http::failedConnection('cURL error 28: Operation timed out'),
            '500' => fn () => Http::response(['message' => 'Server Error'], 500),
            '404' => fn () => Http::response(['error' => 'unknown_page'], 404),
            'not json' => fn () => Http::response('<html>bad gateway</html>', 200),
            'wrong shape' => fn () => Http::response(['hours' => 'x', 'total' => ['messages' => 'many']], 200),
        ];
        $n = 0;
        foreach ($cases as $name => $answer) {
            $this->answer($answer());
            Cache::flush();
            [$c, $res] = $this->screen('page_id=1001');
            $html = $res->getContent();
            $s = self::text(self::section($html));
            $this->assertMatchesRegularExpression('#Forbeast did not answer \(12:30(:\d\d)?\)#', $s, $name);
            $this->assertArrayNotHasKey('meta', $c, $name);
            $this->assertStringNotContainsString('sp-chart-meta', $html, $name);
            // Ang ibang bahagi ng screen ay gaya ng dati.
            foreach (['reply', 'ai', 'push', 'seq'] as $k) $this->assertStringContainsString('<canvas id="sp-chart-' . $k . '"', $html, "$name $k");
            // Isang tawag lang: ang naunang araw ay hindi na tinatawag kapag hindi sumagot ang araw.
            Http::assertSentCount(++$n);
        }
        // Ang tawag ay may hangganang 5 s.
        $this->assertSame(5, MetaReplySpeed::TIMEOUT);
    }

    public function test_S_193_5_hostile_values_are_dropped_and_forbeast_text_is_escaped(): void
    {
        // Ang bawat field na mali ang uri o negatibo: 0 ang bilang, walang oras.
        $bad = ['messages' => -3, 'bot_answered' => '7', 'person_answered' => 1.5, 'unanswered' => null, 'avg_s' => '3.4', 'median_s' => -1, 'p90_s' => true, 'max_s' => [9], 'meta_to_forbeast_avg_s' => 'x'];
        $this->answer(Http::response(self::day('2026-10-21', null, 12, $bad)));
        [$c, $res] = $this->screen();
        $m = $res->viewData('meta');
        $this->assertSame('ok', $m['state']);
        $this->assertSame(['hour' => 10, 'messages' => 0, 'bot_answered' => 0, 'person_answered' => 0, 'unanswered' => 0,
            'avg' => null, 'median' => null, 'p90' => null, 'max' => null, 'meta_to_forbeast_avg' => null], $m['hours'][10]);
        $this->assertNull($c['meta']['series']['avg'][10]);

        // Ang hugis na hindi mabasa: ang oras na lampas sa 23, oras na text, at mga oras na hindi listahan.
        $ok = self::day('2026-10-21', null, 12, self::TODAY);
        foreach (['hour 24' => [['hour' => 24] + self::TODAY], 'hour text' => [['hour' => '10'] + self::TODAY], 'not a list' => ['a' => ['hour' => 10] + self::TODAY]] as $name => $hours) {
            $this->answer(Http::response(['hours' => $hours] + $ok));
            Cache::flush();
            [, $res] = $this->screen();
            $this->assertStringContainsString('Its answer could not be read.', self::text(self::section($res->getContent())), $name);
        }

        // Ang text ng Forbeast ay escaped at putol sa 200.
        $this->answer(Http::response(['message' => '<script>alert(1)</script>' . str_repeat('y', 300)], 500));
        Cache::flush();
        [, $res] = $this->screen();
        $s = self::section($res->getContent());
        $this->assertStringNotContainsString('<script>alert', $s);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $s);
        $this->assertStringNotContainsString(str_repeat('y', 200), $s);
    }

    public function test_S_193_5_a_page_forbeast_does_not_know_says_so(): void
    {
        $this->answer(Http::response(['error' => 'unknown_page'], 404));
        [, $res] = $this->screen('page_id=1002');

        $this->assertStringContainsString('This page is not on Forbeast for this site.', self::text(self::section($res->getContent())));
    }

    public function test_S_193_3_when_only_the_previous_day_fails_the_day_is_still_drawn(): void
    {
        $this->answer(fn (Request $r) => ($r->data()['date'] ?? null) === '2026-10-21'
            ? Http::response(self::day('2026-10-21', null, 12, self::TODAY)) : Http::response([], 500));
        [$c, $res] = $this->screen();

        $this->assertSame('ok', $res->viewData('meta')['state']);
        $this->assertEquals(3.4, $c['meta']['series']['avg'][10]);
        $this->assertSame(array_fill(0, 24, null), $c['meta']['prev']['avg']);
        Http::assertSentCount(2);
    }

    public function test_S_193_5_the_calls_have_a_5_s_limit_together(): void
    {
        // Ang araw ay may 5 s; ang naunang araw ay ang natitira sa 5 s (hindi bababa sa 1 s), kaya ang screen ay hindi
        // naghihintay nang higit sa mga 6 s kahit mabagal ang Forbeast.
        $site = \Mockery::mock(\App\Services\Chatbot\ForbeastSite::class);
        $site->shouldReceive('replySpeed')->once()->with('2026-10-21', null, 5)->andReturnUsing(function () {
            usleep(1_200_000);

            return ['ok' => true, 'status' => 200, 'body' => self::day('2026-10-21', null, 12, self::TODAY), 'error' => null];
        });
        $site->shouldReceive('replySpeed')->once()->with('2026-10-20', null, \Mockery::on(fn ($t) => $t >= 1 && $t <= 3))
            ->andReturn(['ok' => true, 'status' => 200, 'body' => self::day('2026-10-20', null, 23, self::YESTERDAY), 'error' => null]);
        $this->app->instance(\App\Services\Chatbot\ForbeastSite::class, $site);

        [$c] = $this->screen();
        $this->assertEquals(5, $c['meta']['prev']['avg'][10]);
    }

    public function test_S_193_6_a_second_load_makes_no_http_call_until_the_cache_ends(): void
    {
        $this->fakeForbeast();
        $this->screen();
        Http::assertSentCount(2); // ngayon at kahapon
        $this->screen();
        Http::assertSentCount(2);

        // Ngayon: 2 minuto; ang kahapon: 30 minuto.
        $this->travel(121)->seconds();
        $this->screen();
        Http::assertSentCount(3);
        $this->travel(30)->minutes();
        $this->screen();
        Http::assertSentCount(5);
        $this->assertSame([120, 1800], [MetaReplySpeed::TODAY_CACHE_SECONDS, MetaReplySpeed::PAST_CACHE_SECONDS]);
    }

    public function test_S_193_6_a_failed_answer_is_kept_a_minute_so_a_down_forbeast_does_not_slow_every_load(): void
    {
        $this->answer(Http::failedConnection());
        $this->screen();
        $this->screen();
        Http::assertSentCount(1);
        $this->travel(61)->seconds();
        $this->screen();
        Http::assertSentCount(2);
    }

    public function test_S_193_8_the_table_scrolls_inside_its_box_and_the_chart_follows_the_screen_width(): void
    {
        $this->fakeForbeast();
        [, $res] = $this->screen();
        $s = self::section($res->getContent());

        $this->assertMatchesRegularExpression('#<details class="sp-table mt-3" data-sp-table="meta">.*<div class="cb-card overflow-x-auto">\s*<table class="cb-table min-w-\[\d+rem\]" data-table="meta">#s', $s);
        $this->assertMatchesRegularExpression('#<div class="relative h-\d+ w-full[^"]*">\s*<canvas id="sp-chart-meta"#', $s);
    }
}

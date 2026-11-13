<?php

namespace Tests\Feature\Chatblast;

use App\Services\Chatbot\ChatbotAiLoad;
use App\Services\Chatbot\ChatbotAiQueue;
use App\Services\ChatblastSetupCheck;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Spec 231: ang AI step na walang sagot sa banner ng mga problema: paulit-ulit na palya (pula), naipit sa pila (pula),
 * palya sa karamihan ng page (isang linya), at palya na walang fallback (orange). Ngayon: 2026-10-27 12:00.
 */
class AiProblemsTest extends ChatblastTestCase
{
    private const EMPTY = 'OpenAI gave an empty answer.';
    private const NO_ANSWER = 'OpenAI did not answer.';
    private const PAGES = ['Page Uno' => '1001', 'Page Dos' => '1002', 'Page Tres' => '1003'];

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_draft_to_chatbot_flows.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_28_100000_create_chatbot_ai_requests_table.php',
            'database/migrations/2026_10_30_098000_add_raw_to_chatbot_replies.php',
            'database/migrations/2026_10_30_100000_add_results_to_chatbot_replies.php',
            'database/migrations/2026_11_06_100000_add_chat_index_to_chatbot_replies.php',
            'database/migrations/2026_11_10_100000_add_provider_record_to_chatbot_ai_requests.php',
        ]]);
        $this->travelTo('2026-10-27 12:00:00');
        foreach (self::PAGES as $name => $id) {
            $this->page($name, $id);
            DB::table('macro_output')->insert(['PAGE' => $name, 'ts_date' => '2026-10-27']);
        }
        // Flow 1: Start → AI (n2); ang failed port ay hindi naka-wire. Flow 2: pareho, pero naka-wire ang failed sa n3.
        $this->flow(1, false, '2026-10-01 00:00:00');
        $this->flow(2, true, '2026-10-01 00:00:00');
    }

    private static function graph(bool $wired): string
    {
        $nodes = [['id' => 'n1', 'type' => 'start', 'config' => []], ['id' => 'n2', 'type' => 'ai', 'config' => []], ['id' => 'n3', 'type' => 'send', 'config' => []]];
        $edges = [['from' => 'n1', 'port' => 'next', 'to' => 'n2'], ['from' => 'n2', 'port' => 'success', 'to' => 'n3']];
        if ($wired) $edges[] = ['from' => 'n2', 'port' => 'failed', 'to' => 'n3'];

        return json_encode(['nodes' => $nodes, 'edges' => $edges]);
    }

    private function flow(int $id, bool $wired, ?string $publishedAt): void
    {
        DB::table('chatbot_flows')->updateOrInsert(['id' => $id], ['name' => 'Flow ' . $id, 'graph' => self::graph($wired), 'published_at' => $publishedAt,
            'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00']);
    }

    private function version(int $flowId, bool $wired, string $at): void
    {
        DB::table('chatbot_flow_versions')->insert(['flow_id' => $flowId, 'graph' => self::graph($wired), 'created_at' => $at]);
    }

    /** Isang AI request; $error null = may sagot. */
    private function ai(string $pageId, string $at, ?string $error = null, string $status = 'resumed', int $psid = 0, int $flowId = 1): void
    {
        static $n = 0;
        $n++;
        DB::table(ChatbotAiQueue::TABLE)->insert(['request_id' => (string) Str::uuid(), 'page_id' => (int) $pageId, 'psid' => $psid ?: 7000 + $n, 'chat_id' => null,
            'flow_id' => $flowId, 'node_id' => 'n2', 'page_name' => 'x', 'model' => 'gpt-4o-mini', 'messages' => '[]', 'max_tokens' => 100, 'context' => '{}',
            'status' => $status, 'deadline_at' => $at, 'answer' => $error === null && $status === 'resumed' ? 'Hello po' : null, 'error' => $error,
            'answered_at' => in_array($status, ['pending', 'sending'], true) ? null : $at, 'created_at' => $at, 'updated_at' => $at]);
    }

    /** Ang mga linya ng banner, bawat isa: page, kulay, text, mga link. */
    private function lines(string $url = '/chatbot/inbox'): array
    {
        $html = $this->get($url)->assertOk()->getContent();
        if (!preg_match('#<div[^>]*data-alert="problems"[^>]*>(.*?)</ol>#s', $html, $m)) return [];
        $clean = fn ($s) => trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($s))));
        $body = (string) preg_replace('#<span data-ack-(controls|back)[^>]*>.*?</span>#s', '', $m[1]);
        preg_match_all('#<li data-page="([^"]*)" data-level="(\w+)"[^>]*>(.*?)</ul>\s*</li>#s', $body, $rows, PREG_SET_ORDER);
        $out = [];
        foreach ($rows as [, $page, , $inner]) {
            preg_match_all('#<li data-level="(\w+)"[^>]*>(.*?)</li>#s', $inner, $ls, PREG_SET_ORDER);
            foreach ($ls as $l) {
                preg_match_all('#href="([^"]+)"#', $l[2], $h);
                $out[] = ['page' => html_entity_decode($page), 'level' => $l[1], 'text' => $clean($l[2]), 'links' => array_map('html_entity_decode', $h[1])];
            }
        }

        return $out;
    }

    /** Ang mga linya tungkol sa AI lang. */
    private function aiLines(string $url = '/chatbot/inbox'): array
    {
        return array_values(array_filter($this->lines($url), fn ($l) => str_contains($l['text'], 'AI ')));
    }

    private function kindOf(string $page): array
    {
        $html = $this->get('/chatbot/inbox')->getContent();
        preg_match('#data-status="ack" data-page="' . preg_quote($page, '#') . '" data-kind="([0-9a-f]{12})" data-level="(\w+)"#', $html, $m);

        return [$m[1] ?? null, $m[2] ?? null];
    }

    public function test_S_231_1_1_three_failures_in_15_min_make_one_red_line_with_count_error_and_links(): void
    {
        $this->actingAs($this->user());
        $this->ai('1001', '2026-10-27 11:50:00', self::EMPTY, psid: 7101);
        $this->ai('1001', '2026-10-27 11:52:00', self::NO_ANSWER, psid: 7102);
        $this->ai('1001', '2026-10-27 11:58:00', self::EMPTY, psid: 7103);
        foreach (range(1, 6) as $i) $this->ai('1001', '2026-10-27 11:5' . $i . ':30');

        $this->assertSame([['page' => 'Page Uno', 'level' => 'red',
            'text' => '🔴 AI not answering: 3 of 9 AI replies failed in 15 min (OpenAI gave an empty answer) Chat 1 Chat 2 Chat 3 Flow',
            'links' => ['/chatbot/inbox?page_id=1001&psid=7103', '/chatbot/inbox?page_id=1001&psid=7102', '/chatbot/inbox?page_id=1001&psid=7101', '/chatbot/flows/1']]],
            $this->aiLines());
    }

    public function test_S_231_1_2_two_failures_make_no_red_line(): void
    {
        $this->actingAs($this->user());
        // Naka-wire ang failed port (flow 2): walang orange din, kaya walang linya ng AI.
        $this->ai('1001', '2026-10-27 11:50:00', self::EMPTY, flowId: 2);
        $this->ai('1001', '2026-10-27 11:55:00', self::EMPTY, flowId: 2);

        $this->assertSame([], $this->aiLines());
    }

    public function test_S_231_1_3_three_failures_over_20_min_with_two_in_the_window_make_no_red_line(): void
    {
        $this->actingAs($this->user());
        foreach (['11:40:00', '11:50:00', '11:55:00'] as $t) $this->ai('1001', '2026-10-27 ' . $t, self::EMPTY, flowId: 2);

        $this->assertSame([], $this->aiLines());
    }

    public function test_S_231_1_4_failures_on_two_pages_make_one_line_each(): void
    {
        $this->actingAs($this->user());
        foreach (['11:50:00', '11:52:00', '11:54:00'] as $t) $this->ai('1001', '2026-10-27 ' . $t, self::EMPTY);
        foreach (['11:51:00', '11:53:00', '11:55:00', '11:56:00'] as $t) $this->ai('1002', '2026-10-27 ' . $t, self::NO_ANSWER);

        $lines = $this->aiLines();
        $this->assertSame([['Page Dos', 'red', '🔴 AI not answering: 4 of 4 AI replies failed in 15 min (OpenAI did not answer) Chat 1 Chat 2 Chat 3 Flow'],
            ['Page Uno', 'red', '🔴 AI not answering: 3 of 3 AI replies failed in 15 min (OpenAI gave an empty answer) Chat 1 Chat 2 Chat 3 Flow']],
            array_map(fn ($l) => [$l['page'], $l['level'], $l['text']], $lines));
    }

    public function test_S_231_1_5_empty_and_did_not_answer_count_and_no_ai_load_does_not(): void
    {
        $this->actingAs($this->user());
        $this->ai('1001', '2026-10-27 11:50:00', self::EMPTY);
        $this->ai('1001', '2026-10-27 11:51:00', self::NO_ANSWER);
        $this->ai('1001', '2026-10-27 11:52:00', self::NO_ANSWER);
        // Walang AI load: hindi palya at hindi rin "humingi ng AI" (wala sa "of").
        foreach (['11:53:00', '11:54:00'] as $t) $this->ai('1001', '2026-10-27 ' . $t, ChatbotAiLoad::ERROR, 'sync');
        foreach (['11:50:00', '11:51:00', '11:52:00'] as $t) $this->ai('1002', '2026-10-27 ' . $t, ChatbotAiLoad::ERROR, 'sync');

        $this->assertSame([['Page Uno', '🔴 AI not answering: 3 of 3 AI replies failed in 15 min (OpenAI did not answer) Chat 1 Chat 2 Chat 3 Flow']],
            array_map(fn ($l) => [$l['page'], $l['text']], $this->aiLines()));
    }

    public function test_S_231_2_1_a_request_waiting_over_2_min_is_red_ai_stuck(): void
    {
        $this->actingAs($this->user());
        $this->ai('1002', '2026-10-27 11:57:59', null, 'pending');
        $this->ai('1002', '2026-10-27 11:55:00', null, 'sending');

        $this->assertSame([['page' => 'Page Dos', 'level' => 'red', 'text' => '🔴 AI stuck: 2 replies waiting over 2 min Inbox', 'links' => ['/chatbot/inbox?page_id=1002']]],
            $this->aiLines());
    }

    public function test_S_231_2_2_a_request_waiting_90_seconds_shows_nothing(): void
    {
        $this->actingAs($this->user());
        $this->ai('1002', '2026-10-27 11:58:30', null, 'pending');

        $this->assertSame([], $this->aiLines());
    }

    public function test_S_231_2_3_once_answered_the_stuck_line_is_gone_on_the_next_refresh(): void
    {
        $this->actingAs($this->user());
        $this->ai('1002', '2026-10-27 11:57:00', null, 'sending');
        $this->assertCount(1, $this->aiLines());

        DB::table(ChatbotAiQueue::TABLE)->update(['status' => 'resumed', 'answer' => 'Hello po', 'answered_at' => now()]);
        $this->travel(61)->seconds(); // ang banner ay naka-cache nang hanggang 60 segundo
        $this->assertSame([], $this->aiLines());
    }

    public static function sites(): array
    {
        return [
            'S-231.3.1 failures on 6 of 10 pages: one site line' => [6, 10, true],
            'S-231.3.2 failures on 3 of 10 pages: per-page lines' => [3, 10, false],
            'S-231.3.3 failures on 5 of 9 pages: one site line' => [5, 9, true],
        ];
    }

    #[DataProvider('sites')]
    public function test_S_231_3_site_wide_line_by_pages_failing(int $failing, int $asked, bool $site): void
    {
        $this->actingAs($this->user());
        for ($p = 1; $p <= $asked; $p++) {
            $id = (string) (2000 + $p);
            $this->ai($id, '2026-10-27 11:52:00');
            if ($p <= $failing) foreach (['11:53:00', '11:55:00', '11:57:00'] as $t) $this->ai($id, '2026-10-27 ' . $t, self::EMPTY);
        }
        // Isang naipit sa ibang page: nananatili kahit may site line.
        $this->ai('1003', '2026-10-27 11:45:00', null, 'pending'); // labas sa 10 min ng site: hindi kasama sa "of"

        $texts = array_map(fn ($l) => [$l['page'], $l['text']], $this->aiLines());
        $this->assertContains(['Page Tres', '🔴 AI stuck: 1 reply waiting over 2 min Inbox'], $texts);
        $siteLine = ['All pages', '🔴 AI is failing on ' . $failing . ' of ' . $asked . ' pages: OpenAI or the key may be down Health'];
        $perPage = array_filter($texts, fn ($t) => str_contains($t[1], 'AI not answering'));
        if ($site) {
            $this->assertContains($siteLine, $texts);
            $this->assertSame([], $perPage, 'no per-page failure lines under the site line');
            // Ang site line ay may sariling Acknowledge, gaya ng linya ng isang page.
            [$kind, $level] = $this->kindOf('All pages');
            $this->postJson('/chatbot/problems/ack', ['page' => 'All pages', 'kind' => $kind, 'status' => 'ack', 'note' => 'OpenAI status page red', 'level' => $level])->assertOk();
            Cache::flush();
            $this->assertNotContains('All pages', array_column($this->aiLines(), 'page'));
        } else {
            $this->assertNotContains($siteLine[0], array_column($texts, 0));
            $this->assertCount($failing, $perPage);
        }
    }

    public function test_S_231_4_1_one_failure_with_the_failed_port_not_wired_is_orange(): void
    {
        $this->actingAs($this->user());
        $this->ai('1001', '2026-10-27 11:55:00', self::EMPTY, psid: 7201);
        $this->ai('1001', '2026-10-27 11:56:00');

        $this->assertSame([['page' => 'Page Uno', 'level' => 'orange',
            'text' => '🟠 AI failed with no fallback: 1 customer got no reply in 15 min (OpenAI gave an empty answer) Chat 1 Flow',
            'links' => ['/chatbot/inbox?page_id=1001&psid=7201', '/chatbot/flows/1']]], $this->aiLines());
    }

    public function test_S_231_4_2_one_failure_with_the_failed_port_wired_shows_nothing(): void
    {
        $this->actingAs($this->user());
        $this->ai('1001', '2026-10-27 11:55:00', self::EMPTY, flowId: 2);

        $this->assertSame([], $this->aiLines());
    }

    public function test_S_231_4_3_the_check_uses_the_flow_version_that_ran(): void
    {
        $this->actingAs($this->user());
        // Flow 1: hindi naka-wire sa version na tumakbo (11:00); naka-wire na sa bagong Publish (11:58) at sa live graph.
        $this->version(1, false, '2026-10-27 11:00:00');
        $this->version(1, true, '2026-10-27 11:58:00');
        $this->flow(1, true, '2026-10-27 11:58:00');
        $this->ai('1001', '2026-10-27 11:55:00', self::EMPTY);
        // Flow 2: kabaligtaran, naka-wire noong tumakbo: walang linya.
        $this->version(2, true, '2026-10-27 11:00:00');
        $this->version(2, false, '2026-10-27 11:58:00');
        $this->flow(2, false, '2026-10-27 11:58:00');
        $this->ai('1002', '2026-10-27 11:55:00', self::EMPTY, flowId: 2);
        // Flow 3: walang version, at ang live graph (naka-wire) ay na-publish pagkatapos ng takbo: hindi alam = hindi naka-wire.
        $this->flow(3, true, '2026-10-27 11:59:00');
        $this->ai('1003', '2026-10-27 11:55:00', self::EMPTY, flowId: 3);
        // Flow 4: walang version, ang live graph (naka-wire) ay na-publish bago ang takbo: iyon ang tumakbo.
        $this->flow(4, true, '2026-10-01 00:00:00');
        $this->ai('1003', '2026-10-27 11:56:00', self::NO_ANSWER, flowId: 4);

        $this->assertSame([['Page Tres', 'orange', '🟠 AI failed with no fallback: 1 customer got no reply in 15 min (OpenAI gave an empty answer) Chat 1 Flow'],
            ['Page Uno', 'orange', '🟠 AI failed with no fallback: 1 customer got no reply in 15 min (OpenAI gave an empty answer) Chat 1 Flow']],
            array_map(fn ($l) => [$l['page'], $l['level'], $l['text']], $this->aiLines()));
    }

    public function test_S_231_5_1_acknowledge_for_an_hour_hides_the_ai_line_and_it_comes_back_after(): void
    {
        $this->actingAs($this->user());
        foreach (['11:50:00', '11:52:00', '11:54:00'] as $t) $this->ai('1001', '2026-10-27 ' . $t, self::EMPTY);
        [$kind, $level] = $this->kindOf('Page Uno');
        $this->assertSame('red', $level);
        $this->postJson('/chatbot/problems/ack', ['page' => 'Page Uno', 'kind' => $kind, 'status' => 'ack', 'note' => 'OpenAI checking', 'minutes' => 60, 'level' => $level])->assertOk();
        Cache::flush();
        $this->assertSame([], $this->aiLines());

        // 13:01: nagpapatuloy ang palya; tapos na ang ack.
        $this->travelTo('2026-10-27 13:01:00');
        foreach (['12:50:00', '12:55:00', '13:00:00'] as $t) $this->ai('1001', '2026-10-27 ' . $t, self::EMPTY);
        Cache::flush();
        $this->assertCount(1, $this->aiLines());
        $this->assertStringContainsString('the acknowledgement ended', $this->get('/chatbot/inbox')->getContent());
    }

    public function test_S_231_5_2_done_hides_the_ai_line_until_a_new_failure(): void
    {
        $this->actingAs($this->user());
        foreach (['11:50:00', '11:52:00', '11:54:00'] as $t) $this->ai('1001', '2026-10-27 ' . $t, self::EMPTY);
        [$kind, $level] = $this->kindOf('Page Uno');
        $this->postJson('/chatbot/problems/ack', ['page' => 'Page Uno', 'kind' => $kind, 'status' => 'done', 'note' => 'key replaced', 'level' => $level])->assertOk();
        Cache::flush();
        $this->assertSame([], $this->aiLines());

        // 12:16: walang bagong palya → wala pa rin.
        $this->travelTo('2026-10-27 12:16:00');
        Cache::flush();
        $this->assertSame([], $this->aiLines());

        // Mga bagong palya pagkatapos ng Done: bumabalik.
        foreach (['12:10:00', '12:12:00', '12:15:00'] as $t) $this->ai('1001', '2026-10-27 ' . $t, self::EMPTY);
        Cache::flush();
        $this->assertCount(1, $this->aiLines());
        $this->assertStringContainsString('new since it was marked done', $this->get('/chatbot/inbox')->getContent());
    }

    public function test_S_231_5_3_a_database_error_in_the_ai_check_leaves_the_rest_of_the_banner(): void
    {
        $this->actingAs($this->user());
        app(PageSettings::class)->set('Page Dos', 'botcake', ChatblastSetupCheck::CHECK_KEY, json_encode(['at' => '2026-10-27 11:00:00', 'ok' => false, 'x' => ['No flow named X']]), false, null, true);
        // Ang table ay nandiyan pero sira (walang mga column): bawat query ng AI check ay pumapalya.
        Schema::drop(ChatbotAiQueue::TABLE);
        Schema::create(ChatbotAiQueue::TABLE, fn ($t) => $t->id());

        $lines = $this->lines('/chatblast/page-settings');
        $this->assertSame([['Page Dos', '🟠 No shipping status setup: No flow named X Page settings']], array_map(fn ($l) => [$l['page'], $l['text']], $lines));
    }

    public function test_S_231_5_4_the_ai_lines_wrap_in_the_banner_list(): void
    {
        $this->actingAs($this->user());
        foreach (['11:50:00', '11:52:00', '11:54:00'] as $t) $this->ai('1001', '2026-10-27 ' . $t, self::EMPTY);
        $html = $this->get('/chatbot/inbox')->getContent();
        $this->assertMatchesRegularExpression('#<ol id="cb-problems-list" class="[^"]*\bbreak-words\b[^"]*"#', $html, 'long words break instead of scrolling sideways');
        preg_match('#<li data-level="red"[^>]*>[^<]*AI not answering[^<]*#', $html, $m);
        $this->assertNotEmpty($m, 'the AI line is in the list');
        $this->assertDoesNotMatchRegularExpression('#\b(whitespace-nowrap|truncate|w-\[\d+px\])\b#', $m[0]);
    }
}

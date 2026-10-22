<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeMessage;
use App\Services\ChatblastNeedsSetup;

/**
 * Ang "Needs setup" (spec 084): ang mga hindi naipadalang chatblast ng huling 3 araw dahil sa setup ng page (no_config,
 * failed, field_mismatch), ayon sa page at sa dahilan sa simpleng English, sa /chatblast at /chatblast/page-settings at
 * sa needs-setup.json.
 */
class ChatblastNeedsSetupTest extends ChatblastTestCase
{
    private int $n = 0;

    private function row(string $page, string $status, ?string $reason, $at = null, bool $test = false): BotcakeMessage
    {
        $m = BotcakeMessage::create(['page_name' => $page, 'waybill' => 'JT' . (++$this->n), 'kind' => 'in_transit', 'status' => $status, 'reason' => $reason, 'is_test' => $test]);
        if ($at) BotcakeMessage::whereKey($m->id)->update(['created_at' => $at, 'updated_at' => $at]);

        return $m;
    }

    private function list(): array
    {
        return app(ChatblastNeedsSetup::class)->list();
    }

    public function test_S_084_1_rows_are_grouped_by_page_and_plain_english_reason_including_the_old_tagalog_ones(): void
    {
        $this->travelTo('2026-10-20 12:00:00');
        $this->row('Xivana Agustin', 'no_config', 'missing flow.shipping, field.message', now()->subHours(5));
        $this->row('XIVANA AGUSTIN ', 'no_config', 'walang flow.shipping, field.message', now()->subHours(2));
        $this->row('Xivana Agustin', 'no_config', 'missing field.message', now()->subHour());
        $this->row('New Page', 'no_config', 'missing page_id, token, flow.shipping, field.message');
        $this->row('New Page', 'no_config', 'walang token');
        $this->row('Karla Chavez', 'no_config', 'Page not ready: needs a test send');
        $this->row('Karla Chavez', 'failed', 'Token invalid: fix it on /page-settings (Test token) before sending');
        $this->row('Karla Chavez', 'field_mismatch', 'The field holds a different text in the read-back');
        $this->row('Kim Villanueva', 'no_config', 'Page not ready: No flow named UPDATE SHIPPING STATUS 2026 in Botcake');
        $this->row('Kim Villanueva', 'no_config', 'Page not ready: Sent 3, none delivered (Botcake)');
        $this->row('Alyssa Aquino', 'no_config', 'walang {rider_name}');
        $this->row('Alyssa Aquino', 'no_config', 'missing rider info');
        $this->row('Other', 'no_psid', 'No PSID'); // a matching issue, not setup
        $this->row('Other', 'no_config', 'missing token', null, true); // a test row
        $this->row('Other', 'sent', null);

        $rows = array_map(fn ($r) => [$r['page'], $r['group'], $r['count']], $this->list());

        $this->assertEqualsCanonicalizing([
            ['Xivana Agustin', 'Missing flow or message field', 3],
            ['New Page', 'Not set up in page-settings', 2],
            ['Karla Chavez', 'Needs a test send', 1],
            ['Karla Chavez', 'Send failed', 2],
            ['Kim Villanueva', 'No flow named UPDATE SHIPPING STATUS 2026 in Botcake', 1],
            ['Kim Villanueva', 'Sent 3, none delivered (Botcake)', 1],
            ['Alyssa Aquino', 'Message details missing (order data)', 2],
        ], $rows);
        $this->assertSame('Xivana Agustin', $this->list()[0]['page'], 'the biggest first');
        $this->assertSame('2026-10-20 11:00', collect($this->list())->firstWhere('page', 'Xivana Agustin')['latest']);
    }

    public function test_S_084_2_only_the_last_3_days_count_and_a_row_that_was_sent_drops_off(): void
    {
        $this->travelTo('2026-10-20 12:00:00');
        $this->row('Old Page', 'no_config', 'missing flow.shipping', now()->subDays(3)->subMinute());
        $recent = $this->row('Recent Page', 'no_config', 'missing flow.shipping', now()->subDays(3)->addMinute());
        $this->assertSame(['Recent Page'], array_column($this->list(), 'page'));

        $recent->update(['status' => 'sent']);
        $this->assertSame(['Recent Page'], array_column($this->list(), 'page'), 'cached for 60 s');
        $this->travel(61)->seconds();
        $this->assertSame([], $this->list());
    }

    public function test_S_084_3_the_panel_shows_on_both_pages_and_is_hidden_when_nothing_is_blocked(): void
    {
        $this->actingAs($this->user());
        foreach (['/chatblast', '/chatblast/page-settings'] as $url) $this->get($url)->assertOk()->assertDontSee('Needs setup (', false);

        $this->row('Karla Chavez', 'no_config', 'Page not ready: needs a test send');
        $this->travel(61)->seconds();
        foreach (['/chatblast', '/chatblast/page-settings'] as $url) {
            $this->get($url)->assertOk()->assertSee('Needs setup (1 page)')->assertSee('Karla Chavez')->assertSee('Needs a test send')
                ->assertSee(route('page-settings.index', ['q' => 'Karla Chavez']), false);
        }
    }

    public function test_S_084_4_the_json_has_the_same_list_behind_the_same_access(): void
    {
        $this->travelTo('2026-10-20 12:00:00');
        $this->row('Karla Chavez', 'no_config', 'Page not ready: needs a test send', now()->subMinutes(30));
        $this->actingAs($this->user());

        $this->getJson('/chatblast/needs-setup.json')->assertOk()->assertExactJson([
            'window_days' => 3, 'pages' => 1,
            'rows' => [['page' => 'Karla Chavez', 'group' => 'Needs a test send', 'count' => 1, 'latest' => '2026-10-20 11:30']], 'unreachable' => [],
        ]);

        $this->actingAs($this->user('Data Encoder', 'enc@example.test'));
        $this->getJson('/chatblast/needs-setup.json')->assertNotFound();
    }

    private function failedFlow(string $page, string $response, $at = null): BotcakeMessage
    {
        $m = $this->row($page, 'failed', 'The flow was not sent (HTTP 200)', $at);
        $m->update(['steps' => [['step' => 'write', 'http' => 200, 'response' => '{"success":true}'], ['step' => 'flow', 'http' => 200, 'response' => $response]]]);

        return $m;
    }

    private function sent(string $page, int $times, $at = null): void
    {
        for ($i = 0; $i < $times; $i++) $this->row($page, 'sent', null, $at);
    }

    private const E551 = '{"success":false,"message":"(#551) This person isn\'t available right now."}';

    public function test_S_110_1_a_page_with_only_551_failures_is_unreachable_info_not_needs_setup(): void
    {
        $this->travelTo('2026-10-20 12:00:00');
        $this->sent('Kylene Dellamas', 50, now()->subHours(2));
        for ($i = 0; $i < 7; $i++) $this->failedFlow('Kylene Dellamas', self::E551, now()->subHours(2));

        $this->assertSame([], $this->list());
        $this->assertSame([['page' => 'Kylene Dellamas', 'count' => 7]], app(ChatblastNeedsSetup::class)->unreachable());
    }

    public function test_S_110_2_error_100_no_matching_user_counts_as_unreachable(): void
    {
        $this->travelTo('2026-10-20 12:00:00');
        $this->failedFlow('Grace Bernardo', '{"success":false,"message":"(#100) No matching user found"}', now()->subHours(2));

        $this->assertSame([], $this->list());
        $this->assertSame(1, app(ChatblastNeedsSetup::class)->unreachable()[0]['count']);
    }

    public function test_S_110_3_a_page_missing_its_flow_stays_in_needs_setup(): void
    {
        $this->travelTo('2026-10-20 12:00:00');
        $this->row('Beatrice Dizon', 'no_config', 'missing flow.shipping, field.message', now()->subHours(2));

        $this->assertSame([['Beatrice Dizon', 'Missing flow or message field', 1]], array_map(fn ($r) => [$r['page'], $r['group'], $r['count']], $this->list()));
    }

    public function test_S_110_4_ten_percent_of_attempts_failing_for_setup_reasons_lists_the_page_below_that_it_does_not(): void
    {
        $this->travelTo('2026-10-20 12:00:00');
        $this->sent('Flaky Page', 9, now()->subHours(5));
        $this->failedFlow('Flaky Page', '{"success":false,"message":"Something else"}', now()->subHours(5));
        $this->sent('Fine Page', 10, now()->subHours(5));
        $this->failedFlow('Fine Page', '{"success":false,"message":"Something else"}', now()->subHours(5));

        $this->assertSame([['Flaky Page', 'Send failed', 1]], array_map(fn ($r) => [$r['page'], $r['group'], $r['count']], $this->list()));
    }

    public function test_S_110_5_a_page_whose_last_hour_attempts_all_failed_is_listed_even_below_ten_percent(): void
    {
        $this->travelTo('2026-10-20 12:00:00');
        $this->sent('Down Page', 100, now()->subHours(5));
        $this->failedFlow('Down Page', '{"success":false,"message":"Something else"}', now()->subMinutes(10));

        $this->assertSame(['Down Page'], array_column($this->list(), 'page'));
    }

    public function test_S_110_6_title_counts_only_pages_that_need_setup_and_the_box_hides_when_none(): void
    {
        $this->travelTo('2026-10-20 12:00:00');
        $this->actingAs($this->user());
        $this->sent('Kylene Dellamas', 50, now()->subHours(5));
        $this->failedFlow('Kylene Dellamas', self::E551, now()->subHours(5));
        foreach (['/chatblast', '/chatblast/page-settings'] as $url) {
            $this->get($url)->assertOk()->assertDontSee('Needs setup (', false)->assertSee('Hindi na maabot ang customer')->assertSee('Kylene Dellamas 1');
        }

        $this->row('Beatrice Dizon', 'no_config', 'missing flow.shipping');
        $this->row('Tracy Velasquez', 'no_config', 'missing field.message');
        $this->travel(61)->seconds();
        $this->get('/chatblast')->assertSee('Needs setup (2 pages)')->assertSee('Kylene Dellamas 1');
    }
}

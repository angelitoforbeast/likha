<?php

namespace Tests\Feature\Chatblast;

use App\Jobs\SendChatblastMessage;
use App\Models\BotcakeMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * "To send" (/chatblast) at "History" (/chatblast/history): ang ipapadala pa (ready, queued, sending) anuman ang petsa,
 * at ang naproseso na (sent, failed, skipped, walang PSID o config) na may From/To para sa pagtingin lang.
 */
class ChatblastToSendTest extends ChatblastTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->user());
        $this->travelTo('2026-10-18 10:00:00');
    }

    private function msg(string $status, array $extra = []): int
    {
        return DB::table('botcake_messages')->insertGetId($extra + [
            'page_name' => 'Page Uno', 'waybill' => 'WB-' . uniqid(), 'kind' => 'delivering', 'fb_name' => 'Buyer', 'psid' => 'psid-123456',
            'text' => 'Hello Buyer', 'status' => $status, 'attempts' => 0, 'is_test' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function list(array $query)
    {
        return $this->getJson('/chatblast/list?' . http_build_query($query))->assertOk();
    }

    public function test_S_056_16_send_all_ready_sends_every_ready_row_of_the_page_and_sequence_whatever_its_date(): void
    {
        Queue::fake([SendChatblastMessage::class]);
        $today = $this->msg('ready');
        $old = $this->msg('ready', ['created_at' => now()->subDays(3), 'fb_name' => 'Old Buyer']);
        $otherPage = $this->msg('ready', ['page_name' => 'Page Dos']);
        $otherKind = $this->msg('ready', ['kind' => 'in_transit']);
        $this->msg('sent', ['sent_at' => now()]);

        $day = now('Asia/Manila')->subDay()->toDateString();
        $this->postJson('/chatblast/send-filtered', ['page' => 'Page Uno', 'kind' => 'delivering', 'from' => $day, 'to' => $day, 'q' => 'Old'])
            ->assertOk()->assertJsonPath('queued', 2);

        $status = fn ($id) => BotcakeMessage::find($id)->status;
        $this->assertSame(['queued', 'queued', 'ready', 'ready'], [$status($today), $status($old), $status($otherPage), $status($otherKind)]);
    }

    public function test_S_056_17_to_send_lists_what_is_still_to_send_and_history_what_was_processed(): void
    {
        $ready = $this->msg('ready');
        $oldReady = $this->msg('ready', ['created_at' => now()->subDays(5), 'page_name' => 'Page Dos', 'kind' => 'in_transit']);
        $queued = $this->msg('queued', ['created_at' => now()->subDay()]);
        $sent = $this->msg('sent', ['sent_at' => now()]);
        $failed = $this->msg('failed');
        $skipped = $this->msg('skipped', ['reason' => 'already sent today by another sequence']);
        $noPsid = $this->msg('no_psid', ['psid' => null]);
        $oldSent = $this->msg('sent', ['created_at' => now()->subDays(5), 'sent_at' => now()->subDays(5)]);
        $this->msg('ready', ['is_test' => true]);

        $send = $this->list(['view' => 'send']);
        $this->assertEqualsCanonicalizing([$ready, $oldReady, $queued], array_column($send->json('rows'), 'id'));
        $this->assertSame(['queued' => 1, 'ready' => 2], $send->json('counts'));
        $this->assertSame(['delivering' => 1, 'in_transit' => 1], $send->json('by_kind'));
        $this->assertSame(['Page Dos' => 1, 'Page Uno' => 1], $send->json('by_page'));
        $this->assertSame(2, $send->json('sendable'));
        $this->assertSame([$oldReady], array_column($this->list(['view' => 'send', 'page' => 'Page Dos'])->json('rows'), 'id'));

        $today = now('Asia/Manila')->toDateString();
        $history = $this->list(['view' => 'history', 'from' => $today, 'to' => $today]);
        $this->assertSame([$sent], array_column($history->json('rows'), 'id'), 'default: ang naipadala lang');
        $this->assertSame(['failed' => 1, 'no_psid' => 1, 'sent' => 1, 'skipped' => 1], $history->json('counts'));
        $this->assertSame(1, $history->json('total'));
        $this->assertSame([$failed], array_column($this->list(['view' => 'history', 'from' => $today, 'to' => $today, 'status' => 'failed'])->json('rows'), 'id'));
        $this->assertSame([$noPsid], array_column($this->list(['view' => 'history', 'from' => $today, 'to' => $today, 'status' => 'no_psid'])->json('rows'), 'id'));
        $this->assertSame([[$skipped, 'already sent today by another sequence']],
            array_map(fn ($r) => [$r['id'], $r['reason']], $this->list(['view' => 'history', 'from' => $today, 'to' => $today, 'status' => 'skipped'])->json('rows')));
        $from = now('Asia/Manila')->subDays(7)->toDateString();
        $this->assertContains($oldSent, array_column($this->list(['view' => 'history', 'from' => $from, 'to' => $today])->json('rows'), 'id'));
    }

    public function test_S_056_20_history_dates_are_when_the_row_was_processed_not_when_it_was_built(): void
    {
        $sentToday = $this->msg('sent', ['created_at' => now()->subDays(3), 'sent_at' => now()]);          // backlog, ipinadala ngayon
        $failedToday = $this->msg('failed', ['created_at' => now()->subDays(3), 'updated_at' => now()]);   // backlog, pumalya ngayon
        $this->msg('sent', ['created_at' => now(), 'sent_at' => now()->subDays(2), 'updated_at' => now()]); // hindi ngayon naipadala

        $today = now('Asia/Manila')->toDateString();
        $this->assertSame([$sentToday], array_column($this->list(['view' => 'history', 'from' => $today, 'to' => $today])->json('rows'), 'id'));
        $this->assertSame([$failedToday], array_column($this->list(['view' => 'history', 'from' => $today, 'to' => $today, 'status' => 'failed'])->json('rows'), 'id'));

        Queue::fake([SendChatblastMessage::class]);
        $this->postJson('/chatblast/retry-failed', ['from' => $today, 'to' => $today])->assertOk()->assertJsonPath('queued', 1);
    }

    public function test_S_056_28_to_send_shows_when_it_was_updated_and_offers_refresh_now_instead_of_fetch_new(): void
    {
        $this->assertSame(['updated' => null, 'next' => '10:15'], $this->list(['view' => 'send'])->json('state.refresh'));
        app(\App\Services\Chatblast::class)->build(); // isang buong pagkuha (lahat ng sequence)
        $this->assertSame(['updated' => '10:00', 'next' => '10:15'], $this->list(['view' => 'send'])->json('state.refresh'));
        $this->travelTo('2026-10-19 07:00:00');
        $this->assertSame('Oct 18 10:00', $this->list(['view' => 'send'])->json('state.refresh.updated'), 'hindi ngayong araw: may petsa');
        $this->travelTo('2026-10-18 10:00:00');

        $html = $this->get('/chatblast')->assertOk()->getContent();
        $this->assertStringContainsString('>Refresh now</button>', $html);
        $this->assertStringNotContainsString('Fetch new', $html);
        $this->assertStringContainsString("'Updated '", $html);
        $this->assertStringContainsString("' · next refresh '", $html);
    }

    public function test_S_056_29_the_panel_shows_the_j_and_t_status_at_build_and_now(): void
    {
        $id = $this->msg('ready', ['waybill' => 'WB-77', 'jnt_status' => 'Delivering']);
        DB::table('from_jnts')->insert(['waybill_number' => 'WB-77', 'status' => 'Delivered']);

        $panel = $this->getJson('/chatblast/messages/' . $id)->assertOk();
        $this->assertSame(['Delivering', 'Delivered'], [$panel->json('jnt_status'), $panel->json('jnt_now')]);

        $html = $this->get('/chatblast')->getContent();
        $this->assertStringContainsString("kv('J&T at build'", $html);
        $this->assertStringContainsString("kv('J&T now'", $html);
    }

    public function test_S_056_35_history_shows_only_what_was_sent_by_default_and_each_other_state_on_its_chip(): void
    {
        $sent = $this->msg('sent', ['sent_at' => now()]);
        $unsure = $this->msg('unknown');
        $failed = $this->msg('failed');
        $mismatch = $this->msg('field_mismatch');
        $skipped = $this->msg('skipped');
        $noPsid = $this->msg('no_psid', ['psid' => null]);
        $noConfig = $this->msg('no_config');
        $ids = fn (array $q) => array_column($this->list($q + ['view' => 'history'])->json('rows'), 'id');

        $res = $this->list(['view' => 'history']);
        $this->assertEqualsCanonicalizing([$sent, $unsure], array_column($res->json('rows'), 'id'), 'default: ang naipadala lang');
        $this->assertSame(2, $res->json('total'));
        $this->assertSame(['failed' => 1, 'field_mismatch' => 1, 'no_config' => 1, 'no_psid' => 1, 'sent' => 1, 'skipped' => 1, 'unknown' => 1], $res->json('counts'), 'ang bilang ng bawat chip');

        $this->assertEqualsCanonicalizing([$failed, $mismatch], $ids(['status' => 'failed']));
        $this->assertSame([$skipped], $ids(['status' => 'skipped']));
        $this->assertSame([$noPsid], $ids(['status' => 'no_psid']));
        $this->assertSame([$noConfig], $ids(['status' => 'no_config']));
        $this->assertEqualsCanonicalizing([$sent, $unsure], $ids(['status' => 'sent']));
    }

    public function test_S_056_36_the_history_page_has_sent_first_no_all_chip_and_retry_only_on_failed(): void
    {
        $html = $this->get('/chatblast/history')->assertOk()->getContent();

        $this->assertStringContainsString("[['sent', 'Sent', ['sent', 'unknown']]", $html, 'Sent ang una, walang "all"');
        $this->assertStringContainsString("['failed', 'Failed', ['failed', 'field_mismatch']]", $html);
        $this->assertStringContainsString("['no_config', 'No config', ['no_config']]", $html);
        $this->assertStringContainsString("unknown: 'Sent (unconfirmed)'", $html);
        $this->assertStringContainsString('Nothing sent in this range yet.', $html);
        $this->assertStringContainsString("\$id('retry')?.classList.toggle('hidden', current !== 'failed')", $html);
        $this->assertStringContainsString('<input type="hidden" name="status" value="sent">', $html);
        $this->assertStringContainsString('<input type="hidden" name="status" value="">', $this->get('/chatblast')->getContent(), 'To send: walang default na status');
    }

    public function test_S_056_21_send_all_ready_counts_only_sequences_that_are_on(): void
    {
        $this->msg('ready');
        $this->msg('ready', ['kind' => 'in_transit']);
        \App\Models\ChatblastCampaign::where('kind', 'in_transit')->update(['active' => false]);

        $this->assertSame(1, $this->list(['view' => 'send'])->json('sendable'));
    }

    public function test_S_056_18_the_nav_and_the_two_pages_have_their_own_tools(): void
    {
        $send = $this->get('/chatblast')->assertOk()->getContent();
        preg_match_all('#data-cb-nav="([^"]+)"#', $send, $m);
        $this->assertSame(['To send', 'History', 'Messages', 'Sequences', 'Chats', 'Page settings'], $m[1]);
        $this->assertStringContainsString('data-cb-nav="To send" aria-current="page"', preg_replace('/\s+/', ' ', $send));
        foreach (['id="build"', 'id="send-selected"', 'id="send-filtered"', 'id="pause"', 'id="resume"', 'id="by-kind"'] as $tool) $this->assertStringContainsString($tool, $send);
        foreach (['name="from"', 'id="retry"'] as $tool) $this->assertStringNotContainsString($tool, $send);
        $this->assertStringContainsString('Fetching new messages in the background', $send);

        $history = $this->get('/chatblast/history')->assertOk()->getContent();
        $this->assertStringContainsString('data-cb-nav="History" aria-current="page"', preg_replace('/\s+/', ' ', $history));
        foreach (['name="from"', 'name="to"', 'id="retry"'] as $tool) $this->assertStringContainsString($tool, $history);
        foreach (['id="build"', 'id="send-filtered"', 'id="send-selected"'] as $tool) $this->assertStringNotContainsString($tool, $history);
    }
}

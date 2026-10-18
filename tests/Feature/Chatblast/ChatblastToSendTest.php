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
        $this->assertEqualsCanonicalizing([$sent, $failed, $skipped, $noPsid], array_column($history->json('rows'), 'id'));
        $this->assertSame(['failed' => 1, 'no_psid' => 1, 'sent' => 1, 'skipped' => 1], $history->json('counts'));
        $this->assertSame(4, $history->json('total'));
        $this->assertSame([[$skipped, 'already sent today by another sequence']],
            array_map(fn ($r) => [$r['id'], $r['reason']], $this->list(['view' => 'history', 'from' => $today, 'to' => $today, 'status' => 'skipped'])->json('rows')));
        $from = now('Asia/Manila')->subDays(7)->toDateString();
        $this->assertContains($oldSent, array_column($this->list(['view' => 'history', 'from' => $from, 'to' => $today])->json('rows'), 'id'));
    }

    public function test_S_056_18_the_nav_and_the_two_pages_have_their_own_tools(): void
    {
        $send = $this->get('/chatblast')->assertOk()->getContent();
        preg_match_all('#data-cb-nav="([^"]+)"#', $send, $m);
        $this->assertSame(['To send', 'History', 'Messages', 'Sequences', 'Page settings'], $m[1]);
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

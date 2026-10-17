<?php

namespace Tests\Feature\Chatblast;

use App\Jobs\SendChatblastMessage;
use App\Models\BotcakeMessage;
use App\Models\BotcakePsid;
use App\Models\MacroOutput;
use App\Services\Chatblast;
use App\Services\ChatblastSender;
use App\Services\PageSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Para sa totoong customer: isang mensahe lang sabay kada customer, walang kulang na text, at sa kasalukuyang PSID ng
 * order lang ipinapadala.
 */
class ChatblastSafetyTest extends ChatblastTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->page('Page Uno');
        $this->travelTo('2026-10-18 09:00:00');
    }

    private function row(string $waybill, string $kind = 'delivering'): BotcakeMessage
    {
        return BotcakeMessage::where('waybill', $waybill)->where('kind', $kind)->where('is_test', false)->sole();
    }

    private function botcake(): void
    {
        $written = [];
        Http::fake(['botcake.io/*' => function (Request $req) use (&$written) {
            preg_match('#/customer/([^/]+)/#', $req->url(), $m);
            if (str_ends_with($req->url(), '/flows/send_flow')) return Http::response(['success' => true]);
            if ($req->method() === 'POST') { $written[$m[1]] = $req->data()['data'][0]['value']; return Http::response(['success' => true]); }
            return Http::response(['success' => true, 'data' => [['id' => 123, 'custom_field_id' => 7701, 'value' => $written[$m[1]] ?? null]]]);
        }]);
    }

    public function test_one_message_at_a_time_per_customer_and_a_short_gap_after_each(): void
    {
        Queue::fake();
        $this->botcake();
        $this->shipped('Page Uno', 'Ana Cruz', 'WB-001', 'psid-a');
        $this->shipped('Page Uno', 'Ana Cruz', 'WB-002', 'psid-a', 'In Transit');
        app(Chatblast::class)->build();
        $sender = app(ChatblastSender::class);

        // Isa pang job ang may hawak ng customer: babalik mamaya, walang tawag sa Botcake.
        $held = Cache::lock(ChatblastSender::customerLockKey('Page Uno', 'psid-a'), 60);
        $held->get();
        $sender->queue([$this->row('WB-001')->id], 1);
        $sender->process($this->row('WB-001')->id);
        Http::assertNothingSent();
        $this->assertSame('queued', $this->row('WB-001')->status);
        $retry = Queue::pushed(SendChatblastMessage::class)->last();
        $this->assertNotNull($retry->delay, 'susubukan ulit pagkalipas ng ilang segundo');
        $held->release();

        // Naipadala: ang susunod na mensahe sa parehong customer ay maghihintay ng gap (20 s).
        $sender->process($this->row('WB-001')->id);
        $this->assertSame('sent', $this->row('WB-001')->status);
        $sender->queue([$this->row('WB-002', 'in_transit')->id], 1);
        $sender->process($this->row('WB-002', 'in_transit')->id);
        $this->assertSame('queued', $this->row('WB-002', 'in_transit')->status);
        $this->assertCount(3, Http::recorded(), 'ang ikalawa ay hindi pa ipinadala');

        $this->travel(21)->seconds();
        $sender->process($this->row('WB-002', 'in_transit')->id);
        $this->assertSame('sent', $this->row('WB-002', 'in_transit')->status);
    }

    public function test_a_customer_gets_one_message_of_a_kind_per_day_even_with_two_orders(): void
    {
        $this->botcake();
        $this->shipped('Page Uno', 'Ana Cruz', 'WB-001', 'psid-a', 'In Transit');
        $this->shipped('Page Uno', 'Ana Cruz', 'WB-002', 'psid-a', 'In Transit');
        app(Chatblast::class)->build('in_transit');
        $sender = app(ChatblastSender::class);

        $sender->queue([$this->row('WB-001', 'in_transit')->id], 1);
        $this->travel(21)->seconds();
        $sender->queue([$this->row('WB-002', 'in_transit')->id], 1);

        $this->assertSame('sent', $this->row('WB-001', 'in_transit')->status);
        $second = $this->row('WB-002', 'in_transit');
        $this->assertSame(['skipped', 'may mensahe na ngayong araw sa customer na ito'], [$second->status, $second->reason]);
        $this->assertCount(3, Http::recorded());
    }

    public static function missingValues(): array
    {
        // [order na kulang, mga bersyon ng araw, inaasahang bersyon o null, dahilan kapag no_config]
        return [
            'COD null, may ibang bersyon' => [['COD' => null], ['Bayad PHP {cod}', 'Salamat {name}'], 'B', null],
            'COD 0, iisang bersyon'       => [['COD' => '0'], ['Bayad PHP {cod}'], null, 'walang {cod}'],
            'walang pangalan at receiver' => [['fb_name' => '', 'receiver' => ''], ['Hello {name}'], null, 'walang {name}'],
            'walang rider phone'          => [[], ['Tawag {rider_phone}', 'Hello {name}'], 'B', null],
        ];
    }

    /** @dataProvider missingValues */
    public function test_a_version_with_a_placeholder_without_a_value_is_never_used(array $order, array $versions, ?string $version, ?string $reason): void
    {
        app(Chatblast::class)->setSequence('delivering', [$versions]);
        $this->shipped('Page Uno', $order['fb_name'] ?? 'Ana Cruz', 'WB-001', 'psid-a', 'Delivering', 0, $order);

        app(Chatblast::class)->build('delivering');

        $row = $this->row('WB-001');
        $this->assertSame($version, $row->version);
        if ($reason) {
            $this->assertSame(['no_config', $reason], [$row->status, $row->reason]);
        } else {
            $this->assertStringNotContainsString('{', (string) $row->text);
            $this->assertSame('ready', $row->status);
        }
    }

    public function test_deliver_refuses_a_blank_or_unfilled_text(): void
    {
        $this->botcake();
        $this->shipped('Page Uno', 'Ana Cruz', 'WB-001', 'psid-a');
        $this->shipped('Page Uno', 'Ben Reyes', 'WB-002', 'psid-b');
        app(Chatblast::class)->build('delivering');
        $this->row('WB-001')->update(['text' => '   ']);
        $this->row('WB-002')->update(['text' => 'Hello {name}, salamat']);

        app(ChatblastSender::class)->queue([$this->row('WB-001')->id, $this->row('WB-002')->id], 1);

        Http::assertNothingSent();
        $this->assertSame('no_config', $this->row('WB-001')->status);
        $this->assertSame('no_config', $this->row('WB-002')->status);
        $this->assertStringContainsString('{name}', $this->row('WB-002')->reason);
    }

    public function test_a_changed_psid_or_page_is_not_messaged_and_the_next_build_makes_a_fresh_row(): void
    {
        $this->botcake();
        $order = $this->shipped('Page Uno', 'Ana Cruz', 'WB-001', 'psid-a');
        app(Chatblast::class)->build('delivering');
        $order->update(['botcake_psid' => 'psid-z']); // naitama ang tugma

        app(ChatblastSender::class)->queue([$this->row('WB-001')->id], 1);

        Http::assertNothingSent();
        $old = BotcakeMessage::where('waybill', 'WB-001')->sole();
        $this->assertSame(['skipped', 'nagbago ang PSID/page'], [$old->status, $old->reason]);

        $this->assertSame(1, app(Chatblast::class)->build('delivering')['added']);
        $this->assertSame('psid-z', BotcakeMessage::where('waybill', 'WB-001')->where('status', 'ready')->sole()->psid);

        MacroOutput::whereKey($order->id)->update(['PAGE' => 'Page Dos']);
        app(ChatblastSender::class)->queue([BotcakeMessage::where('waybill', 'WB-001')->where('status', 'ready')->sole()->id], 1);
        Http::assertNothingSent();
    }

    public function test_the_read_back_matches_on_either_id_or_custom_field_id(): void
    {
        $this->botcake(); // ang sagot: id 123 (iba), custom_field_id 7701 (tugma)
        $this->shipped('Page Uno', 'Ana Cruz', 'WB-001', 'psid-a');
        app(Chatblast::class)->build('delivering');

        app(ChatblastSender::class)->queue([$this->row('WB-001')->id], 1);

        $this->assertSame('sent', $this->row('WB-001')->status);
    }

    public function test_the_header_shows_whose_test_psid_is_set_and_the_poll_can_skip_the_counts(): void
    {
        $this->actingAs($this->user());
        BotcakePsid::create(['page' => 'Page Uno', 'fb_name' => 'Owner Account', 'psid' => 'psid-owner-7788', 'status' => 'available', 'source' => 'api']);
        app(PageSettings::class)->set('Page Uno', 'botcake', 'test_psid', 'psid-owner-7788');

        $res = $this->getJson('/chatblast/list?page=Page%20Uno')->assertOk();
        $this->assertSame(['name' => 'Owner Account', 'psid_last4' => '7788'], $res->json('test_psid'));
        $this->assertNull($this->getJson('/chatblast/list')->json('test_psid'), 'walang page na napili');

        $light = $this->getJson('/chatblast/list?light=1')->assertOk();
        $this->assertNull($light->json('counts'), 'ang poll ay hindi nagbibilang');
        $this->assertArrayHasKey('state', $light->json());
    }

    public function test_the_daily_cap_uses_the_two_indexed_counts(): void
    {
        $this->botcake();
        config(['services.botcake.chatblast_daily_cap' => 2]);
        foreach (['WB-001' => 'psid-a', 'WB-002' => 'psid-b', 'WB-003' => 'psid-c'] as $wb => $psid) $this->shipped('Page Uno', 'B ' . $wb, $wb, $psid);
        app(Chatblast::class)->build('delivering');
        $this->row('WB-001')->update(['status' => 'sent', 'sent_at' => now()]);
        $this->row('WB-002')->update(['status' => 'unknown']);

        DB::enableQueryLog();
        app(ChatblastSender::class)->queue([$this->row('WB-003')->id], 1);
        $capQueries = collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => str_contains($q, 'count(*)') && str_contains($q, 'page_name'));
        DB::disableQueryLog();

        $this->assertSame('skipped', $this->row('WB-003')->status);
        foreach ($capQueries as $q) $this->assertStringNotContainsString(' or ', strtolower($q), 'walang OR sa bilang ng cap');
        $indexes = collect(DB::select("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'botcake_messages'"))->pluck('name')->all();
        $this->assertContains('botcake_messages_page_name_status_sent_at_index', $indexes);
        $this->assertContains('botcake_messages_page_name_status_updated_at_index', $indexes);
    }
}

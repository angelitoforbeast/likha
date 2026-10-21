<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeMessage;
use App\Models\ChatblastCampaign;
use App\Services\Chatblast;
use App\Services\ChatblastSender;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Ang J&T data ng chatblast ay mula sa database lang (jnt_latest_scans ng kusang track-sync, at from_jnts): walang
 * tawag sa J&T kapag kumukuha ng listahan, nagre-refresh o nagpapadala. Fake HTTP at orasan lang.
 */
class ChatblastTrackTest extends ChatblastTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'jnt.base_url' => 'https://jnt.test',
            'jnt.credentials' => ['eccompanyid' => 'EC-TEST', 'customerid' => 'CU-TEST', 'secret' => 'secret-test', 'api_key' => null],
            'services.jnt.track_sync_auto' => true, // ang DB-only na patakaran: naka-on ang track-sync at may J&T client
        ]);
        $written = [];
        Http::fake([
            'jnt.test/*' => Http::response(['responseitems' => []]),
            'botcake.io/*' => function (Request $req) use (&$written) {
                preg_match('#/customer/([^/]+)/#', $req->url(), $m);
                if (str_ends_with($req->url(), '/flows/send_flow')) return Http::response(['success' => true]);
                if ($req->method() === 'POST') { $written[$m[1]] = $req->data()['data'][0]['value']; return Http::response(['success' => true]); }
                return Http::response(['success' => true, 'data' => [['id' => 7701, 'value' => $written[$m[1]] ?? null]]]);
            },
        ]);
        $this->page('Page Uno');
        $this->travelTo('2026-10-18 09:00:00');
    }

    private function send(): void
    {
        app(ChatblastSender::class)->queue(BotcakeMessage::pluck('id')->all(), 1);
    }

    private function botcakeCalls(): int
    {
        return collect(Http::recorded())->filter(fn ($r) => str_contains($r[0]->url(), 'botcake.io'))->count();
    }

    public static function scansAtBuild(): array
    {
        // [scan_type, scan_time, may row ba]
        return [
            'On Delivery ngayong araw'  => ['On Delivery', '2026-10-18 08:00:00', true],
            'On Delivery kahapon'       => ['On Delivery', '2026-10-17 15:00:00', false],
            'Problematic ngayong araw'  => ['Problematic', '2026-10-18 08:30:00', false],
            'Delivery Fail'             => ['Delivery Fail', '2026-10-18 08:30:00', false],
            'Arrival'                   => ['Arrival', '2026-10-18 06:00:00', false],
            'walang scan'               => [null, null, false],
        ];
    }

    #[DataProvider('scansAtBuild')]
    public function test_S_061_7_a_delivering_row_is_built_only_with_an_on_delivery_scan_from_today(?string $type, ?string $time, bool $built): void
    {
        $this->shipped('Page Uno', 'Ana Cruz', 'JT0000000001', 'psid-a');
        DB::table('jnt_latest_scans')->delete();
        if ($type !== null) $this->scan('JT0000000001', $type, $time);

        app(Chatblast::class)->build('delivering');

        $this->assertSame($built ? 1 : 0, BotcakeMessage::count());
    }

    public function test_S_061_7_an_unsent_delivering_row_whose_scan_is_no_longer_on_delivery_today_is_skipped_and_freed(): void
    {
        $this->shipped('Page Uno', 'Ana Cruz', 'JT0000000001', 'psid-a');
        $this->shipped('Page Uno', 'Ben Reyes', 'JT0000000002', 'psid-b');
        app(Chatblast::class)->build('delivering');
        $this->assertSame(2, BotcakeMessage::where('status', 'ready')->count());

        $this->scan('JT0000000001', 'Delivery Fail', '2026-10-18 08:50:00');
        $this->travelTo('2026-10-19 08:00:00'); // ang On Delivery ng JT0000000002 ay kahapon na

        app(Chatblast::class)->build('delivering');

        $failed = BotcakeMessage::where('waybill', 'JT0000000001')->sole();
        $this->assertSame(['skipped', null], [$failed->status, $failed->dedupe_key]);
        $this->assertStringContainsString('Delivery Fail', $failed->reason);
        $this->assertSame(['skipped', Chatblast::NOT_TODAY], [BotcakeMessage::where('waybill', 'JT0000000002')->value('status'), BotcakeMessage::where('waybill', 'JT0000000002')->value('reason')]);

        // Kinabukasan, On Delivery ulit: may bagong row.
        $this->scan('JT0000000002', 'On Delivery', '2026-10-19 07:30:00');
        app(Chatblast::class)->build('delivering');
        $this->assertSame(1, BotcakeMessage::where('waybill', 'JT0000000002')->where('status', 'ready')->count());
    }

    public function test_S_061_8_build_refresh_and_send_never_call_jnt(): void
    {
        $this->shipped('Page Uno', 'Ana Cruz', 'JT0000000001', 'psid-a');
        $this->shipped('Page Uno', 'Ben Reyes', 'JT0000000002', 'psid-b', 'In Transit');

        app(Chatblast::class)->build();
        $this->travelTo('2026-10-18 09:15:00');
        app(Chatblast::class)->refreshIfDue();
        $this->send();

        $this->assertSame(2, BotcakeMessage::where('status', 'sent')->count());
        $this->assertFalse(collect(Http::recorded())->contains(fn ($r) => str_contains($r[0]->url(), 'jnt.test')), 'walang tawag sa J&T');
    }

    public static function staleScans(): array
    {
        return [
            'nasuri 91 minuto na'   => [91, false],
            'nasuri 89 minuto na'   => [89, true],
        ];
    }

    #[DataProvider('staleScans')]
    public function test_S_061_9_a_delivering_row_whose_scan_was_checked_over_90_minutes_ago_waits_for_the_next_track_sync(int $minutes, bool $sent): void
    {
        $this->shipped('Page Uno', 'Ana Cruz', 'JT0000000001', 'psid-a');
        app(Chatblast::class)->build('delivering');
        $this->travel($minutes)->minutes();

        $this->send();

        $row = BotcakeMessage::sole();
        $this->assertSame($sent ? ['sent', null] : ['ready', Chatblast::STALE_SCAN], [$row->status, $row->reason]);
        $this->assertSame($sent ? 1 : 0, $this->botcakeCalls() > 0 ? 1 : 0, 'walang mensahe sa customer kapag luma');
    }

    public function test_S_061_9_a_delivering_row_without_a_scan_row_is_not_sent(): void
    {
        $this->shipped('Page Uno', 'Ana Cruz', 'JT0000000001', 'psid-a');
        app(Chatblast::class)->build('delivering');
        DB::table('jnt_latest_scans')->delete();

        $this->send();

        $this->assertSame(['ready', Chatblast::STALE_SCAN], [BotcakeMessage::sole()->status, BotcakeMessage::sole()->reason]);
        $this->assertSame(0, $this->botcakeCalls());
    }

    public static function failedAttempts(): array
    {
        return ['Problematic' => ['Problematic'], 'Delivery Fail' => ['Delivery Fail']];
    }

    #[DataProvider('failedAttempts')]
    public function test_a_failed_attempt_in_the_database_at_send_time_skips_the_delivering_message(string $scan): void
    {
        $this->shipped('Page Uno', 'Ana Cruz', 'JT0000000001', 'psid-a');
        app(Chatblast::class)->build('delivering');
        $this->scan('JT0000000001', $scan, '2026-10-18 08:55:00');

        $this->send();

        $row = BotcakeMessage::sole();
        $this->assertSame(['skipped', null], [$row->status, $row->dedupe_key]);
        $this->assertStringContainsString($scan, $row->reason);
        $this->assertSame(0, $this->botcakeCalls());
    }

    public function test_S_061_10_rider_placeholders_come_from_the_latest_scan_and_from_jnts_only_without_a_scan_row(): void
    {
        app(Chatblast::class)->setSequence('delivering', [['Rider {rider} {rider_phone}', 'Walang rider {name}']]);
        $this->shipped('Page Uno', 'Ana Cruz', 'JT0000000002', 'psid-a');
        $this->scan('JT0000000002', 'On Delivery', null, null, 'Juan D. Cruz', '639171234567');
        DB::table('from_jnts')->where('waybill_number', 'JT0000000002')->update(['rider_name' => 'Iba Pa', 'rider_phone' => '639170000001']);
        // May scan ngayon na walang rider: hindi ang rider sa from_jnts (maaaring kahapon pa).
        $this->shipped('Page Uno', 'Ben Reyes', 'JT0000000004', 'psid-b');
        DB::table('from_jnts')->where('waybill_number', 'JT0000000004')->update(['rider_name' => 'Rider Kahapon', 'rider_phone' => '9171112222']);
        $this->shipped('Page Uno', 'Cora Diaz', 'JT0000000006', 'psid-c'); // walang rider kahit saan

        app(Chatblast::class)->build('delivering');

        $this->assertSame(['Rider Juan D. Cruz 09171234567', 'Walang rider Ben Reyes', 'Walang rider Cora Diaz'],
            BotcakeMessage::orderBy('waybill')->pluck('text')->all());

        // Walang jnt_latest_scans row: saka lang ang rider ng from_jnts (In Transit, walang On Delivery na kailangan).
        app(Chatblast::class)->setSequence('in_transit', [['Rider {rider} {rider_phone}', 'Walang rider {name}']]);
        $this->shipped('Page Uno', 'Dino Lim', 'JT0000000008', 'psid-d', 'In Transit');
        DB::table('from_jnts')->where('waybill_number', 'JT0000000008')->update(['rider_name' => 'Maria Santos', 'rider_phone' => '9175550000']);
        app(Chatblast::class)->build('in_transit');
        $this->assertSame('Rider Maria Santos 09175550000', BotcakeMessage::where('waybill', 'JT0000000008')->value('text'));

        // Bago ipadala: ang bagong rider sa database ang ginagamit.
        $this->scan('JT0000000006', 'On Delivery', null, null, 'Pedro Reyes', '09175556666');
        $this->send();
        $this->assertSame('Rider Pedro Reyes 09175556666', BotcakeMessage::where('waybill', 'JT0000000006')->value('text'));
    }

    public function test_S_061_10_a_scan_without_a_rider_never_sends_an_earlier_days_rider(): void
    {
        app(Chatblast::class)->setSequence('delivering', [['Rider {rider} {rider_phone}']]); // lahat ng bersyon ay may rider
        $this->shipped('Page Uno', 'Ana Cruz', 'JT0000000002', 'psid-a');
        $this->scan('JT0000000002', 'On Delivery', null, null, 'Juan D. Cruz', '639171234567');
        app(Chatblast::class)->build('delivering');
        $this->assertSame('ready', BotcakeMessage::sole()->status);

        // Bago ipadala: ang bagong On Delivery scan ay walang mabasang rider; ang from_jnts ay may rider pa kahapon.
        $this->scan('JT0000000002', 'On Delivery');
        DB::table('from_jnts')->where('waybill_number', 'JT0000000002')->update(['rider_name' => 'Juan D. Cruz', 'rider_phone' => '639171234567']);
        $this->send();

        $row = BotcakeMessage::sole();
        $this->assertSame(['no_config', Chatblast::RIDER_REASON], [$row->status, $row->reason]);
        $this->assertSame(0, $this->botcakeCalls(), 'walang mensahe na may lumang rider');
    }

    public static function lenientSites(): array
    {
        return [
            'naka-off ang track-sync (env)'    => [fn () => config(['services.jnt.track_sync_auto' => false])],
            'naka-off ang toggle ng CEO'       => [fn () => \App\Models\AppSetting::set(\App\Services\Jnt\JntAutoTrackSync::SETTING, '0')],
            'walang J&T client (Incepxion)'    => [fn () => config(['jnt.credentials' => ['eccompanyid' => null, 'customerid' => null, 'secret' => null]])],
        ];
    }

    #[DataProvider('lenientSites')]
    public function test_without_a_running_track_sync_delivering_follows_the_upload_status_and_any_stored_scan(\Closure $site): void
    {
        $site();
        $this->shipped('Page Uno', 'Ana Cruz', 'JT0000000002', 'psid-a');
        DB::table('jnt_latest_scans')->delete();                               // walang scan: ang status ng upload lang
        $this->shipped('Page Uno', 'Ben Reyes', 'JT0000000004', 'psid-b');
        $this->scan('JT0000000004', 'On Delivery', '2026-10-17 15:00:00', '2026-10-17 15:20:00'); // kahapon, luma
        $this->shipped('Page Uno', 'Cora Diaz', 'JT0000000006', 'psid-c');
        $this->scan('JT0000000006', 'Problematic');                           // may scan na hindi On Delivery

        app(Chatblast::class)->build('delivering');
        $this->assertSame(['JT0000000002', 'JT0000000004'], BotcakeMessage::orderBy('waybill')->pluck('waybill')->all());

        $this->travel(3)->hours(); // walang stale na patakaran
        $this->send();
        $this->assertSame(2, BotcakeMessage::where('status', 'sent')->count());
        $this->assertFalse(collect(Http::recorded())->contains(fn ($r) => str_contains($r[0]->url(), 'jnt.test')), 'walang tawag sa J&T');

        $this->actingAs($this->user());
        $this->assertStringContainsString('J&amp;T track-sync is off: Delivering uses the J&amp;T upload status only', $this->get('/chatblast')->assertOk()->getContent());
    }

    public function test_with_a_running_track_sync_the_to_send_page_has_no_off_notice(): void
    {
        $this->actingAs($this->user());
        $this->assertStringNotContainsString('J&amp;T track-sync is off', $this->get('/chatblast')->assertOk()->getContent());
    }

    public function test_the_rider_name_and_phone_are_cleaned_for_the_message(): void
    {
        $this->assertSame('Juan D. Cruz', Chatblast::riderName('OCW_Juan D. Cruz'));
        $this->assertSame('09171234567', Chatblast::riderPhone('639171234567'));
        $this->assertSame('09171234567', Chatblast::riderPhone('9171234567'));
        $this->assertSame('', Chatblast::riderPhone('12345'));
    }
}

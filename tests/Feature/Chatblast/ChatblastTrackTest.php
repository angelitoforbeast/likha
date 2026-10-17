<?php

namespace Tests\Feature\Chatblast;

use App\Jobs\SyncFromJntStatusByTrack;
use App\Models\BotcakeMessage;
use App\Services\Chatblast;
use App\Services\ChatblastSender;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * J&T TRACKQUERY para sa chatblast: kapag kumukuha ng listahan at bago ipadala ang delivering, para lang sa mga waybill
 * na iyon; 20 kada tawag; isinusulat ang status at rider sa from_jnts ayon sa id. Fake HTTP at orasan lang.
 */
class ChatblastTrackTest extends ChatblastTestCase
{
    /** @var array<int, array<int, string>> billcodes ng bawat tawag sa J&T */
    private array $calls = [];

    /** waybill => listahan ng scan (pinakabago muna) na isasagot ng fake J&T */
    private array $scans = [];

    /** ilang billcode ng isang batch ang "nalalaglag" (hindi sinasagot) */
    private int $drop = 0;

    private int $secondsPerCall = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::table('from_jnts', function (Blueprint $t) {
            $t->text('status_logs')->nullable();
            $t->string('signingtime')->nullable();
            $t->dateTime('submission_time')->nullable();
            $t->timestamp('updated_at')->nullable();
        });
        Artisan::call('migrate', ['--path' => 'database/migrations/2026_09_16_100000_create_jnt_track_sync_runs_table.php']);
        config([
            'jnt.base_url' => 'https://jnt.test',
            'jnt.credentials' => ['eccompanyid' => 'EC-TEST', 'customerid' => 'CU-TEST', 'secret' => 'secret-test', 'api_key' => null],
        ]);
        $this->page('Page Uno');
        $this->travelTo('2026-10-18 09:00:00');
        $this->fakeJnt();
    }

    private function onDelivery(string $rider = 'OCW_Juan D. Cruz', string $phone = '639171234567'): array
    {
        return ['scantype' => 'On Delivery', 'scantime' => '2026-10-18 08:00:00', 'desc' => 'Your parcel is out for delivery by sprinter【' . $rider . ' : ' . $phone . '】'];
    }

    private function fakeJnt(array $botcake = []): void
    {
        $written = [];
        Http::fake([
            'jnt.test/*' => function (Request $req) {
                $this->clock->time += $this->secondsPerCall;
                $codes = explode(',', json_decode($req->data()['logistics_interface'], true)['billcode']);
                $this->calls[] = $codes;
                $answer = count($codes) === 20 ? array_slice($codes, 0, 20 - $this->drop) : $codes; // ang buong batch ang nalalaglagan
                $traces = [];
                foreach ($answer as $bc) {
                    if (isset($this->scans[$bc])) $traces[] = ['billcode' => $bc, 'details' => $this->scans[$bc]];
                }
                return Http::response(['responseitems' => [['tracesList' => $traces]]]);
            },
            'botcake.io/*' => function (Request $req) use (&$written) {
                preg_match('#/customer/([^/]+)/#', $req->url(), $m);
                if (str_ends_with($req->url(), '/flows/send_flow')) return Http::response(['success' => true]);
                if ($req->method() === 'POST') { $written[$m[1]] = $req->data()['data'][0]['value']; return Http::response(['success' => true]); }
                return Http::response(['success' => true, 'data' => [['id' => 7701, 'value' => $written[$m[1]] ?? null]]]);
            },
        ]);
    }

    private function jntCalls(): array
    {
        return $this->calls;
    }

    public function test_only_the_waybills_being_built_are_tracked_in_batches_of_20_with_dropped_ones_retried_singly(): void
    {
        foreach (range(1, 25) as $i) {
            $wb = sprintf('JT%010d', $i);
            $this->shipped('Page Uno', 'Buyer ' . $i, $wb, 'psid-' . $i);
            $this->scans[$wb] = [$this->onDelivery()];
        }
        $this->shipped('Page Uno', 'Other', 'JT9999999999', 'psid-x', 'In Transit'); // hindi delivering: hindi kasama
        $this->drop = 2;

        app(Chatblast::class)->build('delivering');

        $calls = $this->jntCalls();
        $this->assertCount(20, $calls[0], 'unang tawag: 20');
        $this->assertSame([[$calls[0][18]], [$calls[0][19]]], [$calls[1], $calls[2]], 'ang 2 na nalaglag ay isa-isa');
        $this->assertCount(5, $calls[3]);
        $this->assertCount(4, $calls);
        $this->assertNotContains('JT9999999999', array_merge(...$calls));
        $this->assertSame(array_fill(0, 3, 0.5), array_map(fn ($s) => round($s, 3), $this->clock->sleeps), '2 tawag kada segundo');
        $this->assertSame(25, BotcakeMessage::where('status', 'ready')->count());
    }

    public function test_the_status_and_the_rider_are_written_by_id_and_a_moved_on_parcel_is_not_built(): void
    {
        $this->shipped('Page Uno', 'Ana Cruz', 'JT0000000001', 'psid-a');
        $this->shipped('Page Uno', 'Ben Reyes', 'JT0000000002', 'psid-b');
        DB::table('from_jnts')->insert(['waybill_number' => 'JT0000000001', 'status' => 'Delivering']); // kopya
        $this->scans['JT0000000001'] = [$this->onDelivery(), ['scantype' => 'Arrival', 'scantime' => '2026-10-17 20:00:00']];
        $this->scans['JT0000000002'] = [['scantype' => 'Delivered', 'scantime' => '2026-10-18 08:30:00']];

        app(Chatblast::class)->build('delivering');

        $rows = DB::table('from_jnts')->where('waybill_number', 'JT0000000001')->get();
        $this->assertCount(2, $rows);
        foreach ($rows as $r) $this->assertSame(['Delivering', 'Juan D. Cruz', '639171234567'], [$r->status, $r->rider_name, $r->rider_phone]);
        $delivered = DB::table('from_jnts')->where('waybill_number', 'JT0000000002')->sole();
        $this->assertSame('Delivered', $delivered->status);
        $this->assertSame('chatblast', json_decode($delivered->status_logs, true)[0]['src']);
        $this->assertSame(['JT0000000001'], BotcakeMessage::pluck('waybill')->all(), 'Delivered na: walang mensahe');

        $run = DB::table('jnt_track_sync_runs')->whereNull('date_from')->sole();
        $this->assertSame([2, 1], [(int) $run->processed, (int) $run->updated]);
        $this->assertSame(1, json_decode($run->result_sample, true)['rider_found']);
    }

    public function test_the_rider_is_cleaned_for_the_message_and_versions_without_it_stay_the_fallback(): void
    {
        app(Chatblast::class)->setSequence('delivering', [['Rider {rider} {rider_phone}', 'Walang rider {name}']]);
        $this->shipped('Page Uno', 'Ana Cruz', 'JT0000000002', 'psid-a');
        $this->shipped('Page Uno', 'Ben Reyes', 'JT0000000004', 'psid-b');
        $this->scans['JT0000000002'] = [$this->onDelivery('OCW_Juan D. Cruz', '63 917 123 4567')];
        $this->scans['JT0000000004'] = [['scantype' => 'On Delivery', 'desc' => 'out for delivery']]; // walang rider

        app(Chatblast::class)->build('delivering');

        $this->assertSame('Rider Juan D. Cruz 09171234567', BotcakeMessage::where('waybill', 'JT0000000002')->value('text'));
        $this->assertSame('Walang rider Ben Reyes', BotcakeMessage::where('waybill', 'JT0000000004')->value('text'));
        $this->assertSame('Juan D. Cruz', Chatblast::riderName('OCW_Juan D. Cruz'));
        $this->assertSame('09171234567', Chatblast::riderPhone('639171234567'));
        $this->assertSame('09171234567', Chatblast::riderPhone('9171234567'));
        $this->assertSame('', Chatblast::riderPhone('12345'));
    }

    public function test_the_build_tracks_for_at_most_60_seconds_and_leaves_the_rest_pending(): void
    {
        foreach (range(1, 60) as $i) {
            $wb = sprintf('JT%010d', $i);
            $this->shipped('Page Uno', 'Buyer ' . $i, $wb, 'psid-' . $i);
            $this->scans[$wb] = [$this->onDelivery()];
        }
        $this->secondsPerCall = 35;

        app(Chatblast::class)->build('delivering');

        $this->assertCount(2, $this->jntCalls(), 'tumigil pagkalipas ng 60 s');
        $this->assertSame(60, BotcakeMessage::count(), 'nabuo pa rin ang lahat');
        $this->assertSame(20, BotcakeMessage::get()->filter(fn ($m) => empty($m->vars['tracked_at']))->count(), 'ang 20 na hindi naabot: rider pending');
        $this->assertSame(20, json_decode(DB::table('jnt_track_sync_runs')->whereNull('date_from')->value('result_sample'), true)['pending']);
    }

    public function test_the_single_retries_of_dropped_waybills_also_stop_at_the_budget(): void
    {
        foreach (range(1, 20) as $i) {
            $wb = sprintf('JT%010d', $i);
            $this->shipped('Page Uno', 'Buyer ' . $i, $wb, 'psid-' . $i);
            $this->scans[$wb] = [$this->onDelivery()];
        }
        $this->drop = 15;          // 15 ang nalaglag sa batch
        $this->secondsPerCall = 35; // bawat tawag ay 35 s

        app(Chatblast::class)->build('delivering');

        $this->assertCount(2, $this->jntCalls(), 'ang batch at isang isa-isang subok lang: lampas na sa 60 s');
        $this->assertSame(14, json_decode(DB::table('jnt_track_sync_runs')->whereNull('date_from')->value('result_sample'), true)['pending'], '5 sa batch at 1 isa-isa ang nasagot; ang 14 na hindi nasubukan: pending, hindi "walang sagot"');
    }

    public function test_a_delivering_row_is_tracked_again_before_sending_when_its_data_is_older_than_30_minutes(): void
    {
        app(Chatblast::class)->setSequence('delivering', [['Rider {rider} {rider_phone}', 'Walang rider {name}']]);
        $this->shipped('Page Uno', 'Ana Cruz', 'JT0000000002', 'psid-a');
        $this->shipped('Page Uno', 'Ben Reyes', 'JT0000000004', 'psid-b');
        $this->scans['JT0000000002'] = [['scantype' => 'On Delivery', 'desc' => 'out for delivery']];
        $this->scans['JT0000000004'] = [['scantype' => 'On Delivery', 'desc' => 'out for delivery']];
        app(Chatblast::class)->build('delivering');
        $this->assertSame('Walang rider Ana Cruz', BotcakeMessage::where('waybill', 'JT0000000002')->value('text'));

        $this->travel(40)->minutes();
        $this->scans['JT0000000002'] = [$this->onDelivery()];
        $this->scans['JT0000000004'] = [['scantype' => 'Delivered', 'scantime' => '2026-10-18 09:30:00'], $this->onDelivery()];
        $this->calls = [];
        app(ChatblastSender::class)->queue(BotcakeMessage::pluck('id')->all(), 1);

        $this->assertSame([['JT0000000002'], ['JT0000000004']], $this->jntCalls(), 'isa-isa bago ipadala');
        $sent = BotcakeMessage::where('waybill', 'JT0000000002')->sole();
        $this->assertSame(['sent', 'Rider Juan D. Cruz 09171234567'], [$sent->status, $sent->text]);
        $moved = BotcakeMessage::where('waybill', 'JT0000000004')->sole();
        $this->assertSame('skipped', $moved->status);
        $this->assertStringContainsString('Delivered', $moved->reason);
    }

    public function test_a_fresh_row_is_not_tracked_again_before_sending(): void
    {
        $this->shipped('Page Uno', 'Ana Cruz', 'JT0000000002', 'psid-a');
        $this->scans['JT0000000002'] = [$this->onDelivery()];
        app(Chatblast::class)->build('delivering');
        $this->calls = [];

        $this->travel(10)->minutes();
        app(ChatblastSender::class)->queue(BotcakeMessage::pluck('id')->all(), 1);

        $this->assertSame([], $this->jntCalls());
        $this->assertSame('sent', BotcakeMessage::sole()->status);
    }

    public function test_without_jnt_config_nothing_is_tracked_and_one_warning_is_logged(): void
    {
        config(['jnt.credentials' => ['eccompanyid' => null, 'customerid' => null, 'secret' => null]]);
        Log::spy();
        $this->shipped('Page Uno', 'Ana Cruz', 'JT0000000002', 'psid-a');

        app(Chatblast::class)->build('delivering');
        app(ChatblastSender::class)->queue(BotcakeMessage::pluck('id')->all(), 1);

        $this->assertSame([], $this->jntCalls());
        $this->assertSame('sent', BotcakeMessage::sole()->status, 'gumagana pa rin ang chatblast');
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($msg) => str_contains($msg, 'J&T'));
    }

    public function test_the_manual_track_sync_still_works_and_shows_the_last_automatic_run(): void
    {
        DB::table('from_jnts')->insert(['waybill_number' => 'JT0000000007', 'status' => 'In Transit', 'submission_time' => '2026-10-17 10:00:00']);
        $this->scans['JT0000000007'] = [$this->onDelivery()];
        $runId = DB::table('jnt_track_sync_runs')->insertGetId(['date_from' => '2026-10-01', 'date_to' => '2026-10-18', 'dry_run' => false, 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);

        (new SyncFromJntStatusByTrack($runId, '2026-10-01', '2026-10-18', false))->handle();

        $row = DB::table('from_jnts')->where('waybill_number', 'JT0000000007')->sole();
        $this->assertSame(['Delivering', 'Juan D. Cruz', '639171234567'], [$row->status, $row->rider_name, $row->rider_phone]);
        $this->assertSame('track_sync', json_decode($row->status_logs, true)[0]['src']);
        $this->assertSame(['done', 1, 1], [DB::table('jnt_track_sync_runs')->where('id', $runId)->value('status'), (int) DB::table('jnt_track_sync_runs')->where('id', $runId)->value('processed'), (int) DB::table('jnt_track_sync_runs')->where('id', $runId)->value('updated')]);

        $this->shipped('Page Uno', 'Ana Cruz', 'JT0000000002', 'psid-a');
        $this->scans['JT0000000002'] = [$this->onDelivery()];
        app(Chatblast::class)->build('delivering');

        $this->actingAs($this->user());
        $html = $this->get('/jnt/track-sync')->assertOk()->getContent();
        $this->assertStringContainsString('Huling awtomatikong track', $html);
        $this->assertStringContainsString('rider: 1', $html);
    }

    public static function failedAttempts(): array
    {
        return [
            'Problematic'   => ['Problematic'],
            'Delivery Fail' => ['Delivery Fail'],
        ];
    }

    /**
     * Ang On Delivery na sinundan ng bigong subok (Problematic, Delivery Fail): "Idedeliver po ngayon" ay hindi na totoo.
     * @dataProvider failedAttempts
     */
    public function test_a_failed_delivery_attempt_after_on_delivery_stops_the_delivering_message(string $scan): void
    {
        $this->shipped('Page Uno', 'Ana Cruz', 'JT0000000002', 'psid-a');
        $this->shipped('Page Uno', 'Ben Reyes', 'JT0000000004', 'psid-b');
        $this->scans['JT0000000002'] = [$this->onDelivery()];
        $this->scans['JT0000000004'] = [['scantype' => $scan, 'scantime' => '2026-10-18 08:40:00'], $this->onDelivery()];

        app(Chatblast::class)->build('delivering');

        $this->assertSame(['JT0000000002'], BotcakeMessage::pluck('waybill')->all(), 'walang row ang bigong subok');

        // Sa pagpapadala: ang bagong scan ay bigong subok na.
        $this->travel(40)->minutes();
        $this->scans['JT0000000002'] = [['scantype' => $scan, 'scantime' => '2026-10-18 09:35:00'], $this->onDelivery()];
        app(ChatblastSender::class)->queue(BotcakeMessage::pluck('id')->all(), 1);

        $row = BotcakeMessage::sole();
        $this->assertSame('skipped', $row->status);
        $this->assertStringContainsString($scan, $row->reason);
        $this->assertFalse(collect(Http::recorded())->contains(fn ($r) => str_contains($r[0]->url(), 'botcake.io')), 'walang mensahe sa customer');
    }

    public function test_a_jnt_error_at_send_time_still_sends_with_the_fallback_and_is_not_stamped_as_tracked(): void
    {
        app(Chatblast::class)->setSequence('delivering', [['Rider {rider} {rider_phone}', 'Walang rider {name}']]);
        $this->shipped('Page Uno', 'Ana Cruz', 'JT0000000002', 'psid-a');
        // Walang sagot ang J&T para sa waybill (wala sa scans): hindi ito "na-track".
        app(Chatblast::class)->build('delivering');
        $this->assertNull(BotcakeMessage::sole()->vars['tracked_at']);

        app(ChatblastSender::class)->queue(BotcakeMessage::pluck('id')->all(), 1);

        $this->assertSame(['sent', 'Walang rider Ana Cruz'], [BotcakeMessage::sole()->status, BotcakeMessage::sole()->text]);
    }

    public function test_after_a_failed_attempt_the_next_on_delivery_gets_its_message(): void
    {
        $this->shipped('Page Uno', 'Ana Cruz', 'JT0000000002', 'psid-a');
        $this->scans['JT0000000002'] = [$this->onDelivery()];
        app(Chatblast::class)->build('delivering');

        $this->travel(40)->minutes();
        $this->scans['JT0000000002'] = [['scantype' => 'Problematic', 'scantime' => '2026-10-18 09:35:00'], $this->onDelivery()];
        app(ChatblastSender::class)->queue(BotcakeMessage::pluck('id')->all(), 1);
        $this->assertSame('skipped', BotcakeMessage::sole()->status);

        // Kinabukasan: ibang subok, On Delivery ulit.
        $this->travelTo('2026-10-19 09:00:00');
        $this->scans['JT0000000002'] = [$this->onDelivery(), ['scantype' => 'Problematic', 'scantime' => '2026-10-18 09:35:00']];
        app(Chatblast::class)->build('delivering');

        $this->assertSame(1, BotcakeMessage::where('status', 'ready')->count(), 'may bagong row para sa bagong subok');
    }

    public function test_a_later_build_that_sees_a_failed_attempt_skips_the_unsent_row(): void
    {
        $this->shipped('Page Uno', 'Ana Cruz', 'JT0000000002', 'psid-a');
        $this->scans['JT0000000002'] = [$this->onDelivery()];
        app(Chatblast::class)->build('delivering');

        $this->travel(10)->minutes();
        $this->scans['JT0000000002'] = [['scantype' => 'Delivery Fail', 'scantime' => '2026-10-18 09:05:00'], $this->onDelivery()];
        app(Chatblast::class)->build('delivering');

        $row = BotcakeMessage::sole();
        $this->assertSame(['skipped', null], [$row->status, $row->dedupe_key]);
        $this->assertStringContainsString('Delivery Fail', $row->reason);
    }

    public function test_a_stale_stored_rider_is_never_used_when_the_fresh_answer_has_none(): void
    {
        app(Chatblast::class)->setSequence('delivering', [['Rider {rider} {rider_phone}', 'Walang rider {name}']]);
        $this->shipped('Page Uno', 'Ana Cruz', 'JT0000000002', 'psid-a');
        // Kahapon: rider A ang nakatala. Ngayon: On Delivery na hindi mabasa ang rider.
        DB::table('from_jnts')->where('waybill_number', 'JT0000000002')->update(['rider_name' => 'Rider Kahapon', 'rider_phone' => '639170000000']);
        $this->scans['JT0000000002'] = [['scantype' => 'On Delivery', 'desc' => 'out for delivery']];

        app(Chatblast::class)->build('delivering');
        $this->assertSame('Walang rider Ana Cruz', BotcakeMessage::sole()->text, 'hindi ang rider kahapon');

        // Sa pagpapadala (lampas 30 min), walang sagot ang J&T: hindi rin ang rider kahapon.
        BotcakeMessage::query()->update(['vars' => json_encode(array_merge(BotcakeMessage::sole()->vars, ['tracked_at' => null]))]);
        unset($this->scans['JT0000000002']);
        app(ChatblastSender::class)->queue(BotcakeMessage::pluck('id')->all(), 1);

        $sent = BotcakeMessage::sole();
        $this->assertSame('sent', $sent->status);
        $this->assertStringNotContainsString('Kahapon', $sent->text);
        $this->assertStringNotContainsString('0000000', $sent->text);
    }

    public function test_the_web_build_tracks_for_at_most_20_seconds_and_one_build_runs_at_a_time(): void
    {
        $this->actingAs($this->user());
        foreach (range(1, 60) as $i) {
            $wb = sprintf('JT%010d', $i);
            $this->shipped('Page Uno', 'Buyer ' . $i, $wb, 'psid-' . $i);
            $this->scans[$wb] = [$this->onDelivery()];
        }
        $this->secondsPerCall = 6;

        $this->postJson('/chatblast/build', ['kind' => 'delivering'])->assertOk();
        $this->assertCount(2, $this->jntCalls(), 'walang bagong tawag pagkalipas ng 10 s, kaya kasya ang huling tawag (10 s) sa 20 s');

        $lock = \Illuminate\Support\Facades\Cache::lock(Chatblast::BUILD_LOCK, 60);
        $lock->get();
        $this->postJson('/chatblast/build', ['kind' => 'delivering'])->assertStatus(409)->assertJsonPath('error', 'May tumatakbong pagkuha ng listahan; subukan ulit pagkatapos nito.');
        $lock->release();
    }

    public function test_rows_already_delivered_or_returned_are_not_rewritten(): void
    {
        $this->shipped('Page Uno', 'Ana Cruz', 'JT0000000002', 'psid-a');
        DB::table('from_jnts')->insert(['waybill_number' => 'JT0000000002', 'status' => 'Returned']);
        $this->scans['JT0000000002'] = [$this->onDelivery()];

        app(Chatblast::class)->build('delivering');

        $returned = DB::table('from_jnts')->where('waybill_number', 'JT0000000002')->where('status', 'Returned')->sole();
        $this->assertNull($returned->rider_name, 'ang final na kopya ay hindi ginagalaw');
    }

    public function test_a_single_waybill_is_one_call_and_a_send_does_not_record_a_run(): void
    {
        $this->shipped('Page Uno', 'Ana Cruz', 'JT0000000002', 'psid-a');
        app(Chatblast::class)->build('delivering'); // walang sagot ang J&T
        $this->assertCount(1, $this->jntCalls(), 'walang isa-isang ulit para sa iisang waybill');
        $runs = DB::table('jnt_track_sync_runs')->count();

        $this->calls = [];
        app(ChatblastSender::class)->queue(BotcakeMessage::pluck('id')->all(), 1);

        $this->assertCount(1, $this->jntCalls());
        $this->assertSame($runs, DB::table('jnt_track_sync_runs')->count(), 'ang pagpapadala ay hindi nagtatala ng run');
    }

    public function test_the_least_recently_tracked_go_first_and_rows_without_psid_are_not_tracked(): void
    {
        $this->shipped('Page Uno', 'Ana Cruz', 'JT0000000001', 'psid-a');
        $this->shipped('Page Uno', 'Ben Reyes', 'JT0000000002', 'psid-b');
        $this->shipped('Page Uno', 'Cora Diaz', 'JT0000000003', null);
        app(Chatblast::class)->build('delivering');
        BotcakeMessage::where('waybill', 'JT0000000001')->update(['vars' => json_encode(['name' => 'Ana Cruz', 'tracked_at' => now()->subHours(1)->toIso8601String()])]);
        BotcakeMessage::where('waybill', 'JT0000000002')->update(['vars' => json_encode(['name' => 'Ben Reyes', 'tracked_at' => now()->subHours(3)->toIso8601String()])]);
        $this->shipped('Page Uno', 'Dino Lim', 'JT0000000004', 'psid-d', 'In Transit');
        $this->calls = [];

        app(Chatblast::class)->build();

        $this->assertSame(['JT0000000002', 'JT0000000001', 'JT0000000004'], $this->jntCalls()[0], 'pinakalumang na-track muna, delivering bago in_transit; walang JT0000000003 (walang PSID)');
    }
}

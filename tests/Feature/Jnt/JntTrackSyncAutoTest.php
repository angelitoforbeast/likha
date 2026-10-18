<?php

namespace Tests\Feature\Jnt;

use App\Services\Jnt\JntAutoTrackSync;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Chatblast\ChatblastTestCase;

/**
 * Ang kusang J&T track-sync kada oras (:20, 02 at 06–23 Manila): ang pinakabagong scan ng bawat bukas na waybill sa
 * jnt_latest_scans, ang status at rider sa from_jnts. Fake na J&T at orasan lang.
 */
class JntTrackSyncAutoTest extends ChatblastTestCase
{
    /** @var array<int, array<int, string>> billcodes ng bawat tawag sa J&T */
    private array $calls = [];

    /** waybill => listahan ng scan (pinakabago muna) na isasagot ng fake J&T */
    private array $scans = [];

    private int $secondsPerCall = 0;

    /** ilang billcode ng isang batch ng 20 ang "nalalaglag" (hindi sinasagot) */
    private int $drop = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::table('from_jnts', function (Blueprint $t) {
            $t->text('status_logs')->nullable();
            $t->string('signingtime')->nullable();
            $t->dateTime('submission_time')->nullable();
            $t->timestamp('updated_at')->nullable();
        });
        config([
            'jnt.base_url' => 'https://jnt.test',
            'jnt.credentials' => ['eccompanyid' => 'EC-TEST', 'customerid' => 'CU-TEST', 'secret' => 'secret-test', 'api_key' => null],
            'services.jnt.track_sync_auto' => true,
        ]);
        Http::fake(['jnt.test/*' => function (Request $req) {
            $this->clock->time += $this->secondsPerCall;
            $codes = explode(',', json_decode($req->data()['logistics_interface'], true)['billcode']);
            $this->calls[] = $codes;
            $traces = [];
            $answer = count($codes) === 20 ? array_slice($codes, 0, 20 - $this->drop) : $codes; // ang buong batch ang nalalaglagan
            foreach ($answer as $bc) {
                if (isset($this->scans[$bc])) $traces[] = ['billcode' => $bc, 'details' => $this->scans[$bc]];
            }
            return Http::response(['responseitems' => [['tracesList' => $traces]]]);
        }]);
        $this->travelTo('2026-10-18 09:20:00');
    }

    /** Isang from_jnts row; $minutesAgo ang edad ng submission_time (mas maliit = mas bago). */
    private function waybill(string $wb, ?string $status = 'In Transit', int $minutesAgo = 0, bool $scan = true): void
    {
        DB::table('from_jnts')->insert(['waybill_number' => $wb, 'status' => $status,
            'submission_time' => now()->subMinutes($minutesAgo)->format('Y-m-d H:i:s')]);
        if ($scan) $this->scans[$wb] ??= [['scantype' => 'Arrival', 'scantime' => '2026-10-18 07:00:00', 'city' => 'Pasig', 'state' => 'Metro Manila']];
    }

    private function onDelivery(string $time = '2026-10-18 08:00:00'): array
    {
        return ['scantype' => 'On Delivery', 'scantime' => $time, 'city' => 'Pasig',
            'desc' => 'Your parcel is out for delivery by sprinter【OCW_Juan D. Cruz : 639171234567】'];
    }

    private function sync(): ?array
    {
        return app(JntAutoTrackSync::class)->runIfDue();
    }

    public function test_S_061_1_the_likha_scheduler_runs_it_at_20_past_2_and_6_to_23_manila_in_the_background(): void
    {
        Artisan::call('schedule:list'); // nilo-load ang routes/console.php
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'jnt:track-sync-auto'));

        $this->assertNotNull($event);
        $this->assertSame('20 2,6-23 * * *', $event->expression);
        $this->assertSame('Asia/Manila', $event->timezone);
        $this->assertTrue($event->runInBackground, 'hindi hinaharangan ang ibang naka-schedule');
        $this->assertTrue($event->withoutOverlapping);
    }

    public static function times(): array
    {
        // [oras sa Manila, tatakbo ba]
        return [
            '06:19 (bago ang :20)'      => ['2026-10-18 06:19:00', false],
            '06:20'                     => ['2026-10-18 06:20:00', true],
            '23:55 (huli sa oras)'      => ['2026-10-18 23:55:00', true],
            '02:20'                     => ['2026-10-18 02:20:00', true],
            '03:20 (walang takbo)'      => ['2026-10-18 03:20:00', false],
            '05:40 (walang takbo)'      => ['2026-10-18 05:40:00', false],
        ];
    }

    #[DataProvider('times')]
    public function test_S_061_1_one_run_per_due_hour_from_20_past(string $at, bool $runs): void
    {
        $this->travelTo($at);
        $this->waybill('JT0000000001');

        $this->assertSame($runs, $this->sync() !== null);
        $this->assertSame($runs ? 1 : 0, count($this->calls));
        $this->assertNull($this->sync(), 'isang beses lang kada oras');
        $this->assertSame($runs ? 1 : 0, count($this->calls));
    }

    public function test_S_061_1_off_by_the_setting_nothing_runs(): void
    {
        config(['services.jnt.track_sync_auto' => false]);
        $this->waybill('JT0000000001');

        $this->assertNull($this->sync());
        $this->assertSame([], $this->calls);
    }

    public function test_S_061_1_chatblast_auto_starts_it_in_the_background_once_per_due_hour(): void
    {
        Process::fake();

        Artisan::call('chatblast:auto');
        Process::assertRan(fn ($p) => str_contains((string) (is_array($p->command) ? implode(' ', $p->command) : $p->command), 'jnt:track-sync-auto'));
        Process::assertRanTimes(fn ($p) => true, 1);

        $this->sync(); // ang nagsimulang proseso ang kumuha ng oras
        Artisan::call('chatblast:auto');
        Process::assertRanTimes(fn ($p) => true, 1);

        $this->travelTo('2026-10-18 10:05:00'); // bago ang :20 ng susunod na oras
        Artisan::call('chatblast:auto');
        Process::assertRanTimes(fn ($p) => true, 1);

        config(['services.jnt.track_sync_auto' => false]);
        $this->travelTo('2026-10-18 10:20:00');
        Artisan::call('chatblast:auto');
        Process::assertRanTimes(fn ($p) => true, 1);
    }

    public function test_S_061_2_a_run_still_going_holds_the_lock_and_the_next_start_does_not_track(): void
    {
        $this->waybill('JT0000000001');
        $lock = Cache::lock(JntAutoTrackSync::LOCK, 60);
        $lock->get();

        $this->assertSame(['busy' => true], $this->sync());
        $this->assertSame([], $this->calls);
        $lock->release();
    }

    public function test_S_061_3_open_waybills_of_the_last_60_days_newest_first_20_per_call_2_per_second_recorded_as_auto(): void
    {
        foreach (range(1, 24) as $i) $this->waybill(sprintf('JT%010d', $i), 'In Transit', 100 - $i); // ang 24 ang pinakabago
        $this->waybill('JT0000000025', null, 200);                       // walang status: bukas pa
        $this->waybill('JT0000000026', 'Delivered', 1);                  // tapos na
        $this->waybill('JT0000000027', 'Returned', 1);
        $this->waybill('JT0000000028', 'In Transit', 61 * 24 * 60);      // lampas sa 60 araw
        DB::table('from_jnts')->insert(['waybill_number' => 'JT0000000024', 'status' => 'In Transit',
            'submission_time' => now()->subDays(3)->format('Y-m-d H:i:s')]); // kopya: isang beses lang
        $this->drop = 2;

        $this->sync();

        $wb = fn (int ...$n) => array_map(fn ($i) => sprintf('JT%010d', $i), $n);
        $this->assertSame([$wb(...range(24, 5)), $wb(6), $wb(5), array_merge($wb(4, 3, 2, 1), ['JT0000000025'])], $this->calls,
            'pinakabago muna, 20 kada tawag, ang 2 na nalaglag ay isa-isa');
        $this->assertSame([0.5, 0.5, 0.5], array_map(fn ($s) => round($s, 3), $this->clock->sleeps), '2 tawag kada segundo');

        $run = DB::table('jnt_track_sync_runs')->sole();
        $this->assertSame(['auto', 'done', 25, 25], [$run->kind, $run->status, (int) $run->total, (int) $run->processed]);
        $this->assertNull($run->date_from);
        $this->assertNotNull($run->finished_at);
    }

    public function test_S_061_4_the_run_stops_at_40_minutes_and_the_next_run_starts_with_the_waybills_not_reached(): void
    {
        foreach (range(1, 60) as $i) $this->waybill(sprintf('JT%010d', $i), 'In Transit', 100 - $i); // ang 60 ang pinakabago
        $this->secondsPerCall = 1300; // 2 tawag = 2600 s: lampas sa 40 minuto pagkatapos ng ikalawa

        $this->sync();

        $this->assertCount(2, $this->calls, 'walang bagong tawag pagkalipas ng 40 minuto');
        $first = DB::table('jnt_track_sync_runs')->sole();
        $this->assertSame([40, 'done'], [(int) $first->processed, $first->status]);
        $sample = json_decode($first->result_sample, true);
        $this->assertSame(20, $sample['pending']);
        $this->assertTrue($sample['capped']);

        $this->calls = [];
        $this->travelTo('2026-10-18 10:20:00');
        $this->sync();

        $this->assertSame(sprintf('JT%010d', 20), $this->calls[0][0], 'ang hindi naabot muna (pinakabago sa kanila)');
        $this->assertSame(sprintf('JT%010d', 1), $this->calls[0][19]);
        $this->assertSame(sprintf('JT%010d', 60), $this->calls[1][0], 'saka ang pinakabago');
    }

    public function test_S_061_5_a_problematic_scan_is_stored_as_the_latest_scan_but_does_not_change_the_status(): void
    {
        $this->waybill('JT0000000001', 'Delivering');
        $this->scans['JT0000000001'] = [['scantype' => 'Problematic', 'scantime' => '2026-10-18 08:30:00', 'city' => 'Pasig', 'state' => 'Metro Manila']];
        $this->waybill('JT0000000002', 'Delivering');
        $this->scans['JT0000000002'] = [['scantype' => 'Delivered', 'scantime' => '2026-10-18 08:45:00'], $this->onDelivery()];

        $this->sync();

        $scan = DB::table('jnt_latest_scans')->where('waybill', 'JT0000000001')->sole();
        $this->assertSame(['Problematic', '2026-10-18 08:30:00', 'Pasig, Metro Manila'], [$scan->scan_type, substr((string) $scan->scan_time, 0, 19), $scan->scan_place]);
        $this->assertSame('2026-10-18 09:20:00', substr((string) $scan->checked_at, 0, 19));
        $this->assertSame('Delivering', DB::table('from_jnts')->where('waybill_number', 'JT0000000001')->value('status'));

        $done = DB::table('from_jnts')->where('waybill_number', 'JT0000000002')->sole();
        $this->assertSame('Delivered', $done->status);
        $this->assertSame(['Delivering', 'Delivered', 'auto_track'], array_values(array_intersect_key(json_decode($done->status_logs, true)[0], array_flip(['from', 'to', 'src']))));
        $this->assertSame('Delivered', DB::table('jnt_latest_scans')->where('waybill', 'JT0000000002')->value('scan_type'));
        $this->assertNull(DB::table('jnt_latest_scans')->where('waybill', 'JT0000000002')->value('scan_place'), 'walang lugar sa sagot');

        $run = DB::table('jnt_track_sync_runs')->sole();
        $this->assertSame([1, 1], [(int) $run->unmapped, (int) $run->updated]);
    }

    public function test_S_061_6_the_rider_is_written_to_the_latest_scan_and_every_open_row_and_a_failed_answer_keeps_the_old_scan(): void
    {
        $this->waybill('JT0000000001', 'In Transit');
        DB::table('from_jnts')->insert(['waybill_number' => 'JT0000000001', 'status' => 'In Transit',
            'submission_time' => now()->subHour()->format('Y-m-d H:i:s')]); // kopya
        DB::table('from_jnts')->insert(['waybill_number' => 'JT0000000001', 'status' => null]);       // kopyang walang status: bukas pa
        DB::table('from_jnts')->insert(['waybill_number' => 'JT0000000001', 'status' => 'Returned']); // tapos na: hindi ginagalaw
        $this->scans['JT0000000001'] = [$this->onDelivery()];
        $this->waybill('JT0000000002', 'In Transit', 0, false); // walang sagot ang J&T
        DB::table('jnt_latest_scans')->insert(['waybill' => 'JT0000000002', 'scan_type' => 'Arrival', 'scan_time' => '2026-10-17 20:00:00',
            'checked_at' => '2026-10-17 21:20:00']);

        $this->sync();

        $scan = DB::table('jnt_latest_scans')->where('waybill', 'JT0000000001')->sole();
        $this->assertSame(['On Delivery', 'Juan D. Cruz', '639171234567'], [$scan->scan_type, $scan->rider_name, $scan->rider_phone]);
        $this->assertNull(DB::table('from_jnts')->where('waybill_number', 'JT0000000001')->where('status', 'Returned')->value('rider_name'));
        foreach (DB::table('from_jnts')->where('waybill_number', 'JT0000000001')->where('status', '<>', 'Returned')->get() as $row) {
            $this->assertSame(['Delivering', 'Juan D. Cruz', '639171234567'], [$row->status, $row->rider_name, $row->rider_phone]);
        }
        $old = DB::table('jnt_latest_scans')->where('waybill', 'JT0000000002')->sole();
        $this->assertSame(['Arrival', '2026-10-17 21:20:00'], [$old->scan_type, substr((string) $old->checked_at, 0, 19)], 'hindi "bago" ang hindi nasagot');
        $this->assertSame(1, (int) DB::table('jnt_track_sync_runs')->value('skipped'));
    }
}

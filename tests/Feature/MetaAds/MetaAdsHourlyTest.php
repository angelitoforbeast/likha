<?php

namespace Tests\Feature\MetaAds;

use App\Models\UploadLog;
use App\Services\MetaAds\HourlySpend;
use App\Services\MetaAds\Sync;
use Carbon\Carbon;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Spec 288 TL-2. meta-ads:hourly reads Meta's hourly breakdown (one request per selected ad account per day) and keeps
 * spend per page per hour in cpp_snapshots (H00..H23, cumulative to the end of the hour). Write mode only; a rate limit
 * or block skips the run without retrying; a backfill pauses between requests. No real Meta call.
 */
class MetaAdsHourlyTest extends MetaAdsTestCase
{
    private const DAY = '2026-10-20';

    /** @var list<array{path: string, query: array}> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => ['database/migrations/2026_05_13_120000_create_cpp_snapshots_table.php']]);
        $this->travelTo(Carbon::parse('2026-10-21 10:10:00', 'Asia/Manila'));
        // The synced report knows which page each ad belongs to.
        foreach (['a1' => 'Page P1', 'a2' => 'Page P1', 'b1' => 'Page P2'] as $ad => $page) {
            DB::table('ads_manager_reports')->insert(['day' => self::DAY, 'ad_id' => $ad, 'page_name' => $page, 'amount_spent_php' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private static function hour(string $ad, int $h, float $spend, int $impr, int $msgs = 0): array
    {
        return ['ad_id' => $ad, 'spend' => (string) $spend, 'impressions' => (string) $impr,
            'actions' => $msgs ? [['action_type' => 'onsite_conversion.messaging_conversation_started_7d', 'value' => (string) $msgs]] : [],
            'hourly_stats_aggregated_by_advertiser_time_zone' => sprintf('%02d:00:00 - %02d:59:59', $h, $h),
            'date_start' => self::DAY, 'date_stop' => self::DAY];
    }

    /** @var array<string, list<array>|int> account => rows, or a Graph error code to answer */
    private array $answers = [];
    private bool $faked = false;

    /** @param array<string, list<array>|int> $byAccount account => rows, or a Graph error code to answer */
    private function graph(array $byAccount): void
    {
        // One fake for the whole test: a second Http::fake would sit behind the first, so later answers are swapped in.
        $this->answers = $byAccount;
        if ($this->faked) return;
        $this->faked = true;
        Http::fake(function (Request $req) {
            $byAccount = $this->answers;
            $path = trim(preg_replace('#^/v25\.0#', '', (string) parse_url($req->url(), PHP_URL_PATH)), '/');
            parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);
            $this->calls[] = ['path' => $path, 'query' => $q];
            if (preg_match('#^act_(\d+)/insights$#', $path, $m)) {
                $a = $byAccount[$m[1]] ?? [];
                if (is_int($a)) return Http::response(['error' => ['message' => 'Error ' . $a, 'code' => $a]], 400);
                return Http::response(['data' => $a]);
            }

            return Http::response(['error' => ['message' => 'unexpected ' . $path, 'code' => 100]], 400);
        });
    }

    private function ready(): void
    {
        Sync::setWriteMode(true);
        Sync::setAccounts(['111', '222']);
        $this->connect($this->user('Marketing', 'ana@example.test'), self::TOKEN_ANA, ['111', '222']);
    }

    /** page => [bucket => [spend, cpi, cpm]] */
    private function stored(): array
    {
        $out = [];
        foreach (DB::table('cpp_snapshots')->orderBy('page_name')->orderBy('snapshot_bucket')->get() as $r) {
            $out[$r->page_name][$r->snapshot_bucket] = [(float) $r->amount_spent, $r->cpi === null ? null : (float) $r->cpi, $r->cpm === null ? null : (float) $r->cpm];
        }

        return $out;
    }

    public function test_TL2_one_request_per_account_and_day_stores_cumulative_spend_per_page_and_hour(): void
    {
        $this->ready();
        $this->graph([
            '111' => [self::hour('a1', 9, 100, 1000, 5), self::hour('a1', 10, 50, 500), self::hour('a2', 10, 30, 300, 3), self::hour('zz', 1, 7, 70)],
            '222' => [self::hour('b1', 23, 20, 400, 2)],
        ]);

        Artisan::call('meta-ads:hourly', ['--date' => self::DAY]);

        $this->assertSame(['act_111/insights', 'act_222/insights'], array_column($this->calls, 'path'), 'one request per account');
        $q = $this->calls[0]['query'];
        $this->assertSame(['ad', 'hourly_stats_aggregated_by_advertiser_time_zone', 'ad_id,spend,impressions,actions', '{"since":"2026-10-20","until":"2026-10-20"}'],
            [$q['level'], $q['breakdowns'], $q['fields'], $q['time_range']]);
        $this->assertSame([['field' => 'spend', 'operator' => 'GREATER_THAN', 'value' => 0]], json_decode($q['filtering'], true));

        $s = $this->stored();
        $this->assertSame(['Page P1', 'Page P2', 'Unknown ad'], array_keys($s));
        $this->assertSame([100.0, 100.0, 20.0], $s['Page P1']['H09'], 'cumulative to 09:59: ₱100, CPI ₱100 per 1k impressions, CPM ₱20 per message');
        $this->assertSame([180.0, 100.0, 22.5], $s['Page P1']['H10'], '₱180 over 1,800 impressions and 8 messages');
        $this->assertSame([180.0, 100.0, 22.5], $s['Page P1']['H23'], 'later hours carry the day so far');
        $this->assertArrayNotHasKey('H08', $s['Page P1'], 'no row before the first spend');
        $this->assertSame(['H23'], array_keys($s['Page P2']));
        $this->assertCount(23, $s['Unknown ad'], 'an ad the report does not know yet still counts, on its own row');
        $row = DB::table('cpp_snapshots')->where('page_name', 'Page P1')->where('snapshot_bucket', 'H10')->first();
        $this->assertSame([0, null, 'pagep1', null], [(int) $row->orders, $row->cpp, $row->page_key, $row->saved_by_user_id], 'orders are counted live by the page, not stored');
        $log = UploadLog::where('type', HourlySpend::LOG_TYPE)->first();
        $this->assertSame(['done', 39], [$log->status, (int) $log->processed_rows]);
    }

    public function test_TL2_today_stops_at_the_current_hour_and_a_rerun_replaces_the_day(): void
    {
        $this->ready();
        $this->travelTo(Carbon::parse(self::DAY . ' 11:10:00', 'Asia/Manila'));
        $this->graph(['111' => [self::hour('a1', 9, 100, 1000), self::hour('a1', 12, 40, 400)], '222' => []]);
        Artisan::call('meta-ads:hourly', ['--scheduled' => true]);
        $this->assertSame(['H09', 'H10', 'H11'], array_keys($this->stored()['Page P1']), 'nothing after the current hour (11)');

        $this->graph(['111' => [self::hour('a2', 10, 30, 300)], '222' => []]);
        Artisan::call('meta-ads:hourly', ['--scheduled' => true]);
        $this->assertSame(['H10' => [30.0, 100.0, null], 'H11' => [30.0, 100.0, null]], $this->stored()['Page P1'], 'the day is replaced, not added to');
        $this->assertSame(2, DB::table('cpp_snapshots')->count());
    }

    public function test_TL2_the_scheduled_run_at_0010_also_closes_yesterday(): void
    {
        $this->ready();
        $this->travelTo(Carbon::parse('2026-10-21 00:10:00', 'Asia/Manila'));
        $this->graph(['111' => [], '222' => []]);

        Artisan::call('meta-ads:hourly', ['--scheduled' => true]);

        $days = array_map(fn ($c) => json_decode($c['query']['time_range'], true)['since'], $this->calls);
        $this->assertSame(['2026-10-20', '2026-10-20', '2026-10-21', '2026-10-21'], $days);
    }

    public function test_TL2_nothing_is_read_when_write_mode_is_off(): void
    {
        $this->ready();
        Sync::setWriteMode(false);
        $this->graph(['111' => [self::hour('a1', 9, 100, 1000)]]);

        Artisan::call('meta-ads:hourly', ['--date' => self::DAY]);

        $this->assertSame([], $this->calls);
        $this->assertSame(0, DB::table('cpp_snapshots')->count());
        $log = UploadLog::where('type', HourlySpend::LOG_TYPE)->first();
        $this->assertSame(['skipped', 'Write mode is off.'], [$log->status, $log->error_message]);
    }

    public function test_TL2_a_rate_limit_or_block_skips_the_run_without_retrying_and_keeps_what_was_stored(): void
    {
        $this->ready();
        $this->graph(['111' => [self::hour('a1', 9, 100, 1000)], '222' => []]);
        Artisan::call('meta-ads:hourly', ['--date' => self::DAY, '--pause' => 0]);
        $before = $this->stored();

        foreach ([17, 368] as $code) {
            $this->calls = [];
            $this->graph(['111' => $code, '222' => []]);
            Artisan::call('meta-ads:hourly', ['--date' => self::DAY, '--pause' => 0]);

            $this->assertSame(['act_111/insights'], array_column($this->calls, 'path'), "code {$code}: asked once, the run stops");
            $log = UploadLog::where('type', HourlySpend::LOG_TYPE)->orderByDesc('id')->first();
            $this->assertSame('skipped', $log->status);
            $this->assertStringStartsWith('act_111 2026-10-20: ' . ($code === 17 ? 'rate limited' : 'blocked') . ', run skipped', $log->error_message);
            $this->assertSame($before, $this->stored());
        }
        Sleep::assertNeverSlept();
    }

    public function test_TL2_a_backfill_pauses_between_requests_and_is_limited_to_31_days(): void
    {
        $this->ready();
        $this->graph(['111' => [], '222' => []]);

        Artisan::call('meta-ads:hourly', ['--from' => '2026-10-18', '--to' => '2026-10-20']);

        $this->assertCount(6, $this->calls, '2 accounts × 3 days');
        Sleep::assertSleptTimes(5);
        Sleep::assertSequence(array_fill(0, 5, Sleep::for(3)->seconds()));

        $this->calls = [];
        $this->assertSame(1, Artisan::call('meta-ads:hourly', ['--from' => '2026-09-01', '--to' => '2026-10-20']));
        $this->assertSame([], $this->calls);
    }

    public function test_TL2_the_scheduler_registers_the_hourly_read_at_ten_past_every_hour_behind_write_mode(): void
    {
        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'meta-ads:hourly --scheduled'));

        $this->assertSame(['10 * * * *', 'Asia/Manila'], [$event->expression, $event->timezone]);
        $this->assertFalse($event->filtersPass($this->app));
        Sync::setWriteMode(true);
        $this->assertTrue($event->filtersPass($this->app));
    }
}

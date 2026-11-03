<?php

namespace Tests\Feature\Item;

use App\Services\Stock\JntReturnedSource;
use App\Services\Stock\NexusRtsReturnedSource;
use App\Services\Stock\ReturnedResult;
use App\Services\Stock\ReturnedSource;
use App\Services\Stock\ReturnedSources;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Returned pieces (J&T 'Returned', ayon sa signingtime) at ang switch ng source (app_settings.stock_returned_source).
 * START 2026-10-02; "ngayon" 2026-11-03 10:10 Manila.
 */
class ReturnedStockTest extends ItemTestCase
{
    private const START = '2026-10-02';

    /** @var array<int,string> */
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-03 10:10:00', 'Asia/Manila'));
        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logs[] = $e->message . ' ' . json_encode($e->context);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function returned(): ReturnedResult
    {
        return app(JntReturnedSource::class)->returned(self::START, '2026-11-03 10:10:00');
    }

    /** Isang parcel: order + J&T row (shipped noong $shippedAt, may status at signingtime). */
    private function parcel(string $item, string $wb, string $shippedAt = '2026-10-05 09:00:00', ?string $status = 'Returned',
                            ?string $signedAt = '2026-10-12 14:00:00', ?string $moStatus = 'PROCEED'): void
    {
        $this->order($item, 'Page A', $wb, substr($shippedAt, 0, 10), $moStatus);
        $this->shipped($wb, $shippedAt, $status, $signedAt);
    }

    public function test_S_220_4_1_and_4_2_a_returned_parcel_counts_its_pieces_by_the_prefix_and_no_prefix_is_one(): void
    {
        $this->parcel('2 x GLOW TAPE', 'W1');
        $this->parcel('ROPE', 'W2');

        $r = $this->returned();

        $this->assertTrue($r->available);
        $this->assertTrue($r->likhaKeys);
        $this->assertSame(['glow tape' => 2, 'rope' => 1], $r->pieces);
    }

    public function test_S_220_4_3_for_return_is_not_counted(): void
    {
        $this->parcel('1 x GLOW TAPE', 'W1', status: 'For Return', signedAt: null);
        $this->parcel('1 x GLOW TAPE', 'W2', status: 'For Return', signedAt: '2026-10-12 14:00:00');

        $r = $this->returned();
        $this->assertSame([], $r->pieces);
        $this->assertSame(0, app(JntReturnedSource::class)->flags('2026-11-03 10:10:00')['unsigned'], "'For Return' ay hindi rin binibilang bilang walang signingtime");
    }

    public function test_S_222_2_1_and_2_2_a_late_return_counts_whatever_the_ship_date_and_one_signed_before_the_start_does_not(): void
    {
        // Na-ship bago ang START, bumalik pagkatapos: bilang (kahit kailan na-ship).
        $this->parcel('1 x GLOW TAPE', 'W1', shippedAt: '2026-09-20 23:59:59', signedAt: '2026-10-08 10:00:00');
        $this->parcel('1 x GLOW TAPE', 'W2', shippedAt: '2026-10-05 09:00:00');
        DB::table('from_jnts')->insert(['waybill_number' => 'W2', 'submission_time' => '2026-09-30 08:00:00', 'status' => 'In Transit']);
        DB::table('from_jnts')->insert(['waybill_number' => 'W2', 'submission_time' => '2026-10-05 09:00:00', 'status' => 'Returned', 'signingtime' => '2026-10-12 14:00:00']);
        // Bumalik bago ang START: hindi bilang.
        $this->parcel('1 x ROPE', 'W3', shippedAt: '2026-09-20 09:00:00', signedAt: '2026-10-01 23:59:59');

        $r = $this->returned();

        $this->assertSame(['glow tape' => 2], $r->pieces);
        $this->assertSame(['glow tape' => ['2026-10-08' => 1, '2026-10-12' => 1]], $r->byDay);
    }

    public function test_S_220_4_5_a_cancelled_order_is_not_counted(): void
    {
        $this->parcel('1 x GLOW TAPE', 'W1', moStatus: 'CANNOT PROCEED');
        $this->parcel('1 x GLOW TAPE', 'W2', moStatus: 'ODZ');

        $this->assertSame([], $this->returned()->pieces);
    }

    public function test_S_220_4_6_returned_without_signingtime_is_not_counted_and_the_count_is_logged(): void
    {
        $this->parcel('1 x GLOW TAPE', 'W1', signedAt: null);
        $this->parcel('1 x GLOW TAPE', 'W2');

        $r = $this->returned();

        $this->assertSame(['glow tape' => 1], $r->pieces);
        $this->assertSame(1, app(JntReturnedSource::class)->flags('2026-11-03 10:10:00')['unsigned'], 'hiwalay na COUNT query');
        $this->assertNotEmpty(array_filter($this->logs, fn ($l) => str_contains($l, 'signingtime') && str_contains($l, '"unsigned":1')));
    }

    public function test_S_220_4_7_several_from_jnts_rows_count_once_and_the_latest_row_sets_the_status(): void
    {
        $this->order('1 x GLOW TAPE', 'Page A', 'W1', '2026-10-05');
        DB::table('from_jnts')->insert(['waybill_number' => 'W1', 'submission_time' => '2026-10-05 09:00:00', 'status' => 'In Transit']);
        DB::table('from_jnts')->insert(['waybill_number' => 'W1', 'submission_time' => '2026-10-05 09:00:00', 'status' => 'Returned', 'signingtime' => '2026-10-12 14:00:00']);
        DB::table('from_jnts')->insert(['waybill_number' => 'W1', 'submission_time' => '2026-10-05 09:00:00', 'status' => 'Returned', 'signingtime' => '2026-10-12 14:00:00']);
        // Ang naunang row ay Returned pero ang pinakabago ay hindi: hindi bilang.
        $this->order('1 x ROPE', 'Page A', 'W2', '2026-10-05');
        DB::table('from_jnts')->insert(['waybill_number' => 'W2', 'submission_time' => '2026-10-05 09:00:00', 'status' => 'Returned', 'signingtime' => '2026-10-12 14:00:00']);
        DB::table('from_jnts')->insert(['waybill_number' => 'W2', 'submission_time' => '2026-10-05 09:00:00', 'status' => 'Delivered', 'signingtime' => '2026-10-12 14:00:00']);

        $this->assertSame(['glow tape' => 1], $this->returned()->pieces);
    }

    public function test_S_220_4_8_a_flip_away_from_returned_drops_it_on_the_next_read(): void
    {
        $this->parcel('1 x GLOW TAPE', 'W1');
        $this->assertSame(['glow tape' => 1], $this->returned()->pieces);
        $this->assertSame(['glow tape' => 1], $this->returned()->pieces, 'hindi dumodoble sa ikalawang basa');

        DB::table('from_jnts')->where('waybill_number', 'W1')->update(['status' => 'Delivered']);

        $this->assertSame([], $this->returned()->pieces);
    }

    public function test_S_220_4_9_a_future_signingtime_is_ignored_and_flagged(): void
    {
        $this->parcel('1 x GLOW TAPE', 'W1', signedAt: '2026-11-03 10:10:00');   // ngayon mismo: bilang
        $this->parcel('1 x GLOW TAPE', 'W2', signedAt: '2026-11-03 10:10:01');   // hinaharap
        $this->parcel('1 x GLOW TAPE', 'W3', signedAt: '2027-01-01 00:00:00');

        $r = $this->returned();

        $this->assertSame(['glow tape' => 1], $r->pieces);
        $this->assertSame(2, app(JntReturnedSource::class)->flags('2026-11-03 10:10:00')['future'], 'hiwalay na COUNT query');
    }

    public function test_S_220_4_10_a_multi_row_waybill_counts_each_row_like_shipped(): void
    {
        $this->order('2 x GLOW TAPE', 'Page A', 'W1', '2026-10-05');
        $this->order('1 x GLOW TAPE', 'Page A', 'W1', '2026-10-05');
        $this->order('1 x ROPE', 'Page A', 'W1', '2026-10-05');
        $this->shipped('W1', '2026-10-05 09:00:00', 'Returned', '2026-10-12 14:00:00');

        $this->assertSame(['glow tape' => 3, 'rope' => 1], $this->returned()->pieces);
    }

    public function test_the_returned_query_is_one_grouped_query(): void
    {
        $this->parcel('1 x GLOW TAPE', 'W1');
        $queries = [];
        DB::listen(function ($q) use (&$queries) { $queries[] = $q->sql; });

        $this->returned();

        $this->assertCount(1, $queries);
        $this->assertStringContainsString('group by', strtolower($queries[0]));
    }

    public function test_S_220_5_1_the_default_is_jnt_and_the_migration_seeds_jnt(): void
    {
        $this->assertSame('jnt', DB::table('app_settings')->where('key', 'stock_returned_source')->value('value'));
        $cur = app(ReturnedSources::class)->current();
        $this->assertSame('jnt', $cur['source']->key());
        $this->assertNull($cur['warning']);

        DB::table('app_settings')->where('key', 'stock_returned_source')->delete();
        $this->assertSame('jnt', app(ReturnedSources::class)->current()['source']->key());
    }

    public function test_S_220_5_2_nexus_uses_the_nexus_source(): void
    {
        $fake = new class implements ReturnedSource {
            public function key(): string { return 'nexus'; }
            public function returned(string $start, string $until): ReturnedResult
            {
                return new ReturnedResult(true, ['Glow Tape' => 7], false);
            }
        };
        $this->app->instance(NexusRtsReturnedSource::class, $fake);
        $this->appSetting('stock_returned_source', 'nexus');

        $cur = app(ReturnedSources::class)->current();

        $this->assertSame($fake, $cur['source']);
        $this->assertSame('nexus', $cur['setting']);
        $this->assertNull($cur['warning']);
    }

    public function test_S_220_5_3_an_unknown_value_falls_back_to_jnt_with_a_warning(): void
    {
        foreach (['both', 'JNT ', ''] as $v) {
            $this->appSetting('stock_returned_source', $v);
            $cur = app(ReturnedSources::class)->current();
            $this->assertSame('jnt', $cur['source']->key(), $v);
        }
        $this->appSetting('stock_returned_source', 'both');
        $this->assertSame('unknown_source', app(ReturnedSources::class)->current()['warning']);
    }

    public function test_S_220_5_4_the_nexus_rts_stub_is_not_available(): void
    {
        $r = app(NexusRtsReturnedSource::class)->returned(self::START, '2026-11-03 10:10:00');

        $this->assertFalse($r->available);
        $this->assertSame([], $r->pieces);
        $this->assertSame('not available', $r->note);
    }

    public function test_S_220_5_5_the_seed_never_overwrites_the_owners_choice(): void
    {
        $this->appSetting('stock_returned_source', 'nexus');
        $migration = require base_path('database/migrations/2026_11_16_100100_seed_stock_returned_source_setting.php');

        $migration->up();

        $this->assertSame('nexus', DB::table('app_settings')->where('key', 'stock_returned_source')->value('value'));
        $this->assertSame(1, DB::table('app_settings')->where('key', 'stock_returned_source')->count());
    }

    public function test_S_220_5_8_the_compare_command_prints_both_sides_and_not_available_for_the_stub(): void
    {
        $this->appSetting('item_stock_start', self::START);
        $this->parcel('2 x GLOW TAPE', 'W1');
        $before = DB::table('app_settings')->count() . ':' . DB::table('from_jnts')->count();

        $code = Artisan::call('stock:returned-compare');
        $out  = Artisan::output();

        $this->assertSame(0, $code);
        $this->assertMatchesRegularExpression('/GLOW TAPE\s*\|\s*2\s*\|\s*not available/i', $out);
        $this->assertStringContainsString('jnt', $out);
        $this->assertSame($before, DB::table('app_settings')->count() . ':' . DB::table('from_jnts')->count());
    }
}

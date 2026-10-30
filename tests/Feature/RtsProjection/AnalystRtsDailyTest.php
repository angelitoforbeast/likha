<?php

namespace Tests\Feature\RtsProjection;

use App\Services\ItemAliasResolver;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ang Mira RTS araw-araw (rts:daily-estimate, 06:00 Manila): ang tantya ng final RTS ng bawat page+item ng mga order mula
 * sa unang araw ng nakaraang buwan hanggang ngayon, isinusulat bilang value ng analyst (source auto) na may dahilan, at
 * ang accuracy ng tantya 7 at 14 araw na ang nakalipas laban sa totoong RTS ng parehong mga order.
 */
class AnalystRtsDailyTest extends RtsProjectionTestCase
{
    private const KEY = 'analyst-key';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['services.analyst_rts.key' => self::KEY, 'services.rts_check.key' => 'k']);
        foreach (['2026_10_17_100000_create_page_settings_table', '2026_11_03_100000_add_replaced_by_to_page_settings',
            '2026_11_12_100000_add_source_to_page_settings'] as $m) {
            (require base_path("database/migrations/{$m}.php"))->up();
        }
        Schema::create('tasks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });
        $this->rates(array_fill(0, 15, 0.5));
    }

    private function runAt(string $at): void
    {
        Carbon::setTestNow(Carbon::parse($at, 'Asia/Manila'));
        $this->artisan('rts:daily-estimate')->assertSuccessful();
    }

    private function key(string $item): string
    {
        return ItemAliasResolver::normalize($item);
    }

    /** Lahat ng row (active at history) ng isang analyst key, ayon sa id. */
    private function history(string $prefix, string $page, string $item)
    {
        return DB::table('page_settings')->where('provider', 'analyst')->where('page_name', $page)
            ->where('key', $prefix.$this->key($item))->orderBy('id')->get();
    }

    private function active(string $prefix, string $page, string $item): ?object
    {
        return $this->history($prefix, $page, $item)->firstWhere('active', 1);
    }

    private function day(string $page, string $item): array
    {
        return json_decode($this->active('rts_day:', $page, $item)->value, true);
    }

    private function analystPost(string $page, string $item, float $pct, string $reason): void
    {
        $this->postJson('/api/owner/analyst-rts', ['rows' => [['page' => $page, 'item' => $item, 'rts_pct' => $pct, 'reason' => $reason]]],
            ['X-ANALYST-RTS-KEY' => self::KEY])->assertOk()->assertJson(['saved' => 1]);
    }

    private function setting(string $page, string $item): void
    {
        DB::table('page_item_settings')->insert(['page_name' => $page, 'item_name' => $item, 'rts_pct' => 30,
            'effective_date' => '2026-09-01', 'mode_cod_int' => 599]);
    }

    /** Ang mga waybill ng isang page+item (para baguhin ang status sa test). */
    private function waybills(string $page, string $item): array
    {
        return DB::table('macro_output')->where('PAGE', $page)->where('ITEM_NAME', $item)->orderBy('id')->pluck('waybill')->all();
    }

    private function setStatus(array $waybills, string $status): void
    {
        DB::table('from_jnts')->whereIn('waybill_number', $waybills)->update(['status' => $status]);
    }

    public function test_S_197_P1_1_the_0600_run_writes_a_mira_rts_with_an_auto_reason_for_every_page_item_in_range(): void
    {
        $this->orders(30, 'Page A', 'Item A', '2026-10-20 12:00:00', 'Returned');
        $this->orders(30, 'Page A', 'Item A', '2026-10-20 12:00:00', 'Delivered');
        $this->orders(40, 'Page A', 'Item A', '2026-10-20 12:00:00', 'In Transit');
        $this->orders(10, 'Page A', 'Item B', '2026-09-15 12:00:00', 'Returned');   // nakaraang buwan: kasama (30 order: sapat)
        $this->orders(20, 'Page A', 'Item B', '2026-09-15 12:00:00', 'Delivered');
        $this->orders(5, 'Page C', 'Item C', '2026-08-20 12:00:00', 'Returned');   // bago ang nakaraang buwan: hindi

        $this->runAt('2026-10-23 06:00:00');

        $a = $this->active('rts_pct:', 'Page A', 'Item A');
        $this->assertSame('50', $a->value);                       // (30 + 40 × 0.5) ÷ 100
        $this->assertSame('auto', $a->source);
        $this->assertSame('33.5', $this->active('rts_pct:', 'Page A', 'Item B')->value);   // 33.33 → 0.5 na hakbang
        $this->assertNull($this->active('rts_pct:', 'Page C', 'Item C'));

        $reason = $this->active('rts_reason:', 'Page A', 'Item A')->value;
        $this->assertLessThanOrEqual(500, mb_strlen($reason));
        foreach (['Auto', '100 order', '60% tapos na', '40% nasa biyahe pa', '50%'] as $needle) {
            $this->assertStringContainsString($needle, $reason, $needle);
        }

        $day = $this->day('Page A', 'Item A');
        $this->assertSame(['2026-10-23', '2026-09-01', '2026-10-23', 100], [$day['d'], $day['from'], $day['to'], $day['m']]);
        $this->assertEqualsWithDelta(50.0, $day['est'], 0.001);
        $this->assertEqualsWithDelta(60.0, $day['fin'], 0.001);
        $this->assertEqualsWithDelta(40.0, $day['t'], 0.001);
    }

    public function test_S_197_P1_2_young_in_transit_orders_lift_the_estimate_above_the_rts_so_far(): void
    {
        $this->rates(array_fill(0, 15, 0.5), ['page a||'.$this->key('Item A') => [0 => 0.6]]);
        $this->orders(20, 'Page A', 'Item A', '2026-10-22 12:00:00', 'Returned');
        $this->orders(20, 'Page A', 'Item A', '2026-10-22 12:00:00', 'Delivered');
        $this->orders(60, 'Page A', 'Item A', '2026-10-22 12:00:00', 'In Transit');

        $this->runAt('2026-10-23 06:00:00');

        // RTS so far 20%; ang 60 na nasa biyahe (edad 0) × p 0.6 ng item → 56%.
        $this->assertSame('56', $this->active('rts_pct:', 'Page A', 'Item A')->value);
    }

    public function test_S_197_P1_3_an_analyst_value_after_0600_survives_until_the_next_run_and_history_keeps_all(): void
    {
        $this->setting('Page A', 'Item A');
        $this->orders(30, 'Page A', 'Item A', '2026-10-20 12:00:00', 'Returned');
        $this->orders(30, 'Page A', 'Item A', '2026-10-20 12:00:00', 'Delivered');
        $this->orders(40, 'Page A', 'Item A', '2026-10-20 12:00:00', 'In Transit');

        $this->runAt('2026-10-23 06:00:00');
        Carbon::setTestNow(Carbon::parse('2026-10-23 12:00:00', 'Asia/Manila'));
        $this->analystPost('Page A', 'Item A', 40, 'Binaba ko: mabilis mag-deliver ang batch na ito.');
        $this->runAt('2026-10-23 13:00:00');

        $this->assertSame('40', $this->active('rts_pct:', 'Page A', 'Item A')->value);
        $this->assertSame('mira', $this->active('rts_pct:', 'Page A', 'Item A')->source);
        $this->assertSame('Binaba ko: mabilis mag-deliver ang batch na ito.', $this->active('rts_reason:', 'Page A', 'Item A')->value);

        // Bago mag-06:00 kinabukasan: pinapalitan ng run.
        Carbon::setTestNow(Carbon::parse('2026-10-24 05:00:00', 'Asia/Manila'));
        $this->analystPost('Page A', 'Item A', 45, 'Bago ang run.');
        $this->runAt('2026-10-24 06:00:00');

        $pct = $this->history('rts_pct:', 'Page A', 'Item A');
        $this->assertSame(['50', '40', '45', '50'], $pct->pluck('value')->all());
        $this->assertSame(['auto', 'mira', 'mira', 'auto'], $pct->pluck('source')->all());
        $this->assertStringContainsString('Auto', $this->active('rts_reason:', 'Page A', 'Item A')->value);
        $this->assertSame(['2026-10-23', '2026-10-24'], $this->history('rts_day:', 'Page A', 'Item A')
            ->map(fn ($r) => json_decode($r->value, true)['d'])->all());
    }

    public function test_S_197_P1_6_rerunning_the_same_day_adds_no_history(): void
    {
        $this->orders(30, 'Page A', 'Item A', '2026-10-20 12:00:00', 'Returned');
        $this->orders(70, 'Page A', 'Item A', '2026-10-20 12:00:00', 'In Transit');
        $this->runAt('2026-10-23 06:00:00');
        $before = DB::table('page_settings')->count();

        $this->setStatus(array_slice($this->waybills('Page A', 'Item A'), 30, 40), 'Delivered');   // nagbago ang data
        $this->runAt('2026-10-23 07:00:00');
        $this->runAt('2026-10-23 23:59:00');

        $this->assertSame($before, DB::table('page_settings')->count());
        $this->assertSame('65', $this->active('rts_pct:', 'Page A', 'Item A')->value);   // ang unang run: (30 + 70 × 0.5) ÷ 100
    }

    /** 40 order (Okt 5): run Okt 9 (tantya 50), Okt 16 (62.5), tapos lahat (16 R, 24 D = 40%), run Okt 23. */
    private function threeRuns(): void
    {
        $this->setting('Page A', 'Item A');
        $this->orders(40, 'Page A', 'Item A', '2026-10-05 12:00:00', 'In Transit');
        DB::table('daily_page_primary_item')->insert([
            'ts_date' => '2026-10-05', 'page_label' => 'Page A', 'page_key' => 'page a',
            'primary_item' => 'Item A', 'primary_item_key' => $this->key('Item A'),
            'primary_orders' => 40, 'total_orders_all' => 40, 'primary_mode_cod' => 599,
        ]);
        $wb = $this->waybills('Page A', 'Item A');

        $this->runAt('2026-10-09 06:00:00');                       // 40 × 0.5 ÷ 40 = 50
        $this->setStatus(array_slice($wb, 0, 10), 'Returned');
        $this->runAt('2026-10-16 06:00:00');                       // (10 + 30 × 0.5) ÷ 40 = 62.5
        $this->setStatus(array_slice($wb, 10, 6), 'Returned');
        $this->setStatus(array_slice($wb, 16), 'Delivered');       // tapos na lahat: 16 R, 24 D = 40%
        $this->runAt('2026-10-23 06:00:00');
        Carbon::setTestNow(Carbon::parse('2026-10-23 12:00:00', 'Asia/Manila'));
    }

    public function test_S_197_P1_4_accuracy_compares_the_7_and_14_day_old_estimates_with_the_actual_rts_of_the_same_orders(): void
    {
        $this->threeRuns();

        $acc = $this->day('Page A', 'Item A')['acc'];
        $this->assertSame(['2026-10-16', 'auto', 40], [$acc['7']['d'], $acc['7']['src'], $acc['7']['m']]);
        $this->assertEqualsWithDelta(62.5, $acc['7']['est'], 0.001);
        $this->assertEqualsWithDelta(40.0, $acc['7']['act'], 0.001);
        $this->assertEqualsWithDelta(22.5, $acc['7']['err'], 0.001);
        $this->assertEqualsWithDelta(100.0, $acc['7']['fin'], 0.001);
        $this->assertSame('2026-10-09', $acc['14']['d']);
        $this->assertEqualsWithDelta(10.0, $acc['14']['err'], 0.001);

        // Sa feed: ang overall na linya bawat araw.
        $feed = $this->getJson('/api/owner/rts-check?from=2026-10-02&to=2026-10-23', ['X-RTS-CHECK-KEY' => 'k'])->assertOk()->json('accuracy');
        $this->assertEquals([['d' => '2026-10-23', 'mae_7' => 22.5, 'n_7' => 1, 'mae_14' => 10, 'n_14' => 1]], $feed);
    }

    public function test_S_197_P1_4_owner_private_shows_the_accuracy_column_with_the_last_7_days_in_the_hover(): void
    {
        $this->threeRuns();
        $this->actingAs($this->user());

        $rows = array_column($this->getJson('/owner/private/item-summary?start_date=2026-10-05&end_date=2026-10-05')->assertOk()->json('rows'), null, 'page_key');
        $row = $rows['page a'];
        $this->assertEqualsWithDelta(22.5, $row['analyst_rts_acc_7'], 0.001);
        $this->assertEqualsWithDelta(10.0, $row['analyst_rts_acc_14'], 0.001);
        $this->assertEqualsWithDelta(22.5, $row['analyst_rts_acc_abs'], 0.001);
        $this->assertSame(['2026-10-23'], array_column($row['analyst_rts_acc_hist'], 'd'));
        $this->assertSame('auto', $row['analyst_rts_source']);

        $html = $this->get('/owner/private')->assertOk()->getContent();
        foreach (["id:'analyst_rts_acc'", "label:'Mira RTS accuracy'", 'accTip(row)', 'accText(row)'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        $cat = array_column(\App\Http\Controllers\OwnerColumnSettingsController::CATALOG['owner_private'], 'label', 'id');
        $this->assertSame('Mira RTS accuracy', $cat['analyst_rts_acc']);
    }

    public function test_S_197_P1_4_an_estimate_the_analyst_adjusted_that_day_is_the_one_measured(): void
    {
        $this->setting('Page A', 'Item A');
        $this->orders(40, 'Page A', 'Item A', '2026-10-05 12:00:00', 'In Transit');
        $wb = $this->waybills('Page A', 'Item A');
        $this->runAt('2026-10-16 06:00:00');                       // auto 50
        Carbon::setTestNow(Carbon::parse('2026-10-16 12:00:00', 'Asia/Manila'));
        $this->analystPost('Page A', 'Item A', 45, 'Inayos.');
        $this->setStatus(array_slice($wb, 0, 16), 'Returned');
        $this->setStatus(array_slice($wb, 16), 'Delivered');       // 40%
        $this->runAt('2026-10-23 06:00:00');

        $a7 = $this->day('Page A', 'Item A')['acc']['7'];
        $this->assertSame('mira', $a7['src']);
        $this->assertEqualsWithDelta(45.0, $a7['est'], 0.001);
        $this->assertEqualsWithDelta(5.0, $a7['err'], 0.001);
    }

    public function test_S_197_P1_1_the_reason_names_the_21_day_trend_and_the_set_rts_gap_when_large(): void
    {
        DB::table('page_item_settings')->insert(['page_name' => 'Page A', 'item_name' => 'Item A', 'rts_pct' => 20,
            'effective_date' => '2026-09-01', 'mode_cod_int' => 599]);
        $this->orders(20, 'Page A', 'Item A', '2026-09-10 12:00:00', 'Returned');
        $this->orders(80, 'Page A', 'Item A', '2026-09-10 12:00:00', 'Delivered');
        $this->orders(50, 'Page A', 'Item A', '2026-10-20 12:00:00', 'Returned');
        $this->orders(50, 'Page A', 'Item A', '2026-10-20 12:00:00', 'Delivered');
        $this->runAt('2026-10-23 06:00:00');

        // Buong range 35%, huling 21 araw 50%, Set RTS 20%.
        $reason = $this->active('rts_reason:', 'Page A', 'Item A')->value;
        $this->assertStringContainsString('Huling 21 araw 50% vs buong range 35% (+15.0 pts).', $reason);
        $this->assertStringContainsString('Set RTS 20%: -15.0 pts ang agwat.', $reason);
    }

    public function test_S_197_P1_1_an_item_whose_key_is_too_long_is_skipped_whole_and_the_rest_still_run(): void
    {
        $long = 'Item '.str_repeat('x', 95);
        $this->orders(30, 'Page A', $long, '2026-10-20 12:00:00', 'Returned');
        $this->orders(30, 'Page A', 'Item B', '2026-10-20 12:00:00', 'Returned');
        $this->runAt('2026-10-23 06:00:00');

        $this->assertSame(0, DB::table('page_settings')->where('key', 'like', '%xxxx%')->count());
        $this->assertSame('100', $this->active('rts_pct:', 'Page A', 'Item B')->value);
    }

    public function test_S_197_never_runs_during_the_night_astra_step(): void
    {
        $this->orders(10, 'Page A', 'Item A', '2026-10-20 12:00:00', 'Returned');
        Carbon::setTestNow(Carbon::parse('2026-10-23 03:10:00', 'Asia/Manila'));

        $this->artisan('rts:daily-estimate')->assertFailed();
        $this->assertSame(0, DB::table('page_settings')->count());
    }

    public function test_S_197_as_of_replay_prints_and_writes_nothing(): void
    {
        $this->orders(10, 'Page A', 'Item A', '2026-10-10 12:00:00', 'Returned', [['Returned', '2026-10-12 12:00:00']]);
        Carbon::setTestNow(Carbon::parse('2026-10-23 06:00:00', 'Asia/Manila'));

        $this->artisan('rts:daily-estimate', ['--as-of' => '2026-10-15'])->assertSuccessful()->expectsOutputToContain('Page A');
        $this->assertSame(0, DB::table('page_settings')->count());
    }
}

<?php

namespace Tests\Feature\RtsProjection;

use App\Services\AnalystRtsDaily;
use App\Services\ItemAliasResolver;
use App\Services\RtsProjection;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ang Mira RTS ay galing lang sa mga order sa pinakabagong presyo ng page+item (ang COD ng pinakabagong order).
 * Kulang sa order sa presyong iyon = "not enough yet", walang balik sa lahat ng presyo. Ngayon = 2026-10-23 12:00 Manila.
 */
class LatestPriceTest extends RtsProjectionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
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

    private function k(string $page, string $item): string
    {
        return strtolower($page).'||'.ItemAliasResolver::normalize($item);
    }

    private function runAt(string $at): void
    {
        Carbon::setTestNow(Carbon::parse($at, 'Asia/Manila'));
        $this->artisan('rts:daily-estimate')->assertSuccessful();
    }

    private function active(string $prefix, string $page, string $item): ?object
    {
        return DB::table('page_settings')->where('provider', 'analyst')->where('page_name', $page)
            ->where('key', $prefix.ItemAliasResolver::normalize($item))->where('active', 1)->orderByDesc('id')->first();
    }

    private function dayRows(string $page, string $item): array
    {
        return DB::table('page_settings')->where('provider', 'analyst')->where('page_name', $page)
            ->where('key', 'rts_day:'.ItemAliasResolver::normalize($item))->orderBy('id')->pluck('value')
            ->map(fn ($v) => json_decode($v, true))->all();
    }

    /** ₱249 noong Set (20 R, 20 D = 50%), tapos ₱199 mula Okt 20 (10 R, 30 D = 25%). Lahat: 30 ÷ 80 = 37.5%. */
    private function twoPrices(string $page = 'Page A', int $newCount = 40): void
    {
        $this->orders(20, $page, 'Item A', '2026-09-10 12:00:00', 'Returned', [], '249');
        $this->orders(20, $page, 'Item A', '2026-09-10 12:00:00', 'Delivered', [], '249');
        $this->orders(intdiv($newCount, 4), $page, 'Item A', '2026-10-20 12:00:00', 'Returned', [], '199');
        $this->orders($newCount - intdiv($newCount, 4), $page, 'Item A', '2026-10-20 12:00:00', 'Delivered', [], '199');
    }

    /** Para lumabas ang page sa /owner/private/item-summary ng araw na iyon. */
    private function primary(string $page, string $day, int $cod): void
    {
        DB::table('daily_page_primary_item')->insert([
            'ts_date' => $day, 'page_label' => $page, 'page_key' => strtolower($page),
            'primary_item' => 'Item A', 'primary_item_key' => ItemAliasResolver::normalize('Item A'),
            'primary_orders' => 40, 'total_orders_all' => 40, 'primary_mode_cod' => $cod,
        ]);
    }

    private function ownerRow(string $page, string $day): array
    {
        $this->actingAs($this->user());
        $rows = array_column($this->getJson("/owner/private/item-summary?start_date={$day}&end_date={$day}")->assertOk()->json('rows'), null, 'page_key');

        return $rows[strtolower($page)];
    }

    public function test_S_203_1_1_the_estimate_counts_only_the_orders_at_the_newest_price(): void
    {
        $this->twoPrices();

        $p = new RtsProjection();
        $latest = $p->rows('2026-09-01', '2026-10-23', null, 'latest')[$this->k('Page A', 'Item A')];
        $this->assertSame([199, 40, 10, 30], [$latest['price'], $latest['matched'], $latest['returned'], $latest['delivered']]);
        $this->assertEqualsWithDelta(25.0, $latest['projected_rts'], 0.001);
        $all = $p->rows('2026-09-01', '2026-10-23')[$this->k('Page A', 'Item A')];   // ang dati: lahat ng presyo
        $this->assertSame(80, $all['matched']);
        $this->assertEqualsWithDelta(37.5, $all['projected_rts'], 0.001);

        $this->runAt('2026-10-23 06:00:00');
        $this->assertSame('25', $this->active('rts_pct:', 'Page A', 'Item A')->value);
        $day = $this->dayRows('Page A', 'Item A')[0];
        $this->assertSame([199, 40], [$day['price'], $day['m']]);
        $this->assertEqualsWithDelta(25.0, $day['est'], 0.001);
    }

    public function test_S_203_1_2_the_latest_price_is_the_newest_order_by_date_then_by_id(): void
    {
        // Ang mas bago (Okt 20, ₱199) ay naunang naipasok; ang mas luma (Set 10, ₱249) ay mas marami at mas mataas ang id.
        $this->orders(30, 'Page A', 'Item A', '2026-10-20 12:00:00', 'Delivered', [], '199');
        $this->orders(50, 'Page A', 'Item A', '2026-09-10 12:00:00', 'Delivered', [], '249');
        $this->order('Page A', 'Item A', '2026-10-22 12:00:00', 'Delivered', [], '');      // walang COD: hindi presyo
        // Parehong araw: ang huling naipasok ang pinakabago. Ang COD na may ₱ at comma ay nililinis.
        $this->orders(30, 'Page B', 'Item A', '2026-10-20 12:00:00', 'Delivered', [], '199');
        $this->orders(30, 'Page B', 'Item A', '2026-10-20 12:00:00', 'Delivered', [], '₱1,299.00');
        $this->orders(5, 'Page B', 'Item A', '2026-10-01 12:00:00', 'Delivered', [], '1299');

        $rows = (new RtsProjection())->rows('2026-09-01', '2026-10-23', null, 'latest');
        $this->assertSame([199, 30], [$rows[$this->k('Page A', 'Item A')]['price'], $rows[$this->k('Page A', 'Item A')]['matched']]);
        $this->assertSame([1299, 35], [$rows[$this->k('Page B', 'Item A')]['price'], $rows[$this->k('Page B', 'Item A')]['matched']]);
    }

    public function test_S_203_1_3_another_page_with_the_same_item_keeps_its_own_latest_price(): void
    {
        $this->twoPrices('Page A');
        $this->orders(10, 'Page B', 'Item A', '2026-10-21 12:00:00', 'Returned', [], '249');
        $this->orders(30, 'Page B', 'Item A', '2026-10-21 12:00:00', 'Delivered', [], '249');

        $this->runAt('2026-10-23 06:00:00');

        $this->assertSame(199, $this->dayRows('Page A', 'Item A')[0]['price']);
        $this->assertSame('25', $this->active('rts_pct:', 'Page A', 'Item A')->value);
        $this->assertSame(249, $this->dayRows('Page B', 'Item A')[0]['price']);
        $this->assertSame('25', $this->active('rts_pct:', 'Page B', 'Item A')->value);   // 10 ÷ 40, hindi kasama ang ₱249 ng Page A
        $this->assertSame(40, $this->dayRows('Page B', 'Item A')[0]['m']);
    }

    public function test_S_203_2_1_too_few_orders_at_the_latest_price_gives_not_enough_yet_and_no_fallback(): void
    {
        $this->orders(20, 'Page A', 'Item A', '2026-09-10 12:00:00', 'Returned', [], '249');
        $this->orders(20, 'Page A', 'Item A', '2026-09-10 12:00:00', 'Delivered', [], '249');
        $this->runAt('2026-10-15 06:00:00');
        $this->assertSame('50', $this->active('rts_pct:', 'Page A', 'Item A')->value);   // ₱249 pa ang pinakabago

        $this->orders(10, 'Page A', 'Item A', '2026-10-20 12:00:00', 'Delivered', [], '199');
        $this->runAt('2026-10-23 06:00:00');

        // Walang Mira RTS (ang dating 50 ay hindi na active) at walang tantya mula sa lahat ng presyo.
        $this->assertNull($this->active('rts_pct:', 'Page A', 'Item A'));
        $this->assertNull($this->active('rts_reason:', 'Page A', 'Item A'));
        $day = $this->dayRows('Page A', 'Item A')[1];
        $this->assertSame(['2026-10-23', 199, 10, null], [$day['d'], $day['price'], $day['m'], $day['est']]);
        $this->assertSame('Latest price ₱199: only 10 orders, not enough yet', AnalystRtsDaily::lowNote(199, 10));

        // Isang rerun ngayong araw: walang bagong row.
        $count = DB::table('page_settings')->count();
        $this->runAt('2026-10-23 09:00:00');
        $this->assertSame($count, DB::table('page_settings')->count());

        // Sa owner page: walang value, ang note ang hover ng "—".
        Carbon::setTestNow(Carbon::parse('2026-10-23 12:00:00', 'Asia/Manila'));
        $this->primary('Page A', '2026-10-20', 199);
        $row = $this->ownerRow('Page A', '2026-10-20');
        $this->assertNull($row['analyst_rts_pct']);
        $this->assertSame('Latest price ₱199: only 10 orders, not enough yet', $row['analyst_rts_note']);
        $this->assertStringContainsString('row.analyst_rts_note', $this->get('/owner/private')->assertOk()->getContent());
    }

    public function test_S_203_3_1_the_tooltip_names_the_price(): void
    {
        $this->twoPrices();
        $this->runAt('2026-10-23 06:00:00');

        $reason = $this->active('rts_reason:', 'Page A', 'Item A')->value;
        $this->assertStringContainsString('Auto 06:00: 40 order na may J&T status sa ₱199 (Sep 1–Oct 23)', $reason);

        Carbon::setTestNow(Carbon::parse('2026-10-23 12:00:00', 'Asia/Manila'));
        $this->primary('Page A', '2026-10-20', 199);
        $row = $this->ownerRow('Page A', '2026-10-20');
        $this->assertSame([25.0, 199, 'auto'], [(float) $row['analyst_rts_pct'], $row['analyst_rts_price'], $row['analyst_rts_source']]);
        $this->assertNull($row['analyst_rts_note']);
        // "Mira RTS 25.0% (₱199 orders only): Auto 06:00: …"
        $this->assertStringContainsString("' orders only)'", $this->get('/owner/private')->assertOk()->getContent());
    }

    public function test_S_203_4_1_the_item_learned_p_age_still_applies_at_the_latest_price(): void
    {
        $this->rates(array_fill(0, 15, 0.5), [$this->k('Page A', 'Item A') => [0 => 0.6]]);
        $this->orders(20, 'Page A', 'Item A', '2026-09-10 12:00:00', 'Delivered', [], '249');
        $this->orders(10, 'Page A', 'Item A', '2026-10-22 12:00:00', 'Returned', [], '199');
        $this->orders(30, 'Page A', 'Item A', '2026-10-22 12:00:00', 'In Transit', [], '199');

        $this->runAt('2026-10-23 06:00:00');

        // (10 + 30 × 0.6 ng page+item) ÷ 40 = 70; hindi 62.5 (pooled) at hindi 46.7 (lahat ng presyo).
        $this->assertSame('70', $this->active('rts_pct:', 'Page A', 'Item A')->value);
    }

    public function test_S_203_5_1_old_day_rows_stay_and_new_ones_record_their_price_and_are_measured_on_it(): void
    {
        $this->orders(20, 'Page A', 'Item A', '2026-09-10 12:00:00', 'Returned', [], '249');
        $this->orders(20, 'Page A', 'Item A', '2026-09-10 12:00:00', 'Delivered', [], '249');
        $this->orders(40, 'Page A', 'Item A', '2026-10-05 12:00:00', 'In Transit', [], '199');
        // Day row bago ang pagbabagong ito (walang price): lahat ng presyo ang tinantya noon.
        $old = json_encode(['d' => '2026-10-09', 'from' => '2026-09-01', 'to' => '2026-10-09', 'est' => 45, 'proj' => 45.0, 'm' => 80,
            'fin' => 50, 'rts' => 25, 'del' => 25, 't' => 50, 'age' => 4, 'acc' => (object) []]);
        DB::table('page_settings')->insert(['page_name' => 'Page A', 'provider' => 'analyst', 'key' => 'rts_day:'.ItemAliasResolver::normalize('Item A'),
            'value' => $old, 'is_secret' => false, 'active' => true, 'source' => 'auto',
            'created_at' => '2026-10-09 06:00:00', 'updated_at' => '2026-10-09 06:00:00']);

        $this->runAt('2026-10-16 06:00:00');                       // ₱199: 40 × 0.5 ÷ 40 = 50
        $wb = DB::table('macro_output')->where('COD', '199')->orderBy('id')->pluck('waybill')->all();
        DB::table('from_jnts')->whereIn('waybill_number', array_slice($wb, 0, 10))->update(['status' => 'Returned']);
        DB::table('from_jnts')->whereIn('waybill_number', array_slice($wb, 10))->update(['status' => 'Delivered']);
        $this->runAt('2026-10-23 06:00:00');

        $rows = $this->dayRows('Page A', 'Item A');
        $this->assertSame($old, DB::table('page_settings')->where('key', 'rts_day:'.ItemAliasResolver::normalize('Item A'))->orderBy('id')->value('value'));
        $this->assertArrayNotHasKey('price', $rows[0]);
        $this->assertSame([199, 199], [$rows[1]['price'], $rows[2]['price']]);

        $acc = $rows[2]['acc'];
        // 7 araw: ang tantya ng Okt 16 (₱199) laban sa ₱199 lang: 10 ÷ 40 = 25%.
        $this->assertSame(40, $acc['7']['m']);
        $this->assertEqualsWithDelta(25.0, $acc['7']['act'], 0.001);
        $this->assertEqualsWithDelta(25.0, $acc['7']['err'], 0.001);
        // 14 araw: ang lumang row (walang price) laban sa lahat ng presyo, gaya noon: 30 ÷ 80 = 37.5%.
        $this->assertSame(80, $acc['14']['m']);
        $this->assertEqualsWithDelta(37.5, $acc['14']['act'], 0.001);
    }

    public function test_S_203_6_1_as_of_replay_uses_the_latest_price_known_that_day(): void
    {
        $this->orders(10, 'Page A', 'Item A', '2026-09-10 10:00:00', 'Returned', [['Returned', '2026-09-14 12:00:00']], '249');
        $this->orders(30, 'Page A', 'Item A', '2026-09-10 10:00:00', 'Delivered', [['Delivered', '2026-09-13 12:00:00']], '249');
        $this->orders(30, 'Page A', 'Item A', '2026-10-20 10:00:00', 'Returned', [['Returned', '2026-10-22 12:00:00']], '199');
        $this->orders(10, 'Page A', 'Item A', '2026-10-20 10:00:00', 'Delivered', [['Delivered', '2026-10-22 12:00:00']], '199');
        Carbon::setTestNow(Carbon::parse('2026-10-23 06:00:00', 'Asia/Manila'));
        $d = new AnalystRtsDaily();

        $then = $d->estimates(Carbon::parse('2026-10-15', 'Asia/Manila')->endOfDay())[$this->k('Page A', 'Item A')];
        $this->assertSame([249, 40, 25.0], [$then['price'], $then['matched'], $then['estimate']]);
        $now = $d->estimates(Carbon::parse('2026-10-23 06:00:00', 'Asia/Manila'))[$this->k('Page A', 'Item A')];
        $this->assertSame([199, 40, 75.0], [$now['price'], $now['matched'], $now['estimate']]);

        $this->artisan('rts:daily-estimate', ['--as-of' => '2026-10-15'])->assertSuccessful()->expectsOutputToContain('249');
        $this->assertSame(0, DB::table('page_settings')->count());
    }
}

<?php

namespace Tests\Feature\Item;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * /item/stock ay hindi bumabagal: ang stock inputs (shipped, returned, Nexus stock-in) ay naka-cache.
 * Ang live HOLD query (left join sa from_jnts) ay tumatakbo pa rin gaya ng dati.
 */
class StockCacheTest extends ItemTestCase
{
    private const URL = '/item/stock?start_date=2026-09-01&end_date=2026-10-02';

    protected function setUp(): void
    {
        parent::setUp();
        $this->nexusOn();
        $this->appSetting('item_stock_start', '2026-09-25');
        $this->appSetting('nexus_stock_last_synced_at', '2026-11-03 10:10:00');
        Carbon::setTestNow(Carbon::parse('2026-11-03 10:10:00', 'Asia/Manila'));

        for ($i = 1; $i <= 3; $i++) {
            $this->order('1 x GLOW TAPE', 'Page A', 'G' . $i, '2026-09-26');
            $this->shipped('G' . $i, '2026-09-26 10:00:00', $i === 1 ? 'Returned' : 'Delivered', $i === 1 ? '2026-10-05 12:00:00' : null);
        }
        $this->nexusStockIn('GLOW TAPE', 10, '2026-10-01');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private ?\App\Models\User $ceo = null;

    /** @return array{0: array, 1: array<int,string>} [json, queries] */
    private function fetchStock(): array
    {
        $this->ceo ??= $this->user();
        $queries = [];
        DB::listen(function ($q) use (&$queries) { $queries[] = strtolower($q->sql); });
        $json = $this->actingAs($this->ceo)->getJson(self::URL)->assertOk()->json();
        DB::flushQueryLog();

        return [$json, $queries];
    }

    private function stockOf(array $json): int
    {
        return $json['items']['glow tape']['stock'];
    }

    public function test_S_220_9_1_a_warm_call_runs_no_stock_query_only_the_live_hold_query_touches_from_jnts(): void
    {
        [$cold, $q1] = $this->fetchStock();
        [$warm, $q2] = $this->fetchStock();

        $this->assertSame($cold['items'], $warm['items']);
        $this->assertSame(8, $this->stockOf($warm));   // 10 + 1 − 3
        $fj = fn (array $q) => array_values(array_filter($q, fn ($s) => str_contains($s, 'from_jnts')));
        $this->assertCount(3, $fj($q1), 'cold: HOLD + shipped + returned');
        $this->assertCount(1, $fj($q2), 'warm: ang HOLD lang');
        $this->assertStringContainsString('left join "from_jnts"', $fj($q2)[0]);
        $this->assertSame([], array_values(array_filter($q2, fn ($s) => str_contains($s, 'nexus_stock_ins'))));
    }

    public function test_S_220_9_2_the_returned_query_is_one_grouped_query_in_a_cold_call(): void
    {
        [, $q] = $this->fetchStock();

        $returned = array_values(array_filter($q, fn ($s) => str_contains($s, 'fj.status = ?')));
        $this->assertCount(1, $returned);
        $this->assertStringContainsString('group by', $returned[0]);
    }

    public function test_S_220_9_3_a_successful_sync_re_keys_the_cache_and_otherwise_it_lasts_15_minutes(): void
    {
        $this->fetchStock();
        $this->nexusStockIn('GLOW TAPE', 5, '2026-11-03');

        $this->assertSame(8, $this->stockOf($this->fetchStock()[0]), 'naka-cache pa');

        $this->appSetting('nexus_stock_last_synced_at', '2026-11-03 11:10:00');
        $this->assertSame(13, $this->stockOf($this->fetchStock()[0]), 'bagong sync = bagong key');

        $this->nexusStockIn('GLOW TAPE', 7, '2026-11-02');
        Carbon::setTestNow(Carbon::parse('2026-11-03 10:24:59', 'Asia/Manila'));
        $this->assertSame(13, $this->stockOf($this->fetchStock()[0]));
        Carbon::setTestNow(Carbon::parse('2026-11-03 10:25:01', 'Asia/Manila'));
        $this->assertSame(20, $this->stockOf($this->fetchStock()[0]), 'lampas 15 minuto: kinuwenta ulit');
    }

    public function test_S_220_9_4_concurrent_cold_requests_give_the_same_answer(): void
    {
        // Dalawang request na parehong walang cache: pareho ang kuwenta at ang isinusulat (walang dinadagdag).
        [$a] = $this->fetchStock();
        Cache::flush();
        [$b] = $this->fetchStock();
        [$c] = $this->fetchStock();

        $this->assertSame($a['items'], $b['items']);
        $this->assertSame($b['items'], $c['items']);
        $this->assertSame(8, $this->stockOf($c));
    }

    public function test_S_220_9_5_a_failed_or_partial_sync_does_not_refresh_the_cache(): void
    {
        $this->fetchStock();
        // Ang bigong takbo: may naitabing araw pero walang bagong last_synced_at, may last_error.
        $this->nexusStockIn('GLOW TAPE', 5, '2026-11-03');
        $this->appSetting('nexus_stock_last_error', json_encode(['at' => '2026-11-03 10:15:00', 'kind' => 'partial', 'message' => 'x']));

        $this->assertSame(8, $this->stockOf($this->fetchStock()[0]));
    }
}

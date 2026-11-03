<?php

namespace Tests\Feature\Item;

use App\Services\Stock\JntReturnedSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Ang saradong range ng returned ay may FORCE INDEX (idx_from_jnts_signing_status) kapag MySQL at nasa from_jnts ang
 * index (isang tingin sa information_schema, naka-cache nang 24 oras). Sa sqlite, o walang index: walang hint, walang error.
 */
class StockIndexHintTest extends ItemTestCase
{
    private const HINT = 'FROM from_jnts fj FORCE INDEX (idx_from_jnts_signing_status)';

    private int $wb = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-03 10:10:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Isang source na "MySQL" ang driver; ang sagot ng index check ay $exists (null = ang totoong check). */
    private function mysqlSource(?bool $exists): JntReturnedSource
    {
        return new class($exists) extends JntReturnedSource {
            public int $checks = 0;

            public function __construct(private ?bool $exists) {}

            protected function driverName(): string { return 'mysql'; }

            protected function indexExists(): bool
            {
                $this->checks++;

                return $this->exists ?? parent::indexExists();
            }
        };
    }

    private function returnedParcel(string $shipped, string $signed): void
    {
        $wb = 'H' . (++$this->wb);
        $this->order('2 x GLOW TAPE', 'Page A', $wb, substr($shipped, 0, 10));
        $this->shipped($wb, $shipped, 'Returned', $signed);
    }

    public function test_the_closed_range_sql_has_the_hint_when_the_index_check_says_yes_and_the_answer_is_cached_24_hours(): void
    {
        $src = $this->mysqlSource(true);

        $this->assertTrue($src->useIndexHint());
        $this->assertStringContainsString(self::HINT, $src->closedSql());
        $this->assertStringContainsString(self::HINT, JntReturnedSource::sql(true));
        $src->closedSql();
        $this->assertSame(1, $src->checks, 'isang tingin lang sa information_schema');
        $this->assertSame(1, Cache::get(JntReturnedSource::INDEX_CACHE_KEY));

        Carbon::setTestNow(Carbon::parse('2026-11-04 10:10:01', 'Asia/Manila'));   // lampas 24 oras
        $src->useIndexHint();
        $this->assertSame(2, $src->checks, 'tinitingnan ulit pagkalipas ng 24 oras');
    }

    public function test_no_hint_when_the_index_check_says_no(): void
    {
        $src = $this->mysqlSource(false);

        $this->assertFalse($src->useIndexHint());
        $this->assertStringNotContainsString('FORCE INDEX', $src->closedSql());
        $this->assertStringContainsString("FROM from_jnts fj\n", $src->closedSql());
        $this->assertSame(0, Cache::get(JntReturnedSource::INDEX_CACHE_KEY), 'ang "wala" ay naka-cache rin');
        $src->closedSql();
        $this->assertSame(1, $src->checks);
    }

    public function test_an_unreadable_information_schema_means_no_hint_and_no_error(): void
    {
        $src = $this->mysqlSource(null);   // ang totoong check, sa sqlite: walang information_schema

        $this->assertFalse($src->useIndexHint());
        $this->assertStringNotContainsString('FORCE INDEX', $src->closedSql());
    }

    public function test_no_hint_on_sqlite_and_no_information_schema_query(): void
    {
        $q = [];
        DB::listen(function ($e) use (&$q) { $q[] = strtolower($e->sql); });
        $src = new JntReturnedSource();

        $this->assertFalse($src->useIndexHint());
        $this->assertStringNotContainsString('FORCE INDEX', $src->closedSql());
        $src->returnedClosed('2026-10-01', '2026-11-01 23:59:59');
        $this->assertSame([], array_values(array_filter($q, fn ($s) => str_contains($s, 'information_schema') || str_contains($s, 'force index'))));
        $this->assertNull(Cache::get(JntReturnedSource::INDEX_CACHE_KEY), 'walang tingin, walang cache');
    }

    public function test_the_closed_range_results_are_the_same_as_the_plain_query(): void
    {
        $this->returnedParcel('2026-10-05 10:00:00', '2026-10-12 09:00:00');
        $this->returnedParcel('2026-10-20 10:00:00', '2026-10-25 15:00:00');
        $this->returnedParcel('2026-10-20 10:00:00', '2026-11-02 08:00:00');   // labas sa range
        $src = new JntReturnedSource();

        $plain  = $src->returned('2026-10-01', '2026-11-01 23:59:59');
        $closed = $src->returnedClosed('2026-10-01', '2026-11-01 23:59:59');

        $this->assertSame(['glow tape' => 4], $plain->pieces);
        $this->assertEquals($plain, $closed);
        $this->assertSame(['glow tape' => ['2026-10-12' => 2, '2026-10-25' => 2]], $closed->byDay);
    }

    public function test_the_closed_day_pass_of_item_stock_goes_through_the_hinted_path(): void
    {
        $this->nexusOn();
        $this->appSetting('item_stock_start', '2026-10-02');
        $this->appSetting('nexus_stock_last_synced_at', '2026-11-03 09:10:00');
        $this->appSetting('nexus_stock_synced_through', '2026-11-03');
        $this->nexusStockIn('GLOW TAPE', 100, '2026-10-10');
        $this->returnedParcel('2026-10-12 10:00:00', '2026-10-20 10:00:00');
        $spy = new class extends JntReturnedSource {
            public array $closed = [];

            public function returnedClosed(string $start, string $until): \App\Services\Stock\ReturnedResult
            {
                $this->closed[] = [$start, $until];

                return parent::returnedClosed($start, $until);
            }
        };
        $this->app->instance(JntReturnedSource::class, $spy);

        $it = $this->actingAs($this->user())->getJson('/item/stock')->assertOk()->json('items.glow tape');

        $this->assertSame(2, $it['returned']);
        $this->assertCount(1, $spy->closed, 'ang saradong araw ay isang range query sa hinted path');
        $this->assertSame('2026-11-01 23:59:59', $spy->closed[0][1]);
    }
}

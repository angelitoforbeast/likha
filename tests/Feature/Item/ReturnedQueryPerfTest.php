<?php

namespace Tests\Feature\Item;

use App\Services\ItemStockService;
use App\Services\Stock\JntReturnedSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sukat ng cold /item stock sa MySQL na may dami ng production. HINDI bahagi ng karaniwang suite: skipped maliban kung
 * PERF_220=1 at nakatakda ang LAHAT ng PERF_DB_HOST / PERF_DB_PORT / PERF_DB_USER / PERF_DB_PASSWORD / PERF_DB_DATABASE ng isang
 * itatapong MySQL (hal. Docker); walang default, at tinatanggihan kapag ito ang database ng app. Binubura at ginagawa
 * ulit nito ang mga table sa database na iyon.
 *
 * Dami (Jan–Nov 2026): ~3,000 J&T waybill kada araw (~920k from_jnts rows, 3% may dobleng row), ~25% Returned na
 * may signingtime, ~0.5% Returned na walang signingtime; macro_output = isang row kada waybill + 8% pangalawang row
 * + 4% HOLD (walang J&T), 300 item na may "N x" na variant.
 */
class ReturnedQueryPerfTest extends TestCase
{
    private const ENV = ['PERF_DB_HOST', 'PERF_DB_PORT', 'PERF_DB_USER', 'PERF_DB_PASSWORD', 'PERF_DB_DATABASE'];

    /**
     * Ang target ng perf run, o ang dahilan kung bakit tinatanggihan: kulang na PERF_DB_* (walang default), o
     * pareho ang host, port at database ng isang connection ng app mismo (hindi ito tatakbo sa totoong database).
     *
     * @param array<string,string|false> $env
     * @param array<string,array> $connections config('database.connections')
     * @return array<string,string>|string
     */
    public static function perfTarget(array $env, array $connections): array|string
    {
        foreach (self::ENV as $name) {
            if (!is_string($env[$name] ?? false) || trim((string) $env[$name]) === '') return "$name is not set";
        }
        $host = fn ($h) => in_array(strtolower(trim((string) $h)), ['localhost', '127.0.0.1', '::1', '[::1]'], true) ? 'loopback' : strtolower(trim((string) $h));
        foreach ($connections as $name => $c) {
            if (!in_array($c['driver'] ?? '', ['mysql', 'mariadb', 'pgsql', 'sqlsrv'], true)) continue;
            if ($host($c['host'] ?? '') === $host($env['PERF_DB_HOST'])
                && (string) ($c['port'] ?? '') === trim((string) $env['PERF_DB_PORT'])
                && (string) ($c['database'] ?? '') === trim((string) $env['PERF_DB_DATABASE'])) {
                return "the target is the app's own database connection '$name'";
            }
        }

        return ['host' => trim((string) $env['PERF_DB_HOST']), 'port' => trim((string) $env['PERF_DB_PORT']),
                'username' => (string) $env['PERF_DB_USER'], 'password' => (string) $env['PERF_DB_PASSWORD'],
                'database' => trim((string) $env['PERF_DB_DATABASE'])];
    }

    private function usePerfDatabase(): void
    {
        if (getenv('PERF_220') !== '1') $this->markTestSkipped('perf only: set PERF_220=1 and every PERF_DB_*');
        $target = self::perfTarget(array_combine(self::ENV, array_map('getenv', self::ENV)), (array) config('database.connections'));
        if (is_string($target)) $this->fail("Refusing the perf run: $target.");

        config(['database.connections.perf' => $target + [
            'driver' => 'mysql', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true, 'engine' => null,
        ]]);
        DB::setDefaultConnection('perf');
        config(['services.nexus.token' => 'perf-not-a-token']);
        Carbon::setTestNow(Carbon::parse('2026-11-03 10:10:00', 'Asia/Manila'));
    }

    public function test_the_perf_target_needs_every_value_and_is_never_the_apps_own_database(): void
    {
        $env  = ['PERF_DB_HOST' => '127.0.0.1', 'PERF_DB_PORT' => '33220', 'PERF_DB_USER' => 'root', 'PERF_DB_PASSWORD' => 'x', 'PERF_DB_DATABASE' => 'perf'];
        $conn = ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:'],
                 'mysql'  => ['driver' => 'mysql', 'host' => 'localhost', 'port' => '3306', 'database' => 'likha']];

        $this->assertIsArray(self::perfTarget($env, $conn));
        foreach (self::ENV as $name) {
            $this->assertSame("$name is not set", self::perfTarget([$name => false] + $env, $conn), $name);
            $this->assertSame("$name is not set", self::perfTarget([$name => ' '] + $env, $conn), $name);
        }
        $same = ['PERF_DB_PORT' => '3306', 'PERF_DB_DATABASE' => 'likha'] + $env;   // 127.0.0.1 = localhost
        $this->assertSame("the target is the app's own database connection 'mysql'", self::perfTarget($same, $conn));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function seedVolume(): void
    {
        // PERF_220_KEEP=1: gamitin ulit ang naitanim na data ng naunang takbo.
        if (getenv('PERF_220_KEEP') === '1' && \Illuminate\Support\Facades\Schema::hasTable('nexus_stock_ins')) return;
        foreach (['from_jnts', 'macro_output', 'app_settings', 'nexus_stock_ins', 'seq'] as $t) DB::statement("DROP TABLE IF EXISTS $t");
        DB::statement('CREATE TABLE seq (n INT PRIMARY KEY)');
        DB::statement('INSERT INTO seq (n) WITH RECURSIVE s(n) AS (SELECT 0 UNION ALL SELECT n + 1 FROM s WHERE n < 999) SELECT n FROM s');

        DB::statement("CREATE TABLE from_jnts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, submission_time DATETIME NULL, waybill_number VARCHAR(255) NULL,
            receiver VARCHAR(255) NULL, sender VARCHAR(255) NULL, item_name VARCHAR(255) NULL, cod VARCHAR(255) NULL,
            status VARCHAR(255) NULL, signingtime DATETIME NULL, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
            INDEX from_jnts_waybill_number_index (waybill_number), INDEX idx_from_jnts_submission_time (submission_time),
            INDEX idx_from_jnts_signingtime (signingtime), INDEX idx_from_jnts_status (status),
            INDEX idx_from_jnts_signing_status (signingtime, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        DB::statement("CREATE TABLE macro_output (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, `ITEM_NAME` VARCHAR(255) NULL, `PAGE` VARCHAR(255) NULL,
            `TIMESTAMP` VARCHAR(255) NULL, `STATUS` VARCHAR(255) NULL, waybill VARCHAR(255) NULL, ts_date DATE NULL,
            INDEX macro_output_waybill_index (waybill), INDEX mo_ts_date (ts_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // ~920k waybills: k = 0..919,999, araw = k / 3000 mula 2026-01-01 (hanggang ~2026-11-02).
        for ($chunk = 0; $chunk < 920; $chunk++) {
            DB::statement("INSERT INTO from_jnts (submission_time, waybill_number, status, signingtime)
                SELECT DATE_ADD('2026-01-01 08:00:00', INTERVAL (k DIV 3000) DAY) + INTERVAL (k MOD 600) MINUTE,
                       CONCAT('JT', LPAD(k, 9, '0')),
                       CASE WHEN k MOD 200 = 7 THEN 'Returned' WHEN k MOD 4 = 0 THEN 'Returned' WHEN k MOD 4 = 1 THEN 'In Transit' ELSE 'Delivered' END,
                       CASE WHEN k MOD 200 = 7 THEN NULL WHEN k MOD 4 = 1 THEN NULL
                            ELSE DATE_ADD('2026-01-01 08:00:00', INTERVAL (k DIV 3000) + 6 DAY) END
                FROM (SELECT ? * 1000 + n AS k FROM seq) x", [$chunk]);
        }
        // 3% may naunang pangalawang row (luma, In Transit) — ang pinakabago pa rin ang may status.
        DB::statement("INSERT INTO from_jnts (submission_time, waybill_number, status, signingtime)
            SELECT submission_time, waybill_number, status, signingtime FROM from_jnts WHERE id MOD 33 = 0");
        DB::statement("UPDATE from_jnts SET status = 'In Transit', signingtime = NULL WHERE id MOD 33 = 0 AND id <= 920000");

        $items = "CONCAT(CASE WHEN k MOD 5 = 0 THEN '2 x ' WHEN k MOD 7 = 0 THEN '3 x ' ELSE '1 x ' END, 'ITEM ', LPAD(k MOD 300, 3, '0'))";
        for ($chunk = 0; $chunk < 920; $chunk++) {
            DB::statement("INSERT INTO macro_output (`ITEM_NAME`, `PAGE`, `TIMESTAMP`, `STATUS`, waybill, ts_date)
                SELECT $items, CONCAT('Page ', k MOD 60), '10:00 01-01-2026',
                       CASE WHEN k MOD 97 = 0 THEN 'CANNOT PROCEED' ELSE 'PROCEED' END,
                       CONCAT('JT', LPAD(k, 9, '0')), DATE_ADD('2026-01-01', INTERVAL (k DIV 3000) DAY)
                FROM (SELECT ? * 1000 + n AS k FROM seq) x", [$chunk]);
        }
        DB::statement("INSERT INTO macro_output (`ITEM_NAME`, `PAGE`, `TIMESTAMP`, `STATUS`, waybill, ts_date)
            SELECT CONCAT('1 x ITEM ', LPAD((id * 7) MOD 300, 3, '0')), `PAGE`, `TIMESTAMP`, 'PROCEED', waybill, ts_date FROM macro_output WHERE id MOD 12 = 0");
        DB::statement("INSERT INTO macro_output (`ITEM_NAME`, `PAGE`, `TIMESTAMP`, `STATUS`, waybill, ts_date)
            SELECT `ITEM_NAME`, `PAGE`, '10:00 25-10-2026', 'PROCEED', CONCAT('HOLD', id), '2026-10-25' FROM macro_output WHERE id MOD 25 = 0");

        $migrations = ['2026_04_10_000003_create_app_settings_table.php', '2026_11_16_100000_create_nexus_stock_ins_table.php',
                       '2026_11_16_100100_seed_stock_returned_source_setting.php'];
        foreach ($migrations as $m) (require base_path('database/migrations/' . $m))->up();
        DB::table('app_settings')->insert([
            ['key' => 'item_stock_start', 'value' => '2026-10-02'],
            ['key' => 'nexus_stock_last_synced_at', 'value' => '2026-11-03 10:10:00'],
        ]);
        $rows = [];
        foreach (\Carbon\CarbonPeriod::create('2026-10-02', '2026-11-03') as $d) {
            for ($i = 0; $i < 42; $i++) {
                $name = 'Item ' . str_pad((string) ($i * 7), 3, '0', STR_PAD_LEFT);
                $rows[] = ['stock_date' => $d->toDateString(), 'name_key' => sha1($name), 'item_name' => $name,
                           'stock_in_lots' => 1, 'stock_in_pieces' => 20, 'remaining' => 100, 'as_of' => $d->toDateString() . ' 10:00:00'];
            }
        }
        foreach (array_chunk($rows, 500) as $c) DB::table('nexus_stock_ins')->insert($c);
        DB::statement('ANALYZE TABLE from_jnts, macro_output, nexus_stock_ins');
    }

    private function median(callable $fn, int $runs = 5): float
    {
        $t = [];
        for ($i = 0; $i < $runs; $i++) {
            $s = hrtime(true);
            $fn();
            $t[] = (hrtime(true) - $s) / 1e6;
        }
        sort($t);

        return round($t[intdiv($runs, 2)], 1);
    }

    public function test_cold_time_of_the_returned_query_and_the_stock_inputs_on_production_volume(): void
    {
        $this->usePerfDatabase();
        $this->seedVolume();
        $until = '2026-11-03 10:10:00';
        $out = [
            'from_jnts rows'    => DB::table('from_jnts')->count(),
            'macro_output rows' => DB::table('macro_output')->count(),
            'Returned since start (latest row, signed)' => DB::table('from_jnts')->where('status', 'Returned')->where('signingtime', '>=', '2026-10-02')->count(),
        ];

        $src = app(JntReturnedSource::class);
        $out['returned query median ms (5 runs)'] = $this->median(fn () => $src->rows('2026-10-02', $until));
        $out['returned pieces total'] = array_sum($src->returned('2026-10-02', $until)->pieces);

        $svc = app(ItemStockService::class);
        $out['cold /item/stock build median ms (5 runs, cache flushed)'] = $this->median(function () use ($svc) {
            Cache::flush();
            $svc->build('2026-10-01', '2026-11-03', false);
        });
        $out['warm /item/stock build median ms (5 runs)'] = $this->median(fn () => $svc->build('2026-10-01', '2026-11-03', false));

        config(['services.nexus.token' => '']);
        $out['old path (PO received, no cache) build median ms'] = $this->median(fn () => $svc->build('2026-10-01', '2026-11-03', false));

        $explain = DB::select('EXPLAIN ' . JntReturnedSource::sql(), JntReturnedSource::bindings('2026-10-02', $until));
        $out['explain'] = array_map(fn ($r) => sprintf('%s %s type=%s key=%s rows=%s filtered=%s %s',
            $r->id, $r->table, $r->type, $r->key ?? '-', $r->rows, $r->filtered, $r->Extra ?? ''), $explain);

        fwrite(STDERR, "\nPERF_220 " . json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $this->assertGreaterThan(0, $out['returned pieces total']);
    }
}

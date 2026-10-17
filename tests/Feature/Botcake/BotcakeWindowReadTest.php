<?php

namespace Tests\Feature\Botcake;

use App\Jobs\SyncBotcakeCustomersJob;
use App\Models\BotcakePsid;
use App\Models\BotcakePsidImportRun;
use App\Services\BotcakeCustomerSync;
use App\Services\PageSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Window read: ang sync ng page na hindi pa nakababasa ng buong 45 araw (o cap / time ang huling takbo) ay walang cap
 * at walang known-batch stop; humihinto sa age, end, o sa oras (time), at itinutuloy ng susunod mula sa naabot.
 */
class BotcakeWindowReadTest extends BotcakeApiTestCase
{
    private const TOKEN = 'tok-window-test-3m8d';

    /** @var array<int, int> mga batch na hiningi */
    private array $asked = [];

    protected function setUp(): void
    {
        parent::setUp();

        $s = app(PageSettings::class);
        $s->set('Page Uno', 'botcake', 'page_id', '1001');
        $s->set('Page Uno', 'botcake', 'token', self::TOKEN);
        config(['services.botcake.sync_max_batches' => 3]); // maliit na cap para sa test
    }

    /**
     * Bawat batch: 10 bagong customer na naka-subscribe kahapon; ang batch na $oldAt ay may isang 60 araw na (age).
     * $secondsPerBatch: sinusulong ang orasan sa bawat request (para sa time budget).
     */
    private function fake(?int $oldAt = null, int $secondsPerBatch = 0, ?int $endAt = null): void
    {
        Http::fake(['botcake.io/api/public_api/v1/pages/1001/customer*' => function (Request $req) use ($oldAt, $secondsPerBatch, $endAt) {
            parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);
            $n = (int) $q['page'];
            $this->asked[] = $n;
            $this->clock->time += $secondsPerBatch;
            if ($endAt !== null && $n >= $endAt) return Http::response([]);

            $days = fn ($i) => $n === $oldAt && $i === 10 ? 60 : 1;
            return Http::response(array_map(fn ($i) => $this->customer('b' . $n . '-' . $i, 'Name ' . $n . ' ' . $i)
                + ['date_subscribed_at' => now()->subDays($days($i))->toIso8601String()], range(1, 10)));
        }]);
    }

    private function pastRun(?string $stop, int $batches = 1, ?string $kind = null, ?int $start = null, string $status = 'done'): BotcakePsidImportRun
    {
        return BotcakePsidImportRun::create([
            'status' => $status, 'source' => 'api', 'page_name' => 'Page Uno', 'stop_reason' => $stop,
            'batches_read' => $batches, 'read_kind' => $kind, 'start_batch' => $start,
        ]);
    }

    private function sync(): BotcakePsidImportRun
    {
        return app(BotcakeCustomerSync::class)->syncPage('Page Uno');
    }

    private function stale(): BotcakePsid
    {
        return BotcakePsid::create([
            'page' => 'Page Uno', 'fb_name' => 'Gone', 'psid' => 'gone', 'status' => 'available', 'source' => 'api',
            'last_seen_at' => now()->subDays(31), 'subscribed_at' => now()->subDays(20),
        ]);
    }

    public function test_the_first_sync_reads_past_the_cap_to_the_age_window_and_marks_inactive(): void
    {
        $stale = $this->stale();
        $this->fake(oldAt: 6);

        $run = $this->sync();

        $this->assertSame([1, 2, 3, 4, 5, 6], $this->asked, 'walang cap na 3 batch');
        $this->assertSame(['age', 'window', 1, 6], [$run->stop_reason, $run->read_kind, $run->start_batch, $run->batches_read]);
        $this->assertSame('inactive', $stale->fresh()->status, 'buong window mula batch 1: may inactive marking');
        $this->assertStringContainsString('window', $run->last_message);
    }

    public static function lastRuns(): array
    {
        // [mga naunang run: [stop, batches, kind, start], unang batch na hihingin, uri ng basa]
        return [
            'cap'                        => [[['end', 2, 'window', 1], ['cap', 3, 'incremental', 1]], 1, 'window'],
            'time: tuloy, minus 5'       => [[['time', 20, 'window', 1]], 15, 'window'],
            'time ng tuloy na basa'      => [[['time', 20, 'window', 1], ['time', 30, 'window', 15]], 39, 'window'],
            'age'                        => [[['age', 4, 'window', 1]], 1, 'incremental'],
            'end'                        => [[['end', 2, 'incremental', 1]], 1, 'incremental'],
            'known_batch pagkatapos ng age' => [[['age', 4, 'window', 1], ['known_batch', 1, 'incremental', 1]], 1, 'incremental'],
            'known_batch lang, hindi pa buo' => [[['known_batch', 1, null, null]], 1, 'window'],
            'lumang run na walang kind'  => [[['cap', 300, null, null]], 1, 'window'],
            'rate limit sa gitna: tuloy' => [[['rate_limited', 12, 'window', 1, 'failed']], 7, 'window'],
            'pinatay na run: tuloy'      => [[[null, 12, 'window', 1, 'running']], 7, 'window'],
            '0 batch: parehong simula'   => [[['time', 20, 'window', 1], ['rate_limited', 0, 'window', 15, 'failed']], 15, 'window'],
            '2 sunod na failed: mula 1'  => [[['error', 6, 'window', 15, 'failed'], ['error', 6, 'window', 15, 'failed']], 1, 'window'],
        ];
    }

    /** @dataProvider lastRuns */
    public function test_the_last_runs_decide_the_kind_of_read_and_where_it_starts(array $runs, int $first, string $kind): void
    {
        foreach ($runs as $r) $this->pastRun(...$r);
        $this->fake(endAt: 50);

        $run = $this->sync();

        $this->assertSame($first, $this->asked[0]);
        $this->assertSame([$kind, $first], [$run->read_kind, $run->start_batch]);
        if ($kind === 'incremental') $this->assertCount(3, $this->asked, 'incremental: may cap pa rin');
    }

    public function test_a_window_read_stops_after_22_minutes_and_the_next_one_continues_without_marking_inactive(): void
    {
        $stale = $this->stale();
        $this->fake(secondsPerBatch: 60, endAt: 35);

        $first = $this->sync();

        $this->assertSame(['time', 'window', 1, 22], [$first->stop_reason, $first->read_kind, $first->start_batch, $first->batches_read]);
        $this->assertSame('available', $stale->fresh()->status, 'putol na basa: walang inactive');

        $this->asked = [];
        $second = $this->sync();

        $this->assertSame(17, $this->asked[0], 'mula sa batch 22, minus 5 na overlap');
        $this->assertSame(['end', 'window', 17], [$second->stop_reason, $second->read_kind, $second->start_batch]);
        $this->assertSame('available', $stale->fresh()->status, 'itinuloy na basa (hindi mula batch 1): walang inactive');
        $this->assertStringContainsString('tuloy mula batch 17', $second->last_message);

        $row = collect(app(\App\Services\PageSettingsGrid::class)->build()['rows'])->firstWhere('name', 'Page Uno');
        $this->assertSame('window, tuloy mula batch 17', $row['info']['last_sync']['kind']);
        $this->assertSame('end', $row['info']['last_sync']['stop_reason']);
    }

    public function test_sync_now_and_sync_lahat_use_the_same_rule(): void
    {
        $this->fake(oldAt: 5);

        SyncBotcakeCustomersJob::dispatch('Page Uno'); // sync queue sa tests: tumatakbo agad

        $this->assertSame([1, 2, 3, 4, 5], $this->asked);
        $this->assertSame('window', BotcakePsidImportRun::where('source', 'api')->sole()->read_kind);

        // Ang huling run ay cap: ang Sync lahat ay window read din mula batch 1.
        $this->pastRun('cap', 3, 'incremental', 1);
        $this->asked = [];
        app(\App\Services\BotcakeSyncAll::class)->start();

        $this->assertSame([1, 2, 3, 4, 5], $this->asked);
        $this->assertSame('window', BotcakePsidImportRun::where('source', 'api')->latest('id')->value('read_kind'));
    }
}

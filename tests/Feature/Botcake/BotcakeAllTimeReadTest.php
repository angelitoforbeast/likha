<?php

namespace Tests\Feature\Botcake;

use App\Models\BotcakePsid;
use App\Models\BotcakePsidImportRun;
use App\Services\BotcakeCustomerSync;
use App\Services\PageSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Spec 262: botcake:sync-customers --all-time. Bawat page mula batch 1 hanggang dulo (walang age stop, walang cap),
 * itinutuloy mula sa naabot, isang lock kada page (sabay ang magkaibang page), at --shard=K/N.
 */
class BotcakeAllTimeReadTest extends BotcakeApiTestCase
{
    /** @var array<string, array<int, int>> page id => mga batch na hiningi, sa pagkakasunod */
    private array $asked = [];

    /** @var array<string, int> page id => huling batch na may laman (lampas = []) */
    private array $ends = [];

    /** @var array<string, array<int, int>> page id => batch => HTTP status na isasagot (paulit-ulit) */
    private array $failAt = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->page('Page Uno', '1001', 3);
        $this->page('Page Dos', '1002', 2);

        Http::fake(['botcake.io/api/public_api/v1/pages/*' => function (Request $req) {
            preg_match('#/pages/(\d+)/customer#', $req->url(), $m);
            $id = $m[1];
            parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);
            $n = (int) ($q['page'] ?? 0);
            if ($status = $this->failAt[$id][$n] ?? null) return Http::response(['message' => 'err'], $status);

            $this->asked[$id][] = $n;
            if ($n > ($this->ends[$id] ?? 0)) return Http::response([]);
            // Lahat ay naka-subscribe 400 araw na ang nakaraan: lampas sa 45 araw, kaya ang window ay hihinto sa age.
            return Http::response(array_map(fn ($i) => $this->customer($id . '-' . $n . '-' . $i, 'Name ' . $n . ' ' . $i)
                + ['date_subscribed_at' => now()->subDays(400)->toIso8601String()], range(1, 10)));
        }]);
    }

    private function page(string $name, string $id, int $end): void
    {
        $s = app(PageSettings::class);
        $s->set($name, 'botcake', 'page_id', $id);
        $s->set($name, 'botcake', 'token', 'tok-alltime-' . $id . '-qz');
        $this->ends[$id] = $end;
    }

    private function allTime(array $options = []): string
    {
        $this->assertSame(0, Artisan::call('botcake:sync-customers', ['--all-time' => true] + $options));
        return Artisan::output();
    }

    private function runsOf(string $page)
    {
        return BotcakePsidImportRun::where('source', 'api')->where('page_name', $page)->orderBy('id');
    }

    public function test_all_time_reads_every_batch_to_the_end_without_the_age_stop(): void
    {
        $out = $this->allTime(['--page' => 'Page Uno']);

        $this->assertSame([1, 2, 3, 4], $this->asked['1001'], 'walang age stop kahit 400 araw na ang customer');
        $run = $this->runsOf('Page Uno')->sole();
        $this->assertSame(['done', 'all_time', 1, 4, 'end', 30], [$run->status, $run->read_kind, $run->start_batch, $run->batches_read, $run->stop_reason, $run->customers_new]);
        $this->assertSame(30, BotcakePsid::where('source', 'api')->where('page', 'Page Uno')->count());
        $this->assertStringContainsString('OK Page Uno: all time, 4 batch (end), 30 bago', $out);
        $this->assertArrayNotHasKey('1002', $this->asked, '--page: ang page lang na iyon');
    }

    public function test_all_time_has_no_max_batches_cap_while_full_still_stops_at_the_cap(): void
    {
        $sync = app(BotcakeCustomerSync::class);
        $sync->maxBatches = 2;
        config(['services.botcake.sync_max_age_days' => 100000]); // ang --full ay hindi hihinto sa age
        $this->app->instance(BotcakeCustomerSync::class, $sync);

        Artisan::call('botcake:sync-customers', ['--full' => true, '--page' => 'Page Uno']);
        $this->assertSame('cap', $this->runsOf('Page Uno')->sole()->stop_reason, 'ang --full ay may cap pa rin');

        $this->asked = [];
        $this->allTime(['--page' => 'Page Uno']);
        $this->assertSame([1, 2, 3, 4], $this->asked['1001']);
        $this->assertSame('end', $this->runsOf('Page Uno')->get()->last()->stop_reason);
    }

    public function test_all_time_resumes_after_a_failure_from_its_reached_batch_and_never_restarts_at_1(): void
    {
        $this->ends['1001'] = 20;
        $this->failAt['1001'][13] = 500; // apat na subok, saka error

        $this->allTime(['--page' => 'Page Uno']);
        $failed = $this->runsOf('Page Uno')->sole();
        $this->assertSame(['failed', 'error', 1, 12], [$failed->status, $failed->stop_reason, $failed->start_batch, $failed->batches_read]);

        unset($this->failAt['1001']);
        $this->asked = [];
        $this->allTime(['--page' => 'Page Uno']);

        $resumed = $this->runsOf('Page Uno')->get()->last();
        $this->assertSame(12 - BotcakeCustomerSync::RESUME_OVERLAP, $resumed->start_batch, 'mula sa naabot na batch 12, minus overlap');
        $this->assertSame(range(7, 21), $this->asked['1001']);
        $this->assertSame(['done', 'end'], [$resumed->status, $resumed->stop_reason]);
        $this->assertSame(200, BotcakePsid::where('source', 'api')->where('page', 'Page Uno')->count(), 'walang doble sa overlap');
    }

    public function test_a_killed_all_time_run_left_running_is_marked_killed_and_resumed(): void
    {
        BotcakePsidImportRun::create(['status' => 'running', 'source' => 'api', 'page_name' => 'Page Uno', 'read_kind' => 'all_time', 'start_batch' => 1, 'batches_read' => 9]);
        $this->ends['1001'] = 12;

        $this->allTime(['--page' => 'Page Uno']);

        [$killed, $resumed] = $this->runsOf('Page Uno')->get()->all();
        $this->assertSame(['failed', 'killed'], [$killed->status, $killed->stop_reason]);
        $this->assertSame(4, $resumed->start_batch);
        $this->assertSame(range(4, 13), $this->asked['1001']);
    }

    public function test_an_unfinished_full_read_from_batch_1_is_continued_instead_of_read_again(): void
    {
        // Ang one-off na --full (pinatay, naiwang running) ay nakabasa na ng batch 1..11.
        BotcakePsidImportRun::create(['status' => 'running', 'source' => 'api', 'page_name' => 'Page Uno', 'read_kind' => 'full', 'start_batch' => 1, 'batches_read' => 11]);
        $this->ends['1001'] = 14;

        $this->allTime(['--page' => 'Page Uno']);

        $this->assertSame(range(6, 15), $this->asked['1001'], 'mula batch 11 minus overlap, hindi mula 1');
        $this->assertSame(['failed', 'killed'], [$this->runsOf('Page Uno')->first()->status, $this->runsOf('Page Uno')->first()->stop_reason]);

        // Ang --full na natapos (end o age) ay hindi itinutuloy: mula 1.
        $this->asked = [];
        BotcakePsidImportRun::create(['status' => 'done', 'source' => 'api', 'page_name' => 'Page Dos', 'read_kind' => 'full', 'start_batch' => 1, 'batches_read' => 2, 'stop_reason' => 'age']);
        $this->allTime(['--page' => 'Page Dos']);
        $this->assertSame([1, 2, 3], $this->asked['1002']);
    }

    public function test_a_long_page_is_read_in_runs_of_at_most_an_hour_in_one_command_with_sleep_ms(): void
    {
        $this->ends['1001'] = 25;

        // 200 s kada batch: sa batch 19 ay isang oras na, kaya bagong run (tuloy) sa parehong utos, hanggang dulo.
        $out = $this->allTime(['--page' => 'Page Uno', '--sleep-ms' => 200000]);

        $runs = $this->runsOf('Page Uno')->get()->map(fn ($r) => [$r->status, $r->stop_reason, $r->start_batch, $r->batches_read])->all();
        $this->assertSame([['done', 'time', 1, 19], ['done', 'end', 14, 13]], $runs);
        $this->assertSame(array_merge(range(1, 19), range(14, 26)), $this->asked['1001']);
        $this->assertContains(200.0, $this->clock->sleeps, '--sleep-ms sa pagitan ng mga batch');
        $this->assertSame(250, BotcakePsid::where('source', 'api')->where('page', 'Page Uno')->count());
        $this->assertSame(2, substr_count($out, 'OK Page Uno'));
    }

    public function test_finished_pages_are_skipped_unless_again(): void
    {
        $this->allTime();
        $this->assertSame([1, 2, 3, 4], $this->asked['1001']);
        $this->assertSame([1, 2, 3], $this->asked['1002']);

        $this->asked = [];
        $out = $this->allTime();
        $this->assertSame([], $this->asked, 'tapos na ang dalawa');
        $this->assertStringContainsString('Laktaw ang Page Uno', $out);
        $this->assertSame(2, BotcakePsidImportRun::where('source', 'api')->count());

        $this->allTime(['--again' => true, '--page' => 'Page Dos']);
        $this->assertSame([1, 2, 3], $this->asked['1002'], '--again: mula batch 1 ulit');
        $this->assertArrayNotHasKey('1001', $this->asked);
    }

    public function test_the_shard_split_follows_the_stable_page_list(): void
    {
        $this->page('Page Tres', '1003', 1);
        $this->page('Page Cuatro', '1004', 1);
        $this->page('Page Cinco', '1005', 1);
        $pages = app(BotcakeCustomerSync::class)->pages(); // ayon sa pangalan
        $this->assertSame(['Page Cinco', 'Page Cuatro', 'Page Dos', 'Page Tres', 'Page Uno'], $pages);

        $this->allTime(['--shard' => '2/3']);
        $this->assertEqualsCanonicalizing(['1004', '1001'], array_keys($this->asked), 'index 1 at 4');

        $this->asked = [];
        $this->allTime(['--shard' => '1/3']);
        $this->assertEqualsCanonicalizing(['1005', '1003'], array_keys($this->asked), 'index 0 at 3');
        $this->allTime(['--shard' => '3/3']);
        $this->assertEqualsCanonicalizing(['1005', '1003', '1002'], array_keys($this->asked), 'index 2: walang naulit, walang nalaktawan');
        $this->assertSame(5, BotcakePsidImportRun::where('source', 'api')->count(), 'isang run kada page');

        foreach (['0/3', '4/3', '2', 'a/b', '1/0'] as $bad) {
            $this->assertSame(1, Artisan::call('botcake:sync-customers', ['--all-time' => true, '--shard' => $bad]), $bad);
        }
        $this->assertSame(1, Artisan::call('botcake:sync-customers', ['--shard' => '1/2']), '--shard ay para sa --all-time lang');
    }

    public function test_the_lock_is_per_page_so_two_pages_run_at_once(): void
    {
        $sync = app(BotcakeCustomerSync::class);

        $inner = $sync->withLock('Page Uno', fn () => $sync->withLock('Page Dos', fn () => 'both'));
        $this->assertSame('both', $inner, 'magkaibang page: sabay');

        // Ibang proseso ang may hawak ng Page Uno: binabasa pa rin ang Page Dos.
        $held = Cache::lock($sync->lockKey('Page Uno'), 60);
        $this->assertTrue($held->get());
        $out = $this->allTime();
        $this->assertArrayNotHasKey('1001', $this->asked);
        $this->assertSame([1, 2, 3], $this->asked['1002']);
        $this->assertStringContainsString('Laktaw ang Page Uno', $out);
        $held->release();
    }

    public function test_the_same_page_is_refused_to_a_second_process(): void
    {
        $sync = app(BotcakeCustomerSync::class);

        $this->assertNull($sync->withLock('Page Uno', fn () => $sync->withLock(' page uno ', fn () => 'twice')), 'parehong page (pageKey): laktaw');

        $held = Cache::lock($sync->lockKey('Page Uno'), 60);
        $held->get();
        Artisan::call('botcake:sync-customers', ['--all-time' => true, '--page' => 'Page Uno']);
        $this->assertSame([], $this->asked);
        $this->assertSame(0, BotcakePsidImportRun::count(), 'walang run na nagawa');
        $held->release();
    }

    public function test_the_nightly_sync_skips_a_locked_page_and_goes_on_and_ignores_all_time_runs(): void
    {
        config(['services.botcake.sync_enabled' => true]);
        $this->travelTo('2026-11-11 01:00:00'); // Miyerkules 01:00 Manila: hindi --full
        $sync = app(BotcakeCustomerSync::class);

        // Ang Page Dos ay may window read na naputol (time), saka isang all-time run: ang window read pa rin ang itinutuloy.
        BotcakePsidImportRun::create(['status' => 'done', 'source' => 'api', 'page_name' => 'Page Dos', 'read_kind' => 'window', 'start_batch' => 1, 'batches_read' => 10, 'stop_reason' => 'time']);
        BotcakePsidImportRun::create(['status' => 'done', 'source' => 'api', 'page_name' => 'Page Dos', 'read_kind' => 'all_time', 'start_batch' => 40, 'batches_read' => 30, 'stop_reason' => 'time']);
        $this->assertSame(['window', 5, null, null], $sync->plan('Page Dos'));
        $this->assertSame(['window', 5, null, null], $sync->plans()['page dos']);

        // Isang --all-time (ibang proseso) ang may hawak ng Page Uno habang tumatakbo ang nightly.
        $out = $sync->withLock('Page Uno', function () use ($sync) {
            $this->assertSame('2026-11-11 01:00', $sync->lockedSince()?->timezone('Asia/Manila')->format('Y-m-d H:i'), 'ang grid: may hawak na page');
            $this->assertSame(0, Artisan::call('botcake:sync-customers', ['--nightly' => true]));
            return Artisan::output();
        });
        $this->assertNull($sync->lockedSince(), 'nawala sa listahan kasama ng lock');

        $this->assertStringContainsString('Skipped: may tumatakbo pang Botcake customer sync (mula 2026-11-11 01:00). Laktaw ang Page Uno', $out);
        $this->assertArrayNotHasKey('1001', $this->asked);
        $this->assertSame([5], $this->asked['1002'], 'tuloy ng window read ng Page Dos (walang laman ang batch 5: end)');
        $this->assertSame('window', $this->runsOf('Page Dos')->get()->last()->read_kind);
    }

    public function test_inactive_only_after_an_all_time_chain_from_batch_1_reaches_the_end(): void
    {
        $gone = fn (string $psid, string $page) => BotcakePsid::create([
            'page' => $page, 'fb_name' => 'Gone', 'psid' => $psid, 'status' => 'available', 'source' => 'api',
            'last_seen_at' => now()->subDays(31), 'subscribed_at' => null,
        ]);
        $uno = $gone('gone-uno', 'Page Uno');
        $dos = $gone('gone-dos', 'Page Dos');

        // Page Uno: chain mula 1, naputol, itinuloy hanggang dulo: inactive (kahit walang petsa).
        $this->ends['1001'] = 12;
        $this->failAt['1001'][8] = 500;
        $this->allTime(['--page' => 'Page Uno']);
        $this->assertSame('available', $uno->fresh()->status, 'hindi pa tapos');
        unset($this->failAt['1001']);
        $this->allTime(['--page' => 'Page Uno']);
        $this->assertSame('inactive', $uno->fresh()->status);

        // Page Dos: itinuloy mula sa isang --full (hindi mula 1 ang chain): walang inactive.
        BotcakePsidImportRun::create(['status' => 'failed', 'source' => 'api', 'page_name' => 'Page Dos', 'read_kind' => 'full', 'start_batch' => 1, 'batches_read' => 7, 'stop_reason' => 'killed']);
        $this->ends['1002'] = 9;
        $this->allTime(['--page' => 'Page Dos']);
        $this->assertSame('end', $this->runsOf('Page Dos')->get()->last()->stop_reason);
        $this->assertSame('available', $dos->fresh()->status);
    }
}

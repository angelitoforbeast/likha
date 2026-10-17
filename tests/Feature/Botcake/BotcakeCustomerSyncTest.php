<?php

namespace Tests\Feature\Botcake;

use App\Models\BotcakePsid;
use App\Models\BotcakePsidImportRun;
use App\Services\BotcakeCustomerSync;
use App\Services\PageSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/** botcake:sync-customers: binabasa ang customer list ng Botcake (10 kada page, pinakabago muna) papunta sa botcake_psids. */
class BotcakeCustomerSyncTest extends BotcakeApiTestCase
{
    private const TOKEN = 'tok-sync-test-5x7q';

    /** @var array<int, int> mga page number na hiningi, sa pagkakasunod */
    private array $asked = [];

    protected function setUp(): void
    {
        parent::setUp();

        $s = app(PageSettings::class);
        $s->set('Page Uno', 'botcake', 'page_id', '1001');
        $s->set('Page Uno', 'botcake', 'token', self::TOKEN);
    }

    /** 10 customer kada page; $pages = page number => mga psid. Lampas sa huli = []. */
    private function fakeCustomers(array|\Closure $pages, array $statusQueue = []): void
    {
        Http::fake(['botcake.io/api/public_api/v1/pages/1001/customer*' => function (Request $req) use ($pages, &$statusQueue) {
            $this->clock->requestTimes[] = $this->clock->now();
            $this->assertSame(self::TOKEN, $req->header('access-token')[0] ?? null);
            $status = array_shift($statusQueue);
            if ($status) return Http::response(['message' => 'err'], $status);

            parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);
            $n = (int) ($q['page'] ?? 0);
            $this->asked[] = $n;

            $list = is_array($pages) ? ($pages[$n] ?? []) : $pages($n);
            return Http::response(array_map(fn ($psid) => is_array($psid) ? $psid : $this->customer($psid, 'Name ' . $psid, []), $list));
        }]);
    }

    /** Nakabasa na ang page ng buong window dati: ang susunod na sync ay incremental (hindi window read). */
    private function windowDone(): void
    {
        BotcakePsidImportRun::create(['status' => 'done', 'source' => 'api', 'page_name' => 'Page Uno', 'stop_reason' => 'end', 'read_kind' => 'window', 'start_batch' => 1]);
    }

    private function psids(string $prefix, int $from, int $count = 10): array
    {
        return array_map(fn ($i) => $prefix . $i, range($from, $from + $count - 1));
    }

    public function test_incremental_stops_at_the_first_batch_whose_psids_are_all_known(): void
    {
        // Naitala na ang page 2 mula sa naunang sync; ang page 1 ay bago.
        $this->windowDone();
        foreach ($this->psids('p', 11) as $psid) {
            BotcakePsid::create(['page' => 'Page Uno', 'fb_name' => 'Name ' . $psid, 'psid' => $psid, 'status' => 'available', 'source' => 'api']);
        }
        BotcakePsid::create(['page' => 'Page Uno', 'fb_name' => 'Old', 'psid' => 'p99', 'status' => 'available', 'source' => 'api', 'ad_id' => '120250000000000099']);
        $this->order('Page Uno', 'Buyer', null, 'p99'); // PSID mula sa sheet, walang ad id
        $this->fakeCustomers([1 => $this->psids('p', 1), 2 => $this->psids('p', 11), 3 => $this->psids('p', 21)]);

        $this->assertSame(0, Artisan::call('botcake:sync-customers', ['--page' => 'page uno']));

        $this->assertSame([1, 2], $this->asked, 'hindi na binasa ang page 3');
        $this->assertSame(21, BotcakePsid::where('source', 'api')->count());
        $run = BotcakePsidImportRun::where('source', 'api')->latest('id')->first();
        $this->assertSame(['done', 'Page Uno', 2, 10, 0, 'known_batch'], [$run->status, $run->page_name, $run->batches_read, $run->customers_new, $run->customers_updated, $run->stop_reason]);
        $this->assertSame('available', BotcakePsid::where('psid', 'p1')->value('status'));
        $this->assertStringEndsWith(', 1 ad id', $run->last_message, 'nakikita sa log ang napunang ad id');
    }

    public function test_full_reads_to_the_empty_page_and_updates_changed_names(): void
    {
        BotcakePsid::create(['page' => 'Page Uno', 'fb_name' => 'Old Name', 'psid' => 'p1', 'status' => 'available', 'source' => 'api']);
        $this->fakeCustomers([1 => $this->psids('p', 1), 2 => $this->psids('p', 11), 3 => ['p21', 'p22']]);

        Artisan::call('botcake:sync-customers', ['--full' => true]);

        $this->assertSame([1, 2, 3, 4], $this->asked);
        $this->assertSame(22, BotcakePsid::where('source', 'api')->count(), 'isang row kada page + psid');
        $this->assertSame('Name p1', BotcakePsid::where('psid', 'p1')->value('fb_name'));
        $run = BotcakePsidImportRun::where('source', 'api')->sole();
        $this->assertSame([4, 21, 1, 'end'], [$run->batches_read, $run->customers_new, $run->customers_updated, $run->stop_reason]);
    }

    public function test_429_then_success_retries_with_backoff_and_requests_stay_at_most_two_a_second(): void
    {
        $this->fakeCustomers([1 => $this->psids('p', 1), 2 => $this->psids('p', 11)], [429, 503]);

        Artisan::call('botcake:sync-customers', ['--full' => true]);

        $this->assertSame([1, 2, 3], $this->asked);
        $this->assertSame('done', BotcakePsidImportRun::where('source', 'api')->value('status'));
        $this->assertContains(2.0, $this->clock->sleeps, 'unang backoff');
        $this->assertContains(4.0, $this->clock->sleeps, 'pangalawang backoff');

        $times = $this->clock->requestTimes;
        $this->assertCount(5, $times);
        for ($i = 1; $i < count($times); $i++) {
            $this->assertGreaterThanOrEqual(0.5, round($times[$i] - $times[$i - 1], 6), 'hindi lalampas sa 2 request kada segundo');
        }
    }

    public static function exhausted(): array
    {
        return ['429' => [429, 'rate_limited'], '500' => [500, 'error']];
    }

    /** @dataProvider exhausted */
    public function test_four_failures_stop_the_page_and_report_why(int $status, string $reason): void
    {
        $this->fakeCustomers([1 => $this->psids('p', 1)], array_fill(0, 4, $status));

        Artisan::call('botcake:sync-customers', ['--page' => 'Page Uno']);

        $run = BotcakePsidImportRun::where('source', 'api')->sole();
        $this->assertSame(['failed', $reason], [$run->status, $run->stop_reason]);
        $this->assertStringContainsString((string) $status, (string) $run->last_error);
        $this->assertSame([], $this->asked);
    }

    public function test_401_marks_the_token_invalid_and_stops_that_page_without_logging_the_token(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function ($e) use (&$logged) { $logged[] = $e->message . json_encode($e->context); });
        $this->fakeCustomers([1 => $this->psids('p', 1)], [401]);

        Artisan::call('botcake:sync-customers');

        $this->assertStringStartsWith('invalid', (string) app(PageSettings::class)->get('Page Uno', 'botcake', 'token_status'));
        $run = BotcakePsidImportRun::where('source', 'api')->sole();
        $this->assertSame(['failed', 'token_invalid'], [$run->status, $run->stop_reason]);
        $this->assertSame(0, BotcakePsid::count());
        $this->assertStringNotContainsString(self::TOKEN, implode("\n", $logged) . Artisan::output() . $run->last_error);
    }
    public function test_an_incremental_run_stops_at_the_cap_and_the_next_run_reads_the_whole_window_from_page_1(): void
    {
        $this->assertSame(300, (int) config('services.botcake.sync_max_batches'));
        config(['services.botcake.sync_max_batches' => 5]);
        $this->windowDone();
        $this->fakeCustomers(fn (int $n) => $n > 8 ? [] : $this->psids('n' . $n . '-', 1));

        Artisan::call('botcake:sync-customers');

        $run = BotcakePsidImportRun::where('source', 'api')->latest('id')->first();
        $this->assertSame(['done', 'cap', 5, 50, 'incremental'], [$run->status, $run->stop_reason, $run->batches_read, $run->customers_new, $run->read_kind]);
        $this->assertSame(5, max($this->asked));

        $this->asked = [];
        Artisan::call('botcake:sync-customers');
        $this->assertSame(range(1, 9), $this->asked, 'cap ang huli: window read mula page 1, lampas sa cap at sa kilalang batch');
        $run = BotcakePsidImportRun::where('source', 'api')->latest('id')->first();
        $this->assertSame(['end', 'window'], [$run->stop_reason, $run->read_kind]);
    }

    private function apiRow(string $psid, string $status, ?int $seenDaysAgo, ?int $subscribedDaysAgo = 20): BotcakePsid
    {
        return BotcakePsid::create([
            'page' => 'Page Uno', 'fb_name' => 'Name ' . $psid, 'psid' => $psid, 'status' => $status, 'source' => 'api',
            'last_seen_at' => $seenDaysAgo === null ? null : now()->subDays($seenDaysAgo),
            'subscribed_at' => $subscribedDaysAgo === null ? null : now()->subDays($subscribedDaysAgo),
        ]);
    }

    public function test_a_clean_full_read_marks_customers_unseen_for_30_days_inactive_and_a_returning_one_available(): void
    {
        $stale    = $this->apiRow('old-1', 'available', 31);
        $recent   = $this->apiRow('old-2', 'available', 10);
        $back     = $this->apiRow('p1', 'inactive', 60);
        $this->fakeCustomers([1 => $this->psids('p', 1)]);

        Artisan::call('botcake:sync-customers', ['--full' => true]);

        $this->assertSame('inactive', $stale->fresh()->status);
        $this->assertSame('available', $recent->fresh()->status);
        $this->assertSame('available', $back->fresh()->status, 'nakita ulit → available');
        $this->assertTrue($back->fresh()->last_seen_at->isToday());
        $this->assertSame(12, BotcakePsid::where('source', 'api')->count(), 'walang binura: 3 dati + 9 bago');
    }

    public function test_an_errored_full_read_or_an_incremental_run_marks_nobody_inactive(): void
    {
        $stale = $this->apiRow('old-1', 'available', 31);
        $this->windowDone(); // ang ikalawang sync ay incremental

        $this->fakeCustomers([1 => $this->psids('p', 1), 2 => $this->psids('p', 11)], [null, 500, 500, 500, 500]);
        Artisan::call('botcake:sync-customers', ['--full' => true]);
        $this->assertSame('error', BotcakePsidImportRun::where('source', 'api')->latest('id')->value('stop_reason'));
        $this->assertSame('available', $stale->fresh()->status);

        $this->fakeCustomers([1 => []]);
        Artisan::call('botcake:sync-customers');
        $this->assertSame('available', $stale->fresh()->status);
    }

    public function test_the_live_field_names_for_subscribed_and_last_interactive_are_read(): void
    {
        $c = $this->customer('p1', 'Ana Cruz', ['09171112222']);
        $this->fakeCustomers([1 => [$c + ['date_subscribed_at' => '2026-09-30', 'last_interactive_at' => '2026-10-16T22:15:00']]]);

        Artisan::call('botcake:sync-customers');

        $row = BotcakePsid::where('psid', 'p1')->sole();
        $this->assertSame('2026-09-30', $row->subscribed_at->toDateString());
        $this->assertSame('2026-10-16 22:15:00', $row->last_interactive_at->copy()->utc()->format('Y-m-d H:i:s'), 'UTC ang last_interactive_at');
        $this->assertSame(['09171112222'], $row->phone_numbers);
    }
    public function test_the_ad_id_and_ad_ids_are_stored_and_follow_the_customer(): void
    {
        $withAd = fn (array $ids, ?string $adId) => $this->customer('p1', 'Ana Cruz') + ['ad_id' => $adId, 'ad_ids' => $ids];
        $round = 1;
        $this->fakeCustomers(function (int $n) use (&$round, $withAd) {
            if ($n !== 1) return [];
            return $round === 1
                ? [$withAd(['120250000000000001'], '120250000000000001'), $this->customer('p2', 'Ben Reyes') + ['ad_id' => null, 'ad_ids' => []]]
                : [$withAd(['120250000000000001', '120250000000000002'], '120250000000000002')];
        });

        Artisan::call('botcake:sync-customers', ['--full' => true]);

        $ana = BotcakePsid::where('psid', 'p1')->sole();
        $this->assertSame(['120250000000000001', ['120250000000000001']], [$ana->ad_id, $ana->ad_ids]);
        $ben = BotcakePsid::where('psid', 'p2')->sole();
        $this->assertSame([null, []], [$ben->ad_id, $ben->ad_ids], 'null / walang laman ay nananatiling null / []');

        // Bagong ad sa susunod na sync: lumalaki ang listahan, napapalitan ang ad_id.
        $round = 2;
        Artisan::call('botcake:sync-customers', ['--full' => true]);

        $ana->refresh();
        $this->assertSame(['120250000000000002', ['120250000000000001', '120250000000000002']], [$ana->ad_id, $ana->ad_ids]);
    }

    /** Isang customer na naka-subscribe $days na araw na ang nakaraan. */
    private function subscribed(string $psid, int $days): array
    {
        return $this->customer($psid, 'Name ' . $psid) + ['date_subscribed_at' => now()->subDays($days)->toIso8601String()];
    }

    public static function windows(): array
    {
        // [--full, max age config (null = default 45), araw ng pinakalumang customer sa page 2, inaasahang pages na binasa, stop reason]
        return [
            'incremental, 46 araw'         => [false, null, 46, [1, 2], 'age'],
            'full, 46 araw'                => [true, null, 46, [1, 2], 'age'],
            'full, 44 araw: tuloy'         => [true, null, 44, [1, 2, 3, 4], 'end'],
            'config 10 araw, 11 araw'      => [true, 10, 11, [1, 2], 'age'],
        ];
    }

    /** @dataProvider windows */
    public function test_every_sync_stops_at_the_first_batch_with_a_customer_subscribed_before_the_window(bool $full, ?int $maxAge, int $oldest, array $asked, string $reason): void
    {
        if ($maxAge !== null) config(['services.botcake.sync_max_age_days' => $maxAge]);
        $page2 = array_map(fn ($i) => $this->subscribed('q' . $i, 3), range(1, 9));
        $page2[] = $this->subscribed('q-old', $oldest);
        $this->fakeCustomers([
            1 => array_map(fn ($i) => $this->subscribed('p' . $i, 1), range(1, 10)),
            2 => $page2,
            3 => [$this->subscribed('r1', $oldest)],
        ]);

        Artisan::call('botcake:sync-customers', $full ? ['--full' => true] : []);

        $this->assertSame($asked, $this->asked);
        $this->assertSame($reason, BotcakePsidImportRun::where('source', 'api')->sole()->stop_reason);
        $this->assertTrue(BotcakePsid::where('psid', 'q-old')->exists(), 'ang batch na binasa ay naitatala buo');
    }

    public function test_the_all_known_batch_stop_comes_before_the_age_stop(): void
    {
        $old = array_map(fn ($i) => $this->subscribed('p' . $i, 60), range(1, 10));
        foreach ($old as $c) $this->apiRow($c['psid'], 'available', 1, 60);
        $this->windowDone();
        $this->fakeCustomers([1 => $old]);

        Artisan::call('botcake:sync-customers');

        $this->assertSame('known_batch', BotcakePsidImportRun::where('source', 'api')->latest('id')->value('stop_reason'));
    }

    public function test_a_windowed_full_read_marks_inactive_only_customers_subscribed_within_the_window(): void
    {
        $inWindow  = $this->apiRow('gone-new', 'available', 31, 20);
        $outside   = $this->apiRow('gone-old', 'available', 31, 100);
        $undated   = $this->apiRow('gone-undated', 'available', 31, null);
        $this->fakeCustomers([1 => array_merge(array_map(fn ($i) => $this->subscribed('p' . $i, 2), range(1, 9)), [$this->subscribed('p-old', 50)])]);

        Artisan::call('botcake:sync-customers', ['--full' => true]);

        $this->assertSame('age', BotcakePsidImportRun::where('source', 'api')->sole()->stop_reason);
        $this->assertSame('inactive', $inWindow->fresh()->status);
        $this->assertSame('available', $outside->fresh()->status, 'lampas sa window: hindi na nakikita, pero hindi inactive');
        $this->assertSame('available', $undated->fresh()->status, 'walang petsa: hindi alam kung nasa window');
    }

    public function test_the_lock_expires_after_2_hours_and_shows_since_when_it_is_held(): void
    {
        $sync = app(BotcakeCustomerSync::class);
        $this->assertNull($sync->lockedSince());

        $sync->withLock(function () use ($sync) {
            $this->assertSame(now()->format('Y-m-d H:i:s'), $sync->lockedSince()->format('Y-m-d H:i:s'));
            $this->assertNull($sync->withLock(fn () => 'second'), 'may hawak: laktaw');

            // Isang run na pinatay (hindi nakapag-release): pagkalipas ng 2 oras, malaya na ulit.
            $this->travel(119)->minutes();
            $this->assertFalse(Cache::lock(BotcakeCustomerSync::LOCK, 10)->get());
            $this->travel(2)->minutes();
            $this->assertTrue(Cache::lock(BotcakeCustomerSync::LOCK, 10)->get());
            Cache::lock(BotcakeCustomerSync::LOCK)->forceRelease();
        });

        $this->assertNull($sync->lockedSince(), 'nawawala kasama ng lock');
    }

    public function test_a_signal_handler_force_releases_the_lock_and_the_skip_message_says_since_when(): void
    {
        $sync = app(BotcakeCustomerSync::class);
        $this->travelTo('2026-10-17 08:30:00');
        Cache::lock(BotcakeCustomerSync::LOCK, BotcakeCustomerSync::LOCK_TTL)->get();
        Cache::put(BotcakeCustomerSync::LOCK . ':since', now()->toIso8601String(), BotcakeCustomerSync::LOCK_TTL);

        Artisan::call('botcake:sync-customers');
        $this->assertStringContainsString('Skipped: may tumatakbo pang Botcake customer sync (mula 2026-10-17 08:30).', Artisan::output());

        $sync->forceRelease();
        $this->assertNull($sync->lockedSince());
        $this->assertTrue(Cache::lock(BotcakeCustomerSync::LOCK, 10)->get());
    }
}

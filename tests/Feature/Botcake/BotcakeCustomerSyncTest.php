<?php

namespace Tests\Feature\Botcake;

use App\Models\BotcakePsid;
use App\Models\BotcakePsidImportRun;
use App\Services\PageSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
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
    private function fakeCustomers(array $pages, array $statusQueue = []): void
    {
        Http::fake(['botcake.io/api/public_api/v1/pages/1001/customer*' => function (Request $req) use ($pages, &$statusQueue) {
            $this->clock->requestTimes[] = $this->clock->now();
            $this->assertSame(self::TOKEN, $req->header('access-token')[0] ?? null);
            $status = array_shift($statusQueue);
            if ($status) return Http::response(['message' => 'err'], $status);

            parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);
            $n = (int) ($q['page'] ?? 0);
            $this->asked[] = $n;

            return Http::response(array_map(fn ($psid) => $this->customer($psid, 'Name ' . $psid, []), $pages[$n] ?? []));
        }]);
    }

    private function psids(string $prefix, int $from, int $count = 10): array
    {
        return array_map(fn ($i) => $prefix . $i, range($from, $from + $count - 1));
    }

    public function test_incremental_stops_at_the_first_batch_whose_psids_are_all_known(): void
    {
        // Naitala na ang page 2 mula sa naunang sync; ang page 1 ay bago.
        foreach ($this->psids('p', 11) as $psid) {
            BotcakePsid::create(['page' => 'Page Uno', 'fb_name' => 'Name ' . $psid, 'psid' => $psid, 'status' => 'available', 'source' => 'api']);
        }
        $this->fakeCustomers([1 => $this->psids('p', 1), 2 => $this->psids('p', 11), 3 => $this->psids('p', 21)]);

        $this->assertSame(0, Artisan::call('botcake:sync-customers', ['--page' => 'page uno']));

        $this->assertSame([1, 2], $this->asked, 'hindi na binasa ang page 3');
        $this->assertSame(20, BotcakePsid::where('source', 'api')->count());
        $run = BotcakePsidImportRun::where('source', 'api')->sole();
        $this->assertSame(['done', 'Page Uno', 2, 10, 0], [$run->status, $run->page_name, $run->batches_read, $run->customers_new, $run->customers_updated]);
        $this->assertSame('available', BotcakePsid::where('psid', 'p1')->value('status'));
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
        $this->assertSame([4, 21, 1], [$run->batches_read, $run->customers_new, $run->customers_updated]);
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

    public function test_four_429s_stop_the_page_and_report(): void
    {
        $this->fakeCustomers([1 => $this->psids('p', 1)], [429, 429, 429, 429]);

        Artisan::call('botcake:sync-customers', ['--page' => 'Page Uno']);

        $run = BotcakePsidImportRun::where('source', 'api')->sole();
        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('429', (string) $run->last_error);
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
        $this->assertSame('failed', $run->status);
        $this->assertSame(0, BotcakePsid::count());
        $this->assertStringNotContainsString(self::TOKEN, implode("\n", $logged) . Artisan::output() . $run->last_error);
    }
}

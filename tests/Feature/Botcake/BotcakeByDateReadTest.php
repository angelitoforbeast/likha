<?php

namespace Tests\Feature\Botcake;

use App\Models\BotcakePsid;
use App\Models\BotcakePsidImportRun;
use App\Services\BotcakeCustomerSync;
use App\Services\PageSettings;
use Carbon\Carbon;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * By date read: mula batch 1, humihinto sa unang batch na lahat ng customer ay naka-subscribe bago ang stop date
 * (huling malinis na petsa minus 1 araw, hindi lalampas sa 45 araw). Hindi humihinto sa batch na kilala na lahat.
 */
class BotcakeByDateReadTest extends BotcakeApiTestCase
{
    private const TOKEN = 'tok-bydate-test-8k2w';

    /** @var array<int, int> mga batch na hiningi */
    private array $asked = [];

    protected function setUp(): void
    {
        parent::setUp();

        $s = app(PageSettings::class);
        $s->set('Page Uno', 'botcake', 'page_id', '1001');
        $s->set('Page Uno', 'botcake', 'token', self::TOKEN);
        $this->travelTo(Carbon::parse('2026-10-20 01:00', 'Asia/Manila')); // Martes
    }

    /**
     * $batches = batch => [[psid, petsa], ...]; lampas sa huli = []. $failAt: 500 mula sa batch na ito.
     * $secondsPerBatch: sinusulong ang orasan sa bawat request (para sa time budget).
     */
    private function fake(array $batches, ?int $failAt = null, int $secondsPerBatch = 0): void
    {
        Http::fake(['botcake.io/api/public_api/v1/pages/1001/customer*' => function (Request $req) use ($batches, $failAt, $secondsPerBatch) {
            parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);
            $n = (int) $q['page'];
            $this->asked[] = $n;
            $this->clock->time += $secondsPerBatch;
            if ($failAt !== null && $n >= $failAt) return Http::response(['message' => 'err'], 500);

            return Http::response(array_map(fn ($c) => $this->customer($c[0], 'Name ' . $c[0]) + ['date_subscribed_at' => $c[1]], $batches[$n] ?? []));
        }]);
    }

    /** $count na customer na iisa ang petsa. */
    private function batch(string $prefix, string $date, int $count = 10): array
    {
        return array_map(fn ($i) => [$prefix . $i, $date], range(1, $count));
    }

    private function pastRun(string $kind, ?string $stop, ?string $newest, string $status = 'done', int $start = 1, ?string $stopDate = null, int $batches = 3): BotcakePsidImportRun
    {
        return BotcakePsidImportRun::create([
            'status' => $status, 'source' => 'api', 'page_name' => 'Page Uno', 'stop_reason' => $stop, 'batches_read' => $batches,
            'read_kind' => $kind, 'start_batch' => $start, 'subscribed_newest' => $newest, 'stop_date' => $stopDate,
        ]);
    }

    private function sync(): BotcakePsidImportRun
    {
        return app(BotcakeCustomerSync::class)->syncPage('Page Uno');
    }

    public function test_by_date_reads_past_a_known_batch_and_stops_at_the_first_batch_before_the_stop_date(): void
    {
        $this->pastRun('by_date', 'date', '2026-10-18');
        // Batch 2: kilala na lahat (nag-chat ulit kaya nauna); batch 3: ang bagong subscriber sa ilalim nila.
        foreach ($this->batch('k', '2026-10-18') as $c) {
            BotcakePsid::create(['page' => 'Page Uno', 'fb_name' => 'Name ' . $c[0], 'psid' => $c[0], 'status' => 'available', 'source' => 'api']);
        }
        $this->fake([
            1 => $this->batch('a', '2026-10-20'),
            2 => $this->batch('k', '2026-10-18'),
            3 => array_merge([['gap-new', '2026-10-18']], $this->batch('b', '2026-10-17', 4), $this->batch('c', '2026-10-16', 5)),
            4 => $this->batch('d', '2026-10-16'),
            5 => $this->batch('e', '2026-10-15'),
        ]);

        $run = $this->sync();

        $this->assertSame([1, 2, 3, 4], $this->asked, 'hindi humihinto sa kilalang batch 2; humihinto sa batch 4 (lahat bago ang Okt 17)');
        $this->assertSame(['done', 'date', 'by_date', 1], [$run->status, $run->stop_reason, $run->read_kind, $run->start_batch]);
        $this->assertTrue(BotcakePsid::where('psid', 'gap-new')->exists(), 'ang bagong subscriber sa ilalim ng kilalang batch ay naitala');
        $this->assertSame(['2026-10-17', '2026-10-20', '2026-10-16'], [$run->stop_date?->toDateString(), $run->subscribed_newest?->toDateString(), $run->subscribed_oldest?->toDateString()]);
        $this->assertStringContainsString('by date', $run->last_message);
    }

    public static function pastRuns(): array
    {
        // [mga naunang run: [kind, stop, newest, status, start], uri ng basa, stop date]
        return [
            'malinis na by date'                  => [[['by_date', 'date', '2026-10-18']], 'by_date', '2026-10-17'],
            'malinis na window (age)'             => [[['window', 'age', '2026-10-18']], 'by_date', '2026-10-17'],
            'malinis na full (end)'               => [[['full', 'end', '2026-10-19']], 'by_date', '2026-10-18'],
            'failed pagkatapos: hindi gumagalaw'  => [[['by_date', 'date', '2026-10-18'], ['by_date', 'rate_limited', '2026-10-19', 'failed']], 'by_date', '2026-10-17'],
            'catch-up pagkatapos ng ilang failed' => [[['by_date', 'date', '2026-10-10'], ['by_date', 'error', null, 'failed'], ['by_date', 'error', null, 'failed'], ['by_date', 'killed', '2026-10-19', 'failed']], 'by_date', '2026-10-09'],
            'hanggang 45 araw lang'               => [[['window', 'age', '2026-08-01']], 'by_date', '2026-09-05'],
            'natapos na tuloy na window: malinis ang chain' => [[['by_date', 'date', '2026-10-12'], ['window', 'time', '2026-10-19', 'done', 1], ['window', 'age', '2026-10-19', 'done', 15]], 'by_date', '2026-10-18'],
            'walang malinis na run'               => [[['by_date', 'error', '2026-10-19', 'failed']], 'window', null],
            'lumang window na walang petsa: petsa ng run' => [[['window', 'age', null]], 'by_date', '2026-10-19'],
            'lumang full na walang petsa: petsa ng run'   => [[['full', 'end', null]], 'by_date', '2026-10-19'],
            'lumang tuloy na window na walang petsa'      => [[['window', 'age', null, 'done', 15]], 'window', null],
            'wala pang run'                       => [[], 'window', null],
        ];
    }

    /** @dataProvider pastRuns */
    public function test_the_stop_date_is_the_last_clean_date_minus_one_day_within_45_days(array $runs, string $kind, ?string $stop): void
    {
        foreach ($runs as $r) $this->pastRun(...$r);
        $this->fake([1 => $this->batch('a', '2026-10-20')]);

        $plans = app(BotcakeCustomerSync::class)->plans();
        $run = $this->sync();

        $this->assertSame([$kind, 1, $stop], [$run->read_kind, $run->start_batch, $run->stop_date?->toDateString()]);
        $this->assertSame($stop === null ? ['window', 1] : ['by_date', 1, $stop], $plans['page uno'] ?? ['window', 1], 'plans() ng grid: pareho');
        $this->assertSame(0, BotcakePsidImportRun::where('id', '<', $run->id)->whereNotNull('subscribed_newest')->count() - collect($runs)->filter(fn ($r) => $r[2] !== null)->count(), 'walang lumang row na sinusulatan');
    }

    public static function byDateResumes(): array
    {
        // [mga naunang run: [kind, stop, newest, status, start, stopDate, batches], unang batch, stop date]
        return [
            'time: tuloy, minus 5'          => [[['by_date', 'time', '2026-10-19', 'done', 1, '2026-10-09', 22]], 17, '2026-10-09'],
            'rate limit sa gitna: tuloy'    => [[['by_date', 'rate_limited', '2026-10-19', 'failed', 1, '2026-10-09', 12]], 7, '2026-10-09'],
            'pinatay sa tuloy: tuloy ulit'  => [[['by_date', 'killed', '2026-10-19', 'failed', 17, '2026-10-09', 10]], 21, '2026-10-09'],
            '0 batch: parehong simula'      => [[['by_date', 'rate_limited', '2026-10-19', 'failed', 15, '2026-10-09', 0]], 15, '2026-10-09'],
            '2 sunod na 0 batch, parehong simula: mula 1, bagong stop date' => [[['by_date', 'date', '2026-10-12'], ['by_date', 'rate_limited', '2026-10-19', 'failed', 15, '2026-10-09', 0], ['by_date', 'rate_limited', '2026-10-19', 'failed', 15, '2026-10-09', 0]], 1, '2026-10-11'],
        ];
    }

    /** @dataProvider byDateResumes */
    public function test_an_unfinished_by_date_read_is_continued_like_a_window_read_with_the_same_stop_date(array $runs, int $first, string $stop): void
    {
        foreach ($runs as $r) $this->pastRun(...$r);
        $this->fake([]);

        $run = $this->sync();

        $this->assertSame($first, $this->asked[0]);
        $this->assertSame(['by_date', $first, $stop], [$run->read_kind, $run->start_batch, $run->stop_date?->toDateString()]);
    }

    public function test_a_by_date_read_stops_at_the_45_day_limit_before_the_stop_date(): void
    {
        $this->pastRun('window', 'age', '2026-08-01'); // stop date Ago 31, pero hanggang Set 5 lang (45 araw)
        $this->fake([
            1 => $this->batch('a', '2026-10-20'),
            2 => array_merge($this->batch('b', '2026-09-06', 9), [['b-old', '2026-09-04']]),
            3 => $this->batch('c', '2026-09-03'),
        ]);

        $run = $this->sync();

        $this->assertSame([1, 2], $this->asked);
        $this->assertSame(['age', '2026-09-05'], [$run->stop_reason, $run->stop_date?->toDateString()]);
    }

    public function test_a_long_by_date_read_stops_after_22_minutes_and_the_next_one_continues_the_chain_to_a_clean_end(): void
    {
        $this->pastRun('by_date', 'date', '2026-10-10');
        $this->fake(array_fill(1, 30, $this->batch('a', '2026-10-15')) + [31 => $this->batch('z', '2026-10-08')], secondsPerBatch: 60);

        $first = $this->sync();

        $this->assertSame(['done', 'time', 22], [$first->status, $first->stop_reason, $first->batches_read], 'kasya sa timeout ng worker');

        $this->asked = [];
        $second = $this->sync();

        $this->assertSame(17, $this->asked[0], 'tuloy mula batch 22, minus 5');
        $this->assertSame(['by_date', 17, '2026-10-09', 'date', '2026-10-15'],
            [$second->read_kind, $second->start_batch, $second->stop_date?->toDateString(), $second->stop_reason, $second->subscribed_newest?->toDateString()],
            'parehong stop date; dala ang pinakabagong petsa ng unang run ng chain');
        $this->assertSame(['by_date', 1, '2026-10-14'], app(BotcakeCustomerSync::class)->plans()['page uno'], 'malinis ang chain: gumagalaw ang stop date');
    }

    public function test_a_window_chain_that_ends_cleanly_counts_as_clean_with_the_newest_date_of_its_first_run(): void
    {
        $this->fake([1 => $this->batch('a', '2026-10-20')] + array_fill(2, 29, $this->batch('b', '2026-10-10')), secondsPerBatch: 60);

        $first = $this->sync();
        $this->asked = [];
        $second = $this->sync();

        $this->assertSame(['window', 'time', '2026-10-20'], [$first->read_kind, $first->stop_reason, $first->subscribed_newest?->toDateString()]);
        $this->assertSame(['window', 17, 'end', '2026-10-20', '2026-10-10'],
            [$second->read_kind, $second->start_batch, $second->stop_reason, $second->subscribed_newest?->toDateString(), $second->subscribed_oldest?->toDateString()]);
        $this->assertSame(['by_date', 1, '2026-10-19'], app(BotcakeCustomerSync::class)->plans()['page uno']);
    }

    public function test_a_failed_run_still_records_the_dates_it_read(): void
    {
        $this->fake([1 => array_merge($this->batch('a', '2026-10-20', 5), [['a9', '2026-10-19']])], failAt: 2);

        $run = $this->sync();

        $this->assertSame(['failed', 'window'], [$run->status, $run->read_kind]);
        $this->assertSame(['2026-10-20', '2026-10-19'], [$run->subscribed_newest?->toDateString(), $run->subscribed_oldest?->toDateString()]);
    }
}

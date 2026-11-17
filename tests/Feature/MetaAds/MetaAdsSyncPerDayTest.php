<?php

namespace Tests\Feature\MetaAds;

use App\Models\UploadLog;
use App\Services\MetaAds\Sync;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Spec 288 SS-2 and SS-1. The sync asks Meta one account and one day at a time with a smaller page, tries a transient
 * error again (code 1/2, 5xx) and then Meta's async report job, never retries a permanent error, and ends Partial
 * naming only the account/days still without data. The report card shows the run live through a small status route.
 * No real Meta call: a fake Graph answers per account and day, with scripted errors.
 */
class MetaAdsSyncPerDayTest extends MetaAdsTestCase
{
    private const D1 = '2026-10-20';
    private const D2 = '2026-10-21';
    private const D3 = '2026-10-22';

    /** @var array<string, list<array{code: int, status: int, message: string}>> "acc|day" => errors answered first, in order */
    private array $script = [];

    /** @var list<array{method: string, path: string, query: array}> */
    private array $calls = [];

    /**
     * @param array<string, array<string, list<array>>> $insights account => day => rows
     * @param array<string, list<array>> $ads account => ad details
     * @param array<string, array{status: string, rows?: list<array>}> $async account => the async job's end
     */
    private function graph(array $insights, array $ads = [], array $async = []): void
    {
        Http::fake(function (Request $req) use ($insights, $ads, $async) {
            $path = trim(preg_replace('#^/v25\.0#', '', (string) parse_url($req->url(), PHP_URL_PATH)), '/');
            parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);
            $data = $req->method() === 'POST' ? $req->data() : $q;
            $this->calls[] = ['method' => $req->method(), 'path' => $path, 'query' => $data];

            if ($req->method() === 'POST' && preg_match('#^act_(\d+)/insights$#', $path, $m)) {
                return Http::response(['report_run_id' => '900' . $m[1]]);
            }
            if (preg_match('#^900(\d+)$#', $path, $m)) return Http::response(['id' => $path, 'async_status' => $async[$m[1]]['status'] ?? 'Job Running']);
            if (preg_match('#^900(\d+)/insights$#', $path, $m)) return Http::response(['data' => $async[$m[1]]['rows'] ?? []]);
            if (preg_match('#^act_(\d+)/insights$#', $path, $m)) {
                $day = json_decode((string) ($q['time_range'] ?? ''), true)['since'] ?? '';
                $key = $m[1] . '|' . $day;
                if (!empty($this->script[$key])) {
                    $e = array_shift($this->script[$key]);
                    return $e['code'] === 0
                        ? Http::response('<html>Service Unavailable</html>', $e['status'])
                        : Http::response(['error' => ['message' => $e['message'], 'type' => 'OAuthException', 'code' => $e['code']]], $e['status']);
                }
                return Http::response(['data' => $insights[$m[1]][$day] ?? []]);
            }
            if (preg_match('#^act_(\d+)/ads$#', $path, $m)) {
                $want = json_decode((string) ($q['filtering'] ?? '[]'), true)[0]['value'] ?? [];
                return Http::response(['data' => array_values(array_filter($ads[$m[1]] ?? [], fn ($a) => in_array($a['id'], $want, true)))]);
            }

            return Http::response(['error' => ['message' => 'unexpected ' . $path, 'code' => 100]], 400);
        });
    }

    private function answerError(string $acc, string $day, int $code, int $status, int $times = 1): void
    {
        $message = [1 => 'An unknown error occurred', 2 => 'Service temporarily unavailable', 100 => 'Invalid parameter'][$code] ?? 'Error';
        for ($i = 0; $i < $times; $i++) $this->script[$acc . '|' . $day][] = ['code' => $code, 'status' => $status, 'message' => $message];
    }

    private function insightCalls(string $acc = '111'): array
    {
        return array_values(array_filter($this->calls, fn ($c) => $c['method'] === 'GET' && $c['path'] === "act_{$acc}/insights"));
    }

    private function log(): UploadLog
    {
        return UploadLog::where('type', Sync::LOG_TYPE)->orderByDesc('id')->firstOrFail();
    }

    /** Write mode on, accounts selected and connected for a Marketing user, who is signed in. */
    private function ready(array $accounts = ['111']): \App\Models\User
    {
        Sync::setWriteMode(true);
        Sync::setAccounts($accounts);
        $ana = $this->user('Marketing', 'ana@example.test');
        $this->connect($ana, self::TOKEN_ANA, $accounts);
        $this->actingAs($ana);

        return $ana;
    }

    private function sync(string $from, string $to): void
    {
        $this->post('/ads_manager/meta/sync', ['from' => $from, 'to' => $to])->assertRedirect('/ads_manager/report');
    }

    // ── SS-2 ─────────────────────────────────────────────────────────────

    public function test_SS2_one_request_per_account_per_day_with_a_smaller_page_and_each_ad_detail_asked_once(): void
    {
        $this->ready(['111', '222']);
        $this->graph(
            insights: [
                '111' => [self::D1 => [self::insight('111', 'a1', self::D1, 10)], self::D2 => [self::insight('111', 'a1', self::D2, 11), self::insight('111', 'a2', self::D2, 12)], self::D3 => [self::insight('111', 'a2', self::D3, 13)]],
                '222' => [self::D2 => [self::insight('222', 'b1', self::D2, 20)]],
            ],
            ads: ['111' => [self::adDetail('a1'), self::adDetail('a2')], '222' => [self::adDetail('b1')]],
        );

        $this->sync(self::D1, self::D3);

        foreach (['111', '222'] as $acc) {
            $days = array_map(fn ($c) => json_decode($c['query']['time_range'], true), $this->insightCalls($acc));
            $this->assertSame([['since' => self::D1, 'until' => self::D1], ['since' => self::D2, 'until' => self::D2], ['since' => self::D3, 'until' => self::D3]], $days, "act_{$acc}: one request per day");
            $this->assertSame(['100'], array_values(array_unique(array_column(array_column($this->insightCalls($acc), 'query'), 'limit'))));
        }
        $asked = array_map(fn ($c) => json_decode($c['query']['filtering'], true)[0]['value'],
            array_values(array_filter($this->calls, fn ($c) => $c['path'] === 'act_111/ads')));
        $this->assertSame([['a1'], ['a2']], $asked, 'each ad detail is asked once per run, on the first day it appears');
        $this->assertSame(5, count($this->reportRows()));
        $this->assertSame(['done', null, 5], [$this->log()->status, $this->log()->error_message, (int) $this->log()->inserted]);
        Sleep::assertNeverSlept();
    }

    public static function transientAnswers(): array
    {
        return ['Graph code 2 (HTTP 500)' => [2, 500], 'Graph code 1 (HTTP 500)' => [1, 500], 'HTTP 503 without a Graph code' => [0, 503]];
    }

    #[DataProvider('transientAnswers')]
    public function test_SS2_a_transient_error_then_success_is_tried_again_after_a_wait_and_the_run_is_done(int $code, int $status): void
    {
        $this->ready();
        $this->graph(insights: ['111' => [self::D1 => [self::insight('111', 'a1', self::D1, 10)]]], ads: ['111' => [self::adDetail('a1')]]);
        $this->answerError('111', self::D1, $code, $status);

        $this->sync(self::D1, self::D1);

        $this->assertCount(2, $this->insightCalls());
        Sleep::assertSequence([Sleep::for(2)->seconds()]);
        $this->assertSame(['a1'], array_column($this->reportRows(), 'ad_id'));
        $this->assertSame(['done', null], [$this->log()->status, $this->log()->error_message]);
    }

    public function test_SS2_still_failing_after_three_tries_goes_to_metas_async_report_job_for_that_account_day(): void
    {
        $this->ready();
        $this->graph(
            insights: [],
            ads: ['111' => [self::adDetail('a1')]],
            async: ['111' => ['status' => 'Job Completed', 'rows' => [self::insight('111', 'a1', self::D1, 42)]]],
        );
        $this->answerError('111', self::D1, 2, 500, 3);

        $this->sync(self::D1, self::D1);

        $this->assertCount(3, $this->insightCalls());
        $post = array_values(array_filter($this->calls, fn ($c) => $c['method'] === 'POST'));
        $this->assertCount(1, $post);
        $this->assertSame(['act_111/insights', 'ad', '{"since":"2026-10-20","until":"2026-10-20"}'], [$post[0]['path'], $post[0]['query']['level'], $post[0]['query']['time_range']]);
        $this->assertArrayNotHasKey('limit', $post[0]['query']);
        $this->assertContains('900111', array_column($this->calls, 'path'));
        $this->assertContains('900111/insights', array_column($this->calls, 'path'));
        $this->assertSame(['a1', '42'], [$this->reportRows()[0]['ad_id'], (string) (float) $this->reportRows()[0]['amount_spent_php']]);
        $this->assertSame(['done', null], [$this->log()->status, $this->log()->error_message]);
    }

    public function test_SS2_an_account_day_still_without_data_makes_the_run_partial_and_is_named_once(): void
    {
        $this->ready(['111', '222']);
        $this->graph(
            insights: ['111' => [self::D2 => [self::insight('111', 'a1', self::D2, 10)]], '222' => [self::D1 => [self::insight('222', 'b1', self::D1, 5)], self::D2 => [self::insight('222', 'b1', self::D2, 6)]]],
            ads: ['111' => [self::adDetail('a1')], '222' => [self::adDetail('b1')]],
            async: ['111' => ['status' => 'Job Failed']],
        );
        $this->answerError('111', self::D1, 2, 500, 3);

        $this->sync(self::D1, self::D2);

        $this->assertSame('partial', $this->log()->status);
        $this->assertSame('act_111: no data for Oct 20 (Graph error 2: Service temporarily unavailable; async report: job failed)', $this->log()->error_message,
            'one line for the account, naming the day, with the last error; never one line per retry');
        $this->assertSame(['b1', 'a1', 'b1'], array_column($this->reportRows(), 'ad_id'), 'the other day and the other account are written');
    }

    public function test_SS2_a_permanent_400_is_not_retried_and_the_accounts_other_days_are_not_asked(): void
    {
        $this->ready(['111', '222']);
        $this->graph(insights: ['222' => [self::D1 => [self::insight('222', 'b1', self::D1, 5)]]], ads: ['222' => [self::adDetail('b1')]]);
        $this->answerError('111', self::D1, 100, 400, 5);

        $this->sync(self::D1, self::D3);

        $this->assertCount(1, $this->insightCalls('111'), 'a permanent error is asked once');
        $this->assertCount(3, $this->insightCalls('222'));
        $this->assertEmpty(array_filter($this->calls, fn ($c) => $c['method'] === 'POST'), 'no async job for a permanent error');
        Sleep::assertNeverSlept();
        $this->assertSame(['partial', 'act_111: no data for Oct 20 to Oct 22 (Graph error 100: Invalid parameter)'], [$this->log()->status, $this->log()->error_message]);
        $this->assertSame(['b1'], array_column($this->reportRows(), 'ad_id'));
    }

    public function test_SS2_rows_are_written_the_same_way_unchanged_rows_keep_updated_at(): void
    {
        $this->ready();
        $this->graph(insights: ['111' => [self::D1 => [self::insight('111', 'a1', self::D1, 10)]]], ads: ['111' => [self::adDetail('a1')]]);
        $this->sync(self::D1, self::D1);
        DB::table('ads_manager_reports')->update(['updated_at' => '2026-10-20 08:00:00']);

        $this->sync(self::D1, self::D1);

        $this->assertSame('2026-10-20 08:00:00', (string) DB::table('ads_manager_reports')->value('updated_at'));
        $this->assertSame(0, (int) $this->log()->inserted);
    }

    // ── SS-1 ─────────────────────────────────────────────────────────────

    public function test_SS1_the_status_route_shows_a_queued_run_then_its_progress_while_running(): void
    {
        $this->ready(['111', '222', '333']);
        $log = Sync::startLog(self::D1, self::D3, auth()->id());

        $res = $this->getJson('/ads_manager/meta/sync/status?id=' . $log->id)->assertOk();
        $this->assertSame(['queued', $log->id, '2026-10-20 to 2026-10-22', 'Marketing User'], [$res->json('run.state'), $res->json('run.id'), $res->json('run.range'), $res->json('run.who')]);
        $this->assertStringContainsString('data-state="queued"', $res->json('html'));
        $this->assertStringContainsString('Waiting for the worker', $res->json('html'));

        $log->update(['status' => 'processing', 'started_at' => now()->subSeconds(75), 'processed_rows' => 120, 'inserted' => 100, 'updated' => 20]);
        Sync::progress($log->id, ['accounts_total' => 3, 'accounts_done' => 1, 'days_total' => 3, 'account' => 'Account 222']);

        $res = $this->getJson('/ads_manager/meta/sync/status')->assertOk();
        $this->assertSame(['running', 1, 3, 120, 100, 20, 'Account 222', '1 min 15 s'],
            [$res->json('run.state'), $res->json('run.accounts_done'), $res->json('run.accounts_total'), $res->json('run.rows'), $res->json('run.inserted'), $res->json('run.updated'), $res->json('run.account'), $res->json('run.duration')]);
        $html = preg_replace('/\s+/', ' ', $res->json('html'));
        $this->assertStringContainsString('Accounts 1 of 3 · rows so far 120 · +100 new / ~20 updated · reading Account 222', $html);
    }

    public function test_SS1_after_the_run_the_card_shows_the_result_with_one_named_line_per_account_and_collapses_long_lists(): void
    {
        $accounts = ['111', '222', '333', '444', '555'];
        $this->ready($accounts);
        $this->graph(insights: ['555' => [self::D1 => [self::insight('555', 'e1', self::D1, 5)]]], ads: ['555' => [self::adDetail('e1')]], async: []);
        foreach (['111', '222', '333', '444'] as $acc) {
            foreach ([self::D1, self::D2] as $day) $this->answerError($acc, $day, 2, 500, 3);
        }
        // The async job never finishes for these accounts (polled 24 times).

        $this->sync(self::D1, self::D2);

        $run = $this->getJson('/ads_manager/meta/sync/status')->assertOk()->json('run');
        $this->assertSame('partial', $run['state']);
        $this->assertCount(4, $run['problems'], 'one line per account, not per retry or per day');
        $this->assertSame(['act_111', 'Account 111'], [$run['problems'][0]['account'], $run['problems'][0]['name']]);
        $this->assertStringStartsWith('no data for Oct 20, Oct 21 (Graph error 2: Service temporarily unavailable; async report: not finished after 120 s)', $run['problems'][0]['error']);
        $this->assertNotNull($run['duration']);

        $html = $this->get('/ads_manager/report')->assertOk()->getContent();
        $this->assertSame(1, preg_match('#<div id="meta-sync-run-box">(.*?)</div>\s*<script>#s', $html, $box));
        $this->assertStringContainsString('data-state="partial"', $box[1]);
        $this->assertStringContainsString('Account 111 (act_111): no data for Oct 20, Oct 21', $box[1]);
        $this->assertSame(1, preg_match('#<details[^>]*data-run-more>\s*<summary[^>]*>1 more</summary>(.*?)</details>#s', $box[1], $more), 'the 4th line is collapsed');
        $this->assertStringContainsString('Account 444 (act_444)', $more[1]);
        $this->assertStringContainsString("fetch(run.dataset.statusUrl", $html, 'the card polls the status route');
    }

    public function test_SS1_a_scheduled_run_shows_in_the_same_last_run_box(): void
    {
        $this->ready();
        $this->graph(insights: ['111' => [self::D1 => [self::insight('111', 'a1', self::D1, 10)]]], ads: ['111' => [self::adDetail('a1')]]);

        Artisan::call('meta-ads:sync', ['--from' => self::D1, '--to' => self::D1]);

        $run = $this->getJson('/ads_manager/meta/sync/status')->assertOk()->json('run');
        $this->assertSame(['done', 'scheduled', 1, 1], [$run['state'], $run['who'], $run['inserted'], $run['rows']]);
        $html = preg_replace('/\s+/', ' ', $this->get('/ads_manager/report')->assertOk()->getContent());
        $this->assertStringContainsString('2026-10-20 to 2026-10-20 · scheduled', $html);
        $this->assertStringContainsString('+1 new / ~0 updated / skip 0', $html);
    }

    public function test_SS1_the_status_route_has_the_same_permission_as_the_card(): void
    {
        $this->ready();
        Sync::startLog(self::D1, self::D1, auth()->id());
        $this->actingAs($this->user('Data Encoder', 'de@example.test'));

        $this->getJson('/ads_manager/meta/sync/status')->assertNotFound();
        auth()->logout();
        $this->getJson('/ads_manager/meta/sync/status')->assertUnauthorized();
    }

    public function test_SS1_the_api_runs_table_shows_one_line_per_account_and_collapses_after_three(): void
    {
        $this->ready();
        UploadLog::create(['type' => Sync::LOG_TYPE, 'disk' => 'api', 'path' => 'meta', 'original_name' => 'Meta API x', 'status' => 'partial',
            'error_message' => "act_1: a\nact_2: b\nact_3: c\nact_4: d\nNo page name, skipped: ad 9 (page 8)"]);
        // A run from before spec 288 joined its lines with "; ".
        UploadLog::create(['type' => Sync::LOG_TYPE, 'disk' => 'api', 'path' => 'meta', 'original_name' => 'Meta API old', 'status' => 'partial',
            'error_message' => 'act_7: rate limited, skipped; act_8: Graph error 2: x']);

        $html = $this->get('/ads_manager/report')->assertOk()->getContent();
        preg_match_all('#<td class="p-1 text-red-700" data-log-errors>(.*?)</td>#s', $html, $cells);
        $this->assertSame(['act_7: rate limited, skipped', 'act_8: Graph error 2: x'], $this->lines($cells[1][0]));
        $this->assertSame(['act_1: a', 'act_2: b', 'act_3: c'], array_slice($this->lines($cells[1][1]), 0, 3));
        $this->assertMatchesRegularExpression('#<summary[^>]*>2 more</summary>\s*<div>act_4: d</div><div>No page name, skipped: ad 9 \(page 8\)</div>#', $cells[1][1]);
    }

    private function lines(string $cell): array
    {
        preg_match_all('#<div>(.*?)</div>#', $cell, $m);

        return $m[1];
    }
}

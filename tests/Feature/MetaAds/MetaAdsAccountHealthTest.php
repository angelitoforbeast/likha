<?php

namespace Tests\Feature\MetaAds;

use App\Services\MetaAds\AccountHealth;
use App\Services\MetaAds\Connections;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Spec 284: the Ad Accounts page (balance, spend, spend cap, payment method, health) and its warnings. The Graph is
 * faked per token: no call leaves the test (preventStrayRequests in MetaAdsTestCase).
 */
class MetaAdsAccountHealthTest extends MetaAdsTestCase
{
    private const PAGE = '/ads_manager/meta/ad-accounts';
    private const REFRESH = '/ads_manager/meta/ad-accounts/refresh';

    /** @var array{0: array, 1: array} the answers of the fake below; a later fakeAccounts() call replaces them */
    private array $answers = [[], []];
    private bool $faked = false;

    /** One table row of the page, up to its </tr>. */
    private static function tr(string $html, string $id): string
    {
        $start = strpos($html, '<tr data-account="' . $id . '"');

        return $start === false ? '' : substr($html, $start, strpos($html, '</tr>', $start) - $start);
    }

    /**
     * $byToken: token => list of me/adaccounts rows, or ['error' => code, 'message' => ...]. $fundingRefused: tokens whose
     * call is refused while funding_source_details is asked.
     */
    private function fakeAccounts(array $byToken, array $fundingRefused = []): void
    {
        $this->answers = [$byToken, $fundingRefused];
        if ($this->faked) return; // Http::fake callbacks stack and the first one answers
        $this->faked = true;
        Http::fake(function (Request $req) {
            [$byToken, $fundingRefused] = $this->answers;
            $path  = trim(preg_replace('#^/v25\.0#', '', (string) parse_url($req->url(), PHP_URL_PATH)), '/');
            parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);
            $token = trim(str_replace('Bearer', '', $req->header('Authorization')[0] ?? ''));
            $this->graphCalls[] = $path;
            $this->graphRequests[] = ['path' => $path, 'query' => $q, 'token' => $token, 'url' => $req->url()];

            if ($path !== 'me/adaccounts') return Http::response(['error' => ['message' => 'unexpected ' . $path, 'code' => 100]], 400);
            $answer = $byToken[$token] ?? [];
            if (isset($answer['error'])) {
                return Http::response(['error' => ['message' => $answer['message'] ?? 'Error', 'type' => 'OAuthException', 'code' => $answer['error']]],
                    $answer['error'] === 190 ? 401 : 400);
            }
            if (in_array($token, $fundingRefused, true) && str_contains($q['fields'] ?? '', 'funding_source_details')) {
                return Http::response(['error' => ['message' => '(#100) Requires MANAGE on the ad account for funding_source_details', 'code' => 100]], 400);
            }
            if (!str_contains($q['fields'] ?? '', 'funding_source_details')) {
                $answer = array_map(function ($a) { unset($a['funding_source_details']); return $a; }, $answer);
            }

            return Http::response(['data' => array_values($answer)]);
        });
    }

    private static function acc(string $id, array $extra = []): array
    {
        return array_replace([
            'id' => 'act_' . $id, 'account_id' => $id, 'name' => 'Likha ' . $id, 'account_status' => 1, 'disable_reason' => 0,
            'currency' => 'PHP', 'balance' => '0', 'amount_spent' => '0', 'spend_cap' => '0',
            'funding_source_details' => ['id' => 'fs' . $id, 'display_string' => 'Visa *' . substr('000' . $id, -4), 'type' => 1],
        ], $extra);
    }

    private function meta(): array
    {
        return array_values(array_filter($this->graphCalls, fn ($p) => $p === 'me/adaccounts'));
    }

    private function asCeo(): \App\Models\User
    {
        $ceo = $this->user('CEO', 'ceo@example.test');
        $this->actingAs($ceo);

        return $ceo;
    }

    // ---- AA-1 ----

    public function test_AA_1_H_five_accounts_show_every_field_with_money_in_two_decimals(): void
    {
        $ana = $this->user('Marketing', 'ana@example.test');
        $this->connect($ana, self::TOKEN_ANA, ['111', '222', '333', '444', '555']);
        $this->fakeAccounts([self::TOKEN_ANA => [
            self::acc('111', ['balance' => '123456', 'amount_spent' => '98765432', 'spend_cap' => '200000000']),
            self::acc('222', ['balance' => '5', 'amount_spent' => '1000']),
            self::acc('333', ['currency' => 'USD', 'balance' => '250', 'amount_spent' => '199', 'spend_cap' => '1000']),
            self::acc('444', ['account_status' => 2, 'disable_reason' => 3]),
            self::acc('555', ['account_status' => 101]),
        ]]);
        $this->asCeo();

        $html = $this->get(self::PAGE)->assertOk()->getContent();

        foreach (['111', '222', '333', '444', '555'] as $id) {
            $this->assertSame(1, substr_count($html, '<tr data-account="' . $id . '"'), "one table row for {$id}");
            $this->assertStringContainsString('Likha ' . $id, $html);
            $this->assertStringContainsString('Visa *0' . $id, $html);
        }
        $row = fn ($id) => self::tr($html, $id);
        // 123456 centavos = PHP 1,234.56; 98765432 = 987,654.32; cap 200000000 = 2,000,000.00, 1,012,345.68 left, 49.3% used (rounded down).
        $this->assertStringContainsString('PHP 1,234.56', $row('111'));
        $this->assertStringContainsString('PHP 987,654.32', $row('111'));
        $this->assertStringContainsString('PHP 2,000,000.00', $row('111'));
        $this->assertStringContainsString('PHP 1,012,345.68 left · 49.3% used', $row('111'));
        $this->assertStringContainsString('Marketing User FB', $row('111'));
        $this->assertStringContainsString('Active', $row('111'));
        // 5 centavos = 0.05; cap 0 = No limit and no % used.
        $this->assertStringContainsString('PHP 0.05', $row('222'));
        $this->assertStringContainsString('PHP 10.00', $row('222'));
        $this->assertStringContainsString('No limit', $row('222'));
        $this->assertStringNotContainsString('% used', $row('222'));
        $this->assertStringContainsString('USD 2.50', $row('333'));
        $this->assertStringContainsString('USD 8.01 left · 19.9% used', $row('333'));
        $this->assertStringContainsString('Disabled', $row('444'));
        $this->assertStringContainsString('Reason: Payment risk', $row('444'));
        $this->assertStringContainsString('Closed', $row('555'));
        $this->assertStringContainsString('Read at', $html);
        $this->assertStringNotContainsString(self::TOKEN_ANA, $html);
        $this->assertSame(1, count($this->meta()), 'one Graph call for the one connection');
        $q = $this->graphRequests[0]['query'];
        $this->assertSame('account_id,name,account_status,disable_reason,currency,balance,amount_spent,spend_cap,funding_source_details', $q['fields']);
    }

    public function test_AA_1_minor_units_are_converted_by_the_currency_offset_without_floats(): void
    {
        $this->assertSame('1,234.56', AccountHealth::money('123456', 'PHP'));
        $this->assertSame('0.05', AccountHealth::money('5', 'PHP'));
        $this->assertSame('0.00', AccountHealth::money('0', 'USD'));
        $this->assertSame('-12.30', AccountHealth::money('-1230', 'USD'));
        // 2^53 + 1 centavos: a float would lose the last digit.
        $this->assertSame('90,071,992,547,409.93', AccountHealth::money('9007199254740993', 'PHP'));
        // Offset-1 currencies (Meta's currency table): the amount is already whole units.
        $this->assertSame('1,500.00', AccountHealth::money('1500', 'JPY'));
        $this->assertSame('25,000,000.00', AccountHealth::money('25000000', 'VND'));
        $this->assertNull(AccountHealth::money(null, 'PHP'));
        $this->assertNull(AccountHealth::money('12.5', 'PHP'));
        $this->assertNull(AccountHealth::money('100', null));
    }

    public function test_AA_1_N_no_meta_connection_shows_the_empty_state_and_calls_nothing(): void
    {
        $this->fakeAccounts([]);
        $this->asCeo();

        $this->get(self::PAGE)->assertOk()->assertSee('No Meta connection on this site.')->assertDontSee('Refresh now');

        $this->assertSame([], $this->graphCalls);
    }

    public function test_AA_1_N_payment_method_refused_retries_without_it_and_lists_the_reason_once(): void
    {
        $ana = $this->user('Marketing', 'ana@example.test');
        $this->connect($ana, self::TOKEN_ANA, ['111', '222']);
        $this->fakeAccounts([self::TOKEN_ANA => [self::acc('111', ['balance' => '1000']), self::acc('222')]], [self::TOKEN_ANA]);
        $this->asCeo();

        $html = $this->get(self::PAGE)->assertOk()->getContent();

        $this->assertStringContainsString('PHP 10.00', $html);
        $this->assertStringNotContainsString('Visa', $html);
        $this->assertSame(2 * 2, substr_count($html, '>not available<'), 'payment cell, table and card, for both rows');
        $this->assertSame(1, substr_count($html, 'Requires MANAGE on the ad account'), 'the reason once');
        $this->assertSame(2, count($this->meta()));
        $this->assertStringNotContainsString('funding_source_details', $this->graphRequests[1]['query']['fields']);
    }

    public function test_AA_1_N_a_field_missing_from_one_account_shows_not_available_and_the_page_still_renders(): void
    {
        $ana = $this->user('Marketing', 'ana@example.test');
        $this->connect($ana, self::TOKEN_ANA, ['111', '222']);
        $odd = self::acc('222', ['balance' => 'abc', 'name' => ['x']]);
        unset($odd['amount_spent'], $odd['funding_source_details'], $odd['currency']);
        $this->fakeAccounts([self::TOKEN_ANA => [self::acc('111', ['balance' => '700']), $odd]]);
        $this->asCeo();

        $html = $this->get(self::PAGE)->assertOk()->getContent();
        $row = self::tr($html, '222');

        $this->assertStringContainsString('PHP 7.00', $html);
        $this->assertGreaterThanOrEqual(4, substr_count($row, 'not available'));
        $this->assertStringContainsString('Not returned by Meta: amount spent', $html);
        $this->assertStringContainsString('Not returned by Meta: payment method', $html);
    }

    public function test_AA_1_E_the_same_account_through_two_connections_is_one_row_naming_both(): void
    {
        $ana = $this->user('Marketing', 'ana@example.test');
        $ben = $this->user('Marketing - OIC', 'ben@example.test');
        $this->connect($ana, self::TOKEN_ANA, ['111', '333']);
        $this->connect($ben, self::TOKEN_BEN, ['333']);
        $this->fakeAccounts([self::TOKEN_ANA => [self::acc('111'), self::acc('333')], self::TOKEN_BEN => [self::acc('333')]]);
        $this->asCeo();

        $html = $this->get(self::PAGE)->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '<tr data-account="333"'));
        $row = self::tr($html, '333');
        $this->assertStringContainsString('Marketing User FB, Marketing - OIC User FB', $row);
        $this->assertSame(2, count($this->meta()), 'one call per connection');
    }

    public function test_AA_1_E_an_expired_token_is_named_on_its_rows_and_the_connection_is_marked(): void
    {
        $ana = $this->user('Marketing', 'ana@example.test');
        $ben = $this->user('Marketing - OIC', 'ben@example.test');
        $this->connect($ana, self::TOKEN_ANA, ['111']);
        $this->connect($ben, self::TOKEN_BEN, ['222']);
        $this->fakeAccounts([
            self::TOKEN_ANA => [self::acc('111')],
            self::TOKEN_BEN => ['error' => 190, 'message' => 'Error validating access token: Session has expired'],
        ]);
        $this->asCeo();

        $html = $this->get(self::PAGE)->assertOk()->getContent();

        $row = self::tr($html, '222');
        $this->assertStringContainsString('Token expired: Marketing - OIC User FB (Reconnect)', $row);
        $this->assertSame('expired', app(Connections::class)->get($ben->id)['status']);
        $this->assertStringNotContainsString('Token expired', self::tr($html, '111'));
    }

    // ---- AA-2 ----

    public function test_AA_2_H_an_account_in_grace_period_is_a_red_line_on_both_pages(): void
    {
        $ana = $this->user('Marketing', 'ana@example.test');
        $this->connect($ana, self::TOKEN_ANA, ['111', '222', '333']);
        $this->fakeAccounts([self::TOKEN_ANA => [
            self::acc('111', ['account_status' => 9, 'name' => 'Grace Shop']),
            self::acc('222', ['amount_spent' => '9200', 'spend_cap' => '10000', 'name' => 'Near Cap']),
            self::acc('333', ['name' => 'Healthy']),
        ]]);
        $this->asCeo();

        $page = $this->get(self::PAGE)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#data-warning="red"[^>]*>\s*⚠ <span[^>]*>Grace Shop</span> <span[^>]*>111</span>: In grace period#u', $page);
        $this->assertMatchesRegularExpression('#data-warning="amber"[^>]*>\s*⚠ <span[^>]*>Near Cap</span> <span[^>]*>222</span>: spend cap 92% used#u', $page);
        $this->assertStringNotContainsString('Healthy</span>', $page);

        $calls = count($this->graphCalls);
        $report = $this->get('/ads_manager/report')->assertOk()->getContent();
        $this->assertSame($calls, count($this->graphCalls), 'the report page never calls Meta');
        $line = substr($report, strpos($report, 'id="ad-accounts-warning"') - 200, 1500);
        $this->assertStringContainsString('border-red-300', $line);
        $this->assertStringContainsString('Grace Shop (In grace period)', $line);
        $this->assertStringContainsString('Near Cap (spend cap 92% used)', $line);
        $this->assertStringContainsString('href="' . url(self::PAGE) . '"', $line);
        $this->assertMatchesRegularExpression('#read \w{3} \d{1,2}, \d{4} \d{1,2}:\d{2} [AP]M#', $line);
    }

    public function test_AA_2_unsettled_disabled_and_cap_reached_are_red(): void
    {
        $rows = ['rows' => [
            ['id' => '1', 'name' => 'U', 'status' => 3, 'currency' => 'PHP', 'amount_spent' => '0', 'spend_cap' => '0'],
            ['id' => '2', 'name' => 'D', 'status' => 2, 'currency' => 'PHP'],
            ['id' => '3', 'name' => 'C', 'status' => 1, 'currency' => 'PHP', 'amount_spent' => '10000', 'spend_cap' => '10000'],
            ['id' => '4', 'name' => 'A', 'status' => 1, 'currency' => 'PHP', 'amount_spent' => '8999', 'spend_cap' => '10000'],
        ]];

        $this->assertSame([
            ['id' => '1', 'name' => 'U', 'level' => 'red', 'warning' => 'Unsettled'],
            ['id' => '2', 'name' => 'D', 'level' => 'red', 'warning' => 'Disabled'],
            ['id' => '3', 'name' => 'C', 'level' => 'red', 'warning' => 'spend cap 100% used'],
        ], AccountHealth::warnings($rows));
    }

    public function test_AA_2_N_all_healthy_shows_no_warning_line(): void
    {
        $ana = $this->user('Marketing', 'ana@example.test');
        $this->connect($ana, self::TOKEN_ANA, ['111', '222']);
        $this->fakeAccounts([self::TOKEN_ANA => [self::acc('111'), self::acc('222', ['amount_spent' => '100', 'spend_cap' => '1000'])]]);
        $this->asCeo();

        $this->get(self::PAGE)->assertOk()->assertDontSee('data-warning=', false)->assertSee('Likha 222');
        $this->get('/ads_manager/report')->assertOk()->assertDontSee('id="ad-accounts-warning"', false)->assertSee('id="ad-accounts-link"', false);
    }

    // ---- AA-3 ----

    public function test_AA_3_a_fresh_stored_read_makes_no_graph_call_on_page_load(): void
    {
        $ana = $this->user('Marketing', 'ana@example.test');
        $this->connect($ana, self::TOKEN_ANA, ['111']);
        $this->fakeAccounts([self::TOKEN_ANA => [self::acc('111', ['balance' => '4200'])]]);
        $this->asCeo();

        $this->get(self::PAGE)->assertOk()->assertSee('PHP 42.00');
        $this->assertCount(1, $this->meta());

        $this->travel(9)->minutes();
        $this->get(self::PAGE)->assertOk()->assertSee('PHP 42.00');
        $this->get(self::PAGE)->assertOk();
        $this->assertCount(1, $this->meta(), 'no Graph call while the read is under 10 minutes old');

        $this->travel(2)->minutes();
        $this->get(self::PAGE)->assertOk();
        $this->assertCount(2, $this->meta(), 'read again once it is older than 10 minutes');
    }

    public function test_AA_3_another_read_in_progress_shows_the_stored_read_without_calling(): void
    {
        $ana = $this->user('Marketing', 'ana@example.test');
        $this->connect($ana, self::TOKEN_ANA, ['111']);
        $this->fakeAccounts([self::TOKEN_ANA => [self::acc('111')]]);
        $this->asCeo();
        $lock = Cache::lock(AccountHealth::KEY, 60);
        $this->assertTrue($lock->get());

        $this->get(self::PAGE)->assertOk()->assertSee('No ad account could be read yet.');
        $this->post(self::REFRESH)->assertRedirect(self::PAGE)->assertSessionHas('status', 'A read is already running: showing the last read.');

        $this->assertSame([], $this->graphCalls);
        $lock->release();
    }

    public function test_AA_3_E_api_blocked_shows_the_last_good_read_with_its_time_and_the_error(): void
    {
        $ana = $this->user('Marketing', 'ana@example.test');
        $this->connect($ana, self::TOKEN_ANA, ['111']);
        $this->fakeAccounts([self::TOKEN_ANA => [self::acc('111', ['balance' => '31415'])]]);
        $this->asCeo();
        $this->travelTo(now()->setTimezone('Asia/Manila')->setTime(9, 5));
        $this->get(self::PAGE)->assertOk();

        $this->travel(30)->minutes();
        $this->fakeAccounts([self::TOKEN_ANA => ['error' => 200, 'message' => '(#200) API access blocked.']]);
        $html = $this->get(self::PAGE)->assertOk()->getContent();

        $this->assertStringContainsString('PHP 314.15', $html);
        $this->assertStringContainsString('9:05 AM', $html, 'the time of the last good read');
        $this->assertStringContainsString('Marketing User FB: Graph error 200: (#200) API access blocked.', $html);
        $this->assertStringContainsString('Not read through Marketing User FB', $html);

        // The failed attempt counts for the 10 minutes too: the blocked API is not hit on every load.
        $calls = count($this->meta());
        $this->get(self::PAGE)->assertOk()->assertSee('PHP 314.15');
        $this->assertSame($calls, count($this->meta()));
    }

    public function test_AA_3_E_a_rate_limit_does_not_wait_or_retry_in_the_request(): void
    {
        $ana = $this->user('Marketing', 'ana@example.test');
        $this->connect($ana, self::TOKEN_ANA, ['111']);
        $this->fakeAccounts([self::TOKEN_ANA => ['error' => 17, 'message' => 'User request limit reached']]);
        $this->asCeo();

        $this->get(self::PAGE)->assertOk()->assertSee('User request limit reached');

        $this->assertCount(1, $this->meta(), 'one try, no back-off, no retry without the payment field');
        \Illuminate\Support\Sleep::assertNeverSlept();
    }

    public function test_AA_3_refresh_now_reads_even_when_fresh_for_anyone_with_ads_access_without_write_mode(): void
    {
        $ana = $this->user('Marketing', 'ana@example.test');
        $this->connect($ana, self::TOKEN_ANA, ['111']);
        $this->fakeAccounts([self::TOKEN_ANA => [self::acc('111')]]);
        $this->actingAs($ana);
        $this->assertFalse(\App\Services\MetaAds\Sync::writeMode());

        $this->get(self::PAGE)->assertOk()->assertSee('Refresh now');
        $this->post(self::REFRESH)->assertRedirect(self::PAGE)->assertSessionHas('status', 'Ad accounts read from Meta.');

        $this->assertCount(2, $this->meta());
    }

    public function test_AA_3_the_hourly_schedule_reads_only_when_stale_and_the_scheduled_sync_reads_after_its_run(): void
    {
        $ana = $this->user('Marketing', 'ana@example.test');
        $this->connect($ana, self::TOKEN_ANA, ['111']);
        $this->fakeAccounts([self::TOKEN_ANA => [self::acc('111', ['account_status' => 9])]]);

        Artisan::call('meta-ads:account-health');
        Artisan::call('meta-ads:account-health');
        $this->assertCount(1, $this->meta(), 'the second run within 10 minutes reads nothing');
        $this->assertSame('In grace period', AccountHealth::warnings(AccountHealth::stored())[0]['warning']);

        Artisan::call('schedule:list'); // loads routes/console.php
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) $e->command, 'meta-ads:account-health'));
        $this->assertCount(1, $events);
        $this->assertSame('0 * * * *', $events->first()->expression);
    }

    public function test_AA_3_the_scheduled_sync_refreshes_the_stored_read_after_its_run(): void
    {
        $ana = $this->user('Marketing', 'ana@example.test');
        $this->connect($ana, self::TOKEN_ANA, ['111']);
        \App\Services\MetaAds\Sync::setWriteMode(true);
        $this->travelTo(\Carbon\Carbon::parse('2026-11-17 10:00:00', 'Asia/Manila'));
        Http::fake(function (Request $req) {
            $path = trim(preg_replace('#^/v25\.0#', '', (string) parse_url($req->url(), PHP_URL_PATH)), '/');
            $this->graphCalls[] = $path;
            if ($path === 'me/adaccounts') return Http::response(['data' => [self::acc('111', ['balance' => '999'])]]);

            return Http::response(['data' => []]);
        });

        Artisan::call('meta-ads:sync', ['--scheduled' => true]);

        $this->assertContains('act_111/insights', $this->graphCalls);
        $this->assertSame('999', AccountHealth::stored()['rows']['111']['balance']);
    }

    // ---- AA-4 ----

    public function test_AA_4_a_role_without_the_ads_link_gets_404_and_sees_no_balance_or_warning(): void
    {
        $ana = $this->user('Marketing', 'ana@example.test');
        $this->connect($ana, self::TOKEN_ANA, ['111']);
        $this->fakeAccounts([self::TOKEN_ANA => [self::acc('111', ['account_status' => 9, 'balance' => '123456'])]]);
        $this->actingAs($ana);
        $this->get(self::PAGE)->assertOk()->assertSee('PHP 1,234.56');
        $calls = count($this->graphCalls);

        $this->actingAs($this->user('Data Encoder', 'de@example.test'));
        $this->get(self::PAGE)->assertNotFound();
        $this->post(self::REFRESH)->assertNotFound();
        $this->get('/ads_manager/report')->assertOk()
            ->assertDontSee('id="ad-accounts-warning"', false)->assertDontSee('1,234.56')->assertDontSee('Visa')->assertDontSee('id="ad-accounts-link"', false);
        $this->assertSame($calls, count($this->graphCalls));
    }

    public function test_AA_4_guests_are_sent_to_login(): void
    {
        $this->get(self::PAGE)->assertRedirect();
        $this->post(self::REFRESH)->assertRedirect();
        $this->assertSame([], $this->graphCalls);
    }

    public function test_AA_4_the_stored_read_holds_no_token(): void
    {
        $ana = $this->user('Marketing', 'ana@example.test');
        $this->connect($ana, self::TOKEN_ANA, ['111']);
        $this->fakeAccounts([self::TOKEN_ANA => ['error' => 1, 'message' => 'Bad call access_token=' . self::TOKEN_ANA]]);
        $this->asCeo();

        $html = $this->get(self::PAGE)->assertOk()->getContent();

        $this->assertStringNotContainsString(self::TOKEN_ANA, $html);
        $this->assertStringNotContainsString(self::TOKEN_ANA, (string) DB::table('app_settings')->where('key', AccountHealth::KEY)->value('value'));
    }
}

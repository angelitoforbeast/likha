<?php

namespace Tests\Feature\OrderEncode;

use App\Services\OrderEncode\Checker;
use App\Services\OrderEncode\Steps;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** 1.0 OUTPUT: R, S, ang mga listahan, ang pag-ayos ng GPT sa bawat S, W at ang pangalan/address. Fake ang OpenAI. */
class CheckerTest extends TestCase
{
    private Steps $steps;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->steps = new Steps(Fixtures::KEY);
    }

    /** Ang row ng DATABASE; ang M ay ang CXD na pinaghahambingan ng S. */
    private function row(array $over = []): array
    {
        return $over + ['A' => '10:00 23-10-2026', 'B' => 'Ana Cruz', 'C' => '9171234567', 'D' => 'Blk 1', 'E' => 'CEBU', 'F' => 'CEBU CITY', 'G' => 'LAHUG',
            'H' => '2 x TEA', 'I' => '499', 'J' => 'Page Uno', 'K' => 'chat of the customer', 'L' => 'shop', 'M' => 'Lahug, Cebu City, Cebu', 'N' => '',
            'R' => '', 'S' => '', 'T' => '', 'U' => '', 'V' => '', 'W' => ''];
    }

    private function check(array $row): Checker
    {
        $c = new Checker($row, Fixtures::locations(), Fixtures::gpt($this->steps), $this->steps);
        $c->run();

        return $c;
    }

    /** @param array<int, array> $answers ang JSON ng bawat tawag, ayon sa ayos (Responses API) */
    private function answers(array $answers): void
    {
        $seq = Http::sequence();
        foreach ($answers as $a) $seq->push(Fixtures::responses(json_encode($a)));
        Http::fake(['api.openai.com/v1/responses' => $seq]);
    }

    private function statuses(): array
    {
        return array_column($this->steps->log, 'status', 'step');
    }

    public function test_a_row_that_matches_the_chat_is_a_check_and_needs_no_gpt(): void
    {
        $c = $this->check($this->row());

        $this->assertSame(['✅', '✅', ''], [$c->cells['R'], $c->cells['S'], $c->cells['W']]);
        $this->assertSame(0, $this->steps->requests());
        $this->assertSame('SKIP:ALREADY_FILLED', $this->statuses()['name_addr']);
    }

    public function test_a_filled_n_forces_full_address_without_the_fuzzy_match(): void
    {
        $this->answers([['province' => 'CEBU'], ['city' => 'CEBU CITY'], ['barangay' => 'LAHUG']]);

        $c = $this->check($this->row(['N' => 'Barangay']));

        $this->assertSame('✅', $c->cells['S']); // ang chain ng Full Address, saka nai-recompute; ✅ na kaya walang verify
        $this->assertSame(3, $this->steps->requests());
    }

    public static function rScores(): array
    {
        $m = "page: Page Uno\nprice: ₱499\nquantity: 1\n";

        return [
            'no page: line is a check' => ['order of 2 x tea', '✅'],
            'all three' => ["{$m}2 x tea", '✅', ['J' => '1']],
            'page and quantity (5)' => ["page: x 2 x tea\nprice: ₱1\nquantity: 1", 'Quantity and Price', ['J' => '1']],
            'quantity only (4)' => ["page: x\nprice: ₱1\nquantity: 1", 'Page Name and Price', ['J' => '1']],
            'page and price (3)' => ["page: 2 x tea\nprice: ₱499\nquantity: 9", 'Quantity', ['J' => '1']],
            'price only (2)' => ["page: x\nprice: ₱499\nquantity: 9", 'Page Name and Quantity', ['J' => '1']],
            'page only (1)' => ["page: 2 x tea\nprice: 1\nquantity: 9", 'Price and Quantity', ['J' => '1']],
            'none' => ["page: x\nprice: 1\nquantity: 9", '❌', ['J' => '1']],
            'current layout: J holds the page name, so quantity never matches' => ["page: 2 x tea\nprice: ₱499\nquantity: 1", 'Quantity', []],
        ];
    }

    #[DataProvider('rScores')]
    public function test_page_price_quantity_checker_reads_the_old_columns(string $m, string $expected, array $over = []): void
    {
        $this->answers([]);
        Http::fake(['*' => Http::response(Fixtures::responses('{"province_ok":true,"city_ok":true,"barangay_ok":true}'))]);

        $c = $this->check($this->row(['M' => $m . ' lahug cebu city cebu'] + $over));

        $this->assertSame($expected, $c->cells['R']);
    }

    public static function sValues(): array
    {
        return [
            'barangay missing' => [['M' => 'Cebu City, Cebu'], 'Barangay'],
            'city missing' => [['M' => 'Lahug, Cebu', 'F' => 'MANDAUE CITY'], 'City'],
            'city and barangay missing' => [['M' => 'Cebu', 'F' => 'MANDAUE CITY', 'G' => 'XYZ'], 'City and Barangay'],
            'province missing' => [['M' => 'Lahug, Cebu City', 'E' => 'ABRA'], 'Province'],
            'everything missing' => [['M' => 'somewhere far', 'E' => 'ABRA'], 'Full Address'],
            'ncr counts as manila' => [['M' => 'Poblacion, Makati City, NCR', 'E' => 'METRO MANILA', 'F' => 'MAKATI CITY', 'G' => 'POBLACION'], '✅'],
        ];
    }

    #[DataProvider('sValues')]
    public function test_s_is_classified_from_the_three_fuzzy_matches(array $over, string $expected): void
    {
        // Walang sagot ang OpenAI (500), kaya walang naisusulat na pagbabago: ang S ay ang unang klase.
        Http::fake(['*' => Http::response('{}', 500)]);

        $this->assertSame($expected, $this->check($this->row($over))->cells['S']);
    }

    public function test_barangay_chain_writes_a_barangay_that_is_in_the_list_and_rechecks(): void
    {
        $this->answers([['barangay' => 'BANILAD'], ['province_ok' => true, 'city_ok' => true, 'barangay_ok' => true]]);

        $c = $this->check($this->row(['M' => 'Banilad, Cebu City, Cebu']));

        $this->assertSame('✅', $c->cells['S']);
        $this->assertSame('BANILAD', $c->cells['G']);
        $this->assertSame('BANILAD, LAHUG', $c->cells['T']);
        $this->assertSame('WROTE_BRGY', $this->statuses()['brgy_fix']);
        $this->assertSame(1, $this->steps->requests());
    }

    public static function barangayAnswers(): array
    {
        return [
            'not in the list' => [['barangay' => 'MARS'], 'BRGY_NOT_IN_LIST'],
            'unknown' => [['barangay' => 'UNKNOWN'], 'NO_CHANGE'],
            'same as before' => [['barangay' => 'LAHUG'], 'NO_CHANGE'],
        ];
    }

    #[DataProvider('barangayAnswers')]
    public function test_barangay_chain_does_not_write_when_the_answer_is_not_usable(array $answer, string $status): void
    {
        $this->answers([$answer, ['province_ok' => true, 'city_ok' => true, 'barangay_ok' => false]]);

        $c = $this->check($this->row(['M' => 'Cebu City, Cebu']));

        $this->assertSame('LAHUG', $c->cells['G']);
        $this->assertSame($status, $this->statuses()['brgy_fix']);
        $this->assertSame('Barangay', $c->cells['W']);
    }

    public function test_a_gpt_http_error_is_logged_like_the_script_and_the_row_stays(): void
    {
        Http::fake(['*' => Http::response('{"error":{"message":"Incorrect API key provided: ' . Fixtures::KEY . '"}}', 401)]);

        $c = $this->check($this->row(['M' => 'Cebu City, Cebu']));

        $this->assertStringStartsWith('ERR_BRGY:OpenAI API error: 401', $this->statuses()['brgy_fix']);
        $this->assertSame('LAHUG', $c->cells['G']);
        $this->assertSame('Full Address', $c->cells['W']);
        $this->assertStringStartsWith('ERR_VERIFYK:', $this->statuses()['verify_w']);
        $this->assertStringNotContainsString(Fixtures::KEY, json_encode($this->steps->log));
    }

    public function test_the_responses_api_failing_falls_back_to_chat_completions(): void
    {
        Http::fake([
            'api.openai.com/v1/responses' => Http::response('nope', 400),
            'api.openai.com/v1/chat/completions' => Http::response(Fixtures::chat('{"barangay":"BANILAD"}')),
        ]);

        $c = $this->check($this->row(['M' => 'Banilad, Cebu City, Cebu', 'W' => '']));

        $this->assertSame('BANILAD', $c->cells['G']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/chat/completions') && str_ends_with($r['messages'][1]['content'], "\nReturn JSON only.") && $r['temperature'] === 0);
    }

    public function test_city_chain_picks_a_city_from_the_list_then_checks_the_barangay_belongs(): void
    {
        $this->answers([['city' => 'CEBU CITY']]);

        $c = $this->check($this->row(['M' => 'Lahug, Cebu', 'F' => 'MANDAUE CITY']));

        $this->assertSame(['CEBU CITY', '✅', ''], [$c->cells['F'], $c->cells['S'], $c->cells['W']]);
        $this->assertSame('CEBU CITY, MANDAUE CITY', $c->cells['U']);
        $this->assertSame('BANILAD, LAHUG', $c->cells['T']);
        $this->assertSame(1, $this->steps->requests());
    }

    public function test_city_chain_stops_when_the_city_is_not_in_the_list(): void
    {
        $this->answers([['city' => 'ATLANTIS'], ['province_ok' => true, 'city_ok' => false, 'barangay_ok' => true]]);

        $this->check($this->row(['M' => 'Lahug, Cebu', 'F' => 'MANDAUE CITY']));

        $this->assertSame('CITY_NOT_IN_LIST', $this->statuses()['city_fix']);
    }

    public function test_city_and_barangay_chain_fixes_the_city_then_the_barangay(): void
    {
        $this->answers([['city' => 'MANDAUE CITY'], ['barangay' => 'BAKILID']]);

        $c = $this->check($this->row(['M' => 'Bakilid, Mandaue City, Cebu', 'F' => 'TALISAY CITY', 'G' => 'XYZ']));

        $this->assertSame(['MANDAUE CITY', 'BAKILID', '✅'], [$c->cells['F'], $c->cells['G'], $c->cells['S']]);
        $this->assertSame('BAKILID, BARANGAY 5', $c->cells['T']);
    }

    public function test_full_address_chain_fixes_province_city_and_barangay(): void
    {
        $this->answers([['province' => 'Metro Manila'], ['city' => 'QUEZON CITY'], ['barangay' => 'BAGUMBAYAN']]);

        $c = $this->check($this->row(['M' => 'Bagumbayan, Quezon City, NCR', 'E' => 'CEBU', 'F' => 'X', 'G' => 'Y']));

        $this->assertSame(['METRO MANILA', 'QUEZON CITY', 'BAGUMBAYAN', '✅'], [$c->cells['E'], $c->cells['F'], $c->cells['G'], $c->cells['S']]);
        $this->assertSame('QUEZON CITY, MAKATI CITY' === $c->cells['U'] || 'MAKATI CITY, QUEZON CITY' === $c->cells['U'], true);
        $this->assertStringContainsString('ABRA, CEBU, METRO MANILA', $c->cells['V']);
        $this->assertSame(3, $this->steps->requests());
    }

    public function test_full_address_chain_stops_when_the_province_is_not_in_the_list(): void
    {
        $this->answers([['province' => 'NARNIA'], ['city' => 'ATLANTIS']]);

        $c = $this->check($this->row(['M' => 'somewhere', 'E' => 'ABRA']));

        $this->assertSame('PROV_NOT_IN_LIST', $this->statuses()['prov_fix']);
        $this->assertSame('ABRA', $c->cells['E']);
        // ang ABRA ay nasa listahan pa rin, kaya tumuloy sa city; wala roon ang CEBU CITY, kaya huminto bago ang W
        $this->assertSame('CITY_NOT_RESOLVED_STOP', $this->statuses()['city_fix']);
        $this->assertSame('', $c->cells['W']);
    }

    public function test_w_province_is_turned_into_a_check_like_the_script_does(): void
    {
        $this->answers([['province_ok' => false, 'city_ok' => true, 'barangay_ok' => true]]);

        $c = $this->check($this->row(['M' => 'Lahug, Cebu City', 'E' => 'ABRA']));

        $this->assertSame('Province', $c->cells['S']);
        $this->assertSame('✅', $c->cells['W']);
        $this->assertSame('WROTE_W_PROVINCE_THEN_CHECKMARK', $this->statuses()['verify_w']);
    }

    public function test_the_implied_city_patch_turns_a_missing_city_into_ok_when_the_barangay_has_one_city(): void
    {
        $this->answers([['province_ok' => true, 'city_ok' => false, 'barangay_ok' => true]]);

        $c = $this->check($this->row(['M' => 'Bayaan, Dolores', 'E' => 'ABRA', 'F' => 'DOLORES', 'G' => 'BAYAAN']));

        $this->assertSame('Province', $c->cells['S']);
        $this->assertSame('✅', $c->cells['W']);
    }

    public function test_without_the_patch_the_missing_city_is_written(): void
    {
        $this->answers([['province_ok' => true, 'city_ok' => false, 'barangay_ok' => true]]);
        $rows = array_merge(Fixtures::checkerRows(), [['ABRA', 'LAGAYAN', 'BAYAAN']]); // dalawang city ang may BAYAAN: walang isahang hula
        $c = new Checker($this->row(['M' => 'Bayaan, Dolores', 'E' => 'ABRA', 'F' => 'DOLORES', 'G' => 'BAYAAN']), new \App\Services\OrderEncode\Locations($rows), Fixtures::gpt($this->steps), $this->steps);
        $c->run();

        $this->assertSame('City', $c->cells['W']);
    }

    public static function wMap(): array
    {
        return [[true, true, true, '✅'], [true, true, false, 'Barangay'], [true, false, true, 'City'], [false, true, true, 'Province'],
            [true, false, false, 'City and Barangay'], [false, false, true, 'Province and City'], [false, true, false, 'Province and Barangay'], [false, false, false, 'Full Address']];
    }

    #[DataProvider('wMap')]
    public function test_w_mapping(bool $p, bool $c, bool $b, string $expected): void
    {
        $this->assertSame($expected, Checker::computeW($p, $c, $b));
    }

    public function test_a_blank_name_and_address_are_filled_by_gpt_and_stripped_of_places(): void
    {
        $this->answers([['full_name' => 'Juan  Dela Cruz', 'address_line1' => 'Blk 5 Lot 3, Lahug']]);

        $c = $this->check($this->row(['B' => '', 'D' => 'unknown']));

        $this->assertSame('Juan Dela Cruz', $c->cells['B']);
        $this->assertSame('Blk 5 Lot 3', $c->cells['D']);
        $this->assertSame('WROTE_NAMEADDR', $this->statuses()['name_addr']);
    }

    public function test_an_unknown_name_is_not_written_and_a_name_without_letters_is_not_either(): void
    {
        $this->answers([['full_name' => '12345', 'address_line1' => '']]);

        $c = $this->check($this->row(['B' => '']));

        $this->assertSame('', $c->cells['B']);
        $this->assertSame('NO_CHANGE', $this->statuses()['name_addr']);
    }

    public function test_an_empty_chat_skips_the_gpt_steps(): void
    {
        Http::fake(['*' => Http::response('{}', 500)]);

        $c = $this->check($this->row(['K' => '', 'M' => 'Cebu City, Cebu', 'B' => '']));

        $this->assertSame(0, $this->steps->requests());
        $this->assertSame('SKIP:K_EMPTY_NAMEADDR', $this->statuses()['name_addr']);
        $this->assertSame('', $c->cells['W']);
    }

    public function test_a_missing_key_stops_the_row_but_the_name_step_still_runs_and_logs(): void
    {
        $c = new Checker($this->row(['M' => 'Cebu City, Cebu', 'B' => '']), Fixtures::locations(), Fixtures::gpt($this->steps, ''), $this->steps);

        try {
            $c->run();
            $this->fail('the script throws when the key is missing');
        } catch (\RuntimeException $e) {
            $this->assertSame('API Key not found', $e->getMessage());
        }
        $this->assertStringStartsWith('ERR_NAMEADDR_FATAL:', $this->statuses()['name_addr']);
    }
}

<?php

namespace Tests\Unit;

use App\Services\CardStatement\CardNumberMask;
use App\Services\CardStatement\StatementParser;
use PHPUnit\Framework\TestCase;

/** Pagbasa ng card statement text: RCBC layout, generic fallback, at pag-mask ng card number. */
class CardStatementParseTest extends TestCase
{
    private const RCBC = <<<TXT
RCBC BANKARD
STATEMENT OF ACCOUNT
SALE DATE  POST DATE  DESCRIPTION                          AMOUNT
           PREVIOUS STATEMENT BALANCE                      12,345.67
09/09/26   09/10/26   FACEBK *AB12CD34EF DUBLIN IR          6,278.17
09/09/26   09/10/26   FACEBK *GH56IJ78KL DUBLIN IR            100.00
09/12/26   09/12/26   CASH PAYMENT                          5,000.00-
09/14/26   09/15/26   FACEBK *MN90OP12QR DUBLIN IR            250.50-
09/15/26   09/16/26   SHOPEE PH MANDALUYONG                   899.00
13/09/26   09/16/26   FACEBK *BADDATE01 DUBLIN IR              77.00
TXT;

    public function test_S_049_1_rcbc_lines_have_dates_amounts_credits_and_non_ads_are_listed(): void
    {
        $out = (new StatementParser())->parseText(self::RCBC);

        $this->assertSame('rcbc', $out['bank']);
        $this->assertSame([
            [null, null, 'PREVIOUS STATEMENT BALANCE', '12345.67', false, false],
            ['2026-09-09', '2026-09-10', 'FACEBK *AB12CD34EF DUBLIN IR', '6278.17', false, true],
            ['2026-09-09', '2026-09-10', 'FACEBK *GH56IJ78KL DUBLIN IR', '100.00', false, true],
            ['2026-09-12', '2026-09-12', 'CASH PAYMENT', '5000.00', true, false],
            ['2026-09-14', '2026-09-15', 'FACEBK *MN90OP12QR DUBLIN IR', '250.50', true, true],
            ['2026-09-15', '2026-09-16', 'SHOPEE PH MANDALUYONG', '899.00', false, false],
            [null, '2026-09-16', 'FACEBK *BADDATE01 DUBLIN IR', '77.00', false, true], // maling petsa: nasa preview pa rin, para maitama
        ], array_map(fn ($l) => [$l['sale_date'], $l['post_date'], $l['description'], $l['amount'], $l['is_credit'], $l['is_ads']], $out['lines']));
    }

    public function test_S_049_2_card_numbers_are_masked_to_the_last_four(): void
    {
        foreach ([
            'FACEBK 4111111111115003 DUBLIN'    => 'FACEBK ···· 5003 DUBLIN',
            'FACEBK 4111 1111 1111 5003 DUBLIN' => 'FACEBK ···· 5003 DUBLIN',
            'card 4111-1111-1111-5003'          => 'card ···· 5003',
            'acct 4111 1111 1111 5003 100.00'   => 'acct ···· 5003 100.00',
            'statement_4111111111115003.pdf'    => 'statement_···· 5003.pdf',
            'FACEBK *34SCA52HS4 6,278.17'       => 'FACEBK *34SCA52HS4 6,278.17',
            'FACEBK *AB12 4111 1111 1111 5003'  => 'FACEBK *AB12 ···· 5003',
            '4111 1111 1111 5003 12 DUBLIN'     => '···· 0312 DUBLIN', // hindi matukoy kung 14-digit card: tinatakpan din
            'amex 3782-822463-10005 x'          => 'amex ···· 0005 x',
            'Card Number 4111  1111  1111  5003     Total Amount Due' => 'Card Number ···· 5003     Total Amount Due',
            "x 4111\u{00A0}1111\u{00A0}1111\u{00A0}5003 y" => 'x ···· 5003 y',
            'x 4111.1111.1111.5003 y'           => 'x ···· 5003 y',
            'x 4111    1111    1111    5003 y'  => 'x ···· 5003 y',
            "x 4111\u{2009}1111\u{2009}1111\u{2009}5003 y" => 'x ···· 5003 y',
            'CARDNO4111111111115003'            => 'CARDNO···· 5003',
            'CARDNO4111 1111 1111 5003'         => 'CARDNO···· 5003',
            'RCBC4111111111115003.pdf'          => 'RCBC···· 5003.pdf',
            'ref 123456789012'                  => 'ref 123456789012',
            'diners 3056 930902 5904 x'         => 'diners ···· 5904 x',
            "diners 3056\u{00A0}930902\u{00A0}5904" => 'diners ···· 5904',
            'x 41111111 11115003 y'             => 'x ···· 5003 y',
            'x 4111/1111/1111/5003 y'           => 'x ···· 5003 y',
            'x 4111*1111*1111*5003 y'           => 'x ···· 5003 y',
            'x 4111 - 1111 - 1111 - 5003 y'     => 'x ···· 5003 y',
            'statement 4111-1111-1111-5003 2026-09-30.pdf' => 'statement ···· 5003 2026-09-30.pdf',
            'FACEBK 2026-09-10 2026-09-11 X'    => 'FACEBK 2026-09-10 2026-09-11 X',
            '09/09/26 09/10/26 FACEBK *AB12'    => '09/09/26 09/10/26 FACEBK *AB12',
            'FACEBK *1234567890 100.00'         => 'FACEBK *1234567890 100.00',
            'Account No. 12345678 x'            => 'Account No. ···· 5678 x',
            'CARD NUMBER: 0012 3456 789'        => 'CARD NUMBER: ···· 6789',
            'acct#98765432'                     => 'acct#···· 5432',
            '12345678 card member'              => '···· 5678 card member',
            'card member since 2015'            => 'card member since 2015',
            'FACEBK 2026-09-10 4111111111115003' => 'FACEBK 2026-09-10 ···· 5003',
            '2026-09-30 4111-1111-1111-5003.pdf' => '2026-09-30 ···· 5003.pdf',
            '12/31/2026 4111111111115003'       => '12/31/2026 ···· 5003',
            '1000.00 4111111111115003'          => '1000.00 ···· 5003',
            'AUTH 123456 4111111111115003'      => 'AUTH 123456 ···· 5003',
            'FACEBK *XYZ1234 4111111111115003'  => 'FACEBK *XYZ1234 ···· 5003',
            '4111111111115003 4111111111115004' => '···· 5003 ···· 5004',
            'REF 1234 4111 1111 1111 5003'      => 'REF ···· 5003',
            'x 4111 1111 1111 1111 503 y'       => 'x ···· 1503 y',
            'FACEBK 3782 8224 6310 005 x'       => 'FACEBK ···· 0005 x',
            'FACEBK 3782-8224-6310-005'         => 'FACEBK ···· 0005',
            '3056 9309 0259 04 x'               => '···· 5904 x',
            '4222 2222 2222 2 x'                => '···· 2222 x',
            'x 4111 1111 1111 1111 50 y'        => 'x ···· 1150 y',
            '3056-9309-0259-04 2026-09-10'      => '···· 5904 2026-09-10',
            'FACEBK 4222 2222 2222 2 100.00'    => 'FACEBK ···· 2222 100.00', // 13-digit card, buo ang amount
            'x 4111 1111         1111 5003 y'   => 'x ···· 5003 y',
        ] as $in => $want) {
            $this->assertSame($want, CardNumberMask::mask($in), $in);
        }

        $out = (new StatementParser())->parseText("RCBC\n09/09/26 09/10/26 FACEBK 4111 1111 1111 5003 IR 100.00");
        $this->assertSame('FACEBK ···· 5003 IR', $out['lines'][0]['description']);
    }

    public function test_S_049_3_other_banks_fall_back_to_lines_with_a_date_facebk_and_an_amount(): void
    {
        $text = <<<TXT
OTHER BANK CREDIT CARD
Transaction Date Description Amount
Sep 09, 2026  FACEBK *AB12CD34EF MENLO PARK  1,234.56
2026-09-10 2026-09-11 FACEBOOK ADS 300.00 CR
09/12/2026 GRAB PH 150.00
Payment received thank you 5,000.00
FACEBK no date here 99.00
TXT;
        $out = (new StatementParser())->parseText($text);

        $this->assertSame('generic', $out['bank']);
        $this->assertSame([
            ['2026-09-09', null, 'FACEBK *AB12CD34EF MENLO PARK', '1234.56', false, true],
            ['2026-09-10', '2026-09-11', 'FACEBOOK ADS', '300.00', true, true],
        ], array_map(fn ($l) => [$l['sale_date'], $l['post_date'], $l['description'], $l['amount'], $l['is_credit'], $l['is_ads']], $out['lines']));

        // Pinili ang RCBC kahit walang "RCBC" sa text → RCBC layout pa rin.
        $this->assertSame('rcbc', (new StatementParser())->parseText("09/09/26 09/10/26 FACEBK X 1.00", 'rcbc')['bank']);
    }
}

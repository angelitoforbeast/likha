<?php

namespace Tests\Unit;

use App\Support\ProfitDayM;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PROF(1D)-M ng isang araw: ang mga halimbawa ng brief (A–D, end date 2026-10-09), kinuwenta sa kamay.
 * Ayos ng args: proceed, price, RTS%, cogs, ads, cod_fee_rate, vat, SF_M.
 */
class ProfitDayMTest extends TestCase
{
    /** @return array<string, array{0: array, 1: float}> */
    public static function worked(): array
    {
        return [
            'A, CEO (cogs_ceo 180)'          => [[50, 599, 30, 180, 4000, 0.015, 0.12, 40], 5132.788],
            'B, CEO (cogs_ceo 250)'          => [[20, 799, 0, 250, 1500, 0.02, 0.10, 40], 8148.44],
            'C, CEO (cogs_ceo 250)'          => [[20, 799, 0, 250, 1500, 0.015, 0.12, 40], 8231.536],
            'D, partial date (48 proceed)'   => [[48, 599, 30, 180, 3000, 0.015, 0.12, 40], 5868.27648],
            'A, non-CEO viewer (cogs 170)'   => [[50, 599, 30, 170, 4000, 0.015, 0.12, 40], 5632.788],
            'B, non-CEO viewer (cogs 240)'   => [[20, 799, 0, 240, 1500, 0.02, 0.10, 40], 8348.44],
            'A, RTS 100%'                    => [[50, 599, 100, 180, 4000, 0.015, 0.12, 40], -15480.0],
            'A, proceed 0'                   => [[0, 599, 30, 180, 4000, 0.015, 0.12, 40], -4480.0],
            'A, SF_M 45 (from 2026-10-10)'   => [[50, 599, 30, 180, 4000, 0.015, 0.12, 45], 4882.788],
        ];
    }

    #[DataProvider('worked')]
    public function test_the_worked_examples(array $args, float $expected): void
    {
        $this->assertEqualsWithDelta($expected, ProfitDayM::compute(...$args), 0.001);
    }

    /** @return array<string, array{0: array}> */
    public static function missing(): array
    {
        return [
            'no RTS'          => [[50, 599, null, 180, 4000, 0.015, 0.12, 40]],
            'no cogs'         => [[50, 599, 30, null, 4000, 0.015, 0.12, 40]],
            'no cod rate'     => [[50, 599, 30, 180, 4000, null, 0.12, 40]],
            'no vat'          => [[50, 599, 30, 180, 4000, 0.015, null, 40]],
            'no SF_M'         => [[50, 599, 30, 180, 4000, 0.015, 0.12, null]],
            'no price (0)'    => [[50, 0, 30, 180, 4000, 0.015, 0.12, 40]],
        ];
    }

    #[DataProvider('missing')]
    public function test_a_missing_input_gives_null(array $args): void
    {
        $this->assertNull(ProfitDayM::compute(...$args));
    }

    public function test_ads_are_counted_at_112_percent(): void
    {
        $this->assertSame(1.12, ProfitDayM::ADS_FACTOR);
    }
}

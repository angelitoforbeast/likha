<?php

namespace Tests\Feature\Orders;

use App\Services\OrderPhone;
use PHPUnit\Framework\TestCase;

/** Ang phone ng order: D muna, kung wala ay iisang numero sa E o G. Hindi kailanman nag-iimbento. */
class OrderPhoneTest extends TestCase
{
    public static function forms(): array
    {
        return [
            '09 form'           => ['call me 09123456789 po', '09123456789'],
            '9 form'            => ['number 9123456789', '09123456789'],
            '+639 form'         => ['+639123456789', '09123456789'],
            '639 form'          => ['639123456789', '09123456789'],
            'spaces'            => ['+63 912 345 6789', '09123456789'],
            'dashes'            => ['0912-345-6789', '09123456789'],
            'same number twice' => ['09123456789 ulit 0912 345 6789', '09123456789'],
            'two numbers'       => ['09123456789 o 09987654321', ''],
            'price peso'        => ['₱999 po', ''],
            'price plain'       => ['1999', ''],
            'short'             => ['901', ''],
            'longer digit run'  => ['091234567890', ''],
            'empty'             => ['', ''],
        ];
    }

    /** @dataProvider forms */
    public function test_extract_finds_exactly_one_mobile(string $text, string $expected): void
    {
        $this->assertSame($expected, OrderPhone::extract($text));
    }

    public function test_for_order_prefers_d_then_e_then_g_and_never_guesses(): void
    {
        $this->assertSame('09123456789', OrderPhone::forOrder('0912 345 6789', 'tawag 09999999999', 'x'));
        $this->assertSame('09123456789', OrderPhone::forOrder('', 'phone: +63 912 345 6789', 'x'));
        $this->assertSame('09123456789', OrderPhone::forOrder('n/a', 'walang numero', 'PHONE: 09123456789'));
        $this->assertSame('', OrderPhone::forOrder('', '09123456789 at 09987654321', 'PHONE: 09123456789'), 'dalawang numero sa E: walang hula');
        $this->assertSame('', OrderPhone::forOrder(null, '₱999 ang item', null));
    }
}

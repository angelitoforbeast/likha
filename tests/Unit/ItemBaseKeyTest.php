<?php

namespace Tests\Unit;

use App\Models\ItemSupplierQuote;
use App\Support\ItemBaseKey;
use PHPUnit\Framework\TestCase;

class ItemBaseKeyTest extends TestCase
{
    private const NAMES = [
        '1 x GLOW TAPE',
        '2 x  Glow   Tape',
        '3× fan',
        'GLOW TAPE',
        '  10 X Hand Grip ',
        'x factor',
    ];

    public function test_key_matches_quote_key_for_every_name(): void
    {
        foreach (self::NAMES as $name) {
            $this->assertSame(ItemSupplierQuote::keyFor($name), ItemBaseKey::key($name), $name);
        }
    }

    public function test_parse_gives_qty_and_base(): void
    {
        $cases = [
            //  name                 => [qty, base, key]
            '1 x GLOW TAPE'          => [1, 'GLOW TAPE', 'glow tape'],
            '2 x  Glow   Tape'       => [2, 'Glow   Tape', 'glow tape'],
            '3× fan'                 => [3, 'fan', 'fan'],
            'GLOW TAPE'              => [1, 'GLOW TAPE', 'glow tape'],
            '  10 X Hand Grip '      => [10, 'Hand Grip', 'hand grip'],
            'x factor'               => [1, 'x factor', 'x factor'],
            '0 x zero'               => [1, 'zero', 'zero'],
        ];

        foreach ($cases as $name => [$qty, $base, $key]) {
            $this->assertSame(['qty' => $qty, 'base' => $base, 'key' => $key], ItemBaseKey::parse($name), $name);
        }
    }
}

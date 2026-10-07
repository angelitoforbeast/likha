<?php

namespace Tests\Unit;

use App\Services\SourcingClassifier;
use PHPUnit\Framework\TestCase;

class SourcingClassifierTest extends TestCase
{
    /**
     * Mga patakaran ng sourcing worklist (unang tumama, panalo):
     *  1. hanapan    — walang PO line kailanman, walang quote
     *  2. may_quote  — may quote, walang PO line kailanman
     *  3. i_order    — may supplier, at open qty < HOLD (shortfall = HOLD − open)
     *  4. naka_order — open PO na sakop ang HOLD
     */
    public function test_classifies_each_rule_first_match_wins(): void
    {
        $cases = [
            //                                     hasPoLine, quotes, openQty, hold  => [list, shortfall]
            'walang PO, walang quote'           => [false, 0, 0,   10, ['hanapan', 0]],
            'may quote, walang PO'              => [false, 2, 0,   10, ['may_quote', 0]],
            'quote + walang PO line, open 0'    => [false, 1, 0,    5, ['may_quote', 0]],   // rule 2 bago rule 3
            'may PO dati, walang open PO'       => [true,  0, 0,   10, ['i_order', 10]],
            'may PO + quote, kulang ang open'   => [true,  3, 4,   10, ['i_order', 6]],
            'open = HOLD − 1 (boundary)'        => [true,  0, 9,   10, ['i_order', 1]],
            'open = HOLD (boundary)'            => [true,  0, 10,  10, ['naka_order', 0]],
            'open > HOLD'                       => [true,  0, 50,  10, ['naka_order', 0]],
        ];

        foreach ($cases as $label => [$hasPo, $quotes, $open, $hold, [$list, $shortfall]]) {
            $this->assertSame(
                ['list' => $list, 'shortfall' => $shortfall],
                SourcingClassifier::classify($hasPo, $quotes, $open, $hold),
                $label
            );
        }
    }
}

<?php

namespace App\Services;

/**
 * Sourcing worklist (/item, CEO lang) — saang listahan ang isang base item.
 * Pure: plain values lang ang input, walang DB. Unang tumama, panalo:
 *
 *  1. HANAPAN    — walang PO line kailanman AT walang quote     → "Hanapan ng supplier"
 *  2. MAY_QUOTE  — may quote, walang PO line kailanman          → "May quote, hindi pa na-order"
 *  3. I_ORDER    — may supplier, open qty < HOLD units          → "I-order na" (shortfall = HOLD − open)
 *  4. NAKA_ORDER — open PO na sakop ang HOLD units              → "Naka-order, hinihintay"
 */
class SourcingClassifier
{
    public const HANAPAN    = 'hanapan';
    public const MAY_QUOTE  = 'may_quote';
    public const I_ORDER    = 'i_order';
    public const NAKA_ORDER = 'naka_order';

    public const LISTS = [self::HANAPAN, self::MAY_QUOTE, self::I_ORDER, self::NAKA_ORDER];

    /** @return array{list: string, shortfall: int} */
    public static function classify(bool $hasPoLine, int $quoteCount, int $openQty, int $holdUnits): array
    {
        if (!$hasPoLine) {
            return ['list' => $quoteCount > 0 ? self::MAY_QUOTE : self::HANAPAN, 'shortfall' => 0];
        }
        if ($openQty < $holdUnits) {
            return ['list' => self::I_ORDER, 'shortfall' => $holdUnits - $openQty];
        }
        return ['list' => self::NAKA_ORDER, 'shortfall' => 0];
    }
}

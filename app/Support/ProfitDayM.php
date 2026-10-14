<?php

namespace App\Support;

/**
 * PROF(1D)-M ng isang araw — ang mas mahigpit na bilang ng Prof.Profit(1D) sa /owner/private.
 * Kapareho ng Prof.Profit(1D) maliban sa tatlo:
 *   - shipping = proceed × SF_M (sariling fee setting na `shipping_fee_per_order_m`);
 *   - COGS sa LAHAT ng proceed (walang RTS factor) — bawat naipadalang order ay may gastos na item;
 *   - ads × ADS_FACTOR.
 * Ginagamit ng per-day loop at ng partial-date override ng itemSummary, para iisa ang formula.
 */
class ProfitDayM
{
    /**
     * Ang ads ay binibilang nang may 12% na dagdag (VAT na kasama sa singil ng ad spend), para hindi
     * lumaki ang profit. Dito lang sa -M; walang ibang profit column na gumagamit nito.
     */
    public const ADS_FACTOR = 1.12;

    /**
     * Null kapag kulang ang RTS%, cogs, COD fee rate, VAT o SF_M, o walang presyo — gaya ng
     * Prof.Profit(1D), na walang value kapag hindi ma-kuwenta ang araw.
     */
    public static function compute(
        float $proceed,
        float $price,
        ?float $rtsPct,
        ?float $cogs,
        float $ads,
        ?float $codFeeRate,
        ?float $vatRate,
        ?float $shippingFeeM
    ): ?float {
        if ($rtsPct === null || $cogs === null || $codFeeRate === null || $vatRate === null
            || $shippingFeeM === null || $price <= 0) {
            return null;
        }

        $deliverFactor = 1.0 - $rtsPct / 100.0;

        return $proceed * $price * $deliverFactor                                  // revenue
            - $proceed * $shippingFeeM                                             // shipping (all proceed)
            - $proceed * $cogs                                                     // COGS (all proceed)
            - $ads * self::ADS_FACTOR                                              // adspent
            - $proceed * $deliverFactor * $price * $codFeeRate * (1.0 + $vatRate); // COD fee (delivered)
    }
}

<?php

namespace Tests\Feature\OwnerPrivate;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Base ng mga test ng delivery status kada campaign / ad set / ad: orders, J&T rows at ads_manager_reports
 * (mga column na binabasa lang; MySQL-only ang totoong migrations).
 */
abstract class AdDeliveryTestCase extends OwnerPrivateTestCase
{
    protected const START = '2026-10-01';
    protected const END   = '2026-10-15';
    protected const PAGE  = 'Test Page';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('macro_output', function (Blueprint $t) {
            $t->id();
            $t->string('PAGE')->nullable();
            $t->string('waybill')->nullable();
            $t->string('botcake_ad_id', 64)->nullable();
            $t->date('ts_date')->nullable();
        });
        Schema::create('from_jnts', function (Blueprint $t) {
            $t->id();
            $t->string('waybill_number')->nullable();
            $t->string('status')->nullable();
            $t->string('rts_reason')->nullable();
        });
        Schema::create('ads_manager_reports', function (Blueprint $t) {
            $t->id();
            $t->date('day')->nullable();
            $t->string('page_name')->nullable();
            $t->string('campaign_id')->nullable();
            $t->string('campaign_name')->nullable();
            $t->string('ad_set_id')->nullable();
            $t->string('ad_id')->nullable();
        });
    }

    /** Isang order; kapag may $status, may J&T row din ang waybill nito. */
    protected function order(string $waybill, ?string $adId, ?string $status, string $date = '2026-10-05', string $page = self::PAGE, ?string $rtsReason = null): void
    {
        DB::table('macro_output')->insert(['PAGE' => $page, 'waybill' => $waybill, 'botcake_ad_id' => $adId, 'ts_date' => $date]);
        if ($status !== null) {
            DB::table('from_jnts')->insert(['waybill_number' => $waybill, 'status' => $status, 'rts_reason' => $rtsReason]);
        }
    }

    protected function ad(string $adId, string $adSetId, string $campaignId, string $day = '2026-10-05', string $page = self::PAGE): void
    {
        DB::table('ads_manager_reports')->insert([
            'day' => $day, 'page_name' => $page, 'campaign_id' => $campaignId, 'campaign_name' => 'Campaign ' . $campaignId,
            'ad_set_id' => $adSetId, 'ad_id' => $adId,
        ]);
    }

    /** @return array{shipped:int, delivered:int, in_transit:int, for_return:int, returned:int, rts_pct:?float} */
    protected function counts(int $shipped, int $delivered, int $inTransit, int $forReturn, int $returned): array
    {
        $rts = $forReturn + $returned;
        $den = $rts + $delivered + $inTransit;

        return [
            'shipped' => $shipped, 'delivered' => $delivered, 'in_transit' => $inTransit,
            'for_return' => $forReturn, 'returned' => $returned,
            'rts_pct' => $shipped > 0 ? ($returned + $forReturn) / $shipped * 100.0 : null,
            // RTS / DEL / INT na cell: RTS = for return + returned, % = bilang / (RTS + DEL + INT)
            'rdt' => $den > 0 ? [
                'rts' => ['cnt' => $rts, 'pct' => $rts / $den * 100.0],
                'del' => ['cnt' => $delivered, 'pct' => $delivered / $den * 100.0],
                'int' => ['cnt' => $inTransit, 'pct' => $inTransit / $den * 100.0],
            ] : null,
        ];
    }
}

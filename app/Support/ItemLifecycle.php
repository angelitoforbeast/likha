<?php

namespace App\Support;

/**
 * Lifecycle classification ng item (new / scaling / consistent / ...).
 * Galing sa JntSupplyController — iisa ang rules ng /jnt/supply at ng /item.
 * Priority: New > Phasing Out > Scaling > Declining > Consistent > Active > Dormant
 */
class ItemLifecycle
{
    // Default na settings ng /jnt/supply
    public const RECENT_DAYS       = 14;
    public const NEW_ITEM_DAYS     = 30;
    public const LONG_RUNNING_DAYS = 90;
    public const SCALE_THRESHOLD   = 1.5;
    public const DECLINE_THRESHOLD = 0.5;

    public static function classify(
        float  $recentVel,
        float  $prevVel,
        int    $daysRunning,
        bool   $hasOldOrders,
        int    $newItemDays,
        int    $longRunningDays,
        float  $scaleThreshold,
        float  $declineThreshold
    ): string {
        if ($daysRunning <= $newItemDays && $recentVel > 0) {
            return 'new';
        }
        if ($recentVel == 0 && $prevVel > 0) {
            return 'phasing_out';
        }
        if ($recentVel == 0 && $prevVel == 0) {
            return 'dormant';
        }
        if ($prevVel == 0 && $recentVel > 0) {
            return 'scaling';
        }
        if ($recentVel >= $prevVel * $scaleThreshold) {
            return 'scaling';
        }
        if ($recentVel <= $prevVel * $declineThreshold) {
            return 'declining';
        }
        if ($daysRunning >= $longRunningDays) {
            return 'consistent';
        }
        return 'active';
    }

    /** Badge label + Tailwind classes. @return array{0:string,1:string} */
    public static function badge(string $class): array
    {
        return match ($class) {
            'new'         => ['🆕 New',          'bg-blue-100 text-blue-800'],
            'scaling'     => ['📈 Scaling',       'bg-green-100 text-green-800'],
            'consistent'  => ['✅ Consistent',    'bg-teal-100 text-teal-800'],
            'active'      => ['🔄 Active',        'bg-slate-100 text-slate-700'],
            'declining'   => ['📉 Declining',     'bg-orange-100 text-orange-800'],
            'phasing_out' => ['🚫 Phasing Out',   'bg-red-100 text-red-800'],
            'dormant'     => ['💤 Dormant',       'bg-gray-100 text-gray-500'],
            default       => ['— Unknown',        'bg-gray-100 text-gray-400'],
        };
    }
}

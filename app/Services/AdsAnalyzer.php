<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class AdsAnalyzer
{
    /**
     * @return array{0: array<int, array>, 1: array<string, mixed>}
     */
    public function aggregate(string $from, string $to, ?string $page, string $mode = 'range_summary'): array
    {
        // Use DATE(COALESCE(day, reporting_starts)) for portability
        $dateExpr = DB::raw("DATE(COALESCE(day, reporting_starts))");

        $base = DB::table('ads_manager_reports')
            ->selectRaw("
                TRIM(page_name)  AS page_name,
                TRIM(campaign_name) AS campaign_name,
                SUM(amount_spent_php) AS spend,
                SUM(impressions) AS impressions,
                SUM(reach) AS reach,
                SUM(messaging_conversations_started) AS messages,
                SUM(purchases) AS purchases,
                STRING_AGG(DISTINCT campaign_delivery, ', ') AS campaign_delivery_agg,
                STRING_AGG(DISTINCT ad_set_delivery, ', ') AS ad_set_delivery_agg
            ")
            ->whereBetween($dateExpr, [$from, $to])
            ->when($page, fn($q) => $q->whereRaw('LOWER(TRIM(page_name)) = LOWER(TRIM(?))', [$page]))
            ->groupBy('page_name', 'campaign_name');

        // Fallback for MySQL which doesn't have STRING_AGG
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            $base = DB::table('ads_manager_reports')
                ->selectRaw("
                    TRIM(page_name)  AS page_name,
                    TRIM(campaign_name) AS campaign_name,
                    SUM(amount_spent_php) AS spend,
                    SUM(impressions) AS impressions,
                    SUM(reach) AS reach,
                    SUM(messaging_conversations_started) AS messages,
                    SUM(purchases) AS purchases,
                    GROUP_CONCAT(DISTINCT campaign_delivery SEPARATOR ', ') AS campaign_delivery_agg,
                    GROUP_CONCAT(DISTINCT ad_set_delivery SEPARATOR ', ') AS ad_set_delivery_agg
                ")
                ->whereBetween($dateExpr, [$from, $to])
                ->when($page, fn($q) => $q->where('page_name', $page))
                ->groupBy('page_name', 'campaign_name');
        }

        $rows = $base->get();

        $kpis = [];
        foreach ($rows as $r) {
            $spend = (float)($r->spend ?? 0);
            $impr  = max(0, (int)($r->impressions ?? 0));
            $reach = max(0, (int)($r->reach ?? 0));
            $msgs  = max(0, (int)($r->messages ?? 0));
            $purch = max(0, (int)($r->purchases ?? 0));

            $CPM = $msgs > 0 ? round($spend / $msgs, 4) : null;         // PH def: cost per message
            $CPI = $impr > 0 ? round($spend / $impr, 8) : null;          // cost per impression
            $CPP = $purch > 0 ? round($spend / $purch, 4) : null;        // cost per purchase
            $freq = ($reach > 0 && $impr > 0) ? round($impr / $reach, 4) : null;
            $msgsPer1kImpr = $impr > 0 ? round(($msgs / $impr) * 1000, 4) : null;

            $kpis[] = [
                'page'  => $r->page_name,
                'campaign' => $r->campaign_name,
                'spend' => round($spend, 2),
                'impressions' => $impr,
                'reach' => $reach,
                'messages' => $msgs,
                'purchases' => $purch,
                'CPM' => $CPM,
                'CPI' => $CPI,
                'CPP' => $CPP,
                'frequency' => $freq,
                'msgs_per_1k_impr' => $msgsPer1kImpr,
                'campaign_delivery' => $r->campaign_delivery_agg ?? null,
                'ad_set_delivery'   => $r->ad_set_delivery_agg ?? null,
            ];
        }

        return [$kpis, ['aggregated_kpis' => $kpis]];
    }
}

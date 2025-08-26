<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\AdsAnalyzer;
use App\Services\OpenAIAdsAdvisor;

class AdsInsightsController extends Controller
{
    public function index(Request $request)
    {
        // Distinct pages for the dropdown
        $pages = DB::table('ads_manager_reports')
            ->whereNotNull('page_name')
            ->selectRaw('TRIM(page_name) AS page_name')
            ->distinct()
            ->orderBy('page_name')
            ->pluck('page_name');

        return view('ads_manager.insights', [
            'pages' => $pages,
        ]);
    }

    /**
     * Preview endpoint: aggregate KPIs from DB based on filters (no GPT call).
     * Accepts:
     * - target_cpp, breakeven_cpp (optional numeric)
     * - include_inactive (bool) → default false (ACTIVE-only)
     */
    public function preview(Request $request, AdsAnalyzer $analyzer)
    {
        $from = $request->input('from', now()->subDays(2)->toDateString());
        $to   = $request->input('to', now()->toDateString());
        $page = $request->input('page'); // optional
        $mode = $request->input('mode', 'range_summary');

        $tier           = $request->input('tier');                     // '199' | null
        $targetCpp      = $request->input('target_cpp');               // numeric|null
        $breakevenCpp   = $request->input('breakeven_cpp');            // numeric|null
        $includeInactive= $request->boolean('include_inactive', false);

        // Compute targets (includes overrides when provided)
        $targets = $this->targetsForTier($tier, $targetCpp, $breakevenCpp);

        // Aggregate KPIs
        [$kpis, $meta] = $analyzer->aggregate($from, $to, $page, $mode);

        // === Filter out inactive by default ===
        $activeLike = [
            'active', 'learning', 'learning limited', 'limited learning',
            'eligible', 'delivering'
        ];

        $rows = collect($meta['aggregated_kpis'] ?? $kpis);

        if (!$includeInactive) {
            $rows = $rows->filter(function ($r) use ($activeLike) {
                $cd = strtolower(trim((string)($r['campaign_delivery'] ?? '')));
                $ad = strtolower(trim((string)($r['ad_set_delivery'] ?? '')));

                $okCampaign = in_array($cd, $activeLike, true);
                $okAdset    = in_array($ad, $activeLike, true);

                // include if ANY level is active-ish
                return $okCampaign || $okAdset;
            })->values();
        }

        $hiddenCount = (is_countable($meta['aggregated_kpis'] ?? $kpis) ? count($meta['aggregated_kpis'] ?? $kpis) : 0) - $rows->count();

        Log::info('PREVIEW_DEBUG', [
            'from' => $from,
            'to'   => $to,
            'page' => $page,
            'kpi_count_total' => is_countable($kpis) ? count($kpis) : 0,
            'kpi_count_after_filter' => $rows->count(),
            'include_inactive' => $includeInactive,
        ]);

        return response()->json([
            'from'  => $from,
            'to'    => $to,
            'page'  => $page,
            'mode'  => $mode,
            'targets' => $targets,
            'aggregated_kpis' => $rows->all(),
            'hidden_inactive_rows' => max(0, $hiddenCount),
        ]);
    }

    /**
     * Analyze endpoint: receives EXACT KPIs (from the preview table) and calls GPT.
     * POST JSON: { kpis: [...], tier: "199"|null, target_cpp: number|null, breakeven_cpp: number|null }
     *
     * Note: analysis runs ONLY on what you previewed (already filtered server-side).
     */
    public function analyze(Request $request, OpenAIAdsAdvisor $advisor)
{
    $kpis = $request->input('kpis', []);
    $tier = $request->input('tier'); // '199' | null

    $targetCpp    = $request->input('target_cpp');      // numeric|null
    $breakevenCpp = $request->input('breakeven_cpp');   // numeric|null

    \Log::info('ANALYZE_POST_DEBUG', [
        'kpi_count' => is_array($kpis) ? count($kpis) : 0,
    ]);

    if (!is_array($kpis) || empty($kpis)) {
        return response()->json([
            'summary' => null,
            'global_actions' => [],
            'by_campaign' => [],
            'error' => 'No KPIs provided. Click "Show Data" first.',
        ], 422);
    }

    $targets = $this->targetsForTier($tier, $targetCpp, $breakevenCpp);

    // --- GPT call ---
    $result = $advisor->analyze($kpis, $targets, [
        'avoid_price_in_copy' => true,
        'respect_learning'    => true,
    ]);

    // ✅ ayusin ang maling “above/below breakeven” mula sa model
    $result = $this->reconcileBreakevenContradictions($result, $kpis, $targets);

    // ibalik din ang ginamit na KPIs at targets para transparent sa UI
    return response()->json(array_merge($result, [
        'aggregated_kpis' => $kpis,
        'targets' => $targets,
    ]));
}


    /**
     * Targets helper with overrides.
     * - Tier default: CPM_max=10, CPP_max=70 (or 55 if tier=199)
     * - Overrides: target_cpp -> CPP_max; breakeven_cpp -> CPP_breakeven
     */
    private function targetsForTier($tier, $targetCpp = null, $breakevenCpp = null): array
    {
        $defaults = [
            'CPM_max'        => 10.0, // cost per message
            'CPP_max'        => 70.0, // default target CPP for ₱299 SKU
            'CPP_breakeven'  => null, // optional, if provided by user
        ];

        if ($tier === '199') {
            $defaults['CPP_max'] = 55.0; // tighter CPP for ₱199 SKU
        }

        if (is_numeric($targetCpp)) {
            $defaults['CPP_max'] = (float) $targetCpp;
        }

        if (is_numeric($breakevenCpp)) {
            $defaults['CPP_breakeven'] = (float) $breakevenCpp;
        }

        return $defaults;
    }
    private function reconcileBreakevenContradictions(array $result, array $kpis, array $targets): array
{
    // index KPIs by "page|campaign"
    $idx = [];
    foreach ($kpis as $k) {
        $key = mb_strtolower(trim(($k['page'] ?? '').'|'.($k['campaign'] ?? '')));
        $idx[$key] = $k;
    }

    $be  = $targets['CPP_breakeven'] ?? null;   // breakeven
    $tgt = $targets['CPP_max'] ?? null;         // target cpp

    if (!isset($result['by_campaign']) || !is_array($result['by_campaign'])) {
        return $result;
    }

    foreach ($result['by_campaign'] as &$bc) {
        $key = mb_strtolower(trim(($bc['page'] ?? '').'|'.($bc['campaign'] ?? '')));
        if (!isset($idx[$key])) continue;

        $cpp = $idx[$key]['CPP'] ?? null;
        if (!is_numeric($cpp) || !is_numeric($be)) {
            // if we cannot compare, at least annotate numbers if missing in "why"
            if (isset($bc['why']) && stripos($bc['why'], 'CPP') === false && is_numeric($cpp)) {
                $bc['why'] .= " (CPP ₱".number_format((float)$cpp, 2).")";
            }
            continue;
        }

        $isAboveBE = ((float)$cpp > (float)$be);

        // normalize/correct the wording in "why"
        $why = (string)($bc['why'] ?? '');
        if ($why !== '') {
            // if it mentions 'breakeven', force correct above/below
            if (stripos($why, 'breakeven') !== false) {
                if ($isAboveBE) {
                    $why = preg_replace('/(above|below)\s+the\s+breakeven/i', 'above the breakeven', $why);
                } else {
                    $why = preg_replace('/(above|below)\s+the\s+breakeven/i', 'below the breakeven', $why);
                }
            }
        }

        // Always append ground-truth numbers
        $whySuffix = " (CPP ₱".number_format((float)$cpp, 2)." vs BE ₱".number_format((float)$be, 2).")";
        if (stripos($why, 'CPP ₱') === false) {
            $why .= $whySuffix;
        }

        $bc['why'] = trim($why);

        // Optional: nudge the decision if it contradicts the math
        // - Below BE and below target → scale
        // - Below BE but above target → hold/fix (depends on your preference)
        // - Above BE → fix/pause
        $decision = strtolower((string)($bc['decision'] ?? ''));

        if ($isAboveBE) {
            if (!in_array($decision, ['fix','pause'], true)) {
                $bc['decision'] = 'fix';
            }
        } else {
            if (is_numeric($tgt) && (float)$cpp <= (float)$tgt) {
                if (!in_array($decision, ['scale','hold'], true)) $bc['decision'] = 'scale';
            } else {
                if (!in_array($decision, ['hold','fix','scale'], true)) $bc['decision'] = 'hold';
            }
        }
    }
    unset($bc);

    return $result;
}

    
}

<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdCopyController extends Controller
{
    public function suggestions()
    {
        $grouped = DB::table('ads_manager_reports')
            ->selectRaw('headline, body_ad_settings, 
                SUM(amount_spent_php) as total_spent, 
                SUM(messaging_conversations_started) as total_msgs')
            ->whereNotNull('headline')
            ->whereNotNull('body_ad_settings')
            ->where('messaging_conversations_started', '>', 0)
            ->groupBy('headline', 'body_ad_settings')
            ->get()
            ->map(function ($row) {
                $cpm = $row->total_msgs > 0 ? round($row->total_spent / $row->total_msgs, 2) : null;
                return [
                    'headline' => $row->headline,
                    'body' => $row->body_ad_settings,
                    'cpm' => $cpm,
                ];
            })
            ->filter(fn ($row) => $row['cpm'] !== null)
            ->sortBy('cpm')
            ->values();

        $top5 = $grouped->take(5);
        $worst5 = $grouped->sortByDesc('cpm')->take(5);

        $cpms = $grouped->pluck('cpm')->sort()->values();
        $mean = round($cpms->avg(), 2);
        $median = round($cpms->median(), 2);
        $mode = $cpms->countBy()->sortDesc()->keys()->first();

        // Find 5 closest matches for each metric
        $findClosestMatches = fn ($target) =>
            $grouped->sortBy(fn ($item) => abs($item['cpm'] - $target))->take(5)->values();

        $meanMatches = $findClosestMatches($mean);
        $medianMatches = $findClosestMatches($median);
        $modeMatches = $findClosestMatches($mode);

        $lines = [];

        $lines[] = "🔝 TOP-PERFORMING ADS (Lowest Cost per Message)";
        foreach ($top5 as $i => $item) {
            $lines[] = ($i + 1) . ". Headline: \"{$item['headline']}\"\n   Body: \"{$item['body']}\"\n   CPM: ₱{$item['cpm']}";
        }

        $lines[] = "\n❗ WORST-PERFORMING ADS (Highest Cost per Message)";
        foreach ($worst5 as $i => $item) {
            $lines[] = ($i + 1) . ". Headline: \"{$item['headline']}\"\n   Body: \"{$item['body']}\"\n   CPM: ₱{$item['cpm']}";
        }

        $lines[] = "\n📊 AVERAGE CPM: ₱$mean";
        foreach ($meanMatches as $item) {
            $lines[] = "- Headline: \"{$item['headline']}\"\n  Body: \"{$item['body']}\"\n  CPM: ₱{$item['cpm']}";
        }

        $lines[] = "\n📈 MEDIAN CPM: ₱$median";
        foreach ($medianMatches as $item) {
            $lines[] = "- Headline: \"{$item['headline']}\"\n  Body: \"{$item['body']}\"\n  CPM: ₱{$item['cpm']}";
        }

        $lines[] = "\n📌 MODE CPM: ₱$mode";
        foreach ($modeMatches as $item) {
            $lines[] = "- Headline: \"{$item['headline']}\"\n  Body: \"{$item['body']}\"\n  CPM: ₱{$item['cpm']}";
        }

        return response()->json([
            'output' => implode("\n\n", $lines),
        ]);
    }
}

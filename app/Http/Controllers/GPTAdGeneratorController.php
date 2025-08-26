<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class GPTAdGeneratorController extends Controller
{
    public function generate(Request $request)
    {
        $request->validate([
            'prompt' => 'required|string',
        ]);

        $prompt = $request->input('prompt');

        try {
            $response = Http::withToken(env('OPENAI_API_KEY'))->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4',
                'messages' => [
                    ['role' => 'user', 'content' => $prompt]
                ],
                'temperature' => 0.7,
            ]);

            Log::info('GPT Response', $response->json());

            if ($response->successful()) {
                return response()->json([
                    'output' => $response['choices'][0]['message']['content'] ?? 'No output from GPT.',
                ]);
            } else {
                return response()->json([
                    'output' => '❌ GPT request failed.',
                    'error' => $response->body(),
                ], 500);
            }
        } catch (\Exception $e) {
            Log::error('GPT Exception: ' . $e->getMessage());
            return response()->json([
                'output' => '❌ Server error occurred.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function showGeneratorForm()
    {
        $promptText = file_get_contents(resource_path('views/gpt/gpt_ad_prompts.txt'));

        // NEW: get distinct pages for dropdown
        $pages = DB::table('ads_manager_reports')
            ->whereNotNull('page_name')
            ->selectRaw('TRIM(page_name) AS page_name')
            ->distinct()
            ->orderBy('page_name')
            ->pluck('page_name')
            ->toArray();

        return view('gpt.gpt_ad_generator', compact('promptText', 'pages'));
    }

    public function loadAdCopySuggestions(Request $request)
    {
        $page = $request->query('page'); // 'all' or a specific page name

        $ads = DB::table('ads_manager_reports')
            ->select('headline', 'body_ad_settings', 'amount_spent_php', 'messaging_conversations_started', 'page_name')
            ->when($page && $page !== 'all', function ($q) use ($page) {
                // trim match for cleanliness
                $q->whereRaw('TRIM(page_name) = ?', [$page]);
            })
            ->whereNotNull('headline')
            ->whereNotNull('body_ad_settings')
            ->where('messaging_conversations_started', '>', 0)
            ->get()
            ->map(function ($ad) {
                // Guard vs divide-by-zero (already filtered > 0, but keep safe)
                $den = (float) $ad->messaging_conversations_started;
                $num = (float) $ad->amount_spent_php;
                $ad->cpm = $den > 0 ? ($num / $den) : null; // "CPM" here = cost per message
                return $ad;
            })
            ->filter(fn($ad) => $ad->cpm !== null);

        if ($ads->isEmpty()) {
            $scopeNote = ($page && $page !== 'all') ? " for “{$page}”" : "";
            return response()->json(['output' => "⚠️ No valid ad copies found{$scopeNote}."], 200);
        }

        // Group by Headline + Body
        $grouped = $ads->groupBy(fn ($ad) => $ad->headline . '|' . $ad->body_ad_settings)
            ->map(function ($group) {
                $avgCpm = $group->avg('cpm');
                return [
                    'headline' => $group->first()->headline,
                    'body'     => $group->first()->body_ad_settings,
                    'cpm'      => $avgCpm,
                ];
            })
            ->values();

        // Sort for top and worst
        $sorted = $grouped->sortBy('cpm')->values();
        $top    = $sorted->take(5);
        $worst  = $sorted->reverse()->take(5);

        // Stats
        $cpms   = $grouped->pluck('cpm')->sort()->values();
        $count  = $cpms->count();
        $mean   = $count ? $cpms->avg() : null;
        $median = null;
        if ($count) {
            $median = $count % 2 === 0
                ? (($cpms[$count/2 - 1] + $cpms[$count/2]) / 2)
                : $cpms[floor($count/2)];
        }
        $mode = $count ? $cpms->countBy()->sortDesc()->keys()->first() : null;

        $matchByCpm = function ($value) use ($grouped) {
            if ($value === null) return collect();
            return $grouped->sortBy(fn ($g) => abs($g['cpm'] - $value))->take(5)->values();
        };

        $response = collect([
            '🔝 TOP-PERFORMING ADS (Lowest CPM)' => $top,
            '🔴 WORST-PERFORMING ADS (Highest CPM)' => $worst,
            '🟡 MEAN CPM GROUP'   => $matchByCpm($mean),
            '🟣 MEDIAN CPM GROUP' => $matchByCpm($median),
            '🔵 MODE CPM GROUP'   => $matchByCpm($mode),
        ])->map(function ($group, $label) {
            if ($group->isEmpty()) {
                return "{$label}\n  (no data)";
            }
            $lines = ["{$label}"];
            foreach ($group as $index => $item) {
                $lines[] = ($index + 1) . '. Headline: "' . $item['headline'] . '"' .
                           "\n   Body: \"" . $item['body'] . "\"\n   CPM (Cost per Message): ₱" .
                           number_format($item['cpm'], 2);
            }
            return implode("\n", $lines);
        })->values()->implode("\n\n");

        return response()->json(['output' => $response]);
    }
}

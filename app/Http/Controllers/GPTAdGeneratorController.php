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
        return view('gpt.gpt_ad_generator', compact('promptText'));
    }
    

public function loadAdCopySuggestions()
{
    $ads = DB::table('ads_manager_reports')
        ->select('headline', 'body_ad_settings', 'amount_spent_php', 'messaging_conversations_started')
        ->whereNotNull('headline')
        ->whereNotNull('body_ad_settings')
        ->where('messaging_conversations_started', '>', 0)
        ->get()
        ->map(function ($ad) {
            $ad->cpm = $ad->amount_spent_php / $ad->messaging_conversations_started;
            return $ad;
        });

    if ($ads->isEmpty()) {
        return response()->json(['output' => "⚠️ No valid ad copies found."], 200);
    }

    // Group by Headline + Body
    $grouped = $ads->groupBy(fn ($ad) => $ad->headline . '|' . $ad->body_ad_settings)
        ->map(function ($group) {
            $avgCpm = $group->avg('cpm');
            return [
                'headline' => $group[0]->headline,
                'body' => $group[0]->body_ad_settings,
                'cpm' => $avgCpm,
            ];
        })
        ->values();

    // Sort for top and worst
    $sorted = $grouped->sortBy('cpm')->values();
    $top = $sorted->take(5);
    $worst = $sorted->reverse()->take(5);

    // Stats
    $cpms = $grouped->pluck('cpm')->sort()->values();
    $mean = $cpms->avg();
    $median = $cpms->count() % 2 === 0
        ? ($cpms[$cpms->count()/2 - 1] + $cpms[$cpms->count()/2]) / 2
        : $cpms[floor($cpms->count()/2)];
    $mode = $cpms->countBy()->sortDesc()->keys()->first();

    $matchByCpm = fn ($value) => $grouped->sortBy(fn ($g) => abs($g['cpm'] - $value))->take(5);

    $response = collect([
        '🔝 TOP-PERFORMING ADS (Lowest CPM)' => $top,
        '🔴 WORST-PERFORMING ADS (Highest CPM)' => $worst,
        '🟡 MEAN CPM GROUP' => $matchByCpm($mean),
        '🟣 MEDIAN CPM GROUP' => $matchByCpm($median),
        '🔵 MODE CPM GROUP' => $matchByCpm($mode),
    ])->map(function ($group, $label) {
        $lines = ["{$label}"];
        foreach ($group as $index => $item) {
            $lines[] = ($index + 1) . ". Headline: \"{$item['headline']}\"\n   Body: \"{$item['body']}\"\n   CPM (Cost per Message): ₱" . number_format($item['cpm'], 2);
        }
        return implode("\n", $lines);
    })->values()->implode("\n\n");

    return response()->json(['output' => $response]);
}

}

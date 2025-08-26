<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class GPTAdGeneratorController extends Controller
{
    /**
     * POST /api/generate-gpt-summary
     * Body: { prompt: string }
     */
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
            }

            return response()->json([
                'output' => '❌ GPT request failed.',
                'error'  => $response->body(),
            ], 500);
        } catch (\Exception $e) {
            Log::error('GPT Exception: ' . $e->getMessage());
            return response()->json([
                'output' => '❌ Server error occurred.',
                'error'  => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /gpt/ad-generator
     * Shows the Ad Copy Generator with pages dropdown.
     */
    public function showGeneratorForm()
    {
        $promptText = file_get_contents(resource_path('views/gpt/gpt_ad_prompts.txt'));

        $pages = DB::table('ads_manager_reports')
            ->whereNotNull('page_name')
            ->selectRaw('TRIM(page_name) AS page_name')
            ->distinct()
            ->orderBy('page_name')
            ->pluck('page_name')
            ->toArray();

        return view('gpt.gpt_ad_generator', compact('promptText', 'pages'));
    }

    /**
     * GET /ad-copy-suggestions?page={page|all}
     * Returns text suggestions grouped by performance (Top/Worst/Mean/Median/Mode).
     * Includes headline, body, welcome message, and quick replies 1–3 when available.
     *
     * Notes:
     * - "CPM" here means Cost per Message = amount_spent_php / messaging_conversations_started
     */
    public function loadAdCopySuggestions(Request $request)
    {
        $page  = $request->query('page'); // 'all' or specific page name
        $table = 'ads_manager_reports';

        // Detect optional columns safely
        $hasMsgTpl = Schema::hasColumn($table, 'messaging_template');
        $hasWelcome = Schema::hasColumn($table, 'welcome_message');
        $hasQR1 = Schema::hasColumn($table, 'quick_reply_1');
        $hasQR2 = Schema::hasColumn($table, 'quick_reply_2');
        $hasQR3 = Schema::hasColumn($table, 'quick_reply_3');

        // Build SELECT list
        $cols = [
            'headline',
            'body_ad_settings',
            'amount_spent_php',
            'messaging_conversations_started',
            'page_name',
        ];
        if ($hasMsgTpl)   $cols[] = 'messaging_template';
        if ($hasWelcome)  $cols[] = 'welcome_message';
        if ($hasQR1)      $cols[] = 'quick_reply_1';
        if ($hasQR2)      $cols[] = 'quick_reply_2';
        if ($hasQR3)      $cols[] = 'quick_reply_3';

        // Pull raw ads
        $ads = DB::table($table)
            ->select($cols)
            ->when($page && $page !== 'all', function ($q) use ($page) {
                $q->whereRaw('TRIM(page_name) = ?', [$page]);
            })
            ->whereNotNull('headline')
            ->whereNotNull('body_ad_settings')
            ->where('messaging_conversations_started', '>', 0)
            ->get()
            ->map(function ($ad) use ($hasMsgTpl, $hasWelcome, $hasQR1, $hasQR2, $hasQR3) {
                // Compute CPM (Cost per Message)
                $den   = (float) ($ad->messaging_conversations_started ?? 0);
                $num   = (float) ($ad->amount_spent_php ?? 0);
                $ad->cpm = $den > 0 ? ($num / $den) : null;

                // Normalize welcome/message template into one "welcome" field
                $welcome = null;
                if ($hasMsgTpl && isset($ad->messaging_template) && $ad->messaging_template !== null) {
                    $welcome = trim((string) $ad->messaging_template);
                } elseif ($hasWelcome && isset($ad->welcome_message) && $ad->welcome_message !== null) {
                    $welcome = trim((string) $ad->welcome_message);
                }
                $ad->welcome = $welcome;

                // Normalize quick replies
                $ad->qr1 = $hasQR1 && isset($ad->quick_reply_1) ? trim((string) $ad->quick_reply_1) : null;
                $ad->qr2 = $hasQR2 && isset($ad->quick_reply_2) ? trim((string) $ad->quick_reply_2) : null;
                $ad->qr3 = $hasQR3 && isset($ad->quick_reply_3) ? trim((string) $ad->quick_reply_3) : null;

                // Trim core texts
                $ad->headline         = isset($ad->headline) ? trim((string) $ad->headline) : null;
                $ad->body_ad_settings = isset($ad->body_ad_settings) ? trim((string) $ad->body_ad_settings) : null;

                return $ad;
            })
            ->filter(fn ($ad) => $ad->cpm !== null);

        if ($ads->isEmpty()) {
            $scopeNote = ($page && $page !== 'all') ? " for “{$page}”" : "";
            return response()->json(['output' => "⚠️ No valid ad copies found{$scopeNote}."], 200);
        }

        // Group by the creative set (headline+body+welcome+qr1+qr2+qr3)
        $grouped = $ads->groupBy(function ($ad) {
            return implode('|', [
                $ad->headline ?? '',
                $ad->body_ad_settings ?? '',
                $ad->welcome ?? '',
                $ad->qr1 ?? '',
                $ad->qr2 ?? '',
                $ad->qr3 ?? '',
            ]);
        })->map(function ($group) {
            $first = $group->first();
            return [
                'headline' => $first->headline,
                'body'     => $first->body_ad_settings,
                'welcome'  => $first->welcome,
                'qr1'      => $first->qr1,
                'qr2'      => $first->qr2,
                'qr3'      => $first->qr3,
                'cpm'      => $group->avg('cpm'),
            ];
        })->values();

        // Sort & slice
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

        // Compose output text
        $sections = [
            '🔝 TOP-PERFORMING ADS (Lowest CPM)'  => $top,
            '🔴 WORST-PERFORMING ADS (Highest CPM)' => $worst,
            '🟡 MEAN CPM GROUP'                   => $matchByCpm($mean),
            '🟣 MEDIAN CPM GROUP'                 => $matchByCpm($median),
            '🔵 MODE CPM GROUP'                   => $matchByCpm($mode),
        ];

        $out = collect($sections)->map(function ($group, $label) {
            if ($group->isEmpty()) {
                return "{$label}\n  (no data)";
            }
            $lines = ["{$label}"];
            foreach ($group as $index => $item) {
                $i = $index + 1;
                $lines[] = $this->formatSuggestionLine($i, $item);
            }
            return implode("\n", $lines);
        })->values()->implode("\n\n");

        return response()->json(['output' => $out]);
    }

    /**
     * Helper: nicely format one suggestion block.
     */
    private function formatSuggestionLine(int $index, array $item): string
    {
        $line = $index . '. Headline: "' . ($item['headline'] ?? '') . '"' .
            "\n   Body: \"" . ($item['body'] ?? '') . "\"";

        if (!empty($item['welcome'])) {
            $line .= "\n   Welcome Message: \"" . $item['welcome'] . '"';
        }

        $qrParts = [];
        if (!empty($item['qr1'])) $qrParts[] = $item['qr1'];
        if (!empty($item['qr2'])) $qrParts[] = $item['qr2'];
        if (!empty($item['qr3'])) $qrParts[] = $item['qr3'];
        if (!empty($qrParts)) {
            $line .= "\n   Quick Replies: " . implode(' | ', array_map(fn ($q) => '"' . $q . '"', $qrParts));
        }

        $line .= "\n   CPM (Cost per Message): ₱" . number_format((float) $item['cpm'], 2);
        return $line;
    }
}

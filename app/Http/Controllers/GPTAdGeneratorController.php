<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class GPTAdGeneratorController extends Controller
{
    /** POST /api/generate-gpt-summary */
    public function generate(Request $request)
    {
        $request->validate(['prompt' => 'required|string']);
        $prompt = $request->input('prompt');

        try {
            $response = Http::withToken(env('OPENAI_API_KEY'))->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4',
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'temperature' => 0.7,
            ]);

            Log::info('GPT Response', $response->json());

            if ($response->successful()) {
                return response()->json([
                    'output' => $response['choices'][0]['message']['content'] ?? 'No output from GPT.',
                ], 200, [], JSON_UNESCAPED_UNICODE);
            }

            return response()->json([
                'output' => '❌ GPT request failed.',
                'error'  => $response->body(),
            ], 500, [], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            Log::error('GPT Exception: ' . $e->getMessage());
            return response()->json([
                'output' => '❌ Server error occurred.',
                'error'  => $e->getMessage(),
            ], 500, [], JSON_UNESCAPED_UNICODE);
        }
    }

    /** GET /gpt-ad-generator */
    public function showGeneratorForm()
    {
        $promptText = file_get_contents(resource_path('views/gpt/gpt_ad_prompts.txt'));

        // Get raw page names, normalize in PHP (avoid SQL REPLACE/UNHEX)
        $rawPages = DB::table('ads_manager_reports')
            ->whereNotNull('page_name')
            ->pluck('page_name');

        $pages = collect($rawPages)->map(fn ($p) => $this->normalizePage($p))
            ->filter()->unique()->sort()->values()->toArray();

        return view('gpt.gpt_ad_generator', compact('promptText', 'pages'));
    }

    /** GET /ad-copy-suggestions?page={page|all} */
    public function loadAdCopySuggestions(Request $request)
    {
        try {
            $pageParam = $request->query('page');
            $pageNorm  = $this->normalizePage(is_string($pageParam) ? $pageParam : '');
            $applyPage = $pageNorm !== '' && mb_strtolower($pageNorm) !== 'all';

            // If filtering by page, resolve to the exact RAW page_name variants that normalize to $pageNorm
            $rawMatches = null;
            if ($applyPage) {
                $rawPages = DB::table('ads_manager_reports')
                    ->whereNotNull('page_name')
                    ->select('page_name')
                    ->distinct()
                    ->pluck('page_name');

                $rawMatches = [];
                foreach ($rawPages as $rp) {
                    if ($this->normalizePage($rp) === $pageNorm) {
                        $rawMatches[] = $rp; // exact raw string stored in DB
                    }
                }

                if (empty($rawMatches)) {
                    return response()->json([
                        'output' => "⚠️ No matching page found for “{$pageNorm}”."
                    ], 200, [], JSON_UNESCAPED_UNICODE);
                }
            }

            // 1) Aggregate reports (NO SQL REPLACE/UNHEX)
            $reports = DB::table('ads_manager_reports as r')
                ->when($applyPage, fn ($q) => $q->whereIn('r.page_name', $rawMatches))
                ->whereNotNull('r.ad_id')
                ->where('r.ad_id', '<>', '')
                ->select([
                    'r.ad_id',
                    DB::raw('SUM(COALESCE(r.amount_spent_php, 0)) AS spend'),
                    DB::raw('SUM(COALESCE(r.messaging_conversations_started, 0)) AS msgs'),
                    DB::raw('MAX(r.page_name) AS page_name'),
                ])
                ->groupBy('r.ad_id')
                ->havingRaw('SUM(COALESCE(r.messaging_conversations_started, 0)) > 0')
                ->get();

            if ($reports->isEmpty()) {
                $scope = $applyPage ? " for “{$pageNorm}”" : "";
                return response()->json(['output' => "⚠️ No valid ad reports found{$scope}."], 200, [], JSON_UNESCAPED_UNICODE);
            }

            // 2) Fetch creatives by ad_id
            $adIds = $reports->pluck('ad_id')->unique()->values()->all();
            $creatives = DB::table('ad_campaign_creatives as c')
                ->whereIn('c.ad_id', $adIds)
                ->select([
                    'c.ad_id',
                    'c.headline',
                    'c.body_ad_settings',
                    'c.welcome_message',
                    'c.quick_reply_1',
                    'c.quick_reply_2',
                    'c.quick_reply_3',
                ])
                ->get()
                ->keyBy('ad_id');

            // 3) Merge + compute CPM; normalize page in PHP
            $rows = $reports->map(function ($r) use ($creatives, $applyPage, $pageNorm) {
                $c = $creatives->get($r->ad_id);
                if (!$c) return null;

                $headline = $c->headline ? trim($c->headline) : null;
                $body     = $c->body_ad_settings ? trim($c->body_ad_settings) : null;
                if ($headline === null || $body === null) return null;

                $msgs  = (float) $r->msgs;
                $spend = (float) $r->spend;
                $cpm   = $msgs > 0 ? ($spend / $msgs) : null;

                $pageOut = $r->page_name ?? ($applyPage ? $pageNorm : 'all');
                $pageOut = $this->normalizePage($pageOut);

                return (object) [
                    'ad_id'            => $r->ad_id,
                    'headline'         => $headline,
                    'body_ad_settings' => $body,
                    'welcome_message'  => $c->welcome_message ? trim($c->welcome_message) : null,
                    'quick_reply_1'    => $c->quick_reply_1 ? trim($c->quick_reply_1) : null,
                    'quick_reply_2'    => $c->quick_reply_2 ? trim($c->quick_reply_2) : null,
                    'quick_reply_3'    => $c->quick_reply_3 ? trim($c->quick_reply_3) : null,
                    'cpm'              => $cpm,
                    'page_name'        => $pageOut,
                ];
            })
            ->filter()
            ->filter(fn ($row) => $row->cpm !== null)
            ->values();

            if ($rows->isEmpty()) {
                $scope = $applyPage ? " for “{$pageNorm}”" : "";
                return response()->json(['output' => "⚠️ No valid ad data found{$scope}."], 200, [], JSON_UNESCAPED_UNICODE);
            }

            // 4) Rankings/stats from FILTERED set only
            $sorted = $rows->sortBy('cpm')->values();
            $top    = $sorted->take(5);
            $worst  = $sorted->reverse()->take(5);

            $cpms   = $rows->pluck('cpm')->sort()->values();
            $count  = $cpms->count();
            $mean   = $count ? $cpms->avg() : null;
            $median = $count
                ? ($count % 2 === 0
                    ? (($cpms[$count/2 - 1] + $cpms[$count/2]) / 2)
                    : $cpms[floor($count/2)])
                : null;
            $mode   = $count ? $cpms->countBy()->sortDesc()->keys()->first() : null;

            $nearest = function ($value) use ($rows) {
                if ($value === null) return collect();
                return $rows->sortBy(fn ($r) => abs($r->cpm - $value))->take(5)->values();
            };

            $sections = [
                '🔝 TOP-PERFORMING ADS (Lowest CPM)'     => $top,
                '🔴 WORST-PERFORMING ADS (Highest CPM)'  => $worst,
                '🟡 MEAN CPM GROUP'                      => $nearest($mean),
                '🟣 MEDIAN CPM GROUP'                    => $nearest($median),
                '🔵 MODE CPM GROUP'                      => $nearest($mode),
            ];

            $out = collect($sections)->map(function ($group, $label) {
                if ($group->isEmpty()) return "{$label}\n  (no data)";
                $lines = ["{$label}"];
                foreach ($group as $i => $row) {
                    $lines[] = $this->formatSuggestionBlock($i + 1, $row);
                }
                return implode("\n", $lines);
            })->values()->implode("\n\n");

            return response()->json(['output' => $out], 200, [], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            Log::error('loadAdCopySuggestions error', ['msg' => $e->getMessage()]);
            return response()->json([
                'output' => '❌ Server error occurred.',
                'error'  => $e->getMessage(),
            ], 500, [], JSON_UNESCAPED_UNICODE);
        }
    }

    /** Helpers */
    private function formatSuggestionBlock(int $index, object $row): string
    {
        $v = fn ($x) => isset($x) && $x !== '' ? $x : '—';

        $line  = $index . '. Headline: "' . $v($row->headline) . '"';
        $line .= "\n   Body: \"" . $v($row->body_ad_settings) . '"';
        $line .= "\n   Welcome Message: \"" . $v($row->welcome_message) . '"';
        $line .= "\n   QR1: " . $v($row->quick_reply_1);
        $line .= "\n   QR2: " . $v($row->quick_reply_2);
        $line .= "\n   QR3: " . $v($row->quick_reply_3);
        $line .= "\n   CPM: ₱" . number_format((float) $row->cpm, 2);
        $line .= "\n   Page: " . $v($row->page_name);

        return $line;
    }

    private function normalizePage($s): string
    {
        $s = (string) $s;
        // Replace UTF-8 NBSP (C2 A0) and single-byte A0 with regular space
        $s = str_replace(["\xC2\xA0", "\xA0"], ' ', $s);
        // Collapse multiple spaces
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim($s);
    }
}

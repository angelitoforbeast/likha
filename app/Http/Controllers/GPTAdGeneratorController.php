<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GPTAdGeneratorController extends Controller
{
    /**
     * Whitelist of OpenAI models the generator may call. User picks per-request
     * via the UI dropdown; backend rejects anything not in this list. Order
     * here is the order shown in the dropdown.
     *
     * Labels are shown to the user; keys are the actual model IDs sent to OpenAI.
     */
    public const ALLOWED_MODELS = [
        'gpt-4o'         => 'GPT-4o (recommended) — best quality vs cost',
        'gpt-4o-mini'    => 'GPT-4o mini — cheapest, fast, slightly lower quality',
        'gpt-4-turbo'    => 'GPT-4 Turbo — older, larger context',
        'gpt-4'          => 'GPT-4 (legacy)',
    ];

    /** Default model when the request omits one or sends an invalid value. */
    public const DEFAULT_MODEL = 'gpt-4o';

    /**
     * GET /gpt-ad-generator/history
     *
     * Browseable list of past generations, filterable by user / product / model.
     * Anyone na may access sa /gpt-ad-generator ay pwede tingnan lahat — para
     * makita ang patterns ng team. Each row displays a "View" button na
     * nag-fe-fetch ng detail JSON via historyDetail().
     */
    public function history(Request $request)
    {
        $q          = trim((string) $request->query('q', ''));
        $userFilter = trim((string) $request->query('user', ''));
        $modelFilter= trim((string) $request->query('model', ''));

        // LEFT JOIN users to resolve display name (avoids needing a column).
        // Optional second JOIN to employee_profiles for the proper full name when set.
        $query = DB::table('gpt_ad_generations as g')
            ->leftJoin('users as u', 'u.email', '=', 'g.user_email')
            ->leftJoin('employee_profiles as ep', 'ep.user_id', '=', 'u.id')
            ->select([
                'g.*',
                DB::raw('COALESCE(ep.name, u.name) AS user_name'),
            ])
            ->orderByDesc('g.created_at');

        if ($q !== '') {
            $like = '%'.mb_strtolower($q).'%';
            $query->where(function ($w) use ($like) {
                $w->whereRaw('LOWER(g.product_name) LIKE ?', [$like])
                  ->orWhereRaw('LOWER(g.product_description) LIKE ?', [$like])
                  ->orWhereRaw('LOWER(g.page_filter) LIKE ?', [$like])
                  ->orWhereRaw('LOWER(g.item_filter) LIKE ?', [$like]);
            });
        }
        if ($userFilter !== '') {
            $query->where('g.user_email', $userFilter);
        }
        if ($modelFilter !== '') {
            $query->where('g.model', $modelFilter);
        }

        $rows = $query->paginate(50)->appends($request->query());

        // Distinct dropdown lists for filters
        $allUsers  = DB::table('gpt_ad_generations')->whereNotNull('user_email')
            ->distinct()->orderBy('user_email')->pluck('user_email')->toArray();
        $allModels = DB::table('gpt_ad_generations')->whereNotNull('model')
            ->distinct()->orderBy('model')->pluck('model')->toArray();

        return view('gpt.gpt_ad_history', [
            'rows'        => $rows,
            'q'           => $q,
            'userFilter'  => $userFilter,
            'modelFilter' => $modelFilter,
            'allUsers'    => $allUsers,
            'allModels'   => $allModels,
        ]);
    }

    /**
     * POST /gpt-ad-generator/prompt
     *
     * Save the editable base prompt back to resources/views/gpt/gpt_ad_prompts.txt
     * so future page loads default to this version. Auth required to prevent
     * anonymous edits.
     */
    public function savePrompt(Request $request)
    {
        $request->validate(['prompt' => 'required|string|max:20000']);
        if (!Auth::check()) {
            return response()->json(['error' => 'Login required to save prompt.'], 403);
        }
        try {
            $path = resource_path('views/gpt/gpt_ad_prompts.txt');
            file_put_contents($path, $request->input('prompt'));
            return response()->json([
                'ok'         => true,
                'saved_at'   => now()->toIso8601String(),
                'saved_by'   => Auth::user()?->name ?? Auth::user()?->email,
            ]);
        } catch (\Throwable $e) {
            Log::error('savePrompt error: ' . $e->getMessage());
            return response()->json(['error' => 'Save failed.', 'detail' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /gpt-ad-generator/history/{id}
     *
     * JSON detail of a single past generation. Used by the history view's
     * inline detail expand.
     */
    public function historyDetail(int $id)
    {
        $row = DB::table('gpt_ad_generations as g')
            ->leftJoin('users as u', 'u.email', '=', 'g.user_email')
            ->leftJoin('employee_profiles as ep', 'ep.user_id', '=', 'u.id')
            ->select([
                'g.*',
                DB::raw('COALESCE(ep.name, u.name) AS user_name'),
            ])
            ->where('g.id', $id)
            ->first();
        if (!$row) {
            return response()->json(['error' => 'Not found'], 404);
        }
        $row->output_variants = json_decode($row->output_variants ?? '[]', true) ?: [];
        return response()->json($row);
    }

    /**
     * POST /api/generate-gpt-summary
     *
     * If `stream=1` AND `n=1` → returns text/event-stream (SSE) chunks of GPT
     * deltas as they arrive. After the stream completes, history row is
     * inserted server-side. Otherwise → returns JSON with `variants[]`.
     */
    public function generate(Request $request)
    {
        $request->validate([
            'prompt'              => 'required|string',
            'temperature'         => 'sometimes|numeric|min:0|max:2',
            'n'                   => 'sometimes|integer|min:1|max:5',
            'stream'              => 'sometimes|boolean',
            'model'               => 'sometimes|string|in:' . implode(',', array_keys(self::ALLOWED_MODELS)),
            'product_name'        => 'sometimes|string|max:255',
            'product_description' => 'sometimes|string',
            'page_filter'         => 'sometimes|string|max:255|nullable',
            'item_filter'         => 'sometimes|string|max:255|nullable',
            'active_only'         => 'sometimes|boolean',
        ]);

        $prompt        = $request->input('prompt');
        $temperature   = (float) $request->input('temperature', 0.5);
        $n             = (int)   $request->input('n', 1);
        $streamWanted  = (bool)  $request->input('stream', false);
        $stream        = $streamWanted && $n === 1; // SSE only when single variant
        $requestedModel = (string) $request->input('model', '');
        $model         = isset(self::ALLOWED_MODELS[$requestedModel]) ? $requestedModel : self::DEFAULT_MODEL;

        $payload = [
            'model'       => $model,
            'messages'    => [
                ['role' => 'system', 'content' => $this->systemPrompt()],
                ['role' => 'user',   'content' => $prompt],
            ],
            'temperature' => $temperature,
            'max_tokens'  => 500,
            'n'           => $n,
        ];

        $context = [
            'product_name'        => (string) $request->input('product_name', ''),
            'product_description' => (string) $request->input('product_description', ''),
            'page_filter'         => $request->input('page_filter') ?: null,
            'item_filter'         => $request->input('item_filter') ?: null,
            'active_only'         => (bool) $request->input('active_only', true),
            'temperature'         => $temperature,
            'variants_requested'  => $n,
            'final_prompt'        => $prompt,
            'model'               => $model,
        ];

        if ($stream) {
            return $this->generateStreaming($payload, $context);
        }

        return $this->generateJson($payload, $context);
    }

    /** Non-streaming path — single OpenAI call, returns JSON with variants[]. */
    private function generateJson(array $payload, array $context)
    {
        try {
            $response = Http::withToken(env('OPENAI_API_KEY'))
                ->timeout(60)
                ->post('https://api.openai.com/v1/chat/completions', $payload);

            if (!$response->successful()) {
                return response()->json([
                    'output' => '❌ GPT request failed.',
                    'error'  => $response->body(),
                ], 500);
            }

            $variants = collect($response['choices'] ?? [])
                ->map(fn ($c) => trim($c['message']['content'] ?? ''))
                ->filter()
                ->values()
                ->all();

            $historyId = $this->logGeneration($context, $variants);

            return response()->json([
                'output'     => $variants[0] ?? 'No output from GPT.',
                'variants'   => $variants,
                'model'      => $payload['model'] ?? null,
                'history_id' => $historyId,
            ]);
        } catch (\Exception $e) {
            Log::error('GPT Exception: ' . $e->getMessage());
            return response()->json([
                'output' => '❌ Server error occurred.',
                'error'  => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Streaming path — opens an SSE response and forwards OpenAI delta chunks
     * to the client as they arrive. After the upstream stream ends, the full
     * accumulated text is logged to gpt_ad_generations.
     *
     * Uses Guzzle's stream option for clean chunked reads. Callers should
     * disable PHP output buffering at the server level for true low-latency
     * streaming (e.g. set `output_buffering = Off` in php.ini, or
     * `fastcgi_buffering off` in nginx).
     */
    private function generateStreaming(array $payload, array $context): StreamedResponse
    {
        $payload['stream'] = true;

        $response = new StreamedResponse(function () use ($payload, $context) {
            // Disable PHP output buffering so chunks reach the client live.
            @ob_implicit_flush(true);
            while (ob_get_level() > 0) @ob_end_flush();

            $accumulated = '';

            try {
                $upstream = Http::withToken(env('OPENAI_API_KEY'))
                    ->timeout(120)
                    ->withOptions(['stream' => true])
                    ->post('https://api.openai.com/v1/chat/completions', $payload);

                $body = $upstream->toPsrResponse()->getBody();
                $buffer = '';

                while (!$body->eof()) {
                    $chunk = $body->read(4096);
                    if ($chunk === '' || $chunk === false) continue;
                    $buffer .= $chunk;

                    // OpenAI sends events separated by "\n\n". Process complete events,
                    // keep incomplete tail in buffer.
                    while (($pos = strpos($buffer, "\n\n")) !== false) {
                        $event = substr($buffer, 0, $pos);
                        $buffer = substr($buffer, $pos + 2);

                        foreach (explode("\n", $event) as $line) {
                            $line = trim($line);
                            if ($line === '' || !str_starts_with($line, 'data:')) continue;
                            $json = trim(substr($line, 5));
                            if ($json === '[DONE]') {
                                echo "data: [DONE]\n\n";
                                @flush();
                                continue;
                            }
                            $data = json_decode($json, true);
                            $delta = $data['choices'][0]['delta']['content'] ?? '';
                            if ($delta !== '') {
                                $accumulated .= $delta;
                                echo 'data: ' . json_encode(['delta' => $delta], JSON_UNESCAPED_UNICODE) . "\n\n";
                                @flush();
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::error('GPT streaming exception: ' . $e->getMessage());
                echo 'data: ' . json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE) . "\n\n";
                @flush();
            }

            // Persist after stream ends (best-effort).
            try {
                $accumulated = trim($accumulated);
                if ($accumulated !== '') {
                    $this->logGeneration($context, [$accumulated]);
                }
            } catch (\Throwable $e) {
                Log::error('GPT streaming log error: ' . $e->getMessage());
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no'); // nginx: don't buffer
        return $response;
    }

    /** System prompt content — pulled out to share between JSON + streaming paths. */
    private function systemPrompt(): string
    {
        return implode("\n", [
            'You are a performance-focused Facebook Ads copywriter.',
            'Treat the "=== Suggestions" block as style/structure/length REFERENCE only.',
            'Prefer tone/phrasing and structural patterns seen in TOP-PERFORMING items; avoid WORST patterns.',
            'Mirror the typical length of TOP-PERFORMING samples for Primary Text and Messaging Template.',
            'You MAY adapt or create new Quick Replies that reduce friction; keep them short. Do NOT copy QR1–QR3 verbatim unless they are already optimal.',
            'Do NOT invent details (colors/sizes/fit/materials/variants/bundles/warranty/COD/delivery/promos/price) unless explicitly present in Suggestions or the Product Description.',
            'Output EXACTLY one single line with 7 tab-separated fields: Item, Primary Text, Headline, Messaging Template, Quick Reply 1, Quick Reply 2, Quick Reply 3.',
            'Never add headers, labels, explanations, or extra lines.',
        ]);
    }

    /**
     * Insert a row into gpt_ad_generations. Best-effort — failures are logged
     * but not surfaced to the user. Returns inserted id or null.
     */
    private function logGeneration(array $context, array $variants): ?int
    {
        if (!Schema::hasTable('gpt_ad_generations')) return null;
        try {
            return DB::table('gpt_ad_generations')->insertGetId([
                'user_email'         => Auth::user()?->email,
                'product_name'       => $context['product_name'] ?? '',
                'product_description'=> $context['product_description'] ?? '',
                'page_filter'        => $context['page_filter'] ?? null,
                'item_filter'        => $context['item_filter'] ?? null,
                'active_only'        => (bool) ($context['active_only'] ?? true),
                'temperature'        => $context['temperature'] ?? null,
                'variants_requested' => $context['variants_requested'] ?? 1,
                'final_prompt'       => $context['final_prompt'] ?? null,
                'output_variants'    => json_encode(array_values($variants), JSON_UNESCAPED_UNICODE),
                'model'              => $context['model'] ?? null,
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('logGeneration error: ' . $e->getMessage());
            return null;
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

        // Distinct items from ads_manager_reports.item_name (for the new
        // item filter dropdown). Normalized + de-duped same as pages.
        $rawItems = DB::table('ads_manager_reports')
            ->whereNotNull('item_name')
            ->where('item_name', '<>', '')
            ->pluck('item_name');

        $items = collect($rawItems)->map(fn ($i) => $this->normalizePage($i))
            ->filter()->unique()->sort()->values()->toArray();

        $models        = self::ALLOWED_MODELS;
        $defaultModel  = self::DEFAULT_MODEL;

        return view('gpt.gpt_ad_generator', compact('promptText', 'pages', 'items', 'models', 'defaultModel'));
    }

    /**
     * GET /ad-copy-suggestions?page={page|all}&item={item|all}&active_only={0|1}
     *
     * Cached 5 minutes per (page, item, active_only) combo.
     */
    public function loadAdCopySuggestions(Request $request)
    {
        $pageParam  = (string) $request->query('page', 'all');
        $itemParam  = (string) $request->query('item', 'all');
        $activeOnly = (string) $request->query('active_only', '1') === '1';

        $pageNorm = $this->normalizePage($pageParam);
        $itemNorm = $this->normalizePage($itemParam);

        $cacheKey = sprintf(
            'gpt_suggestions:%s:%s:%d',
            mb_strtolower($pageNorm !== '' ? $pageNorm : 'all'),
            mb_strtolower($itemNorm !== '' ? $itemNorm : 'all'),
            $activeOnly ? 1 : 0
        );

        try {
            $payload = Cache::remember($cacheKey, 300 /* 5 min */, function () use ($pageNorm, $itemNorm, $activeOnly) {
                return $this->buildSuggestions($pageNorm, $itemNorm, $activeOnly);
            });
        } catch (\Throwable $e) {
            Log::error('loadAdCopySuggestions cache error', ['msg' => $e->getMessage()]);
            $payload = ['output' => '❌ Server error occurred.', 'error' => $e->getMessage()];
        }

        return response()->json($payload, 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Build the CPM-ranked suggestions payload. Pure function (no request);
     * extracted so it's cache-friendly and can be called recursively for the
     * active→all-time fallback.
     *
     * Returns: ['output' => string] or ['output' => warning] when empty.
     * When fallback was used (active had no data), also includes
     * 'fallback_used' => true and 'fallback_reason' => string.
     */
    private function buildSuggestions(string $pageNorm, string $itemNorm, bool $activeOnly): array
    {
        try {
            $applyPage = $pageNorm !== '' && mb_strtolower($pageNorm) !== 'all';
            $applyItem = $itemNorm !== '' && mb_strtolower($itemNorm) !== 'all';

            // Resolve raw page_name strings that normalize to $pageNorm (covers
            // NBSP / whitespace variants in the DB).
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
                        $rawMatches[] = $rp;
                    }
                }

                if (empty($rawMatches)) {
                    return ['output' => "⚠️ No matching page found for “{$pageNorm}”."];
                }
            }

            // Active-set filter: subquery of ad_set_ids whose ad_set_delivery
            // is 'active%' on its latest day. Mirrors AdsManagerCampaignsController.
            $activeAdSets = null;
            if ($activeOnly) {
                $latestAdSetDay = DB::table('ads_manager_reports')
                    ->selectRaw('ad_set_id, MAX(`day`) AS latest_day')
                    ->whereNotNull('ad_set_id')
                    ->groupBy('ad_set_id');

                $activeAdSets = DB::table(DB::raw('ads_manager_reports a'))
                    ->joinSub($latestAdSetDay, 't', function ($j) {
                        $j->on('a.ad_set_id', '=', 't.ad_set_id')
                          ->whereRaw('a.`day` = t.latest_day');
                    })
                    ->whereRaw("LOWER(TRIM(a.ad_set_delivery)) LIKE 'active%'")
                    ->select('a.ad_set_id')
                    ->distinct();
            }

            // 1) Aggregate reports
            $reports = DB::table('ads_manager_reports as r')
                ->when($applyPage, fn ($q) => $q->whereIn('r.page_name', $rawMatches))
                ->when($applyItem, fn ($q) => $q->whereRaw(
                    'LOWER(COALESCE(r.item_name, \'\')) LIKE ?',
                    ['%'.mb_strtolower($itemNorm).'%']
                ))
                ->when($activeAdSets, fn ($q) => $q->whereIn('r.ad_set_id', $activeAdSets))
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

            // Fallback: if active-only returned nothing, retry without the
            // active filter and tag the response so the UI can show a banner.
            if ($reports->isEmpty() && $activeOnly) {
                $fallback = $this->buildSuggestions($pageNorm, $itemNorm, false);
                $fallback['fallback_used']   = true;
                $fallback['fallback_reason'] = 'No active ads found for this scope. Showing all-time data instead.';
                return $fallback;
            }

            if ($reports->isEmpty()) {
                $scope = $this->scopeLabel($pageNorm, $itemNorm, $activeOnly);
                return ['output' => "⚠️ No valid ad reports found{$scope}."];
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
                $scope = $this->scopeLabel($pageNorm, $itemNorm, $activeOnly);
                return ['output' => "⚠️ No valid ad data found{$scope}."];
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

            return ['output' => $out, 'fallback_used' => false];
        } catch (\Throwable $e) {
            Log::error('buildSuggestions error', ['msg' => $e->getMessage()]);
            return [
                'output' => '❌ Server error occurred.',
                'error'  => $e->getMessage(),
            ];
        }
    }

    /** Human-readable scope label for "no data" warning messages. */
    private function scopeLabel(string $pageNorm, string $itemNorm, bool $activeOnly): string
    {
        $parts = [];
        if ($pageNorm !== '' && mb_strtolower($pageNorm) !== 'all') $parts[] = "page “{$pageNorm}”";
        if ($itemNorm !== '' && mb_strtolower($itemNorm) !== 'all') $parts[] = "item “{$itemNorm}”";
        if ($activeOnly) $parts[] = 'active ads only';
        return $parts ? ' for ' . implode(', ', $parts) : '';
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

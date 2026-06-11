<?php

namespace App\Http\Controllers;

use App\Models\GptVideoAnalysis;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
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
     * Save a new version of the base prompt to the `gpt_prompts` table
     * (append-only — every save creates a new row, preserving full history).
     * The latest row is what showGeneratorForm() resolves on load.
     *
     * Auth required so we can attribute changes.
     */
    public function savePrompt(Request $request)
    {
        $request->validate([
            'prompt' => 'required|string|max:20000',
            'note'   => 'nullable|string|max:500',
        ]);
        if (!Auth::check()) {
            return response()->json(['error' => 'Login required to save prompt.'], 403);
        }
        try {
            $id = DB::table('gpt_prompts')->insertGetId([
                'prompt_text'    => $request->input('prompt'),
                'saved_by_email' => Auth::user()?->email,
                'note'           => $request->input('note'),
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
            return response()->json([
                'ok'         => true,
                'id'         => $id,
                'saved_at'   => now()->toIso8601String(),
                'saved_by'   => Auth::user()?->name ?? Auth::user()?->email,
            ]);
        } catch (\Throwable $e) {
            Log::error('savePrompt error: ' . $e->getMessage());
            return response()->json(['error' => 'Save failed.', 'detail' => $e->getMessage()], 500);
        }
    }

    /** GET /gpt-ad-generator/prompt-history — list all versions. */
    public function promptHistory()
    {
        $rows = DB::table('gpt_prompts as p')
            ->leftJoin('users as u', 'u.email', '=', 'p.saved_by_email')
            ->leftJoin('employee_profiles as ep', 'ep.user_id', '=', 'u.id')
            ->select([
                'p.*',
                DB::raw('COALESCE(ep.name, u.name) AS saved_by_name'),
            ])
            ->orderByDesc('p.id')
            ->paginate(30);

        return view('gpt.gpt_prompt_history', ['rows' => $rows]);
    }

    /**
     * GET /gpt-ad-generator/prompt-history/{id} — single version JSON.
     * Used by the inline detail expand and the "Restore" preview.
     */
    public function promptVersion(int $id)
    {
        $row = DB::table('gpt_prompts as p')
            ->leftJoin('users as u', 'u.email', '=', 'p.saved_by_email')
            ->leftJoin('employee_profiles as ep', 'ep.user_id', '=', 'u.id')
            ->select([
                'p.*',
                DB::raw('COALESCE(ep.name, u.name) AS saved_by_name'),
            ])
            ->where('p.id', $id)
            ->first();
        if (!$row) return response()->json(['error' => 'Not found'], 404);
        return response()->json($row);
    }

    /**
     * POST /gpt-ad-generator/prompt-history/{id}/restore — make this version
     * the active one by inserting a new row with the same text + a note.
     * Auth required.
     */
    public function promptRestore(int $id, Request $request)
    {
        if (!Auth::check()) {
            return response()->json(['error' => 'Login required.'], 403);
        }
        try {
            $src = DB::table('gpt_prompts')->where('id', $id)->first();
            if (!$src) return response()->json(['error' => 'Source version not found'], 404);

            $newId = DB::table('gpt_prompts')->insertGetId([
                'prompt_text'    => $src->prompt_text,
                'saved_by_email' => Auth::user()?->email,
                'note'           => 'Restored from version #' . $id,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);

            return response()->json([
                'ok'           => true,
                'id'           => $newId,
                'restored_from'=> $id,
            ]);
        } catch (\Throwable $e) {
            \Log::error('promptRestore failed: ' . $e->getMessage());
            return response()->json(['error' => 'Restore failed: ' . $e->getMessage()], 500);
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
            // page_filter: single string or comma-separated multi-page string.
            // Stored as-is for history log; buildSuggestions handles the split.
            'page_filter'         => $request->input('page_filter') ?: null,
            'item_filter'         => $request->input('item_filter') ?: null,
            'active_only'         => (bool) $request->input('active_only', true),
            'temperature'         => $temperature,
            'variants_requested'  => $n,
            'final_prompt'        => $prompt,
            'model'               => $model,
            // Optional — links the generation back to a video analysis row.
            'video_analysis_id'   => $request->input('video_analysis_id') ? (int) $request->input('video_analysis_id') : null,
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
                // Server-side safety net — GPT sometimes ignores the "single-line"
                // rule for QRs (e.g. appends "ProductName" sa likod ng QR with
                // line breaks). Force-clean para guaranteed single-line yung
                // Item, Headline, QR1, QR2, QR3 regardless of model behavior.
                ->map(fn ($v) => $this->sanitizeVariant($v))
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

            // Persist after stream ends (best-effort). Sanitize same as the
            // non-streaming path so the saved record has clean single-line QRs
            // even kung yung live-streamed view sa client was raw.
            try {
                $accumulated = trim($accumulated);
                if ($accumulated !== '') {
                    $this->logGeneration($context, [$this->sanitizeVariant($accumulated)]);
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
            // HARD constraint — never violate.
            'HARD CONSTRAINT: Do NOT invent factual details (colors/sizes/fit/materials/variants/bundles/warranty/COD/delivery/promos/price) unless explicitly present in Suggestions or the Product Description.',
            // Diversity — only matters when the request asks for multiple variants.
            'DIVERSITY: When generating MORE than one variant in a single response, EACH variant MUST use a DISTINCT hook angle from this set: (1) curiosity / question hook, (2) fear or safety / pain-point, (3) social proof / others-are-buying, (4) value / sulit / worth-it, (5) urgency / FOMO. No two variants in the same response may share opening words or main hook category. Vary sentence rhythm, emoji placement, and call-to-action style across variants.',
            // Output shape — 7 tab-separated fields. Line breaks ALLOWED inside Primary Text and Messaging Template only.
            'Output EXACTLY 7 fields separated by REAL TAB CHARACTERS (ASCII 0x09) in this order: Item, Primary Text, Headline, Messaging Template, Quick Reply 1, Quick Reply 2, Quick Reply 3.',
            'Within Primary Text and Messaging Template, you MAY use real LF newlines (ASCII 0x0A) to separate the hook from a short bulleted list of benefits — only when it improves readability. Use ✅ or ✔ as bullet markers (mirror the TOP Suggestion examples). Aim for 2–4 bullets max when used.',
            'Item, Headline, Quick Reply 1, Quick Reply 2, and Quick Reply 3 must each stay on a SINGLE LINE — no internal line breaks.',
            'NEVER insert TAB characters inside any field — TAB is reserved as the field separator only.',
            'Never add headers, labels, explanations, or extra commentary around the output.',
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
            $insert = [
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
            ];
            // Attach video_analysis_id only if the column has been migrated
            // (graceful no-op if migration hasn't run yet).
            if (!empty($context['video_analysis_id']) && Schema::hasColumn('gpt_ad_generations', 'video_analysis_id')) {
                $insert['video_analysis_id'] = (int) $context['video_analysis_id'];
            }
            return DB::table('gpt_ad_generations')->insertGetId($insert);
        } catch (\Throwable $e) {
            Log::error('logGeneration error: ' . $e->getMessage());
            return null;
        }
    }



    /**
     * Resolve the current active prompt — latest row in `gpt_prompts`,
     * falling back to the legacy `gpt_ad_prompts.txt` file when the table
     * is empty (first-time deploy).
     */
    private function resolveActivePrompt(): string
    {
        if (Schema::hasTable('gpt_prompts')) {
            $row = DB::table('gpt_prompts')->orderByDesc('id')->first();
            if ($row && trim($row->prompt_text) !== '') return $row->prompt_text;
        }
        $path = resource_path('views/gpt/gpt_ad_prompts.txt');
        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    /** GET /gpt-ad-generator */
    public function showGeneratorForm()
    {
        $promptText = $this->resolveActivePrompt();

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
     * GET /ad-copy-suggestions
     *
     * Query params:
     *   page         — page name OR 'all'
     *   item         — item name OR 'all'
     *   active_only  — '0' | '1' (default 1)
     *   from_date    — YYYY-MM-DD (default = today − 30)
     *   to_date      — YYYY-MM-DD (default = today)
     *
     * Cached 5 minutes per full (page, item, active, from, to) combo.
     */
    public function loadAdCopySuggestions(Request $request)
    {
        $pageParam  = (string) $request->query('page', 'all');
        $itemParam  = (string) $request->query('item', 'all');
        $activeOnly = (string) $request->query('active_only', '1') === '1';

        // Date range — default last 30 days (PH timezone). Format strict YYYY-MM-DD.
        $tz       = new \DateTimeZone('Asia/Manila');
        $today    = (new \DateTime('now', $tz))->format('Y-m-d');
        $defFrom  = (new \DateTime('now', $tz))->modify('-30 days')->format('Y-m-d');
        $valid    = fn($s) => is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
        $fromDate = $valid($request->query('from_date')) ? $request->query('from_date') : $defFrom;
        $toDate   = $valid($request->query('to_date'))   ? $request->query('to_date')   : $today;
        if ($fromDate > $toDate) [$fromDate, $toDate] = [$toDate, $fromDate];

        // top_n — how many ads to include per section (4 sections × top_n total).
        // Clamped to 1–50 para hindi mag-blow up sa prompt size at token cost.
        $topN = (int) $request->query('top_n', 10);
        $topN = max(1, min(50, $topN));

        // page_filter supports multi-page via comma-separated string
        // e.g. "Annie Reyes,Bella Garcia". Split + normalize each; "all" = no filter.
        $pageNorms = $this->parseMultiPageParam($pageParam);
        $itemNorm  = $this->normalizePage($itemParam);

        // For single-page, preserve existing cache path. For multi-page, use
        // a combined key so we don't pollute the per-page cache entries.
        $pageKey = count($pageNorms) === 0 ? 'all'
                 : (count($pageNorms) === 1 ? $pageNorms[0] : 'multi:' . implode('|', $pageNorms));

        $cacheKey = sprintf(
            'gpt_suggestions:%s:%s:%d:%s:%s:%d',
            mb_strtolower($pageKey),
            mb_strtolower($itemNorm !== '' ? $itemNorm : 'all'),
            $activeOnly ? 1 : 0,
            $fromDate,
            $toDate,
            $topN
        );

        try {
            $payload = Cache::remember($cacheKey, 300 /* 5 min */, function () use ($pageNorms, $itemNorm, $activeOnly, $fromDate, $toDate, $topN) {
                return $this->buildSuggestions($pageNorms, $itemNorm, $activeOnly, $fromDate, $toDate, $topN);
            });
        } catch (\Throwable $e) {
            Log::error('loadAdCopySuggestions cache error', ['msg' => $e->getMessage()]);
            $payload = ['output' => '❌ Server error occurred.', 'error' => $e->getMessage()];
        }

        return response()->json($payload, 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Parse a comma-separated page_filter param into normalized page keys.
     * Returns empty array when value is "all" or blank (= no page filter).
     */
    private function parseMultiPageParam(string $param): array
    {
        $trimmed = trim($param);
        if ($trimmed === '' || mb_strtolower($trimmed) === 'all') return [];

        return array_values(array_filter(
            array_map(fn($p) => $this->normalizePage(trim($p)), explode(',', $trimmed)),
            fn($p) => $p !== '' && $p !== 'all'
        ));
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
    /**
     * @param  string|string[] $pageNormOrNorms  normalized page key(s). Empty array = all pages.
     *         Accepts both a single string (legacy) or an array (multi-page).
     */
    private function buildSuggestions($pageNormOrNorms, string $itemNorm, bool $activeOnly, ?string $fromDate = null, ?string $toDate = null, int $topN = 10): array
    {
        // Normalize to array for unified handling
        $pageNorms = is_array($pageNormOrNorms) ? $pageNormOrNorms : (
            ($pageNormOrNorms === '' || mb_strtolower($pageNormOrNorms) === 'all') ? [] : [$pageNormOrNorms]
        );

        // Single display string for scope labels / stats header / fallback page_name.
        // '' = all pages (so scopeLabel treats it as "All pages"). For multi-page,
        // comma-join for display. Internal filtering uses the $pageNorms array.
        $pageNorm = count($pageNorms) === 0 ? '' : implode(', ', $pageNorms);

        try {
            $applyPage = count($pageNorms) > 0;
            $applyItem = $itemNorm !== '' && mb_strtolower($itemNorm) !== 'all';

            // Resolve raw page_name strings that normalize to any of the $pageNorms
            $rawMatches = null;
            if ($applyPage) {
                $rawPages = DB::table('ads_manager_reports')
                    ->whereNotNull('page_name')
                    ->select('page_name')->distinct()->pluck('page_name');
                $rawMatches = [];
                foreach ($rawPages as $rp) {
                    if (in_array($this->normalizePage($rp), $pageNorms, true)) {
                        $rawMatches[] = $rp;
                    }
                }
                if (empty($rawMatches)) {
                    $pagesLabel = implode(', ', $pageNorms);
                    return ['output' => "No matching page found for: {$pagesLabel}."];
                }
            }

            // Active-set filter: latest-day delivery = 'active%'
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
                    ->select('a.ad_set_id')->distinct();
            }

            // 1) Aggregate reports — NOW WITH DATE FILTER + link_clicks
            $reports = DB::table('ads_manager_reports as r')
                ->when($applyPage, fn ($q) => $q->whereIn('r.page_name', $rawMatches))
                ->when($applyItem, fn ($q) => $q->whereRaw(
                    'LOWER(COALESCE(r.item_name, \'\')) LIKE ?',
                    ['%'.mb_strtolower($itemNorm).'%']
                ))
                ->when($activeAdSets, fn ($q) => $q->whereIn('r.ad_set_id', $activeAdSets))
                ->when($fromDate, fn ($q) => $q->whereRaw('DATE(r.`day`) >= ?', [$fromDate]))
                ->when($toDate,   fn ($q) => $q->whereRaw('DATE(r.`day`) <= ?', [$toDate]))
                ->whereNotNull('r.ad_id')
                ->where('r.ad_id', '<>', '')
                ->select([
                    'r.ad_id',
                    DB::raw('SUM(COALESCE(r.amount_spent_php, 0)) AS spend'),
                    DB::raw('SUM(COALESCE(r.messaging_conversations_started, 0)) AS msgs'),
                    DB::raw('SUM(COALESCE(r.link_clicks, 0)) AS clicks'),
                    DB::raw('MAX(r.page_name) AS page_name'),
                ])
                ->groupBy('r.ad_id')
                ->havingRaw('SUM(COALESCE(r.messaging_conversations_started, 0)) > 0')
                ->get();

            // Active-only fallback (preserve original behavior)
            if ($reports->isEmpty() && $activeOnly) {
                // Pass the ARRAY $pageNorms (not the joined display string) so the
                // recursive call re-filters by the same multi-page scope correctly.
                $fallback = $this->buildSuggestions($pageNorms, $itemNorm, false, $fromDate, $toDate, $topN);
                $fallback['fallback_used']   = true;
                $fallback['fallback_reason'] = 'No active ads found for this scope. Showing all (active+off) for the same date range.';
                return $fallback;
            }

            if ($reports->isEmpty()) {
                $scope = $this->scopeLabel($pageNorm, $itemNorm, $activeOnly);
                $dateLabel = $fromDate && $toDate ? " ({$fromDate} → {$toDate})" : '';
                return ['output' => "⚠️ No valid ad reports found{$scope}{$dateLabel}."];
            }

            // 2) Fetch creatives by ad_id
            $adIds = $reports->pluck('ad_id')->unique()->values()->all();
            $creatives = DB::table('ad_campaign_creatives as c')
                ->whereIn('c.ad_id', $adIds)
                ->select(['c.ad_id', 'c.headline', 'c.body_ad_settings', 'c.welcome_message',
                          'c.quick_reply_1', 'c.quick_reply_2', 'c.quick_reply_3'])
                ->get()->keyBy('ad_id');

            // 3) Merge + compute CPM, WMR per ad
            $rows = $reports->map(function ($r) use ($creatives, $applyPage, $pageNorm) {
                $c = $creatives->get($r->ad_id);
                if (!$c) return null;
                $headline = $c->headline ? trim($c->headline) : null;
                $body     = $c->body_ad_settings ? trim($c->body_ad_settings) : null;
                if ($headline === null || $body === null) return null;

                $msgs   = (float) $r->msgs;
                $spend  = (float) $r->spend;
                $clicks = (float) ($r->clicks ?? 0);
                $cpm    = $msgs   > 0 ? ($spend / $msgs)            : null;
                $wmr    = $clicks > 0 ? (($msgs / $clicks) * 100.0) : null;

                $pageOut = $this->normalizePage($r->page_name ?? ($applyPage ? $pageNorm : 'all'));

                return (object) [
                    'ad_id'            => $r->ad_id,
                    'headline'         => $headline,
                    'body_ad_settings' => $body,
                    'welcome_message'  => $c->welcome_message ? trim($c->welcome_message) : null,
                    'quick_reply_1'    => $c->quick_reply_1 ? trim($c->quick_reply_1) : null,
                    'quick_reply_2'    => $c->quick_reply_2 ? trim($c->quick_reply_2) : null,
                    'quick_reply_3'    => $c->quick_reply_3 ? trim($c->quick_reply_3) : null,
                    'cpm'              => $cpm,
                    'wmr'              => $wmr,
                    'spend'            => $spend,
                    'msgs'             => (int) $msgs,
                    'clicks'           => (int) $clicks,
                    'page_name'        => $pageOut,
                ];
            })->filter()->filter(fn ($row) => $row->cpm !== null)->values();

            if ($rows->isEmpty()) {
                $scope = $this->scopeLabel($pageNorm, $itemNorm, $activeOnly);
                return ['output' => "⚠️ No valid ad data found{$scope}."];
            }

            // 4) Build sections — $topN passed from caller, defaults to 10.
            //    Same value applied across all 4 sections (TOP/WORST CPM/WMR).
            $TOP_N = max(1, min(50, $topN));
            $byCpmAsc  = $rows->sortBy('cpm')->values();
            $byCpmDesc = $rows->sortByDesc('cpm')->values();

            // For WMR sections: only ads with link_clicks > 0 (WMR not null) AND
            // at least one of welcome_message / QR1 / QR2 / QR3 is non-blank.
            // Why: WMR sections are para sa pagturing kung anong welcome+QR
            // patterns ang effective (TOP) or to-be-avoided (WORST). Ads na
            // walang welcome at walang QRs hindi maituturing as either pattern
            // — wala namang content to evaluate.
            $hasWelcomeOrQr = function ($r) {
                $w  = trim((string) ($r->welcome_message ?? ''));
                $q1 = trim((string) ($r->quick_reply_1   ?? ''));
                $q2 = trim((string) ($r->quick_reply_2   ?? ''));
                $q3 = trim((string) ($r->quick_reply_3   ?? ''));
                return $w !== '' || $q1 !== '' || $q2 !== '' || $q3 !== '';
            };
            $rowsWithWmr = $rows
                ->filter(fn ($r) => $r->wmr !== null)
                ->filter($hasWelcomeOrQr)
                ->values();
            $byWmrDesc = $rowsWithWmr->sortByDesc('wmr')->values();
            $byWmrAsc  = $rowsWithWmr->sortBy('wmr')->values();

            // Stats header
            $cpmVals = $rows->pluck('cpm')->filter()->values();
            $wmrVals = $rowsWithWmr->pluck('wmr')->filter()->values();
            $stats = [
                'total_ads'       => $rows->count(),
                'cpm_min'         => $cpmVals->min(),
                'cpm_max'         => $cpmVals->max(),
                'cpm_mean'        => $cpmVals->avg(),
                'cpm_median'      => $this->median($cpmVals),
                'wmr_count'       => $wmrVals->count(),
                'wmr_min'         => $wmrVals->isNotEmpty() ? $wmrVals->min()  : null,
                'wmr_max'         => $wmrVals->isNotEmpty() ? $wmrVals->max()  : null,
                'wmr_mean'        => $wmrVals->isNotEmpty() ? $wmrVals->avg()  : null,
                'wmr_median'      => $this->median($wmrVals),
                'date_from'       => $fromDate,
                'date_to'         => $toDate,
            ];

            $sections = [
                '🔝 TOP CPM (lowest cost per messaging — copy patterns na nag-drives ng cheap engagement)'      => $byCpmAsc->take($TOP_N),
                '🔴 WORST CPM (highest cost per messaging — patterns to AVOID for headline+body)'              => $byCpmDesc->take($TOP_N),
                '🟢 TOP WMR (highest welcome-msg conversion — patterns na pinakamagaling makahold ng clickers)' => $byWmrDesc->take($TOP_N),
                '🟠 WORST WMR (lowest welcome-msg conversion — patterns to AVOID for welcome+QRs)'             => $byWmrAsc->take($TOP_N),
            ];

            // Build text output for ChatGPT
            $header = $this->formatStatsHeader($stats, $pageNorm, $itemNorm, $activeOnly);
            $body = collect($sections)->map(function ($group, $label) {
                if ($group->isEmpty()) return "{$label}\n  (no data)";
                $lines = [$label];
                foreach ($group as $i => $row) {
                    $lines[] = $this->formatSuggestionBlock($i + 1, $row);
                }
                return implode("\n", $lines);
            })->values()->implode("\n\n");

            $out = $header . "\n\n" . $body;

            // Structured sections for the Table view sa UI — same data as the
            // text output, just shaped para sa client-side render. Sections
            // are emitted as a numerically-indexed array to preserve order.
            $sectionsForUi = collect($sections)->map(function ($group, $label) {
                $rows = $group->map(function ($r) {
                    return [
                        'headline'        => $r->headline        ?? null,
                        'body'            => $r->body_ad_settings ?? null,
                        'welcome_message' => $r->welcome_message ?? null,
                        'quick_reply_1'   => $r->quick_reply_1   ?? null,
                        'quick_reply_2'   => $r->quick_reply_2   ?? null,
                        'quick_reply_3'   => $r->quick_reply_3   ?? null,
                        'cpm'             => isset($r->cpm) ? (float) $r->cpm : null,
                        'wmr'             => isset($r->wmr) && $r->wmr !== null ? (float) $r->wmr : null,
                        'spend'           => isset($r->spend)  ? (float) $r->spend  : 0,
                        'msgs'            => isset($r->msgs)   ? (int)   $r->msgs   : 0,
                        'clicks'          => isset($r->clicks) ? (int)   $r->clicks : 0,
                        'page_name'       => $r->page_name ?? null,
                    ];
                })->values()->all();
                return ['label' => $label, 'rows' => $rows];
            })->values()->all();

            return [
                'output'        => $out,
                'sections'      => $sectionsForUi,
                'fallback_used' => false,
                'stats'         => $stats,
                'low_data'      => $rows->count() < 5
                    ? "⚠️ Only {$rows->count()} ad(s) found in the selected date range. Consider extending para sa mas maraming variety."
                    : null,
            ];
        } catch (\Throwable $e) {
            Log::error('buildSuggestions error', ['msg' => $e->getMessage()]);
            return ['output' => '❌ Server error occurred.', 'error' => $e->getMessage()];
        }
    }

    /** Compute median sa sorted-or-unsorted Collection (numeric values). */
    private function median($vals)
    {
        $sorted = $vals->sort()->values();
        $n = $sorted->count();
        if ($n === 0) return null;
        if ($n % 2 === 0) return ($sorted[$n/2 - 1] + $sorted[$n/2]) / 2;
        return $sorted[(int) floor($n / 2)];
    }

    /** Stats context header for the prompt — gives ChatGPT range awareness. */
    private function formatStatsHeader(array $s, string $pageNorm, string $itemNorm, bool $activeOnly): string
    {
        $f  = fn ($v, $dec = 2) => $v === null ? '—' : ('₱' . number_format((float)$v, $dec));
        $p  = fn ($v) => $v === null ? '—' : (number_format((float)$v, 1) . '%');

        $page = ($pageNorm !== '' && mb_strtolower($pageNorm) !== 'all') ? $pageNorm : 'All pages';
        $item = ($itemNorm !== '' && mb_strtolower($itemNorm) !== 'all') ? $itemNorm : 'All items';
        $act  = $activeOnly ? 'Active only' : 'All (active + off)';

        return "=== GLOSSARY ===\n"
             . "CPM = cost per message = spend ÷ msgs  (lower = cheaper engagement; ang gusto natin LOW CPM)\n"
             . "WMR = welcome message rate = msgs ÷ clicks × 100%  (higher = more clickers turned into messengers — yung welcome message + quick replies effective)\n"
             . "Spend = total adspent for that ad within the date range\n"
             . "Msgs = total messaging conversations started by that ad\n"
             . "Clicks = link clicks on that ad (only ads with clicks > 0 have WMR)\n"
             . "\n"
             . "=== CONTEXT ===\n"
             . "Page: {$page}\n"
             . "Item: {$item}\n"
             . "Filter: {$act}\n"
             . "Date range: {$s['date_from']} → {$s['date_to']}\n"
             . "Total ads analyzed: {$s['total_ads']}\n"
             . "CPM range: {$f($s['cpm_min'])} – {$f($s['cpm_max'])} (mean {$f($s['cpm_mean'])}, median {$f($s['cpm_median'])})\n"
             . "WMR range: {$p($s['wmr_min'])} – {$p($s['wmr_max'])} (mean {$p($s['wmr_mean'])}, median {$p($s['wmr_median'])})  [{$s['wmr_count']} ads with click data]";
    }

    /**
     * Sanitize GPT-generated TSV variant — enforce the single-line + length
     * constraints on Item / Headline / QR1 / QR2 / QR3 that the prompt
     * already tells GPT to follow, pero hindi consistent yung compliance.
     *
     * What it does per field:
     *   [0] Item             → strip to first non-blank line, collapse whitespace
     *   [1] Primary Text     → leave as-is (multi-line allowed)
     *   [2] Headline         → strip to first non-blank line
     *   [3] Messaging Template → leave as-is (multi-line allowed)
     *   [4][5][6] QR1/QR2/QR3 → cleanQuickReply (trims label, takes first
     *                            question, hard caps at 80 chars)
     */
    private function sanitizeVariant(string $variant): string
    {
        $parts = explode("\t", $variant);
        // Pad/truncate to exactly 7 fields
        while (count($parts) < 7) $parts[] = '';
        $parts = array_slice($parts, 0, 7);

        $itemName = $this->stripToSingleLine($parts[0]);
        $parts[0] = $itemName;
        $parts[2] = $this->stripToSingleLine($parts[2]);

        // Quick replies (positions 4, 5, 6) — strict cleanup
        foreach ([4, 5, 6] as $i) {
            $parts[$i] = $this->cleanQuickReply($parts[$i], $itemName);
        }

        return implode("\t", $parts);
    }

    /** Take the first non-blank line, collapse internal whitespace. */
    private function stripToSingleLine(string $s): string
    {
        $s = trim($s);
        if ($s === '') return '';
        $lines = preg_split('/\r?\n/u', $s);
        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim !== '') return preg_replace('/\s+/u', ' ', $trim);
        }
        return '';
    }

    /**
     * Aggressive QR cleanup — guarantees a short, single-line, label-free reply.
     *   1. Take first non-blank line, collapse spaces.
     *   2. Strip trailing product-name label (e.g., "Q? Seat Cover" → "Q?").
     *   3. If multi-question/multi-sentence, take only up to first "?".
     *   4. Hard cap at 80 chars (with "…" if truncated).
     */
    private function cleanQuickReply(string $qr, string $itemName): string
    {
        $first = $this->stripToSingleLine($qr);
        if ($first === '') return '';

        // Drop trailing product-name label if present.
        // Matches patterns like:
        //   "Magkano? Seat Cover" or "Magkano? - Seat Cover" or
        //   "Magkano - Seat Cover" — case-insensitive.
        $itemName = trim($itemName);
        if ($itemName !== '') {
            $escaped = preg_quote($itemName, '/');
            $first = preg_replace('/[\s\.\?\!\,\-–—:]*\b' . $escaped . '\b\s*$/iu', '', $first);
            $first = trim($first);
        }

        // If multiple questions/sentences, take only up to the first "?".
        $qPos = mb_strpos($first, '?');
        if ($qPos !== false) {
            $first = mb_substr($first, 0, $qPos + 1);
        }

        // Hard cap (defense against marketing-copy bleed-throughs).
        if (mb_strlen($first) > 80) {
            $first = rtrim(mb_substr($first, 0, 79)) . '…';
        }

        return $first;
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
        $v   = fn ($x) => isset($x) && $x !== '' ? $x : '—';
        $cpm = isset($row->cpm) ? '₱' . number_format((float) $row->cpm, 2) : '—';
        $wmr = isset($row->wmr) && $row->wmr !== null
                  ? number_format((float) $row->wmr, 1) . '%'
                  : '—';

        $line  = $index . '. Headline: "' . $v($row->headline) . '"';
        $line .= "\n   Body: \"" . $v($row->body_ad_settings) . '"';
        $line .= "\n   Welcome Message: \"" . $v($row->welcome_message) . '"';
        $line .= "\n   QR1: " . $v($row->quick_reply_1);
        $line .= "\n   QR2: " . $v($row->quick_reply_2);
        $line .= "\n   QR3: " . $v($row->quick_reply_3);
        $line .= "\n   CPM: {$cpm} | WMR: {$wmr} | spend: ₱"
                . number_format((float)($row->spend ?? 0), 0)
                . " | msgs: " . (int)($row->msgs ?? 0)
                . " | clicks: " . (int)($row->clicks ?? 0);
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

    // ─────────────────────────────────────────────────────────────────────
    //  VIDEO ANALYSIS FEATURE
    //
    //  Flow:
    //    1. Client computes file SHA-256 → POST /gpt-ad-generator/check-video-hash
    //       returns the existing analysis row kung may match (avoids upload).
    //    2. If new file → POST /gpt-ad-generator/analyze-video with the file.
    //       Backend extracts frames via ffmpeg, audio via ffmpeg, transcribes
    //       via OpenAI Whisper, then asks GPT-4o Vision to produce structured
    //       item_name / description / summary. Result is saved sa
    //       gpt_video_analyses keyed by sha256 + the temp file is deleted
    //       immediately after analysis.
    //    3. GET /gpt-ad-generator/video-analysis/{id} → retrieve full record.
    //    4. GET /gpt-ad-generator/video-history → list recent analyses.
    //
    //  Frame count is adaptive by default (5–20 based on duration) but the
    //  client can override per upload.
    // ─────────────────────────────────────────────────────────────────────

    /** Vision-capable OpenAI models the user may pick for video analysis. */
    public const ALLOWED_VIDEO_MODELS = [
        'gpt-4o'         => 'GPT-4o (premium) — best vision quality',
        'gpt-4o-mini'    => 'GPT-4o mini — cheaper, lower vision quality',
        'gpt-4-turbo'    => 'GPT-4 Turbo — older premium',
    ];

    /**
     * POST /gpt-ad-generator/check-video-hash
     *
     * Pre-upload dedup probe — returns existing analysis kung may same SHA-256.
     * Client computes hash sa browser using SubtleCrypto + sends here.
     */
    public function checkVideoHash(Request $request)
    {
        $hash = (string) $request->input('hash', '');
        if (!preg_match('/^[a-f0-9]{64}$/i', $hash)) {
            return response()->json(['ok' => false, 'error' => 'Invalid SHA-256 hash'], 422);
        }
        $row = GptVideoAnalysis::where('file_sha256', strtolower($hash))->first();
        return response()->json([
            'ok'        => true,
            'cached'    => $row !== null,
            'analysis'  => $row,
        ]);
    }

    /**
     * GET /gpt-ad-generator/video-analysis/{id}
     */
    public function getVideoAnalysis($id)
    {
        $row = GptVideoAnalysis::find((int) $id);
        if (!$row) return response()->json(['ok' => false, 'error' => 'Not found'], 404);
        return response()->json(['ok' => true, 'analysis' => $row]);
    }

    /**
     * GET /gpt-ad-generator/video-history
     *
     * Lists the latest 50 video analyses, scoped sa current user kung non-CEO.
     * CEO sees everything across users. Used by the History panel sa UI.
     */
    public function videoHistory(Request $request)
    {
        $user = Auth::user();
        $role = strtoupper(trim((string)($user?->employeeProfile?->role ?? '')));
        $isCEO = $role === 'CEO';

        $q = GptVideoAnalysis::query()->orderByDesc('analyzed_at')->limit(50);
        if (!$isCEO && $user) {
            $q->where('uploaded_by_user_id', $user->id);
        }
        return response()->json([
            'ok'   => true,
            'rows' => $q->get([
                'id', 'file_name', 'file_size_bytes', 'duration_seconds',
                'item_name', 'description', 'uploaded_by_email',
                'model_used', 'cost_estimate_php', 'analyzed_at', 'created_at',
            ]),
        ]);
    }

    /**
     * POST /gpt-ad-generator/analyze-video
     *
     * Heavy endpoint: receives a video file, runs ffmpeg + Whisper + Vision,
     * persists to gpt_video_analyses, returns the analysis. The original file
     * is deleted right after analysis — only DB metadata + AI outputs survive.
     */
    public function analyzeVideo(Request $request)
    {
        // ── Validate request ─────────────────────────────────────────────
        $request->validate([
            'video'       => 'required|file',
            'model'       => 'nullable|string',
            'frame_count' => 'nullable|integer|min:3|max:50',
        ]);

        $model = (string) $request->input('model', 'gpt-4o');
        if (!array_key_exists($model, self::ALLOWED_VIDEO_MODELS)) {
            $model = 'gpt-4o';
        }

        $file = $request->file('video');
        if (!$file) {
            return response()->json(['ok' => false, 'error' => 'No file uploaded'], 422);
        }

        // ── Verify ffmpeg available ─────────────────────────────────────
        $ffmpegBin  = trim((string) shell_exec('which ffmpeg 2>/dev/null'));
        $ffprobeBin = trim((string) shell_exec('which ffprobe 2>/dev/null'));
        if ($ffmpegBin === '' || $ffprobeBin === '') {
            return response()->json([
                'ok' => false,
                'error' => 'ffmpeg/ffprobe not found on server. Install via: apt install ffmpeg',
            ], 500);
        }

        // ── Stash original file + hash ──────────────────────────────────
        $origName = $file->getClientOriginalName();
        $hash     = hash_file('sha256', $file->getRealPath());

        // Dedup short-circuit — exact same file already analyzed before.
        $cached = GptVideoAnalysis::where('file_sha256', $hash)->first();
        if ($cached) {
            return response()->json([
                'ok'        => true,
                'cached'    => true,
                'analysis'  => $cached,
            ]);
        }

        // Persist temp file under storage/app/temp/videos/{hash}.{ext}
        $ext      = strtolower($file->getClientOriginalExtension() ?: 'mp4');
        $tempDir  = storage_path('app/temp/videos');
        if (!is_dir($tempDir)) @mkdir($tempDir, 0775, true);
        $videoPath = $tempDir . '/' . $hash . '.' . $ext;
        $file->move($tempDir, $hash . '.' . $ext);

        // Track files to clean up sa finally block.
        $tempFiles = [$videoPath];
        $framePaths = [];
        $audioPath = null;

        try {
            // ── Probe duration ────────────────────────────────────────
            $durationSec = $this->ffprobeDuration($videoPath);

            // ── Adaptive frame count (user override wins) ─────────────
            $requestedFrames = (int) $request->input('frame_count', 0);
            $frameCount = $requestedFrames > 0
                ? $requestedFrames
                : $this->adaptiveFrameCount($durationSec ?? 30.0);
            $frameCount = max(3, min(50, $frameCount));

            // ── Extract frames evenly ─────────────────────────────────
            $framePaths = $this->extractFrames($videoPath, $tempDir, $hash, $frameCount, $durationSec ?? 30.0);
            $tempFiles  = array_merge($tempFiles, $framePaths);

            // ── Extract audio (if any) ────────────────────────────────
            $audioPath = $tempDir . '/' . $hash . '.mp3';
            $this->extractAudio($videoPath, $audioPath);
            if (is_file($audioPath)) $tempFiles[] = $audioPath;

            // ── Whisper transcription ─────────────────────────────────
            $transcript = '';
            if (is_file($audioPath) && filesize($audioPath) > 1024) {
                try {
                    $transcript = $this->whisperTranscribe($audioPath);
                } catch (\Throwable $e) {
                    Log::warning('Whisper transcription failed', ['err' => $e->getMessage()]);
                    $transcript = '';
                }
            }

            // ── GPT-4o Vision analysis ────────────────────────────────
            $vision = $this->visionAnalyzeFrames($framePaths, $transcript, $model, $origName);

            // ── Cost estimate (rough) ─────────────────────────────────
            $costPhp = $this->estimateAnalysisCostPhp($model, count($framePaths), $durationSec ?? 30.0);

            // ── Save to DB ─────────────────────────────────────────────
            $user = Auth::user();
            $row = GptVideoAnalysis::create([
                'file_name'          => $origName,
                'file_sha256'        => $hash,
                'file_size_bytes'    => filesize($videoPath) ?: 0,
                'duration_seconds'   => $durationSec,
                'frame_count'        => count($framePaths),
                'uploaded_by_user_id'=> $user?->id,
                'uploaded_by_email'  => $user?->email,
                'transcript'         => $transcript ?: null,
                'summary'            => $vision['summary']     ?? null,
                'item_name'          => $vision['item_name']   ?? null,
                'description'        => $vision['description'] ?? null,
                'model_used'         => $model,
                'cost_estimate_php'  => $costPhp,
                'analyzed_at'        => now(),
            ]);

            return response()->json([
                'ok'       => true,
                'cached'   => false,
                'analysis' => $row,
            ]);
        } catch (\Throwable $e) {
            Log::error('analyzeVideo failed', ['msg' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json([
                'ok'    => false,
                'error' => 'Analysis failed: ' . $e->getMessage(),
            ], 500);
        } finally {
            // Auto-delete temp files (success or fail). DB row already has
            // everything we need; original file no longer required.
            foreach ($tempFiles as $tf) {
                if (is_file($tf)) @unlink($tf);
            }
        }
    }

    // ── ffmpeg / ffprobe helpers ────────────────────────────────────────

    private function ffprobeDuration(string $videoPath): ?float
    {
        $cmd = sprintf(
            'ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s 2>/dev/null',
            escapeshellarg($videoPath)
        );
        $out = trim((string) shell_exec($cmd));
        if ($out === '' || !is_numeric($out)) return null;
        return (float) $out;
    }

    /**
     * Adaptive frame count by duration (seconds):
     *   < 15s   → 5 frames
     *   15-30s  → 8 frames
     *   30-60s  → 10 frames
     *   60-120s → 15 frames
     *   > 120s  → 20 frames
     */
    private function adaptiveFrameCount(float $durationSec): int
    {
        if ($durationSec < 15)   return 5;
        if ($durationSec < 30)   return 8;
        if ($durationSec < 60)   return 10;
        if ($durationSec < 120)  return 15;
        return 20;
    }

    /**
     * Evenly-spaced frame extraction → returns array of JPEG paths.
     * Uses ffmpeg's "fps" + select expression based on duration.
     */
    private function extractFrames(string $videoPath, string $tempDir, string $hash, int $frameCount, float $durationSec): array
    {
        $paths = [];

        // Sample N evenly-spaced timestamps and extract one JPEG each.
        for ($i = 0; $i < $frameCount; $i++) {
            // Pick midpoint of each segment, not the edges (avoids black frames).
            $t = ($durationSec * ($i + 0.5)) / $frameCount;
            $out = sprintf('%s/%s_frame_%02d.jpg', $tempDir, $hash, $i);
            $cmd = sprintf(
                'ffmpeg -y -ss %s -i %s -frames:v 1 -q:v 4 -vf "scale=512:-2" %s 2>/dev/null',
                escapeshellarg(number_format($t, 3, '.', '')),
                escapeshellarg($videoPath),
                escapeshellarg($out)
            );
            shell_exec($cmd);
            if (is_file($out) && filesize($out) > 0) $paths[] = $out;
        }

        return $paths;
    }

    /** Extract audio track to mp3 mono 16kHz — Whisper-friendly. */
    private function extractAudio(string $videoPath, string $audioOut): void
    {
        $cmd = sprintf(
            'ffmpeg -y -i %s -vn -ac 1 -ar 16000 -b:a 64k %s 2>/dev/null',
            escapeshellarg($videoPath),
            escapeshellarg($audioOut)
        );
        shell_exec($cmd);
    }

    /**
     * Send audio file to OpenAI Whisper API → returns transcript string.
     * Whisper auto-detects language; ad audio is often Tagalog/Taglish.
     */
    private function whisperTranscribe(string $audioPath): string
    {
        $apiKey = env('OPENAI_API_KEY');
        if (!$apiKey) throw new \RuntimeException('OPENAI_API_KEY not set');

        $res = Http::withToken($apiKey)
            ->timeout(120)
            ->attach('file', file_get_contents($audioPath), basename($audioPath))
            ->asMultipart()
            ->post('https://api.openai.com/v1/audio/transcriptions', [
                ['name' => 'model', 'contents' => 'whisper-1'],
                ['name' => 'response_format', 'contents' => 'text'],
            ]);

        if (!$res->successful()) {
            throw new \RuntimeException('Whisper API: ' . substr($res->body(), 0, 500));
        }
        return trim((string) $res->body());
    }

    /**
     * Send frames (as base64 image_url) + transcript to GPT Vision; ask it to
     * return STRICT JSON with item_name, description, summary. We parse the
     * JSON and return the structured array.
     */
    private function visionAnalyzeFrames(array $framePaths, string $transcript, string $model, string $fileName): array
    {
        $apiKey = env('OPENAI_API_KEY');
        if (!$apiKey) throw new \RuntimeException('OPENAI_API_KEY not set');

        // Build vision input — sequence of image_url parts.
        $userParts = [];
        $userParts[] = [
            'type' => 'text',
            'text' => "Suriin ang mga frame na ito ng isang Facebook ads video "
                . "(file: {$fileName}). " . ($transcript !== ''
                    ? "May voice-over/audio transcript din:\n\n" . mb_substr($transcript, 0, 3000)
                    : '(Walang audio transcript available.)')
                . "\n\nReturn STRICT JSON na may exactly itong keys:"
                . "\n  - item_name: string (e.g., 'Tactical Flashlight')"
                . "\n  - description: string (3-6 short benefit phrases, Taglish OK)"
                . "\n  - summary: string (1-3 paragraphs describing what's happening sa video — visuals + audio cues)"
                . "\nNo markdown, no extra commentary. JSON only.",
        ];
        foreach ($framePaths as $path) {
            $b64 = base64_encode(file_get_contents($path));
            $userParts[] = [
                'type'      => 'image_url',
                'image_url' => ['url' => 'data:image/jpeg;base64,' . $b64, 'detail' => 'low'],
            ];
        }

        $payload = [
            'model'    => $model,
            'messages' => [
                [
                    'role'    => 'system',
                    'content' => "You are a product analyst for a Filipino e-commerce store. "
                        . "Analyze ad videos and extract: product name, key features/benefits, and a narrative summary. "
                        . "Always return strict JSON only.",
                ],
                ['role' => 'user', 'content' => $userParts],
            ],
            'temperature'     => 0.3,
            'response_format' => ['type' => 'json_object'],
            'max_tokens'      => 1500,
        ];

        $res = Http::withToken($apiKey)
            ->timeout(180)
            ->post('https://api.openai.com/v1/chat/completions', $payload);

        if (!$res->successful()) {
            throw new \RuntimeException('Vision API: ' . substr($res->body(), 0, 500));
        }
        $content = $res['choices'][0]['message']['content'] ?? '{}';
        $data    = json_decode($content, true) ?: [];

        // Coerce any field to a string — GPT sometimes returns arrays for
        // fields it thinks should be lists (e.g., description as array of
        // benefits). DB columns are text/string, so we flatten lists with
        // bullet markers para human-readable + DB-friendly.
        $toString = function ($v): ?string {
            if ($v === null) return null;
            if (is_string($v)) return $v;
            if (is_numeric($v) || is_bool($v)) return (string) $v;
            if (is_array($v)) {
                // List of scalars → "✅ a\n✅ b\n✅ c" format
                $isList = array_keys($v) === range(0, count($v) - 1);
                if ($isList) {
                    return implode("\n", array_map(function ($x) {
                        if (is_array($x)) return '✅ ' . json_encode($x, JSON_UNESCAPED_UNICODE);
                        return '✅ ' . (string) $x;
                    }, $v));
                }
                // Associative array → JSON
                return json_encode($v, JSON_UNESCAPED_UNICODE);
            }
            return (string) $v;
        };

        return [
            'item_name'   => $toString($data['item_name']   ?? null),
            'description' => $toString($data['description'] ?? null),
            'summary'     => $toString($data['summary']     ?? null),
        ];
    }

    /**
     * Rough cost estimate sa PHP — used for tracking/budget alerts.
     * Numbers based sa OpenAI pricing pages (USD * ~58 conversion).
     */
    private function estimateAnalysisCostPhp(string $model, int $frameCount, float $durationSec): float
    {
        // Whisper: ~$0.006/min
        $whisperUsd = ($durationSec / 60) * 0.006;

        // Vision: per-image low-detail ~85 tokens + per-frame analysis tokens.
        // Estimated 1500 input + 800 output tokens. Pricing per 1M tokens:
        //   gpt-4o:      $2.50 input / $10.00 output
        //   gpt-4o-mini: $0.15 input / $0.60 output
        //   gpt-4-turbo: $10.00 input / $30.00 output
        $rates = [
            'gpt-4o'      => ['in' => 2.50,  'out' => 10.00],
            'gpt-4o-mini' => ['in' => 0.15,  'out' => 0.60],
            'gpt-4-turbo' => ['in' => 10.00, 'out' => 30.00],
        ];
        $r = $rates[$model] ?? $rates['gpt-4o'];
        $inputTok  = 600 + ($frameCount * 200); // base prompt + per-image overhead
        $outputTok = 800;
        $visionUsd = ($inputTok / 1_000_000) * $r['in'] + ($outputTok / 1_000_000) * $r['out'];

        return round(($whisperUsd + $visionUsd) * 58, 4);
    }
}

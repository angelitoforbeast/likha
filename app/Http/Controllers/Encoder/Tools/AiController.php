<?php

namespace App\Http\Controllers\Encoder\Tools;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AiController extends Controller
{
    private function configDir(): string
    {
        return resource_path('views/encoder/tools');
    }

    private function readFileNonEmpty(string $filename): string
    {
        $path = $this->configDir() . DIRECTORY_SEPARATOR . $filename;

        if (!is_file($path)) {
            abort(500, "Missing file: {$path}");
        }

        $val = trim((string) file_get_contents($path));
        if ($val === '') {
            abort(500, "Empty file: {$path}");
        }

        return $val;
    }

    private function readApiKey(): string
    {
        return $this->readFileNonEmpty('apikey.txt');
    }

    private function readModelPayload(): array
    {
        $raw = $this->readFileNonEmpty('apimodel.txt');

        $json = $raw;
        if (!Str::startsWith(ltrim($raw), '{')) {
            $first = strpos($raw, '{');
            $last  = strrpos($raw, '}');
            if ($first === false || $last === false || $last <= $first) {
                abort(500, "apimodel.txt must contain a JSON object.");
            }
            $json = substr($raw, $first, $last - $first + 1);
        }

        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            abort(500, "Invalid JSON in apimodel.txt");
        }

        $payload['model'] ??= 'gpt-5.2';
        $payload['reasoning'] ??= ['effort' => 'high'];

        $payload['tools'] ??= [[
            'type' => 'web_search',
            'search_context_size' => 'medium',
        ]];
        $payload['tool_choice'] ??= 'auto';
        $payload['truncation'] ??= 'auto';

       
        $payload['max_output_tokens'] ??= 350;
        $payload['store'] ??= false;

        return $payload;
    }

    public function index()
    {
        $payload = $this->readModelPayload();

        return view('encoder.tools.ai', [
            'defaultModel'  => $payload['model'] ?? 'gpt-5.2',
            'defaultEffort' => data_get($payload, 'reasoning.effort', 'high'),
        ]);
    }

    // =========================
    // DB "LEARNING" (RAG-lite)
    // =========================

    private function sanitizeForPrompt(string $text): string
    {
        $t = $text;

        // mask emails
        $t = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[EMAIL]', $t);

        // mask phone-like numbers (10-13 digits; allow separators)
        $t = preg_replace('/(?<!\d)(?:\+?63|0)?[\s\-()]*\d[\d\s\-()]{8,14}\d(?!\d)/', '[PHONE]', $t);

        // collapse whitespace
        $t = preg_replace('/\s+/', ' ', $t);

        return trim($t);
    }

    private function normalizeText(string $text): string
    {
        $t = mb_strtolower($text, 'UTF-8');
        $t = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $t);
        $t = preg_replace('/\s+/', ' ', $t);
        return trim($t);
    }

    private function tokens(string $text): array
    {
        $t = $this->normalizeText($text);
        if ($t === '') return [];

        $parts = explode(' ', $t);

        // very small stopword list (Tagalog + English) to improve matching
        $stop = array_flip([
            'po','opo','pls','please','thanks','thank','you','sir','maam','mam',
            'ang','ng','sa','si','ni','na','naman','lang','din','rin','ito','yan','yung','nung',
            'and','or','the','a','an','to','for','of','in','on','at','from','with',
            'brgy','barangay','city','province','street','st','sitio','purok','baryo',
            'address','addr','loc','location',
        ]);

        $out = [];
        foreach ($parts as $w) {
            if ($w === '' || isset($stop[$w])) continue;
            if (mb_strlen($w, 'UTF-8') <= 1) continue;
            $out[$w] = true;
        }
        return array_keys($out);
    }

    private function jaccardScore(string $a, string $b): float
    {
        $ta = $this->tokens($a);
        $tb = $this->tokens($b);
        if (!$ta || !$tb) return 0.0;

        $setA = array_flip($ta);
        $setB = array_flip($tb);

        $inter = 0;
        foreach ($setA as $k => $_) {
            if (isset($setB[$k])) $inter++;
        }
        $union = count($setA) + count($setB) - $inter;
        if ($union <= 0) return 0.0;

        return $inter / $union;
    }

    private function buildSearchQuery(string $input): string
    {
        // keep it simple: take top tokens and join
        $toks = $this->tokens($input);
        if (!$toks) return '';

        // limit to 10 tokens to avoid heavy queries
        return implode(' ', array_slice($toks, 0, 10));
    }

    /**
     * Fetch similar PROCEED records from macro_output (works for pgsql + mysql).
     * Returns up to $k examples ranked by Jaccard score.
     */
    private function fetchSimilarExamples(string $input, int $k = 6): array
    {
        $driver = DB::getDriverName(); // mysql|pgsql|sqlite...
        $q = $this->buildSearchQuery($input);

        // If empty, don't query DB (avoid scanning)
        if ($q === '') return [];

        // Base select
        $base = DB::table('macro_output')
            ->select(['all_user_input', 'PROVINCE', 'CITY', 'BARANGAY'])
            ->where('STATUS', 'PROCEED')
            ->whereNotNull('all_user_input')
            ->whereRaw('LENGTH(all_user_input) >= 10');

        $rows = collect();

        // Try full-text per driver (fast if indexed). If it fails, fallback LIKE.
        try {
            if ($driver === 'pgsql') {
                // uses simple config; index recommended (see notes below)
                $rows = $base
                    ->whereRaw("to_tsvector('simple', all_user_input) @@ plainto_tsquery('simple', ?)", [$q])
                    ->limit(30)
                    ->get();
            } elseif ($driver === 'mysql') {
                // requires FULLTEXT index on all_user_input for best performance
                $rows = $base
                    ->whereRaw("MATCH(all_user_input) AGAINST (? IN NATURAL LANGUAGE MODE)", [$q])
                    ->limit(30)
                    ->get();
            } else {
                // unknown driver: fallback immediately
                throw new \RuntimeException('Unsupported driver for full-text');
            }
        } catch (\Throwable $e) {
            // LIKE fallback (works everywhere, but slower)
            $like = mb_substr($q, 0, 40, 'UTF-8'); // keep short
            $rows = $base
                ->where('all_user_input', 'like', '%' . $like . '%')
                ->limit(30)
                ->get();
        }

        if ($rows->isEmpty()) return [];

        // Rank by similarity score in PHP (works cross-DB)
        $scored = [];
        foreach ($rows as $r) {
            $src = (string) ($r->all_user_input ?? '');
            $score = $this->jaccardScore($input, $src);

            // keep only decent matches
            if ($score <= 0.05) continue;

            $scored[] = [
                'score' => round($score, 4),
                'conversation' => mb_substr($this->sanitizeForPrompt($src), 0, 380, 'UTF-8'),
                'PROVINCE' => (string) ($r->PROVINCE ?? ''),
                'CITY'     => (string) ($r->CITY ?? ''),
                'BARANGAY' => (string) ($r->BARANGAY ?? ''),
            ];
        }

        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, $k);
    }

    private function appendExamplesToInstructions(string $instructions, array $examples): string
    {
        if (empty($examples)) return $instructions;

        $lines = [];
        $lines[] = "";
        $lines[] = "### Similar labeled examples from your database (macro_output, STATUS=PROCEED)";
        $lines[] = "Use these ONLY as guidance. If the new input strongly matches one of these, prefer the same Province/City/Barangay.";
        $lines[] = "If the input is different/unclear/ambiguous, output UNKNOWN for the asked field(s).";
        $lines[] = "";

        $i = 1;
        foreach ($examples as $ex) {
            $lines[] = "Example {$i} (similarity={$ex['score']}):";
            $lines[] = "Conversation: {$ex['conversation']}";
            $lines[] = "Correct: Province={$ex['PROVINCE']} | City={$ex['CITY']} | Barangay={$ex['BARANGAY']}";
            $lines[] = "";
            $i++;
        }

        return rtrim($instructions) . "\n" . implode("\n", $lines);
    }

    public function run(Request $request)
    {
        @set_time_limit(180);
        @ini_set('max_execution_time', '180');
        @ignore_user_abort(true);

        $request->validate([
            'input'  => ['required', 'string', 'max:20000'],
            'effort' => ['nullable', 'in:low,medium,high'],
            'use_db' => ['nullable', 'boolean'],
        ]);

        $apiKey  = $this->readApiKey();
        $payload = $this->readModelPayload();

        $input = (string) $request->input('input');

        // DB learning matches (optional)
        $useDb = $request->boolean('use_db', true);
        $matches = [];
        if ($useDb) {
            $matches = $this->fetchSimilarExamples($input, 6);
            if (!empty($matches) && isset($payload['instructions']) && is_string($payload['instructions'])) {
                $payload['instructions'] = $this->appendExamplesToInstructions($payload['instructions'], $matches);
            }
        }

        // Override input from UI
        $payload['input'] = $input;

        // Optional override effort
        if ($request->filled('effort')) {
            $payload['reasoning'] = $payload['reasoning'] ?? [];
            $payload['reasoning']['effort'] = $request->input('effort');
        }

        $resp = Http::withToken($apiKey)
            ->acceptJson()
            ->contentType('application/json')
            ->connectTimeout(20)
            ->timeout(170)
            ->retry(1, 800)
            ->post('https://api.openai.com/v1/responses', $payload);

        $data = $resp->json();

        if (!$resp->successful()) {
            return response()->json([
                'ok'      => false,
                'status'  => $resp->status(),
                'error'   => $data ?: $resp->body(),
                'matches' => $matches,
            ], 200);
        }

        // Extract output_text safely
        $text = $data['output_text'] ?? '';
        if (!is_string($text) || $text === '') {
            $text = '';
            foreach (($data['output'] ?? []) as $o) {
                foreach (($o['content'] ?? []) as $c) {
                    if (($c['type'] ?? '') === 'output_text' && isset($c['text'])) {
                        $text .= (string) $c['text'];
                    }
                }
            }
            $text = trim($text);
        }

        return response()->json([
            'ok'      => true,
            'text'    => $text,
            'matches' => $matches,   // ✅ show in Blade
            'raw'     => $data,
        ]);
    }
}

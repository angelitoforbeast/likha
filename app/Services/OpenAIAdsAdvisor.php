<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAIAdsAdvisor
{
    /**
     * Analyze KPI rows and return structured recommendations.
     *
     * @param  array $kpiRows
     * @param  array $targets  e.g. ['CPM_max'=>10,'CPP_max'=>70,'CPP_breakeven'=>60|null]
     * @param  array $constraints
     * @return array
     */
    public function analyze(array $kpiRows, array $targets, array $constraints): array
    {
        // ----- System instructions (with strict output rules) -----
        $instructions = <<<SYS
You are a senior PH e-commerce performance marketer.

Definitions:
- CPM = cost per message (spend / messages)
- CPI = cost per impression (spend / impressions)
- CPP = cost per purchase (spend / purchases)
- Frequency = impressions / reach
- Messages per 1k Impressions = (messages / impressions) * 1000

Business rules:
- Avoid putting price in copy (often raises CPI/CPM).
- Respect ~3-day learning unless metrics are extremely poor.
- If purchases == 0, reason about CPP carefully (no divide by zero).
- If a breakeven CPP is provided, treat it as the economic threshold:
  • CPP well below breakeven → good unit economics → bias toward scale (if CPM also healthy).
  • CPP above breakeven → poor unit economics → bias toward fix/pause unless strategic reason.
- Be concise, concrete, and propose testable next steps.

CRITICAL FORMAT RULES:
- Return ONLY a single valid JSON object.
- Do NOT include any Markdown fences or backticks.
- Do NOT include commentary or extra text.

Return strictly in JSON with the following keys:
{
  "summary": "string",
  "global_actions": [
    {"priority":"high|medium|low","action":"string","reason":"string"}
  ],
  "by_campaign": [
    {"page":"string","campaign":"string","decision":"scale|hold|fix|pause","why":"string","next_tests":["string"]}
  ]
}
SYS;

        // Exact KPIs to analyze (from the UI preview step)
        $inputData = [
            'context' => [
                'targets'     => $targets,      // includes CPM_max, CPP_max (target), CPP_breakeven (optional)
                'constraints' => $constraints,
            ],
            'kpis' => array_values($kpiRows),
        ];

        try {
            $response = Http::withToken(config('services.openai.key', env('OPENAI_API_KEY')))
                ->acceptJson()
                ->timeout(45)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => config('services.openai.model', 'gpt-4o-mini'),
                    'messages' => [
                        ['role' => 'system', 'content' => $instructions],
                        [
                            'role' => 'user',
                            'content' => "Analyze the following JSON and return STRICTLY the required JSON shape:\n\n"
                                . json_encode($inputData, JSON_UNESCAPED_UNICODE)
                        ],
                    ],
                    'temperature' => 0.2,
                    'max_tokens'  => 1500,
                ]);

            Log::info('OPENAI_INSIGHTS_META', [
                'status' => $response->status(),
                'x-request-id' => $response->header('x-request-id'),
            ]);

            if ($response->failed()) {
                Log::error('OPENAI_INSIGHTS_HTTP_ERROR', [
                    'status' => $response->status(),
                    'body'   => substr($response->body(), 0, 800),
                ]);
                return [
                    'summary' => null,
                    'global_actions' => [],
                    'by_campaign' => [],
                    'error' => 'AI request failed: HTTP '.$response->status(),
                ];
            }

            // ----- Parse + log usage -----
            $json = $response->json();
            $usage = data_get($json, 'usage', []);
            if (!empty($usage)) {
                Log::info('OPENAI_INSIGHTS_USAGE', $usage);
            }

            $content = data_get($json, 'choices.0.message.content');

            if (!$content) {
                Log::warning('OPENAI_INSIGHTS_EMPTY_OUTPUT', [
                    'body' => substr($response->body(), 0, 500),
                ]);
                return [
                    'summary' => null,
                    'global_actions' => [],
                    'by_campaign' => [],
                    'error' => 'Empty GPT output',
                ];
            }

            // ----- JSON Sanitizer: strip ```json fences; fallback to first {...} block -----
            $content = trim($content);

            // Remove leading fences ```json or ```
            $content = preg_replace('/^```(?:json)?\s*/i', '', $content ?? '');
            // Remove trailing ```
            $content = preg_replace('/\s*```$/', '', $content ?? '');

            // First attempt
            $parsed = json_decode($content, true);

            // Fallback: extract first {...} block if still invalid
            if (!is_array($parsed)) {
                $start = strpos($content, '{');
                $end   = strrpos($content, '}');
                if ($start !== false && $end !== false && $end > $start) {
                    $candidate = substr($content, $start, $end - $start + 1);
                    $try = json_decode($candidate, true);
                    if (is_array($try)) {
                        $parsed = $try;
                        $content = $candidate; // for logging visibility if needed
                    }
                }
            }

            if (!is_array($parsed)) {
                Log::warning('OPENAI_INSIGHTS_BAD_JSON', ['raw' => substr($content, 0, 300)]);
                return [
                    'summary' => null,
                    'global_actions' => [],
                    'by_campaign' => [],
                    'error' => 'Malformed JSON from GPT',
                ];
            }

            // Optional: ensure keys exist
            $parsed += [
                'summary' => null,
                'global_actions' => [],
                'by_campaign' => [],
            ];

            return $parsed;

        } catch (\Throwable $e) {
            Log::error('OPENAI_INSIGHTS_EXCEPTION', [
                'msg'   => $e->getMessage(),
                'class' => get_class($e),
            ]);
            return [
                'summary' => null,
                'global_actions' => [],
                'by_campaign' => [],
                'error' => 'Exception: '.$e->getMessage(),
            ];
        }
    }
}

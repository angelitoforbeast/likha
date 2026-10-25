<?php

namespace App\Services\Chatbot;

use App\Services\OrderEncode\FlowEncode;
use App\Services\PageSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Ang paghahambing ng mga AI ng isang page sa isang hanay ng araw (Manila), mula sa record ng chatbot_ai_requests: bawat
 * provider ay ilang subok, sagot, palya at lipat sa susunod, average at p95 na ms, at tokens ng mga sagot nito. Mga numero
 * lang ang ibinabalik: walang text ng customer o ng sagot. Ang token na wala (walang usage) ay null, hindi 0.
 */
class ChatbotAiCompare
{
    public const MAX_DAYS = 90; // gaya ng tagal ng record

    public function forPage(?string $page, string $from, string $to): array
    {
        if ($page === null) abort(response()->json(['error' => 'This page is not on the chatbot list.'], 422));
        $today = now('Asia/Manila')->format('Y-m-d');
        // Walang petsa = ang huling 7 araw; ang maling petsa (hal. 2026-02-31) ay tinatanggihan, hindi inililipat nang tahimik.
        $day = function (string $d, string $fallback) {
            $d = $d === '' ? $fallback : $d;
            try {
                $c = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? Carbon::createFromFormat('!Y-m-d', $d, 'Asia/Manila') : null;
            } catch (Throwable $e) {
                $c = null;
            }
            if (!$c || $c->format('Y-m-d') !== $d) abort(response()->json(['error' => 'Pick dates as YYYY-MM-DD.'], 422));

            return $c;
        };
        $start = $day($from, now('Asia/Manila')->subDays(6)->format('Y-m-d'))->startOfDay();
        $end = $day($to, $today)->endOfDay();
        if ($end->lt($start) || $start->diffInDays($end) > self::MAX_DAYS) abort(response()->json(['error' => 'Pick a range of up to ' . self::MAX_DAYS . ' days.'], 422));

        $out = ['page' => $page, 'from' => $start->format('Y-m-d'), 'to' => $end->format('Y-m-d'), 'requests' => 0, 'providers' => [], 'message' => 'No requests'];
        if (!Schema::hasTable(ChatbotAiQueue::TABLE) || !ChatbotAiQueue::recorded()) return $out;

        $trim = DB::getDriverName() === 'pgsql' ? 'BTRIM' : 'TRIM';
        $rows = DB::table(ChatbotAiQueue::TABLE)->whereBetween('created_at', [$start->copy()->timezone(config('app.timezone')), $end->copy()->timezone(config('app.timezone'))])
            ->whereRaw("LOWER($trim(page_name)) = ?", [app(PageSettings::class)->pageKey($page)])
            ->where('model', '<>', FlowEncode::MODEL)->whereNotNull('ai_attempts')
            ->select(['id', 'provider', 'ai_attempts', 'prompt_tokens', 'completion_tokens'])->cursor(); // isa-isa, hindi lahat sa memorya

        $stats = [];
        foreach ($rows as $row) {
            $attempts = json_decode((string) $row->ai_attempts, true);
            if (!is_array($attempts) || !$attempts) continue;
            $out['requests']++;
            $last = array_key_last($attempts);
            foreach ($attempts as $i => $a) {
                $p = (string) ($a['provider'] ?? '');
                if ($p === '') continue;
                $s = &$stats[$p];
                $s ??= ['requests' => 0, 'answered' => 0, 'failed' => 0, 'fallbacks' => 0, 'skipped' => 0, 'ms' => [], 'in' => [], 'out' => []];
                // Ang hindi tinawag (puno ang cap, nakabukas ang breaker, hindi naka-set up) ay hiwalay sa mga tawag.
                if (!empty($a['skipped'])) {
                    $s['skipped']++;
                    unset($s);
                    continue;
                }
                $s['requests']++;
                if (!empty($a['ok'])) $s['answered']++; else $s['failed']++;
                if (empty($a['ok']) && $i !== $last) $s['fallbacks']++;
                $s['ms'][] = max(0, (int) ($a['ms'] ?? 0));
                unset($s);
            }
            if ($row->provider !== null && isset($stats[$row->provider])) {
                if ($row->prompt_tokens !== null) $stats[$row->provider]['in'][] = (int) $row->prompt_tokens;
                if ($row->completion_tokens !== null) $stats[$row->provider]['out'][] = (int) $row->completion_tokens;
            }
        }
        ksort($stats);
        foreach ($stats as $p => $s) {
            sort($s['ms']);
            $n = count($s['ms']);
            $out['providers'][] = [
                'provider' => $p, 'label' => ChatbotAiProviders::label($p),
                'requests' => $s['requests'], 'answered' => $s['answered'], 'failed' => $s['failed'], 'fallbacks' => $s['fallbacks'], 'skipped' => $s['skipped'],
                'avg_ms' => $n ? (int) round(array_sum($s['ms']) / $n) : null,
                'p95_ms' => $n ? $s['ms'][max(0, (int) ceil(0.95 * $n) - 1)] : null, // nearest rank
                'prompt_tokens' => $s['in'] ? array_sum($s['in']) : null, 'completion_tokens' => $s['out'] ? array_sum($s['out']) : null,
                'avg_prompt_tokens' => $s['in'] ? (int) round(array_sum($s['in']) / count($s['in'])) : null,
                'avg_completion_tokens' => $s['out'] ? (int) round(array_sum($s['out']) / count($s['out'])) : null,
            ];
        }
        $out['message'] = $out['requests'] ? null : 'No requests';

        return $out;
    }
}

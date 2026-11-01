<?php

namespace App\Services\Chatbot;

use App\Models\PageSetting;
use App\Services\ChatblastSetupCheck;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Ang load ng account ng page sa Forbeast (spec 210, GET /v1/site/ai-load): bago ang AI step, may load pa ba? Naka-cache
 * kada page nang 60 s (ang top-up ay umaabot sa loob ng isang minuto); ang hindi naka-cache ay tinatanong kasama ang
 * hanggang 99 pang page ng parehong gateway, at ang chatbot:ai-load ay nagpapanibago ng lahat kada minuto (kada 100). Ang
 * "stopped" lang ang pumipigil sa AI; anumang iba, at anumang error (walang sagot, page_not_found, walang gateway), ay
 * tumatakbo ang AI. Ang note ng Forbeast (Ingles, gaya ng ibinigay) ay ipinapakita sa row ng page.
 */
class ChatbotAiLoad
{
    public const ERROR = 'No AI load: Forbeast stopped the AI for this page.';
    public const BATCH = 100;
    /** Ilang segundo ipinapakita pa ang huling note sa screen (mas mahaba sa 60 s ng AI step). */
    public const SHOWN_SECONDS = 900;
    private const PAGES = 'chatbot-ai-load:pages';

    private static function key(string $gateway, string $pageId): string
    {
        return 'chatbot-ai-load:' . $gateway . ':' . $pageId;
    }

    private static function fresh(): int
    {
        return max(1, (int) config('services.chatbot_ai.billing.load_seconds', 60));
    }

    /** Ang note kapag "stopped" ang page (walang AI), o null (tumatakbo ang AI). */
    public function stopped(string $pageName, string $pageId): ?string
    {
        $s = $this->state($pageName, $pageId);

        return ($s['state'] ?? null) === 'stopped' ? (string) ($s['note'] ?? '') : null;
    }

    /** Ang sariwang estado ng page {state, note, balance, at}, o null (walang gateway, error, o walang sagot para sa page). */
    public function state(string $pageName, string $pageId): ?array
    {
        $gw = ChatbotAiUsage::gatewayOf($pageName);
        if ($gw === null || !ctype_digit($pageId)) return null;
        $hit = Cache::get(self::key($gw, $pageId));
        if (is_array($hit) && $hit['at'] > now()->getTimestamp() - self::fresh()) return $hit;
        // Kapapalya lang ang tanong sa gateway na ito: tumatakbo ang AI, at hindi muna tinatanong ulit.
        if (Cache::has('chatbot-ai-load:' . $gw . ':down')) return null;
        $ids = [$pageId];
        foreach (($this->known()[$gw] ?? []) as $other) {
            if (count($ids) >= self::BATCH) break;
            if ($other === $pageId) continue;
            $o = Cache::get(self::key($gw, $other));
            if (!is_array($o) || $o['at'] <= now()->getTimestamp() - self::fresh()) $ids[] = $other;
        }
        $this->fetch($gw, $ids);
        $hit = Cache::get(self::key($gw, $pageId));

        return is_array($hit) && $hit['at'] > now()->getTimestamp() - self::fresh() ? $hit : null;
    }

    /** @return array<string, array<int, string>> gateway => mga page ID (botcake.page_id) ng mga page nito, naka-cache sandali */
    public function known(): array
    {
        return Cache::remember(self::PAGES, 600, function () {
            $out = [];
            $rows = PageSetting::where('provider', 'botcake')->where('key', 'page_id')->where('active', true)->where('page_name', '!=', ChatblastSetupCheck::ALL_PAGE)
                ->orderBy('id')->get(['page_name', 'value']);
            foreach ($rows as $r) {
                $id = trim((string) $r->value);
                $gw = ChatbotAiUsage::gatewayOf((string) $r->page_name);
                if ($gw !== null && ctype_digit($id)) $out[$gw][$id] = $id;
            }

            return array_map('array_values', $out);
        });
    }

    /** Panibaguhin ang lahat ng kilalang page (chatbot:ai-load), kada 100 kada gateway. @return int ilang tanong */
    public function refresh(): int
    {
        Cache::forget(self::PAGES);
        $n = 0;
        foreach ($this->known() as $gw => $ids) {
            foreach (array_chunk($ids, self::BATCH) as $chunk) {
                $n++;
                if (!$this->fetch($gw, $chunk)) break;
            }
        }

        return $n;
    }

    /** Ang tanong sa Forbeast para sa mga page; ang sagot ay untrusted (text lang, putol). @return bool true = may sagot */
    public function fetch(string $gw, array $ids): bool
    {
        $c = Gateways::config($gw);
        if ($c['url'] === '' || $c['site_key'] === '' || !$ids) return false;
        try {
            $res = Http::withToken($c['site_key'])->acceptJson()->connectTimeout(2)->timeout(max(1, (int) config('services.chatbot_ai.billing.load_timeout', 3)))
                ->get($c['url'] . '/v1/site/ai-load', ['pages' => implode(',', array_slice($ids, 0, self::BATCH))]);
        } catch (ConnectionException) {
            Cache::put('chatbot-ai-load:' . $gw . ':down', 1, self::fresh());
            return false;
        } catch (Throwable $e) {
            report($e);
            Cache::put('chatbot-ai-load:' . $gw . ':down', 1, self::fresh());
            return false;
        }
        $pages = $res->successful() ? $res->json('pages') : null;
        if (!is_array($pages)) {
            Cache::put('chatbot-ai-load:' . $gw . ':down', 1, self::fresh());
            return false;
        }
        $asked = array_flip(array_map('strval', $ids));
        foreach ($pages as $p) {
            $id = is_array($p) && is_scalar($p['page_id'] ?? null) ? (string) $p['page_id'] : '';
            if (!isset($asked[$id])) continue;
            $state = is_string($p['state'] ?? null) ? $p['state'] : '';
            $note = is_string($p['note'] ?? null) ? mb_substr(trim($p['note']), 0, 300) : null;
            Cache::put(self::key($gw, $id), ['state' => mb_substr($state, 0, 20), 'note' => $note !== '' ? $note : null,
                'balance' => is_scalar($p['balance_php'] ?? null) ? mb_substr((string) $p['balance_php'], 0, 20) : null, 'at' => now()->getTimestamp()], self::SHOWN_SECONDS);
        }

        return true;
    }

    /**
     * Ang note sa screen (/chatbot/pages) para sa mga page: ang huling low o stopped (hanggang SHOWN_SECONDS), mula sa cache
     * lang (walang tanong sa Forbeast). @param array<string, ?string> $pages page => page ID @return array<string, ?array>
     */
    public function shown(array $pages): array
    {
        $keys = [];
        foreach ($pages as $page => $id) {
            $gw = $id !== null && ctype_digit((string) $id) ? ChatbotAiUsage::gatewayOf((string) $page) : null;
            if ($gw !== null) $keys[$page] = self::key($gw, (string) $id);
        }
        $hits = $keys ? Cache::many(array_values($keys)) : [];
        $out = [];
        foreach ($pages as $page => $id) {
            $h = isset($keys[$page]) ? ($hits[$keys[$page]] ?? null) : null;
            $out[$page] = is_array($h) && in_array($h['state'], ['low', 'stopped'], true) ? ['state' => $h['state'], 'note' => $h['note'], 'balance' => $h['balance']] : null;
        }

        return $out;
    }

    /** Ang record ng AI step na nilaktawan dahil walang load (walang tawag, walang singil): isang sync row na may error. */
    public static function skipped(FlowRun $run, string $nodeId, string $model, int $maxTokens): array
    {
        $res = ['ok' => false, 'text' => '', 'error' => self::ERROR, 'provider' => null, 'model_used' => null, 'fell_back' => false, 'fallback_reason' => null,
            'latency_ms' => 0, 'prompt_tokens' => null, 'completion_tokens' => null, 'attempts' => []];
        try {
            ChatbotAiQueue::record($run, $nodeId, $model, $maxTokens, $res);
        } catch (Throwable $e) {
            report($e);
        }

        return ['ok' => false, 'text' => '', 'error' => self::ERROR];
    }
}

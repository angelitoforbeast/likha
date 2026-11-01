<?php

namespace App\Services\Chatbot;

use App\Services\PageSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Ang usage ng chatbot AI papunta sa Forbeast ng page (spec 210): ang bawat sagot ng provider na may pool ay isang record
 * (client_ref = request_id, kaya ligtas ang ulit: "duplicate"), sa gateway na gamit ng page (usage_gateway, naitala sa oras ng
 * tawag), kasama ang bilang at pause sa screen ng pool. Ang singil (rate at markup) ay sa Forbeast, hindi rito.
 */
class ChatbotAiUsage
{
    public const PENDING = 'pending';
    public const NO_GATEWAY = 'no_gateway';

    /** Ang gateway na tatanggap ng usage ng page (chatbot.gateway, o ang default), kapag may URL at site key ito; o null. */
    public static function gatewayOf(string $page): ?string
    {
        $g = Gateways::active($page);

        return Gateways::setUp($g) && Gateways::config($g)['site_key'] !== '' ? $g : null;
    }

    public const SENT = 'sent';
    public const BATCH = 100;
    public const PAUSE_MINUTES = 15;
    public const MAX_BACKOFF_MINUTES = 30;
    /** Ang result ng Forbeast kada record → ang usage_status ng row. Ang wala rito ay invalid (hindi inuulit nang walang hanggan). */
    private const RESULTS = ['stored' => self::SENT, 'duplicate' => self::SENT, 'needs_price' => 'needs_price', 'unbilled' => 'unbilled',
        'page_not_found' => 'page_not_found', 'invalid' => 'invalid'];

    /** Ipadala ang lahat ng naghihintay, kada gateway (ang bawat gateway ay may sariling bilang at backoff). */
    public function sendAll(): void
    {
        if (!self::ready()) return;
        $lock = Cache::lock('chatbot-ai-usage:send', 280);
        if (!$lock->get()) return;
        try {
            foreach (Gateways::ALL as $g) {
                try {
                    $this->send($g);
                } catch (Throwable $e) {
                    report($e); // ang isang gateway ay hindi dapat makapigil sa iba; ang row ay pending pa rin
                }
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Ang mga pending ng isang gateway, ayon sa id (ang luma muna), hanggang batches_per_run na batch ng 100 (ang Forbeast ay
     * 120 request kada minuto sa tatlong route ng site). Ang 5xx, 429 at walang sagot: hinto sa gateway na ito, at ang susunod na
     * subok ay pagkatapos ng 1, 2, 4 … 30 minuto (walang row na nagagalaw, kaya ganoon pa rin ang ayos). Ang 401/403: naka-pause
     * (row sa pool, kita sa screen), subok ulit kada 15 minuto. @return int ilang batch ang naipadala
     */
    public function send(string $gateway): int
    {
        $c = Gateways::config($gateway);
        if ($c['url'] === '' || $c['site_key'] === '') return 0;
        if ((int) Cache::get('chatbot-ai-usage:' . $gateway . ':next_at', 0) > now()->getTimestamp()) return 0;
        $keys = app(ChatbotAiKeys::class);
        $paused = $keys->latest('usage.' . $gateway . '.paused');
        if ($paused && (int) ($paused['retry_at'] ?? 0) > now()->getTimestamp()) return 0;

        $sent = 0;
        $max = max(1, (int) config('services.chatbot_ai.billing.batches_per_run', 20));
        $lastId = 0;
        while ($sent < $max) {
            $rows = DB::table(ChatbotAiQueue::TABLE)->where('usage_status', self::PENDING)->where('usage_gateway', $gateway)->where('id', '>', $lastId)
                ->orderBy('id')->limit(self::BATCH)->get();
            if ($rows->isEmpty()) break;
            $lastId = (int) $rows->last()->id;
            $answer = $this->post($c, $rows->map(fn ($r) => self::record($r))->all());
            if ($answer['state'] === 'retry') {
                $fails = (int) Cache::increment('chatbot-ai-usage:' . $gateway . ':fails');
                Cache::put('chatbot-ai-usage:' . $gateway . ':next_at', now()->getTimestamp() + 60 * min(self::MAX_BACKOFF_MINUTES, 2 ** max(0, $fails - 1)), 3600);
                break;
            }
            if ($answer['state'] === 'unauthorized') {
                $this->pause($gateway, $answer['error']);
                break;
            }
            Cache::forget('chatbot-ai-usage:' . $gateway . ':fails');
            if ($paused) {
                $this->resume($gateway);
                $paused = null;
            }
            $this->mark($rows, $answer['results']);
            $sent++;
        }

        return $sent;
    }

    /** Ang record ng contract (docs/ai-load.md ng Forbeast): ang walang tokens ay 0, ang walang gastos ay null (needs_price). */
    public static function record(object $r): array
    {
        $at = Carbon::parse((string) ($r->answered_at ?? $r->created_at), config('app.timezone'));

        return ['client_ref' => (string) $r->request_id, 'page_id' => (string) $r->page_id, 'at' => $at->toIso8601String(),
            'provider' => $r->provider !== null ? mb_substr((string) $r->provider, 0, 40) : null, 'model' => $r->model_used !== null ? mb_substr((string) $r->model_used, 0, 100) : null,
            'key_label' => $r->key_label !== null ? mb_substr((string) $r->key_label, 0, 100) : null,
            'tokens_in' => (int) ($r->prompt_tokens ?? 0), 'tokens_out' => (int) ($r->completion_tokens ?? 0),
            'cost_micros' => $r->cost_micros !== null ? (int) $r->cost_micros : null];
    }

    /** @return array{state: string, results: array, error: ?string} state ok | retry | unauthorized | refused */
    private function post(array $c, array $records): array
    {
        try {
            $res = Http::withToken($c['site_key'])->acceptJson()->connectTimeout(5)->timeout(max(1, (int) config('services.chatbot_ai.billing.timeout', 10)))
                ->post($c['url'] . '/v1/site/ai-usage', ['records' => $records]);
        } catch (ConnectionException) {
            return ['state' => 'retry', 'results' => [], 'error' => 'Forbeast did not answer.'];
        }
        $status = $res->status();
        if (in_array($status, [401, 403], true)) return ['state' => 'unauthorized', 'results' => [], 'error' => 'Forbeast refused the site key (HTTP ' . $status . ').'];
        if ($status === 429 || $status >= 500 || $status === 408) return ['state' => 'retry', 'results' => [], 'error' => 'Forbeast answered HTTP ' . $status . '.'];
        $results = $res->json('results');
        // Ang 422 ng buong request (hindi listahan ng 1 hanggang 100): mali ang ipinadala, kaya invalid ang lahat (hindi inuulit).
        if ($status === 422) {
            return ['state' => 'ok', 'results' => array_map(fn ($r) => ['client_ref' => $r['client_ref'], 'result' => 'invalid'], $records), 'error' => null];
        }
        // Ang ibang hindi 2xx (hal. 404 kapag wala pa ang route sa Forbeast): ulit na may backoff, walang row na nawawala.
        if (!$res->successful()) return ['state' => 'retry', 'results' => [], 'error' => 'Forbeast answered HTTP ' . $status . '.'];

        return ['state' => 'ok', 'results' => is_array($results) ? $results : [], 'error' => null];
    }

    /** Markahan ang bawat row ayon sa result nito; ang walang result ay pending pa rin (susunod na takbo). */
    private function mark($rows, array $results): void
    {
        $by = [];
        foreach ($results as $r) {
            if (!is_array($r) || !is_string($r['client_ref'] ?? null)) continue;
            $result = is_string($r['result'] ?? null) ? $r['result'] : 'invalid';
            if ($result === 'stored' && ($r['needs_price'] ?? false) === true) $result = 'needs_price';
            $by[$r['client_ref']] = $result;
        }
        foreach ($rows as $row) {
            $result = $by[(string) $row->request_id] ?? null;
            if ($result === null) continue;
            DB::table(ChatbotAiQueue::TABLE)->where('id', $row->id)->where('usage_status', self::PENDING)->update([
                'usage_status' => self::RESULTS[$result] ?? 'invalid', 'usage_result' => mb_substr($result, 0, 20), 'usage_sent_at' => now(),
            ]);
        }
    }

    private function pause(string $gateway, string $error): void
    {
        app(PageSettings::class)->set(ChatbotAiKeys::PAGE, ChatbotAiKeys::PROVIDER, 'usage.' . $gateway . '.paused', json_encode(['at' => now()->toIso8601String(),
            'error' => $error, 'retry_at' => now()->addMinutes(self::PAUSE_MINUTES)->getTimestamp()]), false, null, true);
    }

    private function resume(string $gateway): void
    {
        $s = app(PageSettings::class);
        foreach ($s->pageQuery(ChatbotAiKeys::PAGE)->where('provider', ChatbotAiKeys::PROVIDER)->where('key', 'usage.' . $gateway . '.paused')->where('active', true)->get() as $row) {
            $s->deactivate($row);
        }
    }

    /** May mga column na ng key at gastos (ang migration ng spec 210)? */
    public static function ready(): bool
    {
        return Schema::hasTable(ChatbotAiQueue::TABLE) && Schema::hasColumn(ChatbotAiQueue::TABLE, 'usage_status');
    }

    /** Ang bilang ng bawat status kada gateway, at ang pause (401) ng gateway. @return array<int, array> */
    public static function panel(): array
    {
        if (!self::ready()) return [];
        $counts = DB::table(ChatbotAiQueue::TABLE)->whereNotNull('usage_status')->groupBy('usage_gateway', 'usage_status')
            ->selectRaw('usage_gateway, usage_status, COUNT(*) AS n')->get();
        $out = [];
        foreach (Gateways::ALL as $g) {
            $mine = $counts->where('usage_gateway', $g)->pluck('n', 'usage_status')->map(fn ($n) => (int) $n)->all();
            $paused = app(ChatbotAiKeys::class)->latest('usage.' . $g . '.paused');
            if (!$mine && !$paused && !Gateways::setUp($g)) continue;
            $out[] = ['gateway' => $g, 'label' => Gateways::label($g), 'counts' => $mine, 'paused' => $paused];
        }
        $none = (int) $counts->where('usage_status', 'no_gateway')->sum('n');
        if ($none > 0) $out[] = ['gateway' => null, 'label' => 'No gateway', 'counts' => ['no_gateway' => $none], 'paused' => null];

        return $out;
    }
}

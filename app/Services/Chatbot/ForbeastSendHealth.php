<?php

namespace App\Services\Chatbot;

use App\Models\PageSetting;
use App\Services\ChatbotFields;
use App\Services\PageSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Ang tunay na bilis ng padala ng Forbeast (GET /v1/health/sends, site key): tinatanggap agad ng Forbeast ang padala at
 * ini-queue, kaya ang "sent" sa Likha ay hindi pa naipadala sa customer. Kinukuha kada minuto ng chatbot:forbeast-watch.
 *
 * Nasa cache lang (walang table): ang huling sagot (kasama ang kada page) at ang overall send lag kada minuto ng huling
 * 24 oras (hanggang 1440 na maliit na item). Ang mawala ay ang graph lang; ang alarm event ay nasa page_settings.
 *
 * Alarm: overall send lag > LAG_ALARM sa STREAK na sunod na sample (o lag ng isang page sa Forbeast) → event sa
 * chatbot.auto_switch (result "alarm", condition send_lag), isa lang kada REPEAT_SECONDS habang tuloy; "recovered" kapag
 * ang pinakamataas na lag (overall at mga page) ay < LAG_CLEAR sa STREAK na sunod na sample. Walang lipat sa Botcake:
 * hindi umaabot sa Likha ang kopya ng Botcake sa page na nasa Forbeast, kaya walang sasagot. Ang palyang kuha (timeout,
 * 500, 404) ay hindi sample: hindi nito binubura o dinadagdagan ang bilang.
 */
class ForbeastSendHealth
{
    public const CONDITION = 'send_lag';
    public const ALARM = 'alarm';
    public const RECOVERED = 'recovered';
    public const LAG_ALARM = 120;
    public const LAG_CLEAR = 30;
    public const STREAK = 3;
    public const REPEAT_SECONDS = 1800;
    public const STALE_SECONDS = 300;
    private const KEEP_SECONDS = 86400;

    private const LATEST = 'forbeast-health:latest';
    private const SAMPLES = 'forbeast-health:samples';
    private const MISSING = 'forbeast-health:missing';
    private const STATE = 'forbeast-health:alarm';
    /** Ang cache ay mas matagal nang kaunti sa 24 oras, para buo ang graph. */
    private const TTL = 90000;

    /** Ang default kung walang thresholds ang sagot. */
    private const THRESHOLDS = ['send_lag_amber' => 30, 'send_lag_red' => 120, 'failed_amber' => 5, 'failed_red' => 15];

    public function __construct(private PageSettings $settings)
    {
    }

    /** Isang kuha (timeout 5 s); hindi kailanman nagtatapon. */
    public function fetch(): void
    {
        $url = rtrim((string) config('services.gateway.url'), '/');
        $key = trim((string) config('services.gateway.key'));
        if ($url === '' || $key === '') return;

        try {
            $res = Http::withToken($key)->acceptJson()->connectTimeout(3)->timeout(5)->get($url . '/v1/health/sends');
        } catch (ConnectionException) {
            return;
        } catch (Throwable $e) {
            report($e);
            return;
        }
        if ($res->status() === 404) {
            // Hindi pa naka-deploy sa Forbeast: "not available yet" hanggang may sagot.
            Cache::put(self::MISSING, now()->getTimestamp(), self::TTL);
            return;
        }
        $data = $res->successful() ? $res->json() : null;
        if (!is_array($data) || !is_array($data['overall'] ?? null)) return;

        $snap = self::normalize($data);
        $now = now()->getTimestamp();
        Cache::put(self::LATEST, ['fetched_at' => $now] + $snap, self::TTL);
        Cache::forget(self::MISSING);
        $samples = array_values(array_filter((array) Cache::get(self::SAMPLES, []), fn ($s) => $s[0] > $now - self::KEEP_SECONDS));
        $samples[] = [$now, $snap['overall']['send_lag_seconds']];
        Cache::put(self::SAMPLES, $samples, self::TTL);

        $this->alarmCheck($snap);
    }

    /** Ang mga numero ng sagot: ang wala ay 0 (o null ang status ng workers). */
    private static function normalize(array $d): array
    {
        $n = fn ($v) => is_numeric($v) ? $v + 0 : 0;
        $o = (array) $d['overall'];
        $overall = [];
        foreach (['queued_sends', 'send_lag_seconds', 'sent_per_min_5m', 'failed_pct_60m', 'forward_lag_seconds'] as $k) $overall[$k] = $n($o[$k] ?? null);
        $overall['workers'] = (int) $n($o['workers']['sends'] ?? null);
        $overall['workers_status'] = is_string($o['workers']['status'] ?? null) ? mb_substr($o['workers']['status'], 0, 30) : null;

        $pages = [];
        foreach (is_array($d['pages'] ?? null) ? $d['pages'] : [] as $p) {
            $id = is_array($p) ? (string) ($p['page_id'] ?? '') : '';
            if ($id === '' || !ctype_digit($id)) continue;
            $pages[$id] = ['queued_sends' => $n($p['queued_sends'] ?? null), 'send_lag_seconds' => $n($p['send_lag_seconds'] ?? null),
                'sent_per_min_5m' => $n($p['sent_per_min_5m'] ?? null), 'failed_pct_60m' => $n($p['failed_pct_60m'] ?? null)];
        }
        $t = self::THRESHOLDS;
        foreach ($t as $k => $v) if (is_numeric($d['thresholds'][$k] ?? null)) $t[$k] = $d['thresholds'][$k] + 0;

        return ['at' => is_string($d['at'] ?? null) ? $d['at'] : null, 'overall' => $overall, 'pages' => $pages, 'thresholds' => $t];
    }

    /** Ang mga page na nasa Forbeast ngayon: Facebook page ID => pangalan sa Likha, ayon sa pagkakasunod ng setting. */
    public function forbeastPages(): array
    {
        $out = [];
        $rows = PageSetting::where('provider', ChatbotFields::PROVIDER)->where('key', 'provider')->where('active', true)->orderBy('id')->get();
        foreach ($rows as $s) {
            if ($s->plainValue() !== ChatbotProvider::GATEWAY) continue;
            $id = trim((string) $this->settings->get((string) $s->page_name, 'botcake', 'page_id'));
            if ($id !== '' && !isset($out[$id])) $out[$id] = (string) $s->page_name;
        }

        return $out;
    }

    // ── alarm ─────────────────────────────────────────────────────────────────────────────────────────────────────

    private function alarmCheck(array $snap): void
    {
        $pages = $this->forbeastPages();
        if ($pages === []) {
            // Walang page sa Forbeast: walang alarm na dapat manatili sa screen.
            Cache::forget(self::STATE);
            return;
        }
        $state = (array) Cache::get(self::STATE, []) + ['active' => false, 'high' => 0, 'page_high' => [], 'low' => 0, 'last' => 0];

        $overallLag = $snap['overall']['send_lag_seconds'];
        $state['high'] = $overallLag > self::LAG_ALARM ? $state['high'] + 1 : 0;
        $pageHigh = [];
        $worst = $overallLag;
        foreach ($pages as $id => $name) {
            $lag = $snap['pages'][$id]['send_lag_seconds'] ?? 0;
            $worst = max($worst, $lag);
            if ($lag > self::LAG_ALARM) $pageHigh[$id] = ($state['page_high'][$id] ?? 0) + 1;
        }
        $state['page_high'] = $pageHigh;
        $state['low'] = $worst < self::LAG_CLEAR ? $state['low'] + 1 : 0;

        $lagging = array_keys(array_filter($pageHigh, fn ($c) => $c >= self::STREAK));
        $now = now()->getTimestamp();
        $error = 'Forbeast send lag ' . $overallLag . 's, ' . $snap['overall']['queued_sends'] . ' queued';
        if ($state['high'] < self::STREAK && $lagging !== []) {
            // Ang page lang ang lampas: ang pinakamabagal na page ang numero, hindi ang overall.
            usort($lagging, fn ($a, $b) => $snap['pages'][$b]['send_lag_seconds'] <=> $snap['pages'][$a]['send_lag_seconds']);
            $w = $snap['pages'][$lagging[0]];
            $error = 'Forbeast send lag ' . $w['send_lag_seconds'] . 's, ' . $w['queued_sends'] . ' queued';
        }
        if (($state['high'] >= self::STREAK || $lagging !== []) && $now - (int) $state['last'] >= self::REPEAT_SECONDS) {
            // Ang apektado: ang mga page na lampas; kung ang overall lang, lahat ng page sa Forbeast.
            $names = array_map(fn ($id) => $pages[$id], $lagging !== [] ? $lagging : array_keys($pages));
            $this->record($names, self::ALARM, $error);
            $state['since'] = $state['active'] ? ($state['since'] ?? $now) : $now;
            $state['active'] = true;
            $state['last'] = $now;
            $state['error'] = $error;
        } elseif ($state['active'] && $state['low'] >= self::STREAK && ($state['pending_low'] ?? self::STREAK) >= self::STREAK) {
            $this->record(array_values($pages), self::RECOVERED, $error);
            $state['active'] = false;
            $state['last'] = 0;
            unset($state['since'], $state['error']);
        }
        Cache::put(self::STATE, $state, self::TTL);
    }

    /**
     * Ang parehong alarm mula sa sariling data ng Likha (gumagana kahit hindi sumasagot ang health ng Forbeast): ang
     * pinakamatandang pending na sagot ng Forbeast > LAG_ALARM sa STREAK na sunod na minuto. Recovered kapag ang dalawa
     * ay < LAG_CLEAR: ang pending sa STREAK na minuto, at ang health (ang sample, o walang bagong sample). Walang
     * send_status pa (RealSendTime::available): walang data, kaya hindi ito nagtataas o nagbubura ng alarm.
     */
    public function pendingCheck(): void
    {
        $pages = $this->forbeastPages();
        if ($pages === []) return; // alarmCheck ang nagbubura ng estado
        $rst = app(RealSendTime::class);
        $state = (array) Cache::get(self::STATE, []) + ['active' => false, 'high' => 0, 'page_high' => [], 'low' => 0, 'last' => 0];
        if (!$rst->available()) {
            // Walang data mula rito: hindi nagtataas at hindi nagbubura ng alarm, at hindi rin humaharang sa recovery ng health.
            $state['pending_high'] = 0;
            $state['pending_low'] = self::STREAK;
            Cache::put(self::STATE, $state, self::TTL);
            return;
        }
        $pending = $rst->pending(array_map('intval', array_keys($pages)));
        $sum = RealSendTime::summary($pending);
        $state['pending_high'] = $sum['oldest'] > self::LAG_ALARM ? ($state['pending_high'] ?? 0) + 1 : 0;
        $state['pending_low'] = $sum['oldest'] < self::LAG_CLEAR ? ($state['pending_low'] ?? 0) + 1 : 0;

        $now = now()->getTimestamp();
        $latest = Cache::get(self::LATEST);
        $healthLow = $state['low'] >= self::STREAK || !is_array($latest) || $now - (int) $latest['fetched_at'] > self::STALE_SECONDS || Cache::has(self::MISSING);
        $error = 'Likha: oldest pending Forbeast reply ' . $sum['oldest'] . 's, ' . $sum['count'] . ' pending';
        if ($state['pending_high'] >= self::STREAK && $now - (int) $state['last'] >= self::REPEAT_SECONDS) {
            $late = array_keys(array_filter($pending, fn ($p) => $p['oldest'] > self::LAG_ALARM));
            $this->record(array_values(array_intersect_key($pages, array_flip($late))) ?: array_values($pages), self::ALARM, $error);
            $state['since'] = $state['active'] ? ($state['since'] ?? $now) : $now;
            $state['active'] = true;
            $state['last'] = $now;
            $state['error'] = $error;
        } elseif ($state['active'] && $state['pending_low'] >= self::STREAK && $healthLow) {
            $this->record(array_values($pages), self::RECOVERED, $error);
            $state['active'] = false;
            $state['last'] = 0;
            unset($state['since'], $state['error']);
        }
        Cache::put(self::STATE, $state, self::TTL);
    }

    /** Isang event row (sa unang apektadong page), kasama ng mga event ng kusang lipat. */
    private function record(array $names, string $result, string $error): void
    {
        app(ForbeastWatch::class)->event($names[0], $result, null, self::CONDITION, $error, ['pages' => array_slice($names, 0, 50)]);
    }

    /** Ang alarm na aktibo ngayon (para sa banner). @return array{error: string, since: string}|null */
    public function activeAlarm(): ?array
    {
        $s = Cache::get(self::STATE);
        if (!is_array($s) || empty($s['active'])) return null;

        return ['error' => (string) ($s['error'] ?? ''), 'since' => now()->setTimestamp((int) ($s['since'] ?? 0))->timezone('Asia/Manila')->format('Y-m-d H:i')];
    }

    // ── panel ─────────────────────────────────────────────────────────────────────────────────────────────────────

    public static function light(int|float|null $v, int|float $amber, int|float $red): string
    {
        if ($v === null) return 'none';

        return $v > $red ? 'red' : ($v > $amber ? 'amber' : 'green');
    }

    /**
     * Ang panel ng Bot Health; null kapag walang page sa Forbeast. status: ok, stale (walang bagong sagot nang > 5 minuto),
     * not_available (404), none (wala pang kuha).
     */
    public function panel(): ?array
    {
        $pages = $this->forbeastPages();
        if ($pages === []) return null;

        $latest = Cache::get(self::LATEST);
        $now = now()->getTimestamp();
        $status = !is_array($latest) ? 'none' : ($now - (int) $latest['fetched_at'] > self::STALE_SECONDS ? 'stale' : 'ok');
        if (Cache::has(self::MISSING)) $status = 'not_available';
        $out = ['status' => $status, 'alarm' => $this->activeAlarm(), 'page_ids' => array_map('strval', array_keys($pages)), 'updated_at' => null, 'overall' => [], 'pages' => [], 'series' => []];
        if (!is_array($latest)) return $out;

        $t = $latest['thresholds'];
        $o = $latest['overall'];
        $lag = fn ($v) => self::light($v, $t['send_lag_amber'], $t['send_lag_red']);
        $out['updated_at'] = now()->setTimestamp((int) $latest['fetched_at'])->timezone('Asia/Manila')->format('Y-m-d H:i:s');
        $out['overall'] = [
            'send_lag_seconds' => ['value' => $o['send_lag_seconds'], 'light' => $lag($o['send_lag_seconds'])],
            'queued_sends' => ['value' => $o['queued_sends'], 'light' => 'none'],
            'sent_per_min_5m' => ['value' => $o['sent_per_min_5m'], 'light' => 'none'],
            'failed_pct_60m' => ['value' => $o['failed_pct_60m'], 'light' => self::light($o['failed_pct_60m'], $t['failed_amber'], $t['failed_red'])],
            'forward_lag_seconds' => ['value' => $o['forward_lag_seconds'], 'light' => $lag($o['forward_lag_seconds'])],
            'workers' => ['value' => $o['workers'], 'status' => $o['workers_status'],
                'light' => $o['workers'] > 0 && $o['workers_status'] === 'running' ? 'green' : 'red'],
        ];
        $rows = [];
        foreach ($pages as $id => $name) {
            $p = $latest['pages'][$id] ?? null;
            if ($p === null) continue;
            $rows[] = ['page' => $name, 'page_id' => (string) $id] + $p + ['light' => $lag($p['send_lag_seconds'])];
        }
        usort($rows, fn ($a, $b) => [$b['send_lag_seconds'], $b['queued_sends']] <=> [$a['send_lag_seconds'], $a['queued_sends']]);
        $out['pages'] = $rows;
        $out['series'] = array_map(fn ($s) => ['at' => $s[0], 'lag' => $s[1]], array_values(array_filter((array) Cache::get(self::SAMPLES, []), fn ($s) => $s[0] > $now - self::KEEP_SECONDS)));
        $out['thresholds'] = $t;

        return $out;
    }
}

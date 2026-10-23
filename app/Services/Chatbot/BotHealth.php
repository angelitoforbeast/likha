<?php

namespace App\Services\Chatbot;

use App\Models\AppSetting;
use App\Models\BotcakeChat;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * /chatbot/health: gaano kabilis sumagot ang bot, ilang padala ang pumalya at bakit, at ang AI calls: ngayon laban sa
 * kahapon, bawat araw sa range, at ngayon kada oras. Ang mga kahulugan ay nasa spec 134 (inbound, unang sagot, reasons).
 * Isang query kada pamilya ng metric; ang kinukuwenta (walang ilaw at queue) ay naka-cache 5 minuto kada (page, range),
 * kaya ang ilaw ay sumusunod agad sa binago na threshold at ang queue ay laging bago.
 */
class BotHealth
{
    public const SETTING = 'bot_health_thresholds';
    public const CACHE_SECONDS = 300;
    public const DEFAULT_DAYS = 7;
    public const MAX_DAYS = 31;
    public const LIST_LIMIT = 20;
    public const BURST_SECONDS = 10;

    /** Ang mga dahilan ng pumalyang padala, sa pagkakasunod na sinusuri (ang una na tumama ang panalo). */
    public const REASONS = [
        'unreachable' => 'Customer not reachable (#551 / isn\'t available)',
        'botcake' => 'Botcake error (HTTP 5xx)',
        'window' => 'Outside 24-hour window (#10)',
        'timeout' => 'No answer from Botcake (HTTP 0 / timeout)',
        'other' => 'Other',
    ];

    /** Ang parehong pagbilang ng reason sa lahat ng query (grouped, listahan, kada oras). */
    private const REASON_SQL = "CASE WHEN status = 'sent' THEN 'sent'
        WHEN LOWER(error) LIKE '%#551%' OR LOWER(error) LIKE '%isn''t available%' THEN 'unreachable'
        WHEN LOWER(error) LIKE '%(#10)%' THEN 'window'
        WHEN http >= 500 THEN 'botcake'
        WHEN http = 0 OR LOWER(error) LIKE '%no answer from botcake%' OR LOWER(error) LIKE '%timed out%' THEN 'timeout'
        ELSE 'other' END";

    /**
     * Ang mga ilaw: key => [label, metric ng tile ngayon, unit, "high" (mas mataas ay masama) o "low", amber, red (null: walang red)].
     * Ang failed % ay ang walang "Customer not reachable".
     */
    public const LIGHTS = [
        'reply_p90' => ['First reply p90', 'reply_p90', 's', 'high', 15, 60],
        'reply_max' => ['First reply max', 'reply_max', 's', 'high', 120, null],
        'failed_pct' => ['Failed replies (without customer not reachable)', 'failed_pct_excl', '%', 'high', 3, 10],
        'ai_failed_pct' => ['AI calls failed', 'ai_failed_pct', '%', 'high', 2, 10],
        'ai_p90' => ['AI time p90', 'ai_p90', 's', 'high', 10, null],
        'answered_pct' => ['Answered', 'answered_pct', '%', 'low', 98, 90],
        'sheet_failed' => ['Sheet writes failed', 'sheet_failed', '', 'high', 0, null],
        'queue' => ['Queue now', 'queue', '', 'high', 200, null],
    ];

    /** @return array<string, array{amber: int|float, red: int|float|null}> */
    public static function defaultThresholds(): array
    {
        return array_map(fn ($l) => ['amber' => $l[4], 'red' => $l[5]], self::LIGHTS);
    }

    /** Ang naka-save na thresholds sa ibabaw ng default; ang sira o kulang na key ay default. */
    public static function thresholds(): array
    {
        $out = self::defaultThresholds();
        $stored = json_decode((string) AppSetting::get(self::SETTING, ''), true);
        if (!is_array($stored)) return $out;
        foreach ($out as $key => $_) {
            $t = $stored[$key] ?? null;
            if (!is_array($t) || !isset($t['amber']) || !is_numeric($t['amber'])) continue;
            $out[$key] = ['amber' => $t['amber'] + 0, 'red' => isset($t['red']) && is_numeric($t['red']) ? $t['red'] + 0 : null];
        }

        return $out;
    }

    // ── mga filter ────────────────────────────────────────────────────────────────────────────────────────────────

    /** @return array{page_id: ?string, from: string, to: string} */
    public static function filters(array $q): array
    {
        $today = now()->startOfDay();
        $date = function ($v) {
            if (!is_string($v)) return null;
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);

            return $d && $d->format('Y-m-d') === $v ? CarbonImmutable::instance($d) : null;
        };
        $to = $date($q['to'] ?? null) ?? CarbonImmutable::instance($today);
        if ($to->gt($today)) $to = CarbonImmutable::instance($today);
        $from = $date($q['from'] ?? null) ?? $to->subDays(self::DEFAULT_DAYS - 1);
        if ($from->gt($to)) $from = $to;
        if ($from->diffInDays($to) > self::MAX_DAYS - 1) $from = $to->subDays(self::MAX_DAYS - 1);
        $page = $q['page_id'] ?? null;

        return ['page_id' => is_string($page) && ctype_digit($page) && strlen($page) <= 30 ? $page : null,
            'from' => $from->toDateString(), 'to' => $to->toDateString()];
    }

    // ── labas ─────────────────────────────────────────────────────────────────────────────────────────────────────

    public function output(array $query): array
    {
        $f = self::filters($query);
        $today = now()->toDateString();
        $payload = Cache::remember('bot-health:' . md5(json_encode([$f, $today])), self::CACHE_SECONDS, fn () => $this->build($f, $today));
        $queue = $this->queue();
        $payload['queue'] = $queue;
        $payload['thresholds'] = self::thresholds();
        $payload['lights'] = $this->lights($payload['tiles']['today'] + ['queue' => $queue['total']], $payload['thresholds']);

        return $payload;
    }

    /** @return array{queues: array<string, ?int>, total: ?int, note: ?string} */
    protected function queue(): array
    {
        $bot = (string) config('queue.chatbot_connection');
        $sizes = [];
        foreach ([['chatbot-high', $bot], ['chatbot', $bot], ['encode', (string) config('queue.default')]] as [$name, $connection]) {
            try {
                $sizes[$name] = $this->queueSize($connection, $name);
            } catch (\Throwable) {
                $sizes[$name] = null;
            }
        }
        $unreadable = array_keys(array_filter($sizes, fn ($n) => $n === null));

        return ['queues' => $sizes, 'total' => $unreadable ? null : array_sum($sizes),
            'note' => $unreadable ? 'Cannot read the queue size of ' . implode(', ', $unreadable) . '.' : null];
    }

    protected function queueSize(string $connection, string $queue): int
    {
        return (int) Queue::connection($connection)->size($queue);
    }

    // ── mga ilaw ──────────────────────────────────────────────────────────────────────────────────────────────────

    /** @return array<string, array{label: string, value: mixed, light: string, rule: string}> */
    public function lights(array $today, array $thresholds): array
    {
        $out = [];
        $rank = ['none' => 0, 'green' => 1, 'amber' => 2, 'red' => 3];
        $worst = 'none';
        foreach (self::LIGHTS as $key => [$label, $metric, $unit, $dir]) {
            $t = $thresholds[$key];
            $v = $today[$metric] ?? null;
            $sign = $dir === 'high' ? '>' : '<';
            $u = $unit === '' ? '' : ' ' . $unit;
            $light = 'none';
            if ($v !== null) {
                $past = fn ($limit) => $limit !== null && ($dir === 'high' ? $v > $limit : $v < $limit);
                $light = $past($t['red']) ? 'red' : ($past($t['amber']) ? 'amber' : 'green');
            }
            if ($rank[$light] > $rank[$worst]) $worst = $light;
            $out[$key] = ['label' => $label, 'value' => $v, 'light' => $light,
                'rule' => "$sign {$t['amber']}$u amber" . ($t['red'] !== null ? ", $sign {$t['red']}$u red" : '')];
        }
        $out['overall'] = ['label' => 'Overall', 'value' => null, 'light' => $worst, 'rule' => 'The worst light of the rules above'];

        return $out;
    }

    // ── pagkuwenta ────────────────────────────────────────────────────────────────────────────────────────────────

    private function build(array $f, string $today): array
    {
        $yesterday = CarbonImmutable::parse($today)->subDay()->toDateString();
        // Laging kasama ang ngayon at kahapon para sa mga tile, kahit ibang range ang pinili.
        $start = min($f['from'], $yesterday);
        $end = max($f['to'], $today);
        $startAt = $start . ' 00:00:00';
        $endAt = CarbonImmutable::parse($end)->addDay()->toDateString() . ' 00:00:00';
        $page = $f['page_id'];

        $inbound = $this->inbound($startAt, $endAt, $page);
        $replies = $this->replies($startAt, $endAt, $page);
        $ai = $this->aiCalls($startAt, $endAt, $page);
        $orders = $this->orders($startAt, $endAt, $page);

        $metrics = [];
        for ($d = CarbonImmutable::parse($start); $d->toDateString() <= $end; $d = $d->addDay()) {
            $k = $d->toDateString();
            $metrics[$k] = $this->dayMetrics($inbound['days'][$k] ?? [], $replies[$k] ?? [], $ai[$k] ?? [], $orders[$k] ?? 0);
        }
        $days = [];
        for ($d = CarbonImmutable::parse($f['from']); $d->toDateString() <= $f['to']; $d = $d->addDay()) {
            $days[] = ['date' => $d->toDateString()] + $metrics[$d->toDateString()];
        }
        $t = $metrics[$today];
        $y = $metrics[$yesterday];

        $names = BotcakeChat::pageMap();
        $slow = $this->slow($inbound['rows'], $f, $names);

        return [
            'filters' => $f,
            'tiles' => ['dates' => ['today' => $today, 'yesterday' => $yesterday], 'today' => $t, 'yesterday' => $y]
                + $this->deltas($t, $y),
            'days' => $days,
            'hourly' => $this->hourly($inbound['rows'], $today, $page),
            'slow' => $slow,
            'failures' => $this->failures($f, $page, $names),
        ];
    }

    /**
     * Ang mga mensahe ng customer sa mga page na sinagot ng bot sa range, kasama ang una nitong sagot (send/sent).
     * @return array{days: array<string, array{secs: float[], n: int}>, rows: array<int, array{id: int, at: string, first: ?string, secs: ?int}>}
     */
    private function inbound(string $startAt, string $endAt, ?string $page): array
    {
        $answered = DB::table('chatbot_replies')->where('kind', 'send')->where('status', 'sent')
            ->where('sent_at', '>=', $startAt)->where('sent_at', '<', $endAt)
            ->when($page !== null, fn ($q) => $q->where('page_id', (int) $page))->distinct()->pluck('page_id')->map(fn ($p) => (string) $p)->all();
        if (!$answered) return ['days' => [], 'rows' => []];

        $out = ['days' => [], 'rows' => []];
        $rows = DB::table('botcake_chats as c')
            ->leftJoin('chatbot_replies as r', fn ($j) => $j->on('r.chat_id', '=', 'c.id')->where('r.kind', 'send')->where('r.status', 'sent'))
            ->whereIn('c.page_id', $answered)->where('c.received_at', '>=', $startAt)->where('c.received_at', '<', $endAt)
            ->groupBy('c.id', 'c.received_at')->selectRaw('c.id, c.received_at, MIN(r.sent_at) as first_at')->get();
        foreach ($rows as $r) {
            $secs = $r->first_at === null ? null : max(0, strtotime($r->first_at) - strtotime($r->received_at));
            $day = substr($r->received_at, 0, 10);
            $out['days'][$day]['n'] = ($out['days'][$day]['n'] ?? 0) + 1;
            if ($secs !== null) $out['days'][$day]['secs'][] = $secs;
            $out['rows'][] = ['id' => (int) $r->id, 'at' => $r->received_at, 'first' => $r->first_at, 'secs' => $secs];
        }

        return $out;
    }

    /** @return array<string, array{sent: int, failed: int, reasons: array<string, int>, sheet_failed: int}> */
    private function replies(string $startAt, string $endAt, ?string $page): array
    {
        $out = [];
        $rows = DB::table('chatbot_replies')->whereIn('kind', ['send', 'sheet'])->where('sent_at', '>=', $startAt)->where('sent_at', '<', $endAt)
            ->when($page !== null, fn ($q) => $q->where('page_id', (int) $page))
            ->selectRaw('DATE(sent_at) as d, kind, ' . self::REASON_SQL . ' as reason, COUNT(*) as n')->groupBy('d', 'kind', 'reason')->get();
        foreach ($rows as $r) {
            $day = &$out[$r->d];
            $day ??= ['sent' => 0, 'failed' => 0, 'reasons' => array_fill_keys(array_keys(self::REASONS), 0), 'sheet_failed' => 0];
            if ($r->kind === 'sheet') {
                if ($r->reason !== 'sent') $day['sheet_failed'] += (int) $r->n;
            } elseif ($r->reason === 'sent') {
                $day['sent'] += (int) $r->n;
            } else {
                $day['failed'] += (int) $r->n;
                $day['reasons'][$r->reason] += (int) $r->n;
            }
            unset($day);
        }

        return $out;
    }

    /** @return array<string, array{calls: int, failed: int, retried: int, secs: int[]}> */
    private function aiCalls(string $startAt, string $endAt, ?string $page): array
    {
        $out = [];
        $rows = DB::table('chatbot_ai_requests')->where('created_at', '>=', $startAt)->where('created_at', '<', $endAt)
            ->when($page !== null, fn ($q) => $q->where('page_id', (int) $page))->get(['created_at', 'answered_at', 'status', 'error', 'attempts']);
        foreach ($rows as $r) {
            $day = &$out[substr($r->created_at, 0, 10)];
            $day ??= ['calls' => 0, 'failed' => 0, 'retried' => 0, 'secs' => []];
            $day['calls']++;
            if ($r->status === 'failed' || trim((string) $r->error) !== '') $day['failed']++;
            if ((int) $r->attempts > 1) $day['retried']++;
            if ($r->answered_at !== null) $day['secs'][] = max(0, strtotime($r->answered_at) - strtotime($r->created_at));
            unset($day);
        }

        return $out;
    }

    /** @return array<string, int> */
    private function orders(string $startAt, string $endAt, ?string $page): array
    {
        return DB::table('chatbot_events')->where('type', 'order')->where('created_at', '>=', $startAt)->where('created_at', '<', $endAt)
            ->when($page !== null, fn ($q) => $q->where('page_id', (int) $page))
            ->selectRaw('DATE(created_at) as d, COUNT(*) as n')->groupBy('d')->pluck('n', 'd')->map(fn ($n) => (int) $n)->all();
    }

    private function dayMetrics(array $in, array $rep, array $ai, int $orders): array
    {
        $inbound = $in['n'] ?? 0;
        $secs = $in['secs'] ?? [];
        $sent = $rep['sent'] ?? 0;
        $failed = $rep['failed'] ?? 0;
        $reasons = $rep['reasons'] ?? array_fill_keys(array_keys(self::REASONS), 0);
        $total = $sent + $failed;
        $pct = fn ($n, $of) => $of > 0 ? round($n / $of * 100, 1) : null;
        $aiSecs = $ai['secs'] ?? [];
        $aiCalls = $ai['calls'] ?? 0;

        return [
            'inbound' => $inbound, 'answered' => count($secs), 'not_answered' => $inbound - count($secs), 'answered_pct' => $pct(count($secs), $inbound),
            'reply_avg' => $secs ? round(array_sum($secs) / count($secs), 1) : null, 'reply_median' => self::median($secs),
            'reply_p90' => self::percentile($secs, 90), 'reply_max' => $secs ? max($secs) : null,
            'sent' => $sent, 'failed' => $failed, 'reasons' => $reasons, 'failed_pct' => $pct($failed, $total),
            'failed_pct_excl' => $pct($failed - $reasons['unreachable'], $total),
            'ai_calls' => $aiCalls, 'ai_failed' => $ai['failed'] ?? 0, 'ai_failed_pct' => $pct($ai['failed'] ?? 0, $aiCalls), 'ai_retried' => $ai['retried'] ?? 0,
            'ai_avg' => $aiSecs ? round(array_sum($aiSecs) / count($aiSecs), 1) : null, 'ai_p90' => self::percentile($aiSecs, 90), 'ai_max' => $aiSecs ? max($aiSecs) : null,
            'orders' => $orders, 'sheet_failed' => $rep['sheet_failed'] ?? 0,
        ];
    }

    /** Ngayon laban sa kahapon: ang pagkakaiba, at ang % ng kahapon kapag may laman ito. */
    private function deltas(array $t, array $y): array
    {
        $delta = $pct = [];
        foreach ($t as $k => $v) {
            if (is_array($v)) continue;
            $o = $y[$k];
            $delta[$k] = $v === null || $o === null ? null : (is_int($v) && is_int($o) ? $v - $o : round($v - $o, 1));
            $pct[$k] = $delta[$k] === null || (float) $o === 0.0 ? null : round($delta[$k] / $o * 100, 1);
        }

        return ['delta' => $delta, 'delta_pct' => $pct];
    }

    /** Ngayon kada oras: reply time ayon sa oras ng dating ng mensahe, at ang mga pumalya ayon sa oras ng padala. */
    private function hourly(array $rows, string $today, ?string $page): array
    {
        $hours = array_fill(0, 24, ['secs' => [], 'failed' => 0]);
        foreach ($rows as $r) {
            if ($r['secs'] !== null && str_starts_with($r['at'], $today)) $hours[(int) substr($r['at'], 11, 2)]['secs'][] = $r['secs'];
        }
        $failed = DB::table('chatbot_replies')->where('kind', 'send')->where('status', '<>', 'sent')
            ->where('sent_at', '>=', $today . ' 00:00:00')->where('sent_at', '<', CarbonImmutable::parse($today)->addDay()->toDateString() . ' 00:00:00')
            ->when($page !== null, fn ($q) => $q->where('page_id', (int) $page))->pluck('sent_at');
        foreach ($failed as $at) $hours[(int) substr($at, 11, 2)]['failed']++;

        $out = [];
        foreach ($hours as $h => $x) {
            $out[] = ['hour' => $h, 'answered' => count($x['secs']), 'avg' => $x['secs'] ? round(array_sum($x['secs']) / count($x['secs']), 1) : null,
                'max' => $x['secs'] ? max($x['secs']) : null, 'failed' => $x['failed']];
        }

        return $out;
    }

    /**
     * Ang 20 pinakamabagal na unang sagot sa range. Burst: nagpadala pa ng mensahe ang parehong customer sa loob ng 10 s
     * bago ang sagot (ang kilalang dahilan ng paghihintay). Mga query para sa 20 row lang, hindi kada row.
     */
    private function slow(array $rows, array $f, array $names): array
    {
        $inRange = array_filter($rows, fn ($r) => $r['secs'] !== null && $r['at'] >= $f['from'] . ' 00:00:00' && substr($r['at'], 0, 10) <= $f['to']);
        usort($inRange, fn ($a, $b) => [$b['secs'], $b['id']] <=> [$a['secs'], $a['id']]);
        $top = array_slice($inRange, 0, self::LIST_LIMIT);
        if (!$top) return [];

        $ids = array_column($top, 'id');
        $chats = DB::table('botcake_chats')->whereIn('id', $ids)->get(['id', 'page_id', 'page_name', 'psid', 'received_at'])->keyBy('id');
        $first = [];
        foreach (DB::table('chatbot_replies')->whereIn('chat_id', $ids)->where('kind', 'send')->where('status', 'sent')->orderBy('sent_at')->orderBy('id')->get(['id', 'chat_id', 'node_id']) as $r) {
            $first[$r->chat_id] ??= $r;
        }
        $near = DB::table('botcake_chats')->whereIn('psid', $chats->pluck('psid')->unique()->all())
            ->where('received_at', '>=', date('Y-m-d H:i:s', strtotime(min(array_column($top, 'at'))) - self::BURST_SECONDS))
            ->where('received_at', '<=', max(array_column($top, 'first')))->get(['id', 'page_id', 'psid', 'received_at']);

        $out = [];
        foreach ($top as $r) {
            $c = $chats[$r['id']];
            $from = strtotime($r['at']) - self::BURST_SECONDS;
            $burst = $near->contains(fn ($n) => $n->id !== $c->id && $n->psid === $c->psid && $n->page_id === $c->page_id
                && strtotime($n->received_at) >= $from && $n->received_at <= $r['first']);
            $reply = $first[$r['id']] ?? null;
            $out[] = ['chat_id' => $r['id'], 'reply_id' => $reply?->id, 'at' => $r['at'], 'seconds' => $r['secs'], 'node' => $reply?->node_id,
                'page' => $c->page_name ?: ($names[$c->page_id] ?? 'Page ' . $c->page_id), 'burst' => $burst,
                'url' => $reply ? route('chatbot.replies.show', $reply->id, false) : null];
        }

        return $out;
    }

    /** Ang pinakabagong 20 pumalyang send sa range, may dahilan. Walang text ng customer: ang error ng Botcake lang. */
    private function failures(array $f, ?string $page, array $names): array
    {
        $rows = DB::table('chatbot_replies')->where('kind', 'send')->where('status', '<>', 'sent')
            ->where('sent_at', '>=', $f['from'] . ' 00:00:00')->where('sent_at', '<', CarbonImmutable::parse($f['to'])->addDay()->toDateString() . ' 00:00:00')
            ->when($page !== null, fn ($q) => $q->where('page_id', (int) $page))->orderByDesc('sent_at')->orderByDesc('id')->limit(self::LIST_LIMIT)
            ->selectRaw('id, page_id, node_id, error, http, sent_at, ' . self::REASON_SQL . ' as reason')->get();

        return $rows->map(fn ($r) => ['id' => (int) $r->id, 'at' => $r->sent_at, 'page' => $names[$r->page_id] ?? 'Page ' . $r->page_id, 'node' => $r->node_id,
            'reason' => self::REASONS[$r->reason], 'http' => $r->http === null ? null : (int) $r->http, 'error' => mb_substr((string) $r->error, 0, 200),
            'url' => route('chatbot.replies.show', $r->id, false)])->all();
    }

    // ── istatistika ───────────────────────────────────────────────────────────────────────────────────────────────

    /** Nearest rank: ang ceil(p% × n)-th na pinakamaliit. */
    public static function percentile(array $values, int $p): int|float|null
    {
        if (!$values) return null;
        sort($values);

        return $values[max(1, (int) ceil($p * count($values) / 100)) - 1];
    }

    public static function median(array $values): int|float|null
    {
        if (!$values) return null;
        sort($values);
        $n = count($values);

        return $n % 2 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
    }
}

<?php

namespace App\Services\Chatbot;

use App\Models\ChatbotReply;
use App\Services\BotcakeChatGuard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * /chatbot/speed: gaano kabilis sumagot ang bot kada oras (00–23 Manila) sa isang araw. Ang sukat: mula sa oras ng
 * mensahe ng customer (received_at ng chat) hanggang sa unang padala ng bot sa parehong customer (page + PSID) pagkatapos
 * nito na talagang naipadala: ang oras ng Meta mula sa send_status (confirmed_at) kapag mayroon, kung wala ay ang sent_at
 * ng talaan (binibilang bilang "unconfirmed"). Hindi ang delivered o seen: oras iyon ng telepono ng customer, hindi ng bot.
 * Dalawang query na may petsa sa index (botcake_chats.received_at at chatbot_replies.sent_at), ang pagtutugma ay sa PHP;
 * naka-cache kada (araw, page, bot): ngayon 2 minuto, ang lumang araw 30 minuto.
 */
class ResponseSpeed
{
    public const TODAY_CACHE_SECONDS = 120;
    public const PAST_CACHE_SECONDS = 1800;
    /** Ang sagot na lampas dito mula sa mensahe ay hindi na sagot dito (unanswered). */
    public const MAX_WAIT_SECONDS = 86400;
    public const WITHIN = [30, 60, 300];

    /** @return array{date: string, page_id: ?string, bot: ?string} */
    public static function filters(array $q): array
    {
        $today = now()->toDateString();
        $d = is_string($q['date'] ?? null) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $q['date']) : false;
        $date = $d && $d->format('Y-m-d') === $q['date'] && $q['date'] <= $today ? $q['date'] : $today;
        $page = $q['page_id'] ?? null;
        $bot = $q['bot'] ?? null;

        return ['date' => $date, 'page_id' => is_string($page) && ctype_digit($page) && strlen($page) <= 30 ? $page : null,
            'bot' => is_string($bot) && in_array($bot, ChatbotConnections::BOTS, true) ? $bot : null];
    }

    public function output(array $q): array
    {
        $f = self::filters($q);
        $today = now()->toDateString();
        $out = $this->day($f, $today);
        $day = CarbonImmutable::parse($f['date']);
        // Ang reference ng mga chart: ang naunang araw na may parehong filter, mula sa parehong cache ng araw na iyon.
        $prev = $this->day(['date' => $day->subDay()->toDateString()] + $f, $today);

        return $out + ['dates' => ['today' => $today, 'yesterday' => CarbonImmutable::parse($today)->subDay()->toDateString(),
            'prev' => $day->subDay()->toDateString(), 'next' => $f['date'] < $today ? $day->addDay()->toDateString() : null],
            'refused_off' => $f['page_id'] === null && $f['bot'] === null ? $this->refusedOff($f['date']) : null,
            'charts' => self::charts($out, $prev)];
    }

    /**
     * Ang mga numero ng isang araw at filter, naka-cache (ngayon 2 minuto, ang lumang araw 30 minuto). Ang bersyon sa key
     * ay pinapalitan kapag nagbago ang hugis ng laman, para hindi mabasa ng bagong code ang lumang entry pagkatapos ng deploy.
     */
    private function day(array $f, string $today): array
    {
        $ttl = $f['date'] === $today ? self::TODAY_CACHE_SECONDS : self::PAST_CACHE_SECONDS;

        return Cache::remember('response-speed:v2:' . md5(json_encode($f)), $ttl, fn () => $this->build($f, $today));
    }

    /**
     * Ang data ng mga chart, galing mismo sa mga table (kaya pareho ang numero): kada chart ang serye ng araw (24 na
     * value, null = puwang: walang numero o hindi pa naaabot ang oras), ang parehong serye ng naunang araw (prev) bilang
     * reference, at ang bilang sa likod ng bawat punto (counts, prev_counts) para sa tooltip.
     */
    public static function charts(array $day, array $prev): array
    {
        $col = fn (array $rows, callable $get) => array_map(fn ($r) => $r === null ? null : $get($r), $rows);
        $defs = [
            'reply' => [fn ($o) => $o['hours'], [
                'avg' => fn ($r) => $r['avg'], 'median' => fn ($r) => $r['median'], 'p90' => fn ($r) => $r['p90'],
            ], ['answered' => fn ($r) => $r['answered']]],
            'ai' => [fn ($o) => $o['hours'], [
                'with_ai_avg' => fn ($r) => $r['with_ai']['avg'], 'no_ai_avg' => fn ($r) => $r['no_ai']['avg'],
                'with_ai_median' => fn ($r) => $r['with_ai']['median'], 'no_ai_median' => fn ($r) => $r['no_ai']['median'],
                'ai_time_avg' => fn ($r) => $r['ai_time']['avg'],
            ], ['with AI' => fn ($r) => $r['with_ai']['n'], 'no AI' => fn ($r) => $r['no_ai']['n']]],
            'push' => [fn ($o) => $o['push']['hours'], [
                'avg' => fn ($r) => $r['avg'], 'p90' => fn ($r) => $r['p90'], 'max' => fn ($r) => $r['max'],
            ], ['measured' => fn ($r) => $r['measured'], 'not confirmed' => fn ($r) => $r['unconfirmed']]],
            'seq' => [fn ($o) => $o['sequences']['hours'], [
                'avg' => fn ($r) => $r['pushed_to_meta']['avg'], 'p90' => fn ($r) => $r['pushed_to_meta']['p90'],
            ], ['steps' => fn ($r) => $r['pushed_to_meta']['n']]],
        ];
        $out = ['date' => $day['filters']['date'], 'prev_date' => $prev['filters']['date']];
        foreach ($defs as $key => [$rows, $series, $counts]) {
            foreach (['series' => $day, 'prev' => $prev] as $part => $o) {
                foreach ($series as $k => $get) $out[$key][$part][$k] = $col($rows($o), $get);
            }
            foreach (['counts' => $day, 'prev_counts' => $prev] as $part => $o) {
                foreach ($counts as $k => $get) $out[$key][$part][$k] = $col($rows($o), $get);
            }
            // Spec 188-1: ang average lang ang nakikita sa simula (ang iba ay nasa legend, nakatago), at ang taas ng axis
            // ay mula sa average para hindi mapatag ng isang biglang taas ang ibang oras.
            $shown = $key === 'ai' ? ['with_ai_avg', 'no_ai_avg'] : ['avg'];
            $out[$key]['shown'] = $shown;
            $out[$key]['top'] = self::axisTop(array_merge(...array_map(fn ($k) => $out[$key]['series'][$k], $shown)),
                array_merge(...array_map(fn ($k) => $out[$key]['prev'][$k], $shown)));
        }

        return $out;
    }

    /**
     * Ang taas ng y-axis ng isang chart: mga 3 × ang karaniwang average kada oras (ang median ng mga average ng mga oras na
     * may numero; ang sa naunang araw kapag wala pang 3 oras ngayon), hindi bababa sa 10 s, pataas sa malinis na numero
     * (1, 1.5, 2, 2.5, 3, 4, 5, 6, 8 × 10ⁿ). Ang value na lampas dito ay iginuguhit sa taas ng chart na may sariling label.
     */
    public static function axisTop(array $values, array $prevValues): int
    {
        $values = array_values(array_filter($values, fn ($v) => $v !== null));
        $prevValues = array_values(array_filter($prevValues, fn ($v) => $v !== null));
        if (count($values) < 3 && count($prevValues) > count($values)) $values = $prevValues;
        $want = 3 * (BotHealth::median($values) ?? 0);
        if ($want <= 10) return 10;
        $mag = 10 ** (int) floor(log10($want));
        foreach ([1, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10] as $step) {
            if ($step * $mag >= $want - 1e-9) return (int) round($step * $mag);
        }

        return (int) (10 * $mag);
    }

    /**
     * Ang chat na hindi iniimbak dahil naka-Off ang bot ng page (bilang lang, walang page, mga huling 3 araw lang): ang
     * dumating sa Botcake at sa Forbeast. null kapag walang bilang.
     */
    private function refusedOff(string $date): ?int
    {
        $n = (app(BotcakeChatGuard::class)->counts($date)['bot_off'] ?? 0) + (int) Cache::get('gateway-events:' . $date . ':bot_off', 0);

        return $n > 0 ? $n : null;
    }

    private function build(array $f, string $today): array
    {
        $start = $f['date'] . ' 00:00:00';
        $end = CarbonImmutable::parse($f['date'])->addDay()->toDateString() . ' 00:00:00';
        $spans = BotHealth::flowSpans();

        // Ang mga mensahe muna (para ang sagot lang ng mga customer na ito ang babasahin), sa magkakahiwalay na listahan ng
        // scalar para magaan ang buong araw: page:PSID, oras, oras sa araw, bot (true = Forbeast), nasa bot.
        [$keys, $ats, $hrs, $fbs, $ons] = [[], [], [], [], []];
        $chats = DB::table('botcake_chats')->where('received_at', '>=', $start)->where('received_at', '<', $end)
            ->when($f['page_id'] !== null, fn ($q) => $q->where('page_id', $f['page_id']))->select(['page_id', 'psid', 'received_at', 'payload'])->cursor();
        foreach ($chats as $c) {
            $payload = json_decode((string) $c->payload, true) ?: [];
            // Ang attachment lang ay sadyang hindi sinasagot; ang standby ay kopya ng Botcake sa page na nasa Forbeast.
            if (!empty($payload['_ignored']) || !empty($payload['_standby'])) continue;
            $keys[] = $c->page_id . ':' . $c->psid;
            $ats[] = strtotime($c->received_at);
            $hrs[] = (int) substr($c->received_at, 11, 2);
            $fbs[] = ($payload['source'] ?? null) === 'gateway';
            $ons[] = self::hadFlow($spans, (string) $c->page_id, $c->received_at);
        }
        $want = [];
        foreach ($keys as $i => $k) if ($ons[$i]) $want[$k] = true;
        $until = date('Y-m-d H:i:s', strtotime($end) + self::MAX_WAIT_SECONDS);
        // Habang binabasa ang mga padala: ang pushed → sent sa Meta ng mga sagot ng araw (parehong query, walang bago).
        $pushBlank = ['pushed' => 0, 'unconfirmed' => 0, 'botcake' => 0, 'secs' => []];
        $push = ['hours' => array_fill(0, 24, $pushBlank), 'total' => $pushBlank];
        $replies = $this->replies($start, $until, $f['page_id'], $want, function (object $r) use (&$push, $end, $f): void {
            if ($r->sent_at >= $end) return;
            $forbeast = ($r->via ?? null) === ChatbotProvider::FORBEAST;
            if ($f['bot'] !== null && $f['bot'] !== ChatbotConnections::BOTS[$forbeast ? 1 : 0]) return;
            $confirmed = in_array($r->confirmed_status ?? null, SendStatus::DELIVERED, true) && ($r->confirmed_at ?? null) !== null;
            foreach ([&$push['hours'][(int) substr($r->sent_at, 11, 2)], &$push['total']] as &$b) {
                // Ang Botcake ay walang oras ng Meta (sabay ang padala): bilang lang, hiwalay.
                if (!$forbeast) {
                    $b['botcake']++;
                    continue;
                }
                $b['pushed']++;
                if ($confirmed) $b['secs'][] = max(0, strtotime($r->confirmed_at) - strtotime($r->sent_at));
                else $b['unconfirmed']++;
            }
            unset($b);
        });
        unset($want);
        $ai = $this->aiTimes($start, $until, $f['page_id']);

        $blank = ['n' => 0, 'off' => 0, 'unconfirmed' => 0, 'secs' => [], 'ai' => [], 'flow' => [], 'ai_time' => []];
        $hours = array_fill(0, 24, $blank);
        $total = $blank;
        $byBot = array_fill_keys(ChatbotConnections::BOTS, $blank);
        $add = function (array &$b, bool $on, ?int $secs, bool $confirmed, ?int $chat) use ($ai): void {
            if (!$on) {
                $b['off']++;
                return;
            }
            $b['n']++;
            if ($secs === null) return;
            $b['secs'][] = $secs;
            if (!$confirmed) $b['unconfirmed']++;
            // May AI ang sagot kapag tumawag sa AI ang takbo ng chat nito; ang oras ng AI ay ang kabuuan ng mga sinagot na tawag.
            if ($chat !== null && array_key_exists($chat, $ai)) {
                $b['ai'][] = $secs;
                if ($ai[$chat] !== null) $b['ai_time'][] = $ai[$chat];
            } else {
                $b['flow'][] = $secs;
            }
        };
        foreach ($keys as $i => $key) {
            [$secs, $confirmed, $chat] = $ons[$i] ? self::firstReply($replies, $key, $ats[$i]) : [null, true, null];
            $bot = ChatbotConnections::BOTS[$fbs[$i] ? 1 : 0];
            $add($byBot[$bot], $ons[$i], $secs, $confirmed, $chat);
            if ($f['bot'] !== null && $f['bot'] !== $bot) continue;
            $add($hours[$hrs[$i]], $ons[$i], $secs, $confirmed, $chat);
            $add($total, $ons[$i], $secs, $confirmed, $chat);
        }

        $nowHour = $f['date'] === $today ? (int) now()->format('G') : 23;

        return [
            'filters' => $f,
            'hours' => array_map(fn ($h) => $h > $nowHour ? null : ['hour' => $h] + self::row($hours[$h]), range(0, 23)),
            'total' => self::row($total),
            'by_bot' => array_map(fn ($x) => self::row($x), $byBot),
            'push' => ['hours' => array_map(fn ($h) => $h > $nowHour ? null : ['hour' => $h] + self::pushRow($push['hours'][$h]), range(0, 23)),
                'total' => self::pushRow($push['total'])],
            'sequences' => $this->sequences($f, $start, $end, $nowHour),
        ];
    }

    /** Ang isang oras o ang araw ng pushed → sent sa Meta: pushed (Forbeast), measured, average, median, p90, max, unconfirmed, botcake. */
    private static function pushRow(array $b): array
    {
        $s = self::stats($b['secs']);

        return ['pushed' => $b['pushed'], 'measured' => $s['n'], 'avg' => $s['avg'], 'median' => $s['median'], 'p90' => $s['p90'], 'max' => $s['max'],
            'unconfirmed' => $b['unconfirmed'], 'botcake' => $b['botcake']];
    }

    /**
     * Ang segundo ng unang sagot kada page, para sa mga mensaheng dumating sa [$from, $to), parehong tuntunin ng screen
     * (nasa bot lang; hindi ang attachment na sadyang hindi sinasagot o ang kopya ng Botcake). Ang sagot ay hanggang $until
     * lang; ang hindi pa nasasagot ay wala sa listahan. Para sa banner ng mga problema ("mabagal").
     * @return array<string, int[]>
     */
    public function pageSeconds(string $from, string $to, string $until): array
    {
        $spans = BotHealth::flowSpans();
        [$keys, $ats] = [[], []];
        $chats = DB::table('botcake_chats')->where('received_at', '>=', $from)->where('received_at', '<', $to)->select(['page_id', 'psid', 'received_at', 'payload'])->cursor();
        foreach ($chats as $c) {
            $payload = json_decode((string) $c->payload, true) ?: [];
            if (!empty($payload['_ignored']) || !empty($payload['_standby']) || !self::hadFlow($spans, (string) $c->page_id, $c->received_at)) continue;
            $keys[] = [(string) $c->page_id, $c->page_id . ':' . $c->psid];
            $ats[] = strtotime($c->received_at);
        }
        $replies = $this->replies($from, $until, null, array_fill_keys(array_column($keys, 1), true));
        $out = [];
        foreach ($keys as $i => [$page, $key]) {
            [$secs] = self::firstReply($replies, $key, $ats[$i]);
            if ($secs !== null) $out[$page][] = $secs;
        }

        return $out;
    }

    private static function hadFlow(array $spans, string $page, string $at): bool
    {
        return isset($spans[$page]) && BotHealth::hadFlow($spans[$page], $at);
    }

    /**
     * Ang mga padala ng bot na naipadala (sent o partial; hindi ang test, pumalya, skipped o ang sequence step na hindi
     * sagot) sa mga customer na $want, kada page + PSID, ayon sa sent_at: ang sent_at (at), ang totoong oras (real), kung
     * kumpirmado (confirmed) at ang chat ng takbo (chat). Ang real, confirmed at chat ng bawat isa ay ang sa pinakamaagang
     * totoong oras mula rito pababa (sunod-sunod ang padala kada PSID, pero hindi umaasa rito). Mga scalar lang, para
     * magaan ang isang buong araw. $each: tinatawag sa bawat row ng query (lahat ng customer), para sa ibang bilang mula
     * sa parehong pagbasa.
     * @return array{at: array<string, int[]>, real: array<string, int[]>, confirmed: array<string, bool[]>, chat: array<string, array<int, ?int>>}
     */
    private function replies(string $startAt, string $endAt, ?string $page, array $want, ?\Closure $each = null): array
    {
        $out = ['at' => [], 'real' => [], 'confirmed' => [], 'chat' => []];
        if (!$want && $each === null) return $out;
        $confirm = ChatbotReply::hasConfirm();
        $cols = array_merge(['page_id', 'psid', 'chat_id', 'sent_at'], $confirm ? ['confirmed_at', 'confirmed_status'] : [], ChatbotReply::hasVia() ? ['via'] : []);
        $rows = DB::table('chatbot_replies')->where('kind', 'send')->whereIn('status', SendStatus::DELIVERED)
            ->where('sent_at', '>=', $startAt)->where('sent_at', '<', $endAt)
            ->when($page !== null, fn ($q) => $q->where('page_id', (int) $page))
            ->when(Schema::hasColumn('chatbot_replies', 'sequence_id'), fn ($q) => $q->whereNull('sequence_id'))
            ->orderBy('sent_at')->orderBy('id')->select($cols)->cursor();
        foreach ($rows as $r) {
            if ($each !== null) $each($r);
            $k = $r->page_id . ':' . $r->psid;
            if (!isset($want[$k])) continue;
            $real = $confirm && in_array($r->confirmed_status, SendStatus::DELIVERED, true) && $r->confirmed_at !== null;
            $out['at'][$k][] = strtotime($r->sent_at);
            $out['real'][$k][] = strtotime($real ? $r->confirmed_at : $r->sent_at);
            $out['confirmed'][$k][] = $real;
            $out['chat'][$k][] = $r->chat_id === null ? null : (int) $r->chat_id;
        }
        foreach ($out['real'] as $k => $real) {
            for ($i = count($real) - 2; $i >= 0; $i--) {
                if ($real[$i + 1] < $real[$i]) {
                    $real[$i] = $real[$i + 1];
                    $out['confirmed'][$k][$i] = $out['confirmed'][$k][$i + 1];
                    $out['chat'][$k][$i] = $out['chat'][$k][$i + 1];
                }
            }
            $out['real'][$k] = $real;
        }

        return $out;
    }

    /**
     * Ang mga chat na tumawag sa AI ang takbo (chat ID => kabuuang segundo ng mga sinagot na tawag, created → answered;
     * null kapag walang sinagot). Gaya ng Bot Health: hindi kasama ang hinihintay ng Encode order at ang sync na record.
     * @return array<int, ?int>
     */
    private function aiTimes(string $startAt, string $endAt, ?string $page): array
    {
        if (!Schema::hasTable('chatbot_ai_requests')) return [];
        $out = [];
        $rows = DB::table('chatbot_ai_requests')->where('created_at', '>=', $startAt)->where('created_at', '<', $endAt)->whereNotNull('chat_id')
            ->where('model', '<>', \App\Services\OrderEncode\FlowEncode::MODEL)->where('status', '<>', 'sync')
            ->when($page !== null, fn ($q) => $q->where('page_id', (int) $page))->select(['chat_id', 'created_at', 'answered_at'])->cursor();
        foreach ($rows as $r) {
            $id = (int) $r->chat_id;
            $out[$id] ??= null;
            if ($r->answered_at !== null) $out[$id] = ($out[$id] ?? 0) + max(0, strtotime($r->answered_at) - strtotime($r->created_at));
        }

        return $out;
    }

    /** Ang segundo hanggang sa unang naipadalang sagot pagkatapos ng $at, kung kumpirmado ang oras, at ang chat ng takbo nito. */
    private static function firstReply(array $replies, string $key, int $at): array
    {
        $times = $replies['at'][$key] ?? [];
        [$lo, $hi] = [0, count($times)];
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            $times[$mid] >= $at ? $hi = $mid : $lo = $mid + 1;
        }
        if ($lo === count($times)) return [null, true, null];
        $secs = max(0, $replies['real'][$key][$lo] - $at);

        return $secs > self::MAX_WAIT_SECONDS ? [null, true, null] : [$secs, $replies['confirmed'][$key][$lo], $replies['chat'][$key][$lo]];
    }

    /** Bilang, average, median, p90 at max ng mga segundo. */
    private static function stats(array $v): array
    {
        return ['n' => count($v), 'avg' => $v ? round(array_sum($v) / count($v), 1) : null, 'median' => BotHealth::median($v),
            'p90' => BotHealth::percentile($v, 90), 'max' => $v ? max($v) : null];
    }

    /** Ang mga numero ng isang oras, araw o bot, mula sa bilang nito (n: nasa bot, off: wala sa bot) at sa mga segundo ng nasagot. */
    private static function row(array $b): array
    {
        $secs = $b['secs'];
        $n = $b['n'];
        $out = ['messages' => $n, 'answered' => count($secs), 'unanswered' => $n - count($secs)]
            + array_intersect_key(self::stats($secs), ['avg' => 1, 'median' => 1, 'p90' => 1, 'max' => 1]);
        foreach (self::WITHIN as $s) {
            $out["within_$s"] = count(array_filter($secs, fn ($v) => $v <= $s));
            $out["pct_$s"] = $n > 0 ? round(100 * $out["within_$s"] / $n, 1) : null;
        }
        $t = self::stats($b['ai_time']);

        return $out + ['not_on_bot' => $b['off'], 'unconfirmed' => $b['unconfirmed'], 'with_ai' => self::stats($b['ai']), 'no_ai' => self::stats($b['flow']),
            'ai_time' => ['avg' => $t['avg'], 'p90' => $t['p90'], 'max' => $t['max']]];
    }

    // ── mga sequence ──────────────────────────────────────────────────────────────────────────────────────────────

    /** Ang padala ng step ay bago ang event ng takbo nito, sa loob nito (ang event ay isinusulat pagkatapos ng takbo). */
    public const STEP_PUSH_SECONDS = 600;
    /** Ang step na due bago maghatinggabi pero tumakbo pagkatapos (naantala o held) ay sa araw pa rin ng due. */
    public const STEP_LATE_SECONDS = 21600;
    public const SLOWEST = 20;

    /**
     * Ang mga step ng sequence ayon sa oras na due: ang due ay ang sequence event (ran, skipped, failed) na may "due" (ang
     * next_send_at noong tumakbo; ang lumang event na wala nito ay sa oras ng event, at walang scheduled → pushed). Ang
     * pushed ay ang sent_at ng unang padala ng step sa Botcake o Forbeast; ang sent ay ang oras ng Meta mula sa send_status
     * (Forbeast). Ang Botcake ay sabay, kaya ang sent nito ay ang pushed (minarkahan, at wala sa pushed → Meta).
     */
    private function sequences(array $f, string $start, string $end, int $nowHour): array
    {
        $blank = ['due' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'none' => 0, 'botcake' => 0, 'push' => [], 'meta' => []];
        $hours = array_fill(0, 24, $blank);
        $total = $blank;
        $slow = [];
        $late = date('Y-m-d H:i:s', strtotime($end) + self::STEP_LATE_SECONDS);
        $events = DB::table('chatbot_events')->where('type', 'sequence')->where('created_at', '>=', $start)->where('created_at', '<', $late)
            ->when($f['page_id'] !== null, fn ($q) => $q->where('page_id', (int) $f['page_id']))->get(['page_id', 'psid', 'detail', 'created_at'])
            ->map(fn ($e) => [$e, json_decode((string) $e->detail, true)])->filter(fn ($x) => is_array($x[1]) && in_array($x[1]['op'] ?? null, ['ran', 'skipped', 'failed'], true));
        $sends = $this->stepSends(date('Y-m-d H:i:s', strtotime($start) - self::STEP_PUSH_SECONDS), $late, $f['page_id']);
        $botOf = $f['bot'] !== null ? BotHealth::botOfPages() : [];
        $names = \App\Models\BotcakeChat::pageMap();
        [$before, $delays] = $this->stepHistory($events->all(), $late);

        foreach ($events as [$e, $d]) {
            $due = self::originalDue($e, $d, $before, $delays);
            $at = $due ?? (string) $e->created_at;
            if ($at < $start || $at >= $end) continue;
            if ($f['bot'] !== null && ($botOf[(string) $e->page_id] ?? ChatbotConnections::BOTS[0]) !== $f['bot']) continue;

            $x = ['op' => $d['op'], 'push' => null, 'meta' => null, 'botcake' => false];
            $row = null;
            if ($d['op'] === 'ran') {
                $row = self::stepSend($sends[$e->page_id . ':' . $e->psid . ':' . (int) ($d['step'] ?? 0)] ?? [], $d['sequence_id'] ?? null, strtotime((string) $e->created_at));
                $x['op'] = $row === null ? 'none' : ($row['delivered'] ? 'sent' : 'failed');
            }
            $h = &$hours[(int) substr($at, 11, 2)];
            foreach ([&$h, &$total] as &$b) {
                $b['due']++;
                if (isset($b[$x['op']])) $b[$x['op']]++;
                if ($x['op'] !== 'sent') continue;
                if ($due !== null) $b['push'][] = max(0, $row['pushed'] - strtotime($due));
                if ($row['meta'] !== null) $b['meta'][] = max(0, $row['meta'] - $row['pushed']);
                if ($row['botcake']) $b['botcake']++;
            }
            unset($b, $h);
            if ($x['op'] === 'sent') {
                $sent = $row['meta'] ?? ($row['botcake'] ? $row['pushed'] : null);
                $slow[] = ['page' => $names[(string) $e->page_id] ?? 'Page ' . $e->page_id, 'sequence' => mb_substr((string) ($d['name'] ?? ''), 0, 120), 'step' => (int) ($d['step'] ?? 0),
                    'scheduled' => $due, 'pushed' => date('Y-m-d H:i:s', $row['pushed']), 'sent' => $sent === null ? null : date('Y-m-d H:i:s', $sent),
                    'scheduled_to_pushed' => $due === null ? null : max(0, $row['pushed'] - strtotime($due)),
                    'pushed_to_meta' => $row['meta'] === null ? null : max(0, $row['meta'] - $row['pushed']),
                    'delay' => max(0, ($sent ?? $row['pushed']) - strtotime($due ?? date('Y-m-d H:i:s', $row['pushed']))), 'sent_is_pushed' => $row['botcake']];
            }
        }
        usort($slow, fn ($a, $b) => [$b['delay'], $b['pushed']] <=> [$a['delay'], $a['pushed']]);

        $out = fn ($b) => ['due' => $b['due'], 'sent' => $b['sent'], 'failed' => $b['failed'], 'skipped' => $b['skipped'], 'no_message' => $b['none'], 'botcake' => $b['botcake'],
            'scheduled_to_pushed' => self::delayStats($b['push']), 'pushed_to_meta' => self::delayStats($b['meta'])];

        return ['hours' => array_map(fn ($h) => $h > $nowHour ? null : ['hour' => $h] + $out($hours[$h]), range(0, 23)), 'total' => $out($total),
            'slowest' => array_slice($slow, 0, self::SLOWEST)];
    }

    /**
     * Para sa orihinal na due ng bawat step: ang mga naunang sequence event ng parehong customer at sequence (subscribed,
     * ran, skipped, failed: ang mga sandaling itinakda ng subscribe at ng advance ang next_send_at), kada page:PSID:pangalan,
     * ayon sa oras; at ang delay ng bawat step (sequence ID:position => [amount, unit]).
     * @return array{0: array<string, string[]>, 1: array<string, array{0: int, 1: string}>}
     */
    private function stepHistory(array $events, string $until): array
    {
        $before = [];
        $pages = array_values(array_unique(array_map(fn ($x) => (int) $x[0]->page_id, $events)));
        $psids = array_values(array_unique(array_map(fn ($x) => (int) $x[0]->psid, $events)));
        foreach (array_chunk($psids, 500) as $chunk) {
            $rows = DB::table('chatbot_events')->where('type', 'sequence')->whereIn('page_id', $pages)->whereIn('psid', $chunk)->where('created_at', '<', $until)
                ->orderBy('created_at')->orderBy('id')->get(['page_id', 'psid', 'detail', 'created_at']);
            foreach ($rows as $r) {
                $d = json_decode((string) $r->detail, true);
                if (!is_array($d) || !is_string($d['name'] ?? null) || !in_array($d['op'] ?? null, ['subscribed', 'ran', 'skipped', 'failed'], true)) continue;
                $before[$r->page_id . ':' . $r->psid . ':' . $d['name']][] = (string) $r->created_at;
            }
        }
        $delays = [];
        if ($events && Schema::hasTable('chatbot_sequence_steps')) {
            $ids = DB::table('chatbot_sequences')->whereIn('name', array_values(array_unique(array_map(fn ($x) => (string) ($x[1]['name'] ?? ''), $events))))->pluck('id', 'name');
            foreach (DB::table('chatbot_sequence_steps')->whereIn('sequence_id', $ids->values()->all())->get(['sequence_id', 'position', 'delay_amount', 'delay_unit']) as $s) {
                $delays[$s->sequence_id . ':' . $s->position] = [(int) $s->delay_amount, (string) $s->delay_unit];
            }
            foreach ($ids as $name => $id) $delays['name:' . $name] = (int) $id;
        }

        return [$before, $delays];
    }

    /**
     * Ang orihinal na due ng step: ang delay nito mula sa naunang event ng customer sa sequence (gaya ng kinuwenta ng
     * subscribe at advance), dahil ang next_send_at ay inuusog ng retry (lock, AI na hinihintay, bot paused). Kapag walang
     * naunang event o step (luma o binura), ang "due" ng event; kung wala rin, null.
     */
    private static function originalDue(object $e, array $d, array $before, array $delays): ?string
    {
        $recorded = is_string($d['due'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $d['due']) ? $d['due'] : null;
        $name = (string) ($d['name'] ?? '');
        $id = is_int($d['sequence_id'] ?? null) ? $d['sequence_id'] : ($delays['name:' . $name] ?? null);
        $delay = $id !== null ? ($delays[$id . ':' . (int) ($d['step'] ?? 0)] ?? null) : null;
        $prev = null;
        foreach ($before[$e->page_id . ':' . $e->psid . ':' . $name] ?? [] as $t) {
            if ($t >= (string) $e->created_at) break;
            $prev = $t;
        }
        if ($delay === null || $prev === null) return $recorded;

        return ChatbotSequences::dueAt(\Carbon\Carbon::parse($prev), $delay[0], $delay[1])->format('Y-m-d H:i:s');
    }

    /**
     * Ang mga padala ng sequence step (kind send), kada page:PSID:step, ayon sa sent_at.
     * @return array<string, array<int, array{at: int, seq: int, delivered: bool, meta: ?int, botcake: bool}>>
     */
    private function stepSends(string $startAt, string $endAt, ?string $page): array
    {
        if (!Schema::hasColumn('chatbot_replies', 'sequence_id')) return [];
        $confirm = ChatbotReply::hasConfirm();
        $via = ChatbotReply::hasVia();
        $cols = array_merge(['page_id', 'psid', 'sequence_id', 'sequence_step', 'status', 'sent_at'], $confirm ? ['confirmed_at', 'confirmed_status'] : [], $via ? ['via'] : []);
        $out = [];
        $rows = DB::table('chatbot_replies')->where('kind', 'send')->whereNotNull('sequence_id')->where('sent_at', '>=', $startAt)->where('sent_at', '<', $endAt)
            ->when($page !== null, fn ($q) => $q->where('page_id', (int) $page))->orderBy('sent_at')->orderBy('id')->select($cols)->cursor();
        foreach ($rows as $r) {
            $real = $confirm && in_array($r->confirmed_status, SendStatus::DELIVERED, true) && $r->confirmed_at !== null;
            $botcake = ($via ? $r->via : null) !== ChatbotProvider::FORBEAST;
            $out[$r->page_id . ':' . $r->psid . ':' . (int) $r->sequence_step][] = ['at' => strtotime($r->sent_at), 'seq' => (int) $r->sequence_id,
                'delivered' => in_array($r->status, SendStatus::DELIVERED, true), 'meta' => $real && !$botcake ? strtotime($r->confirmed_at) : null, 'botcake' => $botcake];
        }

        return $out;
    }

    /**
     * Ang padala ng isang takbo ng step: ang mga padala ng step sa loob ng STEP_PUSH_SECONDS bago ang event. pushed = ang
     * unang padala; ang sent sa Meta ay ang sa unang naipadala. null kapag walang padala.
     * @return array{pushed: int, delivered: bool, meta: ?int, botcake: bool}|null
     */
    private static function stepSend(array $sends, mixed $sequenceId, int $ranAt): ?array
    {
        $mine = array_values(array_filter($sends, fn ($s) => $s['at'] <= $ranAt && $s['at'] >= $ranAt - self::STEP_PUSH_SECONDS
            && (!is_int($sequenceId) || $s['seq'] === $sequenceId)));
        if (!$mine) return null;
        $first = current(array_filter($mine, fn ($s) => $s['delivered'])) ?: null;

        return ['pushed' => $mine[0]['at'], 'delivered' => $first !== null, 'meta' => $first['meta'] ?? null, 'botcake' => $first['botcake'] ?? $mine[0]['botcake']];
    }

    /** Bilang, average, p90 at max ng mga delay (segundo). */
    private static function delayStats(array $v): array
    {
        $s = self::stats($v);

        return ['n' => $s['n'], 'avg' => $s['avg'], 'p90' => $s['p90'], 'max' => $s['max']];
    }
}

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
        $ttl = $f['date'] === $today ? self::TODAY_CACHE_SECONDS : self::PAST_CACHE_SECONDS;
        $out = Cache::remember('response-speed:' . md5(json_encode($f)), $ttl, fn () => $this->build($f, $today));
        $day = CarbonImmutable::parse($f['date']);

        return $out + ['dates' => ['today' => $today, 'yesterday' => CarbonImmutable::parse($today)->subDay()->toDateString(),
            'prev' => $day->subDay()->toDateString(), 'next' => $f['date'] < $today ? $day->addDay()->toDateString() : null],
            'refused_off' => $f['page_id'] === null && $f['bot'] === null ? $this->refusedOff($f['date']) : null];
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
        $replies = $this->replies($start, date('Y-m-d H:i:s', strtotime($end) + self::MAX_WAIT_SECONDS), $f['page_id'], $want);
        unset($want);

        $blank = ['n' => 0, 'off' => 0, 'unconfirmed' => 0, 'secs' => []];
        $hours = array_fill(0, 24, $blank);
        $total = $blank;
        $byBot = array_fill_keys(ChatbotConnections::BOTS, $blank);
        $add = function (array &$b, bool $on, ?int $secs, bool $confirmed): void {
            if (!$on) {
                $b['off']++;
                return;
            }
            $b['n']++;
            if ($secs === null) return;
            $b['secs'][] = $secs;
            if (!$confirmed) $b['unconfirmed']++;
        };
        foreach ($keys as $i => $key) {
            [$secs, $confirmed] = $ons[$i] ? self::firstReply($replies, $key, $ats[$i]) : [null, true];
            $bot = ChatbotConnections::BOTS[$fbs[$i] ? 1 : 0];
            $add($byBot[$bot], $ons[$i], $secs, $confirmed);
            if ($f['bot'] !== null && $f['bot'] !== $bot) continue;
            $add($hours[$hrs[$i]], $ons[$i], $secs, $confirmed);
            $add($total, $ons[$i], $secs, $confirmed);
        }

        $nowHour = $f['date'] === $today ? (int) now()->format('G') : 23;

        return [
            'filters' => $f,
            'hours' => array_map(fn ($h) => $h > $nowHour ? null : ['hour' => $h] + self::row($hours[$h]), range(0, 23)),
            'total' => self::row($total),
            'by_bot' => array_map(fn ($x) => self::row($x), $byBot),
        ];
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
     * sagot) sa mga customer na $want, kada page + PSID, ayon sa sent_at: ang sent_at (at), ang totoong oras (real) at
     * kung kumpirmado (confirmed). Ang real at confirmed ng bawat isa ay ang pinakamaaga mula rito pababa (sunod-sunod ang
     * padala kada PSID, pero hindi umaasa rito). Mga int at bool lang, para magaan ang isang buong araw.
     * @return array{at: array<string, int[]>, real: array<string, int[]>, confirmed: array<string, bool[]>}
     */
    private function replies(string $startAt, string $endAt, ?string $page, array $want): array
    {
        $out = ['at' => [], 'real' => [], 'confirmed' => []];
        if (!$want) return $out;
        $confirm = ChatbotReply::hasConfirm();
        $cols = array_merge(['page_id', 'psid', 'sent_at'], $confirm ? ['confirmed_at', 'confirmed_status'] : []);
        $rows = DB::table('chatbot_replies')->where('kind', 'send')->whereIn('status', SendStatus::DELIVERED)
            ->where('sent_at', '>=', $startAt)->where('sent_at', '<', $endAt)
            ->when($page !== null, fn ($q) => $q->where('page_id', (int) $page))
            ->when(Schema::hasColumn('chatbot_replies', 'sequence_id'), fn ($q) => $q->whereNull('sequence_id'))
            ->orderBy('sent_at')->orderBy('id')->select($cols)->cursor();
        foreach ($rows as $r) {
            $k = $r->page_id . ':' . $r->psid;
            if (!isset($want[$k])) continue;
            $real = $confirm && in_array($r->confirmed_status, SendStatus::DELIVERED, true) && $r->confirmed_at !== null;
            $out['at'][$k][] = strtotime($r->sent_at);
            $out['real'][$k][] = strtotime($real ? $r->confirmed_at : $r->sent_at);
            $out['confirmed'][$k][] = $real;
        }
        foreach ($out['real'] as $k => $real) {
            for ($i = count($real) - 2; $i >= 0; $i--) {
                if ($real[$i + 1] < $real[$i]) {
                    $real[$i] = $real[$i + 1];
                    $out['confirmed'][$k][$i] = $out['confirmed'][$k][$i + 1];
                }
            }
            $out['real'][$k] = $real;
        }

        return $out;
    }

    /** Ang segundo hanggang sa unang naipadalang sagot pagkatapos ng $at, at kung kumpirmado ang oras; [null, true] kapag wala. */
    private static function firstReply(array $replies, string $key, int $at): array
    {
        $times = $replies['at'][$key] ?? [];
        [$lo, $hi] = [0, count($times)];
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            $times[$mid] >= $at ? $hi = $mid : $lo = $mid + 1;
        }
        if ($lo === count($times)) return [null, true];
        $secs = max(0, $replies['real'][$key][$lo] - $at);

        return $secs > self::MAX_WAIT_SECONDS ? [null, true] : [$secs, $replies['confirmed'][$key][$lo]];
    }

    /** Ang mga numero ng isang oras, araw o bot, mula sa bilang nito (n: nasa bot, off: wala sa bot) at sa mga segundo ng nasagot. */
    private static function row(array $b): array
    {
        $secs = $b['secs'];
        $n = $b['n'];
        $out = ['messages' => $n, 'answered' => count($secs), 'unanswered' => $n - count($secs),
            'avg' => $secs ? round(array_sum($secs) / count($secs), 1) : null, 'median' => BotHealth::median($secs), 'p90' => BotHealth::percentile($secs, 90)];
        foreach (self::WITHIN as $s) {
            $out["within_$s"] = count(array_filter($secs, fn ($v) => $v <= $s));
            $out["pct_$s"] = $n > 0 ? round(100 * $out["within_$s"] / $n, 1) : null;
        }

        return $out + ['not_on_bot' => $b['off'], 'unconfirmed' => $b['unconfirmed']];
    }
}

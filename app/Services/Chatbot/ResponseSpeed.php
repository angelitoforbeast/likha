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
        $firsts = $this->replies($start, date('Y-m-d H:i:s', strtotime($end) + self::MAX_WAIT_SECONDS), $f['page_id']);
        $spans = BotHealth::flowSpans();

        $hours = array_fill(0, 24, []);
        $total = [];
        $byBot = array_fill_keys(ChatbotConnections::BOTS, []);
        $chats = DB::table('botcake_chats')->where('received_at', '>=', $start)->where('received_at', '<', $end)
            ->when($f['page_id'] !== null, fn ($q) => $q->where('page_id', $f['page_id']))->select(['page_id', 'psid', 'received_at', 'payload'])->cursor();
        foreach ($chats as $c) {
            $payload = json_decode((string) $c->payload, true) ?: [];
            // Ang attachment lang ay sadyang hindi sinasagot; ang standby ay kopya ng Botcake sa page na nasa Forbeast.
            if (!empty($payload['_ignored']) || !empty($payload['_standby'])) continue;
            $bot = ($payload['source'] ?? null) === 'gateway' ? ChatbotConnections::BOTS[1] : ChatbotConnections::BOTS[0];
            $x = ['on' => self::hadFlow($spans, (string) $c->page_id, $c->received_at), 'secs' => null, 'confirmed' => true];
            if ($x['on']) [$x['secs'], $x['confirmed']] = self::firstReply($firsts[$c->page_id . ':' . $c->psid] ?? null, strtotime($c->received_at));
            $byBot[$bot][] = $x;
            if ($f['bot'] !== null && $f['bot'] !== $bot) continue;
            $hours[(int) substr($c->received_at, 11, 2)][] = $x;
            $total[] = $x;
        }

        $nowHour = $f['date'] === $today ? (int) now()->format('G') : 23;

        return [
            'filters' => $f,
            'hours' => array_map(fn ($h) => $h > $nowHour ? null : ['hour' => $h] + self::row($hours[$h]), range(0, 23)),
            'total' => self::row($total),
            'by_bot' => array_map(fn ($x) => self::row($x), $byBot),
        ];
    }

    private static function hadFlow(array $spans, string $page, string $at): bool
    {
        return isset($spans[$page]) && BotHealth::hadFlow($spans[$page], $at);
    }

    /**
     * Ang mga padala ng bot na naipadala (sent o partial; hindi ang test, pumalya, skipped o ang sequence step na hindi
     * sagot), kada page + PSID: [sent_at, totoong oras, kumpirmado], ayon sa sent_at, at ang pinakamaagang totoong oras
     * mula sa bawat isa pababa (sunod-sunod ang padala kada PSID, pero hindi umaasa rito).
     * @return array<string, array{at: int[], best: array<int, array{0: int, 1: bool}>}>
     */
    private function replies(string $startAt, string $endAt, ?string $page): array
    {
        $confirm = ChatbotReply::hasConfirm();
        $cols = array_merge(['page_id', 'psid', 'sent_at'], $confirm ? ['confirmed_at', 'confirmed_status'] : []);
        $rows = DB::table('chatbot_replies')->where('kind', 'send')->whereIn('status', SendStatus::DELIVERED)
            ->where('sent_at', '>=', $startAt)->where('sent_at', '<', $endAt)
            ->when($page !== null, fn ($q) => $q->where('page_id', (int) $page))
            ->when(Schema::hasColumn('chatbot_replies', 'sequence_id'), fn ($q) => $q->whereNull('sequence_id'))
            ->orderBy('sent_at')->orderBy('id')->select($cols)->cursor();
        $by = [];
        foreach ($rows as $r) {
            $real = $confirm && in_array($r->confirmed_status, SendStatus::DELIVERED, true) && $r->confirmed_at !== null;
            $k = $r->page_id . ':' . $r->psid;
            $by[$k]['at'][] = strtotime($r->sent_at);
            $by[$k]['real'][] = [strtotime($real ? $r->confirmed_at : $r->sent_at), $real];
        }
        foreach ($by as &$b) {
            $best = $b['real'];
            for ($i = count($best) - 2; $i >= 0; $i--) if ($best[$i + 1][0] < $best[$i][0]) $best[$i] = $best[$i + 1];
            $b = ['at' => $b['at'], 'best' => $best];
        }
        unset($b);

        return $by;
    }

    /** Ang segundo hanggang sa unang naipadalang sagot pagkatapos ng $at, at kung kumpirmado ang oras; [null, true] kapag wala. */
    private static function firstReply(?array $r, int $at): array
    {
        if ($r === null) return [null, true];
        [$lo, $hi] = [0, count($r['at'])];
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            $r['at'][$mid] >= $at ? $hi = $mid : $lo = $mid + 1;
        }
        if ($lo === count($r['at'])) return [null, true];
        [$sent, $real] = $r['best'][$lo];
        $secs = max(0, $sent - $at);

        return $secs > self::MAX_WAIT_SECONDS ? [null, true] : [$secs, $real];
    }

    /** Ang mga numero ng isang oras, araw o bot. */
    private static function row(array $items): array
    {
        $on = array_filter($items, fn ($x) => $x['on']);
        $answered = array_filter($on, fn ($x) => $x['secs'] !== null);
        $secs = array_column($answered, 'secs');
        $n = count($on);
        $out = ['messages' => $n, 'answered' => count($secs), 'unanswered' => $n - count($secs),
            'avg' => $secs ? round(array_sum($secs) / count($secs), 1) : null, 'median' => BotHealth::median($secs), 'p90' => BotHealth::percentile($secs, 90)];
        foreach (self::WITHIN as $s) {
            $out["within_$s"] = count(array_filter($secs, fn ($v) => $v <= $s));
            $out["pct_$s"] = $n > 0 ? round(100 * $out["within_$s"] / $n, 1) : null;
        }

        return $out + ['not_on_bot' => count($items) - $n, 'unconfirmed' => count(array_filter($answered, fn ($x) => !$x['confirmed']))];
    }
}

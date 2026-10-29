<?php

namespace App\Services\Chatbot;

use App\Models\BotcakeChat;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * /chatbot/live: ang ginagawa ng bot ngayon, para sa animated na mapa. Dalawang bahagi:
 * - ang mga bilang ngayong araw (Manila) at sa huling 5 minuto: mensahe, sagot na kumpirmado ng Meta (at ang naipadala
 *   pero hindi pa kumpirmado), follow-up (sequence), sagot ng AI at order; naka-cache 10 segundo kada page filter;
 * - ang mga bagong event mula sa cursor na ipinadala ng page, kada pinagmulan: id ng botcake_chats (message), id ng
 *   chatbot_replies (follow_up), id ng chatbot_events na order (order), at (oras, id) ng kumpirmasyon ng Meta (confirmed)
 *   at ng sagot ng AI (ai), dahil ang dalawang iyon ay nangyayari pagkatapos maisulat ang row.
 * Ang event ay type, oras, pangalan ng page at id lang: walang pangalan o mensahe ng customer. Bawat query ay may id o
 * oras na naka-index, sa huling 15 minuto lang; hanggang 200 event kada poll, ang sobra ay bilang lang ("+N more").
 */
class LiveFeed
{
    public const CACHE_SECONDS = 10;
    public const RECENT_SECONDS = 300;
    /** Ang event ay hinahanap sa mga row ng huling 15 minuto lang (ang lumang cursor ay hindi nagbabasa ng buong table). */
    public const WINDOW_SECONDS = 900;
    public const CAP = 200;
    public const TYPES = ['message', 'confirmed', 'follow_up', 'ai', 'order'];
    /** Ang cursor ng bawat pinagmulan: id lang, o oras|id. */
    public const CURSORS = ['chat' => 'id', 'seq' => 'id', 'order' => 'id', 'ok' => 'moment', 'ai' => 'moment'];

    /** Ang page filter: page ID na digits lang, o null (lahat). */
    public static function page(array $q): ?string
    {
        $p = $q['page_id'] ?? null;

        return is_string($p) && ctype_digit($p) && strlen($p) <= 30 ? $p : null;
    }

    /** Ang buong sagot ng endpoint: bilang, mga bagong event mula sa cursor, ilan pa ang hindi isinama, at ang bagong cursor. */
    public function feed(array $q): array
    {
        $page = self::page($q);
        [$events, $more, $cursor] = $this->events($page, is_array($q['cursor'] ?? null) ? $q['cursor'] : []);

        return ['counts' => $this->counts($page), 'events' => $events, 'more' => $more, 'cursor' => $cursor, 'now' => now()->format('H:i:s')];
    }

    // ── mga bilang ─────────────────────────────────────────────────────────────────────────────────────────────────

    /** Ang mga bilang ngayong araw at sa huling 5 minuto, naka-cache 10 segundo kada page. */
    public function counts(?string $page): array
    {
        return Cache::remember('chatbot-live:v1:' . ($page ?? 'all'), self::CACHE_SECONDS, fn () => $this->buildCounts($page));
    }

    private function buildCounts(?string $page): array
    {
        $now = now();
        $start = $now->copy()->startOfDay()->format('Y-m-d H:i:s');
        $end = $now->copy()->startOfDay()->addDay()->format('Y-m-d H:i:s');
        $recent = $now->copy()->subSeconds(self::RECENT_SECONDS)->format('Y-m-d H:i:s');
        // Ang huling 5 minuto ay hindi pinuputol ng hatinggabi: ang range ay mula sa mas maaga sa dalawa.
        $from = min($start, $recent);
        $pair = fn (string $cond) => "SUM(CASE WHEN {$cond} AND %s >= ? THEN 1 ELSE 0 END) AS %s";
        $int = fn ($v) => (int) ($v ?? 0);

        $m = DB::table('botcake_chats')->where('received_at', '>=', $from)->where('received_at', '<', $end)->whereNull('payload->_standby')
            ->when($page !== null, fn ($q) => $q->where('page_id', $page))
            ->selectRaw('SUM(CASE WHEN received_at >= ? THEN 1 ELSE 0 END) AS today, SUM(CASE WHEN received_at >= ? THEN 1 ELSE 0 END) AS recent', [$start, $recent])
            ->first();

        // Ang sagot ng bot (hindi sequence) na kumpirmado ng Meta (confirmed_status sent/partial na may oras: ang Botcake ay
        // kumpirmado na sa padala, ang Forbeast ay sa send_status), ang naipadala pero hinihintay pa ang kumpirmasyon, at
        // ang naipadalang sequence step (follow-up). Ayon sa sent_at (naka-index).
        $delivered = "status IN ('" . implode("','", SendStatus::DELIVERED) . "')";
        $ok = "sequence_id IS NULL AND {$delivered} AND confirmed_status IN ('" . implode("','", SendStatus::DELIVERED) . "') AND confirmed_at IS NOT NULL";
        $wait = "sequence_id IS NULL AND {$delivered} AND confirmed_status IS NULL";
        $seq = "sequence_id IS NOT NULL AND {$delivered}";
        $sql = [];
        $bind = [];
        foreach (['confirmed' => $ok, 'unconfirmed' => $wait, 'follow_ups' => $seq] as $key => $cond) {
            $sql[] = sprintf($pair($cond), 'sent_at', $key);
            $sql[] = sprintf($pair($cond), 'sent_at', $key . '_recent');
            array_push($bind, $start, $recent);
        }
        $r = DB::table('chatbot_replies')->where('kind', 'send')->where('sent_at', '>=', $from)->where('sent_at', '<', $end)
            ->when($page !== null, fn ($q) => $q->where('page_id', (int) $page))->selectRaw(implode(', ', $sql), $bind)->first();

        // Ang sagot ng AI: ang tawag na may sagot (answered_at, walang error), hindi ang hinihintay ng Encode order. Ngayong
        // araw ayon sa created_at (naka-index); ang huling 5 minuto ayon sa oras ng sagot.
        $a = DB::table('chatbot_ai_requests')->where('created_at', '>=', min($from, $now->copy()->subSeconds(self::RECENT_SECONDS + self::WINDOW_SECONDS)->format('Y-m-d H:i:s')))
            ->where('created_at', '<', $end)->whereNotNull('answered_at')->whereNull('error')->where('model', '<>', \App\Services\OrderEncode\FlowEncode::MODEL)
            ->when($page !== null, fn ($q) => $q->where('page_id', (int) $page))
            ->selectRaw('SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) AS today, SUM(CASE WHEN answered_at >= ? THEN 1 ELSE 0 END) AS recent', [$start, $recent])
            ->first();

        // Ang order na naitala ng bot ("Counts as an order"), gaya ng Results at Bot Health.
        $o = DB::table('chatbot_events')->where('type', 'order')->where('created_at', '>=', $from)->where('created_at', '<', $end)
            ->when($page !== null, fn ($q) => $q->where('page_id', (int) $page))
            ->selectRaw('SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) AS today, SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) AS recent', [$start, $recent])
            ->first();

        return [
            'messages' => ['today' => $int($m->today), 'recent' => $int($m->recent)],
            'confirmed' => ['today' => $int($r->confirmed), 'recent' => $int($r->confirmed_recent)],
            'unconfirmed' => ['today' => $int($r->unconfirmed), 'recent' => $int($r->unconfirmed_recent)],
            'follow_ups' => ['today' => $int($r->follow_ups), 'recent' => $int($r->follow_ups_recent)],
            'ai' => ['today' => $int($a->today), 'recent' => $int($a->recent)],
            'orders' => ['today' => $int($o->today), 'recent' => $int($o->recent)],
            'as_of' => $now->format('H:i:s'),
        ];
    }

    // ── mga event ──────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Ang mga pinagmulan ng event: type, ang query (may page filter at sa huling 15 minuto), ang column ng oras ng event,
     * at ang column ng page.
     * @return array<string, array{0: string, 1: Builder, 2: string}>
     */
    private function sources(?string $page, string $since): array
    {
        $pid = fn (Builder $q) => $page === null ? $q : $q->where('page_id', $q->from === 'botcake_chats' ? $page : (int) $page);
        $delivered = SendStatus::DELIVERED;

        return [
            'chat' => ['message', $pid(DB::table('botcake_chats')->where('received_at', '>=', $since)->whereNull('payload->_standby')), 'received_at'],
            'seq' => ['follow_up', $pid(DB::table('chatbot_replies')->where('sent_at', '>=', $since)->where('kind', 'send')->whereNotNull('sequence_id')->whereIn('status', $delivered)), 'sent_at'],
            'order' => ['order', $pid(DB::table('chatbot_events')->where('type', 'order')->where('created_at', '>=', $since)), 'created_at'],
            'ok' => ['confirmed', $pid(DB::table('chatbot_replies')->where('sent_at', '>=', $since)->where('kind', 'send')->whereNull('sequence_id')->whereIn('status', $delivered)
                ->whereIn('confirmed_status', $delivered)->whereNotNull('confirmed_at')), 'confirmed_at'],
            'ai' => ['ai', $pid(DB::table('chatbot_ai_requests')->where('created_at', '>=', $since)->whereNotNull('answered_at')->whereNull('error')
                ->where('model', '<>', \App\Services\OrderEncode\FlowEncode::MODEL)), 'answered_at'],
        ];
    }

    /**
     * Ang mga event pagkatapos ng cursor, ayon sa oras. Ang cursor na wala o sira ay ang dulo ngayon (walang event sa unang
     * poll, ang susunod lang). Kada pinagmulan: hanggang CAP + 1 na row; kapag lampas, ang bilang ng lahat at ang pinakabagong
     * CAP. Kapag lampas sa CAP ang lahat ng event, ang pinakabagong CAP ang ibinabalik at ang iba ay nasa $more.
     * @return array{0: array<int, array{type: string, at: string, page: string, id: int}>, 1: int, 2: array<string, string>}
     */
    private function events(?string $page, array $in): array
    {
        $now = now();
        $since = $now->copy()->subSeconds(self::WINDOW_SECONDS)->format('Y-m-d H:i:s');
        $names = BotcakeChat::pageMap();
        $events = [];
        $more = 0;
        $out = [];
        foreach ($this->sources($page, $since) as $key => [$type, $q, $at]) {
            $table = $q->from;
            $cursor = self::cursor($key, $in[$key] ?? null, $table, $now);
            $after = fn () => self::after(clone $q, $key, $cursor, $at);
            $cols = ['id', 'page_id', $at . ' as at'];
            $rows = $after()->orderBy(self::CURSORS[$key] === 'id' ? 'id' : $at)->orderBy('id')->limit(self::CAP + 1)->get($cols);
            if ($rows->count() > self::CAP) {
                $more += $after()->count() - self::CAP;
                $rows = $after()->orderByDesc(self::CURSORS[$key] === 'id' ? 'id' : $at)->orderByDesc('id')->limit(self::CAP)->get($cols)->reverse()->values();
            }
            $last = $rows->last();
            $out[$key] = $last === null ? $cursor : (self::CURSORS[$key] === 'id' ? (string) $last->id : $last->at . '|' . $last->id);
            foreach ($rows as $r) {
                $events[] = ['type' => $type, 'at' => (string) $r->at, 'page' => $names[(string) $r->page_id] ?? 'Page ' . $r->page_id, 'id' => (int) $r->id];
            }
        }
        usort($events, fn ($a, $b) => [$a['at'], array_search($a['type'], self::TYPES, true), $a['id']] <=> [$b['at'], array_search($b['type'], self::TYPES, true), $b['id']]);
        if (count($events) > self::CAP) {
            $more += count($events) - self::CAP;
            $events = array_slice($events, -self::CAP);
        }

        return [$events, $more, $out];
    }

    /**
     * Ang cursor na ipinadala, na-check: id (digits, hindi lampas sa pinakamataas na id ng table) o "Y-m-d H:i:s|id" (hindi
     * lampas ngayon). Wala o sira: ang dulo ngayon (ang pinakamataas na id, o ngayon|0).
     */
    private static function cursor(string $key, mixed $v, string $table, \Carbon\Carbon $now): string
    {
        if (self::CURSORS[$key] === 'id') {
            $max = (int) DB::table($table)->max('id');

            return is_string($v) && ctype_digit($v) && strlen($v) <= 18 && (int) $v <= $max ? (string) (int) $v : (string) $max;
        }
        // Ang oras ng Meta ay maaaring ilang segundo na lampas sa orasan ng server: hanggang 1 minuto ang tinatanggap.
        if (is_string($v) && preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\|(\d{1,18})$/', $v, $m)
            && $m[1] <= $now->copy()->addMinute()->format('Y-m-d H:i:s')) return $m[1] . '|' . (int) $m[2];

        return $now->format('Y-m-d H:i:s') . '|0';
    }

    /** Ang mga row pagkatapos ng cursor: id > cursor, o (oras, id) > cursor. */
    private static function after(Builder $q, string $key, string $cursor, string $at): Builder
    {
        if (self::CURSORS[$key] === 'id') return $q->where('id', '>', (int) $cursor);
        [$t, $id] = explode('|', $cursor);

        return $q->where(fn ($w) => $w->where($at, '>', $t)->orWhere(fn ($x) => $x->where($at, '=', $t)->where('id', '>', (int) $id)));
    }
}

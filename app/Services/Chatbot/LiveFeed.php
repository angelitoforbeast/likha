<?php

namespace App\Services\Chatbot;

use App\Models\BotcakeChat;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * /chatbot/live: ang ginagawa ng bot ngayon, para sa scoreboard sa TV. Dalawang bahagi:
 * - ang mga bilang ngayong araw (Manila) at sa huling 5 minuto: mensahe, sagot na kumpirmado ng Meta (at ang naipadala
 *   pero hindi pa kumpirmado), follow-up (sequence), sagot ng AI at order; ang pumasok at lumabas kada bot, ang aktibong
 *   chat, ang average na reply time ng Forbeast at ang huling order; hanggang 10 segundo ang tanda, kada page filter;
 * - ang mga bagong event mula sa cursor na ipinadala ng page, kada pinagmulan: id ng botcake_chats (message), id ng
 *   chatbot_replies (follow_up), id ng chatbot_events na order (order), at (oras, id) ng kumpirmasyon ng Meta (confirmed)
 *   at ng sagot ng AI (ai), dahil ang dalawang iyon ay nangyayari pagkatapos maisulat ang row.
 * Ang event ay type, oras, pangalan ng page, id at bot lang: walang pangalan o mensahe ng customer. Bawat query ay may id o
 * oras na naka-index, sa huling 15 minuto lang; hanggang 200 event kada poll, ang sobra ay bilang lang ("+N more").
 */
class LiveFeed
{
    public const CACHE_SECONDS = 10;
    public const RECENT_SECONDS = 300;
    /** Ang event ay hinahanap sa mga row ng huling 15 minuto lang (ang lumang cursor ay hindi nagbabasa ng buong table). */
    public const WINDOW_SECONDS = 900;
    public const CAP = 200;
    /** Ang kumpirmasyon ng Meta at ang sagot ng AI ay ibinibigay kapag 5 segundo na ang lumipas mula sa oras nito. */
    public const LAG_SECONDS = 5;
    /** Ang id cursor ay hindi hihigit sa ganitong layo mula sa pinakamataas na id (mas marami kaysa sa 15 minuto ng anumang table). */
    public const MAX_ID_BACK = 20000;
    public const TYPES = ['message', 'confirmed', 'follow_up', 'ai', 'order'];
    /** Ang cursor ng bawat pinagmulan: id lang, o oras|id. */
    public const CURSORS = ['chat' => 'id', 'seq' => 'id', 'order' => 'id', 'ok' => 'moment', 'ai' => 'moment'];
    /** Ang mga bot sa scoreboard (ang pumasok at lumabas kada bot); ang hindi makilala ay unknown. */
    public const BOTS = [Gateways::FORBEAST, Gateways::INCEPXIONBOT, ChatbotProvider::BOTCAKE];
    /** Ang row na ganito na katanda ay naayos na: kasama sa running total, hindi na binibilang bawat poll. */
    public const SETTLE_SECONDS = 900;
    /** Ang hangganan ng naayos na bahagi ay sumusulong nang 10 minuto bawat hakbang. */
    public const BUCKET_SECONDS = 600;
    /** Ang naayos na bahagi ay binibilang ulit nang buo kada 30 minuto (ang huling kumpirmasyon at sagot ay maisasama). */
    public const RECOUNT_SECONDS = 1800;

    /** Ang page filter: page ID na digits lang, o null (lahat). */
    public static function page(array $q): ?string
    {
        $p = $q['page_id'] ?? null;

        return is_string($p) && ctype_digit($p) && strlen($p) <= 30 ? $p : null;
    }

    /** Ang bot filter: isa sa mga bot ng scoreboard, o null (lahat; pati ang "all" at ang hindi kilala). */
    public static function bot(array $q): ?string
    {
        $b = $q['bot'] ?? null;

        return is_string($b) && in_array($b, self::BOTS, true) ? $b : null;
    }

    /** Ang buong sagot ng endpoint: bilang, mga bagong event mula sa cursor, ilan pa ang hindi isinama, at ang bagong cursor. */
    public function feed(array $q): array
    {
        $page = self::page($q);
        $bot = self::bot($q);
        [$events, $more, $cursor] = $this->events($page, is_array($q['cursor'] ?? null) ? $q['cursor'] : [], $bot);

        return ['counts' => self::narrow($this->counts($page), $bot), 'events' => $events, 'more' => $more, 'cursor' => $cursor, 'now' => now()->format('H:i:s')];
    }

    /** Sa bot filter: ang pumasok at lumabas ng napiling bot lang (ang iba ay 0); ang kabuuan ay sa page pa rin. */
    private static function narrow(array $counts, ?string $bot): array
    {
        if ($bot === null) return $counts;
        foreach (['in', 'out'] as $dir) {
            foreach ($counts['bots'][$dir] as $b => $n) if ($b !== $bot) $counts['bots'][$dir][$b] = 0;
        }

        return $counts;
    }

    // â”€â”€ mga bilang â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /**
     * Ang mga bilang ngayong araw at sa huling 5 minuto, kada page, iisa para sa lahat ng nanonood: sariwa nang 5 segundo;
     * mula 5 hanggang 10 ay ibinibigay pa ang luma habang kinukuwenta ang bago pagkatapos ng sagot (Cache::flexible), kaya
     * ang poll kada 5 segundo ay halos hindi naghihintay sa pagbilang; lampas 10 ay kinukuwenta muna.
     */
    public function counts(?string $page): array
    {
        return Cache::flexible('chatbot-live:v3:' . ($page ?? 'all'), [intdiv(self::CACHE_SECONDS, 2), self::CACHE_SECONDS], fn () => $this->buildCounts($page),
            ['seconds' => 15]); // ang lock ng refresh ay may expiry: ang namatay na worker ay hindi humaharang nang habambuhay
    }

    /**
     * Ang bilang ng araw = ang naayos nang bahagi (mula hatinggabi hanggang sa hangganan, 15 minuto o higit pa ang tanda;
     * running total na naka-cache, dinadagdagan lang ng mga bagong naayos na row) + ang buhay na bahagi (mula sa hangganan
     * hanggang ngayon, binibilang bawat refresh). Kaya ang bawat poll ay hindi nagbabasa ng buong araw. Ang naayos na bahagi
     * ay binibilang ulit nang buo kada 30 minuto, para ang kumpirmasyon na dumating nang lampas 15 minuto ay maisama rin.
     */
    private function buildCounts(?string $page): array
    {
        $now = now();
        $f = fn ($t) => $t->format('Y-m-d H:i:s');
        $start = $f($now->copy()->startOfDay());
        $end = $f($now->copy()->startOfDay()->addDay());
        $recent = $f($now->copy()->subSeconds(self::RECENT_SECONDS));
        // Ang naayos na bahagi ay lampas din sa standby window ng gateway watch: ang chat na itinataas o inuulit doon ay may lumang
        // received_at, kaya hindi pa ito dapat ituring na naayos habang puwede pang dumating ang ganoong row.
        $settle = max(self::SETTLE_SECONDS, (int) config('services.gateway.auto_switch.standby_minutes', 15) * 60 + 60);
        $edge = max($start, $f($now->copy()->setTimestamp(intdiv($now->getTimestamp() - $settle, self::BUCKET_SECONDS) * self::BUCKET_SECONDS)));

        $key = 'chatbot-live:settled:v2:' . ($page ?? 'all');
        $settled = Cache::get($key);
        if (!is_array($settled) || ($settled['day'] ?? null) !== $start || ($settled['to'] ?? '') > $edge || ($settled['full_at'] ?? 0) < $now->getTimestamp() - self::RECOUNT_SECONDS) {
            $settled = ['day' => $start, 'to' => $start, 'full_at' => $now->getTimestamp(), 'n' => self::tallyZero()];
        }
        if ($settled['to'] < $edge) {
            $settled['n'] = self::tallyAdd($settled['n'], $this->tally($page, $settled['to'], $edge, $settled['to'], $edge)['today']);
            $settled['to'] = $edge;
        }
        Cache::put($key, $settled, 86400);

        // Ang buhay na bahagi: ang ngayong araw ay mula sa hangganan; ang huling 5 minuto ay hindi pinuputol ng hatinggabi.
        $live = $this->tally($page, min($edge, $recent), $end, $edge, $recent);
        $day = self::tallyAdd($settled['n'], $live['today']);
        $pair = fn (string $k) => ['today' => $day[$k], 'recent' => $live['recent'][$k]];
        $sum = fn (string $k, string $part) => array_sum($part === 'today' ? $day[$k] : $live['recent'][$k]);

        return [
            'messages' => ['today' => $sum('in', 'today'), 'recent' => $sum('in', 'recent')],
            'confirmed' => ['today' => $sum('out', 'today'), 'recent' => $sum('out', 'recent')],
            'unconfirmed' => $pair('unconfirmed'),
            'follow_ups' => $pair('follow_ups'),
            'ai' => $pair('ai'),
            'orders' => $pair('orders'),
            // Kada bot: ang pumasok ayon sa gateway na naghatid, ang lumabas ayon sa chatbot_replies.via.
            'bots' => ['in' => $day['in'], 'out' => $day['out']],
            'active' => $this->active($page, $recent, $end),
            'reply_time' => app(MetaReplySpeed::class)->todayAverage($page),
            'last_order' => $this->lastOrder($page, $start, $end),
            'order_value' => $this->orderValue($page, $start, $end, $recent),
            'sequences' => $this->topSequences($day['seqs']),
            'linked' => [Gateways::FORBEAST => Gateways::setUp(Gateways::FORBEAST), Gateways::INCEPXIONBOT => Gateways::setUp(Gateways::INCEPXIONBOT)],
            'as_of' => $now->format('H:i:s'),
        ];
    }

    /** Ang walang laman na bilang: kada bot ang pumasok at lumabas, at ang iba ay isang numero. */
    private static function tallyZero(): array
    {
        $bots = array_fill_keys([...self::BOTS, 'unknown'], 0);

        return ['in' => $bots, 'out' => $bots, 'unconfirmed' => 0, 'follow_ups' => 0, 'ai' => 0, 'orders' => 0, 'seqs' => []];
    }

    private static function tallyAdd(array $a, array $b): array
    {
        foreach ($b as $k => $v) {
            if (is_array($v)) foreach ($v as $bot => $n) $a[$k][$bot] = ($a[$k][$bot] ?? 0) + $n;
            else $a[$k] += $v;
        }

        return $a;
    }

    /**
     * Ang mga row ng [$lo, $hi) ayon sa naka-index na oras ng bawat table, binilang sa dalawang marka: ang may oras na >=
     * $today (ang bahagi ng araw) at >= $recent (ang huling 5 minuto; ang sagot ng AI ay ayon sa oras ng sagot, kaya mas
     * maaga ang simula ng range nito). $hi ay ang dulo ng araw o ang hangganan ng naayos na bahagi.
     * @return array{today: array, recent: array}
     */
    private function tally(?string $page, string $lo, string $hi, string $today, string $recent): array
    {
        $out = ['today' => self::tallyZero(), 'recent' => self::tallyZero()];
        $int = fn ($v) => (int) ($v ?? 0);
        $marks = fn (string $col) => [sprintf('SUM(CASE WHEN %s >= ? THEN 1 ELSE 0 END) AS today, SUM(CASE WHEN %1$s >= ? THEN 1 ELSE 0 END) AS recent', $col), [$today, $recent]];
        $bot = fn ($v) => in_array($v, self::BOTS, true) ? $v : 'unknown';

        // Ang pumasok: hindi ang standby na kopya at hindi ang pagbukas ng ad (hindi mensahe); ang bot ay ang marka ng gateway na naghatid (chatVia).
        [$sql, $bind] = $marks('received_at');
        foreach (DB::table('botcake_chats')->where('received_at', '>=', $lo)->where('received_at', '<', $hi)->whereNull('payload->_standby')->whereNull('payload->' . ChatbotInbound::AD_OPEN)
            ->when($page !== null, fn ($q) => $q->where('page_id', $page))
            ->selectRaw(self::chatVia() . ' AS bot, ' . $sql, $bind)->groupByRaw(self::chatVia())->get() as $r) {
            $out['today']['in'][$bot($r->bot)] += $int($r->today);
            $out['recent']['in'][$bot($r->bot)] += $int($r->recent);
        }

        // Ang sagot ng bot (hindi sequence) na kumpirmado ng Meta (confirmed_status sent/partial na may oras: ang Botcake ay
        // kumpirmado na sa padala, ang Forbeast at Incepxionbot ay sa send_status), ang naipadala pero hinihintay pa ang
        // kumpirmasyon, at ang naipadalang sequence step (follow-up). Ayon sa sent_at (naka-index), kada via.
        $delivered = "status IN ('" . implode("','", SendStatus::DELIVERED) . "')";
        $conds = [
            'out' => "sequence_id IS NULL AND {$delivered} AND confirmed_status IN ('" . implode("','", SendStatus::DELIVERED) . "') AND confirmed_at IS NOT NULL",
            'unconfirmed' => "sequence_id IS NULL AND {$delivered} AND confirmed_status IS NULL",
            'follow_ups' => "sequence_id IS NOT NULL AND {$delivered}",
        ];
        // Ang lumabas kada bot ay isang SUM kada bot (walang GROUP BY): ang range ng sent_at ang laging basa, hindi ang index
        // na nagsisimula sa via.
        $known = "'" . implode("','", self::BOTS) . "'";
        $cols = ['unconfirmed' => $conds['unconfirmed'], 'follow_ups' => $conds['follow_ups']];
        foreach (self::BOTS as $b) $cols['out_' . $b] = $conds['out'] . " AND via = '{$b}'";
        $cols['out_unknown'] = $conds['out'] . " AND (via IS NULL OR via NOT IN ({$known}))";
        $sql = [];
        $bind = [];
        foreach ($cols as $k => $cond) {
            $sql[] = "SUM(CASE WHEN {$cond} AND sent_at >= ? THEN 1 ELSE 0 END) AS {$k}_today, SUM(CASE WHEN {$cond} AND sent_at >= ? THEN 1 ELSE 0 END) AS {$k}_recent";
            array_push($bind, $today, $recent);
        }
        $r = DB::table('chatbot_replies')->where('kind', 'send')->where('sent_at', '>=', $lo)->where('sent_at', '<', $hi)
            ->when($page !== null, fn ($q) => $q->where('page_id', (int) $page))->selectRaw(implode(', ', $sql), $bind)->first();
        foreach (['today', 'recent'] as $part) {
            foreach ([...self::BOTS, 'unknown'] as $b) $out[$part]['out'][$b] = $int($r->{'out_' . $b . '_' . $part});
            $out[$part]['unconfirmed'] = $int($r->{'unconfirmed_' . $part});
            $out[$part]['follow_ups'] = $int($r->{'follow_ups_' . $part});
        }

        // Ang follow-up kada sequence (para sa top 3 ng araw): isang naka-grupong basa ng parehong range ng sent_at.
        foreach (DB::table('chatbot_replies')->where('kind', 'send')->where('sent_at', '>=', $lo)->where('sent_at', '<', $hi)->whereNotNull('sequence_id')
            ->whereIn('status', SendStatus::DELIVERED)->when($page !== null, fn ($q) => $q->where('page_id', (int) $page))
            ->selectRaw('sequence_id, SUM(CASE WHEN sent_at >= ? THEN 1 ELSE 0 END) AS today', [$today])->groupBy('sequence_id')->get() as $s) {
            if ($int($s->today) > 0) $out['today']['seqs'][(int) $s->sequence_id] = $int($s->today);
        }

        // Ang sagot ng AI: ang tawag na may sagot (answered_at, walang error), hindi ang hinihintay ng Encode order. Ang
        // araw ayon sa created_at (naka-index); ang huling 5 minuto ayon sa oras ng sagot (kasama ang tawag na hanggang 15
        // minuto bago nito ginawa).
        $aiLo = $recent < $hi ? min($lo, date('Y-m-d H:i:s', strtotime($recent) - self::WINDOW_SECONDS)) : $lo;
        $a = DB::table('chatbot_ai_requests')->where('created_at', '>=', $aiLo)->where('created_at', '<', $hi)->whereNotNull('answered_at')->whereNull('error')
            ->where('model', '<>', \App\Services\OrderEncode\FlowEncode::MODEL)->when($page !== null, fn ($q) => $q->where('page_id', (int) $page))
            ->selectRaw('SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) AS today, SUM(CASE WHEN answered_at >= ? THEN 1 ELSE 0 END) AS recent', [$today, $recent])
            ->first();
        $out['today']['ai'] = $int($a->today);
        $out['recent']['ai'] = $int($a->recent);

        // Ang order na naitala ng bot ("Counts as an order"), gaya ng Results at Bot Health.
        [$sql, $bind] = $marks('created_at');
        $o = DB::table('chatbot_events')->where('type', 'order')->where('created_at', '>=', $lo)->where('created_at', '<', $hi)
            ->when($page !== null, fn ($q) => $q->where('page_id', (int) $page))->selectRaw($sql, $bind)->first();
        $out['today']['orders'] = $int($o->today);
        $out['recent']['orders'] = $int($o->recent);

        return $out;
    }

    /**
     * Ang bot na naghatid ng chat, bilang SQL: ang marka (payload._via, mula nang itinatala ito); ang mas lumang row ay ayon
     * sa dating payload (ang galing sa gateway ay may source, at ang galing sa Incepxionbot ay may gateway; ang iba ay
     * Botcake, ang parehong tuntunin ng ChatbotProvider at ResponseSpeed); walang payload = unknown. Laravel ang bahala sa
     * JSON path ng mysql, pgsql at sqlite.
     */
    private static function chatVia(): string
    {
        $g = DB::connection()->getQueryGrammar();
        [$via, $source, $gateway] = [$g->wrap('payload->_via'), $g->wrap('payload->source'), $g->wrap('payload->gateway')];
        $known = "'" . implode("','", self::BOTS) . "'";

        return "(CASE WHEN {$via} IN ({$known}) THEN {$via} WHEN {$source} = 'gateway' THEN (CASE WHEN {$gateway} = '" . Gateways::INCEPXIONBOT . "' THEN '"
            . Gateways::INCEPXIONBOT . "' ELSE '" . Gateways::FORBEAST . "' END) WHEN payload IS NOT NULL THEN '" . ChatbotProvider::BOTCAKE . "' ELSE 'unknown' END)";
    }

    /** Ang aktibong chat: ilang customer (page + PSID) ang nag-message sa huling 5 minuto (hindi ang pagbukas ng ad lang). */
    private function active(?string $page, string $recent, string $end): int
    {
        return (int) DB::table('botcake_chats')->where('received_at', '>=', $recent)->where('received_at', '<', $end)->whereNull('payload->_standby')->whereNull('payload->' . ChatbotInbound::AD_OPEN)
            ->when($page !== null, fn ($q) => $q->where('page_id', $page))
            ->groupBy('page_id')->selectRaw('COUNT(DISTINCT psid) AS n')->pluck('n')->sum();
    }

    /** Ang huling order ngayong araw: page, oras (HH:MM) at bot. Walang halaga ang order ng bot, kaya wala rito. */
    private function lastOrder(?string $page, string $start, string $end): ?array
    {
        $o = DB::table('chatbot_events')->where('type', 'order')->where('created_at', '>=', $start)->where('created_at', '<', $end)
            ->when($page !== null, fn ($q) => $q->where('page_id', (int) $page))
            ->orderByDesc('created_at')->orderByDesc('id')->first(['page_id', 'created_at', 'detail->via as bot']);
        if ($o === null) return null;

        return ['page' => BotcakeChat::pageMap()[(string) $o->page_id] ?? 'Page ' . $o->page_id, 'at' => substr((string) $o->created_at, 11, 5),
            'bot' => in_array($o->bot, self::BOTS, true) ? $o->bot : null];
    }

    /** Ang top 3 na sequence ngayong araw ayon sa naipadalang follow-up (pangalan at bilang); ang kulang sa 3 ay kung ano ang meron. */
    private function topSequences(array $seqs): array
    {
        if (!$seqs) return [];
        $names = Schema::hasTable('chatbot_sequences') ? DB::table('chatbot_sequences')->whereIn('id', array_keys($seqs))->pluck('name', 'id')->all() : [];
        $list = [];
        foreach ($seqs as $id => $n) $list[] = ['name' => (string) ($names[$id] ?? 'Sequence #' . $id), 'n' => $n];
        usort($list, fn ($a, $b) => [$b['n'], $a['name']] <=> [$a['n'], $b['name']]);

        return array_slice($list, 0, 3);
    }

    /**
     * Ang kabuuang halaga ng order ngayong araw at sa huling 5 minuto: ang alam na halaga lang ng bawat order event. Ang
     * mga order ng araw ay itinatabi (kada page filter) kasama ang halaga nito; bawat refresh ay binabasa lang ang order ng
     * huling 15 minuto (ang bago, pati ang naisulat nang huli) at hinahanapan ulit ng halaga ang wala pa, sa isang basa. Ang
     * may halaga na ay hindi na binabago, kaya iisang beses lang nabibilang ang bawat order.
     */
    private function orderValue(?string $page, string $start, string $end, string $recent): array
    {
        $key = 'chatbot-live:order-value:v1:' . ($page ?? 'all');
        $s = Cache::get($key);
        $fresh = !is_array($s) || ($s['day'] ?? null) !== $start;
        if ($fresh) $s = ['day' => $start, 'orders' => []];
        $since = $fresh ? $start : max($start, now()->subSeconds(self::WINDOW_SECONDS)->format('Y-m-d H:i:s'));
        foreach (DB::table('chatbot_events')->where('type', 'order')->where('created_at', '>=', $since)->where('created_at', '<', $end)
            ->when($page !== null, fn ($q) => $q->where('page_id', (int) $page))->get(['id', 'page_id', 'psid', 'created_at as at', 'detail->run as run']) as $o) {
            $s['orders'][(int) $o->id] ??= ['page_id' => (string) $o->page_id, 'psid' => (string) $o->psid, 'at' => (string) $o->at, 'run' => $o->run, 'amount' => null];
        }
        $open = array_filter($s['orders'], fn ($o) => $o['amount'] === null);
        foreach ($this->amounts($open) as $id => $amount) $s['orders'][$id]['amount'] = $amount;
        Cache::put($key, $s, 86400);

        $sum = fn (string $from) => self::number(array_sum(array_map(fn ($o) => $o['at'] >= $from ? (float) $o['amount'] : 0.0, $s['orders'])));

        return ['today' => $sum($start), 'recent' => $sum($recent)];
    }

    /**
     * Ang halaga ng bawat order event: ang COD ng na-encode na order ng parehong customer (page + PSID). Ang encode ng order
     * ay ang order_encodes row ng parehong takbo (run id: Upload to macro, o ang sheet step na live encode), at kung wala,
     * ang pinakaunang encode ng customer mula 10 minuto bago ang order hanggang 24 oras pagkatapos. Ang na-encode na order
     * ay ang row na iyon kapag tapos (done) at may macro row; kung hindi pa, ang tapos na encode ng parehong macro row sa
     * susunod na takbo (Encode order). Ang COD ay ang nasa macro row (kasama ang ayos ng staff); walang tapos na encode o
     * walang COD na numero = null. Ang "second order not accepted" ay hindi kasama (row iyon ng ibang order). Isang basa
     * para sa lahat ng order (order_encodes_page_created na index), hindi kada order.
     * @param array<int, array{page_id: string, psid: string, at: string, run: ?string}> $orders id => order
     * @return array<int, int|float|null>
     */
    private function amounts(array $orders): array
    {
        $out = array_fill_keys(array_keys($orders), null);
        $names = BotcakeChat::pageMap();
        $pages = array_values(array_unique(array_filter(array_map(fn ($o) => $names[$o['page_id']] ?? null, $orders))));
        if (!$pages || !Schema::hasTable('order_encodes')) return $out;
        $from = date('Y-m-d H:i:s', strtotime(min(array_column($orders, 'at'))) - 600);
        $rows = DB::table('order_encodes as e')->leftJoin('macro_output as m', 'm.id', '=', 'e.macro_output_id')
            ->where('e.mode', 'live')->whereIn('e.page', $pages)->where('e.created_at', '>=', $from)
            ->whereIn('e.psid', array_values(array_unique(array_map(fn ($o) => (int) $o['psid'], $orders))))
            ->orderBy('e.created_at')->orderBy('e.id')
            ->get(['e.page', 'e.psid', 'e.run_id', 'e.status', 'e.macro_output_id', 'e.created_at', 'e.input->not_accepted as not_accepted', 'm.COD as cod'])
            ->filter(fn ($e) => $e->not_accepted === null);

        // Ang mga takbong may sariling order event (ang encode nila ay hindi ipinapahiram sa ibang order), mula 24 oras bago: ang
        // encode ng naunang order ay maaaring dumating pagkatapos ng susunod na order.
        $runs = DB::table('chatbot_events')->whereIn('page_id', array_values(array_unique(array_map(fn ($o) => (int) $o['page_id'], $orders))))
            ->whereIn('psid', array_values(array_unique(array_map(fn ($o) => (int) $o['psid'], $orders))))
            ->where('type', 'order')->where('created_at', '>=', date('Y-m-d H:i:s', strtotime($from) - 86400))->pluck('detail->run as run')->filter()->values()->all();
        foreach ($orders as $id => $o) {
            $lo = date('Y-m-d H:i:s', strtotime($o['at']) - 600);
            $hi = date('Y-m-d H:i:s', strtotime($o['at']) + 86400);
            $mine = $rows->filter(fn ($e) => (string) $e->psid === $o['psid'] && $e->page === ($names[$o['page_id']] ?? null)
                && (string) $e->created_at >= $lo && (string) $e->created_at <= $hi)->values();
            // Walang encode ang sariling takbo: ang encode lang na ginawa sa o pagkatapos ng order, at hindi takbo ng ibang order.
            $own = $mine->first(fn ($e) => $o['run'] !== null && $e->run_id === $o['run'])
                ?? $mine->first(fn ($e) => (string) $e->created_at >= $o['at'] && !in_array($e->run_id, $runs, true));
            if ($own === null) continue;
            $done = fn ($e) => $e->status === 'done' && $e->macro_output_id !== null;
            $encoded = $done($own) ? $own : ($own->macro_output_id === null ? null
                : $mine->first(fn ($e) => $done($e) && (int) $e->macro_output_id === (int) $own->macro_output_id && (string) $e->created_at >= (string) $own->created_at));
            if ($encoded !== null) $out[$id] = self::peso($encoded->cod);
        }

        return $out;
    }

    /** Ang COD bilang piso: numero lang (may kuwit o ₱ man), higit sa 0; ang iba ay null. Hindi kailanman tinatantiya. */
    private static function peso(mixed $cod): int|float|null
    {
        $v = str_replace([',', '₱', ' '], '', trim((string) $cod));
        if (!preg_match('/^\d{1,9}(\.\d{1,2})?$/', $v) || (float) $v <= 0) return null;

        return self::number((float) $v);
    }

    /** Ang buong piso ay int, ang may sentimo ay may 2 decimal. */
    private static function number(float $n): int|float
    {
        $n = round($n, 2);

        return floor($n) === $n ? (int) $n : $n;
    }

    // â”€â”€ mga event â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /**
     * Ang mga pinagmulan ng event: type, ang query (may page filter at sa huling 15 minuto), ang column ng oras ng event,
     * at ang bot (SQL): ang gateway na naghatid ng chat, ang via ng sagot, ang via ng order; ang AI ay walang bot.
     * @return array<string, array{0: string, 1: Builder, 2: string, 3: string}>
     */
    private function sources(?string $page, string $since): array
    {
        $pid = fn (Builder $q) => $page === null ? $q : $q->where('page_id', $q->from === 'botcake_chats' ? $page : (int) $page);
        $delivered = SendStatus::DELIVERED;

        return [
            'chat' => ['message', $pid(DB::table('botcake_chats')->where('received_at', '>=', $since)->whereNull('payload->_standby')->whereNull('payload->' . ChatbotInbound::AD_OPEN)), 'received_at', self::chatVia()],
            'seq' => ['follow_up', $pid(DB::table('chatbot_replies')->where('sent_at', '>=', $since)->where('kind', 'send')->whereNotNull('sequence_id')->whereIn('status', $delivered)), 'sent_at', 'via'],
            'order' => ['order', $pid(DB::table('chatbot_events')->where('type', 'order')->where('created_at', '>=', $since)), 'created_at',
                DB::connection()->getQueryGrammar()->wrap('detail->via')],
            'ok' => ['confirmed', $pid(DB::table('chatbot_replies')->where('sent_at', '>=', $since)->where('kind', 'send')->whereNull('sequence_id')->whereIn('status', $delivered)
                ->whereIn('confirmed_status', $delivered)->whereNotNull('confirmed_at')), 'confirmed_at', 'via'],
            'ai' => ['ai', $pid(DB::table('chatbot_ai_requests')->where('created_at', '>=', $since)->whereNotNull('answered_at')->whereNull('error')
                ->where('model', '<>', \App\Services\OrderEncode\FlowEncode::MODEL)), 'answered_at', 'NULL'],
        ];
    }

    /**
     * Ang mga event pagkatapos ng cursor, ayon sa oras. Ang cursor na wala o sira ay ang dulo ngayon, walang event (ang unang
     * poll ay walang event, ang susunod lang). Ang (oras, id) ay hanggang LAG_SECONDS bago ngayon lang: ang kumpirmasyon ng
     * Meta na dumating nang ilang segundo pagkatapos ng oras nito, o hindi sunod-sunod, ay nahuhuli pa; ang oras na lampas
     * ngayon ay hinihintay. Kada pinagmulan: hanggang CAP + 1 na row; kapag lampas, ang bilang ng lahat at ang pinakabagong
     * CAP. Kapag lampas sa CAP ang lahat ng event, ang pinakabagong CAP ang ibinabalik at ang iba ay nasa $more. Sa bot filter:
     * ang event ng bot na iyon lang (ang AI ay walang bot, kaya wala). Ang order ay may amount (ang COD ng na-encode na order,
     * o null), hinahanap sa isang basa para sa lahat ng order ng poll.
     * @return array{0: array<int, array{type: string, at: string, page: string, id: int, bot: ?string, amount?: int|float|null}>, 1: int, 2: array<string, string>}
     */
    private function events(?string $page, array $in, ?string $only = null): array
    {
        $now = now();
        $since = $now->copy()->subSeconds(self::WINDOW_SECONDS)->format('Y-m-d H:i:s');
        $upTo = $now->copy()->subSeconds(self::LAG_SECONDS)->format('Y-m-d H:i:s');
        $names = BotcakeChat::pageMap();
        $events = [];
        $more = 0;
        $out = [];
        $orders = [];
        foreach ($this->sources($page, $since) as $key => [$type, $q, $at, $bot]) {
            $byId = self::CURSORS[$key] === 'id';
            [$cursor, $given] = self::cursor($key, $in[$key] ?? null, $q->from, $upTo);
            $out[$key] = $cursor;
            if (!$given) continue;
            if ($only !== null) $bot === 'NULL' ? $q->whereRaw('1 = 0') : $q->whereRaw($bot . ' = ?', [$only]);
            $after = fn () => self::after(clone $q, $byId, $cursor, $at, $upTo);
            $cols = ['id', 'page_id', $at . ' as at', DB::raw($bot . ' AS bot'), ...($type === 'order' ? ['psid', 'detail->run as run'] : [])];
            $rows = $after()->orderBy($byId ? 'id' : $at)->orderBy('id')->limit(self::CAP + 1)->get($cols);
            if ($rows->count() > self::CAP) {
                $more += $after()->count() - self::CAP;
                $rows = $after()->orderByDesc($byId ? 'id' : $at)->orderByDesc('id')->limit(self::CAP)->get($cols)->reverse()->values();
            }
            if ($last = $rows->last()) $out[$key] = $byId ? (string) $last->id : $last->at . '|' . $last->id;
            foreach ($rows as $r) {
                $events[] = ['type' => $type, 'at' => (string) $r->at, 'page' => $names[(string) $r->page_id] ?? 'Page ' . $r->page_id, 'id' => (int) $r->id,
                    'bot' => in_array($r->bot, self::BOTS, true) ? $r->bot : null];
                if ($type === 'order') $orders[(int) $r->id] = ['page_id' => (string) $r->page_id, 'psid' => (string) $r->psid, 'at' => (string) $r->at, 'run' => $r->run];
            }
        }
        if ($orders) {
            $amounts = $this->amounts($orders);
            foreach ($events as $i => $e) if ($e['type'] === 'order') $events[$i]['amount'] = $amounts[$e['id']];
        }
        usort($events, fn ($a, $b) => [$a['at'], array_search($a['type'], self::TYPES, true), $a['id']] <=> [$b['at'], array_search($b['type'], self::TYPES, true), $b['id']]);
        if (count($events) > self::CAP) {
            $more += count($events) - self::CAP;
            $events = array_slice($events, -self::CAP);
        }

        return [$events, $more, $out];
    }

    /**
     * Ang cursor na ipinadala, na-check, at kung galing sa page (true) o ang dulo ngayon (false: wala o sira). Id: digits,
     * hindi lampas sa pinakamataas na id ng table; ang napakaluma ay itinataas sa MAX_ID_BACK bago ang pinakamataas (para
     * maliit na range lang ng primary key ang mababasa, kahit ano ang piliin ng MySQL). Oras|id: "Y-m-d H:i:s|id" na totoong
     * petsa at oras, hindi lampas sa $upTo. Ang dulo: ang pinakamataas na id, o $upTo|0.
     * @return array{0: string, 1: bool}
     */
    private static function cursor(string $key, mixed $v, string $table, string $upTo): array
    {
        if (self::CURSORS[$key] === 'id') {
            $max = (int) DB::table($table)->max('id');
            if (!is_string($v) || !ctype_digit($v) || strlen($v) > 18 || (int) $v > $max) return [(string) $max, false];

            return [(string) max((int) $v, $max - self::MAX_ID_BACK), true];
        }
        if (is_string($v) && preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\|(\d{1,18})$/', $v, $m) && $m[1] <= $upTo
            && ($d = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $m[1])) && $d->format('Y-m-d H:i:s') === $m[1]) return [$m[1] . '|' . (int) $m[2], true];

        return [$upTo . '|0', false];
    }

    /** Ang mga row pagkatapos ng cursor: id > cursor, o (oras, id) > cursor at ang oras ay hanggang $upTo. */
    private static function after(Builder $q, bool $byId, string $cursor, string $at, string $upTo): Builder
    {
        if ($byId) return $q->where('id', '>', (int) $cursor);
        [$t, $id] = explode('|', $cursor);

        return $q->where($at, '<=', $upTo)->where(fn ($w) => $w->where($at, '>', $t)->orWhere(fn ($x) => $x->where($at, '=', $t)->where('id', '>', (int) $id)));
    }
}

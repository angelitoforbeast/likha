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
            'bot' => is_string($bot) && in_array($bot, ChatbotConnections::bots(), true) ? $bot : null];
    }

    /** Ang mga chart ng page mismo; ang sa Sequences ay galing sa sariling endpoint nito (sequencesOutput). */
    private const PAGE_CHARTS = ['reply', 'ai', 'push'];
    /**
     * Ang dalawang cache: ang mga numero ng page (walang Sequences) at ang Sequences. Ang bersyon sa key ay pinapalitan
     * kapag nagbago ang hugis ng laman, para hindi mabasa ng bagong code ang lumang entry pagkatapos ng deploy.
     */
    public const PAGE_KEY = 'response-speed:v3:';
    public const SEQUENCES_KEY = 'response-speed-seq:v1:';
    /**
     * Ang isinusulat ng pre-warm ay mas matagal nang kaunti kaysa sa sariling TTL ng page, para hindi ito mag-expire sa
     * pagitan ng dalawang takbo (kada 2 minuto, at ang takbo mismo ay ilang segundo).
     */
    public const WARM_GRACE_SECONDS = 180;
    /** Ang kahapon ay halos hindi na nagbabago: pinapainit ulit kapag ganito na katagal ang huling init nito. */
    public const YESTERDAY_WARM_SECONDS = 1200;
    public const WARM_STAMP_KEY = 'response-speed:warmed:';

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
            'charts' => self::charts($out, $prev, self::PAGE_CHARTS)];
    }

    /**
     * Ang Sequences ng /chatbot/speed, hiwalay sa page (ito ang pinakamabigat): walang $chart, ang araw lang
     * ({filters, sequences}); may $chart, ang chart nito ({date, prev_date, seq}) mula sa araw at sa naunang araw. Bawat
     * tawag ay hindi lalampas sa isang araw ng Sequences na kinukuwenta (ang araw ay naka-cache na mula sa unang tawag).
     */
    public function sequencesOutput(array $q, bool $chart = false): array
    {
        $f = self::filters($q);
        $today = now()->toDateString();
        $seq = $this->sequencesDay($f, $today);
        if (!$chart) return ['filters' => $f, 'sequences' => $seq];
        $pf = ['date' => CarbonImmutable::parse($f['date'])->subDay()->toDateString()] + $f;

        return self::charts(['filters' => $f, 'sequences' => $seq], ['filters' => $pf, 'sequences' => $this->sequencesDay($pf, $today)], ['seq']);
    }

    private static function ttl(array $f, string $today): int
    {
        return $f['date'] === $today ? self::TODAY_CACHE_SECONDS : self::PAST_CACHE_SECONDS;
    }

    /** Ang mga numero ng isang araw at filter (walang Sequences), naka-cache (ngayon 2 minuto, ang lumang araw 30 minuto). */
    private function day(array $f, string $today): array
    {
        $key = self::PAGE_KEY . md5(json_encode($f));
        $hit = Cache::get($key);
        if ($hit !== null) return $hit;
        $out = $this->build($f, $today);
        Cache::put($key, $out, self::ttl($f, $today));

        return $out;
    }

    /** Ang Sequences ng isang araw at filter, sariling cache: ang hindi nakuwenta (too_large) ay sinusubukan ulit pagkalipas ng 2 minuto, hindi ng 30. */
    private function sequencesDay(array $f, string $today, int $extra = 0): array
    {
        $key = self::SEQUENCES_KEY . md5(json_encode($f));
        if ($extra === 0) {
            $hit = Cache::get($key);
            if ($hit !== null) return $hit;
        }
        $out = $this->buildSequences($f, $today);
        Cache::put($key, $out, empty($out['too_large']) ? self::ttl($f, $today) + $extra : self::TODAY_CACHE_SECONDS);

        return $out;
    }

    private function buildSequences(array $f, string $today): array
    {
        $start = $f['date'] . ' 00:00:00';
        $end = CarbonImmutable::parse($f['date'])->addDay()->toDateString() . ' 00:00:00';

        return $this->sequencesSection($f, $start, $end, $f['date'] === $today ? (int) now()->format('G') : 23);
    }

    /**
     * Ang pre-warm ng default na view (walang page, walang bot): ang kahapon kapag oras na (walang tala na buo itong
     * napainit bilang lumang araw, o YESTERDAY_WARM_SECONDS na ang tala), saka ang ngayon (bawat takbo). Sinusulat sa
     * parehong key na binabasa ng page, hindi binabasa ang cache. Bawat bahagi ay sariling try: ang pumalya ay ini-report
     * at hindi isinusulat (ang page ang kukuwenta nito gaya ng dati). Ang kahapon muna: ito ang reference ng mga chart ng
     * ngayon. Pagkalipas ng hatinggabi ay walang tala ang bagong kahapon, kaya agad itong kinukuwenta ulit bilang buong araw.
     * @return array{warmed: string[], failed: string[]}
     */
    public function warmDefault(): array
    {
        $today = now()->toDateString();
        $yesterday = CarbonImmutable::parse($today)->subDay()->toDateString();
        $out = ['warmed' => [], 'failed' => []];
        $at = Cache::get(self::WARM_STAMP_KEY . $yesterday);
        if (!is_int($at) || time() - $at >= self::YESTERDAY_WARM_SECONDS) {
            if ($this->warm($yesterday, $today, $out)) Cache::put(self::WARM_STAMP_KEY . $yesterday, time(), self::PAST_CACHE_SECONDS + self::WARM_GRACE_SECONDS);
        }
        $this->warm($today, $today, $out);

        return $out;
    }

    /** Ang dalawang entry ng isang araw ng default na view. true kapag buo ang dalawa (walang pumalya, nakuwenta ang Sequences). */
    private function warm(string $date, string $today, array &$out): bool
    {
        $f = self::filters(['date' => $date]);
        $ok = true;
        try {
            Cache::put(self::PAGE_KEY . md5(json_encode($f)), $this->build($f, $today), self::ttl($f, $today) + self::WARM_GRACE_SECONDS);
            $out['warmed'][] = $date;
        } catch (\Throwable $e) {
            report($e);
            $out['failed'][] = $date;
            $ok = false;
        }
        // Ang error sa loob ng Sequences ay nagiging too_large (gaya sa page, 2 minuto lang); ang try ay para sa pagsulat sa cache.
        try {
            $seq = $this->sequencesDay($f, $today, self::WARM_GRACE_SECONDS);
            if (empty($seq['too_large'])) {
                $out['warmed'][] = $date . ' sequences';
            } else {
                $out['failed'][] = $date . ' sequences (too large)';
                $ok = false;
            }
        } catch (\Throwable $e) {
            report($e);
            $out['failed'][] = $date . ' sequences';
            $ok = false;
        }

        return $ok;
    }

    /**
     * Ang data ng mga chart, galing mismo sa mga table (kaya pareho ang numero): kada chart ang serye ng araw (24 na
     * value, null = puwang: walang numero o hindi pa naaabot ang oras), ang parehong serye ng naunang araw (prev) bilang
     * reference, at ang bilang sa likod ng bawat punto (counts, prev_counts) para sa tooltip. $only: ang mga chart na ito
     * lang (null = lahat).
     */
    public static function charts(array $day, array $prev, ?array $only = null): array
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
        if ($only !== null) $defs = array_intersect_key($defs, array_flip($only));
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
        // scalar para magaan ang buong araw: page:PSID, oras, oras sa araw, bot (Botcake, o ang gateway ng page), nasa bot.
        [$keys, $ats, $hrs, $fbs, $ons] = [[], [], [], [], []];
        $names = \App\Models\BotcakeChat::pageMap();
        $gwOf = [];
        foreach (self::windows($start, $end) as [$a, $b]) foreach (DB::table('botcake_chats')->where('received_at', '>=', $a)->where('received_at', '<', $b)
            ->when($f['page_id'] !== null, fn ($q) => $q->where('page_id', $f['page_id']))->select(['page_id', 'psid', 'received_at', 'payload'])->cursor() as $c) {
            $payload = json_decode((string) $c->payload, true) ?: [];
            // Ang attachment lang ay sadyang hindi sinasagot; ang standby ay kopya ng Botcake sa page na nasa Forbeast.
            if (!empty($payload['_ignored']) || !empty($payload['_standby'])) continue;
            $keys[] = $c->page_id . ':' . $c->psid;
            $ats[] = strtotime($c->received_at);
            $hrs[] = (int) substr($c->received_at, 11, 2);
            // Ang chat mula sa gateway: ang aktibong gateway ng page (ang sumasagot).
            $fbs[] = ($payload['source'] ?? null) === 'gateway'
                ? ($gwOf[(string) $c->page_id] ??= Gateways::label(isset($names[(string) $c->page_id]) ? Gateways::active($names[(string) $c->page_id]) : Gateways::default()))
                : ChatbotConnections::BOTS[0];
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
            $forbeast = Gateways::valid($r->via ?? null); // alinmang gateway: may oras ng Meta (send_status)
            if ($f['bot'] !== null && $f['bot'] !== ChatbotProvider::viaLabel((string) ($r->via ?? ''))) return;
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
        $byBot = array_fill_keys(ChatbotConnections::bots(), $blank);
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
            $bot = $fbs[$i];
            $byBot[$bot] ??= $blank;
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
        ];
    }

    /**
     * Ang Sequences, o ang tala na hindi ito nakuwenta (too_large) kapag lumampas sa badyet ng memory o nagka-error: ang
     * ibang section ng screen ay buo pa rin, hindi 500.
     */
    private function sequencesSection(array $f, string $start, string $end, int $nowHour): array
    {
        try {
            return $this->sequences($f, $start, $end, $nowHour);
        } catch (\Throwable $e) {
            report($e);

            return ['too_large' => true, 'hours' => array_fill(0, 24, null), 'total' => null, 'slowest' => []];
        }
    }

    /**
     * Ang [$from, $to) na hinati kada oras. Ang MySQL driver ay hawak ang buong resulta ng isang query sa memory kahit
     * cursor, kaya ang buong araw ay binabasa nang paisa-isang oras; ang pagkakasunod (kapag may orderBy) ay pareho.
     * @return \Generator<int, array{0: string, 1: string}>
     */
    private static function windows(string $from, string $to): \Generator
    {
        for ($a = $from, $t = strtotime($from); $a < $to; $a = $b) {
            $t += 3600;
            $b = min(date('Y-m-d H:i:s', $t), $to);
            yield [$a, $b];
        }
    }

    /** Ang memory na puwedeng abutin ng request habang kinukuwenta ang Sequences: 3/4 ng memory_limit (walang hangganan kapag -1). */
    protected function sequenceBudget(): int
    {
        $limit = ini_parse_quantity((string) ini_get('memory_limit'));

        return $limit > 0 ? intdiv($limit * 3, 4) : PHP_INT_MAX;
    }

    private static function checkBudget(int $budget): void
    {
        if (memory_get_usage(true) > $budget) throw new \OverflowException('Speed: the sequences section is over its memory budget.');
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
        $seqCol = Schema::hasColumn('chatbot_replies', 'sequence_id');
        foreach (self::windows($startAt, $endAt) as [$a, $b]) foreach (DB::table('chatbot_replies')->where('kind', 'send')->whereIn('status', SendStatus::DELIVERED)
            ->where('sent_at', '>=', $a)->where('sent_at', '<', $b)
            ->when($page !== null, fn ($q) => $q->where('page_id', (int) $page))
            ->when($seqCol, fn ($q) => $q->whereNull('sequence_id'))
            ->orderBy('sent_at')->orderBy('id')->select($cols)->cursor() as $r) {
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
        $budget = $this->sequenceBudget();
        $blank = ['due' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'none' => 0, 'botcake' => 0, 'push' => [], 'meta' => []];
        $hours = array_fill(0, 24, $blank);
        $total = $blank;
        $late = date('Y-m-d H:i:s', strtotime($end) + self::STEP_LATE_SECONDS);
        // Dalawang basa ng mga event ng araw, walang hawak na row: una ang mga customer, page at sequence (para sa
        // kasaysayan nila) at ang unang takbo ng bawat customer + sequence; pangalawa ang bilang. Ang pangalawa ay ang mga
        // event lang na nakita ng una (hanggang sa ID nito): ang padala ay isinusulat bago ang event, kaya bawat bilang na
        // event ay may padala na nang basahin ang mga padala, gaya ng dati.
        [$pages, $psids, $names, $first] = [[], [], [], []];
        $maxId = $this->stepEvents($f['page_id'], $start, $late, $budget, function (object $e, array $d) use (&$pages, &$psids, &$names, &$first): void {
            $pages[(int) $e->page_id] = true;
            $psids[(int) $e->psid] = true;
            $name = (string) ($d['name'] ?? '');
            $names[$name] = true;
            $k = $e->page_id . ':' . $e->psid . ':' . $name;
            if (!isset($first[$k]) || (string) $e->created_at < $first[$k]) $first[$k] = (string) $e->created_at;
        });
        $before = $this->stepHistory(array_keys($pages), array_keys($psids), $first, $late, $budget);
        $delays = $this->stepDelays(array_map('strval', array_keys($names)));
        unset($pages, $psids, $names, $first);
        $sends = $this->stepSends(date('Y-m-d H:i:s', strtotime($start) - self::STEP_PUSH_SECONDS), $late, $f['page_id'], $budget);
        $botOf = $f['bot'] !== null ? BotHealth::botOfPages() : [];
        // Ang 20 pinakamabagal lang ang hawak: min-heap ng [delay, pushed, -bilang], ang pinakamahina ang nasa itaas. Ang
        // tabla ay ayon sa pagkakabasa (oras ng takbo, ID), gaya ng dati.
        $slow = new \SplMinHeap();
        $n = 0;

        $this->stepEvents($f['page_id'], $start, $late, $budget, function (object $e, array $d) use ($f, $start, $end, $before, $delays, $sends, $botOf, $slow, &$n, &$hours, &$total): void {
            $n++;
            $due = self::originalDue($e, $d, $before, $delays);
            $at = $due ?? (string) $e->created_at;
            if ($at < $start || $at >= $end) return;
            if ($f['bot'] !== null && ($botOf[(string) $e->page_id] ?? ChatbotConnections::BOTS[0]) !== $f['bot']) return;

            $op = $d['op'];
            $row = null;
            if ($op === 'ran') {
                $row = self::stepSend($sends[$e->page_id . ':' . $e->psid . ':' . (int) ($d['step'] ?? 0)] ?? '', $d['sequence_id'] ?? null, strtotime((string) $e->created_at));
                $op = $row === null ? 'none' : ($row['delivered'] ? 'sent' : 'failed');
            }
            $h = &$hours[(int) substr($at, 11, 2)];
            foreach ([&$h, &$total] as &$b) {
                $b['due']++;
                if (isset($b[$op])) $b[$op]++;
                if ($op !== 'sent') continue;
                if ($due !== null) $b['push'][] = max(0, $row['pushed'] - strtotime($due));
                if ($row['meta'] !== null) $b['meta'][] = max(0, $row['meta'] - $row['pushed']);
                if ($row['botcake']) $b['botcake']++;
            }
            unset($b, $h);
            if ($op !== 'sent') return;
            $sent = $row['meta'] ?? ($row['botcake'] ? $row['pushed'] : null);
            $item = [max(0, ($sent ?? $row['pushed']) - strtotime($due ?? date('Y-m-d H:i:s', $row['pushed']))), $row['pushed'], -$n,
                [$e->page_id, (string) ($d['name'] ?? ''), (int) ($d['step'] ?? 0), $due, $row, $sent]];
            if ($slow->count() < self::SLOWEST) {
                $slow->insert($item);
            } elseif ($item > $slow->top()) {
                $slow->extract();
                $slow->insert($item);
            }
        }, $maxId);

        $top = [];
        foreach ($slow as $item) $top[] = $item;
        usort($top, fn ($a, $b) => [$b[0], $b[1], $b[2]] <=> [$a[0], $a[1], $a[2]]);
        $pageNames = \App\Models\BotcakeChat::pageMap();
        $slowest = array_map(function (array $item) use ($pageNames): array {
            [$delay, , , [$page, $name, $step, $due, $row, $sent]] = $item;

            return ['page' => $pageNames[(string) $page] ?? 'Page ' . $page, 'sequence' => mb_substr($name, 0, 120), 'step' => $step,
                'scheduled' => $due, 'pushed' => date('Y-m-d H:i:s', $row['pushed']), 'sent' => $sent === null ? null : date('Y-m-d H:i:s', $sent),
                'scheduled_to_pushed' => $due === null ? null : max(0, $row['pushed'] - strtotime($due)),
                'pushed_to_meta' => $row['meta'] === null ? null : max(0, $row['meta'] - $row['pushed']),
                'delay' => $delay, 'sent_is_pushed' => $row['botcake']];
        }, $top);

        $out = fn ($b) => ['due' => $b['due'], 'sent' => $b['sent'], 'failed' => $b['failed'], 'skipped' => $b['skipped'], 'no_message' => $b['none'], 'botcake' => $b['botcake'],
            'scheduled_to_pushed' => self::delayStats($b['push']), 'pushed_to_meta' => self::delayStats($b['meta'])];

        return ['hours' => array_map(fn ($h) => $h > $nowHour ? null : ['hour' => $h] + $out($hours[$h]), range(0, 23)), 'total' => $out($total),
            'slowest' => $slowest];
    }

    /**
     * Ang mga sequence event ng takbo (ran, skipped, failed) sa [$from, $to), ayon sa oras at ID, isa-isang ibinibigay sa
     * $each kasama ang detail; kada oras ang query at walang row na itinatago. $upTo: ang mga event hanggang sa ID na ito
     * lang. Ibinabalik ang pinakamataas na ID na nabasa (0 kapag wala).
     */
    private function stepEvents(?string $page, string $from, string $to, int $budget, \Closure $each, ?int $upTo = null): int
    {
        $maxId = 0;
        foreach (self::windows($from, $to) as [$a, $b]) {
            $rows = DB::table('chatbot_events')->where('type', 'sequence')->where('created_at', '>=', $a)->where('created_at', '<', $b)
                ->when($page !== null, fn ($q) => $q->where('page_id', (int) $page))->when($upTo !== null, fn ($q) => $q->where('id', '<=', $upTo))
                ->orderBy('created_at')->orderBy('id')->select(['id', 'page_id', 'psid', 'detail', 'created_at'])->cursor();
            foreach ($rows as $e) {
                $maxId = max($maxId, (int) $e->id);
                $d = json_decode((string) $e->detail, true);
                if (is_array($d) && in_array($d['op'] ?? null, ['ran', 'skipped', 'failed'], true)) $each($e, $d);
            }
            self::checkBudget($budget);
        }

        return $maxId;
    }

    /**
     * Para sa orihinal na due ng bawat step: ang mga naunang sequence event ng parehong customer at sequence (subscribed,
     * ran, skipped, failed: ang mga sandaling itinakda ng subscribe at ng advance ang next_send_at), kada page:PSID:pangalan,
     * ayon sa oras, bilang isang string na hinati ng "\n". Ang mga customer + sequence na may step ngayong araw lang
     * ($first: ang unang takbo nila), at bago ang unang takbong iyon ay ang huli lang ang kailangan.
     * @return array<string, string>
     */
    private function stepHistory(array $pages, array $psids, array $first, string $until, int $budget): array
    {
        $before = [];
        foreach (array_chunk($psids, 500) as $chunk) {
            $rows = DB::table('chatbot_events')->where('type', 'sequence')->whereIn('page_id', $pages)->whereIn('psid', $chunk)->where('created_at', '<', $until)
                ->orderBy('created_at')->orderBy('id')->select(['page_id', 'psid', 'detail', 'created_at'])->cursor();
            foreach ($rows as $r) {
                $d = json_decode((string) $r->detail, true);
                if (!is_array($d) || !is_string($d['name'] ?? null) || !in_array($d['op'] ?? null, ['subscribed', 'ran', 'skipped', 'failed'], true)) continue;
                $k = $r->page_id . ':' . $r->psid . ':' . $d['name'];
                if (!isset($first[$k])) continue;
                $t = (string) $r->created_at;
                if ($t < $first[$k] || !isset($before[$k])) $before[$k] = $t;
                else $before[$k] .= "\n" . $t;
            }
            self::checkBudget($budget);
        }

        return $before;
    }

    /**
     * Ang delay ng bawat step ng mga sequence na may pangalang $names (sequence ID:position => [amount, unit]) at ang ID
     * ng bawat pangalan (name:pangalan => ID).
     * @return array<string, array{0: int, 1: string}|int>
     */
    private function stepDelays(array $names): array
    {
        $delays = [];
        if ($names && Schema::hasTable('chatbot_sequence_steps')) {
            $ids = DB::table('chatbot_sequences')->whereIn('name', $names)->pluck('id', 'name');
            foreach (DB::table('chatbot_sequence_steps')->whereIn('sequence_id', $ids->values()->all())->get(['sequence_id', 'position', 'delay_amount', 'delay_unit']) as $s) {
                $delays[$s->sequence_id . ':' . $s->position] = [(int) $s->delay_amount, (string) $s->delay_unit];
            }
            foreach ($ids as $name => $id) $delays['name:' . $name] = (int) $id;
        }

        return $delays;
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
        $history = $before[$e->page_id . ':' . $e->psid . ':' . $name] ?? null;
        foreach ($history === null ? [] : explode("\n", $history) as $t) {
            if ($t >= (string) $e->created_at) break;
            $prev = $t;
        }
        if ($delay === null || $prev === null) return $recorded;

        return ChatbotSequences::dueAt(\Carbon\Carbon::parse($prev), $delay[0], $delay[1])->format('Y-m-d H:i:s');
    }

    /** Ang laki ng isang padala sa stepSends(): pack('JJJC') = sent_at, sequence ID, oras ng Meta, flags. */
    private const SEND_BYTES = 25;
    private const SEND_DELIVERED = 1;
    private const SEND_BOTCAKE = 2;
    private const SEND_META = 4;

    /**
     * Ang mga padala ng sequence step (kind send), kada page:PSID:step, ayon sa sent_at. Siksik para magaan ang buong
     * araw: bawat padala ay SEND_BYTES sa isang string (sent_at, sequence ID, oras ng Meta kapag Forbeast at kumpirmado,
     * flags), binabasa ng stepSend().
     * @return array<string, string>
     */
    private function stepSends(string $startAt, string $endAt, ?string $page, int $budget): array
    {
        if (!Schema::hasColumn('chatbot_replies', 'sequence_id')) return [];
        $confirm = ChatbotReply::hasConfirm();
        $via = ChatbotReply::hasVia();
        $cols = array_merge(['page_id', 'psid', 'sequence_id', 'sequence_step', 'status', 'sent_at'], $confirm ? ['confirmed_at', 'confirmed_status'] : [], $via ? ['via'] : []);
        $out = [];
        foreach (self::windows($startAt, $endAt) as [$a, $b]) {
            $rows = DB::table('chatbot_replies')->where('kind', 'send')->whereNotNull('sequence_id')->where('sent_at', '>=', $a)->where('sent_at', '<', $b)
                ->when($page !== null, fn ($q) => $q->where('page_id', (int) $page))->orderBy('sent_at')->orderBy('id')->select($cols)->cursor();
            foreach ($rows as $r) {
                $real = $confirm && in_array($r->confirmed_status, SendStatus::DELIVERED, true) && $r->confirmed_at !== null;
                $botcake = !Gateways::valid($via ? $r->via : null);
                $meta = $real && !$botcake;
                $k = $r->page_id . ':' . $r->psid . ':' . (int) $r->sequence_step;
                $out[$k] ??= '';
                $out[$k] .= pack('JJJC', strtotime($r->sent_at), (int) $r->sequence_id, $meta ? strtotime($r->confirmed_at) : 0,
                    (in_array($r->status, SendStatus::DELIVERED, true) ? self::SEND_DELIVERED : 0) | ($botcake ? self::SEND_BOTCAKE : 0) | ($meta ? self::SEND_META : 0));
            }
            self::checkBudget($budget);
        }

        return $out;
    }

    /**
     * Ang padala ng isang takbo ng step: ang mga padala ng step sa loob ng STEP_PUSH_SECONDS bago ang event. pushed = ang
     * unang padala; ang sent sa Meta ay ang sa unang naipadala. null kapag walang padala.
     * @return array{pushed: int, delivered: bool, meta: ?int, botcake: bool}|null
     */
    private static function stepSend(string $sends, mixed $sequenceId, int $ranAt): ?array
    {
        $pushed = $first = null;
        for ($o = 0, $len = strlen($sends); $o < $len; $o += self::SEND_BYTES) {
            $s = unpack('Jat/Jseq/Jmeta/Cflags', $sends, $o);
            if ($s['at'] > $ranAt || $s['at'] < $ranAt - self::STEP_PUSH_SECONDS || (is_int($sequenceId) && $s['seq'] !== $sequenceId)) continue;
            $pushed ??= $s;
            if ($s['flags'] & self::SEND_DELIVERED) {
                $first = $s;
                break;
            }
        }
        if ($pushed === null) return null;

        return ['pushed' => $pushed['at'], 'delivered' => $first !== null, 'meta' => $first !== null && ($first['flags'] & self::SEND_META) ? $first['meta'] : null,
            'botcake' => (bool) (($first ?? $pushed)['flags'] & self::SEND_BOTCAKE)];
    }

    /** Bilang, average, p90 at max ng mga delay (segundo). */
    private static function delayStats(array $v): array
    {
        $s = self::stats($v);

        return ['n' => $s['n'], 'avg' => $s['avg'], 'p90' => $s['p90'], 'max' => $s['max']];
    }
}

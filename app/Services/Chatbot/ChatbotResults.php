<?php

namespace App\Services\Chatbot;

use App\Models\BotcakeChat;
use App\Models\PageSetting;
use App\Models\User;
use App\Services\ChatbotFields;
use App\Services\PageSettings;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ang report ng /chatbot/results (at ng results.json at chatbot:results): funnel, By bot, By page, By message, By sequence step, By ad, at
 * ang paghahambing ng dalawang flow version o dalawang version ng isang botfield. Mula sa talaan ng ChatbotTags; ang
 * delivered ay kinukuwenta ngayon mula sa macro_output at J&T (walang kopya). Bawat query ay may petsa sa isang index.
 * Bilang, pangalan at version lang ang lumalabas: walang text, pangalan o PSID ng customer (ang value ng botfield ay ang
 * sariling template ng bot).
 *
 * Spec 278: ang page at ang results.json ay hindi na bumubuo ng report (lampas 2 minuto sa malaking site). Ang
 * chatbot:results-warm (kada minuto) ang bumubuo ng kopya kada hiling (filter at compare) na may built_at; ang walang
 * kopya ay napupunta sa listahang "wanted" at binubuo sa susunod na takbo. Ang chatbot:results ay bumubuo pa rin agad
 * (report(), naka-cache nang 5 minuto).
 *
 * Spec 278-2: ang padala at chat (funnel, By bot, By page, By message, By sequence step, By ad, Compare) ay mula sa mga
 * bahagi ng araw (ResultsPieces: isang basa kada araw, puro column, walang JSON), pinagsasama rito; ang order, delivered,
 * unsubscribe at mga pangalan ay kinukuwenta pa rin sa build (mabilis ang mga ito).
 */
class ChatbotResults
{
    public const CACHE_SECONDS = 300;
    /** Ang default na view ay binubuo ulit kapag ganito na katanda ang kopya. */
    public const DEFAULT_STALE_SECONDS = 900;
    /** Gaano katagal itinatabi ang kopya: ang default 6 na oras, ang iba 1 oras (laging may huling kopya ang tumitingin). */
    public const DEFAULT_KEEP_SECONDS = 21600;
    public const COPY_KEEP_SECONDS = 3600;
    /** Ang wanted na hindi pa nabubuo pagkalipas nito ay tinatanggal (kapag may tumitingin pa, idadagdag ulit ng page). */
    public const WANTED_MAX_AGE = 1800;
    public const WANTED_PER_RUN = 3;
    public const WANTED_MAX = 50;
    /** Spec 278-2: ilang segundo ang isang takbo ng warm bago ito huminto sa pagitan ng mga bahagi ng araw. */
    public const WARM_SECONDS = 240;
    public const WANTED = 'chatbot-results:wanted:v3';
    private const COPY = 'chatbot-results:copy:v3:';
    private const WANTED_LOCK = 'chatbot-results:wanted-lock';
    public const DEFAULT_DAYS = 7;
    public const MAX_DAYS = 92;
    public const MAX_ROWS = 300;        // kada table
    public const MEDIAN_ROWS = 200000;  // ilang reply_seconds ang binabasa para sa median
    public const VALUE_MAX = 20000;     // letra ng value ng botfield sa output
    public const VIA = ['botcake', 'forbeast'];
    public const VERSIONS = 50;        // gaya ng KEEP_VERSIONS ng flow
    public const ORDER_WINDOW = [-1, 3]; // ts_date ng macro_output: mula 1 araw bago hanggang 3 araw pagkatapos ng order

    /** Gaano kalayo na ang J&T status (gaya ng Chatblast): ang pinakamalayong row ng waybill ang totoo. */
    private const PROGRESS = ['In Transit' => 1, 'Delivering' => 2, 'Delivered' => 3, 'For Return' => 3, 'Returned' => 3];

    private ResultsPieces $pieces;

    public function __construct(private PageSettings $settings, ?ResultsPieces $pieces = null)
    {
        $this->pieces = $pieces ?? new ResultsPieces();
    }

    // ---- Filters ----

    /**
     * Ang malinis na filter mula sa query string o sa command: page_id, flow_id at version_id ay digits lang (kung hindi,
     * null); from at to ay Y-m-d (Manila; default ang huling 7 araw, hanggang 92 araw).
     * via ay botcake o forbeast (ang bot na may dala ng chat), kung hindi ay null.
     * @return array{page_id: ?string, from: string, to: string, flow_id: ?string, version_id: ?string, via: ?string}
     */
    public static function filters(array $in): array
    {
        $str = fn (string $k, int $max) => is_scalar($in[$k] ?? null) ? mb_substr(trim((string) $in[$k]), 0, $max) : '';
        $id = fn (string $k) => ($v = $str($k, 20)) !== '' && ctype_digit($v) && (int) $v > 0 ? $v : null;
        $date = function (string $k) use ($str): ?string {
            $v = $str($k, 10);
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) return null;

            return $v;
        };
        $to = $date('to') ?? now('Asia/Manila')->toDateString();
        $from = $date('from') ?? Carbon::parse($to)->subDays(self::DEFAULT_DAYS - 1)->toDateString();
        if ($from > $to) [$from, $to] = [$to, $from];
        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) >= self::MAX_DAYS) $from = Carbon::parse($to)->subDays(self::MAX_DAYS - 1)->toDateString();

        $via = $str('via', 20);

        return ['page_id' => $id('page_id'), 'from' => $from, 'to' => $to, 'flow_id' => $id('flow_id'), 'version_id' => $id('version_id'),
            'via' => in_array($via, self::vias(), true) ? $via : null];
    }

    /** Ang VIA at ang pangalawang gateway kapag naka-set up (incepxionbot). @return array<int, string> */
    public static function vias(): array
    {
        return array_merge(self::VIA, Gateways::setUp(Gateways::INCEPXIONBOT) ? [Gateways::INCEPXIONBOT] : []);
    }

    /** Ang paghahambing sa query: compare=flow|botfield at a, b (digits). @return array{0: string, 1: int, 2: int}|null */
    public static function compareFrom(array $in): ?array
    {
        $kind = is_scalar($in['compare'] ?? null) ? (string) $in['compare'] : '';
        $a = is_scalar($in['a'] ?? null) ? (string) $in['a'] : '';
        $b = is_scalar($in['b'] ?? null) ? (string) $in['b'] : '';
        if (!in_array($kind, ['flow', 'botfield'], true) || !ctype_digit($a) || !ctype_digit($b) || strlen($a) > 20 || strlen($b) > 20) return null;

        return [$kind, (int) $a, (int) $b];
    }

    /** Ang buong sagot ng results.json at ng chatbot:results: ang report at ang paghahambing (kapag hiniling). */
    public function output(array $in): array
    {
        $f = self::filters($in);
        $c = self::compareFrom($in);

        return $this->report($f) + ['compare' => $c ? $this->compare($f, ...$c) : null];
    }

    // ---- Kopya (spec 278) ----

    /**
     * Ang malinis na anyo ng isang hiling (filter at compare), na siya ring ibinibigay ulit sa filters() at compareFrom():
     * ito ang itinatabi sa wanted at ang binubuo ng warm.
     */
    public static function request(array $in): array
    {
        $c = self::compareFrom($in);

        return array_filter(self::filters($in), fn ($v) => $v !== null) + ($c ? ['compare' => $c[0], 'a' => (string) $c[1], 'b' => (string) $c[2]] : []);
    }

    private static function requestId(array $in): string
    {
        return md5(json_encode([self::filters($in), self::compareFrom($in)]));
    }

    /** Ang naitabing kopya ng hiling (lahat ng sakop ng output()), o null. @return array{built_at: string, out: array}|null */
    public function stored(array $in): ?array
    {
        $copy = Cache::get(self::COPY . self::requestId($in));

        return is_array($copy) && isset($copy['built_at'], $copy['out']) ? $copy : null;
    }

    /**
     * Amendment 278-1: ang kopyang lampas 15 minuto ay luma na. Ipinapakita pa rin ng page (may as-of), pero idinadagdag
     * ulit sa wanted para may bago sa loob ng ilang minuto; ang warm ay nag-aalis lang ng wanted na sariwa na ang kopya.
     */
    public static function isStale(?array $copy): bool
    {
        return !$copy || Carbon::parse($copy['built_at'])->lt(now()->subSeconds(self::DEFAULT_STALE_SECONDS));
    }

    /** Idinadagdag sa wanted ang hiling na walang kopya; ang naunang oras ng hiling ang naiiwan (pinakamatanda muna ang warm). */
    public function want(array $in): void
    {
        $id = self::requestId($in);
        $this->editWanted(function (array $list) use ($id, $in) {
            // May hangganan ang listahan: kapag puno, hindi muna idinadagdag (idadagdag ulit ng page sa susunod na reload).
            if (!isset($list[$id]) && count($list) < self::WANTED_MAX) $list[$id] = ['in' => self::request($in), 'at' => now()->getTimestamp()];

            return $list;
        });
    }

    /** @return array<string, array{in: array, at: int}> */
    public function wanted(): array
    {
        $list = Cache::get(self::WANTED);

        return is_array($list) ? $list : [];
    }

    /** Ang pagbago ng wanted sa ilalim ng maikling lock (sabay ang page at ang warm); kapag hindi makuha, isinusulat pa rin. */
    private function editWanted(callable $edit): void
    {
        $write = fn () => Cache::put(self::WANTED, $edit($this->wanted()), self::COPY_KEEP_SECONDS);
        try {
            Cache::lock(self::WANTED_LOCK, 10)->block(3, $write);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            $write();
        }
    }

    /** Binubuo (hindi mula sa cache ng report) at itinatabi ang kopya ng isang hiling. @return array{built_at: string, out: array} */
    public function buildCopy(array $in, bool $default = false): array
    {
        $f = self::filters($in);
        $c = self::compareFrom($in);
        $copy = ['built_at' => now()->toIso8601String(), 'out' => $this->build($f) + ['compare' => $c ? $this->compareBuild($f, ...$c) : null]];
        Cache::put(self::COPY . self::requestId($in), $copy, $default ? self::DEFAULT_KEEP_SECONDS : self::COPY_KEEP_SECONDS);

        return $copy;
    }

    /** Maikling pangalan ng hiling para sa log (walang customer data: page id, petsa, flow, via, compare). */
    public static function label(array $in): string
    {
        $r = self::request($in);
        $parts = [$r['from'] . '..' . $r['to']];
        foreach (['page_id' => 'page', 'flow_id' => 'flow', 'version_id' => 'version', 'via' => 'via'] as $k => $name) {
            if (isset($r[$k])) $parts[] = $name . ' ' . $r[$k];
        }
        if (isset($r['compare'])) $parts[] = 'compare ' . $r['compare'] . ' ' . $r['a'] . '/' . $r['b'];

        return implode(' ', $parts);
    }

    /**
     * Isang takbo ng warm: ang default na view (filters([])) kapag wala o lampas 15 minuto na ang kopya, saka ang wanted
     * (pinakamatanda muna, hanggang 3); ang wanted na lampas 30 minuto ay tinatanggal. Spec 278-2: bawat hiling ay ang
     * mga bahagi ng araw na wala o luma muna (ResultsPieces), sa loob ng $budget na segundo kada takbo (laging kahit isang
     * bahagi); ang kopya ay ginagawa kapag kumpleto na ang mga bahagi, kung hindi ay itutuloy sa susunod na takbo (nasa
     * wanted pa, "binubuo" pa ang page). Ang pumalyang build ay nire-report at tinatanggal sa wanted (idadagdag ulit ng
     * page kapag may tumitingin pa); hindi nito pinipigilan ang iba.
     * @return array{built: array<int, array{0: string, 1: float}>, failed: array<int, string>, dropped: int, pieces: int, pending: array<int, string>}
     */
    public function warm(?int $budget = null): array
    {
        $budget ??= self::WARM_SECONDS;
        $t0 = microtime(true);
        $r = ['built' => [], 'failed' => [], 'dropped' => 0, 'pieces' => 0, 'pending' => []];
        $stop = false;
        // true: tapos na ang hiling (may kopya o pumalya); false: may bahagi pang kulang (lampas na sa oras ng takbo).
        $run = function (string $label, array $in, bool $default) use (&$r, &$stop, $t0, $budget): bool {
            $t = microtime(true);
            try {
                foreach ($this->pending($in) as [$day, $scope]) {
                    if ($r['pieces'] > 0 && microtime(true) - $t0 >= $budget) {
                        $stop = true;
                        $r['pending'][] = $label;

                        return false;
                    }
                    $this->pieces->build($day, $scope);
                    $r['pieces']++;
                }
                $this->buildCopy($in, $default);
                $r['built'][] = [$label, round(microtime(true) - $t, 1)];
            } catch (\Throwable $e) {
                report($e);
                $r['failed'][] = $label;
            }

            return true;
        };

        $default = self::request([]);
        if (self::isStale($this->stored($default))) $run('default', $default, true);
        if ($stop) return $r;

        // Ang lampas 30 minuto at ang may sariwang kopya na (hal. ang default) ay inaalis; ang natitira ay pinakamatanda muna.
        // Ang may lumang kopya (amendment 278-1) ay binubuo ulit.
        $oldest = now()->getTimestamp() - self::WANTED_MAX_AGE;
        $due = [];
        $this->editWanted(function (array $list) use ($oldest, &$due, &$r) {
            foreach ($list as $id => $w) {
                if (!is_array($w['in'] ?? null) || (int) ($w['at'] ?? 0) < $oldest || !self::isStale($this->stored($w['in']))) {
                    unset($list[$id]);
                    $r['dropped']++;
                }
            }
            uasort($list, fn ($a, $b) => (int) $a['at'] <=> (int) $b['at']);
            $due = array_slice($list, 0, self::WANTED_PER_RUN, true);

            return $list;
        });
        foreach ($due as $id => $w) {
            // Ang default na hiling (kapag pumalya ang sariling build nito sa itaas) ay 6 na oras pa rin itinatago.
            if (!$run(self::label($w['in']), $w['in'], self::requestId($w['in']) === self::requestId($default))) break;
            $this->editWanted(function (array $list) use ($id) {
                unset($list[$id]);

                return $list;
            });
        }

        return $r;
    }

    /** @return array{0: Carbon, 1: Carbon} [simula, dulo) sa timezone ng app */
    private function bounds(array $f): array
    {
        $tz = config('app.timezone');

        return [Carbon::parse($f['from'], 'Asia/Manila')->startOfDay()->timezone($tz), Carbon::parse($f['to'], 'Asia/Manila')->addDay()->startOfDay()->timezone($tz)];
    }

    private static function pct(int|float $a, int|float $b): ?float
    {
        return $b > 0 ? round(100 * $a / $b, 1) : null;
    }

    private static function median(array $v): ?int
    {
        if (!$v) return null;
        sort($v);
        $n = count($v);

        return (int) round($n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2);
    }

    // ---- Report ----

    public function report(array $f): array
    {
        // v3: mula sa mga bahagi ng araw (spec 278-2); ang lumang cache ay hindi na binabasa pagka-deploy.
        return Cache::remember('chatbot-results:v3:' . md5(json_encode($f)), self::CACHE_SECONDS, fn () => $this->build($f));
    }

    /**
     * Ang mga bahagi ng araw na kailangan ng hiling: ang saklaw ng funnel (page, flow, version) at ang page lang (By
     * sequence step, By ad, chats) kada araw, at ang sa Compare. @return array<int, array{0: string, 1: array}>
     */
    private function needs(array $in): array
    {
        $f = self::filters($in);
        $c = self::compareFrom($in);
        $scopes = [ResultsPieces::main($f)];
        if (ResultsPieces::base($f) !== $scopes[0]) $scopes[] = ResultsPieces::base($f);
        if ($c) foreach ([$c[1], $c[2]] as $id) $scopes[] = $c[0] === 'flow' ? ResultsPieces::flowVersion($f, $id) : ResultsPieces::botfield($f, $id);
        $out = [];
        foreach (ResultsPieces::days($f['from'], $f['to']) as $day) foreach (array_unique($scopes, SORT_REGULAR) as $s) $out[] = [$day, $s];

        return $out;
    }

    /** Ang mga bahagi ng hiling na wala pa o luma na (ang binubuo ng warm, ilan kada takbo). */
    public function pending(array $in): array
    {
        return array_values(array_filter($this->needs($in), fn ($n) => ResultsPieces::stale($n[0], $this->pieces->get($n[0], $n[1]))));
    }

    /** Ang mga bahagi ng isang saklaw sa range (binubuo ang wala o luma). @return array<int, array> */
    private function piecesOf(array $f, array $scope): array
    {
        return array_map(fn ($day) => $this->pieces->ensure($day, $scope), ResultsPieces::days($f['from'], $f['to']));
    }

    private function build(array $f): array
    {
        [$start, $end] = $this->bounds($f);
        $names = BotcakeChat::pageMap();
        $allOrders = $this->orders($f, $start, $end);
        $orders = $allOrders->filter(fn ($o) => $f['via'] === null || self::orderVia($o) === $f['via']);
        $delivery = $this->deliveries($allOrders);
        $flowOrders = $orders->filter(fn ($o) => ($f['flow_id'] === null || (int) $o->flow_id === (int) $f['flow_id'])
            && ($f['version_id'] === null || (int) ($o->detail['flow_version'] ?? 0) === (int) $f['version_id']));
        // Spec 278-2: ang padala at chat ay mula sa mga bahagi ng araw (isang basa kada araw), pinagsama rito.
        $main = $this->piecesOf($f, ResultsPieces::main($f));
        $base = ResultsPieces::base($f) === ResultsPieces::main($f) ? $main : $this->piecesOf($f, ResultsPieces::base($f));
        $buckets = $f['via'] !== null ? [$f['via']] : null;
        $sends = self::pageCounts($main, $buckets);
        $chats = self::chatCount($base, $buckets);

        return [
            'filters' => $f + ['page' => $f['page_id'] !== null ? ($names[$f['page_id']] ?? null) : null,
                'flow' => $f['flow_id'] !== null ? DB::table('chatbot_flows')->where('id', (int) $f['flow_id'])->value('name') : null],
            'funnel' => ['chats' => $chats] + $this->counted(self::sumPages($sends), $flowOrders, $delivery),
            'by_via' => $this->byVia($f, $allOrders, $delivery, $main, $base),
            'by_page' => $this->byPage($sends, $flowOrders, $delivery, $names),
            'by_message' => $this->byMessage($main, $buckets, $flowOrders, $delivery),
            'by_step' => $this->bySequenceStep($f, $start, $end, $base, $buckets, $orders, $delivery),
            'by_ad' => $this->byAd($base, $buckets, $orders, $delivery),
            'versions' => $this->versions($f['flow_id'] !== null ? (int) $f['flow_id'] : null),
        ];
    }

    private static function inBuckets(string $bucket, ?array $buckets): bool
    {
        return $buckets === null || in_array($bucket, $buckets, true);
    }

    /**
     * Kada page, ang sent, replied at customer (distinct psid: unyon ng mga set ng bawat araw) ng mga bot sa $buckets
     * (null = lahat). Ang page na walang padala sa mga bot na ito ay wala (gaya ng dating WHERE ng via).
     * @return array<string, array{sent: int, customers_sent: int, replied: int}>
     */
    private static function pageCounts(array $pieces, ?array $buckets): array
    {
        $sets = [];
        $out = [];
        foreach ($pieces as $piece) {
            foreach ($piece['sends'] ?? [] as $page => $byBot) {
                foreach ($byBot as $bucket => [$sent, $replied, $psids]) {
                    if (!self::inBuckets((string) $bucket, $buckets) || $sent === 0) continue;
                    $out[(string) $page] ??= ['sent' => 0, 'customers_sent' => 0, 'replied' => 0];
                    $out[(string) $page]['sent'] += $sent;
                    $out[(string) $page]['replied'] += $replied;
                }
            }
        }
        // Ang unyon ay kada page (hindi lahat ng page nang sabay) para hindi lumaki ang memory.
        foreach (array_keys($out) as $page) {
            $set = [];
            foreach ($pieces as $piece) {
                foreach ($piece['sends'][$page] ?? [] as $bucket => $c) if (self::inBuckets((string) $bucket, $buckets)) ResultsPieces::addTo($set, $c[2]);
            }
            $out[$page]['customers_sent'] = count($set);
        }
        // Ang ayos ng page ay gaya ng dating GROUP BY page_id.
        uksort($out, fn ($a, $b) => (int) $a <=> (int) $b);

        return $out;
    }

    /** @return array{sent: int, customers_sent: int, replied: int} */
    private static function sumPages(array $pages): array
    {
        $t = ['sent' => 0, 'customers_sent' => 0, 'replied' => 0];
        foreach ($pages as $row) foreach ($t as $k => $_) $t[$k] += $row[$k];

        return $t;
    }

    /** Ilang customer (page + PSID) ang nag-chat: unyon ng mga araw kada page, ng mga bot sa $buckets (null = lahat). */
    private static function chatCount(array $pieces, ?array $buckets): int
    {
        $pages = [];
        foreach ($pieces as $piece) foreach (array_keys($piece['chats'] ?? []) as $p) $pages[$p] = true;
        $n = 0;
        foreach (array_keys($pages) as $p) {
            $set = [];
            foreach ($pieces as $piece) {
                foreach ($piece['chats'][$p] ?? [] as $bucket => $packed) if (self::inBuckets((string) $bucket, $buckets)) ResultsPieces::addTo($set, $packed);
            }
            $n += count($set);
        }

        return $n;
    }

    /** Ilang customer (page + PSID) ang nag-chat sa [$start, $end), ayon sa filter ng page at via (direktang basa). */
    private function chats(array $f, Carbon $start, Carbon $end): int
    {
        $n = 0;
        foreach ($this->pieces->chatSets($f['page_id'] ?? null, $start, $end) as $byBot) {
            $set = [];
            foreach ($byBot as $bucket => $psids) if (($f['via'] ?? null) === null || $bucket === $f['via']) $set += $psids;
            $n += count($set);
        }

        return $n;
    }

    /** Ang via ng isang order; ang wala (luma) ay botcake. */
    private static function orderVia(object $o): string
    {
        return Gateways::valid($o->detail['via'] ?? null) ? $o->detail['via'] : 'botcake';
    }

    /** Ang funnel ng bawat bot (botcake, forbeast) sa range, kahit anong filter ng via; mula sa parehong mga bahagi. */
    private function byVia(array $f, Collection $allOrders, array $delivery, array $main, array $base): array
    {
        $out = [];
        foreach (self::vias() as $via) {
            $orders = $allOrders->filter(fn ($o) => self::orderVia($o) === $via
                && ($f['flow_id'] === null || (int) $o->flow_id === (int) $f['flow_id'])
                && ($f['version_id'] === null || (int) ($o->detail['flow_version'] ?? 0) === (int) $f['version_id']));
            $out[$via] = ['chats' => self::chatCount($base, [$via])] + $this->counted(self::sumPages(self::pageCounts($main, [$via])), $orders, $delivery);
        }

        return $out;
    }

    /**
     * Kada page, magkakatabi (parehong filter ng funnel: petsa, page, via, flow at version): padala, customer, sagot, order,
     * delivered, at ang hati ng order sa main flow (walang sequence_id o sequence_step sa detail, gaya ng By sequence step)
     * at pagkatapos ng sequence step. Ang padala ay mula sa pageCounts(); ang order at delivered ay mula sa naka-load na.
     * Ang total ay katumbas ng funnel.
     * @param array<string, string> $names page id => pangalan
     * @return array{rows: array<int, array<string, mixed>>, total: array<string, mixed>}
     */
    private function byPage(array $sends, Collection $orders, array $delivery, array $names): array
    {
        $blank = ['sent' => 0, 'customers_sent' => 0, 'replied' => 0, 'orders' => 0, 'main_flow' => 0, 'after_sequence' => 0, 'delivered' => 0];
        $rows = [];
        foreach ($sends as $page => $r) $rows[(string) $page] = $r + $blank;
        foreach ($orders as $o) {
            $p = (string) $o->page_id;
            $rows[$p] ??= $blank;
            $rows[$p]['orders']++;
            $rows[$p][empty($o->detail['sequence_id']) || empty($o->detail['sequence_step']) ? 'main_flow' : 'after_sequence']++;
            if (($delivery[$o->id]['delivery'] ?? '') === 'delivered') $rows[$p]['delivered']++;
        }

        $total = $blank;
        foreach ($rows as $r) foreach ($blank as $k => $_) $total[$k] += $r[$k];
        $rates = fn (array $r) => $r + ['replied_pct' => self::pct($r['replied'], $r['sent']), 'orders_pct' => self::pct($r['orders'], $r['customers_sent']),
            'main_flow_pct' => self::pct($r['main_flow'], $r['orders']), 'after_sequence_pct' => self::pct($r['after_sequence'], $r['orders']),
            'delivered_pct' => self::pct($r['delivered'], $r['orders'])];
        $out = [];
        foreach ($rows as $id => $r) $out[] = ['page_id' => (string) $id, 'page' => $names[(string) $id] ?? (string) $id] + $rates($r);
        usort($out, fn ($a, $b) => [$b['orders'], $b['sent'], $a['page']] <=> [$a['orders'], $a['sent'], $b['page']]);

        return ['rows' => array_slice($out, 0, self::MAX_ROWS), 'total' => $rates($total)];
    }

    /**
     * Ang mga order (chatbot_events type order) sa range at page, may detail na naka-decode. psid at page_id ay para sa
     * pagtugma sa macro_output lang (hindi lumalabas).
     */
    private function orders(array $f, Carbon $start, Carbon $end): Collection
    {
        return DB::table('chatbot_events')->where('type', 'order')->where('created_at', '>=', $start)->where('created_at', '<', $end)
            ->when($f['page_id'], fn ($q, $v) => $q->where('page_id', (int) $v))
            ->orderBy('id')->get(['id', 'page_id', 'psid', 'flow_id', 'created_at', 'detail'])
            ->map(function ($o) {
                $o->detail = (array) json_decode((string) $o->detail, true);
                return $o;
            });
    }

    /**
     * Ang anyo ng dating metrics() mula sa nabilang na: sent, customers, replied (at ang median kapag mayroon), saka ang
     * mga order at ang delivered, RTS, in transit, other at unmatched nila.
     * @param array{sent: int, customers_sent: int, replied: int} $c
     */
    private function counted(array $c, Collection $orders, array $delivery, array $median = []): array
    {
        $tally = ['delivered' => 0, 'rts' => 0, 'in_transit' => 0, 'other' => 0, 'unmatched' => 0];
        foreach ($orders as $o) $tally[$delivery[$o->id]['delivery'] ?? 'unmatched']++;
        $n = $orders->count();

        return ['sent' => $c['sent'], 'customers_sent' => $c['customers_sent'], 'replied' => $c['replied'], 'replied_pct' => self::pct($c['replied'], $c['sent'])] + $median
            + ['orders' => $n, 'orders_pct' => self::pct($n, $c['customers_sent']), 'delivered' => $tally['delivered'], 'delivered_pct' => self::pct($tally['delivered'], $n),
            'rts' => $tally['rts'], 'rts_pct' => self::pct($tally['rts'], $n), 'in_transit' => $tally['in_transit'], 'other' => $tally['other'], 'unmatched' => $tally['unmatched']];
    }

    /** Ang susi ng grupo ng By message (flow, node, botfield, version), gaya ng dati. */
    public static function groupKey(?int $flow, ?string $node, ?string $botfield, ?int $setting): string
    {
        return ($flow ?? '') . '|' . ($node ?? '') . '|' . ($botfield ?? '') . '|' . ($setting ?? '');
    }

    private static function tagKey(object $t): string
    {
        return ($t->flow_id ?? '') . '|' . ($t->node_id ?? '') . '|' . ($t->botfield ?? '') . '|' . ($t->setting_id ?? '');
    }

    /**
     * Ang mga order na sumunod sa bawat grupo: ang order ay binibilang sa bawat grupo (flow, node, botfield, version) ng
     * mga padala nito (ang huling 5 padala bago ang order).
     * @return array<string, array{orders: int, delivered: int}>
     */
    private function ordersAfter(Collection $orders, array $delivery): array
    {
        $ids = $orders->flatMap(fn ($o) => array_map('intval', (array) ($o->detail['sends'] ?? [])))->unique()->values();
        $keys = [];
        foreach ($ids->chunk(1000) as $chunk) {
            foreach (DB::table('chatbot_reply_tags')->whereIn('reply_id', $chunk->all())->get(['reply_id', 'flow_id', 'node_id', 'botfield', 'setting_id']) as $t) {
                $keys[(int) $t->reply_id][self::tagKey($t)] = true;
            }
        }
        $out = [];
        foreach ($orders as $o) {
            $hit = [];
            foreach ((array) ($o->detail['sends'] ?? []) as $id) $hit += $keys[(int) $id] ?? [];
            foreach (array_keys($hit) as $k) {
                $out[$k]['orders'] = ($out[$k]['orders'] ?? 0) + 1;
                $out[$k]['delivered'] = ($out[$k]['delivered'] ?? 0) + (($delivery[$o->id]['delivery'] ?? '') === 'delivered' ? 1 : 0);
            }
        }

        return $out;
    }

    /** Ang botfield version (page_settings row): label, petsa ng pagbago, value (hanggang VALUE_MAX), at kung active pa. */
    private function settingInfo(array $ids): array
    {
        if (!$ids) return [];
        $labels = array_column(app(ChatbotFields::class)->columns(), 'label', 'key');

        return PageSetting::whereIn('id', $ids)->get()->mapWithKeys(fn ($s) => [(int) $s->id => [
            'label' => $labels[$s->key] ?? $s->key, 'changed_on' => $s->created_at?->timezone('Asia/Manila')->toDateString(),
            'value' => $s->is_secret ? '' : mb_substr((string) $s->plainValue(), 0, self::VALUE_MAX), 'active' => (bool) $s->active,
        ]])->all();
    }

    private function flowNames(array $ids): array
    {
        return $ids ? DB::table('chatbot_flows')->whereIn('id', $ids)->pluck('name', 'id')->all() : [];
    }

    /**
     * Ang median ng pinakabagong MEDIAN_ROWS (pinakamataas na id, lahat ng grupo) ng mga [id, grupo, seconds] ng mga
     * bahagi, gaya ng dating ORDER BY id DESC LIMIT. $group: ang susi ng grupo mula sa index ng bahagi.
     * @return array<string, array<int, int>> grupo => mga segundo
     */
    private static function newestSeconds(array $pieces, ?array $buckets, callable $group): array
    {
        $ids = $groups = $secs = [];
        foreach ($pieces as $piece) {
            foreach ($piece['seconds'] ?? [] as $bucket => [$pid, $pkey, $psec]) {
                if (!self::inBuckets((string) $bucket, $buckets)) continue;
                $pieceIds = ResultsPieces::ints($pid, 'J');
                $pieceKeys = ResultsPieces::ints($pkey);
                $pieceSecs = ResultsPieces::ints($psec);
                foreach ($pieceIds as $i => $id) {
                    $ids[] = $id;
                    $groups[] = $group($piece, $pieceKeys[$i]);
                    $secs[] = $pieceSecs[$i];
                }
                // Hindi hihigit sa dalawang beses ng MEDIAN_ROWS ang hawak: ang pinakabago lang ang naiiwan.
                if (count($ids) > 2 * self::MEDIAN_ROWS) {
                    array_multisort($ids, SORT_DESC, SORT_NUMERIC, $groups, $secs);
                    array_splice($ids, self::MEDIAN_ROWS);
                    array_splice($groups, self::MEDIAN_ROWS);
                    array_splice($secs, self::MEDIAN_ROWS);
                }
            }
        }
        if (!$ids) return [];
        array_multisort($ids, SORT_DESC, SORT_NUMERIC, $groups, $secs);
        $out = [];
        foreach (array_slice($groups, 0, self::MEDIAN_ROWS, true) as $i => $g) $out[$g][] = $secs[$i];

        return $out;
    }

    /**
     * By message mula sa mga bahagi: kada grupo ang sent at replied (kabuuan ng mga araw), ayos gaya ng dati (pinakamaraming
     * padala, saka flow, node, version), hanggang MAX_ROWS; ang median mula sa newestSeconds().
     */
    private function byMessage(array $pieces, ?array $buckets, Collection $orders, array $delivery): array
    {
        $totals = [];
        foreach ($pieces as $piece) {
            foreach ($piece['messages']['counts'] ?? [] as $bucket => $byKey) {
                if (!self::inBuckets((string) $bucket, $buckets)) continue;
                foreach ($byKey as $i => [$sent, $replied]) {
                    $tuple = $piece['messages']['keys'][$i];
                    $k = self::groupKey(...$tuple);
                    $totals[$k] ??= ['tuple' => $tuple, 'sent' => 0, 'replied' => 0];
                    $totals[$k]['sent'] += $sent;
                    $totals[$k]['replied'] += $replied;
                }
            }
        }
        if (!$totals) return [];
        // Gaya ng ORDER BY sent DESC, flow_id, node_id, setting_id (ang NULL ay nauuna).
        $nullFirst = fn ($v) => $v === null ? [0, ''] : [1, $v];
        uasort($totals, fn ($a, $b) => [$b['sent'], $nullFirst($a['tuple'][0]), $nullFirst($a['tuple'][1]), $nullFirst($a['tuple'][3])]
            <=> [$a['sent'], $nullFirst($b['tuple'][0]), $nullFirst($b['tuple'][1]), $nullFirst($b['tuple'][3])]);
        $groups = array_slice($totals, 0, self::MAX_ROWS, true);

        // Ang reply_seconds ay laging kasama ng replied_at (ChatbotTags): kapag walang sinagot sa mga grupong ito, wala ring median.
        $seconds = array_sum(array_column($groups, 'replied')) > 0
            ? self::newestSeconds(array_map(fn ($p) => ['seconds' => $p['messages']['seconds'] ?? []] + $p, $pieces), $buckets, fn ($p, $i) => self::groupKey(...$p['messages']['keys'][$i]))
            : [];
        $after = $this->ordersAfter($orders, $delivery);
        $info = $this->settingInfo(array_values(array_unique(array_filter(array_map(fn ($g) => $g['tuple'][3], $groups), fn ($v) => $v !== null))));
        $flows = $this->flowNames(array_values(array_unique(array_filter(array_map(fn ($g) => $g['tuple'][0], $groups), fn ($v) => $v !== null))));

        return array_values(array_map(function ($g) use ($seconds, $after, $info, $flows) {
            [$flow, $node, $botfield, $setting] = $g['tuple'];
            $k = self::groupKey($flow, $node, $botfield, $setting);
            $s = $setting !== null ? ($info[$setting] ?? null) : null;

            return ['flow_id' => $flow, 'flow' => $flows[$flow] ?? null, 'node' => $node,
                'botfield' => $botfield, 'label' => $s['label'] ?? $botfield, 'version' => $setting,
                'changed_on' => $s['changed_on'] ?? null, 'value' => $s['value'] ?? null, 'active' => $s['active'] ?? null,
                'sent' => $g['sent'], 'replied' => $g['replied'], 'replied_pct' => self::pct($g['replied'], $g['sent']),
                'median_reply_seconds' => self::median($seconds[$k] ?? []), 'orders' => $after[$k]['orders'] ?? 0, 'delivered' => $after[$k]['delivered'] ?? 0];
        }, $groups));
    }

    /**
     * Kada step ng sequence (page at petsa lang; ang step ay may sariling flow): padala, sagot (mula sa mga bahagi),
     * ilang nag-unsubscribe (ang huling step na naipadala sa kanila bago umalis), at ang mga order na ang huling step ng
     * customer ay ito.
     */
    private function bySequenceStep(array $f, Carbon $start, Carbon $end, array $pieces, ?array $buckets, Collection $orders, array $delivery): array
    {
        $rows = [];
        $key = fn ($seq, $step) => (int) $seq . ':' . (int) $step;
        foreach ($pieces as $piece) {
            foreach ($piece['steps'] ?? [] as $bucket => $bySteps) {
                if (!self::inBuckets((string) $bucket, $buckets)) continue;
                foreach ($bySteps as $k => [$seq, $step, $sent, $replied]) {
                    $rows[$k] ??= ['sequence_id' => $seq, 'step' => $step, 'sent' => 0, 'replied' => 0];
                    $rows[$k]['sent'] += $sent;
                    $rows[$k]['replied'] += $replied;
                }
            }
        }
        $blank = fn ($seq, $step) => ['sequence_id' => (int) $seq, 'step' => (int) $step, 'sent' => 0, 'replied' => 0];

        $unsub = [];
        if (Schema::hasTable('chatbot_sequence_subscriptions')) {
            $subs = DB::table('chatbot_sequence_subscriptions')->where('status', 'unsubscribed')->where('updated_at', '>=', $start)->where('updated_at', '<', $end)
                ->when($f['page_id'], fn ($q, $v) => $q->where('page_id', (int) $v))->limit(50000)->get(['sequence_id', 'page_id', 'psid', 'updated_at']);
            foreach ($subs->chunk(1000) as $chunk) {
                $sent = DB::table('chatbot_replies')->whereIn('psid', $chunk->pluck('psid')->unique()->values()->all())->whereNotNull('sequence_id')
                    ->where('sent_at', '>=', $start->copy()->subDays(self::MAX_DAYS))->where('sent_at', '<', $end)
                    ->orderBy('sent_at')->get(['page_id', 'psid', 'sequence_id', 'sequence_step', 'sent_at'])
                    ->groupBy(fn ($r) => $r->page_id . ':' . $r->psid . ':' . $r->sequence_id);
                foreach ($chunk as $s) {
                    $last = ($sent[$s->page_id . ':' . $s->psid . ':' . $s->sequence_id] ?? collect())->filter(fn ($r) => (string) $r->sent_at <= (string) $s->updated_at)->last();
                    if ($last) $unsub[$key($last->sequence_id, $last->sequence_step)] = ($unsub[$key($last->sequence_id, $last->sequence_step)] ?? 0) + 1;
                }
            }
        }

        $ordered = [];
        foreach ($orders as $o) {
            if (empty($o->detail['sequence_id']) || empty($o->detail['sequence_step'])) continue;
            $k = $key($o->detail['sequence_id'], $o->detail['sequence_step']);
            $ordered[$k]['orders'] = ($ordered[$k]['orders'] ?? 0) + 1;
            $ordered[$k]['delivered'] = ($ordered[$k]['delivered'] ?? 0) + (($delivery[$o->id]['delivery'] ?? '') === 'delivered' ? 1 : 0);
        }
        foreach (array_keys($unsub + $ordered) as $k) {
            [$seq, $step] = explode(':', $k);
            $rows[$k] ??= $blank($seq, $step);
        }
        $names = $rows ? DB::table('chatbot_sequences')->whereIn('id', array_unique(array_column($rows, 'sequence_id')))->pluck('name', 'id')->all() : [];
        uasort($rows, fn ($a, $b) => [$names[$a['sequence_id']] ?? '', $a['sequence_id'], $a['step']] <=> [$names[$b['sequence_id']] ?? '', $b['sequence_id'], $b['step']]);

        return array_values(array_map(fn ($r) => ['sequence_id' => $r['sequence_id'], 'sequence' => $names[$r['sequence_id']] ?? '#' . $r['sequence_id'], 'step' => $r['step'],
            'sent' => $r['sent'], 'replied' => $r['replied'], 'replied_pct' => self::pct($r['replied'], $r['sent']),
            'unsubscribed' => $unsub[$key($r['sequence_id'], $r['step'])] ?? 0,
            'orders' => $ordered[$key($r['sequence_id'], $r['step'])]['orders'] ?? 0, 'delivered' => $ordered[$key($r['sequence_id'], $r['step'])]['delivered'] ?? 0], array_slice($rows, 0, self::MAX_ROWS)));
    }

    /** Kada ad (page at petsa lang): ilang customer ang naabot ng bot (unyon ng mga araw), ilang order, ilang delivered; ang pangalan mula sa ads_manager_reports. */
    private function byAd(array $pieces, ?array $buckets, Collection $orders, array $delivery): array
    {
        $sets = [];
        foreach ($pieces as $piece) {
            foreach ($piece['ads'] ?? [] as $bucket => $byAd) {
                if (!self::inBuckets((string) $bucket, $buckets)) continue;
                foreach ($byAd as $ad => $packed) {
                    $sets[(string) $ad] ??= [];
                    ResultsPieces::addTo($sets[(string) $ad], $packed);
                }
            }
        }
        $rows = [];
        foreach ($sets as $ad => $set) $rows[(string) $ad] = ['ad_id' => (string) $ad, 'chats' => count($set), 'orders' => 0, 'delivered' => 0];
        unset($sets);
        foreach ($orders as $o) {
            $ad = trim((string) ($o->detail['ad_id'] ?? ''));
            if ($ad === '') continue;
            $rows[$ad] ??= ['ad_id' => $ad, 'chats' => 0, 'orders' => 0, 'delivered' => 0];
            $rows[$ad]['orders']++;
            if (($delivery[$o->id]['delivery'] ?? '') === 'delivered') $rows[$ad]['delivered']++;
        }
        uasort($rows, fn ($a, $b) => [$b['chats'], $b['orders'], $a['ad_id']] <=> [$a['chats'], $a['orders'], $b['ad_id']]);
        $rows = array_slice($rows, 0, self::MAX_ROWS, true);

        $names = [];
        if ($rows && Schema::hasTable('ads_manager_reports')) {
            foreach (DB::table('ads_manager_reports')->whereIn('ad_id', array_map('strval', array_keys($rows)))->orderByDesc('day')
                ->get(['ad_id', 'headline', 'ad_set_name', 'campaign_name']) as $a) {
                $name = trim((string) ($a->headline ?: ($a->ad_set_name ?: $a->campaign_name)));
                if ($name !== '') $names[(string) $a->ad_id] ??= $name;
            }
        }

        return array_values(array_map(fn ($r) => ['ad_id' => $r['ad_id'], 'name' => $names[$r['ad_id']] ?? null] + $r, $rows));
    }

    /** Ang mga Publish ng flow (pinakabago muna): id, petsa, sino, note. */
    public function versions(?int $flowId): array
    {
        if ($flowId === null) return [];
        $rows = DB::table('chatbot_flow_versions')->where('flow_id', $flowId)->orderByDesc('id')->limit(self::VERSIONS)->get(['id', 'note', 'published_by', 'created_at']);
        $users = User::whereIn('id', $rows->pluck('published_by')->filter()->unique()->values())->pluck('name', 'id');

        return $rows->map(fn ($v) => ['id' => (int) $v->id, 'at' => $v->created_at ? Carbon::parse($v->created_at)->timezone('Asia/Manila')->format('Y-m-d H:i') : null,
            'by' => $users[$v->published_by] ?? null, 'note' => $v->note])->values()->all();
    }

    // ---- Compare ----

    /**
     * Dalawang flow version (kind flow: a at b ay chatbot_flow_versions id) o dalawang version ng isang botfield (kind
     * botfield: a at b ay page_settings id), magkatabi, sa parehong page at petsa. Kasama ang bilang (sent, orders) para
     * kita ang maliit na sample. Spec 278-2: ang padala ay mula sa mga bahagi ng araw ng bawat version.
     */
    public function compare(array $f, string $kind, int $a, int $b): array
    {
        return Cache::remember('chatbot-results-compare:v3:' . md5(json_encode([$f, $kind, $a, $b])), self::CACHE_SECONDS, fn () => $this->compareBuild($f, $kind, $a, $b));
    }

    private function compareBuild(array $f, string $kind, int $a, int $b): array
    {
        [$start, $end] = $this->bounds($f);
        $orders = $this->orders($f, $start, $end);
        $delivery = $this->deliveries($orders);
        $side = $kind === 'flow' ? fn (int $id) => $this->flowSide($f, $orders, $delivery, $id) : fn (int $id) => $this->botfieldSide($f, $start, $end, $orders, $delivery, $id);

        return ['kind' => $kind, 'a' => $side($a), 'b' => $side($b)];
    }

    /** Ang bilang ng Compare mula sa mga bahagi: sent, customers, replied at ang median ng pinakabagong reply_seconds. */
    private function sideCounts(array $pieces, ?array $buckets): array
    {
        $median = self::newestSeconds($pieces, $buckets, fn () => 'all');

        return [self::sumPages(self::pageCounts($pieces, $buckets)), ['median_reply_seconds' => self::median($median['all'] ?? [])]];
    }

    private function flowSide(array $f, Collection $orders, array $delivery, int $version): array
    {
        $row = DB::table('chatbot_flow_versions')->where('id', $version)->first(['id', 'flow_id', 'note', 'published_by', 'created_at']);
        [$counts, $median] = $this->sideCounts($this->piecesOf($f, ResultsPieces::flowVersion($f, $version)), $f['via'] !== null ? [$f['via']] : null);

        return ['label' => ['id' => $version, 'flow' => $row ? DB::table('chatbot_flows')->where('id', $row->flow_id)->value('name') : null,
                'at' => $row?->created_at ? Carbon::parse($row->created_at)->timezone('Asia/Manila')->format('Y-m-d H:i') : null,
                'by' => $row?->published_by ? User::whereKey($row->published_by)->value('name') : null, 'note' => $row?->note]]
            + $this->counted($counts, $orders->filter(fn ($o) => (int) ($o->detail['flow_version'] ?? 0) === $version), $delivery, $median);
    }

    private function botfieldSide(array $f, Carbon $start, Carbon $end, Collection $orders, array $delivery, int $setting): array
    {
        [$counts, $median] = $this->sideCounts($this->piecesOf($f, ResultsPieces::botfield($f, $setting)), null);
        $ids = DB::table('chatbot_reply_tags')->where('setting_id', $setting)->whereIn('reply_id', $orders->flatMap(fn ($o) => (array) ($o->detail['sends'] ?? []))->map(fn ($v) => (int) $v)->unique()->values()->all())
            ->pluck('reply_id')->map(fn ($v) => (int) $v)->flip();
        $info = $this->settingInfo([$setting])[$setting] ?? [];

        return ['label' => ['id' => $setting, 'botfield' => PageSetting::whereKey($setting)->value('key')] + $info]
            + $this->counted($counts, $orders->filter(fn ($o) => collect((array) ($o->detail['sends'] ?? []))->contains(fn ($id) => isset($ids[(int) $id]))), $delivery, $median);
    }

    // ---- Delivered ----

    /**
     * Ang totoong order ng bawat order event: ang macro_output row ng parehong page (trim, hindi case-sensitive) at
     * botcake_psid na ang ts_date ay mula 1 araw bago hanggang 3 araw pagkatapos ng event; ang pinakamalapit ang panalo
     * (kapag pantay, ang pagkatapos ng event, saka ang naunang row). Walang tugma = unmatched.
     * @return array<int, array{delivery: string, waybill: ?string}> event id => resulta
     */
    public function deliveries(Collection $orders): array
    {
        if ($orders->isEmpty() || !Schema::hasTable('macro_output')) return $orders->mapWithKeys(fn ($o) => [$o->id => ['delivery' => 'unmatched', 'waybill' => null]])->all();
        $pages = BotcakeChat::pageMap();
        $day = fn ($o) => Carbon::parse($o->created_at)->timezone('Asia/Manila')->startOfDay();
        $days = $orders->map($day);
        $from = $days->min()->copy()->addDays(self::ORDER_WINDOW[0])->toDateString();
        $to = $days->max()->copy()->addDays(self::ORDER_WINDOW[1] + 1)->toDateString();

        $candidates = collect();
        foreach ($orders->pluck('psid')->map(fn ($p) => (string) $p)->unique()->chunk(1000) as $chunk) {
            $candidates = $candidates->concat(DB::table('macro_output')->whereIn('botcake_psid', $chunk->values()->all())
                ->where('ts_date', '>=', $from)->where('ts_date', '<', $to)->get(['id', 'PAGE', 'botcake_psid', 'waybill', 'ts_date']));
        }
        $byPsid = $candidates->groupBy(fn ($m) => (string) $m->botcake_psid);

        $match = [];
        foreach ($orders as $o) {
            $page = $this->settings->pageKey((string) ($pages[(string) $o->page_id] ?? ''));
            $d = $day($o);
            $best = ($byPsid[(string) $o->psid] ?? collect())
                ->filter(fn ($m) => $page !== '' && $this->settings->pageKey((string) $m->PAGE) === $page && trim((string) $m->waybill) !== '')
                ->map(fn ($m) => ['m' => $m, 'diff' => (int) $d->diffInDays(Carbon::parse(substr((string) $m->ts_date, 0, 10), 'Asia/Manila')->startOfDay(), false)])
                ->filter(fn ($c) => $c['diff'] >= self::ORDER_WINDOW[0] && $c['diff'] <= self::ORDER_WINDOW[1])
                ->sortBy(fn ($c) => [abs($c['diff']), $c['diff'] < 0 ? 1 : 0, (int) $c['m']->id])->first();
            $match[$o->id] = $best ? trim((string) $best['m']->waybill) : null;
        }

        $waybills = array_values(array_unique(array_filter($match)));
        $jnt = [];
        $scans = [];
        foreach (array_chunk($waybills, 1000) as $chunk) {
            DB::table('from_jnts')->whereIn('waybill_number', $chunk)->orderBy('id')->get(['waybill_number', 'status', 'rts_reason'])
                ->each(function ($r) use (&$jnt) { $jnt[$r->waybill_number][] = ['status' => $r->status, 'rts_reason' => $r->rts_reason]; });
            if (Schema::hasTable('jnt_latest_scans')) foreach (DB::table('jnt_latest_scans')->whereIn('waybill', $chunk)->pluck('waybill') as $w) $scans[$w] = true;
        }

        return collect($match)->map(fn ($w) => ['delivery' => $w === null ? 'unmatched' : self::deliveryOf($jnt[$w] ?? [], isset($scans[$w])), 'waybill' => $w])->all();
    }

    /**
     * Ang status ng waybill mula sa mga from_jnts row nito (gaya ng Chatblast: ang pinakamalayo; kapag pantay, ang huli):
     * delivered, rts (Returned, For Return, o anumang may RTS reason), in_transit (In Transit, Delivering, o may scan pero
     * wala pang J&T row), other.
     * @param array<int, array{status: ?string, rts_reason: ?string}> $rows
     */
    public static function deliveryOf(array $rows, bool $scan): string
    {
        if (!$rows) return $scan ? 'in_transit' : 'other';
        // Gaya ng Chatblast: ang walang status ay 0, ang hindi kilala ay kapantay ng In Transit.
        $rank = fn (array $r) => ($s = trim((string) $r['status'])) === '' ? 0 : (self::PROGRESS[$s] ?? 1);
        $top = null;
        foreach ($rows as $r) {
            if ($top === null || $rank($r) >= $rank($top)) $top = $r;
        }
        $status = trim((string) $top['status']);
        if ($status === 'Delivered') return 'delivered';
        if (in_array($status, ['Returned', 'For Return'], true) || trim((string) $top['rts_reason']) !== '') return 'rts';

        return in_array($status, ['In Transit', 'Delivering'], true) ? 'in_transit' : 'other';
    }
}

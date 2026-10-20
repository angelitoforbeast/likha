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
 * Ang report ng /chatbot/results (at ng results.json at chatbot:results): funnel, By message, By sequence step, By ad, at
 * ang paghahambing ng dalawang flow version o dalawang version ng isang botfield. Mula sa talaan ng ChatbotTags; ang
 * delivered ay kinukuwenta ngayon mula sa macro_output at J&T (walang kopya). Bawat query ay may petsa sa isang index; ang
 * resulta ay naka-cache nang 5 minuto kada filter. Bilang, pangalan at version lang ang lumalabas: walang text, pangalan
 * o PSID ng customer (ang value ng botfield ay ang sariling template ng bot).
 */
class ChatbotResults
{
    public const CACHE_SECONDS = 300;
    public const DEFAULT_DAYS = 7;
    public const MAX_DAYS = 92;
    public const MAX_ROWS = 300;        // kada table
    public const MEDIAN_ROWS = 200000;  // ilang reply_seconds ang binabasa para sa median
    public const VALUE_MAX = 20000;     // letra ng value ng botfield sa output
    public const VERSIONS = 50;         // gaya ng KEEP_VERSIONS ng flow
    public const ORDER_WINDOW = [-1, 3]; // ts_date ng macro_output: mula 1 araw bago hanggang 3 araw pagkatapos ng order

    /** Gaano kalayo na ang J&T status (gaya ng Chatblast): ang pinakamalayong row ng waybill ang totoo. */
    private const PROGRESS = ['In Transit' => 1, 'Delivering' => 2, 'Delivered' => 3, 'For Return' => 3, 'Returned' => 3];

    public function __construct(private PageSettings $settings)
    {
    }

    // ---- Filters ----

    /**
     * Ang malinis na filter mula sa query string o sa command: page_id, flow_id at version_id ay digits lang (kung hindi,
     * null); from at to ay Y-m-d (Manila; default ang huling 7 araw, hanggang 92 araw).
     * @return array{page_id: ?string, from: string, to: string, flow_id: ?string, version_id: ?string}
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

        return ['page_id' => $id('page_id'), 'from' => $from, 'to' => $to, 'flow_id' => $id('flow_id'), 'version_id' => $id('version_id')];
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
        return Cache::remember('chatbot-results:' . md5(json_encode($f)), self::CACHE_SECONDS, fn () => $this->build($f));
    }

    private function build(array $f): array
    {
        [$start, $end] = $this->bounds($f);
        $names = BotcakeChat::pageMap();
        $orders = $this->orders($f, $start, $end);
        $delivery = $this->deliveries($orders);
        $flowOrders = $orders->filter(fn ($o) => ($f['flow_id'] === null || (int) $o->flow_id === (int) $f['flow_id'])
            && ($f['version_id'] === null || (int) ($o->detail['flow_version'] ?? 0) === (int) $f['version_id']));

        return [
            'filters' => $f + ['page' => $f['page_id'] !== null ? ($names[$f['page_id']] ?? null) : null,
                'flow' => $f['flow_id'] !== null ? DB::table('chatbot_flows')->where('id', (int) $f['flow_id'])->value('name') : null],
            'funnel' => ['chats' => $this->chats($f, $start, $end)] + $this->metrics($this->sends($f, $start, $end, true), $flowOrders, $delivery, true),
            'by_message' => $this->byMessage($f, $start, $end, $flowOrders, $delivery),
            'by_step' => $this->bySequenceStep($f, $start, $end, $orders, $delivery),
            'by_ad' => $this->byAd($f, $start, $end, $orders, $delivery),
            'versions' => $this->versions($f['flow_id'] !== null ? (int) $f['flow_id'] : null),
        ];
    }

    /** Ang mga padala ng bot (send, sent) sa range; $flow: kasama ang filter ng flow at version. */
    private function sends(array $f, Carbon $start, Carbon $end, bool $flow)
    {
        return DB::table('chatbot_replies')->where('sent_at', '>=', $start)->where('sent_at', '<', $end)->where('kind', 'send')->where('status', 'sent')
            ->when($f['page_id'], fn ($q, $v) => $q->where('page_id', (int) $v))
            ->when($flow && $f['flow_id'], fn ($q) => $q->where('flow_id', (int) $f['flow_id']))
            ->when($flow && $f['version_id'], fn ($q) => $q->where('flow_version_id', (int) $f['version_id']));
    }

    /** Ilang customer (page + PSID) ang nag-chat sa range. */
    private function chats(array $f, Carbon $start, Carbon $end): int
    {
        return (int) DB::table('botcake_chats')->where('received_at', '>=', $start)->where('received_at', '<', $end)
            ->when($f['page_id'], fn ($q, $v) => $q->where('page_id', (string) $v))
            ->groupBy('page_id')->selectRaw('COUNT(DISTINCT psid) AS n')->pluck('n')->sum();
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
     * Ang bilang ng isang hanay ng padala at ng mga order nito: sent, customers, replied, median na oras ng sagot, orders,
     * delivered, RTS, in transit, other, unmatched. $funnel: ang anyo ng funnel (walang median).
     */
    private function metrics($sends, Collection $orders, array $delivery, bool $funnel = false): array
    {
        $sent = (int) (clone $sends)->count();
        $customers = (int) (clone $sends)->groupBy('page_id')->selectRaw('COUNT(DISTINCT psid) AS n')->pluck('n')->sum();
        $replied = (int) (clone $sends)->whereNotNull('replied_at')->count();
        $tally = ['delivered' => 0, 'rts' => 0, 'in_transit' => 0, 'other' => 0, 'unmatched' => 0];
        foreach ($orders as $o) $tally[$delivery[$o->id]['delivery'] ?? 'unmatched']++;
        $n = $orders->count();
        $out = ['sent' => $sent, 'customers_sent' => $customers, 'replied' => $replied, 'replied_pct' => self::pct($replied, $sent)];
        if (!$funnel) {
            $out['median_reply_seconds'] = self::median((clone $sends)->whereNotNull('reply_seconds')->orderByDesc('id')->limit(self::MEDIAN_ROWS)->pluck('reply_seconds')->map(fn ($v) => (int) $v)->all());
        }

        return $out + ['orders' => $n, 'orders_pct' => self::pct($n, $customers), 'delivered' => $tally['delivered'], 'delivered_pct' => self::pct($tally['delivered'], $n),
            'rts' => $tally['rts'], 'rts_pct' => self::pct($tally['rts'], $n), 'in_transit' => $tally['in_transit'], 'other' => $tally['other'], 'unmatched' => $tally['unmatched']];
    }

    /** Ang mga row ng chatbot_reply_tags sa range (kasama ang reply), ayon sa filter. */
    private function tags(array $f, Carbon $start, Carbon $end)
    {
        return DB::table('chatbot_reply_tags as t')->join('chatbot_replies as r', 'r.id', '=', 't.reply_id')
            ->where('t.sent_at', '>=', $start)->where('t.sent_at', '<', $end)->where('r.kind', 'send') // ang padala ng bot lang, gaya ng funnel
            ->when($f['page_id'], fn ($q, $v) => $q->where('t.page_id', (int) $v))
            ->when($f['flow_id'], fn ($q, $v) => $q->where('t.flow_id', (int) $v))
            ->when($f['version_id'], fn ($q, $v) => $q->where('t.flow_version_id', (int) $v));
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

    private function byMessage(array $f, Carbon $start, Carbon $end, Collection $orders, array $delivery): array
    {
        $groups = $this->tags($f, $start, $end)->groupBy('t.flow_id', 't.node_id', 't.botfield', 't.setting_id')
            ->selectRaw('t.flow_id, t.node_id, t.botfield, t.setting_id, COUNT(DISTINCT t.reply_id) AS sent, COUNT(DISTINCT CASE WHEN r.replied_at IS NOT NULL THEN t.reply_id END) AS replied')
            ->orderByDesc('sent')->orderBy('t.flow_id')->orderBy('t.node_id')->orderBy('t.setting_id')->limit(self::MAX_ROWS)->get();
        if ($groups->isEmpty()) return [];

        $seconds = [];
        foreach ($this->tags($f, $start, $end)->whereNotNull('r.reply_seconds')->distinct()
            ->orderByDesc('t.reply_id')->limit(self::MEDIAN_ROWS)->get(['t.reply_id', 't.flow_id', 't.node_id', 't.botfield', 't.setting_id', 'r.reply_seconds']) as $t) {
            $seconds[self::tagKey($t)][] = (int) $t->reply_seconds;
        }
        $after = $this->ordersAfter($orders, $delivery);
        $info = $this->settingInfo($groups->pluck('setting_id')->filter()->map(fn ($v) => (int) $v)->unique()->values()->all());
        $flows = $this->flowNames($groups->pluck('flow_id')->filter()->unique()->values()->all());

        return $groups->map(function ($g) use ($seconds, $after, $info, $flows) {
            $k = self::tagKey($g);
            $s = $g->setting_id !== null ? ($info[(int) $g->setting_id] ?? null) : null;

            return ['flow_id' => $g->flow_id !== null ? (int) $g->flow_id : null, 'flow' => $flows[$g->flow_id] ?? null, 'node' => $g->node_id,
                'botfield' => $g->botfield, 'label' => $s['label'] ?? $g->botfield, 'version' => $g->setting_id !== null ? (int) $g->setting_id : null,
                'changed_on' => $s['changed_on'] ?? null, 'value' => $s['value'] ?? null, 'active' => $s['active'] ?? null,
                'sent' => (int) $g->sent, 'replied' => (int) $g->replied, 'replied_pct' => self::pct((int) $g->replied, (int) $g->sent),
                'median_reply_seconds' => self::median($seconds[$k] ?? []), 'orders' => $after[$k]['orders'] ?? 0, 'delivered' => $after[$k]['delivered'] ?? 0];
        })->values()->all();
    }

    /**
     * Kada step ng sequence (page at petsa lang; ang step ay may sariling flow): padala, sagot, ilang nag-unsubscribe
     * (ang huling step na naipadala sa kanila bago umalis), at ang mga order na ang huling step ng customer ay ito.
     */
    private function bySequenceStep(array $f, Carbon $start, Carbon $end, Collection $orders, array $delivery): array
    {
        $rows = [];
        $key = fn ($seq, $step) => (int) $seq . ':' . (int) $step;
        foreach ($this->sends($f, $start, $end, false)->whereNotNull('sequence_id')->groupBy('sequence_id', 'sequence_step')
            ->selectRaw('sequence_id, sequence_step, COUNT(*) AS sent, SUM(CASE WHEN replied_at IS NOT NULL THEN 1 ELSE 0 END) AS replied')->get() as $r) {
            $rows[$key($r->sequence_id, $r->sequence_step)] = ['sequence_id' => (int) $r->sequence_id, 'step' => (int) $r->sequence_step, 'sent' => (int) $r->sent, 'replied' => (int) $r->replied];
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

    /** Kada ad (page at petsa lang): ilang customer ang naabot ng bot, ilang order, ilang delivered; ang pangalan mula sa ads_manager_reports. */
    private function byAd(array $f, Carbon $start, Carbon $end, Collection $orders, array $delivery): array
    {
        $rows = [];
        foreach ($this->sends($f, $start, $end, false)->whereNotNull('ad_id')->where('ad_id', '<>', '')->groupBy('ad_id')
            ->selectRaw('ad_id, COUNT(DISTINCT psid) AS chats')->get() as $r) {
            $rows[(string) $r->ad_id] = ['ad_id' => (string) $r->ad_id, 'chats' => (int) $r->chats, 'orders' => 0, 'delivered' => 0];
        }
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
     * kita ang maliit na sample.
     */
    public function compare(array $f, string $kind, int $a, int $b): array
    {
        return Cache::remember('chatbot-results-compare:' . md5(json_encode([$f, $kind, $a, $b])), self::CACHE_SECONDS, function () use ($f, $kind, $a, $b) {
            [$start, $end] = $this->bounds($f);
            $orders = $this->orders($f, $start, $end);
            $delivery = $this->deliveries($orders);
            $side = $kind === 'flow' ? fn (int $id) => $this->flowSide($f, $start, $end, $orders, $delivery, $id) : fn (int $id) => $this->botfieldSide($f, $start, $end, $orders, $delivery, $id);

            return ['kind' => $kind, 'a' => $side($a), 'b' => $side($b)];
        });
    }

    private function flowSide(array $f, Carbon $start, Carbon $end, Collection $orders, array $delivery, int $version): array
    {
        $row = DB::table('chatbot_flow_versions')->where('id', $version)->first(['id', 'flow_id', 'note', 'published_by', 'created_at']);
        $sends = $this->sends(['flow_id' => null, 'version_id' => null] + $f, $start, $end, false)->where('flow_version_id', $version);

        return ['label' => ['id' => $version, 'flow' => $row ? DB::table('chatbot_flows')->where('id', $row->flow_id)->value('name') : null,
                'at' => $row?->created_at ? Carbon::parse($row->created_at)->timezone('Asia/Manila')->format('Y-m-d H:i') : null,
                'by' => $row?->published_by ? User::whereKey($row->published_by)->value('name') : null, 'note' => $row?->note]]
            + $this->metrics($sends, $orders->filter(fn ($o) => (int) ($o->detail['flow_version'] ?? 0) === $version), $delivery);
    }

    private function botfieldSide(array $f, Carbon $start, Carbon $end, Collection $orders, array $delivery, int $setting): array
    {
        $tagged = DB::table('chatbot_reply_tags')->where('setting_id', $setting)->where('sent_at', '>=', $start)->where('sent_at', '<', $end)
            ->when($f['page_id'], fn ($q, $v) => $q->where('page_id', (int) $v))->select('reply_id');
        $sends = DB::table('chatbot_replies')->whereIn('id', $tagged);
        $ids = DB::table('chatbot_reply_tags')->where('setting_id', $setting)->whereIn('reply_id', $orders->flatMap(fn ($o) => (array) ($o->detail['sends'] ?? []))->map(fn ($v) => (int) $v)->unique()->values()->all())
            ->pluck('reply_id')->map(fn ($v) => (int) $v)->flip();
        $info = $this->settingInfo([$setting])[$setting] ?? [];

        return ['label' => ['id' => $setting, 'botfield' => PageSetting::whereKey($setting)->value('key')] + $info]
            + $this->metrics($sends, $orders->filter(fn ($o) => collect((array) ($o->detail['sends'] ?? []))->contains(fn ($id) => isset($ids[(int) $id]))), $delivery);
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

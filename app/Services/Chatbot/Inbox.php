<?php

namespace App\Services\Chatbot;

use App\Models\BotcakeChat;
use App\Models\BotcakePsid;
use App\Models\ChatbotFlow;
use App\Models\ChatbotReply;
use App\Models\ChatbotSequence;
use App\Models\ChatbotSequenceSubscription;
use App\Models\User;
use App\Services\ChatbotFields;
use App\Services\PageSettings;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ang /chatbot/inbox: ang listahan ng usapan ng isang page, ang usapan ng isang customer (papasok mula sa
 * botcake_chats, palabas mula sa chatbot_replies, at ang executed actions mula sa chatbot_events kapag hiningi) at ang
 * panel ng customer. Pang-~1M chat kada araw:
 * - listahan: isang GROUP BY psid kada table, laging may page at simula ng oras (index (page_id, received_at) at
 *   (page_id, sent_at)) at LIMIT; ang mga detalye ay ayon sa id lang;
 * - usapan: 50 kada basa ayon sa id ng bawat table ((page_id, psid, id)), pinagsasama ayon sa oras; ang mga event ay
 *   sa parehong saklaw ng oras lang ((page_id, psid, created_at)).
 */
class Inbox
{
    public const WINDOWS = ['24h' => 24, '7d' => 168, '30d' => 720];
    public const LIST_LIMIT = 200;   // pinakamaraming usapan sa listahan
    public const GROUP_LIMIT = 500;  // pinakamaraming customer kada table bago pagsamahin
    public const PAGE_SIZE = 50;     // laman ng usapan kada basa
    public const EVENT_LIMIT = 300;  // pinakamaraming event kada basa
    public const REPLY_WINDOW_HOURS = 24; // Messenger: send_content lang sa loob ng 24 oras mula sa huling mensahe ng customer
    public const OUT_KINDS = ['send', 'manual'];

    public function __construct(private PageSettings $settings)
    {
    }

    /** Ang oras bilang ISO 8601 sa Manila (para sa relative time at mga araw sa browser). */
    public static function iso($at): ?string
    {
        return $at ? Carbon::parse($at)->timezone('Asia/Manila')->toIso8601String() : null;
    }

    /** Wala pang chatbot_events hangga't hindi pa nami-migrate: walang executed actions, hindi error. */
    private function hasEvents(): bool
    {
        return Schema::hasTable('chatbot_events');
    }

    /**
     * Alin sa mga customer na ito ang naka-pause ang bot (tao ang sumasagot). Ayon sa psid lang (index (page_id, psid,
     * key)), hindi lahat ng customer ng page.
     * @param array<int, string> $psids
     * @return array<int, string>
     */
    public function pausedPsids(string $pageId, array $psids): array
    {
        $paused = [];
        foreach (array_chunk(array_values(array_filter($psids, 'ctype_digit')), 500) as $chunk) {
            $paused = array_merge($paused, DB::table('chatbot_customer_values')->where('page_id', (int) $pageId)->whereIn('psid', array_map('intval', $chunk))
                ->where('key', CustomerFields::BOT_PAUSED)->where('value', '1')->pluck('psid')->map(fn ($p) => (string) $p)->all());
        }

        return $paused;
    }

    /**
     * Ang mga usapan ng page sa loob ng window, pinakabagong galaw muna. tab: '' (lahat), 'bot' o 'human' (naka-pause).
     * @return array<int, array{psid: string, name: string, preview: string, from: string, at: ?string, waiting: bool, human: bool}>
     */
    public function conversations(string $pageId, string $window = '7d', string $q = '', string $tab = ''): array
    {
        $since = now()->subHours(self::WINDOWS[$window] ?? 168);
        $q = trim($q);
        $like = '%' . strtr(mb_strtolower($q), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';

        $in = BotcakeChat::active()->where('page_id', $pageId)->where('received_at', '>=', $since)
            ->when($q !== '', fn ($w) => $w->where(fn ($o) => $o->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$like])->orWhere('psid', 'like', strtr($q, ['%' => '', '_' => '']) . '%')))
            ->selectRaw('psid, MAX(id) AS last_id, MAX(received_at) AS last_at')->groupBy('psid')
            ->orderByDesc('last_at')->limit(self::GROUP_LIMIT)->get()->keyBy(fn ($r) => (string) $r->psid);
        $out = ChatbotReply::where('page_id', (int) $pageId)->where('sent_at', '>=', $since)->whereIn('kind', self::OUT_KINDS)
            ->selectRaw('psid, MAX(id) AS last_id, MAX(sent_at) AS last_at')->groupBy('psid')
            ->orderByDesc('last_at')->limit(self::GROUP_LIMIT)->get()->keyBy(fn ($r) => (string) $r->psid);
        // May hanap: ang mga customer na tugma sa papasok lang (ang pangalan ay nasa botcake_chats).
        if ($q !== '') $out = $out->only($in->keys()->all());

        $all = $in->keys()->merge($out->keys())->unique();
        $paused = array_flip($this->pausedPsids($pageId, $all->map(fn ($p) => (string) $p)->all()));
        $rows = [];
        foreach ($all as $psid) {
            $human = isset($paused[(string) $psid]);
            if (($tab === 'human' && !$human) || ($tab === 'bot' && $human)) continue;
            $rows[$psid] = ['in' => $in[$psid] ?? null, 'out' => $out[$psid] ?? null, 'human' => $human];
        }
        uasort($rows, fn ($a, $b) => strcmp((string) max($b['in']->last_at ?? '', $b['out']->last_at ?? ''), (string) max($a['in']->last_at ?? '', $a['out']->last_at ?? '')));
        $rows = array_slice($rows, 0, self::LIST_LIMIT, true);

        $inIds = array_values(array_filter(array_map(fn ($r) => $r['in']->last_id ?? null, $rows)));
        $outIds = array_values(array_filter(array_map(fn ($r) => $r['out']->last_id ?? null, $rows)));
        $lastIn = $inIds ? BotcakeChat::whereIn('id', $inIds)->where('page_id', $pageId)->get(['id', 'psid', 'name', 'message', 'received_at'])->keyBy('id') : collect();
        $lastOut = $outIds ? ChatbotReply::whereIn('id', $outIds)->where('page_id', (int) $pageId)->get(['id', 'psid', 'kind', 'content', 'status', 'sent_at'])->keyBy('id') : collect();
        // Ang pangalan ng customer na wala pang chat sa window (bot lang): mula sa botcake_psids.
        $page = BotcakeChat::pageMap()[$pageId] ?? null;
        $noName = array_map('strval', array_keys(array_filter($rows, fn ($r) => !$r['in'])));
        $names = $noName && $page ? BotcakePsid::whereIn('psid', $noName)->get(['page', 'psid', 'fb_name'])
            ->filter(fn ($p) => $this->settings->pageKey((string) $p->page) === $this->settings->pageKey($page))->pluck('fb_name', 'psid') : collect();

        $list = [];
        foreach ($rows as $psid => $r) {
            $i = $r['in'] ? $lastIn[$r['in']->last_id] ?? null : null;
            $o = $r['out'] ? $lastOut[$r['out']->last_id] ?? null : null;
            $lastIsOut = $o && (!$i || $o->sent_at >= $i->received_at);
            $name = trim((string) ($i->name ?? $names[(string) $psid] ?? ''));
            $list[] = [
                'psid' => (string) $psid,
                'name' => $name !== '' ? $name : 'PSID …' . mb_substr((string) $psid, -4),
                'preview' => $lastIsOut ? ($o->kind === 'manual' ? 'You: ' : 'Bot: ') . self::outPreview((array) $o->content) : mb_substr((string) ($i->message ?? ''), 0, 120),
                'from' => $lastIsOut ? 'out' : 'in',
                'at' => self::iso($lastIsOut ? $o->sent_at : $i?->received_at),
                // Naghihintay ang customer: ang huli ay sa kanya, o ang huling sagot natin ay hindi naipadala.
                'waiting' => !$lastIsOut || $o->status !== 'sent',
                'human' => $r['human'],
            ];
        }

        return $list;
    }

    private static function outPreview(array $content): string
    {
        foreach ($content as $m) if (($m['type'] ?? '') === 'text' && trim((string) ($m['text'] ?? '')) !== '') return mb_substr((string) $m['text'], 0, 120);

        return $content ? '[Photo]' : '';
    }

    /**
     * Ang usapan: ang pinakabagong 50 (o mas luma kaysa sa $before, o mas bago kaysa sa $after), sunod-sunod sa oras.
     * Ang cursor: {"in": id, "out": id, "ev": id, "at": oras}. Ang executed actions ay kasama lang kapag $events.
     */
    public function thread(string $pageId, string $psid, ?array $before = null, ?array $after = null, bool $events = false): array
    {
        $events = $events && $this->hasEvents();
        $inQ = fn () => BotcakeChat::active()->where('page_id', $pageId)->where('psid', $psid);
        $outQ = fn () => ChatbotReply::where('page_id', (int) $pageId)->where('psid', (int) $psid);
        $evQ = fn () => DB::table('chatbot_events')->where('page_id', (int) $pageId)->where('psid', (int) $psid);

        if ($after !== null) {
            $in = $inQ()->where('id', '>', (int) ($after['in'] ?? 0))->orderBy('id')->limit(self::PAGE_SIZE)->get();
            $out = $outQ()->where('id', '>', (int) ($after['out'] ?? 0))->orderBy('id')->limit(self::PAGE_SIZE)->get();
            $ev = $events ? $evQ()->where('id', '>', (int) ($after['ev'] ?? 0))->orderBy('id')->limit(self::EVENT_LIMIT)->get() : collect();
            $newer = ['in' => (int) ($in->max('id') ?? ($after['in'] ?? 0)), 'out' => (int) ($out->max('id') ?? ($after['out'] ?? 0)),
                'ev' => (int) ($ev->max('id') ?? ($after['ev'] ?? 0))];

            return ['items' => array_map([self::class, 'strip'], $this->merge($in, $out, $ev)), 'older' => null, 'newer' => $newer, 'has_older' => null];
        }

        $in = $inQ()->when($before, fn ($w) => $w->where('id', '<', (int) ($before['in'] ?? 0)))->orderByDesc('id')->limit(self::PAGE_SIZE + 1)->get();
        $out = $outQ()->when($before, fn ($w) => $w->where('id', '<', (int) ($before['out'] ?? 0)))->orderByDesc('id')->limit(self::PAGE_SIZE + 1)->get();
        $all = $this->merge($in, $out, collect());
        $hasOlder = count($all) > self::PAGE_SIZE;
        $items = array_slice($all, -self::PAGE_SIZE);
        $shownIn = array_filter($items, fn ($x) => $x['src'] === 'in');
        $shownOut = array_filter($items, fn ($x) => $x['src'] === 'out');
        $oldestAt = $items ? $items[0]['raw'] : null;

        // Ang mga event sa parehong saklaw ng oras: mula sa pinakalumang naipakita (o mula pa noong una kapag wala nang
        // mas luma) hanggang sa dating pinakaluma (kapag "Load older") o hanggang ngayon.
        // Ang pinakabagong event ay binabasa bago ang listahan: ang takbong naisulat sa pagitan ay lalabas pa sa polling.
        $evMax = !$before && $events ? (int) ($evQ()->max('id') ?? 0) : 0;
        $ev = collect();
        if ($events) {
            $ev = $evQ()->when($hasOlder && $oldestAt, fn ($w) => $w->where('created_at', '>=', $oldestAt))
                ->when($before && !empty($before['at']), fn ($w) => $w->where('created_at', '<', Carbon::parse($before['at'])))
                ->orderByDesc('id')->limit(self::EVENT_LIMIT)->get();
        }
        $items = array_values(array_merge($items, $this->merge(collect(), collect(), $ev)));
        usort($items, [self::class, 'order']);

        return [
            'items' => array_map([self::class, 'strip'], $items),
            // Ang mas luma: mula sa pinakalumang naipakita ng bawat table. Kapag walang naipakita ang isang table, ang lahat ng
        // nakuha rito ay mas luma pa, kaya kasama sila sa susunod (pinakamalaking id + 1); kung wala, ang dating cursor.
            'older' => ['in' => $shownIn ? min(array_column($shownIn, 'id')) : ($in->isNotEmpty() ? (int) $in->max('id') + 1 : (int) ($before['in'] ?? 0)),
                'out' => $shownOut ? min(array_column($shownOut, 'id')) : ($out->isNotEmpty() ? (int) $out->max('id') + 1 : (int) ($before['out'] ?? 0)),
                'at' => $oldestAt ? Carbon::parse($oldestAt)->toIso8601String() : ($before['at'] ?? null)],
            // Ang polling ng event ay mula sa pinakabagong event ng customer, hindi sa naipakita (para hindi bumalik ang mga luma).
            'newer' => $before ? null : ['in' => (int) ($in->max('id') ?? 0), 'out' => (int) ($out->max('id') ?? 0), 'ev' => $evMax],
            'has_older' => $hasOlder,
        ];
    }

    private static function order(array $a, array $b): int
    {
        $rank = ['in' => 0, 'ev' => 1, 'out' => 2];

        return [$a['raw'], $rank[$a['src']], $a['id']] <=> [$b['raw'], $rank[$b['src']], $b['id']];
    }

    private static function strip(array $x): array
    {
        unset($x['raw']);

        return $x;
    }

    /** Pagsamahin ayon sa oras (papasok, saka event, saka palabas kapag pareho ang segundo). */
    private function merge(Collection $in, Collection $out, Collection $ev): array
    {
        $users = User::whereIn('id', $out->where('kind', 'manual')->pluck('user_id')->filter()->unique()->values())->pluck('name', 'id');
        $items = [];
        foreach ($in as $c) {
            $img = $c->message === ChatMessageCleaner::PHOTO ? ($c->payload['image_url'] ?? null) : null;
            $items[] = ['key' => 'in-' . $c->id, 'src' => 'in', 'id' => $c->id, 'side' => 'in', 'at' => self::iso($c->received_at), 'raw' => Carbon::parse($c->received_at)->format('Y-m-d H:i:s'),
                'text' => (string) $c->message, 'like' => $c->message === ChatMessageCleaner::LIKE,
                'image_url' => is_string($img) && preg_match('#^https?://#i', $img) ? $img : null];
        }
        foreach ($out as $r) {
            $base = ['key' => 'out-' . $r->id, 'src' => 'out', 'id' => $r->id, 'at' => self::iso($r->sent_at), 'raw' => Carbon::parse($r->sent_at)->format('Y-m-d H:i:s'),
                'status' => $r->status, 'error' => $r->error];
            if ($r->kind === 'sheet') {
                $text = match ($r->status) { 'sent' => 'Order row written to sheet', 'failed' => 'Order row failed: ' . $r->error, default => 'Order row skipped: ' . $r->error };
                $items[] = $base + ['side' => 'system', 'text' => $text, 'link' => ChatbotSheet::rowLink((array) $r->content)];
                continue;
            }
            $messages = array_values(array_filter(array_map(fn ($m) => ($m['type'] ?? '') === 'image'
                ? (preg_match('#^https?://#i', (string) ($m['url'] ?? '')) ? ['type' => 'image', 'url' => (string) $m['url']] : null)
                : ['type' => 'text', 'text' => (string) ($m['text'] ?? '')], (array) $r->content)));
            $items[] = $base + ['side' => 'out', 'messages' => $messages, 'manual' => $r->kind === 'manual',
                'by' => $r->kind === 'manual' ? ($users[$r->user_id] ?? 'You') : 'Bot'];
        }
        $flows = $ev->isEmpty() ? [] : ChatbotFlow::whereIn('id', $ev->pluck('flow_id')->filter()->unique()->values())->pluck('name', 'id')->all();
        foreach ($ev as $e) {
            $d = json_decode((string) $e->detail, true) ?: [];
            $items[] = ['key' => 'ev-' . $e->id, 'src' => 'ev', 'id' => $e->id, 'side' => 'event', 'type' => $e->type, 'at' => self::iso($e->created_at),
                'raw' => Carbon::parse($e->created_at)->format('Y-m-d H:i:s'), 'text' => ChatbotEvents::text($e->type, $e->node_id, $d + ['flow' => $e->flow_id], $flows)];
        }
        usort($items, [self::class, 'order']);

        return $items;
    }

    /** Ang huling mensahe ng customer sa page na ito (para sa 24 oras ng Messenger), o null. */
    public function lastIncomingAt(string $pageId, string $psid): ?Carbon
    {
        $at = BotcakeChat::active()->where('page_id', $pageId)->where('psid', $psid)->orderByDesc('id')->value('received_at');

        return $at ? Carbon::parse($at) : null;
    }

    public function insideWindow(string $pageId, string $psid): bool
    {
        $at = $this->lastIncomingAt($pageId, $psid);

        return $at !== null && $at->gt(now()->subHours(self::REPLY_WINDOW_HOURS));
    }

    /**
     * Ang panel ng customer: sino, ang lagay ng bot, ang mga field, ang mga botfield ng page, ang flow, at ang mga
     * seksyon gaya ng CBB (executed flows, error logs, media).
     */
    public function panel(string $pageId, string $page, string $psid): array
    {
        $first = BotcakeChat::active()->where('page_id', $pageId)->where('psid', $psid)->orderBy('id')->first(['name', 'payload', 'received_at']);
        $last = BotcakeChat::active()->where('page_id', $pageId)->where('psid', $psid)->orderByDesc('id')->first(['name', 'payload', 'received_at']);
        $record = BotcakePsid::where('psid', $psid)->get(['page', 'fb_name', 'source', 'ad_id', 'first_seen_at', 'subscribed_at'])
            ->first(fn ($p) => $this->settings->pageKey((string) $p->page) === $this->settings->pageKey($page));
        $fields = new CustomerFields((int) $pageId, (int) $psid);
        $wait = (string) $fields->get(CustomerFields::WAIT);
        $values = $fields->fields();
        ksort($values);

        $flowId = trim((string) $this->settings->get($page, ChatbotFields::PROVIDER, 'flow_id'));
        $flow = ctype_digit($flowId) ? ChatbotFlow::find((int) $flowId, ['id', 'name']) : null;
        $botfields = [];
        $labels = array_column(app(ChatbotFields::class)->columns(), 'label', 'key');
        foreach ($this->settings->pageQuery($page)->where('provider', ChatbotFields::PROVIDER)->where('active', true)->orderBy('id')->get() as $s) {
            if (in_array($s->key, ChatbotFields::RESERVED, true)) continue;
            $botfields[$s->key] = ['label' => $labels[$s->key] ?? $s->key, 'value' => (string) $s->plainValue()];
        }

        return [
            'name' => trim((string) ($last->name ?? $record->fb_name ?? '')) ?: 'PSID …' . mb_substr($psid, -4),
            'psid' => $psid,
            'ad_id' => $record->ad_id ?? ($last->payload['ad_id'] ?? ($first->payload['ad_id'] ?? null)),
            'source' => $record->source ?? null,
            'first_seen' => self::iso($record->first_seen_at ?? $record->subscribed_at ?? $first?->received_at),
            'chat_status' => $values['chat status'] ?? null,
            'waiting' => $wait !== '' ? 'Waiting for reply at step ' . (explode(':', $wait, 2)[1] ?? $wait) : null,
            'bot_paused' => $fields->get(CustomerFields::BOT_PAUSED) === '1',
            'fields' => array_map(fn ($k, $v) => ['key' => (string) $k, 'value' => $v], array_keys($values), $values),
            'botfields' => array_values($botfields),
            'flow' => $flow ? ['name' => $flow->name, 'url' => url('/chatbot/flows/' . $flow->id)] : null,
            'last_incoming' => self::iso($last?->received_at),
            'can_reply_window' => $this->insideWindow($pageId, $psid),
            'runs' => $this->runs($pageId, $psid),
            'errors' => $this->errors($pageId, $psid),
            'media' => $this->media($pageId, $psid),
        ] + $this->sequences($pageId, $psid);
    }

    /** Ang mga sequence ng customer (index (page_id, psid)) at ang lahat ng sequence para sa Subscribe. */
    private function sequences(string $pageId, string $psid): array
    {
        if (!Schema::hasTable('chatbot_sequence_subscriptions')) return ['sequences' => [], 'all_sequences' => []];
        $all = ChatbotSequence::orderBy('name')->limit(500)->get(['id', 'name']);
        $mine = ChatbotSequenceSubscription::with('sequence:id,name')->where('page_id', (int) $pageId)->where('psid', (int) $psid)->limit(100)->get()
            ->map(fn ($s) => ['id' => (int) $s->sequence_id, 'name' => (string) ($s->sequence?->name ?? '#' . $s->sequence_id), 'status' => $s->status,
                'step' => (int) $s->current_step, 'next_send' => self::iso($s->next_send_at)])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();

        return ['sequences' => $mine, 'all_sequences' => $all->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->all()];
    }

    /** Ang huling 20 takbo: oras, flow, ang dinaanan na mga step at ang resulta. */
    private function runs(string $pageId, string $psid): array
    {
        if (!$this->hasEvents()) return [];
        $ends = DB::table('chatbot_events')->where('page_id', (int) $pageId)->where('psid', (int) $psid)->whereIn('type', ['run_ended', 'skipped'])
            ->orderByDesc('id')->limit(20)->get();
        $flows = ChatbotFlow::whereIn('id', $ends->pluck('flow_id')->filter()->unique()->values())->pluck('name', 'id');

        return $ends->map(function ($e) use ($flows) {
            $d = json_decode((string) $e->detail, true) ?: [];
            return ['at' => self::iso($e->created_at), 'flow' => $flows[$e->flow_id] ?? null, 'path' => array_values((array) ($d['path'] ?? [])),
                'result' => $e->type === 'skipped' ? 'Skipped: ' . ($d['reason'] ?? '') : (string) ($d['reason'] ?? '')];
        })->values()->all();
    }

    /** Ang mga pumalyang padala at pumalyang AI step, pinakabago muna. */
    private function errors(string $pageId, string $psid): array
    {
        $sends = ChatbotReply::where('page_id', (int) $pageId)->where('psid', (int) $psid)->where('status', 'failed')->orderByDesc('id')->limit(20)
            ->get(['kind', 'error', 'sent_at'])->map(fn ($r) => ['at' => self::iso($r->sent_at), 'raw' => (string) $r->sent_at,
                'text' => ($r->kind === 'sheet' ? 'Sheet row failed: ' : 'Send failed: ') . $r->error]);
        $steps = !$this->hasEvents() ? collect() : DB::table('chatbot_events')->where('page_id', (int) $pageId)->where('psid', (int) $psid)->whereIn('type', ['ai', 'sequence'])->orderByDesc('id')->limit(50)->get()
            ->filter(fn ($e) => in_array('failed', [json_decode((string) $e->detail, true)['result'] ?? '', json_decode((string) $e->detail, true)['op'] ?? ''], true))
            ->map(fn ($e) => ['at' => self::iso($e->created_at), 'raw' => (string) $e->created_at, 'text' => ChatbotEvents::text($e->type, $e->node_id, json_decode((string) $e->detail, true) ?: [])]);

        // toBase: ang walang laman na Eloquent collection ay hindi puwedeng pagsamahin sa mga array.
        return $sends->toBase()->merge($steps)->sortByDesc('raw')->take(20)->map(fn ($x) => ['at' => $x['at'], 'text' => $x['text']])->values()->all();
    }

    /** Ang mga larawang ipinadala ng customer (payload.image_url ng [Photo]), pinakabago muna. */
    private function media(string $pageId, string $psid): array
    {
        return BotcakeChat::active()->where('page_id', $pageId)->where('psid', $psid)->where('message', ChatMessageCleaner::PHOTO)->orderByDesc('id')->limit(30)
            ->get(['payload', 'received_at'])->map(fn ($c) => ['url' => $c->payload['image_url'] ?? null, 'at' => self::iso($c->received_at)])
            ->filter(fn ($m) => is_string($m['url']) && preg_match('#^https?://#i', $m['url']))->values()->all();
    }
}

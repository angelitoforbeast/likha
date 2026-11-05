<?php

namespace App\Services\Chatbot;

use App\Models\BotcakeChat;
use App\Services\BotcakeChatGuard;
use App\Services\BotcakeMock;
use App\Services\ChatbotFields;
use App\Services\PageSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ang echo: bawat mensaheng ipinadala ng page sa customer, mula sa alinmang app (Forbeast, Botcake, ang Page Inbox ng tao, …),
 * ay ipinapasa ng Meta sa bawat app na naka-subscribe sa page, kaya dumarating ito sa Likha sa Forbeast Gateway (ang Botcake
 * ay nagpapasa rin ng echo nito; sa page na Botcake ang route, ang echo na iyon ay ang sagot mismo ng Likha bot na ipinadala
 * sa API ng Botcake). Iniimbak nang maikli sa chatbot_events (type echo; psid = ang customer, created_at = ang
 * oras ng Meta): ang mid, ang app, ang nagpadala, ang hash ng text at ang unang 200 letra. Para lang makita kung sino ang
 * sumagot (Inbox), ang hindi nasagot at ang dobleng sagot (Bot Health), at ang ebidensya ng ForbeastWatch. Walang ginagawa
 * ang bot dahil dito (hindi ito nagpa-pause kapag tao ang sumagot).
 */
class ChatEchoes
{
    public const TYPE = 'echo';
    /** Ang mga kilalang app ng nagpadala (app_id ng echo). */
    public const APPS = [
        '1115162827653315' => 'forbeast',
        '556376998159104' => 'botcake',
        '573598697063667' => 'chatbot_builder',
        '263902037430900' => 'person', // Page Inbox / Business Suite: tao ang sumagot
    ];
    public const LABELS = ['forbeast' => 'Bot (Forbeast)', 'incepxionbot' => 'Bot (Incepxionbot)', 'botcake' => 'Botcake', 'chatbot_builder' => 'Chatbot Builder', 'person' => 'Person', 'other' => 'Other app'];
    public const TEXT = 200;
    /**
     * Ang echo na ganito kaaga bago ang mensahe ng customer (segundo) ay sagot pa rin dito: ang oras ng chat ay ang pagdating
     * sa Likha, na ilang segundo pagkatapos ng sa Meta.
     */
    public const EARLY_SECONDS = 30;
    /** Ang Bot Health: ang huling ilang minuto, naka-cache ilang segundo, ilang halimbawa kada page. */
    public const HEALTH_MINUTES = 60;
    // v2: may main-flow double at leftover na ang bawat page; ang lumang hugis sa cache ay hindi na binabasa pagka-deploy.
    public const HEALTH_CACHE = 'bot-health:echoes:v2';
    private const HEALTH_CACHE_SECONDS = 60;
    private const EXAMPLES = 5;
    /**
     * Ang "CBB" ng dobleng sagot: ang Chatbot Builder lang, ang app na may sariling main flow, sequence at loop bukod sa Likha
     * bot. Ang Botcake ay hindi CBB: ito ang tagadala ng Likha bot sa page na Botcake ang route.
     */
    public const CBB = ['chatbot_builder'];
    public const MAIN_EXAMPLES = 3;
    /** Ang page na may echo sa huling ilang oras lang ang sinusukat (ang page na walang echo ay hindi nakakabit sa Forbeast). */
    private const ACTIVE_SECONDS = 86400;
    private const SEEN_SECONDS = 86400;

    /**
     * Ang nagpadala ayon sa app_id: forbeast, incepxionbot (ang Meta app nito, INCEPXIONBOT_APP_ID), botcake,
     * chatbot_builder, person, o other (hindi kilala o wala).
     */
    public static function sender(?string $appId): string
    {
        $ixb = Gateways::config(Gateways::INCEPXIONBOT)['app_id'];
        if ($ixb !== '' && (string) $appId === $ixb) return Gateways::INCEPXIONBOT;
        if (self::route($appId) !== null) return Gateways::FORBEAST;

        return self::APPS[(string) $appId] ?? 'other';
    }

    /** Ang address ng forbeast gateway na ang Meta app ay $appId (Gateways::appIds), o null. */
    public static function route(?string $appId): ?string
    {
        return Gateways::appIds()[(string) $appId] ?? null;
    }

    /** Ang label; ang padala ng forbeast gateway sa ibang address ay ang pangalan ng address ("Bot (Incepxion)"). */
    public static function label(?string $sender, ?string $route = null): string
    {
        if ($sender === Gateways::FORBEAST && $route !== null && $route !== Gateways::FORBEAST) return 'Bot (' . Gateways::routeLabel($route) . ')';

        return self::LABELS[(string) $sender] ?? self::LABELS['other'];
    }

    /**
     * Ang nagpadala at label ng naimbak na echo (ang detail nito). Ang app ng isang address ng forbeast gateway ay ang bot
     * kahit naimbak ito bago pa ang GATEWAY_APP_IDS (sender other). @return array{0: string, 1: string}
     */
    public static function who(array $detail): array
    {
        $route = self::route(isset($detail['app_id']) ? (string) $detail['app_id'] : null);
        $sender = $route !== null ? Gateways::FORBEAST : (string) ($detail['sender'] ?? 'other');

        return [$sender, self::label($sender, $route)];
    }

    /** Ang maikling hash ng text (walang pagkakaiba sa laki ng titik at espasyo), para sa paghahambing sa sagot ng Likha. */
    public static function hash(?string $text): string
    {
        return substr(sha1(mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $text)))), 0, 16);
    }

    /**
     * Ang echo mula sa HandleGatewayEvent (ang mga field ng fields()): iniimbak isang beses kada mid. @return string ang
     * bibilangin: echo, echo_duplicate, echo_no_id
     */
    public static function store(array $e): string
    {
        $pageId = (string) $e['page_id'];
        $psid = (string) $e['psid'];
        $mid = (string) $e['mid'];
        // Ang psid ay ang customer: ang page mismo (ang nagpadala) ay hindi customer.
        if ($mid === '' || !preg_match('/^\d{1,19}$/', $pageId) || !preg_match('/^\d{1,19}$/', $psid) || $psid === $pageId) return 'echo_no_id';
        if (!Schema::hasTable('chatbot_events')) return self::TYPE;

        $seen = 'chat-echoes:seen:' . sha1($mid);
        if (!Cache::add($seen, 1, self::SEEN_SECONDS)) return 'echo_duplicate';
        $q = fn () => DB::table('chatbot_events')->where('page_id', (int) $pageId)->where('psid', (int) $psid)->where('type', self::TYPE);
        try {
            if ($q()->where('detail->mid', $mid)->exists()) return 'echo_duplicate';
            $text = (string) $e['text'];
            if ($text === '' && ($e['attachments'][0]['type'] ?? null)) $text = '[' . ucfirst((string) $e['attachments'][0]['type']) . ']';
            $appId = $e['app_id'] ?? null;
            // route: ang address ng forbeast gateway kapag ang app ay isa sa mga address nito.
            $route = self::route($appId);
            DB::table('chatbot_events')->insert(['page_id' => (int) $pageId, 'psid' => (int) $psid, 'chat_id' => null, 'flow_id' => null, 'node_id' => null,
                'type' => self::TYPE, 'detail' => json_encode(['mid' => $mid, 'app_id' => $appId, 'sender' => self::sender($appId)] + ($route !== null ? ['route' => $route] : [])
                    + ['hash' => self::hash($text), 'text' => mb_substr($text, 0, self::TEXT)], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                'created_at' => self::at($e['timestamp'] ?? null)->format('Y-m-d H:i:s')]);
        } catch (\Throwable $ex) {
            Cache::forget($seen); // hindi naimbak: ang ulit ay tatanggapin
            throw $ex;
        }
        Cache::put('chat-echoes:page:' . $pageId, now()->getTimestamp(), self::ACTIVE_SECONDS);

        return self::TYPE;
    }

    /** Ang oras ng Meta (ms) kapag makatwiran (sa loob ng isang araw mula ngayon), kung hindi ay ngayon. */
    private static function at(mixed $ms): Carbon
    {
        $now = now();
        if (!is_int($ms) || abs(intdiv($ms, 1000) - $now->getTimestamp()) > 86400) return $now;

        return Carbon::createFromTimestampMs($ms)->setTimezone(config('app.timezone'));
    }

    /**
     * Ang huling sumagot sa customer (para sa ibang feature): ang nagpadala, ang label at ang oras, o null.
     * @return array{sender: string, label: string, at: string}|null
     */
    public static function latest(string $pageId, string $psid): ?array
    {
        if (!preg_match('/^\d{1,19}$/', $pageId) || !preg_match('/^\d{1,19}$/', $psid) || !Schema::hasTable('chatbot_events')) return null;
        $row = DB::table('chatbot_events')->where('page_id', (int) $pageId)->where('psid', (int) $psid)->where('type', self::TYPE)
            ->orderByDesc('created_at')->orderByDesc('id')->first(['detail', 'created_at']);
        if ($row === null) return null;
        [$sender, $label] = self::who(json_decode((string) $row->detail, true) ?: []);

        return ['sender' => $sender, 'label' => $label, 'at' => (string) $row->created_at];
    }

    /**
     * Ang Bot Health kada page sa huling HEALTH_MINUTES: ang hindi nasagot (customer na may mensahe na walang echo mula sa
     * anumang app sa loob ng unanswered_minutes) at ang dobleng sagot (dalawang magkaibang app sa iisang customer sa loob ng
     * double_seconds). Ang page na sinusukat: may echo sa huling 24 oras, naka-On ang bot, at hindi test page.
     * Ang doble ay hinahati (newCustomers): ang main-flow double (bagong customer na sinagot ng CBB at ng main flow ng
     * Likha bot) at ang leftover (lahat ng iba, hal. lumang customer sa sequence o loop ng CBB). Ang doubles ay ang kabuuan.
     * @return array{pages: array<int, array<string, mixed>>, measured: int}
     */
    public function health(): array
    {
        return Cache::remember(self::HEALTH_CACHE, self::HEALTH_CACHE_SECONDS,
            fn () => Schema::hasTable('chatbot_events') && Schema::hasTable('botcake_chats') ? $this->build() : ['pages' => [], 'measured' => 0]);
    }

    private function build(): array
    {
        $names = BotcakeChat::pageMap();
        $guard = app(BotcakeChatGuard::class);
        $pages = [];
        foreach ($names as $id => $name) {
            if (Cache::get('chat-echoes:page:' . $id) !== null && !BotcakeMock::isTestId((string) $id) && !$guard->botOff($name)) $pages[] = (string) $id;
        }
        if (!$pages) return ['pages' => [], 'measured' => 0];

        $wait = self::limit('unanswered_minutes') * 60;
        $since = now()->subMinutes(self::HEALTH_MINUTES);
        $echoes = DB::table('chatbot_events')->where('type', self::TYPE)->whereIn('page_id', array_map('intval', $pages))
            ->where('created_at', '>=', $since->copy()->subSeconds(self::EARLY_SECONDS)->format('Y-m-d H:i:s'))->orderBy('created_at')->orderBy('id')
            ->get(['page_id', 'psid', 'detail', 'created_at'])
            ->map(function ($r) {
                $d = json_decode((string) $r->detail, true) ?: [];
                [$sender, $label] = self::who($d);
                // app: ang sumagot para sa doble, ang kilalang nagpadala (ang mga address ng forbeast gateway ay iisang bot),
                // o ang app_id kapag hindi kilala; null kapag walang app_id.
                $app = isset($d['app_id']) ? ($sender === 'other' ? (string) $d['app_id'] : $sender) : null;

                return ['page' => (string) $r->page_id, 'psid' => (string) $r->psid, 'at' => Carbon::parse($r->created_at)->getTimestamp(),
                    'app' => $app, 'sender' => $sender, 'label' => $label];
            })
            ->groupBy(fn ($x) => $x['page'] . '|' . $x['psid']);

        $out = [];
        $row = function (string $id) use (&$out, $names): void {
            $out[$id] ??= ['page_id' => $id, 'page' => $names[$id] ?? 'Page ' . $id, 'unanswered' => 0, 'unanswered_messages' => 0, 'first_at' => null,
                'examples' => [], 'doubles' => 0, 'double_examples' => [], 'main_doubles' => 0, 'main_examples' => [], 'leftovers' => 0,
                'leftover_examples' => [], 'new_customers' => 0, 'cbb_new' => 0];
        };
        $inbox = fn (string $id, string $psid) => route('chatbot.inbox', ['page_id' => $id, 'psid' => $psid], false);

        // Ang chat ng window: ang mga customer nito ang sinusuri kung bago (newCustomers); ang hindi nasagot ay ang lampas na
        // sa paghihintay lang.
        $ignoring = ChatAttachments::ignoring();
        $chats = BotcakeChat::active()->whereIn('page_id', $pages)->where('received_at', '>=', $since)
            ->orderBy('received_at')->get(['id', 'page_id', 'psid', 'message', 'payload', 'received_at']);
        $cut = now()->subSeconds($wait)->getTimestamp();
        $missed = $firstAt = $inWindow = [];
        // Ang hindi nasagot: ang mensahe ng customer na lampas na sa paghihintay, walang echo sa [dating − EARLY, dating + wait].
        foreach ($chats as $c) {
            // Ang pagbukas ng ad ay nasa window pa rin (doon nagiging bagong customer), pero hindi mensahe: walang sagot na hinihintay.
            $inWindow[(string) $c->page_id][(string) $c->psid] = true;
            if ($c->received_at->getTimestamp() > $cut) continue;
            if (!empty($c->payload[ChatbotInbound::AD_OPEN]) || is_string($c->payload['_ignored'] ?? null) || ($ignoring && ChatAttachments::type($c->message, (array) $c->payload) !== null)) continue;
            $at = $c->received_at->getTimestamp();
            $answered = ($echoes[$c->page_id . '|' . $c->psid] ?? collect())->contains(fn ($x) => $x['at'] >= $at - self::EARLY_SECONDS && $x['at'] <= $at + $wait);
            if ($answered) continue;
            $missed[(string) $c->page_id][(string) $c->psid][] = $c->received_at;
            $firstAt[(string) $c->page_id] ??= $c->received_at; // luma muna ang chats
        }
        foreach ($missed as $id => $customers) {
            $row((string) $id);
            $r = &$out[(string) $id];
            $r['unanswered'] = count($customers);
            $r['unanswered_messages'] = array_sum(array_map('count', $customers));
            $r['first_at'] = $firstAt[$id]->format('Y-m-d H:i:s');
            uasort($customers, fn ($a, $b) => end($b) <=> end($a));
            foreach (array_slice($customers, 0, self::EXAMPLES, true) as $psid => $list) {
                $r['examples'][] = ['at' => end($list)->format('Y-m-d H:i:s'), 'inbox' => $inbox((string) $id, (string) $psid)];
            }
            unset($r);
        }

        // Ang doble: magkasunod na echo ng iisang customer mula sa dalawang magkaibang app, sa loob ng double_seconds.
        $gap = self::limit('double_seconds');
        $from = $since->getTimestamp();
        $at = fn (int $ts) => Carbon::createFromTimestamp($ts)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
        $pairs = [];
        foreach ($echoes as $list) {
            $list = $list->filter(fn ($x) => $x['at'] >= $from)->values();
            for ($i = 0; $i + 1 < $list->count(); $i++) {
                [$a, $b] = [$list[$i], $list[$i + 1]];
                if ($a['app'] === null || $b['app'] === null || $a['app'] === $b['app'] || $b['at'] - $a['at'] > $gap) continue;
                // cbb: kasama sa pares ang echo ng Chatbot Builder (ang iba ay hindi leftover ng CBB).
                $pairs[$a['page']][$a['psid']][] = ['at' => $at($b['at']), 'senders' => $a['label'] . ' + ' . $b['label'],
                    'inbox' => $inbox($a['page'], $a['psid']), 'cbb' => in_array($a['sender'], self::CBB, true) || in_array($b['sender'], self::CBB, true)];
                $i++; // ang pangalawa ay hindi na kapares ng susunod
            }
        }

        $fresh = $this->newCustomers($names, $since, $wait, $inWindow, $echoes);
        $latest = function (array $list, int $n): array {
            usort($list, fn ($a, $b) => $b['at'] <=> $a['at']);
            return array_slice($list, 0, $n);
        };
        foreach (array_unique(array_merge(array_keys($pairs), array_keys(array_filter($fresh, fn ($f) => $f['main'])))) as $id) {
            $id = (string) $id;
            $row($id);
            $r = &$out[$id];
            $main = $fresh[$id]['main'] ?? [];
            $all = $left = [];
            foreach ($pairs[$id] ?? [] as $psid => $list) {
                array_push($all, ...$list);
                if (!isset($main[$psid])) array_push($left, ...array_filter($list, fn ($p) => $p['cbb']));
            }
            $strip = fn (array $list) => array_map(fn ($p) => array_diff_key($p, ['cbb' => 1]), $list);
            $r['doubles'] = count($all);
            $r['double_examples'] = $strip($latest($all, self::EXAMPLES));
            $r['leftovers'] = count($left);
            $r['leftover_examples'] = $strip($latest($left, self::EXAMPLES));
            $r['main_doubles'] = count($main);
            arsort($main);
            $r['main_examples'] = array_map(fn ($psid, $ts) => ['at' => $at($ts), 'inbox' => $inbox($id, (string) $psid)],
                array_keys($top = array_slice($main, 0, self::MAIN_EXAMPLES, true)), $top);
            unset($r);
        }
        foreach ($out as $id => &$r) [$r['new_customers'], $r['cbb_new']] = [$fresh[$id]['new'] ?? 0, $fresh[$id]['cbb'] ?? 0];
        unset($r);
        $out = array_values($out);
        usort($out, fn ($a, $b) => [-$a['unanswered'], -$a['main_doubles'], -$a['doubles'], $a['page']] <=> [-$b['unanswered'], -$b['main_doubles'], -$b['doubles'], $b['page']]);

        return ['pages' => $out, 'measured' => count($pages)];
    }

    /**
     * Ang mga bagong customer ng window kada page, mula sa sariling talaan ng Likha (walang tawag sa Botcake o CBB):
     * - bago: ang unang chat niya sa page (botcake_chats, lahat ng row; ang ad referral at postback ay naiimbak din bilang
     *   chat) ay nasa window, at walang echo ng CBB sa kanya bago iyon (sa 30 araw ng echo). Ang kilala na ng CBB pero
     *   bago sa Likha (bago pa ang talaan) ay bago. Ang customer ng ad ay bago mula sa pagbukas ng ad (spec 204: ang
     *   _ad_open na row ang una niya).
     * - sinagot ng CBB: may echo ng CBB sa [unang chat − EARLY, unang mensahe + wait] (ang parehong sukat ng "nasagot"). Ang
     *   unang mensahe ay ang unang row na hindi pagbukas ng ad (ang unang chat kung wala): ang CBB ay sumasagot sa pagbukas,
     *   ang Likha bot ay sa unang mensahe lang (spec 204), na minsan ilang minuto pagkatapos.
     * - main-flow double: sinagot ng CBB at, sa parehong sukat, may naipadalang sagot ang Likha bot mula sa main flow ng
     *   page: chatbot_replies na send, sent, walang sequence_id, at ang flow_id ay ang flow ng page (chatbot.flow_id) o ng
     *   active na ad group nito (ang tumatakbo para sa bagong customer, ChatbotPage::flow).
     * Tatlong query ng data lang, hindi kada pares o customer: ang unang chat (isang grouped query), ang lumang echo ng CBB at
     * sagot ng Likha (kapag may bagong customer na sinagot ng CBB).
     * @param array<string, array<string, true>> $customers page => psid ng may chat sa window
     * @return array<string, array{new: int, cbb: int, main: array<string, int>}> page => bilang, at psid => oras ng unang mensahe
     */
    private function newCustomers(array $names, Carbon $since, int $wait, array $customers, $echoes): array
    {
        if (!$customers) return [];
        $out = [];
        $psids = array_values(array_unique(array_merge(...array_map(fn ($l) => array_map('strval', array_keys($l)), array_values($customers)))));
        $from = $since->getTimestamp();
        $cbb = fn ($x) => in_array($x['sender'], self::CBB, true);
        $new = [];
        $query = BotcakeChat::query();
        $adOpen = $query->getQuery()->getGrammar()->wrap('payload->' . ChatbotInbound::AD_OPEN);
        foreach ($query->whereIn('page_id', array_map('strval', array_keys($customers)))->whereIn('psid', $psids)->groupBy('page_id', 'psid')
            ->selectRaw("page_id, psid, MIN(received_at) AS first_at, MIN(CASE WHEN {$adOpen} IS NULL THEN received_at END) AS first_message")->toBase()->get() as $f) {
            [$id, $psid] = [(string) $f->page_id, (string) $f->psid];
            $first = strtotime((string) $f->first_at); // ang oras ng DB ay sa app timezone (ang default ng PHP), gaya ng Carbon::parse
            $message = $f->first_message === null ? $first : strtotime((string) $f->first_message);
            if (!isset($customers[$id][$psid]) || $first < $from) continue;
            $list = $echoes[$id . '|' . $psid] ?? collect();
            if ($list->contains(fn ($x) => $cbb($x) && $x['at'] < $first - self::EARLY_SECONDS)) continue;
            $new[$id][$psid] = [$first, $message];
        }
        if (!$new) return [];

        // Ang echo ng CBB bago pa ang window (sequence o loop ng lumang customer): hindi bago.
        $older = DB::table('chatbot_events')->where('type', self::TYPE)->whereIn('page_id', array_map('intval', array_keys($new)))
            ->whereIn('psid', array_map('intval', array_merge(...array_map('array_keys', array_values($new)))))
            ->where('created_at', '<', $since->copy()->subSeconds(self::EARLY_SECONDS)->format('Y-m-d H:i:s'))
            ->whereIn('detail->sender', self::CBB)->distinct()->get(['page_id', 'psid']);
        foreach ($older as $o) unset($new[(string) $o->page_id][(string) $o->psid]);

        $answered = [];
        foreach ($new as $id => $list) {
            $out[$id] = ['new' => count($list), 'cbb' => 0, 'main' => []];
            foreach ($list as $psid => [$first, $message]) {
                $in = fn ($x) => $x['at'] >= $first - self::EARLY_SECONDS && $x['at'] <= $message + $wait;
                if (!($echoes[$id . '|' . $psid] ?? collect())->contains(fn ($x) => $cbb($x) && $in($x))) continue;
                $out[$id]['cbb']++;
                $answered[$id][(string) $psid] = [$first, $message];
            }
        }
        if (!$answered || !Schema::hasColumn('chatbot_replies', 'sequence_id')) return $out;

        $settings = app(PageSettings::class);
        $groups = app(AdGroups::class);
        $flows = [];
        foreach (array_keys($answered) as $id) {
            $name = (string) ($names[$id] ?? '');
            $ids = [trim((string) $settings->get($name, ChatbotFields::PROVIDER, 'flow_id'))];
            foreach ($groups->activeGroups($name) as $g) $ids[] = trim((string) ($g['values']['flow_id'] ?? ''));
            $flows[$id] = array_flip(array_filter($ids, 'ctype_digit'));
        }
        $replies = DB::table('chatbot_replies')->whereIn('page_id', array_map('intval', array_keys($answered)))
            ->whereIn('psid', array_map('intval', array_merge(...array_map('array_keys', array_values($answered)))))
            ->where('kind', 'send')->where('status', 'sent')->whereNull('sequence_id')->whereNotNull('flow_id')
            ->where('sent_at', '>=', $since->copy()->subSeconds(self::EARLY_SECONDS)->format('Y-m-d H:i:s'))->get(['page_id', 'psid', 'flow_id', 'sent_at']);
        foreach ($replies as $rep) {
            [$id, $psid] = [(string) $rep->page_id, (string) $rep->psid];
            [$first, $message] = $answered[$id][$psid] ?? [null, null];
            $sent = strtotime((string) $rep->sent_at);
            if ($first === null || !isset($flows[$id][(string) $rep->flow_id]) || $sent < $first - self::EARLY_SECONDS || $sent > $message + $wait) continue;
            $out[$id]['main'][$psid] = $message;
        }

        return $out;
    }

    public static function limit(string $key): int
    {
        return max(1, (int) config('services.gateway.echo.' . $key));
    }
}

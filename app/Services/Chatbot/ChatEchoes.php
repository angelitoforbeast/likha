<?php

namespace App\Services\Chatbot;

use App\Models\BotcakeChat;
use App\Services\BotcakeChatGuard;
use App\Services\BotcakeMock;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ang echo: bawat mensaheng ipinadala ng page sa customer, mula sa alinmang app (Forbeast, Botcake, ang Page Inbox ng tao, …),
 * ay ipinapasa ng Meta sa bawat app na naka-subscribe sa page, kaya dumarating ito sa Likha sa Forbeast Gateway (ang Botcake
 * ay hindi nagpapasa ng echo nito). Iniimbak nang maikli sa chatbot_events (type echo; psid = ang customer, created_at = ang
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
    public const HEALTH_CACHE = 'bot-health:echoes';
    private const HEALTH_CACHE_SECONDS = 60;
    private const EXAMPLES = 5;
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

        return self::APPS[(string) $appId] ?? 'other';
    }

    public static function label(?string $sender): string
    {
        return self::LABELS[(string) $sender] ?? self::LABELS['other'];
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
            DB::table('chatbot_events')->insert(['page_id' => (int) $pageId, 'psid' => (int) $psid, 'chat_id' => null, 'flow_id' => null, 'node_id' => null,
                'type' => self::TYPE, 'detail' => json_encode(['mid' => $mid, 'app_id' => $appId, 'sender' => self::sender($appId), 'hash' => self::hash($text),
                    'text' => mb_substr($text, 0, self::TEXT)], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
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
        $sender = (string) (json_decode((string) $row->detail, true)['sender'] ?? 'other');

        return ['sender' => $sender, 'label' => self::label($sender), 'at' => (string) $row->created_at];
    }

    /**
     * Ang Bot Health kada page sa huling HEALTH_MINUTES: ang hindi nasagot (customer na may mensahe na walang echo mula sa
     * anumang app sa loob ng unanswered_minutes) at ang dobleng sagot (dalawang magkaibang app sa iisang customer sa loob ng
     * double_seconds). Ang page na sinusukat: may echo sa huling 24 oras, naka-On ang bot, at hindi test page.
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
            ->map(fn ($r) => ['page' => (string) $r->page_id, 'psid' => (string) $r->psid, 'at' => Carbon::parse($r->created_at)->getTimestamp(),
                'app' => json_decode((string) $r->detail, true)['app_id'] ?? null, 'sender' => json_decode((string) $r->detail, true)['sender'] ?? 'other'])
            ->groupBy(fn ($x) => $x['page'] . '|' . $x['psid']);

        $out = [];
        $row = function (string $id) use (&$out, $names): void {
            $out[$id] ??= ['page_id' => $id, 'page' => $names[$id] ?? 'Page ' . $id, 'unanswered' => 0, 'unanswered_messages' => 0, 'first_at' => null,
                'examples' => [], 'doubles' => 0, 'double_examples' => []];
        };
        $inbox = fn (string $id, string $psid) => route('chatbot.inbox', ['page_id' => $id, 'psid' => $psid], false);

        // Ang hindi nasagot: ang mensahe ng customer na lampas na sa paghihintay, walang echo sa [dating − EARLY, dating + wait].
        $ignoring = ChatAttachments::ignoring();
        $chats = BotcakeChat::active()->whereIn('page_id', $pages)->where('received_at', '>=', $since)->where('received_at', '<=', now()->subSeconds($wait))
            ->orderBy('received_at')->get(['id', 'page_id', 'psid', 'message', 'payload', 'received_at']);
        $missed = $firstAt = [];
        foreach ($chats as $c) {
            // Ang pagbukas ng ad ay hindi mensahe: walang sagot na hinihintay.
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
        foreach ($echoes as $list) {
            $list = $list->filter(fn ($x) => $x['at'] >= $from)->values();
            for ($i = 0; $i + 1 < $list->count(); $i++) {
                [$a, $b] = [$list[$i], $list[$i + 1]];
                if ($a['app'] === null || $b['app'] === null || $a['app'] === $b['app'] || $b['at'] - $a['at'] > $gap) continue;
                $row($a['page']);
                $r = &$out[$a['page']];
                $r['doubles']++;
                $r['double_examples'][] = ['at' => Carbon::createFromTimestamp($b['at'])->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'),
                    'senders' => self::label($a['sender']) . ' + ' . self::label($b['sender']), 'inbox' => $inbox($a['page'], $a['psid'])];
                unset($r);
                $i++; // ang pangalawa ay hindi na kapares ng susunod
            }
        }
        foreach ($out as &$r) {
            usort($r['double_examples'], fn ($a, $b) => $b['at'] <=> $a['at']);
            $r['double_examples'] = array_slice($r['double_examples'], 0, self::EXAMPLES);
        }
        unset($r);
        $out = array_values($out);
        usort($out, fn ($a, $b) => [-$a['unanswered'], -$a['doubles'], $a['page']] <=> [-$b['unanswered'], -$b['doubles'], $b['page']]);

        return ['pages' => $out, 'measured' => count($pages)];
    }

    public static function limit(string $key): int
    {
        return max(1, (int) config('services.gateway.echo.' . $key));
    }
}

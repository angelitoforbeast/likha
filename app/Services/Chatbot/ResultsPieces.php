<?php

namespace App\Services\Chatbot;

use App\Models\ChatbotReply;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Spec 278-2: ang mga bahagi ng /chatbot/results na mula sa padala at chat, binubuo kada araw (Manila) at kada saklaw
 * (page, flow, version; o ang flow version o botfield ng Compare), hindi kada range. Sa malaking site ay ~1.7M padala
 * kada araw at hindi kaya ang 7 araw na basa kada 15 minuto, kaya ang isang araw ay binabasa nang isang beses: ang
 * report ng range ay ang pagsasama ng mga araw nito (kabuuan, at unyon ng set ng customer para eksakto pa rin ang
 * distinct). Ang basa ng chatbot_replies ay isang araw lang sa index ng sent_at, puro column (walang JSON: ang via ay
 * ang column na via), paisa-isang oras (amendment 278-3: ang buong araw na isang query ay lampas 300 s sa live, ang isang
 * oras ay ~0.5 s), papunta sa bilang sa PHP.
 *
 * Kailan binubuo ulit: ang ngayon kapag lampas 15 minuto; kahapon at noong isang araw kapag lampas 60 minuto (ang
 * replied_at ay napupunan hanggang 24 oras at may backfill kada oras); ang mas luma ay minsan lang (kapag nabuo na
 * pagkatapos maging luma ang araw). Itinatago nang 9 araw kapag nasa default na 7 araw, 1 araw kung hindi.
 */
class ResultsPieces
{
    public const PREFIX = 'chatbot-results:piece:v1:';
    public const TODAY_STALE = 900;
    public const RECENT_STALE = 3600;
    /** Kahapon at noong isang araw ay "bago" pa (60 minuto); mula sa ikatlong araw ay luma na. */
    public const RECENT_DAYS = 2;
    public const KEEP_WINDOW = 9 * 86400;
    public const KEEP_OTHER = 86400;
    private const CHUNK = 1000;
    private const PACK_CHUNK = 5000;
    /** Ang chat na mula sa gateway na hindi kilala ang gateway: kasama sa lahat, wala sa anumang bot. */
    public const NO_BUCKET = '-';

    // ---- Saklaw at araw ----

    /** Ang saklaw ng funnel, By bot, By page at By message: page, flow at version (walang via: ang hati ay kada bot). */
    public static function main(array $f): array
    {
        return ['kind' => 'main', 'page_id' => $f['page_id'] ?? null, 'flow_id' => $f['flow_id'] ?? null, 'version_id' => $f['version_id'] ?? null];
    }

    /** Ang saklaw ng By sequence step, By ad at chats: page lang (dati: walang filter ng flow). */
    public static function base(array $f): array
    {
        return ['kind' => 'main', 'page_id' => $f['page_id'] ?? null, 'flow_id' => null, 'version_id' => null];
    }

    /** Compare ng flow version: ang padala ng version na iyon (page lang ang ibang filter). */
    public static function flowVersion(array $f, int $version): array
    {
        return ['kind' => 'vflow', 'page_id' => $f['page_id'] ?? null, 'id' => $version];
    }

    /** Compare ng botfield version: ang mga padalang may tag ng page_settings row na iyon. */
    public static function botfield(array $f, int $setting): array
    {
        return ['kind' => 'field', 'page_id' => $f['page_id'] ?? null, 'id' => $setting];
    }

    private static function isBase(array $scope): bool
    {
        return $scope['kind'] === 'main' && $scope['flow_id'] === null && $scope['version_id'] === null;
    }

    /** Ang mga araw (Y-m-d, Manila) mula $from hanggang $to. @return array<int, string> */
    public static function days(string $from, string $to): array
    {
        $out = [];
        for ($d = Carbon::parse($from, 'Asia/Manila'); $d->toDateString() <= $to; $d->addDay()) $out[] = $d->toDateString();

        return $out;
    }

    /** @return array{0: Carbon, 1: Carbon} [simula, dulo) ng araw sa timezone ng app */
    public static function bounds(string $day): array
    {
        $tz = config('app.timezone');

        return [Carbon::parse($day, 'Asia/Manila')->startOfDay()->timezone($tz), Carbon::parse($day, 'Asia/Manila')->addDay()->startOfDay()->timezone($tz)];
    }

    private static function key(string $day, array $scope): string
    {
        return self::PREFIX . $day . ':' . md5(json_encode($scope));
    }

    // ---- Tago at basa ----

    public function get(string $day, array $scope): ?array
    {
        $raw = Cache::get(self::key($day, $scope));
        if (!is_string($raw)) return null;
        $piece = @unserialize((string) @gzuncompress((string) base64_decode($raw, true)), ['allowed_classes' => false]);

        return is_array($piece) && isset($piece['built_at']) ? $piece : null;
    }

    /** Wala, o kailangan nang buuin ulit ayon sa araw (ngayon 15 min, 2 araw 60 min, ang luma kapag nabuo bago naging luma). */
    public static function stale(string $day, ?array $piece): bool
    {
        if (!$piece) return true;
        $built = Carbon::parse($piece['built_at']);
        $today = now('Asia/Manila')->startOfDay();
        $d = Carbon::parse($day, 'Asia/Manila')->startOfDay();
        if ($d->gte($today)) return $built->lt(now()->subSeconds(self::TODAY_STALE));
        if ($d->gte($today->copy()->subDays(self::RECENT_DAYS))) return $built->lt(now()->subSeconds(self::RECENT_STALE));

        // Ang luma ay hindi na nagbabago, pero ang nabuo noong bago pa ito (hal. huminto ang warm) ay binubuo ulit nang minsan.
        return $built->lt($d->copy()->addDays(self::RECENT_DAYS + 1));
    }

    /** Ang bahagi ng araw, binubuo kapag wala o luma. */
    public function ensure(string $day, array $scope): array
    {
        $piece = $this->get($day, $scope);

        return self::stale($day, $piece) ? $this->build($day, $scope) : $piece;
    }

    /** Binabasa ang araw at itinatabi ang bahagi (siksik: serialize, gzip, base64, para kahit database cache). */
    public function build(string $day, array $scope): array
    {
        [$start, $end] = self::bounds($day);
        $piece = ['built_at' => now()->toIso8601String()] + match ($scope['kind']) {
            'main' => $this->readMain($scope, $start, $end),
            'vflow' => $this->readSends($scope, $start, $end, false, true),
            'field' => $this->readBotfield($scope, $start, $end),
        };
        $inWindow = $day >= now('Asia/Manila')->subDays(ChatbotResults::DEFAULT_DAYS - 1)->toDateString();
        Cache::put(self::key($day, $scope), base64_encode(gzcompress(serialize($piece), 1)), $inWindow ? self::KEEP_WINDOW : self::KEEP_OTHER);

        return $piece;
    }

    // ---- Bot at set ----

    /** Ang bot ng padala, gaya ng whereVia: ang gateway (forbeast, incepxionbot) ay siya; ang iba, pati walang via, ay botcake. */
    public static function bucket(mixed $via): string
    {
        return is_string($via) && Gateways::valid($via) ? $via : ChatbotProvider::BOTCAKE;
    }

    /**
     * Ang bot ng chat, gaya ng dating whereVia ng payload->source: hindi gateway ang source = botcake; gateway na walang
     * payload->gateway o forbeast = forbeast; gateway na incepxionbot = incepxionbot; iba = NO_BUCKET.
     */
    public static function chatBucket(array $payload): string
    {
        if (($payload['source'] ?? null) !== 'gateway') return ChatbotProvider::BOTCAKE;
        $gw = $payload['gateway'] ?? null;
        if ($gw === null || $gw === Gateways::FORBEAST) return Gateways::FORBEAST;

        return $gw === Gateways::INCEPXIONBOT ? Gateways::INCEPXIONBOT : self::NO_BUCKET;
    }

    /** Ang set (mga key) bilang siksik na string: 'J' + mga 64-bit na numero kapag puro numero, kung hindi 'S' + JSON. */
    public static function pack(array $set): string
    {
        $keys = array_keys($set);
        foreach ($keys as $k) if (!is_int($k)) return 'S' . json_encode(array_map('strval', $keys));
        sort($keys);
        $out = 'J';
        foreach (array_chunk($keys, self::PACK_CHUNK) as $chunk) $out .= pack('J*', ...$chunk);

        return $out;
    }

    /** Idinadagdag ang laman ng siksik na set sa $set (unyon). */
    public static function addTo(array &$set, string $packed): void
    {
        if ($packed === '' || $packed === 'J') return;
        if ($packed[0] === 'S') {
            foreach ((array) json_decode(substr($packed, 1), true) as $k) $set[$k] = true;
            return;
        }
        $n = intdiv(strlen($packed) - 1, 8);
        for ($i = 0; $i < $n; $i += self::PACK_CHUNK) {
            foreach (unpack('J*', substr($packed, 1 + $i * 8, min(self::PACK_CHUNK, $n - $i) * 8)) as $v) $set[$v] = true;
        }
    }

    /** @return array<int, int> */
    public static function ints(string $packed, string $code = 'N'): array
    {
        return $packed === '' ? [] : array_values(unpack($code . '*', $packed));
    }

    private static function packInts(array $v, string $code = 'N'): string
    {
        $out = '';
        foreach (array_chunk($v, self::PACK_CHUNK) as $chunk) $out .= pack($code . '*', ...$chunk);

        return $out;
    }

    /** Ang pinakabagong MEDIAN_ROWS (pinakamataas na id) ng mga [id, key, seconds], siksik. @return array{0: string, 1: string, 2: string} */
    private static function newest(array $ids, array $keys, array $secs): array
    {
        if (!$ids) return ['', '', ''];
        array_multisort($ids, SORT_DESC, SORT_NUMERIC, $keys, $secs);

        return [self::packInts(array_slice($ids, 0, ChatbotResults::MEDIAN_ROWS), 'J'), self::packInts(array_slice($keys, 0, ChatbotResults::MEDIAN_ROWS)),
            self::packInts(array_slice($secs, 0, ChatbotResults::MEDIAN_ROWS))];
    }

    /**
     * Amendment 278-3: ang [$start, $end) na hinati kada oras (gaya ng ResponseSpeed::windows). Sa live, ang buong araw na
     * isang query ay lampas 300 s, ang isang oras ay ~0.5 s at ~85k row.
     * @return \Generator<int, array{0: Carbon, 1: Carbon}>
     */
    public static function hours(Carbon $start, Carbon $end): \Generator
    {
        for ($a = $start->copy(); $a->lt($end); $a = $b) {
            $b = $a->copy()->addHour();
            if ($b->gt($end)) $b = $end->copy();
            yield [$a, $b];
        }
    }

    /**
     * Ang basa ng araw nang paisa-isang oras: $query($a, $b) ang query ng oras na iyon (normal, naka-buffer), at ang
     * bawat row ay ipinapasa sa $each (pare-pareho ang gastos kada row: set bilang array key, walang paghahanap o
     * pag-aayos habang nagbabasa). $afterHour: pagkatapos ng bawat oras (hal. pagbawas ng hawak na median).
     */
    private static function stream(callable $query, Carbon $start, Carbon $end, callable $each, ?callable $afterHour = null): void
    {
        foreach (self::hours($start, $end) as [$a, $b]) {
            foreach ($query($a, $b)->cursor() as $row) $each($row);
            if ($afterHour) $afterHour();
        }
    }

    /** Ang hawak na [id, key, seconds] ay hindi hihigit sa 2 × MEDIAN_ROWS kada bot: ang pinakabago lang ang naiiwan. */
    private static function trim(array &$med): void
    {
        foreach ($med as &$m) {
            if (count($m[0]) <= 2 * ChatbotResults::MEDIAN_ROWS) continue;
            array_multisort($m[0], SORT_DESC, SORT_NUMERIC, $m[1], $m[2]);
            foreach ([0, 1, 2] as $i) array_splice($m[$i], ChatbotResults::MEDIAN_ROWS);
        }
    }

    /** Ang via ng row: ang column na via; kapag wala pang column (lumang install), mula sa tags sa PHP (walang JSON sa SQL). */
    private static function viaOf(object $r): mixed
    {
        if (property_exists($r, 'via')) return $r->via;
        $t = is_string($r->tags ?? null) ? json_decode($r->tags, true) : null;

        return is_array($t) ? ($t['via'] ?? null) : null;
    }

    private static function viaColumn(string $table = ''): string
    {
        return ($table !== '' ? $table . '.' : '') . (ChatbotReply::hasVia() ? 'via' : 'tags');
    }

    // ---- Basa ----

    /** Ang bahagi ng funnel/By bot/By page/By message (at sa saklaw na page lang: By sequence step, By ad, chats). */
    private function readMain(array $scope, Carbon $start, Carbon $end): array
    {
        $base = self::isBase($scope);
        $out = $this->readSends($scope, $start, $end, $base, false) + ['messages' => $this->readMessages($scope, $start, $end)];
        if ($base) $out['chats'] = array_map(fn ($b) => array_map([self::class, 'pack'], $b), $this->chatSets($scope['page_id'], $start, $end));

        return $out;
    }

    /**
     * Isang basa ng padala (send, sent) ng araw: kada page at bot ang sent, replied at set ng customer; $steps: pati ang
     * sequence step at ad (kada bot); $median: pati ang [id, reply_seconds] (pinakabago) para sa Compare.
     */
    private function readSends(array $scope, Carbon $start, Carbon $end, bool $steps, bool $median): array
    {
        $cols = array_merge(['page_id', 'psid', 'replied_at', self::viaColumn()], $steps ? ['sequence_id', 'sequence_step', 'ad_id'] : [], $median ? ['id', 'reply_seconds'] : []);
        $q = fn (Carbon $a, Carbon $b) => DB::table('chatbot_replies')->where('sent_at', '>=', $a)->where('sent_at', '<', $b)->where('kind', 'send')->where('status', 'sent')
            ->when($scope['page_id'], fn ($q, $v) => $q->where('page_id', (int) $v))
            ->when($scope['kind'] === 'main' && $scope['flow_id'], fn ($q) => $q->where('flow_id', (int) $scope['flow_id']))
            ->when($scope['kind'] === 'main' && $scope['version_id'], fn ($q) => $q->where('flow_version_id', (int) $scope['version_id']))
            ->when($scope['kind'] === 'vflow', fn ($q) => $q->where('flow_version_id', (int) $scope['id']))
            ->select($cols);

        $pages = [];
        $seq = [];
        $ads = [];
        $med = [];
        self::stream($q, $start, $end, function ($r) use (&$pages, &$seq, &$ads, &$med, $steps, $median) {
            $b = self::bucket(self::viaOf($r));
            $p = (string) $r->page_id;
            $replied = $r->replied_at !== null;
            $pages[$p][$b] ??= [0, 0, []];
            $pages[$p][$b][0]++;
            if ($replied) $pages[$p][$b][1]++;
            $pages[$p][$b][2][$r->psid] = true;
            if ($steps) {
                if ($r->sequence_id !== null) {
                    $k = (int) $r->sequence_id . ':' . (int) $r->sequence_step;
                    $seq[$b][$k] ??= [(int) $r->sequence_id, (int) $r->sequence_step, 0, 0];
                    $seq[$b][$k][2]++;
                    if ($replied) $seq[$b][$k][3]++;
                }
                // Gaya ng dating WHERE ad_id <> '' (MySQL: ang puro espasyo ay katumbas ng '').
                if ($r->ad_id !== null && rtrim((string) $r->ad_id, ' ') !== '') $ads[$b][(string) $r->ad_id][$r->psid] = true;
            }
            if ($median && $r->reply_seconds !== null) {
                $med[$b][0][] = (int) $r->id;
                $med[$b][1][] = 0;
                $med[$b][2][] = (int) $r->reply_seconds;
            }
        }, function () use (&$med) { self::trim($med); });

        $out = ['sends' => array_map(fn ($byBot) => array_map(fn ($c) => [$c[0], $c[1], self::pack($c[2])], $byBot), $pages)];
        if ($steps) {
            $out['steps'] = $seq;
            $out['ads'] = array_map(fn ($byAd) => array_map([self::class, 'pack'], $byAd), $ads);
        }
        if ($median) $out['seconds'] = array_map(fn ($m) => array_slice(self::newest($m[0], $m[1], $m[2]), 0, 3), $med);

        return $out;
    }

    /**
     * Ang By message ng araw: isang DISTINCT na basa ng tags at padala (reply_id + grupo ay iisang row). Kada bot at grupo
     * (flow, node, botfield, version): sent at replied (distinct reply_id; isang araw lang ang reply, kaya nadadagdag),
     * at ang pinakabagong MEDIAN_ROWS na [reply_id, grupo, reply_seconds] para sa median.
     */
    private function readMessages(array $scope, Carbon $start, Carbon $end): array
    {
        $q = fn (Carbon $a, Carbon $b) => DB::table('chatbot_reply_tags as t')->join('chatbot_replies as r', 'r.id', '=', 't.reply_id')
            ->where('t.sent_at', '>=', $a)->where('t.sent_at', '<', $b)->where('r.kind', 'send')
            ->when($scope['page_id'], fn ($q, $v) => $q->where('t.page_id', (int) $v))
            ->when($scope['flow_id'], fn ($q, $v) => $q->where('t.flow_id', (int) $v))
            ->when($scope['version_id'], fn ($q, $v) => $q->where('t.flow_version_id', (int) $v))
            ->distinct()->select(['t.reply_id', 't.flow_id', 't.node_id', 't.botfield', 't.setting_id', 'r.replied_at', 'r.reply_seconds', self::viaColumn('r')]);

        $keys = [];
        $index = [];
        $counts = [];
        $med = [];
        // Ang DISTINCT ay kada oras: ang mga tag row ng isang reply ay iisa ang sent_at, kaya walang reply na nasa dalawang oras.
        self::stream($q, $start, $end, function ($r) use (&$keys, &$index, &$counts, &$med) {
            $tuple = [$r->flow_id !== null ? (int) $r->flow_id : null, $r->node_id !== null ? (string) $r->node_id : null,
                $r->botfield !== null ? (string) $r->botfield : null, $r->setting_id !== null ? (int) $r->setting_id : null];
            $k = ChatbotResults::groupKey(...$tuple);
            if (!isset($index[$k])) {
                $index[$k] = count($keys);
                $keys[] = $tuple;
            }
            $i = $index[$k];
            $b = self::bucket(self::viaOf($r));
            $counts[$b][$i] ??= [0, 0];
            $counts[$b][$i][0]++;
            if ($r->replied_at !== null) $counts[$b][$i][1]++;
            if ($r->reply_seconds !== null) {
                $med[$b][0][] = (int) $r->reply_id;
                $med[$b][1][] = $i;
                $med[$b][2][] = (int) $r->reply_seconds;
            }
        }, function () use (&$med) { self::trim($med); });

        return ['keys' => $keys, 'counts' => $counts, 'seconds' => array_map(fn ($m) => self::newest($m[0], $m[1], $m[2]), $med)];
    }

    /**
     * Ang mga customer na nag-chat (kada page at bot, ang NO_BUCKET ay sa lahat lang) sa [$start, $end): puro column ang
     * basa, ang payload ay binabasa sa PHP (ang standby ay wala sa bilang, gaya ng whereNull payload->_standby).
     * @return array<string, array<string, array<int|string, true>>>
     */
    public function chatSets(?string $pageId, Carbon $start, Carbon $end): array
    {
        $q = fn (Carbon $a, Carbon $b) => DB::table('botcake_chats')->where('received_at', '>=', $a)->where('received_at', '<', $b)
            ->when($pageId, fn ($q, $v) => $q->where('page_id', (string) $v))->select(['page_id', 'psid', 'payload']);
        $out = [];
        self::stream($q, $start, $end, function ($r) use (&$out) {
            $p = is_string($r->payload) ? json_decode($r->payload, true) : (array) $r->payload;
            if (!is_array($p)) $p = [];
            if (isset($p['_standby'])) return;
            $out[(string) $r->page_id][self::chatBucket($p)][$r->psid] = true;
        });

        return $out;
    }

    /**
     * Ang Compare ng botfield version sa araw: ang mga padalang may tag ng setting (anumang kind, gaya ng dati): kada page
     * ang sent, replied at customer, at ang pinakabagong [id, reply_seconds]. Walang hati kada bot (gaya ng dati).
     */
    private function readBotfield(array $scope, Carbon $start, Carbon $end): array
    {
        $ids = [];
        self::stream(fn (Carbon $a, Carbon $b) => DB::table('chatbot_reply_tags')->where('setting_id', $scope['id'])->where('sent_at', '>=', $a)->where('sent_at', '<', $b)
            ->when($scope['page_id'], fn ($q, $v) => $q->where('page_id', (int) $v))->select('reply_id'), $start, $end, function ($t) use (&$ids) { $ids[(int) $t->reply_id] = true; });

        $pages = [];
        $med = [[], [], []];
        foreach (array_chunk(array_keys($ids), self::CHUNK) as $chunk) {
            foreach (DB::table('chatbot_replies')->whereIn('id', $chunk)->get(['id', 'page_id', 'psid', 'replied_at', 'reply_seconds']) as $r) {
                $p = (string) $r->page_id;
                $pages[$p]['*'] ??= [0, 0, []];
                $pages[$p]['*'][0]++;
                if ($r->replied_at !== null) $pages[$p]['*'][1]++;
                $pages[$p]['*'][2][$r->psid] = true;
                if ($r->reply_seconds !== null) {
                    $med[0][] = (int) $r->id;
                    $med[1][] = 0;
                    $med[2][] = (int) $r->reply_seconds;
                }
            }
        }

        return ['sends' => array_map(fn ($byBot) => array_map(fn ($c) => [$c[0], $c[1], self::pack($c[2])], $byBot), $pages),
            'seconds' => ['*' => self::newest(...$med)]];
    }
}

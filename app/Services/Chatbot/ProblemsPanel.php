<?php

namespace App\Services\Chatbot;

use App\Models\BotcakeChat;
use App\Models\PageSetting;
use App\Services\BotcakeChatGuard;
use App\Services\BotcakeMock;
use App\Services\Chatblast;
use App\Services\ChatblastSetupCheck;
use App\Services\ChatbotFields;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Ang banner ng mga problema sa itaas ng bawat screen ng Chatbot at Chatblast: kada page, lahat ng mali ngayon, bawat isa
 * may kulay at link kung saan ito inaayos o tinitingnan. Galing sa mga dati nang sukat (mga pumalyang padala, ang hindi
 * nasagot at dobleng sagot ng echo, ang setup check, ang mga lipat ng ForbeastWatch) at sa dalawang bago: mabagal at walang
 * chat. Ang bawat pinagmulan ay naka-cache nang hanggang 60 segundo; ang pumalyang isa ay hindi nagtatago ng iba.
 */
class ProblemsPanel
{
    public const CACHE = 'chatbot:problems-panel';
    public const CACHE_SECONDS = 60;
    /** Ang karaniwan ng page sa oras na ito (huling USUAL_DAYS araw): hindi gaanong gumagalaw, kaya mas matagal ang cache. */
    public const USUAL_CACHE_SECONDS = 900;
    public const USUAL_DAYS = 7;
    public const EVENT_HOURS = 6;
    /** Ang page na "nagbebenta": may order (macro_output, ayon sa ts_date) sa huling ORDER_DAYS araw ng Manila, ngayon kasama. */
    public const ORDER_DAYS = 14;
    /** Ang mga kulay, pinakamabigat muna; ang puti ay hindi urgent. */
    public const LEVELS = ['red' => '🔴', 'orange' => '🟠', 'white' => '⚪'];

    public function __construct(private PageSettings $settings)
    {
    }

    /**
     * Ang mga page na may problema (pinaka-urgent muna) at ilan ang urgent; null kapag wala.
     * @return array{pages: array<int, array{page: string, level: string, lines: array<int, array{level: string, text: string, links: array<int, array{0: string, 1: string}>}>}>, urgent: int, doubles: bool}|null
     */
    public function panel(): ?array
    {
        // Ang banner ay hindi dapat makasira ng screen.
        return $this->safely(fn () => $this->build(), null);
    }

    private function build(): ?array
    {
        $own = Cache::remember(self::CACHE, self::CACHE_SECONDS, fn () => [
            'events' => $this->safely(fn () => $this->events(), []),
            'slow' => $this->safely(fn () => $this->slow(), []),
            'quiet' => $this->safely(fn () => $this->quiet(), []),
            'sending' => $this->safely(fn () => array_map(fn ($p) => $this->settings->pageKey($p), app(Chatblast::class)->sendingPages()), []),
            // Ang mga row na may parehong page ID: dalawang query, kaya naka-cache rin gaya ng iba (ang banner ay sa bawat screen).
            'same_ids' => $this->safely(fn () => $this->sameIds(), []),
            // Ang mga page na may order sa huling ORDER_DAYS araw (isang naka-group na query); null kapag hindi nabasa.
            'selling' => $this->safely(fn () => $this->selling(), null),
        ]);
        $echoes = $this->safely(fn () => $this->echoes(), []);
        $lines = array_merge(
            $this->safely(fn () => $this->sends(), []),
            $echoes,
            $own['events'], $own['slow'], $own['quiet'],
            $this->safely(fn () => $this->setup($own['sending'], $own['selling'] ?? null), []),
            $own['same_ids'] ?? [],
        );
        if (!$lines) return null;

        $rank = array_flip(array_keys(self::LEVELS));
        $pages = [];
        $acked = [];
        $acks = app(ProblemAcks::class);
        foreach ($lines as $l) {
            $key = $this->settings->pageKey($l['page']);
            $kind = ProblemAcks::kind($l['text']);
            $line = ['level' => $l['level'], 'text' => $l['text'], 'links' => $l['links'], 'kind' => $kind];
            // Acknowledged o Done: wala sa listahan ng problema (nasa "Acknowledged"), hanggang bumalik ayon sa ProblemAcks.
            $ack = $this->safely(fn () => $acks->state($l['page'], $kind, $l['level']), null);
            if ($ack !== null && $ack['hidden']) {
                $acked[$key] ??= ['page' => $l['page'], 'lines' => []];
                $acked[$key]['lines'][] = $line + ['ack' => $ack];
                continue;
            }
            if ($ack !== null) $line['ack'] = $ack; // bumalik: ipinapakita kung sino ang nag-ack, kailan at bakit bumalik
            $pages[$key] ??= ['page' => $l['page'], 'level' => 'white', 'lines' => []];
            $pages[$key]['lines'][] = $line;
            if ($rank[$l['level']] < $rank[$pages[$key]['level']]) $pages[$key]['level'] = $l['level'];
        }
        if (!$pages && !$acked) return null;
        $count = fn ($p, $level) => count(array_filter($p['lines'], fn ($l) => $l['level'] === $level));
        foreach ($pages as &$p) usort($p['lines'], fn ($a, $b) => $rank[$a['level']] <=> $rank[$b['level']]);
        unset($p);
        $pages = array_values($pages);
        usort($pages, fn ($a, $b) => [$rank[$a['level']], -$count($a, 'red'), -$count($a, 'orange'), -count($a['lines']), mb_strtolower($a['page'])]
            <=> [$rank[$b['level']], -$count($b, 'red'), -$count($b, 'orange'), -count($b['lines']), mb_strtolower($b['page'])]);

        // doubles: may linya ng dobleng sagot (ang hindi pula sa echo), para lumabas lang ang paliwanag ng "bago" kapag kailangan.
        return ['pages' => $pages, 'urgent' => count(array_filter($pages, fn ($p) => $p['level'] !== 'white')),
            'doubles' => (bool) array_filter($echoes, fn ($l) => $l['level'] !== 'red'), 'acked' => array_values($acked)];
    }

    private function safely(callable $fn, mixed $fallback): mixed
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            report($e);
            return $fallback;
        }
    }

    private static function line(string $page, string $level, string $text, array $links): array
    {
        return ['page' => $page, 'level' => $level, 'text' => $text, 'links' => $links];
    }

    private static function name(string $pageId): string
    {
        return BotcakeChat::pageMap()[$pageId] ?? 'Page ' . $pageId;
    }

    private static function health(string $pageId, string $anchor): array
    {
        return ['Health', '/chatbot/health?page_id=' . $pageId . '#' . $anchor];
    }

    private static function inbox(string $pageId): array
    {
        return ['Inbox', '/chatbot/inbox?page_id=' . $pageId];
    }

    private static function speed(string $pageId): array
    {
        return ['Speed', '/chatbot/speed?page_id=' . $pageId];
    }

    private static function hhmm(?string $at): string
    {
        return substr((string) $at, 11, 5);
    }

    /** Ang mga pumalyang padala (Pages with problems ng Health): pula o amber ayon sa sarili nitong ilaw. */
    private function sends(): array
    {
        $out = [];
        foreach (app(BotHealth::class)->problems() as $p) {
            if (($p['send_light'] ?? null) === null) continue;
            $out[] = self::line($p['page'], $p['send_light'] === 'red' ? 'red' : 'orange',
                $p['send_reason'] . ': ' . ($p['failed'] - $p['unreachable']) . ' of ' . $p['sends'] . ' sends failed since ' . self::hhmm($p['send_since']),
                [self::health($p['page_id'], 'page-problems'), self::inbox($p['page_id'])]);
        }

        return $out;
    }

    /**
     * Ang hindi nasagot (pula, kapag sapat ang dami gaya ng Health) at ang dobleng sagot mula sa echo: orange lang ang
     * main-flow double (bagong customer na sinagot ng CBB at ng main flow ng Likha bot, kaya parehong naka-On ang dalawang
     * main flow); puti ang leftover (lumang customer na nasa sequence o loop pa ng CBB, mauubos din). Ang bilang ng bagong
     * customer at ng sinagot ng CBB ay nasa unang linya ng doble.
     */
    private function echoes(): array
    {
        $out = [];
        foreach (app(ChatEchoes::class)->health()['pages'] as $e) {
            if ($e['unanswered'] >= ChatEchoes::limit('unanswered_customers')) {
                $out[] = self::line($e['page'], 'red', $e['unanswered'] . ' customers not answered in ' . ChatEchoes::limit('unanswered_minutes') . ' min',
                    [['Inbox', $e['examples'][0]['inbox'] ?? self::inbox($e['page_id'])[1]], self::health($e['page_id'], 'who-answered')]);
            }
            $numbers = ' (new customers: ' . $e['new_customers'] . ', answered by CBB: ' . $e['cbb_new'] . ')';
            $window = ' in the last ' . ChatEchoes::HEALTH_MINUTES . ' min';
            if ($e['main_doubles'] > 0) {
                $links = [];
                foreach ($e['main_examples'] as $i => $x) $links[] = ['Example ' . ($i + 1), $x['inbox']];
                $out[] = self::line($e['page'], 'orange', 'Main flow double: ' . $e['main_doubles'] . ' new ' . ($e['main_doubles'] === 1 ? 'customer' : 'customers')
                    . ' answered by both CBB and the Likha bot' . $window . $numbers, [...$links, self::health($e['page_id'], 'who-answered')]);
            }
            if ($e['leftovers'] > 0) {
                $out[] = self::line($e['page'], 'white', $e['leftovers'] . ' leftover CBB ' . ($e['leftovers'] === 1 ? 'reply' : 'replies')
                    . ' to old customers (sequence/loop)' . $window . ($e['main_doubles'] > 0 ? '' : $numbers),
                    [['Inbox', $e['leftover_examples'][0]['inbox'] ?? self::inbox($e['page_id'])[1]], self::health($e['page_id'], 'who-answered')]);
            }
        }

        return $out;
    }

    /** Ang mga lipat, held at balik ng ForbeastWatch sa huling EVENT_HOURS: ang pinakabago ng bawat uri kada page. */
    private function events(): array
    {
        $rows = PageSetting::where('provider', ChatbotFields::PROVIDER)->where('key', ForbeastWatch::KEY)
            ->where('created_at', '>=', now()->subHours(self::EVENT_HOURS))->orderByDesc('id')->get(['page_name', 'value', 'is_secret', 'created_at']);
        $seen = $out = [];
        foreach ($rows as $s) {
            $e = json_decode((string) $s->plainValue(), true);
            $result = is_array($e) ? ($e['result'] ?? null) : null;
            $label = match (true) {
                $result === ForbeastWatch::SWITCHED && ($e['to'] ?? null) === ChatbotProvider::BOTCAKE => 'Moved to Botcake',
                $result === ForbeastWatch::HELD => 'Held on Forbeast',
                $result === ForbeastWatch::SWITCHED_BACK => 'Back on Forbeast',
                default => null,
            };
            $page = (string) $s->page_name;
            if ($label === null || isset($seen[$this->settings->pageKey($page)][$result])) continue;
            $seen[$this->settings->pageKey($page)][$result] = true;
            $why = preg_replace('/^held:\s*/i', '', ForbeastWatch::reason($e));
            $out[] = self::line($page, 'orange', $label . ' at ' . $s->created_at->timezone('Asia/Manila')->format('H:i') . ($why !== '' ? ': ' . $why : ''),
                [['History', '/chatbot/pages?changes=' . urlencode($page) . '&field=' . ForbeastWatch::KEY]]);
        }

        return $out;
    }

    /**
     * Mabagal: ang median ng unang sagot sa huling oras (slow_min_replies man lang) ay slow_seconds pataas, o slow_factor
     * beses ng karaniwan ng page sa parehong oras ng huling USUAL_DAYS araw. Ang sukat ay ang sa Speed screen.
     */
    private function slow(): array
    {
        if (!Schema::hasTable('botcake_chats') || !Schema::hasTable('chatbot_replies')) return [];
        $c = config('services.chatbot.problems');
        $now = now()->format('Y-m-d H:i:s');
        $usual = $this->usual()['median'];
        $out = [];
        foreach (app(ResponseSpeed::class)->pageSeconds(now()->subHour()->format('Y-m-d H:i:s'), $now, $now) as $id => $secs) {
            if (count($secs) < max(1, (int) $c['slow_min_replies']) || BotcakeMock::isTestId((string) $id)) continue;
            $m = (int) round(BotHealth::median($secs));
            $u = $usual[$id] ?? null;
            if ($m < (int) $c['slow_seconds'] && !($u > 0 && $m >= (float) $c['slow_factor'] * $u)) continue;
            $out[] = self::line(self::name((string) $id), 'orange', 'Slow: median first reply ' . $m . ' s in the last hour'
                . ($u !== null ? ' (usual ' . $u . ' s at this hour)' : ''), [self::speed((string) $id)]);
        }

        return $out;
    }

    /**
     * Walang chat: ang page na naka-On ang bot (hindi test page) na karaniwang no_chats_per_hour pataas ang chat sa oras na
     * ito, pero walang dumating sa huling no_chats_minutes.
     */
    private function quiet(): array
    {
        if (!Schema::hasTable('botcake_chats')) return [];
        $c = config('services.chatbot.problems');
        $minutes = max(1, (int) $c['no_chats_minutes']);
        // Ang pagbukas ng ad (ChatbotInbound::AD_OPEN) ay hindi chat ng customer: dito at sa karaniwang bilang sa ibaba.
        $recent = BotcakeChat::active()->whereNull('payload->' . ChatbotInbound::AD_OPEN)->where('received_at', '>=', now()->subMinutes($minutes))->pluck('page_id')->map(fn ($id) => (string) $id)->flip();
        $guard = app(BotcakeChatGuard::class);
        $out = [];
        foreach ($this->usual()['chats'] as $id => $perHour) {
            $id = (string) $id;
            if ($perHour < (int) $c['no_chats_per_hour'] || isset($recent[$id]) || BotcakeMock::isTestId($id)) continue;
            $name = BotcakeChat::pageMap()[$id] ?? null;
            if ($name === null || $guard->botOff($name)) continue;
            $out[] = self::line($name, 'white', 'No chats in the last ' . $minutes . ' min (usually ' . $perHour . ' an hour)', [self::inbox($id), self::speed($id)]);
        }

        return $out;
    }

    /**
     * Ang karaniwan ng bawat page sa parehong oras (ang 60 minutong natatapos sa ngayon) ng huling USUAL_DAYS araw: ang
     * median ng unang sagot (slow_min_replies man lang) at ang chat kada oras. Naka-cache kada 10 minuto.
     * @return array{median: array<string, int>, chats: array<string, int>}
     */
    private function usual(): array
    {
        $key = 'chatbot:problems-usual:' . now()->format('Y-m-d H:') . intdiv((int) now()->format('i'), 10);

        return Cache::remember($key, self::USUAL_CACHE_SECONDS, function () {
            $speed = app(ResponseSpeed::class);
            $secs = $chats = [];
            for ($d = 1; $d <= self::USUAL_DAYS; $d++) {
                $to = now()->subDays($d);
                $from = $to->copy()->subHour();
                [$f, $t] = [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')];
                foreach ($speed->pageSeconds($f, $t, $t) as $id => $list) $secs[$id] = array_merge($secs[$id] ?? [], $list);
                // Binibilang sa PHP (isang oras lang ng page_id): walang COUNT o GROUP BY sa botcake_chats.
                foreach (BotcakeChat::active()->whereNull('payload->' . ChatbotInbound::AD_OPEN)->where('received_at', '>=', $f)->where('received_at', '<', $t)->pluck('page_id') as $id) {
                    $chats[(string) $id] = ($chats[(string) $id] ?? 0) + 1;
                }
            }
            $min = max(1, (int) config('services.chatbot.problems.slow_min_replies'));

            return ['median' => array_map(fn ($l) => (int) round(BotHealth::median($l)), array_filter($secs, fn ($l) => count($l) >= $min)),
                'chats' => array_map(fn ($n) => (int) round($n / self::USUAL_DAYS), $chats)];
        });
    }

    /**
     * Dalawa o higit pang row na may iisang Botcake page ID (ang chat ay napupunta sa isa lang): isang orange na linya kada
     * grupo, sa unang row ayon sa pangalan, na nagsasabi kung aling row ang tugma sa pangalan sa Facebook (kung alam).
     */
    private function sameIds(): array
    {
        $out = $seen = [];
        $all = app(FacebookName::class)->overview();
        uasort($all, fn ($a, $b) => strnatcasecmp($a['page'], $b['page']));
        foreach ($all as $f) {
            if (!$f['same_id'] || isset($seen[$f['page_id']])) continue;
            $seen[$f['page_id']] = true;
            $which = $f['match'] !== null ? $f['match'] . ' matches the Facebook name'
                : ($f['name'] !== null ? 'no row matches the Facebook name "' . $f['name'] . '"' : 'Facebook name: unknown');
            $out[] = self::line($f['page'], 'orange', 'Same page ID ' . $f['page_id'] . ' as ' . implode(', ', $f['same_id']) . '; ' . $which,
                [['Pages', '/chatbot/pages']]);
        }

        return $out;
    }

    /**
     * Ang X ng setup check: orange kapag pinapadalhan ng chatblast ang page at may order ito sa huling ORDER_DAYS araw;
     * puti (hindi urgent) ang tahimik na page (lumang page na halos walang mapapadalhan) at ang hindi pinapadalhan. Kapag
     * hindi nabasa ang mga order ($selling null), orange pa rin gaya ng dati: mas mabuting maingay kaysa matago ang page
     * na nagbebenta.
     * @param array<string, true>|null $selling page key => true
     */
    private function setup(array $sending, ?array $selling): array
    {
        $out = [];
        foreach (app(ChatblastSetupCheck::class)->pages() as $p) {
            $key = $this->settings->pageKey($p['page']);
            $on = in_array($key, $sending, true);
            $quiet = $on && $selling !== null && !isset($selling[$key]);
            $out[] = self::line($p['page'], $on && !$quiet ? 'orange' : 'white',
                match (true) {
                    !$on => 'Not sending, setup incomplete: ',
                    $quiet => 'Quiet (no order in ' . self::ORDER_DAYS . ' days), setup incomplete: ',
                    default => 'Setup: ',
                } . $p['reason'],
                [['Page settings', '/chatblast/page-settings?q=' . urlencode($p['page'])]]);
        }

        return $out;
    }

    /**
     * Ang mga page na may order sa huling ORDER_DAYS araw (ts_date sa Manila, ngayon kasama): isang naka-group na query
     * (ang index ay ts_date, PAGE), at ang pangalan ay tinutugma sa PHP (pageKey), dahil may dobleng espasyo minsan ang PAGE.
     * @return array<string, true>|null page key => true; null kapag walang macro_output
     */
    private function selling(): ?array
    {
        if (!Schema::hasTable('macro_output')) return null;
        $page = DB::getQueryGrammar()->wrap('PAGE');
        $out = [];
        foreach (DB::table('macro_output')->where('ts_date', '>=', now('Asia/Manila')->subDays(self::ORDER_DAYS - 1)->toDateString())
            ->whereNotNull('PAGE')->selectRaw("$page AS page")->groupBy('PAGE')->pluck('page') as $name) {
            $out[$this->settings->pageKey((string) $name)] = true;
        }

        return $out;
    }
}

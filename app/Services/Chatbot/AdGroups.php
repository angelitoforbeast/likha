<?php

namespace App\Services\Chatbot;

use App\Models\BotcakeChat;
use App\Models\BotcakePsid;
use App\Models\ChatbotFlow;
use App\Models\PageSetting;
use App\Models\User;
use App\Services\ChatbotFields;
use App\Services\MetaAds\Connections;
use App\Services\MetaAds\Graph;
use App\Services\MetaAds\GraphException;
use App\Services\PageSettings;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Ang mga ad group ng isang chatbot page: ilang ad ID na iisa ang setup (item, presyo, Main Flow, flow, Seq …). Ang
 * value na hindi itinakda ng group ay ang page value (ang default). Nasa page_settings, provider "adgroup":
 * gN.name, gN.ads (mga ad ID, comma), at gN.<key> para sa bawat value. Ang N ay hindi inuulit sa page, kaya ang history
 * ng na-delete na group ay hindi nahahalo sa bago. System lang ang sumusulat (PageSettings::validate), mula rito.
 */
class AdGroups
{
    public const PROVIDER = 'adgroup';
    public const MAX_NAME = 100;
    public const MAX_ADS = 500;
    public const HISTORY_LIMIT = 100;
    public const RECENT_CHATS = 50;
    public const UNASSIGNED_DAYS = 30;
    public const UNASSIGNED_LIMIT = 50;
    public const UNASSIGNED_TTL = 120;
    /** Ang status ng mga ad (ads_manager_reports) ay naka-cache nang 5 minuto; "New" = nasa huling NEW_DAYS araw. */
    public const STATUS_TTL = 300;
    public const NEW_DAYS = 3;
    /** Run state ng customer (chatbot_customer_values): ang group nang pumasok siya sa sequence N. */
    public const SEQ_PIN = '__ad_group_seq_';
    /** Ang switch ng page: adgroup.mode = "ad" ay Ad-based; wala = Page-based (ang default). */
    public const MODE_KEY = 'mode';
    public const MODE_AD = 'ad';
    /**
     * Ang ID sa group ay ad, ad set o campaign. Ang ad set at campaign ng ad ng customer ay mula sa ad_catalog (o
     * ads_manager_reports); kapag wala, isang tawag sa Meta (LOOKUP_*), at ang nakita ay isinusulat sa ad_catalog.
     */
    public const PARENTS = 'chatbot-adgroups:parents:';
    public const PARENTS_TTL = 86400;
    public const LOOKUP_TIMEOUT = 3;
    public const LOOKUP_RETRY = 300;
    public const LOOKUP_NOT_FOUND = 3600;
    public const LOOKUPS_PER_MINUTE = 30;
    /** Tahimik: Off nang QUIET_DAYS araw (o walang report), walang mensahe, at hindi bagong dagdag o bagong ayos. */
    public const QUIET_DAYS = 7;
    public const KINDS = ['ad' => 'Ad', 'adset' => 'Ad set', 'campaign' => 'Campaign'];

    /**
     * Ang huling forCustomer / forSequence: ang tala para sa run log kapag ang lookup sa Meta ay pumalya o nilaktawan, at
     * unresolved = pumalya (subukan ulit sa susunod; hindi itinatala bilang page values ng sequence).
     */
    public ?string $lookupNote = null;
    public bool $unresolved = false;

    /**
     * Ang group ay nakapagtatakda ng bawat value ng page (bawat botfield ng /chatbot/pages, ang flow na pinili, at ang AI
     * ng page) maliban sa mga ito: ang pagkakakilanlan at mga switch ng page (On/Off, provider, ang Botcake ID at mga
     * token, ang Ad-based switch), ang hindi value (ang talaan ng kusang lipat, ang pekeng AI ng stress test, ang FLOW na
     * text, ang SHOP DETAILS na binubuo, ang AI ng buong site), at ang sariling name at ads ng group.
     */
    public const EXCLUDED = ['active', 'provider', 'gateway', 'page_id', 'token', self::MODE_KEY, 'auto_switch', 'mock_ai', 'mock_ai_latency_ms', 'flow', 'shop_details',
        'ai_local_concurrency', 'ai_breaker_failures', 'ai_breaker_seconds', 'ai_local_reasoning_effort', 'name', 'ads'];
    /** Ang AI ng page (ChatbotAiSettings::PAGE_FIELDS) na maitatakda rin ng group. */
    public const AI_KEYS = ['ai_provider', 'ai_fallback', 'ai_local_model', 'ai_local_timeout'];
    public const PAGE_ONLY = ' is the page\'s own setting (identity or a switch): it is set on the page only, not in a group.';

    public function __construct(private PageSettings $settings)
    {
    }

    /** Value ba ito na maitatakda ng group (anyo ng key ng botfield, at wala sa EXCLUDED). */
    public static function isValueKey(string $key): bool
    {
        return preg_match('/^[a-z0-9_]{1,100}$/', $key) === 1 && !in_array($key, self::EXCLUDED, true);
    }

    /**
     * Ang mga ad ID sa tina-type o pina-paste (comma, space o bagong linya ang pagitan), walang ulit.
     * @return array{ids: array<int, string>, bad: array<int, string>}
     */
    public static function parseAds(string $text): array
    {
        $ids = $bad = [];
        foreach (preg_split('/[\s,;]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) as $t) {
            if (preg_match('/^\d{1,64}$/', $t)) $ids[$t] = true;
            else $bad[$t] = true;
        }

        return ['ids' => array_map('strval', array_keys($ids)), 'bad' => array_map('strval', array_keys($bad))];
    }

    /** Ang mali ng ID na hindi numero (ang unang lima). */
    public static function notAnId(array $bad): string
    {
        return 'Not an ID (digits only): ' . implode(', ', array_slice($bad, 0, 5)) . '.';
    }

    /**
     * Ad, ad set o campaign ang ID ayon sa ad_catalog (isang indexed query bawat antas); ang hindi kilala ay "ad", gaya ng
     * dati. Para sa mensahe lang, hindi sa takbo.
     */
    public static function kindOf(string $id): string
    {
        try {
            foreach (['ad_set_id' => 'adset', 'campaign_id' => 'campaign'] as $col => $kind) {
                if (DB::table('ad_catalog')->where($col, $id)->exists()) return $kind;
            }
        } catch (QueryException) {
            // walang ad_catalog
        }

        return 'ad';
    }

    /**
     * Ang mga group ng page, mula sa naka-cache na settings ng page (walang dagdag na query sa takbo). Group lang ang may
     * pangalan.
     * @return array<int, array{id: int, name: string, ads: array<int, string>, values: array<string, string>, ids: array<string, int>}>
     */
    public function groups(string $page): array
    {
        $out = [];
        foreach ($this->settings->allWithIds($page, self::PROVIDER) as $key => $row) {
            if (!preg_match('/^g(\d+)\.(.+)$/', $key, $m)) continue;
            $g = &$out[(int) $m[1]];
            $g ??= ['id' => (int) $m[1], 'name' => '', 'ads' => [], 'values' => [], 'ids' => []];
            if ($m[2] === 'name') $g['name'] = $row['value'];
            elseif ($m[2] === 'ads') $g['ads'] = array_values(array_filter(explode(',', $row['value']), fn ($a) => $a !== ''));
            elseif (self::isValueKey($m[2])) [$g['values'][$m[2]], $g['ids'][$m[2]]] = [$row['value'], $row['id']];
            unset($g);
        }
        $out = array_filter($out, fn ($g) => $g['name'] !== '');
        ksort($out);

        return $out;
    }

    /**
     * Ad-based ang page (row na adgroup.mode = "ad"): ang mga group lang nito ang ginagamit sa takbo. Ang wala o ibang
     * value = Page-based (ang default): page values lagi, ang mga group ay naiiwan pero hindi binabasa.
     */
    public function adBased(string $page): bool
    {
        return $this->settings->get($page, self::PROVIDER, self::MODE_KEY) === self::MODE_AD;
    }

    /**
     * Ilipat ang page sa Ad-based (isang row) o pabalik sa Page-based (ang row ay nagiging history). Ang parehong mode ay
     * walang isinusulat.
     */
    public function setMode(string $page, bool $ad, ?int $userId = null): void
    {
        if ($ad) {
            $this->settings->set($page, self::PROVIDER, self::MODE_KEY, self::MODE_AD, false, $userId, true);
            return;
        }
        foreach ($this->settings->pageQuery($page)->where('provider', self::PROVIDER)->where('key', self::MODE_KEY)->where('active', true)->get() as $s) {
            $this->settings->deactivate($s, $userId);
        }
    }

    /** Ang mga group na ginagamit sa takbo: ang sa Ad-based na page lang. */
    public function activeGroups(string $page): array
    {
        return $this->adBased($page) ? $this->groups($page) : [];
    }

    /** Ang group ng bawat ID (ad, ad set o campaign) ng page: ID => numero ng group. */
    public function index(string $page): array
    {
        $out = [];
        foreach ($this->groups($page) as $id => $g) foreach ($g['ads'] as $a) $out[$a] ??= $id;

        return $out;
    }

    /**
     * Ang numero ng group ng page para sa ad na ito, o null: ang group na may ad mismo, saka ang may ad set nito, saka
     * ang may campaign nito (ang pinakatiyak ang nananaig, hindi ang ayos ng group). Walang tawag sa Meta.
     */
    public function groupForAd(string $page, ?string $adId): ?int
    {
        return $this->match($page, $adId, false)[0];
    }

    /**
     * @return array{0: ?int, 1: ?string, 2: bool} ang group, ang tala ng lookup, at kung pumalya ang lookup
     */
    private function match(string $page, ?string $ad, bool $lookup): array
    {
        if ($ad === null || !preg_match('/^\d{1,64}$/', $ad)) return [null, null, false];
        $index = $this->index($page);
        if (isset($index[$ad])) return [$index[$ad], null, false];
        if (!$index) return [null, null, false];
        $p = $this->parents($ad, $page, $lookup);
        foreach (['adset', 'campaign'] as $level) {
            if ($p[$level] !== null && isset($index[$p[$level]])) return [$index[$p[$level]], null, false];
        }

        return [null, $p['note'], $p['unresolved']];
    }

    /**
     * Ang ad set at campaign ng ad: naka-cache, saka ad_catalog, saka ads_manager_reports (isang indexed query bawat isa);
     * kapag hindi kilala at $lookup, ang Meta (lookup()).
     * @return array{adset: ?string, campaign: ?string, note: ?string, unresolved: bool}
     */
    private function parents(string $ad, string $page, bool $lookup): array
    {
        $none = ['adset' => null, 'campaign' => null, 'note' => null, 'unresolved' => false];
        if (is_array($p = Cache::get(self::PARENTS . $ad))) return $p + $none;
        if ($p = self::knownParents($ad)) {
            Cache::put(self::PARENTS . $ad, $p, self::PARENTS_TTL);
            return $p + $none;
        }

        return $lookup ? $this->lookup($ad, $page) + $none : $none;
    }

    /** Ang numerong ID lang (hanggang 64 na digit), o null. */
    private static function digits(mixed $v): ?string
    {
        $v = is_scalar($v) ? trim((string) $v) : '';

        return preg_match('/^\d{1,64}$/', $v) ? $v : null;
    }

    /** @return ?array{adset: ?string, campaign: ?string} */
    private static function knownParents(string $ad): ?array
    {
        foreach (['ad_catalog' => fn ($q) => $q, 'ads_manager_reports' => fn ($q) => $q->orderByDesc('day')->orderByDesc('id')] as $table => $order) {
            try {
                $r = $order(DB::table($table)->where('ad_id', $ad)->where(fn ($w) => $w->whereNotNull('ad_set_id')->orWhereNotNull('campaign_id')))->first(['ad_set_id', 'campaign_id']);
            } catch (QueryException) {
                continue; // wala ang table
            }
            $p = ['adset' => self::digits($r->ad_set_id ?? null), 'campaign' => self::digits($r->campaign_id ?? null)];
            if ($p['adset'] !== null || $p['campaign'] !== null) return $p;
        }

        return null;
    }

    /**
     * Isang tawag sa Meta para sa ad na hindi kilala: isa lang kada ad nang sabay (lock; ang iba ay naghihintay hanggang
     * LOOKUP_TIMEOUT segundo), hanggang LOOKUPS_PER_MINUTE kada page, ang "wala" ay tanda nang LOOKUP_NOT_FOUND segundo
     * at ang pumalya ay hindi inuulit nang LOOKUP_RETRY segundo. Walang ibinabato: ang mali ay page values lang.
     * @return array{adset?: ?string, campaign?: ?string, note?: ?string, unresolved?: bool}
     */
    private function lookup(string $ad, string $page): array
    {
        $none = 'chatbot-adgroups:lookup:none:' . $ad;
        $fail = 'chatbot-adgroups:lookup:fail:' . $ad;
        $failed = ['note' => 'Ad lookup for ' . $ad . ' failed in the last ' . (self::LOOKUP_RETRY / 60) . ' minutes: page values', 'unresolved' => true];
        try {
            if (Cache::has($none)) return [];
            if (Cache::has($fail)) return $failed;
            $lock = Cache::lock('chatbot-adgroups:lookup:' . $ad, self::LOOKUP_TIMEOUT + 5);
            if (!$lock->get()) {
                // Ibang customer ang nagtatanong na sa Meta: hintayin ang sagot, hanggang LOOKUP_TIMEOUT segundo.
                for ($i = 0; $i < self::LOOKUP_TIMEOUT * 10; $i++) {
                    Sleep::for(100)->milliseconds();
                    if (is_array($p = Cache::get(self::PARENTS . $ad))) return $p;
                    if (Cache::has($none)) return [];
                    if (Cache::has($fail)) return $failed;
                }

                return ['note' => 'Ad lookup for ' . $ad . ' still running: page values', 'unresolved' => true];
            }
            try {
                if (is_array($p = Cache::get(self::PARENTS . $ad))) return $p;
                $rate = 'chatbot-adgroups:lookup:rate:' . sha1($this->settings->pageKey($page));
                if (RateLimiter::tooManyAttempts($rate, self::LOOKUPS_PER_MINUTE)) {
                    return ['note' => 'Ad lookup for ' . $ad . ' skipped (' . self::LOOKUPS_PER_MINUTE . ' lookups this minute already): page values', 'unresolved' => true];
                }
                RateLimiter::hit($rate, 60);

                return $this->fetchParents($ad, $page, $none, $fail);
            } finally {
                $lock->release();
            }
        } catch (Throwable $e) {
            report(new \RuntimeException('Ad group lookup failed: ' . Graph::clean($e->getMessage())));

            return ['note' => 'Ad lookup for ' . $ad . ' failed: page values', 'unresolved' => true];
        }
    }

    /** Ang tawag mismo: GET /<ad>?fields=adset_id,campaign_id; ang mga ID lang ang kinukuha at iniimbak. */
    private function fetchParents(string $ad, string $page, string $none, string $fail): array
    {
        $failed = function (string $why) use ($ad, $fail) {
            Cache::put($fail, true, self::LOOKUP_RETRY);

            return ['note' => 'Ad lookup for ' . $ad . ' failed (' . $why . '): page values', 'unresolved' => true];
        };
        $notFound = function () use ($ad, $none) {
            Cache::put($none, true, self::LOOKUP_NOT_FOUND);

            return ['note' => 'Ad ' . $ad . ' not found in Meta: page values'];
        };
        // Walang Meta Ads connection (o lahat ay expired): hindi maitatanong hanggang may mag-Connect. Gaya ng "wala" ito
        // para sa sequence (page values ang itinatala, gaya ng dati), at hindi inuulit nang LOOKUP_RETRY segundo.
        $token = $this->metaToken($page);
        if ($token === null) {
            Cache::put($none, true, self::LOOKUP_RETRY);

            return ['note' => 'Ad lookup for ' . $ad . ' failed (no Meta Ads connection): page values'];
        }
        try {
            $json = app(Graph::class)->get($ad, ['fields' => 'id,adset_id,campaign_id'], $token, true, 1, self::LOOKUP_TIMEOUT);
        } catch (GraphException $e) {
            // Wala, o hindi abot ng connection (100 / 803 / permission): hindi na itatanong nang isang oras.
            if (in_array($e->graphCode, [10, 100, 803], true) || ($e->graphCode >= 200 && $e->graphCode < 300)) return $notFound();

            return $failed($e->tokenExpired() ? 'Meta token expired' : ($e->graphCode ? 'Meta error ' . $e->graphCode : 'no answer from Meta'));
        }
        $p = ['adset' => self::digits($json['adset_id'] ?? null), 'campaign' => self::digits($json['campaign_id'] ?? null)];
        if ((isset($json['id']) && (string) $json['id'] !== $ad) || ($p['adset'] === null && $p['campaign'] === null)) return $notFound();
        $this->storeParents($ad, $p);
        Cache::put(self::PARENTS . $ad, $p, self::PARENTS_TTL);

        return $p;
    }

    /**
     * Ang nakita ay isinusulat sa row ng ad sa ad_catalog (isang row kada ad, ID lang): pinupunan ng susunod na upload
     * o sync ang pangalan at mga petsa, at hindi nito pinapalitan ang ID na nandoon na.
     */
    private function storeParents(string $ad, array $p): void
    {
        try {
            $now = now();
            if (DB::table('ad_catalog')->insertOrIgnore(['ad_id' => $ad, 'ad_set_id' => $p['adset'], 'campaign_id' => $p['campaign'], 'created_at' => $now, 'updated_at' => $now])) return;
            foreach (['ad_set_id' => $p['adset'], 'campaign_id' => $p['campaign']] as $col => $v) {
                if ($v !== null) DB::table('ad_catalog')->where('ad_id', $ad)->whereNull($col)->update([$col => $v, 'updated_at' => $now]);
            }
        } catch (QueryException) {
            // wala ang ad_catalog: ang cache na lang
        }
    }

    /**
     * Ang token ng Meta Ads connection na gagamitin (gaya ng sync): ang may ad account ng page muna (ayon sa reports),
     * saka ang unang hindi expired. Hindi kailanman ipinapakita o itinatala.
     */
    private function metaToken(string $page): ?string
    {
        try {
            $connections = app(Connections::class);
            $all = array_filter($connections->all(), fn ($c) => ($c['status'] ?? 'ok') !== 'expired');
        } catch (QueryException) {
            return null;
        }
        $bare = fn ($id) => preg_replace('/^act_/', '', trim((string) $id));
        $mine = [];
        try {
            foreach (DB::table('ads_manager_reports')->where('page_name', $page)->whereNotNull('account_id')->distinct()->limit(20)->pluck('account_id') as $a) $mine[$bare($a)] = true;
        } catch (QueryException) {
            // walang reports o walang account_id: ang unang connection
        }
        $ours = fn (array $c) => (bool) array_filter($c['ad_accounts'] ?? [], fn ($x) => isset($mine[$bare($x['id'] ?? '')]));
        uasort($all, fn ($a, $b) => $ours($b) <=> $ours($a));
        foreach ($all as $c) if (($t = $connections->token($c)) !== null && $t !== '') return $t;

        return null;
    }

    /**
     * Ang pinakabagong ad ng customer sa page: ang pinakabagong chat niya (sa huling RECENT_CHATS) na may ad_id (ang
     * referral ng Forbeast o ang ad ng Botcake webhook, may oras ang bawat isa); kung wala, ang ad_id ng botcake_psids ng
     * page (ang kasalukuyang ad ng customer ayon sa Botcake, walang sariling oras).
     */
    public function latestAd(int|string $pageId, int|string $psid, string $page): ?string
    {
        $chats = BotcakeChat::active()->where('page_id', (string) $pageId)->where('psid', (string) $psid)
            ->orderByDesc('id')->limit(self::RECENT_CHATS)->get(['id', 'payload']);
        foreach ($chats as $c) {
            $ad = trim((string) ($c->payload['ad_id'] ?? ''));
            if (preg_match('/^\d{1,64}$/', $ad)) return $ad;
        }
        $key = $this->settings->pageKey($page);
        $ad = BotcakePsid::where('psid', (string) $psid)->whereNotNull('ad_id')->where('ad_id', '<>', '')->get(['page', 'ad_id'])
            ->first(fn ($r) => $this->settings->pageKey((string) $r->page) === $key)?->ad_id;

        return $ad !== null && preg_match('/^\d{1,64}$/', (string) $ad) ? (string) $ad : null;
    }

    /**
     * Ang group ng customer ayon sa pinakabagong ad niya, o null (page values). Ang Page-based na page o ang walang group:
     * walang query.
     */
    public function forCustomer(int|string $pageId, int|string $psid, string $page): ?int
    {
        [$this->lookupNote, $this->unresolved] = [null, false];
        if (!$this->activeGroups($page)) return null;
        [$group, $this->lookupNote, $this->unresolved] = $this->match($page, $this->latestAd($pageId, $psid, $page), true);

        return $group;
    }

    /**
     * Ang group ng customer sa isang sequence: ang naitala nang pumasok siya (pin), para hindi lumipat sa gitna. Ang walang
     * pin (pumasok bago nagkaroon ng group ang page) ay kinukuha ngayon at itinatala; kapag pumalya ang lookup sa Meta,
     * page values sa step na ito at walang pin (kinukuha ulit sa susunod na step). Ang na-delete na group = page values.
     * Ang Page-based na page: page values, at ang pin ay hindi ginagalaw (bumabalik kapag Ad-based ulit).
     */
    public function forSequence(int $sequenceId, CustomerFields $fields, string $page): ?int
    {
        [$this->lookupNote, $this->unresolved] = [null, false];
        $groups = $this->activeGroups($page);
        if (!$groups) return null;
        $pin = $fields->get(self::SEQ_PIN . $sequenceId);
        if ($pin === null) {
            [$group, $this->lookupNote, $this->unresolved] = $this->match($page, $this->latestAd($fields->pageId, $fields->psid, $page), true);
            if (!$this->unresolved) $this->pin($sequenceId, $fields, $page, $group);

            return $group;
        }

        return isset($groups[(int) $pin]) ? (int) $pin : null;
    }

    /**
     * Itala ang group ng customer sa pagpasok sa sequence (0 = page values). Walang isinusulat kapag walang ginagamit na
     * group ang page (Page-based, o walang group).
     */
    public function pin(int $sequenceId, CustomerFields $fields, string $page, ?int $group): void
    {
        if ($group === null && !$this->activeGroups($page)) return;
        $fields->set(self::SEQ_PIN . $sequenceId, (string) ($group ?? 0));
    }

    /**
     * Bago (id null) o palit ng isang group. $values: key => value; ang blangko ay bumabalik sa page value; ang key na
     * wala sa $values ay hindi ginagalaw. Lahat o wala: ang mali (pangalan, ad ID, key, value, ad na nasa ibang group ng
     * page) ay walang naisusulat.
     * @param array<int, string> $ads
     * @param array<string, ?string> $values
     * @return array{ok: bool, id?: int, error?: string}
     */
    public function save(string $page, ?int $id, string $name, array $ads, array $values, ?int $userId = null): array
    {
        return $this->change($page, $id, fn () => [$name, $ads, $values], $userId);
    }

    /**
     * Isa-isa kada page (lock): ang group ay binabasa sa loob ng lock mula sa database (binubura muna ang memo at cache
     * ng page), $build ang bumubuo ng pangalan, ads at values mula rito, saka sinusuri (pati ang duplicate) at isinusulat.
     * @param callable(?array): array{0: string, 1: array, 2: array} $build
     * @return array{ok: bool, id?: int, error?: string}
     */
    private function change(string $page, ?int $id, callable $build, ?int $userId): array
    {
        return Cache::lock('chatbot-adgroups:' . sha1($this->settings->pageKey($page)), 10)->block(5, function () use ($page, $id, $build, $userId) {
            PageSettings::forget($page);
            $groups = $this->groups($page);
            if ($id !== null && !isset($groups[$id])) return ['ok' => false, 'error' => 'This group was deleted.'];
            [$name, $ads, $values] = $build($groups[$id] ?? null);
            if (($error = $this->invalid($name, $ads, $values, $page, $groups[$id]['values'] ?? [])) !== null) return ['ok' => false, 'error' => $error];
            // Iisang group lang ang bawat ID, anuman ang antas (ad, ad set, campaign). Ang ad sa isang group at ang ad set
            // nito sa iba ay puwede: ang pinakatiyak ang nananaig sa takbo.
            foreach ($ads as $ad) {
                foreach ($groups as $other) {
                    if ($other['id'] !== $id && in_array($ad, $other['ads'], true)) {
                        return ['ok' => false, 'error' => self::KINDS[self::kindOf($ad)] . ' ' . $ad . ' is already in the group "' . $other['name'] . '".', 'group' => $other['id']];
                    }
                }
            }
            $id ??= $this->nextId($page);
            DB::transaction(function () use ($page, $id, $name, $ads, $values, $userId) {
                $this->put($page, $id, 'name', $name, $userId);
                $this->put($page, $id, 'ads', implode(',', $ads), $userId);
                foreach ($values as $key => $value) $this->put($page, $id, (string) $key, trim((string) $value), $userId);
            });

            return ['ok' => true, 'id' => $id];
        });
    }

    /** Ang mali sa pangalan, ads o values, o null; ang pangalan at ads ay inaayos (iisang espasyo, walang ulit, ayos). */
    private function invalid(string &$name, array &$ads, array $values, string $page, array $own): ?string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name));
        if ($name === '') return 'Give the group a name.';
        if (mb_strlen($name) > self::MAX_NAME) return 'Name: ' . self::MAX_NAME . ' characters at most.';
        $parsed = self::parseAds(implode(',', array_map('strval', $ads)));
        if ($parsed['bad']) return self::notAnId($parsed['bad']);
        $ads = $parsed['ids'];
        sort($ads, SORT_STRING);
        if (count($ads) > self::MAX_ADS) return 'At most ' . self::MAX_ADS . ' ads in a group.';
        $labels = null;
        foreach ($values as $key => $value) {
            $key = (string) $key;
            $value = trim((string) $value);
            if (!self::isValueKey($key)) return $key . self::PAGE_ONLY;
            $labels ??= app(ChatbotFields::class)->labels();
            $ai = in_array($key, self::AI_KEYS, true);
            if (!$ai && $key !== 'flow_id' && !isset($labels[$key]) && !preg_match('/^seq(_img)?_([1-9]|[1-9]\d)$/', $key)) return $key . ' is not a value of the page.';
            if ($value === '') continue;
            // Ang flow na wala na ay hindi tinatanggap (at kapag na-delete pagkatapos, ang flow ng page ang tumatakbo).
            if ($key === 'flow_id' && (!ctype_digit($value) || !ChatbotFlow::whereKey((int) $value)->exists())) return 'Pick a flow from the list.';
            if ($ai && ($err = self::aiError($key, $value))) return $err;
            if (!$ai && ($err = ChatbotFields::valueError($key, $value))) return $key . ': ' . $err;
        }
        $aiKey = array_key_first(array_intersect_key($values, array_flip(self::AI_KEYS)));
        if ($aiKey !== null && $this->localWithoutModel($page, $own, $values)) return ChatbotAiSettings::LABELS[$aiKey] . ': ' . ChatbotAiSettings::NO_MODEL;

        return null;
    }

    /**
     * Gaya ng sa page (ChatbotAiSettings): ang AI ng group (ang bago, ang naka-save, saka ang sa page) ay gumagamit ng Local
     * pero walang Local model kahit saan (group, page, site, config).
     */
    private function localWithoutModel(string $page, array $own, array $values): bool
    {
        $ai = $this->settings->all($page, ChatbotAiChain::PROVIDER);
        foreach (self::AI_KEYS as $key) {
            $v = trim((string) (array_key_exists($key, $values) ? $values[$key] : ($own[$key] ?? '')));
            if ($v !== '') $ai[$key] = $v;
        }
        $site = ChatbotAiChain::site();
        $provider = ChatbotAiChain::providerOf($ai, $site);
        $fallback = (string) ($ai['ai_fallback'] ?? $site['fallback'] ?? '');
        if ($provider !== ChatbotAiProviders::LOCAL && !in_array(ChatbotAiProviders::LOCAL, explode(',', $fallback), true)) return false;
        $model = $ai['ai_local_model'] ?? $site['local_model'] ?? (string) ChatbotAiProviders::option(ChatbotAiProviders::LOCAL, 'model', '');

        return trim((string) $model) === '';
    }

    /** Ang mali sa AI ng group: gaya ng sa page (ChatbotAiSettings::clean), pero ang "default" ay ang blangko (ang sa page). */
    private static function aiError(string $key, string $value): ?string
    {
        $field = substr($key, 3);
        $label = ChatbotAiSettings::LABELS[$key];
        [$err, $clean] = ChatbotAiSettings::clean([$field => $value], false);
        if ($err !== null) return str_starts_with($err, $label) ? $err : $label . ': ' . $err;
        if (($clean[$field] ?? null) === ChatbotAiSettings::DEFAULT) return $label . ': leave it empty to use the page\'s.';
        if (in_array($key, ['ai_provider', 'ai_fallback'], true) && $value === ChatbotAiProviders::LOCAL && ChatbotAiProviders::notReady(ChatbotAiProviders::LOCAL) !== null) {
            return $label . ': ' . ChatbotAiSettings::NOT_SET_UP;
        }

        return null;
    }

    /**
     * Ang mga tsinek na ad mula sa "Ads not in a group yet": idinadagdag sa group $id, o sa bagong group na $name. Ang
     * ads ng group ay binabasa sa loob ng lock, kaya hindi nawawala ang sabay na idinagdag ng iba.
     * @return array{ok: bool, id?: int, error?: string}
     */
    public function addAds(string $page, ?int $id, ?string $name, array $ads, ?int $userId = null): array
    {
        if ($id === null) return $this->save($page, null, (string) $name, $ads, [], $userId);
        $already = [];
        $r = $this->change($page, $id, function (array $g) use ($ads, &$already) {
            $already = array_values(array_intersect(array_map('strval', $ads), $g['ads']));

            return [$g['name'], array_merge($g['ads'], $ads), []];
        }, $userId);

        // Ang naipasa nang dalawang beses (o nasa group na): isang beses lang nakatala, at sinasabi.
        return $r['ok'] && $already ? $r + ['note' => 'Already in this group: ' . implode(', ', array_slice($already, 0, 5)) . (count($already) > 5 ? ' …' : '') . '.'] : $r;
    }

    /**
     * Isang value ng group (ang cell sa row ng page): sa loob ng parehong lock at pagsuri ng save; blangko = page value.
     * @return array{ok: bool, id?: int, error?: string}
     */
    public function setValue(string $page, int $id, string $key, string $value, ?int $userId = null): array
    {
        return $this->change($page, $id, fn (array $g) => [$g['name'], $g['ads'], [$key => $value]], $userId);
    }

    /**
     * Alisin ang isang ad ID sa group (ang lumang ad list ay history); ang ad ay bumabalik sa "Ads not in a group yet".
     * @return array{ok: bool, id?: int, error?: string}
     */
    public function removeAd(string $page, int $id, string $ad, ?int $userId = null): array
    {
        $missing = false;
        $r = $this->change($page, $id, function (array $g) use ($ad, &$missing) {
            $missing = !in_array($ad, $g['ads'], true);

            return [$g['name'], array_values(array_diff($g['ads'], [$ad])), []];
        }, $userId);

        return $missing && $r['ok'] ? ['ok' => false, 'error' => 'Ad ' . $ad . ' is not in this group.'] : $r;
    }

    /** Alisin ang group: lahat ng row nito ay nagiging history; ang mga ad nito ay bumabalik sa page values. */
    public function delete(string $page, int $id, ?int $userId = null): bool
    {
        $rows = $this->rows($page, $id)->where('active', true)->get();
        DB::transaction(fn () => $rows->each(fn ($s) => $this->settings->deactivate($s, $userId)));

        return $rows->isNotEmpty();
    }

    /**
     * Ang history ng isang group (pinakabago muna): bawat value na naisulat, active man o napalitan.
     * @return array<int, array{id: int, key: string, value: string, active: bool, at: ?string, replaced_at: ?string, by: ?string}>
     */
    public function history(string $page, int $id): array
    {
        $rows = $this->rows($page, $id)->orderByDesc('id')->limit(self::HISTORY_LIMIT)->get();
        $names = User::whereIn('id', $rows->pluck('created_by')->filter()->unique())->pluck('name', 'id');

        return $rows->map(fn (PageSetting $s) => [
            'id' => (int) $s->id, 'key' => substr((string) $s->key, strlen('g' . $id . '.')), 'value' => (string) $s->plainValue(), 'active' => (bool) $s->active,
            'at' => $s->created_at?->timezone('Asia/Manila')->format('Y-m-d H:i'), 'replaced_at' => $s->replaced_at?->timezone('Asia/Manila')->format('Y-m-d H:i'),
            'by' => $s->created_by ? ($names[$s->created_by] ?? null) : null,
        ])->values()->all();
    }

    /**
     * Ibalik ang lumang value ng isang group (row ng history). Dumadaan sa parehong pagsuri ng save (hal. ang ad na
     * nasa ibang group na). Ang group na na-delete ay hindi na binubuhay.
     * @return array{ok: bool, id?: int, error?: string}
     */
    public function restore(string $page, int $settingId, ?int $userId = null): array
    {
        $s = $this->settings->pageQuery($page)->where('provider', self::PROVIDER)->whereKey($settingId)->first();
        if (!$s || !preg_match('/^g(\d+)\.(.+)$/', (string) $s->key, $m)) return ['ok' => false, 'error' => 'That version is not in this page\'s groups.'];
        [$key, $value] = [$m[2], (string) $s->plainValue()];

        return $this->change($page, (int) $m[1], fn (array $g) => match ($key) {
            'name' => [$value, $g['ads'], []],
            'ads' => [$g['name'], explode(',', $value), []],
            default => [$g['name'], $g['ads'], [$key => $value]],
        }, $userId);
    }

    /**
     * Ang lahat ng kailangan ng row ng Ad-based na page: ang mga group na ang bawat ID ay may uri (Ad, Ad set, Campaign o
     * hindi kilala), pangalan, status, ilang customer at huling oras (ang ad set at campaign: ang lahat ng ad sa ilalim
     * nito), quiet (tahimik: tingnan ang quiet()), hidden ng group (lahat ng ID ay tahimik at lampas QUIET_DAYS na ang
     * group), ang huling araw ng report (as_of), at ang "Ads not in a group yet": ang mga ad na nakita sa page sa huling
     * UNASSIGNED_DAYS araw (ang ad_id ng mga chat, at ang ad_id ng botcake_psids ng page) na wala sa group, mismo man o
     * sa ad set o campaign nito; ang hindi tahimik muna, saka ang bago, saka ang On, saka ang pinakamaraming customer,
     * hanggang $limit; $q = bahagi ng ad ID o ng pangalan. new_unassigned = ilang bago ang wala pa sa group (hindi ayon
     * sa $q). Ang pagtatago ay sa screen lang: walang isinusulat, at ang takbo ay hindi nagbabago. Walang tawag sa Meta.
     * @return array{groups: array<int, array<string, mixed>>, unassigned: array<int, array<string, mixed>>, unassigned_total: int, new_unassigned: int, as_of: ?string, hidden: array{groups: int, ids: int, unassigned: int}}
     */
    public function overview(string $page, string $q = '', int $limit = self::UNASSIGNED_LIMIT): array
    {
        $seen = $this->seenCached($page);
        $groups = $this->groups($page);
        $index = $this->index($page);
        $parents = $this->parentsMany(array_values(array_unique(array_merge(array_map('strval', array_keys($seen)), array_map('strval', array_keys($index))))));
        $levels = $this->levels(array_values(array_diff(array_map('strval', array_keys($index)), array_keys($parents))));
        $rest = $this->uncovered($seen, $index, $parents);
        // Ang mga ad sa ilalim ng bawat ad set o campaign ng group: ayon sa ad_catalog, at ang mga nakitang ad na doon kabilang.
        $under = $levels['children'];
        foreach ($parents as $ad => $p) foreach ($p as $parent) if ($parent !== null && isset($levels['kind'][$parent])) $under[$parent][$ad] = true;
        $all = array_values(array_unique(array_merge(array_map('strval', array_keys($index)), array_map('strval', array_keys($rest)),
            ...array_values(array_map(fn ($ads) => array_map('strval', array_keys($ads)), $under)) ?: [[]])));
        $status = $this->statuses($all);
        $names = $this->adNames(array_values(array_diff($all, array_keys($levels['kind']))));
        $row = fn (string $ad) => $this->adRow($ad, $names[$ad] ?? null, $seen[$ad] ?? null, $status['ads'][$ad] ?? null);
        $times = $this->times($page);
        $cut = now()->subDays(self::QUIET_DAYS);

        $outGroups = [];
        $hidden = ['groups' => 0, 'ids' => 0, 'unassigned' => 0];
        foreach ($groups as $id => $g) {
            $rows = [];
            foreach ($g['ads'] as $x) {
                $kind = $levels['kind'][$x] ?? (isset($status['ads'][$x]) || isset($seen[$x]) || isset($parents[$x]) ? 'ad' : 'unknown');
                $r = $kind === 'adset' || $kind === 'campaign'
                    ? $this->rollup($x, $levels['names'][$x] ?? null, array_map('strval', array_keys($under[$x] ?? [])), $seen, $status)
                    : $row($x);
                $touched = max($times['added'][$id][$x] ?? null, $times['edited'][$id] ?? null);
                $rows[] = $r + ['kind' => $kind, 'quiet' => $this->quiet($r, $touched)];
            }
            $isHidden = $rows && ($times['created'][$id] ?? now())->lt($cut) && !array_filter($rows, fn ($r) => !$r['quiet']);
            if ($isHidden) $hidden['groups']++;
            else $hidden['ids'] += count(array_filter($rows, fn ($r) => $r['quiet']));
            $outGroups[$id] = $g + ['rows' => $rows, 'all_off' => $rows && !array_filter($rows, fn ($a) => $a['status'] !== 'off'), 'hidden' => $isHidden];
        }
        $q = mb_strtolower(trim($q));
        $out = [];
        $new = 0;
        foreach (array_keys($rest) as $ad) {
            $r = $row((string) $ad);
            $r += ['kind' => 'ad', 'quiet' => $this->quiet($r, null)];
            if ($r['new']) $new++;
            if ($q !== '' && !str_contains($r['ad_id'], $q) && !str_contains(mb_strtolower((string) $r['name']), $q)) continue;
            if ($r['quiet']) $hidden['unassigned']++;
            $out[] = $r;
        }
        usort($out, fn ($a, $b) => [!$b['quiet'], $b['new'], $b['status'] === 'on', $b['customers'], (string) $b['last']] <=> [!$a['quiet'], $a['new'], $a['status'] === 'on', $a['customers'], (string) $a['last']]);

        return ['groups' => $outGroups, 'unassigned' => array_slice($out, 0, $limit), 'unassigned_total' => count($out), 'new_unassigned' => $new, 'as_of' => $status['as_of'], 'hidden' => $hidden];
    }

    /** Ilang bagong ad (New) ang wala pa sa group ng page (mismo, o sa ad set o campaign nito): ang badge ng nakasarang Ad-based na row. */
    public function newUnassigned(string $page): int
    {
        $seen = $this->seenCached($page);
        $rest = $this->uncovered($seen, $this->index($page), $this->parentsMany(array_map('strval', array_keys($seen))));
        $status = $this->statuses(array_map('strval', array_keys($rest)));

        return count(array_filter(array_keys($rest), fn ($ad) => $this->adRow((string) $ad, null, $rest[$ad], $status['ads'][(string) $ad] ?? null)['new']));
    }

    /** Ang mga nakitang ad na wala sa group: hindi ang ad mismo, hindi ang ad set nito, hindi ang campaign nito. */
    private function uncovered(array $seen, array $index, array $parents): array
    {
        return array_filter($seen, function ($v, $ad) use ($index, $parents) {
            if (isset($index[(string) $ad])) return false;
            foreach ($parents[(string) $ad] ?? [] as $p) if ($p !== null && isset($index[$p])) return false;

            return true;
        }, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Tahimik ang ID kapag (a) Off nang QUIET_DAYS araw o higit (off_days gaya ng sa screen) o walang report data, (b)
     * walang customer na nag-message nito (o sa ilalim nito) sa huling QUIET_DAYS araw, at (c) ang pagdagdag nito o ang
     * huling ayos ng values ng group ($touched; null = wala, gaya ng ad na wala sa group) ay lampas QUIET_DAYS araw na.
     */
    private function quiet(array $r, ?Carbon $touched): bool
    {
        $cut = now()->subDays(self::QUIET_DAYS);
        $off = $r['status'] === 'none' || ($r['status'] === 'off' && (int) $r['off_days'] >= self::QUIET_DAYS);

        return $off && ($r['last'] === null || Carbon::parse($r['last'])->lt($cut)) && ($touched === null || $touched->lt($cut));
    }

    /**
     * Ang ad set o campaign bilang isang row: On kapag may ad na On sa ilalim; kung wala, Off kapag may Off, mula sa
     * pinakahuling off_since ng mga ad; ang customers ay ang iba't ibang customer ng mga ad sa ilalim, ang last ang
     * pinakahuli. Ang walang kilalang ad sa ilalim ay "none".
     */
    private function rollup(string $id, ?string $name, array $ads, array $seen, array $status): array
    {
        $st = array_values(array_filter(array_map(fn ($a) => $status['ads'][$a] ?? null, $ads)));
        $on = (bool) array_filter($st, fn ($s) => $s['status'] === 'on');
        $since = $on ? null : (collect($st)->where('status', 'off')->max('off_since'));
        $psids = [];
        $last = null;
        foreach ($ads as $a) {
            foreach ($seen[$a]['psids'] ?? [] as $p) $psids[$p] = true;
            if (($seen[$a]['last'] ?? null) !== null && ($last === null || $seen[$a]['last'] > $last)) $last = $seen[$a]['last'];
        }

        return ['ad_id' => $id, 'name' => $name !== null && $name !== '' ? $name : null, 'status' => $on ? 'on' : ($since !== null ? 'off' : 'none'), 'off_since' => $since,
            'off_days' => $since === null || $status['as_of'] === null ? null : (int) Carbon::parse($since)->diffInDays(Carbon::parse($status['as_of'])) + 1,
            'new' => false, 'customers' => count($psids), 'last' => $last !== null ? Carbon::parse($last)->format('Y-m-d H:i') : null, 'ads_under' => count($ads)];
    }

    /**
     * Ang ad set at campaign ng mga ad (para sa "Ads not in a group yet" at ang pag-uuri): ad_catalog, saka
     * ads_manager_reports para sa wala roon. Mga indexed na "ad_id IN (...)", 500 bawat isa; naka-cache nang STATUS_TTL.
     * @param array<int, string> $ads
     * @return array<string, array{0: ?string, 1: ?string}> ad => [ad set, campaign]
     */
    private function parentsMany(array $ads): array
    {
        $ads = array_values(array_filter($ads, fn ($a) => preg_match('/^\d{1,64}$/', $a)));
        sort($ads, SORT_STRING);
        if (!$ads) return [];

        return Cache::remember('chatbot-adgroups:parents-many:' . sha1(implode(',', $ads)), self::STATUS_TTL, function () use ($ads) {
            $out = [];
            foreach (['ad_catalog', 'ads_manager_reports'] as $table) {
                foreach (array_chunk(array_values(array_diff($ads, array_keys($out))), 500) as $chunk) {
                    try {
                        $rows = DB::table($table)->whereIn('ad_id', $chunk)->where(fn ($w) => $w->whereNotNull('ad_set_id')->orWhereNotNull('campaign_id'))
                            ->groupBy('ad_id')->selectRaw('ad_id, MAX(ad_set_id) AS s, MAX(campaign_id) AS c')->get();
                    } catch (QueryException) {
                        continue 2;
                    }
                    foreach ($rows as $r) {
                        $p = [self::digits($r->s), self::digits($r->c)];
                        if ($p[0] !== null || $p[1] !== null) $out[(string) $r->ad_id] = $p;
                    }
                }
            }

            return $out;
        });
    }

    /**
     * Ang mga ID ng group na ad set o campaign (ayon sa ad_catalog: indexed na ad_set_id at campaign_id): ang uri, ang
     * pangalan, at ang mga ad sa ilalim. Naka-cache nang STATUS_TTL.
     * @param array<int, string> $ids
     * @return array{kind: array<string, string>, names: array<string, string>, children: array<string, array<string, true>>}
     */
    private function levels(array $ids): array
    {
        $ids = array_values(array_filter($ids, fn ($a) => preg_match('/^\d{1,64}$/', $a)));
        sort($ids, SORT_STRING);
        $out = ['kind' => [], 'names' => [], 'children' => []];
        if (!$ids) return $out;

        return Cache::remember('chatbot-adgroups:levels:' . sha1(implode(',', $ids)), self::STATUS_TTL, function () use ($ids, $out) {
            foreach (array_chunk($ids, 500) as $chunk) {
                foreach (['ad_set_id' => ['adset', 'ad_set_name'], 'campaign_id' => ['campaign', 'campaign_name']] as $col => [$kind, $nameCol]) {
                    try {
                        $rows = DB::table('ad_catalog')->whereIn($col, $chunk)->get(['ad_id', $col, $nameCol]);
                    } catch (QueryException) {
                        return $out;
                    }
                    foreach ($rows as $r) {
                        $id = (string) $r->{$col};
                        $out['kind'][$id] = $kind;
                        if (trim((string) $r->{$nameCol}) !== '') $out['names'][$id] ??= (string) $r->{$nameCol};
                        if (($ad = self::digits($r->ad_id)) !== null) $out['children'][$id][$ad] = true;
                    }
                }
            }

            return $out;
        });
    }

    /**
     * Mula sa history ng page_settings (dalawang query): kailan nagawa ang bawat group, kailan huling inayos ang values
     * nito, at kailan idinagdag ang bawat ID (ang pinakalumang sunod-sunod na ad list ng group na may ID na iyon).
     * @return array{created: array<int, Carbon>, edited: array<int, Carbon>, added: array<int, array<string, Carbon>>}
     */
    private function times(string $page): array
    {
        $out = ['created' => [], 'edited' => [], 'added' => []];
        $at = fn ($v) => $v ? Carbon::parse($v) : null;
        $rows = $this->settings->pageQuery($page)->where('provider', self::PROVIDER)->where('key', 'like', 'g%.%')->toBase()->get(['key', 'active', 'created_at', 'replaced_at']);
        foreach ($rows as $s) {
            if (!preg_match('/^g(\d+)\.(.+)$/', (string) $s->key, $m)) continue;
            $id = (int) $m[1];
            $c = $at($s->created_at);
            if ($c && (!isset($out['created'][$id]) || $c->lt($out['created'][$id]))) $out['created'][$id] = $c;
            if (!self::isValueKey($m[2])) continue;
            foreach ([$c, $at($s->replaced_at)] as $t) if ($t && (!isset($out['edited'][$id]) || $t->gt($out['edited'][$id]))) $out['edited'][$id] = $t;
        }
        $lists = $this->settings->pageQuery($page)->where('provider', self::PROVIDER)->where('key', 'like', 'g%.ads')->orderBy('id')->get(['id', 'key', 'value', 'is_secret', 'created_at']);
        foreach ($lists as $s) {
            if (!preg_match('/^g(\d+)\.ads$/', (string) $s->key, $m)) continue;
            $id = (int) $m[1];
            $now = array_flip(array_filter(explode(',', (string) $s->plainValue()), fn ($a) => $a !== ''));
            $prev = $out['added'][$id] ?? [];
            $out['added'][$id] = [];
            foreach (array_keys($now) as $ad) $out['added'][$id][(string) $ad] = $prev[(string) $ad] ?? ($s->created_at ?? now());
        }

        return $out;
    }

    /**
     * Isang ad para sa screen. new: ang unang araw nito sa reports ay nasa huling NEW_DAYS araw (kasama ngayon); kapag
     * wala sa reports, ang unang customer nito (sa huling UNASSIGNED_DAYS araw) ay nasa huling NEW_DAYS araw.
     * @param ?array{customers: int, last: ?string, first?: ?string} $seen
     * @param ?array{status: string, off_since: ?string, off_days: ?int, first_day: string} $st
     */
    private function adRow(string $ad, ?string $name, ?array $seen, ?array $st): array
    {
        $first = $seen['first'] ?? null;
        $new = $st !== null
            ? $st['first_day'] >= now()->subDays(self::NEW_DAYS - 1)->toDateString()
            : ($first !== null && \Carbon\Carbon::parse($first)->gte(now()->subDays(self::NEW_DAYS)));

        return ['ad_id' => $ad, 'name' => $name !== '' ? $name : null, 'status' => $st['status'] ?? 'none', 'off_since' => $st['off_since'] ?? null,
            'off_days' => $st['off_days'] ?? null, 'new' => $new, 'customers' => (int) ($seen['customers'] ?? 0),
            'last' => !empty($seen['last']) ? \Carbon\Carbon::parse($seen['last'])->format('Y-m-d H:i') : null];
    }

    /**
     * Ang status ng mga ad mula sa ads_manager_reports (walang tawag sa Meta). Dalawang grouped query, parehong
     * "ad_id IN (...)" (index ads_report_adid_idx): (1) kada ad ang unang at huling araw, ang huling araw na may gastos at
     * ang unang araw na hindi parehong active ang campaign at ad set; (2) ang mga row ng bawat ad sa huling araw nito.
     * on: may row ang ad sa as_of, at sa araw na iyon active ang campaign at ad set (gaya ng Ads Manager report: LIKE
     * 'active%'), o may gastos.
     * off: off_since = ang araw pagkatapos ng huling gastos, o ang unang hindi active na araw kapag hindi kailanman
     * gumastos; off_days = as_of − off_since + 1. as_of = ang pinakahuling araw ng report ng mga ad na ito. Ang ad na wala
     * sa reports ay wala sa "ads" (No report data). Naka-cache nang STATUS_TTL segundo.
     * @param array<int, string> $ads
     * @return array{as_of: ?string, ads: array<string, array{status: string, off_since: ?string, off_days: ?int, first_day: string}>}
     */
    public function statuses(array $ads): array
    {
        $ads = array_values(array_unique(array_filter(array_map('strval', $ads), fn ($a) => preg_match('/^\d{1,64}$/', $a))));
        sort($ads, SORT_STRING);
        if (!$ads || !\Illuminate\Support\Facades\Schema::hasTable('ads_manager_reports')) return ['as_of' => null, 'ads' => []];

        return Cache::remember('chatbot-adgroups:status:' . sha1(implode(',', $ads)), self::STATUS_TTL, function () use ($ads) {
            $active = "(LOWER(TRIM(COALESCE(campaign_delivery, ''))) LIKE 'active%' AND LOWER(TRIM(COALESCE(ad_set_delivery, ''))) LIKE 'active%')";
            $days = DB::table('ads_manager_reports')->whereIn('ad_id', $ads)->whereNotNull('day')->groupBy('ad_id')
                ->selectRaw("ad_id, MIN(day) AS first_day, MAX(day) AS last_day, MAX(CASE WHEN amount_spent_php > 0 THEN day END) AS last_spend, MIN(CASE WHEN NOT $active THEN day END) AS first_off")
                ->get()->keyBy(fn ($r) => (string) $r->ad_id);
            if ($days->isEmpty()) return ['as_of' => null, 'ads' => []];
            $latest = DB::table('ads_manager_reports as a')
                ->joinSub(DB::table('ads_manager_reports')->whereIn('ad_id', $ads)->whereNotNull('day')->groupBy('ad_id')->selectRaw('ad_id, MAX(day) AS d'), 'm', fn ($j) => $j->on('a.ad_id', '=', 'm.ad_id')->on('a.day', '=', 'm.d'))
                ->groupBy('a.ad_id')
                ->selectRaw('a.ad_id, SUM(COALESCE(a.amount_spent_php, 0)) AS spend, MAX(CASE WHEN ' . str_replace(['campaign_delivery', 'ad_set_delivery'], ['a.campaign_delivery', 'a.ad_set_delivery'], $active) . ' THEN 1 ELSE 0 END) AS on_now')
                ->get()->keyBy(fn ($r) => (string) $r->ad_id);
            $day = fn ($v) => $v === null ? null : substr((string) $v, 0, 10);
            $asOf = $days->map(fn ($r) => $day($r->last_day))->max();
            $out = [];
            foreach ($days as $ad => $r) {
                $l = $latest[$ad] ?? null;
                // Ang ad na wala nang row sa huling araw ng report (as_of) ay Off: ang report ay may row lang sa mga araw na
                // may delivery, kaya ang na-pause na ad ay basta nawawala, at ang huling row nito ay madalas may gastos pa.
                $current = $day($r->last_day) === $asOf;
                $on = $current && $l && ((int) $l->on_now === 1 || (float) $l->spend > 0);
                $since = $on ? null : ($r->last_spend !== null ? \Carbon\Carbon::parse($day($r->last_spend))->addDay()->toDateString()
                    : ($day($r->first_off) ?? \Carbon\Carbon::parse($day($r->last_day))->addDay()->toDateString()));
                $out[(string) $ad] = ['status' => $on ? 'on' : 'off', 'off_since' => $since,
                    'off_days' => $since === null ? null : (int) \Carbon\Carbon::parse($since)->diffInDays(\Carbon\Carbon::parse($asOf)) + 1,
                    'first_day' => $day($r->first_day)];
            }

            return ['as_of' => $asOf, 'ads' => $out];
        });
    }

    /** @return array<string, array{customers: int, last: ?string, first: ?string, psids: array<int, string>}> */
    private function seenCached(string $page): array
    {
        return Cache::remember('chatbot-adgroups:seen:v3:' . sha1($this->settings->pageKey($page)), self::UNASSIGNED_TTL, fn () => $this->seen($page));
    }

    /** @return array<string, array{customers: int, last: ?string, first: ?string, psids: array<int, string>}> ad => ilang customer (at sino, para sa ad set at campaign), ang huli at ang unang oras */
    private function seen(string $page): array
    {
        $since = now()->subDays(self::UNASSIGNED_DAYS);
        $psids = [];
        $last = [];
        $first = [];
        $add = function ($ad, $psid, $at, $from = null) use (&$psids, &$last, &$first) {
            $ad = trim((string) $ad);
            if (!preg_match('/^\d{1,64}$/', $ad)) return;
            $psids[$ad][(string) $psid] = true;
            $at = $at ? (string) \Carbon\Carbon::parse($at)->format('Y-m-d H:i:s') : null;
            if ($at && ($last[$ad] ?? '') < $at) $last[$ad] = $at;
            $from = $from ? (string) \Carbon\Carbon::parse($from)->format('Y-m-d H:i:s') : $at;
            if ($from && (!isset($first[$ad]) || $first[$ad] > $from)) $first[$ad] = $from;
        };
        $pageId = $this->settings->get($page, 'botcake', 'page_id');
        if ($pageId !== null && $pageId !== '') {
            // Isang row kada ad + customer (ang JSON ng payload ay binabasa ng database, mysql/pgsql/sqlite).
            BotcakeChat::active()->where('page_id', $pageId)->where('received_at', '>=', $since)->whereNotNull('payload->ad_id')
                ->selectRaw('psid, MAX(received_at) as last_at, MIN(received_at) as first_at')->addSelect('payload->ad_id as ad')
                ->groupBy('payload->ad_id', 'psid')->toBase()->get()->each(fn ($r) => $add($r->ad, $r->psid, $r->last_at, $r->first_at));
        }
        $trim = DB::getDriverName() === 'pgsql' ? 'BTRIM' : 'TRIM';
        BotcakePsid::whereRaw("LOWER($trim(page)) = ?", [$this->settings->pageKey($page)])->whereNotNull('ad_id')->where('ad_id', '<>', '')
            ->where(fn ($w) => $w->where('last_interactive_at', '>=', $since)->orWhere('first_seen_at', '>=', $since))
            ->toBase()->get(['psid', 'ad_id', 'last_interactive_at', 'first_seen_at'])
            ->each(fn ($r) => $add($r->ad_id, $r->psid, max((string) $r->last_interactive_at, (string) $r->first_seen_at) ?: null, $r->first_seen_at ?: null));

        $out = [];
        foreach ($psids as $ad => $set) $out[(string) $ad] = ['customers' => count($set), 'last' => $last[$ad] ?? null, 'first' => $first[$ad] ?? null, 'psids' => array_map('strval', array_keys($set))];

        return $out;
    }

    /** Ang pangalan ng bawat ad (campaign / ad set / headline) mula sa pinakabagong row ng ads_manager_reports. */
    private function adNames(array $adIds): array
    {
        if (!$adIds || !\Illuminate\Support\Facades\Schema::hasTable('ads_manager_reports')) return [];

        return DB::table('ads_manager_reports')
            ->whereIn('id', fn ($q) => $q->from('ads_manager_reports')->whereIn('ad_id', $adIds)->selectRaw('MAX(id)')->groupBy('ad_id'))
            ->get(['ad_id', 'campaign_name', 'ad_set_name', 'headline'])
            ->mapWithKeys(fn ($r) => [(string) $r->ad_id => implode(' / ', array_filter([$r->campaign_name, $r->ad_set_name, $r->headline], fn ($v) => trim((string) $v) !== ''))])
            ->all();
    }

    /** Ad ba ito ng page: nasa isang group nito, o nakita sa page sa huling UNASSIGNED_DAYS araw. */
    public function hasAd(string $page, string $ad): bool
    {
        foreach ($this->groups($page) as $g) if (in_array($ad, $g['ads'], true)) return true;

        return isset($this->seenCached($page)[$ad]);
    }

    /**
     * Ang creative ng isang ad para sa preview, mula sa naka-save na (walang tawag sa Meta), gaya ng /owner/private:
     * ang row ng ad sa ad_campaign_creatives, o kapag wala, ang row ng campaign nito (from_campaign). Ang headline at body
     * na wala roon ay mula sa pinakabagong row ng ads_manager_reports. link: ang ad_link (post, reel o video sa
     * Facebook) kapag https na Facebook link lang, dahil ini-embed ito; kung hindi, null ("No preview").
     * @return array{ad_id: string, name: ?string, headline: string, body: string, link: ?string, from_campaign: bool}
     */
    public function creative(string $ad): array
    {
        $cols = ['headline', 'body_ad_settings', 'ad_link'];
        $hasCreatives = \Illuminate\Support\Facades\Schema::hasTable('ad_campaign_creatives');
        $report = \Illuminate\Support\Facades\Schema::hasTable('ads_manager_reports')
            ? DB::table('ads_manager_reports')->where('ad_id', $ad)->orderByDesc('day')->orderByDesc('id')->first(['headline', 'body_ad_settings', 'campaign_id']) : null;
        $cre = $hasCreatives ? DB::table('ad_campaign_creatives')->where('ad_id', $ad)->first($cols) : null;
        $fromCampaign = false;
        if (!$cre && $hasCreatives && $report && $report->campaign_id) {
            $cre = DB::table('ad_campaign_creatives')->where('campaign_id', $report->campaign_id)->first($cols);
            $fromCampaign = (bool) $cre;
        }
        $link = trim((string) ($cre->ad_link ?? ''));
        $facebook = preg_match('#^https://([a-z0-9-]+\.)?(facebook\.com|fb\.watch)/#i', $link) === 1;
        $text = fn (...$v) => (string) (collect($v)->first(fn ($x) => trim((string) $x) !== '') ?? '');

        return ['ad_id' => $ad, 'name' => ($this->adNames([$ad])[$ad] ?? '') ?: null,
            'headline' => $text($cre->headline ?? null, $report->headline ?? null), 'body' => $text($cre->body_ad_settings ?? null, $report->body_ad_settings ?? null),
            'link' => $facebook ? $link : null, 'from_campaign' => $fromCampaign];
    }

    /** Ang lahat ng row (active at history) ng group N ng page. */
    private function rows(string $page, int $id)
    {
        return $this->settings->pageQuery($page)->where('provider', self::PROVIDER)->where('key', 'like', 'g' . $id . '.%');
    }

    /** Ang susunod na numero: lampas sa lahat ng naisulat na sa page, pati ang na-delete. */
    private function nextId(string $page): int
    {
        $max = 0;
        foreach ($this->settings->pageQuery($page)->where('provider', self::PROVIDER)->distinct()->pluck('key') as $key) {
            if (preg_match('/^g(\d+)\./', (string) $key, $m)) $max = max($max, (int) $m[1]);
        }

        return $max + 1;
    }

    /** Isulat ang isang key ng group; ang blangko ay binubura (history). */
    private function put(string $page, int $id, string $key, string $value, ?int $userId): void
    {
        $full = 'g' . $id . '.' . $key;
        if ($value !== '') {
            $this->settings->set($page, self::PROVIDER, $full, $value, false, $userId, true);
            return;
        }
        foreach ($this->rows($page, $id)->where('key', $full)->where('active', true)->get() as $s) $this->settings->deactivate($s, $userId);
    }
}

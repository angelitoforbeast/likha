<?php

namespace App\Services\Chatbot;

use App\Models\BotcakeChat;
use App\Models\BotcakePsid;
use App\Models\PageSetting;
use App\Models\User;
use App\Services\ChatbotFields;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
    /** Run state ng customer (chatbot_customer_values): ang group nang pumasok siya sa sequence N. */
    public const SEQ_PIN = '__ad_group_seq_';

    /** Ang mga botfield na puwedeng palitan ng group; ang iba (form, shop details, AI, connection, On/Off) ay sa page lang. */
    public const VALUE_KEYS = ['item_name_in_jnt_dashboard', 'price', 'quantity', 'picture', 'main_flow', 'main_flow_2', 'flow_id'];

    public function __construct(private PageSettings $settings)
    {
    }

    public static function isValueKey(string $key): bool
    {
        return in_array($key, self::VALUE_KEYS, true) || (bool) preg_match('/^seq(_img)?_([1-9]|[1-9]\d)$/', $key);
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

    /** Ang numero ng group ng page na may ad na ito, o null. */
    public function groupForAd(string $page, ?string $adId): ?int
    {
        if ($adId === null || $adId === '') return null;
        foreach ($this->groups($page) as $id => $g) {
            if (in_array($adId, $g['ads'], true)) return $id;
        }

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

    /** Ang group ng customer ayon sa pinakabagong ad niya, o null (page values). Ang page na walang group: walang query. */
    public function forCustomer(int|string $pageId, int|string $psid, string $page): ?int
    {
        if (!$this->groups($page)) return null;

        return $this->groupForAd($page, $this->latestAd($pageId, $psid, $page));
    }

    /**
     * Ang group ng customer sa isang sequence: ang naitala nang pumasok siya (pin), para hindi lumipat sa gitna. Ang walang
     * pin (pumasok bago nagkaroon ng group ang page) ay kinukuha ngayon at itinatala. Ang na-delete na group = page values.
     */
    public function forSequence(int $sequenceId, CustomerFields $fields, string $page): ?int
    {
        $groups = $this->groups($page);
        if (!$groups) return null;
        $pin = $fields->get(self::SEQ_PIN . $sequenceId);
        if ($pin === null) {
            $group = $this->groupForAd($page, $this->latestAd($fields->pageId, $fields->psid, $page));
            $this->pin($sequenceId, $fields, $page, $group);

            return $group;
        }

        return isset($groups[(int) $pin]) ? (int) $pin : null;
    }

    /** Itala ang group ng customer sa pagpasok sa sequence (0 = page values). Walang isinusulat kapag walang group ang page. */
    public function pin(int $sequenceId, CustomerFields $fields, string $page, ?int $group): void
    {
        if ($group === null && !$this->groups($page)) return;
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
        $name = trim(preg_replace('/\s+/u', ' ', $name));
        if ($name === '') return ['ok' => false, 'error' => 'Give the group a name.'];
        if (mb_strlen($name) > self::MAX_NAME) return ['ok' => false, 'error' => 'Name: ' . self::MAX_NAME . ' characters at most.'];
        $parsed = self::parseAds(implode(',', array_map('strval', $ads)));
        if ($parsed['bad']) return ['ok' => false, 'error' => 'Not an ad ID (digits only): ' . implode(', ', array_slice($parsed['bad'], 0, 5)) . '.'];
        $ads = $parsed['ids'];
        sort($ads, SORT_STRING);
        if (count($ads) > self::MAX_ADS) return ['ok' => false, 'error' => 'At most ' . self::MAX_ADS . ' ads in a group.'];
        foreach ($values as $key => $value) {
            $key = (string) $key;
            $value = trim((string) $value);
            if (!self::isValueKey($key)) return ['ok' => false, 'error' => $key . ' is set on the page only, not in a group.'];
            if ($value === '') continue;
            if ($key === 'flow_id' && !ctype_digit($value)) return ['ok' => false, 'error' => 'Pick a flow from the list.'];
            if ($err = ChatbotFields::valueError($key, $value)) return ['ok' => false, 'error' => $key . ': ' . $err];
        }

        // Isa-isa kada page: ang pagsuri ng duplicate at ang pagsulat ay hindi nagsasabay.
        return Cache::lock('chatbot-adgroups:' . sha1($this->settings->pageKey($page)), 10)->block(5, function () use ($page, $id, $name, $ads, $values, $userId) {
            $groups = $this->groups($page);
            if ($id !== null && !isset($groups[$id])) return ['ok' => false, 'error' => 'This group was deleted. Reload the page.'];
            foreach ($ads as $ad) {
                foreach ($groups as $other) {
                    if ($other['id'] !== $id && in_array($ad, $other['ads'], true)) return ['ok' => false, 'error' => 'Ad ' . $ad . ' is already in the group "' . $other['name'] . '".'];
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

    /**
     * Ang mga tsinek na ad mula sa "Ads not in a group yet": idinadagdag sa group $id, o sa bagong group na $name.
     * @return array{ok: bool, id?: int, error?: string}
     */
    public function addAds(string $page, ?int $id, ?string $name, array $ads, ?int $userId = null): array
    {
        if ($id === null) return $this->save($page, null, (string) $name, $ads, [], $userId);
        $g = $this->groups($page)[$id] ?? null;
        if (!$g) return ['ok' => false, 'error' => 'This group was deleted. Reload the page.'];

        return $this->save($page, $id, $g['name'], array_merge($g['ads'], $ads), [], $userId);
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
        $g = $this->groups($page)[(int) $m[1]] ?? null;
        if (!$g) return ['ok' => false, 'error' => 'This group was deleted.'];
        $value = (string) $s->plainValue();

        return match ($m[2]) {
            'name' => $this->save($page, $g['id'], $value, $g['ads'], [], $userId),
            'ads' => $this->save($page, $g['id'], $g['name'], explode(',', $value), [], $userId),
            default => $this->save($page, $g['id'], $g['name'], $g['ads'], [$m[2] => $value], $userId),
        };
    }

    /**
     * "Ads not in a group yet": ang mga ad na nakita sa page sa huling UNASSIGNED_DAYS araw (ang ad_id ng mga chat, at ang
     * ad_id ng botcake_psids ng page), wala pa sa alinmang group ng page; ang pangalan mula sa ads_manager_reports. Ang
     * pinakamaraming customer muna, hanggang $limit; $q = bahagi ng ad ID o ng pangalan. Ang bilang ay naka-cache nang
     * UNASSIGNED_TTL segundo kada page.
     * @return array{rows: array<int, array{ad_id: string, name: ?string, customers: int, last: ?string}>, total: int}
     */
    public function unassigned(string $page, string $q = '', int $limit = self::UNASSIGNED_LIMIT): array
    {
        $seen = Cache::remember('chatbot-adgroups:seen:' . sha1($this->settings->pageKey($page)), self::UNASSIGNED_TTL, fn () => $this->seen($page));
        $grouped = array_merge(...array_values(array_map(fn ($g) => $g['ads'], $this->groups($page))) ?: [[]]);
        $rows = array_diff_key($seen, array_flip($grouped));
        $names = $this->adNames(array_map('strval', array_keys($rows)));
        $q = mb_strtolower(trim($q));
        $out = [];
        foreach ($rows as $ad => $r) {
            $ad = (string) $ad;
            $name = $names[$ad] ?? null;
            if ($q !== '' && !str_contains($ad, $q) && !str_contains(mb_strtolower((string) $name), $q)) continue;
            $out[] = ['ad_id' => $ad, 'name' => $name !== '' ? $name : null, 'customers' => $r['customers'],
                'last' => $r['last'] ? \Carbon\Carbon::parse($r['last'])->format('Y-m-d H:i') : null];
        }
        usort($out, fn ($a, $b) => [$b['customers'], (string) $b['last']] <=> [$a['customers'], (string) $a['last']]);

        return ['rows' => array_slice($out, 0, $limit), 'total' => count($out)];
    }

    /** @return array<string, array{customers: int, last: ?string}> ad => ilang customer at ang huling oras */
    private function seen(string $page): array
    {
        $since = now()->subDays(self::UNASSIGNED_DAYS);
        $psids = [];
        $last = [];
        $add = function ($ad, $psid, $at) use (&$psids, &$last) {
            $ad = trim((string) $ad);
            if (!preg_match('/^\d{1,64}$/', $ad)) return;
            $psids[$ad][(string) $psid] = true;
            $at = $at ? (string) \Carbon\Carbon::parse($at)->format('Y-m-d H:i:s') : null;
            if ($at && ($last[$ad] ?? '') < $at) $last[$ad] = $at;
        };
        $pageId = $this->settings->get($page, 'botcake', 'page_id');
        if ($pageId !== null && $pageId !== '') {
            // Isang row kada ad + customer (ang JSON ng payload ay binabasa ng database, mysql/pgsql/sqlite).
            BotcakeChat::active()->where('page_id', $pageId)->where('received_at', '>=', $since)->whereNotNull('payload->ad_id')
                ->selectRaw('psid, MAX(received_at) as last_at')->addSelect('payload->ad_id as ad')
                ->groupBy('payload->ad_id', 'psid')->toBase()->get()->each(fn ($r) => $add($r->ad, $r->psid, $r->last_at));
        }
        $trim = DB::getDriverName() === 'pgsql' ? 'BTRIM' : 'TRIM';
        BotcakePsid::whereRaw("LOWER($trim(page)) = ?", [$this->settings->pageKey($page)])->whereNotNull('ad_id')->where('ad_id', '<>', '')
            ->where(fn ($w) => $w->where('last_interactive_at', '>=', $since)->orWhere('first_seen_at', '>=', $since))
            ->toBase()->get(['psid', 'ad_id', 'last_interactive_at', 'first_seen_at'])
            ->each(fn ($r) => $add($r->ad_id, $r->psid, max((string) $r->last_interactive_at, (string) $r->first_seen_at) ?: null));

        $out = [];
        foreach ($psids as $ad => $set) $out[(string) $ad] = ['customers' => count($set), 'last' => $last[$ad] ?? null];

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

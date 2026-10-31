<?php

namespace App\Services\Chatbot;

use App\Models\PageSetting;
use App\Services\ChatblastSetupCheck;
use App\Services\MetaAds\Connections;
use App\Services\MetaAds\Graph;
use App\Services\MetaAds\GraphException;
use App\Services\PageSettings;

/**
 * Ang tunay na pangalan ng page sa Facebook, para makita kapag iba ang pangalang tinype ng staff o kapag dalawang row ang
 * may iisang page ID (ang chat ay napupunta sa maling row). Mga row ng page: facebook.name (ang pangalan, kapag may
 * nakabasa) at facebook.name_checked ("<oras> · <forbeast|incepxionbot|meta-ads|unknown> · <page ID>", bawat subok).
 * Ang page ID sa name_checked ang nagtatali ng pangalan sa ID: ang pangalang nabasa para sa ibang ID ay hindi na sa page na ito.
 *
 * Saan galing, sa ayos: ang gateway probe (Forbeast, IncepxionBot) na may token ang page; saka ang Meta Ads connection ng
 * mga user (Graph GET /{page-id}?fields=name, isang subok bawat isa, titigil sa unang may sagot); wala: unknown, at ang
 * huling alam na pangalan ay naiiwan. Hindi ang Meta app token: hindi nito nababasa ang page. Ang pangalan ay text mula sa
 * labas (untrusted): text lang ang ipinapakita. Walang token sa anumang isinusulat o ibinabalik.
 */
class FacebookName
{
    public const PROVIDER = 'facebook';
    public const UNKNOWN = 'unknown';
    public const META_ADS = 'meta-ads';
    /** Ang Graph lookup ay sa Test at sa gabi-gabing run: maikli, at walang ulit sa rate limit (isang tawag bawat connection). */
    private const GRAPH_TIMEOUT = 10;

    public function __construct(private PageSettings $settings, private ForbeastPageSetup $forbeast, private Connections $connections, private Graph $graph)
    {
    }

    /** Isinusulat ang pangalang nabasa ng $source para sa $pageId, at ang subok. */
    public function remember(string $page, string $pageId, string $name, string $source): void
    {
        $name = mb_substr(trim($name), 0, 255);
        if ($name === '' || trim($pageId) === '') return;
        $this->settings->set($page, self::PROVIDER, 'name', $name, false, null, true);
        $this->stamp($page, $source, $pageId);
    }

    /**
     * Ang sagot ng gateway probe (ForbeastPageSetup::probe, o ang GET ng connect) na nakabasa ng page na ito: itinatabi ang
     * pangalan. Ang mali sa pagsulat ay hindi sumisira sa Test o Save ng gateway. @return bool true = naitabi
     */
    public function fromProbe(string $page, string $pageId, array $probe, string $gateway): bool
    {
        if (($probe['status'] ?? null) !== 'ok' || trim((string) ($probe['name'] ?? '')) === '') return false;
        if (($probe['id'] ?? null) !== null && (string) $probe['id'] !== $pageId) return false;
        try {
            $this->remember($page, $pageId, (string) $probe['name'], $gateway);
            return true;
        } catch (\Throwable $e) {
            report($e);
            return false;
        }
    }

    /**
     * Hinahanap ang pangalan ng page ayon sa botcake.page_id nito. $skip: ang mga gateway na na-probe na sa run na ito.
     * $since: ang subok na naitala na mula noon (hal. ang retest ng gateway sa parehong run) ang ginagamit, walang bagong tawag.
     * @return array{name: string, source: string}|null null = walang nakabasa (unknown) o walang page ID
     */
    public function lookup(string $page, array $skip = [], ?\DateTimeInterface $since = null): ?array
    {
        $pageId = trim((string) $this->settings->get($page, 'botcake', 'page_id'));
        // Digits lang ang Facebook page ID; ang iba ay hindi ipinapasa sa URL ng gateway o ng Graph.
        if (!ctype_digit($pageId)) return null;
        $last = self::parse($this->settings->get($page, self::PROVIDER, 'name_checked'));
        $name = (string) $this->settings->get($page, self::PROVIDER, 'name');
        if ($since !== null && $last !== null && $last['id'] === $pageId && $last['at'] >= self::at($since)) {
            return $last['source'] !== self::UNKNOWN && $name !== '' ? ['name' => $name, 'source' => $last['source']] : null;
        }
        // Ang ibang row na may parehong page ID na nabasa na sa run na ito (ang doble): ang pangalang iyon, walang bagong tawag.
        if ($since !== null) {
            foreach ($this->overview() as $o) {
                if ($o['page_id'] === $pageId && $o['name'] !== null && $o['source'] !== self::UNKNOWN && $o['checked_at'] >= self::at($since)) {
                    $this->remember($page, $pageId, $o['name'], $o['source']);
                    return ['name' => $o['name'], 'source' => $o['source']];
                }
            }
        }
        // Ang pangalang nabasa para sa ibang ID (napalitan ang ID) ay hindi "huling alam" ng page na ito.
        if ($last !== null && $last['id'] !== $pageId) {
            $this->settings->pageQuery($page)->where('provider', self::PROVIDER)->where('key', 'name')->where('active', true)
                ->get()->each(fn ($s) => $this->settings->deactivate($s));
        }

        foreach (Gateways::ALL as $gw) {
            $setup = $this->forbeast->on($gw);
            if (in_array($gw, $skip, true) || $setup->token($page) === '') continue;
            $p = $setup->probe($pageId, $setup->token($page));
            if ($this->fromProbe($page, $pageId, $p, $gw)) return ['name' => $p['name'], 'source' => $gw];
        }

        foreach ($this->connections->all() as $c) {
            if (($c['status'] ?? 'ok') === 'expired') continue;
            $token = $this->connections->token($c);
            if ($token === null || $token === '') continue;
            try {
                $r = $this->graph->get($pageId, ['fields' => 'name'], $token, true, 1, self::GRAPH_TIMEOUT);
            } catch (GraphException $e) {
                // Walang sagot ang Graph (timeout, walang koneksyon): ang ibang token ay maghihintay lang din, kaya tigil na.
                if ($e->getCode() === 0 && str_starts_with($e->getMessage(), 'Graph request failed')) break;
                continue; // hindi nababasa ng connection na ito ang page (#100): ang susunod
            }
            $found = is_scalar($r['name'] ?? null) ? trim((string) $r['name']) : '';
            if ($found !== '') {
                $this->remember($page, $pageId, $found, self::META_ADS);
                return ['name' => mb_substr($found, 0, 255), 'source' => self::META_ADS];
            }
        }

        $this->stamp($page, self::UNKNOWN, $pageId);

        return null;
    }

    /**
     * Ang ipinapakita sa /chatbot/pages, sa Connections at sa banner (dalawang query, walang tawag sa labas): bawat page na may
     * botcake.page_id, ayon sa page key.
     * @return array<string, array{page: string, page_id: string, name: ?string, source: ?string, checked_at: ?string, differs: bool, same_id: list<string>, match: ?string}>
     */
    public function overview(): array
    {
        $ids = [];
        foreach (PageSetting::where('provider', 'botcake')->where('key', 'page_id')->where('active', true)->where('page_name', '!=', ChatblastSetupCheck::ALL_PAGE)
            ->orderBy('id')->get(['page_name', 'value']) as $s) {
            $id = trim((string) $s->value);
            if ($id !== '') $ids[$this->settings->pageKey($s->page_name)] = ['page' => (string) $s->page_name, 'id' => $id];
        }
        if (!$ids) return [];

        $rows = [];
        foreach (PageSetting::where('provider', self::PROVIDER)->whereIn('key', ['name', 'name_checked'])->where('active', true)->orderBy('id')->get(['page_name', 'key', 'value']) as $s) {
            $rows[$this->settings->pageKey($s->page_name)][$s->key] = (string) $s->value;
        }
        // Ang pangalan bawat page ID: ang nabasa ng isang row (ng anumang row na may ID na iyon), ang huling nabasa nang maayos muna.
        $named = [];
        foreach ($rows as $r) {
            $c = self::parse($r['name_checked'] ?? null);
            if ($c === null || trim($r['name'] ?? '') === '') continue;
            $cand = ['name' => trim($r['name']), 'source' => $c['source'], 'at' => $c['at']];
            $best = $named[$c['id']] ?? null;
            if ($best === null || [$cand['source'] !== self::UNKNOWN, $cand['at']] > [$best['source'] !== self::UNKNOWN, $best['at']]) $named[$c['id']] = $cand;
        }
        $byId = [];
        foreach ($ids as $key => $p) $byId[$p['id']][$key] = $p['page'];

        $out = [];
        foreach ($ids as $key => $p) {
            $fb = $named[$p['id']] ?? null;
            $others = array_values(array_diff_key($byId[$p['id']], [$key => true]));
            sort($others, SORT_NATURAL | SORT_FLAG_CASE);
            $match = null;
            if ($fb !== null) {
                foreach ($byId[$p['id']] as $k => $name) if ($k === $this->settings->pageKey($fb['name'])) $match = $name;
            }
            $out[$key] = ['page' => $p['page'], 'page_id' => $p['id'], 'name' => $fb['name'] ?? null, 'source' => $fb['source'] ?? null, 'checked_at' => $fb['at'] ?? null,
                // Trim, case-insensitive at pinagsamang espasyo lang (PageSettings::pageKey): walang ibang paghula.
                'differs' => $fb !== null && $this->settings->pageKey($fb['name']) !== $key,
                'same_id' => $others, 'match' => $match];
        }

        return $out;
    }

    private function stamp(string $page, string $source, string $pageId): void
    {
        $this->settings->set($page, self::PROVIDER, 'name_checked', self::at(now()) . ' · ' . $source . ' · ' . $pageId, false, null, true);
    }

    private static function at(\DateTimeInterface $t): string
    {
        return \Illuminate\Support\Carbon::instance($t)->timezone('Asia/Manila')->format('Y-m-d H:i:s');
    }

    /** @return array{at: string, source: string, id: string}|null */
    private static function parse(?string $v): ?array
    {
        $parts = explode(' · ', (string) $v);
        if (count($parts) !== 3 || trim($parts[2]) === '') return null;

        return ['at' => $parts[0], 'source' => $parts[1], 'id' => trim($parts[2])];
    }
}

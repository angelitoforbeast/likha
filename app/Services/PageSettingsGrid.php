<?php

namespace App\Services;

use App\Models\BotcakePsid;
use App\Models\BotcakePsidImportRun;
use App\Models\PageSetting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Ang /page-settings bilang grid na parang Google Sheet: isang row kada page, isang column kada provider + key.
 * Ang storage ay Page | Key | Value pa rin (page_settings); dito lang ito inaayos bilang grid. Ilang query lang
 * kahit ilang page o key (settings, user names, customers, huling sync), walang query kada cell.
 * Ang secret ay hindi kailanman lumalabas: displayValue() lang (•••• + huling 4).
 */
class PageSettingsGrid
{
    /** Mga kilalang column, sa ganitong ayos, laging nakikita kahit wala pang value (para mapunan ng owner). */
    public const KNOWN = [
        'botcake.page_id'          => 'Botcake ID',
        'botcake.token'            => 'Botcake token',
        'botcake.flow.shipping'    => 'Shipping flow ID',
        'botcake.flow.on_delivery' => 'Delivering flow ID',
        'botcake.flow.in_transit'  => 'In transit flow ID',
        'botcake.flow.broadcast'   => 'Broadcast flow ID',
        'botcake.field.message'    => 'Message field ID',
        'botcake.token_status'     => 'Token status',
    ];

    public function __construct(private PageSettings $settings, private BotcakeCustomerSync $sync, private BotcakeSyncAll $syncAll)
    {
    }

    /** Ilang segundo naka-cache ang bilang ng orders kada page (mabigat na query; hindi ito pinapatakbo ng poll). */
    public const ORDER_COUNT_TTL = 600;

    /**
     * Ang buong grid: columns, rows at ang oras na gagamitin sa susunod na poll. Rows = mga page na may order sa huling
     * N araw (pinakamaraming order muna), saka ang mga page na may settings pero walang order sa window (ayon sa pangalan).
     */
    public function build(): array
    {
        $now    = $this->now();
        $active = PageSetting::where('active', true)->orderBy('id')->get();
        $names  = $this->userNames($active);

        $rows = [];
        foreach ($active as $s) {
            $pk = $this->settings->pageKey($s->page_name);
            $rows[$pk] ??= ['key' => $pk, 'name' => $this->settings->normPage($s->page_name), 'cells' => []];
            $rows[$pk]['cells'][$s->fullKey()] = $this->cell($s, $names);
        }

        // Ang page na may order pero walang settings: walang lamang cells, gaya ng row mula sa "+ Page".
        $orders = $this->orderCounts();
        foreach ($orders as $pk => $o) {
            $rows[$pk] ??= ['key' => $pk, 'name' => $o['name'], 'cells' => []];
        }

        $info = $this->info(array_keys($rows));
        foreach ($rows as $pk => &$row) {
            $row['info'] = $this->rowInfo($row['cells'], $info, $pk) + ['orders' => $orders[$pk]['orders'] ?? 0];
        }
        unset($row);

        uasort($rows, fn ($a, $b) => ($b['info']['orders'] <=> $a['info']['orders']) ?: strnatcasecmp($a['name'], $b['name']));

        return [
            'columns' => $this->columns($active), 'rows' => array_values($rows), 'now' => $now,
            'sync_lock' => $this->syncLock(), 'sync_all' => $this->syncAll->progress(), 'order_days' => $this->orderDays(),
        ];
    }

    public function orderDays(): int
    {
        return max(1, (int) config('services.botcake.grid_order_days', 30));
    }

    /**
     * Bilang ng orders kada page sa huling N araw (ts_date), sa isang grouped query na naka-cache nang 10 minuto.
     * Pinagsasama ang mga baybay na pareho ang pageKey; ang pangalan ay ang pinakamadalas na baybay. Blangkong PAGE = wala.
     * @return array<string, array{name:string, orders:int}> pageKey => pangalan at bilang
     */
    private function orderCounts(): array
    {
        $days = $this->orderDays();

        return Cache::remember('page-settings-grid:orders:' . $days, self::ORDER_COUNT_TTL, function () use ($days) {
            $counts = DB::table('macro_output')
                ->where('ts_date', '>=', now('Asia/Manila')->subDays($days)->toDateString())
                ->whereNotNull('PAGE')
                ->select('PAGE')->selectRaw('COUNT(*) AS n')->groupBy('PAGE')->get();

            $out = [];
            $spellings = [];
            foreach ($counts as $c) {
                $name = $this->settings->normPage((string) $c->PAGE);
                if ($name === '') continue;
                $pk = $this->settings->pageKey($name);
                $out[$pk] = ['name' => $name, 'orders' => ($out[$pk]['orders'] ?? 0) + (int) $c->n];
                $spellings[$pk][$name] = ($spellings[$pk][$name] ?? 0) + (int) $c->n;
            }
            foreach ($spellings as $pk => $byName) {
                arsort($byName);
                $out[$pk]['name'] = (string) array_key_first($byName);
            }

            return $out;
        });
    }

    /**
     * Ang mga cell na nagbago mula sa $since (bago, pinalitan o na-clear), at ang info ng mga page na may sync mula noon.
     * Isang ACTIVE row lang kada cell: kapag ang cell ay walang active sa mga nagbago, na-clear ito (cell = null).
     */
    public function changes(Carbon $since): array
    {
        $now     = $this->now();
        $changed = PageSetting::where(fn ($q) => $q->where('created_at', '>=', $since)->orWhere('replaced_at', '>=', $since))
            ->orderBy('id')->get();
        $names   = $this->userNames($changed->where('active', true));

        $cells = [];
        foreach ($changed as $s) {
            $pk = $this->settings->pageKey($s->page_name);
            $id = $pk . '|' . $s->fullKey();
            $cells[$id] ??= ['page' => $pk, 'name' => $this->settings->normPage($s->page_name), 'key' => $s->fullKey(), 'cell' => null];
            if ($s->active) $cells[$id]['cell'] = $this->cell($s, $names);
        }

        $runPages = BotcakePsidImportRun::where('source', 'api')->where('updated_at', '>=', $since)->distinct()->pluck('page_name')
            ->filter()->map(fn ($p) => $this->settings->pageKey($p))->unique()->values()->all();

        return [
            'cells' => array_values($cells), 'info' => $runPages ? $this->info($runPages) : [], 'now' => $now,
            'sync_lock' => $this->syncLock(), 'sync_all' => $this->syncAll->progress(),
        ];
    }

    /** Ang dating values ng isang cell (pinalitan o na-clear), pinakabago muna. Secret = huling 4 lang. */
    public function history(string $page, string $provider, string $key): array
    {
        $rows = $this->settings->pageQuery($page)->where('provider', strtolower(trim($provider)))->where('key', strtolower(trim($key)))
            ->where('active', false)->orderByDesc('id')->limit(50)->get();
        $names = $this->userNames($rows);

        return $rows->map(fn ($s) => $this->cell($s, $names) + [
            'replaced_at' => $s->replaced_at?->timezone('Asia/Manila')->format('Y-m-d H:i'),
        ])->all();
    }

    /** Ang ipinapadala sa browser para sa isang setting: hindi kailanman ang secret mismo. */
    public function cell(PageSetting $s, ?Collection $names = null): array
    {
        $names ??= $this->userNames(collect([$s]));

        return [
            'id'     => $s->id,
            'value'  => $s->displayValue(),
            'secret' => (bool) $s->is_secret,
            'by'     => $s->created_by ? ($names[$s->created_by] ?? 'user #' . $s->created_by) : 'system',
            'at'     => $s->created_at?->timezone('Asia/Manila')->format('Y-m-d H:i'),
        ];
    }

    /**
     * Ang "since" ng susunod na poll. Segundo lang ang created_at, at maaaring ma-commit ang save pagkatapos ng query
     * ngunit may naunang segundo: kaya 2 segundong overlap. Ang naulit na cell ay walang pinsala.
     */
    /** Kailan nagsimula ang Botcake sync na may hawak ng lock (Manila), o null. Mula sa cache, hindi sa database. */
    private function syncLock(): ?string
    {
        return $this->sync->lockedSince()?->timezone('Asia/Manila')->format('Y-m-d H:i');
    }

    private function now(): string
    {
        return now()->subSeconds(2)->format('Y-m-d H:i:s');
    }

    private function columns(Collection $active): array
    {
        $secret = $active->where('is_secret', true)->map(fn ($s) => $s->fullKey())->flip();

        $col = fn (string $key, ?string $label) => [
            'key'      => $key,
            'provider' => strstr($key, '.', true),
            'name'     => substr(strstr($key, '.'), 1),
            'label'    => $label ?? $key,
            'secret'   => in_array($key, PageSettings::SECRET_KEYS, true) || isset($secret[$key]),
            'readonly' => in_array($key, PageSettings::SYSTEM_KEYS, true),
        ];

        $other = $active->map(fn ($s) => $s->fullKey())->unique()->reject(fn ($k) => isset(self::KNOWN[$k]))->sort()->values();

        return array_merge(
            array_map($col, array_keys(self::KNOWN), array_values(self::KNOWN)),
            $other->map(fn ($k) => $col($k, null))->all()
        );
    }

    /** @return Collection<int, string> user id => pangalan, sa isang query */
    private function userNames(Collection $settings): Collection
    {
        $ids = $settings->pluck('created_by')->filter()->unique()->values();
        return $ids->isEmpty() ? collect() : User::whereIn('id', $ids)->pluck('name', 'id');
    }

    /**
     * Customers (available na API rows) at ang huling API sync kada page: dalawang grouped query lang.
     * @param array<int, string> $pageKeys
     * @return array<string, array{customers:int, last_sync:?array}>
     */
    private function info(array $pageKeys): array
    {
        if (!$pageKeys) return [];

        $trim = DB::getDriverName() === 'pgsql' ? 'BTRIM' : 'TRIM';
        $out  = array_fill_keys($pageKeys, ['customers' => 0, 'last_sync' => null]);

        $counts = BotcakePsid::where('source', 'api')->where('status', 'available')
            ->selectRaw("LOWER($trim(page)) as pk, COUNT(*) as n")->groupByRaw("LOWER($trim(page))")->get();
        foreach ($counts as $c) {
            $pk = $this->settings->pageKey((string) $c->pk);
            if (isset($out[$pk])) $out[$pk]['customers'] += (int) $c->n;
        }

        $runs = BotcakePsidImportRun::whereIn('id', function ($q) use ($trim) {
            $q->from('botcake_psid_import_runs')->where('source', 'api')->whereNotNull('page_name')
                ->selectRaw('MAX(id)')->groupByRaw("LOWER($trim(page_name))");
        })->orderBy('id')->get();
        foreach ($runs as $run) {
            $pk = $this->settings->pageKey((string) $run->page_name);
            if (!isset($out[$pk])) continue;
            $out[$pk]['last_sync'] = [
                'at'          => $run->created_at?->timezone('Asia/Manila')->format('Y-m-d H:i'),
                'status'      => $run->status,
                'stop_reason' => $run->stop_reason,
                'kind'        => BotcakeCustomerSync::kindLabel($run->read_kind, $run->start_batch),
                'error'       => $run->last_error,
            ];
        }

        return $out;
    }

    private function rowInfo(array $cells, array $info, string $pk): array
    {
        return ($info[$pk] ?? ['customers' => 0, 'last_sync' => null])
            + ['botcake' => isset($cells['botcake.page_id'], $cells['botcake.token'])];
    }
}

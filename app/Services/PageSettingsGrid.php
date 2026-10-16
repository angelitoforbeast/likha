<?php

namespace App\Services;

use App\Models\BotcakePsid;
use App\Models\BotcakePsidImportRun;
use App\Models\PageSetting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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

    public function __construct(private PageSettings $settings)
    {
    }

    /** Ang buong grid: columns, rows (kada page, ayon sa pangalan) at ang oras na gagamitin sa susunod na poll. */
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

        $info = $this->info(array_keys($rows));
        foreach ($rows as $pk => &$row) {
            $row['info'] = $this->rowInfo($row['cells'], $info, $pk);
        }
        unset($row);

        uasort($rows, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));

        return ['columns' => $this->columns($active), 'rows' => array_values($rows), 'now' => $now];
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

        return ['cells' => array_values($cells), 'info' => $runPages ? $this->info($runPages) : [], 'now' => $now];
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

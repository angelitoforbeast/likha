<?php

namespace App\Services;

use App\Support\ItemBaseKey;
use App\Support\ItemLifecycle;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ItemStockService — stock gauge, DOI, order qty, category at item value para sa /item (GET /item/stock).
 *
 * Bawat input = isang grouped query; ang pagsasama sa base key (ItemBaseKey::key) ay sa PHP, sa
 * grouped rows lang. Spec: docs/specs/003-stock-doi-category.md.
 *
 *   stock_raw = natanggap mula START − umalis mula START;  stock = max(0, stock_raw)
 *   order_qty = max(0, ceil(hold + upd × (lead + palugit) − incoming − stock))
 *   doi       = round((stock + incoming − hold) × days / units, 1)  — ito ang ginagamit sa kulay at order_by
 *
 * Handoff 004: bawat base item ay may lifecycle (ItemLifecycle, as-of = end date) at dalawang result set,
 * `normal` (palugit at velocity ng lifecycle) at `lugi` (palugit_lugi, 14-day) — ang page ang pumipili
 * gamit ang kita. Spec: docs/specs/004-lifecycle-restock.md.
 */
class ItemStockService
{
    private const DEFAULT_LEAD   = 7;
    private const DEMAND_DAYS    = 14;
    private const SCALING_DAYS   = 7;
    private const NEAR_ZERO      = 0.5;
    private const FIRST_DATES_TTL = 43200;
    /** Default palugit (araw) bawat lifecycle; ang supply_settings (palugit_*) ang nananalo. */
    private const PALUGIT_DEFAULTS = ['new' => 3, 'scaling' => 14, 'consistent' => 10, 'active' => 7, 'declining' => 0, 'lugi' => 3];
    private const START_SETTING  = 'item_stock_start';
    private const OPEN_PO_STATUSES = ['ordered', 'delivered'];

    /** @return array<string,mixed> */
    public function build(string $start, string $end, bool $withCeoValue): array
    {
        $hold   = $this->holdByBase($start, $end);
        $demand = $this->demandByBase($end);

        $stockStart = $this->stockStart();
        $ready      = $stockStart !== null;
        $left       = $ready ? $this->leftByBase($stockStart) : [];
        $received   = $ready ? $this->receivedByKey($stockStart) : [];
        $incoming   = $this->incomingByKey();
        $settings   = $this->settingsByKey();
        $category   = $this->categoryByKey();

        $keys = array_unique(array_merge(
            array_keys($hold), array_keys($demand), array_keys($left['units'] ?? []),
            array_keys($received), array_keys($incoming), array_map('strval', array_keys($category))
        ));
        sort($keys);

        $endDay    = Carbon::parse($end, 'Asia/Manila')->startOfDay();
        $life      = $this->lifecycleInputs($end);
        $firstDate = $this->firstDates($end);
        $palugit   = $this->palugitDefaults();
        $items     = [];
        foreach ($keys as $k) {
            $holdUnits = (int) ($hold[$k]['units'] ?? 0);
            $inc       = (int) ($incoming[$k] ?? 0);
            $lead      = $settings[$k]['lead'] ?? self::DEFAULT_LEAD;

            // 14-day: 003's rule. 7-day: units ng huling 7 araw ÷ 7 (Scaling lang; ang mas malaki sa dalawa ang gamit).
            $v14 = ['units' => 0, 'days' => 1];
            if (isset($demand[$k]) && $demand[$k]['units'] > 0) {
                $first = Carbon::parse($demand[$k]['first'], 'Asia/Manila')->startOfDay();
                $v14   = ['units' => $demand[$k]['units'],
                          'days'  => max(1, min(self::DEMAND_DAYS, (int) $first->diffInDays($endDay) + 1))];
            }
            $v7 = ['units' => $demand[$k]['units7'] ?? 0, 'days' => 7];

            [$lifecycle, $auto] = $this->lifecycleOf($k, $life, $firstDate, $end, $settings[$k]['lifecycle_override'] ?? null);
            $override = $settings[$k]['palugit_override'] ?? null;
            $pal = fn (string $lc) => $override ?? $palugit[$lc] ?? $palugit['active'];

            $gated = in_array($lifecycle, ['scaling', 'consistent'], true);
            $ctx   = [
                'ready' => $ready, 'hold' => $holdUnits, 'inc' => $inc, 'lead' => $lead, 'endDay' => $endDay,
                'lifecycle' => $lifecycle, 'upd14' => $v14['units'] / $v14['days'], 'upd14_days' => $v14['days'],
                'stock' => null,
            ];
            $stock = null;
            if ($ready) {
                $raw   = (int) ($received[$k] ?? 0) - (int) ($left['units'][$k] ?? 0);
                $stock = max(0, $raw);
                $ctx['stock'] = $stock;
            }

            // Scaling: ang mas malaki sa 7-day at 14-day (amendment 004-1); tie = parehong numero.
            $vNormal = $lifecycle === 'scaling' && $v7['units'] / $v7['days'] > $v14['units'] / $v14['days'] ? $v7 : $v14;
            $normal = $this->restockSet($ctx, $vNormal, $pal($lifecycle));
            $lugi   = $gated ? $this->restockSet($ctx, $v14, $pal('lugi')) : $normal;

            $variants = array_keys(($hold[$k]['variants'] ?? []) + ($demand[$k]['variants'] ?? []));
            sort($variants);

            $items[$k] = [
                'name'             => $hold[$k]['name'] ?? $demand[$k]['name'] ?? $left['names'][$k] ?? $k,
                'variants'         => $variants,
                'hold_units'       => $holdUnits,
                'incoming'         => $inc,
                'stock_raw'        => $ready ? $raw : null,
                'stock'            => $stock,
                'stock_needs_count' => $ready ? $raw < 0 : null,
                'lead'             => $lead,
                'palugit_override' => $override,
                'category_id'      => $category[$k]['id'] ?? null,
                'category'         => $category[$k]['name'] ?? null,
                'lifecycle'        => $lifecycle,
                'lifecycle_label'  => ItemLifecycle::badge($lifecycle)[0],
                'lifecycle_auto'   => $auto,
                'gated'            => $gated,
                'normal'           => $normal,
                'lugi'             => $lugi,
            ];
        }

        return [
            'ok'          => true,
            'start'       => $start,
            'end_date'    => $end,
            'stock_ready' => $ready,
            'categories'  => $this->categories(),
            'items'       => $items ?: new \stdClass(),
            'values'      => $this->values($start, $end, $withCeoValue) ?: new \stdClass(),
        ];
    }

    /**
     * Isang result set (normal o lugi) ng isang base item.
     * $vel = ['units','days'] ng velocity na gagamitin (14-day o 7-day); $palugit = araw ng palugit.
     * Phasing Out / Dormant = HOLD lang (walang lead, walang palugit). stock_ready = false → walang
     * order_qty/doi/order_by/colour, pero ang doi_note (galing sa lifecycle at velocity lang) ay nananatili.
     *
     * @param array<string,mixed> $c
     * @param array{units:int,days:int} $vel
     * @return array<string,mixed>
     */
    private function restockSet(array $c, array $vel, int $palugit): array
    {
        $holdOnly = in_array($c['lifecycle'], ['phasing_out', 'dormant'], true);
        $v        = $vel['units'] > 0 ? $vel['units'] / $vel['days'] : 0.0;
        $set = [
            'units_per_day' => round($holdOnly ? $c['upd14'] : $v, 2),
            // araw sa likod ng units_per_day (7 kapag 7-day ang nanalo sa Scaling; HOLD-only = 14-day window)
            'velocity_days' => $holdOnly ? $c['upd14_days'] : $vel['days'],
            'palugit'       => $holdOnly ? null : $palugit,
            'doi'           => null,
            'doi_note'      => $holdOnly ? 'walang_benta' : ($v > 0 && $v < self::NEAR_ZERO ? 'halos_walang_benta' : null),
            'order_qty'     => null, 'order_by' => null, 'colour' => null,
        ];
        if (!$c['ready']) return $set;

        $lead  = $c['lead'];
        $stock = $c['stock'];
        if ($holdOnly) {
            $set['order_qty'] = (int) max(0, ceil($c['hold'] - $c['inc'] - $stock));
            $set['colour']    = 'grey';
            return $set;
        }

        $set['order_qty'] = (int) max(0, ceil(round($c['hold'] + $v * ($lead + $palugit) - $c['inc'] - $stock, 6)));
        if ($v > 0) {
            // Walang paghahati sa umuulit na float; ang naka-round na doi ang nagpapasya ng kulay at petsa.
            $doi = round(($stock + $c['inc'] - $c['hold']) * $vel['days'] / $vel['units'], 1);
            $set['doi']      = $doi;
            $set['order_by'] = $doi < $lead ? 'now' : $c['endDay']->copy()->addDays((int) floor($doi - $lead))->toDateString();
            $set['colour']   = $set['doi_note'] !== null ? 'grey' : ($doi < $lead ? 'red' : ($doi < $lead + $palugit ? 'amber' : 'green'));
        }
        return $set;
    }

    /**
     * Lifecycle ng base item as-of $end — parehong kahulugan ng /jnt/supply (bilang ang LAHAT ng macro_output
     * rows). Kapag walang first date sa (naka-cache na) mapa pero may order sa window, window_first ang gamit.
     * @return array{0:string,1:bool} [lifecycle, auto]
     */
    private function lifecycleOf(string $k, array $life, array $firstDate, string $end, ?string $override): array
    {
        if ($override !== null && $override !== '') return [$override, false];

        $first = $firstDate[$k] ?? ($life[$k]['window_first'] ?? null);
        $days  = $first
            ? (int) Carbon::parse($first, 'Asia/Manila')->diffInDays(Carbon::parse($end, 'Asia/Manila'))
            : 9999;
        $recent = round(($life[$k]['recent'] ?? 0) / self::DEMAND_DAYS, 4);
        $prev   = round(($life[$k]['prev'] ?? 0) / self::DEMAND_DAYS, 4);

        return [ItemLifecycle::classify(
            $recent, $prev, $days, $first !== null,
            ItemLifecycle::NEW_ITEM_DAYS, ItemLifecycle::LONG_RUNNING_DAYS,
            ItemLifecycle::SCALE_THRESHOLD, ItemLifecycle::DECLINE_THRESHOLD
        ), true];
    }

    /** base key => ['recent','prev','window_first'] — recent = end−13..end, prev = end−27..end−14 (units). */
    private function lifecycleInputs(string $end): array
    {
        $endC  = Carbon::parse($end, 'Asia/Manila');
        $item  = $this->col('ITEM_NAME');
        $rows  = DB::table('macro_output as mo')
            ->whereBetween('mo.ts_date', [$endC->copy()->subDays(2 * self::DEMAND_DAYS - 1)->toDateString(), $end])
            ->selectRaw(
                "$item as item_name,
                 SUM(CASE WHEN mo.ts_date >= ? THEN 1 ELSE 0 END) as recent,
                 SUM(CASE WHEN mo.ts_date <= ? THEN 1 ELSE 0 END) as prev,
                 MIN(mo.ts_date) as window_first",
                [$endC->copy()->subDays(self::DEMAND_DAYS - 1)->toDateString(), $endC->copy()->subDays(self::DEMAND_DAYS)->toDateString()]
            )
            ->groupByRaw($item)
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $p = ItemBaseKey::parse(trim((string) ($r->item_name ?? '')));
            if ($p['key'] === '') continue;
            $first = substr((string) $r->window_first, 0, 10);
            $d = $out[$p['key']] ??= ['recent' => 0, 'prev' => 0, 'window_first' => $first];
            $d['recent'] += (int) $r->recent * $p['qty'];
            $d['prev']   += (int) $r->prev * $p['qty'];
            if ($first < $d['window_first']) $d['window_first'] = $first;
            $out[$p['key']] = $d;
        }
        return $out;
    }

    /** base key => pinakamaagang order date <= $end. Buong scan ng macro_output kaya naka-cache (12 oras). */
    private function firstDates(string $end): array
    {
        $key = 'item_stock:first_dates:v1:' . strtolower(request()->getHost()) . ':' . $end;

        return Cache::remember($key, self::FIRST_DATES_TTL, function () use ($end) {
            $item = $this->col('ITEM_NAME');
            $rows = DB::table('macro_output as mo')
                ->where('mo.ts_date', '<=', $end)
                ->selectRaw("$item as item_name, MIN(mo.ts_date) as first_date")
                ->groupByRaw($item)
                ->get();

            $out = [];
            foreach ($rows as $r) {
                $k = ItemBaseKey::key(trim((string) ($r->item_name ?? '')));
                if ($k === '' || $r->first_date === null) continue;
                $d = substr((string) $r->first_date, 0, 10);
                if (!isset($out[$k]) || $d < $out[$k]) $out[$k] = $d;
            }
            return $out;
        });
    }

    /** lifecycle => araw ng palugit galing supply_settings (palugit_*); wala = default sa code. */
    private function palugitDefaults(): array
    {
        $out = self::PALUGIT_DEFAULTS;
        if (!Schema::hasTable('supply_settings')) return $out;

        $keys = array_map(fn ($n) => 'palugit_' . $n, array_keys($out));
        foreach (DB::table('supply_settings')->whereIn('key', $keys)->pluck('value', 'key') as $key => $value) {
            if (is_numeric($value)) $out[substr($key, 8)] = (int) $value;
        }
        return $out;
    }

    private function col(string $name): string
    {
        return DB::getDriverName() === 'pgsql' ? 'mo."' . $name . '"' : 'mo.`' . $name . '`';
    }

    /** STATUS expression kapareho ng HoldService::liveHoldQuery. */
    private function notCancelledSql(): string
    {
        $status = $this->col('STATUS');
        $marks  = implode(',', array_fill(0, count(HoldService::CANCELLED_STATUSES), '?'));
        return "($status IS NULL OR LOWER(REPLACE(REPLACE(TRIM($status), ' ', ''), '_', '')) NOT IN ($marks))";
    }

    /** base key => ['name','units','variants'=>[raw=>units]] — kapareho ng /item/worklist HOLD. */
    private function holdByBase(string $start, string $end): array
    {
        $svc  = app(HoldService::class);
        $item = $this->col('ITEM_NAME');
        $rows = $svc->liveHoldQuery($start, $end)
            ->selectRaw("$item as item_name, COUNT(*) as hold_count")
            ->groupByRaw($item)
            ->get();

        $out = [];
        foreach ($svc->groupUnitsByBaseItem($rows) as $info) {
            $k = ItemBaseKey::key($info['name']);
            $out[$k] ??= ['name' => $info['name'], 'units' => 0, 'variants' => []];
            $out[$k]['units'] += (int) $info['units'];
            foreach ($info['variants'] as $n => $u) $out[$k]['variants'][$n] = true;
        }
        return $out;
    }

    /** base key => ['name','units','first','variants'=>[raw=>true]] — huling 14 araw hanggang $end. */
    private function demandByBase(string $end): array
    {
        $from  = Carbon::parse($end, 'Asia/Manila')->subDays(self::DEMAND_DAYS - 1)->toDateString();
        $from7 = Carbon::parse($end, 'Asia/Manila')->subDays(self::SCALING_DAYS - 1)->toDateString();
        $item  = $this->col('ITEM_NAME');
        $rows = DB::table('macro_output as mo')
            ->whereBetween('mo.ts_date', [$from, $end])
            ->whereRaw($this->notCancelledSql(), HoldService::CANCELLED_STATUSES)
            ->selectRaw(
                "$item as item_name, COUNT(*) as cnt, MIN(mo.ts_date) as first_date,
                 SUM(CASE WHEN mo.ts_date >= ? THEN 1 ELSE 0 END) as cnt7",
                [$from7]
            )
            ->groupByRaw($item)
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $raw = trim((string) ($r->item_name ?? ''));
            if ($raw === '') continue;
            $p = ItemBaseKey::parse($raw);
            if ($p['key'] === '') continue;
            $first = substr((string) $r->first_date, 0, 10);
            $d = $out[$p['key']] ??= ['name' => $p['base'], 'units' => 0, 'units7' => 0, 'first' => $first, 'variants' => []];
            $d['units']  += (int) $r->cnt * $p['qty'];
            $d['units7'] += (int) $r->cnt7 * $p['qty'];
            if ($first < $d['first']) $d['first'] = $first;
            $d['variants'][$raw] = true;
            $out[$p['key']] = $d;
        }
        return $out;
    }

    /**
     * Units na umalis mula START: waybills na ang PINAKAUNANG J&T record ay >= START
     * (submission_time). Walang submission_time = hindi mabibilang.
     * @return array{units: array<string,int>, names: array<string,string>}
     */
    private function leftByBase(string $stockStart): array
    {
        $from = $stockStart . ' 00:00:00';
        $item = $this->col('ITEM_NAME');
        $rows = DB::select(
            "SELECT $item AS item_name, COUNT(*) AS cnt
             FROM macro_output mo
             JOIN (SELECT DISTINCT fj.waybill_number FROM from_jnts fj
                   WHERE fj.submission_time >= ?
                     AND NOT EXISTS (SELECT 1 FROM from_jnts fj2
                                     WHERE fj2.waybill_number = fj.waybill_number
                                       AND fj2.submission_time < ?)) s
               ON s.waybill_number = mo.waybill
             WHERE " . $this->notCancelledSql() . "
             GROUP BY $item",
            array_merge([$from, $from], HoldService::CANCELLED_STATUSES)
        );

        $units = [];
        $names = [];
        foreach ($rows as $r) {
            $raw = trim((string) ($r->item_name ?? ''));
            if ($raw === '') continue;
            $p = ItemBaseKey::parse($raw);
            if ($p['key'] === '') continue;
            $units[$p['key']] = ($units[$p['key']] ?? 0) + (int) $r->cnt * $p['qty'];
            $names[$p['key']] ??= $p['base'];
        }
        return ['units' => $units, 'names' => $names];
    }

    /** base key => Σ received_qty ng POs na na-count mula START (discount line hindi kasama). */
    private function receivedByKey(string $stockStart): array
    {
        if (!Schema::hasTable('supply_order_items') || !Schema::hasTable('supply_orders')) return [];
        $rows = DB::table('supply_order_items as i')
            ->join('supply_orders as o', 'o.id', '=', 'i.supply_order_id')
            ->where('o.counted_at', '>=', $stockStart . ' 00:00:00')
            ->whereNotNull('i.received_qty')
            ->where('i.unit_cost', '>=', 0)
            ->selectRaw('i.item_key as item_key, SUM(i.received_qty) as qty')
            ->groupBy('i.item_key')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $k = ItemBaseKey::key((string) $r->item_key);
            if ($k !== '') $out[$k] = ($out[$k] ?? 0) + (int) $r->qty;
        }
        return $out;
    }

    /** base key => Σ max(0, ordered − received) sa open POs (ordered|delivered). */
    private function incomingByKey(): array
    {
        if (!Schema::hasTable('supply_order_items') || !Schema::hasTable('supply_orders')) return [];
        $marks = implode(',', array_fill(0, count(self::OPEN_PO_STATUSES), '?'));
        $rows = DB::table('supply_order_items as i')
            ->join('supply_orders as o', 'o.id', '=', 'i.supply_order_id')
            ->whereIn('o.status', self::OPEN_PO_STATUSES)
            ->where('i.ordered_qty', '>', 0)
            ->where('i.unit_cost', '>=', 0)
            ->selectRaw(
                'i.item_key as item_key, SUM(CASE WHEN i.ordered_qty > COALESCE(i.received_qty, 0)
                    THEN i.ordered_qty - COALESCE(i.received_qty, 0) ELSE 0 END) as qty'
            )
            ->groupBy('i.item_key')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $k = ItemBaseKey::key((string) $r->item_key);
            if ($k !== '' && (int) $r->qty > 0) $out[$k] = ($out[$k] ?? 0) + (int) $r->qty;
        }
        return $out;
    }

    /** base key => ['lead','palugit_override','lifecycle_override']; pinakamababang id ang nananalo kapag doble. */
    private function settingsByKey(): array
    {
        if (!Schema::hasTable('supply_item_settings')) return [];
        $cols = ['item_name', 'lead_time_days'];
        foreach (['lifecycle_override', 'palugit_override'] as $c) {
            if (Schema::hasColumn('supply_item_settings', $c)) $cols[] = $c;
        }
        $out = [];
        foreach (DB::table('supply_item_settings')->orderBy('id')->get($cols) as $s) {
            $k = ItemBaseKey::key((string) $s->item_name);
            if ($k === '' || isset($out[$k])) continue;
            $out[$k] = [
                'lead'               => $s->lead_time_days !== null ? (int) $s->lead_time_days : self::DEFAULT_LEAD,
                'palugit_override'   => ($s->palugit_override ?? null) !== null ? (int) $s->palugit_override : null,
                'lifecycle_override' => $s->lifecycle_override ?? null,
            ];
        }
        return $out;
    }

    private function categoryByKey(): array
    {
        if (!Schema::hasTable('item_category_assignments') || !Schema::hasTable('item_categories')) return [];
        $out = [];
        $rows = DB::table('item_category_assignments as a')
            ->join('item_categories as c', 'c.id', '=', 'a.category_id')
            ->get(['a.item_key', 'c.id as category_id', 'c.name as category_name']);
        foreach ($rows as $r) $out[(string) $r->item_key] = ['id' => (int) $r->category_id, 'name' => $r->category_name];
        return $out;
    }

    private function categories(): array
    {
        if (!Schema::hasTable('item_categories')) return [];
        return DB::table('item_categories')->orderBy('sort_order')->orderBy('id')->get(['id', 'name'])
            ->map(fn ($c) => ['id' => (int) $c->id, 'name' => $c->name])->all();
    }

    private function stockStart(): ?string
    {
        if (!Schema::hasTable('app_settings')) return null;
        $v = DB::table('app_settings')->where('key', self::START_SETTING)->value('value');
        $v = is_string($v) ? trim($v) : '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4))) {
            return null;
        }
        return $v;
    }

    /**
     * lower(trim(raw ITEM_NAME)) => ['item_value', 'item_value_ceo'?] para sa mga raw name na may order sa range
     * at sa mga pangalan sa cogs (/cogs_ceo, CEO lang) na date <= $end.
     * Rule ng OwnerPrivateController (latest cogs row <= $end, by ItemAliasResolver::canonicalKey);
     * item_value_ceo (cogs_ceo, parehong rule, walang fallback) ay kasama LANG kung $withCeoValue.
     */
    private function values(string $start, string $end, bool $withCeoValue): array
    {
        $item  = $this->col('ITEM_NAME');
        $names = DB::table('macro_output as mo')
            ->whereBetween('mo.ts_date', [$start, $end])
            ->whereRaw("NULLIF(TRIM($item), '') IS NOT NULL")
            ->distinct()
            ->selectRaw("$item as item_name")
            ->pluck('item_name');

        // Isama rin ang mga pangalan sa cogs (page-only na item, walang order sa range) — parehong rows na binabasa.
        $aliases  = new ItemAliasResolver();
        $cogsSeen = [];
        $ceoSeen  = [];
        $cogs     = $this->latestCost('cogs', $end, $aliases, false, $cogsSeen);
        $ceo      = $withCeoValue ? $this->latestCost('cogs_ceo', $end, $aliases, true, $ceoSeen) : [];

        $out = [];
        foreach (array_merge($names->all(), $cogsSeen, $ceoSeen) as $raw) {
            $raw = (string) $raw;
            $key = mb_strtolower(trim($raw));
            if ($key === '') continue;
            $ck  = $aliases->canonicalKey($raw);
            $out[$key] = ['item_value' => $cogs[$ck] ?? null];
            if ($withCeoValue) $out[$key]['item_value_ceo'] = $ceo[$ck] ?? null;
        }
        return $out;
    }

    /** canonical key => unit_cost ng pinakabagong row na date <= $end (first-seen sa date DESC). */
    private function latestCost(string $table, string $end, ItemAliasResolver $aliases, bool $keepNull, array &$rawNames): array
    {
        if (!Schema::hasTable($table)) return [];
        $map = [];
        foreach (DB::table($table)->where('date', '<=', $end)->orderByDesc('date')->get(['item_name', 'unit_cost']) as $r) {
            $rawNames[mb_strtolower(trim((string) ($r->item_name ?? '')))] ??= (string) $r->item_name;
            $k = $aliases->canonicalKey((string) ($r->item_name ?? ''));
            if ($k === '' || array_key_exists($k, $map)) continue;
            $map[$k] = $r->unit_cost !== null ? (float) $r->unit_cost : ($keepNull ? null : 0.0);
        }
        return $map;
    }
}

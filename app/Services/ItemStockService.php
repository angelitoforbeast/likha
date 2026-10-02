<?php

namespace App\Services;

use App\Support\ItemBaseKey;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ItemStockService — stock gauge, DOI, order qty, category at item value para sa /item (GET /item/stock).
 *
 * Bawat input = isang grouped query; ang pagsasama sa base key (ItemBaseKey::key) ay sa PHP, sa
 * grouped rows lang. Spec: docs/specs/003-stock-doi-category.md.
 *
 *   stock_raw = natanggap mula START − umalis mula START;  stock = max(0, stock_raw)
 *   order_qty = max(0, ceil(hold + upd × (lead + safety) − incoming − stock))
 *   doi       = round((stock + incoming − hold) × days / units, 1)  — ito ang ginagamit sa kulay at order_by
 */
class ItemStockService
{
    private const DEFAULT_LEAD   = 7;
    private const DEFAULT_SAFETY = 3;
    private const DEMAND_DAYS    = 14;
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
            array_keys($received), array_keys($incoming)
        ));
        sort($keys);

        $endDay = Carbon::parse($end, 'Asia/Manila')->startOfDay();
        $items  = [];
        foreach ($keys as $k) {
            $holdUnits = (int) ($hold[$k]['units'] ?? 0);
            $inc       = (int) ($incoming[$k] ?? 0);
            $lead      = $settings[$k]['lead'] ?? self::DEFAULT_LEAD;
            $safety    = $settings[$k]['safety'] ?? self::DEFAULT_SAFETY;

            $upd  = 0.0;
            $days = 1;
            if (isset($demand[$k]) && $demand[$k]['units'] > 0) {
                $first = Carbon::parse($demand[$k]['first'], 'Asia/Manila')->startOfDay();
                $days  = max(1, min(self::DEMAND_DAYS, (int) $first->diffInDays($endDay) + 1));
                $upd   = $demand[$k]['units'] / $days;
            }

            $variants = array_keys(($hold[$k]['variants'] ?? []) + ($demand[$k]['variants'] ?? []));
            sort($variants);

            $row = [
                'name'          => $hold[$k]['name'] ?? $demand[$k]['name'] ?? $left['names'][$k] ?? $k,
                'variants'      => $variants,
                'hold_units'    => $holdUnits,
                'units_per_day' => round($upd, 2),
                'incoming'      => $inc,
                'stock_raw'     => null, 'stock' => null, 'stock_needs_count' => null,
                'lead'          => $lead,
                'safety'        => $safety,
                'doi'           => null, 'order_qty' => null, 'order_by' => null, 'colour' => null,
                'category_id'   => $category[$k]['id'] ?? null,
                'category'      => $category[$k]['name'] ?? null,
            ];

            if ($ready) {
                $raw   = (int) ($received[$k] ?? 0) - (int) ($left['units'][$k] ?? 0);
                $stock = max(0, $raw);
                $row['stock_raw']         = $raw;
                $row['stock']             = $stock;
                $row['stock_needs_count'] = $raw < 0;
                $row['order_qty'] = (int) max(0, ceil(round($holdUnits + $upd * ($lead + $safety) - $inc - $stock, 6)));

                if ($upd > 0) {
                    // Walang paghahati sa umuulit na float; ang naka-round na doi ang nagpapasya ng kulay at petsa.
                    $doi = round(($stock + $inc - $holdUnits) * $days / $demand[$k]['units'], 1);
                    $row['doi']      = $doi;
                    $row['order_by'] = $doi < $lead ? 'now' : $endDay->copy()->addDays((int) floor($doi - $lead))->toDateString();
                    $row['colour']   = $doi < $lead ? 'red' : ($doi < $lead + $safety ? 'amber' : 'green');
                }
            }
            $items[$k] = $row;
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
        $from = Carbon::parse($end, 'Asia/Manila')->subDays(self::DEMAND_DAYS - 1)->toDateString();
        $item = $this->col('ITEM_NAME');
        $rows = DB::table('macro_output as mo')
            ->whereBetween('mo.ts_date', [$from, $end])
            ->whereRaw($this->notCancelledSql(), HoldService::CANCELLED_STATUSES)
            ->selectRaw("$item as item_name, COUNT(*) as cnt, MIN(mo.ts_date) as first_date")
            ->groupByRaw($item)
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $raw = trim((string) ($r->item_name ?? ''));
            if ($raw === '') continue;
            $p = ItemBaseKey::parse($raw);
            if ($p['key'] === '') continue;
            $first = substr((string) $r->first_date, 0, 10);
            $d = $out[$p['key']] ??= ['name' => $p['base'], 'units' => 0, 'first' => $first, 'variants' => []];
            $d['units'] += (int) $r->cnt * $p['qty'];
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

    /** base key => ['lead','safety']; pinakamababang id ang nananalo kapag doble. */
    private function settingsByKey(): array
    {
        if (!Schema::hasTable('supply_item_settings')) return [];
        $out = [];
        foreach (DB::table('supply_item_settings')->orderBy('id')->get(['item_name', 'lead_time_days', 'safety_days']) as $s) {
            $k = ItemBaseKey::key((string) $s->item_name);
            if ($k === '' || isset($out[$k])) continue;
            $out[$k] = [
                'lead'   => $s->lead_time_days !== null ? (int) $s->lead_time_days : self::DEFAULT_LEAD,
                'safety' => $s->safety_days !== null ? (int) $s->safety_days : self::DEFAULT_SAFETY,
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
     * lower(trim(raw ITEM_NAME)) => ['item_value', 'item_value_ceo'?] para sa mga raw name na may order sa range.
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
        if ($names->isEmpty()) return [];

        $aliases = new ItemAliasResolver();
        $cogs    = $this->latestCost('cogs', $end, $aliases, false);
        $ceo     = $withCeoValue ? $this->latestCost('cogs_ceo', $end, $aliases, true) : [];

        $out = [];
        foreach ($names as $raw) {
            $raw = (string) $raw;
            $key = mb_strtolower(trim($raw));
            $ck  = $aliases->canonicalKey($raw);
            $out[$key] = ['item_value' => $cogs[$ck] ?? null];
            if ($withCeoValue) $out[$key]['item_value_ceo'] = $ceo[$ck] ?? null;
        }
        return $out;
    }

    /** canonical key => unit_cost ng pinakabagong row na date <= $end (first-seen sa date DESC). */
    private function latestCost(string $table, string $end, ItemAliasResolver $aliases, bool $keepNull): array
    {
        if (!Schema::hasTable($table)) return [];
        $map = [];
        foreach (DB::table($table)->where('date', '<=', $end)->orderByDesc('date')->get(['item_name', 'unit_cost']) as $r) {
            $k = $aliases->canonicalKey((string) ($r->item_name ?? ''));
            if ($k === '' || array_key_exists($k, $map)) continue;
            $map[$k] = $r->unit_cost !== null ? (float) $r->unit_cost : ($keepNull ? null : 0.0);
        }
        return $map;
    }
}

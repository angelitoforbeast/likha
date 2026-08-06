<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Carbon;
use App\Models\Supplier;
use App\Models\SupplyOrder;
use App\Models\SupplyOrderItem;
use App\Models\SupplyPayment;
use App\Models\SupplyPaymentReceipt;

/**
 * SupplyFinanceController — finance tracking para sa SUPPLY (mula sa supplier)
 * at PAYMENT (utang/bayad). Iisang flow:
 *
 *   ORDER → DELIVERED → COUNT (= stock-in + checking) → PAYMENT (partial, may resibo)
 *
 * Supplier balance (utang) = opening_balance + Σ(order totals) − Σ(payments).
 * opening_balance = existing utang bago pa ang system (na-input pagka-create).
 */
class SupplyFinanceController extends Controller
{
    // ───────────────────────── access control ─────────────────────────

    private function getNormalizedRole(): string
    {
        $raw  = Auth::user()?->employeeProfile?->role ?? '';
        $norm = preg_replace('/\s+/u', ' ', trim((string) $raw));
        if (preg_match('/^ceo$/iu', $norm)) return 'CEO';
        if (preg_match('/^marketing\s*[-–—]\s*oic$/iu', $norm)) return 'Marketing - OIC';
        if (preg_match('/^marketing$/iu', $norm)) return 'Marketing';
        return $norm;
    }

    /** View gate — CEO only (sensitive ang finance). */
    private function checkAccess(): void
    {
        if ($this->getNormalizedRole() !== 'CEO') abort(404);
    }

    /** Write gate — CEO only. */
    private function checkWriteAccess(): void
    {
        if ($this->getNormalizedRole() !== 'CEO') abort(403);
    }

    /** Normalize item label → key (links stock-in sa cogs/hold). */
    private function itemKey(string $name): string
    {
        $s = mb_strtolower(trim($name));
        return preg_replace('/\s+/u', ' ', $s) ?? $s;
    }

    /**
     * Distinct BASE item names mula sa macro_output (stripped ng "N x" prefix) —
     * para mag-suggest ang order item input, tugma sa /jnt/hold?group=item.
     */
    private function macroItemNames(): array
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('macro_output')) return [];
        try {
            $raw = DB::table('macro_output')->distinct()->pluck('ITEM_NAME');
        } catch (\Throwable $e) {
            return [];
        }
        $set = [];
        foreach ($raw as $name) {
            $name = trim((string) $name);
            if ($name === '') continue;
            // Linisin ang invalid UTF-8 — ang macro_output ay pwedeng may malformed
            // bytes na sumisira sa json_encode (= blangkong autocomplete) at sa /u regex.
            if (!mb_check_encoding($name, 'UTF-8')) {
                $name = mb_convert_encoding($name, 'UTF-8', 'UTF-8');
            }
            // Strip "N x" / "N ×" qty prefix → base item (same as /jnt/hold).
            if (preg_match('/^\s*(\d+)\s*[x×]\s*(.+)$/iu', $name, $m)) {
                $base = trim($m[2]);
            } else {
                $base = $name;
            }
            if ($base !== '') $set[$base] = true;
        }
        $names = array_keys($set);
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        return $names;
    }

    /** Per-supplier rollup: [supplier_id => ['ordered','delivered','paid','balance']]. */
    private function balanceMap(): array
    {
        // ALL orders (informational — kasama pa ang "ordered" na di pa dumating).
        $ordered = SupplyOrder::query()
            ->groupBy('supplier_id')
            ->selectRaw('supplier_id, COALESCE(SUM(total_cost),0) as t')
            ->pluck('t', 'supplier_id');

        // DELIVERED + COUNTED lang ang bumibilang sa UTANG (payable). Ang "ordered"
        // na status = di pa dumating, kaya HINDI pa utang.
        $delivered = SupplyOrder::query()
            ->whereIn('status', ['delivered', 'counted'])
            ->groupBy('supplier_id')
            ->selectRaw('supplier_id, COALESCE(SUM(total_cost),0) as t')
            ->pluck('t', 'supplier_id');

        $paid = SupplyPayment::query()
            ->groupBy('supplier_id')
            ->selectRaw('supplier_id, COALESCE(SUM(amount),0) as t')
            ->pluck('t', 'supplier_id');

        $map = [];
        foreach (Supplier::query()->get(['id', 'opening_balance']) as $s) {
            $open = (float) $s->opening_balance;
            $ord  = (float) ($ordered[$s->id] ?? 0);
            $del  = (float) ($delivered[$s->id] ?? 0);
            $pay  = (float) ($paid[$s->id] ?? 0);
            $map[$s->id] = [
                'opening'   => $open,
                'ordered'   => $ord,   // lahat ng order (pipeline)
                'delivered' => $del,   // counts to utang
                'paid'      => $pay,
                'balance'   => $open + $del - $pay,   // utang = opening + delivered − paid
            ];
        }
        return $map;
    }

    // ───────────────────────── dashboard ─────────────────────────

    /** GET /finance/supply — dashboard: lahat ng supplier + balances. */
    public function index(Request $request)
    {
        $this->checkAccess();

        $bal       = $this->balanceMap();
        $suppliers = Supplier::query()->orderBy('name')->get();

        $rows = $suppliers->map(function ($s) use ($bal) {
            $b = $bal[$s->id] ?? ['opening' => 0, 'ordered' => 0, 'delivered' => 0, 'paid' => 0, 'balance' => 0];
            return [
                'id'        => $s->id,
                'name'      => $s->name,
                'contact'   => $s->contact,
                'terms'     => $s->terms,
                'opening'   => $b['opening'],
                'ordered'   => $b['ordered'],
                'delivered' => $b['delivered'],
                'paid'      => $b['paid'],
                'balance'   => $b['balance'],
            ];
        });

        $totalPayable   = array_sum(array_column($bal, 'balance'));
        $totalOrdered   = array_sum(array_column($bal, 'ordered'));
        $totalDelivered = array_sum(array_column($bal, 'delivered'));
        $totalOpening   = array_sum(array_column($bal, 'opening'));

        // Paid this month (PH).
        $monthStart = Carbon::now('Asia/Manila')->startOfMonth()->toDateString();
        $monthEnd   = Carbon::now('Asia/Manila')->endOfMonth()->toDateString();
        $paidThisMonth = (float) SupplyPayment::query()
            ->whereBetween('paid_date', [$monthStart, $monthEnd])
            ->sum('amount');

        // Recent activity.
        $recentOrders = SupplyOrder::query()
            ->with('supplier:id,name')
            ->orderByDesc('order_date')->orderByDesc('id')
            ->limit(8)->get();

        $recentPayments = SupplyPayment::query()
            ->with('supplier:id,name')
            ->orderByDesc('paid_date')->orderByDesc('id')
            ->limit(8)->get();

        return view('finance.supply.index', [
            'rows'            => $rows,
            'totalPayable'    => $totalPayable,
            'totalOrdered'    => $totalOrdered,
            'totalDelivered'  => $totalDelivered,
            'totalOpening'    => $totalOpening,
            'paidThisMonth'   => $paidThisMonth,
            'recentOrders'    => $recentOrders,
            'recentPayments'  => $recentPayments,
            'canWrite'        => $this->getNormalizedRole() === 'CEO',
        ]);
    }

    /** GET /finance/supply/{supplier} — supplier detail: orders + payments + stock-in. */
    public function show(Request $request, int $supplier)
    {
        $this->checkAccess();

        $s = Supplier::query()->findOrFail($supplier);

        $orders = SupplyOrder::query()
            ->with('items')
            ->where('supplier_id', $s->id)
            ->orderByDesc('order_date')->orderByDesc('id')
            ->get();

        $payments = SupplyPayment::query()
            ->where('supplier_id', $s->id)
            ->with('receipts')
            ->orderByDesc('paid_date')->orderByDesc('id')
            ->get()
            ->map(function ($p) {
                // Multiple receipts → [{id, url}]. Fallback sa legacy receipt_path.
                $list = $p->receipts->map(fn ($r) => [
                    'id'  => $r->id,
                    'url' => Storage::disk('public')->url($r->path),
                ])->values();
                if ($list->isEmpty() && $p->receipt_path) {
                    $list = collect([['id' => 0, 'url' => Storage::disk('public')->url($p->receipt_path)]]);
                }
                $p->receipt_list = $list->all();
                return $p;
            });

        $bal = $this->balanceMap()[$s->id] ?? ['opening' => 0, 'ordered' => 0, 'delivered' => 0, 'paid' => 0, 'balance' => 0];

        // Stock-in per item (counted received_qty) for this supplier.
        $stockIn = SupplyOrderItem::query()
            ->whereIn('supply_order_id', $orders->pluck('id'))
            ->whereNotNull('received_qty')
            ->groupBy('item_key', 'item_name')
            ->selectRaw('item_key, MAX(item_name) as item_name, SUM(received_qty) as units, SUM(line_total) as cost')
            ->orderByDesc(DB::raw('SUM(received_qty)'))
            ->get();

        return view('finance.supply.show', [
            'supplier' => $s,
            'orders'   => $orders,
            'payments' => $payments,
            'bal'      => $bal,
            'stockIn'  => $stockIn,
            'canWrite' => $this->getNormalizedRole() === 'CEO',
            'itemNames' => $this->macroItemNames(),
        ]);
    }

    // ───────────────────────── suppliers ─────────────────────────

    public function storeSupplier(Request $request)
    {
        $this->checkWriteAccess();

        $data = $request->validate([
            'name'                 => ['required', 'string', 'max:191'],
            'contact'              => ['nullable', 'string', 'max:191'],
            'terms'                => ['nullable', 'string', 'max:191'],
            'opening_balance'      => ['nullable', 'numeric'],
            'opening_balance_note' => ['nullable', 'string', 'max:191'],
            'notes'                => ['nullable', 'string'],
        ]);

        $s = Supplier::create([
            'name'                 => trim($data['name']),
            'contact'              => $data['contact'] ?? null,
            'terms'                => $data['terms'] ?? null,
            'opening_balance'      => round((float) ($data['opening_balance'] ?? 0), 2),
            'opening_balance_note' => $data['opening_balance_note'] ?? null,
            'notes'                => $data['notes'] ?? null,
            'created_by'           => Auth::id(),
        ]);

        return redirect()->route('finance.supply.show', $s->id)
            ->with('success', "Supplier \"{$s->name}\" naidagdag.");
    }

    public function updateSupplier(Request $request, int $supplier)
    {
        $this->checkWriteAccess();
        $s = Supplier::query()->findOrFail($supplier);

        $data = $request->validate([
            'name'                 => ['required', 'string', 'max:191'],
            'contact'              => ['nullable', 'string', 'max:191'],
            'terms'                => ['nullable', 'string', 'max:191'],
            'opening_balance'      => ['nullable', 'numeric'],
            'opening_balance_note' => ['nullable', 'string', 'max:191'],
            'notes'                => ['nullable', 'string'],
        ]);

        $s->update([
            'name'                 => trim($data['name']),
            'contact'              => $data['contact'] ?? null,
            'terms'                => $data['terms'] ?? null,
            'opening_balance'      => round((float) ($data['opening_balance'] ?? 0), 2),
            'opening_balance_note' => $data['opening_balance_note'] ?? null,
            'notes'                => $data['notes'] ?? null,
        ]);

        return back()->with('success', 'Supplier na-update.');
    }

    // ───────────────────────── orders (PO) ─────────────────────────

    public function storeOrder(Request $request)
    {
        $this->checkWriteAccess();

        $data = $request->validate([
            'supplier_id'        => ['required', 'integer', 'exists:suppliers,id'],
            'order_date'         => ['required', 'date'],
            'order_no'           => ['nullable', 'string', 'max:191'],
            'expected_delivery'  => ['nullable', 'date'],
            'notes'              => ['nullable', 'string'],
            'items'              => ['required', 'array', 'min:1'],
            'items.*.item_name'  => ['required', 'string', 'max:191'],
            'items.*.ordered_qty' => ['required', 'integer', 'min:0'],
            // unit_cost pwedeng NEGATIVE — para sa discount line (hal. -500).
            'items.*.unit_cost'  => ['required', 'numeric'],
        ]);

        $order = DB::transaction(function () use ($data) {
            $order = SupplyOrder::create([
                'supplier_id'       => $data['supplier_id'],
                'order_no'          => $data['order_no'] ?? null,
                'order_date'        => $data['order_date'],
                'expected_delivery' => $data['expected_delivery'] ?? null,
                'status'            => 'ordered',
                'notes'             => $data['notes'] ?? null,
                'created_by'        => Auth::id(),
                'total_cost'        => 0,
            ]);

            $total = 0.0;
            foreach ($data['items'] as $it) {
                $qty  = (int) $it['ordered_qty'];
                $cost = round((float) $it['unit_cost'], 2);
                $line = round($qty * $cost, 2);
                $total += $line;

                SupplyOrderItem::create([
                    'supply_order_id' => $order->id,
                    'item_key'        => $this->itemKey($it['item_name']),
                    'item_name'       => trim($it['item_name']),
                    'ordered_qty'     => $qty,
                    'unit_cost'       => $cost,
                    'received_qty'    => null,
                    'line_total'      => $line,
                ]);
            }

            $order->update(['total_cost' => round($total, 2)]);
            return $order;
        });

        return redirect()->route('finance.supply.show', $order->supplier_id)
            ->with('success', "Order naidagdag (₱" . number_format($order->total_cost, 2) . ").");
    }

    /**
     * Edit an existing order (header + line items). Existing items na-update
     * IN PLACE (preserve received_qty); bagong rows ginagawa; tinanggal na rows
     * (remove_ids) dinedelete. Recompute ang total_cost.
     */
    public function updateOrder(Request $request, int $order)
    {
        $this->checkWriteAccess();
        $o = SupplyOrder::query()->with('items')->findOrFail($order);

        $data = $request->validate([
            'order_date'         => ['required', 'date'],
            'order_no'           => ['nullable', 'string', 'max:191'],
            'expected_delivery'  => ['nullable', 'date'],
            'notes'              => ['nullable', 'string'],
            'items'              => ['required', 'array', 'min:1'],
            'items.*.id'         => ['nullable'],
            'items.*.item_name'  => ['required', 'string', 'max:191'],
            'items.*.ordered_qty' => ['required', 'integer', 'min:0'],
            // unit_cost pwedeng NEGATIVE — para sa discount line (hal. -500).
            'items.*.unit_cost'  => ['required', 'numeric'],
            'remove_ids'         => ['nullable', 'array'],
            'remove_ids.*'       => ['integer'],
        ]);

        DB::transaction(function () use ($o, $data) {
            $existing  = $o->items->keyBy('id');
            $removeIds = array_map('intval', $data['remove_ids'] ?? []);

            if (!empty($removeIds)) {
                SupplyOrderItem::query()
                    ->where('supply_order_id', $o->id)
                    ->whereIn('id', $removeIds)
                    ->delete();
            }

            $total = 0.0;
            foreach ($data['items'] as $it) {
                $qty  = (int) $it['ordered_qty'];
                $cost = round((float) $it['unit_cost'], 2);
                $line = round($qty * $cost, 2);
                $total += $line;
                $id = (isset($it['id']) && $it['id'] !== null && $it['id'] !== '') ? (int) $it['id'] : null;

                if ($id !== null && $existing->has($id) && !in_array($id, $removeIds, true)) {
                    // Update in place — HUWAG galawin ang received_qty.
                    $existing->get($id)->update([
                        'item_key'    => $this->itemKey($it['item_name']),
                        'item_name'   => trim($it['item_name']),
                        'ordered_qty' => $qty,
                        'unit_cost'   => $cost,
                        'line_total'  => $line,
                    ]);
                } elseif ($id === null) {
                    SupplyOrderItem::create([
                        'supply_order_id' => $o->id,
                        'item_key'        => $this->itemKey($it['item_name']),
                        'item_name'       => trim($it['item_name']),
                        'ordered_qty'     => $qty,
                        'unit_cost'       => $cost,
                        'received_qty'    => null,
                        'line_total'      => $line,
                    ]);
                }
            }

            $o->update([
                'order_date'        => $data['order_date'],
                'order_no'          => $data['order_no'] ?? null,
                'expected_delivery' => $data['expected_delivery'] ?? null,
                'notes'             => $data['notes'] ?? null,
                'total_cost'        => round($total, 2),
            ]);
        });

        return back()->with('success', 'Order na-update.');
    }

    /** Mark an order delivered (di pa na-count). */
    public function markDelivered(Request $request, int $order)
    {
        $this->checkWriteAccess();
        $o = SupplyOrder::query()->findOrFail($order);

        if ($o->status === 'ordered') {
            $o->update(['status' => 'delivered', 'delivered_at' => now()]);
        }

        return back()->with('success', 'Na-mark na delivered.');
    }

    /** Save received counts (= STOCK-IN + checking). */
    public function saveCount(Request $request, int $order)
    {
        $this->checkWriteAccess();
        $o = SupplyOrder::query()->with('items')->findOrFail($order);

        $data = $request->validate([
            'received' => ['required', 'array'],
            'received.*' => ['nullable', 'integer', 'min:0'],
        ]);

        DB::transaction(function () use ($o, $data) {
            foreach ($o->items as $item) {
                if (array_key_exists($item->id, $data['received'])) {
                    $rcv = $data['received'][$item->id];
                    $item->update(['received_qty' => $rcv === null ? null : (int) $rcv]);
                }
            }
            $o->update([
                'status'       => 'counted',
                'counted_at'   => now(),
                'delivered_at' => $o->delivered_at ?? now(),
            ]);
        });

        return back()->with('success', 'Na-save ang bilang (stock-in). Checked vs ordered.');
    }

    public function deleteOrder(Request $request, int $order)
    {
        $this->checkWriteAccess();
        $o = SupplyOrder::query()->findOrFail($order);
        $sid = $o->supplier_id;
        $o->delete(); // cascade items
        return redirect()->route('finance.supply.show', $sid)->with('success', 'Order tinanggal.');
    }

    // ───────────────────────── payments ─────────────────────────

    public function storePayment(Request $request)
    {
        $this->checkWriteAccess();

        $data = $request->validate([
            'supplier_id'     => ['required', 'integer', 'exists:suppliers,id'],
            'supply_order_id' => ['nullable', 'integer', 'exists:supply_orders,id'],
            'amount'          => ['required', 'numeric', 'min:0.01'],
            'paid_date'       => ['required', 'date'],
            'method'          => ['nullable', 'string', 'max:30'],
            'reference_no'    => ['nullable', 'string', 'max:191'],
            'notes'           => ['nullable', 'string'],
            'receipts'        => ['nullable', 'array'],
            'receipts.*'      => ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
        ]);

        $p = SupplyPayment::create([
            'supplier_id'     => $data['supplier_id'],
            'supply_order_id' => $data['supply_order_id'] ?? null,
            'amount'          => round((float) $data['amount'], 2),
            'paid_date'       => $data['paid_date'],
            'method'          => $data['method'] ?? null,
            'reference_no'    => $data['reference_no'] ?? null,
            'notes'           => $data['notes'] ?? null,
            'paid_by'         => Auth::id(),
        ]);

        $this->storeReceiptFiles($request, $p->id);

        return redirect()->route('finance.supply.show', $data['supplier_id'])
            ->with('success', "Payment ₱" . number_format((float) $data['amount'], 2) . " naitala.");
    }

    /** Edit an existing payment (fields + add/remove receipts). CEO only. */
    public function updatePayment(Request $request, int $payment)
    {
        $this->checkWriteAccess();
        $p = SupplyPayment::query()->findOrFail($payment);

        $data = $request->validate([
            'amount'               => ['required', 'numeric', 'min:0.01'],
            'paid_date'            => ['required', 'date'],
            'method'               => ['nullable', 'string', 'max:30'],
            'reference_no'         => ['nullable', 'string', 'max:191'],
            'notes'                => ['nullable', 'string'],
            'receipts'             => ['nullable', 'array'],
            'receipts.*'           => ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
            'remove_receipt_ids'   => ['nullable', 'array'],
            'remove_receipt_ids.*' => ['integer'],
        ]);

        $p->update([
            'amount'       => round((float) $data['amount'], 2),
            'paid_date'    => $data['paid_date'],
            'method'       => $data['method'] ?? null,
            'reference_no' => $data['reference_no'] ?? null,
            'notes'        => $data['notes'] ?? null,
        ]);

        // Remove selected receipts (file + row).
        foreach ($data['remove_receipt_ids'] ?? [] as $rid) {
            $r = SupplyPaymentReceipt::query()
                ->where('supply_payment_id', $p->id)->where('id', (int) $rid)->first();
            if ($r) {
                try { Storage::disk('public')->delete($r->path); } catch (\Throwable $e) {}
                $r->delete();
            }
        }

        $this->storeReceiptFiles($request, $p->id);

        return redirect()->route('finance.supply.show', $p->supplier_id)
            ->with('success', 'Payment na-update.');
    }

    /** Store all uploaded/pasted receipt files (receipts[]) for a payment. */
    private function storeReceiptFiles(Request $request, int $paymentId): void
    {
        if (!$request->hasFile('receipts')) return;
        foreach ($request->file('receipts') as $file) {
            if (!$file) continue;
            $path = $file->store('supply_receipts', 'public');
            SupplyPaymentReceipt::create(['supply_payment_id' => $paymentId, 'path' => $path]);
        }
    }

    public function deletePayment(Request $request, int $payment)
    {
        $this->checkWriteAccess();
        $p = SupplyPayment::query()->with('receipts')->findOrFail($payment);
        $sid = $p->supplier_id;
        foreach ($p->receipts as $r) {
            try { Storage::disk('public')->delete($r->path); } catch (\Throwable $e) {}
        }
        if ($p->receipt_path) {
            try { Storage::disk('public')->delete($p->receipt_path); } catch (\Throwable $e) {}
        }
        $p->delete(); // cascade receipts rows
        return redirect()->route('finance.supply.show', $sid)->with('success', 'Payment tinanggal.');
    }
}

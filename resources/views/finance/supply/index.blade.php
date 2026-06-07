<x-layout>
  <x-slot name="title">Supply Finance</x-slot>
  <x-slot name="heading">Supply Finance — Suppliers &amp; Payments</x-slot>

  <div class="max-w-6xl mx-auto p-4" x-data="{ showAddSupplier: false }">

    @if (session('success'))
      <div class="mb-3 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-green-800">{{ session('success') }}</div>
    @endif
    @if (session('error'))
      <div class="mb-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-red-800">{{ session('error') }}</div>
    @endif
    @if ($errors->any())
      <div class="mb-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-red-800 text-sm">
        <ul class="list-disc list-inside">
          @foreach ($errors->all() as $e) <li>{{ $e }}</li> @endforeach
        </ul>
      </div>
    @endif

    {{-- ── Summary cards ───────────────────────────────────────────── --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
      <div class="rounded-xl border border-red-200 bg-red-50 p-4">
        <div class="text-[11px] font-semibold uppercase tracking-wide text-red-700">Total Payable (Utang)</div>
        <div class="mt-1 text-2xl font-bold text-red-700">₱{{ number_format($totalPayable, 2) }}</div>
      </div>
      <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4">
        <div class="text-[11px] font-semibold uppercase tracking-wide text-emerald-700">Paid This Month</div>
        <div class="mt-1 text-2xl font-bold text-emerald-700">₱{{ number_format($paidThisMonth, 2) }}</div>
      </div>
      <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
        <div class="text-[11px] font-semibold uppercase tracking-wide text-slate-600">Total Ordered</div>
        <div class="mt-1 text-2xl font-bold text-slate-700">₱{{ number_format($totalOrdered, 2) }}</div>
        <div class="text-[10px] text-slate-400">delivered (utang basis): ₱{{ number_format($totalDelivered, 2) }}</div>
      </div>
      <div class="rounded-xl border border-amber-200 bg-amber-50 p-4">
        <div class="text-[11px] font-semibold uppercase tracking-wide text-amber-700">Opening Balances</div>
        <div class="mt-1 text-2xl font-bold text-amber-700">₱{{ number_format($totalOpening, 2) }}</div>
      </div>
    </div>

    {{-- ── Suppliers table ─────────────────────────────────────────── --}}
    <div class="mb-3 flex items-center justify-between gap-2">
      <h2 class="text-lg font-semibold text-slate-800">Suppliers</h2>
      @if ($canWrite)
        <button @click="showAddSupplier = !showAddSupplier"
                class="inline-flex items-center rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
          + Add Supplier
        </button>
      @endif
    </div>

    {{-- Add supplier form (collapsible) --}}
    @if ($canWrite)
      <div x-show="showAddSupplier" x-cloak x-transition class="mb-4 rounded-xl border border-indigo-200 bg-indigo-50 p-4">
        <form method="POST" action="{{ route('finance.supply.suppliers.store') }}" class="grid grid-cols-1 md:grid-cols-3 gap-3">
          @csrf
          <div>
            <label class="block text-[11px] font-semibold text-indigo-900 mb-1">Supplier name *</label>
            <input name="name" required class="w-full border border-indigo-300 rounded px-3 py-2 text-sm bg-white">
          </div>
          <div>
            <label class="block text-[11px] font-semibold text-indigo-900 mb-1">Contact</label>
            <input name="contact" class="w-full border border-indigo-300 rounded px-3 py-2 text-sm bg-white">
          </div>
          <div>
            <label class="block text-[11px] font-semibold text-indigo-900 mb-1">Terms (e.g. 7 days)</label>
            <input name="terms" class="w-full border border-indigo-300 rounded px-3 py-2 text-sm bg-white">
          </div>
          <div>
            <label class="block text-[11px] font-semibold text-indigo-900 mb-1">Existing utang (opening balance)</label>
            <input name="opening_balance" type="number" step="0.01" value="0"
                   class="w-full border border-indigo-300 rounded px-3 py-2 text-sm bg-white">
          </div>
          <div class="md:col-span-2">
            <label class="block text-[11px] font-semibold text-indigo-900 mb-1">Opening balance note (optional)</label>
            <input name="opening_balance_note" placeholder="hal. as of Jun 1, 2026"
                   class="w-full border border-indigo-300 rounded px-3 py-2 text-sm bg-white">
          </div>
          <div class="md:col-span-3 flex justify-end">
            <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Save supplier</button>
          </div>
        </form>
      </div>
    @endif

    <div class="overflow-x-auto rounded-xl border border-slate-200">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-100 text-slate-600">
          <tr>
            <th class="px-3 py-2 text-left font-semibold">Supplier</th>
            <th class="px-3 py-2 text-left font-semibold">Contact</th>
            <th class="px-3 py-2 text-left font-semibold">Terms</th>
            <th class="px-3 py-2 text-right font-semibold">Opening</th>
            <th class="px-3 py-2 text-right font-semibold">Ordered</th>
            <th class="px-3 py-2 text-right font-semibold">Delivered</th>
            <th class="px-3 py-2 text-right font-semibold">Paid</th>
            <th class="px-3 py-2 text-right font-semibold">Balance</th>
            <th class="px-3 py-2"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          @forelse ($rows as $r)
            <tr class="hover:bg-slate-50">
              <td class="px-3 py-2 font-medium text-slate-800">
                <a href="{{ route('finance.supply.show', $r['id']) }}" class="text-indigo-600 hover:underline">{{ $r['name'] }}</a>
              </td>
              <td class="px-3 py-2 text-slate-500">{{ $r['contact'] ?: '—' }}</td>
              <td class="px-3 py-2 text-slate-500">{{ $r['terms'] ?: '—' }}</td>
              <td class="px-3 py-2 text-right text-slate-500">₱{{ number_format($r['opening'], 2) }}</td>
              <td class="px-3 py-2 text-right text-slate-400">₱{{ number_format($r['ordered'], 2) }}</td>
              <td class="px-3 py-2 text-right text-slate-700">₱{{ number_format($r['delivered'], 2) }}</td>
              <td class="px-3 py-2 text-right text-emerald-700">₱{{ number_format($r['paid'], 2) }}</td>
              <td class="px-3 py-2 text-right font-bold {{ $r['balance'] > 0 ? 'text-red-600' : 'text-slate-400' }}">
                ₱{{ number_format($r['balance'], 2) }}
              </td>
              <td class="px-3 py-2 text-right">
                <a href="{{ route('finance.supply.show', $r['id']) }}" class="text-xs text-indigo-600 hover:underline">View →</a>
              </td>
            </tr>
          @empty
            <tr><td colspan="9" class="px-3 py-8 text-center text-slate-400">Wala pang supplier. Mag-add muna sa taas.</td></tr>
          @endforelse
        </tbody>
        @if ($rows->count())
          <tfoot class="bg-slate-50 font-semibold text-slate-700">
            <tr>
              <td class="px-3 py-2" colspan="3">TOTAL</td>
              <td class="px-3 py-2 text-right">₱{{ number_format($totalOpening, 2) }}</td>
              <td class="px-3 py-2 text-right text-slate-400">₱{{ number_format($totalOrdered, 2) }}</td>
              <td class="px-3 py-2 text-right">₱{{ number_format($totalDelivered, 2) }}</td>
              <td class="px-3 py-2 text-right text-emerald-700">₱{{ number_format($rows->sum('paid'), 2) }}</td>
              <td class="px-3 py-2 text-right text-red-600">₱{{ number_format($totalPayable, 2) }}</td>
              <td></td>
            </tr>
          </tfoot>
        @endif
      </table>
    </div>

    {{-- ── Recent activity ─────────────────────────────────────────── --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-6">
      <div class="rounded-xl border border-slate-200 p-4">
        <h3 class="text-sm font-semibold text-slate-700 mb-2">Recent Orders</h3>
        <ul class="divide-y divide-slate-100 text-sm">
          @forelse ($recentOrders as $o)
            <li class="py-2 flex items-center justify-between">
              <span>
                <a href="{{ route('finance.supply.show', $o->supplier_id) }}" class="text-indigo-600 hover:underline">{{ $o->supplier->name ?? '—' }}</a>
                <span class="text-slate-400">· {{ \Illuminate\Support\Carbon::parse($o->order_date)->format('M j, Y') }}</span>
              </span>
              <span class="flex items-center gap-2">
                <span class="text-[10px] uppercase font-semibold px-2 py-0.5 rounded-full
                  {{ $o->status === 'counted' ? 'bg-emerald-100 text-emerald-700' : ($o->status === 'delivered' ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-600') }}">
                  {{ $o->status }}
                </span>
                <span class="font-semibold text-slate-700">₱{{ number_format($o->total_cost, 2) }}</span>
              </span>
            </li>
          @empty
            <li class="py-3 text-slate-400">Wala pa.</li>
          @endforelse
        </ul>
      </div>
      <div class="rounded-xl border border-slate-200 p-4">
        <h3 class="text-sm font-semibold text-slate-700 mb-2">Recent Payments</h3>
        <ul class="divide-y divide-slate-100 text-sm">
          @forelse ($recentPayments as $p)
            <li class="py-2 flex items-center justify-between">
              <span>
                <a href="{{ route('finance.supply.show', $p->supplier_id) }}" class="text-indigo-600 hover:underline">{{ $p->supplier->name ?? '—' }}</a>
                <span class="text-slate-400">· {{ \Illuminate\Support\Carbon::parse($p->paid_date)->format('M j, Y') }}</span>
                @if ($p->method)<span class="text-[10px] uppercase text-slate-400">{{ $p->method }}</span>@endif
              </span>
              <span class="font-semibold text-emerald-700">₱{{ number_format($p->amount, 2) }}</span>
            </li>
          @empty
            <li class="py-3 text-slate-400">Wala pa.</li>
          @endforelse
        </ul>
      </div>
    </div>

  </div>
</x-layout>

<x-layout>
  <x-slot name="title">{{ $supplier->name }} — Supply Finance</x-slot>
  <x-slot name="heading">{{ $supplier->name }}</x-slot>

  {{-- Number inputs na walang up/down spinner — free-text itsura, numero lang. --}}
  <style>
    .no-spin::-webkit-outer-spin-button,
    .no-spin::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
    .no-spin { -moz-appearance: textfield; appearance: textfield; }
  </style>

  {{-- Item-name suggestions (macro_output base). Simpleng native datalist —
       server-rendered options, gumagana sa lahat ng item input (create + edit).
       Sanitized na ang $itemNames (valid UTF-8) sa controller. --}}
  <datalist id="moItemNames">
    @foreach ($itemNames as $nm)
      <option value="{{ $nm }}"></option>
    @endforeach
  </datalist>

  <div class="max-w-6xl mx-auto p-4"
       x-data="{
         showEdit: false,
         showAddOrder: false,
         showAddPayment: false,
         items: [{ item_name: '', ordered_qty: 1, unit_cost: 0 }],
         addRow()    { this.items.push({ item_name:'', ordered_qty:1, unit_cost:0 }); },
         removeRow(i){ if (this.items.length > 1) this.items.splice(i,1); },
         get grandTotal(){ return this.items.reduce((s,it)=> s + (Number(it.ordered_qty)||0)*(Number(it.unit_cost)||0), 0); },
         peso(n){ return '₱' + Number(n||0).toLocaleString('en-PH',{minimumFractionDigits:2, maximumFractionDigits:2}); },
         allItemNames: (window.__supplyItemNames || []),
         suggestKey: null,
         itemMatches(q){
           q = String(q || '').trim().toLowerCase();
           if (q.length < 2) return [];   // mag-react lang kapag may 2+ letters
           return this.allItemNames.filter(n => n.toLowerCase().includes(q)).slice(0, 12);
         }
       }">

    <div class="mb-3">
      <a href="{{ route('finance.supply.index') }}" class="text-sm text-indigo-600 hover:underline">← Back to all suppliers</a>
    </div>

    @if (session('success'))
      <div class="mb-3 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-green-800">{{ session('success') }}</div>
    @endif
    @if (session('error'))
      <div class="mb-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-red-800">{{ session('error') }}</div>
    @endif
    @if ($errors->any())
      <div class="mb-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-red-800 text-sm">
        <ul class="list-disc list-inside">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
      </div>
    @endif

    {{-- ── Balance summary ─────────────────────────────────────────── --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-2">
      <div class="rounded-xl border border-amber-200 bg-amber-50 p-3">
        <div class="text-[11px] font-semibold uppercase text-amber-700">Opening Utang</div>
        <div class="mt-1 text-xl font-bold text-amber-700">₱{{ number_format($bal['opening'], 2) }}</div>
        @if ($supplier->opening_balance_note)<div class="text-[10px] text-amber-600">{{ $supplier->opening_balance_note }}</div>@endif
      </div>
      <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
        <div class="text-[11px] font-semibold uppercase text-slate-600">Delivered (utang basis)</div>
        <div class="mt-1 text-xl font-bold text-slate-700">₱{{ number_format($bal['delivered'], 2) }}</div>
        <div class="text-[10px] text-slate-400">ordered (lahat): ₱{{ number_format($bal['ordered'], 2) }}</div>
      </div>
      <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-3">
        <div class="text-[11px] font-semibold uppercase text-emerald-700">Total Paid</div>
        <div class="mt-1 text-xl font-bold text-emerald-700">₱{{ number_format($bal['paid'], 2) }}</div>
      </div>
      <div class="rounded-xl border border-red-200 bg-red-50 p-3">
        <div class="text-[11px] font-semibold uppercase text-red-700">Balance (Utang)</div>
        <div class="mt-1 text-xl font-bold text-red-700">₱{{ number_format($bal['balance'], 2) }}</div>
      </div>
    </div>
    <div class="text-[11px] text-slate-400 mb-4">
      Balance = Opening (₱{{ number_format($bal['opening'],2) }}) + Delivered (₱{{ number_format($bal['delivered'],2) }}) − Paid (₱{{ number_format($bal['paid'],2) }})
      · <span class="text-slate-400">Orders na "ordered" pa lang (di pa dumating) = HINDI pa kasama sa utang.</span>
    </div>

    {{-- Supplier meta + edit --}}
    <div class="mb-5 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-slate-500">
      <span>📞 {{ $supplier->contact ?: '—' }}</span>
      <span>🗓 Terms: {{ $supplier->terms ?: '—' }}</span>
      @if ($canWrite)
        <button @click="showEdit = !showEdit" class="text-indigo-600 hover:underline text-xs">edit supplier</button>
      @endif
    </div>
    @if ($canWrite)
      <div x-show="showEdit" x-cloak x-transition class="mb-5 rounded-xl border border-indigo-200 bg-indigo-50 p-4">
        <form method="POST" action="{{ route('finance.supply.suppliers.update', $supplier->id) }}" class="grid grid-cols-1 md:grid-cols-3 gap-3">
          @csrf @method('PUT')
          <div><label class="block text-[11px] font-semibold text-indigo-900 mb-1">Name *</label>
            <input name="name" value="{{ $supplier->name }}" required class="w-full border border-indigo-300 rounded px-3 py-2 text-sm bg-white"></div>
          <div><label class="block text-[11px] font-semibold text-indigo-900 mb-1">Contact</label>
            <input name="contact" value="{{ $supplier->contact }}" class="w-full border border-indigo-300 rounded px-3 py-2 text-sm bg-white"></div>
          <div><label class="block text-[11px] font-semibold text-indigo-900 mb-1">Terms</label>
            <input name="terms" value="{{ $supplier->terms }}" class="w-full border border-indigo-300 rounded px-3 py-2 text-sm bg-white"></div>
          <div><label class="block text-[11px] font-semibold text-indigo-900 mb-1">Opening balance (existing utang)</label>
            <input name="opening_balance" type="number" step="0.01" value="{{ $supplier->opening_balance }}" class="w-full border border-indigo-300 rounded px-3 py-2 text-sm bg-white"></div>
          <div class="md:col-span-2"><label class="block text-[11px] font-semibold text-indigo-900 mb-1">Opening note</label>
            <input name="opening_balance_note" value="{{ $supplier->opening_balance_note }}" class="w-full border border-indigo-300 rounded px-3 py-2 text-sm bg-white"></div>
          <div class="md:col-span-3 flex justify-end"><button class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Save</button></div>
        </form>
      </div>
    @endif

    {{-- ── ORDERS + PAYMENTS two-column ─────────────────────────────── --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

      {{-- LEFT: orders (2/3) --}}
      <div class="lg:col-span-2">
        <div class="mb-2 flex items-center justify-between">
          <h2 class="text-lg font-semibold text-slate-800">Supply Orders</h2>
          @if ($canWrite)
            <button @click="showAddOrder = !showAddOrder"
                    class="inline-flex items-center rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700">+ New Order</button>
          @endif
        </div>

        {{-- Add order form --}}
        @if ($canWrite)
          <div x-show="showAddOrder" x-cloak x-transition class="mb-4 rounded-xl border border-indigo-200 bg-indigo-50 p-4">
            <form method="POST" action="{{ route('finance.supply.orders.store') }}">
              @csrf
              <input type="hidden" name="supplier_id" value="{{ $supplier->id }}">
              <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-3">
                <div><label class="block text-[11px] font-semibold text-indigo-900 mb-1">Order date *</label>
                  <input name="order_date" type="date" required value="{{ \Illuminate\Support\Carbon::now('Asia/Manila')->toDateString() }}" class="w-full border border-indigo-300 rounded px-3 py-2 text-sm bg-white"></div>
                <div><label class="block text-[11px] font-semibold text-indigo-900 mb-1">PO / ref no.</label>
                  <input name="order_no" class="w-full border border-indigo-300 rounded px-3 py-2 text-sm bg-white"></div>
                <div><label class="block text-[11px] font-semibold text-indigo-900 mb-1">Expected delivery</label>
                  <input name="expected_delivery" type="date" class="w-full border border-indigo-300 rounded px-3 py-2 text-sm bg-white"></div>
              </div>

              {{-- dynamic item rows --}}
              <div class="rounded-lg border border-indigo-200 bg-white p-2 mb-3">
                <div class="grid grid-cols-12 gap-2 px-1 pb-1 text-[10px] font-semibold uppercase text-slate-400">
                  <div class="col-span-6">Item</div>
                  <div class="col-span-2 text-right">Qty</div>
                  <div class="col-span-3 text-right">Unit Cost</div>
                  <div class="col-span-1"></div>
                </div>
                <template x-for="(it, idx) in items" :key="idx">
                  <div class="grid grid-cols-12 gap-2 mb-1 items-center">
                    <div class="col-span-6">
                      <input :name="`items[${idx}][item_name]`" x-model="it.item_name" placeholder="Item name" required autocomplete="off"
                             list="moItemNames"
                             class="w-full border border-slate-300 rounded px-2 py-1.5 text-sm">
                    </div>
                    <input :name="`items[${idx}][ordered_qty]`" x-model.number="it.ordered_qty" type="number" min="0" required
                           inputmode="numeric"
                           class="no-spin col-span-2 border border-slate-300 rounded px-2 py-1.5 text-sm text-right">
                    <input :name="`items[${idx}][unit_cost]`" x-model.number="it.unit_cost" type="number" step="0.01" min="0" required
                           inputmode="decimal"
                           class="no-spin col-span-3 border border-slate-300 rounded px-2 py-1.5 text-sm text-right">
                    <button type="button" @click="removeRow(idx)" class="col-span-1 text-red-500 hover:text-red-700 text-lg leading-none">×</button>
                  </div>
                </template>
                <div class="flex items-center justify-between mt-2 pt-2 border-t border-slate-100">
                  <button type="button" @click="addRow()" class="text-xs font-semibold text-indigo-600 hover:underline">+ add item</button>
                  <div class="text-sm font-bold text-slate-700">Total: <span x-text="peso(grandTotal)"></span></div>
                </div>
              </div>

              <div class="mb-3">
                <label class="block text-[11px] font-semibold text-indigo-900 mb-1">Notes</label>
                <input name="notes" class="w-full border border-indigo-300 rounded px-3 py-2 text-sm bg-white">
              </div>
              <div class="flex justify-end"><button class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Save order</button></div>
            </form>
          </div>
        @endif

        {{-- order cards --}}
        <div class="space-y-3">
          @forelse ($orders as $o)
            @php
              $eSeed = $o->items->map(fn ($it) => [
                'id'          => $it->id,
                'item_name'   => $it->item_name,
                'ordered_qty' => (int) $it->ordered_qty,
                'unit_cost'   => (float) $it->unit_cost,
              ])->values();
            @endphp
            <div class="rounded-xl border border-slate-200 p-4"
                 x-data="{
                   countOpen: false,
                   editOpen: false,
                   eItems: @js($eSeed),
                   eRemoved: [],
                   eAdd(){ this.eItems.push({id:null,item_name:'',ordered_qty:1,unit_cost:0}); },
                   eRemove(i){ if(this.eItems[i].id) this.eRemoved.push(this.eItems[i].id); this.eItems.splice(i,1); },
                   get eTotal(){ return this.eItems.reduce((s,it)=> s+(Number(it.ordered_qty)||0)*(Number(it.unit_cost)||0), 0); }
                 }">
              <div class="flex items-start justify-between mb-2">
                <div>
                  <div class="font-semibold text-slate-800">
                    {{ \Illuminate\Support\Carbon::parse($o->order_date)->format('M j, Y') }}
                    @if ($o->order_no)<span class="text-slate-400 text-sm">· {{ $o->order_no }}</span>@endif
                  </div>
                  @if ($o->expected_delivery)
                    <div class="text-[11px] text-slate-400">expected: {{ \Illuminate\Support\Carbon::parse($o->expected_delivery)->format('M j, Y') }}</div>
                  @endif
                </div>
                <div class="flex items-center gap-2">
                  <span class="text-[10px] uppercase font-semibold px-2 py-0.5 rounded-full
                    {{ $o->status === 'counted' ? 'bg-emerald-100 text-emerald-700' : ($o->status === 'delivered' ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-600') }}">
                    {{ $o->status }}
                  </span>
                  <span class="font-bold text-slate-700">₱{{ number_format($o->total_cost, 2) }}</span>
                </div>
              </div>

              {{-- items --}}
              <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                  <thead class="text-[10px] uppercase text-slate-400">
                    <tr>
                      <th class="py-1 text-left font-semibold">Item</th>
                      <th class="py-1 text-right font-semibold">Ordered</th>
                      <th class="py-1 text-right font-semibold">Unit Cost</th>
                      <th class="py-1 text-right font-semibold">Subtotal</th>
                      <th class="py-1 text-right font-semibold">Received</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-50">
                    @foreach ($o->items as $it)
                      <tr>
                        <td class="py-1 text-slate-700">{{ $it->item_name }}</td>
                        <td class="py-1 text-right text-slate-600">{{ $it->ordered_qty }}</td>
                        <td class="py-1 text-right text-slate-500">₱{{ number_format($it->unit_cost, 2) }}</td>
                        <td class="py-1 text-right text-slate-700">₱{{ number_format($it->line_total, 2) }}</td>
                        <td class="py-1 text-right font-semibold
                          {{ is_null($it->received_qty) ? 'text-slate-300' : ((int)$it->received_qty === (int)$it->ordered_qty ? 'text-emerald-600' : 'text-red-600') }}">
                          @if (is_null($it->received_qty))
                            —
                          @else
                            {{ $it->received_qty }}
                            @if ((int)$it->received_qty !== (int)$it->ordered_qty)
                              <span class="text-[10px]">({{ ($it->received_qty - $it->ordered_qty) > 0 ? '+' : '' }}{{ $it->received_qty - $it->ordered_qty }})</span>
                            @endif
                          @endif
                        </td>
                      </tr>
                    @endforeach
                  </tbody>
                </table>
              </div>

              @if ($o->notes)<div class="mt-2 text-xs text-slate-400">📝 {{ $o->notes }}</div>@endif

              {{-- lifecycle actions --}}
              @if ($canWrite)
                <div class="mt-3 flex flex-wrap items-center gap-2 pt-2 border-t border-slate-100">
                  @if ($o->status === 'ordered')
                    <form method="POST" action="{{ route('finance.supply.orders.deliver', $o->id) }}">
                      @csrf
                      <button class="rounded-md bg-amber-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-600">Mark Delivered</button>
                    </form>
                  @endif
                  @if (in_array($o->status, ['ordered','delivered','counted'], true))
                    <button @click="countOpen = !countOpen"
                            class="rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700">
                      {{ $o->status === 'counted' ? 'Re-count / Edit' : 'Count / Receive (stock-in)' }}
                    </button>
                  @endif
                  <button @click="editOpen = !editOpen"
                          class="rounded-md bg-slate-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-700">Edit order</button>
                  <form method="POST" action="{{ route('finance.supply.orders.delete', $o->id) }}" onsubmit="return confirm('Burahin ang order na ito?')" class="ml-auto">
                    @csrf @method('DELETE')
                    <button class="text-xs text-red-500 hover:underline">delete</button>
                  </form>
                </div>

                {{-- count form --}}
                <div x-show="countOpen" x-cloak x-transition class="mt-3 rounded-lg border border-emerald-200 bg-emerald-50 p-3">
                  <div class="text-[11px] font-semibold text-emerald-900 mb-2">Ilagay ang AKTWAL na natanggap (checking + stock-in):</div>
                  <form method="POST" action="{{ route('finance.supply.orders.count', $o->id) }}">
                    @csrf
                    <div class="space-y-1">
                      @foreach ($o->items as $it)
                        <div class="grid grid-cols-12 gap-2 items-center text-sm">
                          <span class="col-span-6 text-slate-700">{{ $it->item_name }}</span>
                          <span class="col-span-3 text-right text-[11px] text-slate-400">ordered: {{ $it->ordered_qty }}</span>
                          <input type="number" min="0" name="received[{{ $it->id }}]"
                                 value="{{ $it->received_qty ?? $it->ordered_qty }}"
                                 class="col-span-3 border border-emerald-300 rounded px-2 py-1.5 text-sm text-right bg-white">
                        </div>
                      @endforeach
                    </div>
                    <div class="flex justify-end mt-3">
                      <button class="rounded-md bg-emerald-600 px-4 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700">Save count (stock-in)</button>
                    </div>
                  </form>
                </div>

                {{-- edit-order form (header + items; preserve received counts) --}}
                <div x-show="editOpen" x-cloak x-transition class="mt-3 rounded-lg border border-slate-300 bg-slate-50 p-3">
                  <div class="text-[11px] font-semibold text-slate-600 mb-2">I-edit ang order (kung mali ang na-order/na-deliver na bilang o presyo):</div>
                  <form method="POST" action="{{ route('finance.supply.orders.update', $o->id) }}">
                    @csrf @method('PUT')
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-2 mb-2">
                      <div><label class="block text-[10px] font-semibold text-slate-500 mb-1">Order date *</label>
                        <input name="order_date" type="date" required value="{{ \Illuminate\Support\Carbon::parse($o->order_date)->toDateString() }}" class="w-full border border-slate-300 rounded px-2 py-1.5 text-sm bg-white"></div>
                      <div><label class="block text-[10px] font-semibold text-slate-500 mb-1">PO / ref</label>
                        <input name="order_no" value="{{ $o->order_no }}" class="w-full border border-slate-300 rounded px-2 py-1.5 text-sm bg-white"></div>
                      <div><label class="block text-[10px] font-semibold text-slate-500 mb-1">Expected delivery</label>
                        <input name="expected_delivery" type="date" value="{{ $o->expected_delivery ? \Illuminate\Support\Carbon::parse($o->expected_delivery)->toDateString() : '' }}" class="w-full border border-slate-300 rounded px-2 py-1.5 text-sm bg-white"></div>
                    </div>

                    <div class="rounded-lg border border-slate-200 bg-white p-2 mb-2">
                      <div class="grid grid-cols-12 gap-2 px-1 pb-1 text-[10px] font-semibold uppercase text-slate-400">
                        <div class="col-span-6">Item</div><div class="col-span-2 text-right">Qty</div><div class="col-span-3 text-right">Unit Cost</div><div class="col-span-1"></div>
                      </div>
                      <template x-for="(it, idx) in eItems" :key="idx">
                        <div class="grid grid-cols-12 gap-2 mb-1 items-center">
                          <input type="hidden" :name="`items[${idx}][id]`" :value="it.id ?? ''">
                          <div class="col-span-6">
                            <input :name="`items[${idx}][item_name]`" x-model="it.item_name" placeholder="Item name" required autocomplete="off"
                                   list="moItemNames"
                                   class="w-full border border-slate-300 rounded px-2 py-1.5 text-sm">
                          </div>
                          <input :name="`items[${idx}][ordered_qty]`" x-model.number="it.ordered_qty" type="number" min="0" required inputmode="numeric"
                                 class="no-spin col-span-2 border border-slate-300 rounded px-2 py-1.5 text-sm text-right">
                          <input :name="`items[${idx}][unit_cost]`" x-model.number="it.unit_cost" type="number" step="0.01" min="0" required inputmode="decimal"
                                 class="no-spin col-span-3 border border-slate-300 rounded px-2 py-1.5 text-sm text-right">
                          <button type="button" @click="eRemove(idx)" class="col-span-1 text-red-500 hover:text-red-700 text-lg leading-none">×</button>
                        </div>
                      </template>
                      <div class="flex items-center justify-between mt-2 pt-2 border-t border-slate-100">
                        <button type="button" @click="eAdd()" class="text-xs font-semibold text-indigo-600 hover:underline">+ add item</button>
                        <div class="text-sm font-bold text-slate-700">Total: <span x-text="peso(eTotal)"></span></div>
                      </div>
                    </div>

                    <template x-for="rid in eRemoved" :key="rid"><input type="hidden" name="remove_ids[]" :value="rid"></template>

                    <div class="mb-2"><label class="block text-[10px] font-semibold text-slate-500 mb-1">Notes</label>
                      <input name="notes" value="{{ $o->notes }}" class="w-full border border-slate-300 rounded px-2 py-1.5 text-sm bg-white"></div>
                    <div class="flex justify-end gap-3 items-center">
                      <button type="button" @click="editOpen=false" class="text-xs text-slate-500 hover:underline">cancel</button>
                      <button class="rounded-md bg-slate-700 px-4 py-1.5 text-xs font-semibold text-white hover:bg-slate-800">Save changes</button>
                    </div>
                  </form>
                </div>
              @endif
            </div>
          @empty
            <div class="rounded-xl border border-dashed border-slate-200 p-8 text-center text-slate-400">Wala pang order.</div>
          @endforelse
        </div>
      </div>

      {{-- RIGHT: payments (1/3) --}}
      <div class="lg:col-span-1">
        <div class="mb-2 flex items-center justify-between">
          <h2 class="text-lg font-semibold text-slate-800">Payments</h2>
          @if ($canWrite)
            <button @click="showAddPayment = !showAddPayment"
                    class="inline-flex items-center rounded-lg bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-700">+ Pay</button>
          @endif
        </div>

        @if ($canWrite)
          <div x-show="showAddPayment" x-cloak x-transition class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 p-4">
            <form method="POST" action="{{ route('finance.supply.payments.store') }}" enctype="multipart/form-data">
              @csrf
              <input type="hidden" name="supplier_id" value="{{ $supplier->id }}">
              <div class="mb-2"><label class="block text-[11px] font-semibold text-emerald-900 mb-1">Amount * (partial OK)</label>
                <input name="amount" type="number" step="0.01" min="0.01" required class="w-full border border-emerald-300 rounded px-3 py-2 text-sm bg-white"></div>
              <div class="mb-2"><label class="block text-[11px] font-semibold text-emerald-900 mb-1">Paid date *</label>
                <input name="paid_date" type="date" required value="{{ \Illuminate\Support\Carbon::now('Asia/Manila')->toDateString() }}" class="w-full border border-emerald-300 rounded px-3 py-2 text-sm bg-white"></div>
              <div class="mb-2"><label class="block text-[11px] font-semibold text-emerald-900 mb-1">Method</label>
                <select name="method" class="w-full border border-emerald-300 rounded px-3 py-2 text-sm bg-white">
                  <option value="">—</option>
                  <option value="cash">Cash</option>
                  <option value="gcash">GCash</option>
                  <option value="bank">Bank transfer</option>
                  <option value="check">Check</option>
                </select></div>
              <div class="mb-2"><label class="block text-[11px] font-semibold text-emerald-900 mb-1">Reference no.</label>
                <input name="reference_no" class="w-full border border-emerald-300 rounded px-3 py-2 text-sm bg-white"></div>
              <div class="mb-2"><label class="block text-[11px] font-semibold text-emerald-900 mb-1">📎 Resibo (image/PDF)</label>
                <input name="receipt" type="file" accept=".jpg,.jpeg,.png,.webp,.pdf" class="w-full text-xs"></div>
              <div class="mb-3"><label class="block text-[11px] font-semibold text-emerald-900 mb-1">Notes</label>
                <input name="notes" class="w-full border border-emerald-300 rounded px-3 py-2 text-sm bg-white"></div>
              <div class="flex justify-end"><button class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">Record payment</button></div>
            </form>
          </div>
        @endif

        <div class="space-y-2">
          @forelse ($payments as $p)
            <div class="rounded-lg border border-slate-200 p-3">
              <div class="flex items-center justify-between">
                <div class="font-bold text-emerald-700">₱{{ number_format($p->amount, 2) }}</div>
                <div class="text-[11px] text-slate-400">{{ \Illuminate\Support\Carbon::parse($p->paid_date)->format('M j, Y') }}</div>
              </div>
              <div class="text-[11px] text-slate-500 mt-0.5">
                @if ($p->method)<span class="uppercase">{{ $p->method }}</span>@endif
                @if ($p->reference_no)<span>· {{ $p->reference_no }}</span>@endif
              </div>
              @if ($p->notes)<div class="text-[11px] text-slate-400 mt-0.5">📝 {{ $p->notes }}</div>@endif
              <div class="flex items-center justify-between mt-1">
                @if ($p->receipt_url)
                  <a href="{{ $p->receipt_url }}" target="_blank" class="text-[11px] text-indigo-600 hover:underline">📎 view receipt</a>
                @else
                  <span class="text-[11px] text-slate-300">no receipt</span>
                @endif
                @if ($canWrite)
                  <form method="POST" action="{{ route('finance.supply.payments.delete', $p->id) }}" onsubmit="return confirm('Burahin ang payment?')">
                    @csrf @method('DELETE')
                    <button class="text-[11px] text-red-400 hover:underline">delete</button>
                  </form>
                @endif
              </div>
            </div>
          @empty
            <div class="rounded-lg border border-dashed border-slate-200 p-6 text-center text-slate-400 text-sm">Wala pang bayad.</div>
          @endforelse
        </div>

        {{-- ── Stock-in summary ─────────────────────────────────────── --}}
        <h3 class="text-sm font-semibold text-slate-700 mt-6 mb-2">Stock-In (counted)</h3>
        <div class="rounded-lg border border-slate-200 overflow-hidden">
          <table class="min-w-full text-sm">
            <thead class="bg-slate-100 text-[10px] uppercase text-slate-500">
              <tr><th class="px-2 py-1.5 text-left">Item</th><th class="px-2 py-1.5 text-right">Units</th><th class="px-2 py-1.5 text-right">Cost</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
              @forelse ($stockIn as $si)
                <tr><td class="px-2 py-1.5 text-slate-700">{{ $si->item_name }}</td>
                  <td class="px-2 py-1.5 text-right font-semibold text-slate-700">{{ (int) $si->units }}</td>
                  <td class="px-2 py-1.5 text-right text-slate-500">₱{{ number_format($si->cost, 2) }}</td></tr>
              @empty
                <tr><td colspan="3" class="px-2 py-4 text-center text-slate-400 text-xs">Wala pang na-count na order.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>

    </div>
  </div>
</x-layout>

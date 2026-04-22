<!doctype html>
<html lang="en" x-data="privateUI()" x-init="init()" x-cloak>
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Daily Summary • Private</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
  <style>
    html { scroll-behavior: smooth; }
    [x-cloak] { display: none !important; }
    body { overflow-x: hidden; }
    .spinner-inline {
      border: 2px solid #e5e7eb; border-top: 2px solid #3b82f6;
      border-radius: 50%; width: 16px; height: 16px;
      animation: spin 0.8s linear infinite; display: inline-block; vertical-align: middle;
    }
    @keyframes spin { 0%{transform:rotate(0deg)} 100%{transform:rotate(360deg)} }
    .sortable { cursor: pointer; user-select: none; }
    .sortable:hover { background-color: #e5e7eb; }
  </style>
</head>
<body class="bg-gray-100 text-gray-900">

  <!-- Nav -->
  <nav class="bg-white border-b sticky top-0 z-40">
    <div class="w-full px-3 lg:px-5">
      <div class="h-14 flex items-center justify-between gap-3">
        <div class="font-semibold text-base">Daily Summary</div>
        <div class="flex items-center gap-2">
          <input type="date" x-model="date"
                 class="border rounded px-2 py-1.5 text-sm"
                 @change="load()">
          <button @click="load()"
                  class="px-3 py-1.5 bg-blue-600 text-white rounded text-sm hover:bg-blue-700 transition-colors">
            Load
          </button>
          <span x-show="loading" x-transition.opacity><span class="spinner-inline"></span></span>
          <span x-show="saveMsg" x-transition class="text-sm text-green-600 font-semibold" x-text="saveMsg"></span>
        </div>
      </div>
    </div>
  </nav>

  <main class="w-full px-2 sm:px-3 lg:px-5 py-4">

    <!-- Inline edit form -->
    <template x-if="editIdx !== -1 && rows[editIdx]">
      <div class="mb-3 p-3 bg-blue-50 border border-blue-200 rounded-lg flex flex-wrap items-end gap-4">
        <div>
          <div class="text-xs font-bold text-blue-800 mb-0.5"
               x-text="rows[editIdx].page_name + ' · ' + rows[editIdx].item_name"></div>
          <div class="text-xs text-blue-500">Effective date: <span x-text="date"></span></div>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1">Price (₱)</label>
          <input type="number" step="1" min="0" x-model="editVals.price"
                 class="border rounded px-2 py-1.5 text-sm w-28 text-right"
                 @keydown.enter="saveEdit()" @keydown.escape="cancelEdit()"
                 placeholder="e.g. 299">
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1">RTS %</label>
          <input type="number" step="0.1" min="0" max="100" x-model="editVals.rts_pct"
                 class="border rounded px-2 py-1.5 text-sm w-24 text-right"
                 @keydown.enter="saveEdit()" @keydown.escape="cancelEdit()"
                 placeholder="e.g. 25">
        </div>
        <div class="flex gap-2 items-center">
          <button @click="saveEdit()"
                  class="px-4 py-1.5 bg-green-600 text-white rounded text-sm hover:bg-green-700 font-semibold"
                  :disabled="saving">
            <span x-text="saving ? 'Saving…' : 'Save'"></span>
          </button>
          <button @click="cancelEdit()"
                  class="px-3 py-1.5 bg-gray-200 text-gray-700 rounded text-sm hover:bg-gray-300">
            Cancel
          </button>
        </div>
      </div>
    </template>

    <!-- Table -->
    <section class="bg-white rounded-xl shadow">
      <div class="overflow-x-auto">
        <table class="min-w-full text-xs">
          <thead class="bg-gray-50 sticky top-14 z-20">
            <tr class="text-left text-gray-600 border-b">
              <th class="px-3 py-2 sortable" @click="sortBy('page_name')">
                Page <span :class="arrowClass('page_name')" x-text="arrow('page_name')"></span>
              </th>
              <th class="px-3 py-2">Item Name</th>
              <th class="px-3 py-2 text-right sortable" @click="sortBy('adspent')">
                Adspent <span :class="arrowClass('adspent')" x-text="arrow('adspent')"></span>
              </th>
              <th class="px-3 py-2 text-right sortable" @click="sortBy('orders')">
                Orders <span :class="arrowClass('orders')" x-text="arrow('orders')"></span>
              </th>
              <th class="px-3 py-2 text-right sortable" @click="sortBy('cpp')">
                CPP <span :class="arrowClass('cpp')" x-text="arrow('cpp')"></span>
              </th>
              <th class="px-3 py-2 text-right sortable" @click="sortBy('proceed_orders')">
                Proceed <span :class="arrowClass('proceed_orders')" x-text="arrow('proceed_orders')"></span>
              </th>
              <th class="px-3 py-2 text-right sortable" @click="sortBy('proceed_cpp')">
                Proceed CPP <span :class="arrowClass('proceed_cpp')" x-text="arrow('proceed_cpp')"></span>
              </th>
              <th class="px-3 py-2 text-right sortable" @click="sortBy('projected_profit')">
                Proj. Profit <span :class="arrowClass('projected_profit')" x-text="arrow('projected_profit')"></span>
              </th>
              <th class="px-3 py-2 text-right sortable" @click="sortBy('proj_profit_per_order')">
                Profit/Order <span :class="arrowClass('proj_profit_per_order')" x-text="arrow('proj_profit_per_order')"></span>
              </th>
              <th class="px-3 py-2 text-right sortable" @click="sortBy('rts_pct')">
                RTS% <span :class="arrowClass('rts_pct')" x-text="arrow('rts_pct')"></span>
              </th>
              <th class="px-3 py-2 text-right">Price</th>
              <th class="px-3 py-2 text-right">Item Value</th>
              <th class="px-3 py-2 text-right">Shipping</th>
              <th class="px-3 py-2 text-right">COD Fee</th>
              <th class="px-3 py-2"></th>
            </tr>
          </thead>
          <tbody>
            <template x-if="rows.length === 0 && !loading">
              <tr>
                <td class="px-4 py-6 text-gray-400" colspan="15">
                  No data for selected date.
                </td>
              </tr>
            </template>

            <template x-for="(row, idx) in sortedRows()" :key="row.page_key">
              <tr class="border-t"
                  :class="editIdx === idx ? 'bg-blue-50' : 'hover:bg-gray-50'">

                <!-- Page -->
                <td class="px-3 py-2 font-medium whitespace-nowrap" x-text="row.page_name"></td>

                <!-- Item Name -->
                <td class="px-3 py-2" style="min-width:160px">
                  <div class="font-semibold leading-tight" x-text="row.item_name"></div>
                  <template x-for="sec in (row.secondary_items || [])" :key="sec.item_name">
                    <div class="text-gray-400 text-xs leading-tight"
                         x-text="sec.item_name + ' (' + sec.total_orders + ')'"></div>
                  </template>
                </td>

                <!-- Adspent -->
                <td class="px-3 py-2 text-right whitespace-nowrap" x-text="money(row.adspent)"></td>

                <!-- Orders -->
                <td class="px-3 py-2 text-right" x-text="num(row.orders)"></td>

                <!-- CPP -->
                <td class="px-3 py-2 text-right whitespace-nowrap" x-text="moneyOrDash(row.cpp)"></td>

                <!-- Proceed -->
                <td class="px-3 py-2 text-right" x-text="num(row.proceed_orders)"></td>

                <!-- Proceed CPP -->
                <td class="px-3 py-2 text-right whitespace-nowrap" x-text="moneyOrDash(row.proceed_cpp)"></td>

                <!-- Proj. Profit -->
                <td class="px-3 py-2 text-right whitespace-nowrap">
                  <span class="px-1.5 py-0.5 rounded font-semibold"
                        :class="profitClass(row.projected_profit)"
                        x-text="moneyOrDash(row.projected_profit)"></span>
                </td>

                <!-- Profit per Order -->
                <td class="px-3 py-2 text-right whitespace-nowrap">
                  <span class="px-1.5 py-0.5 rounded font-semibold"
                        :class="profitClass(row.proj_profit_per_order)"
                        x-text="moneyOrDash(row.proj_profit_per_order)"></span>
                </td>

                <!-- RTS% -->
                <td class="px-3 py-2 text-right">
                  <span class="px-1.5 py-0.5 rounded"
                        :class="row.rts_pct !== null ? rtsClass(row.rts_pct) : 'text-red-400 italic'"
                        x-text="row.rts_pct !== null ? row.rts_pct.toFixed(1) + '%' : '—'"></span>
                </td>

                <!-- Price -->
                <td class="px-3 py-2 text-right whitespace-nowrap"
                    :class="row.price === null ? 'text-red-400 italic' : 'text-gray-700'"
                    x-text="row.price !== null ? money(row.price) : '—'"></td>

                <!-- Item Value -->
                <td class="px-3 py-2 text-right whitespace-nowrap text-gray-500"
                    x-text="row.item_value !== null ? money(row.item_value) : '—'"></td>

                <!-- Shipping -->
                <td class="px-3 py-2 text-right whitespace-nowrap text-gray-500"
                    x-text="row.shipping_fee !== null ? money(row.shipping_fee) : '—'"></td>

                <!-- COD Fee -->
                <td class="px-3 py-2 text-right whitespace-nowrap text-gray-500"
                    x-text="row.cod_fee !== null ? money(row.cod_fee) : '—'"></td>

                <!-- Edit -->
                <td class="px-3 py-2">
                  <button @click="startEdit(idx, row)"
                          class="px-2 py-1 text-xs rounded border transition-colors"
                          :class="editIdx === idx
                            ? 'bg-blue-100 border-blue-400 text-blue-700'
                            : 'border-gray-300 text-gray-500 hover:border-blue-300 hover:text-blue-600'">
                    <span x-text="row.has_settings ? 'Edit' : '+ Set'"></span>
                  </button>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>

      <!-- Footer note -->
      <div class="px-3 py-2 text-xs text-gray-400 border-t">
        One row per page · Dominant item used for calculations · Shipping = ₱37/proceed order · COD Fee = Price × 5% × 1.12 per delivered order
      </div>
    </section>
  </main>

  <script>
    function privateUI() {
      return {
        date: (function () {
          const now = new Date();
          const ph  = new Date(now.toLocaleString('en-US', { timeZone: 'Asia/Manila' }));
          ph.setDate(ph.getDate() - 1);
          const p = n => String(n).padStart(2, '0');
          return ph.getFullYear() + '-' + p(ph.getMonth() + 1) + '-' + p(ph.getDate());
        })(),

        rows:     [],
        loading:  false,
        editIdx:  -1,
        editVals: { price: '', rts_pct: '' },
        saving:   false,
        saveMsg:  '',
        sortCol:  '',
        sortDir:  'asc',

        // ── data loading ──────────────────────────────────────────────────────
        async load() {
          this.loading = true;
          this.editIdx = -1;
          this.saveMsg = '';
          try {
            const res  = await fetch('{{ route('owner.private.item-summary') }}?date=' + this.date);
            const json = await res.json();
            this.rows  = json.rows || [];
          } catch (e) {
            console.error('load error', e);
          } finally {
            this.loading = false;
          }
        },

        // ── sorting ───────────────────────────────────────────────────────────
        sortBy(col) {
          if (this.sortCol === col) {
            this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc';
          } else {
            this.sortCol = col;
            this.sortDir = 'asc';
          }
        },
        arrow(col) {
          if (this.sortCol !== col) return '↕';
          return this.sortDir === 'asc' ? '↑' : '↓';
        },
        arrowClass(col) {
          return this.sortCol === col ? 'text-blue-600 font-bold' : 'text-gray-400';
        },
        sortedRows() {
          if (!this.sortCol) return this.rows;
          const col = this.sortCol;
          const dir = this.sortDir === 'asc' ? 1 : -1;
          return [...this.rows].sort((a, b) => {
            let va = a[col], vb = b[col];
            if (va == null) va = typeof vb === 'string' ? '' : -Infinity;
            if (vb == null) vb = typeof va === 'string' ? '' : -Infinity;
            if (typeof va === 'string') return dir * va.localeCompare(vb);
            return dir * (Number(va) - Number(vb));
          });
        },

        // ── inline edit ───────────────────────────────────────────────────────
        startEdit(idx, row) {
          this.editIdx  = idx;
          this.editVals = {
            price:   row.price   !== null ? row.price   : '',
            rts_pct: row.rts_pct !== null ? row.rts_pct : '',
          };
          this.saveMsg = '';
          this.$nextTick(() => {
            document.querySelector('input[x-model="editVals.price"]')?.focus();
          });
        },
        cancelEdit() {
          this.editIdx  = -1;
          this.editVals = { price: '', rts_pct: '' };
        },
        async saveEdit() {
          const price  = parseFloat(this.editVals.price);
          const rtsPct = parseFloat(this.editVals.rts_pct);
          if (isNaN(price)  || price  < 0)          { alert('Enter a valid Price (≥ 0).');         return; }
          if (isNaN(rtsPct) || rtsPct < 0 || rtsPct > 100) { alert('Enter a valid RTS% (0–100).'); return; }

          const row = this.rows[this.editIdx];
          this.saving = true;
          try {
            const res  = await fetch('{{ route('owner.private.item-setting.save') }}', {
              method:  'POST',
              headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
              body:    JSON.stringify({
                page_name:      row.page_name,
                item_name:      row.item_name,
                price:          price,
                rts_pct:        rtsPct,
                effective_date: this.date,
              }),
            });
            const json = await res.json();
            if (json.ok) {
              this.cancelEdit();
              await this.load();
              this.saveMsg = 'Saved!';
              setTimeout(() => { this.saveMsg = ''; }, 2500);
            }
          } catch (e) {
            console.error('save error', e);
            alert('Save failed. Check console.');
          } finally {
            this.saving = false;
          }
        },

        // ── conditional formatting ────────────────────────────────────────────
        profitClass(v) {
          if (v == null || isNaN(v)) return '';
          if (v <  0)   return 'bg-red-100 text-red-800';
          if (v <  500) return 'bg-orange-100 text-orange-800';
          if (v < 2000) return 'bg-yellow-100 text-yellow-800';
          return 'bg-green-100 text-green-800';
        },
        rtsClass(v) {
          if (v == null || isNaN(v)) return '';
          if (v > 45) return 'bg-red-100 text-red-800';
          if (v > 35) return 'bg-orange-100 text-orange-800';
          if (v > 25) return 'bg-yellow-100 text-yellow-800';
          return 'bg-green-100 text-green-800';
        },

        // ── formatters ────────────────────────────────────────────────────────
        money(v)       { return '₱' + Number(v || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
        moneyOrDash(v) { return (v == null || isNaN(v)) ? '—' : this.money(v); },
        num(v)         { return Number(v || 0).toLocaleString('en-PH'); },

        async init() { await this.load(); },
      };
    }
  </script>
</body>
</html>

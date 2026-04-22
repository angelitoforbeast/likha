<!doctype html>
<html lang="en" x-data="privateUI()" x-init="init()" x-cloak>
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Daily Summary • Private</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
  <style>
    [x-cloak] { display: none !important; }

    /* Fix: overflow-x on html/body so sticky thead works relative to viewport */
    html, body { overflow-x: auto; }

    body { background: #f1f5f9; min-width: 900px; }

    /* Nav */
    .top-nav {
      position: sticky; top: 0; z-index: 50;
      background: #1e293b;
      border-bottom: 1px solid #334155;
      height: 52px;
      display: flex; align-items: center;
      padding: 0 20px;
      gap: 12px;
    }

    /* Sticky thead — sticks below nav (52px) */
    thead th {
      position: sticky;
      top: 52px;
      z-index: 40;
      background: #1e293b;
      color: #94a3b8;
      font-size: 11px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: .04em;
      padding: 8px 10px;
      white-space: nowrap;
      border-bottom: 2px solid #334155;
      user-select: none;
    }
    thead th.sortable { cursor: pointer; }
    thead th.sortable:hover { background: #263347; color: #e2e8f0; }
    thead th.sorted { color: #60a5fa; }

    /* Rows */
    tbody tr {
      border-bottom: 1px solid #e2e8f0;
      transition: background .1s;
    }
    tbody tr:hover { background: #f8fafc; }
    tbody tr.editing { background: #eff6ff !important; }

    td {
      font-size: 12px;
      color: #374151;
      padding: 7px 10px;
      white-space: nowrap;
    }

    /* Badges */
    .badge {
      display: inline-block;
      padding: 2px 7px;
      border-radius: 9999px;
      font-size: 11px;
      font-weight: 700;
      line-height: 1.5;
    }
    .badge-green  { background:#dcfce7; color:#15803d; }
    .badge-yellow { background:#fef9c3; color:#a16207; }
    .badge-orange { background:#ffedd5; color:#c2410c; }
    .badge-red    { background:#fee2e2; color:#b91c1c; }
    .badge-gray   { background:#f1f5f9; color:#64748b; }

    /* Spinner */
    .spinner {
      display: inline-block; width: 15px; height: 15px;
      border: 2px solid #475569; border-top-color: #60a5fa;
      border-radius: 50%;
      animation: spin .7s linear infinite;
      vertical-align: middle;
    }
    @keyframes spin { to { transform: rotate(360deg); } }

    /* Edit panel */
    .edit-panel {
      background: #eff6ff;
      border: 1.5px solid #93c5fd;
      border-radius: 10px;
      padding: 12px 16px;
      display: flex; flex-wrap: wrap; align-items: flex-end; gap: 14px;
      margin-bottom: 10px;
    }

    /* Table container — no overflow-x here so sticky works */
    .table-wrap {
      background: #fff;
      border-radius: 12px;
      box-shadow: 0 1px 4px rgba(0,0,0,.08);
      overflow: hidden;
    }
    table { width: 100%; border-collapse: collapse; }

    /* Page col */
    .col-page { min-width: 110px; max-width: 140px; }
    /* Item col */
    .col-item { min-width: 150px; max-width: 200px; }
    /* Money cols */
    .col-money { min-width: 80px; }
    /* Count cols */
    .col-cnt { min-width: 60px; }
    /* Narrow */
    .col-narrow { min-width: 50px; }
  </style>
</head>
<body>

  <!-- ── Nav ───────────────────────────────────────────────────────────────── -->
  <div class="top-nav">
    <span style="color:#f1f5f9;font-weight:700;font-size:14px;letter-spacing:.01em;">Daily Summary</span>
    <div style="flex:1"></div>

    <input type="date" x-model="date"
           @change="load()"
           style="background:#0f172a;color:#e2e8f0;border:1px solid #475569;border-radius:6px;padding:4px 10px;font-size:13px;outline:none;">

    <button @click="load()"
            style="background:#3b82f6;color:#fff;border:none;border-radius:6px;padding:5px 14px;font-size:13px;font-weight:600;cursor:pointer;">
      Load
    </button>

    <span x-show="loading" x-transition.opacity><span class="spinner"></span></span>

    <span x-show="saveMsg" x-transition
          style="color:#4ade80;font-size:13px;font-weight:700;"
          x-text="saveMsg"></span>
  </div>

  <!-- ── Main ──────────────────────────────────────────────────────────────── -->
  <div style="padding:14px 16px;">

    <!-- Edit panel -->
    <template x-if="editIdx !== -1 && rows[editIdx]">
      <div class="edit-panel">
        <div>
          <div style="font-size:12px;font-weight:700;color:#1e40af;"
               x-text="rows[editIdx].page_name"></div>
          <div style="font-size:11px;color:#60a5fa;"
               x-text="stripQty(rows[editIdx].item_name)"></div>
          <div style="font-size:10px;color:#93c5fd;margin-top:2px;">
            Effective: <span x-text="date"></span>
          </div>
        </div>
        <div>
          <div style="font-size:11px;font-weight:600;color:#475569;margin-bottom:4px;">Price (₱)</div>
          <input type="number" step="1" min="0" x-model="editVals.price"
                 @keydown.enter="saveEdit()" @keydown.escape="cancelEdit()"
                 style="border:1.5px solid #93c5fd;border-radius:6px;padding:5px 10px;font-size:13px;width:100px;text-align:right;outline:none;"
                 placeholder="e.g. 299">
        </div>
        <div>
          <div style="font-size:11px;font-weight:600;color:#475569;margin-bottom:4px;">RTS %</div>
          <input type="number" step="0.1" min="0" max="100" x-model="editVals.rts_pct"
                 @keydown.enter="saveEdit()" @keydown.escape="cancelEdit()"
                 style="border:1.5px solid #93c5fd;border-radius:6px;padding:5px 10px;font-size:13px;width:80px;text-align:right;outline:none;"
                 placeholder="e.g. 25">
        </div>
        <div style="display:flex;gap:8px;align-items:center;">
          <button @click="saveEdit()" :disabled="saving"
                  style="background:#16a34a;color:#fff;border:none;border-radius:6px;padding:6px 16px;font-size:13px;font-weight:700;cursor:pointer;">
            <span x-text="saving ? 'Saving…' : 'Save'"></span>
          </button>
          <button @click="cancelEdit()"
                  style="background:#e2e8f0;color:#475569;border:none;border-radius:6px;padding:6px 12px;font-size:13px;cursor:pointer;">
            Cancel
          </button>
        </div>
      </div>
    </template>

    <!-- Table -->
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th class="col-page sortable" :class="sorted('page_name')" @click="sortBy('page_name')" style="text-align:left;">
              Page <span x-text="arrow('page_name')"></span>
            </th>
            <th class="col-item" style="text-align:left;">Item</th>
            <th class="col-money sortable" :class="sorted('adspent')" @click="sortBy('adspent')" style="text-align:right;">
              Adspent <span x-text="arrow('adspent')"></span>
            </th>
            <th class="col-cnt sortable" :class="sorted('orders')" @click="sortBy('orders')" style="text-align:right;">
              Orders <span x-text="arrow('orders')"></span>
            </th>
            <th class="col-money sortable" :class="sorted('cpp')" @click="sortBy('cpp')" style="text-align:right;">
              CPP <span x-text="arrow('cpp')"></span>
            </th>
            <th class="col-cnt sortable" :class="sorted('proceed_orders')" @click="sortBy('proceed_orders')" style="text-align:right;">
              Proceed <span x-text="arrow('proceed_orders')"></span>
            </th>
            <th class="col-money sortable" :class="sorted('proceed_cpp')" @click="sortBy('proceed_cpp')" style="text-align:right;">
              P.CPP <span x-text="arrow('proceed_cpp')"></span>
            </th>
            <th class="col-money sortable" :class="sorted('projected_profit')" @click="sortBy('projected_profit')" style="text-align:right;">
              Proj. Profit <span x-text="arrow('projected_profit')"></span>
            </th>
            <th class="col-money sortable" :class="sorted('proj_profit_per_order')" @click="sortBy('proj_profit_per_order')" style="text-align:right;">
              /Order <span x-text="arrow('proj_profit_per_order')"></span>
            </th>
            <th class="col-narrow sortable" :class="sorted('rts_pct')" @click="sortBy('rts_pct')" style="text-align:right;">
              RTS% <span x-text="arrow('rts_pct')"></span>
            </th>
            <th class="col-money" style="text-align:right;">Price</th>
            <th class="col-money" style="text-align:right;">Item Val.</th>
            <th class="col-money" style="text-align:right;">Ship</th>
            <th class="col-money" style="text-align:right;">COD Fee</th>
            <th style="min-width:52px;text-align:center;"></th>
          </tr>
        </thead>
        <tbody>

          <!-- Empty state -->
          <template x-if="rows.length === 0 && !loading">
            <tr>
              <td colspan="15" style="text-align:center;padding:40px;color:#94a3b8;font-size:13px;">
                No data for selected date.
              </td>
            </tr>
          </template>

          <!-- Loading placeholder -->
          <template x-if="rows.length === 0 && loading">
            <tr>
              <td colspan="15" style="text-align:center;padding:40px;color:#94a3b8;font-size:13px;">
                <span class="spinner" style="margin-right:6px;"></span> Loading…
              </td>
            </tr>
          </template>

          <template x-for="(row, idx) in sortedRows()" :key="row.page_key">
            <tr :class="editIdx === idx ? 'editing' : ''">

              <!-- Page -->
              <td class="col-page">
                <div style="font-weight:600;color:#1e293b;white-space:normal;line-height:1.3;"
                     x-text="row.page_name"></div>
              </td>

              <!-- Item -->
              <td class="col-item">
                <div style="font-weight:600;color:#1e293b;white-space:normal;line-height:1.3;"
                     x-text="stripQty(row.item_name)"></div>
                <template x-for="sec in (row.secondary_items || [])" :key="sec.item_name">
                  <div style="font-size:10px;color:#94a3b8;line-height:1.4;"
                       x-text="stripQty(sec.item_name) + ' (' + sec.total_orders + ')'"></div>
                </template>
              </td>

              <!-- Adspent -->
              <td class="col-money" style="text-align:right;font-weight:500;" x-text="money(row.adspent)"></td>

              <!-- Orders -->
              <td class="col-cnt" style="text-align:right;" x-text="num(row.orders)"></td>

              <!-- CPP -->
              <td class="col-money" style="text-align:right;color:#64748b;" x-text="moneyOrDash(row.cpp)"></td>

              <!-- Proceed -->
              <td class="col-cnt" style="text-align:right;font-weight:600;" x-text="num(row.proceed_orders)"></td>

              <!-- Proceed CPP -->
              <td class="col-money" style="text-align:right;color:#64748b;" x-text="moneyOrDash(row.proceed_cpp)"></td>

              <!-- Proj. Profit -->
              <td class="col-money" style="text-align:right;">
                <span class="badge" :class="profitBadge(row.projected_profit)"
                      x-text="moneyOrDash(row.projected_profit)"></span>
              </td>

              <!-- /Order -->
              <td class="col-money" style="text-align:right;">
                <span class="badge" :class="profitBadge(row.proj_profit_per_order)"
                      x-text="moneyOrDash(row.proj_profit_per_order)"></span>
              </td>

              <!-- RTS% -->
              <td class="col-narrow" style="text-align:right;">
                <template x-if="row.rts_pct !== null">
                  <span class="badge" :class="rtsBadge(row.rts_pct)"
                        x-text="row.rts_pct.toFixed(1) + '%'"></span>
                </template>
                <template x-if="row.rts_pct === null">
                  <span style="color:#fca5a5;font-style:italic;font-size:11px;">—</span>
                </template>
              </td>

              <!-- Price -->
              <td class="col-money" style="text-align:right;">
                <span :style="row.price === null ? 'color:#fca5a5;font-style:italic;' : 'color:#374151;'"
                      x-text="row.price !== null ? money(row.price) : '—'"></span>
              </td>

              <!-- Item Value -->
              <td class="col-money" style="text-align:right;color:#64748b;"
                  x-text="row.item_value !== null ? money(row.item_value) : '—'"></td>

              <!-- Shipping -->
              <td class="col-money" style="text-align:right;color:#64748b;"
                  x-text="row.shipping_fee !== null ? money(row.shipping_fee) : '—'"></td>

              <!-- COD Fee -->
              <td class="col-money" style="text-align:right;color:#64748b;"
                  x-text="row.cod_fee !== null ? money(row.cod_fee) : '—'"></td>

              <!-- Edit -->
              <td style="text-align:center;">
                <button @click="startEdit(idx, row)"
                        :style="editIdx === idx
                          ? 'background:#bfdbfe;color:#1d4ed8;border:1.5px solid #93c5fd;border-radius:5px;padding:3px 9px;font-size:11px;font-weight:700;cursor:pointer;'
                          : 'background:#f8fafc;color:#64748b;border:1.5px solid #e2e8f0;border-radius:5px;padding:3px 9px;font-size:11px;cursor:pointer;'">
                  <span x-text="row.has_settings ? 'Edit' : '+ Set'"></span>
                </button>
              </td>
            </tr>
          </template>
        </tbody>
      </table>

      <!-- Footer note -->
      <div style="padding:8px 12px;font-size:10px;color:#94a3b8;border-top:1px solid #f1f5f9;">
        One row per page · Dominant item used for calculations · Shipping = ₱37/proceed order · COD Fee = Price × 5% × 1.12 per delivered
      </div>
    </div>
  </div>

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

        // ── data ──────────────────────────────────────────────────────────────
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
            this.sortDir = 'desc'; // default desc for numbers
          }
        },
        arrow(col) {
          if (this.sortCol !== col) return '';
          return this.sortDir === 'asc' ? ' ↑' : ' ↓';
        },
        sorted(col) {
          return this.sortCol === col ? 'sorted' : '';
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

        // ── edit ──────────────────────────────────────────────────────────────
        startEdit(idx, row) {
          this.editIdx  = idx;
          this.editVals = {
            price:   row.price   !== null ? row.price   : '',
            rts_pct: row.rts_pct !== null ? row.rts_pct : '',
          };
          this.saveMsg = '';
          this.$nextTick(() => {
            document.querySelector('input[placeholder="e.g. 299"]')?.focus();
          });
        },
        cancelEdit() {
          this.editIdx  = -1;
          this.editVals = { price: '', rts_pct: '' };
        },
        async saveEdit() {
          const price  = parseFloat(this.editVals.price);
          const rtsPct = parseFloat(this.editVals.rts_pct);
          if (isNaN(price)  || price  < 0)               { alert('Valid Price needed (≥ 0).');    return; }
          if (isNaN(rtsPct) || rtsPct < 0 || rtsPct > 100) { alert('Valid RTS% needed (0–100).'); return; }

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
              this.saveMsg = '✓ Saved!';
              setTimeout(() => { this.saveMsg = ''; }, 2500);
            }
          } catch (e) {
            console.error('save error', e);
            alert('Save failed. Check console.');
          } finally {
            this.saving = false;
          }
        },

        // ── formatting ────────────────────────────────────────────────────────
        // Strip leading "2 x " / "1x " quantity prefix from item names
        stripQty(name) {
          if (!name) return '';
          return name.replace(/^\d+\s*[xX]\s*/u, '').trim();
        },

        profitBadge(v) {
          if (v == null || isNaN(v)) return 'badge-gray';
          if (v <    0) return 'badge-red';
          if (v <  500) return 'badge-orange';
          if (v < 2000) return 'badge-yellow';
          return 'badge-green';
        },
        rtsBadge(v) {
          if (v == null || isNaN(v)) return 'badge-gray';
          if (v > 45) return 'badge-red';
          if (v > 35) return 'badge-orange';
          if (v > 25) return 'badge-yellow';
          return 'badge-green';
        },

        money(v)       { return '₱' + Number(v || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
        moneyOrDash(v) { return (v == null || isNaN(Number(v))) ? '—' : this.money(v); },
        num(v)         { return Number(v || 0).toLocaleString('en-PH'); },

        async init() { await this.load(); },
      };
    }
  </script>
</body>
</html>

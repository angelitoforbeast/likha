<!doctype html>
<html lang="en" x-data="privateUI()" x-init="init()" x-cloak>
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Daily Summary • Private</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
  <style>
    [x-cloak] { display:none !important; }
    * { box-sizing:border-box; margin:0; padding:0; }

    /* Full-viewport flex column */
    html, body { height:100%; background:#f1f5f9; }
    body { display:flex; flex-direction:column; overflow:hidden; }

    /* ── Nav ── */
    #nav {
      flex-shrink:0;
      height:52px;
      background:#1e293b;
      border-bottom:1px solid #334155;
      display:flex; align-items:center;
      padding:0 18px; gap:10px;
      z-index:10;
    }

    /* ── Scroll container ── */
    #scroll {
      flex:1;
      overflow:auto;
      padding:14px 16px;
      min-width:0;
    }

    /* ── Table card ── */
    /* NOTE: no overflow:hidden — it breaks position:sticky on thead */
    .card {
      background:#fff;
      border-radius:10px;
      box-shadow:0 1px 4px rgba(0,0,0,.09);
      min-width:900px;
    }

    /* Table — MUST use separate borders for sticky z-index to work */
    table { width:100%; border-collapse:separate; border-spacing:0; }

    /* Sticky thead — high z-index so rows never bleed over it */
    thead th {
      position:sticky; top:0; z-index:30;
      background:#1e293b; color:#94a3b8;
      font-size:11px; font-weight:600;
      text-transform:uppercase; letter-spacing:.05em;
      padding:9px 10px; white-space:nowrap;
      border-bottom:2px solid #0f172a;
    }
    thead th:first-child { border-radius:10px 0 0 0; }
    thead th:last-child  { border-radius:0 10px 0 0; }
    thead th.s { cursor:pointer; }
    thead th.s:hover { background:#263347; color:#e2e8f0; }
    thead th.active { color:#60a5fa; }

    /* Total row — sticky at bottom, z-index below header but above rows */
    tr.total-row td {
      position:sticky; bottom:0; z-index:20;
      font-weight:700; color:#0f172a;
      background:#f1f5f9;
      border-top:2px solid #cbd5e1;
    }

    /* Body row borders on td (required with border-collapse:separate) */
    tbody td { border-bottom:1px solid #f1f5f9; }
    tbody tr:hover td { background:#f8fafc; }
    tbody tr.editing-row td { background:#eff6ff !important; }

    td {
      font-size:12.5px; color:#374151;
      padding:7px 10px; white-space:nowrap;
      vertical-align:middle;
    }

    /* Inline inputs */
    .ii {
      border:1.5px solid #93c5fd; border-radius:6px;
      padding:4px 7px; font-size:12px;
      text-align:right; outline:none; background:#fff;
    }
    .ii:focus { border-color:#3b82f6; box-shadow:0 0 0 2px rgba(59,130,246,.15); }

    /* Badges */
    .badge { display:inline-block; padding:2px 8px; border-radius:9999px; font-size:11px; font-weight:700; }
    .bg  { background:#dcfce7; color:#15803d; }
    .by  { background:#fef9c3; color:#a16207; }
    .bo  { background:#ffedd5; color:#c2410c; }
    .br  { background:#fee2e2; color:#b91c1c; }
    .bx  { background:#f1f5f9; color:#94a3b8; }

    /* Spinner */
    .spin {
      display:inline-block; width:15px; height:15px;
      border:2px solid #475569; border-top-color:#60a5fa;
      border-radius:50%; animation:rot .7s linear infinite; vertical-align:middle;
    }
    @keyframes rot { to { transform:rotate(360deg); } }

    /* Buttons */
    .btn-refresh {
      display:flex; align-items:center; justify-content:center;
      width:32px; height:32px; border-radius:6px; border:1px solid #475569;
      background:#0f172a; color:#94a3b8; cursor:pointer; font-size:16px;
      transition:color .15s, border-color .15s;
    }
    .btn-refresh:hover { color:#e2e8f0; border-color:#94a3b8; }
    .btn-refresh.spinning svg { animation:rot .7s linear infinite; }

    .btn-save   { font-size:11px; padding:3px 10px; border-radius:5px; cursor:pointer; border:none; background:#16a34a; color:#fff; font-weight:700; }
    .btn-save:disabled { opacity:.6; cursor:not-allowed; }
    .btn-cancel { font-size:11px; padding:3px 8px; border-radius:5px; cursor:pointer; border:1.5px solid #e2e8f0; background:#f1f5f9; color:#475569; }
    .btn-set    { font-size:11px; padding:3px 9px; border-radius:5px; cursor:pointer; border:1.5px solid #e2e8f0; background:#f8fafc; color:#64748b; }
    .btn-set:hover { border-color:#93c5fd; color:#2563eb; background:#eff6ff; }
  </style>
</head>
<body>

  <!-- ── Nav ───────────────────────────────────────────────────────────────── -->
  <div id="nav">
    <span style="color:#f1f5f9;font-weight:700;font-size:14px;">Daily Summary</span>
    <div style="flex:1"></div>

    <span x-show="saveMsg" x-transition style="color:#4ade80;font-size:13px;font-weight:700;" x-text="saveMsg"></span>

    <!-- Date input — auto-loads on change -->
    <input type="date" x-model="date" @change="load()"
           style="background:#0f172a;color:#e2e8f0;border:1px solid #475569;
                  border-radius:6px;padding:5px 10px;font-size:13px;outline:none;cursor:pointer;">

    <!-- Refresh icon button -->
    <button class="btn-refresh" :class="loading ? 'spinning' : ''"
            @click="load()" title="Refresh">
      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
           fill="none" stroke="currentColor" stroke-width="2.2"
           stroke-linecap="round" stroke-linejoin="round">
        <path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/>
        <path d="M21 3v5h-5"/>
        <path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/>
        <path d="M3 21v-5h5"/>
      </svg>
    </button>
  </div>

  <!-- ── Scrollable area ───────────────────────────────────────────────────── -->
  <div id="scroll">
    <div class="card">
      <table>
        <thead>
          <tr>
            <th class="s" :class="ac('page_name')" @click="sb('page_name')" style="text-align:left;min-width:110px;">
              Page<span x-text="arr('page_name')"></span>
            </th>
            <th style="text-align:left;min-width:160px;">Item</th>
            <th class="s" :class="ac('adspent')" @click="sb('adspent')" style="text-align:right;min-width:90px;">
              Adspent<span x-text="arr('adspent')"></span>
            </th>
            <th class="s" :class="ac('orders')" @click="sb('orders')" style="text-align:right;min-width:65px;">
              Orders<span x-text="arr('orders')"></span>
            </th>
            <th class="s" :class="ac('cpp')" @click="sb('cpp')" style="text-align:right;min-width:75px;">
              CPP<span x-text="arr('cpp')"></span>
            </th>
            <th class="s" :class="ac('proceed_orders')" @click="sb('proceed_orders')" style="text-align:right;min-width:70px;">
              Proceed<span x-text="arr('proceed_orders')"></span>
            </th>
            <th class="s" :class="ac('proceed_cpp')" @click="sb('proceed_cpp')" style="text-align:right;min-width:75px;">
              P.CPP<span x-text="arr('proceed_cpp')"></span>
            </th>
            <th class="s" :class="ac('projected_profit')" @click="sb('projected_profit')" style="text-align:right;min-width:95px;">
              Proj.Profit<span x-text="arr('projected_profit')"></span>
            </th>
            <th class="s" :class="ac('proj_profit_per_order')" @click="sb('proj_profit_per_order')" style="text-align:right;min-width:75px;">
              /Order<span x-text="arr('proj_profit_per_order')"></span>
            </th>
            <th class="s" :class="ac('rts_pct')" @click="sb('rts_pct')" style="text-align:right;min-width:65px;">
              RTS%<span x-text="arr('rts_pct')"></span>
            </th>
            <th style="text-align:right;min-width:85px;">Price</th>
            <th style="text-align:right;min-width:78px;">Item Val.</th>
            <th style="text-align:right;min-width:58px;">Ship</th>
            <th style="text-align:right;min-width:72px;">COD Fee</th>
            <th style="text-align:center;min-width:90px;"></th>
          </tr>
        </thead>
        <tbody>

          <template x-if="rows.length === 0 && !loading">
            <tr>
              <td colspan="15" style="text-align:center;padding:48px;color:#94a3b8;font-size:13px;">
                No data for selected date.
              </td>
            </tr>
          </template>

          <template x-if="rows.length === 0 && loading">
            <tr>
              <td colspan="15" style="text-align:center;padding:48px;color:#94a3b8;font-size:13px;">
                <span class="spin" style="margin-right:6px;"></span>Loading…
              </td>
            </tr>
          </template>

          <template x-for="(row, idx) in sortedRows()" :key="row.page_key">
            <tr :class="editIdx === idx ? 'editing-row' : ''">

              <!-- Page -->
              <td>
                <span style="font-weight:600;color:#0f172a;white-space:normal;line-height:1.35;"
                      x-text="row.page_name"></span>
              </td>

              <!-- Item -->
              <td>
                <div style="font-weight:600;color:#1e293b;white-space:normal;line-height:1.35;"
                     x-text="sq(row.item_name)"></div>
                <template x-for="s in (row.secondary_items||[])" :key="s.item_name">
                  <div style="font-size:10px;color:#94a3b8;line-height:1.4;">
                    <span x-text="sq(s.item_name)+' ('+s.total_orders+')'"></span>
                    <!-- show price if it differs from dominant -->
                    <template x-if="s.price && s.price !== row.price">
                      <span style="color:#cbd5e1;" x-text="' · '+money(s.price)"></span>
                    </template>
                  </div>
                </template>
              </td>

              <!-- Adspent -->
              <td style="text-align:right;font-weight:500;" x-text="money(row.adspent)"></td>

              <!-- Orders -->
              <td style="text-align:right;" x-text="num(row.orders)"></td>

              <!-- CPP -->
              <td style="text-align:right;color:#64748b;" x-text="md(row.cpp)"></td>

              <!-- Proceed -->
              <td style="text-align:right;font-weight:600;" x-text="num(row.proceed_orders)"></td>

              <!-- Proceed CPP -->
              <td style="text-align:right;color:#64748b;" x-text="md(row.proceed_cpp)"></td>

              <!-- Proj. Profit -->
              <td style="text-align:right;">
                <span class="badge" :class="pb(row.projected_profit)"
                      x-text="md(row.projected_profit)"></span>
              </td>

              <!-- /Order -->
              <td style="text-align:right;">
                <span class="badge" :class="pb(row.proj_profit_per_order)"
                      x-text="md(row.proj_profit_per_order)"></span>
              </td>

              <!-- RTS% -->
              <td style="text-align:right;">
                <template x-if="editIdx === idx">
                  <input class="ii" type="number" step="0.1" min="0" max="100"
                         x-model="ev.rts_pct" placeholder="RTS%"
                         @keydown.enter="save()" @keydown.escape="cancel()"
                         style="width:65px;">
                </template>
                <template x-if="editIdx !== idx">
                  <template x-if="row.rts_pct !== null">
                    <div>
                      <span class="badge" :class="rb(row.rts_pct)"
                            x-text="row.rts_pct.toFixed(1)+'%'"></span>
                      <div style="font-size:9px;color:#94a3b8;margin-top:2px;"
                           x-text="'from ' + row.settings_date"></div>
                    </div>
                  </template>
                  <template x-if="row.rts_pct === null">
                    <span style="color:#fca5a5;font-style:italic;font-size:11px;">—</span>
                  </template>
                </template>
              </td>

              <!-- Price — auto from COD, always read-only -->
              <td style="text-align:right;">
                <template x-if="row.price !== null">
                  <div>
                    <span style="color:#374151;" x-text="money(row.price)"></span>
                    <template x-if="row.price_is_range">
                      <div style="font-size:9px;color:#94a3b8;"
                           x-text="'↕ ' + money(row.price_min)"></div>
                    </template>
                  </div>
                </template>
                <template x-if="row.price === null">
                  <span style="color:#94a3b8;font-size:11px;">—</span>
                </template>
              </td>

              <!-- Item Value — editable inline -->
              <td style="text-align:right;">
                <template x-if="editIdx === idx">
                  <input class="ii" type="number" step="1" min="0"
                         x-model="ev.item_value" placeholder="Item Val."
                         @keydown.enter="save()" @keydown.escape="cancel()"
                         style="width:78px;">
                </template>
                <template x-if="editIdx !== idx">
                  <template x-if="row.item_value !== null">
                    <div>
                      <span style="color:#64748b;" x-text="money(row.item_value)"></span>
                      <template x-if="row.item_value_source === 'cogs'">
                        <div style="font-size:9px;color:#cbd5e1;">cogs</div>
                      </template>
                    </div>
                  </template>
                  <template x-if="row.item_value === null">
                    <span style="color:#fca5a5;font-style:italic;font-size:11px;">—</span>
                  </template>
                </template>
              </td>

              <!-- Shipping -->
              <td style="text-align:right;color:#64748b;"
                  x-text="row.shipping_fee!==null ? money(row.shipping_fee) : '—'"></td>

              <!-- COD Fee -->
              <td style="text-align:right;color:#64748b;"
                  x-text="row.cod_fee!==null ? money(row.cod_fee) : '—'"></td>

              <!-- Actions -->
              <td style="text-align:center;">
                <template x-if="editIdx === idx">
                  <span style="display:inline-flex;gap:5px;align-items:center;">
                    <button class="btn-save" @click="save()" :disabled="saving"
                            x-text="saving ? '…' : 'Save'"></button>
                    <button class="btn-cancel" @click="cancel()">✕</button>
                  </span>
                </template>
                <template x-if="editIdx !== idx">
                  <button class="btn-set" @click="startEdit(idx, row)"
                          x-text="row.has_settings ? 'Edit' : '+ Set'"></button>
                </template>
              </td>
            </tr>
          </template>

          <!-- Total row -->
          <template x-if="rows.length > 0">
            <tr class="total-row">
              <td>TOTAL</td>
              <td></td>
              <td style="text-align:right;" x-text="money(tot().adspent)"></td>
              <td style="text-align:right;" x-text="num(tot().orders)"></td>
              <td style="text-align:right;color:#475569;" x-text="md(tot().cpp)"></td>
              <td style="text-align:right;" x-text="num(tot().proceed_orders)"></td>
              <td style="text-align:right;color:#475569;" x-text="md(tot().proceed_cpp)"></td>
              <td style="text-align:right;">
                <span class="badge" :class="pb(tot().projected_profit)"
                      x-text="md(tot().projected_profit)"></span>
              </td>
              <td style="text-align:right;">
                <span class="badge" :class="pb(tot().proj_profit_per_order)"
                      x-text="md(tot().proj_profit_per_order)"></span>
              </td>
              <td colspan="6"></td>
            </tr>
          </template>
        </tbody>
      </table>

      <div style="padding:7px 12px;font-size:10px;color:#94a3b8;border-top:1px solid #f1f5f9;">
        One row per page · Dominant item used · Ship=₱37/proceed · COD Fee=Price×5%×1.12/delivered
      </div>
    </div>
  </div>

  <script>
    function privateUI() {
      return {
        date: (function(){
          const ph = new Date(new Date().toLocaleString('en-US',{timeZone:'Asia/Manila'}));
          ph.setDate(ph.getDate()-1);
          const p = n => String(n).padStart(2,'0');
          return ph.getFullYear()+'-'+p(ph.getMonth()+1)+'-'+p(ph.getDate());
        })(),

        rows:[], loading:false, editIdx:-1,
        ev:{ item_value:'', rts_pct:'' },
        saving:false, saveMsg:'',
        sortCol:'', sortDir:'desc',

        // ── load ─────────────────────────────────────────────────────────────
        async load(){
          this.loading=true; this.editIdx=-1; this.saveMsg='';
          try{
            const r = await fetch('{{ route('owner.private.item-summary') }}?date='+this.date);
            const j = await r.json();
            this.rows = j.rows||[];
          }catch(e){ console.error(e); }
          finally{ this.loading=false; }
        },

        // ── sort ─────────────────────────────────────────────────────────────
        sb(col){
          if(this.sortCol===col){ this.sortDir = this.sortDir==='asc'?'desc':'asc'; }
          else{ this.sortCol=col; this.sortDir='desc'; }
        },
        arr(col){ return this.sortCol!==col ? '' : (this.sortDir==='asc' ? ' ↑' : ' ↓'); },
        ac(col) { return this.sortCol===col ? 'active' : ''; },
        sortedRows(){
          if(!this.sortCol) return this.rows;
          const c=this.sortCol, d=this.sortDir==='asc'?1:-1;
          return [...this.rows].sort((a,b)=>{
            let va=a[c], vb=b[c];
            if(va==null) va = typeof vb==='string'?'':-Infinity;
            if(vb==null) vb = typeof va==='string'?'':-Infinity;
            if(typeof va==='string') return d*va.localeCompare(vb);
            return d*(Number(va)-Number(vb));
          });
        },

        // ── edit ─────────────────────────────────────────────────────────────
        startEdit(idx, row){
          this.editIdx=idx;
          this.ev = {
            item_value: row.item_value !== null ? row.item_value : '',
            rts_pct:    row.rts_pct   !== null ? row.rts_pct   : '',
          };
          this.saveMsg='';
        },
        cancel(){ this.editIdx=-1; this.ev={item_value:'',rts_pct:''}; },
        async save(){
          const itemVal = parseFloat(this.ev.item_value);
          const rts     = parseFloat(this.ev.rts_pct);
          if(isNaN(itemVal)||itemVal<0)    { alert('Item Value needed (≥ 0).'); return; }
          if(isNaN(rts)||rts<0||rts>100)  { alert('RTS% needed (0–100).'); return; }

          const row = this.rows[this.editIdx];
          this.saving = true;
          try {
            const r = await fetch('{{ route('owner.private.item-setting.save') }}', {
              method:  'POST',
              headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
              body:    JSON.stringify({
                page_name:      row.page_name,
                item_name:      row.item_name,
                item_value:     itemVal,
                rts_pct:        rts,
                effective_date: this.date,
              }),
            });

            let j;
            try { j = await r.json(); }
            catch { alert('Save failed: server returned non-JSON (HTTP ' + r.status + ')'); return; }

            if (!r.ok) {
              // Laravel validation errors
              const msg = j.message || (j.errors
                ? Object.values(j.errors).flat().join('\n')
                : 'HTTP ' + r.status);
              alert('Save failed:\n' + msg);
              return;
            }

            if (j.ok) {
              this.cancel();
              await this.load();
              this.saveMsg = '✓ Saved!';
              setTimeout(() => { this.saveMsg = ''; }, 2500);
            }
          } catch(e) {
            console.error(e);
            alert('Save failed: ' + e.message);
          } finally {
            this.saving = false;
          }
        },

        // ── totals ────────────────────────────────────────────────────────────
        tot() {
          const t = { adspent:0, orders:0, proceed_orders:0, projected_profit:null, cpp:null, proceed_cpp:null, proj_profit_per_order:null };
          let hasP = false;
          for (const r of this.rows) {
            t.adspent        += Number(r.adspent        || 0);
            t.orders         += Number(r.orders         || 0);
            t.proceed_orders += Number(r.proceed_orders || 0);
            if (r.projected_profit != null) { t.projected_profit = (t.projected_profit||0) + r.projected_profit; hasP = true; }
          }
          if (!hasP) t.projected_profit = null;
          t.cpp                  = t.orders         > 0 ? t.adspent / t.orders         : null;
          t.proceed_cpp          = t.proceed_orders  > 0 ? t.adspent / t.proceed_orders : null;
          t.proj_profit_per_order= (t.proceed_orders > 0 && t.projected_profit != null) ? t.projected_profit / t.proceed_orders : null;
          return t;
        },

        // ── helpers ───────────────────────────────────────────────────────────
        sq(n){ return n ? n.replace(/^\d+\s*[xX]\s*/u,'').trim() : ''; },
        pb(v){
          if(v==null||isNaN(v)) return 'bx';
          return v<0?'br':v<500?'bo':v<2000?'by':'bg';
        },
        rb(v){
          if(v==null||isNaN(v)) return 'bx';
          return v>45?'br':v>35?'bo':v>25?'by':'bg';
        },
        money(v){ return '₱'+Number(v||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}); },
        md(v)   { return (v==null||isNaN(Number(v))) ? '—' : this.money(v); },
        num(v)  { return Number(v||0).toLocaleString('en-PH'); },

        async init(){ await this.load(); },
      };
    }
  </script>
</body>
</html>

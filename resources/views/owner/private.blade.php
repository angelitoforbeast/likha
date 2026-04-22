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
    * { box-sizing: border-box; }

    html, body { margin: 0; padding: 0; height: 100%; overflow: hidden; background: #f1f5f9; }

    /* ── Fixed nav ── */
    #top-nav {
      position: fixed; top: 0; left: 0; right: 0;
      height: 52px; z-index: 100;
      background: #1e293b;
      border-bottom: 1px solid #334155;
      display: flex; align-items: center;
      padding: 0 18px; gap: 10px;
    }

    /* ── Scrollable area below nav ── */
    #main-scroll {
      position: fixed;
      top: 52px; left: 0; right: 0; bottom: 0;
      overflow: auto;
      padding: 14px 16px;
    }

    /* ── Table card ── */
    .table-card {
      background: #fff;
      border-radius: 10px;
      box-shadow: 0 1px 4px rgba(0,0,0,.09);
      overflow: hidden; /* clip rounded corners */
      min-width: 900px;
    }

    /* Table */
    table { width: 100%; border-collapse: collapse; }

    /* Sticky thead — top:0 is relative to #main-scroll container */
    thead th {
      position: sticky; top: 0; z-index: 20;
      background: #1e293b;
      color: #94a3b8;
      font-size: 11px; font-weight: 600;
      text-transform: uppercase; letter-spacing: .05em;
      padding: 9px 10px;
      white-space: nowrap;
      border-bottom: 2px solid #0f172a;
    }
    thead th.s { cursor: pointer; }
    thead th.s:hover { background: #263347; color: #e2e8f0; }
    thead th.active { color: #60a5fa; }

    /* Rows */
    tbody tr { border-bottom: 1px solid #f1f5f9; }
    tbody tr:hover { background: #f8fafc; }
    tbody tr.editing-row { background: #eff6ff !important; }

    td {
      font-size: 12.5px;
      color: #374151;
      padding: 7px 10px;
      white-space: nowrap;
      vertical-align: middle;
    }

    /* Inline inputs */
    .inline-input {
      border: 1.5px solid #93c5fd;
      border-radius: 6px;
      padding: 4px 8px;
      font-size: 12px;
      text-align: right;
      outline: none;
      width: 80px;
      background: #fff;
    }
    .inline-input:focus { border-color: #3b82f6; box-shadow: 0 0 0 2px rgba(59,130,246,.15); }

    /* Badges */
    .badge {
      display: inline-block;
      padding: 2px 8px; border-radius: 9999px;
      font-size: 11px; font-weight: 700;
    }
    .b-green  { background:#dcfce7; color:#15803d; }
    .b-yellow { background:#fef9c3; color:#a16207; }
    .b-orange { background:#ffedd5; color:#c2410c; }
    .b-red    { background:#fee2e2; color:#b91c1c; }
    .b-gray   { background:#f1f5f9; color:#94a3b8; }

    /* Spinner */
    .spin {
      display:inline-block; width:14px; height:14px;
      border:2px solid #475569; border-top-color:#60a5fa;
      border-radius:50%; animation:rot .7s linear infinite;
      vertical-align:middle;
    }
    @keyframes rot { to { transform:rotate(360deg); } }

    /* Action buttons */
    .btn-set  { font-size:11px; padding:3px 9px; border-radius:5px; cursor:pointer; border:1.5px solid #e2e8f0; background:#f8fafc; color:#64748b; }
    .btn-set:hover { border-color:#93c5fd; color:#2563eb; background:#eff6ff; }
    .btn-save { font-size:11px; padding:3px 10px; border-radius:5px; cursor:pointer; border:none; background:#16a34a; color:#fff; font-weight:700; }
    .btn-save:disabled { opacity:.6; cursor:not-allowed; }
    .btn-cancel { font-size:11px; padding:3px 8px; border-radius:5px; cursor:pointer; border:1.5px solid #e2e8f0; background:#f1f5f9; color:#475569; }
  </style>
</head>
<body>

  <!-- ── Nav ───────────────────────────────────────────────────────────────── -->
  <div id="top-nav">
    <span style="color:#f1f5f9;font-weight:700;font-size:14px;">Daily Summary</span>
    <div style="flex:1"></div>

    <span x-show="saveMsg" x-transition style="color:#4ade80;font-size:13px;font-weight:700;" x-text="saveMsg"></span>
    <span x-show="loading" x-transition><span class="spin"></span></span>

    <input type="date" x-model="date" @change="load()"
           style="background:#0f172a;color:#e2e8f0;border:1px solid #475569;border-radius:6px;
                  padding:4px 10px;font-size:13px;outline:none;cursor:pointer;">

    <button @click="load()"
            style="background:#3b82f6;color:#fff;border:none;border-radius:6px;
                   padding:5px 14px;font-size:13px;font-weight:600;cursor:pointer;">
      Load
    </button>
  </div>

  <!-- ── Scrollable main ───────────────────────────────────────────────────── -->
  <div id="main-scroll">
    <div class="table-card">
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
            <th class="s" :class="ac('orders')" @click="sb('orders')" style="text-align:right;min-width:60px;">
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
            <th class="s" :class="ac('proj_profit_per_order')" @click="sb('proj_profit_per_order')" style="text-align:right;min-width:80px;">
              /Order<span x-text="arr('proj_profit_per_order')"></span>
            </th>
            <th class="s" :class="ac('rts_pct')" @click="sb('rts_pct')" style="text-align:right;min-width:65px;">
              RTS%<span x-text="arr('rts_pct')"></span>
            </th>
            <th style="text-align:right;min-width:85px;">Price</th>
            <th style="text-align:right;min-width:80px;">Item Val.</th>
            <th style="text-align:right;min-width:60px;">Ship</th>
            <th style="text-align:right;min-width:75px;">COD Fee</th>
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
                  <div style="font-size:10px;color:#94a3b8;line-height:1.4;"
                       x-text="sq(s.item_name)+' ('+s.total_orders+')'"></div>
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

              <!-- RTS% — inline input when editing -->
              <td style="text-align:right;">
                <template x-if="editIdx === idx">
                  <input class="inline-input" type="number" step="0.1" min="0" max="100"
                         x-model="ev.rts_pct" placeholder="RTS%"
                         @keydown.enter="save()" @keydown.escape="cancel()"
                         style="width:65px;">
                </template>
                <template x-if="editIdx !== idx">
                  <template x-if="row.rts_pct !== null">
                    <span class="badge" :class="rb(row.rts_pct)"
                          x-text="row.rts_pct.toFixed(1)+'%'"></span>
                  </template>
                  <template x-if="row.rts_pct === null">
                    <span style="color:#fca5a5;font-style:italic;font-size:11px;">—</span>
                  </template>
                </template>
              </td>

              <!-- Price — inline input when editing -->
              <td style="text-align:right;">
                <template x-if="editIdx === idx">
                  <input class="inline-input" type="number" step="1" min="0"
                         x-model="ev.price" placeholder="Price"
                         @keydown.enter="save()" @keydown.escape="cancel()"
                         style="width:78px;">
                </template>
                <template x-if="editIdx !== idx">
                  <span :style="row.price===null?'color:#fca5a5;font-style:italic;':'color:#374151;'"
                        x-text="row.price!==null ? money(row.price) : '—'"></span>
                </template>
              </td>

              <!-- Item Value -->
              <td style="text-align:right;color:#64748b;"
                  x-text="row.item_value!==null ? money(row.item_value) : '—'"></td>

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
                            x-text="saving?'…':'Save'"></button>
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
        </tbody>
      </table>

      <div style="padding:7px 12px;font-size:10px;color:#94a3b8;border-top:1px solid #f1f5f9;">
        One row per page · Dominant item used · Ship = ₱37/proceed · COD Fee = Price×5%×1.12/delivered
      </div>
    </div>
  </div>

  <script>
    function privateUI() {
      return {
        date: (function(){
          const ph = new Date(new Date().toLocaleString('en-US',{timeZone:'Asia/Manila'}));
          ph.setDate(ph.getDate()-1);
          const p = n=>String(n).padStart(2,'0');
          return ph.getFullYear()+'-'+p(ph.getMonth()+1)+'-'+p(ph.getDate());
        })(),

        rows:[], loading:false, editIdx:-1,
        ev:{ price:'', rts_pct:'' },
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
          if(this.sortCol===col){ this.sortDir=this.sortDir==='asc'?'desc':'asc'; }
          else{ this.sortCol=col; this.sortDir='desc'; }
        },
        arr(col){
          if(this.sortCol!==col) return '';
          return this.sortDir==='asc' ? ' ↑' : ' ↓';
        },
        ac(col){ return this.sortCol===col?'active':''; },
        sortedRows(){
          if(!this.sortCol) return this.rows;
          const c=this.sortCol, d=this.sortDir==='asc'?1:-1;
          return [...this.rows].sort((a,b)=>{
            let va=a[c], vb=b[c];
            if(va==null) va=typeof vb==='string'?'':-Infinity;
            if(vb==null) vb=typeof va==='string'?'':-Infinity;
            if(typeof va==='string') return d*va.localeCompare(vb);
            return d*(Number(va)-Number(vb));
          });
        },

        // ── edit ─────────────────────────────────────────────────────────────
        startEdit(idx, row){
          this.editIdx=idx;
          this.ev={ price: row.price!==null?row.price:'', rts_pct: row.rts_pct!==null?row.rts_pct:'' };
          this.saveMsg='';
        },
        cancel(){ this.editIdx=-1; this.ev={price:'',rts_pct:''}; },
        async save(){
          const price=parseFloat(this.ev.price), rts=parseFloat(this.ev.rts_pct);
          if(isNaN(price)||price<0){ alert('Valid Price needed.'); return; }
          if(isNaN(rts)||rts<0||rts>100){ alert('Valid RTS% needed (0–100).'); return; }

          const row=this.rows[this.editIdx];
          this.saving=true;
          try{
            const r=await fetch('{{ route('owner.private.item-setting.save') }}',{
              method:'POST',
              headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},
              body:JSON.stringify({ page_name:row.page_name, item_name:row.item_name,
                                    price, rts_pct:rts, effective_date:this.date }),
            });
            const j=await r.json();
            if(j.ok){
              this.cancel();
              await this.load();
              this.saveMsg='✓ Saved!';
              setTimeout(()=>{ this.saveMsg=''; },2500);
            }
          }catch(e){ console.error(e); alert('Save failed.'); }
          finally{ this.saving=false; }
        },

        // ── helpers ───────────────────────────────────────────────────────────
        sq(n){ return n ? n.replace(/^\d+\s*[xX]\s*/u,'').trim() : ''; },

        pb(v){
          if(v==null||isNaN(v)) return 'b-gray';
          if(v<0)    return 'b-red';
          if(v<500)  return 'b-orange';
          if(v<2000) return 'b-yellow';
          return 'b-green';
        },
        rb(v){
          if(v==null||isNaN(v)) return 'b-gray';
          if(v>45) return 'b-red';
          if(v>35) return 'b-orange';
          if(v>25) return 'b-yellow';
          return 'b-green';
        },

        money(v){ return '₱'+Number(v||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}); },
        md(v){ return (v==null||isNaN(Number(v)))?'—':this.money(v); },
        num(v){ return Number(v||0).toLocaleString('en-PH'); },

        async init(){ await this.load(); },
      };
    }
  </script>
</body>
</html>

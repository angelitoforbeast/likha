<!DOCTYPE html>
<html lang="en" x-data="breakdownUI()" x-init="init()" x-cloak>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>Breakdown · {{ $pageLabel }}</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js"></script>
  <style>
    [x-cloak]{display:none!important}
    body{background:#fff;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;}
    .spin{display:inline-block;width:14px;height:14px;border:2px solid #cbd5e1;border-top-color:#2563eb;border-radius:50%;animation:sp 0.8s linear infinite;vertical-align:middle;}
    @keyframes sp{to{transform:rotate(360deg)}}
    /* Sticky top nav (stays pinned while scrolling) */
    .bd-nav{position:sticky;top:0;z-index:40;height:48px;}
    /* Sticky table header — sits right below the 48px nav. box-shadow used for
       the bottom rule since border-collapse borders don't follow sticky cells. */
    .bd-table{border-collapse:separate;border-spacing:0;}
    .bd-table thead th{position:sticky;top:48px;z-index:30;background:#f8fafc;box-shadow:inset 0 -1px 0 #e2e8f0;}
    .bd-table tfoot td{position:sticky;bottom:0;z-index:20;box-shadow:inset 0 1px 0 #cbd5e1;}
  </style>
</head>
<body class="min-h-screen">

  <!-- Header / Nav (sticky) -->
  <div class="bd-nav bg-slate-900 text-slate-100 px-4 flex items-center gap-3 shadow">
    <a href="{{ route('owner.private') }}?start_date={{ $startDate }}&end_date={{ $endDate }}"
       class="text-slate-400 hover:text-white text-sm">← Back to Daily Summary</a>
    <span class="text-sm font-semibold text-white truncate max-w-[28ch]" x-text="pageLabel || '{{ $pageLabel }}'"></span>
    <div class="flex-1"></div>
    <span class="text-xs text-slate-400">From</span>
    <input type="date" x-model="startDate" @change="reload()"
           class="bg-slate-800 border border-slate-600 rounded px-2 py-1 text-sm">
    <span class="text-xs text-slate-400">To</span>
    <input type="date" x-model="endDate" @change="reload()"
           class="bg-slate-800 border border-slate-600 rounded px-2 py-1 text-sm">
  </div>

  <!-- Sub-title (scrolls away) -->
  <div class="px-4 py-2 border-b border-slate-200 bg-white">
    <div class="text-xs text-slate-500">
      <span x-text="startDate + ' → ' + endDate"></span>
      <template x-if="anchorItem">
        <span> · anchor (end-date): <span class="font-bold text-blue-600" x-text="anchorItem"></span>
          <template x-if="anchorModeCod">
            <span x-text="' @ ' + money(anchorModeCod)"></span>
          </template>
        </span>
      </template>
    </div>
  </div>

  <!-- Content (edge-to-edge) -->
  <template x-if="loading">
    <div class="py-12 text-center text-slate-400 text-sm">
      <span class="spin mr-2"></span>Loading…
    </div>
  </template>

  <template x-if="!loading && rows.length === 0">
    <div class="py-12 text-center text-slate-400 text-sm">No data.</div>
  </template>

  <template x-if="!loading && rows.length > 0">
    <table class="bd-table w-full text-sm">
      <thead>
        <tr class="text-slate-600 font-bold text-xs uppercase tracking-wide">
          <th class="text-left px-4 py-2 border-b border-slate-200">Date</th>
          <th class="text-left px-4 py-2 border-b border-slate-200">Primary Item</th>
          <th class="text-left px-4 py-2 border-b border-slate-200">Item Alias</th>
          <th class="text-right px-4 py-2 border-b border-slate-200" x-show="showCol('orders')">Orders</th>
          <th class="text-right px-4 py-2 border-b border-slate-200" x-show="showCol('price')">Mode COD</th>
          <th class="text-right px-4 py-2 border-b border-slate-200" x-show="showCol('rts_set')">Set RTS%</th>
          <th class="text-left px-4 py-2 border-b border-slate-200" x-show="showCol('promo')">Promo</th>
          <th class="text-right px-4 py-2 border-b border-slate-200" x-show="showCol('item_val')">Item Val.</th>
          <th class="text-right px-4 py-2 border-b border-slate-200" x-show="showCol('adspent')">Adspent</th>
          <th class="text-right px-4 py-2 border-b border-slate-200" x-show="showCol('proceed')">Proceed</th>
          <th class="text-right px-4 py-2 border-b border-slate-200" x-show="showCol('cpp')">CPP</th>
          <th class="text-right px-4 py-2 border-b border-slate-200" x-show="showCol('proj_profit')">Net Profit</th>
          <th class="text-right px-4 py-2 border-b border-slate-200" x-show="showCol('proj_pct')">Proj%</th>
          <th class="text-center px-4 py-2 border-b border-slate-200">Status</th>
          <th class="text-left px-4 py-2 border-b border-slate-200">Action</th>
        </tr>
      </thead>
      <tbody>
        <template x-for="r in rows" :key="r.date">
          <tr :class="!r.has_data ? 'text-slate-400' : ''">
            <td class="px-4 py-2 border-b border-slate-100 font-mono">
              <span x-text="r.date"></span>
              {{-- ⚓ ANCHOR badge sa end-date row (anchor source) --}}
              <template x-if="r.is_anchor_date">
                <span class="ml-1 inline-block bg-blue-600 text-white text-[10px] font-bold px-1.5 py-0.5 rounded align-middle"
                      title="Anchor source — end-date primary item ang basehan ng anchor">⚓ ANCHOR</span>
              </template>
            </td>
            <td class="px-4 py-2 border-b border-slate-100">
              <span x-text="r.primary_item || '— no data —'"></span>
              <template x-if="r.second_item">
                <span class="text-xs text-slate-400"
                      x-text="' (2nd: ' + r.second_item + ' × ' + r.second_orders + ')'"></span>
              </template>
              {{-- subtle included/excluded tint cue via a left bar --}}
              <template x-if="r.has_data && !r.is_anchor">
                <span class="ml-1 text-[10px] text-amber-600">(other primary)</span>
              </template>
            </td>
            {{-- Item Alias — canonical item_type family. "—" kapag walang alias. --}}
            <td class="px-4 py-2 border-b border-slate-100">
              <span x-show="r.item_alias" class="text-slate-700" x-text="r.item_alias"></span>
              <span x-show="!r.item_alias" class="text-slate-300">—</span>
            </td>
            {{-- Orders --}}
            <td class="px-4 py-2 border-b border-slate-100 text-right font-mono"
                x-show="showCol('orders')" :style="cf('orders', r)"
                x-text="r.has_data ? (r.primary_orders + ' / ' + r.total_orders) : '—'"></td>
            {{-- Mode COD (price) --}}
            <td class="px-4 py-2 border-b border-slate-100 text-right font-mono"
                x-show="showCol('price')" :style="cf('price', r)"
                x-text="r.mode_cod !== null ? money(r.mode_cod) : '—'"></td>
            {{-- Set RTS% — manual rts_pct (page_item_settings), as of this date.
                 Back-fill red / effective blue stay (data-quality cues). CF only
                 applies on a "normal" cell (not back-filled / not effective). --}}
            <td class="px-4 py-2 border-b border-slate-100 text-right font-mono"
                x-show="showCol('rts_set')"
                :class="r.rts_backfilled ? 'font-bold text-red-700 bg-red-100' : ((r.rts_eff_date && r.date === r.rts_eff_date) ? 'font-bold text-blue-700 bg-blue-100' : '')"
                :style="(r.rts_backfilled || (r.rts_eff_date && r.date === r.rts_eff_date)) ? '' : cf('rts_set', r)"
                :title="r.rts_backfilled ? ('⚠ walang proper Set RTS para sa araw na ito — back-filled mula ' + r.rts_eff_date) : (r.rts_eff_date ? ('effective from ' + r.rts_eff_date) : 'no Set RTS')">
              <span x-text="(r.rts_pct !== null && r.rts_pct !== undefined) ? (Number(r.rts_pct).toFixed(1) + '%') : '—'"></span>
              <template x-if="r.rts_backfilled">
                <span class="block text-[10px] text-red-600 font-normal">⚠ back-filled</span>
              </template>
              <template x-if="!r.rts_backfilled && r.rts_eff_date && r.date === r.rts_eff_date">
                <span class="block text-[10px] text-blue-600 font-normal">↑ effective</span>
              </template>
              <template x-if="!r.rts_backfilled && r.rts_eff_date && r.rts_eff_date < startDate && r.date === startDate">
                <span class="block text-[10px] text-slate-400 font-normal" x-text="'since ' + r.rts_eff_date"></span>
              </template>
            </td>
            {{-- Promo --}}
            <td class="px-4 py-2 border-b border-slate-100"
                x-show="showCol('promo')"
                :class="r.promo_backfilled ? 'font-bold text-red-700 bg-red-100' : ((r.promo_eff && r.date === r.promo_eff) ? 'font-bold text-blue-700 bg-blue-100' : '')"
                :style="(r.promo_backfilled || (r.promo_eff && r.date === r.promo_eff)) ? '' : cf('promo', r)"
                :title="r.promo_backfilled ? ('⚠ walang proper promo para sa araw na ito — back-filled mula ' + r.promo_eff) : (r.promo_eff ? ('effective from ' + r.promo_eff) : 'no promo')">
              <span x-text="(r.promo !== null && r.promo !== undefined && r.promo !== '') ? r.promo : '—'"></span>
              <template x-if="r.promo_backfilled">
                <span class="block text-[10px] text-red-600 font-normal">⚠ back-filled</span>
              </template>
              <template x-if="!r.promo_backfilled && r.promo_eff && r.date === r.promo_eff">
                <span class="block text-[10px] text-blue-600 font-normal">↑ effective</span>
              </template>
              <template x-if="!r.promo_backfilled && r.promo_eff && r.promo_eff < startDate && r.date === startDate">
                <span class="block text-[10px] text-slate-400 font-normal" x-text="'since ' + r.promo_eff"></span>
              </template>
            </td>
            {{-- Item Value (cogs) --}}
            <td class="px-4 py-2 border-b border-slate-100 text-right font-mono"
                x-show="showCol('item_val')"
                :class="r.item_value_backfilled ? 'font-bold text-red-700 bg-red-100' : ((r.item_value_eff && r.date === r.item_value_eff) ? 'font-bold text-blue-700 bg-blue-100' : '')"
                :style="(r.item_value_backfilled || (r.item_value_eff && r.date === r.item_value_eff)) ? '' : cf('item_val', r)"
                :title="r.item_value_backfilled ? ('⚠ walang proper cogs para sa araw na ito — back-filled mula ' + r.item_value_eff) : (r.item_value_eff ? ('effective from ' + r.item_value_eff) : 'no cogs entry')">
              <span x-text="(r.item_value !== null && r.item_value !== undefined) ? money(r.item_value) : '—'"></span>
              <template x-if="r.item_value_backfilled">
                <span class="block text-[10px] text-red-600 font-normal">⚠ back-filled</span>
              </template>
              <template x-if="!r.item_value_backfilled && r.item_value_eff && r.date === r.item_value_eff">
                <span class="block text-[10px] text-blue-600 font-normal">↑ effective</span>
              </template>
              <template x-if="!r.item_value_backfilled && r.item_value_eff && r.item_value_eff < startDate && r.date === startDate">
                <span class="block text-[10px] text-slate-400 font-normal" x-text="'since ' + r.item_value_eff"></span>
              </template>
            </td>
            {{-- ── Financials (white bg; color only via conditional formatting) ── --}}
            {{-- Adspent --}}
            <td class="px-4 py-2 border-b border-slate-100 text-right font-mono"
                x-show="showCol('adspent')" :style="cf('adspent', r)"
                x-text="(r.adspent !== null && r.adspent !== undefined && r.adspent > 0) ? money(r.adspent) : '—'"></td>
            {{-- Proceed --}}
            <td class="px-4 py-2 border-b border-slate-100 text-right font-mono"
                x-show="showCol('proceed')" :style="cf('proceed', r)"
                x-text="(r.proceed !== null && r.proceed !== undefined && r.proceed > 0) ? r.proceed : '—'"></td>
            {{-- CPP (adspent ÷ proceed) --}}
            <td class="px-4 py-2 border-b border-slate-100 text-right font-mono"
                x-show="showCol('cpp')" :style="cf('cpp', r)"
                x-text="(r.cpp !== null && r.cpp !== undefined) ? money(r.cpp) : '—'"></td>
            {{-- Net Profit (per-date formula). White unless CF; small partial note. --}}
            <td class="px-4 py-2 border-b border-slate-100 text-right font-mono font-bold"
                x-show="showCol('proj_profit')" :style="cf('proj_profit', r)"
                :title="r.net_profit_partial ? 'Adspent-only (kulang ang RTS/cogs/fee/price para sa araw na ito)' : (r.fee_backfilled ? 'May back-filled na fee' : '')">
              <span x-text="(r.net_profit !== null && r.net_profit !== undefined) ? money(r.net_profit) : '—'"></span>
              <template x-if="r.net_profit_partial">
                <span class="block text-[10px] text-amber-600 font-normal">⚠ adspent-only</span>
              </template>
              <template x-if="!r.net_profit_partial && r.fee_backfilled && r.net_profit !== null && r.net_profit !== undefined">
                <span class="block text-[10px] text-red-500 font-normal">⚠ fee back-filled</span>
              </template>
            </td>
            {{-- Proj% --}}
            <td class="px-4 py-2 border-b border-slate-100 text-right font-mono"
                x-show="showCol('proj_pct')" :style="cf('proj_pct', r)"
                x-text="(r.proj_pct !== null && r.proj_pct !== undefined) ? (Number(r.proj_pct).toFixed(1) + '%') : '—'"></td>
            {{-- Status --}}
            <td class="px-4 py-2 border-b border-slate-100 text-center">
              <template x-if="r.is_anchor"><span class="text-blue-600 font-bold">✓ included</span></template>
              <template x-if="!r.is_anchor && r.has_data"><span class="text-amber-700 font-semibold">✗ excluded</span></template>
              <template x-if="!r.has_data"><span class="text-slate-300">—</span></template>
            </td>
            {{-- Action note (per date) — VIEW ONLY (auto-expanded full text).
                 Editing happens sa /owner/private (per end_date). --}}
            <td class="px-4 py-2 border-b border-slate-100">
              <div class="text-left text-xs text-slate-700" style="max-width:240px;white-space:normal;line-height:1.3;">
                <template x-if="r.action_comment">
                  <span>
                    <span x-text="r.action_comment"></span>
                    <template x-if="r.action_by">
                      <span class="block text-[10px] text-slate-400" x-text="'✎ '+r.action_by+(r.action_at?(' · '+r.action_at):'')"></span>
                    </template>
                  </span>
                </template>
                <template x-if="!r.action_comment"><span class="text-slate-300">—</span></template>
              </div>
            </td>
          </tr>
        </template>
      </tbody>
      {{-- Totals across the whole range (each row's net profit already nets
           that day's adspent → sum = page net profit for the range). --}}
      <tfoot>
        <tr class="bg-slate-100 font-bold text-slate-800 border-t-2 border-slate-300">
          <td class="px-4 py-2 bg-slate-100"></td>
          <td class="px-4 py-2 bg-slate-100 text-right">TOTAL (range)</td>
          <td class="px-4 py-2 bg-slate-100"></td>
          <td class="px-4 py-2 bg-slate-100" x-show="showCol('orders')"></td>
          <td class="px-4 py-2 bg-slate-100" x-show="showCol('price')"></td>
          <td class="px-4 py-2 bg-slate-100" x-show="showCol('rts_set')"></td>
          <td class="px-4 py-2 bg-slate-100" x-show="showCol('promo')"></td>
          <td class="px-4 py-2 bg-slate-100" x-show="showCol('item_val')"></td>
          <td class="px-4 py-2 bg-slate-100 text-right font-mono" x-show="showCol('adspent')" x-text="money(totals().adspent)"></td>
          <td class="px-4 py-2 bg-slate-100 text-right font-mono" x-show="showCol('proceed')" x-text="totals().proceed"></td>
          <td class="px-4 py-2 bg-slate-100 text-right font-mono" x-show="showCol('cpp')"
              x-text="totals().proceed > 0 ? money(totals().adspent / totals().proceed) : '—'"></td>
          <td class="px-4 py-2 bg-slate-100 text-right font-mono" x-show="showCol('proj_profit')"
              :class="totals().net_profit < 0 ? 'text-red-600' : 'text-emerald-700'"
              x-text="money(totals().net_profit)"></td>
          <td class="px-4 py-2 bg-slate-100 text-right font-mono" x-show="showCol('proj_pct')"
              :class="totals().proj_pct < 0 ? 'text-red-600' : 'text-slate-700'"
              x-text="totals().gross > 0 ? (totals().proj_pct.toFixed(1) + '%') : '—'"></td>
          <td class="px-4 py-2 bg-slate-100"></td>
          <td class="px-4 py-2 bg-slate-100"></td>
        </tr>
      </tfoot>
    </table>
  </template>

  <div class="px-4 py-3 bg-slate-50 text-xs text-slate-500 border-t border-slate-200">
    Blue "✓ included" = same primary as anchor (counted) · Amber "✗ excluded" = ibang primary that day ·
    ⚓ ANCHOR = end-date row (anchor source) ·
    Set RTS% / Promo / Item Val. = value effective as of each date ·
    <span class="font-bold text-blue-700">Blue cell + "↑ effective"</span> = saang date NAGSIMULA ang value ·
    <span class="font-bold text-red-700">Red cell + "⚠ back-filled"</span> = walang proper setting (hiniram earliest — dapat mag-set ng tamang value) ·
    "since [date]" = nagsimula bago pa ang range.
    <span class="block mt-2 pt-2 border-t border-slate-200">
      <span class="font-bold">Adspent</span> = total ad spend ng page sa araw na yon ·
      <span class="font-bold">Proceed</span> = PROCEED orders para sa primary item ng araw na yon ·
      <span class="font-bold">CPP</span> = adspent ÷ proceed ·
      <span class="font-bold">Net Profit</span> = per-date formula (RTS/cogs/fees effective as of each date) ·
      <span class="font-bold">Proj%</span> = net profit ÷ gross sales (price × orders) ·
      Kulay sa financial columns = galing sa <b>conditional formatting</b> na naka-set sa
      <code>/owner/column-settings</code> (Page Summary). Walang kulay = walang rule na tumama (white) ·
      Visible columns = ayon din sa column settings (per-role).
    </span>
  </div>

  <script>
  function breakdownUI(){
    return {
      pageKey:    @json($pageKey),
      pageLabel:  @json($pageLabel),
      startDate:  @json($startDate),
      endDate:    @json($endDate),
      rows:[], loading:true,
      anchorItem:null, anchorModeCod:null,

      // Column visibility + conditional formatting (from /owner/column-settings,
      // owner_private catalog). Same config as the main /owner/private table.
      hidden:       @json($colsConfig['hidden'] ?? []),
      colFmt:       @json($colFormatRules ?? new \stdClass()),
      breakevenPct: @json($breakevenPct ?? 5),

      async init(){ await this.reload(); },

      async reload(){
        this.loading = true;
        if (this.startDate && this.endDate && this.startDate > this.endDate) {
          const t = this.startDate; this.startDate = this.endDate; this.endDate = t;
        }
        const qs = new URLSearchParams({
          page_key:   this.pageKey,
          start_date: this.startDate,
          end_date:   this.endDate,
        });
        history.replaceState(null,'','?'+qs.toString());
        try {
          const r = await fetch('{{ route('owner.private.page-range-breakdown') }}?'+qs.toString());
          const j = await r.json();
          if (j.ok) {
            this.rows = j.rows || [];
            if (j.page_label) this.pageLabel = j.page_label;
            this.anchorItem    = j.anchor_item || null;
            this.anchorModeCod = j.anchor_mode_cod || null;
          } else {
            alert(j.message || 'Failed to load');
          }
        } catch(e) { console.error(e); alert('Network error'); }
        finally { this.loading = false; }
      },

      money(v){ return '₱'+Number(v||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}); },

      // ── Column visibility ────────────────────────────────────────────────
      // catId === null/'' → always visible (breakdown-specific column).
      showCol(catId){ return !catId || !this.hidden.includes(catId); },

      // Map an owner_private catalog id → this breakdown row's numeric value.
      catVal(catId, r){
        if (!r) return null;
        switch(catId){
          case 'adspent':      return r.adspent;
          case 'proceed':      return r.proceed;
          case 'cpp':          return r.cpp;
          case 'proj_profit':  return r.net_profit;
          case 'proj_pct':     return r.proj_pct;
          case 'orders':       return r.primary_orders;
          case 'price':        return r.mode_cod;
          case 'rts_set':      return r.rts_pct;
          case 'item_val':     return r.item_value;
          case 'promo':        return r.promo;
          default:             return null;
        }
      },

      // ── Conditional formatting (same rule shape + evaluator as main view) ──
      cf(catId, r){
        if (!catId) return '';
        const rules = (this.colFmt || {})[catId];
        if (!Array.isArray(rules) || rules.length === 0) return '';
        const own = this.catVal(catId, r);
        for (const rule of rules){
          // Determine evaluated value: compare_col (sibling) or self.
          let evalRaw = own;
          if (rule.compare_col){
            const cv = this.catVal(rule.compare_col, r);
            if (cv !== undefined) evalRaw = cv;
          }
          let hit = false;
          if (rule.op === 'is_null' || rule.op === 'is_not_null'){
            const empty = (evalRaw === null || evalRaw === undefined || evalRaw === '');
            hit = (rule.op === 'is_null') ? empty : !empty;
          } else {
            const t = this.resolveThreshold(rule.value, r);
            if (isNaN(t)) continue;
            if (evalRaw == null || isNaN(Number(evalRaw))) continue;
            const v = Number(evalRaw);
            switch(rule.op){
              case '>=': hit = v >= t; break;
              case '>':  hit = v >  t; break;
              case '=':  hit = v == t; break;
              case '<=': hit = v <= t; break;
              case '<':  hit = v <  t; break;
            }
          }
          if (hit){
            const bg  = rule.bg || '#fee2e2';
            const txt = (rule.color && /^#[0-9a-f]{6}$/i.test(rule.color)) ? rule.color : '#111827';
            return 'background:'+bg+';color:'+txt+';'+(rule.bold ? 'font-weight:700;' : '');
          }
        }
        return '';
      },

      // Literal numbers + same-row refs. Formula/cross-table refs that can't
      // resolve here return NaN → that rule is skipped (graceful).
      resolveThreshold(threshold, r){
        if (threshold == null) return NaN;
        if (typeof threshold === 'number') return threshold;
        if (typeof threshold === 'object' && threshold.type === 'ref'){
          const v = this.catVal(threshold.col, r);
          return (v == null || isNaN(Number(v))) ? NaN : Number(v);
        }
        if (typeof threshold === 'object') return NaN; // formula → unsupported here
        const n = Number(threshold);
        return isNaN(n) ? NaN : n;
      },

      // Range totals — sum each row's adspent/proceed/net profit. Net profit
      // per row already nets that day's adspent, so the sum is the page net
      // profit for the range. Proj% = totalNetProfit ÷ totalGrossSales.
      totals(){
        let adspent = 0, proceed = 0, net = 0, gross = 0;
        for (const r of this.rows) {
          if (r.adspent)    adspent += Number(r.adspent);
          if (r.proceed)    proceed += Number(r.proceed);
          if (r.net_profit !== null && r.net_profit !== undefined) net += Number(r.net_profit);
          if (r.has_data && r.mode_cod) gross += Number(r.mode_cod) * Number(r.primary_orders || 0);
        }
        return {
          adspent, proceed, net_profit: net, gross,
          proj_pct: gross > 0 ? (net / gross) * 100 : 0,
        };
      },
    };
  }
  </script>
</body>
</html>

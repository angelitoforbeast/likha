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
    body{background:#f8fafc;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;}
    .spin{display:inline-block;width:14px;height:14px;border:2px solid #cbd5e1;border-top-color:#2563eb;border-radius:50%;animation:sp 0.8s linear infinite;vertical-align:middle;}
    @keyframes sp{to{transform:rotate(360deg)}}
  </style>
</head>
<body class="min-h-screen">

  <!-- Header / Nav -->
  <div class="bg-slate-900 text-slate-100 px-4 py-3 flex items-center gap-3 shadow">
    <a href="{{ route('owner.private') }}?start_date={{ $startDate }}&end_date={{ $endDate }}"
       class="text-slate-400 hover:text-white text-sm">← Back to Daily Summary</a>
    <div class="flex-1"></div>
    <span class="text-xs text-slate-400">From</span>
    <input type="date" x-model="startDate" @change="reload()"
           class="bg-slate-800 border border-slate-600 rounded px-2 py-1 text-sm">
    <span class="text-xs text-slate-400">To</span>
    <input type="date" x-model="endDate" @change="reload()"
           class="bg-slate-800 border border-slate-600 rounded px-2 py-1 text-sm">
  </div>

  <!-- Content -->
  <div class="max-w-7xl mx-auto p-6">
    <div class="bg-white rounded-lg shadow overflow-hidden">
      <!-- Title -->
      <div class="px-5 py-4 border-b border-slate-200">
        <div class="text-lg font-bold text-slate-900" x-text="pageLabel || '{{ $pageLabel }}'"></div>
        <div class="text-xs text-slate-500 mt-1">
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

      <!-- Table -->
      <template x-if="loading">
        <div class="py-12 text-center text-slate-400 text-sm">
          <span class="spin mr-2"></span>Loading…
        </div>
      </template>

      <template x-if="!loading && rows.length === 0">
        <div class="py-12 text-center text-slate-400 text-sm">No data.</div>
      </template>

      <template x-if="!loading && rows.length > 0">
        <table class="w-full text-sm">
          <thead>
            <tr class="bg-slate-50 text-slate-600 font-bold text-xs uppercase tracking-wide">
              <th class="text-left px-4 py-2 border-b border-slate-200">Date</th>
              <th class="text-left px-4 py-2 border-b border-slate-200">Primary Item</th>
              <th class="text-right px-4 py-2 border-b border-slate-200">Orders</th>
              <th class="text-right px-4 py-2 border-b border-slate-200">Mode COD</th>
              <th class="text-right px-4 py-2 border-b border-slate-200">Set RTS%</th>
              <th class="text-left px-4 py-2 border-b border-slate-200">Promo</th>
              <th class="text-right px-4 py-2 border-b border-slate-200">Item Val.</th>
              <th class="text-right px-4 py-2 border-b border-slate-200 bg-emerald-50">Adspent</th>
              <th class="text-right px-4 py-2 border-b border-slate-200 bg-emerald-50">Proceed</th>
              <th class="text-right px-4 py-2 border-b border-slate-200 bg-emerald-50">CPP</th>
              <th class="text-right px-4 py-2 border-b border-slate-200 bg-emerald-50">Net Profit</th>
              <th class="text-right px-4 py-2 border-b border-slate-200 bg-emerald-50">Proj%</th>
              <th class="text-center px-4 py-2 border-b border-slate-200">Status</th>
            </tr>
          </thead>
          <tbody>
            <template x-for="r in rows" :key="r.date">
              <tr :class="!r.has_data ? 'bg-slate-50 text-slate-400' : (r.is_anchor ? 'bg-blue-50' : 'bg-amber-50')">
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
                </td>
                <td class="px-4 py-2 border-b border-slate-100 text-right font-mono"
                    x-text="r.has_data ? (r.primary_orders + ' / ' + r.total_orders) : '—'"></td>
                <td class="px-4 py-2 border-b border-slate-100 text-right font-mono"
                    x-text="r.mode_cod !== null ? money(r.mode_cod) : '—'"></td>
                {{-- Set RTS% — manually-configured rts_pct (page_item_settings),
                     resolved as of this date. NOT the JNT actual RTS.
                     Highlight = EFFECTIVE-DATE row (kung saan nagsimula ang value).
                     Kung bago pa sa range nagsimula → "since [date]" sa first row. --}}
                <td class="px-4 py-2 border-b border-slate-100 text-right font-mono"
                    :class="r.rts_backfilled ? 'font-bold text-red-700 bg-red-100' : ((r.rts_eff_date && r.date === r.rts_eff_date) ? 'font-bold text-blue-700 bg-blue-100' : '')"
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
                {{-- Promo — label (page_item_settings.promo), resolved as of this date --}}
                <td class="px-4 py-2 border-b border-slate-100"
                    :class="r.promo_backfilled ? 'font-bold text-red-700 bg-red-100' : ((r.promo_eff && r.date === r.promo_eff) ? 'font-bold text-blue-700 bg-blue-100' : '')"
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
                {{-- Item Value (cogs) — resolved as of this date --}}
                <td class="px-4 py-2 border-b border-slate-100 text-right font-mono"
                    :class="r.item_value_backfilled ? 'font-bold text-red-700 bg-red-100' : ((r.item_value_eff && r.date === r.item_value_eff) ? 'font-bold text-blue-700 bg-blue-100' : '')"
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
                {{-- ── Financials (per-date, back-fill-aware) ──────────────── --}}
                {{-- Adspent — ads_manager_reports total for this page that day --}}
                <td class="px-4 py-2 border-b border-slate-100 text-right font-mono bg-emerald-50/40"
                    x-text="(r.adspent !== null && r.adspent !== undefined && r.adspent > 0) ? money(r.adspent) : '—'"></td>
                {{-- Proceed — PROCEED orders for THIS date's primary item --}}
                <td class="px-4 py-2 border-b border-slate-100 text-right font-mono bg-emerald-50/40"
                    x-text="(r.proceed !== null && r.proceed !== undefined && r.proceed > 0) ? r.proceed : '—'"></td>
                {{-- CPP — adspent ÷ proceed (cost per proceed) --}}
                <td class="px-4 py-2 border-b border-slate-100 text-right font-mono bg-emerald-50/40"
                    x-text="(r.cpp !== null && r.cpp !== undefined) ? money(r.cpp) : '—'"></td>
                {{-- Net Profit — per-date formula (RTS/cogs/fees as of this date) --}}
                <td class="px-4 py-2 border-b border-slate-100 text-right font-mono font-bold bg-emerald-50/40"
                    :class="(r.net_profit === null || r.net_profit === undefined) ? 'text-slate-300' : (r.net_profit < 0 ? 'text-red-600' : 'text-emerald-700')"
                    :title="r.net_profit_partial ? 'Adspent-only (kulang ang RTS/cogs/fee/price para sa araw na ito)' : (r.fee_backfilled ? 'May back-filled na fee — tingnan ang Set RTS / cogs / fee' : '')">
                  <span x-text="(r.net_profit !== null && r.net_profit !== undefined) ? money(r.net_profit) : '—'"></span>
                  <template x-if="r.net_profit_partial">
                    <span class="block text-[10px] text-amber-600 font-normal">⚠ adspent-only</span>
                  </template>
                  <template x-if="!r.net_profit_partial && r.fee_backfilled && r.net_profit !== null && r.net_profit !== undefined">
                    <span class="block text-[10px] text-red-500 font-normal">⚠ fee back-filled</span>
                  </template>
                </td>
                {{-- Proj% — net profit ÷ gross sales (price × orders) --}}
                <td class="px-4 py-2 border-b border-slate-100 text-right font-mono bg-emerald-50/40"
                    :class="(r.proj_pct === null || r.proj_pct === undefined) ? 'text-slate-300' : (r.proj_pct < 0 ? 'text-red-600' : 'text-slate-700')"
                    x-text="(r.proj_pct !== null && r.proj_pct !== undefined) ? (Number(r.proj_pct).toFixed(1) + '%') : '—'"></td>
                <td class="px-4 py-2 border-b border-slate-100 text-center">
                  <template x-if="r.is_anchor"><span class="text-blue-600 font-bold">✓ included</span></template>
                  <template x-if="!r.is_anchor && r.has_data"><span class="text-amber-700 font-semibold">✗ excluded</span></template>
                  <template x-if="!r.has_data"><span class="text-slate-300">—</span></template>
                </td>
              </tr>
            </template>
          </tbody>
          {{-- Totals across the whole range (each row's net profit already nets
               that day's adspent → sum = page net profit for the range). --}}
          <tfoot>
            <tr class="bg-slate-100 font-bold text-slate-800 border-t-2 border-slate-300">
              <td class="px-4 py-2 text-right" colspan="3">TOTAL (range)</td>
              <td class="px-4 py-2"></td>
              <td class="px-4 py-2"></td>
              <td class="px-4 py-2"></td>
              <td class="px-4 py-2"></td>
              <td class="px-4 py-2 text-right font-mono bg-emerald-100" x-text="money(totals().adspent)"></td>
              <td class="px-4 py-2 text-right font-mono bg-emerald-100" x-text="totals().proceed"></td>
              <td class="px-4 py-2 text-right font-mono bg-emerald-100"
                  x-text="totals().proceed > 0 ? money(totals().adspent / totals().proceed) : '—'"></td>
              <td class="px-4 py-2 text-right font-mono bg-emerald-100"
                  :class="totals().net_profit < 0 ? 'text-red-600' : 'text-emerald-700'"
                  x-text="money(totals().net_profit)"></td>
              <td class="px-4 py-2 text-right font-mono bg-emerald-100"
                  :class="totals().proj_pct < 0 ? 'text-red-600' : 'text-slate-700'"
                  x-text="totals().gross > 0 ? (totals().proj_pct.toFixed(1) + '%') : '—'"></td>
              <td class="px-4 py-2"></td>
            </tr>
          </tfoot>
        </table>
      </template>

      <div class="px-5 py-3 bg-slate-50 text-xs text-slate-500 border-t border-slate-200">
        Blue row = same primary as anchor → counted in totals ·
        Amber row = different primary that day → excluded from totals ·
        ⚓ ANCHOR = end-date row (anchor source) ·
        Set RTS% / Promo / Item Val. = value effective as of each date ·
        <span class="font-bold text-blue-700">Blue cell + "↑ effective"</span> = kung saang date NAGSIMULA ang value ·
        <span class="font-bold text-red-700">Red cell + "⚠ back-filled"</span> = walang proper setting para sa araw na yon (hiniram ang earliest — dapat mag-set ka ng tamang value) ·
        "since [date]" = nagsimula bago pa ang range.
        <span class="block mt-2 pt-2 border-t border-slate-200">
          <span class="font-bold text-emerald-700">Adspent</span> = total ad spend ng page sa araw na yon ·
          <span class="font-bold text-emerald-700">Proceed</span> = PROCEED orders para sa primary item ng araw na yon ·
          <span class="font-bold text-emerald-700">CPP</span> = adspent ÷ proceed ·
          <span class="font-bold text-emerald-700">Net Profit</span> = per-date formula (RTS/cogs/fees effective as of each date) ·
          <span class="font-bold text-emerald-700">Proj%</span> = net profit ÷ gross sales (price × orders) ·
          <span class="text-amber-600">"⚠ adspent-only"</span> = may adspent pero kulang ang setting/price kaya adspent loss lang ang nakuha ·
          <span class="text-red-500">"⚠ fee back-filled"</span> = ginamit ang earliest fee (tingnan ang fee_settings).
          <span class="font-semibold">TOTAL</span> row = buong range (lahat ng araw kasama ang excluded — bawat row na may sariling adspent).
        </span>
      </div>
    </div>
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

      async init(){ await this.reload(); },

      async reload(){
        this.loading = true;
        if (this.startDate && this.endDate && this.startDate > this.endDate) {
          const t = this.startDate; this.startDate = this.endDate; this.endDate = t;
        }
        // Keep URL in sync so refresh lands on same view
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

      // Range totals — sum each row's adspent/proceed/net profit. Net profit
      // per row already nets that day's adspent, so the sum is the page net
      // profit for the range. Proj% = totalNetProfit ÷ totalGrossSales.
      totals(){
        let adspent = 0, proceed = 0, net = 0, gross = 0;
        for (const r of this.rows) {
          if (r.adspent)    adspent += Number(r.adspent);
          if (r.proceed)    proceed += Number(r.proceed);
          if (r.net_profit !== null && r.net_profit !== undefined) net += Number(r.net_profit);
          // gross sales for Proj% denominator: price × primary orders (data rows)
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

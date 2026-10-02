{{-- Aggregate metric cells for an ITEM row. Expects Alpine `A` (aggregate object
     from aggOf()/itemGroups()) and `col` in scope. Same columns + formatting as
     owner/private's TOTAL row — weighted ratios, summed additive. --}}
<template x-if="col.id==='adspent'">
  <span x-text="money(A.adspent)"></span>
</template>
<template x-if="col.id==='orders'">
  <span x-text="num(A.orders)"></span>
</template>
<template x-if="col.id==='orders_1d'">
  <span x-text="num(A.orders_last_day)"></span>
</template>
<template x-if="col.id==='cpp'">
  <span style="color:#475569;" x-text="md(A.cpp)"></span>
</template>
<template x-if="col.id==='proceed'">
  <span x-text="num(A.proceed_orders)"></span>
</template>
<template x-if="col.id==='pcpp'">
  <span style="color:#475569;" x-text="md(A.proceed_cpp)"></span>
</template>
<template x-if="col.id==='tcpr'">
  <span x-text="(A.orders > 0) ? ((1 - A.proceed_orders / A.orders) * 100).toFixed(1) + '%' : '—'"></span>
</template>
<template x-if="col.id==='breakeven_cpp'">
  <span style="color:#cbd5e1;">—</span>
</template>
<template x-if="col.id==='proj_profit'">
  <span style="font-weight:700;" x-text="md(A.projected_profit)"></span>
</template>
<template x-if="col.id==='per_order'">
  <span style="color:#111;" x-text="md(A.proj_profit_per_order)"></span>
</template>
<template x-if="col.id==='np_per_order'">
  <span style="color:#111;font-weight:700;" x-text="A.np_per_order != null ? md(A.np_per_order) : '—'"></span>
</template>
<template x-if="col.id==='np_per_order_3d'">
  <span style="font-weight:700;color:#111;" x-text="A.np_per_order_3d != null ? md(A.np_per_order_3d) : '—'"></span>
</template>
<template x-if="col.id==='np_per_order_7d'">
  <span style="font-weight:700;color:#111;" x-text="A.np_per_order_7d != null ? md(A.np_per_order_7d) : '—'"></span>
</template>
<template x-if="col.id==='np_per_order_1m'">
  <span style="font-weight:700;color:#111;" x-text="A.np_per_order_1m != null ? md(A.np_per_order_1m) : '—'"></span>
</template>
<template x-if="col.id==='proj_pct'">
  <span style="font-weight:700;color:#111;" x-text="A.proj_pct!=null ? A.proj_pct.toFixed(1)+'%' : '—'"></span>
</template>
<template x-if="col.id==='proj_pct_1d'">
  <span style="font-weight:700;color:#111;" x-text="A.proj_pct_1d!=null ? A.proj_pct_1d.toFixed(1)+'%' : '—'"></span>
</template>
<template x-if="col.id==='proj_pct_3d'">
  <span style="font-weight:700;color:#111;" x-text="A.proj_pct_3d!=null ? A.proj_pct_3d.toFixed(1)+'%' : '—'"></span>
</template>
<template x-if="col.id==='proj_pct_7d'">
  <span style="font-weight:700;color:#111;" x-text="A.proj_pct_7d!=null ? A.proj_pct_7d.toFixed(1)+'%' : '—'"></span>
</template>
<template x-if="col.id==='proj_prof_1d'">
  <span style="font-weight:700;" x-text="md(A.projected_profit_last_day)"></span>
</template>
<template x-if="col.id==='proj_prof_3d'">
  <span style="font-weight:700;" x-text="md(A.projected_profit_last_3d)"></span>
</template>
<template x-if="col.id==='proj_prof_7d'">
  <span style="font-weight:700;" x-text="md(A.projected_profit_last_7d)"></span>
</template>
{{-- HOLD sa item level = jnt/hold value (row.hold), para tugma sa badge (hindi
     yung owner/private hold_units snapshot na iba ang source). --}}
<template x-if="col.id==='hold'">
  <span style="color:#7c2d12;font-weight:700;" x-text="Number(row.hold||0).toLocaleString()"></span>
</template>
{{-- RTS / DEL / INT sa item level = aggregate ng page values (ratio-of-sums):
     summed counts, % = cnt ÷ summed jnt_total. Kaparehong stacked layout ng page
     cell (jnt_rdt). Sinusunod ang col.members (kung alin ang naka-check). --}}
<template x-if="col.id==='jnt_rdt'">
  <table style="border-collapse:collapse;font-size:11px;margin:0 auto;">
    <tbody>
      <template x-if="col.members && col.members.includes('jnt_rts')">
        <tr>
          <td style="padding:1px 6px;text-align:left;color:#94a3b8;font-size:9px;font-weight:700;letter-spacing:0.04em;">RTS</td>
          <td style="padding:1px 6px;text-align:right;border-left:1px solid #cbd5e1;" :style="A.jnt_rts_pct==null?'color:#cbd5e1':'color:#111;font-weight:700'"
              x-text="A.jnt_rts_pct!=null ? A.jnt_rts_pct.toFixed(1)+'%' : '—'"></td>
          <td style="padding:1px 6px;text-align:right;border-left:1px solid #cbd5e1;color:#64748b;"
              x-text="A.jnt_rts_pct!=null ? A.jnt_rts_cnt : ''"></td>
        </tr>
      </template>
      <template x-if="col.members && col.members.includes('jnt_del')">
        <tr>
          <td style="padding:1px 6px;text-align:left;color:#94a3b8;font-size:9px;font-weight:700;letter-spacing:0.04em;">DEL</td>
          <td style="padding:1px 6px;text-align:right;border-left:1px solid #cbd5e1;" :style="A.jnt_del_pct==null?'color:#cbd5e1':'color:#111;font-weight:600'"
              x-text="A.jnt_del_pct!=null ? A.jnt_del_pct.toFixed(1)+'%' : '—'"></td>
          <td style="padding:1px 6px;text-align:right;border-left:1px solid #cbd5e1;color:#64748b;"
              x-text="A.jnt_del_pct!=null ? A.jnt_del_cnt : ''"></td>
        </tr>
      </template>
      <template x-if="col.members && col.members.includes('jnt_transit')">
        <tr>
          <td style="padding:1px 6px;text-align:left;color:#94a3b8;font-size:9px;font-weight:700;letter-spacing:0.04em;">INT</td>
          <td style="padding:1px 6px;text-align:right;border-left:1px solid #cbd5e1;" :style="A.jnt_transit_pct==null?'color:#cbd5e1':'color:#111;font-weight:600'"
              x-text="A.jnt_transit_pct!=null ? A.jnt_transit_pct.toFixed(1)+'%' : '—'"></td>
          <td style="padding:1px 6px;text-align:right;border-left:1px solid #cbd5e1;color:#64748b;"
              x-text="A.jnt_transit_pct!=null ? A.jnt_transit_cnt : ''"></td>
        </tr>
      </template>
    </tbody>
  </table>
</template>
{{-- Individual (un-merged) jnt columns — kung hiwalay na ipinapakita sa settings. --}}
<template x-if="col.id==='jnt_rts'">
  <span :style="A.jnt_rts_pct==null?'color:#cbd5e1;font-size:11px':'color:#111;font-weight:700;font-size:12px'"
        x-text="A.jnt_rts_pct!=null ? A.jnt_rts_pct.toFixed(1)+'%('+A.jnt_rts_cnt+')' : '—'"></span>
</template>
<template x-if="col.id==='jnt_del'">
  <span :style="A.jnt_del_pct==null?'color:#cbd5e1;font-size:11px':'color:#111;font-size:12px'"
        x-text="A.jnt_del_pct!=null ? A.jnt_del_pct.toFixed(1)+'%('+A.jnt_del_cnt+')' : '—'"></span>
</template>
<template x-if="col.id==='jnt_transit'">
  <span :style="A.jnt_transit_pct==null?'color:#cbd5e1;font-size:11px':'color:#111;font-size:12px'"
        x-text="A.jnt_transit_pct!=null ? A.jnt_transit_pct.toFixed(1)+'%('+A.jnt_transit_cnt+')' : '—'"></span>
</template>
{{-- Item-level na columns galing sa /item/stock (x-text lang, walang x-html).
     Base-item numbers ang stock/DOI/order; pareho sa lahat ng variant (tooltip ang nagsasabi). --}}
<template x-if="col.id==='item_val'">
  <span :title="stockTip('ITEM VAL.: halaga ng isang piraso (cogs) hanggang sa huling petsa ng range', stockFor(row.item_name))">
    <template x-if="stockPending()"><span style="color:#94a3b8;">…</span></template>
    <template x-if="!stockPending() && itemValue(row.item_name) !== null">
      <span>
        <span style="color:#111;" x-text="money(itemValue(row.item_name))"></span>
        <div style="font-size:9px;color:#cbd5e1;font-weight:400;">cogs</div>
      </span>
    </template>
    <template x-if="!stockPending() && itemValue(row.item_name) === null">
      <span style="color:#fca5a5;font-style:italic;font-size:11px;">—</span>
    </template>
  </span>
</template>
@if($effectiveIsCEO)
<template x-if="col.id==='item_val_ceo'">
  <span :title="stockTip('ITEM VAL. (CEO): halaga ng isang piraso (cogs CEO) hanggang sa huling petsa ng range', stockFor(row.item_name))">
    <template x-if="stockPending()"><span style="color:#94a3b8;">…</span></template>
    <template x-if="!stockPending() && itemValueCeo(row.item_name) !== null">
      <span>
        <span style="color:#111;" x-text="money(itemValueCeo(row.item_name))"></span>
        <div style="font-size:9px;color:#cbd5e1;font-weight:400;">cogs</div>
      </span>
    </template>
    <template x-if="!stockPending() && itemValueCeo(row.item_name) === null">
      <span style="color:#fca5a5;font-style:italic;font-size:11px;">—</span>
    </template>
  </span>
</template>
@endif
<template x-if="col.id==='category'">
  <span :title="stockTip('Category ng item na ito', stockFor(row.item_name))">
    <span x-show="stockPending()" style="color:#94a3b8;">…</span>
    <span x-show="!stockPending()" style="font-weight:600;" x-text="stockFor(row.item_name)?.category || '—'"></span>
  </span>
</template>
<template x-if="col.id==='stock'">
  <span :title="stockTip('ilang piraso ang natitira (natanggap na PO bawas ang nai-ship) mula ' + (stock.start || 'START'), stockFor(row.item_name))">
    <span x-show="stockPending()" style="color:#94a3b8;">…</span>
    <span x-show="!stockPending()" x-text="(stock.ready && stockFor(row.item_name)) ? num(stockFor(row.item_name).stock) : '—'"></span>
    <template x-if="stock.ready && stockFor(row.item_name)?.stock_needs_count">
      <div style="font-size:9px;color:#b45309;font-weight:600;"
           :title="'may stock na hindi nabilang bago ang ' + stock.start">bilangin</div>
    </template>
  </span>
</template>
<template x-if="col.id==='incoming'">
  <span :title="stockTip('mga naka-order sa supplier na hindi pa dumarating/nabibilang', stockFor(row.item_name))">
    <span x-show="stockPending()" style="color:#94a3b8;">…</span>
    <span x-show="!stockPending()" x-text="stockFor(row.item_name) ? num(stockFor(row.item_name).incoming) : '—'"></span>
  </span>
</template>
<template x-if="col.id==='units_per_day'">
  <span :title="stockTip('average na nabentang piraso kada araw (huling 14 araw)', stockFor(row.item_name))">
    <span x-show="stockPending()" style="color:#94a3b8;">…</span>
    <span x-show="!stockPending()" x-text="stockFor(row.item_name) ? Number(stockFor(row.item_name).units_per_day).toFixed(1) : '—'"></span>
  </span>
</template>
<template x-if="col.id==='doi'">
  <span :title="stockTip('ilang araw pa tatagal ang stock + paparating, bawas ang HOLD', stockFor(row.item_name))">
    <span x-show="stockPending()" style="color:#94a3b8;">…</span>
    <span x-show="!stockPending()">
      <span :style="'font-weight:700;color:' + doiColour(stockFor(row.item_name))" x-text="doiText(stockFor(row.item_name))"></span>
      <template x-if="stockFor(row.item_name)">
        <div style="font-size:9px;color:#94a3b8;font-weight:400;"
             x-text="'lead ' + stockFor(row.item_name).lead + ' · palugit ' + stockFor(row.item_name).safety"></div>
      </template>
    </span>
  </span>
</template>
<template x-if="col.id==='order_qty'">
  <span :title="stockTip('ilang piraso ang dapat i-order (HOLD + benta habang hinihintay − stock − paparating)', stockFor(row.item_name))">
    <span x-show="stockPending()" style="color:#94a3b8;">…</span>
    <span x-show="!stockPending()">
      <span style="font-weight:800;" x-text="stockFor(row.item_name) ? num(stockFor(row.item_name).order_qty) : '—'"></span>
      <template x-if="stockFor(row.item_name)">
        <div style="font-size:9px;color:#64748b;font-weight:400;" x-text="orderByText(stockFor(row.item_name))"></div>
      </template>
    </span>
  </span>
</template>
{{-- Per-page-only columns (Promo, Price, Set RTS%, Item Val., etc.)
     — blank sa item aggregate row, tulad ng TOTAL row ng owner/private. --}}
<template x-if="!['adspent','orders','orders_1d','cpp','proceed','pcpp','tcpr','breakeven_cpp','proj_profit','per_order','np_per_order','np_per_order_3d','np_per_order_7d','np_per_order_1m','proj_pct','proj_pct_1d','proj_pct_3d','proj_pct_7d','proj_prof_1d','proj_prof_3d','proj_prof_7d','hold','jnt_rdt','jnt_rts','jnt_del','jnt_transit','item_val','item_val_ceo','category','stock','incoming','units_per_day','doi','order_qty'].includes(col.id)">
  <span></span>
</template>

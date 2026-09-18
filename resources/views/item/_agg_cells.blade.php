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
{{-- Per-page-only columns (Promo, Price, Set RTS%, Item Val., etc.)
     — blank sa item aggregate row, tulad ng TOTAL row ng owner/private. --}}
<template x-if="!['adspent','orders','orders_1d','cpp','proceed','pcpp','tcpr','breakeven_cpp','proj_profit','per_order','np_per_order','np_per_order_3d','np_per_order_7d','np_per_order_1m','proj_pct','proj_pct_1d','proj_pct_3d','proj_pct_7d','proj_prof_1d','proj_prof_3d','proj_prof_7d','hold','jnt_rdt','jnt_rts','jnt_del','jnt_transit'].includes(col.id)">
  <span></span>
</template>

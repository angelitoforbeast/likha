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
{{-- Per-page-only columns (Promo, Price, Set RTS%, Item Val., RTS/DEL/INT, etc.)
     — blank sa item aggregate row, tulad ng TOTAL row ng owner/private. --}}
<template x-if="!['adspent','orders','orders_1d','cpp','proceed','pcpp','tcpr','breakeven_cpp','proj_profit','per_order','np_per_order','np_per_order_3d','np_per_order_7d','np_per_order_1m','proj_pct','proj_pct_1d','proj_pct_3d','proj_pct_7d','proj_prof_1d','proj_prof_3d','proj_prof_7d','hold'].includes(col.id)">
  <span></span>
</template>

{{-- Expanded block ng bagong /item layout (005 T4). Expects Alpine `G` (item group) sa scope.
     Walang x-html, walang fills (pbStyle / cellFormatStyle) — text + ▲/▼ lang. Ang CSS ay .il-* sa index. --}}

{{-- ── ITEM: definition-list grid. Bawat entry ay lalabas lang kung visible ang id niya. ── --}}
<div class="il-dl">

  <template x-if="ilIdOn('item_val') || ilIdOn('item_val_ceo')">
    <div class="il-dl-i" title="Halaga ng isang piraso (cogs) hanggang sa huling petsa ng range">
      <div class="il-dl-l">Puhunan bawat piraso</div>
      <div class="il-dl-v">
        <span x-show="stockPending()" class="il-grey">…</span>
        <span x-show="!stockPending() && ilIdOn('item_val')"
              x-text="ilPiece(itemValue(G.item_name), G.item_name) === null ? '—' : ilMoney(ilPiece(itemValue(G.item_name), G.item_name))"></span>
        @if($effectiveIsCEO)
        <template x-if="!stockPending() && ilIdOn('item_val_ceo') && ilCeoPieceDiff(G.item_name) !== null">
          <span class="il-sub" x-text="'CEO: ' + ilMoney(ilCeoPieceDiff(G.item_name))"></span>
        </template>
        @endif
      </div>
    </div>
  </template>

  <template x-if="ilIdOn('jnt_rdt')">
    <div class="il-dl-i" title="Totoong RTS / Delivered / In-transit ng JNT, pinagsama ang lahat ng page ng item">
      <div class="il-dl-l">RTS / DEL / INT</div>
      <div class="il-dl-v">
        <div x-show="ilIdOn('jnt_rts')" x-text="'RTS ' + (G.agg.jnt_rts_pct != null ? G.agg.jnt_rts_pct.toFixed(1) + '% (' + G.agg.jnt_rts_cnt + ')' : '—')"></div>
        <div x-show="ilIdOn('jnt_del')" x-text="'DEL ' + (G.agg.jnt_del_pct != null ? G.agg.jnt_del_pct.toFixed(1) + '% (' + G.agg.jnt_del_cnt + ')' : '—')"></div>
        <div x-show="ilIdOn('jnt_transit')" x-text="'INT ' + (G.agg.jnt_transit_pct != null ? G.agg.jnt_transit_pct.toFixed(1) + '% (' + G.agg.jnt_transit_cnt + ')' : '—')"></div>
      </div>
    </div>
  </template>

  <template x-if="ilIdOn('tcpr')">
    <div class="il-dl-i" title="TCPR: ilang % ng orders ang hindi umabot sa proceed">
      <div class="il-dl-l">TCPR</div>
      <div class="il-dl-v" x-text="G.agg.orders > 0 ? ((1 - G.agg.proceed_orders / G.agg.orders) * 100).toFixed(1) + '%' : '—'"></div>
    </div>
  </template>

  <template x-if="ilIdOn('proj_profit')">
    <div class="il-dl-i" title="Kita (o lugi) ng buong napiling range">
      <div class="il-dl-l">Prof.Profit (range)</div>
      <div class="il-dl-v il-num" :style="'color:' + ilTone(G.agg.projected_profit)" x-text="ilArrow(G.agg.projected_profit) + ilMoney(G.agg.projected_profit)"></div>
    </div>
  </template>
  <template x-if="ilIdOn('proj_prof_3d')">
    <div class="il-dl-i">
      <div class="il-dl-l">Prof.Profit 3D</div>
      <div class="il-dl-v il-num" :style="'color:' + ilTone(G.agg.projected_profit_last_3d)" x-text="ilArrow(G.agg.projected_profit_last_3d) + ilMoney(G.agg.projected_profit_last_3d)"></div>
    </div>
  </template>
  <template x-if="ilIdOn('proj_prof_7d')">
    <div class="il-dl-i">
      <div class="il-dl-l">Prof.Profit 7D</div>
      <div class="il-dl-v il-num" :style="'color:' + ilTone(G.agg.projected_profit_last_7d)" x-text="ilArrow(G.agg.projected_profit_last_7d) + ilMoney(G.agg.projected_profit_last_7d)"></div>
    </div>
  </template>

  <template x-if="ilIdOn('np_per_order_1m')">
    <div class="il-dl-i" title="Net profit kada order sa buong range">
      <div class="il-dl-l">NP/O (1M)</div>
      <div class="il-dl-v il-num" :style="'color:' + ilTone(G.agg.np_per_order_1m)" x-text="ilArrow(G.agg.np_per_order_1m) + ilMoney(G.agg.np_per_order_1m)"></div>
    </div>
  </template>

  <template x-if="ilIdOn('orders')">
    <div class="il-dl-i">
      <div class="il-dl-l">Orders</div>
      <div class="il-dl-v" x-text="num(G.agg.orders)"></div>
    </div>
  </template>
  <template x-if="ilIdOn('proceed')">
    <div class="il-dl-i">
      <div class="il-dl-l">Proceed</div>
      <div class="il-dl-v" x-text="num(G.agg.proceed_orders)"></div>
    </div>
  </template>

  <template x-if="categoryColVisible()">
    <div class="il-dl-i">
      <div class="il-dl-l">Category</div>
      <div class="il-dl-v" :title="stockTip('Category ng item na ito', stockFor(G.item_name))">
        <span x-show="stockPending()" class="il-grey">…</span>
        @if($effectiveIsCEO)
        {{-- CEO: palitan ang category (para sa lahat ng variant ng base item). --}}
        <span x-show="!stockPending()" title="para sa lahat ng variant ng item na ito">
          <select aria-label="Category ng item" :disabled="stockEdit.saving"
                  @change="saveCategory(G.item_name, $event.target.value, $event.target)"
                  style="border:1px solid #cbd5e1;border-radius:5px;padding:3px 4px;font-size:12px;max-width:100%;">
            <option value="" :selected="!stockFor(G.item_name)?.category_id">— wala —</option>
            <template x-for="c in stock.categories" :key="'ilcc-'+c.id">
              <option :value="String(c.id)" :selected="String(c.id) === String(stockFor(G.item_name)?.category_id)" x-text="c.name"></option>
            </template>
            <option value="__new">+ bagong category</option>
          </select>
          <template x-if="stockEdit.key === supKey(G.item_name) && stockEdit.mode === 'cat'">
            <div style="margin-top:3px;display:flex;gap:4px;align-items:center;flex-wrap:wrap;">
              <input type="text" maxlength="60" aria-label="Bagong category" x-model="stockEdit.newCat"
                     @keydown.enter.stop.prevent="saveNewCategory(G.item_name, stockEdit.newCat)"
                     style="border:1px solid #cbd5e1;border-radius:5px;padding:3px 5px;font-size:12px;width:110px;max-width:100%;">
              <button type="button" class="il-edit" :disabled="stockEdit.saving" @click.stop="saveNewCategory(G.item_name, stockEdit.newCat)">Save</button>
              <button type="button" class="il-edit" :disabled="stockEdit.saving"
                      @click.stop="cancelNewCategory(G.item_name, $el.closest('span[title]').querySelector('select'))">Cancel</button>
            </div>
          </template>
          <div x-show="stockEdit.key === supKey(G.item_name) && stockEdit.mode === 'cat' && stockEdit.error"
               style="font-size:11px;color:#b91c1c;font-weight:600;" x-text="stockEdit.error"></div>
        </span>
        @else
        <span x-show="!stockPending()" style="font-weight:600;" x-text="stockFor(G.item_name)?.category || '—'"></span>
        @endif
      </div>
    </div>
  </template>

  <template x-if="ilIdOn('order_qty')">
    <div class="il-dl-i il-dl-wide">
      <div class="il-dl-l">I-order</div>
      <div class="il-dl-v" x-text="'Paano nakuha: ' + ilOrderReason(G.item_name)"></div>
    </div>
  </template>

  {{-- Naka-duplicate dito ang mga column na nakatago sa makitid na screen (ipinapakita ng .il-only-* sa T5). --}}
  <template x-if="ilColOn('action')">
    <div class="il-dl-i il-dl-wide il-only-narrow il-only-lt1366">
      <div class="il-dl-l">Action</div>
      <div class="il-dl-v" x-text="ilLatestAction(G) ? ilLatestAction(G).page + ': ' + ilLatestAction(G).text : '—'"></div>
    </div>
  </template>
  <template x-if="ilColOn('stock')">
    <div class="il-dl-i il-only-narrow il-only-lt1100">
      <div class="il-dl-l">Stock / Paparating</div>
      <div class="il-dl-v" x-text="'Stock ' + ((stock.ready && stockFor(G.item_name)) ? num(stockFor(G.item_name).stock) : '—') + ' · Paparating ' + (stockFor(G.item_name) ? num(stockFor(G.item_name).incoming) : '—')"></div>
    </div>
  </template>
  <template x-if="ilColOn('upd')">
    <div class="il-dl-i il-only-narrow il-only-lt1100">
      <div class="il-dl-l">Benta/araw</div>
      <div class="il-dl-v" x-text="(stockSet(G.item_name) && stockSet(G.item_name).units_per_day != null) ? Number(stockSet(G.item_name).units_per_day).toFixed(1) : '—'"></div>
    </div>
  </template>
  <template x-if="ilColOn('kita')">
    <div class="il-dl-i il-only-narrow il-only-lt1100">
      <div class="il-dl-l">Kita ngayon</div>
      <div class="il-dl-v il-num" :style="'color:' + ilTone(G.agg.projected_profit_last_day)"
           x-text="ilArrow(G.agg.projected_profit_last_day) + ilMoneyKita(G.agg.projected_profit_last_day) + ' · ' + num(G.agg.orders_last_day) + ' orders'"></div>
    </div>
  </template>
  <template x-if="ilColOn('ads')">
    <div class="il-dl-i il-only-narrow il-only-lt1100">
      <div class="il-dl-l">Ads</div>
      <div class="il-dl-v" x-text="ilMoney(G.agg.adspent) + (ilAdsLine(G) ? ' · ' + ilAdsLine(G) : '')"></div>
    </div>
  </template>

  <div class="il-dl-i il-dl-wide il-dl-btns">
    <a class="item-photo-btn" :href="'{{ route('item.photo') }}?item='+encodeURIComponent(G.item_name)+'&start_date='+startDate+'&end_date='+endDate"
       target="_blank" rel="noopener" style="text-decoration:none;"
       x-text="itemImages[G.item_name] ? 'Change' : 'Add photo'"></a>
    <button type="button" class="item-copy-btn" @click.stop="copyItem(G.item_name, G.hold)"
            x-text="copyState===G.item_name ? '✓ Copied' : '📋 Copy'"></button>
  </div>
</div>

{{-- ── PAGES ── --}}
<div class="il-pages-h" x-text="'Mga page (' + G.pages.length + ')'"></div>
<template x-if="!G.hasPages">
  <div class="il-sub" style="padding:6px 2px;">Walang running page — walang page na maipapakita.</div>
</template>

{{-- Ang x-for variable ay dapat `row` — iyon ang inaasahan ng shared campaigns include. --}}
<template x-for="row in G.pages" :key="row.page_key">
  <div>
    <div class="il-page-card">
      <div class="il-pc-head">
        <div class="il-pc-page">
          <template x-if="row.is_range">
            <a href="#" @click.prevent="openBreakdown(row)" class="il-pc-name il-pc-link"
               title="View per-date primary item breakdown" x-text="row.page_name"></a>
          </template>
          <template x-if="!row.is_range">
            <span class="il-pc-name" x-text="row.page_name"></span>
          </template>
          <template x-if="row.mixed_primary">
            <div style="cursor:pointer;" @click="openBreakdown(row)"
                 :title="row.distinct_items_in_range + ' distinct primary items across ' + row.range_days + '-day range. Click to see breakdown.'">
              <div class="il-pc-warn" style="color:#b45309;">
                ⚠ mixed primary · <span x-text="row.included_days + '/' + row.range_days + ' d'"></span>
              </div>
              <template x-if="row.anchor_first_date">
                <div class="il-sub">computed since <span x-text="fmtMD(row.anchor_first_date)"></span></div>
              </template>
            </div>
          </template>
          <template x-if="row.has_backfill">
            <div class="il-pc-warn" style="cursor:pointer;color:#dc2626;" @click="openBreakdown(row)"
                 :title="'⚠ ' + (row.backfill_dates ? row.backfill_dates.length : 0) + ' date(s) walang proper setting — back-filled earliest. Click para makita sa breakdown.'"
                 x-text="'⚠ back-filled ' + (row.backfill_fields && row.backfill_fields.length ? row.backfill_fields.map(f => ({rts:'RTS', cost:'cost', fee:'fee'}[f] || f)).join(' + ') : '')"></div>
          </template>
        </div>
        <div class="il-pc-item">
          <div style="font-weight:600;color:#1e293b;" x-text="sq(row.item_name)"></div>
          <template x-for="s in (row.secondary_items||[])" :key="s.item_name">
            <div class="il-sub">
              <span x-text="sq(s.item_name)+' ('+s.total_orders+')'"></span>
              <template x-if="s.price && s.price !== row.price">
                <span x-text="' · '+money(s.price)"></span>
              </template>
            </div>
          </template>
        </div>
        <button type="button" class="il-edit il-camp-btn" @click.stop="togglePageExpand(row.page_name)"
                :aria-expanded="(expandedPages[row.page_name] || {}).open ? 'true' : 'false'"
                title="Ipakita/itago ang campaigns ng page na ito">
          Campaigns <span aria-hidden="true" class="il-camp-chev" :class="(expandedPages[row.page_name] || {}).open ? 'active' : ''">›</span>
        </button>
      </div>

      {{-- Lahat ng page field na nakikita ng role, sa lumang column order. Walang fill; text lang. --}}
      <div class="il-pf-grid">
        <template x-for="col in cols" :key="'pf-'+row.page_key+'-'+col.id">
          <div class="il-pf" :class="col.id === 'action' ? 'il-pf-wide' : ''" x-show="ilPageFieldOn(col.id)">
            <div class="il-dl-l" x-text="col.label"></div>
            <div class="il-pf-v">
              <div style="flex:1;min-width:0;">
                <div :class="(ilPageVal(col.id, row).miss ? 'il-grey ' : '') + (col.id === 'action' && !row._actionOpen ? 'il-clamp' : '')"
                     :style="ilPageVal(col.id, row).tone ? 'color:' + ilPageVal(col.id, row).tone + ';font-weight:700;' : ''"
                     :title="ilPageVal(col.id, row).tip"
                     x-text="ilPageVal(col.id, row).text"></div>
                <template x-if="ilPageVal(col.id, row).sub">
                  <div class="il-sub" x-text="ilPageVal(col.id, row).sub"></div>
                </template>
                <template x-if="col.id === 'action' && (row.action_comment||'').length > 60">
                  <button type="button" class="il-linkbtn" @click="row._actionOpen = !row._actionOpen"
                          x-text="row._actionOpen ? '▾ less' : '▸ more'"></button>
                </template>
              </div>
              <template x-if="col.id === 'rts_set'">
                <button type="button" class="il-edit" @click="openEditModal(row, 'rts')" title="Edit RTS%" aria-label="Edit RTS%">✎</button>
              </template>
              <template x-if="col.id === 'promo'">
                <button type="button" class="il-edit" @click="openEditModal(row, 'promo')" title="Edit Promo" aria-label="Edit Promo">✎</button>
              </template>
              <template x-if="col.id === 'item_val'">
                <button type="button" class="il-edit" @click="openEditModal(row, 'cogs')" title="Edit Unit Cost (COGS)" aria-label="Edit Unit Cost (COGS)">✎</button>
              </template>
              @if($effectiveIsCEO)
              <template x-if="col.id === 'item_val_ceo'">
                <button type="button" class="il-edit" @click="openEditModal(row, 'cogs_ceo')" title="Edit CEO Unit Cost" aria-label="Edit CEO Unit Cost">✎</button>
              </template>
              @endif
              <template x-if="col.id === 'action'">
                <button type="button" class="il-edit" @click="openActionModal(row)" title="Edit Action note" aria-label="Edit Action note">✎</button>
              </template>
            </div>
          </div>
        </template>
      </div>
    </div>

    {{-- Campaigns / ad sets / ads ng page — shared include; ang .il-camp CSS ang nagpapasya sa lapad (walang horizontal scroll). --}}
    <template x-if="(expandedPages[row.page_name] || {}).open">
      <div class="il-camp">
        @include('owner._private_expand_inline')
      </div>
    </template>
  </div>
</template>

{{-- Details (›) ng bagong /item (006 T5) — English, maiikling label, isang tooltip bawat isa. Expects Alpine `G` (item group).
     x-text / :title lang (walang x-html); walang fills (pbStyle / cellFormatStyle). CSS = .il-* sa index.
     CEO-only (supplier, quotes, sourcing-list info, ✎ lead/buffer, CEO value, category select) ay naka-gate sa Blade. --}}

{{-- ── ITEM: definition-list grid. Bawat entry ay lalabas lang kung naka-grant ang id niya. ── --}}
<div class="il-dl">

  <template x-if="ilIdOn('units_per_day')">
    <div class="il-dl-i" :title="ilTip(stockSet(G.item_name) ? 'Average of the last ' + stockSet(G.item_name).velocity_days + ' days' : 'Average pieces sold a day', stockFor(G.item_name))">
      <div class="il-dl-l">Sales a day</div>
      <div class="il-dl-v">
        <span x-show="stockPending()" class="il-grey">…</span>
        <span x-show="!stockPending()" x-text="ilSalesADay(G.item_name)"></span>
      </div>
    </div>
  </template>

  <template x-if="ilIdOn('stock') || ilIdOn('incoming')">
    <div class="il-dl-i" :title="ilTip('Pieces in stock, and pieces ordered from the supplier that have not arrived yet', stockFor(G.item_name))">
      <div class="il-dl-l">Stock / Incoming</div>
      <div class="il-dl-v">
        <span x-show="stockPending()" class="il-grey">…</span>
        <span x-show="!stockPending()"
              x-text="(stock.ready && stockFor(G.item_name) && stockFor(G.item_name).stock_needs_count) ? 'Count the stock first'
                      : ((stock.ready && stockFor(G.item_name)) ? num(stockFor(G.item_name).stock) : '—') + ' / ' + (stockFor(G.item_name) ? num(stockFor(G.item_name).incoming) : '—')"></span>
      </div>
    </div>
  </template>

  {{-- Lead time + buffer (lahat ng role) + ✎ editor (CEO LANG). --}}
  <template x-if="!stockPending() && stockFor(G.item_name)">
    <div class="il-dl-i il-dl-wide" @click.stop>
      <div class="il-dl-l">Lead time</div>
      @if($effectiveIsCEO)
      <div class="il-dl-v" style="display:flex;gap:4px;align-items:flex-start;"
           x-show="!(stockEdit.key === supKey(G.item_name) && stockEdit.mode === 'sup')">
        <span title="Days until an order arrives, plus extra days kept in reserve" x-text="ilLeadLine(G.item_name)"></span>
        <button type="button" class="il-edit" title="Change the lead time and buffer for this item (all variants)"
                aria-label="Change the lead time and buffer for this item (all variants)"
                @click.stop="openStockEdit(G.item_name, stockFor(G.item_name))">✎</button>
      </div>
      <template x-if="stockEdit.key === supKey(G.item_name) && stockEdit.mode === 'sup'">
        <div style="display:flex;flex-wrap:wrap;gap:4px;align-items:center;font-size:11px;font-weight:400;" title="For every variant of this item">
          <label>Lead time <input type="number" min="0" max="255" step="1" x-model="stockEdit.lead"
                                  style="width:52px;border:1px solid #cbd5e1;border-radius:5px;padding:2px 4px;font-size:12px;"></label>
          <label>Buffer <input type="number" min="0" max="255" step="1" x-model="stockEdit.safety"
                               placeholder="blank = lifecycle default" title="blank = lifecycle default"
                               style="width:52px;border:1px solid #cbd5e1;border-radius:5px;padding:2px 4px;font-size:12px;"></label>
          <span class="il-sub">blank = lifecycle default</span>
          <button type="button" :disabled="stockEdit.saving" @click.stop="saveSupplySettings(G.item_name, stockEdit.lead, stockEdit.safety)"
                  style="border:0;border-radius:5px;padding:4px 9px;font-size:11px;font-weight:700;cursor:pointer;background:#4f46e5;color:#fff;">Save</button>
          <button type="button" :disabled="stockEdit.saving" @click.stop="closeStockEdit()"
                  style="border:0;border-radius:5px;padding:4px 9px;font-size:11px;font-weight:700;cursor:pointer;background:#e2e8f0;color:#334155;">Cancel</button>
        </div>
      </template>
      <div x-show="stockEdit.key === supKey(G.item_name) && stockEdit.mode === 'sup' && stockEdit.error"
           style="font-size:11px;color:#b91c1c;font-weight:600;" x-text="stockEdit.error"></div>
      @else
      <div class="il-dl-v" title="Days until an order arrives, plus extra days kept in reserve" x-text="ilLeadLine(G.item_name)"></div>
      @endif
    </div>
  </template>

  <template x-if="ilIdOn('order_qty')">
    <div class="il-dl-i il-dl-wide" title="How the qty to order is worked out from the server's numbers">
      <div class="il-dl-l">How the qty is worked out</div>
      <div class="il-dl-v" x-text="ilOrderReason(G.item_name)"></div>
    </div>
  </template>

  <template x-if="ilIdOn('lifecycle') && stockFor(G.item_name)">
    <div class="il-dl-i il-dl-wide" :title="ilTrendTip(G.item_name)">
      <div class="il-dl-l">Trend</div>
      <div class="il-dl-v" x-text="ilTrendText(G.item_name) + (stockIsLugi(G.item_name) ? ' · losing money' : '') + ' — ' + ilTrendTip(G.item_name)"></div>
    </div>
  </template>

  <div class="il-dl-i" title="Pages with ads running for this item in the range">
    <div class="il-dl-l">Running pages</div>
    <div class="il-dl-v" x-text="G.hasPages ? G.pages.length + (G.pages.length === 1 ? ' running page' : ' running pages') : 'No running ad'"></div>
  </div>

  <template x-if="ilIdOn('item_val') || ilIdOn('item_val_ceo')">
    <div class="il-dl-i" title="Cost of one piece (COGS) as of the last day of the range">
      <div class="il-dl-l">Cost per piece</div>
      <div class="il-dl-v">
        <span x-show="stockPending()" class="il-grey">…</span>
        <span x-show="!stockPending() && ilIdOn('item_val')"
              x-text="ilPiece(itemValue(G.item_name), G.item_name) === null ? '—' : ilMoney(ilPiece(itemValue(G.item_name), G.item_name))"></span>
        @if($effectiveIsCEO)
        <template x-if="!stockPending() && ilIdOn('item_val_ceo') && ilCeoPieceDiff(G.item_name) !== null">
          <span class="il-sub" title="The CEO's cost per piece, shown when it differs" x-text="'CEO value: ' + ilMoney(ilCeoPieceDiff(G.item_name))"></span>
        </template>
        @endif
      </div>
    </div>
  </template>

  <template x-if="ilIdOn('jnt_rdt')">
    <div class="il-dl-i" title="Actual J&T returned / delivered / in-transit rates, all pages of the item together">
      <div class="il-dl-l">RTS / DEL / INT</div>
      <div class="il-dl-v">
        <div x-show="ilIdOn('jnt_rts')" x-text="'RTS ' + (G.agg.jnt_rts_pct != null ? G.agg.jnt_rts_pct.toFixed(1) + '% (' + G.agg.jnt_rts_cnt + ')' : '—')"></div>
        <div x-show="ilIdOn('jnt_del')" x-text="'DEL ' + (G.agg.jnt_del_pct != null ? G.agg.jnt_del_pct.toFixed(1) + '% (' + G.agg.jnt_del_cnt + ')' : '—')"></div>
        <div x-show="ilIdOn('jnt_transit')" x-text="'INT ' + (G.agg.jnt_transit_pct != null ? G.agg.jnt_transit_pct.toFixed(1) + '% (' + G.agg.jnt_transit_cnt + ')' : '—')"></div>
      </div>
    </div>
  </template>

  <template x-if="ilIdOn('tcpr')">
    <div class="il-dl-i" title="TCPR: the % of orders that did not proceed">
      <div class="il-dl-l">TCPR</div>
      <div class="il-dl-v" x-text="G.agg.orders > 0 ? ((1 - G.agg.proceed_orders / G.agg.orders) * 100).toFixed(1) + '%' : '—'"></div>
    </div>
  </template>

  <template x-if="ilIdOn('proj_profit')">
    <div class="il-dl-i" title="Projected profit (or loss) over the whole range">
      <div class="il-dl-l">Profit (range)</div>
      <div class="il-dl-v il-num" :style="'color:' + ilTone(G.agg.projected_profit)" x-text="ilArrow(G.agg.projected_profit) + ilMoney(G.agg.projected_profit)"></div>
    </div>
  </template>
  <template x-if="ilIdOn('proj_prof_3d')">
    <div class="il-dl-i" title="Projected profit (or loss) over the last 3 days">
      <div class="il-dl-l">Profit (3 days)</div>
      <div class="il-dl-v il-num" :style="'color:' + ilTone(G.agg.projected_profit_last_3d)" x-text="ilArrow(G.agg.projected_profit_last_3d) + ilMoney(G.agg.projected_profit_last_3d)"></div>
    </div>
  </template>
  <template x-if="ilIdOn('proj_prof_7d')">
    <div class="il-dl-i" title="Projected profit (or loss) over the last 7 days, this item only">
      <div class="il-dl-l">Profit (7 days)</div>
      <div class="il-dl-v il-num" :style="'color:' + ilTone(G.agg.projected_profit_last_7d)" x-text="ilArrow(G.agg.projected_profit_last_7d) + ilMoney(G.agg.projected_profit_last_7d)"></div>
    </div>
  </template>

  <template x-if="ilIdOn('np_per_order_1m')">
    <div class="il-dl-i" title="Net profit per order over the whole range">
      <div class="il-dl-l">Profit per order (1 month)</div>
      <div class="il-dl-v il-num" :style="'color:' + ilTone(G.agg.np_per_order_1m)" x-text="ilArrow(G.agg.np_per_order_1m) + ilMoney(G.agg.np_per_order_1m)"></div>
    </div>
  </template>

  <template x-if="ilIdOn('orders')">
    <div class="il-dl-i" title="Orders in the range">
      <div class="il-dl-l">Orders</div>
      <div class="il-dl-v" x-text="num(G.agg.orders)"></div>
    </div>
  </template>
  <template x-if="ilIdOn('proceed')">
    <div class="il-dl-i" title="Orders that proceeded in the range">
      <div class="il-dl-l">Proceed</div>
      <div class="il-dl-v" x-text="num(G.agg.proceed_orders)"></div>
    </div>
  </template>

  <template x-if="categoryColVisible()">
    <div class="il-dl-i">
      <div class="il-dl-l">Category</div>
      <div class="il-dl-v" :title="ilTip('Category of this item', stockFor(G.item_name))">
        <span x-show="stockPending()" class="il-grey">…</span>
        @if($effectiveIsCEO)
        {{-- CEO: palitan ang category (para sa lahat ng variant ng base item). --}}
        <span x-show="!stockPending()" title="For every variant of this item">
          <select aria-label="Item category" :disabled="stockEdit.saving"
                  @change="saveCategory(G.item_name, $event.target.value, $event.target)"
                  style="border:1px solid #cbd5e1;border-radius:5px;padding:3px 4px;font-size:12px;max-width:100%;">
            <option value="" :selected="!stockFor(G.item_name)?.category_id">— none —</option>
            <template x-for="c in stock.categories" :key="'ilcc-'+c.id">
              <option :value="String(c.id)" :selected="String(c.id) === String(stockFor(G.item_name)?.category_id)" x-text="c.name"></option>
            </template>
            <option value="__new">+ new category</option>
          </select>
          <template x-if="stockEdit.key === supKey(G.item_name) && stockEdit.mode === 'cat'">
            <div style="margin-top:3px;display:flex;gap:4px;align-items:center;flex-wrap:wrap;">
              <input type="text" maxlength="60" aria-label="New category" x-model="stockEdit.newCat"
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

  <div class="il-dl-i il-dl-wide il-dl-btns">
    <a class="item-photo-btn" :href="'{{ route('item.photo') }}?item='+encodeURIComponent(G.item_name)+'&start_date='+startDate+'&end_date='+endDate"
       target="_blank" rel="noopener" style="text-decoration:none;" title="Upload or change the item's photo (opens a new tab)"
       x-text="itemImages[G.item_name] ? 'Change photo' : 'Add photo'"></a>
    <button type="button" class="item-copy-btn" @click.stop="copyItem(G.item_name, G.hold)" title="Copy the item name, hold and photo"
            x-text="copyState===G.item_name ? '✓ Copied' : '📋 Copy'"></button>
  </div>
</div>

@if($effectiveIsCEO)
{{-- ── CEO LANG: sourcing-list info, supplier (PO) lines, quotes + inline add / edit / delete. Lahat x-text (escaped). ── --}}
<div class="il-dl" @click.stop>
  <template x-if="worklistItem(G.item_name)">
    <template x-for="W in [worklistItem(G.item_name)]" :key="'wl-'+G.item_name">
      <div class="il-dl-i il-dl-wide" title="Details from the selected sourcing list">
        <div class="il-dl-l">Sourcing list</div>
        <div class="il-dl-v">
          <template x-if="W.variants.length > 1">
            <div style="color:#7c2d12;font-weight:700;" x-text="'total on hold: ' + num(W.hold_units)"></div>
          </template>
          <template x-if="W.list === 'i_order' && W.shortfall > 0">
            <div style="color:#b91c1c;font-weight:800;" x-text="'Short ' + num(W.shortfall) + ' — order now'"></div>
          </template>
          <template x-if="W.list === 'naka_order' && W.open_po">
            <div>
              🚚 <b x-text="W.open_po.supplier"></b>
              <span style="color:#64748b;" x-text="W.open_po.order_date + (W.open_po.orders > 1 ? ' (+' + (W.open_po.orders - 1) + ' more)' : '')"></span>
              <div x-text="'Ordered ' + num(W.open_po.ordered_qty) + ' · arrived ' + num(W.open_po.received_qty) + ' · waiting ' + num(W.open_po.open_qty)"></div>
              <div :style="(W.open_po.lead_time_days !== null && W.open_po.days_since > W.open_po.lead_time_days) ? 'color:#b91c1c;font-weight:700;' : 'color:#475569;'"
                   x-text="W.open_po.days_since + ' days ago' + (W.open_po.lead_time_days !== null ? ' / lead time ' + W.open_po.lead_time_days + ' days' : '')"></div>
            </div>
          </template>
        </div>
      </div>
    </template>
  </template>

  {{-- Supplier(s) + huling unit cost (Supply Finance, pinakabagong PO muna). --}}
  <div class="il-dl-i il-dl-wide" title="Suppliers from purchase orders, newest first, with their last unit cost">
    <div class="il-dl-l">Suppliers</div>
    <div class="il-dl-v">
      <template x-if="suppliersFor(G.item_name).length">
        <div>
          <template x-for="(s, si) in suppliersFor(G.item_name)" :key="'sup-'+G.item_name+'-'+si">
            <span :title="'PO ' + (s.order_date||'') + (s.order_no ? ' · '+s.order_no : '')">
              <span x-show="si>0" style="color:#94a3b8;"> · </span>🏭 <b x-text="s.supplier"></b> <span style="color:#065f46;font-weight:700;" x-text="money(s.unit_cost)"></span>
            </span>
          </template>
        </div>
      </template>
      <template x-if="!suppliersFor(G.item_name).length">
        <div class="il-sub" style="font-style:italic;">No purchase order yet</div>
      </template>
    </div>
  </div>

  {{-- Supplier quotes — INLINE add / edit / delete. --}}
  <div class="il-dl-i il-dl-wide" title="Supplier quotes found before ordering">
    <div class="il-dl-l">Quotes</div>
    <div class="il-dl-v">
      <template x-for="(q, qi) in quotesFor(G.item_name)" :key="'q-'+G.item_name+'-'+q.id">
        <div style="display:flex;gap:4px;align-items:center;flex-wrap:wrap;">
          <span :title="'Quote' + (q.moq ? ' · MOQ '+q.moq : '') + (q.updated_at ? ' · '+q.updated_at : '')">🏷 <b x-text="q.supplier"></b>
            <span style="color:#1d4ed8;font-weight:700;" x-text="q.price!==null ? money(q.price) : '—'"></span>
            <span x-show="q.moq" class="il-sub" x-text="q.moq ? 'MOQ '+q.moq : ''"></span>
            <span x-show="q.prev_price !== null && q.prev_price !== undefined" class="il-sub"
                  x-text="'was ' + money(q.prev_price) + (q.prev_date ? ' (' + q.prev_date + ')' : '')"></span>
          </span>
          <template x-if="q.photo_url">
            <img class="item-sq" style="width:22px;height:22px;border-color:#bfdbfe;" :src="q.photo_url" :alt="q.supplier"
                 :title="'Quote photo · ' + q.supplier" @click.stop="photoModal = { open:true, url:q.photo_url, name:q.supplier+' — '+G.item_name }">
          </template>
          <template x-if="safeLink(q.link)"><a :href="safeLink(q.link)" target="_blank" rel="noopener" @click.stop style="color:#4f46e5;">link</a></template>
          <button type="button" class="il-edit" title="Edit quote" aria-label="Edit quote" @click.stop="openQuote(G.item_name, q)">✎</button>
          <button type="button" class="il-edit" style="color:#b91c1c;" title="Delete quote" aria-label="Delete quote" @click.stop="deleteQuote(G.item_name, q)">✕</button>
        </div>
      </template>
      <template x-if="!quotesFor(G.item_name).length && !suppliersFor(G.item_name).length">
        <div style="color:#b91c1c;font-weight:700;">⚠ No supplier yet</div>
      </template>
      <template x-if="quoteForm.key === supKey(G.item_name)">
        <div style="display:flex;flex-wrap:wrap;gap:3px;margin-top:3px;align-items:center;">
          <select x-model="quoteForm.supplier_id" aria-label="Supplier" style="font-size:11px;padding:1px;max-width:130px;">
            <option value="">— supplier —</option>
            <template x-for="s in supplierList" :key="'s-'+s.id"><option :value="String(s.id)" x-text="s.name"></option></template>
          </select>
          <input type="number" step="0.01" min="0" x-model="quoteForm.price" placeholder="₱ price" aria-label="Price" style="width:78px;font-size:11px;padding:1px;">
          <input type="number" min="0" x-model="quoteForm.moq" placeholder="MOQ" aria-label="MOQ" style="width:54px;font-size:11px;padding:1px;">
          <input type="text" x-model="quoteForm.link" placeholder="link (optional)" aria-label="Link" style="width:120px;font-size:11px;padding:1px;">
          <input type="file" accept="image/jpeg,image/png,image/webp" @change="quoteForm.photo = $event.target.files[0] || null"
                 title="Photo of the supplier's product (jpg/png/webp, up to 10 MB)" aria-label="Quote photo" style="font-size:11px;max-width:170px;">
          <button type="button" class="il-edit" @click.stop="saveQuote()" x-text="quoteForm.saving ? '…' : 'Save'"></button>
          <button type="button" class="il-edit" @click.stop="quoteForm.key=null">Cancel</button>
        </div>
      </template>
      <button type="button" class="il-edit" style="margin-top:2px;" title="Add a supplier quote for this item"
              x-show="quoteForm.key !== supKey(G.item_name)" @click.stop="openQuote(G.item_name, null)">+ supplier quote</button>
    </div>
  </div>
</div>
@endif

{{-- ── PAGES ── --}}
<div class="il-pages-h" x-text="'Pages (' + G.pages.length + ')'"></div>
<template x-if="!G.hasPages">
  <div class="il-sub" style="padding:6px 2px;">No running ad — no pages to show.</div>
</template>

{{-- Ang x-for variable ay dapat `row` — iyon ang inaasahan ng shared campaigns include. --}}
<template x-for="row in G.pages" :key="row.page_key">
  <div>
    <div class="il-page-card">
      <div class="il-pc-head">
        <div class="il-pc-page">
          <template x-if="row.is_range">
            <a href="#" @click.prevent="openBreakdown(row)" class="il-pc-name il-pc-link"
               title="Open the per-date primary item breakdown" x-text="row.page_name"></a>
          </template>
          <template x-if="!row.is_range">
            <span class="il-pc-name" x-text="row.page_name"></span>
          </template>
          <template x-if="row.mixed_primary">
            <div style="cursor:pointer;" @click="openBreakdown(row)"
                 :title="row.distinct_items_in_range + ' different primary items across the ' + row.range_days + '-day range. Click to see the breakdown.'">
              <div class="il-pc-warn" style="color:#b45309;">
                ⚠ mixed primary · <span x-text="row.included_days + '/' + row.range_days + ' days'"></span>
              </div>
              <template x-if="row.anchor_first_date">
                <div class="il-sub">computed since <span x-text="fmtMD(row.anchor_first_date)"></span></div>
              </template>
            </div>
          </template>
          <template x-if="row.has_backfill">
            <div class="il-pc-warn" style="cursor:pointer;color:#dc2626;" @click="openBreakdown(row)"
                 :title="'⚠ ' + (row.backfill_dates ? row.backfill_dates.length : 0) + ' date(s) without a proper setting — filled from the earliest one. Click to see the breakdown.'"
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
                title="Show or hide this page's campaigns">
          Campaigns <span aria-hidden="true" class="il-camp-chev" :class="(expandedPages[row.page_name] || {}).open ? 'active' : ''">›</span>
        </button>
      </div>

      {{-- Lahat ng page field na nakikita ng role, sa lumang column order. Walang fill; text lang. --}}
      <div class="il-pf-grid">
        <template x-for="col in cols" :key="'pf-'+row.page_key+'-'+col.id">
          <div class="il-pf" :class="{!! !empty($isCEO) ? "['action','claude_action','claude_reason']" : "['action']" !!}.includes(col.id) ? 'il-pf-wide' : ''" x-show="ilPageFieldOn(col.id)">
            <div class="il-dl-l" x-text="col.label"></div>
            <div class="il-pf-v">
              <div style="flex:1;min-width:0;">
                <div :class="(ilPageVal(col.id, row).miss ? 'il-grey ' : '') + ((col.id === 'action' && !row._actionOpen){!! !empty($isCEO) ? " || (col.id === 'claude_action' && !row._claudeActionOpen) || (col.id === 'claude_reason' && !row._claudeReasonOpen)" : '' !!} ? 'il-clamp' : '')"
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
                @if(!empty($isCEO))
                {{-- Claude columns: more/less lang, walang ✎ --}}
                <template x-if="col.id === 'claude_action' && (row.claude_action||'').length > 60">
                  <button type="button" class="il-linkbtn" @click="row._claudeActionOpen = !row._claudeActionOpen"
                          x-text="row._claudeActionOpen ? '▾ less' : '▸ more'"></button>
                </template>
                <template x-if="col.id === 'claude_reason' && (row.claude_reason||'').length > 60">
                  <button type="button" class="il-linkbtn" @click="row._claudeReasonOpen = !row._claudeReasonOpen"
                          x-text="row._claudeReasonOpen ? '▾ less' : '▸ more'"></button>
                </template>
                @endif
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

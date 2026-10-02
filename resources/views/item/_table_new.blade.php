  {{-- Bagong layout (default) — walang horizontal scroll. Isang row kada item, 11 composite columns.
       Lahat ng text ay x-text / :title (walang x-html). Ang CSS ay naka-scope sa .il-* sa index. --}}
  <div id="scroll" class="il-scroll">
    <div class="card il-card" id="item-layout-new">
      <table class="il-table">
        <colgroup>
          <col>
          <col class="il-col-lc" style="width:112px" x-show="ilColOn('lifecycle')">
          <col style="width:104px" x-show="ilColOn('stock')">
          <col style="width:72px" x-show="ilColOn('upd')">
          <col style="width:188px" x-show="ilColOn('doi')">
          <col style="width:150px" x-show="ilColOn('order')">
          <col style="width:96px" x-show="ilColOn('kita')">
          <col style="width:150px" x-show="ilColOn('pct')">
          <col style="width:128px" x-show="ilColOn('ads')">
          <col style="width:116px" x-show="ilColOn('action')">
          <col style="width:36px">
        </colgroup>
        <thead>
          <tr>
            <th class="sortable il-th-item" :class="ac('item_name')" tabindex="0" @click="sb('item_name')" @keydown.enter="sb('item_name')"
                title="Item: pangalan, naka-hold, at mga supplier (CEO)">
              <span>ITEM</span><span x-text="arr('item_name')"></span>
            </th>
            <th class="sortable il-col-lc" :class="ac('lifecycle')" x-show="ilColOn('lifecycle')" tabindex="0" @click="sb('lifecycle')" @keydown.enter="sb('lifecycle')"
                title="Anong yugto ng buhay ng item: bago, lumalaki, stable, bumababa...">
              <span>LIFECYCLE</span><span x-text="arr('lifecycle')"></span>
            </th>
            <th class="sortable" :class="ac('stock')" x-show="ilColOn('stock')" tabindex="0" @click="sb('stock')" @keydown.enter="sb('stock')"
                title="Ilang piraso ang nasa stock, at ilan ang paparating pa mula sa supplier">
              <span>STOCK / PAPARATING</span><span x-text="arr('stock')"></span>
            </th>
            <th class="sortable" :class="ac('units_per_day')" x-show="ilColOn('upd')" tabindex="0" @click="sb('units_per_day')" @keydown.enter="sb('units_per_day')"
                title="Average na piraso na nabebenta kada araw">
              <span>BENTA/ARAW</span><span x-text="arr('units_per_day')"></span>
            </th>
            <th class="sortable" :class="ac('doi')" x-show="ilColOn('doi')" tabindex="0" @click="sb('doi')" @keydown.enter="sb('doi')"
                title="Aabot pa ba ang stock hanggang sa dumating ang bagong order?">
              <span>AABOT PA?</span><span x-text="arr('doi')"></span>
            </th>
            <th class="sortable" :class="ac('order_qty')" x-show="ilColOn('order')" tabindex="0" @click="sb('order_qty')" @keydown.enter="sb('order_qty')"
                title="Ilang piraso ang dapat i-order, at kailan">
              <span>I-ORDER</span><span x-text="arr('order_qty')"></span>
            </th>
            <th class="sortable" :class="ac('projected_profit_last_day')" x-show="ilColOn('kita')" tabindex="0" @click="sb('projected_profit_last_day')" @keydown.enter="sb('projected_profit_last_day')"
                title="Kita (o lugi) ngayong huling araw, at ilang order">
              <span>KITA NGAYON</span><span x-text="arr('projected_profit_last_day')"></span>
            </th>
            <th class="sortable" :class="ac('proj_pct_last_7d')" x-show="ilColOn('pct')" tabindex="0" @click="sb('proj_pct_last_7d')" @keydown.enter="sb('proj_pct_last_7d')"
                title="Kita bilang % ng benta: 1 araw, 3 araw, 7 araw, buong range">
              <span>KITA %</span><span x-text="arr('proj_pct_last_7d')"></span>
            </th>
            <th class="sortable" :class="ac('adspent')" x-show="ilColOn('ads')" tabindex="0" @click="sb('adspent')" @keydown.enter="sb('adspent')"
                title="Gastos sa ads, kasama ang CPP at breakeven CPP">
              <span>ADS</span><span x-text="arr('adspent')"></span>
            </th>
            <th class="sortable" :class="ac('action_at')" x-show="ilColOn('action')" tabindex="0" @click="sb('action_at')" @keydown.enter="sb('action_at')"
                title="Pinakabagong action note sa mga page ng item">
              <span>ACTION</span><span x-text="arr('action_at')"></span>
            </th>
            <th class="il-chev-th" title="Ipakita/itago ang detalye ng item"><span class="sr-only" style="position:absolute;left:-9999px;">Detalye</span></th>
          </tr>
        </thead>

        {{-- Walang data / loading / walang natira sa filter (parehong kondisyon ng lumang table). --}}
        <tbody>
          <template x-if="rows.length === 0 && !loading">
            <tr class="il-empty-row"><td :colspan="ilColspan()">No data for selected date.</td></tr>
          </template>
          @if(!empty($effectiveIsCEO))
          <template x-if="worklist.list !== 'lahat' && !itemGroups().length && !(rows.length === 0 && loading)">
            <tr class="il-empty-row"><td :colspan="ilColspan()"
                x-text="worklist.error ? worklist.error : (worklist.loading || !worklist.loaded || !holdLoaded ? 'Loading…' : 'Walang item sa listahang ito.')"></td></tr>
          </template>
          @endif
          <template x-if="categoryFilter !== '' && categoryColVisible() && (!effectiveIsCeo || worklist.list === 'lahat') && !itemGroups().length && !(rows.length === 0 && loading)">
            <tr class="il-empty-row"><td :colspan="ilColspan()"
                x-text="!stock.loaded ? 'Loading…' : 'Walang item sa category na ito.'"></td></tr>
          </template>
          <template x-if="rows.length === 0 && loading">
            <tr class="il-empty-row"><td :colspan="ilColspan()"><span class="spin" style="margin-right:6px;"></span>Loading…</td></tr>
          </template>
        </tbody>

        {{-- Isang <tbody> kada item: ang main row + (naka-hold ang lugar ng) expanded block. --}}
        <template x-for="G in itemGroups()" :key="'il-'+G.item_name">
          <tbody>
            <tr class="il-row" :class="!G.hasPages ? 'il-nopage' : ''" @click="toggleItemExpand(G.item_name)">

              {{-- ITEM --}}
              <td class="il-item">
                <div class="il-item-top">
                  <template x-if="itemImages[G.item_name]">
                    <img class="item-sq" :src="itemImages[G.item_name]" :alt="G.item_name"
                         @click.stop="viewItemPhoto(G.item_name)" title="View photo">
                  </template>
                  <template x-if="!itemImages[G.item_name]">
                    <span class="item-sq item-sq-empty">🖼</span>
                  </template>
                  <div class="il-item-main">
                    <div class="il-name" x-text="G.item_name"></div>
                    <div>
                      <span class="il-hold-chip" title="Ilang piraso ang naka-hold (may order na, hindi pa naipapadala)"
                            x-text="'Naka-hold: ' + num(G.hold)"></span>
                    </div>
                    <template x-if="G.hasPages">
                      <div class="il-sub" x-text="G.pages.length + (G.pages.length === 1 ? ' running page' : ' running pages')"></div>
                    </template>
                    <template x-if="!G.hasPages">
                      <div class="il-warn">⚠ walang running page</div>
                    </template>
                    <div class="il-lc-under" x-show="ilColOn('lifecycle')">@include('item._il_lifecycle')</div>
                  </div>
                </div>
                @if($effectiveIsCEO)
                {{-- Extra info ng napiling sourcing list — CEO LANG. Lahat x-text (escaped). --}}
                <template x-if="worklistItem(G.item_name)">
                  <template x-for="W in [worklistItem(G.item_name)]" :key="'wl-'+G.item_name">
                    <div style="font-size:11px;line-height:1.45;margin-top:3px;">
                      <template x-if="W.variants.length > 1">
                        <div style="color:#7c2d12;font-weight:700;" x-text="'kabuuan: ' + num(W.hold_units)"></div>
                      </template>
                      <template x-if="W.list === 'i_order' && W.shortfall > 0">
                        <div style="color:#b91c1c;font-weight:800;" x-text="'Kulang ' + num(W.shortfall) + ' — i-order na'"></div>
                      </template>
                      <template x-if="W.list === 'naka_order' && W.open_po">
                        <div>
                          🚚 <b x-text="W.open_po.supplier"></b>
                          <span style="color:#64748b;" x-text="W.open_po.order_date + (W.open_po.orders > 1 ? ' (+'+(W.open_po.orders-1)+' pa)' : '')"></span>
                          <div x-text="'Naka-order ' + num(W.open_po.ordered_qty) + ' · dumating ' + num(W.open_po.received_qty) + ' · hinihintay ' + num(W.open_po.open_qty)"></div>
                          <div :style="(W.open_po.lead_time_days !== null && W.open_po.days_since > W.open_po.lead_time_days) ? 'color:#b91c1c;font-weight:700;' : 'color:#475569;'"
                               x-text="W.open_po.days_since + ' araw na' + (W.open_po.lead_time_days !== null ? ' / lead time ' + W.open_po.lead_time_days + ' araw' : '')"></div>
                        </div>
                      </template>
                    </div>
                  </template>
                </template>
                {{-- Supplier(s) + huling unit cost (Supply Finance) — CEO LANG; wala sa markup ng hindi-CEO. --}}
                <div style="font-size:11px;margin-top:3px;line-height:1.4;font-weight:400;">
                  <template x-if="suppliersFor(G.item_name).length">
                    <div style="color:#0f172a;">
                      <template x-for="(s, si) in suppliersFor(G.item_name)" :key="'sup-'+G.item_name+'-'+si">
                        <span :title="'PO ' + (s.order_date||'') + (s.order_no ? ' · '+s.order_no : '')">
                          <span x-show="si>0" style="color:#94a3b8;"> · </span>🏭 <b x-text="s.supplier"></b> <span style="color:#065f46;font-weight:700;" x-text="money(s.unit_cost)"></span>
                        </span>
                      </template>
                    </div>
                  </template>
                  <template x-if="!suppliersFor(G.item_name).length">
                    <div class="il-sub" style="font-style:italic;">walang supplier</div>
                  </template>
                </div>
                {{-- Supplier quotes — INLINE add / edit / delete (CEO LANG). --}}
                <div style="font-size:11px;margin-top:2px;line-height:1.45;font-weight:400;" @click.stop>
                  <template x-for="(q, qi) in quotesFor(G.item_name)" :key="'q-'+G.item_name+'-'+q.id">
                    <div style="display:flex;gap:4px;align-items:center;flex-wrap:wrap;">
                      <span :title="'Quote' + (q.moq ? ' · MOQ '+q.moq : '') + (q.updated_at ? ' · '+q.updated_at : '')">🏷 <b x-text="q.supplier"></b>
                        <span style="color:#1d4ed8;font-weight:700;" x-text="q.price!==null ? money(q.price) : '—'"></span>
                        <span x-show="q.moq" class="il-sub" x-text="q.moq ? 'MOQ '+q.moq : ''"></span>
                        <span x-show="q.prev_price !== null && q.prev_price !== undefined" class="il-sub"
                              x-text="'dati '+money(q.prev_price)+(q.prev_date ? ' ('+q.prev_date+')' : '')"></span>
                      </span>
                      <template x-if="q.photo_url">
                        <img class="item-sq" style="width:22px;height:22px;border-color:#bfdbfe;" :src="q.photo_url" :alt="q.supplier"
                             :title="'Quote photo · '+q.supplier" @click.stop="photoModal = { open:true, url:q.photo_url, name:q.supplier+' — '+G.item_name }">
                      </template>
                      <template x-if="safeLink(q.link)"><a :href="safeLink(q.link)" target="_blank" rel="noopener" @click.stop style="color:#4f46e5;">link</a></template>
                      <button type="button" class="il-edit" title="I-edit ang quote" aria-label="I-edit ang quote" @click.stop="openQuote(G.item_name, q)">✎</button>
                      <button type="button" class="il-edit" style="color:#b91c1c;" title="Tanggalin ang quote" aria-label="Tanggalin ang quote" @click.stop="deleteQuote(G.item_name, q)">✕</button>
                    </div>
                  </template>
                  <template x-if="!quotesFor(G.item_name).length && !suppliersFor(G.item_name).length">
                    <div style="color:#b91c1c;font-weight:700;">⚠ wala pang supplier</div>
                  </template>
                  <template x-if="quoteForm.key === supKey(G.item_name)">
                    <div style="display:flex;flex-wrap:wrap;gap:3px;margin-top:3px;align-items:center;">
                      <select x-model="quoteForm.supplier_id" aria-label="Supplier" style="font-size:11px;padding:1px;max-width:130px;">
                        <option value="">— supplier —</option>
                        <template x-for="s in supplierList" :key="'s-'+s.id"><option :value="String(s.id)" x-text="s.name"></option></template>
                      </select>
                      <input type="number" step="0.01" min="0" x-model="quoteForm.price" placeholder="₱ presyo" aria-label="Presyo" style="width:78px;font-size:11px;padding:1px;">
                      <input type="number" min="0" x-model="quoteForm.moq" placeholder="MOQ" aria-label="MOQ" style="width:54px;font-size:11px;padding:1px;">
                      <input type="text" x-model="quoteForm.link" placeholder="link (opsyonal)" aria-label="Link" style="width:120px;font-size:11px;padding:1px;">
                      <input type="file" accept="image/jpeg,image/png,image/webp" @change="quoteForm.photo = $event.target.files[0] || null"
                             title="Photo ng produkto ng supplier (jpg/png/webp, hanggang 10 MB)" style="font-size:11px;max-width:170px;">
                      <button type="button" class="il-edit" @click.stop="saveQuote()" x-text="quoteForm.saving ? '…' : 'Save'"></button>
                      <button type="button" class="il-edit" @click.stop="quoteForm.key=null">Cancel</button>
                    </div>
                  </template>
                  <button type="button" class="il-edit" style="margin-top:2px;"
                          x-show="quoteForm.key !== supKey(G.item_name)" @click.stop="openQuote(G.item_name, null)">+ supplier quote</button>
                </div>
                @endif
              </td>

              {{-- LIFECYCLE (nakatago sa ibaba ng 1,440 px; nasa ilalim ng pangalan na) --}}
              <td class="il-col-lc" x-show="ilColOn('lifecycle')">@include('item._il_lifecycle')</td>

              {{-- STOCK / PAPARATING --}}
              <td x-show="ilColOn('stock')" :title="stockTip('Stock = ilang piraso ang natitira; Paparating = naka-order sa supplier na hindi pa dumarating', stockFor(G.item_name))">
                <span x-show="stockPending()" class="il-grey">…</span>
                <div x-show="!stockPending()" style="display:flex;flex-wrap:wrap;gap:0 4px;justify-content:center;">
                  <template x-if="ilIdOn('stock')">
                    <span>
                      <template x-if="stock.ready && stockFor(G.item_name) && stockFor(G.item_name).stock_needs_count">
                        <span class="il-grey il-nb" :title="'May stock na hindi nabilang bago ang ' + stock.start + '; bilangin muna'"
                              x-text="'Hindi pa nabibilang' + (ilIdOn('incoming') ? ' ·' : '')"></span>
                      </template>
                      <template x-if="!(stock.ready && stockFor(G.item_name) && stockFor(G.item_name).stock_needs_count)">
                        <span class="il-nb" x-text="'Stock ' + ((stock.ready && stockFor(G.item_name)) ? num(stockFor(G.item_name).stock) : '—') + (ilIdOn('incoming') ? ' ·' : '')"></span>
                      </template>
                    </span>
                  </template>
                  <template x-if="ilIdOn('incoming')">
                    <span class="il-nb" x-text="'Paparating ' + (stockFor(G.item_name) ? num(stockFor(G.item_name).incoming) : '—')"></span>
                  </template>
                </div>
              </td>

              {{-- BENTA/ARAW --}}
              <td x-show="ilColOn('upd')"
                  :title="stockTip(stockSet(G.item_name) ? 'average ng huling ' + stockSet(G.item_name).velocity_days + ' araw' : 'average na benta kada araw', stockFor(G.item_name))">
                <span x-show="stockPending()" class="il-grey">…</span>
                <span x-show="!stockPending()" x-text="stockSet(G.item_name) ? Number(stockSet(G.item_name).units_per_day).toFixed(1) : '—'"></span>
              </td>

              {{-- AABOT PA? --}}
              <td x-show="ilColOn('doi')" style="text-align:left;">
                <span x-show="stockPending()" class="il-grey">…</span>
                <template x-if="!stockPending()">
                  <div>
                    <div class="il-state" :class="'il-tone-' + ilAabot(G.item_name).tone"
                         :title="stockTip(ilAabot(G.item_name).tip, stockFor(G.item_name))" x-text="ilAabot(G.item_name).text"></div>
                    <template x-if="stockFor(G.item_name)">
                      @if($effectiveIsCEO)
                      <div @click.stop>
                        <div class="il-sub" style="display:flex;gap:4px;align-items:flex-start;"
                             x-show="!(stockEdit.key === supKey(G.item_name) && stockEdit.mode === 'sup')">
                          <span x-text="ilLeadLine(G.item_name)"></span>
                          <button type="button" class="il-edit" title="Palitan ang lead at palugit ng item na ito (para sa lahat ng variant)"
                                  aria-label="Palitan ang lead at palugit ng item na ito (para sa lahat ng variant)"
                                  @click.stop="openStockEdit(G.item_name, stockFor(G.item_name))">✎</button>
                        </div>
                        <template x-if="stockEdit.key === supKey(G.item_name) && stockEdit.mode === 'sup'">
                          <div style="display:flex;flex-wrap:wrap;gap:4px;align-items:center;font-size:11px;font-weight:400;" title="para sa lahat ng variant ng item na ito">
                            <label>lead <input type="number" min="0" max="255" step="1" x-model="stockEdit.lead"
                                               style="width:52px;border:1px solid #cbd5e1;border-radius:5px;padding:2px 4px;font-size:12px;"></label>
                            <label>palugit <input type="number" min="0" max="255" step="1" x-model="stockEdit.safety"
                                                  placeholder="blank = default ng lifecycle" title="blank = default ng lifecycle"
                                                  style="width:52px;border:1px solid #cbd5e1;border-radius:5px;padding:2px 4px;font-size:12px;"></label>
                            <span class="il-sub">blank = default ng lifecycle</span>
                            <button type="button" :disabled="stockEdit.saving" @click.stop="saveSupplySettings(G.item_name, stockEdit.lead, stockEdit.safety)"
                                    style="border:0;border-radius:5px;padding:4px 9px;font-size:11px;font-weight:700;cursor:pointer;background:#4f46e5;color:#fff;">Save</button>
                            <button type="button" :disabled="stockEdit.saving" @click.stop="closeStockEdit()"
                                    style="border:0;border-radius:5px;padding:4px 9px;font-size:11px;font-weight:700;cursor:pointer;background:#e2e8f0;color:#334155;">Cancel</button>
                          </div>
                        </template>
                        <div x-show="stockEdit.key === supKey(G.item_name) && stockEdit.mode === 'sup' && stockEdit.error"
                             style="font-size:11px;color:#b91c1c;font-weight:600;" x-text="stockEdit.error"></div>
                      </div>
                      @else
                      <div class="il-sub" title="Lead = ilang araw bago dumating ang order; palugit = dagdag na araw na reserba"
                           x-text="ilLeadLine(G.item_name)"></div>
                      @endif
                    </template>
                  </div>
                </template>
              </td>

              {{-- I-ORDER --}}
              <td x-show="ilColOn('order')" :title="stockTip(ilOrderReason(G.item_name), stockFor(G.item_name))">
                <span x-show="stockPending()" class="il-grey">…</span>
                <template x-if="!stockPending()">
                  <div>
                    <div class="il-qty" x-text="ilQtyText(G.item_name)"></div>
                    <template x-if="ilPill(G.item_name)">
                      <span class="il-pill" :class="'il-pill-' + ilPill(G.item_name).tone" x-text="ilPill(G.item_name).text"></span>
                    </template>
                    @if($effectiveIsCEO)
                    <template x-if="(ilIdOn('item_val') || ilIdOn('item_val_ceo')) && ilPieceCost(G.item_name) !== null && stockSet(G.item_name) && stockSet(G.item_name).order_qty > 0">
                      <div class="il-sub" x-text="'≈ ₱' + num(Math.round(stockSet(G.item_name).order_qty * ilPieceCost(G.item_name)))"></div>
                    </template>
                    @endif
                  </div>
                </template>
              </td>

              {{-- KITA NGAYON --}}
              <td x-show="ilColOn('kita')">
                <template x-if="ilIdOn('proj_prof_1d')">
                  <div class="il-num" :style="'color:' + ilTone(G.agg.projected_profit_last_day)"
                       x-text="ilArrow(G.agg.projected_profit_last_day) + ilMoney(G.agg.projected_profit_last_day)"></div>
                </template>
                <template x-if="ilIdOn('orders_1d')">
                  <div class="il-sub" x-text="num(G.agg.orders_last_day) + ' orders'"></div>
                </template>
              </td>

              {{-- KITA % (2×2) --}}
              <td x-show="ilColOn('pct')" title="Kita bilang % ng benta: 1 araw, 3 araw, 7 araw, buong range">
                <div class="il-pct-grid">
                  <template x-if="ilIdOn('proj_pct_1d')"><span :style="'color:' + ilTone(G.agg.proj_pct_1d)" x-text="'1D ' + ilPct(G.agg.proj_pct_1d)"></span></template>
                  <template x-if="ilIdOn('proj_pct_3d')"><span :style="'color:' + ilTone(G.agg.proj_pct_3d)" x-text="'3D ' + ilPct(G.agg.proj_pct_3d)"></span></template>
                  <template x-if="ilIdOn('proj_pct_7d')"><span :style="'color:' + ilTone(G.agg.proj_pct_7d)" x-text="'7D ' + ilPct(G.agg.proj_pct_7d)"></span></template>
                  <template x-if="ilIdOn('proj_pct')"><span :style="'color:' + ilTone(G.agg.proj_pct)" x-text="'1M ' + ilPct(G.agg.proj_pct)"></span></template>
                </div>
              </td>

              {{-- ADS --}}
              <td x-show="ilColOn('ads')">
                <template x-if="ilIdOn('adspent')">
                  <div class="il-num" x-text="ilMoney(G.agg.adspent)"></div>
                </template>
                <div class="il-sub" x-text="ilAdsLine(G)"></div>
              </td>

              {{-- ACTION — pinakabagong note; ang pag-edit ay per page (nasa expanded block) --}}
              <td x-show="ilColOn('action')">
                <template x-if="ilLatestAction(G)">
                  <div class="il-ellipsis" :title="ilLatestAction(G).page + ': ' + ilLatestAction(G).text" x-text="ilLatestAction(G).text"></div>
                </template>
                <template x-if="!ilLatestAction(G)"><span class="il-grey">—</span></template>
              </td>

              {{-- › --}}
              <td>
                <button type="button" class="il-chev" :class="isItemOpen(G.item_name) ? 'active' : ''"
                        :aria-expanded="isItemOpen(G.item_name) ? 'true' : 'false'"
                        title="Ipakita/itago ang detalye ng item" aria-label="Ipakita/itago ang detalye ng item"
                        @click.stop="toggleItemExpand(G.item_name)">›</button>
              </td>
            </tr>

            {{-- Expanded block — lalagyan ng laman sa T4. --}}
            <tr x-show="isItemOpen(G.item_name)" class="il-expand-row">
              <td :colspan="ilColspan()"><div class="il-expand" data-expand-hook></div></td>
            </tr>
          </tbody>
        </template>
      </table>
    </div>
  </div>

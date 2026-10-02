{{-- To order (006, default tab). Isang root element lang (<table>) dahil nasa <template x-if> ng index.
     Isang state kada cell + isang sub-line. Column grant = data gate (ilIdOn ng source id, gaya ng 005).
     Lahat ng text ay x-text / :title (walang x-html). CSS = .il-* sa index. --}}
<table class="il-table" id="il-table-order">
  <colgroup>
    <col>
    <col style="width:250px" x-show="ilIdOn('order_qty')">
    <col style="width:130px" x-show="ilIdOn('order_qty')">
    <col style="width:120px" x-show="ilIdOn('doi')">
    <col style="width:130px" x-show="ilIdOn('proj_pct_7d')">
    <col style="width:130px" x-show="ilIdOn('lifecycle')">
    <col style="width:36px">
  </colgroup>
  <thead>
    <tr>
      <th class="sortable il-th-item" :class="ac('item_name')" tabindex="0" @click="sb('item_name')" @keydown.enter="sb('item_name')"
          title="The item, and how many pieces are on hold (ordered by customers, not shipped yet)"><span>Item</span><span x-text="arr('item_name')"></span></th>
      <th class="sortable" x-show="ilIdOn('order_qty')" :class="ac('il_next')" tabindex="0" @click="sb('il_next')" @keydown.enter="sb('il_next')"
          title="The one thing to do next for this item"><span>Next step</span><span x-text="arr('il_next')"></span></th>
      <th class="sortable" x-show="ilIdOn('order_qty')" :class="ac('order_qty')" tabindex="0" @click="sb('order_qty')" @keydown.enter="sb('order_qty')"
          title="How many pieces to order now"><span>Qty to order</span><span x-text="arr('order_qty')"></span></th>
      <th class="sortable" x-show="ilIdOn('doi')" :class="ac('doi')" tabindex="0" @click="sb('doi')" @keydown.enter="sb('doi')"
          title="How many days the stock and incoming pieces last after the orders on hold"><span>Days left</span><span x-text="arr('doi')"></span></th>
      <th class="sortable" x-show="ilIdOn('proj_pct_7d')" :class="ac('il_profit7')" tabindex="0" @click="sb('il_profit7')" @keydown.enter="sb('il_profit7')"
          title="Profit as a % of sales over the last 7 days (all variants together), and the profit in pesos"><span>Profit (7 days)</span><span x-text="arr('il_profit7')"></span></th>
      <th class="sortable" x-show="ilIdOn('lifecycle')" :class="ac('lifecycle')" tabindex="0" @click="sb('lifecycle')" @keydown.enter="sb('lifecycle')"
          title="Where the item's sales are going: new, growing, steady, slowing..."><span>Trend</span><span x-text="arr('lifecycle')"></span></th>
      <th class="il-chev-th" title="Show or hide the item details"><span class="sr-only" style="position:absolute;left:-9999px;">Details</span></th>
    </tr>
  </thead>

  {{-- Walang data / walang natira sa filter / loading (parehong kondisyon ng 005). --}}
  <tbody>
    <template x-if="rows.length === 0 && !loading">
      <tr class="il-empty-row"><td :colspan="ilOrderColspan()">No data for the selected dates.</td></tr>
    </template>
    @if(!empty($effectiveIsCEO))
    <template x-if="worklist.list !== 'lahat' && !itemGroups().length && !(rows.length === 0 && loading)">
      <tr class="il-empty-row"><td :colspan="ilOrderColspan()"
          x-text="worklist.error ? worklist.error : (worklist.loading || !worklist.loaded || !holdLoaded ? 'Loading…' : 'No items in this list.')"></td></tr>
    </template>
    @endif
    <template x-if="categoryFilter !== '' && categoryColVisible() && (!effectiveIsCeo || worklist.list === 'lahat') && !itemGroups().length && !(rows.length === 0 && loading)">
      <tr class="il-empty-row"><td :colspan="ilOrderColspan()"
          x-text="!stock.loaded ? 'Loading…' : 'No items in this category.'"></td></tr>
    </template>
    <template x-if="rows.length === 0 && loading">
      <tr class="il-empty-row"><td :colspan="ilOrderColspan()"><span class="spin" style="margin-right:6px;"></span>Loading…</td></tr>
    </template>
  </tbody>

  {{-- Isang <tbody> kada item: main row + details (›). Peach row = walang running page. --}}
  <template x-for="G in itemGroups()" :key="'il-'+G.item_name">
    <tbody>
      <tr class="il-row" :class="!G.hasPages ? 'il-nopage' : ''" @click="toggleItemExpand(G.item_name)">
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
              <template x-if="G.hold > 0">
                <div class="il-sub" title="Pieces ordered by customers, not shipped yet" x-text="num(G.hold) + ' on hold'"></div>
              </template>
            </div>
          </div>
        </td>
        {{-- Next step: icon + salita + kulay, at isang maikling dahilan. --}}
        <td x-show="ilIdOn('order_qty')" style="text-align:left;">
          <span x-show="stockPending()" class="il-grey">…</span>
          <template x-if="!stockPending()">
            <template x-for="N in [ilNext(G.item_name)]" :key="'nx-'+G.item_name">
              <div :title="ilTip(N.tip, stockFor(G.item_name))">
                <div class="il-state" :class="'il-tone-' + N.tone" x-text="N.text"></div>
                <template x-if="ilReason(G.item_name)">
                  <div class="il-sub" x-text="ilReason(G.item_name)"></div>
                </template>
              </div>
            </template>
          </template>
        </td>

        {{-- Qty to order (+ "≈ ₱" CEO lang) --}}
        <td x-show="ilIdOn('order_qty')" :title="ilTip('How many pieces to order now', stockFor(G.item_name))">
          <span x-show="stockPending()" class="il-grey">…</span>
          <template x-if="!stockPending()">
            <div>
              <div :class="ilQty(G.item_name) ? 'il-qty' : 'il-grey'" x-text="ilQty(G.item_name) || '—'"></div>
              @if($effectiveIsCEO)
              <template x-if="ilQtyCost(G.item_name)">
                <div class="il-sub" title="Estimated cost: qty × the cheapest quote's price, or the item value per piece" x-text="ilQtyCost(G.item_name)"></div>
              </template>
              @endif
            </div>
          </template>
        </td>

        {{-- Days left --}}
        <td x-show="ilIdOn('doi')">
          <span x-show="stockPending()" class="il-grey">…</span>
          <template x-if="!stockPending()">
            <template x-for="D in [ilDaysLeft(G.item_name)]" :key="'dl-'+G.item_name">
              <div class="il-state" :class="'il-tone-' + D.tone" :title="ilTip(D.tip, stockFor(G.item_name))" x-text="D.text"></div>
            </template>
          </template>
        </td>

        {{-- Profit (7 days): pinagsamang base item; mapusyaw na kulay ng rule ng owner --}}
        <td x-show="ilIdOn('proj_pct_7d')" :title="ilProfit7Tip(G.item_name)">
          <template x-if="baseProfitPct7(G.item_name) === null">
            <span class="il-grey" title="No profit data for the last 7 days">—</span>
          </template>
          <template x-if="baseProfitPct7(G.item_name) !== null">
            <div class="il-cf" :style="ilCfTint('proj_pct_7d', baseProfitPct7(G.item_name))">
              <div class="il-num" x-text="ilPct(baseProfitPct7(G.item_name))"></div>
              <template x-if="ilIdOn('proj_prof_7d')">
                <div class="il-cf-sub" x-text="ilPesoWhole(baseProfit7(G.item_name))"></div>
              </template>
            </div>
          </template>
        </td>

        {{-- Trend --}}
        <td x-show="ilIdOn('lifecycle')">@include('item._il_lifecycle')</td>
        <td class="il-chev-td">
          <button type="button" class="il-chev" :class="isItemOpen(G.item_name) ? 'active' : ''"
                  :aria-expanded="isItemOpen(G.item_name) ? 'true' : 'false'"
                  title="Show or hide the item details" aria-label="Show or hide the item details"
                  @click.stop="toggleItemExpand(G.item_name)">›</button>
        </td>
      </tr>

      {{-- Details — nire-render lang kapag bukas (x-if) para hindi mabigat ang daan-daang item. --}}
      <tr x-show="isItemOpen(G.item_name)" class="il-expand-row">
        <td :colspan="ilOrderColspan()">
          <div class="il-expand">
            <template x-if="isItemOpen(G.item_name)">
              <div>
                @include('item._il_expand')
              </div>
            </template>
          </div>
        </td>
      </tr>
    </tbody>
  </template>

  {{-- TOTAL: mga row na pula ang Next step sa mga nakikita (filter + chip + category). Isang compute lang kada render. --}}
  <template x-if="itemGroups().length > 0">
    <tbody>
      <template x-for="T in [ilOrderTotal()]" :key="'il-ot'">
        <tr class="il-total">
          <td :colspan="ilOrderColspan()" style="text-align:left;padding-left:10px;"
              title="Shown items whose next step is red (Find a supplier, Order now): how many, how many pieces to order, and the estimated cost">
            <span x-text="T.items ? 'To order now: ' + num(T.items) + (T.items === 1 ? ' item · ' : ' items · ') + num(T.pcs) + ' pcs' : 'To order now: nothing urgent'"></span>
            @if($effectiveIsCEO)
            <span x-show="T.items && T.peso !== null" x-text="' · ≈ ₱' + num(Math.round(T.peso))"></span>
            @endif
          </td>
        </tr>
      </template>
    </tbody>
  </template>
</table>

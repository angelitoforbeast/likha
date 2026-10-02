{{-- To order (006, default tab). Isang root element lang (<table>) dahil nasa <template x-if> ng index.
     T1: headers + Item cell + › details; ang ibang cells ay pupunuin ng T2 (Next step, Qty, Days left, Profit, Trend).
     Lahat ng text ay x-text / :title (walang x-html). CSS = .il-* sa index. --}}
<table class="il-table" id="il-table-order">
  <colgroup>
    <col>
    <col style="width:250px">
    <col style="width:130px">
    <col style="width:120px">
    <col style="width:130px">
    <col style="width:130px">
    <col style="width:36px">
  </colgroup>
  <thead>
    <tr>
      <th class="sortable il-th-item" :class="ac('item_name')" tabindex="0" @click="sb('item_name')" @keydown.enter="sb('item_name')"
          title="The item, and how many pieces are on hold (ordered by customers, not shipped yet)"><span>Item</span><span x-text="arr('item_name')"></span></th>
      <th title="The one thing to do next for this item"><span>Next step</span></th>
      <th class="sortable" :class="ac('order_qty')" tabindex="0" @click="sb('order_qty')" @keydown.enter="sb('order_qty')"
          title="How many pieces to order now"><span>Qty to order</span><span x-text="arr('order_qty')"></span></th>
      <th class="sortable" :class="ac('doi')" tabindex="0" @click="sb('doi')" @keydown.enter="sb('doi')"
          title="How many days the stock and incoming pieces last after the orders on hold"><span>Days left</span><span x-text="arr('doi')"></span></th>
      <th title="Profit as a % of sales over the last 7 days, and the profit in pesos"><span>Profit (7 days)</span></th>
      <th class="sortable" :class="ac('lifecycle')" tabindex="0" @click="sb('lifecycle')" @keydown.enter="sb('lifecycle')"
          title="Where the item's sales are going: new, growing, steady, slowing..."><span>Trend</span><span x-text="arr('lifecycle')"></span></th>
      <th class="il-chev-th" title="Show or hide the item details"><span class="sr-only" style="position:absolute;left:-9999px;">Details</span></th>
    </tr>
  </thead>

  {{-- Walang data / walang natira sa filter / loading (parehong kondisyon ng 005). --}}
  <tbody>
    <template x-if="rows.length === 0 && !loading">
      <tr class="il-empty-row"><td colspan="7">No data for the selected dates.</td></tr>
    </template>
    @if(!empty($effectiveIsCEO))
    <template x-if="worklist.list !== 'lahat' && !itemGroups().length && !(rows.length === 0 && loading)">
      <tr class="il-empty-row"><td colspan="7"
          x-text="worklist.error ? worklist.error : (worklist.loading || !worklist.loaded || !holdLoaded ? 'Loading…' : 'No items in this list.')"></td></tr>
    </template>
    @endif
    <template x-if="categoryFilter !== '' && categoryColVisible() && (!effectiveIsCeo || worklist.list === 'lahat') && !itemGroups().length && !(rows.length === 0 && loading)">
      <tr class="il-empty-row"><td colspan="7"
          x-text="!stock.loaded ? 'Loading…' : 'No items in this category.'"></td></tr>
    </template>
    <template x-if="rows.length === 0 && loading">
      <tr class="il-empty-row"><td colspan="7"><span class="spin" style="margin-right:6px;"></span>Loading…</td></tr>
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
        {{-- T2: Next step, Qty to order, Days left, Profit (7 days), Trend. --}}
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td class="il-chev-td">
          <button type="button" class="il-chev" :class="isItemOpen(G.item_name) ? 'active' : ''"
                  :aria-expanded="isItemOpen(G.item_name) ? 'true' : 'false'"
                  title="Show or hide the item details" aria-label="Show or hide the item details"
                  @click.stop="toggleItemExpand(G.item_name)">›</button>
        </td>
      </tr>

      {{-- Details — nire-render lang kapag bukas (x-if) para hindi mabigat ang daan-daang item. --}}
      <tr x-show="isItemOpen(G.item_name)" class="il-expand-row">
        <td colspan="7">
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
</table>

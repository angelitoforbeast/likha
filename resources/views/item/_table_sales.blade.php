{{-- Sales & Profit (006, ?tab=sales). Isang root element lang (<table>) dahil nasa <template x-if> ng index.
     T1: headers + Item cell + › details; ang ibang cells ay pupunuin ng T4 (orders, profit, profit %, ads, CPP).
     Lahat ng text ay x-text / :title (walang x-html). CSS = .il-* sa index. --}}
<table class="il-table" id="il-table-sales">
  <colgroup>
    <col>
    <col style="width:90px">
    <col style="width:120px">
    <col style="width:336px">
    <col style="width:110px">
    <col style="width:130px">
    <col style="width:36px">
  </colgroup>
  <thead>
    <tr>
      <th class="sortable il-th-item" :class="ac('item_name')" tabindex="0" @click="sb('item_name')" @keydown.enter="sb('item_name')"
          title="The item, and how many pieces are on hold (ordered by customers, not shipped yet)"><span>Item</span><span x-text="arr('item_name')"></span></th>
      <th class="sortable" :class="ac('orders_last_day')" tabindex="0" @click="sb('orders_last_day')" @keydown.enter="sb('orders_last_day')"
          title="Orders on the last day of the range"><span>Orders today</span><span x-text="arr('orders_last_day')"></span></th>
      <th class="sortable" :class="ac('projected_profit_last_day')" tabindex="0" @click="sb('projected_profit_last_day')" @keydown.enter="sb('projected_profit_last_day')"
          title="Projected profit (or loss) on the last day of the range"><span>Profit today</span><span x-text="arr('projected_profit_last_day')"></span></th>
      <th class="sortable" :class="ac('proj_pct_last_7d')" tabindex="0" @click="sb('proj_pct_last_7d')" @keydown.enter="sb('proj_pct_last_7d')"
          title="Profit as a % of sales: today, 3 days, 7 days and 1 month"><span>Profit %</span><span x-text="arr('proj_pct_last_7d')"></span></th>
      <th class="sortable" :class="ac('adspent')" tabindex="0" @click="sb('adspent')" @keydown.enter="sb('adspent')"
          title="Money spent on ads in the range"><span>Ad spend</span><span x-text="arr('adspent')"></span></th>
      <th class="sortable" :class="ac('cpp')" tabindex="0" @click="sb('cpp')" @keydown.enter="sb('cpp')"
          title="Ad cost per order (CPP), with the break-even cost per order under it"><span>Cost per order</span><span x-text="arr('cpp')"></span></th>
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
        {{-- T4: Orders today, Profit today, Profit %, Ad spend, Cost per order. --}}
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

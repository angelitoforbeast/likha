{{-- Sales & Profit (006, ?tab=sales). Isang root element lang (<table>) dahil nasa <template x-if> ng index.
     Isang row kada item (gaya ng 005). Column grant = data gate (ilIdOn ng source id). Profit % = apat na sub-column,
     bawat isa may mapusyaw na kulay ng rule ng owner (ilCfTint). Lahat ng text ay x-text / :title (walang x-html). --}}
<table class="il-table" id="il-table-sales">
  <colgroup>
    <col>
    <col style="width:90px" x-show="ilIdOn('orders_1d')">
    <col style="width:120px" x-show="ilIdOn('proj_prof_1d')">
    <col style="width:84px" x-show="ilIdOn('proj_pct_1d')">
    <col style="width:84px" x-show="ilIdOn('proj_pct_3d')">
    <col style="width:84px" x-show="ilIdOn('proj_pct_7d')">
    <col style="width:84px" x-show="ilIdOn('proj_pct')">
    <col style="width:110px" x-show="ilIdOn('adspent')">
    <col style="width:130px" x-show="ilIdOn('cpp')">
    <col style="width:36px">
  </colgroup>
  <thead>
    <tr>
      <th rowspan="2" class="sortable il-th-item" :class="ac('item_name')" tabindex="0" @click="sb('item_name')" @keydown.enter="sb('item_name')"
          title="The item, and how many pieces are on hold (ordered by customers, not shipped yet)"><span>Item</span><span x-text="arr('item_name')"></span></th>
      <th rowspan="2" class="sortable" x-show="ilIdOn('orders_1d')" :class="ac('orders_last_day')" tabindex="0" @click="sb('orders_last_day')" @keydown.enter="sb('orders_last_day')"
          title="Orders on the last day of the range"><span>Orders today</span><span x-text="arr('orders_last_day')"></span></th>
      <th rowspan="2" class="sortable" x-show="ilIdOn('proj_prof_1d')" :class="ac('projected_profit_last_day')" tabindex="0" @click="sb('projected_profit_last_day')" @keydown.enter="sb('projected_profit_last_day')"
          title="Projected profit (or loss) on the last day of the range"><span>Profit today</span><span x-text="arr('projected_profit_last_day')"></span></th>
      <th x-show="ilSalesPctCount()" :colspan="ilSalesPctCount()"
          title="Profit as a % of sales: today, 3 days, 7 days and 1 month. Colours follow the owner's rules for each window"><span>Profit %</span></th>
      <th rowspan="2" class="sortable" x-show="ilIdOn('adspent')" :class="ac('adspent')" tabindex="0" @click="sb('adspent')" @keydown.enter="sb('adspent')"
          title="Money spent on ads in the range"><span>Ad spend</span><span x-text="arr('adspent')"></span></th>
      <th rowspan="2" class="sortable" x-show="ilIdOn('cpp')" :class="ac('cpp')" tabindex="0" @click="sb('cpp')" @keydown.enter="sb('cpp')"
          title="Ad cost per order (CPP), with the break-even cost per order under it"><span>Cost per order</span><span x-text="arr('cpp')"></span></th>
      <th rowspan="2" class="il-chev-th" title="Show or hide the item details"><span class="sr-only" style="position:absolute;left:-9999px;">Details</span></th>
    </tr>
    <tr>
      <th class="sortable" x-show="ilIdOn('proj_pct_1d')" :class="ac('proj_pct_last_day')" tabindex="0" @click="sb('proj_pct_last_day')" @keydown.enter="sb('proj_pct_last_day')"
          title="Profit % on the last day of the range"><span>Today</span><span x-text="arr('proj_pct_last_day')"></span></th>
      <th class="sortable" x-show="ilIdOn('proj_pct_3d')" :class="ac('proj_pct_last_3d')" tabindex="0" @click="sb('proj_pct_last_3d')" @keydown.enter="sb('proj_pct_last_3d')"
          title="Profit % over the last 3 days"><span>3 days</span><span x-text="arr('proj_pct_last_3d')"></span></th>
      <th class="sortable" x-show="ilIdOn('proj_pct_7d')" :class="ac('proj_pct_last_7d')" tabindex="0" @click="sb('proj_pct_last_7d')" @keydown.enter="sb('proj_pct_last_7d')"
          title="Profit % over the last 7 days"><span>7 days</span><span x-text="arr('proj_pct_last_7d')"></span></th>
      <th class="sortable" x-show="ilIdOn('proj_pct')" :class="ac('proj_pct_computed')" tabindex="0" @click="sb('proj_pct_computed')" @keydown.enter="sb('proj_pct_computed')"
          title="Profit % over the whole date range (about 1 month)"><span>1 month</span><span x-text="arr('proj_pct_computed')"></span></th>
    </tr>
  </thead>

  {{-- Walang data / walang natira sa filter / loading (parehong kondisyon ng 005). --}}
  <tbody>
    <template x-if="rows.length === 0 && !loading">
      <tr class="il-empty-row"><td :colspan="ilSalesColspan()">No data for the selected dates.</td></tr>
    </template>
    @if(!empty($effectiveIsCEO))
    <template x-if="worklist.list !== 'lahat' && !itemGroups().length && !(rows.length === 0 && loading)">
      <tr class="il-empty-row"><td :colspan="ilSalesColspan()"
          x-text="worklist.error ? worklist.error : (worklist.loading || !worklist.loaded || !holdLoaded ? 'Loading…' : 'No items in this list.')"></td></tr>
    </template>
    @endif
    <template x-if="categoryFilter !== '' && categoryColVisible() && (!effectiveIsCeo || worklist.list === 'lahat') && !itemGroups().length && !(rows.length === 0 && loading)">
      <tr class="il-empty-row"><td :colspan="ilSalesColspan()"
          x-text="!stock.loaded ? 'Loading…' : 'No items in this category.'"></td></tr>
    </template>
    <template x-if="rows.length === 0 && loading">
      <tr class="il-empty-row"><td :colspan="ilSalesColspan()"><span class="spin" style="margin-right:6px;"></span>Loading…</td></tr>
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
        <td x-show="ilIdOn('orders_1d')" class="il-num" x-text="num(G.agg.orders_last_day)"></td>
        <td x-show="ilIdOn('proj_prof_1d')">
          <div class="il-num" :style="'color:' + ilTone(G.agg.projected_profit_last_day)"
               x-text="ilArrow(G.agg.projected_profit_last_day) + ilMoneyKita(G.agg.projected_profit_last_day)"></div>
        </td>
        <td x-show="ilIdOn('proj_pct_1d')"><span :class="G.agg.proj_pct_1d == null ? 'il-grey' : 'il-cf il-num'" :style="ilCfTint('proj_pct_1d', G.agg.proj_pct_1d)" x-text="ilPct(G.agg.proj_pct_1d)"></span></td>
        <td x-show="ilIdOn('proj_pct_3d')"><span :class="G.agg.proj_pct_3d == null ? 'il-grey' : 'il-cf il-num'" :style="ilCfTint('proj_pct_3d', G.agg.proj_pct_3d)" x-text="ilPct(G.agg.proj_pct_3d)"></span></td>
        <td x-show="ilIdOn('proj_pct_7d')"><span :class="G.agg.proj_pct_7d == null ? 'il-grey' : 'il-cf il-num'" :style="ilCfTint('proj_pct_7d', G.agg.proj_pct_7d)" x-text="ilPct(G.agg.proj_pct_7d)"></span></td>
        <td x-show="ilIdOn('proj_pct')"><span :class="G.agg.proj_pct == null ? 'il-grey' : 'il-cf il-num'" :style="ilCfTint('proj_pct', G.agg.proj_pct)" x-text="ilPct(G.agg.proj_pct)"></span></td>
        <td x-show="ilIdOn('adspent')" class="il-num" x-text="ilMoney(G.agg.adspent)"></td>
        <td x-show="ilIdOn('cpp')">
          <div class="il-num" x-text="md(G.agg.cpp)"></div>
          <template x-if="ilIdOn('breakeven_cpp') && ilBeRange(G)">
            <div class="il-sub" title="The cost per order where the item stops making money (one value per page)" x-text="'break-even ' + ilBeRange(G)"></div>
          </template>
        </td>
        <td class="il-chev-td">
          <button type="button" class="il-chev" :class="isItemOpen(G.item_name) ? 'active' : ''"
                  :aria-expanded="isItemOpen(G.item_name) ? 'true' : 'false'"
                  title="Show or hide the item details" aria-label="Show or hide the item details"
                  @click.stop="toggleItemExpand(G.item_name)">›</button>
        </td>
      </tr>

      {{-- Details — nire-render lang kapag bukas (x-if) para hindi mabigat ang daan-daang item. --}}
      <tr x-show="isItemOpen(G.item_name)" class="il-expand-row">
        <td :colspan="ilSalesColspan()">
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

  {{-- Total (shown): sumusunod sa itemGroups() (item filter, chip, category). Isang compute lang kada render. --}}
  <template x-if="itemGroups().length > 0">
    <tbody>
      <template x-for="T in [ilTotVisible()]" :key="'il-st'">
        <tr class="il-total">
          <td class="il-item" title="Totals over the items shown now (with the filters)"><div class="il-name">Total (shown)</div></td>
          <td x-show="ilIdOn('orders_1d')" x-text="num(T.orders_last_day)"></td>
          <td x-show="ilIdOn('proj_prof_1d')">
            <div :style="'color:' + ilTone(T.projected_profit_last_day)"
                 x-text="ilArrow(T.projected_profit_last_day) + ilMoneyKita(T.projected_profit_last_day)"></div>
          </td>
          <td x-show="ilIdOn('proj_pct_1d')"><span :class="T.proj_pct_1d == null ? 'il-grey' : 'il-cf'" :style="ilCfTint('proj_pct_1d', T.proj_pct_1d)" x-text="ilPct(T.proj_pct_1d)"></span></td>
          <td x-show="ilIdOn('proj_pct_3d')"><span :class="T.proj_pct_3d == null ? 'il-grey' : 'il-cf'" :style="ilCfTint('proj_pct_3d', T.proj_pct_3d)" x-text="ilPct(T.proj_pct_3d)"></span></td>
          <td x-show="ilIdOn('proj_pct_7d')"><span :class="T.proj_pct_7d == null ? 'il-grey' : 'il-cf'" :style="ilCfTint('proj_pct_7d', T.proj_pct_7d)" x-text="ilPct(T.proj_pct_7d)"></span></td>
          <td x-show="ilIdOn('proj_pct')"><span :class="T.proj_pct == null ? 'il-grey' : 'il-cf'" :style="ilCfTint('proj_pct', T.proj_pct)" x-text="ilPct(T.proj_pct)"></span></td>
          <td x-show="ilIdOn('adspent')" x-text="ilMoney(T.adspent)"></td>
          <td x-show="ilIdOn('cpp')" x-text="md(T.cpp)"></td>
          <td></td>
        </tr>
      </template>
    </tbody>
  </template>
</table>

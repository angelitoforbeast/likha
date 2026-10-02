{{-- Trend cell ng To order (006 T2). Expects Alpine `G` (item group). Icon + salita, walang fill; x-text lang. --}}
<div :title="ilTrendTip(G.item_name)">
  <span x-show="stockPending()" class="il-grey">…</span>
  <template x-if="!stockPending()">
    <span>
      <template x-if="stockFor(G.item_name)">
        <span>
          <span style="font-weight:600;" x-text="ilTrendText(G.item_name)"></span>
          <template x-if="stockIsLugi(G.item_name)">
            <span class="il-tag-loss" title="7-day profit % is 0 or less, so the smaller buffer is used">losing money</span>
          </template>
          <template x-if="stockFor(G.item_name).lifecycle_auto === false">
            <span class="il-sub" title="Set by hand on the Supply page; it does not change on its own">(manual)</span>
          </template>
        </span>
      </template>
      <template x-if="!stockFor(G.item_name)"><span class="il-grey">—</span></template>
    </span>
  </template>
</div>

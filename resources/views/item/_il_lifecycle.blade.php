{{-- Lifecycle badge ng bagong layout. Expects Alpine `G` (item group). Ginagamit sa LIFECYCLE column
     at (sa ibaba ng 1,440 px) sa ilalim ng pangalan sa ITEM cell. x-text lang. --}}
<div :title="stockTip(lifecycleTip(G.item_name), stockFor(G.item_name))">
  <span x-show="stockPending()" class="il-grey">…</span>
  <template x-if="!stockPending()">
    <span>
      <template x-if="stockFor(G.item_name)">
        <span>
          <span class="il-badge" :style="ilLcStyle(stockFor(G.item_name).lifecycle)" x-text="ilLifecycleText(G.item_name)"></span>
          <template x-if="stockFor(G.item_name).lifecycle_auto === false">
            <span class="il-sub" title="Ikaw ang nagtakda ng lifecycle na ito sa Supply page; hindi awtomatiko">(manual)</span>
          </template>
        </span>
      </template>
      <template x-if="!stockFor(G.item_name)"><span class="il-grey">—</span></template>
    </span>
  </template>
</div>

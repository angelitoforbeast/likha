  <!-- Scroll area -->
  {{-- Ang card ng supplier cells: Esc at click sa labas ang nagsasara (capture, dahil hinaharang ng mga cell ang
       click bago ito umakyat); sumusunod ito sa scroll ng table at sa pagbabago ng laki ng window (kung hindi,
       maiiwan ang naka-pin na card sa lumang puwesto); nawawala kapag sarado na ang form nito. --}}
  <div id="scroll" x-effect="splSync()" @scroll.passive="splScrolled()" @resize.window="splScrolled()"
       @keydown.escape.window="splEsc()" @click.window.capture="splOutside($event)">
    <div class="card">
      <table class="spl-table">
        <thead>
          <tr class="spl-h1">
            <!-- Fixed: Page (sortable) -->
            <th rowspan="2"
              :class="['sortable', 'spl-c1', ac('page_name') ? 'col-active' : '']"
              style="text-align:left;min-width:110px;"
              @click="sb('page_name')"
            >
              <span>Page</span>
              <span x-text="arr('page_name')" style="font-size:10px;"></span>
            </th>
            <!-- Fixed: Item (sortable) -->
            <th rowspan="2"
              :class="['sortable', 'spl-c2', ac('item_name') ? 'col-active' : '']"
              style="text-align:left;min-width:160px;"
              @click="sb('item_name')"
            >
              <span>Item</span>
              <span x-text="arr('item_name')" style="font-size:10px;"></span>
            </th>

            {{-- Ang grupo ng supplier: laging kasunod ng Item, hindi kasama sa reorder ng ibang column. --}}
            <th class="spl-grp" colspan="4">SUPPLIERS</th>

            <!-- Draggable/reorderable columns -->
            <template x-for="col in cols" :key="col.id">
              <th rowspan="2"
                draggable="true"
                :class="[col.sort ? 'sortable' : '', col.sort && ac(col.sort) ? 'col-active' : '', dragOver===col.id ? 'drag-over' : '']"
                :style="'text-align:'+col.align+';min-width:'+col.minw+'px'"
                @click="col.sort && sb(col.sort)"
                @dragstart="colDragStart($event, col.id)"
                @dragend="colDragEnd($event)"
                @dragover.prevent="dragOver=col.id"
                @dragleave="dragOver=null"
                @drop.prevent="colDrop($event, col.id)"
              >
                <span x-text="col.label"></span>
                <template x-if="col.sort">
                  <span x-text="arr(col.sort)" style="font-size:10px;"></span>
                </template>
              </th>
            </template>

            {{-- Row-level Actions column removed. Per-cell ✎ edit icons na lang. --}}
          </tr>
          {{-- Pangalawang row: ang apat na sub-header ng grupo. Walang sort at walang drag — ang server ang
               nag-aayos ng quotes (pinakamura muna), kaya hindi ito puwedeng ilipat o i-sort dito. --}}
          <tr class="spl-h2">
            <th title="Cheapest first. Changing a price can move a quote to another column.">Supplier 1</th><th>Supplier 2</th><th>Supplier 3</th><th title="Supplier(s) from purchase orders">PO</th>
          </tr>
        </thead>
        <tbody class="msg-tbody">

          <template x-if="rows.length === 0 && !loading">
            <tr><td :colspan="cols.length + 6" style="text-align:center;padding:48px;color:#94a3b8;font-size:13px;">
              No data for selected date.
            </td></tr>
          </template>

          @if(!empty($effectiveIsCEO))
          {{-- Walang natira sa napiling sourcing list (CEO LANG). --}}
          <template x-if="worklist.list !== 'lahat' && !itemGroups().length && !(rows.length === 0 && loading)">
            <tr><td :colspan="cols.length + 6" style="text-align:center;padding:36px;color:#94a3b8;font-size:13px;"
                    x-text="worklist.error ? worklist.error : (worklist.loading || !worklist.loaded || !holdLoaded ? 'Loading…' : 'Walang item sa listahang ito.')"></td></tr>
          </template>
          @endif
          {{-- Walang natira sa napiling category (lahat ng role; kung walang sourcing list na sumasagot na). --}}
          <template x-if="categoryFilter !== '' && categoryColVisible() && (!effectiveIsCeo || worklist.list === 'lahat') && !itemGroups().length && !(rows.length === 0 && loading)">
            <tr><td :colspan="cols.length + 6" style="text-align:center;padding:36px;color:#94a3b8;font-size:13px;"
                    x-text="!stock.loaded ? 'Loading…' : 'Walang item sa category na ito.'"></td></tr>
          </template>

          <template x-if="rows.length === 0 && loading">
            <tr><td :colspan="cols.length + 6" style="text-align:center;padding:48px;color:#94a3b8;font-size:13px;">
              <span class="spin" style="margin-right:6px;"></span>Loading…
            </td></tr>
          </template>

        </tbody>

          {{-- ── ITEM TIER + PAGES (interleaved) ──────────────────────────────
               displayRows() = item-header entries + (kapag naka-expand) ang page
               rows nila, interleaved at grouped. Isang <tbody> per entry (kailangan
               ng Alpine x-for ng IISANG root element). row.__itemHeader = item
               aggregate row; kung hindi → normal na page row (existing template). --}}
          <template x-for="(row, idx) in displayRows()" :key="row.__itemHeader ? ('__i:'+row.item_name) : row.page_key">
          <tbody :class="row.__itemHeader ? 'item-row-tbody' : ('page-row-tbody' + ((expandedPages[row.page_name] || {}).open ? ' page-section-expanded' : ''))">

            {{-- ITEM aggregate row (parehong tot() logic: weighted CPP/ratios,
                 summed profit) + photo. Click → toggle pages. --}}
            <template x-if="row.__itemHeader">
            <template x-for="A in [row.agg]" :key="'agg-'+row.item_name">
            <tr class="item-row" :class="!row.hasPages ? 'item-row-nopage' : ''"
                @click="row.hasPages && toggleItemExpand(row.item_name)">
              <td class="spl-c1">
                <div class="item-cell">
                  <template x-if="row.hasPages">
                    <button class="expand-chev" :class="isItemOpen(row.item_name) ? 'active' : ''"
                            @click.stop="toggleItemExpand(row.item_name)"
                            :title="isItemOpen(row.item_name) ? 'Hide pages' : 'Show pages'">›</button>
                  </template>
                  <template x-if="!row.hasPages">
                    <span class="expand-chev-empty"></span>
                  </template>
                  <template x-if="itemImages[row.item_name]">
                    <img class="item-sq" :src="itemImages[row.item_name]" :alt="row.item_name"
                         @click.stop="viewItemPhoto(row.item_name)" title="View photo">
                  </template>
                  <template x-if="!itemImages[row.item_name]">
                    <span class="item-sq item-sq-empty">🖼</span>
                  </template>
                  <span class="item-name" x-text="row.item_name"></span>
                  <span class="item-hold" x-text="'HOLD '+Number(row.hold||0).toLocaleString()"></span>
                </div>
                @if($effectiveIsCEO)
                {{-- Extra info ng napiling sourcing list — CEO LANG, habang may napiling chip. Lahat x-text (escaped). --}}
                <template x-if="worklistItem(row.item_name)">
                  <template x-for="W in [worklistItem(row.item_name)]" :key="'wl-'+row.item_name">
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
                @endif
              </td>
              <td class="spl-c2" style="text-align:center;">
                <template x-if="row.hasPages">
                  <div style="font-size:11px;color:#64748b;font-weight:600;"
                       x-text="row.pages_count + (row.pages_count===1?' running page':' running pages')"></div>
                </template>
                <template x-if="!row.hasPages">
                  <div style="font-size:11px;color:#b91c1c;font-weight:700;">⚠ walang running page</div>
                </template>
                <div class="spl-rowact">
                <div style="display:flex;gap:4px;justify-content:center;flex-wrap:wrap;margin-top:3px;">
                  <a class="item-photo-btn" @click.stop
                     :href="'{{ route('item.photo') }}?item='+encodeURIComponent(row.item_name)+'&start_date='+startDate+'&end_date='+endDate"
                     target="_blank" rel="noopener"
                     style="text-decoration:none;"
                     x-text="itemImages[row.item_name] ? 'Change' : 'Add photo'"></a>
                  <button type="button" class="item-copy-btn" @click.stop="copyItem(row.item_name, row.hold)"
                          x-text="copyState===row.item_name ? '✓ Copied' : '📋 Copy'"></button>
                </div>
                </div>
              </td>
              {{-- ── Ang grupo ng supplier: laging apat na column pagkatapos ng Item ──
                   Isang root element lang ang kaya ng x-if, kaya tatlong hugis ang apat na column: isang cell na
                   sakop ang apat (placeholder o pulang band), o tatlong quote cell + ang PO cell.
                   May @click.stop ang bawat cell: ang click sa loob nito ay hindi dapat magbukas ng page rows.
                   Lahat ng galing sa supplier ay x-text / bound attribute lang (text, hindi HTML). --}}
              {{-- 1. Hindi pa (o hindi) sumagot ang dalawang listahan: neutral na placeholder. Hindi puwedeng sabihing
                      "wala pang supplier" hangga't hindi pa alam. --}}
              <template x-if="!splReady()">
                <td colspan="4" class="spl-sc spl-wait" @click.stop>
                  <span class="spl-waittext" x-text="splFailed ? 'hindi na-load' : '…'"
                        :title="splFailed ? 'Hindi na-load ang listahan ng supplier. I-refresh ang page.' : 'Loading suppliers…'"></span>
                </td>
              </template>
              {{-- 2. Walang quote at walang PO supplier: isang pulang band, isang beses lang ang babala. --}}
              <template x-if="splReady() && splNone(row.item_name)">
                <td colspan="4" class="spl-sc spl-nosup" @click.stop>
                  <div class="spl-band spl-cell">
                    <b>⚠ wala pang supplier</b>
                    <button type="button" class="item-photo-btn" @click.stop="splForm(row.item_name, null, 'band', $el)">+ supplier quote</button>
                    {{-- Ang add form, sa loob ng card ng cell na ito. Para lang sa row na ito: kasama sa kondisyon ang
                         sariling pangalan ng item, dahil magkapareho ang quote key ng dalawang variant row. --}}
                    <template x-if="splIs(row.item_name, 'band', 'form') && quoteForm.key === supKey(row.item_name) && quoteForm.item_name === row.item_name">
                      <div class="spl-card" role="group" aria-label="Supplier quote" @click.stop :style="splCard.style">
                        <div class="spl-form" @click.stop>
                          <select x-model="quoteForm.supplier_id" aria-label="Supplier" @click.stop>
                            <option value="">— supplier —</option>
                            <template x-for="s in supplierList" :key="'s-'+s.id"><option :value="String(s.id)" x-text="s.name"></option></template>
                          </select>
                          <input type="number" step="0.01" min="0" x-model="quoteForm.price" placeholder="₱ presyo" aria-label="Presyo" @click.stop>
                          <input type="number" min="0" x-model="quoteForm.moq" placeholder="MOQ" aria-label="MOQ" @click.stop>
                          <input type="text" x-model="quoteForm.link" placeholder="link (opsyonal)" aria-label="Link (opsyonal)" @click.stop>
                          <input type="file" accept="image/jpeg,image/png,image/webp" @change="quoteForm.photo = $event.target.files[0] || null" @click.stop
                                 title="Photo ng produkto ng supplier (jpg/png/webp, hanggang 10 MB)" aria-label="Photo ng produkto ng supplier">
                          <div class="spl-card-act">
                            <button type="button" class="item-photo-btn" @click.stop="saveQuote()" x-text="quoteForm.saving ? '…' : 'Save'"></button>
                            <button type="button" class="item-photo-btn" @click.stop="splCancel()">Cancel</button>
                          </div>
                        </div>
                      </div>
                    </template>
                  </div>
                </td>
              </template>
              {{-- 3. Supplier 1–3: ang unang tatlong quote ayon sa pagkakasunod ng server (pinakamura muna). --}}
              <template x-for="si in (splReady() && !splNone(row.item_name) ? [0, 1, 2] : [])" :key="'spl-'+row.item_name+'-'+si">
                <td class="spl-sc" @click.stop>
                  {{-- Ang laman ng cell ang may-ari ng card nito: anak nito ang card, kaya hindi "umaalis" ang pointer
                       kapag lumipat mula sa cell papunta sa card. Hover lang kapag may quote ang cell. --}}
                  <div class="spl-cell"
                       @mouseenter="splTop3(row.item_name)[si] && splHover(row.item_name, si, 'quote', $el)"
                       @mouseleave="splLeave(row.item_name, si)">
                  <template x-if="splTop3(row.item_name)[si]">
                    <template x-for="q in [splTop3(row.item_name)[si]]" :key="'spl-q-'+row.item_name+'-'+q.id">
                      <div class="spl-q">
                        <button type="button" class="spl-name" aria-haspopup="true"
                                :aria-expanded="splIs(row.item_name, si, 'quote') ? 'true' : 'false'"
                                @click.stop="splToggle(row.item_name, si, 'quote', $el)" :title="q.supplier" x-text="q.supplier"></button>
                        <div class="spl-l2">
                          {{-- Explicit na null check: ang money() ay nagpi-print ng 0.00 para sa null. --}}
                          <span :class="q.cheapest === true ? 'spl-price spl-low' : 'spl-price'" x-text="q.price !== null ? money(q.price) : '—'"></span>
                          {{-- Ang MOQ na 0 ay value pa rin, kaya null / undefined lang ang itinatago. --}}
                          <template x-if="q.moq !== null && q.moq !== undefined">
                            <span class="spl-moq" x-text="'MOQ ' + q.moq"></span>
                          </template>
                        </div>
                        {{-- Ang card ng quote: ang mga detalyeng hindi kasya sa cell. Galing lahat sa na-load nang
                             listahan (walang request), at text lang ang bawat value. --}}
                        <template x-if="splIs(row.item_name, si, 'quote')">
                          <div class="spl-card" role="group" :aria-label="'Quote details: ' + q.supplier" @click.stop :style="splCard.style">
                            <div class="spl-card-name" x-text="q.supplier"></div>
                            <div class="spl-card-row">
                              <span :class="q.cheapest === true ? 'spl-price spl-low' : 'spl-price'" x-text="q.price !== null ? money(q.price) : '—'"></span>
                              <span class="spl-moq" x-text="(q.moq !== null && q.moq !== undefined) ? 'MOQ ' + q.moq : 'walang MOQ'"></span>
                            </div>
                            <template x-if="q.prev_price !== null && q.prev_price !== undefined">
                              <div class="spl-card-sub" x-text="'dati ' + money(q.prev_price) + (q.prev_date ? ' (' + q.prev_date + ')' : '')"></div>
                            </template>
                            <template x-if="q.updated_at">
                              <div class="spl-card-sub" x-text="'updated ' + q.updated_at"></div>
                            </template>
                            <div class="spl-card-act">
                              <template x-if="q.photo_url">
                                <button type="button" class="spl-card-photo" :title="'Quote photo · ' + q.supplier" :aria-label="'Quote photo · ' + q.supplier"
                                        @click.stop="photoModal = { open:true, url:q.photo_url, name:q.supplier+' — '+row.item_name }">
                                  <img class="item-sq" :src="q.photo_url" :alt="q.supplier">
                                </button>
                              </template>
                              {{-- http / https lang ang nagiging link; ang iba ay hindi ipinapakita. --}}
                              <template x-if="safeLink(q.link)"><a :href="safeLink(q.link)" target="_blank" rel="noopener" @click.stop>link</a></template>
                              <button type="button" class="item-photo-btn" title="I-edit ang quote" aria-label="I-edit ang quote"
                                      @click.stop="splForm(row.item_name, q, si, $el)">✎</button>
                              <button type="button" class="item-photo-btn" title="Tanggalin ang quote" aria-label="Tanggalin ang quote"
                                      @click.stop="splRemove(row.item_name, q)">✕</button>
                            </div>
                          </div>
                        </template>
                      </div>
                    </template>
                  </template>
                  {{-- Sa huling cell lang: ilan pang quote ang hindi kasya sa tatlo. --}}
                  <template x-if="si === 2 && splRest(row.item_name) > 0">
                    <button type="button" class="spl-more" aria-haspopup="true"
                            :aria-expanded="splIs(row.item_name, 'more', 'list') ? 'true' : 'false'"
                            :aria-label="'Show all ' + quotesFor(row.item_name).length + ' quotes'"
                            @click.stop="splToggle(row.item_name, 'more', 'list', $el)" x-text="'+' + splRest(row.item_name)"></button>
                  </template>
                  {{-- Ang listahan sa likod ng "+N": lahat ng quote ayon sa pagkakasunod ng server (walang sort dito). --}}
                  <template x-if="si === 2 && splIs(row.item_name, 'more', 'list')">
                    <div class="spl-card" role="group" aria-label="All quotes" @click.stop :style="splCard.style">
                      <div class="spl-card-name" x-text="'Lahat ng quote (' + quotesFor(row.item_name).length + ')'"></div>
                      <div class="spl-card-list">
                        <template x-for="q in quotesFor(row.item_name)" :key="'spl-li-'+row.item_name+'-'+q.id">
                          <div class="spl-card-li">
                            <b :title="q.supplier" x-text="q.supplier"></b>
                            <span :class="q.cheapest === true ? 'spl-price spl-low' : 'spl-price'" x-text="q.price !== null ? money(q.price) : '—'"></span>
                            <template x-if="q.moq !== null && q.moq !== undefined">
                              <span class="spl-moq" x-text="'MOQ ' + q.moq"></span>
                            </template>
                            <button type="button" class="item-photo-btn" title="I-edit ang quote" aria-label="I-edit ang quote"
                                    @click.stop="splForm(row.item_name, q, si, $el)">✎</button>
                            <button type="button" class="item-photo-btn" title="Tanggalin ang quote" aria-label="Tanggalin ang quote"
                                    @click.stop="splRemove(row.item_name, q)">✕</button>
                          </div>
                        </template>
                      </div>
                      <div class="spl-card-act">
                        <button type="button" class="item-photo-btn" @click.stop="splForm(row.item_name, null, si, $el)">+ supplier quote</button>
                      </div>
                    </div>
                  </template>
                  <template x-if="!splTop3(row.item_name)[si]">
                    <button type="button" class="spl-add" @click.stop="splForm(row.item_name, null, si, $el)" title="+ supplier quote" :aria-label="'+ supplier quote (Supplier ' + (si + 1) + ')'">+</button>
                  </template>
                  {{-- Ang add / edit form, sa loob ng card ng cell na pinindutan. Para lang sa row na ito: kasama sa
                       kondisyon ang sariling pangalan ng item, dahil magkapareho ang quote key ng dalawang variant row. --}}
                  <template x-if="splIs(row.item_name, si, 'form') && quoteForm.key === supKey(row.item_name) && quoteForm.item_name === row.item_name">
                    <div class="spl-card" role="group" aria-label="Supplier quote" @click.stop :style="splCard.style">
                      <div class="spl-form" @click.stop>
                        <select x-model="quoteForm.supplier_id" aria-label="Supplier" @click.stop>
                          <option value="">— supplier —</option>
                          <template x-for="s in supplierList" :key="'s-'+s.id"><option :value="String(s.id)" x-text="s.name"></option></template>
                        </select>
                        <input type="number" step="0.01" min="0" x-model="quoteForm.price" placeholder="₱ presyo" aria-label="Presyo" @click.stop>
                        <input type="number" min="0" x-model="quoteForm.moq" placeholder="MOQ" aria-label="MOQ" @click.stop>
                        <input type="text" x-model="quoteForm.link" placeholder="link (opsyonal)" aria-label="Link (opsyonal)" @click.stop>
                        <input type="file" accept="image/jpeg,image/png,image/webp" @change="quoteForm.photo = $event.target.files[0] || null" @click.stop
                               title="Photo ng produkto ng supplier (jpg/png/webp, hanggang 10 MB)" aria-label="Photo ng produkto ng supplier">
                        <div class="spl-card-act">
                          <button type="button" class="item-photo-btn" @click.stop="saveQuote()" x-text="quoteForm.saving ? '…' : 'Save'"></button>
                          <button type="button" class="item-photo-btn" @click.stop="splCancel()">Cancel</button>
                        </div>
                      </div>
                    </div>
                  </template>
                  </div>
                </td>
              </template>
              {{-- 4. PO: ang unang supplier mula sa purchase orders (kasalukuyang pagkakasunod ng listahan) at ang cost nito. --}}
              <template x-if="splReady() && !splNone(row.item_name)">
                <td class="spl-sc spl-po" @click.stop>
                  <div class="spl-cell"
                       @mouseenter="suppliersFor(row.item_name).length && splHover(row.item_name, 'po', 'po', $el)"
                       @mouseleave="splLeave(row.item_name, 'po')">
                  <template x-if="suppliersFor(row.item_name).length">
                    <template x-for="s in [suppliersFor(row.item_name)[0]]" :key="'spl-po-'+row.item_name">
                      <div class="spl-q">
                        <button type="button" class="spl-name" aria-haspopup="true"
                                :aria-expanded="splIs(row.item_name, 'po', 'po') ? 'true' : 'false'"
                                @click.stop="splToggle(row.item_name, 'po', 'po', $el)" x-text="s.supplier"
                                :title="'PO ' + (s.order_date||'') + (s.order_no ? ' · '+s.order_no : '')"></button>
                        <div class="spl-l2">
                          <span class="spl-pocost" x-text="money(s.unit_cost)"></span>
                        </div>
                      </div>
                    </template>
                  </template>
                  <template x-if="suppliersFor(row.item_name).length > 1">
                    <button type="button" class="spl-more" aria-haspopup="true"
                            :aria-expanded="splIs(row.item_name, 'po', 'po') ? 'true' : 'false'"
                            :aria-label="'Show all ' + suppliersFor(row.item_name).length + ' PO suppliers'"
                            @click.stop="splToggle(row.item_name, 'po', 'po', $el)" x-text="'+' + (suppliersFor(row.item_name).length - 1)"></button>
                  </template>
                  <template x-if="!suppliersFor(row.item_name).length">
                    <span class="spl-empty">—</span>
                  </template>
                  {{-- Ang card ng PO: lahat ng supplier mula sa purchase orders. Basahin lang; walang control. --}}
                  <template x-if="splIs(row.item_name, 'po', 'po')">
                    <div class="spl-card" role="group" aria-label="Supplier galing sa PO" @click.stop :style="splCard.style">
                      <div class="spl-card-name">Supplier galing sa PO</div>
                      <template x-for="(s, pi) in suppliersFor(row.item_name)" :key="'spl-pol-'+row.item_name+'-'+pi">
                        <div class="spl-card-li">
                          <b :title="s.supplier" x-text="s.supplier"></b>
                          <span class="spl-pocost" x-text="money(s.unit_cost)"></span>
                          <template x-if="s.order_date"><span class="spl-moq" x-text="s.order_date"></span></template>
                          <template x-if="s.order_no"><span class="spl-moq" x-text="s.order_no"></span></template>
                        </div>
                      </template>
                    </div>
                  </template>
                  </div>
                </td>
              </template>
              <template x-for="col in cols" :key="'ic-'+row.item_name+'-'+col.id">
                <td :style="'text-align:'+col.align+';'+(col.id==='proj_profit'?pbStyle(A.projected_profit,{included_days:rangeDays,range_days:rangeDays}):'')+(col.id==='proj_prof_1d'?pbStyleN(A.projected_profit_last_day,1):'')+(col.id==='proj_prof_3d'?pbStyleN(A.projected_profit_last_3d,3):'')+(col.id==='proj_prof_7d'?pbStyleN(A.projected_profit_last_7d,7):'')">
                  @include('item._agg_cells')
                </td>
              </template>
            </tr>
            </template>
            </template>


            {{-- Per-page repeated column header — VIEW ONLY. Shows above each
                 EXPANDED page section so you don't lose track of which column
                 is which while scrolling. Display-only labels: walang sort
                 click / drag (yun lang sa main header). Sumusunod pa rin sa
                 global `cols` order, kaya pag nag-reorder ka sa main header,
                 nag-uupdate din itong display. Hidden when collapsed. --}}
            <tr x-show="!row.__itemHeader && (expandedPages[row.page_name] || {}).open" class="page-col-header">
              <th style="text-align:left;min-width:110px;">Page</th>
              <th style="text-align:left;min-width:160px;">Item</th>
              <th colspan="4"></th>
              <template x-for="col in cols" :key="'ph-'+row.page_key+'-'+col.id">
                <th :style="'text-align:'+col.align+';min-width:'+col.minw+'px'">
                  <span x-text="col.label"></span>
                </th>
              </template>
            </tr>

            <tr x-show="!row.__itemHeader" :class="(editIdx === idx ? 'editing-row ' : '') + ((expandedPages[row.page_name] || {}).open ? 'page-row-expanded' : '')">

              <!-- Fixed: Page -->
              <td class="spl-c1">
                {{-- Page cell layout: chevron in a fixed-width gutter so it
                     vertically aligns to the FIRST LINE of the page name
                     across every row (regardless of multi-line warnings). --}}
                <div class="page-cell">
                  {{-- Inline expand chevron — fetches & shows this page's
                       campaigns/adsets/ads via /ads_manager/campaigns/data.
                       Right arrow rotates down when open. --}}
                  <button class="expand-chev"
                          :class="(expandedPages[row.page_name] || {}).open ? 'active' : ''"
                          @click.stop="togglePageExpand(row.page_name)"
                          :title="(expandedPages[row.page_name] || {}).open ? 'Hide campaigns' : 'Show campaigns'">›</button>
                  <div class="page-cell-body">
                    <template x-if="row.is_range">
                      <a href="#" @click.prevent="openBreakdown(row)"
                         style="font-weight:600;color:#0f172a;white-space:normal;line-height:1.35;
                                text-decoration:underline;text-decoration-color:#cbd5e1;
                                text-underline-offset:2px;cursor:pointer;"
                         onmouseover="this.style.color='#2563eb';this.style.textDecorationColor='#2563eb';"
                         onmouseout="this.style.color='#0f172a';this.style.textDecorationColor='#cbd5e1';"
                         title="View per-date primary item breakdown"
                         x-text="row.page_name"></a>
                    </template>
                    <template x-if="!row.is_range">
                      <span style="font-weight:600;color:#0f172a;white-space:normal;line-height:1.35;"
                            x-text="row.page_name"></span>
                    </template>
                    <template x-if="row.mixed_primary">
                      <div style="cursor:pointer;" @click="openBreakdown(row)"
                           :title="row.distinct_items_in_range + ' distinct primary items across ' + row.range_days + '-day range. Click to see breakdown.'">
                        <div style="font-size:10px;color:#b45309;font-weight:600;line-height:1.3;margin-top:2px;">
                          ⚠ mixed primary · <span x-text="row.included_days + '/' + row.range_days + ' d'"></span>
                        </div>
                        <template x-if="row.anchor_first_date">
                          <div style="font-size:9px;color:#64748b;line-height:1.2;">
                            computed since <span x-text="fmtMD(row.anchor_first_date)"></span>
                          </div>
                        </template>
                      </div>
                    </template>
                    {{-- Back-fill warning — may included date(s) na walang proper
                         setting kaya hiniram ang earliest. Precise label: ilista
                         kung ALIN ang back-filled (RTS / cost / fee). Click →
                         breakdown (red cells doon). --}}
                    <template x-if="row.has_backfill">
                      <div style="cursor:pointer;font-size:10px;color:#dc2626;font-weight:600;line-height:1.3;margin-top:2px;"
                           @click="openBreakdown(row)"
                           :title="'⚠ ' + (row.backfill_dates ? row.backfill_dates.length : 0) + ' date(s) walang proper setting — back-filled earliest. Click para makita sa breakdown (red cells).'"
                           x-text="'⚠ back-filled ' + (row.backfill_fields && row.backfill_fields.length ? row.backfill_fields.map(f => ({rts:'RTS', cost:'cost', fee:'fee'}[f] || f)).join(' + ') : '')">
                      </div>
                    </template>
                  </div>
                </div>
              </td>

              <!-- Fixed: Item -->
              <td class="spl-c2" style="text-align:center;">
                <div style="font-weight:600;color:#1e293b;white-space:normal;line-height:1.35;"
                     x-text="sq(row.item_name)"></div>
                <template x-for="s in (row.secondary_items||[])" :key="s.item_name">
                  <div style="font-size:10px;color:#94a3b8;line-height:1.4;">
                    <span x-text="sq(s.item_name)+' ('+s.total_orders+')'"></span>
                    <template x-if="s.price && s.price !== row.price">
                      <span style="color:#cbd5e1;" x-text="' · '+money(s.price)"></span>
                    </template>
                  </div>
                </template>
              </td>
              {{-- Ang supplier ay sa item, hindi sa page: blangko ang ilalim ng grupo sa page row. --}}
              <td colspan="4" class="spl-under"></td>

              <!-- Dynamic columns -->
              <template x-for="col in cols" :key="col.id">
                <td :style="'text-align:'+col.align+';'+(col.id==='rts_set'&&editIdx!==idx&&row.rts_pct===null?'background:#fef2f2;':'')+(col.id==='item_val'&&editIdx!==idx&&row.item_value===null?'background:#fef2f2;':'')+(col.id==='proj_profit'?pbStyle(row.projected_profit,row):'')+(col.id==='proj_prof_1d'?pbStyleN(row.projected_profit_last_day,1):'')+(col.id==='proj_prof_3d'?pbStyleN(row.projected_profit_last_3d,3):'')+(col.id==='proj_prof_7d'?pbStyleN(row.projected_profit_last_7d,7):'')+cellFormatStyle(col.id, cellValueFor(col, row), row)">

                  <!-- adspent -->
                  <template x-if="col.id==='adspent'">
                    <span style="color:#111;font-weight:500;" x-text="money(row.adspent)"></span>
                  </template>

                  <!-- orders -->
                  <template x-if="col.id==='orders'">
                    <span style="color:#111;" x-text="num(row.orders)"></span>
                  </template>

                  <!-- orders_1d — orders count on end_date (last day of range) -->
                  <template x-if="col.id==='orders_1d'">
                    <span style="color:#111;" x-text="num(row.orders_last_day)"></span>
                  </template>

                  <!-- cpp -->
                  <template x-if="col.id==='cpp'">
                    <span style="color:#111;" x-text="md(row.cpp)"></span>
                  </template>

                  <!-- proceed -->
                  <template x-if="col.id==='proceed'">
                    <span style="color:#111;font-weight:600;" x-text="num(row.proceed_orders)"></span>
                  </template>

                  <!-- pcpp -->
                  <template x-if="col.id==='pcpp'">
                    <span style="color:#111;" x-text="md(row.proceed_cpp)"></span>
                  </template>

                  <!-- TCPR (pending rate) — (1 − proceed/orders) × 100 -->
                  <template x-if="col.id==='tcpr'">
                    <span x-text="tcprFor(row) === null ? '—' : tcprFor(row).toFixed(1) + '%'"></span>
                  </template>

                  <!-- Breakeven CPP — derived from existing profit math at the
                       global target Proj.% (configurable via /owner/column-settings). -->
                  <template x-if="col.id==='breakeven_cpp'">
                    <span :title="breakevenCppFor(row) === null ? 'Missing rts / item_value / price / orders' : ('Target ' + (window.__BREAKEVEN_PCT__ ?? 5) + '% Proj.% · actual CPP ' + md(row.cpp))"
                          x-text="breakevenCppFor(row) === null ? '—' : md(breakevenCppFor(row))"></span>
                  </template>

                  <!-- proj_profit — cell background handles color; bold text -->
                  <template x-if="col.id==='proj_profit'">
                    <span style="font-weight:700;" :style="'color:'+pbColor(row.projected_profit)"
                          x-text="md(row.projected_profit)"></span>
                  </template>

                  <!-- per_order -->
                  <template x-if="col.id==='per_order'">
                    <span style="color:#111;" x-text="md(row.proj_profit_per_order)"></span>
                  </template>

                  {{-- NP/O = projected_profit_last_day ÷ orders_last_day. Single-day
                       net-profit-per-order snapshot using end_date metrics. Null when
                       either side missing (no slice on end_date, or missing RTS/cogs). --}}
                  <template x-if="col.id==='np_per_order'">
                    @php /* npo computed inline below */ @endphp
                    <template x-if="row.projected_profit_last_day !== null && row.orders_last_day > 0">
                      <span style="color:#111;font-weight:600;"
                            :style="'color:'+pbColor(row.projected_profit_last_day / row.orders_last_day)"
                            x-text="md(row.projected_profit_last_day / row.orders_last_day)"
                            :title="'1D net profit ₱'+Number(row.projected_profit_last_day||0).toLocaleString('en-PH',{maximumFractionDigits:0})+' / orders '+(row.orders_last_day||0)+' (end_date only)'"></span>
                    </template>
                    <template x-if="!(row.projected_profit_last_day !== null && row.orders_last_day > 0)">
                      <span style="color:#cbd5e1;" title="Missing 1D profit or orders for end_date">—</span>
                    </template>
                  </template>

                  {{-- NP/O (3D) = projected_profit_last_3d ÷ orders_last_3d --}}
                  <template x-if="col.id==='np_per_order_3d'">
                    <template x-if="row.projected_profit_last_3d !== null && row.orders_last_3d > 0">
                      <span style="font-weight:600;"
                            :style="'color:'+pbColor(row.projected_profit_last_3d / row.orders_last_3d)"
                            x-text="md(row.projected_profit_last_3d / row.orders_last_3d)"
                            :title="'3D net profit ₱'+Number(row.projected_profit_last_3d||0).toLocaleString('en-PH',{maximumFractionDigits:0})+' / orders '+(row.orders_last_3d||0)+' (last 3 days)'"></span>
                    </template>
                    <template x-if="!(row.projected_profit_last_3d !== null && row.orders_last_3d > 0)">
                      <span style="color:#cbd5e1;" title="Missing 3D profit or orders">—</span>
                    </template>
                  </template>

                  {{-- NP/O (7D) = projected_profit_last_7d ÷ orders_last_7d --}}
                  <template x-if="col.id==='np_per_order_7d'">
                    <template x-if="row.projected_profit_last_7d !== null && row.orders_last_7d > 0">
                      <span style="font-weight:600;"
                            :style="'color:'+pbColor(row.projected_profit_last_7d / row.orders_last_7d)"
                            x-text="md(row.projected_profit_last_7d / row.orders_last_7d)"
                            :title="'7D net profit ₱'+Number(row.projected_profit_last_7d||0).toLocaleString('en-PH',{maximumFractionDigits:0})+' / orders '+(row.orders_last_7d||0)+' (last 7 days)'"></span>
                    </template>
                    <template x-if="!(row.projected_profit_last_7d !== null && row.orders_last_7d > 0)">
                      <span style="color:#cbd5e1;" title="Missing 7D profit or orders">—</span>
                    </template>
                  </template>

                  {{-- NP/O (1M, entire selected range) = projected_profit ÷ orders --}}
                  <template x-if="col.id==='np_per_order_1m'">
                    <template x-if="row.projected_profit !== null && row.orders > 0">
                      <span style="font-weight:600;"
                            :style="'color:'+pbColor(row.projected_profit / row.orders)"
                            x-text="md(row.projected_profit / row.orders)"
                            :title="'Range net profit ₱'+Number(row.projected_profit||0).toLocaleString('en-PH',{maximumFractionDigits:0})+' / orders '+(row.orders||0)+' (entire selected range)'"></span>
                    </template>
                    <template x-if="!(row.projected_profit !== null && row.orders > 0)">
                      <span style="color:#cbd5e1;" title="Missing range profit or orders">—</span>
                    </template>
                  </template>

                  <!-- proj_pct = projected_profit ÷ gross_sales × 100 (net margin) -->
                  <template x-if="col.id==='proj_pct'">
                    <span>
                      <template x-if="row.projected_profit !== null && row.gross_sales > 0">
                        <span style="font-weight:700;"
                              x-text="(row.projected_profit/row.gross_sales*100).toFixed(1)+'%'"
                              :title="'profit ₱'+Number(row.projected_profit||0).toLocaleString('en-PH',{maximumFractionDigits:0})+' / gross ₱'+Number(row.gross_sales).toLocaleString('en-PH',{maximumFractionDigits:0})"></span>
                      </template>
                      <template x-if="!(row.projected_profit !== null && row.gross_sales > 0)">
                        <span style="color:#cbd5e1;">—</span>
                      </template>
                    </span>
                  </template>

                  <!-- proj_pct_1d = same formula, but only the slice on end_date.
                       Strict end_date — null when end_date has no slice for this page+item. -->
                  <template x-if="col.id==='proj_pct_1d'">
                    <span>
                      <template x-if="row.proj_pct_last_day !== null">
                        <span style="font-weight:700;"
                              x-text="row.proj_pct_last_day.toFixed(1)+'%'"
                              :title="'1D profit ₱'+Number(row.projected_profit_last_day||0).toLocaleString('en-PH',{maximumFractionDigits:0})+' / gross ₱'+Number(row.gross_sales_last_day||0).toLocaleString('en-PH',{maximumFractionDigits:0})+' · orders '+(row.orders_last_day||0)+' · proceed '+(row.proceed_last_day||0)+' (end_date only)'"></span>
                      </template>
                      <template x-if="row.proj_pct_last_day === null">
                        <span style="color:#cbd5e1;" title="No slice on end_date for this page+item, or RTS/item_value missing">—</span>
                      </template>
                    </span>
                  </template>

                  <!-- proj_pct_3d / proj_pct_7d — last 3/7 days ending at end_date. -->
                  <template x-if="col.id==='proj_pct_3d'">
                    <span>
                      <template x-if="row.proj_pct_last_3d !== null">
                        <span style="font-weight:700;"
                              x-text="row.proj_pct_last_3d.toFixed(1)+'%'"
                              :title="'3D profit ₱'+Number(row.projected_profit_last_3d||0).toLocaleString('en-PH',{maximumFractionDigits:0})+' / gross ₱'+Number(row.gross_sales_last_3d||0).toLocaleString('en-PH',{maximumFractionDigits:0})+' · orders '+(row.orders_last_3d||0)+' · proceed '+(row.proceed_last_3d||0)"></span>
                      </template>
                      <template x-if="row.proj_pct_last_3d === null">
                        <span style="color:#cbd5e1;" title="No slice in last 3 days, or RTS/item_value missing">—</span>
                      </template>
                    </span>
                  </template>
                  <template x-if="col.id==='proj_pct_7d'">
                    <span>
                      <template x-if="row.proj_pct_last_7d !== null">
                        <span style="font-weight:700;"
                              x-text="row.proj_pct_last_7d.toFixed(1)+'%'"
                              :title="'7D profit ₱'+Number(row.projected_profit_last_7d||0).toLocaleString('en-PH',{maximumFractionDigits:0})+' / gross ₱'+Number(row.gross_sales_last_7d||0).toLocaleString('en-PH',{maximumFractionDigits:0})+' · orders '+(row.orders_last_7d||0)+' · proceed '+(row.proceed_last_7d||0)"></span>
                      </template>
                      <template x-if="row.proj_pct_last_7d === null">
                        <span style="color:#cbd5e1;" title="No slice in last 7 days, or RTS/item_value missing">—</span>
                      </template>
                    </span>
                  </template>

                  <!-- proj_prof_1d / 3d / 7d — peso totals, color-coded via pbColor like the range Proj.Profit. -->
                  <template x-if="col.id==='proj_prof_1d'">
                    <span style="font-weight:700;" :style="'color:'+pbColor(row.projected_profit_last_day)"
                          x-text="md(row.projected_profit_last_day)"
                          :title="row.projected_profit_last_day!==null ? '1D profit (end_date only) · orders '+(row.orders_last_day||0)+' · proceed '+(row.proceed_last_day||0) : 'No slice on end_date'"></span>
                  </template>
                  <template x-if="col.id==='proj_prof_3d'">
                    <span style="font-weight:700;" :style="'color:'+pbColor(row.projected_profit_last_3d)"
                          x-text="md(row.projected_profit_last_3d)"
                          :title="row.projected_profit_last_3d!==null ? '3D profit (last 3 days) · orders '+(row.orders_last_3d||0)+' · proceed '+(row.proceed_last_3d||0) : 'No slice in last 3 days'"></span>
                  </template>
                  <template x-if="col.id==='proj_prof_7d'">
                    <span style="font-weight:700;" :style="'color:'+pbColor(row.projected_profit_last_7d)"
                          x-text="md(row.projected_profit_last_7d)"
                          :title="row.projected_profit_last_7d!==null ? '7D profit (last 7 days) · orders '+(row.orders_last_7d||0)+' · proceed '+(row.proceed_last_7d||0) : 'No slice in last 7 days'"></span>
                  </template>

                  <!-- jnt_rts — actual RTS% from JNT (90-day window) -->
                  <template x-if="col.id==='jnt_rts'">
                    <span>
                      <template x-if="row.jnt_rts_pct !== null">
                        <span style="color:#111;font-weight:700;font-size:12px;"
                              x-text="row.jnt_rts_pct.toFixed(1)+'%('+row.jnt_rts_cnt+')'"></span>
                      </template>
                      <template x-if="row.jnt_rts_pct === null">
                        <span style="color:#cbd5e1;font-size:11px;">—</span>
                      </template>
                    </span>
                  </template>

                  <!-- jnt_del — actual Delivered% from JNT -->
                  <template x-if="col.id==='jnt_del'">
                    <span>
                      <template x-if="row.jnt_del_pct !== null">
                        <span style="color:#111;font-size:12px;"
                              x-text="row.jnt_del_pct.toFixed(1)+'%('+row.jnt_del_cnt+')'"></span>
                      </template>
                      <template x-if="row.jnt_del_pct === null">
                        <span style="color:#cbd5e1;font-size:11px;">—</span>
                      </template>
                    </span>
                  </template>

                  <!-- jnt_transit — actual In-transit% from JNT -->
                  <template x-if="col.id==='jnt_transit'">
                    <span>
                      <template x-if="row.jnt_transit_pct !== null">
                        <span style="color:#111;font-size:12px;"
                              x-text="row.jnt_transit_pct.toFixed(1)+'%('+row.jnt_transit_cnt+')'"></span>
                      </template>
                      <template x-if="row.jnt_transit_pct === null">
                        <span style="color:#cbd5e1;font-size:11px;">—</span>
                      </template>
                    </span>
                  </template>

                  <!-- jnt_rdt — combined RTS% / Del% / Transit% (3 stacked rows; % removed, count kept).
                       Per-line: each row shown only kung naka-check sa column-settings (col.members). -->
                  <template x-if="col.id==='jnt_rdt'">
                    <table style="border-collapse:collapse;font-size:11px;margin:0 auto;">
                      <tbody>
                        <template x-if="col.members.includes('jnt_rts')">
                          <tr>
                            <td style="padding:1px 6px;text-align:left;color:#94a3b8;font-size:9px;font-weight:700;letter-spacing:0.04em;">RTS</td>
                            <td style="padding:1px 6px;text-align:right;border-left:1px solid #cbd5e1;" :style="row.jnt_rts_pct===null?'color:#cbd5e1':'color:#111;font-weight:700'"
                                x-text="row.jnt_rts_pct!==null ? row.jnt_rts_pct.toFixed(1)+'%' : '—'"></td>
                            <td style="padding:1px 6px;text-align:right;border-left:1px solid #cbd5e1;color:#64748b;"
                                x-text="row.jnt_rts_pct!==null ? row.jnt_rts_cnt : ''"></td>
                          </tr>
                        </template>
                        <template x-if="col.members.includes('jnt_del')">
                          <tr>
                            <td style="padding:1px 6px;text-align:left;color:#94a3b8;font-size:9px;font-weight:700;letter-spacing:0.04em;">DEL</td>
                            <td style="padding:1px 6px;text-align:right;border-left:1px solid #cbd5e1;" :style="row.jnt_del_pct===null?'color:#cbd5e1':'color:#111;font-weight:600'"
                                x-text="row.jnt_del_pct!==null ? row.jnt_del_pct.toFixed(1)+'%' : '—'"></td>
                            <td style="padding:1px 6px;text-align:right;border-left:1px solid #cbd5e1;color:#64748b;"
                                x-text="row.jnt_del_pct!==null ? row.jnt_del_cnt : ''"></td>
                          </tr>
                        </template>
                        <template x-if="col.members.includes('jnt_transit')">
                          <tr>
                            <td style="padding:1px 6px;text-align:left;color:#94a3b8;font-size:9px;font-weight:700;letter-spacing:0.04em;">INT</td>
                            <td style="padding:1px 6px;text-align:right;border-left:1px solid #cbd5e1;" :style="row.jnt_transit_pct===null?'color:#cbd5e1':'color:#111;font-weight:600'"
                                x-text="row.jnt_transit_pct!==null ? row.jnt_transit_pct.toFixed(1)+'%' : '—'"></td>
                            <td style="padding:1px 6px;text-align:right;border-left:1px solid #cbd5e1;color:#64748b;"
                                x-text="row.jnt_transit_pct!==null ? row.jnt_transit_cnt : ''"></td>
                          </tr>
                        </template>
                      </tbody>
                    </table>
                  </template>

                  <!-- rts_set — manually set RTS% (read-only here; click ✎ icon to edit via modal) -->
                  <template x-if="col.id==='rts_set'">
                    <span style="display:inline-flex;align-items:flex-start;gap:4px;">
                      <div style="flex:1;">
                        <template x-if="row.rts_pct !== null">
                          <div>
                            <span style="font-weight:700;color:#000;"
                                  x-text="row.rts_pct.toFixed(1)+'%'"></span>
                            <template x-if="row.settings_date">
                              <div style="font-size:9px;color:#94a3b8;margin-top:2px;"
                                   x-text="'from ' + row.settings_date"></div>
                            </template>
                            <template x-if="row.rts_comment">
                              <div style="font-size:9px;color:#64748b;margin-top:1px;font-style:italic;white-space:normal;max-width:120px;"
                                   x-text="'💬 '+row.rts_comment"></div>
                            </template>
                          </div>
                        </template>
                        <template x-if="row.rts_pct === null">
                          <span style="color:#fca5a5;font-style:italic;font-size:11px;">—</span>
                        </template>
                      </div>
                      <button type="button" class="cell-edit-icon" @click="openEditModal(row, 'rts')"
                              title="Edit RTS%">✎</button>
                    </span>
                  </template>

                  <!-- promo — per-date inherited; click ✎ to edit via modal -->
                  <template x-if="col.id==='promo'">
                    <span style="display:inline-flex;align-items:center;gap:4px;">
                      <div style="flex:1;">
                        <template x-if="row.promo">
                          <span x-text="(row.promo.toUpperCase()==='NONE' || row.promo==='-') ? '—' : row.promo"></span>
                        </template>
                        <template x-if="!row.promo">
                          <span style="color:#cbd5e1;" title="no promo set yet">—</span>
                        </template>
                      </div>
                      <button type="button" class="cell-edit-icon" @click="openEditModal(row, 'promo')"
                              title="Edit Promo">✎</button>
                    </span>
                  </template>

                  <!-- price — mode COD, read-only -->
                  <template x-if="col.id==='price'">
                    <span>
                      <template x-if="row.price !== null">
                        <div>
                          <span style="color:#374151;" x-text="money(row.price)"></span>
                          <template x-if="row.price_min !== null">
                            <div style="font-size:9px;color:#94a3b8;"
                                 x-text="'↓ ' + money(row.price_min)"></div>
                          </template>
                          <template x-if="row.price_max !== null">
                            <div style="font-size:9px;color:#94a3b8;"
                                 x-text="'↑ ' + money(row.price_max)"></div>
                          </template>
                        </div>
                      </template>
                      <template x-if="row.price === null">
                        <span style="color:#94a3b8;font-size:11px;">—</span>
                      </template>
                    </span>
                  </template>

                  <!-- item_val — Marketing's cogs; click ✎ to edit via modal -->
                  <template x-if="col.id==='item_val'">
                    <span style="display:inline-flex;align-items:flex-start;gap:4px;">
                      <div style="flex:1;">
                        <template x-if="row.item_value !== null">
                          <div>
                            <span style="color:#111;" x-text="money(row.item_value)"></span>
                            <template x-if="row.item_value_source === 'cogs'">
                              <div style="font-size:9px;color:#cbd5e1;">cogs</div>
                            </template>
                            <template x-if="row.item_value_source === 'manual' && row.settings_date">
                              <div style="font-size:9px;color:#94a3b8;margin-top:2px;"
                                   x-text="'from ' + row.settings_date"></div>
                            </template>
                            <template x-if="row.item_value_comment && row.item_value_source === 'manual'">
                              <div style="font-size:9px;color:#64748b;margin-top:1px;font-style:italic;white-space:normal;max-width:110px;"
                                   x-text="'💬 '+row.item_value_comment"></div>
                            </template>
                          </div>
                        </template>
                        <template x-if="row.item_value === null">
                          <span style="color:#fca5a5;font-style:italic;font-size:11px;">—</span>
                        </template>
                      </div>
                      <button type="button" class="cell-edit-icon" @click="openEditModal(row, 'cogs')"
                              title="Edit Unit Cost (COGS)">✎</button>
                    </span>
                  </template>

                  <!-- item_val_ceo — CEO-only; click ✎ to edit CEO cogs via modal -->
                  <template x-if="col.id==='item_val_ceo'">
                    <span style="display:inline-flex;align-items:center;gap:4px;">
                      <div style="flex:1;">
                        <template x-if="row.item_value_ceo !== null && row.item_value_ceo !== undefined">
                          <span style="color:#111;" x-text="money(row.item_value_ceo)"></span>
                        </template>
                        <template x-if="row.item_value_ceo === null || row.item_value_ceo === undefined">
                          <span style="color:#fca5a5;font-style:italic;font-size:11px;" title="No CEO value set — profit calc shows — for this row.">—</span>
                        </template>
                      </div>
                      <button type="button" class="cell-edit-icon" @click="openEditModal(row, 'cogs_ceo')"
                              title="Edit CEO Unit Cost">✎</button>
                    </span>
                  </template>

                  <!-- ship -->
                  <template x-if="col.id==='ship'">
                    <span style="color:#111;"
                          x-text="row.shipping_fee !== null ? money(row.shipping_fee) : '—'"></span>
                  </template>

                  <!-- cod_fee -->
                  <template x-if="col.id==='cod_fee'">
                    <span style="color:#111;"
                          x-text="row.cod_fee !== null ? money(row.cod_fee) : '—'"></span>
                  </template>

                  <!-- hold — daily HOLD snapshot (units) as-of end_date.
                       Black font; coloring via conditional formatting (column-settings), manual. -->
                  <template x-if="col.id==='hold'">
                    <span style="color:#111;"
                          :title="row.hold_snap_date ? ('HOLD units as-of '+row.hold_snap_date) : 'no hold snapshot yet'"
                          x-text="(row.hold_units !== null && row.hold_units !== undefined) ? num(row.hold_units) : '—'"></span>
                  </template>

                  <!-- action — per-(page, end_date) note; click ✎ to edit via modal -->
                  {{-- action — truncated by default (huwag auto-expand kahit mahaba);
                       may "more/less" toggle per cell. ✎ → floating edit modal. --}}
                  <template x-if="col.id==='action'">
                    <span style="display:flex;width:100%;align-items:flex-start;justify-content:space-between;gap:6px;">
                      <div style="flex:1;text-align:left;min-width:0;">
                        <template x-if="row.action_comment">
                          <div :title="row.action_comment">
                            <div :style="row._actionOpen
                                          ? 'white-space:normal;max-width:200px;'
                                          : 'white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:150px;'"
                                 style="font-size:11px;color:#0f172a;line-height:1.3;">
                              <span x-text="row.action_comment"></span>
                            </div>
                            {{-- Editor info — LAGING visible (kahit collapsed) --}}
                            <template x-if="row.action_by">
                              <div style="font-size:9px;color:#94a3b8;margin-top:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:160px;"
                                   :title="'✎ '+row.action_by + (row.action_at ? (' · '+row.action_at) : '')"
                                   x-text="'✎ '+row.action_by + (row.action_at ? (' · '+row.action_at) : '')"></div>
                            </template>
                            <template x-if="(row.action_comment||'').length > 24">
                              <button type="button" @click="row._actionOpen = !row._actionOpen"
                                      style="background:none;border:none;color:#2563eb;font-size:9px;cursor:pointer;padding:0;font-weight:600;"
                                      x-text="row._actionOpen ? '▾ less' : '▸ more'"></button>
                            </template>
                          </div>
                        </template>
                        <template x-if="!row.action_comment">
                          <span style="color:#cbd5e1;" title="no action logged">—</span>
                        </template>
                      </div>
                      {{-- White chip ✎ — visible sa kahit anong cell bg (white o red CF) --}}
                      <button type="button" @click="openActionModal(row)" title="Edit Action note"
                              style="flex-shrink:0;align-self:flex-start;background:#fff;border:1px solid #cbd5e1;
                                     border-radius:5px;color:#334155;font-size:12px;line-height:1;padding:3px 6px;
                                     cursor:pointer;box-shadow:0 1px 2px rgba(0,0,0,.2);">✎</button>
                    </span>
                  </template>

                </td>
              </template>

              {{-- Row-level Edit button removed. Per-cell ✎ icons sa RTS / Promo /
                   Item Val / Item Val (CEO) columns ang nag-open ng scoped modal
                   for that specific field. --}}
            </tr>

            {{-- Inline expand row — sits directly after THIS page row when
                 expandedPages[page_name].open is true. Hosts the nested
                 campaigns / adsets / ads view from /ads_manager/campaigns/data.
                 Wrapped together with the page row inside a per-iteration
                 <tbody> so they stay interleaved (Alpine x-for needs single
                 root child — <tbody> serves as that root). --}}
            <tr x-show="!row.__itemHeader && (expandedPages[row.page_name] || {}).open"
                class="page-expand-row">
              <td :colspan="(cols.length + 6)" style="padding:0;">{{-- (cols.length + 6): page + item + ang apat na supplier column + cols --}}
                @include('owner._private_expand_inline')
              </td>
            </tr>
          </tbody>
          </template>{{-- /displayRows x-for (item headers + pages interleaved) --}}

          <tbody class="total-tbody">
          <!-- Total row -->
          <template x-if="rows.length > 0">
            <tr class="total-row">
              <td>TOTAL</td>
              <td colspan="5"></td>
              <template x-for="col in cols" :key="col.id">
                <td :style="'text-align:'+col.align+';'+(col.id==='proj_profit'?pbStyle(tot().projected_profit,{included_days:rangeDays,range_days:rangeDays}):'')+(col.id==='proj_prof_1d'?pbStyleN(tot().projected_profit_last_day,1):'')+(col.id==='proj_prof_3d'?pbStyleN(tot().projected_profit_last_3d,3):'')+(col.id==='proj_prof_7d'?pbStyleN(tot().projected_profit_last_7d,7):'')">
                  <template x-if="col.id==='adspent'">
                    <span x-text="money(tot().adspent)"></span>
                  </template>
                  <template x-if="col.id==='orders'">
                    <span x-text="num(tot().orders)"></span>
                  </template>
                  <template x-if="col.id==='orders_1d'">
                    <span x-text="num(tot().orders_last_day)"></span>
                  </template>
                  <template x-if="col.id==='cpp'">
                    <span style="color:#475569;" x-text="md(tot().cpp)"></span>
                  </template>
                  <template x-if="col.id==='proceed'">
                    <span x-text="num(tot().proceed_orders)"></span>
                  </template>
                  <template x-if="col.id==='pcpp'">
                    <span style="color:#475569;" x-text="md(tot().proceed_cpp)"></span>
                  </template>
                  <template x-if="col.id==='tcpr'">
                    <span x-text="(tot().orders > 0) ? ((1 - tot().proceed_orders / tot().orders) * 100).toFixed(1) + '%' : '—'"></span>
                  </template>
                  <template x-if="col.id==='breakeven_cpp'">
                    <span style="color:#cbd5e1;">—</span>
                  </template>
                  <template x-if="col.id==='proj_profit'">
                    <span style="font-weight:700;" x-text="md(tot().projected_profit)"></span>
                  </template>
                  <template x-if="col.id==='per_order'">
                    <span style="color:#111;" x-text="md(tot().proj_profit_per_order)"></span>
                  </template>
                  <template x-if="col.id==='np_per_order'">
                    <span style="color:#111;font-weight:700;" x-text="tot().np_per_order != null ? md(tot().np_per_order) : '—'"
                          :title="tot().projected_profit_last_day != null ? '1D net profit ₱'+Number(tot().projected_profit_last_day||0).toLocaleString('en-PH',{maximumFractionDigits:0})+' / orders '+(tot().orders_last_day||0) : ''"></span>
                  </template>
                  <template x-if="col.id==='np_per_order_3d'">
                    <span style="font-weight:700;color:#111;" x-text="tot().np_per_order_3d != null ? md(tot().np_per_order_3d) : '—'"
                          :title="tot().projected_profit_last_3d != null ? '3D net profit ₱'+Number(tot().projected_profit_last_3d||0).toLocaleString('en-PH',{maximumFractionDigits:0})+' / orders '+(tot().orders_last_3d||0) : ''"></span>
                  </template>
                  <template x-if="col.id==='np_per_order_7d'">
                    <span style="font-weight:700;color:#111;" x-text="tot().np_per_order_7d != null ? md(tot().np_per_order_7d) : '—'"
                          :title="tot().projected_profit_last_7d != null ? '7D net profit ₱'+Number(tot().projected_profit_last_7d||0).toLocaleString('en-PH',{maximumFractionDigits:0})+' / orders '+(tot().orders_last_7d||0) : ''"></span>
                  </template>
                  <template x-if="col.id==='np_per_order_1m'">
                    <span style="font-weight:700;color:#111;" x-text="tot().np_per_order_1m != null ? md(tot().np_per_order_1m) : '—'"
                          :title="tot().projected_profit != null ? 'Range net profit ₱'+Number(tot().projected_profit||0).toLocaleString('en-PH',{maximumFractionDigits:0})+' / orders '+(tot().orders||0)+' (entire range)' : ''"></span>
                  </template>
                  <template x-if="col.id==='proj_pct'">
                    <span style="font-weight:700;color:#111;"
                          x-text="tot().proj_pct!=null ? tot().proj_pct.toFixed(1)+'%' : '—'"
                          :title="tot().gross_sales!=null ? 'profit ₱'+Number(tot().projected_profit||0).toLocaleString('en-PH',{maximumFractionDigits:0})+' / gross ₱'+Number(tot().gross_sales).toLocaleString('en-PH',{maximumFractionDigits:0}) : ''"></span>
                  </template>
                  <template x-if="col.id==='proj_pct_1d'">
                    <span style="font-weight:700;color:#111;"
                          x-text="tot().proj_pct_1d!=null ? tot().proj_pct_1d.toFixed(1)+'%' : '—'"
                          :title="tot().gross_sales_last_day ? '1D profit ₱'+Number(tot().projected_profit_last_day||0).toLocaleString('en-PH',{maximumFractionDigits:0})+' / gross ₱'+Number(tot().gross_sales_last_day||0).toLocaleString('en-PH',{maximumFractionDigits:0})+' (end_date only)' : ''"></span>
                  </template>
                  <template x-if="col.id==='proj_pct_3d'">
                    <span style="font-weight:700;color:#111;"
                          x-text="tot().proj_pct_3d!=null ? tot().proj_pct_3d.toFixed(1)+'%' : '—'"
                          :title="tot().gross_sales_last_3d ? '3D profit ₱'+Number(tot().projected_profit_last_3d||0).toLocaleString('en-PH',{maximumFractionDigits:0})+' / gross ₱'+Number(tot().gross_sales_last_3d||0).toLocaleString('en-PH',{maximumFractionDigits:0}) : ''"></span>
                  </template>
                  <template x-if="col.id==='proj_pct_7d'">
                    <span style="font-weight:700;color:#111;"
                          x-text="tot().proj_pct_7d!=null ? tot().proj_pct_7d.toFixed(1)+'%' : '—'"
                          :title="tot().gross_sales_last_7d ? '7D profit ₱'+Number(tot().projected_profit_last_7d||0).toLocaleString('en-PH',{maximumFractionDigits:0})+' / gross ₱'+Number(tot().gross_sales_last_7d||0).toLocaleString('en-PH',{maximumFractionDigits:0}) : ''"></span>
                  </template>
                  <template x-if="col.id==='proj_prof_1d'">
                    <span style="font-weight:700;" x-text="md(tot().projected_profit_last_day)"></span>
                  </template>
                  <template x-if="col.id==='proj_prof_3d'">
                    <span style="font-weight:700;" x-text="md(tot().projected_profit_last_3d)"></span>
                  </template>
                  <template x-if="col.id==='proj_prof_7d'">
                    <span style="font-weight:700;" x-text="md(tot().projected_profit_last_7d)"></span>
                  </template>
                  <template x-if="!['adspent','orders','orders_1d','cpp','proceed','pcpp','tcpr','breakeven_cpp','proj_profit','per_order','np_per_order','np_per_order_3d','np_per_order_7d','np_per_order_1m','proj_pct','proj_pct_1d','proj_pct_3d','proj_pct_7d','proj_prof_1d','proj_prof_3d','proj_prof_7d'].includes(col.id)">
                    <span></span>
                  </template>
                </td>
              </template>
            </tr>
          </template>

        </tbody>
      </table>
      <div style="padding:7px 12px;font-size:10px;color:#94a3b8;border-top:1px solid #f1f5f9;">
        One row per page · Price = mode COD · Ship/proceed · COD Fee=Price×rate×(1+VAT)/delivered · Proj.%=/Order÷Price · RTS/Del/Transit% = JNT 90-day · Drag headers to reorder
        <template x-if="skippedCount > 0">
          <span style="color:#b45309;font-weight:600;margin-left:8px;"
                :title="'Pages excluded: '+skippedPages.join(', ')">
            ⚠ <span x-text="skippedCount"></span> page(s) skipped (tied primary or unresolved — hover for list)
          </span>
        </template>
      </div>
    </div>
  </div>

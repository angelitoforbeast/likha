<!doctype html>
<html lang="en" x-data="privateUI()" x-init="init()" x-cloak>
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="csrf-token" content="{{ csrf_token() }}" />
  <title>Daily Summary • Private</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
  <style>
    [x-cloak] { display:none !important; }
    * { box-sizing:border-box; margin:0; padding:0; }

    html, body { height:100%; background:#f1f5f9; }
    body { display:flex; flex-direction:column; overflow:hidden; }

    #nav {
      flex-shrink:0; height:52px; background:#1e293b;
      border-bottom:1px solid #334155;
      display:flex; align-items:center; padding:0 18px; gap:10px; z-index:10;
    }

    #scroll { flex:1; overflow:auto; padding:0 16px; min-width:0; }

    .card {
      background:#fff; border-radius:10px;
      box-shadow:0 1px 4px rgba(0,0,0,.09);
      min-width:900px; margin:14px 0;
    }

    table { width:100%; border-collapse:separate; border-spacing:0; }

    thead th {
      position:sticky; top:0; z-index:30;
      background:#1e293b; color:#94a3b8;
      font-size:11px; font-weight:600;
      text-transform:uppercase; letter-spacing:.05em;
      padding:9px 10px; white-space:nowrap;
      border-bottom:2px solid #0f172a;
      user-select:none;
    }
    thead th:first-child { border-radius:10px 0 0 0; }
    thead th:last-child  { border-radius:0 10px 0 0; }
    thead th.sortable { cursor:pointer; }
    thead th.sortable:hover { background:#263347; color:#e2e8f0; }
    thead th.col-active { color:#60a5fa; }
    thead th[draggable="true"] { cursor:grab; }
    thead th[draggable="true"]:active { cursor:grabbing; }
    thead th.drag-over { box-shadow:inset 2px 0 0 #60a5fa; }

    tr.total-row td {
      position:sticky; bottom:0; z-index:20;
      font-weight:700; color:#0f172a;
      background:#f1f5f9; border-top:2px solid #cbd5e1;
    }

    tbody td { border-bottom:1px solid #f1f5f9; }
    tbody tr:hover td { background:#f8fafc; }
    tbody tr.editing-row td { background:#eff6ff !important; }

    td {
      font-size:12.5px; color:#374151;
      padding:7px 10px; white-space:nowrap;
      vertical-align:middle;
    }

    .ii {
      border:1.5px solid #93c5fd; border-radius:6px;
      padding:4px 7px; font-size:12px;
      text-align:right; outline:none; background:#fff;
    }
    .ii:focus { border-color:#3b82f6; box-shadow:0 0 0 2px rgba(59,130,246,.15); }
    .ii-comment {
      border:1.5px solid #93c5fd; border-radius:6px;
      padding:4px 7px; font-size:11px; outline:none; background:#fff; width:100%;
    }
    .ii-comment:focus { border-color:#3b82f6; }

    .badge { display:inline-block; padding:2px 8px; border-radius:9999px; font-size:11px; font-weight:700; }
    .bg  { background:#dcfce7; color:#15803d; }
    .by  { background:#fef9c3; color:#a16207; }
    .bo  { background:#ffedd5; color:#c2410c; }
    .br  { background:#fee2e2; color:#b91c1c; }
    .bb  { background:#dbeafe; color:#1d4ed8; }
    .bx  { background:#f1f5f9; color:#94a3b8; }

    .null-warn { background:#fef2f2 !important; }

    .spin {
      display:inline-block; width:15px; height:15px;
      border:2px solid #475569; border-top-color:#60a5fa;
      border-radius:50%; animation:rot .7s linear infinite; vertical-align:middle;
    }
    @keyframes rot { to { transform:rotate(360deg); } }

    .btn-refresh {
      display:flex; align-items:center; justify-content:center;
      width:32px; height:32px; border-radius:6px; border:1px solid #475569;
      background:#0f172a; color:#94a3b8; cursor:pointer; font-size:16px;
      transition:color .15s, border-color .15s;
    }
    .btn-refresh:hover { color:#e2e8f0; border-color:#94a3b8; }
    .btn-refresh.spinning svg { animation:rot .7s linear infinite; }

    .btn-save   { font-size:11px; padding:3px 10px; border-radius:5px; cursor:pointer; border:none; background:#16a34a; color:#fff; font-weight:700; }
    .btn-save:disabled { opacity:.6; cursor:not-allowed; }
    .btn-cancel { font-size:11px; padding:3px 8px; border-radius:5px; cursor:pointer; border:1.5px solid #e2e8f0; background:#f1f5f9; color:#475569; }
    .btn-set    { font-size:11px; padding:3px 9px; border-radius:5px; cursor:pointer; border:1.5px solid #e2e8f0; background:#f8fafc; color:#64748b; }
    .btn-set:hover { border-color:#93c5fd; color:#2563eb; background:#eff6ff; }
  </style>
</head>
<body>

  <!-- Nav -->
  <div id="nav">
    <span style="color:#f1f5f9;font-weight:700;font-size:14px;">Daily Summary</span>
    <div style="flex:1"></div>
    <span x-show="saveMsg" x-transition style="color:#4ade80;font-size:13px;font-weight:700;" x-text="saveMsg"></span>
    <span style="color:#94a3b8;font-size:11px;font-weight:600;">From</span>
    <input type="date" x-model="startDate" @change="load()"
           style="background:#0f172a;color:#e2e8f0;border:1px solid #475569;
                  border-radius:6px;padding:5px 10px;font-size:13px;outline:none;cursor:pointer;">
    <span style="color:#94a3b8;font-size:11px;font-weight:600;">To</span>
    <input type="date" x-model="endDate" @change="load()"
           style="background:#0f172a;color:#e2e8f0;border:1px solid #475569;
                  border-radius:6px;padding:5px 10px;font-size:13px;outline:none;cursor:pointer;">
    <a :href="'{{ route('owner.private.breakdown') }}?start_date='+startDate+'&end_date='+endDate"
       target="_blank"
       x-show="!isSingleDate"
       style="background:#1e293b;color:#93c5fd;border:1px solid #475569;
              border-radius:6px;padding:5px 10px;font-size:12px;font-weight:700;
              cursor:pointer;text-decoration:none;"
       title="View page × date × item matrix for this range">🧭 Matrix</a>
    <button class="btn-refresh" :class="loading ? 'spinning' : ''" @click="load()" title="Refresh">
      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
           fill="none" stroke="currentColor" stroke-width="2.2"
           stroke-linecap="round" stroke-linejoin="round">
        <path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/>
        <path d="M21 3v5h-5"/>
        <path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/>
        <path d="M3 21v-5h5"/>
      </svg>
    </button>

    @if(!empty($isCEO))
      <a href="{{ route('jnt.supply.excluded.index') }}" target="_blank"
         title="Manage excluded pages (affects /owner/private + /jnt/supply)"
         style="background:#1e293b;color:#f87171;border:1px solid #475569;
                border-radius:6px;padding:5px 10px;font-size:12px;font-weight:700;
                cursor:pointer;margin-left:4px;text-decoration:none;">🚫 Excluded</a>
      <button id="refreshPrimaryBtn"
              @click="refreshPrimary()"
              :disabled="refreshing"
              title="Recompute daily_page_primary_item (last 90 days)"
              style="background:#1e293b;color:#fbbf24;border:1px solid #475569;
                     border-radius:6px;padding:5px 10px;font-size:12px;font-weight:700;
                     cursor:pointer;margin-left:4px;">
        <span x-show="!refreshing">🔄 Primary Items</span>
        <span x-show="refreshing">Recomputing…</span>
      </button>
    @endif
  </div>

  <!-- Scroll area -->
  <div id="scroll">
    <div class="card">
      <table>
        <thead>
          <tr>
            <!-- Fixed: Page -->
            <th style="text-align:left;min-width:110px;">Page</th>
            <!-- Fixed: Item -->
            <th style="text-align:left;min-width:160px;">Item</th>

            <!-- Draggable/reorderable columns -->
            <template x-for="col in cols" :key="col.id">
              <th
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

            <!-- Fixed: Actions -->
            <th style="text-align:center;min-width:90px;"></th>
          </tr>
        </thead>
        <tbody>

          <template x-if="rows.length === 0 && !loading">
            <tr><td :colspan="cols.length + 3" style="text-align:center;padding:48px;color:#94a3b8;font-size:13px;">
              No data for selected date.
            </td></tr>
          </template>

          <template x-if="rows.length === 0 && loading">
            <tr><td :colspan="cols.length + 3" style="text-align:center;padding:48px;color:#94a3b8;font-size:13px;">
              <span class="spin" style="margin-right:6px;"></span>Loading…
            </td></tr>
          </template>

          <template x-for="(row, idx) in sortedRows()" :key="row.page_key">
            <tr :class="editIdx === idx ? 'editing-row' : ''">

              <!-- Fixed: Page -->
              <td style="text-align:center;">
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
              </td>

              <!-- Fixed: Item -->
              <td style="text-align:center;">
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

              <!-- Dynamic columns -->
              <template x-for="col in cols" :key="col.id">
                <td :style="'text-align:'+col.align+';'+(col.id==='rts_set'&&editIdx!==idx?(row.rts_pct===null?'background:#fef2f2;':rbStyle(row.rts_pct)):'')+(col.id==='item_val'&&editIdx!==idx&&row.item_value===null?'background:#fef2f2;':'')+(col.id==='proj_profit'?pbStyle(row.projected_profit,row):'')+(col.id==='proj_pct'&&row.proj_profit_per_order!==null&&row.price>0?rppStyle(row.proj_profit_per_order/row.price*100):'')">

                  <!-- adspent -->
                  <template x-if="col.id==='adspent'">
                    <span style="color:#111;font-weight:500;" x-text="money(row.adspent)"></span>
                  </template>

                  <!-- orders -->
                  <template x-if="col.id==='orders'">
                    <span style="color:#111;" x-text="num(row.orders)"></span>
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

                  <!-- proj_profit — cell background handles color; bold text -->
                  <template x-if="col.id==='proj_profit'">
                    <span style="font-weight:700;" :style="'color:'+pbColor(row.projected_profit)"
                          x-text="md(row.projected_profit)"></span>
                  </template>

                  <!-- per_order -->
                  <template x-if="col.id==='per_order'">
                    <span style="color:#111;" x-text="md(row.proj_profit_per_order)"></span>
                  </template>

                  <!-- proj_pct = /order ÷ price × 100 — bold, cell bg from rppStyle -->
                  <template x-if="col.id==='proj_pct'">
                    <span>
                      <template x-if="row.proj_profit_per_order !== null && row.price > 0">
                        <span style="font-weight:700;"
                              :style="'color:'+rppColor(row.proj_profit_per_order/row.price*100)"
                              x-text="(row.proj_profit_per_order/row.price*100).toFixed(1)+'%'"></span>
                      </template>
                      <template x-if="!(row.proj_profit_per_order !== null && row.price > 0)">
                        <span style="color:#cbd5e1;">—</span>
                      </template>
                    </span>
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

                  <!-- rts_set — manually set RTS% (editable) + comment display -->
                  <template x-if="col.id==='rts_set'">
                    <span>
                      <template x-if="editIdx === idx">
                        <div style="display:flex;flex-direction:column;gap:4px;min-width:140px;">
                          <input class="ii" type="number" step="0.1" min="0" max="100"
                                 x-model="ev.rts_pct" placeholder="RTS%"
                                 @keydown.enter="save()" @keydown.escape="cancel()"
                                 style="width:70px;">
                          <input class="ii-comment" type="text" maxlength="500"
                                 x-model="ev.comment" placeholder="Comment (optional)"
                                 @keydown.enter="save()" @keydown.escape="cancel()">
                          <div style="font-size:9px;color:#94a3b8;">Both 0 = delete override</div>
                        </div>
                      </template>
                      <template x-if="editIdx !== idx">
                        <div>
                          <template x-if="row.rts_pct !== null">
                            <div>
                              <span style="font-weight:700;color:#000;"
                                    x-text="row.rts_pct.toFixed(1)+'%'"></span>
                              <div style="font-size:9px;color:#94a3b8;margin-top:2px;"
                                   x-text="'from ' + row.settings_date"></div>
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
                      </template>
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

                  <!-- item_val — editable inline -->
                  <template x-if="col.id==='item_val'">
                    <span>
                      <template x-if="editIdx === idx">
                        <div style="display:flex;flex-direction:column;gap:4px;min-width:140px;">
                          <input class="ii" type="number" step="1" min="0"
                                 x-model="ev.item_value" placeholder="Item Val."
                                 @keydown.enter="save()" @keydown.escape="cancel()"
                                 style="width:78px;">
                          <input class="ii-comment" type="text" maxlength="500"
                                 x-model="ev.iv_comment" placeholder="Comment (optional)"
                                 @keydown.enter="save()" @keydown.escape="cancel()">
                        </div>
                      </template>
                      <template x-if="editIdx !== idx">
                        <span>
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
                        </span>
                      </template>
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

                </td>
              </template>

              <!-- Fixed: Actions -->
              <td style="text-align:center;">
                <template x-if="editIdx === idx">
                  <span style="display:inline-flex;gap:5px;align-items:center;">
                    <button class="btn-save" @click="save()" :disabled="saving"
                            x-text="saving ? '…' : 'Save'"></button>
                    <button class="btn-cancel" @click="cancel()">✕</button>
                  </span>
                </template>
                <template x-if="editIdx !== idx && row.is_single_date">
                  <button class="btn-set" @click="startEdit(idx, row)"
                          x-text="row.has_settings ? 'Edit' : '+ Set'"></button>
                </template>
                <template x-if="editIdx !== idx && !row.is_single_date">
                  <span style="font-size:10px;color:#cbd5e1;" title="Switch From = To to edit">—</span>
                </template>
              </td>
            </tr>
          </template>

          <!-- Total row -->
          <template x-if="rows.length > 0">
            <tr class="total-row">
              <td>TOTAL</td>
              <td></td>
              <template x-for="col in cols" :key="col.id">
                <td :style="'text-align:'+col.align+';'+(col.id==='proj_profit'?pbStyle(tot().projected_profit,{included_days:rangeDays,range_days:rangeDays}):'')">
                  <template x-if="col.id==='adspent'">
                    <span x-text="money(tot().adspent)"></span>
                  </template>
                  <template x-if="col.id==='orders'">
                    <span x-text="num(tot().orders)"></span>
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
                  <template x-if="col.id==='proj_profit'">
                    <span style="font-weight:700;" x-text="md(tot().projected_profit)"></span>
                  </template>
                  <template x-if="col.id==='per_order'">
                    <span style="color:#111;" x-text="md(tot().proj_profit_per_order)"></span>
                  </template>
                  <template x-if="!['adspent','orders','cpp','proceed','pcpp','proj_profit','per_order'].includes(col.id)">
                    <span></span>
                  </template>
                </td>
              </template>
              <td></td>
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

  <script>
  function privateUI() {
    return {
      ...(function(){
        // URL precedence: ?start_date + ?end_date > legacy ?date= > yesterday PH
        const qs = new URLSearchParams(window.location.search);
        const re = /^\d{4}-\d{2}-\d{2}$/;
        const urlStart = qs.get('start_date');
        const urlEnd   = qs.get('end_date');
        const urlDate  = qs.get('date');
        const ph = new Date(new Date().toLocaleString('en-US',{timeZone:'Asia/Manila'}));
        ph.setDate(ph.getDate()-1);
        const p = n => String(n).padStart(2,'0');
        const yesterday = ph.getFullYear()+'-'+p(ph.getMonth()+1)+'-'+p(ph.getDate());
        let s, e;
        if (urlStart && re.test(urlStart) && urlEnd && re.test(urlEnd)) { s = urlStart; e = urlEnd; }
        else if (urlDate && re.test(urlDate)) { s = urlDate; e = urlDate; }
        else { s = yesterday; e = yesterday; }
        if (s > e) { const t = s; s = e; e = t; }
        return { startDate: s, endDate: e };
      })(),

      rows:[], loading:false, editIdx:-1, editRow:null,
      ev:{ item_value:'', rts_pct:'', comment:'' },
      saving:false, saveMsg:'',
      refreshing:false,
      skippedCount:0, skippedPages:[],
      isSingleDate:true, rangeDays:1,
      sortCol:'', sortDir:'desc',
      dragSrc:null, dragOver:null,
      cols:[],

      // ── Column definitions ────────────────────────────────────────────────
      defaultCols() {
        return [
          { id:'adspent',    label:'Adspent',    sort:'adspent',              align:'center', minw:90  },
          { id:'orders',     label:'Orders',     sort:'orders',               align:'center', minw:65  },
          { id:'cpp',        label:'CPP',        sort:'cpp',                  align:'center', minw:75  },
          { id:'proceed',    label:'Proceed',    sort:'proceed_orders',       align:'center', minw:70  },
          { id:'pcpp',       label:'P.CPP',      sort:'proceed_cpp',          align:'center', minw:75  },
          { id:'proj_profit',label:'Proj.Profit',sort:'projected_profit',     align:'center', minw:95  },
          { id:'per_order',  label:'/Order',     sort:'proj_profit_per_order',align:'center', minw:75  },
          { id:'proj_pct',   label:'Proj.%',     sort:null,                   align:'center', minw:65  },
          { id:'jnt_rts',    label:'RTS%',       sort:null,                   align:'center', minw:100 },
          { id:'jnt_del',    label:'Del%',       sort:null,                   align:'center', minw:90  },
          { id:'jnt_transit',label:'Transit%',   sort:null,                   align:'center', minw:85  },
          { id:'rts_set',    label:'Set RTS%',   sort:'rts_pct',              align:'center', minw:110 },
          { id:'price',      label:'Price',      sort:null,                   align:'center', minw:85  },
          { id:'item_val',   label:'Item Val.',  sort:null,                   align:'center', minw:80  },
          { id:'ship',       label:'Ship',       sort:null,                   align:'center', minw:58  },
          { id:'cod_fee',    label:'COD Fee',    sort:null,                   align:'center', minw:72  },
        ];
      },

      initCols() {
        const defs = this.defaultCols();
        const saved = localStorage.getItem('private_col_order_v1');
        if (saved) {
          try {
            const ids = JSON.parse(saved);
            const defMap = Object.fromEntries(defs.map(c => [c.id, c]));
            const ordered = ids.map(id => defMap[id]).filter(Boolean);
            const savedSet = new Set(ids);
            defs.forEach(c => { if (!savedSet.has(c.id)) ordered.push(c); });
            this.cols = ordered;
            return;
          } catch(e) {}
        }
        this.cols = defs;
      },

      saveCols() {
        localStorage.setItem('private_col_order_v1', JSON.stringify(this.cols.map(c => c.id)));
      },

      // ── Column drag-and-drop ──────────────────────────────────────────────
      colDragStart(e, colId) {
        this.dragSrc = colId;
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', colId);
      },
      colDragEnd(e) {
        this.dragSrc  = null;
        this.dragOver = null;
      },
      colDrop(e, targetId) {
        if (!this.dragSrc || this.dragSrc === targetId) { this.dragOver=null; return; }
        const from = this.cols.findIndex(c => c.id === this.dragSrc);
        const to   = this.cols.findIndex(c => c.id === targetId);
        if (from < 0 || to < 0) { this.dragOver=null; return; }
        const [moved] = this.cols.splice(from, 1);
        this.cols.splice(to, 0, moved);
        this.saveCols();
        this.dragSrc = this.dragOver = null;
      },

      // ── Load ─────────────────────────────────────────────────────────────
      async load(){
        this.loading=true; this.editIdx=-1; this.saveMsg='';
        // Normalize: if start > end, swap before query
        if (this.startDate && this.endDate && this.startDate > this.endDate) {
          const t = this.startDate; this.startDate = this.endDate; this.endDate = t;
        }
        // Persist range in URL so refresh restores it
        const qs = new URLSearchParams({ start_date: this.startDate, end_date: this.endDate });
        history.replaceState(null,'','?'+qs.toString());
        try{
          const r = await fetch('{{ route('owner.private.item-summary') }}?'+qs.toString());
          const j = await r.json();
          this.rows = j.rows||[];
          this.skippedCount = j.skipped_count || 0;
          this.skippedPages = j.skipped_pages || [];
          this.isSingleDate = !!j.is_single_date;
          this.rangeDays    = Number(j.range_days || 1);
        }catch(e){ console.error(e); }
        finally{ this.loading=false; }
      },

      // ── Page breakdown (navigate to separate route) ───────────────────
      openBreakdown(row){
        const qs = new URLSearchParams({
          page_key:   row.page_key,
          start_date: this.startDate,
          end_date:   this.endDate,
        });
        window.open('{{ route('owner.private.breakdown') }}?'+qs.toString(), '_blank');
      },

      // ── CEO: manual recompute of daily_page_primary_item ────────────────
      async refreshPrimary(){
        if (this.refreshing) return;
        if (!confirm('Recompute primary-item table for the last 90 days?\nThis rebuilds the cached per-page/date primary item used by /owner/private and /jnt/supply.')) return;
        this.refreshing = true; this.saveMsg = '';
        try {
          const r = await fetch('{{ route('owner.private.refresh-primary-items') }}', {
            method: 'POST',
            headers: {
              'Content-Type':'application/json',
              'Accept':'application/json',
              'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            body: JSON.stringify({}),
          });
          const j = await r.json();
          if (j.ok && j.summary) {
            const s = j.summary;
            this.saveMsg = `✓ ${s.rows_upserted} rows · ${s.ties_skipped} ties skipped (${s.from}→${s.to}, ${s.elapsed_s}s)`;
            setTimeout(() => this.saveMsg = '', 6000);
            await this.load();
          } else {
            alert(j.message || 'Refresh failed');
          }
        } catch(e) {
          console.error(e);
          alert('Network error');
        } finally {
          this.refreshing = false;
        }
      },

      // ── Sort ─────────────────────────────────────────────────────────────
      sb(col){
        if(this.sortCol===col){ this.sortDir = this.sortDir==='asc'?'desc':'asc'; }
        else{ this.sortCol=col; this.sortDir='desc'; }
      },
      arr(col){ return this.sortCol!==col?'':(this.sortDir==='asc'?' ↑':' ↓'); },
      ac(col) { return this.sortCol===col?'col-active':''; },
      sortedRows(){
        if(!this.sortCol) return this.rows;
        const c=this.sortCol, d=this.sortDir==='asc'?1:-1;
        return [...this.rows].sort((a,b)=>{
          let va=a[c], vb=b[c];
          if(va==null) va=typeof vb==='string'?'':-Infinity;
          if(vb==null) vb=typeof va==='string'?'':-Infinity;
          if(typeof va==='string') return d*va.localeCompare(vb);
          return d*(Number(va)-Number(vb));
        });
      },

      // ── Edit ─────────────────────────────────────────────────────────────
      startEdit(idx, row){
        this.editIdx=idx; this.editRow=row; this.saveMsg='';
        this.ev = {
          item_value: row.item_value !== null ? row.item_value : '',
          rts_pct:    row.rts_pct   !== null ? row.rts_pct   : '',
          comment:    row.rts_comment || '',
          iv_comment: row.item_value_comment || '',
        };
      },
      cancel(){ this.editIdx=-1; this.editRow=null; this.ev={item_value:'',rts_pct:'',comment:'',iv_comment:''}; },

      async save(){
        if (!this.isSingleDate) { alert('Switch to single-date mode (From = To) to edit.'); return; }
        const itemVal = parseFloat(this.ev.item_value);
        const rts     = parseFloat(this.ev.rts_pct);
        if(isNaN(itemVal)||itemVal<0)   { alert('Item Value needed (≥ 0). Set both to 0 to delete this date\'s override.'); return; }
        if(isNaN(rts)||rts<0||rts>100) { alert('RTS% needed (0–100). Set both to 0 to delete this date\'s override.'); return; }

        const row = this.editRow;
        this.saving = true;
        try {
          const r = await fetch('{{ route('owner.private.item-setting.save') }}', {
            method:  'POST',
            headers: { 'Content-Type':'application/json', 'X-CSRF-TOKEN':'{{ csrf_token() }}' },
            body:    JSON.stringify({
              page_name:      row.page_name,
              item_name:      row.item_name,
              item_value:     itemVal,
              rts_pct:        rts,
              effective_date: this.endDate,
              comment:              this.ev.comment    || null,
              item_value_comment:   this.ev.iv_comment || null,
            }),
          });
          let j;
          try { j = await r.json(); }
          catch { alert('Save failed: server returned non-JSON (HTTP '+r.status+')'); return; }
          if (!r.ok) {
            const msg = j.message||(j.errors?Object.values(j.errors).flat().join('\n'):'HTTP '+r.status);
            alert('Save failed:\n'+msg); return;
          }
          if (j.ok) {
            this.cancel();
            await this.load();
            this.saveMsg = j.deleted ? '🗑 Deleted!' : '✓ Saved!';
            setTimeout(()=>{ this.saveMsg=''; }, 2500);
          }
        } catch(e) {
          console.error(e); alert('Save failed: '+e.message);
        } finally {
          this.saving=false;
        }
      },

      // ── Totals ────────────────────────────────────────────────────────────
      tot() {
        const t = { adspent:0, orders:0, proceed_orders:0, projected_profit:null, cpp:null, proceed_cpp:null, proj_profit_per_order:null };
        let hasP=false;
        for (const r of this.rows) {
          t.adspent        += Number(r.adspent        ||0);
          t.orders         += Number(r.orders         ||0);
          t.proceed_orders += Number(r.proceed_orders ||0);
          if (r.projected_profit!=null){ t.projected_profit=(t.projected_profit||0)+r.projected_profit; hasP=true; }
        }
        if(!hasP) t.projected_profit=null;
        t.cpp                  = t.orders>0         ? t.adspent/t.orders         : null;
        t.proceed_cpp          = t.proceed_orders>0  ? t.adspent/t.proceed_orders : null;
        t.proj_profit_per_order= (t.orders>0&&t.projected_profit!=null) ? t.projected_profit/t.orders : null;
        return t;
      },

      // ── Helpers ───────────────────────────────────────────────────────────
      sq(n){ return n||''; },
      // Projected Profit threshold is PER-DAY. If the row has a limited included-day window
      // (mixed primary), divide by included_days. Otherwise divide by range_days.
      ppd(v, row){
        if (v==null || isNaN(Number(v))) return null;
        let days = 1;
        if (row) {
          days = Number(row.included_days) > 0 ? Number(row.included_days)
               : (Number(row.range_days) > 0 ? Number(row.range_days) : 1);
        }
        return Number(v) / days;
      },
      pb(v, row){
        const p = this.ppd(v, row);
        if (p==null) return 'bx';
        return p<0 ? 'br' : p>=3000 ? 'bg' : 'bx';
      },
      pbStyle(v, row){
        const p = this.ppd(v, row);
        if (p==null) return '';
        return p<0 ? 'background:#ff0000;' : p>=3000 ? 'background:#00ff00;' : '';
      },
      pbColor(v){ return '#000'; },
      rppStyle(v){ if(v==null||isNaN(Number(v))) return ''; if(v<5) return 'background:#ff0000;'; if(v<10) return 'background:#ff6600;'; if(v<20) return 'background:#00ffff;'; return 'background:#00ff00;'; },
      rppColor(v){ return '#000'; },
      // Set RTS%: ≤25 cyan, ≤30 green, ≤40 yellow, ≤50 orange, >50 red
      rbStyle(v){ if(v==null||isNaN(Number(v))) return ''; if(v<=25) return 'background:#00ffff;'; if(v<=30) return 'background:#00ff00;'; if(v<=40) return 'background:#ffff00;'; if(v<=50) return 'background:#ff6600;'; return 'background:#ff0000;'; },
      rb(v){ if(v==null||isNaN(v)) return 'bx'; return v>45?'br':v>35?'bo':v>25?'by':'bg'; },
      dlb(v){ if(v==null||isNaN(v)) return 'bx'; return v>=80?'bg':v>=60?'by':v>=40?'bo':'br'; },
      rpp(v){ if(v==null||isNaN(v)) return 'bx'; if(v<5) return 'br'; if(v<10) return 'bo'; if(v<15) return 'by'; if(v<20) return 'bb'; return 'bg'; },
      money(v){ return '₱'+Number(v||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}); },
      md(v)   { return (v==null||isNaN(Number(v)))?'—':this.money(v); },
      num(v)  { return Number(v||0).toLocaleString('en-PH'); },
      fmtMD(dstr){
        if(!dstr) return '';
        const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(dstr); if(!m) return dstr;
        const months=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        return months[parseInt(m[2],10)-1]+' '+parseInt(m[3],10);
      },

      async init(){
        this.initCols();
        await this.load();
      },
    };
  }
  </script>
</body>
</html>

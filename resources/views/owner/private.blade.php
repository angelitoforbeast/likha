<!doctype html>
<html lang="en" x-data="overallUI({{ ($isCEO ?? false) ? 'true' : 'false' }}, {{ ($isMarketingOIC ?? false) ? 'true' : 'false' }})" x-init="init()" x-cloak>
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Overall Summary • Likha</title>

  <script src="https://cdn.tailwindcss.com"></script>
  <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>

  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
  <style>
    [x-cloak]{display:none!important}
    .flatpickr-calendar{z-index:9999!important}
    body { overflow-x: hidden; }
    /* Items column auto-width */
    .items-cell { min-width: 320px; max-width: 500px; }
    .items-cell .item-row { display: flex; justify-content: space-between; gap: 6px; padding: 1px 0; border-bottom: 1px solid #f3f4f6; }
    .items-cell .item-row:last-child { border-bottom: none; }
    .items-cell .item-name { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: #2563eb; cursor: pointer; flex-shrink: 1; min-width: 0; }
    .items-cell .item-name:hover { text-decoration: underline; }
    .items-cell .item-cost { white-space: nowrap; color: #6b7280; text-align: right; flex-shrink: 0; font-size: 10px; }
    .items-cell .item-cod { white-space: nowrap; color: #059669; text-align: right; flex-shrink: 0; font-size: 10px; }
    /* Loading spinner inline */
    .spinner-inline { border: 2px solid #e5e7eb; border-top: 2px solid #3b82f6; border-radius: 50%; width: 18px; height: 18px; animation: spin 0.8s linear infinite; display: inline-block; vertical-align: middle; }
    @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    /* Sortable header */
    .sortable { cursor: pointer; user-select: none; position: relative; }
    .sortable:hover { background-color: #e5e7eb; }
    .sort-arrow { font-size: 10px; margin-left: 2px; color: #9ca3af; }
    .sort-arrow.active { color: #1d4ed8; font-weight: bold; }
    /* Multi-select dropdown */
    .ms-dropdown { position: absolute; z-index: 50; margin-top: 4px; width: 100%; background: white; border: 1px solid #d1d5db; border-radius: 8px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); max-height: 280px; overflow-y: auto; }
    .ms-item { display: flex; align-items: center; gap: 8px; padding: 6px 12px; font-size: 13px; cursor: pointer; }
    .ms-item:hover { background-color: #eff6ff; }
    /* Draggable column header */
    .col-draggable { cursor: grab; }
    .col-draggable:active { cursor: grabbing; }
    .col-dragging { opacity: 0.4; background-color: #dbeafe !important; }
    .col-drag-over { border-left: 3px solid #3b82f6 !important; }
    /* Column settings modal */
    .col-modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.4); z-index: 100; display: flex; align-items: center; justify-content: center; }
    .col-modal { background: white; border-radius: 12px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); width: 380px; max-height: 80vh; display: flex; flex-direction: column; }
    .col-modal-header { padding: 16px 20px; border-bottom: 1px solid #e5e7eb; display: flex; align-items: center; justify-content: space-between; }
    .col-modal-body { padding: 12px 20px; overflow-y: auto; flex: 1; }
    .col-modal-footer { padding: 12px 20px; border-top: 1px solid #e5e7eb; display: flex; gap: 8px; justify-content: flex-end; }
    .col-sort-item { display: flex; align-items: center; gap: 8px; padding: 8px 10px; margin: 2px 0; border: 1px solid #e5e7eb; border-radius: 8px; background: white; font-size: 13px; cursor: grab; user-select: none; transition: box-shadow 0.15s, border-color 0.15s; }
    .col-sort-item:hover { border-color: #93c5fd; box-shadow: 0 1px 3px rgba(59,130,246,0.15); }
    .col-sort-item:active { cursor: grabbing; }
    .col-sort-item.dragging { opacity: 0.4; background: #eff6ff; }
    .col-sort-item .grip { color: #9ca3af; font-size: 16px; line-height: 1; flex-shrink: 0; }
    .col-sort-item .col-label { flex: 1; }
    .col-sort-item .col-idx { color: #9ca3af; font-size: 11px; flex-shrink: 0; }
  </style>
</head>
<body class="bg-gray-100 text-gray-900">
  <!-- Top bar -->
  <nav class="bg-white border-b sticky top-0 z-40">
    <div class="w-full mx-auto px-2 sm:px-3 lg:px-4">
      <div class="h-16 flex items-center justify-between">
        <div class="font-semibold text-lg">Overall Summary</div>
        <div class="text-sm text-gray-500" x-text="dateLabel"></div>
      </div>
    </div>
  </nav>

  <main class="w-full mx-auto px-2 sm:px-3 lg:px-4 py-4 space-y-4">
    <!-- Filters -->
    <section class="bg-white rounded-xl shadow p-3">
      <div class="grid md:grid-cols-6 gap-3 items-end">
        <!-- Page filter (searchable) -->
        <div class="md:col-span-2 relative" x-data="{ open: false, search: '' }">
          <label class="block text-sm font-semibold mb-1">Page</label>
          <div class="relative">
            <input type="text" class="w-full border rounded px-3 py-2 pr-8"
                   x-ref="pageInput"
                   :value="open ? search : (filters.page_name === 'all' ? 'All Pages' : filters.page_name)"
                   @focus="open = true; search = ''; $refs.pageInput.value = ''"
                   @input="open = true; search = $event.target.value"
                   @click.away="open = false; $refs.pageInput.value = filters.page_name === 'all' ? 'All Pages' : filters.page_name"
                   placeholder="Search pages...">
            <svg class="absolute right-2 top-2.5 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
          </div>
          <div x-show="open" x-transition class="absolute z-50 mt-1 w-full bg-white border rounded-lg shadow-lg max-h-60 overflow-y-auto">
            <div class="px-3 py-2 hover:bg-blue-50 cursor-pointer text-sm"
                 :class="filters.page_name === 'all' ? 'bg-blue-100 font-semibold' : ''"
                 @click="filters.page_name = 'all'; open = false; reload()">All Pages</div>
            @foreach(($pages ?? []) as $p)
              <div class="px-3 py-2 hover:bg-blue-50 cursor-pointer text-sm"
                   :class="filters.page_name === '{{ trim($p) }}' ? 'bg-blue-100 font-semibold' : ''"
                   x-show="!search || '{{ strtolower(trim($p)) }}'.includes(search.toLowerCase())"
                   @click="filters.page_name = '{{ trim($p) }}'; open = false; reload()">{{ trim($p) }}</div>
            @endforeach
          </div>
        </div>

        <!-- Item Name filter (multi-select with checkboxes + search) -->
        <div class="md:col-span-2 relative" x-data="{ open: false, search: '' }">
          <label class="block text-sm font-semibold mb-1">Item Name</label>
          <div class="relative">
            <input type="text" class="w-full border rounded px-3 py-2 pr-8" readonly
                   :value="selectedItems.length === 0 ? 'All Items' : selectedItems.length + ' item(s) selected'"
                   @click="open = !open; search = ''"
                   placeholder="Filter by item...">
            <svg class="absolute right-2 top-2.5 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
          </div>
          <div x-show="open" @click.away="open = false" x-transition class="ms-dropdown">
            <div class="sticky top-0 bg-white p-2 border-b">
              <input type="text" class="w-full border rounded px-2 py-1 text-sm" placeholder="Search items..."
                     x-model="search" @click.stop>
              <div class="flex gap-2 mt-1">
                <button class="text-xs text-blue-600 hover:underline" @click.stop="selectedItems = [...allItemNames]">Select All</button>
                <button class="text-xs text-red-600 hover:underline" @click.stop="selectedItems = []">Clear All</button>
              </div>
            </div>
            <template x-for="item in allItemNames.filter(i => !search || i.toLowerCase().includes(search.toLowerCase()))" :key="item">
              <label class="ms-item" @click.stop>
                <input type="checkbox" class="w-4 h-4 rounded" :value="item"
                       :checked="selectedItems.includes(item)"
                       @change="toggleItem(item)">
                <span x-text="item" class="truncate"></span>
              </label>
            </template>
          </div>
        </div>

        <div>
          <label class="block text-sm font-semibold mb-1">Date range</label>
          <input id="dateRange" type="text" placeholder="Select date range"
                 class="w-full border border-gray-300 p-2 rounded-md shadow-sm cursor-pointer bg-white" readonly>
        </div>

        <div class="flex flex-col gap-2">
          <template x-if="isCEO">
            <div class="flex items-center gap-2">
              <input id="showAllColumns" type="checkbox" class="w-4 h-4" x-model="showAllColumns">
              <label for="showAllColumns" class="text-sm">Show all columns</label>
            </div>
          </template>
          <div class="flex items-center gap-2">
            <input id="showAllRows" type="checkbox" class="w-4 h-4" x-model="showAllRows">
            <label for="showAllRows" class="text-sm">Show all rows</label>
          </div>
        </div>
      </div>

      <!-- Quick Date Filter Buttons -->
      <div class="flex flex-wrap gap-2 mt-3">
        <button
          :class="activePreset === 'today' ? 'bg-blue-600 text-white ring-2 ring-blue-300' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'"
          class="px-3 py-2 rounded text-sm font-medium transition-colors"
          @click="setPreset('today')">Today</button>
        <button
          :class="activePreset === 'yesterday' ? 'bg-blue-600 text-white ring-2 ring-blue-300' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'"
          class="px-3 py-2 rounded text-sm font-medium transition-colors"
          @click="setPreset('yesterday')">Yesterday</button>
        <button
          :class="activePreset === 'this_week' ? 'bg-blue-600 text-white ring-2 ring-blue-300' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'"
          class="px-3 py-2 rounded text-sm font-medium transition-colors"
          @click="setPreset('this_week')">This Week</button>
        <button
          :class="activePreset === 'last_7_days' ? 'bg-blue-600 text-white ring-2 ring-blue-300' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'"
          class="px-3 py-2 rounded text-sm font-medium transition-colors"
          @click="setPreset('last_7_days')">Last 7 Days</button>
        <button
          :class="activePreset === 'this_month' ? 'bg-blue-600 text-white ring-2 ring-blue-300' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'"
          class="px-3 py-2 rounded text-sm font-medium transition-colors"
          @click="setPreset('this_month')">This Month</button>
        <button
          :class="activePreset === 'last_month' ? 'bg-blue-600 text-white ring-2 ring-blue-300' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'"
          class="px-3 py-2 rounded text-sm font-medium transition-colors"
          @click="setPreset('last_month')">Last Month</button>
        <button class="px-3 py-2 rounded border hover:bg-gray-50 text-sm" @click="reload()">Refresh</button>

        <!-- Column Settings Button -->
        <button class="px-3 py-2 rounded border hover:bg-gray-50 text-sm flex items-center gap-1"
                @click="showColModal = true"
                title="Customize column order">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
          Columns
        </button>
        <!-- Reset Columns Button -->
        <button class="px-3 py-2 rounded border hover:bg-gray-50 text-sm text-gray-500"
                @click="resetColumns()"
                title="Reset columns to default order">
          <svg class="w-4 h-4 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
          Reset
        </button>

        <span x-show="isLoading" x-transition.opacity class="inline-flex items-center gap-1">
          <span class="spinner-inline"></span>
          <span class="text-sm text-gray-500">Loading...</span>
        </span>
      </div>
    </section>

    <!-- KPI row: Actual RTS + Target CPP + Breakeven CPP (top, single line) -->
    <section class="grid grid-cols-1 md:grid-cols-3 gap-3" x-show="filters.page_name !== 'all'">
      <div class="bg-white rounded-xl shadow p-4 flex items-center justify-between">
        <div>
          <div class="font-semibold">Actual RTS</div>
          <div class="text-xs text-gray-500">Computed only on dates with &lt; 3% In Transit</div>
        </div>
        <div class="text-2xl md:text-3xl font-extrabold" x-text="percent(data.actual_rts_pct)"></div>
      </div>
      <div class="bg-white rounded-xl shadow p-4 flex items-center justify-between">
        <div>
          <div class="font-semibold">Target CPP</div>
          <div class="text-xs text-gray-500">(1-RTS)·(0.985·COD - Unit) - 0.2·COD - 37</div>
        </div>
        <div class="text-2xl md:text-3xl font-extrabold" x-text="moneyOrDash(data.target_cpp)"></div>
      </div>
      <div class="bg-white rounded-xl shadow p-4 flex items-center justify-between">
        <div>
          <div class="font-semibold">Breakeven CPP</div>
          <div class="text-xs text-gray-500">(1-RTS)·(0.985·COD - Unit) - 0.05·COD - 37</div>
        </div>
        <div class="text-2xl md:text-3xl font-extrabold" x-text="moneyOrDash(data.breakeven_cpp)"></div>
      </div>
    </section>

    <!-- Page Summary (single page only) -->
    <section class="bg-white rounded-xl shadow p-3" x-show="filters.page_name !== 'all'">
      <div class="flex items-center justify-between mb-2">
        <div class="font-semibold">Page Summary</div>
        <div class="text-xs text-gray-500">Computed on the server</div>
      </div>
      <div class="overflow-x-auto">
        <table class="min-w-full text-xs table-fixed">
          <thead class="bg-gray-50">
            <tr class="text-left text-gray-600">
              <th class="px-2 py-2 w-[200px]">Date Range</th>
              <th class="px-2 py-2">Page</th>
              <th class="px-2 py-2 text-right">Adspent</th>
              <th class="px-2 py-2 text-right">Proceed CPP</th>
              <th class="px-2 py-2 text-right">RTS</th>
              <th class="px-2 py-2 text-right">Projected Net Profit(%)</th>
            </tr>
          </thead>
          <tbody>
            <template x-for="row in (data.top_summary || [])" :key="row.key">
              <tr class="border-t hover:bg-gray-50">
                <td class="px-2 py-2" x-text="row.rangeLabel"></td>
                <td class="px-2 py-2" x-text="filters.page_name"></td>
                <td class="px-2 py-2 text-right" x-text="moneyOrDash(row.adspent)"></td>
                <td class="px-2 py-2 text-right" x-text="moneyOrDash(row.proceed_cpp)"></td>
                <td class="px-2 py-2 text-right" x-text="percent(data.actual_rts_pct)"></td>
                <td class="px-2 py-2 text-right">
                  <span class="px-2 py-0.5 rounded font-bold"
                        :class="netClass(row.pn_pct)"
                        :style="netStyle(row.pn_pct)"
                        x-text="percent(row.pn_pct)"></span>
                </td>
              </tr>
            </template>
            <template x-if="!(data.top_summary && data.top_summary.length)">
              <tr class="border-t"><td class="px-2 py-2 text-gray-500" colspan="6">No data.</td></tr>
            </template>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Daily Table -->
    <section class="bg-white rounded-xl shadow p-3">
      <div class="flex items-center justify-between mb-2">
        <div class="font-semibold">Daily Ad Spend</div>
        <div class="text-xs text-gray-500">
          Source: ads_manager_reports + macro_output + from_jnts + cogs
        </div>
      </div>

      <div class="overflow-x-visible">
        <!-- LIMITED COLUMNS -->
        <table class="min-w-full w-full text-xs" x-show="!isCEO || (isCEO && !showAllColumns)">
          <thead class="bg-gray-50 sticky top-16 z-20">
            <tr class="text-left text-gray-600">
              <template x-for="col in activeColumns('limited')" :key="col.key">
                <th class="px-2 py-2 col-draggable"
                    :class="[col.align === 'right' ? 'text-right' : '', col.sortable ? 'sortable' : '']"
                    @click="col.sortable && sortBy(col.sortKey || col.key)"
                    draggable="true"
                    @dragstart="colDragStart($event, col.key, 'limited')"
                    @dragover.prevent="colDragOver($event, col.key)"
                    @dragleave="colDragLeave($event)"
                    @drop.prevent="colDrop($event, col.key, 'limited')"
                    @dragend="colDragEnd($event)"
                    :title="'Drag to reorder'">
                  <span x-text="col.label"></span>
                  <template x-if="col.sortable">
                    <span class="sort-arrow" :class="sortArrowClass(col.sortKey || col.key)" x-text="sortArrow(col.sortKey || col.key)"></span>
                  </template>
                </th>
              </template>
            </tr>
          </thead>
          <tbody>
            <template x-if="filteredRows().length===0">
              <tr class="border-t">
                <td class="px-3 py-3 text-gray-500" :colspan="activeColumns('limited').length">No data for selected filters.</td>
              </tr>
            </template>
            <template x-for="row in filteredRows()" :key="(row.date ?? '') + '|' + (row.page ?? '') + '|' + (row.is_total?'1':'0')">
              <tr class="border-t"
                  :class="row.is_total ? 'bg-blue-50 font-bold border-t-2 border-blue-300' : 'hover:bg-gray-50'">
                <template x-for="col in activeColumns('limited')" :key="col.key">
                  <td class="px-2 py-2"
                      :class="[col.align === 'right' ? 'text-right' : '', col.key === 'items_with_costs' ? 'items-cell' : '']">
                    <template x-if="col.key === 'items_with_costs'">
                      <span x-html="fmtItemsWithCosts(row.items_with_costs)"></span>
                    </template>
                    <template x-if="col.key === 'date'">
                      <span x-text="fmtDate(row.date)"></span>
                    </template>
                    <template x-if="col.key === 'page'">
                      <span x-text="row.page ?? '\u2014'"></span>
                    </template>
                    <template x-if="col.key === 'adspent'">
                      <span x-text="money(row.adspent)"></span>
                    </template>
                    <template x-if="col.key === 'orders'">
                      <span x-text="num(row.orders)"></span>
                    </template>
                    <template x-if="col.key === 'proceed_cpp'">
                      <span x-text="moneyOrDash(row.proceed_cpp)"></span>
                    </template>
                    <template x-if="col.key === 'shipped'">
                      <span x-text="num(row.shipped)"></span>
                    </template>
                    <template x-if="col.key === 'avg_delay_days'">
                      <span x-text="days(row.avg_delay_days)"></span>
                    </template>
                    <template x-if="col.key === 'hold'">
                      <span x-text="num(row.hold)"></span>
                    </template>
                    <template x-if="col.key === 'rts_pct'">
                      <span class="px-2 py-0.5 rounded" :class="rtsClass(row.rts_pct)" x-text="percent(row.rts_pct)"></span>
                    </template>
                    <template x-if="col.key === 'actual_rts_pct'">
                      <span class="px-2 py-0.5 rounded" :class="rtsClass(row.actual_rts_pct)" x-text="percent(row.actual_rts_pct)"></span>
                    </template>
                    <template x-if="col.key === 'in_transit_pct'">
                      <span x-text="percent(row.in_transit_pct)"></span>
                    </template>
                    <template x-if="col.key === 'tcpr'">
                      <span class="px-2 py-0.5 rounded" :class="tcprClass(row.tcpr)" x-text="percent(row.tcpr)"></span>
                    </template>
                    <template x-if="col.key === 'net_profit_pct'">
                      <span class="px-2 py-0.5 rounded font-bold"
                            :class="netClass(row.net_profit_pct)"
                            :style="netStyle(row.net_profit_pct)"
                            x-text="percent(row.net_profit_pct)"></span>
                    </template>
                    <template x-if="col.key === 'projected_net_profit_pct'">
                      <span class="px-2 py-0.5 rounded font-bold"
                            :class="netClass(projectedPct(row))"
                            :style="netStyle(projectedPct(row))"
                            x-text="percent(projectedPct(row))"></span>
                    </template>
                  </td>
                </template>
              </tr>
            </template>
          </tbody>
        </table>

        <!-- FULL COLUMNS (CEO) -->
        <table class="min-w-full w-full text-xs" x-show="isCEO && showAllColumns">
          <thead class="bg-gray-50 sticky top-16 z-20">
            <tr class="text-left text-gray-600">
              <template x-for="col in activeColumns('full')" :key="col.key">
                <th class="px-2 py-2 col-draggable"
                    :class="[col.align === 'right' ? 'text-right' : '', col.sortable ? 'sortable' : '']"
                    @click="col.sortable && sortBy(col.sortKey || col.key)"
                    draggable="true"
                    @dragstart="colDragStart($event, col.key, 'full')"
                    @dragover.prevent="colDragOver($event, col.key)"
                    @dragleave="colDragLeave($event)"
                    @drop.prevent="colDrop($event, col.key, 'full')"
                    @dragend="colDragEnd($event)"
                    :title="'Drag to reorder'">
                  <span x-text="col.label"></span>
                  <template x-if="col.sortable">
                    <span class="sort-arrow" :class="sortArrowClass(col.sortKey || col.key)" x-text="sortArrow(col.sortKey || col.key)"></span>
                  </template>
                </th>
              </template>
            </tr>
          </thead>
          <tbody>
            <template x-if="filteredRows().length===0">
              <tr class="border-t">
                <td class="px-3 py-3 text-gray-500" :colspan="activeColumns('full').length">No data for selected filters.</td>
              </tr>
            </template>
            <template x-for="row in filteredRows()" :key="(row.date ?? '') + '|' + (row.page ?? '') + '|' + (row.is_total?'1':'0')">
              <tr class="border-t"
                  :class="row.is_total ? 'bg-blue-50 font-bold border-t-2 border-blue-300' : 'hover:bg-gray-50'">
                <template x-for="col in activeColumns('full')" :key="col.key">
                  <td class="px-2 py-2"
                      :class="[col.align === 'right' ? 'text-right' : '', col.key === 'items_with_costs' ? 'items-cell' : '']">
                    <template x-if="col.key === 'items_with_costs'">
                      <span x-html="fmtItemsWithCosts(row.items_with_costs)"></span>
                    </template>
                    <template x-if="col.key === 'date'">
                      <span x-text="fmtDate(row.date)"></span>
                    </template>
                    <template x-if="col.key === 'page'">
                      <span x-text="row.page ?? '\u2014'"></span>
                    </template>
                    <template x-if="col.key === 'adspent'">
                      <span x-text="money(row.adspent)"></span>
                    </template>
                    <template x-if="col.key === 'orders'">
                      <span x-text="num(row.orders)"></span>
                    </template>
                    <template x-if="col.key === 'proceed_cpp'">
                      <span x-text="moneyOrDash(row.proceed_cpp)"></span>
                    </template>
                    <template x-if="col.key === 'shipped'">
                      <span x-text="num(row.shipped)"></span>
                    </template>
                    <template x-if="col.key === 'avg_delay_days'">
                      <span x-text="days(row.avg_delay_days)"></span>
                    </template>
                    <template x-if="col.key === 'hold'">
                      <span x-text="num(row.hold)"></span>
                    </template>
                    <template x-if="col.key === 'rts_pct'">
                      <span class="px-2 py-0.5 rounded" :class="rtsClass(row.rts_pct)" x-text="percent(row.rts_pct)"></span>
                    </template>
                    <template x-if="col.key === 'actual_rts_pct'">
                      <span class="px-2 py-0.5 rounded" :class="rtsClass(row.actual_rts_pct)" x-text="percent(row.actual_rts_pct)"></span>
                    </template>
                    <template x-if="col.key === 'in_transit_pct'">
                      <span x-text="percent(row.in_transit_pct)"></span>
                    </template>
                    <template x-if="col.key === 'tcpr'">
                      <span class="px-2 py-0.5 rounded" :class="tcprClass(row.tcpr)" x-text="percent(row.tcpr)"></span>
                    </template>
                    <template x-if="col.key === 'net_profit_pct'">
                      <span class="px-2 py-0.5 rounded font-bold"
                            :class="netClass(row.net_profit_pct)"
                            :style="netStyle(row.net_profit_pct)"
                            x-text="percent(row.net_profit_pct)"></span>
                    </template>
                    <template x-if="col.key === 'proceed'">
                      <span x-text="num(row.proceed)"></span>
                    </template>
                    <template x-if="col.key === 'cannot_proceed'">
                      <span x-text="num(row.cannot_proceed)"></span>
                    </template>
                    <template x-if="col.key === 'odz'">
                      <span x-text="num(row.odz)"></span>
                    </template>
                    <template x-if="col.key === 'delivered'">
                      <span x-text="num(row.delivered)"></span>
                    </template>
                    <template x-if="col.key === 'gross_sales'">
                      <span x-text="money(row.gross_sales)"></span>
                    </template>
                    <template x-if="col.key === 'shipping_fee'">
                      <span x-text="money(row.shipping_fee)"></span>
                    </template>
                    <template x-if="col.key === 'cogs'">
                      <span x-text="money(row.cogs)"></span>
                    </template>
                    <template x-if="col.key === 'net_profit'">
                      <span x-text="money(row.net_profit)"></span>
                    </template>
                    <template x-if="col.key === 'returned'">
                      <span x-text="num(row.returned)"></span>
                    </template>
                    <template x-if="col.key === 'for_return'">
                      <span x-text="num(row.for_return)"></span>
                    </template>
                    <template x-if="col.key === 'in_transit'">
                      <span x-text="num(row.in_transit)"></span>
                    </template>
                    <template x-if="col.key === 'cpp'">
                      <span x-text="moneyOrDash(row.cpp)"></span>
                    </template>
                    <template x-if="col.key === 'projected_net_profit'">
                      <span x-text="moneyOrDash(row.projected_net_profit)"></span>
                    </template>
                    <template x-if="col.key === 'projected_net_profit_pct'">
                      <span class="px-2 py-0.5 rounded font-bold"
                            :class="netClass(projectedPct(row))"
                            :style="netStyle(projectedPct(row))"
                            x-text="percent(projectedPct(row))"></span>
                    </template>
                  </td>
                </template>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
    </section>
  </main>

  <!-- Column Settings Modal -->
  <template x-if="showColModal">
    <div class="col-modal-overlay" @click.self="showColModal = false" @keydown.escape.window="showColModal = false">
      <div class="col-modal" @click.stop>
        <div class="col-modal-header">
          <h3 class="font-semibold text-base">Customize Columns</h3>
          <button class="text-gray-400 hover:text-gray-600" @click="showColModal = false">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
          </button>
        </div>
        <div class="col-modal-body">
          <p class="text-xs text-gray-500 mb-3">Drag items to reorder columns. Changes are saved automatically.</p>
          <div x-ref="modalSortList">
            <template x-for="(col, idx) in modalColumns()" :key="col.key">
              <div class="col-sort-item"
                   draggable="true"
                   :data-col-key="col.key"
                   @dragstart="modalDragStart($event, idx)"
                   @dragover.prevent="modalDragOver($event, idx)"
                   @dragleave="modalDragLeave($event)"
                   @drop.prevent="modalDrop($event, idx)"
                   @dragend="modalDragEnd($event)">
                <span class="grip">\u2630</span>
                <span class="col-label" x-text="col.label"></span>
                <span class="col-idx" x-text="'#' + (idx + 1)"></span>
              </div>
            </template>
          </div>
        </div>
        <div class="col-modal-footer">
          <button class="px-4 py-2 text-sm rounded border text-gray-600 hover:bg-gray-50"
                  @click="resetColumns(); showColModal = false">Reset to Default</button>
          <button class="px-4 py-2 text-sm rounded bg-blue-600 text-white hover:bg-blue-700"
                  @click="showColModal = false">Done</button>
        </div>
      </div>
    </div>
  </template>

  <script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
  <script>
    function overallUI(isCEO=false, isMarketingOIC=false){
      // Default column definitions
      const DEFAULT_LIMITED_COLS = [
        { key: 'date',                       label: 'Date',                    align: 'left',  sortable: true  },
        { key: 'page',                       label: 'Page',                    align: 'left',  sortable: true  },
        { key: 'items_with_costs',           label: 'Items / Unit Cost / COD', align: 'left',  sortable: false },
        { key: 'adspent',                    label: 'Adspent',                 align: 'right', sortable: true  },
        { key: 'orders',                     label: 'Orders',                  align: 'right', sortable: true  },
        { key: 'proceed_cpp',                label: 'Proceed CPP',             align: 'right', sortable: true  },
        { key: 'shipped',                    label: 'Shipped',                 align: 'right', sortable: true  },
        { key: 'avg_delay_days',             label: 'Delay',                   align: 'right', sortable: true  },
        { key: 'hold',                       label: 'Hold',                    align: 'right', sortable: true  },
        { key: 'rts_pct',                    label: 'RTS%',                    align: 'right', sortable: true  },
        { key: 'actual_rts_pct',             label: 'Est. RTS%',               align: 'right', sortable: true  },
        { key: 'in_transit_pct',             label: 'In Transit%',             align: 'right', sortable: true  },
        { key: 'tcpr',                       label: 'TCPR',                    align: 'right', sortable: true  },
        { key: 'net_profit_pct',             label: 'Net Profit(%)',            align: 'right', sortable: true  },
        { key: 'projected_net_profit_pct',   label: 'Projected Net Profit(%)', align: 'right', sortable: true  },
      ];

      const DEFAULT_FULL_COLS = [
        { key: 'date',                       label: 'Date',                    align: 'left',  sortable: true  },
        { key: 'page',                       label: 'Page',                    align: 'left',  sortable: true  },
        { key: 'items_with_costs',           label: 'Items / Unit Cost / COD', align: 'left',  sortable: false },
        { key: 'adspent',                    label: 'Adspent',                 align: 'right', sortable: true  },
        { key: 'orders',                     label: 'Orders',                  align: 'right', sortable: true  },
        { key: 'proceed_cpp',                label: 'Proceed CPP',             align: 'right', sortable: true  },
        { key: 'shipped',                    label: 'Shipped',                 align: 'right', sortable: true  },
        { key: 'avg_delay_days',             label: 'Delay',                   align: 'right', sortable: true  },
        { key: 'hold',                       label: 'Hold',                    align: 'right', sortable: true  },
        { key: 'rts_pct',                    label: 'RTS%',                    align: 'right', sortable: true  },
        { key: 'actual_rts_pct',             label: 'Est. RTS%',               align: 'right', sortable: true  },
        { key: 'in_transit_pct',             label: 'In Transit%',             align: 'right', sortable: true  },
        { key: 'tcpr',                       label: 'TCPR',                    align: 'right', sortable: true  },
        { key: 'net_profit_pct',             label: 'Net Profit(%)',            align: 'right', sortable: true  },
        { key: 'proceed',                    label: 'Proceed',                 align: 'right', sortable: true  },
        { key: 'cannot_proceed',             label: 'Cannot Proceed',          align: 'right', sortable: true  },
        { key: 'odz',                        label: 'ODZ',                     align: 'right', sortable: true  },
        { key: 'delivered',                  label: 'Delivered',               align: 'right', sortable: true  },
        { key: 'gross_sales',                label: 'Gross Sales',             align: 'right', sortable: true  },
        { key: 'shipping_fee',               label: 'Shipping Fee',            align: 'right', sortable: true  },
        { key: 'cogs',                       label: 'COGS',                    align: 'right', sortable: true  },
        { key: 'net_profit',                 label: 'Net Profit',              align: 'right', sortable: true  },
        { key: 'returned',                   label: 'Returned',                align: 'right', sortable: true  },
        { key: 'for_return',                 label: 'For Return',              align: 'right', sortable: true  },
        { key: 'in_transit',                 label: 'In Transit',              align: 'right', sortable: true  },
        { key: 'cpp',                        label: 'CPP',                     align: 'right', sortable: true  },
        { key: 'projected_net_profit',       label: 'Projected Net Profit',    align: 'right', sortable: false },
        { key: 'projected_net_profit_pct',   label: 'Projected Net Profit(%)', align: 'right', sortable: true  },
      ];

      const LS_KEY_LIMITED = 'likha_col_order_limited';
      const LS_KEY_FULL    = 'likha_col_order_full';

      function loadOrder(lsKey, defaults) {
        try {
          const saved = localStorage.getItem(lsKey);
          if (!saved) return defaults.map(c => c.key);
          const order = JSON.parse(saved);
          // Validate: must contain same keys
          const defKeys = new Set(defaults.map(c => c.key));
          const savedKeys = new Set(order);
          if (defKeys.size !== savedKeys.size) return defaults.map(c => c.key);
          for (const k of defKeys) { if (!savedKeys.has(k)) return defaults.map(c => c.key); }
          return order;
        } catch(e) { return defaults.map(c => c.key); }
      }

      function saveOrder(lsKey, order) {
        try { localStorage.setItem(lsKey, JSON.stringify(order)); } catch(e) {}
      }

      return {
        isCEO,
        isMarketingOIC,
        showAllColumns: false,
        showAllRows: true,
        isLoading: false,
        activePreset: '',
        showColModal: false,

        // Column order (array of keys)
        limitedColOrder: loadOrder(LS_KEY_LIMITED, DEFAULT_LIMITED_COLS),
        fullColOrder:    loadOrder(LS_KEY_FULL, DEFAULT_FULL_COLS),

        // Drag state for table headers
        _dragColKey: null,
        _dragTableType: null,

        // Drag state for modal
        _modalDragIdx: null,

        // Sorting
        sortCol: '',
        sortDir: 'asc',

        // Item filter
        selectedItems: [],
        allItemNames: [],

        data: { ads_daily: [], actual_rts_pct: null, top_summary: [], target_cpp: null, breakeven_cpp: null },
        filters: { page_name: 'all', start_date: '', end_date: '' },
        dateLabel: 'Select dates',

        // ===== Column order helpers =====
        activeColumns(type) {
          const defs = type === 'full' ? DEFAULT_FULL_COLS : DEFAULT_LIMITED_COLS;
          const order = type === 'full' ? this.fullColOrder : this.limitedColOrder;
          const map = {};
          defs.forEach(c => { map[c.key] = c; });
          return order.map(k => map[k]).filter(Boolean);
        },

        modalColumns() {
          const type = (this.isCEO && this.showAllColumns) ? 'full' : 'limited';
          return this.activeColumns(type);
        },

        // Table header drag-and-drop
        colDragStart(e, key, type) {
          this._dragColKey = key;
          this._dragTableType = type;
          e.target.classList.add('col-dragging');
          e.dataTransfer.effectAllowed = 'move';
          e.dataTransfer.setData('text/plain', key);
        },
        colDragOver(e, key) {
          e.target.closest('th')?.classList.add('col-drag-over');
        },
        colDragLeave(e) {
          e.target.closest('th')?.classList.remove('col-drag-over');
        },
        colDrop(e, targetKey, type) {
          e.target.closest('th')?.classList.remove('col-drag-over');
          const srcKey = this._dragColKey;
          if (!srcKey || srcKey === targetKey || this._dragTableType !== type) return;
          const order = type === 'full' ? this.fullColOrder : this.limitedColOrder;
          const srcIdx = order.indexOf(srcKey);
          const tgtIdx = order.indexOf(targetKey);
          if (srcIdx < 0 || tgtIdx < 0) return;
          // Move src to tgt position
          order.splice(srcIdx, 1);
          order.splice(tgtIdx, 0, srcKey);
          if (type === 'full') {
            this.fullColOrder = [...order];
            saveOrder(LS_KEY_FULL, this.fullColOrder);
          } else {
            this.limitedColOrder = [...order];
            saveOrder(LS_KEY_LIMITED, this.limitedColOrder);
          }
        },
        colDragEnd(e) {
          e.target.classList.remove('col-dragging');
          document.querySelectorAll('.col-drag-over').forEach(el => el.classList.remove('col-drag-over'));
          this._dragColKey = null;
          this._dragTableType = null;
        },

        // Modal drag-and-drop
        modalDragStart(e, idx) {
          this._modalDragIdx = idx;
          e.target.classList.add('dragging');
          e.dataTransfer.effectAllowed = 'move';
          e.dataTransfer.setData('text/plain', idx);
        },
        modalDragOver(e, idx) {
          const el = e.target.closest('.col-sort-item');
          if (el) el.style.borderTopColor = '#3b82f6';
        },
        modalDragLeave(e) {
          const el = e.target.closest('.col-sort-item');
          if (el) el.style.borderTopColor = '';
        },
        modalDrop(e, targetIdx) {
          const el = e.target.closest('.col-sort-item');
          if (el) el.style.borderTopColor = '';
          const srcIdx = this._modalDragIdx;
          if (srcIdx === null || srcIdx === targetIdx) return;
          const type = (this.isCEO && this.showAllColumns) ? 'full' : 'limited';
          const order = type === 'full' ? [...this.fullColOrder] : [...this.limitedColOrder];
          const item = order.splice(srcIdx, 1)[0];
          order.splice(targetIdx, 0, item);
          if (type === 'full') {
            this.fullColOrder = order;
            saveOrder(LS_KEY_FULL, order);
          } else {
            this.limitedColOrder = order;
            saveOrder(LS_KEY_LIMITED, order);
          }
        },
        modalDragEnd(e) {
          e.target.classList.remove('dragging');
          document.querySelectorAll('.col-sort-item').forEach(el => el.style.borderTopColor = '');
          this._modalDragIdx = null;
        },

        resetColumns() {
          this.limitedColOrder = DEFAULT_LIMITED_COLS.map(c => c.key);
          this.fullColOrder = DEFAULT_FULL_COLS.map(c => c.key);
          saveOrder(LS_KEY_LIMITED, this.limitedColOrder);
          saveOrder(LS_KEY_FULL, this.fullColOrder);
        },

        // formatting helpers
        money(v){ return `\u20b1${Number(v||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2})}`; },
        moneyOrDash(v){ return (v==null || isNaN(v)) ? '\u2014' : this.money(v); },
        num(v){ return Number(v||0).toLocaleString('en-PH'); },
        percent(v){ return (v==null || isNaN(v)) ? '\u2014' : (Number(v).toFixed(2) + '%'); },
        ymd(d){ const p=n=>String(n).padStart(2,'0'); return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate()); },
        days(v){ return (v==null || isNaN(v)) ? '\u2014' : Number(v).toFixed(2); },

        fmtItemsWithCosts(items){
          if (!Array.isArray(items) || items.length===0) return '\u2014';
          return items.map(it => {
            const label = it.label || '\u2014';
            const cost = it.cost || '\u2014';
            const cod = it.cod || '\u2014';
            const noCost = !it.cost || it.cost === '\u2014' || it.cost === '\u20b10.00';
            const costClass = noCost ? 'item-cost text-red-500 font-semibold' : 'item-cost';
            const costDisplay = noCost ? '<span title="Missing unit cost!">\u26a0 No cost</span>' : cost;
            return `<div class="item-row"><a href="/item/cogs" class="item-name" title="Edit in COGS">${label}</a><span class="${costClass}">${costDisplay}</span><span class="item-cod">${cod}</span></div>`;
          }).join('');
        },

        fmtDate(dateStr){
          if (!dateStr) return '\u2014';
          if (dateStr.toUpperCase() === 'TOTAL' || dateStr.toUpperCase() === 'TOTALS') return dateStr;
          if (dateStr.includes('\u2013') || dateStr.includes(' - ')) {
            const sep = dateStr.includes('\u2013') ? '\u2013' : '-';
            const parts = dateStr.split(sep).map(s => s.trim());
            if (parts.length === 2) return this.fmtSingleDate(parts[0]) + ' \u2013 ' + this.fmtSingleDate(parts[1]);
          }
          return this.fmtSingleDate(dateStr);
        },
        fmtSingleDate(s){
          if (!s) return '\u2014';
          const M = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
          const d = new Date(s + 'T00:00:00');
          if (isNaN(d.getTime())) return s;
          return M[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
        },

        projectedPct(row){
          const pre = row?.projected_net_profit_pct;
          return (pre==null || isNaN(pre)) ? null : Number(pre);
        },

        // Conditional formatting
        tcprClass(pct){
          if (pct == null || isNaN(pct)) return '';
          if (pct > 7) return 'bg-red-100 text-red-800';
          if (pct > 5) return 'bg-orange-100 text-orange-800';
          if (pct > 3) return 'bg-yellow-100 text-yellow-800';
          return '';
        },
        rtsClass(pct){
          if (pct == null || isNaN(pct)) return '';
          if (pct > 35) return 'bg-red-100 text-red-800';
          if (pct > 30) return 'bg-orange-100 text-orange-800';
          if (pct > 25) return 'bg-yellow-100 text-yellow-800';
          return '';
        },
        netClass(pct){
          if (pct == null || isNaN(pct)) return '';
          if (pct < 0)  return 'bg-red-100 text-red-800';
          if (pct < 5)  return 'bg-orange-100 text-orange-800';
          if (pct < 10) return 'bg-yellow-100 text-yellow-800';
          if (pct < 15) return 'bg-blue-100 text-blue-800';
          return '';
        },
        netStyle(pct){
          if (pct == null || isNaN(pct)) return {};
          if (pct >= 15) return { backgroundColor: '#00ff00', color: '#052e16' };
          return {};
        },

        // Sorting
        sortBy(col){
          if (this.sortCol === col) {
            this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc';
          } else {
            this.sortCol = col;
            this.sortDir = 'asc';
          }
        },
        sortArrow(col){
          if (this.sortCol !== col) return '\u2195';
          return this.sortDir === 'asc' ? '\u2191' : '\u2193';
        },
        sortArrowClass(col){
          return this.sortCol === col ? 'active' : '';
        },

        // Item filter toggle
        toggleItem(item){
          const idx = this.selectedItems.indexOf(item);
          if (idx >= 0) {
            this.selectedItems.splice(idx, 1);
          } else {
            this.selectedItems.push(item);
          }
        },

        // Extract all unique item names from data
        buildItemNames(){
          const names = new Set();
          (this.data.ads_daily || []).forEach(row => {
            if (row.is_total) return;
            (row.items_with_costs || []).forEach(it => {
              if (it.label) {
                // Remove qty suffix like "(37)" for cleaner matching
                const clean = it.label.replace(/\(\d+\)$/, '').replace(/^\d+\s*x\s*/i, '').trim();
                if (clean) names.add(clean);
              }
            });
          });
          this.allItemNames = [...names].sort();
        },

        // Check if a row has any of the selected items
        rowHasSelectedItems(row){
          if (this.selectedItems.length === 0) return true;
          if (row.is_total) return true;
          const rowItems = (row.items_with_costs || []).map(it => {
            return (it.label || '').replace(/\(\d+\)$/, '').replace(/^\d+\s*x\s*/i, '').trim();
          });
          return this.selectedItems.some(sel => rowItems.includes(sel));
        },

        // Filtered + sorted rows
        filteredRows(){
          let rows = this.data.ads_daily || [];
          if (!Array.isArray(rows)) return [];

          // Show all rows toggle
          rows = this.showAllRows ? rows : rows.filter(r => r.is_total);

          // Item filter
          if (this.selectedItems.length > 0) {
            rows = rows.filter(r => this.rowHasSelectedItems(r));
          }

          // Sort (keep total at bottom)
          if (this.sortCol) {
            const col = this.sortCol;
            const dir = this.sortDir === 'asc' ? 1 : -1;
            const nonTotal = rows.filter(r => !r.is_total);
            const totals = rows.filter(r => r.is_total);

            nonTotal.sort((a, b) => {
              let va = a[col], vb = b[col];
              if (va == null) va = col === 'page' || col === 'date' ? '' : -Infinity;
              if (vb == null) vb = col === 'page' || col === 'date' ? '' : -Infinity;
              if (typeof va === 'string' && typeof vb === 'string') return dir * va.localeCompare(vb);
              return dir * ((Number(va) || 0) - (Number(vb) || 0));
            });

            rows = [...nonTotal, ...totals];
          }

          return rows;
        },

        rowsForDisplay(rows){
          return this.filteredRows();
        },

        setDateLabel(){
          if (!this.filters.start_date || !this.filters.end_date) { this.dateLabel = 'Select dates'; return; }
          const s = new Date(this.filters.start_date+'T00:00:00');
          const e = new Date(this.filters.end_date+'T00:00:00');
          const same = s.getTime()===e.getTime();
          const M = i => ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][i];
          this.dateLabel = same
            ? `${M(s.getMonth())} ${s.getDate()}, ${s.getFullYear()}`
            : `${M(s.getMonth())} ${s.getDate()}, ${s.getFullYear()} \u2013 ${M(e.getMonth())} ${e.getDate()}, ${e.getFullYear()}`;
        },

        detectPreset(){
          const today = new Date();
          const todayStr = this.ymd(today);
          const yesterday = new Date(today); yesterday.setDate(today.getDate() - 1);
          const yesterdayStr = this.ymd(yesterday);
          const dow = today.getDay();
          const mondayOffset = dow === 0 ? 6 : dow - 1;
          const monday = new Date(today); monday.setDate(today.getDate() - mondayOffset);
          const mondayStr = this.ymd(monday);
          const last7 = new Date(today); last7.setDate(today.getDate() - 6);
          const last7Str = this.ymd(last7);
          const monthStart = new Date(today.getFullYear(), today.getMonth(), 1);
          const monthStartStr = this.ymd(monthStart);
          const lastMonthStart = new Date(today.getFullYear(), today.getMonth() - 1, 1);
          const lastMonthEnd = new Date(today.getFullYear(), today.getMonth(), 0);
          const lastMonthStartStr = this.ymd(lastMonthStart);
          const lastMonthEndStr = this.ymd(lastMonthEnd);

          const s = this.filters.start_date;
          const e = this.filters.end_date;

          if (s === todayStr && e === todayStr) return 'today';
          if (s === yesterdayStr && e === yesterdayStr) return 'yesterday';
          if (s === mondayStr && e === todayStr) return 'this_week';
          if (s === last7Str && e === todayStr) return 'last_7_days';
          if (s === monthStartStr && e === todayStr) return 'this_month';
          if (s === lastMonthStartStr && e === lastMonthEndStr) return 'last_month';
          return '';
        },

        setPreset(preset){
          const today = new Date();
          let startDate, endDate;
          switch(preset){
            case 'today': startDate = endDate = today; break;
            case 'yesterday':
              const y = new Date(today); y.setDate(today.getDate() - 1);
              startDate = endDate = y; break;
            case 'this_week':
              const dow = today.getDay();
              const mondayOffset = dow === 0 ? 6 : dow - 1;
              startDate = new Date(today); startDate.setDate(today.getDate() - mondayOffset);
              endDate = today; break;
            case 'last_7_days':
              startDate = new Date(today); startDate.setDate(today.getDate() - 6);
              endDate = today; break;
            case 'this_month':
              startDate = new Date(today.getFullYear(), today.getMonth(), 1);
              endDate = today; break;
            case 'last_month':
              startDate = new Date(today.getFullYear(), today.getMonth() - 1, 1);
              endDate = new Date(today.getFullYear(), today.getMonth(), 0); break;
          }
          this.filters.start_date = this.ymd(startDate);
          this.filters.end_date = this.ymd(endDate);
          this.activePreset = preset;
          this.setDateLabel();
          const fp = document.querySelector('#dateRange')._flatpickr;
          if (fp) {
            fp.setDate([this.filters.start_date, this.filters.end_date], false);
            fp.input.value = `${this.filters.start_date} to ${this.filters.end_date}`;
          }
          this.reload();
        },

        async reload(){
          this.isLoading = true;
          try {
            const params = new URLSearchParams({
              page_name: this.filters.page_name || 'all',
              start_date: this.filters.start_date || '',
              end_date: this.filters.end_date   || ''
            });
            const res  = await fetch('{{ route('owner.private.data') }}?'+params.toString());
            const json = await res.json();
            this.data = json;
            this.activePreset = this.detectPreset();
            this.buildItemNames();
            // Reset item filter when data changes
            this.selectedItems = [];
          } catch(e) {
            console.error('Reload error:', e);
          } finally {
            this.isLoading = false;
          }
        },

        async init(){
          const now = new Date();
          const start = new Date(now);
          start.setDate(now.getDate() - 29);
          this.filters.start_date = this.ymd(start);
          this.filters.end_date   = this.ymd(now);
          this.setDateLabel();

          this.$nextTick(() => {
            window.flatpickr('#dateRange', {
              mode: 'range',
              dateFormat: 'Y-m-d',
              defaultDate: [this.filters.start_date, this.filters.end_date],
              onClose: (sel) => {
                if (sel.length === 2) {
                  this.filters.start_date = this.ymd(sel[0]);
                  this.filters.end_date   = this.ymd(sel[1]);
                } else if (sel.length === 1) {
                  this.filters.start_date = this.ymd(sel[0]);
                  this.filters.end_date   = this.ymd(sel[0]);
                } else { return; }
                this.setDateLabel();
                this.reload();
              },
              onReady: (_sd, _ds, inst) => {
                inst.input.value = `${this.filters.start_date} to ${this.filters.end_date}`;
              }
            });
          });

          await this.reload();
        }
      }
    }
  </script>
</body>
</html>

{{-- resources/views/ads_manager/cpp.blade.php --}}
<x-layout>
  <x-slot name="title">CPP</x-slot>
  <x-slot name="heading">CPP Summary</x-slot>

  <style>
    /* ✅ Custom searchable dropdown (Page) */
    .page-dd { position: relative; width: 260px; }
    .page-dd-btn {
      width: 260px;
      border: 1px solid #d1d5db;
      border-radius: 0.375rem;
      padding: 0.45rem 0.6rem;
      background: white;
      text-align: left;
      font-size: 0.875rem;
      line-height: 1.25rem;
    }
    .page-dd-panel {
      position: absolute;
      top: calc(100% + 8px);
      left: 0;
      width: 260px;
      background: white;
      border: 1px solid #e5e7eb;
      border-radius: 0.75rem;
      box-shadow: 0 10px 25px rgba(0,0,0,0.10);
      z-index: 9999;
      padding: 0.6rem;
      display: none;
    }
    .page-dd.open .page-dd-panel { display: block; }
    .page-dd-search {
      width: 100%;
      border: 1px solid #d1d5db;
      border-radius: 0.5rem;
      padding: 0.45rem 0.6rem;
      font-size: 0.875rem;
    }
    .page-dd-list {
      margin-top: 0.55rem;
      max-height: 280px;
      overflow: auto;
      border: 1px solid #f3f4f6;
      border-radius: 0.5rem;
    }
    .page-dd-item {
      padding: 0.55rem 0.65rem;
      cursor: pointer;
      font-size: 0.875rem;
      line-height: 1.25rem;
      border-bottom: 1px solid #f3f4f6;
      user-select: none;
    }
    .page-dd-item:last-child { border-bottom: 0; }
    .page-dd-item:hover { background: #f9fafb; }
    .page-dd-item.selected { background: #eef2ff; }
    .page-dd-empty {
      padding: 0.65rem;
      font-size: 0.8rem;
      color: #6b7280;
    }
    /* Multi-select checkbox styling for #pageDd items only */
    #pageDd .page-dd-item {
      display: flex; align-items: center; gap: 0.5rem;
    }
    #pageDd .page-dd-item input[type="checkbox"] {
      flex-shrink: 0; cursor: pointer; width: 14px; height: 14px;
      accent-color: #2563eb;
    }
    #pageDd .page-dd-item-label { flex: 1; min-width: 0; }
    #pageDd .page-dd-actions {
      display: flex; gap: 6px; padding-top: 8px; margin-top: 8px;
      border-top: 1px solid #f3f4f6;
    }
    #pageDd .page-dd-btn-apply {
      flex: 1; background: #2563eb; color: white;
      font-size: 12.5px; font-weight: 600; padding: 6px 10px;
      border-radius: 5px; border: none; cursor: pointer;
    }
    #pageDd .page-dd-btn-apply:hover { background: #1d4ed8; }
    #pageDd .page-dd-btn-clear {
      background: #f1f5f9; color: #475569;
      font-size: 12.5px; padding: 6px 10px;
      border-radius: 5px; border: 1px solid #e2e8f0; cursor: pointer;
    }
    #pageDd .page-dd-btn-clear:hover { background: #e2e8f0; }
    #pageDd .page-dd-count {
      font-size: 11px; color: #64748b; padding: 4px 0 6px;
    }
  </style>

  {{-- Filter Controls --}}
  <div class="mt-4 mb-6 flex flex-wrap items-end gap-4">
    <div class="page-dd" id="pageDd">
      <label class="block font-semibold mb-1">Select Pages:</label>

      {{-- Multi-select state: comma-separated page names, or 'all' for everything.
           Backwards-compat — accepts legacy ?ui_page= if ?pages= is missing. --}}
      @php
        $selPages = $selectedPages ?? [];
        if (empty($selPages) && request()->filled('ui_page') && request('ui_page') !== 'all') {
          $selPages = [request('ui_page')];
        }
        $hiddenVal = empty($selPages) ? 'all' : implode(',', $selPages);
        $btnLabel  = empty($selPages)
          ? 'All Pages'
          : (count($selPages) === 1 ? $selPages[0] : count($selPages) . ' pages selected');
      @endphp
      <input type="hidden" id="pageHidden" value="{{ $hiddenVal }}">

      <button type="button" class="page-dd-btn" id="pageDdBtn" aria-haspopup="listbox" aria-expanded="false">
        <span id="pageDdLabel">{{ $btnLabel }}</span>
      </button>

      <div class="page-dd-panel" id="pageDdPanel">
        <input type="text" class="page-dd-search" id="pageDdSearch" placeholder="Type to filter..." autocomplete="off">

        <div class="page-dd-count" id="pageDdCount"></div>

        <div class="page-dd-list" id="pageDdList" role="listbox">
          <div class="page-dd-item" data-value="all">
            <input type="checkbox" id="page-cb-all" {{ empty($selPages) ? 'checked' : '' }}>
            <span class="page-dd-item-label"><strong>All Pages</strong></span>
          </div>

          @foreach (array_keys($matrix) as $page)
            <div class="page-dd-item" data-value="{{ $page }}">
              <input type="checkbox" {{ in_array($page, $selPages, true) ? 'checked' : '' }}>
              <span class="page-dd-item-label">{{ $page }}</span>
            </div>
          @endforeach

          <div class="page-dd-empty hidden" id="pageDdEmpty">No matches.</div>
        </div>

        <div class="page-dd-actions">
          <button type="button" class="page-dd-btn-clear" id="pageDdBtnClear" title="Uncheck all selections">Clear</button>
          <button type="button" class="page-dd-btn-apply" id="pageDdBtnApply">Apply selected</button>
        </div>

        <div class="text-[11px] text-gray-500 mt-2">
          Check para mag-add ng page. Pag walang naka-check = All Pages. URL persists via ?pages=.
        </div>
      </div>
    </div>

    <div>
      <label for="startDate" class="block font-semibold mb-1">Start Date:</label>
      <input type="date" id="startDate" class="border px-2 py-1 rounded" value="{{ $start ?? '' }}">
    </div>

    <div>
      <label for="endDate" class="block font-semibold mb-1">End Date:</label>
      <input type="date" id="endDate" class="border px-2 py-1 rounded" value="{{ $end ?? '' }}">
    </div>

    <div>
      <label class="block font-semibold mb-1">Item Name:</label>
      <div class="page-dd" id="itemDd" style="width:220px">
        <button type="button" class="page-dd-btn" id="itemDdBtn" style="width:220px" aria-haspopup="listbox" aria-expanded="false">
          <span id="itemDdLabel">All Items</span>
        </button>
        <div class="page-dd-panel" id="itemDdPanel" style="width:220px">
          <input type="text" class="page-dd-search" id="itemDdSearch" placeholder="Type to filter..." autocomplete="off">
          <div class="page-dd-list" id="itemDdList" role="listbox">
            <div class="page-dd-item selected" data-value="">All Items</div>
          </div>
        </div>
      </div>
      <input type="hidden" id="itemHidden" value="">
    </div>

    {{-- Quick Date Filter Buttons --}}
    <div class="flex items-end gap-2" id="quickDateBtns">
      <button type="button" data-preset="today" onclick="setQuickDate('today')" class="quick-date-btn bg-gray-200 hover:bg-blue-600 hover:text-white text-gray-700 text-sm font-medium px-3 py-1.5 rounded shadow-sm transition">Today</button>
      <button type="button" data-preset="yesterday" onclick="setQuickDate('yesterday')" class="quick-date-btn bg-gray-200 hover:bg-blue-600 hover:text-white text-gray-700 text-sm font-medium px-3 py-1.5 rounded shadow-sm transition">Yesterday</button>
      <button type="button" data-preset="this_week" onclick="setQuickDate('this_week')" class="quick-date-btn bg-gray-200 hover:bg-blue-600 hover:text-white text-gray-700 text-sm font-medium px-3 py-1.5 rounded shadow-sm transition">This Week</button>
      <button type="button" data-preset="last_7_days" onclick="setQuickDate('last_7_days')" class="quick-date-btn bg-gray-200 hover:bg-blue-600 hover:text-white text-gray-700 text-sm font-medium px-3 py-1.5 rounded shadow-sm transition">Last 7 Days</button>
      <button type="button" data-preset="this_month" onclick="setQuickDate('this_month')" class="quick-date-btn bg-gray-200 hover:bg-blue-600 hover:text-white text-gray-700 text-sm font-medium px-3 py-1.5 rounded shadow-sm transition">This Month</button>

      {{-- Separator + link to the snapshot timeline (grid of buckets × dates).
           Snapshots are saved automatically every Copy Table click. --}}
      <span class="text-gray-300 mx-1">|</span>
      <a href="{{ route('ads_manager.cpp.timeline') }}"
         class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium px-3 py-1.5 rounded shadow-sm transition"
         title="View saved snapshots over time (auto-saved on Copy Table click)">
        📈 View Timeline
      </a>
    </div>
  </div>

  {{-- Display Area --}}
  <div id="singlePageLayout" class="hidden lg:flex gap-6 mb-10">
    <div class="basis-full lg:basis-1/3 lg:shrink-0">
      <h2 class="font-bold text-lg mb-2">CPP Chart <span class="text-xs font-normal text-gray-400">(cost / order)</span></h2>
      <canvas id="cppChart" height="100" class="mb-8"></canvas>
      <h2 class="font-bold text-lg mb-2">CPI Chart <span class="text-xs font-normal text-gray-400">(cost / 1k impressions)</span></h2>
      <canvas id="cpiChart" height="100" class="mb-8"></canvas>
      <h2 class="font-bold text-lg mb-2">CPM Chart <span class="text-xs font-normal text-gray-400">(cost / message)</span></h2>
      <canvas id="cpmChart" height="100"></canvas>
    </div>
    <div class="basis-full lg:basis-2/3 lg:shrink-0 min-w-0 overflow-auto" id="rightTableContainer"></div>
  </div>

  <div id="multiPageTables" class="overflow-auto mb-10"></div>

  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels"></script>

  <script>
    // --- Data from server ---
    const rawData   = @json($matrix);
    const allDates  = @json($allDates); // full selected range (start→end)
    const srvStart  = @json($start ?? null);
    const srvEnd    = @json($end ?? null);

    // --- Elements ---
    const startDateInput   = document.getElementById('startDate');
    const endDateInput     = document.getElementById('endDate');
    const cppCanvas        = document.getElementById('cppChart');
    const cpiCanvas        = document.getElementById('cpiChart');
    const cpmCanvas        = document.getElementById('cpmChart');
    const tableRight       = document.getElementById('rightTableContainer');
    const multiPageTables  = document.getElementById('multiPageTables');
    const singlePageLayout = document.getElementById('singlePageLayout');

    // ✅ Dropdown elements
    const pageDd        = document.getElementById('pageDd');
    const pageDdBtn     = document.getElementById('pageDdBtn');
    const pageDdPanel   = document.getElementById('pageDdPanel');
    const pageDdSearch  = document.getElementById('pageDdSearch');
    const pageDdList    = document.getElementById('pageDdList');
    const pageDdEmpty   = document.getElementById('pageDdEmpty');
    const pageHidden    = document.getElementById('pageHidden');
    const pageDdLabel   = document.getElementById('pageDdLabel');

    let cppChart, cpmChart, cpiChart;

    // --- Helpers ---
    // Multi-select: returns ARRAY of page names, or [] meaning "all pages".
    // pageHidden.value format: 'all' OR 'Page1,Page2,Page3' (comma-joined).
    function getSelectedPages() {
      const raw = (pageHidden?.value || 'all').trim();
      if (raw === '' || raw.toLowerCase() === 'all') return [];
      return raw.split(',').map(s => s.trim()).filter(Boolean);
    }
    function getSelectedPagesSet() { return new Set(getSelectedPages()); }
    function isAllPagesMode() { return getSelectedPages().length === 0; }

    // Backwards-compat: returns 'all' or the single page name.
    // Used by parts of legacy code that still expect single-page semantics.
    function getSelectedPage() {
      const sel = getSelectedPages();
      if (sel.length === 0) return 'all';
      if (sel.length === 1) return sel[0];
      return 'all'; // Multiple selected → fallback to all behavior + frontend filter
    }

    function fmtISO(iso) {
      const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || '');
      if (!m) return 'Invalid Date';
      const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
      const y = m[1], mm = +m[2], dd = +m[3];
      return `${months[mm-1]} ${dd}, ${y}`;
    }

    function filterDates() {
      const start = startDateInput.value || srvStart;
      const end   = endDateInput.value   || srvEnd;
      return allDates.filter(d => (!start || d >= start) && (!end || d <= end));
    }

    // ---- helpers para i-filter dates na may spend > 0 ----
    // Multi-select aware: kung walang selected pages → all pages contribute.
    // Kung may filter → only those pages.
    function totalSpendAcross(date, pageSet) {
      let sum = 0;
      Object.entries(rawData).forEach(([page, data]) => {
        if (pageSet && pageSet.size > 0 && !pageSet.has(page)) return;
        const r = data[date] || {};
        if (typeof r.spent === 'number') sum += r.spent;
      });
      return sum;
    }
    function datesWithSpendFiltered(dates, pageSet) {
      return dates.filter(d => totalSpendAcross(d, pageSet) > 0);
    }
    function datesWithSpendForPage(dates, page) {
      const data = rawData[page] || {};
      return dates.filter(d => (data[d]?.spent || 0) > 0);
    }

    // --- Navigate with BOTH start & end (server re-query) ---
    function navigateWithBothDates() {
      const s = startDateInput.value;
      const e = endDateInput.value;
      if (!s || !e) return;

      const url = new URL("{{ route('ads_manager.cpp') }}", window.location.origin);
      url.searchParams.set('start', s);
      url.searchParams.set('end',   e);
      // Multi-select: ?pages=A,B,C (or omit for all)
      const sel = getSelectedPages();
      if (sel.length > 0) {
        url.searchParams.set('pages', sel.join(','));
      }
      window.location.assign(url.toString());
    }

    function getSelectedItemName() {
      return (document.getElementById('itemHidden')?.value || '').trim();
    }

    // Populate item name dropdown from current filtered dates
    function populateItemDropdown(filteredDates) {
      const counts = {};
      Object.values(rawData).forEach(data => {
        filteredDates.forEach(date => {
          ((data[date] || {}).item_names || []).forEach(name => {
            if (name) counts[name] = (counts[name] || 0) + 1;
          });
        });
      });

      const sorted = Object.entries(counts).sort((a, b) => b[1] - a[1]).map(([name]) => name);
      const list = document.getElementById('itemDdList');
      const current = getSelectedItemName();

      // Keep "All Items" first
      list.innerHTML = `<div class="page-dd-item ${current === '' ? 'selected' : ''}" data-value="">All Items</div>`;
      sorted.forEach(name => {
        list.innerHTML += `<div class="page-dd-item ${current === name ? 'selected' : ''}" data-value="${name.replace(/"/g, '&quot;')}">${name}</div>`;
      });

      // Update label if current selection no longer exists
      if (current !== '' && !sorted.includes(current)) {
        document.getElementById('itemHidden').value = '';
        document.getElementById('itemDdLabel').textContent = 'All Items';
      }
    }

    // --- Render tables ---
    // selectedPages: [] = all, [one] = single-page layout, [many] = multi w/ TOTAL.
    function renderTables(filteredDates, selectedPages) {
      const itemFilter = getSelectedItemName(); // '' = all
      const titleStart = filteredDates[0];
      const titleEnd   = filteredDates[filteredDates.length - 1];
      const pageSet    = new Set(selectedPages);
      const isFiltered = selectedPages.length > 0;
      const isSinglePage = selectedPages.length === 1;

      // SINGLE-PAGE layout when exactly one page is selected (legacy behavior).
      if (isSinglePage) {
        renderSinglePage(filteredDates, selectedPages[0]);
        return;
      }

      // MULTI-PAGE layout (all-pages or 2+ selected). Same chrome as legacy
      // 'all' branch; just filtered when pageSet non-empty. Keep #singlePageLayout
      // visible (lg:flex) so the chart panel sa kaliwa remains rendered.
      {
        singlePageLayout.classList.add('hidden');
        multiPageTables.classList.remove('hidden');
        // Reset any inline display set elsewhere — let CSS classes control it.
        singlePageLayout.style.display = '';
        multiPageTables.style.display  = '';

        const itemLabel = itemFilter ? ` · ${itemFilter}` : '';
        const pageLabel = isFiltered ? ` · ${selectedPages.length} page${selectedPages.length>1?'s':''}` : '';
        const title = (titleStart !== titleEnd)
          ? `SUMMARY OF ADS - ${fmtISO(titleStart)} to ${fmtISO(titleEnd)}${itemLabel}${pageLabel}`
          : `SUMMARY OF ADS - ${fmtISO(titleStart)}${itemLabel}${pageLabel}`;

        // 1) Summary by Page — EXCLUDE pages with total spend == 0
        let summaryHtml = `
          <div class="flex justify-between items-center mb-2">
            <h2 class="font-bold text-lg">${title}</h2>
            <button onclick="copySummaryOfAds()" class="bg-blue-500 text-white px-3 py-1 rounded text-sm hover:bg-blue-600">Copy Table</button>
          </div>
          <table id="summaryOfAdsTable" class="min-w-full border text-sm mb-6">
            <thead class="bg-gray-200">
              <tr>
                <th class="border px-2 py-1">Page Name</th>
                <th class="border px-2 py-1">Amount Spent</th>
                <th class="border px-2 py-1">Orders</th>
                <th class="border px-2 py-1">CPP</th>
                <th class="border px-2 py-1">CPI</th>
                <th class="border px-2 py-1">CPM</th>
                <th class="border px-2 py-1">TCPR</th>
                <th class="border px-2 py-1">Item Names</th>
              </tr>
            </thead>
            <tbody>
        `;

        // Running totals across all rendered rows (for the TOTAL footer when
        // multi-select is active). Same numerator/denominator structure as the
        // per-row math so the TOTAL CPP/CPI/CPM/TCPR are correct.
        let totalSpent = 0, totalOrders = 0, totalWImps = 0, totalWCPI = 0, totalTcprFail = 0;
        let pagesShown = 0;
        const totalItemArrays = [];

        Object.entries(rawData).forEach(([page, data]) => {
          // Multi-select filter: only render pages in the selected set.
          if (isFiltered && !pageSet.has(page)) return;

          // If item filter active, skip pages that never have that item in filtered dates
          if (itemFilter !== '') {
            const hasItem = filteredDates.some(d => ((data[d] || {}).item_names || []).includes(itemFilter));
            if (!hasItem) return;
          }

          let sumSpent=0, sumOrders=0, wImps=0, wCPI=0, tcprFail=0;
          filteredDates.forEach(date => {
            const r = data[date] || {};
            if (typeof r.spent === 'number') sumSpent += r.spent;
            if (r.spent && r.orders) sumOrders += r.orders;
            if (r.spent && r.cpm) wImps += r.spent / r.cpm;
            if (r.spent && r.cpi) wCPI  += r.spent / r.cpi;
            if (r.spent && r.tcpr_fail) tcprFail += r.tcpr_fail;
          });

          if (sumSpent > 0) {
            const cpp  = sumOrders > 0 ? sumSpent / sumOrders : null;
            const cpi  = wCPI  > 0 ? sumSpent / wCPI  : null;
            const cpm  = wImps > 0 ? sumSpent / wImps : null;
            const tcpr = sumOrders > 0 ? (tcprFail / sumOrders) : null;

            // Item names: collect across all dates for this page, sort by frequency
            const pageItemArrays = filteredDates.map(d => ((data[d] || {}).item_names || []));
            const pageItems = prioritizedItems(pageItemArrays);
            const pageItemContent = pageItems.join('\n') || '—';

            summaryHtml += `
              <tr>
                <td class="border px-2 py-1">${page}</td>
                <td class="border px-2 py-1">₱${sumSpent.toFixed(2)}</td>
                <td class="border px-2 py-1">${sumOrders}</td>
                <td class="border px-2 py-1">${cpp != null ? `₱${cpp.toFixed(2)}` : '—'}</td>
                <td class="border px-2 py-1">${cpi != null ? `₱${cpi.toFixed(2)}` : '—'}</td>
                <td class="border px-2 py-1">${cpm != null ? `₱${cpm.toFixed(2)}` : '—'}</td>
                <td class="border px-2 py-1">${tcpr != null ? tcprBadge(tcpr * 100) : '—'}</td>
                <td class="border px-2 py-1 whitespace-pre-line">${pageItemContent}</td>
              </tr>
            `;

            totalSpent    += sumSpent;
            totalOrders   += sumOrders;
            totalWImps    += wImps;
            totalWCPI     += wCPI;
            totalTcprFail += tcprFail;
            pagesShown++;
            totalItemArrays.push(...pageItemArrays);
          }
        });

        // TOTAL footer row — only shown when multi-select is active (>= 2 pages).
        // For all-pages mode + single-page mode we keep the legacy layout (no total).
        if (isFiltered && pagesShown >= 2) {
          const tCpp  = totalOrders > 0 ? totalSpent / totalOrders : null;
          const tCpi  = totalWCPI  > 0  ? totalSpent / totalWCPI   : null;
          const tCpm  = totalWImps > 0  ? totalSpent / totalWImps  : null;
          const tTcpr = totalOrders > 0 ? (totalTcprFail / totalOrders) : null;
          const totalItems = prioritizedItems(totalItemArrays).slice(0, 8).join('\n') || '—';
          summaryHtml += `
            <tr class="bg-blue-50 font-bold border-t-2 border-blue-300">
              <td class="border px-2 py-1">TOTAL (${pagesShown} pages)</td>
              <td class="border px-2 py-1">₱${totalSpent.toFixed(2)}</td>
              <td class="border px-2 py-1">${totalOrders}</td>
              <td class="border px-2 py-1">${tCpp != null ? `₱${tCpp.toFixed(2)}` : '—'}</td>
              <td class="border px-2 py-1">${tCpi != null ? `₱${tCpi.toFixed(2)}` : '—'}</td>
              <td class="border px-2 py-1">${tCpm != null ? `₱${tCpm.toFixed(2)}` : '—'}</td>
              <td class="border px-2 py-1">${tTcpr != null ? tcprBadge(tTcpr * 100) : '—'}</td>
              <td class="border px-2 py-1 whitespace-pre-line">${totalItems}</td>
            </tr>
          `;
        }

        summaryHtml += `</tbody></table>`;

        // 2) Performance by Date (all pages)
        let dateHtml = `
          <h2 class="font-bold text-lg mb-2">All Pages – Performance by Date</h2>
          <table class="w-full table-auto border text-sm">
            <thead class="bg-gray-200">
              <tr>
                <th class="border px-2 py-1">Date</th>
                <th class="border px-2 py-1">Amount Spent</th>
                <th class="border px-2 py-1">Orders</th>
                <th class="border px-2 py-1">CPP</th>
                <th class="border px-2 py-1">CPI</th>
                <th class="border px-2 py-1">CPM</th>
                <th class="border px-2 py-1">TCPR</th>
              </tr>
            </thead>
            <tbody>
        `;

        filteredDates.forEach(date => {
          // If item filter active, skip dates where no page has that item (within selected pages)
          if (itemFilter !== '') {
            const anyHas = Object.entries(rawData).some(([page, data]) => {
              if (isFiltered && !pageSet.has(page)) return false;
              return ((data[date] || {}).item_names || []).includes(itemFilter);
            });
            if (!anyHas) return;
          }

          let sumSpent=0, sumOrders=0, wImps=0, wCPI=0, tcprFail=0;

          Object.entries(rawData).forEach(([page, data]) => {
            // Multi-select filter
            if (isFiltered && !pageSet.has(page)) return;
            // If item filter active, only sum pages that have the item on this date
            if (itemFilter !== '' && !((data[date] || {}).item_names || []).includes(itemFilter)) return;
            const r = data[date] || {};
            if (typeof r.spent === 'number') sumSpent += r.spent;
            if (r.spent && r.orders) sumOrders += r.orders;
            if (r.spent && r.cpm)   wImps += r.spent / r.cpm;
            if (r.spent && r.cpi)   wCPI  += r.spent / r.cpi;
            if (r.spent && r.tcpr_fail) tcprFail += r.tcpr_fail;
          });

          if (sumSpent <= 0) return;

          const cpp  = sumOrders > 0 ? sumSpent / sumOrders : null;
          const cpi  = wCPI  > 0 ? sumSpent / wCPI  : null;
          const cpm  = wImps > 0 ? sumSpent / wImps : null;
          const tcpr = sumOrders > 0 ? (tcprFail / sumOrders) : null;

          dateHtml += `
            <tr>
              <td class="border px-2 py-1 text-center">${fmtISO(date)}</td>
              <td class="border px-2 py-1 text-center">₱${sumSpent.toFixed(2)}</td>
              <td class="border px-2 py-1 text-center">${sumOrders}</td>
              <td class="border px-2 py-1 text-center">${cpp != null ? `₱${cpp.toFixed(2)}` : '—'}</td>
              <td class="border px-2 py-1 text-center">${cpi != null ? `₱${cpi.toFixed(2)}` : '—'}</td>
              <td class="border px-2 py-1 text-center">${cpm != null ? `₱${cpm.toFixed(2)}` : '—'}</td>
              <td class="border px-2 py-1 text-center">${tcpr != null ? tcprBadge(tcpr * 100) : '—'}</td>
            </tr>
          `;
        });

        dateHtml += `</tbody></table>`;

        // Legacy routing: content goes to #rightTableContainer (sa loob ng
        // #singlePageLayout). Charts stay sa kaliwa. #multiPageTables is
        // toggled-visible but kept empty (matches original behavior).
        tableRight.innerHTML = summaryHtml + dateHtml;
        multiPageTables.innerHTML = '';
      }
    }

    // Single-page layout (1 page selected) — legacy behavior.
    function renderSinglePage(filteredDates, pageFilter) {
      const titleStart = filteredDates[0];
      const titleEnd   = filteredDates[filteredDates.length - 1];
      multiPageTables.classList.add('hidden');
      singlePageLayout.classList.remove('hidden');

      const data = rawData[pageFilter] || {};

      let html = `
          <div class="flex justify-between items-center mb-2">
            <h2 class="font-bold text-lg">${pageFilter} – Performance by Date (${fmtISO(titleStart)} to ${fmtISO(titleEnd)})</h2>
            <button onclick="copySinglePageTable()" class="bg-blue-500 text-white px-3 py-1 rounded text-sm hover:bg-blue-600">Copy Table</button>
          </div>

          <table id="singlePageTable" class="w-full border text-sm mb-6">
            <thead class="bg-gray-200">
              <tr>
                <th class="border px-2 py-1">Date</th>
                <th class="border px-2 py-1">Amount Spent</th>
                <th class="border px-2 py-1">Orders</th>
                <th class="border px-2 py-1">CPP</th>
                <th class="border px-2 py-1">CPM</th>
                <th class="border px-2 py-1">TCPR</th>
                <th class="border px-2 py-1">Item Names</th>
                <th class="border px-2 py-1">CODs</th>
                <th class="border px-2 py-1">PROCEED</th>
              </tr>
            </thead>
            <tbody>
        `;

        filteredDates.forEach(date => {
          const r = data[date] || {};
          if (!r.spent || r.spent <= 0) return;

          const itemNames = (r.item_names || []);
          const itemContent = itemNames.length <= 1 ? itemNames.join('') : itemNames.join('\n');
          const cods = (r.cods || []).join(', ');

          const orders = Number(r.orders || 0);
          const fail   = Number(r.tcpr_fail || 0);
          const tcpr   = orders > 0 ? (fail / orders) : null;

          html += `
            <tr>
              <td class="border px-2 py-1 text-center">${fmtISO(date)}</td>
              <td class="border px-2 py-1 text-center">₱${r.spent.toFixed(2)}</td>
              <td class="border px-2 py-1 text-center">${r.orders ?? '—'}</td>
              <td class="border px-2 py-1 text-center">${r.cpp != null ? `₱${r.cpp.toFixed(2)}` : '—'}</td>
              <td class="border px-2 py-1 text-center">${r.cpm != null ? `₱${r.cpm.toFixed(2)}` : '—'}</td>
              <td class="border px-2 py-1 text-center">${tcpr != null ? tcprBadge(tcpr * 100) : '—'}</td>
              <td class="border px-2 py-1 text-left whitespace-pre-line">${itemContent || '—'}</td>
              <td class="border px-2 py-1 text-left whitespace-nowrap overflow-hidden text-ellipsis max-w-[300px]" title="${cods}">${cods || '—'}</td>
              <td class="border px-2 py-1 text-center">${r.proceed ?? '—'}</td>
            </tr>
          `;
        });

        // Totals (include TCPR)
        let totalSpent = 0, totalOrders = 0, sumWeighted = 0, totalFail = 0;

        filteredDates.forEach(date => {
          const r = data[date] || {};
          if (r.spent && r.spent > 0) {
            totalSpent  += r.spent;
            if (r.orders) totalOrders += r.orders;
            if (r.cpm) sumWeighted += r.spent / r.cpm;
            totalFail += Number(r.tcpr_fail || 0);
          }
        });

        const totalCPP  = totalOrders > 0 ? totalSpent / totalOrders : null;
        const totalCPM  = sumWeighted > 0  ? totalSpent / sumWeighted : null;
        const totalTCPR = totalOrders > 0 ? (totalFail / totalOrders) : null;

        html += `
          <tr class="bg-gray-100 font-bold">
            <td class="border px-2 py-1 text-center">TOTAL</td>
            <td class="border px-2 py-1 text-center">₱${totalSpent.toFixed(2)}</td>
            <td class="border px-2 py-1 text-center">${totalOrders}</td>
            <td class="border px-2 py-1 text-center">${totalCPP != null ? `₱${totalCPP.toFixed(2)}` : '—'}</td>
            <td class="border px-2 py-1 text-center">${totalCPM != null ? `₱${totalCPM.toFixed(2)}` : '—'}</td>
            <td class="border px-2 py-1 text-center">${totalTCPR != null ? tcprBadge(totalTCPR * 100) : '—'}</td>
            <td class="border px-2 py-1" colspan="3"></td>
          </tr>
        `;

        html += `</tbody></table>`;
        tableRight.innerHTML = html;
    }

    // Charts
    // Prominent value label: white badge w/ thick colored border above each point.
    function valueLabel(color, decimals, prefix = '₱') {
      return {
        display: true,
        anchor: 'end',
        align: 'top',
        offset: 6,
        clamp: true,                 // keep labels inside the canvas
        color: '#0f172a',
        backgroundColor: 'rgba(255,255,255,0.95)',
        borderColor: color,
        borderWidth: 2,
        borderRadius: 6,
        padding: { top: 3, bottom: 3, left: 6, right: 6 },
        font: { weight: 'bold', size: 13 },
        formatter: v => (v || v === 0) ? `${prefix}${Number(v).toFixed(decimals)}` : ''
      };
    }
    // Shared dataset styling so points/line are clearly visible.
    function lineDataset(label, data, color) {
      return {
        label, data, tension: 0.3, spanGaps: true,
        borderColor: color, backgroundColor: color,
        borderWidth: 2, pointRadius: 4, pointHoverRadius: 6, pointBackgroundColor: color
      };
    }
    function baseOpts(color, decimals, axisText, prefix = '₱') {
      return {
        responsive: true,
        layout: { padding: { top: 24, right: 12, left: 4 } }, // headroom for top labels
        plugins: { legend: { display: false }, datalabels: valueLabel(color, decimals, prefix) },
        scales: { y: { beginAtZero: true, title: { display: true, text: axisText }, grace: '12%' } }
      };
    }

    function renderCPPChart(filteredDates, cppData) {
      if (cppChart) cppChart.destroy();
      cppChart = new Chart(cppCanvas.getContext('2d'), {
        type: 'line',
        data: { labels: filteredDates, datasets: [lineDataset('CPP', cppData, '#2563eb')] },
        options: baseOpts('#2563eb', 0, 'CPP'),
        plugins: [ChartDataLabels]
      });
    }

    function renderCPMChart(filteredDates, cpmData) {
      if (cpmChart) cpmChart.destroy();
      cpmChart = new Chart(cpmCanvas.getContext('2d'), {
        type: 'line',
        data: { labels: filteredDates, datasets: [lineDataset('CPM', cpmData, '#d97706')] },
        options: baseOpts('#d97706', 0, 'CPM', ''),
        plugins: [ChartDataLabels]
      });
    }

    function renderCPIChart(filteredDates, cpiData) {
      if (cpiChart) cpiChart.destroy();
      cpiChart = new Chart(cpiCanvas.getContext('2d'), {
        type: 'line',
        data: { labels: filteredDates, datasets: [lineDataset('CPI', cpiData, '#16a34a')] },
        options: baseOpts('#16a34a', 0, 'CPI', ''),
        plugins: [ChartDataLabels]
      });
    }

    function refreshAll() {
      const selectedPages = getSelectedPages(); // [] = all
      const pageSet = new Set(selectedPages);
      const baseDates = filterDates();

      // Date filtering respects selected pages (only show dates where at least
      // one selected page has spend > 0). Single-page mode uses legacy helper
      // for backwards-compat (no behavior change).
      const dates = (selectedPages.length === 1)
        ? datesWithSpendForPage(baseDates, selectedPages[0])
        : datesWithSpendFiltered(baseDates, pageSet);

      // Repopulate item dropdown based on current date range
      populateItemDropdown(dates);

      if (!dates.length) {
        if (cppChart) cppChart.destroy();
        if (cpmChart) cpmChart.destroy();
        if (cpiChart) cpiChart.destroy();
        singlePageLayout.classList.add('hidden');
        multiPageTables.classList.remove('hidden');
        tableRight.innerHTML = `
          <div class="p-4 border rounded bg-yellow-50 text-yellow-800">
            No data with ad spend &gt; 0 for the selected dates / pages.
          </div>`;
        return;
      }

      // Charts: SPEND-WEIGHTED blend across selected pages — same numerator/
      // denominator as the table TOTAL row, so graph === table.
      //   CPP = Σspent / Σorders          (cost per order)
      //   CPI = Σspent / Σ(spent/cpi)     (cost per 1k impressions)
      //   CPM = Σspent / Σ(spent/cpm)     (cost per message)
      // (spent/cpi = impressions/1000 ; spent/cpm = messages — exact inverses.)
      let cppData = [], cpmData = [], cpiData = [];
      if (selectedPages.length === 1) {
        const sel = rawData[selectedPages[0]] || {};
        cppData = dates.map(d => sel[d]?.cpp ?? null);
        cpiData = dates.map(d => sel[d]?.cpi ?? null);
        cpmData = dates.map(d => sel[d]?.cpm ?? null);
      } else {
        dates.forEach(date => {
          let sumSpent = 0, sumOrders = 0, wMsg = 0, wImpK = 0;
          Object.entries(rawData).forEach(([page, d]) => {
            if (pageSet.size > 0 && !pageSet.has(page)) return;
            const r = d[date];
            if (!r || !r.spent) return;
            sumSpent  += r.spent;
            if (r.orders) sumOrders += r.orders;
            if (r.cpm)    wMsg      += r.spent / r.cpm;   // messages
            if (r.cpi)    wImpK     += r.spent / r.cpi;   // impressions / 1000
          });
          cppData.push(sumOrders > 0 ? sumSpent / sumOrders : null);
          cpiData.push(wImpK     > 0 ? sumSpent / wImpK     : null);
          cpmData.push(wMsg      > 0 ? sumSpent / wMsg      : null);
        });
      }

      renderCPPChart(dates, cppData);
      renderCPIChart(dates, cpiData);
      renderCPMChart(dates, cpmData);
      renderTables(dates, selectedPages);
    }

    function copySummaryOfAds() {
      const table = document.getElementById('summaryOfAdsTable');
      if (!table) return;

      const rows = Array.from(table.querySelectorAll('tr'));
      const copiedText = rows.map(row => {
        return Array.from(row.querySelectorAll('th, td'))
          .map(cell => cell.textContent.replace(/₱/g, '').replace(/\n+/g, ', ').trim())
          .join('\t');
      }).join('\n');

      navigator.clipboard.writeText(copiedText)
        .then(() => {
          // Also save snapshot to cpp_snapshots — backend re-queries today's
          // /cpp data and saves per-page rows tagged with the current PH-time
          // bucket (10AM / 3PM / 7PM). Latest-wins per (date, bucket, page).
          saveCppSnapshot();
        })
        .catch(err => console.error('Copy failed:', err));
    }

    // POST to /ads_manager/cpp/snapshot — auto-save snapshot of today's /cpp
    // matrix. Called after a successful clipboard write sa Copy Table button.
    // Shows a small toast so user knows na nai-save (or na may error).
    function saveCppSnapshot() {
      const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
      fetch('{{ route('ads_manager.cpp.snapshot') }}', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept':       'application/json',
          'X-CSRF-TOKEN': csrf,
        },
        body: '{}',
      })
      .then(r => r.json())
      .then(j => {
        if (j && j.ok) {
          showCppToast(`📸 Snapshot saved (${j.snapshot_bucket}) · ${j.pages_saved} pages`, 'ok');
        } else {
          showCppToast('⚠ Snapshot save failed', 'err');
        }
      })
      .catch(e => {
        console.error('Snapshot save error:', e);
        showCppToast('⚠ Snapshot save failed', 'err');
      });
    }

    // Minimal toast helper — fades out after ~2.5s sa bottom-right corner.
    function showCppToast(msg, kind) {
      let el = document.getElementById('cpp-snapshot-toast');
      if (!el) {
        el = document.createElement('div');
        el.id = 'cpp-snapshot-toast';
        el.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:9999;'
          + 'padding:10px 14px;border-radius:8px;font-size:13px;font-weight:500;'
          + 'box-shadow:0 4px 16px rgba(0,0,0,0.18);transition:opacity .25s;'
          + 'pointer-events:none;';
        document.body.appendChild(el);
      }
      const bg = kind === 'err' ? '#fee2e2' : '#dcfce7';
      const fg = kind === 'err' ? '#991b1b' : '#166534';
      el.style.background = bg;
      el.style.color = fg;
      el.style.opacity = '1';
      el.textContent = msg;
      clearTimeout(el._hideTimer);
      el._hideTimer = setTimeout(() => { el.style.opacity = '0'; }, 2500);
    }

    function copySinglePageTable() {
      const table = document.getElementById('singlePageTable');
      if (!table) return;

      const rows = Array.from(table.querySelectorAll('tr'));
      const copiedText = rows.map(row => {
        return Array.from(row.querySelectorAll('th, td'))
          .map(cell => cell.textContent.replace(/₱/g, '').replace(/\n+/g, ', ').trim())
          .join('\t');
      }).join('\n');

      navigator.clipboard.writeText(copiedText)
        .then(() => alert('Single Page table copied!'))
        .catch(err => console.error('Copy failed:', err));
    }

    // ✅ Multi-select dropdown: checkbox-based, Apply / Clear buttons.
    // "All Pages" checkbox is a synthetic toggle: checking it unchecks all
    // page items (no filter). Checking any page unchecks "All Pages".
    (function initPageDropdown(){
      if (!pageDd) return;
      const cbAll      = document.getElementById('page-cb-all');
      const btnApply   = document.getElementById('pageDdBtnApply');
      const btnClear   = document.getElementById('pageDdBtnClear');
      const countLabel = document.getElementById('pageDdCount');

      function norm(s) {
        return (s || '')
          .toString()
          .toLowerCase()
          .normalize('NFD')
          .replace(/[\u0300-\u036f]/g, '')
          .trim();
      }

      function open() {
        pageDd.classList.add('open');
        pageDdBtn.setAttribute('aria-expanded', 'true');
        pageDdSearch.value = '';
        filter('');
        updateCountLabel();
        setTimeout(() => pageDdSearch.focus(), 0);
      }
      function close() {
        pageDd.classList.remove('open');
        pageDdBtn.setAttribute('aria-expanded', 'false');
      }
      function filter(q) {
        const query = norm(q);
        let shown = 0;
        const items = Array.from(pageDdList.querySelectorAll('.page-dd-item'));
        items.forEach(item => {
          // Never hide "All Pages" via search (it's a control row).
          if (item.dataset.value === 'all') { item.style.display = 'flex'; return; }
          const text = norm(item.querySelector('.page-dd-item-label')?.textContent || '');
          const isMatch = query === '' ? true : text.includes(query);
          item.style.display = isMatch ? 'flex' : 'none';
          if (isMatch) shown++;
        });
        pageDdEmpty?.classList.toggle('hidden', shown > 0);
      }

      // Currently checked PAGE items (excludes "All Pages" toggle).
      function checkedPages() {
        return Array.from(pageDdList.querySelectorAll('.page-dd-item'))
          .filter(i => i.dataset.value !== 'all')
          .filter(i => i.querySelector('input[type="checkbox"]')?.checked)
          .map(i => i.dataset.value);
      }

      function updateCountLabel() {
        const sel = checkedPages();
        if (sel.length === 0)      countLabel.textContent = 'All pages will be shown (no filter active)';
        else if (sel.length === 1) countLabel.textContent = `1 page selected · ${sel[0]}`;
        else                       countLabel.textContent = `${sel.length} pages selected`;
      }

      function applyAndClose() {
        const sel = checkedPages();
        const newVal = sel.length === 0 ? 'all' : sel.join(',');
        const currentVal = pageHidden.value;
        pageHidden.value = newVal;
        pageDdLabel.textContent = sel.length === 0
          ? 'All Pages'
          : (sel.length === 1 ? sel[0] : `${sel.length} pages selected`);
        close();
        // Persist via URL (?pages=A,B,C) → full reload so the link is shareable.
        if (newVal !== currentVal) navigateWithBothDates();
      }

      pageDdBtn.addEventListener('click', (e) => {
        e.preventDefault();
        if (pageDd.classList.contains('open')) close(); else open();
      });
      pageDdSearch.addEventListener('input', () => filter(pageDdSearch.value));

      // Clicking anywhere in an item toggles its checkbox.
      pageDdList.addEventListener('click', (e) => {
        const item = e.target.closest('.page-dd-item');
        if (!item) return;
        const cb = item.querySelector('input[type="checkbox"]');
        if (!cb) return;
        if (e.target !== cb) cb.checked = !cb.checked;

        if (item.dataset.value === 'all') {
          if (cb.checked) {
            pageDdList.querySelectorAll('.page-dd-item input[type="checkbox"]').forEach(c => {
              if (c !== cb) c.checked = false;
            });
          }
        } else {
          if (cb.checked && cbAll) cbAll.checked = false;
          if (!cb.checked && checkedPages().length === 0 && cbAll) cbAll.checked = true;
        }
        updateCountLabel();
      });

      btnApply?.addEventListener('click', applyAndClose);
      btnClear?.addEventListener('click', () => {
        pageDdList.querySelectorAll('.page-dd-item input[type="checkbox"]').forEach(c => c.checked = false);
        if (cbAll) cbAll.checked = true;
        updateCountLabel();
      });

      // close on outside click
      document.addEventListener('click', (e) => {
        if (!pageDd.classList.contains('open')) return;
        if (pageDd.contains(e.target)) return;
        close();
      });
      // close on ESC
      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && pageDd.classList.contains('open')) close();
      });

      updateCountLabel();
    })();

    // ✅ Item Name Dropdown behavior
    (function initItemDropdown(){
      const itemDd     = document.getElementById('itemDd');
      const itemDdBtn  = document.getElementById('itemDdBtn');
      const itemDdPanel= document.getElementById('itemDdPanel');
      const itemDdSearch= document.getElementById('itemDdSearch');
      const itemDdList = document.getElementById('itemDdList');
      const itemHidden = document.getElementById('itemHidden');
      const itemDdLabel= document.getElementById('itemDdLabel');
      if (!itemDd) return;

      function norm(s) {
        return (s||'').toString().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'').trim();
      }
      function open() {
        itemDd.classList.add('open');
        itemDdBtn.setAttribute('aria-expanded','true');
        itemDdSearch.value = '';
        filter('');
        setTimeout(() => itemDdSearch.focus(), 0);
      }
      function close() {
        itemDd.classList.remove('open');
        itemDdBtn.setAttribute('aria-expanded','false');
      }
      function filter(q) {
        const query = norm(q);
        let shown = 0;
        Array.from(itemDdList.querySelectorAll('.page-dd-item')).forEach(item => {
          const match = query === '' || norm(item.textContent).includes(query);
          item.style.display = match ? 'block' : 'none';
          if (match) shown++;
        });
      }
      function setSelected(val, labelText) {
        itemHidden.value = val;
        itemDdLabel.textContent = val === '' ? 'All Items' : labelText;
        itemDdList.querySelectorAll('.page-dd-item').forEach(i => i.classList.remove('selected'));
        const el = Array.from(itemDdList.querySelectorAll('.page-dd-item')).find(i => i.dataset.value === val);
        if (el) el.classList.add('selected');
        close();
        refreshAll();
      }
      itemDdBtn.addEventListener('click', e => {
        e.preventDefault();
        itemDd.classList.contains('open') ? close() : open();
      });
      itemDdSearch.addEventListener('input', () => filter(itemDdSearch.value));
      itemDdList.addEventListener('click', e => {
        const item = e.target.closest('.page-dd-item');
        if (!item) return;
        setSelected(item.dataset.value ?? '', item.textContent.trim());
      });
      document.addEventListener('click', e => {
        if (!itemDd.classList.contains('open')) return;
        if (itemDd.contains(e.target)) return;
        close();
      });
      document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && itemDd.classList.contains('open')) close();
      });
    })();

    // --- Quick Date Filter ---
    function setQuickDate(preset) {
      const today = new Date();
      let start, end;

      switch (preset) {
        case 'today':
          start = end = formatDate(today);
          break;
        case 'yesterday':
          const yesterday = new Date(today);
          yesterday.setDate(today.getDate() - 1);
          start = end = formatDate(yesterday);
          break;
        case 'this_week':
          const dayOfWeek = today.getDay();
          const monday = new Date(today);
          monday.setDate(today.getDate() - (dayOfWeek === 0 ? 6 : dayOfWeek - 1));
          start = formatDate(monday);
          end = formatDate(today);
          break;
        case 'last_7_days':
          const sevenDaysAgo = new Date(today);
          sevenDaysAgo.setDate(today.getDate() - 6);
          start = formatDate(sevenDaysAgo);
          end = formatDate(today);
          break;
        case 'this_month':
          const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
          start = formatDate(firstDay);
          end = formatDate(today);
          break;
      }

      startDateInput.value = start;
      endDateInput.value = end;
      navigateWithBothDates();
    }

    function formatDate(date) {
      const y = date.getFullYear();
      const m = String(date.getMonth() + 1).padStart(2, '0');
      const d = String(date.getDate()).padStart(2, '0');
      return `${y}-${m}-${d}`;
    }

    // Events
    startDateInput.addEventListener('change', navigateWithBothDates);
    endDateInput.addEventListener('change',   navigateWithBothDates);

    // --- Highlight active quick date button ---
    function highlightActivePreset() {
      const s = startDateInput.value;
      const e = endDateInput.value;
      const today = new Date();
      const todayStr = formatDate(today);

      const yesterday = new Date(today);
      yesterday.setDate(today.getDate() - 1);
      const yesterdayStr = formatDate(yesterday);

      const dayOfWeek = today.getDay();
      const monday = new Date(today);
      monday.setDate(today.getDate() - (dayOfWeek === 0 ? 6 : dayOfWeek - 1));
      const mondayStr = formatDate(monday);

      const sevenDaysAgo = new Date(today);
      sevenDaysAgo.setDate(today.getDate() - 6);
      const sevenDaysAgoStr = formatDate(sevenDaysAgo);

      const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
      const firstDayStr = formatDate(firstDay);

      let activePreset = null;
      if (s === todayStr && e === todayStr) activePreset = 'today';
      else if (s === yesterdayStr && e === yesterdayStr) activePreset = 'yesterday';
      else if (s === mondayStr && e === todayStr) activePreset = 'this_week';
      else if (s === sevenDaysAgoStr && e === todayStr) activePreset = 'last_7_days';
      else if (s === firstDayStr && e === todayStr) activePreset = 'this_month';

      document.querySelectorAll('#quickDateBtns .quick-date-btn').forEach(btn => {
        if (btn.dataset.preset === activePreset) {
          btn.classList.remove('bg-gray-200', 'text-gray-700');
          btn.classList.add('bg-blue-600', 'text-white', 'ring-2', 'ring-blue-300');
        } else {
          btn.classList.remove('bg-blue-600', 'text-white', 'ring-2', 'ring-blue-300');
          btn.classList.add('bg-gray-200', 'text-gray-700');
        }
      });
    }

    // Init
    window.onload = () => {
      if ((!startDateInput.value || !endDateInput.value) && allDates.length) {
        startDateInput.value = allDates[0];
        endDateInput.value   = allDates[allDates.length - 1];
      }
      highlightActivePreset();
      refreshAll();
    };

    // Returns item names sorted by frequency (most occurrences first)
    // nameArrays: array of string[] (one array per date/page)
    function prioritizedItems(nameArrays) {
      const counts = {};
      nameArrays.forEach(arr => {
        (arr || []).forEach(name => {
          if (!name) return;
          counts[name] = (counts[name] || 0) + 1;
        });
      });
      return Object.entries(counts)
        .sort((a, b) => b[1] - a[1])
        .map(([name]) => name);
    }

    function tcprBadge(pct) {
      if (pct == null || isNaN(pct)) return '—';

      const base = 'inline-block min-w-[64px] text-center px-2 py-0.5 rounded-md font-semibold shadow-sm';

      if (pct > 7) {
        return `<span class="${base} bg-red-600 text-white">${pct.toFixed(2)}%</span>`;
      } else if (pct > 5) {
        return `<span class="${base} bg-orange-500 text-white">${pct.toFixed(2)}%</span>`;
      } else if (pct > 3) {
        return `<span class="${base} bg-yellow-400 text-slate-900">${pct.toFixed(2)}%</span>`;
      }

      return `${pct.toFixed(2)}%`;
    }
  </script>
</x-layout>

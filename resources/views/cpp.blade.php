<x-layout>
  <x-slot name="heading">CPP Summary</x-slot>

  {{-- Filters (no auto-submit; we always navigate with BOTH start & end) --}}
  <div class="mt-4 mb-6 flex flex-wrap items-end gap-4">
    <div>
      <label for="pageSelect" class="block font-semibold mb-1">Select Page:</label>
      <select id="pageSelect" class="border px-2 py-1 rounded">
        <option value="all">All Pages</option>
        @foreach (array_keys($matrix) as $page)
          <option value="{{ $page }}">{{ $page }}</option>
        @endforeach
      </select>
    </div>

    <div>
      <label for="startDate" class="block font-semibold mb-1">Start Date:</label>
      <input type="date" id="startDate" class="border px-2 py-1 rounded" value="{{ $start }}">
    </div>

    <div>
      <label for="endDate" class="block font-semibold mb-1">End Date:</label>
      <input type="date" id="endDate" class="border px-2 py-1 rounded" value="{{ $end }}">
    </div>
  </div>

  {{-- Single Page Layout --}}
  <div id="singlePageLayout" class="hidden lg:flex gap-6 mb-10">
    <div class="flex-1">
      <h2 class="font-bold text-lg mb-2">CPP Chart</h2>
      <canvas id="cppChart" height="100" class="mb-8"></canvas>
      <h2 class="font-bold text-lg mb-2">CPM Chart</h2>
      <canvas id="cpmChart" height="100"></canvas>
    </div>
    <div class="flex-1 overflow-auto" id="rightTableContainer"></div>
  </div>

  {{-- All Pages Layout --}}
  <div id="allPagesContainer" class="mb-10"></div>

  @once
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels"></script>
  @endonce

  <script>
    // --- Server state ---
    const rawData     = @json($matrix);
    const allDates    = @json($allDates); // full chosen range (start→end)
    const serverStart = @json($start);
    const serverEnd   = @json($end);

    // --- Elements ---
    const pageSelect        = document.getElementById('pageSelect');
    const startDateInput    = document.getElementById('startDate');
    const endDateInput      = document.getElementById('endDate');
    const singlePageLayout  = document.getElementById('singlePageLayout');
    const allPagesContainer = document.getElementById('allPagesContainer');
    const rightTable        = document.getElementById('rightTableContainer');
    const cppCanvas         = document.getElementById('cppChart');
    const cpmCanvas         = document.getElementById('cpmChart');

    let cppChart, cpmChart;

    // Persist selected page via ?ui_page=
    const uiPageParam = new URL(location.href).searchParams.get('ui_page') || 'all';
    pageSelect.value = uiPageParam;

    // --- Date navigation: ALWAYS pass BOTH start & end (fixes the "stuck last 7 days") ---
    function navigateWithBothDates() {
      const s = startDateInput.value;
      const e = endDateInput.value;
      if (!s || !e) return; // wait until both are set

      const url = new URL("{{ route('ads_manager.cpp') }}", window.location.origin);
      url.searchParams.set('start', s);
      url.searchParams.set('end',   e);
      url.searchParams.set('ui_page', pageSelect.value || 'all');
      window.location.assign(url.toString());
    }
    startDateInput.addEventListener('change', navigateWithBothDates);
    endDateInput.addEventListener('change',   navigateWithBothDates);

    // Keep selected page without reloading
    pageSelect.addEventListener('change', () => {
      const url = new URL(location.href);
      url.searchParams.set('ui_page', pageSelect.value || 'all');
      history.replaceState({}, '', url.toString());
      refreshAll();
    });

    // --- Helpers ---
    const monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    function fmtISO(iso) {
      if (!iso || !/^\d{4}-\d{2}-\d{2}$/.test(iso)) return 'Invalid Date';
      const [y,m,d] = iso.split('-');
      return `${monthNames[+m-1]} ${+d}, ${y}`;
    }
    function peso(n){ return '₱' + Number(n || 0).toFixed(2); }

    // --- Renderers ---
    function renderAllPagesTables(dates) {
      singlePageLayout.classList.add('hidden');

      const title = (serverStart && serverEnd && serverStart !== serverEnd)
        ? `SUMMARY OF ADS - ${fmtISO(serverStart)} to ${fmtISO(serverEnd)}`
        : (serverStart ? `SUMMARY OF ADS - ${fmtISO(serverStart)}` : 'SUMMARY OF ADS');

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
            </tr>
          </thead>
          <tbody>
      `;

      Object.entries(rawData).forEach(([page, data]) => {
        let spent=0, orders=0, wImps=0, wCpi=0;
        dates.forEach(d=>{
          const r = data[d] || {};
          if (r.spent)  spent  += r.spent;
          if (r.orders) orders += r.orders;
          if (r.spent && r.cpm) wImps += r.spent / r.cpm;
          if (r.spent && r.cpi) wCpi  += r.spent / r.cpi;
        });
        if (spent>0 || orders>0) {
          const cpp = orders>0 ? spent/orders : null;
          const cpi = wCpi>0 ? spent/wCpi : null;
          const cpm = wImps>0 ? spent/wImps : null;
          summaryHtml += `
            <tr>
              <td class="border px-2 py-1">${page}</td>
              <td class="border px-2 py-1">${spent?peso(spent):'—'}</td>
              <td class="border px-2 py-1">${orders}</td>
              <td class="border px-2 py-1">${cpp!=null?peso(cpp):'—'}</td>
              <td class="border px-2 py-1">${cpi!=null?peso(cpi):'—'}</td>
              <td class="border px-2 py-1">${cpm!=null?peso(cpm):'—'}</td>
            </tr>`;
        }
      });

      summaryHtml += `</tbody></table>`;

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
            </tr>
          </thead>
          <tbody>`;

      dates.forEach(d=>{
        let spent=0, orders=0, wImps=0, wCpi=0;
        Object.values(rawData).forEach(data=>{
          const r=data[d]||{};
          if (r.spent)  spent  += r.spent;
          if (r.orders) orders += r.orders;
          if (r.spent && r.cpm) wImps += r.spent / r.cpm;
          if (r.spent && r.cpi) wCpi  += r.spent / r.cpi;
        });
        const cpp = orders>0 ? spent/orders : null;
        const cpi = wCpi>0 ? spent/wCpi : null;
        const cpm = wImps>0 ? spent/wImps : null;

        dateHtml += `
          <tr>
            <td class="border px-2 py-1 text-center">${d}</td>
            <td class="border px-2 py-1 text-center">${spent?peso(spent):'—'}</td>
            <td class="border px-2 py-1 text-center">${orders}</td>
            <td class="border px-2 py-1 text-center">${cpp!=null?peso(cpp):'—'}</td>
            <td class="border px-2 py-1 text-center">${cpi!=null?peso(cpi):'—'}</td>
            <td class="border px-2 py-1 text-center">${cpm!=null?peso(cpm):'—'}</td>
          </tr>`;
      });

      dateHtml += `</tbody></table>`;
      allPagesContainer.innerHTML = summaryHtml + dateHtml;
    }

    function renderSinglePage(dates, page) {
      allPagesContainer.innerHTML = '';
      singlePageLayout.classList.remove('hidden');

      const data = rawData[page] || {};
      let html = `
        <h2 class="font-bold text-lg mb-2">${page} – Performance by Date (${fmtISO(serverStart)} to ${fmtISO(serverEnd)})</h2>
        <table class="w-full border text-sm mb-6">
          <thead class="bg-gray-200">
            <tr>
              <th class="border px-2 py-1">Date</th>
              <th class="border px-2 py-1">Amount Spent</th>
              <th class="border px-2 py-1">Orders</th>
              <th class="border px-2 py-1">CPP</th>
              <th class="border px-2 py-1">CPM</th>
              <th class="border px-2 py-1">Item Names</th>
              <th class="border px-2 py-1">CODs</th>
            </tr>
          </thead>
          <tbody>`;

      dates.forEach(d=>{
        const r = data[d] || {};
        const items = (r.item_names||[]);
        const itemContent = items.length<=1 ? items.join('') : items.join('\n');
        const cods = (r.cods||[]).join(', ');
        html += `
          <tr>
            <td class="border px-2 py-1 text-center">${d}</td>
            <td class="border px-2 py-1 text-center">${r.spent?peso(r.spent):'—'}</td>
            <td class="border px-2 py-1 text-center">${r.orders ?? '—'}</td>
            <td class="border px-2 py-1 text-center">${r.cpp!=null?peso(r.cpp):'—'}</td>
            <td class="border px-2 py-1 text-center">${r.cpm!=null?peso(r.cpm):'—'}</td>
            <td class="border px-2 py-1 text-left whitespace-pre-line">${itemContent || '—'}</td>
            <td class="border px-2 py-1 text-left whitespace-nowrap overflow-hidden text-ellipsis max-w-[300px]" title="${cods}">${cods || '—'}</td>
          </tr>`;
      });

      // Totals
      let spent=0, orders=0, wImps=0;
      dates.forEach(d=>{
        const r=data[d]||{};
        if (r.spent) spent+=r.spent;
        if (r.orders) orders+=r.orders;
        if (r.spent && r.cpm) wImps += r.spent / r.cpm;
      });
      const cpp = orders>0 ? spent/orders : null;
      const cpm = wImps>0 ? spent/wImps : null;

      html += `
        <tr class="bg-gray-100 font-bold">
          <td class="border px-2 py-1 text-center">TOTAL</td>
          <td class="border px-2 py-1 text-center">${spent?peso(spent):'—'}</td>
          <td class="border px-2 py-1 text-center">${orders}</td>
          <td class="border px-2 py-1 text-center">${cpp!=null?peso(cpp):'—'}</td>
          <td class="border px-2 py-1 text-center">${cpm!=null?peso(cpm):'—'}</td>
          <td class="border px-2 py-1"></td>
          <td class="border px-2 py-1"></td>
        </tr>
      </tbody></table>`;
      rightTable.innerHTML = html;

      // Charts
      const cppData = dates.map(d => (data[d]?.cpp ?? null));
      const cpmData = dates.map(d => (data[d]?.cpm ?? null));
      renderCPPChart(dates, cppData);
      renderCPMChart(dates, cpmData);
    }

    function renderCPPChart(labels, data) {
      if (cppChart) cppChart.destroy();
      cppChart = new Chart(cppCanvas.getContext('2d'), {
        type: 'line',
        data: { labels, datasets: [{ label: 'CPP', data, tension: 0.3, spanGaps: true }] },
        options: {
          responsive: true,
          plugins: { datalabels: { display: true, formatter: v => (v||v===0)?peso(Math.round(v)):'' } },
          scales: { y: { beginAtZero: true, title: { display: true, text: 'CPP' } } }
        },
        plugins: [ChartDataLabels]
      });
    }

    function renderCPMChart(labels, data) {
      if (cpmChart) cpmChart.destroy();
      cpmChart = new Chart(cpmCanvas.getContext('2d'), {
        type: 'line',
        data: { labels, datasets: [{ label: 'CPM', data, tension: 0.3, spanGaps: true }] },
        options: {
          responsive: true,
          plugins: { datalabels: { display: true, formatter: v => (v||v===0)?peso(Math.round(v)):'' } },
          scales: { y: { beginAtZero: true, title: { display: true, text: 'CPM' } } }
        },
        plugins: [ChartDataLabels]
      });
    }

    function refreshAll() {
      const page  = pageSelect.value;
      const dates = allDates.slice(); // full chosen range from server
      if (page === 'all') {
        if (cppChart) cppChart.destroy();
        if (cpmChart) cpmChart.destroy();
        renderAllPagesTables(dates);
      } else {
        renderSinglePage(dates, page);
      }
    }

    // Init
    window.onload = refreshAll;

    // Copy helper
    function copySummaryOfAds() {
      const table = document.getElementById('summaryOfAdsTable');
      if (!table) return;
      const rows = Array.from(table.querySelectorAll('tr'));
      const txt = rows.map(r => Array.from(r.querySelectorAll('th,td')).map(c=>c.textContent.replace(/₱/g,'').trim()).join('\t')).join('\n');
      navigator.clipboard.writeText(txt).then(()=>alert('SUMMARY OF ADS table copied!'));
    }
    window.copySummaryOfAds = copySummaryOfAds;
  </script>
</x-layout>

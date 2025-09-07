{{-- resources/views/ads_manager/cpp.blade.php --}}
<x-layout>
  <x-slot name="heading">CPP Summary</x-slot>

  {{-- Filter Controls --}}
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
      <input type="date" id="startDate" class="border px-2 py-1 rounded" value="{{ $start ?? '' }}">
    </div>
    <div>
      <label for="endDate" class="block font-semibold mb-1">End Date:</label>
      <input type="date" id="endDate" class="border px-2 py-1 rounded" value="{{ $end ?? '' }}">
    </div>
  </div>

  {{-- Display Area --}}
  <div id="singlePageLayout" class="hidden lg:flex gap-6 mb-10">
    <div class="basis-full lg:basis-1/3 lg:shrink-0">
      <h2 class="font-bold text-lg mb-2">CPP Chart</h2>
      <canvas id="cppChart" height="100" class="mb-8"></canvas>
      <h2 class="font-bold text-lg mb-2">CPM Chart</h2>
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
    const pageSelect       = document.getElementById('pageSelect');
    const startDateInput   = document.getElementById('startDate');
    const endDateInput     = document.getElementById('endDate');
    const cppCanvas        = document.getElementById('cppChart');
    const cpmCanvas        = document.getElementById('cpmChart');
    const tableRight       = document.getElementById('rightTableContainer');
    const multiPageTables  = document.getElementById('multiPageTables');
    const singlePageLayout = document.getElementById('singlePageLayout');

    let cppChart, cpmChart;

    // --- Helpers ---
    function fmtISO(iso) {
      const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || '');
      if (!m) return 'Invalid Date';
      const months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
      const y = m[1], mm = +m[2], dd = +m[3];
      return `${months[mm-1]} ${dd}, ${y}`;
    }

    function filterDates() {
      const start = startDateInput.value || srvStart;
      const end   = endDateInput.value   || srvEnd;
      return allDates.filter(d => (!start || d >= start) && (!end || d <= end));
    }

    // ---- helpers para i-filter dates na may spend > 0 ----
    function totalSpendAllPagesOn(date) {
      let sum = 0;
      Object.values(rawData).forEach(data => {
        const r = data[date] || {};
        if (typeof r.spent === 'number') sum += r.spent;
      });
      return sum;
    }
    function datesWithSpendAllPages(dates) {
      return dates.filter(d => totalSpendAllPagesOn(d) > 0);
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
      url.searchParams.set('ui_page', pageSelect.value || 'all');
      window.location.assign(url.toString());
    }

    // --- Render tables ---
    function renderTables(filteredDates, pageFilter) {
      // Title uses actually shown dates (already spend-filtered in refreshAll)
      const titleStart = filteredDates[0];
      const titleEnd   = filteredDates[filteredDates.length - 1];

      if (pageFilter === 'all') {
        const title = (titleStart !== titleEnd)
          ? `SUMMARY OF ADS - ${fmtISO(titleStart)} to ${fmtISO(titleEnd)}`
          : `SUMMARY OF ADS - ${fmtISO(titleStart)}`;

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
              </tr>
            </thead>
            <tbody>
        `;

        Object.entries(rawData).forEach(([page, data]) => {
          let sumSpent=0, sumOrders=0, wImps=0, wCPI=0, tcprFail=0;
          filteredDates.forEach(date => {
            const r = data[date] || {};
            if (typeof r.spent === 'number') sumSpent += r.spent;
            // count orders only if this page had spend > 0 on that day
            if (r.spent && r.orders) sumOrders += r.orders;
            if (r.spent && r.cpm) wImps += r.spent / r.cpm;
            if (r.spent && r.cpi) wCPI  += r.spent / r.cpi;
            if (r.spent && r.tcpr_fail) tcprFail += r.tcpr_fail;
          });

          // >>> key fix: STRICTLY hide pages with zero total spend
          if (sumSpent > 0) {
            const cpp  = sumOrders > 0 ? sumSpent / sumOrders : null;
            const cpi  = wCPI  > 0 ? sumSpent / wCPI  : null;
            const cpm  = wImps > 0 ? sumSpent / wImps : null;
            const tcpr = sumOrders > 0 ? (tcprFail / sumOrders) : null;

            summaryHtml += `
              <tr>
                <td class="border px-2 py-1">${page}</td>
                <td class="border px-2 py-1">₱${sumSpent.toFixed(2)}</td>
                <td class="border px-2 py-1">${sumOrders}</td>
                <td class="border px-2 py-1">${cpp != null ? `₱${cpp.toFixed(2)}` : '—'}</td>
                <td class="border px-2 py-1">${cpi != null ? `₱${cpi.toFixed(2)}` : '—'}</td>
                <td class="border px-2 py-1">${cpm != null ? `₱${cpm.toFixed(2)}` : '—'}</td>
                <td class="border px-2 py-1">
  ${tcpr != null ? tcprBadge(tcpr * 100) : '—'}
</td>
                </tr>
            `;
          }
        });

        summaryHtml += `</tbody></table>`;

        // 2) Performance by Date (all pages) — also ignore orders from zero-spend pages
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
            <tbody>
        `;

        filteredDates.forEach(date => {
          let sumSpent=0, sumOrders=0, wImps=0, wCPI=0;
          Object.values(rawData).forEach(data => {
            const r = data[date] || {};
            if (typeof r.spent === 'number') sumSpent += r.spent;
            // count orders only when page had spend that day
            if (r.spent && r.orders) sumOrders += r.orders;
            if (r.spent && r.cpm)   wImps += r.spent / r.cpm;
            if (r.spent && r.cpi)   wCPI  += r.spent / r.cpi;
          });

          if (sumSpent <= 0) return; // skip day with zero total spend

          const cpp = sumOrders>0 ? sumSpent/sumOrders : null;
          const cpi = wCPI>0 ? sumSpent/wCPI : null;
          const cpm = wImps>0 ? sumSpent/wImps : null;

          dateHtml += `
            <tr>
              <td class="border px-2 py-1 text-center">${date}</td>
              <td class="border px-2 py-1 text-center">₱${sumSpent.toFixed(2)}</td>
              <td class="border px-2 py-1 text-center">${sumOrders}</td>
              <td class="border px-2 py-1 text-center">${cpp != null ? `₱${cpp.toFixed(2)}` : '—'}</td>
              <td class="border px-2 py-1 text-center">${cpi != null ? `₱${cpi.toFixed(2)}` : '—'}</td>
              <td class="border px-2 py-1 text-center">${cpm != null ? `₱${cpm.toFixed(2)}` : '—'}</td>
            </tr>
          `;
        });

        dateHtml += `</tbody></table>`;
        tableRight.innerHTML = summaryHtml + dateHtml;

      } else {
        // Single Page layout (unchanged except we skip zero-spend days)
        multiPageTables.classList.add('hidden');
        singlePageLayout.classList.remove('hidden');
        const data = rawData[pageFilter] || {};

        let html = `
          <h2 class="font-bold text-lg mb-2">${pageFilter} – Performance by Date (${fmtISO(titleStart)} to ${fmtISO(titleEnd)})</h2>
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
                <th class="border px-2 py-1">PROCEED</th>
              </tr>
            </thead>
            <tbody>
        `;

        filteredDates.forEach(date => {
          const r = data[date] || {};
          if (!r.spent || r.spent <= 0) return; // skip rows with zero spend

          const itemNames = (r.item_names || []);
          const itemContent = itemNames.length <= 1 ? itemNames.join('') : itemNames.join('\n');
          const cods = (r.cods || []).join(', ');

          html += `
            <tr>
              <td class="border px-2 py-1 text-center">${date}</td>
              <td class="border px-2 py-1 text-center">₱${r.spent.toFixed(2)}</td>
              <td class="border px-2 py-1 text-center">${r.orders ?? '—'}</td>
              <td class="border px-2 py-1 text-center">${r.cpp != null ? `₱${r.cpp.toFixed(2)}` : '—'}</td>
              <td class="border px-2 py-1 text-center">${r.cpm != null ? `₱${r.cpm.toFixed(2)}` : '—'}</td>
              <td class="border px-2 py-1 text-left whitespace-pre-line">${itemContent || '—'}</td>
              <td class="border px-2 py-1 text-left whitespace-nowrap overflow-hidden text-ellipsis max-w-[300px]" title="${cods}">${cods || '—'}</td>
              <td class="border px-2 py-1 text-center">${r.proceed ?? '—'}</td>
            </tr>
          `;
        });

        // Totals: based only on shown rows (spend>0)
        let totalSpent = 0, totalOrders = 0, sumWeighted = 0;
        filteredDates.forEach(date => {
          const r = data[date] || {};
          if (r.spent && r.spent > 0) {
            totalSpent  += r.spent;
            if (r.orders) totalOrders += r.orders;
            if (r.cpm) sumWeighted += r.spent / r.cpm;
          }
        });
        const totalCPP = totalOrders > 0 ? totalSpent / totalOrders : null;
        const totalCPM = sumWeighted > 0  ? totalSpent / sumWeighted   : null;

        html += `
          <tr class="bg-gray-100 font-bold">
            <td class="border px-2 py-1 text-center">TOTAL</td>
            <td class="border px-2 py-1 text-center">₱${totalSpent.toFixed(2)}</td>
            <td class="border px-2 py-1 text-center">${totalOrders}</td>
            <td class="border px-2 py-1 text-center">${totalCPP != null ? `₱${totalCPP.toFixed(2)}` : '—'}</td>
            <td class="border px-2 py-1 text-center">${totalCPM != null ? `₱${totalCPM.toFixed(2)}` : '—'}</td>
            <td class="border px-2 py-1" colspan="3"></td>
          </tr>
        `;

        html += `</tbody></table>`;
        tableRight.innerHTML = html;
      }
    }

    // Charts
    function renderCPPChart(filteredDates, cppData) {
      if (cppChart) cppChart.destroy();
      cppChart = new Chart(cppCanvas.getContext('2d'), {
        type: 'line',
        data: { labels: filteredDates, datasets: [{ label: 'CPP', data: cppData, tension: 0.3, spanGaps: true }] },
        options: {
          responsive: true,
          plugins: { datalabels: { display: true, formatter: v => (v || v === 0) ? `₱${Number(v).toFixed(0)}` : '' } },
          scales: { y: { beginAtZero: true, title: { display: true, text: 'CPP' } } }
        },
        plugins: [ChartDataLabels]
      });
    }

    function renderCPMChart(filteredDates, cpmData) {
      if (cpmChart) cpmChart.destroy();
      cpmChart = new Chart(cpmCanvas.getContext('2d'), {
        type: 'line',
        data: { labels: filteredDates, datasets: [{ label: 'CPM', data: cpmData, tension: 0.3, spanGaps: true }] },
        options: {
          responsive: true,
          plugins: { datalabels: { display: true, formatter: v => (v || v === 0) ? `₱${Number(v).toFixed(0)}` : '' } },
          scales: { y: { beginAtZero: true, title: { display: true, text: 'CPM' } } }
        },
        plugins: [ChartDataLabels]
      });
    }

    function refreshAll() {
      const page  = pageSelect.value;
      const baseDates = filterDates();

      // Use only dates with spend
      const dates = (page === 'all')
        ? datesWithSpendAllPages(baseDates)
        : datesWithSpendForPage(baseDates, page);

      if (!dates.length) {
        if (cppChart) cppChart.destroy();
        if (cpmChart) cpmChart.destroy();
        singlePageLayout.classList.add('hidden');
        multiPageTables.classList.remove('hidden');
        tableRight.innerHTML = `
          <div class="p-4 border rounded bg-yellow-50 text-yellow-800">
            No data with ad spend &gt; 0 for the selected dates.
          </div>`;
        return;
      }

      let cppData = [], cpmData = [];
      if (page === 'all') {
        // Averages across pages WITH spend only
        dates.forEach(date => {
          let sumCpp = 0, cntCpp = 0, sumCpm = 0, cntCpm = 0;
          Object.values(rawData).forEach(d => {
            const r = d[date];
            if (r && r.spent && r.cpp != null) { sumCpp += r.cpp; cntCpp++; }
            if (r && r.spent && r.cpm != null) { sumCpm += r.cpm; cntCpm++; }
          });
          cppData.push(cntCpp ? sumCpp / cntCpp : null);
          cpmData.push(cntCpm ? sumCpm / cntCpm : null);
        });
      } else {
        const sel = rawData[page] || {};
        cppData = dates.map(d => sel[d]?.cpp ?? null);
        cpmData = dates.map(d => sel[d]?.cpm ?? null);
      }

      renderCPPChart(dates, cppData);
      renderCPMChart(dates, cpmData);
      renderTables(dates, page);
    }

    function copySummaryOfAds() {
      const table = document.getElementById('summaryOfAdsTable');
      if (!table) return;

      const rows = Array.from(table.querySelectorAll('tr'));
      const copiedText = rows.map(row => {
        return Array.from(row.querySelectorAll('th, td'))
          .map(cell => cell.textContent.replace(/₱/g, '').trim())
          .join('\t');
      }).join('\n');

      navigator.clipboard.writeText(copiedText)
        .then(() => alert('SUMMARY OF ADS table copied!'))
        .catch(err => console.error('Copy failed:', err));
    }

    // Events
    startDateInput.addEventListener('change', navigateWithBothDates);
    endDateInput.addEventListener('change',   navigateWithBothDates);
    pageSelect.addEventListener('change', refreshAll);

    // Init
    window.onload = () => {
      if ((!startDateInput.value || !endDateInput.value) && allDates.length) {
        startDateInput.value = allDates[0];
        endDateInput.value   = allDates[allDates.length - 1];
      }
      refreshAll();
    };
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

  // ≤ 3% → plain text (no styling)
  return `${pct.toFixed(2)}%`;
}



  </script>
</x-layout>

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
      <input type="date" id="startDate" class="border px-2 py-1 rounded">
    </div>
    <div>
      <label for="endDate" class="block font-semibold mb-1">End Date:</label>
      <input type="date" id="endDate" class="border px-2 py-1 rounded">
    </div>
  </div>

  {{-- Display Area --}}
  <div id="singlePageLayout" class="hidden lg:flex gap-6 mb-10">
    <div class="flex-1">
      <h2 class="font-bold text-lg mb-2">CPP Chart</h2>
      <canvas id="cppChart" height="100" class="mb-8"></canvas>
      <h2 class="font-bold text-lg mb-2">CPM Chart</h2>
      <canvas id="cpmChart" height="100"></canvas>
    </div>
    <div class="flex-1 overflow-auto" id="rightTableContainer"></div>
  </div>

  {{-- Multi Page Tables (default) --}}
  <div id="multiPageTables" class="overflow-auto mb-10"></div>

  {{-- ChartJS and Plugin --}}
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels"></script>

  <script>
    const rawData          = @json($matrix);
    const allDates         = @json($allDates);
    const pageSelect       = document.getElementById('pageSelect');
    const startDateInput   = document.getElementById('startDate');
    const endDateInput     = document.getElementById('endDate');
    const cppCanvas        = document.getElementById('cppChart');
    const cpmCanvas        = document.getElementById('cpmChart');
    const tableRight       = document.getElementById('rightTableContainer');
    const multiPageTables  = document.getElementById('multiPageTables');
    const singlePageLayout = document.getElementById('singlePageLayout');

    let cppChart, cpmChart;

    function getLast7Days() {
      const today = new Date();
      const result = [];
      for (let i = 6; i >= 0; i--) {
        const d = new Date(today);
        d.setDate(today.getDate() - i);
        result.push(d.toISOString().slice(0, 10));
      }
      return result.filter(date => allDates.includes(date));
    }

    function filterDates() {
      const start = startDateInput.value;
      const end   = endDateInput.value;
      return allDates.filter(d => (!start || d >= start) && (!end || d <= end));
    }

    function renderTables(filteredDates, pageFilter) {
     if (pageFilter === 'all') {
  // Compute a dynamic title based on the first and last filtered date
 const start = filteredDates[0];
const end = filteredDates[filteredDates.length - 1];

const formattedStart = new Date(start).toLocaleDateString('en-US', {
  year: 'numeric',
  month: 'long',
  day: 'numeric'
});

const formattedEnd = new Date(end).toLocaleDateString('en-US', {
  year: 'numeric',
  month: 'long',
  day: 'numeric'
});

const title = filteredDates.length > 1
  ? `SUMMARY OF ADS - ${formattedStart} to ${formattedEnd}`
  : `SUMMARY OF ADS - ${formattedStart}`;

  // 1) Summary by Page — use our dynamic title here
  let summaryHtml = `
  <div class="flex justify-between items-center mb-2">
    <h2 class="font-bold text-lg">${title}</h2>
    <button onclick="copySummaryOfAds()" class="bg-blue-500 text-white px-3 py-1 rounded text-sm hover:bg-blue-600">Copy Table</button>
  </div>
  <table id="summaryOfAdsTable" class="min-w-full border text-sm mb-6">
      <thead class="bg-gray-200"><tr>
        <th class="border px-2 py-1">Page Name</th>
        <th class="border px-2 py-1">Amount Spent</th>
        <th class="border px-2 py-1">Orders</th>
        <th class="border px-2 py-1">CPP</th>
        <th class="border px-2 py-1">CPM</th>
      </tr></thead>
      <tbody>
  `;
  // …the rest stays the samse…


        Object.entries(rawData).forEach(([page, data]) => {
          let sumSpent = 0, sumOrders = 0, sumWeightedImps = 0;
          filteredDates.forEach(date => {
            const r = data[date] || {};
            if (r.spent)  sumSpent    += r.spent;
            if (r.orders) sumOrders   += r.orders;
            if (r.spent && r.cpm) sumWeightedImps += r.spent / r.cpm;
          });

          // only include pages with spent > 0
          if (sumSpent > 0) {
            const cpp = sumOrders  > 0 ? sumSpent  / sumOrders      : null;
            const cpm = sumWeightedImps > 0 ? sumSpent / sumWeightedImps : null;

            summaryHtml += `
              <tr>
                <td class="border px-2 py-1">${page}</td>
                <td class="border px-2 py-1">₱${sumSpent.toFixed(2)}</td>
                <td class="border px-2 py-1">${sumOrders}</td>
                <td class="border px-2 py-1">${cpp != null ? `₱${cpp.toFixed(2)}` : '—'}</td>
                <td class="border px-2 py-1">${cpm != null ? `₱${cpm.toFixed(2)}` : '—'}</td>
              </tr>
            `;
          }
        });

        summaryHtml += `</tbody></table>`;

        // 2) Performance by Date
        let dateHtml = `
          <h2 class="font-bold text-lg mb-2">All Pages – Performance by Date</h2>
          <table class="min-w-full border text-sm"><thead class="bg-gray-200"><tr>
            <th class="border px-2 py-1">Date</th>
            <th class="border px-2 py-1">Amount Spent</th>
            <th class="border px-2 py-1">Orders</th>
            <th class="border px-2 py-1">CPP</th>
            <th class="border px-2 py-1">CPM</th>
          </tr></thead><tbody>
        `;

        filteredDates.forEach(date => {
          let sumSpent = 0, sumOrders = 0, sumWeightedImps = 0;
          Object.values(rawData).forEach(data => {
            const r = data[date] || {};
            if (r.spent)  sumSpent    += r.spent;
            if (r.orders) sumOrders   += r.orders;
            if (r.spent && r.cpm) sumWeightedImps += r.spent / r.cpm;
          });
          const cpp = sumOrders  > 0 ? sumSpent  / sumOrders      : null;
          const cpm = sumWeightedImps > 0 ? sumSpent / sumWeightedImps : null;

          dateHtml += `
            <tr>
              <td class="border px-2 py-1 text-center">${date}</td>
              <td class="border px-2 py-1 text-center">₱${sumSpent.toFixed(2)}</td>
              <td class="border px-2 py-1 text-center">${sumOrders}</td>
              <td class="border px-2 py-1 text-center">${cpp != null ? `₱${cpp.toFixed(2)}` : '—'}</td>
              <td class="border px-2 py-1 text-center">${cpm != null ? `₱${cpm.toFixed(2)}` : '—'}</td>
            </tr>
          `;
        });

        dateHtml += `</tbody></table>`;

        // 3) render both
        tableRight.innerHTML = summaryHtml + dateHtml;

      } else {
        // Single-page for one page
        multiPageTables.classList.add('hidden');
        singlePageLayout.classList.remove('hidden');
        const data = rawData[pageFilter] || {};
        let html = `
          <h2 class="font-bold text-lg mb-2">${pageFilter} – Performance by Date</h2>
          <table class="min-w-full border text-sm mb-6">
            <thead class="bg-gray-200"><tr>
              <th class="border px-2 py-1">Date</th>
              <th class="border px-2 py-1">Amount Spent</th>
              <th class="border px-2 py-1">Orders</th>
              <th class="border px-2 py-1">CPP</th>
              <th class="border px-2 py-1">CPM</th>
            </tr></thead>
            <tbody>
        `;

        filteredDates.forEach(date => {
          const r = data[date] || {};
          html += `
            <tr>
              <td class="border px-2 py-1 text-center">${date}</td>
              <td class="border px-2 py-1 text-center">${r.spent != null ? `₱${r.spent.toFixed(2)}` : '—'}</td>
              <td class="border px-2 py-1 text-center">${r.orders != null ? r.orders : '—'}</td>
              <td class="border px-2 py-1 text-center">${r.cpp != null ? `₱${r.cpp.toFixed(2)}` : '—'}</td>
              <td class="border px-2 py-1 text-center">${r.cpm != null ? `₱${r.cpm.toFixed(2)}` : '—'}</td>
            </tr>
          `;
        });

        // Totals for single page
        let totalSpent = 0, totalOrders = 0, sumWeighted = 0;
        filteredDates.forEach(date => {
          const r = data[date] || {};
          if (r.spent)  totalSpent  += r.spent;
          if (r.orders) totalOrders += r.orders;
          if (r.spent && r.cpm) sumWeighted += r.spent / r.cpm;
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
            </tr>
        `;

        html += `</tbody></table>`;
        tableRight.innerHTML = html;
      }
    }

    // Chart rendering unchanged...

    function renderCPPChart(filteredDates, cppData) {
      if (cppChart) cppChart.destroy();
      cppChart = new Chart(cppCanvas.getContext('2d'), {
        type: 'line',
        data: { labels: filteredDates, datasets: [{ label: 'CPP', data: cppData, tension: 0.3, spanGaps: true }] },
        options: {
          responsive: true,
          plugins: { datalabels: { display: true, formatter: v => v ? `₱${v.toFixed(0)}` : '' } },
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
          plugins: { datalabels: { display: true, formatter: v => v ? `₱${v.toFixed(0)}` : '' } },
          scales: { y: { beginAtZero: true, title: { display: true, text: 'CPM' } } }
        },
        plugins: [ChartDataLabels]
      });
    }

    function refreshAll() {
      const page = pageSelect.value;
      const dates = filterDates();
      let cppData = [], cpmData = [];
      if (page === 'all') {
        dates.forEach(date => {
          let sumCpp = 0, cntCpp = 0, sumCpm = 0, cntCpm = 0;
          Object.values(rawData).forEach(d => {
            if (d[date]?.cpp != null) { sumCpp += d[date].cpp; cntCpp++; }
            if (d[date]?.cpm != null) { sumCpm += d[date].cpm; cntCpm++; }
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

    pageSelect.addEventListener('change', refreshAll);
    startDateInput.addEventListener('change', refreshAll);
    endDateInput.addEventListener('change', refreshAll);

    window.onload = () => {
      const last7 = getLast7Days();
      if (last7.length) {
        startDateInput.value = last7[0];
        endDateInput.value   = last7[last7.length - 1];
      }
      refreshAll();
    };
    
  </script>
</x-layout>
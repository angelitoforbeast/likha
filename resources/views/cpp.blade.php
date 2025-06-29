
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
    const rawData = @json($matrix);
    const allDates = @json($allDates);
    const pageSelect = document.getElementById('pageSelect');
    const startDateInput = document.getElementById('startDate');
    const endDateInput = document.getElementById('endDate');
    const cppCanvas = document.getElementById('cppChart');
    const cpmCanvas = document.getElementById('cpmChart');
    const tableRight = document.getElementById('rightTableContainer');
    const multiPageTables = document.getElementById('multiPageTables');
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
      const end = endDateInput.value;
      return allDates.filter(d => (!start || d >= start) && (!end || d <= end));
    }

    function renderTables(filteredDates, pageFilter) {
      let html = '';

      if (pageFilter === 'all') {
        singlePageLayout.classList.add('hidden');
        multiPageTables.classList.remove('hidden');

        const buildTable = (type) => {
          let table = `<table class="min-w-full border text-sm mb-6"><thead class="bg-gray-200"><tr><th class="border px-2 py-1">Page</th>`;
          filteredDates.forEach(date => {
            table += `<th class="border px-2 py-1">${date}</th>`;
          });
          table += `</tr></thead><tbody>`;

          Object.entries(rawData).forEach(([page, data]) => {
            table += `<tr><td class="border px-2 py-1 font-bold">${page}</td>`;
            filteredDates.forEach(date => {
              const val = data[date] ?? {};
              let content = '—';
              if (type === 'cpp' && val.cpp != null) content = `₱${val.cpp.toFixed(2)}`;
              if (type === 'cpm' && val.cpm != null) content = `₱${val.cpm.toFixed(2)}`;
              if (type === 'orders' && val.orders != null) content = val.orders;
              table += `<td class="border px-2 py-1 text-center">${content}</td>`;
            });
            table += `</tr>`;
          });

          table += `</tbody></table>`;
          return table;
        };

        html += `<h2 class="font-bold text-lg mb-2">CPP Table</h2>${buildTable('cpp')}`;
        html += `<h2 class="font-bold text-lg mb-2">Orders Table</h2>${buildTable('orders')}`;
        html += `<h2 class="font-bold text-lg mb-2">CPM Table</h2>${buildTable('cpm')}`;

        multiPageTables.innerHTML = html;
      } else {
        multiPageTables.classList.add('hidden');
        singlePageLayout.classList.remove('hidden');

        const data = rawData[pageFilter] || {};
        html += `<h2 class="font-bold text-lg mb-2">${pageFilter} - Performance Table</h2>`;
        html += `<table class="min-w-full border text-sm"><thead class="bg-gray-200"><tr>
                  <th class="border px-2 py-1">Date</th>
                  <th class="border px-2 py-1">Amount Spent</th>
                  <th class="border px-2 py-1">Orders</th>
                  <th class="border px-2 py-1">CPP</th>
                  <th class="border px-2 py-1">CPM</th>
                </tr></thead><tbody>`;

        filteredDates.forEach(date => {
          const row = data[date] || {};
          html += `<tr>
            <td class="border px-2 py-1 text-center">${date}</td>
            <td class="border px-2 py-1 text-center">${row.spent != null ? `₱${row.spent.toFixed(2)}` : '—'}</td>
            <td class="border px-2 py-1 text-center">${row.orders ?? '—'}</td>
            <td class="border px-2 py-1 text-center">${row.cpp != null ? `₱${row.cpp.toFixed(2)}` : '—'}</td>
            <td class="border px-2 py-1 text-center">${row.cpm != null ? `₱${row.cpm.toFixed(2)}` : '—'}</td>
          </tr>`;
        });

        html += `</tbody></table>`;
        tableRight.innerHTML = html;
      }
    }

    function renderCPPChart(filteredDates, cppData) {
      if (cppChart) cppChart.destroy();
      cppChart = new Chart(cppCanvas.getContext('2d'), {
        type: 'line',
        data: {
          labels: filteredDates,
          datasets: [{
            label: 'CPP',
            data: cppData,
            borderColor: 'rgba(75, 192, 192, 1)',
            backgroundColor: 'rgba(75, 192, 192, 0.2)',
            tension: 0.3,
            spanGaps: true,
          }]
        },
        options: {
          responsive: true,
          plugins: {
            datalabels: {
              display: true,
              formatter: value => value ? `₱${value.toFixed(0)}` : '',
              anchor: 'end',
              align: 'top',
              font: { weight: 'bold' }
            }
          },
          scales: {
            y: {
              beginAtZero: true,
              title: { display: true, text: 'CPP' }
            }
          }
        },
        plugins: [ChartDataLabels]
      });
    }

    function renderCPMChart(filteredDates, cpmData) {
      if (cpmChart) cpmChart.destroy();
      cpmChart = new Chart(cpmCanvas.getContext('2d'), {
        type: 'line',
        data: {
          labels: filteredDates,
          datasets: [{
            label: 'CPM',
            data: cpmData,
            borderColor: 'rgba(255, 99, 132, 1)',
            backgroundColor: 'rgba(255, 99, 132, 0.2)',
            tension: 0.3,
            spanGaps: true
          }]
        },
        options: {
          responsive: true,
          plugins: {
            datalabels: {
              display: true,
              formatter: value => value ? `₱${value.toFixed(0)}` : '',
              anchor: 'end',
              align: 'top',
              font: { weight: 'bold' }
            }
          },
          scales: {
            y: {
              beginAtZero: true,
              title: { display: true, text: 'CPM' }
            }
          }
        },
        plugins: [ChartDataLabels]
      });
    }

    function refreshAll() {
      const page = pageSelect.value;
      const filteredDates = filterDates();

      let cppData = [], cpmData = [];

      if (page === 'all') {
        filteredDates.forEach(date => {
          let cppSum = 0, cppCount = 0, cpmSum = 0, cpmCount = 0;
          Object.values(rawData).forEach(pageData => {
            if (pageData[date]) {
              if (pageData[date].cpp != null) { cppSum += pageData[date].cpp; cppCount++; }
              if (pageData[date].cpm != null) { cpmSum += pageData[date].cpm; cpmCount++; }
            }
          });
          cppData.push(cppCount ? cppSum / cppCount : null);
          cpmData.push(cpmCount ? cpmSum / cpmCount : null);
        });
      } else {
        const selected = rawData[page] || {};
        cppData = filteredDates.map(date => selected[date]?.cpp ?? null);
        cpmData = filteredDates.map(date => selected[date]?.cpm ?? null);
      }

      renderCPPChart(filteredDates, cppData);
      renderCPMChart(filteredDates, cpmData);
      renderTables(filteredDates, page);
    }

    pageSelect.addEventListener('change', refreshAll);
    startDateInput.addEventListener('change', refreshAll);
    endDateInput.addEventListener('change', refreshAll);

    window.onload = () => {
      const last7 = getLast7Days();
      if (last7.length > 0) {
        startDateInput.value = last7[0];
        endDateInput.value = last7[last7.length - 1];
      }
      refreshAll();
    };
  </script>
</x-layout>

<x-layout>
  <x-slot name="title">Sales Declaration</x-slot>
  <x-slot name="heading">Sales Declaration</x-slot>

  @once
    <link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
    <style>
      .ts-wrapper { min-height: 38px; }
      .ts-control { border-radius: 0.5rem !important; }
    </style>
  @endonce

  {{-- Month info --}}
  <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 mb-4">
    <div class="flex flex-wrap gap-4 items-center">
      <div>
        <span class="text-sm text-gray-500">Month:</span>
        <span class="font-bold text-lg">{{ $month }}</span>
      </div>
      <div>
        <span class="text-sm text-gray-500">Total Delivered Orders:</span>
        <span class="font-bold text-lg text-green-700">{{ number_format($monthCount) }}</span>
      </div>
      <div>
        <span class="text-sm text-gray-500">Total Delivered COD:</span>
        <span class="font-bold text-lg text-green-700">₱{{ number_format($monthTotal, 2) }}</span>
      </div>
    </div>
  </div>

  {{-- Filters Form --}}
  <div class="bg-white p-4 rounded-xl shadow mb-4">
    <div class="grid md:grid-cols-4 gap-4 mb-4">
      {{-- Month --}}
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Month</label>
        <input type="month" id="f_month" value="{{ $month }}" class="w-full border rounded-lg px-3 py-2" />
      </div>

      {{-- Target Amount --}}
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Target Sales Amount (₱)</label>
        <input type="number" id="f_target" placeholder="e.g. 500000" step="0.01" min="1"
               class="w-full border rounded-lg px-3 py-2" />
      </div>

      {{-- Status --}}
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
        <select id="f_status" class="w-full border rounded-lg px-3 py-2">
          @foreach($statuses as $s)
            <option value="{{ $s }}" {{ $s === 'Delivered' ? 'selected' : '' }}>{{ $s }}</option>
          @endforeach
        </select>
      </div>

      {{-- Min Orders Per Day --}}
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Min Orders / Day</label>
        <input type="number" id="f_minperday" value="5" min="1" max="100"
               class="w-full border rounded-lg px-3 py-2" />
      </div>

      {{-- Max Orders Per Day --}}
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Max Orders / Day <span class="text-gray-400 text-xs">(optional)</span></label>
        <input type="number" id="f_maxperday" value="10" min="1" max="10000" placeholder="No limit"
               class="w-full border rounded-lg px-3 py-2" />
      </div>
    </div>

    <div class="grid md:grid-cols-3 gap-4 mb-4">
      {{-- Sender --}}
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Sender (optional)</label>
        <select id="f_senders" multiple placeholder="All senders..."></select>
      </div>

      {{-- Items --}}
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Items (optional)</label>
        <select id="f_items" multiple placeholder="All items..."></select>
      </div>

      {{-- COD Values --}}
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">COD Prices (optional)</label>
        <select id="f_cods" multiple placeholder="All prices..."></select>
      </div>
    </div>

    <div class="flex items-center gap-4 flex-wrap">
      {{-- Per Day Toggle --}}
      <label class="flex items-center gap-2 text-sm">
        <input type="checkbox" id="f_perday" checked class="rounded" />
        Per-day breakdown
      </label>

      {{-- Generate Button --}}
      <button type="button" id="btnGenerate"
              class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-semibold">
        Generate Declaration
      </button>

      {{-- Download Button (hidden until generated) --}}
      <button type="button" id="btnDownload" style="display:none"
              class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 font-semibold">
        Download CSV
      </button>

      {{-- Loading indicator --}}
      <span id="filterLoading" style="display:none" class="text-sm text-gray-400">Loading filters...</span>
    </div>
  </div>

  {{-- Loading --}}
  <div id="loading" style="display:none" class="text-center py-8">
    <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
    <p class="mt-2 text-gray-500">Generating declaration...</p>
  </div>

  {{-- Results --}}
  <div id="results" style="display:none">
    {{-- Summary Cards --}}
    <div class="grid md:grid-cols-4 gap-3 mb-4">
      <div class="bg-white rounded-xl shadow p-3 text-center">
        <div class="text-xs text-gray-500">Target Amount</div>
        <div class="text-xl font-bold" id="r_target">—</div>
      </div>
      <div class="bg-white rounded-xl shadow p-3 text-center">
        <div class="text-xs text-gray-500">Actual Total</div>
        <div class="text-xl font-bold text-green-700" id="r_actual">—</div>
      </div>
      <div class="bg-white rounded-xl shadow p-3 text-center">
        <div class="text-xs text-gray-500">Orders Selected</div>
        <div class="text-xl font-bold" id="r_orders">—</div>
      </div>
      <div class="bg-white rounded-xl shadow p-3 text-center">
        <div class="text-xs text-gray-500">Available Orders</div>
        <div class="text-xl font-bold text-gray-500" id="r_available">—</div>
      </div>
    </div>

    {{-- Per-Day Breakdown --}}
    <div id="perDaySection" style="display:none" class="mb-4">
      <h3 class="font-semibold text-gray-700 mb-2">Per-Day Breakdown</h3>
      <div class="bg-white rounded-xl shadow overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-100">
            <tr>
              <th class="border px-3 py-2 text-left">Date</th>
              <th class="border px-3 py-2 text-right">Orders</th>
              <th class="border px-3 py-2 text-right">Total COD</th>
            </tr>
          </thead>
          <tbody id="perDayBody"></tbody>
        </table>
      </div>
    </div>

    {{-- Orders Table --}}
    <h3 class="font-semibold text-gray-700 mb-2">Selected Orders</h3>
    <div class="bg-white rounded-xl shadow overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-gray-100 text-left">
          <tr>
            <th class="border px-2 py-2">#</th>
            <th class="border px-2 py-2">Submission Time</th>
            <th class="border px-2 py-2">Signing Time</th>
            <th class="border px-2 py-2">WAYBILL</th>
            <th class="border px-2 py-2">RECEIVER</th>
            <th class="border px-2 py-2">PHONE</th>
            <th class="border px-2 py-2">SENDER</th>
            <th class="border px-2 py-2">ITEM</th>
            <th class="border px-2 py-2 text-right">COD</th>
            <th class="border px-2 py-2">PROVINCE</th>
            <th class="border px-2 py-2">CITY</th>
            <th class="border px-2 py-2">BARANGAY</th>
            <th class="border px-2 py-2">ADDRESS</th>
            <th class="border px-2 py-2">REMARKS</th>
          </tr>
        </thead>
        <tbody id="ordersBody"></tbody>
      </table>
    </div>
  </div>

  {{-- Hidden export form --}}
  <form id="exportForm" method="get" action="{{ route('jnt.sales-declaration.export') }}" style="display:none">
    <input type="hidden" name="month" id="ex_month">
    <input type="hidden" name="target_amount" id="ex_target">
    <input type="hidden" name="status" id="ex_status">
    <input type="hidden" name="items" id="ex_items">
    <input type="hidden" name="senders" id="ex_senders">
    <input type="hidden" name="cod_values" id="ex_cods">
    <input type="hidden" name="min_per_day" id="ex_minperday">
    <input type="hidden" name="max_per_day" id="ex_maxperday">
    <input type="hidden" name="seed" id="ex_seed">
  </form>

  <script>
    document.addEventListener('DOMContentLoaded', function () {
      // Init Tom Select instances
      let tsSenders, tsItems, tsCods;
      let isUpdating = false; // prevent infinite loops

      tsSenders = new TomSelect('#f_senders', {
        plugins: ['remove_button'],
        maxOptions: 1000,
        onChange: function () { if (!isUpdating) refreshFilters('sender'); },
      });

      tsItems = new TomSelect('#f_items', {
        plugins: ['remove_button'],
        maxOptions: 1000,
        onChange: function () { if (!isUpdating) refreshFilters('item'); },
      });

      tsCods = new TomSelect('#f_cods', {
        plugins: ['remove_button'],
        maxOptions: 500,
      });

      // Month change → reload page
      document.getElementById('f_month').addEventListener('change', function () {
        window.location.href = '{{ route("jnt.sales-declaration") }}?month=' + this.value;
      });

      // Status change → refresh filters
      document.getElementById('f_status').addEventListener('change', function () {
        refreshFilters('status');
      });

      /**
       * Refresh cascading filter options via AJAX.
       * @param {string} changedBy - which filter triggered the refresh
       */
      async function refreshFilters(changedBy) {
        const month   = document.getElementById('f_month').value;
        const status  = document.getElementById('f_status').value;
        const senders = tsSenders.getValue();
        const items   = tsItems.getValue();

        document.getElementById('filterLoading').style.display = 'inline';

        try {
          const resp = await fetch('{{ route("jnt.sales-declaration.filter") }}', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-TOKEN': '{{ csrf_token() }}',
            },
            body: JSON.stringify({ month, status, senders, items }),
          });

          const data = await resp.json();
          isUpdating = true;

          // Update senders (only if item or status changed)
          if (changedBy !== 'sender') {
            const currentSenders = tsSenders.getValue();
            tsSenders.clearOptions();
            tsSenders.addOption(data.senders.map(s => ({ value: s, text: s })));
            // Restore selection if still valid
            currentSenders.forEach(s => {
              if (data.senders.includes(s)) tsSenders.addItem(s, true);
            });
          }

          // Update items (only if sender or status changed)
          if (changedBy !== 'item') {
            const currentItems = tsItems.getValue();
            tsItems.clearOptions();
            tsItems.addOption(data.items.map(i => ({ value: i.value, text: i.label })));
            currentItems.forEach(i => {
              if (data.items.find(x => x.value === i)) tsItems.addItem(i, true);
            });
          }

          // Always update COD values
          const currentCods = tsCods.getValue();
          tsCods.clearOptions();
          tsCods.addOption(data.cod_values.map(c => ({ value: String(c), text: '₱' + Number(c).toLocaleString() })));
          currentCods.forEach(c => {
            if (data.cod_values.map(String).includes(c)) tsCods.addItem(c, true);
          });

          isUpdating = false;
        } catch (err) {
          console.error('Filter refresh error:', err);
          isUpdating = false;
        } finally {
          document.getElementById('filterLoading').style.display = 'none';
        }
      }

      // Initial load of filter options
      refreshFilters('status');

      let currentSeed = null;

      // Generate
      document.getElementById('btnGenerate').addEventListener('click', async function () {
        const target = parseFloat(document.getElementById('f_target').value);
        if (!target || target <= 0) {
          alert('Please enter a valid target amount.');
          return;
        }

        const month     = document.getElementById('f_month').value;
        const status    = document.getElementById('f_status').value;
        const perDay    = document.getElementById('f_perday').checked;
        const minPerDay = parseInt(document.getElementById('f_minperday').value) || 5;
        const maxPerDayVal = document.getElementById('f_maxperday').value;
        const maxPerDay = maxPerDayVal !== '' ? parseInt(maxPerDayVal) : null;
        const senders   = tsSenders.getValue();
        const items     = tsItems.getValue();
        const codValues = tsCods.getValue();

        currentSeed = Math.floor(Math.random() * 999999999);

        document.getElementById('loading').style.display = 'block';
        document.getElementById('results').style.display = 'none';

        try {
          const resp = await fetch('{{ route("jnt.sales-declaration.generate") }}', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-TOKEN': '{{ csrf_token() }}',
            },
            body: JSON.stringify({
              month, target_amount: target, status, per_day: perDay,
              min_per_day: minPerDay, max_per_day: maxPerDay, items, senders, cod_values: codValues
            }),
          });

          const data = await resp.json();

          if (!data.success) {
            alert('Error generating declaration.');
            return;
          }

          // Summary
          document.getElementById('r_target').textContent = '₱' + Number(data.target_amount).toLocaleString(undefined, {minimumFractionDigits: 2});
          document.getElementById('r_actual').textContent = '₱' + Number(data.actual_total).toLocaleString(undefined, {minimumFractionDigits: 2});
          document.getElementById('r_orders').textContent = Number(data.total_orders).toLocaleString();
          document.getElementById('r_available').textContent = Number(data.available).toLocaleString();

          // Highlight if actual < target
          const actualEl = document.getElementById('r_actual');
          if (data.actual_total < data.target_amount) {
            actualEl.classList.remove('text-green-700');
            actualEl.classList.add('text-red-600');
          } else {
            actualEl.classList.remove('text-red-600');
            actualEl.classList.add('text-green-700');
          }

          // Per-day
          const pdSection = document.getElementById('perDaySection');
          const pdBody = document.getElementById('perDayBody');
          pdBody.innerHTML = '';
          if (perDay && data.per_day && data.per_day.length > 0) {
            pdSection.style.display = 'block';
            data.per_day.forEach(d => {
              pdBody.innerHTML += `<tr class="hover:bg-gray-50">
                <td class="border px-3 py-1">${d.date}</td>
                <td class="border px-3 py-1 text-right">${Number(d.orders).toLocaleString()}</td>
                <td class="border px-3 py-1 text-right">₱${Number(d.total).toLocaleString(undefined, {minimumFractionDigits: 2})}</td>
              </tr>`;
            });
          } else {
            pdSection.style.display = 'none';
          }

          // Orders table
          const oBody = document.getElementById('ordersBody');
          oBody.innerHTML = '';
          data.orders.forEach((o, idx) => {
            const dt = o.submission_time ? String(o.submission_time).substring(0, 10) : '';
            const st = o.signingtime ? String(o.signingtime).substring(0, 10) : '';
            const esc = (v) => String(v ?? '').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            oBody.innerHTML += `<tr class="hover:bg-gray-50">
              <td class="border px-2 py-1 text-gray-400 text-xs">${idx + 1}</td>
              <td class="border px-2 py-1 text-xs">${dt}</td>
              <td class="border px-2 py-1 text-xs">${st}</td>
              <td class="border px-2 py-1 font-mono text-xs">${esc(o.waybill_number)}</td>
              <td class="border px-2 py-1">${esc(o.receiver)}</td>
              <td class="border px-2 py-1">${esc(o.receiver_cellphone)}</td>
              <td class="border px-2 py-1">${esc(o.sender)}</td>
              <td class="border px-2 py-1">${esc(o.item_name)}</td>
              <td class="border px-2 py-1 text-right">${Number(o.cod).toLocaleString()}</td>
              <td class="border px-2 py-1">${esc(o.province)}</td>
              <td class="border px-2 py-1">${esc(o.city)}</td>
              <td class="border px-2 py-1">${esc(o.barangay)}</td>
              <td class="border px-2 py-1 text-xs">${esc(o.address)}</td>
              <td class="border px-2 py-1 text-xs">${esc(o.remarks)}</td>
            </tr>`;
          });

          // Setup export form
          document.getElementById('ex_month').value = month;
          document.getElementById('ex_target').value = target;
          document.getElementById('ex_status').value = status;
          document.getElementById('ex_items').value = items.join('||');
          document.getElementById('ex_senders').value = senders.join('||');
          document.getElementById('ex_cods').value = codValues.join(',');
          document.getElementById('ex_minperday').value = minPerDay;
          document.getElementById('ex_maxperday').value = maxPerDay !== null ? maxPerDay : '';
          document.getElementById('ex_seed').value = currentSeed;

          document.getElementById('btnDownload').style.display = 'inline-block';
          document.getElementById('results').style.display = 'block';

        } catch (err) {
          alert('Error: ' + err.message);
        } finally {
          document.getElementById('loading').style.display = 'none';
        }
      });

      // Download
      document.getElementById('btnDownload').addEventListener('click', function () {
        document.getElementById('exportForm').submit();
      });
    });
  </script>
</x-layout>

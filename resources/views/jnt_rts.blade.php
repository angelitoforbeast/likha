<x-layout>
  <x-slot name="title">RTS</x-slot>
  <x-slot name="heading">RTS Monitoring</x-slot>

  {{-- Styles (inline) --}}
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
  <style>
    /* paginate buttons — works wherever the element lives */
    .dataTables_paginate .paginate_button {
      padding: 0.2rem 0.65rem; margin: 0 2px; border-radius: 0.375rem;
      background-color: #374151; color: white !important; border: none;
      cursor: pointer; font-size: 12px;
    }
    .dataTables_paginate .paginate_button.current {
      background-color: #2563eb !important; font-weight: bold;
    }
    .dataTables_paginate .paginate_button.disabled {
      opacity: 0.4; cursor: default;
    }
    .dataTables_wrapper .dataTables_filter { display:none; }
    .dataTables_wrapper .dataTables_info   { display:none; }
    .dataTables_wrapper .dataTables_paginate { display:none; }
    .flatpickr-calendar { z-index: 9999 !important; }
  </style>

  {{-- Filters --}}
  <div class="px-4 py-6">
  <form method="GET" action="{{ url('/jnt_rts') }}" class="mb-4 bg-white p-4 shadow rounded" id="rtsFilterForm">
    <div class="flex flex-wrap items-end gap-3">
      <div class="min-w-[260px]">
        <label class="block text-sm font-semibold mb-1">Date range</label>
        <input id="dateRange" type="text" placeholder="Select date range"
               class="w-full border border-gray-300 p-2 rounded-md shadow-sm cursor-pointer bg-white" readonly>
        <input type="hidden" name="from" id="from" value="{{ $from ?? '' }}">
        <input type="hidden" name="to"   id="to"   value="{{ $to   ?? '' }}">
      </div>

      <div class="flex-1"></div>

      {{-- Global search --}}
      <div class="min-w-[240px]">
        <label for="globalSearch" class="block text-sm font-semibold mb-1">Search</label>
        <input id="globalSearch" type="text" placeholder="Search anything…"
               class="w-full border border-gray-300 p-2 rounded-md shadow-sm" />
      </div>

      <button class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700 shadow">Apply</button>
      <a href="{{ url('/jnt_rts') }}" class="px-4 py-2 rounded-md border hover:bg-gray-50">Reset</a>
    </div>
  </form>

  @if (!empty($results) && count($results))
    <div class="bg-white shadow rounded" style="overflow:auto; max-height:calc(100vh - 310px);">
      <table id="rtsTable" class="min-w-full table-auto border-collapse text-sm">
        <thead style="position:sticky;top:0;z-index:20;background:#f1f5f9;box-shadow:0 2px 4px rgba(0,0,0,0.1);">
          <tr>
            <th class="px-3 py-2 border border-gray-300 text-left text-xs font-semibold text-gray-600 uppercase tracking-wide whitespace-nowrap">Date Range</th>
            <th class="px-3 py-2 border border-gray-300 text-left text-xs font-semibold text-gray-600 uppercase tracking-wide whitespace-nowrap">Sender</th>
            <th class="px-3 py-2 border border-gray-300 text-left text-xs font-semibold text-gray-600 uppercase tracking-wide whitespace-nowrap">Item</th>
            <th class="px-3 py-2 border border-gray-300 text-left text-xs font-semibold text-gray-600 uppercase tracking-wide whitespace-nowrap">COD</th>
            <th class="px-3 py-2 border border-gray-300 text-right text-xs font-semibold text-gray-600 uppercase tracking-wide whitespace-nowrap">Qty</th>
            <th class="px-3 py-2 border border-gray-300 text-right text-xs font-semibold text-gray-600 uppercase tracking-wide whitespace-nowrap">RTS Qty</th>
            <th class="px-3 py-2 border border-gray-300 text-right text-xs font-semibold text-gray-600 uppercase tracking-wide whitespace-nowrap">Del Qty</th>
            <th class="px-3 py-2 border border-gray-300 text-right text-xs font-semibold text-gray-600 uppercase tracking-wide whitespace-nowrap">Transit Qty</th>
            <th class="px-3 py-2 border border-gray-300 text-right text-xs font-semibold text-gray-600 uppercase tracking-wide whitespace-nowrap">RTS%</th>
            <th class="px-3 py-2 border border-gray-300 text-right text-xs font-semibold text-gray-600 uppercase tracking-wide whitespace-nowrap">Delivered%</th>
            <th class="px-3 py-2 border border-gray-300 text-right text-xs font-semibold text-gray-600 uppercase tracking-wide whitespace-nowrap">In Transit%</th>
            <th class="px-3 py-2 border border-gray-300 text-right text-xs font-semibold text-gray-600 uppercase tracking-wide whitespace-nowrap">Current RTS%</th>
            <th class="px-3 py-2 border border-gray-300 text-right text-xs font-semibold text-gray-600 uppercase tracking-wide whitespace-nowrap">MAX RTS%</th>
          </tr>
        </thead>
        <tbody>
          @foreach ($results as $r)
            @php
              $rtsColor = $r['rts_percent'] > 25 ? 'bg-red-100'
                        : ($r['rts_percent'] > 20 ? 'bg-orange-100'
                        : ($r['rts_percent'] > 15 ? 'bg-green-100' : 'bg-cyan-100'));
              $num = fn($v) => is_numeric($v) ? $v : null;
            @endphp
            <tr class="hover:bg-blue-50 transition-colors">
              <td class="px-3 py-1.5 border border-gray-200 whitespace-nowrap text-gray-700">{{ $r['date_range'] }}</td>
              <td class="px-3 py-1.5 border border-gray-200 whitespace-nowrap font-medium text-gray-800">{{ $r['sender'] }}</td>
              <td class="px-3 py-1.5 border border-gray-200 whitespace-nowrap text-gray-700">{{ $r['item'] }}</td>
              <td class="px-3 py-1.5 border border-gray-200 whitespace-nowrap text-gray-700">{{ $r['cod'] }}</td>
              <td class="px-3 py-1.5 border border-gray-200 text-right text-gray-700" data-raw="{{ (int)$r['quantity'] }}">{{ number_format((int)$r['quantity']) }}</td>
              <td class="px-3 py-1.5 border border-gray-200 text-right text-gray-700" data-raw="{{ (int)$r['rts_count'] }}">{{ number_format((int)$r['rts_count']) }}</td>
              <td class="px-3 py-1.5 border border-gray-200 text-right text-gray-700" data-raw="{{ (int)$r['delivered_count'] }}">{{ number_format((int)$r['delivered_count']) }}</td>
              <td class="px-3 py-1.5 border border-gray-200 text-right text-gray-700" data-raw="{{ (int)$r['transit_count'] }}">{{ number_format((int)$r['transit_count']) }}</td>

              <td class="px-3 py-1.5 border border-gray-200 text-right font-semibold {{ $rtsColor }}"
                  data-order="{{ $r['rts_percent'] }}">{{ number_format($r['rts_percent'], 2) }}%</td>

              <td class="px-3 py-1.5 border border-gray-200 text-right text-gray-700"
                  data-order="{{ $r['delivered_percent'] }}">{{ number_format($r['delivered_percent'], 2) }}%</td>

              <td class="px-3 py-1.5 border border-gray-200 text-right text-gray-700"
                  data-order="{{ $r['transit_percent'] }}">{{ number_format($r['transit_percent'], 2) }}%</td>

              <td class="px-3 py-1.5 border border-gray-200 text-right text-gray-700"
                  data-order="{{ $num($r['current_rts']) ?? -1 }}">
                {{ is_numeric($r['current_rts']) ? number_format($r['current_rts'], 2) . '%' : 'N/A' }}
              </td>

              <td class="px-3 py-1.5 border border-gray-200 text-right text-gray-700"
                  data-order="{{ $num($r['max_rts']) ?? -1 }}">
                {{ is_numeric($r['max_rts']) ? number_format($r['max_rts'], 2) . '%' : 'N/A' }}
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  @else
    <p class="text-gray-600">No data to display. Please select a date range.</p>
  @endif
  </div>{{-- end px-4 py-6 --}}

  @if (!empty($results) && count($results))
  {{-- Fixed bottom bar: totals + pagination --}}
  <div id="rts-bottom-bar" style="position:fixed;bottom:0;left:0;right:0;z-index:100;background:#f8fafc;border-top:2px solid #94a3b8;box-shadow:0 -4px 14px rgba(0,0,0,0.12);">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 20px;gap:12px;flex-wrap:wrap;">

      {{-- Totals --}}
      <div style="display:flex;align-items:center;gap:8px;font-size:13px;flex-wrap:wrap;">
        <span style="font-weight:600;color:#64748b;font-size:10px;text-transform:uppercase;letter-spacing:0.08em;margin-right:4px;">TOTAL</span>
        <span style="font-weight:700;color:#1e293b;background:#e2e8f0;padding:2px 8px;border-radius:4px;">
          Qty: <span id="tot-qty">—</span>
        </span>
        <span style="color:#94a3b8;">·</span>
        <span style="font-weight:700;color:#b91c1c;background:#fee2e2;padding:2px 8px;border-radius:4px;">
          RTS: <span id="tot-rts">—</span> &nbsp;<span id="tot-rts-pct" style="font-size:11px;">—</span>
        </span>
        <span style="color:#94a3b8;">·</span>
        <span style="font-weight:700;color:#15803d;background:#dcfce7;padding:2px 8px;border-radius:4px;">
          Del: <span id="tot-del">—</span> &nbsp;<span id="tot-del-pct" style="font-size:11px;">—</span>
        </span>
        <span style="color:#94a3b8;">·</span>
        <span style="font-weight:700;color:#1d4ed8;background:#dbeafe;padding:2px 8px;border-radius:4px;">
          Transit: <span id="tot-transit">—</span> &nbsp;<span id="tot-transit-pct" style="font-size:11px;">—</span>
        </span>
      </div>

      {{-- Pagination + info --}}
      <div style="display:flex;align-items:center;gap:14px;">
        <span id="dt-info-slot" style="font-size:12px;color:#6b7280;white-space:nowrap;"></span>
        <div id="dt-paging-slot"></div>
      </div>

    </div>
  </div>
  @endif

  {{-- Scripts (inline + fallback) --}}
  <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.js"></script>
  <script>
    // Fallback if jsDelivr fails
    window.addEventListener('load', function() {
      if (!window.flatpickr) {
        var s = document.createElement('script');
        s.src = 'https://unpkg.com/flatpickr@4.6.13/dist/flatpickr.min.js';
        s.onload = initFlatpickr;
        document.body.appendChild(s);
      } else {
        initFlatpickr();
      }
    });

    function ymd(d){
      const pad=n=>String(n).padStart(2,'0');
      return d ? d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate()) : '';
    }

    function initFlatpickr(){
      try {
        const fromInit = document.getElementById('from').value || null;
        const toInit   = document.getElementById('to').value   || null;

        flatpickr('#dateRange', {
          mode: 'range',
          clickOpens: true,
          allowInput: false,
          dateFormat: 'Y-m-d',
          defaultDate: (fromInit && toInit) ? [fromInit, toInit] : undefined,
          onReady(selectedDates, dateStr, instance){
            if(fromInit && toInit){
              instance.input.value = fromInit + ' to ' + toInit;
            }
          },
          onChange(selectedDates){
            if(selectedDates.length === 1){
              document.getElementById('from').value = ymd(selectedDates[0]);
              document.getElementById('to').value   = ymd(selectedDates[0]);
            } else if (selectedDates.length === 2){
              const [start, end] = selectedDates;
              document.getElementById('from').value = ymd(start);
              document.getElementById('to').value   = ymd(end);
            }
          }
        });
      } catch (e) {
        console.error('Flatpickr init error:', e);
      }
    }
  </script>

  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
  <script>
    function fmtNum(n) {
      return Number(n).toLocaleString('en-PH');
    }

    function updateTotals(dt) {
      let qty = 0, rts = 0, del = 0, transit = 0;

      dt.rows({ search: 'applied' }).nodes().each(function (row) {
        const cells = row.querySelectorAll('td[data-raw]');
        qty     += parseInt(cells[0]?.dataset.raw || 0);
        rts     += parseInt(cells[1]?.dataset.raw || 0);
        del     += parseInt(cells[2]?.dataset.raw || 0);
        transit += parseInt(cells[3]?.dataset.raw || 0);
      });

      const total = Math.max(1, qty);
      document.getElementById('tot-qty').textContent         = fmtNum(qty);
      document.getElementById('tot-rts').textContent         = fmtNum(rts);
      document.getElementById('tot-del').textContent         = fmtNum(del);
      document.getElementById('tot-transit').textContent     = fmtNum(transit);
      document.getElementById('tot-rts-pct').textContent     = (rts / total * 100).toFixed(2) + '%';
      document.getElementById('tot-del-pct').textContent     = (del / total * 100).toFixed(2) + '%';
      document.getElementById('tot-transit-pct').textContent = (transit / total * 100).toFixed(2) + '%';
    }

    function updateInfo(dt) {
      const info = dt.page.info();
      const slot = document.getElementById('dt-info-slot');
      if (!slot) return;
      const filtered = info.recordsDisplay !== info.recordsTotal
        ? ` (filtered from ${fmtNum(info.recordsTotal)})` : '';
      slot.textContent = `Showing ${fmtNum(info.start + 1)}–${fmtNum(info.end)} of ${fmtNum(info.recordsDisplay)} entries${filtered}`;
    }

    document.addEventListener('DOMContentLoaded', function () {
      const tableEl = document.getElementById('rtsTable');
      if (!tableEl) return;

      const dt = $('#rtsTable').DataTable({
        paging: true,
        searching: true,
        ordering: true,
        info: true,
        dom: 'lrtip',
        order: [[8, 'desc']],
        pageLength: 25,
        drawCallback: function () {
          const api = this.api();
          updateTotals(api);
          updateInfo(api);
          // move pagination into fixed bar (idempotent — only moves once)
          const wrapper = this.api().table().container();
          const pagEl   = wrapper.querySelector('.dataTables_paginate');
          const pagSlot = document.getElementById('dt-paging-slot');
          if (pagEl && pagSlot && !pagSlot.contains(pagEl)) pagSlot.appendChild(pagEl);
        }
      });

      updateTotals(dt);
      updateInfo(dt);

      // Move pagination on first load
      const wrapper = dt.table().container();
      const pagEl   = wrapper.querySelector('.dataTables_paginate');
      const pagSlot = document.getElementById('dt-paging-slot');
      if (pagEl && pagSlot) pagSlot.appendChild(pagEl);

      const searchInput = document.getElementById('globalSearch');
      if (searchInput) {
        searchInput.addEventListener('input', function () {
          dt.search(this.value).draw();
        });
      }
    });
  </script>
</x-layout>

<x-layout>
  <x-slot name="heading">RTS Monitoring</x-slot>

  {{-- Styles (inline) --}}
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
  <style>
    .dataTables_wrapper .dataTables_paginate .paginate_button {
      padding: 0.25rem 0.75rem; margin: 0 2px; border-radius: 0.375rem;
      background-color: #1f2937; color: white !important; border: none;
    }
    .dataTables_wrapper .dataTables_paginate .paginate_button.current {
      background-color: #2563eb !important; font-weight: bold;
    }
    .dataTables_wrapper .dataTables_info { margin-top: 0.75rem; color: #6b7280; }
    .dataTables_wrapper .dataTables_filter { display:none; } /* external search bar */
    .flatpickr-calendar { z-index: 9999 !important; }
  </style>

  {{-- Filters --}}
  <form method="POST" action="{{ url('/jnt_rts') }}" class="mb-4 bg-white p-4 shadow rounded" id="rtsFilterForm">
    @csrf
    <div class="flex flex-wrap items-end gap-3">
      <div class="min-w-[260px]">
        <label class="block text-sm font-semibold mb-1">Date range</label>
        <input id="dateRange" type="text" placeholder="Select date range"
               class="w-full border border-gray-300 p-2 rounded-md shadow-sm cursor-pointer bg-white" readonly>
        {{-- keep controller contract --}}
        <input type="hidden" name="from" id="from" value="{{ old('from', $from ?? '') }}">
        <input type="hidden" name="to"   id="to"   value="{{ old('to',   $to   ?? '') }}">
      </div>

      <div class="flex-1"></div>

      {{-- Global search --}}
      <div class="min-w-[240px]">
        <label for="globalSearch" class="block text-sm font-semibold mb-1">Search</label>
        <input id="globalSearch" type="text" placeholder="Search anything…"
               class="w-full border border-gray-300 p-2 rounded-md shadow-sm" />
      </div>

      <button class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700 shadow">Apply</button>
      @if(!empty($from) || !empty($to))
        <a href="{{ url('/jnt_rts') }}" class="px-4 py-2 rounded-md border hover:bg-gray-50">Reset</a>
      @endif
    </div>
  </form>

  @if (!empty($results) && count($results))
    <div class="overflow-auto bg-white p-4 shadow rounded">
      <table id="rtsTable" class="min-w-full table-auto border text-sm">
        <thead class="bg-gray-100 sticky top-0 z-10">
          <tr>
            <th class="px-2 py-1 border">Date Range</th>
            <th class="px-2 py-1 border">Sender</th>
            <th class="px-2 py-1 border">Item</th>
            <th class="px-2 py-1 border">COD</th>
            <th class="px-2 py-1 border">Qty</th>
            <th class="px-2 py-1 border">RTS%</th>
            <th class="px-2 py-1 border">Delivered%</th>
            <th class="px-2 py-1 border">In Transit%</th>
            <th class="px-2 py-1 border">Current RTS%</th>
            <th class="px-2 py-1 border">MAX RTS%</th>
          </tr>
        </thead>
        <tbody>
          @foreach ($results as $r)
            @php
              $rtsColor = $r['rts_percent'] > 25 ? 'bg-red-200'
                        : ($r['rts_percent'] > 20 ? 'bg-orange-200'
                        : ($r['rts_percent'] > 15 ? 'bg-green-200' : 'bg-cyan-200'));
              $num = fn($v) => is_numeric($v) ? $v : null;
            @endphp
            <tr class="hover:bg-gray-50">
              <td class="px-2 py-1 border whitespace-nowrap">{{ $r['date_range'] }}</td>
              <td class="px-2 py-1 border whitespace-nowrap">{{ $r['sender'] }}</td>
              <td class="px-2 py-1 border whitespace-nowrap">{{ $r['item'] }}</td>
              <td class="px-2 py-1 border whitespace-nowrap">{{ $r['cod'] }}</td>
              <td class="px-2 py-1 border text-right">{{ number_format((int)$r['quantity']) }}</td>

              <td class="px-2 py-1 border text-right {{ $rtsColor }}"
                  data-order="{{ $r['rts_percent'] }}">{{ number_format($r['rts_percent'], 2) }}%</td>

              <td class="px-2 py-1 border text-right"
                  data-order="{{ $r['delivered_percent'] }}">{{ number_format($r['delivered_percent'], 2) }}%</td>

              <td class="px-2 py-1 border text-right"
                  data-order="{{ $r['transit_percent'] }}">{{ number_format($r['transit_percent'], 2) }}%</td>

              <td class="px-2 py-1 border text-right"
                  data-order="{{ $num($r['current_rts']) ?? -1 }}">
                {{ is_numeric($r['current_rts']) ? number_format($r['current_rts'], 2) . '%' : 'N/A' }}
              </td>

              <td class="px-2 py-1 border text-right"
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
    document.addEventListener('DOMContentLoaded', function () {
      const tableEl = document.getElementById('rtsTable');
      if (!tableEl) return;

      const dt = $('#rtsTable').DataTable({
        paging: true,
        searching: true,
        ordering: true,
        info: true,
        dom: 'lrtip',
        order: [[5, 'desc']], // sort by RTS% desc
        pageLength: 25
      });

      const searchInput = document.getElementById('globalSearch');
      if (searchInput) {
        searchInput.addEventListener('input', function () {
          dt.search(this.value).draw();
        });
      }
    });
  </script>
</x-layout>

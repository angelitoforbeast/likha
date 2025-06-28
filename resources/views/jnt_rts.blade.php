<x-layout>
  <x-slot name="heading">RTS Monitoring</x-slot>

  <form method="POST" action="{{ url('/jnt_rts') }}" class="mb-6 bg-white p-4 shadow-md rounded">
    @csrf
    <div class="flex flex-wrap items-center gap-4">
      <div class="flex items-center gap-2">
        <label class="font-semibold">From:</label>
        <input type="date" name="from" value="{{ old('from', $from ?? '') }}" required class="border border-gray-300 p-2 rounded-md shadow-sm" />
      </div>
      <div class="flex items-center gap-2">
        <label class="font-semibold">To:</label>
        <input type="date" name="to" value="{{ old('to', $to ?? '') }}" required class="border border-gray-300 p-2 rounded-md shadow-sm" />
      </div>
      <button class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700 shadow">Filter</button>
    </div>
  </form>

  @if (count($results))
  <div class="overflow-auto bg-white p-4 shadow-md rounded">
    <table id="rtsTable" class="table-auto w-full border text-sm">
      <thead class="bg-gray-200">
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
        <tr class="hover:bg-gray-50">
          <td class="px-2 py-1 border whitespace-nowrap">{{ $r['date_range'] }}</td>
          <td class="px-2 py-1 border whitespace-nowrap">{{ $r['sender'] }}</td>
          <td class="px-2 py-1 border whitespace-nowrap">{{ $r['item'] }}</td>
          <td class="px-2 py-1 border whitespace-nowrap">{{ $r['cod'] }}</td>
          <td class="px-2 py-1 border whitespace-nowrap">{{ $r['quantity'] }}</td>
          <td class="px-2 py-1 border whitespace-nowrap {{ $r['rts_percent'] > 25 ? 'bg-red-200' : ($r['rts_percent'] > 20 ? 'bg-orange-200' : ($r['rts_percent'] > 15 ? 'bg-green-200' : 'bg-cyan-200')) }}">{{ $r['rts_percent'] }}%</td>
          <td class="px-2 py-1 border whitespace-nowrap">{{ $r['delivered_percent'] }}%</td>
          <td class="px-2 py-1 border whitespace-nowrap">{{ $r['transit_percent'] }}%</td>
          <td class="px-2 py-1 border whitespace-nowrap">{{ $r['current_rts'] }}%</td>
          <td class="px-2 py-1 border whitespace-nowrap">{{ $r['max_rts'] }}%</td>
        </tr>
        @endforeach
      </tbody>
    </table>
  </div>
  @else
  <p class="text-gray-600">No data to display. Please select a date range.</p>
  @endif

  @push('styles')
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
  <style>
    .dataTables_wrapper .dataTables_paginate .paginate_button {
      padding: 0.25rem 0.75rem;
      margin: 0 2px;
      border-radius: 0.375rem;
      background-color: #1f2937;
      color: white !important;
      border: none;
    }
    .dataTables_wrapper .dataTables_paginate .paginate_button.current {
      background-color: #2563eb !important;
      font-weight: bold;
    }
    .dataTables_wrapper .dataTables_info {
      margin-top: 0.75rem;
      color: #6b7280;
    }
    .dataTables_wrapper .dataTables_filter {
      text-align: right;
      margin-bottom: 1rem;
    }
    .dataTables_wrapper .dataTables_filter label {
      font-weight: 600;
      margin-right: 0.5rem;
    }
    .dataTables_wrapper .dataTables_filter input {
      border: 1px solid #d1d5db;
      padding: 0.25rem 0.5rem;
      border-radius: 0.375rem;
      outline: none;
    }
  </style>
  @endpush

  @push('scripts')
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
  <script>
    document.addEventListener("DOMContentLoaded", function () {
      $('#rtsTable').DataTable({
        paging: true,
        searching: true,
        ordering: true,
        info: true,
        language: {
          search: "Search:",
          lengthMenu: "Show _MENU_ entries",
          info: "Showing _START_ to _END_ of _TOTAL_ entries",
          paginate: {
            previous: "<",
            next: ">"
          }
        }
      });
    });
  </script>
  @endpush
</x-layout>

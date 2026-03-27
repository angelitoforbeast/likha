<x-layout>
  <x-slot name="title">Hold Orders – Download</x-slot>
  <x-slot name="heading">J&T Hold Orders – View & Download</x-slot>

  @once
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <style>
      .flatpickr-calendar { z-index: 9999 !important; }
      th, td { vertical-align: middle; }
    </style>
  @endonce

  {{-- Flash messages --}}
  @if(session('error'))
    <div class="mb-4 bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded">
      {{ session('error') }}
    </div>
  @endif

  {{-- Filters --}}
  <form id="filtersForm" method="get" action="{{ route('jnt.hold.download') }}" class="bg-white p-4 rounded-xl shadow mb-4">
    <div class="grid md:grid-cols-6 gap-3 items-end">
      {{-- Date Range --}}
      <div class="md:col-span-2">
        <label class="block text-sm font-medium text-gray-700 mb-1">Date Range</label>
        <input id="date_range" type="text" name="date_range" placeholder="YYYY-MM-DD to YYYY-MM-DD"
               value="{{ $rangeSta && $rangeEnd ? ($rangeSta.' to '.$rangeEnd) : '' }}"
               class="w-full border rounded-lg px-3 py-2" autocomplete="off" />
      </div>

      {{-- Page --}}
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Page</label>
        <select name="PAGE" class="w-full border rounded-lg px-3 py-2" onchange="this.form.submit()">
          <option value="">All Pages</option>
          @foreach($pages as $p)
            <option value="{{ $p }}" {{ ($page ?? '') === $p ? 'selected' : '' }}>{{ $p }}</option>
          @endforeach
        </select>
      </div>

      {{-- Status --}}
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
        <select name="status" class="w-full border rounded-lg px-3 py-2" onchange="this.form.submit()">
          <option value="">All</option>
          <option value="PROCEED" {{ ($status ?? '') === 'PROCEED' ? 'selected' : '' }}>PROCEED</option>
          <option value="CANNOT PROCEED" {{ ($status ?? '') === 'CANNOT PROCEED' ? 'selected' : '' }}>CANNOT PROCEED</option>
          <option value="ODZ" {{ ($status ?? '') === 'ODZ' ? 'selected' : '' }}>ODZ</option>
          <option value="BLANK" {{ ($status ?? '') === 'BLANK' ? 'selected' : '' }}>BLANK</option>
        </select>
      </div>

      {{-- Search --}}
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Search</label>
        <input type="text" name="q" value="{{ $q ?? '' }}" placeholder="Name, waybill, item..."
               class="w-full border rounded-lg px-3 py-2" autocomplete="off" />
      </div>

      {{-- Buttons --}}
      <div class="flex gap-2">
        <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">Filter</button>
        <a href="{{ route('jnt.hold.download') }}" class="px-4 py-2 border rounded-lg hover:bg-gray-50">Reset</a>
      </div>
    </div>
  </form>

  {{-- Summary Cards --}}
  <div class="grid md:grid-cols-6 gap-3 mb-4">
    <div class="bg-white rounded-xl shadow p-3 text-center">
      <div class="text-xs text-gray-500">Total Hold</div>
      <div class="text-2xl font-bold">{{ number_format($statusCounts['TOTAL']) }}</div>
    </div>
    <div class="bg-white rounded-xl shadow p-3 text-center">
      <div class="text-xs text-gray-500">PROCEED</div>
      <div class="text-2xl font-bold text-green-700">{{ number_format($statusCounts['PROCEED']) }}</div>
    </div>
    <div class="bg-white rounded-xl shadow p-3 text-center">
      <div class="text-xs text-gray-500">CANNOT PROCEED</div>
      <div class="text-2xl font-bold text-red-700">{{ number_format($statusCounts['CANNOT PROCEED']) }}</div>
    </div>
    <div class="bg-white rounded-xl shadow p-3 text-center">
      <div class="text-xs text-gray-500">ODZ</div>
      <div class="text-2xl font-bold text-orange-600">{{ number_format($statusCounts['ODZ']) }}</div>
    </div>
    <div class="bg-white rounded-xl shadow p-3 text-center">
      <div class="text-xs text-gray-500">BLANK</div>
      <div class="text-2xl font-bold text-gray-500">{{ number_format($statusCounts['BLANK']) }}</div>
    </div>

    {{-- Download Button --}}
    <form method="get" action="{{ route('jnt.hold.export') }}" class="bg-green-50 rounded-xl shadow p-3 flex flex-col items-center justify-center border-2 border-green-300">
      {{-- Pass current filters to export --}}
      @if($rangeSta && $rangeEnd)
        <input type="hidden" name="date_range" value="{{ $rangeSta }} to {{ $rangeEnd }}">
      @endif
      @if($page ?? '')
        <input type="hidden" name="PAGE" value="{{ $page }}">
      @endif
      @if($status ?? '')
        <input type="hidden" name="status" value="{{ $status }}">
      @endif
      @if($q ?? '')
        <input type="hidden" name="q" value="{{ $q }}">
      @endif
      <button type="submit" class="px-5 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 font-semibold text-sm">
        Download CSV
      </button>
      <div class="text-xs text-green-700 mt-1">{{ number_format($statusCounts['TOTAL']) }} orders</div>
    </form>
  </div>

  {{-- Date range info --}}
  @if($rangeSta && $rangeEnd)
    <div class="text-xs text-gray-500 mb-2">
      Date range: <strong>{{ $rangeSta }}</strong> to <strong>{{ $rangeEnd }}</strong>
    </div>
  @endif

  {{-- Navigation --}}
  <div class="flex gap-2 mb-4 text-sm">
    <a href="{{ route('jnt.hold') }}" class="px-3 py-1 border rounded hover:bg-gray-50">Back to Hold Summary</a>
  </div>

  {{-- Table --}}
  <div class="bg-white rounded-xl shadow overflow-x-auto">
    <table class="min-w-full text-sm">
      <thead class="bg-gray-100 text-left">
        <tr>
          <th class="border px-2 py-2">#</th>
          <th class="border px-2 py-2">FULL NAME</th>
          <th class="border px-2 py-2">PHONE NUMBER</th>
          <th class="border px-2 py-2">ADDRESS</th>
          <th class="border px-2 py-2">PROVINCE</th>
          <th class="border px-2 py-2">CITY</th>
          <th class="border px-2 py-2">BARANGAY</th>
          <th class="border px-2 py-2">STATUS</th>
          <th class="border px-2 py-2">ITEM NAME</th>
          <th class="border px-2 py-2">COD</th>
          <th class="border px-2 py-2">WAYBILL</th>
          <th class="border px-2 py-2">PAGE</th>
          <th class="border px-2 py-2">TIMESTAMP</th>
        </tr>
      </thead>
      <tbody>
        @forelse($records as $i => $r)
          @php
            $statusColor = match($r->status) {
              'PROCEED'        => 'text-green-700 bg-green-50',
              'CANNOT PROCEED' => 'text-red-700 bg-red-50',
              'ODZ'            => 'text-orange-600 bg-orange-50',
              default          => 'text-gray-500',
            };
          @endphp
          <tr class="hover:bg-gray-50">
            <td class="border px-2 py-1 text-gray-400 text-xs">{{ $records->firstItem() + $i }}</td>
            <td class="border px-2 py-1 font-medium">{{ $r->full_name }}</td>
            <td class="border px-2 py-1">{{ $r->phone_number }}</td>
            <td class="border px-2 py-1">{{ $r->address }}</td>
            <td class="border px-2 py-1">{{ $r->province }}</td>
            <td class="border px-2 py-1">{{ $r->city }}</td>
            <td class="border px-2 py-1">{{ $r->barangay }}</td>
            <td class="border px-2 py-1 font-semibold {{ $statusColor }}">{{ $r->status ?: '—' }}</td>
            <td class="border px-2 py-1">{{ $r->item_name }}</td>
            <td class="border px-2 py-1 text-right">{{ $r->cod }}</td>
            <td class="border px-2 py-1 font-mono text-xs">{{ $r->waybill }}</td>
            <td class="border px-2 py-1">{{ $r->page }}</td>
            <td class="border px-2 py-1 text-xs whitespace-nowrap">{{ $r->timestamp }}</td>
          </tr>
        @empty
          <tr>
            <td class="border px-4 py-6 text-center text-gray-500" colspan="13">
              No HOLD orders found for the selected filters.
            </td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>

  {{-- Pagination --}}
  @if($records->hasPages())
    <div class="mt-4">
      {{ $records->withQueryString()->links() }}
    </div>
  @endif

  <script>
    document.addEventListener('DOMContentLoaded', function () {
      flatpickr('#date_range', {
        mode: 'range',
        dateFormat: 'Y-m-d',
        onClose: function (selectedDates, dateStr) {
          if (selectedDates.length >= 1) {
            document.getElementById('filtersForm').submit();
          }
        }
      });
    });
  </script>
</x-layout>

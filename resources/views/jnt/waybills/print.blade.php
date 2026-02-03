{{-- ✅ resources/views/jnt/waybills/print.blade.php --}}
<x-layout>
  <x-slot name="title">Waybills Print</x-slot>
  <x-slot name="heading">Waybills Print</x-slot>

  @php
    $selectedDate = $date ?? request('date') ?? now('Asia/Manila')->subDay()->toDateString();
    $filterBy = $filterBy ?? request('filter_by','page');
    $filterValue = $filterValue ?? request('filter_value','');
    $pages = $pages ?? [];
    $items = $items ?? [];
    $rows  = $rows ?? null;
  @endphp

  <style>
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
    .btn { display:inline-flex; align-items:center; gap:.5rem; border-radius:.5rem; padding:.5rem .75rem; font-size:.875rem; }
    .btn-blue { background:#2563eb; color:#fff; }
    .btn-blue:hover { background:#1d4ed8; }
    .btn-gray { background:#e5e7eb; color:#111827; }
    .btn-gray:hover { background:#d1d5db; }
    .btn-disabled { background:#e5e7eb; color:#6b7280; cursor:not-allowed; }
    .card { background:#fff; border:1px solid #e5e7eb; border-radius:1rem; }
    .muted { color:#6b7280; }
  </style>

  {{-- Flash --}}
  @if(session('success'))
    <div class="mb-4 card p-3 border-green-200 bg-green-50 text-green-800">
      {{ session('success') }}
    </div>
  @endif
  @if(session('error'))
    <div class="mb-4 card p-3 border-red-200 bg-red-50 text-red-800">
      {{ session('error') }}
    </div>
  @endif

  {{-- Filters --}}
  <div class="card p-4 mb-4">
    <form method="GET" action="{{ url('/jnt/waybills/print') }}" class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
      <div class="flex flex-col gap-3 md:flex-row md:items-end">
        <div>
          <label class="block text-sm font-medium mb-1">Date</label>
          <input type="date" name="date" value="{{ $selectedDate }}" class="border rounded-lg px-3 py-2 text-sm w-52">
        </div>

        <div>
          <label class="block text-sm font-medium mb-1">Filter Type</label>
          <select name="filter_by" class="border rounded-lg px-3 py-2 text-sm w-44">
            <option value="page" @selected($filterBy==='page')>Page</option>
            <option value="item" @selected($filterBy==='item')>Item Name</option>
          </select>
        </div>

        <div>
          <label class="block text-sm font-medium mb-1">Filter Value</label>
          <select name="filter_value" class="border rounded-lg px-3 py-2 text-sm w-72">
            <option value="">-- Select --</option>

            @if($filterBy === 'page')
              @foreach($pages as $p)
                <option value="{{ $p }}" @selected($filterValue===$p)>{{ $p }}</option>
              @endforeach
            @else
              @foreach($items as $it)
                <option value="{{ $it }}" @selected($filterValue===$it)>{{ $it }}</option>
              @endforeach
            @endif
          </select>
        </div>

        <div class="flex gap-2">
          <button type="submit" class="btn btn-blue">Filter</button>
        </div>
      </div>
    </form>
  </div>

  {{-- Bulk actions --}}
  <div class="card p-4 mb-4">
    <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
      <div>
        <div class="font-semibold">Bulk Print</div>
        <div class="text-sm muted">Select rows then print as ONE merged PDF.</div>
      </div>

      <div class="flex gap-2 flex-wrap">
        {{-- Print Selected --}}
        <form method="POST" action="{{ url('/jnt/waybills/print-bulk') }}" target="_blank" id="bulkSelectedForm">
          @csrf
          <input type="hidden" name="mode" value="selected">
          <input type="hidden" name="date" value="{{ $selectedDate }}">
          <input type="hidden" name="filter_by" value="{{ $filterBy }}">
          <input type="hidden" name="filter_value" value="{{ $filterValue }}">
          <button type="submit" class="btn btn-blue">Print Selected (1 PDF)</button>
        </form>

        {{-- Print ALL (filtered) --}}
        <form method="POST" action="{{ url('/jnt/waybills/print-bulk') }}" target="_blank">
          @csrf
          <input type="hidden" name="mode" value="all">
          <input type="hidden" name="date" value="{{ $selectedDate }}">
          <input type="hidden" name="filter_by" value="{{ $filterBy }}">
          <input type="hidden" name="filter_value" value="{{ $filterValue }}">
          <button type="submit" class="btn btn-gray">Print ALL Filtered (1 PDF)</button>
        </form>
      </div>
    </div>
  </div>

  {{-- Table --}}
  <div class="card overflow-x-auto">
    <table class="min-w-full text-sm">
      <thead class="bg-gray-50 text-gray-700">
        <tr>
          <th class="p-2 text-left">
            <input type="checkbox" id="chkAll">
          </th>
          <th class="p-2 text-left">Macro ID</th>
          <th class="p-2 text-left">Date</th>
          <th class="p-2 text-left">Page</th>
          <th class="p-2 text-left">FB Name</th>
          <th class="p-2 text-left">Item</th>
          <th class="p-2 text-left">COD</th>
          <th class="p-2 text-left">Address</th>
          <th class="p-2 text-left">Province</th>
          <th class="p-2 text-left">City</th>
          <th class="p-2 text-left">Barangay</th>
          <th class="p-2 text-left">Mailno</th>
          <th class="p-2 text-left">Print</th>
        </tr>
      </thead>

      <tbody class="divide-y">
        @forelse(($rows ?? []) as $r)
          @php
            $macroId = data_get($r,'macro_output_id','-');
            $mailno  = data_get($r,'mailno', null);
          @endphp

          <tr class="align-top">
            <td class="p-2">
              @if(!empty($mailno))
                <input type="checkbox" class="chkRow" name="mailnos[]" value="{{ $mailno }}" form="bulkSelectedForm">
              @else
                <input type="checkbox" disabled>
              @endif
            </td>

            <td class="p-2 mono text-xs">{{ $macroId }}</td>
            <td class="p-2 mono text-xs">{{ data_get($r,'ts_date','-') }}</td>
            <td class="p-2 text-xs">{{ data_get($r,'page','-') }}</td>
            <td class="p-2 text-xs">{{ data_get($r,'fb_name','-') }}</td>
            <td class="p-2 text-xs">{{ data_get($r,'item_name','-') }}</td>
            <td class="p-2 mono text-xs">{{ data_get($r,'cod','-') }}</td>
            <td class="p-2 text-xs">{{ data_get($r,'address','-') }}</td>
            <td class="p-2 mono text-xs">{{ data_get($r,'province','-') }}</td>
            <td class="p-2 mono text-xs">{{ data_get($r,'city','-') }}</td>
            <td class="p-2 mono text-xs">{{ data_get($r,'barangay','-') }}</td>
            <td class="p-2 mono text-xs">{{ $mailno ?? '-' }}</td>

            <td class="p-2">
              @if(!empty($mailno))
                <form method="POST" action="{{ url('/jnt/waybills/print-one') }}" target="_blank">
                  @csrf
                  <input type="hidden" name="mailno" value="{{ $mailno }}">
                  <button type="submit" class="btn btn-blue" style="padding:.35rem .6rem; font-size:.75rem;">
                    Print
                  </button>
                </form>
              @else
                <button disabled class="btn btn-disabled" style="padding:.35rem .6rem; font-size:.75rem;">
                  Print
                </button>
              @endif
            </td>
          </tr>
        @empty
          <tr>
            <td colspan="13" class="p-6 text-center muted">No rows found.</td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>

  {{-- Pagination --}}
  @if($rows)
    <div class="mt-4">
      {{ $rows->links() }}
    </div>
  @endif

  <script>
    (function(){
      const all = document.getElementById('chkAll');
      if (!all) return;

      all.addEventListener('change', function(){
        document.querySelectorAll('.chkRow').forEach(chk => {
          chk.checked = all.checked;
        });
      });
    })();
  </script>
</x-layout>

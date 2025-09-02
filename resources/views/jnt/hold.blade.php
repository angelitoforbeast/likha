<x-layout>
  <x-slot name="heading">JNT Hold Items</x-slot>

  {{-- Flatpickr assets (CDN). Safe to include here; wrapped to avoid duplication if you reuse. --}}
  @once
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <style>
      [x-cloak]{display:none!important}
      th, td { vertical-align: middle; }
    </style>
  @endonce

  <!-- Filters -->
  <form id="filtersForm" method="get" class="bg-white p-4 rounded-xl shadow mb-6 grid md:grid-cols-7 gap-4">
    <div class="md:col-span-2">
      <label class="block text-sm font-medium text-gray-700 mb-1">Search</label>
      <input id="q" type="text" name="q" value="{{ $q }}" placeholder="Search item/page/waybill..."
             class="w-full border rounded-lg px-3 py-2" autocomplete="off" />
    </div>

    <div class="md:col-span-2">
      <label class="block text-sm font-medium text-gray-700 mb-1">Date range</label>
      <input
        id="date_range"
        type="text"
        name="date_range"
        placeholder="YYYY-MM-DD to YYYY-MM-DD"
        value="{{ $rangeSta && $rangeEnd ? ($rangeSta.' to '.$rangeEnd) : ($uiDate ? ($uiDate.' to '.$uiDate) : ($dateRange ?? '')) }}"
        class="w-full border rounded-lg px-3 py-2"
        autocomplete="off"
      />
      <p class="text-xs text-gray-500 mt-1">Single selection = whole day.</p>
    </div>

    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">View by</label>
      <select id="group" name="group" class="w-full border rounded-lg px-3 py-2">
        <option value="item_name" {{ $group === 'item_name' ? 'selected' : '' }}>ITEM NAME (raw)</option>
        <option value="item"      {{ $group === 'item'      ? 'selected' : '' }}>ITEM (units)</option>
        <option value="page"      {{ $group === 'page'      ? 'selected' : '' }}>PAGE</option>
      </select>
      <p class="text-xs text-gray-500 mt-1">
        ITEM (units) removes quantity prefix (e.g., "2 x") and sums needed stock.
      </p>
    </div>

    <div class="flex items-center gap-2 mt-6 md:mt-7">
      <input type="checkbox" name="include_blank" value="1" id="include_blank"
             {{ $includeBlank ? 'checked' : '' }}
             class="h-4 w-4">
      <label for="include_blank" class="text-sm">Include blank/NULL waybills</label>
    </div>

    <div class="flex items-center gap-2 mt-6 md:mt-7">
      <input type="checkbox" name="per_date" value="1" id="per_date"
             {{ $perDate ? 'checked' : '' }}
             class="h-4 w-4">
      <label for="per_date" class="text-sm">Display per date (pivot)</label>
    </div>

    <div class="md:col-span-7">
      <a href="{{ route('jnt.hold') }}" class="px-4 py-2 rounded-lg border">Reset</a>
    </div>

    @if($rangeSta && $rangeEnd)
      <div class="md:col-span-7 text-xs text-gray-600">
        <strong>Date range applied:</strong> {{ $rangeSta }} 00:00:00 → {{ $rangeEnd }} 23:59:59
      </div>
    @elseif($uiDate)
      <div class="md:col-span-7 text-xs text-gray-600">
        <strong>Date applied:</strong> {{ $uiDate }} (whole day)
      </div>
    @endif
  </form>

  <!-- Top summary cards -->
  <div class="grid md:grid-cols-3 gap-4 mb-6">
    <div class="bg-white rounded-xl shadow p-4">
      <div class="text-sm text-gray-500">Total Holds</div>
      <div class="text-3xl font-semibold">{{ number_format($holdsCount) }}</div>
    </div>
    <div class="bg-white rounded-xl shadow p-4 md:col-span-2">
      <div class="text-sm text-gray-500">
        @if($group === 'item')
          Distinct Base Items
        @elseif($group === 'page')
          Distinct Pages
        @else
          Items with Holds (raw)
        @endif
      </div>
      <div class="text-3xl font-semibold">
        {{ number_format($perDate ? ($pivotRows->count() ?? 0) : ($itemsWithHoldsCount ?? 0)) }}
      </div>
    </div>
  </div>

  @if($perDate)
    <!-- PIVOT TABLE (per date, with month/year merged header + day numbers) -->
    <div class="bg-white rounded-xl shadow p-4 overflow-auto">
      <div class="text-sm font-medium mb-3">
        @if($group === 'item') By Item (Needed Units) — Per Date
        @elseif($group === 'page') By Page (Hold Count) — Per Date
        @else By Item Name (Hold Count) — Per Date @endif
      </div>

      <table class="min-w-full text-sm">
        <thead>
          <!-- Row 1: MONTH YEAR merged -->
          <tr class="bg-gray-50">
            <th class="text-left p-2 border-b align-bottom" rowspan="2" style="position: sticky; left: 0; background: #f9fafb;">
              @if($group === 'item') Item
              @elseif($group === 'page') Page
              @else Item Name @endif
            </th>
            @foreach(($monthGroups ?? []) as $m)
              <th class="text-center p-2 border-b whitespace-nowrap" colspan="{{ $m['count'] ?? 0 }}">
                {{ $m['label'] ?? '' }}
              </th>
            @endforeach
            <th class="text-right p-2 border-b align-bottom" rowspan="2">Total</th>
          </tr>
          <!-- Row 2: day numbers -->
          <tr class="bg-gray-50">
            @foreach($dateKeys as $dk)
              <th class="text-right p-2 border-b whitespace-nowrap">{{ $dayLabels[$dk] ?? '' }}</th>
            @endforeach
          </tr>
        </thead>

        <tbody>
          @forelse($pivotRows as $row)
            <tr class="odd:bg-white even:bg-gray-50">
              <td class="p-2 border-b" style="position: sticky; left: 0; background: white;">
                {{ $row['label'] ?? '—' }}
              </td>
              @foreach($dateKeys as $dk)
                <td class="p-2 border-b text-right">{{ number_format($row['dates'][$dk] ?? 0) }}</td>
              @endforeach
              <td class="p-2 border-b text-right font-semibold">{{ number_format($row['total'] ?? 0) }}</td>
            </tr>
          @empty
            <tr><td class="p-2 text-gray-500" colspan="{{ 2 + count($dateKeys) }}">No results.</td></tr>
          @endforelse
        </tbody>

        <tfoot class="bg-gray-50">
          <tr>
            <th class="text-left p-2 border-t" style="position: sticky; left: 0; background: #f9fafb;">Totals</th>
            @foreach($dateKeys as $dk)
              <th class="text-right p-2 border-t">{{ number_format($colTotals[$dk] ?? 0) }}</th>
            @endforeach
            <th class="text-right p-2 border-t">{{ number_format($grandTotal ?? 0) }}</th>
          </tr>
        </tfoot>
      </table>
    </div>
  @else
    <!-- SIMPLE GROUPED LIST (no per-date) -->
    <div class="bg-white rounded-xl shadow p-4">
      <div class="text-sm font-medium mb-3">
        @if($group === 'item') By Item (Needed Units)
        @elseif($group === 'page') By Page (Hold Count)
        @else By Item Name (Hold Count) @endif
      </div>
      <table class="min-w-full text-sm">
        <thead class="bg-gray-50">
          <tr>
            <th class="text-left p-2 border-b">
              @if($group === 'item') Item
              @elseif($group === 'page') Page
              @else Item Name @endif
            </th>
            <th class="text-right p-2 border-b">
              @if($group === 'item') Needed Units
              @else Hold Count @endif
            </th>
          </tr>
        </thead>
        <tbody>
          @if($group === 'item')
            @forelse($byItem as $row)
              <tr class="odd:bg-white even:bg-gray-50">
                <td class="p-2 border-b">{{ $row->item ?? '—' }}</td>
                <td class="p-2 border-b text-right font-semibold">{{ number_format($row->need_units) }}</td>
              </tr>
            @empty
              <tr><td class="p-2 text-gray-500" colspan="2">No results.</td></tr>
            @endforelse
          @elseif($group === 'page')
            @forelse($byItem as $row)
              <tr class="odd:bg-white even:bg-gray-50">
                <td class="p-2 border-b">{{ $row->page ?? '—' }}</td>
                <td class="p-2 border-b text-right font-semibold">{{ number_format($row->hold_count) }}</td>
              </tr>
            @empty
              <tr><td class="p-2 text-gray-500" colspan="2">No results.</td></tr>
            @endforelse
          @else
            @forelse($byItem as $row)
              <tr class="odd:bg-white even:bg-gray-50">
                <td class="p-2 border-b">{{ $row->item_name ?? '—' }}</td>
                <td class="p-2 border-b text-right font-semibold">{{ number_format($row->hold_count) }}</td>
              </tr>
            @empty
              <tr><td class="p-2 text-gray-500" colspan="2">No results.</td></tr>
            @endforelse
          @endif
        </tbody>
      </table>
    </div>
  @endif

  {{-- Auto-apply behavior --}}
  <script>
  (function() {
    const form = document.getElementById('filtersForm');
    const qInput = document.getElementById('q');
    const includeBlank = document.getElementById('include_blank');
    const perDate = document.getElementById('per_date');
    const groupSel = document.getElementById('group');

    let t;
    const submitDebounced = (ms = 400) => { clearTimeout(t); t = setTimeout(() => form.submit(), ms); };

    // Auto-apply for non-date controls
    qInput       && qInput.addEventListener('input', () => submitDebounced(400));
    includeBlank && includeBlank.addEventListener('change', () => form.submit());
    perDate      && perDate.addEventListener('change', () => form.submit());
    groupSel     && groupSel.addEventListener('change', () => form.submit());

    // Date picker
    const defaultDates = @json(($rangeSta && $rangeEnd) ? [$rangeSta, $rangeEnd] : ($uiDate ? [$uiDate, $uiDate] : []));
    flatpickr('#date_range', {
      mode: 'range',
      dateFormat: 'Y-m-d',
      allowInput: true,
      defaultDate: defaultDates,
      clickOpens: true,
      closeOnSelect: true,   // <-- auto-close after second selection
      disableMobile: true,   // optional: consistent UI on mobile

      // Submit ONLY when:
      // 1) Range is complete (2 clicks, or double-click same day), OR
      // 2) User closes the picker with just one date (single-day filter), OR
      // 3) Cleared field then closed.
      onChange: function(selectedDates) {
        if (selectedDates && selectedDates.length === 2) {
          // Includes the double-click same-day case (start=end)
          form.submit();
        }
      },
      onClose: function(selectedDates, dateStr, instance) {
        const val = (instance.input.value || '').trim();

        if (val === '' || !selectedDates || selectedDates.length === 0) {
          form.submit(); // cleared
          return;
        }
        if (selectedDates.length === 1) {
          form.submit(); // single-day (user closed manually)
        }
      }
    });

    // NOTE: No 'input/change/blur' listeners on #date_range to avoid early submits.
  })();
</script>



</x-layout>

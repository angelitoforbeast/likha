<x-layout>
  <x-slot name="title">Hold</x-slot>
  <x-slot name="heading">JNT Hold Items</x-slot>

  @once
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <style>
      [x-cloak]{display:none!important}
      th, td { vertical-align: middle; }
    </style>
  @endonce

  <!-- Filters -->
  <form id="filtersForm" method="get" class="bg-white p-4 rounded-xl shadow mb-6 grid md:grid-cols-9 gap-4">
    <div class="md:col-span-2">
      <label class="block text-sm font-medium text-gray-700 mb-1">Search</label>
      <input id="q" type="text" name="q" value="{{ $q }}" placeholder="Search item/page/waybill..."
             class="w-full border rounded-lg px-3 py-2" autocomplete="off" />
    </div>

    <div class="md:col-span-2">
      <label class="block text-sm font-medium text-gray-700 mb-1">Date range</label>
      <input id="date_range" type="text" name="date_range" placeholder="YYYY-MM-DD to YYYY-MM-DD"
             value="{{ $rangeSta && $rangeEnd ? ($rangeSta.' to '.$rangeEnd) : ($uiDate ? ($uiDate.' to '.$uiDate) : ($dateRange ?? '')) }}"
             class="w-full border rounded-lg px-3 py-2" autocomplete="off"/>
      <p class="text-xs text-gray-500 mt-1">Single selection = whole day.</p>
    </div>

    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">View by</label>
      <select id="group" name="group" class="w-full border rounded-lg px-3 py-2">
        <option value="item_name" {{ $group === 'item_name' ? 'selected' : '' }}>ITEM NAME (raw)</option>
        <option value="item"      {{ $group === 'item'      ? 'selected' : '' }}>ITEM (units)</option>
        <option value="page"      {{ $group === 'page'      ? 'selected' : '' }}>PAGE</option>
      </select>
      <p class="text-xs text-gray-500 mt-1">ITEM (units) removes quantity prefix and sums needed stock.</p>
    </div>

    <div class="flex items-center gap-2 mt-6 md:mt-7">
      <input type="checkbox" name="include_blank" value="1" id="include_blank" {{ $includeBlank ? 'checked' : '' }} class="h-4 w-4">
      <label for="include_blank" class="text-sm">Include blank/NULL waybills</label>
    </div>

    <div class="flex items-center gap-2 mt-6 md:mt-7">
      <input type="checkbox" name="per_date" value="1" id="per_date" {{ $perDate ? 'checked' : '' }} class="h-4 w-4">
      <label for="per_date" class="text-sm">Display per date (pivot)</label>
    </div>

    <!-- Within N days controls -->
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">Within last (days)</label>
      <input type="number" min="1" max="365" name="lookback_days" value="{{ $lookbackDays ?? 3 }}" class="w-full border rounded-lg px-3 py-2" />
    </div>
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">As of</label>
      <input type="date" name="as_of_date" value="{{ $asOfDateStr ?? now()->toDateString() }}" class="w-full border rounded-lg px-3 py-2" />
      <p class="text-xs text-gray-500 mt-1">Counts from (as-of − N days) to (as-of − 1 day).</p>
    </div>

    <div class="md:col-span-9">
      <a href="{{ route('jnt.hold') }}" class="px-4 py-2 rounded-lg border">Reset</a>
    </div>

    @if($rangeSta && $rangeEnd)
      <div class="md:col-span-9 text-xs text-gray-600">
        <strong>Date range applied:</strong> {{ $rangeSta }} 00:00:00 → {{ $rangeEnd }} 23:59:59
      </div>
    @elseif($uiDate)
      <div class="md:col-span-9 text-xs text-gray-600">
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
        @if($group === 'item') Distinct Base Items
        @elseif($group === 'page') Distinct Pages
        @else Items with Holds (raw) @endif
      </div>
      <div class="text-3xl font-semibold">
        {{ number_format($perDate ? ($pivotRows->count() ?? 0) : ($itemsWithHoldsCount ?? 0)) }}
      </div>
    </div>
  </div>

  @if($perDate)
    <!-- PIVOT TABLE -->
    <div class="bg-white rounded-xl shadow p-4 overflow-auto">
      <div class="flex justify-end mb-2">
        <button type="button" id="copyBtn" class="px-3 py-1.5 text-sm rounded-lg border bg-white hover:bg-gray-50">
          Copy table
        </button>
      </div>

      <div class="text-sm font-medium mb-3">
        @if($group === 'item') By Item (Needed Units) — Per Date
        @elseif($group === 'page') By Page (Hold Count) — Per Date
        @else By Item Name (Hold Count) — Per Date @endif
      </div>

      <table id="holdTable" class="min-w-full text-sm">
        <thead>
          <tr class="bg-gray-50">
            <th class="text-left p-2 border-b align-bottom" rowspan="2" style="position: sticky; left: 0; background: #f9fafb;">
              @if($group === 'item') Item
              @elseif($group === 'page') Page
              @else Item Name @endif
            </th>
            @foreach(($monthGroups ?? []) as $m)
              <th class="text-center p-2 border-b whitespace-nowrap" colspan="{{ $m['count'] ?? 0 }}">{{ $m['label'] ?? '' }}</th>
            @endforeach
            <th class="text-right p-2 border-b align-bottom" rowspan="2">Within {{ $lookbackDays }}d</th>
            <th class="text-right p-2 border-b align-bottom" rowspan="2">Total</th>
          </tr>
          <tr class="bg-gray-50">
            @foreach($dateKeys as $dk)
              <th class="text-right p-2 border-b whitespace-nowrap">{{ $dayLabels[$dk] ?? '' }}</th>
            @endforeach
          </tr>
        </thead>

        <tbody>
          @forelse($pivotRows as $row)
            <tr class="odd:bg-white even:bg-gray-50">
              <td class="p-2 border-b" style="position: sticky; left: 0; background: white;">{{ $row['label'] ?? '—' }}</td>
              @foreach($dateKeys as $dk)
                @php $v = isset($row['dates'][$dk]) ? (int)$row['dates'][$dk] : 0; @endphp
                <td class="p-2 border-b text-right">{{ $v ? number_format($v) : '' }}</td>
              @endforeach
              <td class="p-2 border-b text-right">{{ number_format($recentMap[$row['label'] ?? ''] ?? 0) }}</td>
              <td class="p-2 border-b text-right font-semibold">{{ number_format($row['total'] ?? 0) }}</td>
            </tr>
          @empty
            <tr><td class="p-2 text-gray-500" colspan="{{ 3 + count($dateKeys) }}">No results.</td></tr>
          @endforelse
        </tbody>

        <tfoot class="bg-gray-50">
          <tr>
            <th class="text-left p-2 border-t" style="position: sticky; left: 0; background: #f9fafb;">Totals</th>
            @foreach($dateKeys as $dk)
              @php $cv = (int)($colTotals[$dk] ?? 0); @endphp
              <th class="text-right p-2 border-t">{{ $cv ? number_format($cv) : '' }}</th>
            @endforeach
            <th class="text-right p-2 border-t">{{ number_format($recentGrand ?? 0) }}</th>
            <th class="text-right p-2 border-t">{{ number_format($grandTotal ?? 0) }}</th>
          </tr>
        </tfoot>
      </table>
      <div class="text-xs text-gray-500 mt-2">
        Within {{ $lookbackDays }} day(s) as of <strong>{{ $asOfDateStr }}</strong>.
      </div>
    </div>
  @else
    <!-- SIMPLE GROUPED LIST -->
    <div class="bg-white rounded-xl shadow p-4">
      <div class="flex justify-end mb-2">
        <button type="button" id="copyBtn" class="px-3 py-1.5 text-sm rounded-lg border bg-white hover:bg-gray-50">
          Copy table
        </button>
      </div>

      <div class="text-sm font-medium mb-3">
        @if($group === 'item') By Item (Needed Units)
        @elseif($group === 'page') By Page (Hold Count)
        @else By Item Name (Hold Count) @endif
      </div>

      <table id="holdTable" class="min-w-full text-sm">
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
            <th class="text-right p-2 border-b">Within {{ $lookbackDays }}d</th>
          </tr>
        </thead>
        <tbody>
          @if($group === 'item')
            @forelse($byItem as $row)
              <tr class="odd:bg-white even:bg-gray-50">
                <td class="p-2 border-b">{{ $row->item ?? '—' }}</td>
                <td class="p-2 border-b text-right font-semibold">{{ number_format($row->need_units) }}</td>
                <td class="p-2 border-b text-right">{{ number_format($recentMap[$row->item ?? ''] ?? 0) }}</td>
              </tr>
            @empty
              <tr><td class="p-2 text-gray-500" colspan="3">No results.</td></tr>
            @endforelse

          @elseif($group === 'page')
            @forelse($byItem as $row)
              <tr class="odd:bg-white even:bg-gray-50">
                <td class="p-2 border-b">{{ $row->page ?? '—' }}</td>
                <td class="p-2 border-b text-right font-semibold">{{ number_format($row->hold_count) }}</td>
                <td class="p-2 border-b text-right">{{ number_format($recentMap[$row->page ?? ''] ?? 0) }}</td>
              </tr>
            @empty
              <tr><td class="p-2 text-gray-500" colspan="3">No results.</td></tr>
            @endforelse

          @else
            @forelse($byItem as $row)
              <tr class="odd:bg-white even:bg-gray-50">
                <td class="p-2 border-b">{{ $row->item_name ?? '—' }}</td>
                <td class="p-2 border-b text-right font-semibold">{{ number_format($row->hold_count) }}</td>
                <td class="p-2 border-b text-right">{{ number_format($recentMap[$row->item_name ?? ''] ?? 0) }}</td>
              </tr>
            @empty
              <tr><td class="p-2 text-gray-500" colspan="3">No results.</td></tr>
            @endforelse
          @endif
        </tbody>
      </table>
      <div class="text-xs text-gray-500 mt-2">
        Within {{ $lookbackDays }} day(s) as of <strong>{{ $asOfDateStr }}</strong>.
      </div>
    </div>
  @endif

  {{-- Auto-apply behavior + Copy-to-Sheets --}}
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

    // Auto-apply for "Within N days" & "As of"
    document.querySelector('input[name="lookback_days"]')?.addEventListener('change', () => form.submit());
    document.querySelector('input[name="as_of_date"]')?.addEventListener('change', () => form.submit());

    // Date range picker: auto-close after second date; submit only on completion/close
    const defaultDates = @json(($rangeSta && $rangeEnd) ? [$rangeSta, $rangeEnd] : ($uiDate ? [$uiDate, $uiDate] : []));
    flatpickr('#date_range', {
      mode: 'range',
      dateFormat: 'Y-m-d',
      allowInput: true,
      defaultDate: defaultDates,
      clickOpens: true,
      closeOnSelect: true,
      disableMobile: true,
      onChange: function(selectedDates) {
        if (selectedDates && selectedDates.length === 2) { form.submit(); }
      },
      onClose: function(selectedDates, dateStr, instance) {
        const val = (instance.input.value || '').trim();
        if (val === '' || !selectedDates || selectedDates.length === 0) { form.submit(); return; }
        if (selectedDates.length === 1) { form.submit(); }
      }
    });

    // ---- Copy-to-Sheets (TSV) ----
    const copyBtn = document.getElementById('copyBtn');
    const holdTable = document.getElementById('holdTable');

    function tableToTSV(tableEl) {
      const rows = [];
      const trList = tableEl.querySelectorAll('thead tr, tbody tr, tfoot tr');
      trList.forEach(tr => {
        const cells = tr.querySelectorAll('th, td');
        const row = [];
        cells.forEach(cell => {
          // visible text; collapse spaces
          let txt = (cell.innerText || '').replace(/\u00A0/g, ' ').replace(/\s+/g, ' ').trim();
          // Remove thousands commas so Sheets treats as number
          if (/^-?\d[\d,]*$/.test(txt)) { txt = txt.replace(/,/g, ''); }
          row.push(txt);
        });
        rows.push(row.join('\t'));
      });
      return rows.join('\n');
    }

    async function copyTable() {
      if (!holdTable) return;
      const tsv = tableToTSV(holdTable);
      try {
        await navigator.clipboard.writeText(tsv);
      } catch (e) {
        const ta = document.createElement('textarea');
        ta.value = tsv; document.body.appendChild(ta);
        ta.select(); document.execCommand('copy');
        document.body.removeChild(ta);
      }
    }

    copyBtn && copyBtn.addEventListener('click', async () => {
      const orig = copyBtn.textContent;
      copyBtn.disabled = true;
      await copyTable();
      copyBtn.textContent = 'Copied!';
      setTimeout(() => { copyBtn.textContent = orig; copyBtn.disabled = false; }, 1000);
    });
  })();
  </script>
</x-layout>

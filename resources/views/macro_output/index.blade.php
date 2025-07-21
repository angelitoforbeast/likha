<x-layout>
  <x-slot name="heading">
    <div class="sticky-header">
      CHECKER 1
    </div>
  </x-slot>

  <style>
    .sticky-header {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      width: 100vw;
      background-color: white;
      z-index: 100;
      padding: 1rem;
      margin: 0;
      box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
    }

    .all-user-input {
      transition: all 0.3s ease;
      max-height: 4.5em;
      overflow: hidden;
      white-space: nowrap;
      text-overflow: ellipsis;
    }

    .all-user-input.expanded {
      white-space: normal;
      overflow: visible;
      text-overflow: initial;
      max-height: none;
    }

    table td,
    table th {
      word-break: break-word;
    }

    textarea {
      overflow: hidden !important;
      resize: none;
      min-height: 3em;
      line-height: 1.2em;
    }
  </style>

  {{-- Filters + Table Head --}}
  <div id="fixed-header" class="fixed top-[64px] left-0 right-0 z-40 bg-white px-4 pt-4 pb-2 shadow">
 {{-- Filters --}}
    
      <form method="GET" action="{{ route('macro_output.index') }}" class="flex items-end gap-4 mb-4 flex-wrap w-full">
  <div>
    <label class="text-sm font-medium">Date</label>
    <input type="date" name="date" value="{{ request('date') }}" class="border rounded px-2 py-1" />
  </div>
  <div>
    <label class="text-sm font-medium">Page</label>
    <select name="PAGE" class="border rounded px-2 py-1">
      <option value="">All</option>
      @foreach($pages as $page)
        <option value="{{ $page }}" @selected(request('PAGE') == $page)> {{ $page }} </option>
      @endforeach
    </select>
     <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Filter</button>
  </div>
  <div class="ml-auto flex gap-2 items-center">
 
  <button type="button" id="validate-btn" class="bg-gray-700 text-white px-4 py-2 rounded hover:bg-gray-800">Validate</button>
  <span id="validate-status" class="text-sm text-gray-600"></span>
  <button type="button" class="bg-gray-700 text-white px-4 py-2 rounded hover:bg-gray-800">Download</button>
</div>





</form>

    </form>

    {{-- Table Head --}}
    
    <table class="table-fixed w-full border text-sm">
      <thead class="bg-gray-100 text-left">
        <tr>
          <th class="border p-2" style="width: 10%">TIMESTAMP</th>
          <th class="border p-2" style="width: 10%">FULL NAME</th>
          <th class="border p-2" style="width: 10%">PHONE NUMBER</th>
          <th class="border p-2" style="width: 10%">ADDRESS</th>
          <th class="border p-2" style="width: 8%">PROVINCE</th>
          <th class="border p-2" style="width: 8%">CITY</th>
          <th class="border p-2" style="width: 8%">BARANGAY</th>
          <th class="border p-2" style="width: 8%">STATUS</th>
          <th class="border p-2" style="width: 20%">CUSTOMER DETAILS</th>
          <th class="border p-2" style="width: 10%">HISTORICAL LOGS</th>
        </tr>
      </thead>
    </table>
  </div>

  {{-- Spacer to prevent overlap --}}
  <div class="h-[0px]"></div>

  {{-- Scrollable Table Body --}}
  <div id="table-body-wrapper" class="px-4">

    <table class="table-fixed w-full border text-sm" id="table-body">
      <tbody>
        @foreach($records as $record)
          @php
            $checker = strtolower($record['APP SCRIPT CHECKER'] ?? '');
            $shouldHighlight = function($field) use ($checker) {
              if (str_contains($checker, 'full address')) {
                return in_array($field, ['PROVINCE', 'CITY', 'BARANGAY']);
              }
              return match($field) {
                'PROVINCE' => str_contains($checker, 'province'),
                'CITY' => str_contains($checker, 'city'),
                'BARANGAY' => str_contains($checker, 'barangay'),
                default => false,
              };
            };
          @endphp
          <tr>
            <td class="border p-2 text-gray-700" style="width: 10%">{{ $record->TIMESTAMP }}</td>

            @foreach(['FULL NAME', 'PHONE NUMBER', 'ADDRESS', 'PROVINCE', 'CITY', 'BARANGAY', 'STATUS'] as $field)
              @php
                $editFlag = match($field) {
                  'FULL NAME' => $record->edited_full_name ?? false,
                  'PHONE NUMBER' => $record->edited_phone_number ?? false,
                  'ADDRESS' => $record->edited_address ?? false,
                  'PROVINCE' => $record->edited_province ?? false,
                  'CITY' => $record->edited_city ?? false,
                  'BARANGAY' => $record->edited_barangay ?? false,
                  default => false
                };

                $finalClass = 'editable-input auto-resize w-full px-2 py-1 border rounded text-sm';
                $customStyle = '';
if ($editFlag) {
  $customStyle = 'background-color: #00ff00;';
} elseif ($shouldHighlight($field)) {
  $finalClass .= ' bg-red-200';
}
 elseif ($shouldHighlight($field)) {
                  $finalClass .= ' bg-red-200';
                }
                $columnWidth = match($field) {
                  'PROVINCE', 'CITY', 'BARANGAY', 'STATUS' => '8%',
                  default => '10%',
                };
              @endphp

              <td class="border p-1 align-top" style="width: {{ $columnWidth }}">
                @if($field === 'STATUS')
                  <select
                    data-id="{{ $record->id }}"
                    data-field="{{ $field }}"
                    class="editable-input w-full px-2 py-1 border rounded text-sm"
                  >
                    <option value="">—</option>
                    @foreach(['PROCEED', 'CANNOT PROCEED', 'ODZ'] as $option)
                      <option value="{{ $option }}" @selected(($record[$field] ?? '') === $option)>
                        {{ $option }}
                      </option>
                    @endforeach
                  </select>
                @else
                  <textarea
  data-id="{{ $record->id }}"
  data-field="{{ $field }}"
  class="{{ $finalClass }}"
  style="{{ $customStyle }}"
  oninput="autoResize(this)"
>{{ $record[$field] ?? '' }}</textarea>

                @endif
              </td>
            @endforeach

            <td class="border p-2 text-gray-700 cursor-pointer all-user-input" style="width: 20%" onclick="expandOnlyOnce(this)">
              {{ $record['all_user_input'] }}
            </td>
            <td class="border p-2 text-gray-700 cursor-pointer all-user-input" style="width: 10%" onclick="expandOnlyOnce(this)">
              {{ $record['HISTORICAL LOGS'] ?? '' }}
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>

  <div class="mt-6 px-4">
    {{ $records->withQueryString()->links() }}
  </div>

  <script>
    function autoResize(el) {
      el.style.height = 'auto';
      el.style.height = el.scrollHeight + 'px';
    }

    document.querySelectorAll('.editable-input').forEach(input => {
      if (input.tagName.toLowerCase() === 'textarea') autoResize(input);

      const handler = function () {
        const id = this.dataset.id;
        const field = this.dataset.field;
        const value = this.value;

        fetch('{{ route("macro_output.update_field") }}', {
          method: 'POST',
          headers: {
            'X-CSRF-TOKEN': '{{ csrf_token() }}',
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({ id, field, value })
        })
        .then(res => res.json())
        .then(data => {
          if (data.status === 'success') {
            this.classList.remove('bg-red-200');
            this.classList.add('bg-green-100');
          } else {
            this.style.backgroundColor = '#fee2e2';
          }
        })
        .catch(() => {
          this.style.backgroundColor = '#fee2e2';
        });
      };

      input.addEventListener('blur', handler);
      input.addEventListener('change', handler);
    });

    function expandOnlyOnce(cell) {
      if (!cell.classList.contains('expanded')) {
        cell.classList.add('expanded');
      }
    }
  </script>
  <script>
  function adjustTableBodyMargin() {
    const header = document.getElementById('fixed-header');
    const bodyWrapper = document.getElementById('table-body-wrapper');

    if (header && bodyWrapper) {
      const rect = header.getBoundingClientRect();
      const totalHeight = rect.height;
 // full height from top of page
      bodyWrapper.style.marginTop = totalHeight + 'px';
    }
  }

  window.addEventListener('load', adjustTableBodyMargin);
  window.addEventListener('resize', adjustTableBodyMargin);
</script>
<script>
  document.getElementById('validate-btn').addEventListener('click', function () {
    const statusEl = document.getElementById('validate-status');
    statusEl.textContent = 'Validating...';
    statusEl.classList.remove('text-green-600');
    statusEl.classList.add('text-gray-600');

    // Reset all background colors before validating
    document.querySelectorAll('[data-id][data-field]').forEach(input => {
      input.style.backgroundColor = '';
    });

    // Collect only visible IDs
    const ids = Array.from(document.querySelectorAll('[data-id]'))
      .map(el => el.dataset.id)
      .filter((v, i, a) => a.indexOf(v) === i); // unique only

    fetch("{{ route('macro_output.validate') }}", {
      method: 'POST',
      headers: {
        'X-CSRF-TOKEN': '{{ csrf_token() }}',
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({ ids })
    })
    .then(res => res.json())
    .then(results => {
      let errorCount = 0;
      results.forEach(result => {
        if (result.invalid_fields) {
          Object.keys(result.invalid_fields).forEach(field => {
            if (result.invalid_fields[field]) {
              errorCount++;
              const input = document.querySelector(`[data-id="${result.id}"][data-field="${field}"]`);
              if (input) {
                input.style.backgroundColor = '#ff0000';
              }
            }
          });
        }
      });

      statusEl.textContent = errorCount > 0 
        ? `${errorCount} cell(s) with issues`
        : 'All good! ✅';
      statusEl.classList.remove('text-gray-600');
      statusEl.classList.add(errorCount > 0 ? 'text-red-600' : 'text-green-600');
    })
    .catch(() => {
      statusEl.textContent = 'Validation failed.';
      statusEl.classList.remove('text-gray-600');
      statusEl.classList.add('text-red-600');
    });
  });
</script>



</x-layout>

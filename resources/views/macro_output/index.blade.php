<x-layout>
  <x-slot name="heading">
    <div class="sticky-header">
      📋 Macro Output Editor (Checker 1)
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
  <div class="fixed top-[64px] left-0 right-0 z-40 bg-white px-4 pt-4 pb-2 shadow">
    {{-- Filters --}}
    <form method="GET" action="{{ route('macro_output.index') }}" class="flex items-end gap-4 mb-4 flex-wrap">
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
      </div>
      <div>
        <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Filter</button>
      </div>
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
          <th class="border p-2" style="width: 20%">ALL USER INPUT</th>
          <th class="border p-2" style="width: 10%">HISTORICAL LOGS</th>
        </tr>
      </thead>
    </table>
  </div>

  {{-- Spacer to prevent overlap --}}
  <div class="h-[5px]"></div>

  {{-- Scrollable Table Body --}}
  <div class="px-4">
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
                if ($editFlag) {
                  $finalClass .= ' bg-green-100';
                } elseif ($shouldHighlight($field)) {
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
</x-layout>

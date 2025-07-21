<x-layout>
  <x-slot name="heading">📋 Macro Output Editor (Checker 1)</x-slot>

  <style>
    .all-user-input {
      transition: all 0.3s ease;
      max-height: 4.5em; /* approx 3 lines */
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
    .auto-resize {
      height: auto;
      overflow-y: hidden;
      resize: none;
      min-height: 2.5em;
    }
  </style>

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

  {{-- Editable Table --}}
  <div class="overflow-auto">
    <table class="table-auto w-full border text-sm">
      <thead>
        <tr class="bg-gray-100 text-left">
          <th class="border p-2">TIMESTAMP</th>
          @foreach(['FULL NAME', 'PHONE NUMBER', 'ADDRESS', 'PROVINCE', 'CITY', 'BARANGAY', 'STATUS'] as $col)
            <th class="border p-2">{{ $col }}</th>
          @endforeach
          <th class="border p-2">ALL USER INPUT</th>
        </tr>
      </thead>
      <tbody>
        @foreach($records as $record)
          <tr>
            <td class="border p-2 text-gray-700">{{ $record->TIMESTAMP }}</td>

            @foreach(['FULL NAME', 'PHONE NUMBER', 'ADDRESS', 'PROVINCE', 'CITY', 'BARANGAY', 'STATUS'] as $field)
              <td class="border p-1 align-top">
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
                    class="editable-input auto-resize w-full px-2 py-1 border rounded text-sm"
                    oninput="autoResize(this)"
                  >{{ $record[$field] ?? '' }}</textarea>
                @endif
              </td>
            @endforeach

            <td
              class="border p-2 text-gray-700 cursor-pointer all-user-input max-w-xs"
              onclick="expandOnlyOnce(this)"
              title="Click to expand"
            >
              {{ $record['all_user_input'] }}
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>

  <div class="mt-6">
    {{ $records->withQueryString()->links() }}
  </div>

  {{-- Scripts --}}
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
            this.style.backgroundColor = '#d1fae5';
            setTimeout(() => this.style.backgroundColor = '', 800);
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

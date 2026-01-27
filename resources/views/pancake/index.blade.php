<x-layout>
  <x-slot name="title">Pancake Conversations</x-slot>
  <x-slot name="heading">Pancake Conversations</x-slot>

  @php
    // small helper
    $qs = request()->query();
    $hasFilters = (request('start_date') || request('end_date') || request('pancake_page_id') || request('q') || request('per_page'));
  @endphp

  <div class="max-w-7xl mx-auto mt-6 bg-white shadow-md rounded-lg p-6 space-y-4">

    {{-- Filters --}}
    <form method="GET" action="{{ url('/pancake/index') }}" class="space-y-3">
      <div class="flex flex-col lg:flex-row lg:items-end gap-3">

        <div>
          <label class="block text-xs text-gray-600 mb-1">Start Date (Created)</label>
          <input type="date" name="start_date" value="{{ old('start_date', $startDate ?? request('start_date')) }}"
                 class="border border-gray-300 rounded-md px-3 py-2 text-sm w-52">
        </div>

        <div>
          <label class="block text-xs text-gray-600 mb-1">End Date (Created)</label>
          <input type="date" name="end_date" value="{{ old('end_date', $endDate ?? request('end_date')) }}"
                 class="border border-gray-300 rounded-md px-3 py-2 text-sm w-52">
        </div>

        <div class="min-w-[240px]">
          <label class="block text-xs text-gray-600 mb-1">Page ID</label>

          {{-- Dropdown if you want, but still editable using datalist-like feel --}}
          <select name="pancake_page_id"
                  class="border border-gray-300 rounded-md px-3 py-2 text-sm w-full">
            <option value="">-- All --</option>
            @foreach(($pageIds ?? []) as $pid)
              <option value="{{ $pid }}" @selected(($pageId ?? request('pancake_page_id')) == $pid)>{{ $pid }}</option>
            @endforeach
          </select>

          {{-- If you prefer free text instead of dropdown, replace the select with:
          <input type="text" name="pancake_page_id" value="{{ old('pancake_page_id', $pageId ?? request('pancake_page_id')) }}"
                 placeholder="e.g. 361790427012676"
                 class="border border-gray-300 rounded-md px-3 py-2 text-sm w-full">
          --}}
        </div>

        <div class="flex-1 min-w-[260px]">
          <label class="block text-xs text-gray-600 mb-1">Search (Name / Chat)</label>
          <input type="text" name="q" value="{{ old('q', $search ?? request('q')) }}"
                 placeholder="e.g. Esteve / 'mag order' / phone / etc."
                 class="border border-gray-300 rounded-md px-3 py-2 text-sm w-full">
        </div>

        <div>
          <label class="block text-xs text-gray-600 mb-1">Per Page</label>
          <select name="per_page" class="border border-gray-300 rounded-md px-3 py-2 text-sm w-28">
            @foreach([25,50,100,200] as $n)
              <option value="{{ $n }}" @selected(($perPage ?? (int)request('per_page',50)) === $n)>{{ $n }}</option>
            @endforeach
          </select>
        </div>

        <div class="flex gap-2">
          <button type="submit"
                  class="inline-flex items-center px-4 py-2 rounded-md bg-blue-600 text-white text-sm font-medium hover:bg-blue-700">
            Apply
          </button>

          @if($hasFilters)
            <a href="{{ url('/pancake/index') }}"
               class="inline-flex items-center px-4 py-2 rounded-md bg-gray-100 text-gray-800 text-sm font-medium hover:bg-gray-200">
              Clear
            </a>
          @endif
        </div>
      </div>

      {{-- quick info --}}
      <div class="text-xs text-gray-600">
        Showing <b>{{ $rows->total() }}</b> record(s).
        @if(($startDate ?? '') || ($endDate ?? '') || ($pageId ?? '') || ($search ?? ''))
          Filters:
          @if(($startDate ?? '') !== '') <span class="px-2 py-0.5 rounded bg-gray-100">start: {{ $startDate }}</span> @endif
          @if(($endDate ?? '') !== '') <span class="px-2 py-0.5 rounded bg-gray-100">end: {{ $endDate }}</span> @endif
          @if(($pageId ?? '') !== '') <span class="px-2 py-0.5 rounded bg-gray-100">page: {{ $pageId }}</span> @endif
          @if(($search ?? '') !== '') <span class="px-2 py-0.5 rounded bg-gray-100">q: {{ $search }}</span> @endif
        @endif
      </div>
    </form>

    {{-- Table --}}
    <div class="overflow-x-auto border border-gray-200 rounded-lg">
      <table class="min-w-full text-sm">
        <thead class="bg-gray-50 text-gray-700">
          <tr>
            <th class="px-3 py-2 text-left whitespace-nowrap">ID</th>
            <th class="px-3 py-2 text-left whitespace-nowrap">Page ID</th>
            <th class="px-3 py-2 text-left whitespace-nowrap">Full Name</th>
            <th class="px-3 py-2 text-left">Customers Chat</th>
            <th class="px-3 py-2 text-left whitespace-nowrap">Created At</th>
            <th class="px-3 py-2 text-left whitespace-nowrap">Updated At</th>
          </tr>
        </thead>

        <tbody class="divide-y divide-gray-100">
          @forelse($rows as $r)
            @php
              $chat = (string) ($r->customers_chat ?? '');
              $short = mb_strlen($chat) > 120 ? mb_substr($chat, 0, 120).'…' : $chat;
              $rowKey = 'pc_'.$r->id;
            @endphp

            <tr class="hover:bg-gray-50 align-top">
              <td class="px-3 py-2 whitespace-nowrap text-gray-700">{{ $r->id }}</td>

              <td class="px-3 py-2 whitespace-nowrap">
                <span class="font-mono text-xs bg-gray-100 px-2 py-1 rounded">
                  {{ $r->pancake_page_id }}
                </span>
              </td>

              <td class="px-3 py-2 whitespace-nowrap font-medium text-gray-900">
                {{ $r->full_name }}
              </td>

              <td class="px-3 py-2">
                <div class="space-y-2">
                  <div id="{{ $rowKey }}_short" class="text-gray-800 whitespace-pre-wrap break-words">
                    {{ $short }}
                  </div>

                  <div id="{{ $rowKey }}_full" class="hidden text-gray-800 whitespace-pre-wrap break-words">
                    {{ $chat }}
                  </div>

                  <div class="flex flex-wrap items-center gap-2">
                    @if(mb_strlen($chat) > 120)
                      <button type="button"
                              class="text-xs px-2 py-1 rounded bg-blue-50 text-blue-700 hover:bg-blue-100"
                              onclick="toggleChat('{{ $rowKey }}')">
                        See more
                      </button>
                    @endif

                    <button type="button"
                            class="text-xs px-2 py-1 rounded bg-gray-100 text-gray-800 hover:bg-gray-200"
                            onclick="copyText(@js($chat))">
                      Copy chat
                    </button>

                    <button type="button"
                            class="text-xs px-2 py-1 rounded bg-gray-100 text-gray-800 hover:bg-gray-200"
                            onclick="copyText(@js($r->full_name))">
                      Copy name
                    </button>
                  </div>
                </div>
              </td>

              <td class="px-3 py-2 whitespace-nowrap text-gray-700">
                {{ optional($r->created_at)->format('Y-m-d H:i:s') }}
              </td>

              <td class="px-3 py-2 whitespace-nowrap text-gray-700">
                {{ optional($r->updated_at)->format('Y-m-d H:i:s') }}
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="6" class="px-4 py-10 text-center text-gray-500">
                No records found for the current filters.
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>

    {{-- Pagination --}}
    <div>
      {{ $rows->links() }}
    </div>

  </div>

  <script>
    function toggleChat(rowKey) {
      const shortEl = document.getElementById(rowKey + '_short');
      const fullEl  = document.getElementById(rowKey + '_full');
      if (!shortEl || !fullEl) return;

      const isHidden = fullEl.classList.contains('hidden');
      if (isHidden) {
        shortEl.classList.add('hidden');
        fullEl.classList.remove('hidden');
      } else {
        fullEl.classList.add('hidden');
        shortEl.classList.remove('hidden');
      }
    }

    async function copyText(text) {
      try {
        await navigator.clipboard.writeText(String(text ?? ''));
        // optional tiny feedback
      } catch (e) {
        // fallback
        const ta = document.createElement('textarea');
        ta.value = String(text ?? '');
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
      }
    }
  </script>
</x-layout>

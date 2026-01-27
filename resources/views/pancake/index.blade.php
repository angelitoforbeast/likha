{{-- resources/views/pancake/index.blade.php --}}
<x-layout>
  <x-slot name="title">Pancake Conversations</x-slot>
  <x-slot name="heading">Pancake Conversations</x-slot>

  @php
    $hasFilters = (
      request('start_date') ||
      request('end_date') ||
      request('pancake_page_name') ||
      request('page_q') ||
      request('q') ||
      request('per_page')
    );
  @endphp

  <div class="max-w-7xl mx-auto mt-6 space-y-4">

    {{-- FILTER CARD --}}
    <div class="bg-white shadow-sm rounded-2xl border border-gray-200 p-5">
      <form method="GET" action="{{ url('/pancake/index') }}" class="space-y-4">

        <div class="flex items-center justify-between gap-3 flex-wrap">
          <div class="text-sm text-gray-700">
            <span class="font-semibold">Total:</span> {{ number_format($rows->total()) }} records
            @if($hasFilters)
              <span class="ml-2 text-xs text-gray-500">(filtered)</span>
            @endif
          </div>

          <div class="flex items-center gap-2">
            @if($hasFilters)
              <a href="{{ url('/pancake/index') }}"
                 class="inline-flex items-center px-3 py-2 rounded-lg bg-gray-100 text-gray-800 text-sm font-medium hover:bg-gray-200">
                Clear
              </a>
            @endif

            <button type="submit"
                    class="inline-flex items-center px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700">
              Apply
            </button>
          </div>
        </div>

        {{-- Inputs grid --}}
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-6 gap-3">

          <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Start Date (Created)</label>
            <input type="date" name="start_date"
                   value="{{ old('start_date', $startDate ?? request('start_date')) }}"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-200">
          </div>

          <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">End Date (Created)</label>
            <input type="date" name="end_date"
                   value="{{ old('end_date', $endDate ?? request('end_date')) }}"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-200">
          </div>

          {{-- Exact Page Name (dropdown) --}}
          <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Page (Exact)</label>
            <select name="pancake_page_name"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-200">
              <option value="">All Pages</option>
              @foreach(($pageNames ?? []) as $pn)
                <option value="{{ $pn }}" @selected(($pageName ?? request('pancake_page_name')) == $pn)>
                  {{ $pn }}
                </option>
              @endforeach
            </select>
          </div>

          {{-- Search Page Name (LIKE) --}}
          <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Search Page Name</label>
            <input type="text" name="page_q"
                   value="{{ old('page_q', $pageQ ?? request('page_q')) }}"
                   placeholder="e.g. Vivian"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-200">
          </div>

          <div class="xl:col-span-2">
            <label class="block text-xs font-medium text-gray-600 mb-1">Search (Full Name / Chat)</label>
            <input type="text" name="q"
                   value="{{ old('q', $search ?? request('q')) }}"
                   placeholder="e.g. Reynaldo / address / phone / 'mag order'…"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-200">
          </div>

          <div class="xl:col-span-1">
            <label class="block text-xs font-medium text-gray-600 mb-1">Per Page</label>
            <select name="per_page"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-200">
              @foreach([25,50,100,200] as $n)
                <option value="{{ $n }}" @selected(($perPage ?? (int)request('per_page',50)) === $n)>{{ $n }}</option>
              @endforeach
            </select>
          </div>

        </div>

        {{-- active filters chips --}}
        @if($hasFilters)
          <div class="flex flex-wrap gap-2 pt-1">
            @if(($startDate ?? '') !== '')
              <span class="text-xs px-2 py-1 rounded-full bg-gray-100 text-gray-700">Start: {{ $startDate }}</span>
            @endif
            @if(($endDate ?? '') !== '')
              <span class="text-xs px-2 py-1 rounded-full bg-gray-100 text-gray-700">End: {{ $endDate }}</span>
            @endif
            @if(($pageName ?? '') !== '')
              <span class="text-xs px-2 py-1 rounded-full bg-blue-50 text-blue-700">Page: {{ $pageName }}</span>
            @endif
            @if(($pageQ ?? '') !== '')
              <span class="text-xs px-2 py-1 rounded-full bg-blue-50 text-blue-700">Page search: {{ $pageQ }}</span>
            @endif
            @if(($search ?? '') !== '')
              <span class="text-xs px-2 py-1 rounded-full bg-gray-100 text-gray-700">Q: {{ $search }}</span>
            @endif
          </div>
        @endif

      </form>
    </div>

    {{-- TABLE CARD --}}
    <div class="bg-white shadow-sm rounded-2xl border border-gray-200 overflow-hidden">
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 text-gray-700 sticky top-0 z-10">
            <tr>
              <th class="px-4 py-3 text-left font-semibold w-[90px]">ID</th>
              <th class="px-4 py-3 text-left font-semibold w-[240px]">Page</th>
              <th class="px-4 py-3 text-left font-semibold w-[220px]">Full Name</th>
              <th class="px-4 py-3 text-left font-semibold">Customers Chat</th>
              <th class="px-4 py-3 text-left font-semibold w-[190px]">Created</th>
              <th class="px-4 py-3 text-left font-semibold w-[190px]">Updated</th>
            </tr>
          </thead>

          <tbody class="divide-y divide-gray-100">
            @forelse($rows as $r)
              @php
                $chat = (string) ($r->customers_chat ?? '');
                $short = mb_strlen($chat) > 180 ? mb_substr($chat, 0, 180).'…' : $chat;
                $rowKey = 'pc_'.$r->id;
                $pageNameUi = $r->page?->pancake_page_name;
                $pageIdUi = (string) ($r->pancake_page_id ?? '');
              @endphp

              <tr class="hover:bg-gray-50 align-top">
                <td class="px-4 py-3 text-gray-700 whitespace-nowrap">
                  {{ $r->id }}
                </td>

                <td class="px-4 py-3">
                  <div class="leading-tight">
                    <div class="font-semibold text-gray-900">
                      {{ $pageNameUi ?? 'Unmapped Page' }}
                    </div>
                    <div class="text-xs text-gray-500 font-mono">
                      {{ $pageIdUi }}
                    </div>
                  </div>
                </td>

                <td class="px-4 py-3 font-medium text-gray-900 whitespace-nowrap">
                  {{ $r->full_name }}
                </td>

                <td class="px-4 py-3">
                  <div class="space-y-2">
                    <div id="{{ $rowKey }}_short" class="text-gray-800 whitespace-pre-wrap break-words">
                      {{ $short }}
                    </div>

                    <div id="{{ $rowKey }}_full" class="hidden text-gray-800 whitespace-pre-wrap break-words">
                      {{ $chat }}
                    </div>

                    <div class="flex flex-wrap items-center gap-2 pt-1">
                      @if(mb_strlen($chat) > 180)
                        <button type="button"
                                class="text-xs px-2.5 py-1.5 rounded-lg bg-blue-50 text-blue-700 hover:bg-blue-100"
                                onclick="toggleChat('{{ $rowKey }}')">
                          See more
                        </button>
                      @endif

                      <button type="button"
                              class="text-xs px-2.5 py-1.5 rounded-lg bg-gray-100 text-gray-800 hover:bg-gray-200"
                              onclick="copyText(@js($chat))">
                        Copy chat
                      </button>

                      <button type="button"
                              class="text-xs px-2.5 py-1.5 rounded-lg bg-gray-100 text-gray-800 hover:bg-gray-200"
                              onclick="copyText(@js($r->full_name))">
                        Copy name
                      </button>

                      <button type="button"
                              class="text-xs px-2.5 py-1.5 rounded-lg bg-gray-100 text-gray-800 hover:bg-gray-200"
                              onclick="copyText(@js($pageNameUi ?? ''))">
                        Copy page
                      </button>
                    </div>
                  </div>
                </td>

                <td class="px-4 py-3 text-gray-700 whitespace-nowrap">
                  {{ optional($r->created_at)->format('Y-m-d H:i:s') }}
                </td>

                <td class="px-4 py-3 text-gray-700 whitespace-nowrap">
                  {{ optional($r->updated_at)->format('Y-m-d H:i:s') }}
                </td>
              </tr>
            @empty
              <tr>
                <td colspan="6" class="px-6 py-12 text-center text-gray-500">
                  No records found for the current filters.
                </td>
              </tr>
            @endforelse
          </tbody>
        </table>
      </div>

      {{-- Pagination footer --}}
      <div class="px-4 py-3 border-t border-gray-200">
        {{ $rows->links() }}
      </div>
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
      } catch (e) {
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

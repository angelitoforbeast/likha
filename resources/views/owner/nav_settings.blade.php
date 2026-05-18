<x-layout>
  <x-slot name="heading">Nav Settings</x-slot>

  {{-- SortableJS for drag-to-reorder rows. CDN load is fine for an admin-only page. --}}
  <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>

  <div class="max-w-6xl mx-auto py-4">

    @if($saved)
      <div class="bg-green-50 border border-green-200 text-green-800 rounded-lg p-3 text-sm mb-4">
        ✓ Nav saved (visibility + order). Refresh any page to apply.
      </div>
    @endif

    <div class="bg-white rounded-xl shadow p-4 mb-4">
      <div class="font-semibold text-lg mb-1">Top Nav Visibility + Order</div>
      <p class="text-sm text-gray-500">
        Check which roles can see each navlink. <strong>Reorder</strong> via drag-handle (≡) on the left, or by typing a number directly.
        Lower number = appears first sa top nav. Changes apply on next page load.
      </p>
    </div>

    <form method="POST" action="{{ route('owner.nav-settings.save') }}" id="navSettingsForm">
      @csrf

      <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="bg-gray-50 border-b">
                <th class="px-2 py-2 text-center" style="width:30px;">≡</th>
                <th class="px-2 py-2 text-center" style="width:70px;">Order</th>
                <th class="px-3 py-2 text-left" style="min-width:220px;">Navlink</th>
                <th class="px-3 py-2 text-left" style="min-width:160px;">URL</th>
                @foreach($roles as $role)
                  <th class="px-3 py-2 text-center" style="min-width:100px;">
                    <div class="flex flex-col items-center gap-1">
                      <span class="font-semibold">{{ $role }}</span>
                      <button type="button"
                              class="text-[10px] text-blue-600 hover:underline"
                              onclick="toggleAllForRole(this, '{{ $role }}')"
                              title="Toggle all rows for this role">all</button>
                    </div>
                  </th>
                @endforeach
              </tr>
            </thead>
            <tbody id="navRowsBody">
              @foreach($links as $link)
                <tr class="border-b hover:bg-blue-50" data-link-id="{{ $link->id }}">
                  <td class="px-2 py-2 text-center text-gray-400 cursor-grab drag-handle" title="Drag to reorder">
                    <i class="fa-solid fa-grip-vertical"></i>
                  </td>
                  <td class="px-2 py-2 text-center">
                    <input type="number"
                           name="sort_order[{{ $link->id }}]"
                           value="{{ $link->sort_order }}"
                           min="0" max="9999"
                           class="w-16 text-center border border-gray-300 rounded px-1 py-0.5 text-xs font-mono order-input"
                           onchange="reorderByInput()"
                           title="Type a number to reorder">
                  </td>
                  <td class="px-3 py-2">
                    <div class="flex items-center gap-2">
                      @if($link->icon)
                        <i class="{{ $link->icon }} text-gray-400 w-4 text-center"></i>
                      @endif
                      <span class="font-semibold">{{ $link->label }}</span>
                    </div>
                    <div class="text-[10px] text-gray-400 font-mono">{{ $link->key }}</div>
                  </td>
                  <td class="px-3 py-2 text-xs font-mono text-gray-500">
                    <a href="{{ $link->route_url }}" target="_blank" class="text-blue-600 hover:underline">{{ $link->route_url }}</a>
                  </td>
                  @foreach($roles as $role)
                    @php
                      $isVisible = $visibilityMap[$link->id][$role] ?? false;
                    @endphp
                    <td class="px-3 py-2 text-center">
                      <input type="checkbox"
                             name="visibility[{{ $link->id }}][{{ $role }}]"
                             value="1"
                             data-role="{{ $role }}"
                             {{ $isVisible ? 'checked' : '' }}
                             class="w-4 h-4 cursor-pointer">
                    </td>
                  @endforeach
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      </div>

      <div class="mt-4 flex items-center gap-2">
        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded px-5 py-2 text-sm">
          💾 Save (Visibility + Order)
        </button>
        <a href="{{ route('owner.nav-settings') }}" class="text-sm text-gray-500 hover:underline">Cancel</a>
        <span class="text-xs text-gray-400 ml-3">Tip: drag any row by the ≡ handle or change a number to reorder.</span>
      </div>
    </form>
  </div>

  <script>
    // "all" header link: toggles every checkbox for a given role column.
    function toggleAllForRole(btn, role) {
      const boxes = document.querySelectorAll('input[type=checkbox][data-role="' + role + '"]');
      if (!boxes.length) return;
      let anyUnchecked = false;
      boxes.forEach(b => { if (!b.checked) anyUnchecked = true; });
      boxes.forEach(b => { b.checked = anyUnchecked; });
    }

    // Re-write sort_order inputs in DOM order — call after any drag or number-input change.
    // Uses gap-10 numbering (10, 20, 30...) so user can insert "in between" by typing e.g. 15.
    function syncOrderFromDom() {
      const rows = document.querySelectorAll('#navRowsBody tr');
      rows.forEach((row, i) => {
        const input = row.querySelector('.order-input');
        if (input) input.value = (i + 1) * 10;
      });
    }

    // Triggered when user types a number — re-sort rows in DOM, then renumber.
    function reorderByInput() {
      const rows = Array.from(document.querySelectorAll('#navRowsBody tr'));
      rows.sort((a, b) => {
        const av = Number(a.querySelector('.order-input')?.value ?? 0);
        const bv = Number(b.querySelector('.order-input')?.value ?? 0);
        return av - bv;
      });
      const tbody = document.getElementById('navRowsBody');
      rows.forEach(r => tbody.appendChild(r));
      syncOrderFromDom();
    }

    // Drag-to-reorder via SortableJS, restricted to the drag-handle column.
    document.addEventListener('DOMContentLoaded', () => {
      const tbody = document.getElementById('navRowsBody');
      if (tbody && window.Sortable) {
        Sortable.create(tbody, {
          handle: '.drag-handle',
          animation: 150,
          ghostClass: 'bg-yellow-100',
          onEnd: () => syncOrderFromDom(),
        });
      }
    });
  </script>
</x-layout>

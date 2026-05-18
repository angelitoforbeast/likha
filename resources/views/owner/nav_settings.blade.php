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
                           onchange="reorderByInput(this)"
                           title="Type a number to reorder (tied values: this row wins — old row pushed down)">
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

    {{-- Reset to factory defaults (separate form so it doesn't clash with the
         main save). Wipes ALL visibility + sort_order and restores from
         NavLink::defaultData(). Confirm dialog before submit. --}}
    <form method="POST" action="{{ route('owner.nav-settings.reset') }}" class="mt-6 pt-4 border-t border-gray-200"
          onsubmit="return confirm('⚠ Reset ALL nav settings to defaults?\n\nThis will:\n  • Restore original visibility per role\n  • Restore original sequential order (1, 2, 3...)\n  • Undo every customization you made\n\nProceed?')">
      @csrf
      <div class="flex items-center justify-between">
        <div>
          <div class="text-sm font-semibold text-gray-700">Reset to Defaults</div>
          <div class="text-xs text-gray-500">Restores original visibility + order from the seed catalog. Any customizations will be lost.</div>
        </div>
        <button type="submit" class="bg-amber-500 hover:bg-amber-600 text-white font-semibold rounded px-4 py-2 text-sm">
          ↺ Reset to Defaults
        </button>
      </div>
    </form>

    {{-- Unregistered routes — auto-discovered GET routes not yet sa nav_links.
         Lets admin quickly promote any route to a navlink. New links default to
         CEO-only visibility (admin then grants other roles via the matrix above). --}}
    @if(count($unregisteredRoutes) > 0)
      <div class="mt-6 pt-4 border-t border-gray-200">
        <details>
          <summary class="cursor-pointer select-none flex items-center justify-between hover:bg-gray-50 -mx-2 px-2 py-1 rounded">
            <div>
              <div class="text-sm font-semibold text-gray-700">
                🔎 Unregistered Routes
                <span class="ml-1 inline-block px-1.5 py-0.5 text-[10px] font-bold bg-blue-100 text-blue-700 rounded">{{ count($unregisteredRoutes) }}</span>
              </div>
              <div class="text-xs text-gray-500">GET routes that exist sa app but aren't registered as navlinks. Click to expand and add.</div>
            </div>
            <span class="text-xs text-gray-400">▼ Expand</span>
          </summary>

          <div class="mt-3 bg-gray-50 rounded-lg border border-gray-200 overflow-hidden">
            <table class="w-full text-xs">
              <thead class="bg-gray-100">
                <tr>
                  <th class="px-3 py-2 text-left">URL</th>
                  <th class="px-3 py-2 text-left">Route name</th>
                  <th class="px-3 py-2 text-center" style="width:140px;">Action</th>
                </tr>
              </thead>
              <tbody>
                @foreach($unregisteredRoutes as $r)
                  <tr class="border-t border-gray-200">
                    <td class="px-3 py-2 font-mono">
                      <a href="{{ $r['url'] }}" target="_blank" class="text-blue-600 hover:underline">{{ $r['url'] }}</a>
                    </td>
                    <td class="px-3 py-2 font-mono text-gray-500">{{ $r['name'] ?? '—' }}</td>
                    <td class="px-3 py-2 text-center">
                      <form method="POST" action="{{ route('owner.nav-settings.add-route') }}" class="inline">
                        @csrf
                        <input type="hidden" name="url" value="{{ $r['url'] }}">
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-semibold rounded px-3 py-1"
                                title="Register as nav link (CEO-only by default; grant to other roles via matrix above)">
                          + Add to Nav
                        </button>
                      </form>
                    </td>
                  </tr>
                @endforeach
              </tbody>
            </table>
            <div class="px-3 py-2 text-[11px] text-gray-500 bg-gray-100 border-t border-gray-200">
              Filter: GET routes only · no parameters · excludes api/, telescope/, save/store/data/status endpoints.
              New entries default to CEO-only visibility — grant other roles via the matrix above.
            </div>
          </div>
        </details>
      </div>
    @endif

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

    // Renumber rows in DOM order using gap-10 spacing (10, 20, 30...).
    // ONLY called after drag-reorder — drag has no specific numeric target,
    // so we assign clean gaps. Number input does NOT call this (keeps user's
    // typed values exactly as entered, only re-sorting the DOM).
    function renumberAfterDrag() {
      const rows = document.querySelectorAll('#navRowsBody tr');
      rows.forEach((row, i) => {
        const input = row.querySelector('.order-input');
        if (input) input.value = (i + 1) * 10;
      });
    }

    // Triggered when user types a number — re-sort rows in DOM by typed value,
    // KEEPING the user's exact values. No renumber (no times-10 surprise).
    // Tie-break: just-changed row WINS (placed BEFORE any other row with the
    // same value). User intent: typing "37" on a row na originally 39 means
    // "this row IS rank 37"; if another row already has 37, push it down by 1.
    function reorderByInput(changedInput) {
      const changedRow = changedInput ? changedInput.closest('tr') : null;
      const changedVal = changedInput ? Number(changedInput.value) : null;

      // Bump other rows with the same value by +1 so the changed row "claims" its slot
      // cleanly and the displaced row doesn't disappear into ambiguous tie ordering.
      // E.g., row A typed=37, row B already=37 → row B becomes 38, row C 38→39, etc.
      // Only bumps the contiguous chain that's at the exact tied value.
      if (changedRow && Number.isFinite(changedVal)) {
        const rowsAll = Array.from(document.querySelectorAll('#navRowsBody tr .order-input'));
        let cascadeAt = changedVal;
        rowsAll.forEach(inp => {
          if (inp === changedInput) return;
          if (Number(inp.value) === cascadeAt) {
            inp.value = cascadeAt + 1;
            cascadeAt = cascadeAt + 1; // next collision target
          }
        });
      }

      // Re-sort DOM by current values.
      const rows = Array.from(document.querySelectorAll('#navRowsBody tr'));
      rows.sort((a, b) => {
        const av = Number(a.querySelector('.order-input')?.value ?? 0);
        const bv = Number(b.querySelector('.order-input')?.value ?? 0);
        if (av !== bv) return av - bv;
        if (changedRow === a) return -1;
        if (changedRow === b) return  1;
        return 0;
      });
      const tbody = document.getElementById('navRowsBody');
      rows.forEach(r => tbody.appendChild(r));
      // NOTE: no renumber — user's typed values stay as-is.
    }

    // Drag-to-reorder via SortableJS, restricted to the drag-handle column.
    // Drag does renumber (clean 10/20/30 gaps) since drag has no numeric intent.
    document.addEventListener('DOMContentLoaded', () => {
      const tbody = document.getElementById('navRowsBody');
      if (tbody && window.Sortable) {
        Sortable.create(tbody, {
          handle: '.drag-handle',
          animation: 150,
          ghostClass: 'bg-yellow-100',
          onEnd: () => renumberAfterDrag(),
        });
      }
    });
  </script>
</x-layout>

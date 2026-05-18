<x-layout>
  <x-slot name="heading">Nav Settings</x-slot>

  <div class="max-w-6xl mx-auto py-4">

    @if($saved)
      <div class="bg-green-50 border border-green-200 text-green-800 rounded-lg p-3 text-sm mb-4">
        ✓ Nav visibility saved. Refresh any page to apply.
      </div>
    @endif

    <div class="bg-white rounded-xl shadow p-4 mb-4">
      <div class="font-semibold text-lg mb-1">Top Nav Visibility per Role</div>
      <p class="text-sm text-gray-500">
        Check which roles can see each navlink. Changes apply immediately on next page load.
        Order is fixed (sort by id) — drag-to-reorder may come later.
      </p>
    </div>

    <form method="POST" action="{{ route('owner.nav-settings.save') }}">
      @csrf

      <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="bg-gray-50 border-b">
                <th class="px-3 py-2 text-left sticky left-0 bg-gray-50" style="min-width:220px;">Navlink</th>
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
            <tbody>
              @foreach($links as $link)
                <tr class="border-b hover:bg-blue-50">
                  <td class="px-3 py-2 sticky left-0 bg-white">
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
          💾 Save Visibility
        </button>
        <a href="{{ route('owner.nav-settings') }}" class="text-sm text-gray-500 hover:underline">Cancel</a>
      </div>
    </form>
  </div>

  <script>
    // "all" header link: toggles every checkbox for a given role column.
    // Reads first matching checkbox's state and flips ALL to the opposite.
    function toggleAllForRole(btn, role) {
      const boxes = document.querySelectorAll('input[type=checkbox][data-role="' + role + '"]');
      if (!boxes.length) return;
      // If ANY unchecked → check all; else uncheck all.
      let anyUnchecked = false;
      boxes.forEach(b => { if (!b.checked) anyUnchecked = true; });
      boxes.forEach(b => { b.checked = anyUnchecked; });
    }
  </script>
</x-layout>

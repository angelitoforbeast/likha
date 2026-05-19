<x-layout>
  <x-slot name="heading">Item Type Mappings — Grouped View</x-slot>

  <div class="max-w-6xl mx-auto py-4">

    {{-- Nav back to other modes --}}
    <div class="mb-4 flex items-center gap-3 text-sm">
      <a href="/jnt/item-types" class="text-blue-600 hover:underline">← Bulk Paste Editor</a>
      <span class="text-gray-300">|</span>
      <a href="/jnt/item-types/inline" class="text-blue-600 hover:underline">Inline Editor</a>
      <span class="text-gray-300">|</span>
      <span class="text-gray-700 font-semibold">Grouped View (read-only)</span>
    </div>

    {{-- Header card --}}
    <div class="bg-white rounded-xl shadow p-4 mb-4">
      <div class="font-semibold text-lg mb-1">📋 Grouped by Item Type</div>
      <p class="text-sm text-gray-500">
        Each row = one <code class="bg-gray-100 px-1 py-0.5 rounded text-xs">item_type</code> with ALL its mapped <code class="bg-gray-100 px-1 py-0.5 rounded text-xs">item_names</code>.
        Unmapped items (na nasa macro_output pero walang assigned type) ay nasa baba ng table.
      </p>
      <div class="mt-2 text-xs text-gray-500">
        <strong>{{ count($grouped) }}</strong> item types ·
        <strong>{{ $grouped->sum('variant_count') }}</strong> total mapped items ·
        <strong>{{ count($unmapped) }}</strong> unmapped items
      </div>
    </div>

    {{-- Grouped table --}}
    <div class="bg-white rounded-xl shadow overflow-hidden mb-4">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 border-b">
            <tr>
              <th class="px-3 py-2 text-left" style="width:180px;">Item Type</th>
              <th class="px-3 py-2 text-center" style="width:80px;">Count</th>
              <th class="px-3 py-2 text-left">Item Names</th>
            </tr>
          </thead>
          <tbody>
            @forelse($grouped as $g)
              <tr class="border-b hover:bg-blue-50">
                <td class="px-3 py-2 font-semibold text-indigo-700">{{ $g->item_type }}</td>
                <td class="px-3 py-2 text-center">
                  <span class="inline-block px-2 py-0.5 rounded bg-indigo-100 text-indigo-700 font-mono text-xs">
                    {{ $g->variant_count }}
                  </span>
                </td>
                <td class="px-3 py-2 text-xs">
                  @php
                    // Split by GROUP_CONCAT separator and render each item as a chip
                    $items = explode(' | ', $g->item_names);
                  @endphp
                  <div class="flex flex-wrap gap-1">
                    @foreach($items as $item)
                      <span class="inline-block px-1.5 py-0.5 rounded bg-gray-100 text-gray-700 font-mono">{{ $item }}</span>
                    @endforeach
                  </div>
                </td>
              </tr>
            @empty
              <tr>
                <td colspan="3" class="px-3 py-8 text-center text-gray-400">No item type mappings yet. Go to <a href="/jnt/item-types" class="text-blue-600 hover:underline">Bulk Paste Editor</a> to add.</td>
              </tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>

    {{-- Unmapped items section --}}
    <div class="bg-white rounded-xl shadow overflow-hidden">
      <div class="px-4 py-3 bg-amber-50 border-b border-amber-200">
        <div class="font-semibold text-amber-900">
          ⚠ Unmapped Items
          <span class="ml-1 inline-block px-1.5 py-0.5 text-[10px] font-bold bg-amber-200 text-amber-900 rounded">{{ count($unmapped) }}</span>
        </div>
        <div class="text-xs text-amber-700 mt-0.5">
          Distinct item_names sa <code class="bg-amber-100 px-1 rounded">macro_output</code> na walang entry sa item_type_mappings.
          Add types via <a href="/jnt/item-types" class="text-blue-600 hover:underline">Bulk Paste</a> or <a href="/jnt/item-types/inline" class="text-blue-600 hover:underline">Inline Editor</a>.
        </div>
      </div>
      <div class="p-4">
        @if(count($unmapped) === 0)
          <div class="text-sm text-green-700">✓ All items in macro_output have an assigned item_type.</div>
        @else
          <div class="flex flex-wrap gap-1.5">
            @foreach($unmapped as $name)
              <span class="inline-block px-2 py-1 rounded bg-amber-100 text-amber-900 font-mono text-xs border border-amber-200">{{ $name }}</span>
            @endforeach
          </div>
        @endif
      </div>
    </div>

  </div>
</x-layout>

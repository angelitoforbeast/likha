<x-layout>
  <x-slot name="heading">Item Type Mapping</x-slot>

  <div class="p-4 space-y-4">

    {{-- Alerts --}}
    @if(session('success'))
      <div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg">{{ session('success') }}</div>
    @endif
    @if(session('error'))
      <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg">{{ session('error') }}</div>
    @endif

    {{-- Header + Filters --}}
    <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
      <div>
        <div class="font-semibold text-lg">Item Name → Item Type</div>
        <div class="text-sm text-gray-500">
          Showing unique items from macro_output (last 30 days).
          @if($unmappedCount > 0)
            <span class="ml-2 px-2 py-0.5 bg-amber-100 text-amber-800 rounded-full text-xs font-semibold">
              {{ $unmappedCount }} unmapped
            </span>
          @else
            <span class="ml-2 px-2 py-0.5 bg-green-100 text-green-700 rounded-full text-xs font-semibold">
              All mapped ✓
            </span>
          @endif
        </div>
      </div>

      <form method="GET" action="{{ url('jnt/item-types/inline') }}" class="flex flex-wrap gap-2 items-center">
        <input type="date" name="date_from" value="{{ $dateFromStr }}"
               class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
        <span class="text-gray-400 text-sm">to</span>
        <input type="date" name="date_to" value="{{ $dateToStr }}"
               class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
        <input type="text" name="search" value="{{ $search }}"
               placeholder="Search item name or type..."
               class="border border-gray-300 rounded-lg px-3 py-2 text-sm w-48">
        <button type="submit" class="bg-gray-700 text-white px-4 py-2 rounded-lg text-sm hover:bg-gray-800">Filter</button>
        <a href="{{ url('jnt/item-types/inline') }}" class="bg-gray-100 text-gray-700 px-3 py-2 rounded-lg text-sm hover:bg-gray-200">Reset</a>
      </form>
    </div>

    {{-- Nav to Option 1 --}}
    <div class="flex items-center gap-3 text-sm">
      <a href="{{ url('jnt/item-types') }}" class="text-blue-600 hover:underline">← Option 1: Paste from Sheets</a>
      <span class="text-gray-300">|</span>
      <span class="font-semibold text-gray-800">Option 2: Inline Edit</span>
    </div>

    {{-- Table --}}
    <div class="bg-white border border-gray-200 rounded-xl overflow-x-auto shadow-sm">
      <table class="min-w-full text-sm">
        <thead class="bg-gray-50 text-gray-700">
          <tr>
            <th class="px-4 py-2 text-left font-semibold">Item Name</th>
            <th class="px-4 py-2 text-left font-semibold w-64">Item Type</th>
            <th class="px-4 py-2 text-center font-semibold w-28">Action</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          @forelse($rows as $r)
            @php $mapped = !empty($r->item_type); @endphp
            <tr class="{{ $mapped ? 'bg-white hover:bg-gray-50' : 'bg-amber-50 hover:bg-amber-100' }}">
              <td class="px-4 py-2 font-mono text-xs text-gray-700">{{ $r->item_name }}</td>
              <td class="px-4 py-2"
                  x-data="{
                    val: {{ json_encode($r->item_type ?? '') }},
                    status: '',
                    async save() {
                      if (this.val.trim() === '') return;
                      this.status = 'saving';
                      try {
                        const fd = new FormData();
                        fd.append('_token', '{{ csrf_token() }}');
                        fd.append('item_name', {{ json_encode($r->item_name) }});
                        fd.append('item_type', this.val.trim());
                        const res = await fetch('{{ url('jnt/item-types/save-one') }}', { method: 'POST', body: fd });
                        this.status = res.ok ? 'saved' : 'error';
                      } catch(e) { this.status = 'error'; }
                      setTimeout(() => this.status = '', 2000);
                    }
                  }">
                <div class="flex items-center gap-1.5">
                  <input type="text" x-model="val"
                         @blur="save()"
                         @keydown.enter.prevent="$el.blur()"
                         placeholder="{{ $mapped ? '' : 'Enter item type...' }}"
                         class="border rounded px-2 py-1 text-sm w-full
                                {{ $mapped ? 'border-gray-300' : 'border-amber-400 bg-amber-50' }}">
                  <span class="text-xs whitespace-nowrap w-12 text-center"
                        :class="{ 'text-green-600': status==='saved', 'text-red-500': status==='error', 'text-gray-400': status==='saving' }">
                    <span x-show="status==='saving'">saving…</span>
                    <span x-show="status==='saved'">✓ saved</span>
                    <span x-show="status==='error'">✗ error</span>
                  </span>
                </div>
              </td>
              <td class="px-4 py-2 text-center">
                @if($mapped && $r->mapping_id)
                  <form method="POST" action="{{ url('jnt/item-types/delete/' . $r->mapping_id) }}"
                        onsubmit="return confirm('Delete type mapping for [{{ $r->item_name }}]?')">
                    @csrf
                    <button type="submit" class="text-red-500 hover:underline text-xs">Delete</button>
                  </form>
                @else
                  <span class="text-xs text-amber-600 font-semibold">Unmapped</span>
                @endif
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="3" class="px-4 py-8 text-center text-gray-400">
                No item names found in the selected date range.
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>

    <div class="text-xs text-gray-400">
      Items without a type won't appear in the "Item Type" filter on the
      <a href="{{ url('jnt/waybills/print') }}" class="underline">Waybills Print</a> page.
    </div>

  </div>
</x-layout>

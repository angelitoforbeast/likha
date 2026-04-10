<x-layout>
  <x-slot name="heading">Item Sender Name Mapping</x-slot>

  <div class="p-4 space-y-4">

    {{-- Alerts --}}
    @if(session('success'))
      <div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg">
        {{ session('success') }}
      </div>
    @endif
    @if(session('error'))
      <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg">
        {{ session('error') }}
      </div>
    @endif

    {{-- Header + Search --}}
    <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
      <div>
        <div class="font-semibold text-lg">Page + Item Name → Shop Name</div>
        <div class="text-sm text-gray-500">
          Showing unique combos from macro_output (last 90 days).
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

      <form method="GET" action="{{ url('jnt/item-sender-name') }}" class="flex flex-wrap gap-2 items-center">
        <input type="date" name="date_from" value="{{ $dateFromStr }}"
               class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
        <span class="text-gray-400 text-sm">to</span>
        <input type="date" name="date_to" value="{{ $dateToStr }}"
               class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
        <input type="text" name="search" value="{{ $search }}"
               placeholder="Search page or item..."
               class="border border-gray-300 rounded-lg px-3 py-2 text-sm w-44">
        <button type="submit"
                class="bg-gray-700 text-white px-4 py-2 rounded-lg text-sm hover:bg-gray-800">
          Filter
        </button>
        <a href="{{ url('jnt/item-sender-name') }}"
           class="bg-gray-100 text-gray-700 px-3 py-2 rounded-lg text-sm hover:bg-gray-200">
          Reset
        </a>
      </form>
    </div>

    {{-- Table --}}
    <div class="bg-white border border-gray-200 rounded-xl overflow-x-auto shadow-sm">
      <table class="min-w-full text-sm">
        <thead class="bg-gray-50 text-gray-700">
          <tr>
            <th class="px-4 py-2 text-left font-semibold w-48">Page</th>
            <th class="px-4 py-2 text-left font-semibold">Item Name</th>
            <th class="px-4 py-2 text-left font-semibold w-64">Shop Name</th>
            <th class="px-4 py-2 text-center font-semibold w-28">Action</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          @forelse($rows as $r)
            @php $mapped = !empty($r->sender_name); @endphp
            <tr class="{{ $mapped ? 'bg-white hover:bg-gray-50' : 'bg-amber-50 hover:bg-amber-100' }}">
              <td class="px-4 py-2 font-medium text-gray-800">{{ $r->page }}</td>
              <td class="px-4 py-2 text-gray-700 font-mono text-xs">{{ $r->item_name }}</td>
              <td class="px-4 py-2">
                <form method="POST" action="{{ url('jnt/item-sender-name/save') }}"
                      class="flex gap-1.5 items-center">
                  @csrf
                  <input type="hidden" name="page" value="{{ $r->page }}">
                  <input type="hidden" name="item_name" value="{{ $r->item_name }}">
                  <input type="text" name="sender_name" value="{{ $r->sender_name ?? '' }}"
                         placeholder="{{ $mapped ? '' : 'Enter shop name...' }}"
                         class="border border-gray-300 rounded px-2 py-1 text-sm w-full
                                {{ $mapped ? '' : 'border-amber-400 bg-amber-50' }}">
                  <button type="submit"
                          class="px-2.5 py-1 text-xs font-medium rounded
                                 {{ $mapped ? 'bg-gray-100 hover:bg-gray-200 text-gray-700' : 'bg-amber-500 hover:bg-amber-600 text-white' }}
                                 whitespace-nowrap">
                    {{ $mapped ? 'Update' : 'Save' }}
                  </button>
                </form>
              </td>
              <td class="px-4 py-2 text-center">
                @if($mapped && $r->mapping_id)
                  <form method="POST" action="{{ url('jnt/item-sender-name/delete/' . $r->mapping_id) }}"
                        onsubmit="return confirm('Delete mapping for [{{ $r->page }}] + [{{ $r->item_name }}]?')">
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
              <td colspan="4" class="px-4 py-8 text-center text-gray-400">
                No Page + Item combos found in the last 90 days.
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>

    <div class="text-xs text-gray-400">
      Toggle "Item Sender Mapping" per JNT Account at
      <a href="{{ route('jnt.accounts.index') }}" class="underline">JNT Accounts</a>
      to enable this lookup for specific pages.
    </div>

  </div>
</x-layout>

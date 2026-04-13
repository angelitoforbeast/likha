<x-layout>
  <x-slot name="heading">Item Type Mapping</x-slot>

  <div class="p-4 space-y-5" x-data="itemTypeApp({{ json_encode($existingMap) }})">

    {{-- Alerts --}}
    @if(session('success'))
      <div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg">{{ session('success') }}</div>
    @endif
    @if(session('error'))
      <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg">{{ session('error') }}</div>
    @endif

    {{-- Nav to Option 2 --}}
    <div class="flex items-center gap-3">
      <span class="text-sm font-semibold text-gray-800">Option 1: Paste from Sheets</span>
      <span class="text-gray-300">|</span>
      <a href="{{ url('jnt/item-types/inline') }}" class="text-sm text-blue-600 hover:underline">
        Option 2: Inline Edit →
      </a>
    </div>

    {{-- Paste Form --}}
    <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-4">
      <div class="mb-3">
        <div class="font-semibold text-gray-800">Paste from Google Sheets</div>
        <div class="text-xs text-gray-500 mt-0.5">
          Format: <code class="bg-gray-100 px-1 rounded">ITEM NAME [tab] ITEM TYPE</code>
        </div>
      </div>

      {{-- Textarea --}}
      <textarea x-model="raw" @input="parse()"
                @keydown.tab.prevent="
                  const el = $event.target;
                  const start = el.selectionStart;
                  const end = el.selectionEnd;
                  raw = raw.substring(0, start) + '\t' + raw.substring(end);
                  $nextTick(() => { el.selectionStart = el.selectionEnd = start + 1; });
                  parse();
                "
                rows="8"
                class="w-full border border-gray-300 rounded-lg p-3 text-sm font-mono"
                placeholder="e.g.&#10;mini flashlight&#9;flashlight&#10;2x mini flashlight&#9;flashlight&#10;banana chips 100g&#9;snacks"></textarea>

      {{-- Live Preview --}}
      <div x-show="parsed.length > 0" class="mt-4">
        <div class="text-sm font-semibold text-gray-700 mb-2">
          Preview
          <span class="ml-2 text-xs font-normal text-gray-400">
            (<span x-text="parsed.length"></span> rows —
            <span class="text-green-600" x-text="parsed.filter(r=>r.ok).length"></span> valid,
            <span class="text-orange-500" x-text="parsed.filter(r=>!r.ok && r.conflict).length"></span> conflict,
            <span class="text-red-500" x-text="parsed.filter(r=>!r.ok && !r.conflict).length"></span> invalid)
          </span>
        </div>
        <div class="overflow-x-auto border border-gray-200 rounded-lg">
          <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-gray-600">
              <tr>
                <th class="px-3 py-2 text-left">#</th>
                <th class="px-3 py-2 text-left">Item Name</th>
                <th class="px-3 py-2 text-left">Item Type</th>
                <th class="px-3 py-2 text-left">Status</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <template x-for="(r, i) in parsed" :key="i">
                <tr :class="r.ok ? 'bg-white' : (r.conflict ? 'bg-orange-50' : 'bg-red-50')">
                  <td class="px-3 py-1.5 text-gray-400 text-xs" x-text="i+1"></td>
                  <td class="px-3 py-1.5 font-mono text-xs" x-text="r.itemName || '—'"></td>
                  <td class="px-3 py-1.5 text-gray-800" x-text="r.itemType || '—'"></td>
                  <td class="px-3 py-1.5">
                    <span x-show="r.ok && !r.isUpdate" class="text-xs text-green-600 font-medium">✓ New</span>
                    <span x-show="r.ok && r.isUpdate" class="text-xs text-blue-600 font-medium">↻ Update</span>
                    <span x-show="!r.ok && r.conflict" class="text-xs text-orange-600 font-medium" x-text="r.error"></span>
                    <span x-show="!r.ok && !r.conflict" class="text-xs text-red-500 font-medium" x-text="r.error"></span>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>
      </div>

      {{-- Save Button --}}
      <form method="POST" action="{{ url('jnt/item-types/save') }}" class="mt-4">
        @csrf
        <input type="hidden" name="bulk_data" x-bind:value="raw">
        <button type="submit"
                :disabled="parsed.filter(r=>r.ok).length === 0"
                :class="parsed.filter(r=>r.ok).length > 0
                  ? 'bg-blue-600 hover:bg-blue-700 text-white'
                  : 'bg-gray-200 text-gray-400 cursor-not-allowed'"
                class="px-4 py-2 rounded-lg text-sm font-medium transition">
          Save (<span x-text="parsed.filter(r=>r.ok).length"></span> valid rows)
        </button>
      </form>
    </div>

    {{-- Search + Table --}}
    <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-4">
      <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
        <h2 class="font-semibold text-gray-800">Existing Mappings ({{ $mappings->total() }})</h2>
        <form method="GET" action="{{ url('jnt/item-types') }}" class="flex gap-2">
          <input type="text" name="search" value="{{ $search }}"
                 placeholder="Search item name or type..."
                 class="border border-gray-300 rounded-lg px-3 py-2 text-sm w-64">
          <button type="submit" class="bg-gray-700 text-white px-4 py-2 rounded-lg text-sm hover:bg-gray-800">Search</button>
          @if($search)
            <a href="{{ url('jnt/item-types') }}" class="bg-gray-100 text-gray-700 px-3 py-2 rounded-lg text-sm hover:bg-gray-200">Clear</a>
          @endif
        </form>
      </div>

      <div class="overflow-x-auto border border-gray-200 rounded-lg">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 text-gray-600">
            <tr>
              <th class="px-4 py-2 text-left">Item Name</th>
              <th class="px-4 py-2 text-left">Item Type</th>
              <th class="px-4 py-2 text-left">Added</th>
              <th class="px-4 py-2 text-left">Action</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            @forelse($mappings as $row)
              <tr class="hover:bg-gray-50">
                <td class="px-4 py-2 font-mono text-xs text-gray-700">{{ $row->item_name }}</td>
                <td class="px-4 py-2 font-medium text-gray-800">{{ $row->item_type }}</td>
                <td class="px-4 py-2 text-gray-400 text-xs">{{ \Carbon\Carbon::parse($row->created_at)->diffForHumans() }}</td>
                <td class="px-4 py-2">
                  <form method="POST" action="{{ url('jnt/item-types/delete/' . $row->id) }}"
                        onsubmit="return confirm('Delete mapping for [{{ $row->item_name }}]?')">
                    @csrf
                    <button type="submit" class="text-red-500 hover:underline text-xs">Delete</button>
                  </form>
                </td>
              </tr>
            @empty
              <tr>
                <td colspan="4" class="px-4 py-6 text-center text-gray-400">No mappings found.</td>
              </tr>
            @endforelse
          </tbody>
        </table>
      </div>

      <div class="mt-4">{{ $mappings->withQueryString()->links() }}</div>
    </div>

  </div>

  <script>
    function itemTypeApp(existingMap) {
      return {
        existingMap: existingMap || {},
        raw: '',
        parsed: [],

        parse() {
          const lines = this.raw.split('\n').filter(l => l.trim() !== '');
          const batchMap = {}; // item_name → item_type within this batch

          this.parsed = lines.map(line => {
            const parts = line.split('\t').map(p => p.trim());
            if (parts.length < 2) {
              return { ok: false, conflict: false, itemName: parts[0] || '', itemType: '', error: 'Missing item type column' };
            }

            const [itemName, itemType] = parts;

            if (!itemName) return { ok: false, conflict: false, itemName, itemType, error: 'Missing item name' };
            if (!itemType) return { ok: false, conflict: false, itemName, itemType, error: 'Missing item type' };

            // Check if this item_name is already mapped to a DIFFERENT type in DB
            const existingType = this.existingMap[itemName];
            if (existingType && existingType !== itemType) {
              return { ok: true, conflict: false, isUpdate: true, itemName, itemType,
                       error: `Will update from "${existingType}" → "${itemType}"` };
            }

            // Check intra-batch duplicate
            const batchType = batchMap[itemName];
            if (batchType && batchType !== itemType) {
              return { ok: false, conflict: true, itemName, itemType,
                       error: `Conflict: same item already set to "${batchType}" in this batch` };
            }

            batchMap[itemName] = itemType;
            const isUpdate = !!existingType; // same type, just re-saving
            return { ok: true, conflict: false, isUpdate, itemName, itemType };
          });
        }
      }
    }
  </script>

</x-layout>

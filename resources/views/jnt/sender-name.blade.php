<x-layout>
  <x-slot name="heading">Sender Name Mapping</x-slot>

  <div class="p-4 space-y-5" x-data="senderNameApp({{ $defaultColumns }}, {{ json_encode($shopPageMap) }})" x-init="init()">

    {{-- Alerts --}}
    @if(session('success'))
      <div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg">{{ session('success') }}</div>
    @endif
    @if(session('error'))
      <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg">{{ session('error') }}</div>
    @endif

    {{-- Paste Form --}}
    <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-4">
      <div class="flex items-center justify-between mb-3">
        <div>
          <div class="font-semibold text-gray-800">Paste from Google Sheets</div>
          <div class="text-xs text-gray-500 mt-0.5">
            <span x-show="cols == 2">Format: <code class="bg-gray-100 px-1 rounded">PAGE [tab] SHOP NAME</code></span>
            <span x-show="cols == 3">Format: <code class="bg-gray-100 px-1 rounded">PAGE [tab] ITEM NAME [tab] SHOP NAME</code></span>
          </div>
        </div>

        {{-- Settings Button --}}
        <button @click="showSettings = !showSettings"
                class="flex items-center gap-1.5 text-sm px-3 py-1.5 rounded-lg border border-gray-300 hover:bg-gray-50 text-gray-700">
          ⚙ Settings
        </button>
      </div>

      {{-- Settings Panel --}}
      <div x-show="showSettings" x-transition
           class="mb-4 p-3 bg-gray-50 border border-gray-200 rounded-lg">
        <form method="POST" action="{{ url('jnt/sender-name/settings') }}" class="flex items-center gap-4 flex-wrap">
          @csrf
          <span class="text-sm font-medium text-gray-700">Default columns:</span>
          <label class="flex items-center gap-1.5 text-sm cursor-pointer">
            <input type="radio" name="default_columns" value="2" {{ $defaultColumns == 2 ? 'checked' : '' }}
                   @change="cols = 2" class="accent-blue-600">
            <span>2 columns <span class="text-gray-400">(PAGE + SHOP NAME)</span></span>
          </label>
          <label class="flex items-center gap-1.5 text-sm cursor-pointer">
            <input type="radio" name="default_columns" value="3" {{ $defaultColumns == 3 ? 'checked' : '' }}
                   @change="cols = 3" class="accent-blue-600">
            <span>3 columns <span class="text-gray-400">(PAGE + ITEM NAME + SHOP NAME)</span></span>
          </label>
          <button type="submit"
                  class="px-3 py-1.5 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700">
            Save Setting
          </button>
        </form>
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
                rows="10"
                class="w-full border border-gray-300 rounded-lg p-3 text-sm font-mono"
                :placeholder="cols == 2
                  ? 'e.g.\nAngela Lim\t*ANGELA BEST PICKS\nAlexa Angeles\t*ALEXA SHOP'
                  : 'e.g.\nAngela Lim\t2 x ITEM\t*ANGELA BEST PICKS\nAngela Lim\t4 x ITEM\t*ANGELA SHOP 2'">
      </textarea>

      {{-- Live Preview --}}
      <div x-show="parsed.length > 0" class="mt-4">
        <div class="text-sm font-semibold text-gray-700 mb-2">
          Preview
          <span class="ml-2 text-xs font-normal text-gray-400">
            (<span x-text="parsed.length"></span> rows —
            <span class="text-green-600" x-text="parsed.filter(r=>r.ok).length"></span> valid,
            <span class="text-red-500" x-text="parsed.filter(r=>!r.ok).length"></span> invalid)
          </span>
        </div>
        <div class="overflow-x-auto border border-gray-200 rounded-lg">
          <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-gray-600">
              <tr>
                <th class="px-3 py-2 text-left">#</th>
                <th class="px-3 py-2 text-left">Page</th>
                <th class="px-3 py-2 text-left" x-show="hasItemCol">Item Name</th>
                <th class="px-3 py-2 text-left">Shop Name</th>
                <th class="px-3 py-2 text-left">Status</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <template x-for="(r, i) in parsed" :key="i">
                <tr :class="r.ok ? 'bg-white' : (r.conflict ? 'bg-orange-50' : 'bg-red-50')">
                  <td class="px-3 py-1.5 text-gray-400 text-xs" x-text="i+1"></td>
                  <td class="px-3 py-1.5 font-medium" x-text="r.page || '—'"></td>
                  <td class="px-3 py-1.5 text-gray-600 font-mono text-xs" x-show="hasItemCol" x-text="r.itemName || '(none)'"></td>
                  <td class="px-3 py-1.5 text-gray-800" x-text="r.senderName || '—'"></td>
                  <td class="px-3 py-1.5">
                    <span x-show="r.ok" class="text-xs text-green-600 font-medium">✓ OK</span>
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
      <form method="POST" action="/jnt/sender-name" class="mt-4">
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
        <form method="GET" action="/jnt/sender-name" class="flex gap-2">
          <input type="text" name="search" value="{{ request('search') }}"
                 placeholder="Search page, item, or shop name..."
                 class="border border-gray-300 rounded-lg px-3 py-2 text-sm w-64">
          <button type="submit" class="bg-gray-700 text-white px-4 py-2 rounded-lg text-sm hover:bg-gray-800">Search</button>
          @if(request('search'))
            <a href="/jnt/sender-name" class="bg-gray-100 text-gray-700 px-3 py-2 rounded-lg text-sm hover:bg-gray-200">Clear</a>
          @endif
        </form>
      </div>

      <div class="overflow-x-auto border border-gray-200 rounded-lg">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 text-gray-600">
            <tr>
              <th class="px-4 py-2 text-left">Page</th>
              <th class="px-4 py-2 text-left">Item Name</th>
              <th class="px-4 py-2 text-left">Shop Name</th>
              <th class="px-4 py-2 text-left">Added</th>
              <th class="px-4 py-2 text-left">Action</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            @forelse($mappings as $row)
              <tr class="hover:bg-gray-50">
                <td class="px-4 py-2 font-medium">{{ $row->PAGE }}</td>
                <td class="px-4 py-2 font-mono text-xs text-gray-500">{{ $row->item_name ?? '—' }}</td>
                <td class="px-4 py-2">{{ $row->SENDER_NAME }}</td>
                <td class="px-4 py-2 text-gray-400 text-xs">{{ \Carbon\Carbon::parse($row->created_at)->diffForHumans() }}</td>
                <td class="px-4 py-2">
                  <form method="POST" action="/jnt/sender-name/delete/{{ $row->id }}">
                    @csrf
                    <button type="submit" class="text-red-500 hover:underline text-xs">Delete</button>
                  </form>
                </td>
              </tr>
            @empty
              <tr>
                <td colspan="5" class="px-4 py-6 text-center text-gray-400">No mappings found.</td>
              </tr>
            @endforelse
          </tbody>
        </table>
      </div>

      <div class="mt-4">{{ $mappings->withQueryString()->links() }}</div>
    </div>

  </div>

  <script>
    function senderNameApp(defaultCols, shopPageMap) {
      return {
        cols: defaultCols,
        shopPageMap: shopPageMap || {},
        raw: '',
        parsed: [],
        showSettings: false,

        get hasItemCol() {
          return this.parsed.some(r => r.itemName);
        },

        init() {},

        parse() {
          const lines = this.raw.split('\n').filter(l => l.trim() !== '');
          // Track shop→page within this batch to catch intra-batch conflicts
          const batchMap = {};

          this.parsed = lines.map(line => {
            const parts = line.split('\t');
            const cleaned = parts.map(p => p.trim());
            let page, itemName, senderName;

            if (cleaned.length === 2) {
              [page, senderName] = cleaned;
              itemName = null;
              if (!page) return { ok: false, conflict: false, page, itemName, senderName, error: 'Missing page' };
              if (!senderName) return { ok: false, conflict: false, page, itemName, senderName, error: 'Missing shop name' };
            } else if (cleaned.length >= 3) {
              [page, itemName, senderName] = cleaned;
              if (!page) return { ok: false, conflict: false, page, itemName, senderName, error: 'Missing page' };
              if (!senderName) return { ok: false, conflict: false, page, itemName, senderName, error: 'Missing shop name' };
            } else {
              return { ok: false, conflict: false, page: cleaned[0] || '', itemName: null, senderName: '', error: 'Not enough columns' };
            }

            // Check existing DB map for conflict
            const existingPage = this.shopPageMap[senderName];
            if (existingPage && existingPage !== page) {
              return { ok: false, conflict: true, page, itemName, senderName, error: `Shop already used by "${existingPage}"` };
            }

            // Check intra-batch conflict
            const batchPage = batchMap[senderName];
            if (batchPage && batchPage !== page) {
              return { ok: false, conflict: true, page, itemName, senderName, error: `Shop already used by "${batchPage}" in this batch` };
            }

            // Valid — register in batch map
            batchMap[senderName] = page;
            return { ok: true, conflict: false, page, itemName, senderName };
          });
        }
      }
    }
  </script>

</x-layout>

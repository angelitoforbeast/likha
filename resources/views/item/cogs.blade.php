<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>COGS — Daily Editor</title>
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <script src="https://cdn.tailwindcss.com"></script>
  <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
  <style>
    [x-cloak]{display:none!important}
    /* Sticky first column */
    .sticky-col { position: sticky; left: 0; z-index: 10; }
    /* Editable cell styles */
    td.editable { cursor: text; transition: background 0.15s; }
    td.editable:hover { background: #eff6ff; }
    td.editing { outline: 2px solid #3b82f6; background: #eef2ff !important; }
    td.saved { background: #d1fae5 !important; transition: background 0.3s; }
    td.nonpresent { background: #f9fafb; color: #d1d5db; }
    /* Missing cost highlight */
    .missing-cost { background: #fef2f2 !important; }
    .missing-cost .sticky-col { background: #fef2f2 !important; }
    /* Scrollbar styling */
    .grid-wrapper::-webkit-scrollbar { height: 8px; }
    .grid-wrapper::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
    .grid-wrapper::-webkit-scrollbar-track { background: #f1f5f9; }
  </style>
</head>
<body class="bg-gray-100 text-gray-900" x-data="gridApp('{{ $month }}')" x-cloak>

  <!-- Top bar -->
  <nav class="bg-white border-b sticky top-0 z-40">
    <div class="max-w-full mx-auto px-4">
      <div class="h-16 flex items-center justify-between">
        <div class="flex items-center gap-3">
          <a href="/summary/overall" class="text-gray-400 hover:text-gray-600" title="Back to Summary">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
          </a>
          <div class="font-semibold text-lg">COGS — Daily Editor</div>
        </div>
        <div class="text-sm text-gray-500" x-text="month"></div>
      </div>
    </div>
  </nav>

  <main class="max-w-full mx-auto px-4 py-4 space-y-4">
    <!-- Controls -->
    <section class="bg-white rounded-xl shadow p-4">
      <div class="flex flex-wrap gap-4 items-end">
        <!-- Month picker -->
        <div>
          <label class="block text-sm font-semibold mb-1">Month</label>
          <input type="month" class="border rounded px-3 py-2 text-sm" x-model="month" @change="load()">
        </div>

        <!-- Search -->
        <div class="flex-1 min-w-[200px]">
          <label class="block text-sm font-semibold mb-1">Search Item</label>
          <div class="relative">
            <input type="text" class="w-full border rounded px-3 py-2 pr-8 text-sm" placeholder="Type to search items..."
                   x-model="searchQuery">
            <svg class="absolute right-2 top-2.5 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
          </div>
        </div>

        <!-- Filter toggles -->
        <div class="flex items-center gap-4">
          <label class="flex items-center gap-2 text-sm cursor-pointer">
            <input type="checkbox" class="w-4 h-4 rounded border-gray-300 text-blue-600" x-model="showMissingOnly">
            <span class="text-red-600 font-medium">Show missing cost only</span>
            <span class="bg-red-100 text-red-700 text-xs px-2 py-0.5 rounded-full font-semibold" x-text="missingCount"></span>
          </label>
        </div>
      </div>

      <!-- Info bar -->
      <div class="mt-3 flex flex-wrap gap-4 text-xs text-gray-500">
        <span>Total items: <strong class="text-gray-700" x-text="rows.length"></strong></span>
        <span>Items with cost: <strong class="text-green-600" x-text="rows.length - missingCount"></strong></span>
        <span>Items missing cost: <strong class="text-red-600" x-text="missingCount"></strong></span>
        <span class="ml-auto">Rule: Only dates present in <strong>macro_output</strong> are editable. Missing days carry forward from last known price.</span>
      </div>
    </section>

    <!-- Grid -->
    <section class="bg-white rounded-xl shadow">
      <template x-if="ready">
        <div class="grid-wrapper overflow-auto rounded-xl" style="max-height: calc(100vh - 260px);">
          <table class="text-xs border-collapse">
            <thead class="sticky top-0 z-20">
              <tr class="bg-gray-100 text-gray-600">
                <th class="sticky-col bg-gray-100 px-4 py-3 text-left font-semibold border-b border-r min-w-[260px]">ITEM NAME</th>
                <template x-for="d in days" :key="'h'+d">
                  <th class="px-3 py-3 text-center font-semibold border-b min-w-[72px]" x-text="d"></th>
                </template>
              </tr>
            </thead>
            <tbody>
              <template x-for="r in filteredRows" :key="r.item_name">
                <tr class="border-b hover:bg-gray-50 transition-colors" :class="r._hasMissing ? 'missing-cost' : ''">
                  <td class="sticky-col bg-white px-4 py-2 font-medium border-r whitespace-nowrap"
                      :class="r._hasMissing ? 'bg-red-50 text-red-700' : ''">
                    <div class="flex items-center gap-2">
                      <span x-text="r.item_name"></span>
                      <template x-if="r._hasMissing">
                        <span class="text-red-500 text-[10px] bg-red-100 px-1.5 py-0.5 rounded font-semibold" title="Missing unit cost">NO COST</span>
                      </template>
                    </div>
                  </td>
                  <template x-for="d in days" :key="r.item_name+'-'+d">
                    <td :class="cellClass(r, d)"
                        :contenteditable="r.editable[d] ? 'true' : 'false'"
                        @focus="onFocus($event)"
                        @blur="onBlur($event, r.item_name, d)"
                        @keydown.enter.prevent="commit($event, r.item_name, d)"
                        class="px-3 py-2 text-right"
                        x-text="fmt(r.prices[d])">
                    </td>
                  </template>
                </tr>
              </template>
              <template x-if="filteredRows.length === 0">
                <tr>
                  <td class="px-4 py-8 text-center text-gray-400" :colspan="days.length + 1">
                    <template x-if="searchQuery || showMissingOnly">
                      <span>No items match your filter.</span>
                    </template>
                    <template x-if="!searchQuery && !showMissingOnly">
                      <span>No data for this month.</span>
                    </template>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>
      </template>

      <template x-if="!ready">
        <div class="flex items-center justify-center py-16 text-gray-400">
          <div class="text-center">
            <div class="inline-block border-3 border-gray-200 border-t-blue-500 rounded-full w-8 h-8 animate-spin mb-3" style="border-width:3px;"></div>
            <div class="text-sm">Loading COGS data...</div>
          </div>
        </div>
      </template>
    </section>
  </main>

<script>
function gridApp(initialMonth){
  return {
    month: initialMonth,
    ready: false,
    days: [],
    rows: [],
    searchQuery: '',
    showMissingOnly: false,

    get missingCount(){
      return this.rows.filter(r => r._hasMissing).length;
    },

    get filteredRows(){
      let result = this.rows;
      if (this.searchQuery) {
        const q = this.searchQuery.toLowerCase();
        result = result.filter(r => r.item_name.toLowerCase().includes(q));
      }
      if (this.showMissingOnly) {
        result = result.filter(r => r._hasMissing);
      }
      return result;
    },

    fmt(v){ return (v===null||v===undefined) ? '' : Number(v).toFixed(2); },
    parse(v){ if(v===''||v===null) return null; const n = parseFloat(String(v).replace(/,/g,'')); return isNaN(n)? null : n; },

    cellClass(r, d){
      if (!r.editable[d]) return 'nonpresent';
      if (r.prices[d] === null || r.prices[d] === undefined) return 'editable bg-yellow-50';
      return 'editable';
    },

    async load(){
      this.ready=false;
      const res = await fetch(`{{ route('item.cogs.grid') }}?month=${this.month}`);
      const j = await res.json();
      this.days = Array.from({length: j.days}, (_,i)=>i+1);
      // Mark items that have missing cost (all prices null for editable days)
      this.rows = j.rows.map(r => {
        const editableDays = Object.keys(r.editable).filter(d => r.editable[d]);
        const hasCost = editableDays.some(d => r.prices[d] !== null && r.prices[d] !== undefined);
        r._hasMissing = editableDays.length > 0 && !hasCost;
        return r;
      });
      this.ready=true;
    },
    onFocus(e){ e.target.classList.add('editing'); },
    async onBlur(e, name, day){ e.target.classList.remove('editing'); await this.commit(e, name, day); },
    async commit(e, name, day){
      const val = this.parse(e.target.innerText.trim());
      if (val === null) { e.target.innerText=''; return; }
      if (!e.target.classList.contains('editable')) { e.target.innerText=''; return; }

      const date = new Date(`${this.month}-01`); date.setDate(day);
      const ymd = date.toISOString().slice(0,10);

      const res = await fetch(`{{ route('item.cogs.update') }}`, {
        method:'POST',
        headers: { 'Content-Type':'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
        body: JSON.stringify({ item_name: name, date: ymd, price: val })
      });
      if (res.ok) {
        await this.load();
        e.target.classList.add('saved'); setTimeout(()=>e.target.classList.remove('saved'), 800);
      } else {
        const err = await res.json().catch(()=>({error:'Save failed'}));
        alert(err.error || 'Save failed');
      }
    },
    async init(){ await this.load(); }
  }
}
</script>
</body>
</html>

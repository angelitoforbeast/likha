<!doctype html>
<html lang="en" x-data="campaignsUI()">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Ads Manager–style Campaigns</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="bg-gray-100 text-gray-900">
  <!-- Top bar -->
  <nav class="bg-white border-b sticky top-0 z-40">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="h-16 flex items-center justify-between gap-4">
        <div class="flex items-center gap-2 min-w-0">
          <div class="font-semibold text-lg truncate" x-text="titleForTab()"></div>
          <div class="text-xs text-gray-500 px-2 py-0.5 rounded-full bg-gray-100">Opportunity score: 100</div>
        </div>
        <div class="flex items-center gap-2">
          <button class="inline-flex items-center gap-2 border rounded px-3 py-1.5 text-sm hover:bg-gray-50">
            <span class="i">⏱️</span>
            <span x-text="dateLabel"></span>
          </button>
          <button class="border rounded px-3 py-1.5 text-sm hover:bg-gray-50" @click="exportCsv()">Export</button>
        </div>
      </div>
    </div>
  </nav>

  <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-4">
    <!-- Tabs + Search + Right controls -->
    <div class="flex flex-wrap items-end gap-3">
      <div class="flex items-center gap-2">
        <button :class="tab==='campaigns' ? 'bg-white shadow' : 'bg-gray-100'"
                class="px-3 py-2 rounded text-sm"
                @click="switchTab('campaigns')">Campaigns</button>
        <button :class="tab==='adsets' ? 'bg-white shadow' : 'bg-gray-100'"
                class="px-3 py-2 rounded text-sm"
                @click="switchTab('adsets')">Ad sets</button>
        <button :class="tab==='ads' ? 'bg-white shadow' : 'bg-gray-100'"
                class="px-3 py-2 rounded text-sm"
                @click="switchTab('ads')">Ads</button>
      </div>

      <div class="flex-1 min-w-[280px]">
        <input type="search" x-model.debounce.500ms="filters.q"
               placeholder="Search to filter by name or text"
               class="w-full rounded border px-3 py-2 text-sm bg-white"
               @input="reload()" />
      </div>

      <div class="flex items-center gap-2">
        <input type="date" class="border rounded px-2 py-2 text-sm" x-model="filters.start_date" @change="reload()">
        <input type="date" class="border rounded px-2 py-2 text-sm" x-model="filters.end_date" @change="reload()">
        <select class="border rounded px-2 py-2 text-sm" x-model="filters.page_name" @change="reload()">
          <option value="all">All Pages</option>
          @foreach ($pages as $p)
            <option value="{{ $p }}">{{ $p }}</option>
          @endforeach
        </select>
      </div>
    </div>

    <!-- Table Card -->
    <section class="bg-white border rounded-lg shadow-sm">
      <!-- Scroll container so sticky works -->
      <div class="overflow-auto max-h-[70vh]">
        <table class="min-w-[1000px] w-full text-sm">
          <!-- Sticky HEADER -->
          <thead class="bg-gray-50 sticky top-0 z-30">
            <tr class="text-left text-gray-600">
              <th class="w-16 px-4 py-3">Off / On</th>
              <th class="px-4 py-3">
                <span x-show="tab==='campaigns'">Campaign</span>
                <span x-show="tab==='adsets'">Ad set</span>
                <span x-show="tab==='ads'">Ad</span>
              </th>
              <th class="px-4 py-3 cursor-pointer select-none" @click="toggleSort('spend')">
                <div class="inline-flex items-center gap-1">Amount spent <span x-show="sortBy==='spend'">▼</span></div>
              </th>
              <th class="px-4 py-3 cursor-pointer select-none" @click="toggleSort('cpm_1000')">
                <div class="inline-flex items-center gap-1">CPM (per 1,000) <span x-show="sortBy==='cpm_1000'">▼</span></div>
              </th>
              <th class="px-4 py-3 cursor-pointer select-none" @click="toggleSort('cpm_msg')">
                <div class="inline-flex items-center gap-1">Cost per messaging <span x-show="sortBy==='cpm_msg'">▼</span></div>
              </th>
              <th class="px-4 py-3 cursor-pointer select-none" @click="toggleSort('cpr')">
                <div class="inline-flex items-center gap-1">Cost per result <span x-show="sortBy==='cpr'">▼</span></div>
              </th>
              <th class="px-4 py-3 cursor-pointer select-none" @click="toggleSort('cpp')">
                <div class="inline-flex items-center gap-1">Cost per purchase <span x-show="sortBy==='cpp'">▼</span></div>
              </th>
              <th class="px-4 py-3">Impr.</th>
              <th class="px-4 py-3">Msgs</th>
              <th class="px-4 py-3">Purchases</th>
            </tr>
          </thead>

          <tbody>
            <template x-for="row in rows" :key="rowKey(row)">
              <tr class="border-t hover:bg-gray-50"
                  @click="rowClick(row)"
                  :class="{'cursor-pointer': tab!=='ads'}">
                <td class="px-4 py-3">
                  <span class="inline-flex items-center gap-2">
                    <span class="inline-block w-2.5 h-2.5 rounded-full" :class="row.on ? 'bg-emerald-600' : 'bg-gray-400'"></span>
                    <span x-text="row.on ? 'Active' : 'Off'"></span>
                  </span>
                </td>

                <td class="px-4 py-3">
                  <template x-if="tab==='campaigns'">
                    <div class="font-medium text-blue-700 hover:underline" x-text="row.campaign_name"></div>
                  </template>
                  <template x-if="tab==='adsets'">
                    <div class="font-medium text-blue-700 hover:underline" x-text="row.ad_set_name"></div>
                  </template>
                  <template x-if="tab==='ads'">
                    <div class="font-medium text-blue-700 hover:underline" x-text="row.headline || ('Ad '+row.ad_id)"></div>
                    <div class="text-[11px] text-gray-500" x-text="row.item_name"></div>
                  </template>
                  <div class="text-[11px] text-gray-500" x-text="row.page_name"></div>
                </td>

                <td class="px-4 py-3" x-text="money(row.spend)"></td>
                <td class="px-4 py-3" x-text="row.cpm_1000 != null ? money(row.cpm_1000) : '—'"></td>
                <td class="px-4 py-3" x-text="row.cpm_msg != null ? money(row.cpm_msg) : '—'"></td>
                <td class="px-4 py-3" x-text="row.cpr != null ? money(row.cpr) : '—'"></td>
                <td class="px-4 py-3" x-text="row.cpp != null ? money(row.cpp) : '—'"></td>

                <td class="px-4 py-3" x-text="num(row.impressions)"></td>
                <td class="px-4 py-3" x-text="num(row.messages)"></td>
                <td class="px-4 py-3" x-text="num(row.purchases)"></td>
              </tr>
            </template>

            <!-- Sticky FOOTER totals -->
            <tr class="bg-gray-50 border-t sticky bottom-0 z-20">
              <td class="px-4 py-3"></td>
              <td class="px-4 py-3 text-gray-600">
                <span x-text="`Results from ${rows.length} ${tabLabel()}`"></span>
              </td>
              <td class="px-4 py-3 font-medium" x-text="money(totals.spend ?? 0)"></td>
              <td class="px-4 py-3" x-text="totals.cpm_1000 != null ? money(totals.cpm_1000) : '—'"></td>
              <td class="px-4 py-3" x-text="totals.cpm_msg  != null ? money(totals.cpm_msg)  : '—'"></td>
              <td class="px-4 py-3" x-text="totals.cpr      != null ? money(totals.cpr)      : '—'"></td>
              <td class="px-4 py-3" x-text="totals.cpp      != null ? money(totals.cpp)      : '—'"></td>
              <td class="px-4 py-3" x-text="num(totals.impressions ?? 0)"></td>
              <td class="px-4 py-3" x-text="num(totals.messages ?? 0)"></td>
              <td class="px-4 py-3" x-text="num(totals.purchases ?? 0)"></td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </main>

  <script>
    function campaignsUI() {
      return {
        tab: 'campaigns',
        currentCampaignId: null,
        currentAdSetId: null,
        rows: [],
        totals: {},
        sortBy: 'spend',
        sortDir: 'desc',
        dateLabel: 'This month',
        filters: {
          start_date: '',
          end_date: '',
          page_name: 'all',
          q: '',
          limit: 200,
        },

        titleForTab() { return this.tab === 'campaigns' ? 'Campaigns' : (this.tab === 'adsets' ? 'Ad sets' : 'Ads'); },
        tabLabel()    { return this.tab === 'ads' ? 'ads' : (this.tab === 'adsets' ? 'ad sets' : 'campaigns'); },
        rowKey(r)     { return (r.ad_id || r.ad_set_id || r.campaign_id); },
        money(v){ return `₱${Number(v||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2})}`; },
        num(v){ return Number(v||0).toLocaleString('en-PH'); },

        toggleSort(k){
          if (this.sortBy === k) this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc';
          else { this.sortBy = k; this.sortDir = 'desc'; }
          this.reload();
        },

        switchTab(t){
          this.tab = t;
          this.reload();
        },

        rowClick(row){
          if (this.tab === 'campaigns') {
            this.currentCampaignId = row.campaign_id; this.tab = 'adsets'; this.reload();
          } else if (this.tab === 'adsets') {
            this.currentAdSetId = row.ad_set_id; this.tab = 'ads'; this.reload();
          }
        },

        async reload(){
          const params = new URLSearchParams({
            level: this.tab,
            start_date: this.filters.start_date || '',
            end_date: this.filters.end_date || '',
            page_name: this.filters.page_name || 'all',
            q: this.filters.q || '',
            sort_by: this.sortBy,
            sort_dir: this.sortDir,
            limit: this.filters.limit
          });
          if (this.currentCampaignId && this.tab !== 'campaigns') params.set('campaign_id', this.currentCampaignId);
          if (this.currentAdSetId && this.tab === 'ads') params.set('ad_set_id', this.currentAdSetId);

          const res  = await fetch('{{ route('ads_manager.campaigns.data') }}?'+params.toString());
          const json = await res.json();
          this.rows   = json.rows || [];
          this.totals = json.totals || {};
        },

        exportCsv(){
          const params = new URLSearchParams({
            level: this.tab,
            start_date: this.filters.start_date || '',
            end_date: this.filters.end_date || '',
            page_name: this.filters.page_name || 'all',
            q: this.filters.q || '',
            sort_by: this.sortBy,
            sort_dir: this.sortDir,
            limit: this.filters.limit,
            export: 'csv'
          });
          if (this.currentCampaignId && this.tab !== 'campaigns') params.set('campaign_id', this.currentCampaignId);
          if (this.currentAdSetId && this.tab === 'ads') params.set('ad_set_id', this.currentAdSetId);
          window.location = '{{ route('ads_manager.campaigns.data') }}?'+params.toString();
        },

        async init(){
          const now   = new Date();
          const start = new Date(now.getFullYear(), now.getMonth(), 1);
          this.filters.start_date = start.toISOString().slice(0,10);
          this.filters.end_date   = now.toISOString().slice(0,10);
          await this.reload();
        }
      }
    }
  </script>
</body>
</html>

<!doctype html>
<html lang="en" x-data="campaignsUI()" x-cloak>
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Ads Manager - Likha</title>

  {{-- Tailwind & Alpine --}}
  <script src="https://cdn.tailwindcss.com"></script>
  <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>

  {{-- Flatpickr styles --}}
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
  <style>
    [x-cloak]{display:none!important}
    .flatpickr-calendar{z-index:9999!important}
  </style>
</head>
<body class="bg-gray-100 text-gray-900">
  <!-- Top bar -->
  <nav class="bg-white border-b sticky top-0 z-40">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="h-16 flex items-center justify-between gap-4">
        <div class="flex items-center gap-2 min-w-0">
          <div class="font-semibold text-lg truncate" x-text="titleForTab()"></div>
          <template x-if="selectedCount() > 0">
            <div class="text-xs flex items-center gap-2 px-2 py-0.5 rounded-full bg-blue-50 text-blue-700 border border-blue-200">
              <span x-text="`${selectedCount()} selected`"></span>
              <button class="underline" @click="clearSelection(tab); reload()">Clear</button>
            </div>
          </template>
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
        {{-- Unified single input (one day or range) --}}
        <div class="min-w-[260px]">
          <label class="block text-sm font-semibold mb-1">Date range</label>
          <input id="dateRange" type="text" placeholder="Select date range"
                 class="w-full border border-gray-300 p-2 rounded-md shadow-sm cursor-pointer bg-white" readonly>
        </div>

        <select class="border rounded px-2 py-2 text-sm" x-model="filters.page_name" @change="reload()">
          <option value="all">All Pages</option>
          @foreach(($pages ?? []) as $p)
            <option value="{{ trim($p) }}">{{ trim($p) }}</option>
          @endforeach
        </select>
      </div>
    </div>

    <!-- Table Card -->
    <section class="relative left-1/2 right-1/2 -mx-[50vw] w-screen bg-white shadow-sm border-y rounded-none">
      <div class="px-2 sm:px-3 lg:px-4">
        <div class="overflow-auto max-h-[70vh]">
          <table class="w-full min-w-[1100px] text-sm">
            <!-- Sticky HEADER -->
            <thead class="bg-gray-50 sticky top-0 z-30">
              <tr class="text-left text-gray-600">
                <!-- NEW: Select-all checkbox -->
                <th class="w-10 px-4 py-3">
                  <input type="checkbox"
                         :checked="allChecked()"
                         @change="toggleCheckAll($event)"
                         class="h-4 w-4 rounded border-gray-300">
                </th>
                <th class="w-24 px-4 py-3">Off / On</th>
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
                  <!-- NEW: row checkbox -->
                  <td class="px-4 py-3" @click.stop>
                    <input type="checkbox"
                           :checked="isChecked(row)"
                           @change="onCheck(row, $event.target.checked)"
                           class="h-4 w-4 rounded border-gray-300">
                  </td>

                  <!-- Off / On -->
                  <td class="px-4 py-3">
                    <span class="inline-flex items-center gap-2">
                      <span class="inline-block w-2.5 h-2.5 rounded-full" :class="row.on ? 'bg-emerald-600' : 'bg-gray-400'"></span>
                      <span x-text="row.on ? 'Active' : 'Off'"></span>
                    </span>
                  </td>

                  <!-- Name -->
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

                  <!-- Spend -->
                  <td class="px-4 py-3" x-text="money(row.spend)"></td>

                  <!-- CPM per 1,000 -->
                  <td class="px-4 py-3" x-text="row.cpm_1000 != null ? money(row.cpm_1000) : '—'"></td>

                  <!-- Cost per message -->
                  <td class="px-4 py-3" x-text="row.cpm_msg != null ? money(row.cpm_msg) : '—'"></td>

                  <!-- Cost per result -->
                  <td class="px-4 py-3" x-text="row.cpr != null ? money(row.cpr) : '—'"></td>

                  <!-- Cost per purchase -->
                  <td class="px-4 py-3" x-text="row.cpp != null ? money(row.cpp) : '—'"></td>

                  <!-- Impressions / Messages / Purchases -->
                  <td class="px-4 py-3" x-text="num(row.impressions)"></td>
                  <td class="px-4 py-3" x-text="num(row.messages)"></td>
                  <td class="px-4 py-3" x-text="num(row.purchases)"></td>
                </tr>
              </template>

              <!-- Sticky FOOTER totals -->
              <tr class="bg-gray-50 border-t sticky bottom-0 z-20">
                <td class="px-4 py-3"></td>
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
      </div>
    </section>
  </main>

  {{-- Flatpickr script --}}
  <script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>

  <script>
    function campaignsUI() {
      return {
        tab: 'campaigns',
        currentCampaignId: null,
        currentAdSetId: null,
        rows: [],
        totals: {},
        sortBy: 'default',
        sortDir: 'desc',
        dateLabel: 'This month',
        filters: {
          start_date: '',
          end_date: '',
          page_name: 'all',
          q: '',
          limit: 200,
        },

        // NEW: selections
        selectedCampaignIds: new Set(),
        selectedAdSetIds: new Set(),

        // Helpers
        titleForTab() {
          if (this.tab === 'campaigns') return 'Campaigns';
          if (this.tab === 'adsets') {
            if (this.selectedCampaignIds.size > 0) {
              const n = this.selectedCampaignIds.size;
              return `Ad sets for ${n} Campaign${n>1?'s':''}`;
            }
            if (this.currentCampaignId) return 'Ad sets for 1 Campaign';
            return 'Ad sets';
          }
          if (this.tab === 'ads') {
            if (this.selectedAdSetIds.size > 0) {
              const n = this.selectedAdSetIds.size;
              return `Ads for ${n} Ad set${n>1?'s':''}`;
            }
            if (this.currentAdSetId) return 'Ads for 1 Ad set';
            return 'Ads';
          }
          return 'Ads Manager';
        },
        tabLabel() { return this.tab === 'ads' ? 'ads' : (this.tab === 'adsets' ? 'ad sets' : 'campaigns'); },
        rowKey(r) { return (r.ad_id || r.ad_set_id || r.campaign_id); },
        money(v){ return `₱${Number(v||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2})}`; },
        num(v){ return Number(v||0).toLocaleString('en-PH'); },
        ymd(d){ const p=n=>String(n).padStart(2,'0'); return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate()); },
        monthName(i){ return ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][i]; },
        setDateLabel(){
          if (!this.filters.start_date || !this.filters.end_date) { this.dateLabel = 'Select dates'; return; }
          const s = new Date(this.filters.start_date+'T00:00:00');
          const e = new Date(this.filters.end_date+'T00:00:00');
          const sameDay = s.getTime() === e.getTime();
          const sM = this.monthName(s.getMonth()), eM = this.monthName(e.getMonth());
          if (sameDay) {
            this.dateLabel = `${sM} ${s.getDate()}, ${s.getFullYear()}`;
          } else if (s.getFullYear() === e.getFullYear() && s.getMonth() === e.getMonth()) {
            this.dateLabel = `${sM} ${s.getDate()}–${e.getDate()}, ${s.getFullYear()}`;
          } else {
            this.dateLabel = `${sM} ${s.getDate()}, ${s.getFullYear()} – ${eM} ${e.getDate()}, ${e.getFullYear()}`;
          }
        },

        // NEW: selection helpers
        selectedCount(){
          return this.tab === 'campaigns'
            ? this.selectedCampaignIds.size
            : this.tab === 'adsets'
              ? this.selectedAdSetIds.size
              : 0;
        },
        clearSelection(which = this.tab){
          if (which === 'campaigns') this.selectedCampaignIds = new Set();
          if (which === 'adsets') this.selectedAdSetIds = new Set();
        },
        isChecked(row){
          if (this.tab==='campaigns') return this.selectedCampaignIds.has(row.campaign_id);
          if (this.tab==='adsets')    return this.selectedAdSetIds.has(row.ad_set_id);
          return false;
        },
        onCheck(row, checked){
          const set = this.tab==='campaigns' ? this.selectedCampaignIds : this.selectedAdSetIds;
          const key = this.tab==='campaigns' ? row.campaign_id : row.ad_set_id;
          if (checked) set.add(key); else set.delete(key);
        },
        allChecked(){
          if (!this.rows.length) return false;
          if (this.tab==='campaigns') return this.rows.every(r => this.selectedCampaignIds.has(r.campaign_id));
          if (this.tab==='adsets')    return this.rows.every(r => this.selectedAdSetIds.has(r.ad_set_id));
          return false;
        },
        toggleCheckAll(e){
          const checked = e.target.checked;
          if (this.tab==='campaigns') {
            if (checked) this.rows.forEach(r => this.selectedCampaignIds.add(r.campaign_id));
            else         this.rows.forEach(r => this.selectedCampaignIds.delete(r.campaign_id));
          } else if (this.tab==='adsets') {
            if (checked) this.rows.forEach(r => this.selectedAdSetIds.add(r.ad_set_id));
            else         this.rows.forEach(r => this.selectedAdSetIds.delete(r.ad_set_id));
          }
        },

        // Sorting
        toggleSort(k){
          if (this.sortBy === k) {
            this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc';
          } else {
            this.sortBy = k;
            this.sortDir = (k === 'spend') ? 'desc' : 'asc';
          }
          this.reload();
        },

        // Tab switching (respect selections)
        switchTab(t){
          this.tab = t;

          // if going to Ad sets & there are selected campaigns, ignore single-drill id
          if (t==='adsets' && this.selectedCampaignIds.size > 0) {
            this.currentCampaignId = null;
          }
          // if going to Ads & there are selected ad sets, ignore single-drill id
          if (t==='ads' && this.selectedAdSetIds.size > 0) {
            this.currentAdSetId = null;
          }
          this.reload();
        },

        // Row click drilldown
        rowClick(row){
          if (this.tab === 'campaigns') {
            // if there’s a selection, just go to Ad sets for selection
            if (this.selectedCampaignIds.size > 0) {
              this.currentCampaignId = null;
              this.tab = 'adsets';
              this.reload();
            } else {
              this.currentCampaignId = row.campaign_id;
              this.tab = 'adsets';
              this.reload();
            }
          } else if (this.tab === 'adsets') {
            if (this.selectedAdSetIds.size > 0) {
              this.currentAdSetId = null;
              this.tab = 'ads';
              this.reload();
            } else {
              this.currentAdSetId = row.ad_set_id;
              this.tab = 'ads';
              this.reload();
            }
          }
        },

        // Data loading
        async reload(){
          const level = this.tab === 'campaigns' ? 'campaigns' : (this.tab === 'adsets' ? 'adsets' : 'ads');
          const params = new URLSearchParams({
            level,
            start_date: this.filters.start_date || '',
            end_date: this.filters.end_date || '',
            page_name: this.filters.page_name || 'all',
            q: this.filters.q || '',
            sort_by: this.sortBy,
            sort_dir: this.sortDir,
            limit: this.filters.limit
          });

          // single-drill ids (only when no multi-select)
          if (this.currentCampaignId && level !== 'campaigns' && this.selectedCampaignIds.size === 0) {
            params.set('campaign_id', this.currentCampaignId);
          }
          if (this.currentAdSetId && level === 'ads' && this.selectedAdSetIds.size === 0) {
            params.set('ad_set_id', this.currentAdSetId);
          }

          // multi-select filters (affect child tabs)
          if (level === 'adsets' && this.selectedCampaignIds.size > 0) {
            params.set('campaign_ids', Array.from(this.selectedCampaignIds).join(','));
          }
          if (level === 'ads') {
            if (this.selectedAdSetIds.size > 0) {
              params.set('ad_set_ids', Array.from(this.selectedAdSetIds).join(','));
            } else if (this.selectedCampaignIds.size > 0) {
              // allow jumping Campaigns → Ads directly if needed
              params.set('campaign_ids', Array.from(this.selectedCampaignIds).join(','));
            }
          }

          const res  = await fetch('{{ route('ads_manager.campaigns.data') }}?'+params.toString());
          const json = await res.json();
          this.rows   = json.rows || [];
          this.totals = json.totals || {};
        },

        exportCsv(){
          const level = this.tab === 'campaigns' ? 'campaigns' : (this.tab === 'adsets' ? 'adsets' : 'ads');
          const params = new URLSearchParams({
            level,
            start_date: this.filters.start_date || '',
            end_date: this.filters.end_date || '',
            page_name: this.filters.page_name || 'all',
            q: this.filters.q || '',
            sort_by: this.sortBy,
            sort_dir: this.sortDir,
            limit: this.filters.limit
          });

          if (this.currentCampaignId && level !== 'campaigns' && this.selectedCampaignIds.size === 0) params.set('campaign_id', this.currentCampaignId);
          if (this.currentAdSetId && level === 'ads' && this.selectedAdSetIds.size === 0) params.set('ad_set_id', this.currentAdSetId);

          if (level === 'adsets' && this.selectedCampaignIds.size > 0) {
            params.set('campaign_ids', Array.from(this.selectedCampaignIds).join(','));
          }
          if (level === 'ads') {
            if (this.selectedAdSetIds.size > 0) {
              params.set('ad_set_ids', Array.from(this.selectedAdSetIds).join(','));
            } else if (this.selectedCampaignIds.size > 0) {
              params.set('campaign_ids', Array.from(this.selectedCampaignIds).join(','));
            }
          }

          params.set('export', 'csv');
          window.location = '{{ route('ads_manager.campaigns.data') }}?'+params.toString();
        },

        async init(){
          // Default to this month (from 1st day to today)
          const now   = new Date();
          const start = new Date(now.getFullYear(), now.getMonth(), 1);
          this.filters.start_date = this.ymd(start);
          this.filters.end_date   = this.ymd(now);
          this.setDateLabel();

          // Init Flatpickr after Alpine renders
          this.$nextTick(() => {
            const fp = window.flatpickr || null;
            if (!fp) return;
            fp('#dateRange', {
              mode: 'range',
              dateFormat: 'Y-m-d',
              defaultDate: [this.filters.start_date, this.filters.end_date],
              onClose: (selectedDates, dateStr, instance) => {
                if (selectedDates.length === 2) {
                  const [from, to] = selectedDates;
                  this.filters.start_date = this.ymd(from);
                  this.filters.end_date   = this.ymd(to);
                } else if (selectedDates.length === 1) {
                  const d = selectedDates[0];
                  this.filters.start_date = this.ymd(d);
                  this.filters.end_date   = this.ymd(d);
                } else {
                  return;
                }
                this.setDateLabel();
                this.reload();
              },
              onReady: (selectedDates, dateStr, instance) => {
                instance.input.value = `${this.filters.start_date} to ${this.filters.end_date}`;
              }
            });
          });

          await this.reload();
        }
      }
    }
  </script>
</body>
</html>

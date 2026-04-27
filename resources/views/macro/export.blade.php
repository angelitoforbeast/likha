<x-layout>
  <x-slot name="title">Macro Export</x-slot>
  <x-slot name="heading">Macro Output — Filtered Export</x-slot>

  <style>
    .field-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; }
    .multi-list { max-height: 180px; overflow-y: auto; border:1px solid #d1d5db; border-radius:6px; padding:6px; background:white; }
    .multi-list label { display:flex; align-items:center; gap:6px; padding:2px 4px; font-size:12px; cursor:pointer; }
    .multi-list label:hover { background:#f3f4f6; }
    .tri-state { display:inline-flex; gap:0; border:1px solid #d1d5db; border-radius:6px; overflow:hidden; }
    .tri-state input[type=radio] { display:none; }
    .tri-state label { padding:3px 8px; font-size:11px; cursor:pointer; background:white; }
    .tri-state input[type=radio]:checked + label { background:#2563eb; color:white; font-weight:600; }
    .col-grid { display:grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap:4px 12px; }
    .col-grid label { display:flex; align-items:center; gap:5px; font-size:12px; cursor:pointer; padding:1px 3px; }
    .col-grid label:hover { background:#f3f4f6; border-radius:4px; }
    .section { background:white; border-radius:8px; box-shadow:0 1px 3px rgba(0,0,0,.05); border:1px solid #e5e7eb; padding:16px; margin-bottom:12px; }
    .section h3 { font-size:13px; font-weight:600; color:#374151; text-transform:uppercase; letter-spacing:.04em; margin-bottom:8px; }
    .btn-primary { background:#2563eb; color:white; padding:8px 18px; border-radius:6px; font-weight:600; font-size:13px; }
    .btn-primary:hover { background:#1d4ed8; }
    .btn-primary:disabled { background:#94a3b8; cursor:not-allowed; }
    .btn-secondary { background:white; color:#374151; padding:8px 16px; border-radius:6px; font-weight:600; font-size:13px; border:1px solid #d1d5db; }
    .btn-secondary:hover { background:#f3f4f6; }
    .pill { display:inline-block; background:#e0e7ff; color:#3730a3; padding:2px 8px; border-radius:9999px; font-size:11px; font-weight:600; margin:1px; }
    input[type=text], input[type=date] { padding:5px 8px; border:1px solid #d1d5db; border-radius:6px; font-size:13px; width:100%; box-sizing:border-box; }
    input[type=text]:focus, input[type=date]:focus { outline:none; border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,.15); }
    .field-label { display:block; font-size:11px; font-weight:600; color:#6b7280; text-transform:uppercase; letter-spacing:.04em; margin-bottom:4px; }
    .multi-search { width:100%; padding:4px 8px; border:1px solid #d1d5db; border-radius:6px; font-size:11px; margin-bottom:6px; }
  </style>

  <div class="mx-auto px-4 py-4" style="max-width:1280px;" x-data="macroExport()">

    <div class="section">
      <h3>Date Range (ts_date)</h3>
      <div class="field-grid">
        <div>
          <label class="field-label">Start date</label>
          <input type="date" x-model="filters.start_date">
        </div>
        <div>
          <label class="field-label">End date</label>
          <input type="date" x-model="filters.end_date">
        </div>
      </div>
    </div>

    <div class="section">
      <h3>Page · Item · Status (multi-select)</h3>
      <div class="field-grid">
        <div>
          <label class="field-label">Page <span class="text-gray-400" x-text="filters.pages.length ? '('+filters.pages.length+' selected)' : ''"></span></label>
          <input type="text" class="multi-search" placeholder="🔍 search pages…" x-model="search.pages">
          <div class="multi-list">
            <template x-for="p in distinctPages.filter(x => !search.pages || x.toLowerCase().includes(search.pages.toLowerCase()))" :key="p">
              <label><input type="checkbox" :value="p" x-model="filters.pages"><span x-text="p"></span></label>
            </template>
          </div>
        </div>
        <div>
          <label class="field-label">Item <span class="text-gray-400" x-text="filters.items.length ? '('+filters.items.length+' selected)' : ''"></span></label>
          <input type="text" class="multi-search" placeholder="🔍 search items…" x-model="search.items">
          <div class="multi-list">
            <template x-for="p in distinctItems.filter(x => !search.items || x.toLowerCase().includes(search.items.toLowerCase()))" :key="p">
              <label><input type="checkbox" :value="p" x-model="filters.items"><span x-text="p"></span></label>
            </template>
          </div>
        </div>
        <div>
          <label class="field-label">Status <span class="text-gray-400" x-text="filters.statuses.length ? '('+filters.statuses.length+' selected)' : ''"></span></label>
          <input type="text" class="multi-search" placeholder="🔍 search statuses…" x-model="search.statuses">
          <div class="multi-list">
            <template x-for="p in distinctStatuses.filter(x => !search.statuses || x.toLowerCase().includes(search.statuses.toLowerCase()))" :key="p">
              <label><input type="checkbox" :value="p" x-model="filters.statuses"><span x-text="p"></span></label>
            </template>
          </div>
        </div>
      </div>
    </div>

    <div class="section">
      <h3>Address — Cascading Multi-Select</h3>
      <p class="text-xs text-gray-500 mb-2">
        Pag may sine-select ka sa isang field, mag-aadjust automatic yung ibang fields papunta lang sa mga matching values.
        Bidirectional — pwede mag-start sa Barangay, sa City, o sa Province. Multi-select pa rin per field. Type sa search box para mag-narrow down.
      </p>
      <div class="flex justify-end mb-2">
        <button type="button" class="text-xs text-amber-600 hover:underline"
                @click="filters.provinces=[]; filters.cities=[]; filters.barangays=[];">
          ↻ Clear all address filters
        </button>
      </div>
      <div class="field-grid">
        <div>
          <label class="field-label">
            Province
            <span class="text-gray-400" x-text="filters.provinces.length ? '('+filters.provinces.length+' selected)' : ''"></span>
          </label>
          <input type="text" class="multi-search" placeholder="🔍 search…" x-model="search.provinces">
          <div class="multi-list">
            <template x-for="p in visibleAddressList(cascadedProvinces(), search.provinces, filters.provinces)" :key="p">
              <label><input type="checkbox" :value="p" x-model="filters.provinces"><span x-text="p"></span></label>
            </template>
            <div x-show="addressOverflow(cascadedProvinces(), search.provinces)"
                 class="text-[10px] text-gray-400 italic px-2 py-1">
              … showing first 500. Type to narrow down.
            </div>
          </div>
          <div class="text-[10px] text-gray-500 mt-1"
               x-show="filters.cities.length || filters.barangays.length"
               x-text="'Filtered to ' + cascadedProvinces().length + ' matching province(s)'"></div>
        </div>
        <div>
          <label class="field-label">
            City
            <span class="text-gray-400" x-text="filters.cities.length ? '('+filters.cities.length+' selected)' : ''"></span>
          </label>
          <input type="text" class="multi-search" placeholder="🔍 search…" x-model="search.cities">
          <div class="multi-list">
            <template x-for="p in visibleAddressList(cascadedCities(), search.cities, filters.cities)" :key="p">
              <label><input type="checkbox" :value="p" x-model="filters.cities"><span x-text="p"></span></label>
            </template>
            <div x-show="addressOverflow(cascadedCities(), search.cities)"
                 class="text-[10px] text-gray-400 italic px-2 py-1">
              … showing first 500. Type to narrow down.
            </div>
          </div>
          <div class="text-[10px] text-gray-500 mt-1"
               x-show="filters.provinces.length || filters.barangays.length"
               x-text="'Filtered to ' + cascadedCities().length + ' matching city(ies)'"></div>
        </div>
        <div>
          <label class="field-label">
            Barangay
            <span class="text-gray-400" x-text="filters.barangays.length ? '('+filters.barangays.length+' selected)' : ''"></span>
          </label>
          <input type="text" class="multi-search" placeholder="🔍 search…" x-model="search.barangays">
          <div class="multi-list">
            <template x-for="p in visibleAddressList(cascadedBarangays(), search.barangays, filters.barangays)" :key="p">
              <label><input type="checkbox" :value="p" x-model="filters.barangays"><span x-text="p"></span></label>
            </template>
            <div x-show="addressOverflow(cascadedBarangays(), search.barangays)"
                 class="text-[10px] text-gray-400 italic px-2 py-1">
              … showing first 500. Type to narrow down.
            </div>
          </div>
          <div class="text-[10px] text-gray-500 mt-1"
               x-show="filters.provinces.length || filters.cities.length"
               x-text="'Filtered to ' + cascadedBarangays().length + ' matching barangay(s)'"></div>
        </div>
      </div>
    </div>

    <div class="section">
      <h3>Search · CXD · Waybill</h3>
      <div class="field-grid">
        <div>
          <label class="field-label">Free-text search (Name · Phone · Address · User Input)</label>
          <input type="text" x-model="filters.search" placeholder="any keyword…">
        </div>
        <div>
          <label class="field-label">CXD (contains)</label>
          <input type="text" x-model="filters.cxd" placeholder="CXD value">
        </div>
        <div>
          <label class="field-label">Has waybill?</label>
          <div class="tri-state">
            <input type="radio" id="wb_any" value="any" x-model="filters.waybill"><label for="wb_any">Any</label>
            <input type="radio" id="wb_yes" value="yes" x-model="filters.waybill"><label for="wb_yes">Yes</label>
            <input type="radio" id="wb_no"  value="no"  x-model="filters.waybill"><label for="wb_no">No</label>
          </div>
        </div>
      </div>
    </div>

    <div class="section">
      <h3>Validate Flags</h3>
      <div class="field-grid">
        <template x-for="f in [
          {key:'validate_1',   label:'validate_1'},
          {key:'validate_2',   label:'validate_2'},
          {key:'item_checker', label:'item_checker'},
        ]" :key="f.key">
          <div>
            <label class="field-label" x-text="f.label"></label>
            <div class="tri-state">
              <input type="radio" :id="f.key+'_any'" value="any" x-model="filters[f.key]"><label :for="f.key+'_any'">Any</label>
              <input type="radio" :id="f.key+'_yes'" value="yes" x-model="filters[f.key]"><label :for="f.key+'_yes'">Yes</label>
              <input type="radio" :id="f.key+'_no'"  value="no"  x-model="filters[f.key]"><label :for="f.key+'_no'">No</label>
            </div>
          </div>
        </template>
      </div>
    </div>

    <div class="section">
      <h3>Edit-Tracking Flags</h3>
      <div class="field-grid">
        <template x-for="f in editFlags" :key="f">
          <div>
            <label class="field-label" x-text="f"></label>
            <div class="tri-state">
              <input type="radio" :id="f+'_any'" value="any" x-model="filters[f]"><label :for="f+'_any'">Any</label>
              <input type="radio" :id="f+'_yes'" value="yes" x-model="filters[f]"><label :for="f+'_yes'">Yes</label>
              <input type="radio" :id="f+'_no'"  value="no"  x-model="filters[f]"><label :for="f+'_no'">No</label>
            </div>
          </div>
        </template>
      </div>
    </div>

    <div class="section">
      <div class="flex items-center justify-between mb-2">
        <h3 style="margin-bottom:0;">Columns to Include</h3>
        <div class="flex gap-2">
          <button type="button" class="text-xs text-blue-600 hover:underline" @click="selectAllCols()">Select all</button>
          <button type="button" class="text-xs text-gray-500 hover:underline" @click="clearCols()">Clear</button>
          <button type="button" class="text-xs text-amber-600 hover:underline" @click="defaultCols()">Default (basic)</button>
        </div>
      </div>
      <div class="col-grid">
        <template x-for="c in allColumns" :key="c">
          <label><input type="checkbox" :value="c" x-model="selectedColumns"><span x-text="c"></span></label>
        </template>
      </div>
      <p class="text-xs text-gray-500 mt-2">
        <span x-text="selectedColumns.length"></span> of <span x-text="allColumns.length"></span> columns selected.
      </p>
    </div>

    <div class="section">
      <div class="flex items-center justify-between flex-wrap gap-3">
        <div class="flex items-center gap-3">
          <label class="field-label" style="margin-bottom:0;">Format:</label>
          <div class="tri-state">
            <input type="radio" id="fmt_csv"  value="csv"  x-model="format"><label for="fmt_csv">CSV</label>
            <input type="radio" id="fmt_xlsx" value="xlsx" x-model="format"><label for="fmt_xlsx">XLSX</label>
          </div>
        </div>
        <div class="flex gap-2 items-center">
          <button type="button" class="btn-secondary" @click="previewCount()" :disabled="counting">
            <span x-show="!counting">🔍 Preview row count</span>
            <span x-show="counting">Counting…</span>
          </button>
          <span x-show="lastCount !== null" class="pill">
            <span x-text="Number(lastCount).toLocaleString('en-PH')"></span> rows
          </span>
          <button type="button" class="btn-primary" @click="download()" :disabled="downloading || selectedColumns.length === 0">
            <span x-show="!downloading">⬇ Download</span>
            <span x-show="downloading">Building file…</span>
          </button>
        </div>
      </div>
      <p class="text-xs text-gray-500 mt-2" x-show="selectedColumns.length === 0">
        ⚠ Select at least one column to enable download.
      </p>
    </div>

  </div>

  <script>
  function macroExport() {
    return {
      allColumns:        @json($allColumns),
      distinctPages:     @json($distinctPages),
      distinctItems:     @json($distinctItems),
      distinctStatuses:  @json($distinctStatuses),
      distinctProvinces: @json($distinctProvinces),
      distinctCities:    @json($distinctCities),
      distinctBarangays: @json($distinctBarangays),
      // Distinct (province, city, barangay) triples for cascading filter.
      addressTriples:    @json($addressTriples),
      editFlags: [
        'edited_full_name','edited_phone_number','edited_address',
        'edited_province','edited_city','edited_barangay',
        'edited_cod','edited_item_name',
      ],
      search: { pages:'', items:'', statuses:'', provinces:'', cities:'', barangays:'' },
      filters: {
        start_date:'', end_date:'',
        pages:[], items:[], statuses:[],
        provinces:[], cities:[], barangays:[],
        search:'', cxd:'', waybill:'any',
        validate_1:'any', validate_2:'any', item_checker:'any',
        edited_full_name:'any', edited_phone_number:'any', edited_address:'any',
        edited_province:'any', edited_city:'any', edited_barangay:'any',
        edited_cod:'any', edited_item_name:'any',
      },
      selectedColumns: [],
      format: 'csv',
      lastCount: null,
      counting: false,
      downloading: false,

      init() {
        // Default columns — basic shipping fields. User can override.
        this.defaultCols();
      },

      selectAllCols() { this.selectedColumns = [...this.allColumns]; },
      clearCols()     { this.selectedColumns = []; },
      defaultCols()   {
        this.selectedColumns = [
          'id','ts_date','PAGE','FULL NAME','PHONE NUMBER','ADDRESS',
          'PROVINCE','CITY','BARANGAY','ITEM_NAME','COD','STATUS','waybill',
        ].filter(c => this.allColumns.includes(c));
      },

      // ─── CASCADING ADDRESS FILTERS ────────────────────────────────────
      // Each cascadedXxx() returns the visible options for that field given
      // the current selections in the OTHER two fields. The cascade is
      // bidirectional — picking a Barangay narrows Province + City to only
      // those that contain it; picking a Province narrows City + Barangay,
      // and so on. Selected values are always preserved (never disappear).
      _cascadeCache: null,
      _cascadeCacheKey: '',
      _cascadeCompute() {
        // Memoize across selection changes so a typing burst doesn't re-iterate
        // tens of thousands of triples on every keystroke.
        const key = JSON.stringify([
          this.filters.provinces.slice().sort(),
          this.filters.cities.slice().sort(),
          this.filters.barangays.slice().sort(),
        ]);
        if (key === this._cascadeCacheKey && this._cascadeCache) return this._cascadeCache;

        const provSet = new Set(this.filters.provinces);
        const citySet = new Set(this.filters.cities);
        const brgySet = new Set(this.filters.barangays);
        const hasProv = provSet.size > 0, hasCity = citySet.size > 0, hasBrgy = brgySet.size > 0;

        // For each field's cascaded list, ignore that field's own selections
        // (otherwise picking 1 city would hide all other cities — bad UX).
        const provList = new Set();   // visible provinces given selected city + brgy
        const cityList = new Set();   // visible cities given selected prov + brgy
        const brgyList = new Set();   // visible barangays given selected prov + city

        for (const [p, c, b] of this.addressTriples) {
          // Province visibility — match against city + brgy only.
          if ((!hasCity || citySet.has(c)) && (!hasBrgy || brgySet.has(b))) provList.add(p);
          // City visibility — match against prov + brgy only.
          if ((!hasProv || provSet.has(p)) && (!hasBrgy || brgySet.has(b))) cityList.add(c);
          // Barangay visibility — match against prov + city only.
          if ((!hasProv || provSet.has(p)) && (!hasCity || citySet.has(c))) brgyList.add(b);
        }

        const sortAlpha = arr => [...arr].sort((a, b) => a.localeCompare(b, 'en'));
        const cache = {
          provinces: hasCity || hasBrgy ? sortAlpha(provList) : this.distinctProvinces,
          cities:    hasProv || hasBrgy ? sortAlpha(cityList) : this.distinctCities,
          barangays: hasProv || hasCity ? sortAlpha(brgyList) : this.distinctBarangays,
        };
        this._cascadeCache = cache;
        this._cascadeCacheKey = key;
        return cache;
      },
      cascadedProvinces() { return this._cascadeCompute().provinces; },
      cascadedCities()    { return this._cascadeCompute().cities;    },
      cascadedBarangays() { return this._cascadeCompute().barangays; },

      // For huge address lists (e.g. tens of thousands of barangays), only
      // render up to 500 matching items + always-include the currently-checked
      // ones so they don't disappear when the user types something else.
      ADDRESS_RENDER_LIMIT: 500,
      visibleAddressList(full, query, selected) {
        const q = (query || '').toLowerCase().trim();
        const filtered = q
          ? full.filter(x => x && x.toLowerCase().includes(q))
          : full;
        if (filtered.length <= this.ADDRESS_RENDER_LIMIT) return filtered;
        // Always include selected items even if they don't match the search.
        const head = filtered.slice(0, this.ADDRESS_RENDER_LIMIT);
        const headSet = new Set(head);
        const extras = (selected || []).filter(s => !headSet.has(s));
        return [...head, ...extras];
      },
      addressOverflow(full, query) {
        const q = (query || '').toLowerCase().trim();
        const matchedCount = q ? full.filter(x => x && x.toLowerCase().includes(q)).length : full.length;
        return matchedCount > this.ADDRESS_RENDER_LIMIT;
      },

      // Build a FormData with all current filter values for POST.
      buildPayload() {
        const fd = new FormData();
        fd.append('_token', '{{ csrf_token() }}');
        if (this.filters.start_date) fd.append('start_date', this.filters.start_date);
        if (this.filters.end_date)   fd.append('end_date',   this.filters.end_date);
        this.filters.pages.forEach(v     => fd.append('pages[]',     v));
        this.filters.items.forEach(v     => fd.append('items[]',     v));
        this.filters.statuses.forEach(v  => fd.append('statuses[]',  v));
        this.filters.provinces.forEach(v => fd.append('provinces[]', v));
        this.filters.cities.forEach(v    => fd.append('cities[]',    v));
        this.filters.barangays.forEach(v => fd.append('barangays[]', v));
        ['search','cxd','waybill'].forEach(k => {
          if (this.filters[k] !== '' && this.filters[k] !== 'any') fd.append(k, this.filters[k]);
          else if (k === 'waybill') fd.append(k, 'any');
        });
        ['validate_1','validate_2','item_checker',
         ...this.editFlags].forEach(k => fd.append(k, this.filters[k]));
        this.selectedColumns.forEach(v => fd.append('columns[]', v));
        fd.append('format', this.format);
        return fd;
      },

      async previewCount() {
        this.counting = true;
        this.lastCount = null;
        try {
          const r = await fetch('{{ route('macro.export.count') }}', {
            method: 'POST',
            body:   this.buildPayload(),
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
          });
          const j = await r.json();
          if (j.ok) this.lastCount = j.count;
          else alert('Count failed: ' + (j.error || 'unknown'));
        } catch (e) {
          alert('Count failed: ' + e.message);
        } finally {
          this.counting = false;
        }
      },

      download() {
        if (this.selectedColumns.length === 0) return;
        this.downloading = true;
        // Submit a real form so the browser handles the streamed download.
        const f = document.createElement('form');
        f.method = 'POST';
        f.action = '{{ route('macro.export.download') }}';
        f.style.display = 'none';
        const fd = this.buildPayload();
        for (const [k, v] of fd.entries()) {
          const i = document.createElement('input');
          i.type = 'hidden'; i.name = k; i.value = v;
          f.appendChild(i);
        }
        document.body.appendChild(f);
        f.submit();
        // Re-enable button after a short delay (we don't get a JS callback for downloads).
        setTimeout(() => { this.downloading = false; document.body.removeChild(f); }, 3000);
      },
    };
  }
  </script>
</x-layout>

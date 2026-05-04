<x-layout>
  <x-slot name="title">JNT V2 — Data Browser</x-slot>
  <x-slot name="heading">JNT V2 — DATA BROWSER (from_jnts_2)</x-slot>

  <style>
    .db-card { background:#fff; border:1px solid #e5e7eb; border-radius:10px; box-shadow:0 1px 2px rgba(0,0,0,0.04); }

    /* Toolbar — sticky sa taas */
    .db-toolbar {
      position:sticky; top:64px; z-index:30;
      background:#fff; border-bottom:1px solid #e5e7eb;
      padding:10px 14px; display:flex; gap:10px; flex-wrap:wrap; align-items:center;
    }
    .db-input, .db-select {
      padding:6px 10px; font-size:12.5px; color:#0f172a; background:#fff;
      border:1px solid #cbd5e1; border-radius:6px;
    }
    .db-input:focus, .db-select:focus { outline:none; border-color:#6366f1; box-shadow:0 0 0 3px rgba(99,102,241,0.12); }

    .db-btn { display:inline-flex; align-items:center; gap:5px; background:#4f46e5; color:#fff; font-weight:600; font-size:12px; padding:7px 12px; border-radius:6px; }
    .db-btn:hover { background:#4338ca; }
    .db-btn-ghost { display:inline-flex; align-items:center; gap:4px; background:transparent; color:#475569; font-size:12px; padding:6px 10px; border-radius:6px; border:1px solid #e2e8f0; }
    .db-btn-ghost:hover { background:#f1f5f9; }

    /* Table — GSheet style: dense rows, sticky header */
    .db-table-wrap {
      max-height:calc(100vh - 200px); overflow:auto;
      border:1px solid #e5e7eb; border-radius:8px; background:#fff;
    }
    .db-table { width:100%; border-collapse:separate; border-spacing:0; font-size:12px; }
    .db-table thead th {
      position:sticky; top:0; z-index:5;
      background:#f8fafc; color:#334155; font-weight:600;
      padding:8px 10px; text-align:left; white-space:nowrap;
      border-bottom:2px solid #cbd5e1; user-select:none;
    }
    .db-table thead th.sortable { cursor:pointer; }
    .db-table thead th.sortable:hover { background:#eef2ff; }
    .db-table thead th .sort-ind { display:inline-block; margin-left:3px; opacity:0.4; }
    .db-table thead th.sorted .sort-ind { opacity:1; color:#6366f1; }

    /* Filter row sticky below header */
    .db-table thead tr.filter-row th {
      position:sticky; top:33px; z-index:4;
      background:#f1f5f9; padding:5px 8px; border-bottom:1px solid #cbd5e1;
    }
    .db-table thead tr.filter-row input,
    .db-table thead tr.filter-row select {
      width:100%; padding:3px 6px; font-size:11px;
      border:1px solid #cbd5e1; border-radius:4px; background:#fff;
    }

    .db-table tbody td {
      padding:5px 10px; border-bottom:1px solid #f1f5f9;
      white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:280px;
    }
    .db-table tbody tr:nth-child(even) { background:#fbfbfd; }
    .db-table tbody tr:hover { background:#eef2ff; cursor:pointer; }
    .db-table tbody tr.expanded { background:#fef3c7 !important; }

    /* Status badges */
    .badge { display:inline-flex; align-items:center; padding:1px 6px; border-radius:999px; font-size:10px; font-weight:600; white-space:nowrap; }
    .badge.ok { background:#dcfce7; color:#166534; }
    .badge.bad { background:#fee2e2; color:#991b1b; }
    .badge.warn { background:#fef3c7; color:#92400e; }
    .badge.info { background:#dbeafe; color:#1e40af; }
    .badge.gray { background:#e2e8f0; color:#475569; }

    /* Expanded row detail */
    .row-detail {
      background:#fef9c3; padding:14px; font-size:12px;
    }
    .row-detail h4 { font-weight:600; margin-bottom:6px; color:#0f172a; }
    .row-detail .grid-2 { display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:8px 16px; }
    .row-detail .field { padding:4px 0; }
    .row-detail .field .label { font-size:10.5px; color:#64748b; text-transform:uppercase; letter-spacing:0.04em; font-weight:600; }
    .row-detail .field .value { color:#0f172a; word-break:break-word; }

    .timeline { margin-top:8px; }
    .timeline .step {
      display:flex; gap:10px; padding:6px 0; border-bottom:1px dashed #e5e7eb;
    }
    .timeline .step:last-child { border-bottom:0; }
    .timeline .step .when { color:#64748b; font-family:ui-monospace,monospace; font-size:11px; min-width:140px; }
    .timeline .step .arrow { color:#6366f1; font-weight:bold; }

    /* Multi-select dropdown */
    .multi-dropdown { position:relative; }
    .multi-dropdown .multi-list {
      position:absolute; top:100%; left:0; min-width:180px; max-height:240px; overflow-y:auto;
      background:#fff; border:1px solid #cbd5e1; border-radius:6px;
      box-shadow:0 8px 16px rgba(0,0,0,0.1); padding:4px; z-index:50;
    }
    .multi-dropdown .multi-list label {
      display:flex; align-items:center; gap:5px; padding:3px 6px; font-size:11px; cursor:pointer;
    }
    .multi-dropdown .multi-list label:hover { background:#f1f5f9; }
    .multi-dropdown .multi-trigger {
      width:100%; padding:3px 6px; font-size:11px; text-align:left;
      border:1px solid #cbd5e1; border-radius:4px; background:#fff; cursor:pointer;
      white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    }
    .multi-dropdown .multi-trigger:hover { background:#f8fafc; }

    /* Column visibility dropdown */
    .col-toggle {
      position:absolute; top:100%; right:0; margin-top:4px; min-width:240px;
      background:#fff; border:1px solid #cbd5e1; border-radius:8px;
      box-shadow:0 8px 16px rgba(0,0,0,0.1); padding:8px; z-index:50;
    }
    .col-toggle label { display:flex; align-items:center; gap:6px; padding:3px 6px; font-size:12px; cursor:pointer; border-radius:4px; }
    .col-toggle label:hover { background:#f1f5f9; }

    .pagination-controls { display:flex; gap:8px; align-items:center; padding:10px 14px; flex-wrap:wrap; }
    .pagination-controls input { width:60px; text-align:center; }
  </style>

  <div class="w-full p-2" x-data="dataBrowser()" x-init="init()" x-cloak>

    <div class="db-card">
      {{-- ============= TOOLBAR ============= --}}
      <div class="db-toolbar">
        <input type="text" class="db-input" placeholder="🔍 Search waybill / receiver / phone / item…"
               style="min-width:260px; flex:1;"
               x-model="search"
               @input.debounce.300ms="onFiltersChanged()">

        <div class="text-xs text-slate-500" x-text="resultsLabel()"></div>

        <div class="ml-auto flex items-center gap-2 relative">
          <button type="button" class="db-btn-ghost" @click="reload()">
            ↻ Refresh
          </button>
          <button type="button" class="db-btn-ghost" @click="resetFilters()">
            ⟲ Reset filters
          </button>
          <div class="relative">
            <button type="button" class="db-btn-ghost" @click="showColToggle = !showColToggle">
              📋 Columns ▼
            </button>
            <div x-show="showColToggle" @click.outside="showColToggle = false" class="col-toggle">
              <div class="text-[10.5px] text-slate-500 uppercase font-semibold tracking-wide px-2 py-1">Toggle visibility</div>
              <template x-for="col in allColumns" :key="col.key">
                <label>
                  <input type="checkbox" :value="col.key" x-model="visibleCols">
                  <span x-text="col.label"></span>
                </label>
              </template>
            </div>
          </div>
        </div>
      </div>

      {{-- ============= TABLE ============= --}}
      <div class="db-table-wrap">
        <table class="db-table">
          <thead>
            <tr>
              <th style="width:32px;"></th>
              <template x-for="col in visibleColumnDefs()" :key="col.key">
                <th :class="['sortable', sort.col === col.key ? 'sorted' : '']"
                    @click="sortBy(col.key)" x-text="col.label + ' '">
                  <span class="sort-ind" x-text="sort.col === col.key ? (sort.dir === 'asc' ? '↑' : '↓') : '↕'"></span>
                </th>
              </template>
            </tr>
            <tr class="filter-row">
              <th></th>
              <template x-for="col in visibleColumnDefs()" :key="col.key + '_f'">
                <th>
                  {{-- Multi-select for some cols --}}
                  <template x-if="col.filterType === 'multi'">
                    <div class="multi-dropdown">
                      <button type="button" class="multi-trigger"
                              @click="toggleMultiOpen(col.key)"
                              x-text="(filters[col.key] && filters[col.key].length) ? filters[col.key].length + ' selected' : 'All'"></button>
                      <div x-show="multiOpen === col.key" @click.outside="multiOpen = null" class="multi-list">
                        <input type="text" x-model="multiSearch[col.key]" placeholder="🔍" class="db-input mb-1" style="width:100%;font-size:10.5px;padding:3px 6px;">
                        <template x-for="opt in (col.options || []).filter(o => !multiSearch[col.key] || (o||'').toLowerCase().includes((multiSearch[col.key]||'').toLowerCase()))" :key="opt">
                          <label><input type="checkbox" :value="opt" x-model="filters[col.key]" @change="onFiltersChanged()"><span x-text="opt"></span></label>
                        </template>
                      </div>
                    </div>
                  </template>
                  {{-- Date range --}}
                  <template x-if="col.filterType === 'date'">
                    <div class="flex gap-1">
                      <input type="date" x-model="filters[col.key + '_from']" @change="onFiltersChanged()" style="font-size:10px;padding:3px;">
                      <input type="date" x-model="filters[col.key + '_to']"   @change="onFiltersChanged()" style="font-size:10px;padding:3px;">
                    </div>
                  </template>
                  {{-- Text --}}
                  <template x-if="col.filterType === 'text'">
                    <input type="text" placeholder="contains…" x-model="filters[col.key]" @input.debounce.300ms="onFiltersChanged()">
                  </template>
                  {{-- No filter --}}
                  <template x-if="!col.filterType">
                    <span></span>
                  </template>
                </th>
              </template>
            </tr>
          </thead>
          <tbody>
            <template x-if="loading">
              <tr><td :colspan="visibleColumnDefs().length + 1" style="text-align:center;padding:24px;color:#94a3b8;">Loading…</td></tr>
            </template>
            <template x-if="!loading && rows.length === 0">
              <tr><td :colspan="visibleColumnDefs().length + 1" style="text-align:center;padding:36px;color:#94a3b8;">No matching rows.</td></tr>
            </template>
            <template x-for="row in rows" :key="row.id">
              <template>
                <tr :class="expanded === row.id ? 'expanded' : ''" @click="toggleExpand(row.id)">
                  <td style="text-align:center;color:#94a3b8;" x-text="expanded === row.id ? '▼' : '▶'"></td>
                  <template x-for="col in visibleColumnDefs()" :key="col.key + '_' + row.id">
                    <td>
                      <template x-if="col.key === 'status'">
                        <span :class="'badge ' + statusBadgeClass(row.status)" x-text="(row.status || '—').toUpperCase()"></span>
                      </template>
                      <template x-if="col.key === 'waybill_number'">
                        <span style="font-family:ui-monospace,monospace;font-size:11.5px;font-weight:600;color:#0f172a;" x-text="row.waybill_number || '—'"></span>
                      </template>
                      <template x-if="col.key === 'cod'">
                        <span style="font-family:ui-monospace,monospace;color:#15803d;" x-text="row.cod ? '₱' + Number(row.cod).toLocaleString() : '—'"></span>
                      </template>
                      <template x-if="col.key === 'total_shipping_cost'">
                        <span style="font-family:ui-monospace,monospace;" x-text="row.total_shipping_cost ? '₱' + Number(row.total_shipping_cost).toLocaleString() : '—'"></span>
                      </template>
                      <template x-if="col.key === 'signingtime' || col.key === 'submission_time' || col.key === 'created_at' || col.key === 'updated_at'">
                        <span style="font-size:11px;color:#475569;" x-text="fmtDate(row[col.key])"></span>
                      </template>
                      <template x-if="!['status','waybill_number','cod','total_shipping_cost','signingtime','submission_time','created_at','updated_at'].includes(col.key)">
                        <span x-text="row[col.key] || '—'" :title="row[col.key] || ''"></span>
                      </template>
                    </td>
                  </template>
                </tr>
                <tr x-show="expanded === row.id">
                  <td :colspan="visibleColumnDefs().length + 1" class="row-detail">
                    <h4>📄 Full record — Waybill <code x-text="row.waybill_number"></code></h4>
                    <div class="grid-2">
                      <div class="field"><div class="label">ID</div><div class="value" x-text="row.id"></div></div>
                      <div class="field"><div class="label">Waybill</div><div class="value" x-text="row.waybill_number"></div></div>
                      <div class="field"><div class="label">Status</div><div class="value"><span :class="'badge ' + statusBadgeClass(row.status)" x-text="(row.status||'—').toUpperCase()"></span></div></div>
                      <div class="field"><div class="label">Item</div><div class="value" x-text="row.item_name || '—'"></div></div>
                      <div class="field"><div class="label">Sender</div><div class="value" x-text="row.sender || '—'"></div></div>
                      <div class="field"><div class="label">Receiver</div><div class="value" x-text="row.receiver || '—'"></div></div>
                      <div class="field"><div class="label">Phone</div><div class="value" x-text="row.receiver_cellphone || '—'"></div></div>
                      <div class="field"><div class="label">COD</div><div class="value" x-text="row.cod ? '₱' + Number(row.cod).toLocaleString() : '—'"></div></div>
                      <div class="field"><div class="label">Submission Time</div><div class="value" x-text="fmtDate(row.submission_time)"></div></div>
                      <div class="field"><div class="label">Signing Time</div><div class="value" x-text="fmtDate(row.signingtime)"></div></div>
                      <div class="field"><div class="label">Province</div><div class="value" x-text="row.province || '—'"></div></div>
                      <div class="field"><div class="label">City</div><div class="value" x-text="row.city || '—'"></div></div>
                      <div class="field"><div class="label">Barangay</div><div class="value" x-text="row.barangay || '—'"></div></div>
                      <div class="field"><div class="label">Shipping Cost</div><div class="value" x-text="row.total_shipping_cost ? '₱' + Number(row.total_shipping_cost).toLocaleString() : '—'"></div></div>
                      <div class="field"><div class="label">RTS Reason</div><div class="value" x-text="row.rts_reason || '—'"></div></div>
                      <div class="field"><div class="label">Remarks</div><div class="value" x-text="row.remarks || '—'"></div></div>
                      <div class="field"><div class="label">Last Upload Run</div><div class="value">
                        <template x-if="row.last_upload_log_id">
                          <a :href="'/jnt_upload_v2/history?search=' + row.last_upload_log_id" class="text-indigo-600 hover:underline" x-text="'#' + row.last_upload_log_id"></a>
                        </template>
                        <template x-if="!row.last_upload_log_id"><span>—</span></template>
                      </div></div>
                      <div class="field"><div class="label">Created</div><div class="value" x-text="fmtDate(row.created_at)"></div></div>
                      <div class="field"><div class="label">Updated</div><div class="value" x-text="fmtDate(row.updated_at)"></div></div>
                    </div>

                    <h4 class="mt-3">📜 Status timeline</h4>
                    <div class="timeline">
                      <template x-if="!row.status_logs || row.status_logs.length === 0">
                        <div class="text-xs text-slate-500 italic">No status log entries.</div>
                      </template>
                      <template x-for="(log, i) in (row.status_logs || [])" :key="i">
                        <div class="step">
                          <div class="when" x-text="log.batch_at"></div>
                          <div>
                            <span class="text-slate-500" x-text="log.from || '(initial)'"></span>
                            <span class="arrow"> → </span>
                            <strong x-text="log.to || '?'"></strong>
                            <template x-if="log.upload_log_id">
                              <span class="text-[10.5px] text-slate-400 ml-2" x-text="'(via upload #' + log.upload_log_id + ')'"></span>
                            </template>
                          </div>
                        </div>
                      </template>
                    </div>
                  </td>
                </tr>
              </template>
            </template>
          </tbody>
        </table>
      </div>

      {{-- ============= PAGINATION ============= --}}
      <div class="pagination-controls">
        <button type="button" class="db-btn-ghost" :disabled="page <= 1" @click="goPage(1)">⏮ First</button>
        <button type="button" class="db-btn-ghost" :disabled="page <= 1" @click="goPage(page - 1)">◀ Prev</button>

        <span class="text-xs text-slate-600">Page</span>
        <input type="number" min="1" :max="totalPages" x-model.number="pageInput" @change="goPage(pageInput)" class="db-input">
        <span class="text-xs text-slate-500">of <span x-text="totalPages.toLocaleString()"></span></span>

        <button type="button" class="db-btn-ghost" :disabled="page >= totalPages" @click="goPage(page + 1)">Next ▶</button>
        <button type="button" class="db-btn-ghost" :disabled="page >= totalPages" @click="goPage(totalPages)">Last ⏭</button>

        <span class="ml-auto text-xs text-slate-600">Per page:</span>
        <select class="db-select" x-model.number="perPage" @change="onFiltersChanged()">
          <option value="50">50</option>
          <option value="100">100</option>
          <option value="250">250</option>
          <option value="500">500</option>
        </select>
      </div>
    </div>
  </div>

  <script>
    function dataBrowser() {
      return {
        // ===== Column definitions =====
        allColumns: [
          { key:'waybill_number',     label:'Waybill',      filterType:'text' },
          { key:'status',             label:'Status',       filterType:'multi', options: @json($distinct['statuses']) },
          { key:'signingtime',        label:'Signing Time', filterType:'date' },
          { key:'submission_time',    label:'Submission',   filterType:'date' },
          { key:'item_name',          label:'Item',         filterType:'multi', options: @json($distinct['items']) },
          { key:'sender',             label:'Sender',       filterType:'multi', options: @json($distinct['senders']) },
          { key:'receiver',           label:'Receiver',     filterType:'text' },
          { key:'receiver_cellphone', label:'Phone',        filterType:'text' },
          { key:'cod',                label:'COD',          filterType:'text' },
          { key:'province',           label:'Province',     filterType:'multi', options: @json($distinct['provinces']) },
          { key:'city',               label:'City',         filterType:'multi', options: @json($distinct['cities']) },
          { key:'barangay',           label:'Barangay',     filterType:'multi', options: @json($distinct['barangays']) },
          { key:'total_shipping_cost',label:'Ship Cost',    filterType:null },
          { key:'rts_reason',         label:'RTS Reason',   filterType:'text' },
          { key:'remarks',            label:'Remarks',      filterType:null },
          { key:'created_at',         label:'Created',      filterType:null },
          { key:'updated_at',         label:'Updated',      filterType:null },
        ],

        // ===== State =====
        visibleCols: [
          'waybill_number','status','signingtime','item_name','sender',
          'receiver','receiver_cellphone','cod','province','city',
        ],
        rows: [],
        total: 0,
        page: 1,
        pageInput: 1,
        perPage: 100,
        totalPages: 1,
        loading: false,
        expanded: null,

        search: '',
        filters: {},
        multiSearch: {},
        multiOpen: null,
        showColToggle: false,
        sort: { col: 'id', dir: 'desc' },

        init() {
          // Initialize multi-select filter arrays
          this.allColumns.forEach(c => {
            if (c.filterType === 'multi') this.filters[c.key] = [];
            else if (c.filterType === 'date') {
              this.filters[c.key + '_from'] = '';
              this.filters[c.key + '_to']   = '';
            } else if (c.filterType === 'text') this.filters[c.key] = '';
            this.multiSearch[c.key] = '';
          });
          this.reload();
        },

        visibleColumnDefs() {
          return this.allColumns.filter(c => this.visibleCols.includes(c.key));
        },

        toggleMultiOpen(key) {
          this.multiOpen = (this.multiOpen === key) ? null : key;
        },

        statusBadgeClass(status) {
          const s = (status || '').toLowerCase();
          if (s.includes('delivered')) return 'ok';
          if (s.includes('returned')) return 'bad';
          if (s.includes('rts')) return 'bad';
          if (s.includes('transit') || s.includes('delivering')) return 'info';
          if (s.includes('hold') || s.includes('pending')) return 'warn';
          if (s.includes('cancelled') || s.includes('failed')) return 'gray';
          return 'gray';
        },

        fmtDate(s) {
          if (!s) return '—';
          try {
            const d = new Date(s.replace(' ', 'T'));
            if (isNaN(d.getTime())) return s;
            return d.toLocaleDateString('en-US', { month:'short', day:'numeric', year:'numeric' }) +
                   ' ' + d.toLocaleTimeString('en-US', { hour:'numeric', minute:'2-digit', hour12:true });
          } catch (e) { return s; }
        },

        sortBy(col) {
          if (this.sort.col === col) {
            this.sort.dir = (this.sort.dir === 'asc') ? 'desc' : 'asc';
          } else {
            this.sort.col = col;
            this.sort.dir = 'asc';
          }
          this.reload();
        },

        toggleExpand(id) {
          this.expanded = (this.expanded === id) ? null : id;
        },

        onFiltersChanged() {
          this.page = 1;
          this.reload();
        },

        resetFilters() {
          this.search = '';
          this.allColumns.forEach(c => {
            if (c.filterType === 'multi') this.filters[c.key] = [];
            else if (c.filterType === 'date') {
              this.filters[c.key + '_from'] = '';
              this.filters[c.key + '_to']   = '';
            } else if (c.filterType === 'text') this.filters[c.key] = '';
          });
          this.page = 1;
          this.reload();
        },

        goPage(n) {
          n = Math.max(1, Math.min(this.totalPages, parseInt(n, 10) || 1));
          this.page = n;
          this.pageInput = n;
          this.reload();
        },

        async reload() {
          this.loading = true;
          this.expanded = null;

          const params = new URLSearchParams();
          if (this.search) params.append('search', this.search);
          params.append('sort', this.sort.col);
          params.append('dir',  this.sort.dir);
          params.append('page', this.page);
          params.append('per_page', this.perPage);

          // Per-column filters
          this.allColumns.forEach(c => {
            if (c.filterType === 'multi') {
              (this.filters[c.key] || []).forEach(v => params.append(`filter_${c.key}[]`, v));
            } else if (c.filterType === 'date') {
              if (this.filters[c.key + '_from']) params.append(`filter_${c.key}_from`, this.filters[c.key + '_from']);
              if (this.filters[c.key + '_to'])   params.append(`filter_${c.key}_to`,   this.filters[c.key + '_to']);
            } else if (c.filterType === 'text') {
              if (this.filters[c.key]) params.append(`filter_${c.key}`, this.filters[c.key]);
            }
          });

          try {
            const res = await fetch('/jnt_upload_v2/data/query?' + params.toString(), {
              headers: { 'Accept': 'application/json' }
            });
            if (!res.ok) throw new Error('Status ' + res.status);
            const data = await res.json();
            this.rows = data.rows || [];
            this.total = data.total || 0;
            this.page = data.page || 1;
            this.pageInput = this.page;
            this.perPage = data.per_page || this.perPage;
            this.totalPages = data.total_pages || 1;
          } catch (e) {
            console.error('reload error:', e);
            alert('Failed to load data: ' + e.message);
          } finally {
            this.loading = false;
          }
        },

        resultsLabel() {
          if (this.total === 0) return 'No rows';
          const from = (this.page - 1) * this.perPage + 1;
          const to   = Math.min(this.page * this.perPage, this.total);
          return `Showing ${from.toLocaleString()}–${to.toLocaleString()} of ${this.total.toLocaleString()} rows`;
        },
      };
    }
  </script>
</x-layout>

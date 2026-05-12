<x-layout>
  <x-slot name="title">CPP Timeline</x-slot>
  <x-slot name="heading">CPP Snapshot Timeline</x-slot>

  <style>
    .tl-grid-wrap { overflow-x: auto; }
    .tl-grid { border-collapse: separate; border-spacing: 0; min-width: 100%; font-size: 12px; }
    .tl-grid th, .tl-grid td {
      border: 1px solid #e5e7eb;
      padding: 8px 10px;
      vertical-align: top;
      background: white;
      min-width: 140px;
    }
    .tl-grid thead th {
      background: #f1f5f9;
      font-weight: 600;
      color: #334155;
      position: sticky;
      top: 0;
      z-index: 2;
      text-align: center;
    }
    .tl-grid th.tl-bucket-col {
      background: #f8fafc;
      color: #475569;
      font-weight: 700;
      position: sticky;
      left: 0;
      z-index: 3;
      min-width: 90px;
      text-align: center;
    }
    .tl-grid thead th.tl-corner {
      left: 0;
      z-index: 4;
      background: #e2e8f0;
    }
    .tl-cell {
      cursor: pointer;
      transition: background .15s;
      font-family: 'SFMono-Regular', Consolas, monospace;
      line-height: 1.45;
    }
    .tl-cell:hover { background: #eff6ff; }
    .tl-cell-empty { background: #fafafa; color: #cbd5e1; text-align: center; font-style: italic; cursor: default; }
    .tl-cell-empty:hover { background: #fafafa; }
    .tl-cell .label { font-size: 10px; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; }
    .tl-cell .val   { color: #0f172a; font-weight: 600; }
    .tl-cell .saved { font-size: 10px; color: #94a3b8; margin-top: 4px; }

    /* Modal */
    .tl-modal-backdrop {
      position: fixed; inset: 0; background: rgba(15, 23, 42, 0.55);
      z-index: 100; display: flex; align-items: flex-start; justify-content: center;
      padding: 40px 16px; overflow-y: auto;
    }
    .tl-modal {
      background: white; border-radius: 12px; max-width: 1100px; width: 100%;
      box-shadow: 0 20px 60px rgba(0,0,0,0.25); display: flex; flex-direction: column;
      max-height: calc(100vh - 80px);
    }
    .tl-modal-head {
      display: flex; align-items: center; justify-content: space-between;
      padding: 16px 20px; border-bottom: 1px solid #e5e7eb;
    }
    .tl-modal-title { font-size: 16px; font-weight: 700; color: #0f172a; }
    .tl-modal-sub { font-size: 12px; color: #64748b; }
    .tl-modal-body { padding: 16px 20px; overflow: auto; flex: 1; }
    .tl-modal-close {
      cursor: pointer; padding: 6px 12px; border-radius: 6px; border: 1px solid #cbd5e1;
      background: white; color: #475569; font-size: 13px;
    }
    .tl-modal-close:hover { background: #f1f5f9; }
    .tl-detail-table { width: 100%; border-collapse: collapse; font-size: 12px; }
    .tl-detail-table th, .tl-detail-table td { border: 1px solid #e5e7eb; padding: 6px 8px; }
    .tl-detail-table thead th { background: #f1f5f9; color: #334155; text-align: left; font-weight: 600; }
    .tl-detail-table tbody tr:nth-child(even) td { background: #fafafa; }
    .tl-detail-table tfoot td { background: #f1f5f9; font-weight: 700; }
    .num { text-align: right; font-family: 'SFMono-Regular', Consolas, monospace; }
  </style>

  <div class="max-w-7xl mx-auto" x-data="cppTimeline()" x-init="init()">

    {{-- ── Filter bar ──────────────────────────────────────────────── --}}
    <div class="bg-white border border-gray-200 rounded-lg p-4 mb-4 flex flex-wrap items-end gap-3">
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1">Start Date</label>
        <input type="date" x-model="start" class="border border-gray-300 rounded px-2 py-1 text-sm">
      </div>
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1">End Date</label>
        <input type="date" x-model="end" class="border border-gray-300 rounded px-2 py-1 text-sm">
      </div>
      <button @click="reload()" class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium px-4 py-1.5 rounded">
        Apply
      </button>
      <button @click="preset(7)"  class="bg-gray-200 hover:bg-gray-300 text-gray-700 text-sm px-3 py-1.5 rounded">Last 7d</button>
      <button @click="preset(14)" class="bg-gray-200 hover:bg-gray-300 text-gray-700 text-sm px-3 py-1.5 rounded">Last 14d</button>
      <button @click="preset(30)" class="bg-gray-200 hover:bg-gray-300 text-gray-700 text-sm px-3 py-1.5 rounded">Last 30d</button>
      <span class="text-xs text-gray-500 ml-auto">
        Snapshots auto-save sa <a href="{{ route('ads_manager.cpp') }}" class="text-blue-600 hover:underline">/cpp</a> every Copy Table click.
      </span>
    </div>

    {{-- ── Grid ────────────────────────────────────────────────────── --}}
    <div class="bg-white border border-gray-200 rounded-lg p-4">
      <div class="flex items-center justify-between mb-2">
        <div class="text-sm text-gray-600">
          Rows = snapshot bucket · Columns = date · Click a cell for per-page detail.
        </div>
        <div class="text-xs text-gray-500" x-show="loading">⏳ Loading…</div>
      </div>

      <div class="tl-grid-wrap">
        <table class="tl-grid">
          <thead>
            <tr>
              <th class="tl-corner tl-bucket-col">Bucket</th>
              <template x-for="d in dates" :key="d">
                <th x-text="fmtDate(d)"></th>
              </template>
            </tr>
          </thead>
          <tbody>
            <template x-for="b in buckets" :key="b">
              <tr>
                <th class="tl-bucket-col" x-text="b"></th>
                <template x-for="d in dates" :key="b + '|' + d">
                  <td x-bind:class="cellOf(b, d) ? 'tl-cell' : 'tl-cell-empty'"
                      @click="cellOf(b, d) && openDetail(d, b)">
                    <template x-if="cellOf(b, d)">
                      <div>
                        <div><span class="label">Adspent:</span> <span class="val" x-text="money(cellOf(b, d).spent)"></span></div>
                        <div><span class="label">Orders:</span>  <span class="val" x-text="num(cellOf(b, d).orders)"></span></div>
                        <div><span class="label">CPP:</span>     <span class="val" x-text="cellOf(b, d).cpp != null ? money(cellOf(b, d).cpp) : '—'"></span></div>
                        <div class="saved" x-text="'saved ' + fmtSavedAt(cellOf(b, d).saved_at)"></div>
                      </div>
                    </template>
                    <template x-if="!cellOf(b, d)">
                      <div>—</div>
                    </template>
                  </td>
                </template>
              </tr>
            </template>
            <template x-if="!loading && dates.length === 0">
              <tr><td colspan="100" class="text-center text-gray-400 py-6">Walang data sa selected range.</td></tr>
            </template>
          </tbody>
        </table>
      </div>
    </div>

    {{-- ── Detail Modal ───────────────────────────────────────────── --}}
    <template x-if="modal.open">
      <div class="tl-modal-backdrop" @click.self="closeDetail()">
        <div class="tl-modal">
          <div class="tl-modal-head">
            <div>
              <div class="tl-modal-title">
                <span x-text="fmtDate(modal.date)"></span> · <span x-text="modal.bucket"></span> Snapshot
              </div>
              <div class="tl-modal-sub">
                <span x-text="modal.rows.length"></span> pages
                <template x-if="modal.savedAt"><span> · saved <span x-text="fmtSavedAt(modal.savedAt)"></span></span></template>
                <template x-if="modal.savedBy.length"><span> · by <span x-text="modal.savedBy.join(', ')"></span></span></template>
              </div>
            </div>
            <div class="flex items-center gap-2">
              <button @click="copyDetail()" class="bg-blue-600 hover:bg-blue-700 text-white text-sm px-3 py-1.5 rounded">📋 Copy</button>
              <button @click="closeDetail()" class="tl-modal-close">✕ Close</button>
            </div>
          </div>
          <div class="tl-modal-body">
            <template x-if="modal.loading">
              <div class="text-center text-gray-400 py-8">⏳ Loading detail…</div>
            </template>
            <template x-if="!modal.loading">
              <table class="tl-detail-table" id="tlDetailTable">
                <thead>
                  <tr>
                    <th>Page Name</th>
                    <th>Item Names</th>
                    <th class="num">Amount Spent</th>
                    <th class="num">Orders</th>
                    <th class="num">Proceed</th>
                    <th class="num">CPP</th>
                    <th class="num">CPI</th>
                    <th class="num">CPM</th>
                    <th class="num">TCPR%</th>
                  </tr>
                </thead>
                <tbody>
                  <template x-for="r in modal.rows" :key="r.page_name">
                    <tr>
                      <td x-text="r.page_name"></td>
                      <td x-text="r.item_names || '—'"></td>
                      <td class="num" x-text="money(r.amount_spent)"></td>
                      <td class="num" x-text="num(r.orders)"></td>
                      <td class="num" x-text="num(r.proceed_orders)"></td>
                      <td class="num" x-text="r.cpp != null ? money(r.cpp) : '—'"></td>
                      <td class="num" x-text="r.cpi != null ? money(r.cpi) : '—'"></td>
                      <td class="num" x-text="r.cpm != null ? money(r.cpm) : '—'"></td>
                      <td class="num" x-text="r.tcpr_pct != null ? (Number(r.tcpr_pct).toFixed(1) + '%') : '—'"></td>
                    </tr>
                  </template>
                  <template x-if="modal.rows.length === 0">
                    <tr><td colspan="9" class="text-center text-gray-400 py-6">Walang rows sa snapshot na ito.</td></tr>
                  </template>
                </tbody>
                <tfoot>
                  <tr>
                    <td>TOTAL</td>
                    <td></td>
                    <td class="num" x-text="money(modal.totals.amount_spent)"></td>
                    <td class="num" x-text="num(modal.totals.orders)"></td>
                    <td class="num" x-text="num(modal.totals.proceed_orders)"></td>
                    <td class="num" x-text="modal.totals.cpp != null ? money(modal.totals.cpp) : '—'"></td>
                    <td class="num"></td>
                    <td class="num"></td>
                    <td class="num"></td>
                  </tr>
                </tfoot>
              </table>
            </template>
          </div>
        </div>
      </div>
    </template>
  </div>

  <script>
    function cppTimeline() {
      return {
        start:  @json($start),
        end:    @json($end),
        dates:   [],
        buckets: [],
        cells:   {},
        loading: false,

        modal: {
          open: false,
          loading: false,
          date: '',
          bucket: '',
          rows: [],
          totals: { amount_spent: 0, orders: 0, proceed_orders: 0, cpp: null },
          savedAt: null,
          savedBy: [],
        },

        init() { this.reload(); },

        async reload() {
          this.loading = true;
          try {
            const qs = new URLSearchParams({ start: this.start, end: this.end });
            const res = await fetch(`{{ route('ads_manager.cpp.timeline.data') }}?${qs.toString()}`);
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const j = await res.json();
            this.dates   = j.dates   || [];
            this.buckets = j.buckets || [];
            this.cells   = j.cells   || {};
          } catch (e) {
            console.error('Timeline reload failed:', e);
          } finally {
            this.loading = false;
          }
        },

        preset(days) {
          const ph = new Date(new Date().toLocaleString('en-US', { timeZone: 'Asia/Manila' }));
          const end = new Date(ph);
          const start = new Date(ph); start.setDate(start.getDate() - (days - 1));
          this.end   = end.toISOString().slice(0, 10);
          this.start = start.toISOString().slice(0, 10);
          this.reload();
        },

        cellOf(bucket, date) {
          return (this.cells[bucket] && this.cells[bucket][date]) || null;
        },

        async openDetail(date, bucket) {
          this.modal.open    = true;
          this.modal.loading = true;
          this.modal.date    = date;
          this.modal.bucket  = bucket;
          this.modal.rows    = [];
          try {
            const qs = new URLSearchParams({ date, bucket });
            const res = await fetch(`{{ route('ads_manager.cpp.timeline.detail') }}?${qs.toString()}`);
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const j = await res.json();
            if (j.ok) {
              this.modal.rows    = j.rows || [];
              this.modal.totals  = j.totals || this.modal.totals;
              this.modal.savedAt = j.saved_at || null;
              this.modal.savedBy = j.saved_by || [];
            }
          } catch (e) {
            console.error('Detail load failed:', e);
          } finally {
            this.modal.loading = false;
          }
        },

        closeDetail() { this.modal.open = false; },

        copyDetail() {
          const table = document.getElementById('tlDetailTable');
          if (!table) return;
          const rows = Array.from(table.querySelectorAll('tr'));
          const tsv = rows.map(row =>
            Array.from(row.querySelectorAll('th, td'))
              .map(c => c.textContent.replace(/₱/g, '').replace(/\n+/g, ', ').trim())
              .join('\t')
          ).join('\n');
          navigator.clipboard.writeText(tsv).then(() => {
            alert('Detail table copied!');
          }).catch(e => console.error('Copy failed:', e));
        },

        // Formatters
        fmtDate(iso) {
          if (!iso) return '';
          const [y, m, d] = iso.split('-');
          const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
          return `${months[+m - 1]} ${+d}`;
        },
        fmtSavedAt(iso) {
          if (!iso) return '—';
          const d = new Date(iso.replace(' ', 'T'));
          if (isNaN(d.getTime())) return iso;
          return d.toLocaleString('en-US', {
            timeZone: 'Asia/Manila',
            month: 'short', day: 'numeric',
            hour: 'numeric', minute: '2-digit', hour12: true,
          });
        },
        money(v) {
          const n = Number(v ?? 0);
          return '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },
        num(v) { return Number(v ?? 0).toLocaleString('en-PH'); },
      };
    }
  </script>
</x-layout>

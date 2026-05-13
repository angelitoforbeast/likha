<x-layout>
  <x-slot name="title">CPP Timeline</x-slot>
  <x-slot name="heading">CPP Snapshot Timeline</x-slot>

  <style>
    /* ── Timeline grid — clean, centered, mostly black text ──────────── */
    .tl-grid-wrap {
      overflow-x: auto;
      display: flex;
      justify-content: center;
    }
    .tl-grid {
      border-collapse: separate;
      border-spacing: 0;
      font-size: 13px;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      color: #000;
      margin: 0 auto;
    }
    .tl-grid th, .tl-grid td {
      border: 1px solid #e5e7eb;
      padding: 10px 14px;
      vertical-align: middle;
      background: white;
      min-width: 170px;
      text-align: center;
    }
    .tl-grid thead th {
      background: #f9fafb;
      font-weight: 700;
      color: #000;
      position: sticky;
      top: 0;
      z-index: 2;
      letter-spacing: 0.04em;
      font-size: 12px;
      text-transform: uppercase;
    }
    .tl-grid th.tl-date-col {
      background: #f9fafb;
      color: #000;
      font-weight: 700;
      position: sticky;
      left: 0;
      z-index: 3;
      min-width: 140px;
      text-align: center;
      letter-spacing: 0.02em;
    }
    .tl-grid thead th.tl-corner {
      left: 0;
      z-index: 4;
      background: #f3f4f6;
    }
    /* Cell base — clickable */
    .tl-cell {
      cursor: pointer;
      transition: background .15s;
      line-height: 1.5;
    }
    .tl-cell:hover { background: #fafafa; }
    /* Empty / no-data */
    .tl-cell-empty {
      background: white;
      color: #d1d5db;
      cursor: default;
    }
    .tl-cell-empty:hover { background: white; }
    /* Inferred (past) — very light amber, still mostly black text */
    .tl-cell-inferred { background: #fffdf6; }
    .tl-cell-inferred:hover { background: #fefce8; }
    /* Estimate (future today) — very light blue, still mostly black text */
    .tl-cell-estimate { background: #f8fafc; cursor: default; }
    .tl-cell-estimate:hover { background: #f1f5f9; }
    /* Badges — small, low-contrast, informational only */
    .tl-cell .est-badge {
      display: inline-block;
      font-size: 10px;
      color: #6b7280;
      background: transparent;
      padding: 0;
      margin-top: 6px;
      letter-spacing: 0.02em;
      font-style: italic;
    }
    .tl-cell .inferred-badge {
      display: inline-block;
      font-size: 10px;
      color: #6b7280;
      background: transparent;
      padding: 0;
      margin-top: 6px;
      letter-spacing: 0.02em;
      font-style: italic;
    }
    /* Labels (Adspent / Orders / CPP) — black bold */
    .tl-cell .label {
      font-size: 11px;
      color: #000;
      font-weight: 600;
      letter-spacing: 0.02em;
    }
    /* Values — black, slightly heavier */
    .tl-cell .val {
      color: #000;
      font-weight: 600;
      font-family: 'SFMono-Regular', Consolas, monospace;
    }
    /* Saved timestamp footer — muted gray */
    .tl-cell .saved {
      font-size: 10px;
      color: #9ca3af;
      margin-top: 6px;
      letter-spacing: 0.02em;
    }

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

    .tl-legend {
      display: inline-flex; align-items: center; gap: 14px;
      font-size: 11px; color: #64748b;
    }
    .tl-legend .swatch {
      display: inline-block; width: 12px; height: 12px; border-radius: 2px;
      border: 1px solid #e5e7eb; vertical-align: middle; margin-right: 4px;
    }
  </style>

  <div class="max-w-7xl mx-auto" x-data="cppTimeline()" x-init="init()">

    {{-- ── Filter bar ──────────────────────────────────────────────── --}}
    <div class="bg-white border border-gray-200 rounded-lg p-4 mb-4">
      <div class="flex flex-wrap items-end gap-3">
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1">Preset</label>
          <select x-model="preset" @change="applyPreset()" class="border border-gray-300 rounded px-2 py-1 text-sm w-44">
            <option value="last7">Last 7 Days</option>
            <option value="last14">Last 14 Days</option>
            <option value="last30">Last 30 Days</option>
            <option value="this_month">This Month</option>
            <option value="last_month">Last Month</option>
            <option value="last_month_to_date">Last Month-to-Date</option>
            <option value="this_year">This Year</option>
            <option value="custom">Custom</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1">Start Date</label>
          <input type="date" x-model="start" @change="preset='custom'" class="border border-gray-300 rounded px-2 py-1 text-sm">
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1">End Date</label>
          <input type="date" x-model="end" @change="preset='custom'" class="border border-gray-300 rounded px-2 py-1 text-sm">
        </div>

        <div>
          {{-- Cutoff mode affects how inferred orders are counted in cells na
               walang saved snapshot. Saved cells are unaffected. --}}
          <label class="block text-xs font-semibold text-gray-600 mb-1"
                 title="Affects only inferred cells (yellow). Saved cells are immutable.">
            Inferred Cutoff
          </label>
          <select x-model="cutoffMode" @change="reload()" class="border border-gray-300 rounded px-2 py-1 text-sm w-52">
            <option value="upload">Upload time (ads upload moment)</option>
            <option value="clock">Clock time (10:00 / 15:00 / 19:00)</option>
          </select>
        </div>

        <div>
          {{-- Show/hide the "⚠ inferred · as of HH:MM" badge sa inferred cells.
               Default Hide para hindi messy yung grid. Cell background tint
               still shows kung saved or inferred yung cell. --}}
          <label class="block text-xs font-semibold text-gray-600 mb-1"
                 title="Show/hide the cutoff time badge sa inferred cells.">
            Inferred Badge
          </label>
          <select x-model="showInferredBadge" class="border border-gray-300 rounded px-2 py-1 text-sm w-28">
            <option value="hide">Hide</option>
            <option value="show">Show</option>
          </select>
        </div>

        <button @click="reload()" class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium px-4 py-1.5 rounded">
          Apply
        </button>
        <span class="text-xs text-gray-500 ml-auto">
          Snapshots auto-save sa <a href="{{ route('ads_manager.cpp') }}" class="text-blue-600 hover:underline">/cpp</a> every Copy Table click.
        </span>
      </div>
      <div class="mt-3 tl-legend">
        <span><span class="swatch" style="background:#ffffff;"></span> Saved snapshot — Adspent + Orders + CPP</span>
        <span><span class="swatch" style="background:#fffdf6;"></span> Inferred — Orders only (from macro_output)</span>
        <span><span class="swatch" style="background:#f8fafc;"></span> Estimate — projected from earlier-today × historical ratio</span>
        <span><span class="swatch" style="background:#ffffff;border-color:#e5e7eb;"></span> No data</span>
      </div>
    </div>

    {{-- ── Grid (transposed: rows=date, cols=bucket) ──────────────── --}}
    <div class="bg-white border border-gray-200 rounded-lg p-4">
      <div class="flex items-center justify-between mb-2">
        <div class="text-sm text-gray-600">
          Rows = date · Columns = snapshot bucket · Click a cell for per-page detail (saved snapshots only).
        </div>
        <div class="text-xs text-gray-500" x-show="loading">⏳ Loading…</div>
      </div>

      <div class="tl-grid-wrap">
        <table class="tl-grid">
          <thead>
            <tr>
              <th class="tl-corner tl-date-col">Date</th>
              <template x-for="b in buckets" :key="b">
                <th x-text="b"></th>
              </template>
            </tr>
          </thead>
          <tbody>
            <template x-for="d in dates" :key="d">
              <tr>
                <th class="tl-date-col" x-text="fmtDate(d)"></th>
                <template x-for="b in buckets" :key="d + '|' + b">
                  <td x-bind:class="cellClass(b, d)"
                      @click="cellOf(b, d) && !cellOf(b, d).inferred && !cellOf(b, d).is_estimate && openDetail(d, b)">
                    <template x-if="cellOf(b, d)">
                      <div>
                        <template x-if="cellOf(b, d).spent != null">
                          <div><span class="label">Adspent</span> <span class="val" x-text="money(cellOf(b, d).spent)"></span></div>
                        </template>
                        <div>
                          <span class="label" x-text="cellOf(b, d).is_estimate ? 'Est. Orders' : 'Orders'"></span>
                          <span class="val" x-text="num(cellOf(b, d).orders)"></span>
                        </div>
                        <template x-if="cellOf(b, d).cpp != null">
                          <div><span class="label">CPP</span> <span class="val" x-text="money(cellOf(b, d).cpp)"></span></div>
                        </template>
                        {{-- Estimate label — italic muted, always shown for projections --}}
                        <template x-if="cellOf(b, d).is_estimate">
                          <div class="est-badge"
                               :title="'Projection — ' + (cellOf(b, d).estimate_source || '')">
                            projected
                          </div>
                        </template>
                        <template x-if="cellOf(b, d).inferred && showInferredBadge === 'show'">
                          <div class="inferred-badge"
                               x-text="inferredBadgeText(cellOf(b, d))"
                               :title="cellOf(b, d).cutoff_src === 'clock_fallback' ? 'No ads upload found for this bucket — fell back to clock cutoff' : ''">
                          </div>
                        </template>
                        <template x-if="!cellOf(b, d).inferred && !cellOf(b, d).is_estimate && cellOf(b, d).saved_at">
                          <div class="saved">
                            <div x-text="'saved ' + fmtSavedAt(cellOf(b, d).saved_at)"></div>
                            <template x-if="cellOf(b, d).ads_at">
                              <div x-text="'ads upload at ' + cellOf(b, d).ads_at"></div>
                            </template>
                          </div>
                        </template>
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
        start:      @json($start),
        end:        @json($end),
        preset:     'last7',
        cutoffMode: 'upload', // 'upload' | 'clock' — controls inferred cell cutoff
        showInferredBadge: 'hide', // 'hide' | 'show' — toggle the ⚠ inferred badge text

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

        // PH-local "now" for preset date computation. Server is PH-timed too,
        // so dates match what `Carbon::now('Asia/Manila')` would produce.
        phNow() {
          return new Date(new Date().toLocaleString('en-US', { timeZone: 'Asia/Manila' }));
        },
        toIso(d) { return d.toISOString().slice(0, 10); },

        applyPreset() {
          const now = this.phNow();
          let s, e;
          switch (this.preset) {
            case 'last7':
              e = now; s = new Date(now); s.setDate(s.getDate() - 6);
              break;
            case 'last14':
              e = now; s = new Date(now); s.setDate(s.getDate() - 13);
              break;
            case 'last30':
              e = now; s = new Date(now); s.setDate(s.getDate() - 29);
              break;
            case 'this_month':
              s = new Date(now.getFullYear(), now.getMonth(), 1);
              e = now;
              break;
            case 'last_month':
              s = new Date(now.getFullYear(), now.getMonth() - 1, 1);
              e = new Date(now.getFullYear(), now.getMonth(), 0); // last day of prev month
              break;
            case 'last_month_to_date':
              // Last month, from 1st up to today-of-month (e.g., today is May 13 →
              // Apr 1 to Apr 13). Clamps if last month has fewer days.
              s = new Date(now.getFullYear(), now.getMonth() - 1, 1);
              const lastMonthEnd = new Date(now.getFullYear(), now.getMonth(), 0);
              const tgtDay = Math.min(now.getDate(), lastMonthEnd.getDate());
              e = new Date(now.getFullYear(), now.getMonth() - 1, tgtDay);
              break;
            case 'this_year':
              s = new Date(now.getFullYear(), 0, 1);
              e = now;
              break;
            case 'custom':
              return; // do nothing — user picks dates manually
          }
          this.start = this.toIso(s);
          this.end   = this.toIso(e);
          this.reload();
        },

        async reload() {
          this.loading = true;
          try {
            const qs = new URLSearchParams({
              start: this.start,
              end:   this.end,
              cutoff_mode: this.cutoffMode,
            });
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

        cellOf(bucket, date) {
          return (this.cells[bucket] && this.cells[bucket][date]) || null;
        },

        cellClass(bucket, date) {
          const c = this.cellOf(bucket, date);
          if (!c) return 'tl-cell-empty';
          if (c.is_estimate) return 'tl-cell tl-cell-estimate'; // future projection (blue)
          if (c.inferred)    return 'tl-cell tl-cell-inferred'; // past inferred (amber)
          return 'tl-cell'; // saved snapshot (white)
        },

        // Compose the inferred-cell badge text — shows the cutoff time used.
        // Distinguishes between upload-based cutoff vs clock fallback.
        inferredBadgeText(c) {
          if (!c) return '';
          const t = c.cutoff_at || '—';
          if (c.cutoff_src === 'upload') return `⚠ inferred · as of ${t} (upload)`;
          if (c.cutoff_src === 'clock_fallback') return `⚠ inferred · as of ${t} (no upload, clock fallback)`;
          return `⚠ inferred · as of ${t} (clock)`;
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
          return `${months[+m - 1]} ${+d}, ${y}`;
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

<x-layout>
  <x-slot name="title">RTS</x-slot>
  <x-slot name="heading">RTS Monitoring</x-slot>

  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">

  <style>
    html, body { overflow: hidden !important; height: 100% !important; }

    /* Pagination buttons */
    .dataTables_paginate .paginate_button {
      padding: .2rem .6rem; margin: 0 2px; border-radius: .375rem;
      background: #374151 !important; color: #fff !important; border: none !important;
      cursor: pointer; font-size: 12px; display: inline-block;
    }
    .dataTables_paginate .paginate_button.current  { background: #2563eb !important; font-weight: 700; }
    .dataTables_paginate .paginate_button.disabled { opacity: .4; cursor: default; }
    .dataTables_paginate .paginate_button:hover:not(.disabled):not(.current) { background: #4b5563 !important; }

    /* Hide DataTables built-in controls (we render our own) */
    .dataTables_wrapper .dataTables_filter   { display: none; }
    .dataTables_wrapper .dataTables_info     { display: none; }
    .dataTables_wrapper .dataTables_length   { display: none; }
    .dataTables_wrapper .dataTables_paginate { display: none; }

    /* DataTables scroll sections */
    .dataTables_scrollHead { background: #f1f5f9; border-bottom: 2px solid #cbd5e1; }
    .dataTables_scrollHead table { margin: 0 !important; }
    .dataTables_scrollBody { flex: 1; }
    .dataTables_scrollFoot { background: #f8fafc; border-top: 2px solid #94a3b8; box-shadow: 0 -3px 10px rgba(0,0,0,0.08); }
    .dataTables_scrollFoot table { margin: 0 !important; }

    /* Remove DataTables outer border/margin quirks */
    .dataTables_wrapper { height: 100%; display: flex; flex-direction: column; }
    .dataTables_scroll   { flex: 1; overflow: hidden; display: flex; flex-direction: column; }

    .flatpickr-calendar { z-index: 9999 !important; }
  </style>

  {{-- Root: fixed below nav (nav = h-16 = 64px, position:fixed top-0) --}}
  <div style="position:fixed; top:64px; left:0; right:0; bottom:0; display:flex; flex-direction:column; overflow:hidden; background:#f3f4f6; z-index:10;">

    {{-- Filters bar --}}
    <div style="flex-shrink:0; padding:10px 16px 8px;">
      <form method="GET" action="{{ url('/jnt_rts') }}" id="rtsFilterForm"
            style="display:flex; flex-wrap:wrap; align-items:flex-end; gap:10px;
                   background:white; padding:10px 16px; border-radius:8px;
                   box-shadow:0 1px 3px rgba(0,0,0,0.1); border:1px solid #e2e8f0;">
        <div>
          <label style="display:block;font-size:11px;font-weight:600;color:#6b7280;margin-bottom:3px;text-transform:uppercase;letter-spacing:.04em;">Date Range</label>
          <input id="dateRange" type="text" placeholder="Select date range" readonly
                 style="border:1px solid #d1d5db;padding:5px 10px;border-radius:6px;background:white;cursor:pointer;font-size:13px;min-width:220px;">
          <input type="hidden" name="from" id="from" value="{{ $from ?? '' }}">
          <input type="hidden" name="to"   id="to"   value="{{ $to   ?? '' }}">
        </div>
        <div style="flex:1;"></div>
        <div>
          <label style="display:block;font-size:11px;font-weight:600;color:#6b7280;margin-bottom:3px;text-transform:uppercase;letter-spacing:.04em;">Search</label>
          <input id="globalSearch" type="text" placeholder="Search anything…"
                 style="border:1px solid #d1d5db;padding:5px 10px;border-radius:6px;font-size:13px;min-width:200px;">
        </div>
        <div>
          <label style="display:block;font-size:11px;font-weight:600;color:#6b7280;margin-bottom:3px;text-transform:uppercase;letter-spacing:.04em;">Item (exact)</label>
          <input id="itemExact" type="text" list="itemOptionsList" placeholder="Type or pick item…"
                 style="border:1px solid #0d9488;padding:5px 10px;border-radius:6px;font-size:13px;min-width:200px;">
          <datalist id="itemOptionsList">
            @foreach(($itemOptions ?? []) as $it)
              <option value="{{ $it }}"></option>
            @endforeach
          </datalist>
        </div>
        <button type="button" id="itemExactBtn"
                style="background:#0d9488;color:white;padding:6px 16px;border-radius:6px;border:none;cursor:pointer;font-size:13px;font-weight:600;">Search Item</button>
        <button type="submit"
                style="background:#2563eb;color:white;padding:6px 16px;border-radius:6px;border:none;cursor:pointer;font-size:13px;font-weight:600;">Apply</button>
        <a href="{{ url('/jnt_rts') }}"
           style="padding:6px 14px;border-radius:6px;border:1px solid #d1d5db;color:#374151;font-size:13px;text-decoration:none;background:white;display:inline-flex;align-items:center;">Reset</a>
      </form>
    </div>

    {{-- ── RTS donut charts (Full Range + Projection) — above the table ── --}}
    @if(!empty($full))
    <div style="flex-shrink:0; padding:0 16px 8px;">
      <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">

        {{-- Chart 1: RTS Projection (partial cohort, slideable) --}}
        <div style="background:white; border:1px solid #e2e8f0; border-radius:12px; box-shadow:0 1px 3px rgba(0,0,0,.08); padding:12px 16px;">
          <div style="display:flex; align-items:center; justify-content:space-between;">
            <h2 style="font-size:13px; font-weight:600; color:#1f2937; margin:0;">🔮 RTS Projection</h2>
            <span style="font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#b45309; background:#fef3c7; border-radius:9999px; padding:2px 8px;">Partial cohort · ≥300</span>
          </div>
          <p style="font-size:11px; color:#9ca3af; margin:2px 0 8px;">Default = <b>pinakalumang ≥300 shipments</b> (settled). Pwede mong i-adjust ang cutoff sa slider. Nagre-reset sa bawat filter.</p>
          <div style="margin-bottom:8px;">
            <div style="display:flex; align-items:center; justify-content:space-between; font-size:11px; margin-bottom:2px;">
              <span style="color:#6b7280;">Data up to</span>
              <span style="font-weight:600; color:#374151;" id="projCutoffLabel">—</span>
            </div>
            <input type="range" id="projSlider" min="0" max="100" value="0" oninput="onProjSlider()"
                   style="width:100%; accent-color:#4f46e5; cursor:pointer;">
            <div style="display:flex; justify-content:space-between; font-size:10px; color:#9ca3af; margin-top:1px;">
              <span>{{ \Carbon\Carbon::parse($from)->format('M j') }}</span>
              <span>{{ \Carbon\Carbon::parse($to)->format('M j') }}</span>
            </div>
          </div>
          @include('partials.rts-pie', array_merge($projection, ['pieId' => 'proj']))
        </div>

        {{-- Chart 2: Full selected range --}}
        <div style="background:white; border:1px solid #e2e8f0; border-radius:12px; box-shadow:0 1px 3px rgba(0,0,0,.08); padding:12px 16px;">
          <div style="display:flex; align-items:center; justify-content:space-between;">
            <h2 style="font-size:13px; font-weight:600; color:#1f2937; margin:0;">📊 Full Range</h2>
            <span style="font-size:10px; font-weight:600; text-transform:uppercase; letter-spacing:.04em; color:#9ca3af;">{{ \Carbon\Carbon::parse($from)->format('M j') }} → {{ \Carbon\Carbon::parse($to)->format('M j, Y') }}</span>
          </div>
          <p style="font-size:11px; color:#9ca3af; margin:2px 0 8px;">Lahat ng shipment sa piniling range (kasama pa ang in-transit).</p>
          <div style="margin-top:44px;">
            @include('partials.rts-pie', array_merge($full, ['pieId' => 'full']))
          </div>
        </div>

      </div>
    </div>
    @endif

    {{-- Controls: length left, info+pagination right --}}
    <div style="flex-shrink:0; padding:2px 16px 6px; display:flex; align-items:center; justify-content:space-between; gap:12px;">
      <div id="dt-length-slot" style="font-size:12px; color:#6b7280;"></div>
      <div style="display:flex; align-items:center; gap:14px;">
        <span id="dt-info-slot" style="font-size:12px; color:#6b7280; white-space:nowrap;"></span>
        <div id="dt-paging-slot"></div>
      </div>
    </div>

    @if (!empty($results) && count($results))
    {{-- Table area: flex:1, DataTables manages its own scroll via scrollY --}}
    <div style="flex:1; overflow:hidden; padding:0 16px 14px; display:flex; flex-direction:column;">
      <div style="flex:1; overflow:hidden; border-radius:8px; box-shadow:0 1px 4px rgba(0,0,0,0.1); border:1px solid #e2e8f0;">
        <table id="rtsTable" class="text-sm" style="width:100%;">
          <thead>
            <tr>
              <th class="px-3 py-2 border border-gray-300 text-left text-xs font-semibold text-gray-600 uppercase tracking-wide whitespace-nowrap">Date Range</th>
              <th class="px-3 py-2 border border-gray-300 text-left text-xs font-semibold text-gray-600 uppercase tracking-wide whitespace-nowrap">Sender</th>
              <th class="px-3 py-2 border border-gray-300 text-left text-xs font-semibold text-gray-600 uppercase tracking-wide whitespace-nowrap">Item</th>
              <th class="px-3 py-2 border border-gray-300 text-left text-xs font-semibold text-gray-600 uppercase tracking-wide whitespace-nowrap">COD</th>
              <th class="px-3 py-2 border border-gray-300 text-right text-xs font-semibold text-gray-600 uppercase tracking-wide whitespace-nowrap">Qty</th>
              <th class="px-3 py-2 border border-gray-300 text-right text-xs font-semibold text-gray-600 uppercase tracking-wide whitespace-nowrap">RTS Qty</th>
              <th class="px-3 py-2 border border-gray-300 text-right text-xs font-semibold text-gray-600 uppercase tracking-wide whitespace-nowrap">Del Qty</th>
              <th class="px-3 py-2 border border-gray-300 text-right text-xs font-semibold text-gray-600 uppercase tracking-wide whitespace-nowrap">Transit Qty</th>
              <th class="px-3 py-2 border border-gray-300 text-right text-xs font-semibold text-gray-600 uppercase tracking-wide whitespace-nowrap">RTS%</th>
              <th class="px-3 py-2 border border-gray-300 text-right text-xs font-semibold text-gray-600 uppercase tracking-wide whitespace-nowrap">Delivered%</th>
              <th class="px-3 py-2 border border-gray-300 text-right text-xs font-semibold text-gray-600 uppercase tracking-wide whitespace-nowrap">In Transit%</th>
              <th class="px-3 py-2 border border-gray-300 text-right text-xs font-semibold text-gray-600 uppercase tracking-wide whitespace-nowrap">Current RTS%</th>
              <th class="px-3 py-2 border border-gray-300 text-right text-xs font-semibold text-gray-600 uppercase tracking-wide whitespace-nowrap">MAX RTS%</th>
            </tr>
          </thead>
          <tbody>
            @foreach ($results as $r)
              @php
                $rtsColor = $r['rts_percent'] > 25 ? 'bg-red-100'
                          : ($r['rts_percent'] > 20 ? 'bg-orange-100'
                          : ($r['rts_percent'] > 15 ? 'bg-green-100' : 'bg-cyan-100'));
                $num = fn($v) => is_numeric($v) ? $v : null;
              @endphp
              <tr class="hover:bg-blue-50 transition-colors" style="background:white;">
                <td class="px-3 py-1.5 border border-gray-200 whitespace-nowrap text-gray-700 text-xs" data-start="{{ $r['start'] }}">{{ $r['date_range'] }}</td>
                <td class="px-3 py-1.5 border border-gray-200 whitespace-nowrap font-medium text-gray-800 text-xs">{{ $r['sender'] }}</td>
                <td class="px-3 py-1.5 border border-gray-200 whitespace-nowrap text-gray-700 text-xs">{{ $r['item'] }}</td>
                <td class="px-3 py-1.5 border border-gray-200 whitespace-nowrap text-gray-700 text-xs">{{ $r['cod'] }}</td>
                <td class="px-3 py-1.5 border border-gray-200 text-right text-gray-700 text-xs" data-raw="{{ (int)$r['quantity'] }}">{{ number_format((int)$r['quantity']) }}</td>
                <td class="px-3 py-1.5 border border-gray-200 text-right text-xs" data-raw="{{ (int)$r['rts_count'] }}" style="color:#b91c1c;">{{ number_format((int)$r['rts_count']) }}</td>
                <td class="px-3 py-1.5 border border-gray-200 text-right text-xs" data-raw="{{ (int)$r['delivered_count'] }}" style="color:#15803d;">{{ number_format((int)$r['delivered_count']) }}</td>
                <td class="px-3 py-1.5 border border-gray-200 text-right text-xs" data-raw="{{ (int)$r['transit_count'] }}" style="color:#1d4ed8;">{{ number_format((int)$r['transit_count']) }}</td>
                <td class="px-3 py-1.5 border border-gray-200 text-right font-semibold text-xs {{ $rtsColor }}"
                    data-order="{{ $r['rts_percent'] }}">{{ number_format($r['rts_percent'], 2) }}%</td>
                <td class="px-3 py-1.5 border border-gray-200 text-right text-gray-700 text-xs"
                    data-order="{{ $r['delivered_percent'] }}">{{ number_format($r['delivered_percent'], 2) }}%</td>
                <td class="px-3 py-1.5 border border-gray-200 text-right text-gray-700 text-xs"
                    data-order="{{ $r['transit_percent'] }}">{{ number_format($r['transit_percent'], 2) }}%</td>
                <td class="px-3 py-1.5 border border-gray-200 text-right text-gray-700 text-xs"
                    data-order="{{ $num($r['current_rts']) ?? -1 }}">
                  {{ is_numeric($r['current_rts']) ? number_format($r['current_rts'], 2) . '%' : 'N/A' }}
                </td>
                <td class="px-3 py-1.5 border border-gray-200 text-right text-gray-700 text-xs"
                    data-order="{{ $num($r['max_rts']) ?? -1 }}">
                  {{ is_numeric($r['max_rts']) ? number_format($r['max_rts'], 2) . '%' : 'N/A' }}
                </td>
              </tr>
            @endforeach
          </tbody>
          <tfoot>
            <tr>
              <td class="px-3 py-2 border border-gray-300 text-right text-xs font-bold text-gray-400 uppercase tracking-wide" colspan="4">Total</td>
              <td class="px-3 py-2 border border-gray-300 text-right font-bold text-gray-800 text-xs" id="tot-qty">—</td>
              <td class="px-3 py-2 border border-gray-300 text-right font-bold text-xs" id="tot-rts" style="color:#b91c1c;">—</td>
              <td class="px-3 py-2 border border-gray-300 text-right font-bold text-xs" id="tot-del" style="color:#15803d;">—</td>
              <td class="px-3 py-2 border border-gray-300 text-right font-bold text-xs" id="tot-transit" style="color:#1d4ed8;">—</td>
              <td class="px-3 py-2 border border-gray-300 text-right font-bold text-xs" id="tot-rts-pct" style="color:#b91c1c;">—</td>
              <td class="px-3 py-2 border border-gray-300 text-right font-bold text-xs" id="tot-del-pct" style="color:#15803d;">—</td>
              <td class="px-3 py-2 border border-gray-300 text-right font-bold text-xs" id="tot-transit-pct" style="color:#1d4ed8;">—</td>
              <td class="px-3 py-2 border border-gray-300 text-right text-gray-400 text-xs" colspan="2">—</td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
    @else
    <div style="flex:1; padding:0 16px;">
      <p style="color:#6b7280; font-size:14px;">No data to display. Please select a date range.</p>
    </div>
    @endif

  </div>{{-- end root --}}

  {{-- Scripts --}}
  <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.js"></script>
  <script>
    window.addEventListener('load', function () {
      if (!window.flatpickr) {
        var s = document.createElement('script');
        s.src = 'https://unpkg.com/flatpickr@4.6.13/dist/flatpickr.min.js';
        s.onload = initFlatpickr;
        document.body.appendChild(s);
      } else { initFlatpickr(); }
    });

    function ymd(d) {
      const p = n => String(n).padStart(2, '0');
      return d ? d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) : '';
    }

    function initFlatpickr() {
      try {
        const fromInit = document.getElementById('from').value || null;
        const toInit   = document.getElementById('to').value   || null;
        flatpickr('#dateRange', {
          mode: 'range', clickOpens: true, allowInput: false, dateFormat: 'Y-m-d',
          defaultDate: (fromInit && toInit) ? [fromInit, toInit] : undefined,
          onReady(_, __, inst) { if (fromInit && toInit) inst.input.value = fromInit + ' to ' + toInit; },
          onChange(dates) {
            if (dates.length === 1) {
              document.getElementById('from').value = ymd(dates[0]);
              document.getElementById('to').value   = ymd(dates[0]);
            } else if (dates.length === 2) {
              document.getElementById('from').value = ymd(dates[0]);
              document.getElementById('to').value   = ymd(dates[1]);
            }
          }
        });
      } catch (e) { console.error('Flatpickr:', e); }
    }
  </script>

  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
  <script>
    function fmtNum(n) { return Number(n).toLocaleString('en-PH'); }

    // ── Projection slider state + helpers ──
    let projRows = [];   // filtered rows (start + counts), sorted by date — set kada draw
    function setEl(id, v) { const el = document.getElementById(id); if (el) el.textContent = v; }
    function projFromDate() { return (document.getElementById('from') || {}).value || ''; }
    function offsetToDate(off) {
      const f = projFromDate(); if (!f) return f;
      const d = new Date(f + 'T00:00:00'); d.setDate(d.getDate() + off);
      const p = n => String(n).padStart(2, '0');
      return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
    }
    function dateToOffset(ds) {
      const f = projFromDate(); if (!f || !ds) return 0;
      return Math.max(0, Math.round((new Date(ds + 'T00:00:00') - new Date(f + 'T00:00:00')) / 86400000));
    }
    function default300Offset() {   // earliest date kung saan umabot sa >= 300 shipments
      let q = 0, last = projFromDate();
      for (const r of projRows) { q += r.qty; if (r.start) last = r.start; if (q >= 300) break; }
      return dateToOffset(last);
    }
    function renderProjection(cutoff) {   // cohort = rows na start <= cutoff
      let pq = 0, prts = 0, pdel = 0, ptr = 0;
      for (const r of projRows) {
        if (r.start && r.start > cutoff) continue;
        pq += r.qty; prts += r.rts; pdel += r.del; ptr += r.tr;
      }
      const pb = Math.max(1, pq);
      const a = Math.round(prts / pb * 1000) / 10, b = Math.round(pdel / pb * 1000) / 10, c = Math.round(ptr / pb * 1000) / 10;
      const s2 = a + b;
      const d = document.getElementById('proj-donut');
      if (d) d.style.background = `conic-gradient(#dc2626 0 ${a}%, #16a34a ${a}% ${s2}%, #2563eb ${s2}% 100%)`;
      setEl('proj-total', fmtNum(pq));
      setEl('proj-pct-rts', a.toFixed(1) + '%'); setEl('proj-cnt-rts', '(' + fmtNum(prts) + ')');
      setEl('proj-pct-del', b.toFixed(1) + '%'); setEl('proj-cnt-del', '(' + fmtNum(pdel) + ')');
      setEl('proj-pct-tr',  c.toFixed(1) + '%'); setEl('proj-cnt-tr',  '(' + fmtNum(ptr) + ')');
      setEl('projCutoffLabel', cutoff || '—');
    }
    function onProjSlider() {   // manual adjust (walang reset)
      const s = document.getElementById('projSlider');
      if (s) renderProjection(offsetToDate(parseInt(s.value || 0, 10)));
    }

    function updateTotals(dt) {
      let qty = 0, rts = 0, del = 0, transit = 0;
      projRows = [];
      dt.rows({ search: 'applied' }).nodes().each(function (row) {
        const cells = row.querySelectorAll('td[data-raw]');
        const sc = row.querySelector('td[data-start]');
        const q = parseInt(cells[0]?.dataset.raw || 0),
              r = parseInt(cells[1]?.dataset.raw || 0),
              d = parseInt(cells[2]?.dataset.raw || 0),
              t = parseInt(cells[3]?.dataset.raw || 0);
        qty += q; rts += r; del += d; transit += t;
        projRows.push({ start: sc ? sc.dataset.start : '', qty: q, rts: r, del: d, tr: t });
      });
      const t = Math.max(1, qty);
      document.getElementById('tot-qty').textContent         = fmtNum(qty);
      document.getElementById('tot-rts').textContent         = fmtNum(rts);
      document.getElementById('tot-del').textContent         = fmtNum(del);
      document.getElementById('tot-transit').textContent     = fmtNum(transit);
      document.getElementById('tot-rts-pct').textContent     = (rts / t * 100).toFixed(2) + '%';
      document.getElementById('tot-del-pct').textContent     = (del / t * 100).toFixed(2) + '%';
      document.getElementById('tot-transit-pct').textContent = (transit / t * 100).toFixed(2) + '%';

      // ── Live update ng Full Range donut mula sa na-filter/na-search na rows ──
      const pRts = Math.round(rts / t * 1000) / 10;
      const pDel = Math.round(del / t * 1000) / 10;
      const pTr  = Math.round(transit / t * 1000) / 10;
      const stop2 = pRts + pDel;
      const donut = document.getElementById('full-donut');
      if (donut) {
        donut.style.background = `conic-gradient(#dc2626 0 ${pRts}%, #16a34a ${pRts}% ${stop2}%, #2563eb ${stop2}% 100%)`;
      }
      const setTxt = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
      setTxt('full-total',   fmtNum(qty));
      setTxt('full-pct-rts', pRts.toFixed(1) + '%'); setTxt('full-cnt-rts', '(' + fmtNum(rts) + ')');
      setTxt('full-pct-del', pDel.toFixed(1) + '%'); setTxt('full-cnt-del', '(' + fmtNum(del) + ')');
      setTxt('full-pct-tr',  pTr.toFixed(1) + '%');  setTxt('full-cnt-tr',  '(' + fmtNum(transit) + ')');

      // ── Projection: slider (default = >=300 point), nagre-reset sa bawat filter change ──
      projRows.sort((a, b) => (a.start < b.start ? -1 : a.start > b.start ? 1 : 0));  // pinakaluma muna
      const projSlider = document.getElementById('projSlider');
      if (projSlider) {
        const maxOff = dateToOffset((document.getElementById('to') || {}).value || '');
        projSlider.max = Math.max(1, maxOff);
        projSlider.value = Math.min(default300Offset(), maxOff);   // reset sa bagong >=300 point (2a)
        renderProjection(offsetToDate(parseInt(projSlider.value, 10)));
      }
    }

    function updateInfo(dt) {
      const info = dt.page.info();
      const slot = document.getElementById('dt-info-slot');
      if (!slot) return;
      const filtered = info.recordsDisplay !== info.recordsTotal
        ? ` (filtered from ${fmtNum(info.recordsTotal)})` : '';
      slot.textContent = `Showing ${fmtNum(info.start + 1)}–${fmtNum(info.end)} of ${fmtNum(info.recordsDisplay)} entries${filtered}`;
    }

    function moveControls(dt) {
      const wrapper = dt.table().container();

      // Move length selector
      const lenEl  = wrapper.querySelector('.dataTables_length');
      const lenSlot = document.getElementById('dt-length-slot');
      if (lenEl && lenSlot && !lenSlot.contains(lenEl)) {
        lenEl.style.display = 'block';
        lenSlot.appendChild(lenEl);
      }

      // Move pagination
      const pagEl  = wrapper.querySelector('.dataTables_paginate');
      const pagSlot = document.getElementById('dt-paging-slot');
      if (pagEl && pagSlot && !pagSlot.contains(pagEl)) {
        pagEl.style.display = 'block';
        pagSlot.appendChild(pagEl);
      }
    }

    document.addEventListener('DOMContentLoaded', function () {
      const tableEl = document.getElementById('rtsTable');
      if (!tableEl) return;

      // Container is position:fixed top:64px bottom:0 (fills space below nav exactly).
      // scrollY = container height minus: filters(~82px) + controls(~34px) + scrollHead(~35px) + scrollFoot(~37px) + paddings(~24px)
      // container height = 100vh - 64px, so: scrollY = 100vh - 64 - 82 - 34 - 35 - 37 - 24 = 100vh - 276px
      const scrollY = 'calc(100vh - 280px)';

      const dt = $('#rtsTable').DataTable({
        scrollY: scrollY,
        scrollX: true,
        scrollCollapse: false,
        paging:   true,
        searching: true,
        ordering: true,
        info:     true,
        dom: 'lrtip',
        order: [[8, 'desc']],
        pageLength: 25,
        drawCallback: function () {
          const api = this.api();
          updateTotals(api);
          updateInfo(api);
          moveControls(api);
        }
      });

      updateTotals(dt);
      updateInfo(dt);
      moveControls(dt);

      const searchInput = document.getElementById('globalSearch');
      if (searchInput) {
        searchInput.addEventListener('input', function () {
          dt.search(this.value).draw();
        });
      }

      // ✅ EXACT item search (column-specific) — "1 x LIP TATTOO" ay hindi tatama
      // sa "11 x LIP TATTOO 2.0". Item column = index 2. Regex ^...$ = exact,
      // case-insensitive. Blangko = alisin ang filter.
      const ITEM_COL = 2;
      const itemExactInput = document.getElementById('itemExact');
      const itemExactBtn   = document.getElementById('itemExactBtn');
      function applyExactItem() {
        const v = (itemExactInput.value || '').trim();
        if (v === '') {
          dt.column(ITEM_COL).search('').draw();
        } else {
          const esc = v.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); // escape regex specials
          dt.column(ITEM_COL).search('^' + esc + '$', true, false).draw(); // regex=true, smart=false
        }
      }
      itemExactBtn?.addEventListener('click', applyExactItem);
      itemExactInput?.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); applyExactItem(); }
      });
      // Auto-clear ang exact filter kapag binura ang input
      itemExactInput?.addEventListener('input', function () {
        if ((this.value || '').trim() === '') dt.column(ITEM_COL).search('').draw();
      });
    });
  </script>
</x-layout>

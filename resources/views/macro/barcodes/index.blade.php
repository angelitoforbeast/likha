<x-layout>
  <x-slot name="title">Bundle Barcode Generator</x-slot>
  <x-slot name="heading"><div class="text-xl font-bold">🏷️ Bundle Barcode Generator</div></x-slot>

  <style>
    .bc-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 1px 2px rgba(0,0,0,0.04); }
    .bc-card-header { display:flex; align-items:center; justify-content:space-between; padding:12px 16px; border-bottom:1px solid #f1f5f9; flex-wrap:wrap; gap:8px; }
    .bc-title { font-size:14px; font-weight:600; color:#0f172a; }
    .bc-btn { display:inline-flex; align-items:center; gap:6px; background:#4f46e5; color:#fff; font-weight:600; font-size:13px; padding:8px 14px; border-radius:6px; }
    .bc-btn:hover { background:#4338ca; }
    .bc-btn:disabled { opacity:.5; cursor:not-allowed; }
    .bc-btn-ghost { display:inline-flex; align-items:center; gap:4px; background:transparent; color:#64748b; font-size:12px; padding:6px 12px; border-radius:6px; border:1px solid #e2e8f0; }
    .bc-btn-ghost:hover { background:#f1f5f9; color:#0f172a; }
    .bc-btn-green { background:#16a34a; color:#fff; font-weight:600; font-size:13px; padding:8px 14px; border-radius:6px; }
    .bc-btn-green:hover { background:#15803d; }
    .bc-counter { background:#f8fafc; border:1px solid #e2e8f0; padding:12px 16px; border-radius:8px; }
    .bc-counter .label { font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:.05em; font-weight:600; }
    .bc-counter .value { font-size:28px; font-weight:700; color:#0f172a; margin-top:2px; }
    .bc-table { width:100%; border-collapse:separate; border-spacing:0; font-size:12.5px; }
    .bc-table thead th { background:#f8fafc; color:#475569; font-size:10.5px; font-weight:600; text-transform:uppercase; letter-spacing:.04em; padding:8px 10px; text-align:left; border-bottom:2px solid #e2e8f0; }
    .bc-table tbody td { padding:6px 10px; border-bottom:1px solid #f1f5f9; }
    .bc-table tbody tr:hover td { background:#f8fafc; }
    .bc-mono { font-family:monospace; }
  </style>

  <div class="w-full flex flex-col gap-4 p-2">

    {{-- ── Controls ── --}}
    <div class="bc-card">
      <div class="bc-card-header">
        <div class="bc-title">📅 Pumili ng Date</div>
        <a href="{{ route('macro.barcodes.logs') }}" class="bc-btn-ghost">📜 Print Logs</a>
      </div>
      <div class="p-4 flex flex-wrap items-end gap-3">
        <div>
          <label class="block text-xs font-semibold text-slate-600 mb-1">Date (macro_output)</label>
          <input id="bcDate" type="text" readonly value="{{ $defaultDate }}"
                 class="border border-gray-300 p-2 rounded-md shadow-sm cursor-pointer bg-white w-48">
        </div>
        <button id="btnGenerate" type="button" class="bc-btn">🔄 Generate</button>
        <button id="btnYesterday" type="button" class="bc-btn-ghost">Kahapon</button>
        <div class="text-xs text-slate-400" id="bcStatus"></div>
      </div>
    </div>

    {{-- ── Summary ── --}}
    <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
      <div class="bc-counter"><div class="label">Bundles (item+date)</div><div class="value" id="cBundles">0</div></div>
      <div class="bc-counter"><div class="label">Waybills</div><div class="value" id="cWaybills">0</div></div>
      <div class="bc-counter">
        <div class="label">Print Labels (QR)</div>
        <button id="btnPrint" type="button" class="bc-btn-green mt-1" disabled>🖨️ Print QR Labels</button>
      </div>
    </div>

    {{-- ── Copy table (BARCODE, WAYBILL) ── --}}
    <div class="bc-card">
      <div class="bc-card-header">
        <div class="bc-title">📋 Copy Table — <span class="bc-mono">BARCODE</span> + <span class="bc-mono">WAYBILL</span></div>
        <button id="btnCopy" type="button" class="bc-btn" disabled>📄 Copy Table</button>
      </div>
      <div class="p-0 overflow-auto" style="max-height:460px;">
        <table class="bc-table">
          <thead><tr><th>BARCODE</th><th>WAYBILL</th></tr></thead>
          <tbody id="copyRows">
            <tr><td colspan="2" style="text-align:center;color:#94a3b8;padding:24px;">— Wala pang data, pindutin ang Generate —</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    {{-- ── Bundles preview ── --}}
    <div class="bc-card">
      <div class="bc-card-header"><div class="bc-title">📦 Bundles (item + date → barcode)</div></div>
      <div class="p-0 overflow-auto" style="max-height:400px;">
        <table class="bc-table">
          <thead><tr><th>Item Name</th><th style="text-align:right;">Count</th><th>Bundle Barcode</th></tr></thead>
          <tbody id="bundleRows">
            <tr><td colspan="3" style="text-align:center;color:#94a3b8;padding:24px;">—</td></tr>
          </tbody>
        </table>
      </div>
    </div>

  </div>

  {{-- flatpickr + QR code lib (CDN — gaya ng chart.js/flatpickr na ginagamit na) --}}
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
  <script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.js"></script>

  <script>
    const csrf = '{{ csrf_token() }}';
    let currentData = null;

    const el = (id) => document.getElementById(id);
    const bcDate = el('bcDate');
    function escapeHtml(s){ return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

    function fmtNiceDate(iso){
      const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || ''); if (!m) return iso || '';
      const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
      return `${months[+m[2]-1]} ${+m[3]}, ${m[1]}`;
    }

    // QR → GIF data URL (qrcode-generator: global `qrcode`, sync). cellSize, margin(px).
    function makeQrDataUrl(text){
      const qr = qrcode(0, 'M'); // 0 = auto-size, 'M' = error correction
      qr.addData(String(text));
      qr.make();
      return qr.createDataURL(5, 8);
    }

    flatpickr('#bcDate', { dateFormat: 'Y-m-d', defaultDate: bcDate.value, disableMobile: true, onChange: () => loadData() });

    async function loadData(){
      const date = bcDate.value;
      if (!date) return;
      el('bcStatus').textContent = 'Kinukuha…';
      el('btnGenerate').disabled = true;
      try {
        const res = await fetch(`/macro/barcodes/data?date=${encodeURIComponent(date)}`, { headers: { 'Accept':'application/json' } });
        if (!res.ok) { el('bcStatus').textContent = 'Error sa pag-load.'; return; }
        currentData = await res.json();
        render(currentData);
        el('bcStatus').textContent = `Na-load: ${fmtNiceDate(currentData.date)}`;
      } catch (e) {
        el('bcStatus').textContent = 'Error: ' + e.message;
      } finally {
        el('btnGenerate').disabled = false;
      }
    }

    function render(d){
      el('cBundles').textContent  = (d.totals.bundles || 0).toLocaleString();
      el('cWaybills').textContent = (d.totals.waybills || 0).toLocaleString();

      const rows = d.rows || [];
      el('copyRows').innerHTML = rows.length
        ? rows.map(r => `<tr><td class="bc-mono">${escapeHtml(r.barcode)}</td><td class="bc-mono">${escapeHtml(r.waybill)}</td></tr>`).join('')
        : '<tr><td colspan="2" style="text-align:center;color:#94a3b8;padding:24px;">— Walang waybill sa date na ito —</td></tr>';

      const bundles = d.bundles || [];
      el('bundleRows').innerHTML = bundles.length
        ? bundles.map(b => `<tr><td>${escapeHtml(b.item_name)}</td><td style="text-align:right;font-weight:600;">${b.count}</td><td class="bc-mono">${escapeHtml(b.barcode)}</td></tr>`).join('')
        : '<tr><td colspan="3" style="text-align:center;color:#94a3b8;padding:24px;">—</td></tr>';

      const has = rows.length > 0;
      el('btnCopy').disabled  = !has;
      el('btnPrint').disabled = !has;
    }

    // ── Copy table (tab-separated, may header) ──
    el('btnCopy').addEventListener('click', async () => {
      if (!currentData || !currentData.rows.length) return;
      const lines = ['BARCODE\tWAYBILL'];
      currentData.rows.forEach(r => lines.push(`${r.barcode}\t${r.waybill}`));
      try {
        await navigator.clipboard.writeText(lines.join('\n'));
        el('bcStatus').textContent = `Na-copy: ${currentData.rows.length} rows ✅`;
      } catch (e) {
        alert('Copy failed: ' + e.message);
      }
    });

    // ── Print QR labels ──
    el('btnPrint').addEventListener('click', async () => {
      if (!currentData || !currentData.bundles.length) return;
      const btn = el('btnPrint');
      btn.disabled = true; btn.textContent = 'Ginagawa ang QR…';
      try {
        const dateNice = fmtNiceDate(currentData.date);
        const labels = [];
        for (const b of currentData.bundles) {
          let img = '';
          try { img = `<img src="${makeQrDataUrl(b.barcode)}" alt="qr">`; }
          catch (e) { img = '<div style="color:#b91c1c;font-size:11px;">[QR error]</div>'; }
          labels.push(`
            <div class="label">
              ${img}
              <div class="item">${escapeHtml(b.item_name)}</div>
              <div class="meta">${escapeHtml(dateNice)}</div>
              <div class="meta"><strong>COUNT: ${b.count}</strong></div>
              <div class="code">${escapeHtml(b.barcode)}</div>
            </div>`);
        }

        // Log the print (audit)
        try {
          await fetch('{{ route('macro.barcodes.print') }}', {
            method: 'POST',
            headers: { 'Content-Type':'application/json', 'Accept':'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify({ date: currentData.date, bundle_count: currentData.totals.bundles, waybill_count: currentData.totals.waybills }),
          });
        } catch (e) { /* huwag i-block ang print kahit pumalya ang logging */ }

        const doc = `<!doctype html><html><head><meta charset="utf-8"><title>Bundle Barcodes — ${escapeHtml(dateNice)}</title>
          <style>
            body { font-family: Arial, sans-serif; margin: 12px; }
            h1 { font-size: 16px; margin: 0 0 12px; }
            .sheet { display:flex; flex-wrap:wrap; gap:10px; }
            .label { width:31%; box-sizing:border-box; border:1px solid #000; border-radius:6px; padding:10px; text-align:center; page-break-inside:avoid; }
            .label img { width:150px; height:150px; }
            .label .item { font-weight:bold; font-size:13px; margin-top:6px; word-break:break-word; }
            .label .meta { font-size:12px; color:#333; }
            .label .code { font-family:monospace; font-size:11px; margin-top:4px; word-break:break-all; color:#111; }
            @media print { @page { margin: 10mm; } .noprint { display:none; } }
          </style></head><body>
          <h1>Bundle Barcodes — ${escapeHtml(dateNice)} · ${currentData.totals.bundles} bundles · ${currentData.totals.waybills} waybills</h1>
          <div class="sheet">${labels.join('')}</div>
          <script>window.onload=function(){setTimeout(function(){window.print();},250);};<\/script>
          </body></html>`;

        const w = window.open('', '_blank');
        if (!w) { alert('Naka-block ang pop-up. Payagan ang pop-up para makapag-print.'); return; }
        w.document.open(); w.document.write(doc); w.document.close();
        el('bcStatus').textContent = `Na-print: ${currentData.totals.bundles} labels 🖨️`;
      } finally {
        btn.disabled = false; btn.textContent = '🖨️ Print QR Labels';
      }
    });

    el('btnGenerate').addEventListener('click', loadData);
    el('btnYesterday').addEventListener('click', () => {
      const y = new Date(); y.setDate(y.getDate() - 1);
      const iso = y.toISOString().slice(0,10);
      bcDate.value = iso;
      if (bcDate._flatpickr) bcDate._flatpickr.setDate(iso, false);
      loadData();
    });

    // Auto-load default (kahapon) on page open.
    loadData();
  </script>
</x-layout>

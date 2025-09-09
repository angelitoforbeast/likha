<x-layout>
  <x-slot name="heading">
    JNT • On-Delivery Counter
  </x-slot>

  {{-- CSRF META (needed for 419 fix) --}}
  <meta name="csrf-token" content="{{ csrf_token() }}">

  <style>
    table { border-collapse: collapse; margin-top: 8px; width: 100%; }
    th, td { border: 1px solid #ccc; padding: 6px; text-align: left; }
    th { background: #f2f2f2; }
    .controls { margin: 10px 0; display:flex; gap:10px; flex-wrap:wrap; align-items: center; }
    .controls .spacer { flex: 1; }
    button { padding: 6px 12px; cursor: pointer; }
    #summary { margin-top: 8px; }
    .counts-actions { display:flex; justify-content:flex-end; }
    .total-row td { font-weight: bold; }
    label { font-size: 14px; }
    #status { font-size: 13px; opacity: .8; }
  </style>

  <div class="bg-white rounded-xl p-4 shadow">
    <h2 class="text-xl font-semibold mb-2">Delivering Tracker</h2>

    <div class="controls">
      <input type="file" id="fileInput" />
      <button onclick="processFile()">Upload</button>
      <button onclick="resetAll()">Reset (Delivering + Counts)</button>
      <span class="spacer"></span>
      <label>
        Delivered date:
        <input type="date" id="deliveredDate" />
      </label>
      <span id="status"></span>
    </div>

    <div id="summary"></div>
  </div>

  <script>
    // --- Date helpers (LOCAL time) ---
    function todayYMD() { return new Date().toLocaleDateString('en-CA'); } // YYYY-MM-DD
    function nowDateTime() {
      const d = new Date();
      const ymd = d.toLocaleDateString('en-CA'); // YYYY-MM-DD
      const hms = d.toLocaleTimeString('en-GB'); // 24h HH:MM:SS
      return `${ymd} ${hms}`;
    }

    // Delivered date picker helpers
    const DELIV_DATE_KEY = 'delivered_date_selected';
    function initDeliveredDate() {
      const inp = document.getElementById('deliveredDate');
      const saved = localStorage.getItem(DELIV_DATE_KEY);
      inp.value = saved || todayYMD();
      inp.addEventListener('change', () => {
        const val = inp.value || todayYMD();
        localStorage.setItem(DELIV_DATE_KEY, val);
        const th = document.querySelector('#delivered-th');
        if (th) th.textContent = `Delivered (SigningTime = ${val}, new)`;
      });
    }
    function getDeliveredFilterYMD() {
      const inp = document.getElementById('deliveredDate');
      return (inp && inp.value) ? inp.value : (localStorage.getItem(DELIV_DATE_KEY) || todayYMD());
    }

    // Excel serial date -> JS Date
    function excelSerialToDate(n) {
      const utcDays = Math.floor(n - 25569);
      const utcValue = utcDays * 86400 * 1000;
      const d = new Date(utcValue);
      const frac = n - Math.floor(n);
      if (frac) d.setMilliseconds(d.getMilliseconds() + Math.round(frac * 86400 * 1000));
      return d;
    }
    function ymdFromValue(val) {
      if (!val && val !== 0) return null;
      let d = null;
      if (val instanceof Date) d = val;
      else if (typeof val === 'number') d = excelSerialToDate(val);
      else if (typeof val === 'string') {
        const s = val.includes('T') ? val : val.replace(' ', 'T');
        const tmp = new Date(s);
        if (!isNaN(tmp.getTime())) d = tmp;
        if (!d) {
          const t = new Date(val);
          if (!isNaN(t.getTime())) d = t;
        }
      }
      if (!d || isNaN(d.getTime())) return null;
      return d.toLocaleDateString('en-CA'); // YYYY-MM-DD
    }

    // Keys for localStorage (per-day)
    function keyDelivering()     { return "delivering_records_" + todayYMD(); } // [{waybill, firstSeen}]
    function keyCountsHistory()  { return "counts_history_"     + todayYMD(); } // [{ts,d,it,dv,fr}]
    function keySeenInTransit()  { return "seen_intransit_"     + todayYMD(); } // [waybill,...]
    function keySeenDelivered()  { return "seen_delivered_"     + todayYMD(); } // [waybill,...] (SigningTime=selected)
    function keySeenForReturn()  { return "seen_forreturn_"     + todayYMD(); } // [waybill,...] (must have been Delivering today)

    // --- UI table (header once, rows append, TOTAL row) ---
    function ensureCountsTable(deliveredDate) {
      const container = document.getElementById('summary');
      let table = container.querySelector('#counts-table');
      if (!table) {
        container.innerHTML = `
          <div class="counts-actions">
            <button onclick="copyFullTable()">Copy Table (with Headers)</button>
          </div>
          <table id="counts-table">
            <thead>
              <tr>
                <th>Date & Time (upload)</th>
                <th>Delivering (new since last)</th>
                <th>In Transit (new since last)</th>
                <th id="delivered-th">Delivered (SigningTime = ${deliveredDate}, new)</th>
                <th>For Return (new, must have been Delivering today)</th>
              </tr>
            </thead>
            <tbody id="counts-body"></tbody>
            <tfoot id="counts-foot">
              <tr id="counts-total-row" class="total-row">
                <td>TOTAL</td>
                <td>0</td>
                <td>0</td>
                <td>0</td>
                <td>0</td>
              </tr>
            </tfoot>
          </table>
        `;
      } else {
        const th = table.querySelector('#delivered-th');
        if (th) th.textContent = `Delivered (SigningTime = ${deliveredDate}, new)`;
      }
    }

    function appendCountsRow({ ts, dNew, itNew, dvNew, frNew }) {
      const tbody = document.getElementById('counts-body');
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${ts}</td>
        <td>${dNew}</td>
        <td>${itNew}</td>
        <td>${dvNew}</td>
        <td>${frNew}</td>
      `;
      tbody.appendChild(tr);
    }

    function updateTotalsFromHistory() {
      const hist = JSON.parse(localStorage.getItem(keyCountsHistory()) || '[]');
      const sums = hist.reduce((acc, r) => {
        acc.d  += Number(r.d || 0);
        acc.it += Number(r.it || 0);
        acc.dv += Number(r.dv || 0);
        acc.fr += Number(r.fr || 0);
        return acc;
      }, { d:0, it:0, dv:0, fr:0 });

      const cells = document.querySelectorAll('#counts-total-row td');
      if (cells.length) {
        cells[1].textContent = String(sums.d);
        cells[2].textContent = String(sums.it);
        cells[3].textContent = String(sums.dv);
        cells[4].textContent = String(sums.fr);
      }
    }

    function copyFullTable() {
      const table = document.getElementById('counts-table');
      if (!table) { alert('No table to copy yet.'); return; }

      const rows = [];
      const headers = Array.from(table.querySelectorAll('thead th')).map(th => th.textContent.trim());
      rows.push(headers.join('\t'));
      table.querySelectorAll('tbody tr').forEach(tr => {
        const vals = Array.from(tr.querySelectorAll('td')).map(td => td.textContent.trim());
        rows.push(vals.join('\t'));
      });
      const tfoot = table.querySelector('tfoot tr');
      if (tfoot) {
        const vals = Array.from(tfoot.querySelectorAll('td')).map(td => td.textContent.trim());
        rows.push(vals.join('\t'));
      }

      const tsv = rows.join('\n');
      if (navigator.clipboard?.writeText) {
        navigator.clipboard.writeText(tsv).then(() => {
          alert('Whole table copied! Paste into Google Sheets.');
        }).catch(() => fallbackCopy(tsv));
      } else {
        fallbackCopy(tsv);
      }
    }
    function fallbackCopy(text) {
      const ta = document.createElement('textarea');
      ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.focus(); ta.select();
      try { document.execCommand('copy'); alert('Copied! Paste into Google Sheets.'); }
      catch(e) { alert('Copy failed. Please select & copy manually.'); }
      document.body.removeChild(ta);
    }

    window.addEventListener('DOMContentLoaded', () => {
      initDeliveredDate();
      ensureCountsTable(getDeliveredFilterYMD());

      const hist = JSON.parse(localStorage.getItem(keyCountsHistory()) || '[]');
      if (hist.length) {
        hist.forEach(entry => appendCountsRow({
          ts: entry.ts || '',
          dNew:  entry.d  || 0,
          itNew: entry.it || 0,
          dvNew: entry.dv || 0,
          frNew: entry.fr || 0
        }));
        updateTotalsFromHistory();
      }
    });

    function resetAll() {
      localStorage.removeItem(keyDelivering());
      localStorage.removeItem(keySeenInTransit());
      localStorage.removeItem(keySeenDelivered());
      localStorage.removeItem(keySeenForReturn());
      localStorage.removeItem(keyCountsHistory());
      document.getElementById('summary').innerHTML = "";
      alert("Reset done: cleared Delivering history, seen sets, and today's displayed counts.");
      ensureCountsTable(getDeliveredFilterYMD());
    }

    // --- SINGLE processFile(): XHR with CSRF + progress ---
    function processFile() {
      const file = document.getElementById('fileInput').files[0];
      if (!file) return alert("Please select a file.");

      const statusEl = document.getElementById('status');
      const deliveredFilterYMD = getDeliveredFilterYMD();
      ensureCountsTable(deliveredFilterYMD);

      // Per-upload temp
      let deliveringNewThisUpload = 0;
      const tsUpload = nowDateTime();
      const nowTime  = tsUpload.split(' ')[1];

      // Persisted "Delivering today" history
      let storedDelivering = JSON.parse(localStorage.getItem(keyDelivering()) || "[]");
      const deliveringSet = new Set(storedDelivering.map(i => i.waybill));

      const seenInTransit  = new Set(JSON.parse(localStorage.getItem(keySeenInTransit()) || "[]"));
      const seenDelivered  = new Set(JSON.parse(localStorage.getItem(keySeenDelivered()) || "[]"));
      const seenForReturn  = new Set(JSON.parse(localStorage.getItem(keySeenForReturn()) || "[]"));

      // Build form
      const fd = new FormData();
      fd.append('file', file);
      fd.append('delivered_date', deliveredFilterYMD);

      const tokenEl = document.querySelector('meta[name="csrf-token"]');
      if (!tokenEl) { alert('Missing CSRF token meta'); return; }

      const xhr = new XMLHttpRequest();
      xhr.open("POST", "{{ route('jnt.ondel.process') }}", true);
      xhr.setRequestHeader("X-CSRF-TOKEN", tokenEl.content);
      xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest");
      xhr.withCredentials = true;

      xhr.upload.onprogress = function (e) {
        if (e.lengthComputable) {
          const percent = Math.round((e.loaded / e.total) * 100);
          statusEl.textContent = `Uploading… ${percent}%`;
        } else {
          statusEl.textContent = 'Uploading…';
        }
      };

      xhr.onerror = function () {
        statusEl.textContent = '';
        alert('Upload failed (network error).');
      };

      xhr.onload = function () {
        if (xhr.status !== 200) {
          statusEl.textContent = '';
          alert('Server error: ' + xhr.status + ' ' + (xhr.responseText || ''));
          return;
        }

        statusEl.textContent = 'Processing…';
        let data;
        try { data = JSON.parse(xhr.responseText); }
        catch { statusEl.textContent = ''; alert('Bad JSON response from server.'); return; }

        const dAll  = new Set(data.dAll || []);
        const itAll = new Set(data.itAll || []);
        const dvAll = new Set(data.dvAll || []);
        const frAll = new Set(data.frAll || []);

        for (const wb of dAll) {
          if (!deliveringSet.has(wb)) {
            storedDelivering.push({ waybill: wb, firstSeen: nowTime });
            deliveringSet.add(wb);
            deliveringNewThisUpload++;
          }
        }

        const inTransitNewSet = new Set([...itAll].filter(x => !seenInTransit.has(x)));
        const deliveredNewSet = new Set([...dvAll].filter(x => !seenDelivered.has(x)));
        const forReturnEligible = new Set([...frAll].filter(x => deliveringSet.has(x)));
        const forReturnNewSet   = new Set([...forReturnEligible].filter(x => !seenForReturn.has(x)));

        localStorage.setItem(keyDelivering(), JSON.stringify(storedDelivering));
        const union = (a, b) => { b.forEach(v => a.add(v)); return a; };
        localStorage.setItem(keySeenInTransit(), JSON.stringify([...union(seenInTransit, inTransitNewSet)]));
        localStorage.setItem(keySeenDelivered(), JSON.stringify([...union(seenDelivered, deliveredNewSet)]));
        localStorage.setItem(keySeenForReturn(), JSON.stringify([...union(seenForReturn, forReturnNewSet)]));

        appendCountsRow({
          ts: tsUpload,
          dNew: deliveringNewThisUpload,
          itNew: inTransitNewSet.size,
          dvNew: deliveredNewSet.size,
          frNew: forReturnNewSet.size
        });

        const histKey = keyCountsHistory();
        const prevHist = JSON.parse(localStorage.getItem(histKey) || '[]');
        prevHist.push({ ts: tsUpload, d: deliveringNewThisUpload, it: inTransitNewSet.size, dv: deliveredNewSet.size, fr: forReturnNewSet.size });
        localStorage.setItem(histKey, JSON.stringify(prevHist));

        updateTotalsFromHistory();
        statusEl.textContent = 'Done.';
        setTimeout(() => statusEl.textContent = '', 1200);
      };

      xhr.send(fd);
    }
  </script>
</x-layout>

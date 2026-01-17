<x-layout>
  <x-slot name="title">J&T Stickers</x-slot>

  <x-slot name="heading">
    <div class="text-lg font-semibold">J&T Stickers</div>
  </x-slot>

  @php
    $filter_date = $filter_date ?? '';
    $limits = $server_limits ?? [
      'max_file_uploads' => 20,
      'upload_max_filesize' => '0',
      'post_max_size' => '0',
    ];
  @endphp

  <style>
    .card { border: 1px solid #e5e7eb; border-radius: 14px; padding: 16px; background: #fff; }
    .btn { border: 1px solid #d1d5db; border-radius: 10px; padding: 8px 12px; font-size: 14px; background: #fff; }
    .btn-primary { border-color: #111827; background: #111827; color: #fff; }
    .btn-danger { border-color: #ef4444; color: #ef4444; }
    .btn-warn { border-color: #f59e0b; color: #92400e; background:#fffbeb; }
    .btn:disabled { opacity: .5; cursor: not-allowed; }

    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
    .muted { color: #6b7280; font-size: 13px; }

    .input { width: 220px; border:1px solid #d1d5db; border-radius:12px; padding:8px 10px; }
    .pill { display:inline-flex; align-items:center; gap:8px; padding:6px 12px; border-radius:999px; border:1px solid #e5e7eb; font-size:12px; background:#fafafa; }
    .pill-ok { background:#ecfdf5; border-color:#a7f3d0; color:#065f46; }
    .pill-bad { background:#fff1f2; border-color:#fecaca; color:#9f1239; }
    .pill-warn { background:#fffbeb; border-color:#fde68a; color:#92400e; }
    .pill-info { background:#eff6ff; border-color:#bfdbfe; color:#1e40af; }

    .badge { padding:2px 10px; border-radius:999px; font-size:12px; display:inline-block; border:1px solid transparent; }
    .b-ok { background:#ecfdf5; border-color:#a7f3d0; color:#065f46; }
    .b-dup { background:#fff1f2; border-color:#fecaca; color:#9f1239; }
    .b-miss { background:#eff6ff; border-color:#bfdbfe; color:#1e40af; }
    .b-warn { background:#fffbeb; border-color:#fde68a; color:#92400e; }

    table { width: 100%; border-collapse: collapse; }
    th, td { border-bottom: 1px solid #eee; padding: 10px 10px; text-align: left; vertical-align: top; }
    th { font-size: 12px; color: #6b7280; font-weight: 700; }
    .nowrap { white-space: nowrap; }

    .row-bad td { background:#fff7f7; }
    .row-mid td { background:#fffbeb; }

    .bar { height: 10px; border-radius: 999px; background: #f3f4f6; overflow: hidden; border: 1px solid #e5e7eb; }
    .bar > div { height: 100%; width: 0%; background: #111827; transition: width .2s ease; }
  </style>

  {{-- CSRF for fetch --}}
  <meta name="csrf-token" content="{{ csrf_token() }}">

  <div class="p-4 space-y-4">

    {{-- Controls --}}
    <div class="card space-y-3">
      <div class="flex flex-wrap items-end gap-3">
        <div>
          <div class="text-sm font-medium mb-1">Filter Date (DB reference)</div>
          <input id="filter_date" type="date" value="{{ $filter_date }}" class="input" />
          <div class="muted mt-1">
            DB match: <span class="mono">DATE(STR_TO_DATE(TIMESTAMP, '%H:%i %d-%m-%Y'))</span>
          </div>
        </div>

        <div class="min-w-[360px]">
          <div class="text-sm font-medium mb-1">Sticker PDF file(s) (Preview / Extract)</div>
          <input id="pdfInput" type="file" multiple accept=".pdf"
                 class="block w-full border border-gray-300 rounded-lg p-2" />
          <div class="muted mt-1">
            Server limits now: <span class="mono">max_file_uploads={{ (int)$limits['max_file_uploads'] }}</span>,
            <span class="mono">post_max_size={{ $limits['post_max_size'] }}</span>,
            <span class="mono">upload_max_filesize={{ $limits['upload_max_filesize'] }}</span>
          </div>
          <div id="uploadLimitMsg" class="mono mt-1" style="display:none;"></div>
        </div>

        <div class="flex flex-wrap gap-2">
          <button id="btnExtract" class="btn btn-primary" type="button">1) Extract (PDF Preview)</button>
          <button id="btnDb" class="btn btn-warn" type="button">2) Show Waybills from DB</button>
          <button id="btnCompare" class="btn btn-primary" type="button">3) Compare / Check</button>
          <button id="btnCommit" class="btn btn-primary" type="button" disabled>COMMIT (Upload All PDFs)</button>
          <button id="btnReset" class="btn btn-danger" type="button">Reset</button>
        </div>
      </div>

      <div class="muted">
        Rule: <b>1 page = 1 waybill</b>. If a page has <b>0</b> JT waybill → Missing. If it has <b>2+</b> → Ambiguous.
      </div>

      <div class="space-y-2">
        <div class="text-sm font-medium">Progress</div>
        <div class="bar"><div id="progressBar"></div></div>
        <div id="progressText" class="muted">Idle.</div>
      </div>
    </div>

    {{-- Summary (emphasized, turns red if issues) --}}
    <div class="card space-y-2">
      <div class="font-semibold">Summary</div>
      <div id="summaryPills" class="flex flex-wrap gap-2"></div>
      <div id="summaryBig" class="mono" style="font-weight:700; font-size:14px;"></div>
    </div>

    {{-- One Table Output --}}
    <div class="card">
      <div class="flex items-center justify-between gap-3 mb-3">
        <div class="font-semibold">One Table Output</div>
        <div class="flex gap-2">
          <button id="btnCopyAll" class="btn" type="button" disabled>Copy ALL (Unique Waybills)</button>
        </div>
      </div>

      <div class="overflow-auto">
        <table id="wbTable">
          <thead>
            <tr>
              <th class="nowrap">WAYBILL</th>
              <th class="nowrap">STATUS</th>
              <th class="nowrap">PDF COUNT</th>
              <th class="nowrap">DB COUNT</th>
              <th class="nowrap">DUPLICATE COUNT</th>
              <th>FILE NAME(S)</th>
            </tr>
          </thead>
          <tbody id="wbTbody">
            <tr><td colspan="6" class="muted">No data yet. Use buttons 1 → 2 → 3.</td></tr>
          </tbody>
        </table>
      </div>

      <div class="muted mt-2">
        Sorting: duplicates/missing first, then OK.
      </div>
    </div>
  </div>

  {{-- PDF.js --}}
  <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.10.377/pdf.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.10.377/pdf.worker.min.js"></script>

  <script>
    const pdfjsLib = window['pdfjs-dist/build/pdf'];
    pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.10.377/pdf.worker.min.js';

    const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    // ===== State =====
    let selectedFiles = []; // File[]
    let pdfItems = [];      // {waybill,file,page}
    let pdfMeta = { files:0, pages_total:0, pages_ok:0, pages_missing:0, pages_ambiguous:0 };

    let dbWaybills = [];    // array of waybills (may include duplicates)
    let dbMeta = { filter_date:'', count:0 };

    let tableRows = [];     // final rows for display after compare
    let compareMeta = { ok:0, dup_pdf:0, miss_db:0, miss_pdf:0, dup_db:0 };

    // ===== Elements =====
    const elPdfInput = document.getElementById('pdfInput');
    const elFilterDate = document.getElementById('filter_date');

    const btnExtract = document.getElementById('btnExtract');
    const btnDb = document.getElementById('btnDb');
    const btnCompare = document.getElementById('btnCompare');
    const btnCommit = document.getElementById('btnCommit');
    const btnReset = document.getElementById('btnReset');

    const progressBar = document.getElementById('progressBar');
    const progressText = document.getElementById('progressText');

    const summaryPills = document.getElementById('summaryPills');
    const summaryBig = document.getElementById('summaryBig');
    const uploadLimitMsg = document.getElementById('uploadLimitMsg');

    const wbTbody = document.getElementById('wbTbody');

    const btnCopyAll = document.getElementById('btnCopyAll');

    // ===== Helpers =====
    function setProgress(pct, text) {
      progressBar.style.width = `${Math.max(0, Math.min(100, pct))}%`;
      progressText.textContent = text || '';
    }

    function uniq(arr) {
      return Array.from(new Set(arr));
    }

    function statusBadgeClass(status) {
      if (status === 'DUPLICATE_PDF') return 'b-dup';
      if (status === 'MISSING_IN_DB' || status === 'MISSING_IN_PDF') return 'b-miss';
      if (status === 'DUPLICATE_DB') return 'b-warn';
      if (status === 'PDF_ONLY') return 'b-warn';
      return 'b-ok';
    }

    function rowClass(status) {
      if (status === 'DUPLICATE_PDF') return 'row-bad';
      if (status !== 'OK') return 'row-mid';
      return '';
    }

    function severityOf(status) {
      if (status === 'DUPLICATE_PDF') return 4;
      if (status === 'MISSING_IN_DB' || status === 'MISSING_IN_PDF') return 3;
      if (status === 'DUPLICATE_DB') return 2;
      if (status === 'PDF_ONLY') return 1;
      return 0;
    }

    function renderSummary() {
      const hasCompare = tableRows.length > 0;

      // Emphasize: red if any missing/dup/errors
      const hasProblem =
        (pdfMeta.pages_missing || 0) > 0 ||
        (pdfMeta.pages_ambiguous || 0) > 0 ||
        (compareMeta.dup_pdf || 0) > 0 ||
        (compareMeta.miss_db || 0) > 0 ||
        (compareMeta.miss_pdf || 0) > 0;

      const pill = (label, value, cls) => `
        <span class="pill ${cls}">
          ${label}: <b>${value}</b>
        </span>
      `;

      const pdfCls = ((pdfMeta.pages_missing||0) > 0 || (pdfMeta.pages_ambiguous||0) > 0) ? 'pill-bad' : 'pill-info';
      const dbCls = (dbMeta.count||0) > 0 ? 'pill-info' : 'pill-warn';

      const cmpOkCls = hasCompare ? 'pill-ok' : 'pill';
      const cmpBadCls = hasCompare ? 'pill-bad' : 'pill';

      summaryPills.innerHTML = [
        pill('PDF Files', pdfMeta.files || 0, 'pill-info'),
        pill('PDF Pages Total', pdfMeta.pages_total || 0, 'pill-info'),
        pill('PDF OK Pages', pdfMeta.pages_ok || 0, 'pill-ok'),
        pill('PDF Missing Pages', pdfMeta.pages_missing || 0, (pdfMeta.pages_missing||0)>0 ? 'pill-bad' : pdfCls),
        pill('PDF Ambiguous Pages', pdfMeta.pages_ambiguous || 0, (pdfMeta.pages_ambiguous||0)>0 ? 'pill-bad' : pdfCls),

        pill('DB Date', dbMeta.filter_date || (elFilterDate.value || ''), dbCls),
        pill('DB Waybills', dbMeta.count || 0, dbCls),

        ...(hasCompare ? [
          pill('Compare OK', compareMeta.ok || 0, cmpOkCls),
          pill('Duplicate (PDF)', compareMeta.dup_pdf || 0, (compareMeta.dup_pdf||0)>0 ? 'pill-bad' : 'pill-info'),
          pill('Missing in DB', compareMeta.miss_db || 0, (compareMeta.miss_db||0)>0 ? 'pill-bad' : 'pill-info'),
          pill('Missing in PDF', compareMeta.miss_pdf || 0, (compareMeta.miss_pdf||0)>0 ? 'pill-bad' : 'pill-info'),
        ] : []),
      ].join('');

      // Big single-line summary you asked to emphasize
      const big = [
        `Summary`,
        `PDF Files: ${pdfMeta.files||0}`,
        `PDF Pages Total: ${pdfMeta.pages_total||0}`,
        `PDF OK Pages: ${pdfMeta.pages_ok||0}`,
        `PDF Missing Pages: ${pdfMeta.pages_missing||0}`,
        `PDF Ambiguous Pages: ${pdfMeta.pages_ambiguous||0}`,
        `DB Date: ${dbMeta.filter_date || (elFilterDate.value || '')}`,
        `DB Waybills: ${dbMeta.count||0}`,
        ...(hasCompare ? [
          `Compare OK: ${compareMeta.ok||0}`,
          `Duplicate (PDF): ${compareMeta.dup_pdf||0}`,
          `Missing in DB: ${compareMeta.miss_db||0}`,
          `Missing in PDF: ${compareMeta.miss_pdf||0}`,
        ] : []),
      ].join('  •  ');

      summaryBig.textContent = big;
      summaryBig.style.color = hasProblem ? '#9f1239' : '#111827'; // red if problems
    }

    function renderTable(rows) {
      tableRows = rows || [];

      if (!tableRows.length) {
        wbTbody.innerHTML = `<tr><td colspan="6" class="muted">No rows yet. Use buttons 1 → 2 → 3.</td></tr>`;
        btnCopyAll.disabled = true;
        btnCommit.disabled = true;
        renderSummary();
        return;
      }

      // Enable copy. Commit enabled only after compare and user has loaded both sides
      btnCopyAll.disabled = false;

      wbTbody.innerHTML = tableRows.map(r => {
        const st = r.status || 'OK';
        return `
          <tr class="${rowClass(st)}">
            <td class="mono nowrap">${escapeHtml(r.waybill || '')}</td>
            <td class="nowrap"><span class="badge ${statusBadgeClass(st)}">${st}</span></td>
            <td class="mono nowrap">${r.pdf_count || 0}</td>
            <td class="mono nowrap">${r.db_count || 0}</td>
            <td class="mono nowrap">${r.duplicate_count || 0}</td>
            <td class="mono">${escapeHtml(r.files || '')}</td>
          </tr>
        `;
      }).join('');

      renderSummary();
    }

    function escapeHtml(s) {
      return String(s)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
    }

    function computeCompareRows() {
      // Build PDF map
      const pdfMap = {}; // waybill => {count:int, files: {fn:cnt}}
      for (const it of pdfItems) {
        const wb = (it.waybill || '').trim();
        if (!wb) continue;
        const fn = (it.file || '').trim();
        if (!pdfMap[wb]) pdfMap[wb] = { count:0, files:{} };
        pdfMap[wb].count += 1;
        if (fn) pdfMap[wb].files[fn] = (pdfMap[wb].files[fn] || 0) + 1;
      }

      // Build DB map (count duplicates too)
      const dbMap = {};
      for (const wbRaw of dbWaybills) {
        const wb = String(wbRaw || '').trim();
        if (!wb) continue;
        dbMap[wb] = (dbMap[wb] || 0) + 1;
      }

      const all = uniq([...Object.keys(pdfMap), ...Object.keys(dbMap)]);

      const rows = [];
      for (const wb of all) {
        const pdfCount = (pdfMap[wb]?.count) || 0;
        const dbCount = dbMap[wb] || 0;

        let fileList = '';
        if (pdfMap[wb]?.files) {
          const parts = [];
          for (const [fn, cnt] of Object.entries(pdfMap[wb].files)) {
            parts.push(cnt > 1 ? `${fn} ×${cnt}` : fn);
          }
          fileList = parts.join('; ');
        }

        let status = 'OK';
        if (pdfCount > 1) status = 'DUPLICATE_PDF';
        else if (pdfCount >= 1 && dbCount === 0) status = 'MISSING_IN_DB';
        else if (dbCount >= 1 && pdfCount === 0) status = 'MISSING_IN_PDF';
        else if (dbCount > 1) status = 'DUPLICATE_DB';
        else if (pdfCount === 1 && dbCount === 1) status = 'OK';
        else if (pdfCount >= 1 && dbCount >= 1) status = 'OK';

        rows.push({
          waybill: wb,
          status,
          pdf_count: pdfCount,
          db_count: dbCount,
          duplicate_count: Math.max(0, pdfCount - 1),
          files: fileList,
          severity: severityOf(status),
        });
      }

      rows.sort((a,b) => {
        if (a.severity !== b.severity) return b.severity - a.severity;
        if (a.pdf_count !== b.pdf_count) return b.pdf_count - a.pdf_count;
        if (a.db_count !== b.db_count) return b.db_count - a.db_count;
        return (a.waybill || '').localeCompare(b.waybill || '');
      });

      // Compare summary
      compareMeta = {
        ok: rows.filter(r => r.status === 'OK').length,
        dup_pdf: rows.filter(r => r.status === 'DUPLICATE_PDF').length,
        miss_db: rows.filter(r => r.status === 'MISSING_IN_DB').length,
        miss_pdf: rows.filter(r => r.status === 'MISSING_IN_PDF').length,
        dup_db: rows.filter(r => r.status === 'DUPLICATE_DB').length,
      };

      return rows;
    }

    function getUniqueWaybillsFromTable() {
      const wbs = [];
      for (const r of tableRows) {
        if (r.waybill) wbs.push(r.waybill);
      }
      return uniq(wbs).join('\n');
    }

    async function copyText(text) {
      if (!text) return;
      try {
        await navigator.clipboard.writeText(text);
      } catch (e) {
        const ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
      }
    }

    // ===== Events =====
    elPdfInput.addEventListener('change', () => {
      selectedFiles = Array.from(elPdfInput.files || []);
      // Show local warning if selection > typical server max_file_uploads
      const serverMax = {{ (int)$limits['max_file_uploads'] }};
      if (selectedFiles.length > serverMax) {
        uploadLimitMsg.style.display = 'block';
        uploadLimitMsg.style.color = '#92400e';
        uploadLimitMsg.textContent = `Note: Selected ${selectedFiles.length}. Server max_file_uploads is ${serverMax}. COMMIT will auto-upload in chunks to avoid missing files.`;
      } else {
        uploadLimitMsg.style.display = 'none';
        uploadLimitMsg.textContent = '';
      }
    });

    btnCopyAll.addEventListener('click', async () => {
      await copyText(getUniqueWaybillsFromTable());
    });

    btnReset.addEventListener('click', () => {
      selectedFiles = [];
      pdfItems = [];
      dbWaybills = [];
      pdfMeta = { files:0, pages_total:0, pages_ok:0, pages_missing:0, pages_ambiguous:0 };
      dbMeta = { filter_date:'', count:0 };
      compareMeta = { ok:0, dup_pdf:0, miss_db:0, miss_pdf:0, dup_db:0 };
      tableRows = [];

      elPdfInput.value = '';
      setProgress(0, 'Idle.');
      renderTable([]);
      uploadLimitMsg.style.display = 'none';
      btnCommit.disabled = true;
    });

    // 1) Extract PDFs (client-side preview)
    btnExtract.addEventListener('click', async () => {
      if (!selectedFiles.length) {
        alert('Select PDF file(s) first.');
        return;
      }

      btnExtract.disabled = true;
      btnDb.disabled = true;
      btnCompare.disabled = true;
      btnCommit.disabled = true;

      pdfItems = [];
      pdfMeta = { files: selectedFiles.length, pages_total:0, pages_ok:0, pages_missing:0, pages_ambiguous:0 };

      setProgress(0, 'Starting PDF preview extraction...');

      try {
        let fileIdx = 0;
        for (const file of selectedFiles) {
          fileIdx++;
          setProgress(0, `Loading file ${fileIdx}/${selectedFiles.length}: ${file.name}`);

          const buffer = await file.arrayBuffer();
          const pdf = await pdfjsLib.getDocument({ data: buffer }).promise;
          const totalPages = pdf.numPages;

          for (let pageNum = 1; pageNum <= totalPages; pageNum++) {
            pdfMeta.pages_total++;

            // Update progress with weighted estimate
            const approx = Math.round((pdfMeta.pages_total / Math.max(1, (pdfMeta.pages_total + (totalPages - pageNum)))) * 100);
            setProgress(Math.min(99, approx), `Extracting page ${pageNum}/${totalPages} (${file.name})`);

            const page = await pdf.getPage(pageNum);
            const content = await page.getTextContent();

            // Fast: join all strings, then regex
            const text = content.items.map(i => i.str).join(' ');
            const matches = (text.match(/\bJT\d+\b/g) || []).map(s => s.trim());
            const candidates = uniq(matches);

            if (candidates.length === 0) {
              pdfMeta.pages_missing++;
            } else if (candidates.length > 1) {
              pdfMeta.pages_ambiguous++;
            } else {
              pdfMeta.pages_ok++;
              pdfItems.push({
                waybill: candidates[0],
                file: file.name,
                page: pageNum,
              });
            }

            // Yield to UI occasionally
            if (pageNum % 25 === 0) await new Promise(r => setTimeout(r, 0));
          }
        }

        setProgress(100, `Done. Extracted OK pages: ${pdfMeta.pages_ok}/${pdfMeta.pages_total}.`);
      } catch (e) {
        console.error(e);
        alert('PDF extraction error. Check console.');
        setProgress(0, 'Error during extraction.');
      } finally {
        btnExtract.disabled = false;
        btnDb.disabled = false;
        btnCompare.disabled = false;
        renderSummary();

        // Show PDF-only table (optional): we keep table empty until compare; but you asked to show extracted data after upload
        const rowsPdfOnly = pdfItems.map(it => ({
          waybill: it.waybill,
          status: 'PDF_ONLY',
          pdf_count: 1,
          db_count: 0,
          duplicate_count: 0,
          files: `${it.file} (p${it.page})`,
          severity: 1,
        }));

        // Sort by waybill; duplicates will naturally show later after compare
        rowsPdfOnly.sort((a,b) => (a.waybill||'').localeCompare(b.waybill||''));
        renderTable(rowsPdfOnly);
      }
    });

    // 2) DB fetch
    btnDb.addEventListener('click', async () => {
      const d = elFilterDate.value;
      if (!d) {
        alert('Select Filter Date first.');
        return;
      }

      btnDb.disabled = true;
      setProgress(10, 'Loading DB waybills...');

      try {
        const res = await fetch(`{{ route('jnt.stickers.db') }}`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf,
            'Accept': 'application/json',
          },
          body: JSON.stringify({ filter_date: d }),
        });

        const json = await res.json();
        if (!json.ok) throw new Error(json.message || 'DB request failed');

        dbWaybills = json.waybills || [];
        dbMeta = { filter_date: json.filter_date || d, count: json.count || 0 };

        setProgress(100, `DB loaded: ${dbMeta.count} waybills for ${dbMeta.filter_date}.`);
        renderSummary();

      } catch (e) {
        console.error(e);
        alert('DB load error. Check console.');
        setProgress(0, 'Error loading DB.');
      } finally {
        btnDb.disabled = false;
      }
    });

    // 3) Compare
    btnCompare.addEventListener('click', () => {
      if (!pdfItems.length) {
        alert('Run 1) Extract first.');
        return;
      }
      if (!dbWaybills.length) {
        alert('Run 2) Show Waybills from DB first.');
        return;
      }

      const rows = computeCompareRows();
      renderTable(rows);

      // Enable commit (you can decide to only enable when no problems)
      btnCommit.disabled = false;
    });

    // COMMIT: upload all PDFs in chunks, then finalize with audit JSON
    btnCommit.addEventListener('click', async () => {
      if (!selectedFiles.length) {
        alert('No PDF files selected.');
        return;
      }

      const filterDate = elFilterDate.value || '';

      // You can enforce “no problems” here if you want:
      // if ((compareMeta.dup_pdf||0) > 0 || (compareMeta.miss_db||0) > 0 || (compareMeta.miss_pdf||0) > 0) { ... }

      btnCommit.disabled = true;
      btnExtract.disabled = true;
      btnDb.disabled = true;
      btnCompare.disabled = true;

      setProgress(0, 'Starting COMMIT (server upload in chunks)...');

      try {
        // 1) Init commit
        const initRes = await fetch(`{{ route('jnt.stickers.commit.init') }}`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf,
            'Accept': 'application/json',
          },
          body: JSON.stringify({
            filter_date: filterDate || null,
            client_summary: pdfMeta,
            client_compare: compareMeta,
          }),
        });
        const initJson = await initRes.json();
        if (!initJson.ok) throw new Error(initJson.message || 'Commit init failed');

        const commitId = initJson.commit_id;
        const serverMax = (initJson.server_limits?.max_file_uploads) || {{ (int)$limits['max_file_uploads'] }} || 20;

        // Use chunk size <= server max_file_uploads (safe)
        const chunkSize = Math.max(1, Math.min(serverMax, 20)); // cap at 20 for stability
        const chunks = [];
        for (let i = 0; i < selectedFiles.length; i += chunkSize) {
          chunks.push(selectedFiles.slice(i, i + chunkSize));
        }

        // 2) Upload chunks
        let uploaded = 0;
        for (let ci = 0; ci < chunks.length; ci++) {
          const chunk = chunks[ci];
          const form = new FormData();
          form.append('commit_id', commitId);
          for (const f of chunk) form.append('pdf_files[]', f);

          setProgress(
            Math.round((uploaded / selectedFiles.length) * 100),
            `Uploading chunk ${ci + 1}/${chunks.length} (files ${uploaded + 1}-${uploaded + chunk.length} of ${selectedFiles.length})...`
          );

          const upRes = await fetch(`{{ route('jnt.stickers.commit.upload') }}`, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
            body: form,
          });

          const upJson = await upRes.json();
          if (!upJson.ok) throw new Error(upJson.message || 'Chunk upload failed');

          uploaded += chunk.length;
        }

        // 3) Finalize (write audit.json)
        setProgress(95, 'Finalizing commit (writing audit.json)...');

        const finRes = await fetch(`{{ route('jnt.stickers.commit.finalize') }}`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf,
            'Accept': 'application/json',
          },
          body: JSON.stringify({
            commit_id: commitId,
            filter_date: filterDate || null,
            client_summary: pdfMeta,
            client_compare: compareMeta,
            table_rows: tableRows.map(r => ({
              waybill: r.waybill,
              status: r.status,
              pdf_count: r.pdf_count,
              db_count: r.db_count,
              duplicate_count: r.duplicate_count,
              files: r.files,
            })),
          }),
        });

        const finJson = await finRes.json();
        if (!finJson.ok) throw new Error(finJson.message || 'Finalize failed');

        setProgress(100, `COMMIT DONE. Uploaded ${uploaded}/${selectedFiles.length} file(s).`);
        alert(`COMMIT DONE.\nUploaded: ${uploaded}/${selectedFiles.length}\nCommit ID: ${commitId}`);

      } catch (e) {
        console.error(e);
        alert('COMMIT error. Check console.');
        setProgress(0, 'COMMIT failed.');
      } finally {
        btnExtract.disabled = false;
        btnDb.disabled = false;
        btnCompare.disabled = false;
        btnCommit.disabled = false; // keep enabled for retry if needed
      }
    });

    // Initial render
    renderSummary();

  </script>
</x-layout>

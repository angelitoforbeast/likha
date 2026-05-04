<x-layout>
  <x-slot name="title">JNT Upload V2</x-slot>
  <x-slot name="heading">JNT BULK UPLOAD (V2)</x-slot>

  <style>
    .v2-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 1px 2px rgba(0,0,0,0.04); }
    .v2-card-header { display:flex; align-items:center; justify-content:space-between; padding:12px 16px; border-bottom:1px solid #f1f5f9; flex-wrap:wrap; gap:8px; }
    .v2-title { font-size:14px; font-weight:600; color:#0f172a; }
    .v2-btn { display:inline-flex; align-items:center; gap:6px; background:#4f46e5; color:#fff; font-weight:600; font-size:13px; padding:8px 14px; border-radius:6px; }
    .v2-btn:hover { background:#4338ca; }
    .v2-btn:disabled { opacity:0.5; cursor:not-allowed; }
    .v2-btn-ghost { display:inline-flex; align-items:center; gap:4px; background:transparent; color:#64748b; font-size:12px; padding:6px 12px; border-radius:6px; border:1px solid #e2e8f0; }
    .v2-btn-ghost:hover { background:#f1f5f9; color:#0f172a; }

    .drop-zone { border:2px dashed #cbd5e1; background:#f8fafc; border-radius:12px; padding:36px; text-align:center; transition:all 0.15s; cursor:pointer; }
    .drop-zone:hover, .drop-zone.dragover { border-color:#6366f1; background:#eef2ff; }
    .drop-zone.dragover { transform:scale(1.01); }

    .file-row { display:grid; grid-template-columns:24px 1fr 90px 110px 1fr 80px; gap:8px; align-items:center; padding:8px 12px; border-bottom:1px solid #f1f5f9; font-size:12.5px; }
    .file-row:hover { background:#f8fafc; }
    .file-row.bad { background:#fef2f2; }
    .file-row .name { font-weight:600; color:#0f172a; word-break:break-all; }
    .file-row .size { color:#64748b; }
    .file-row .badge { display:inline-flex; align-items:center; padding:2px 8px; border-radius:999px; font-size:10.5px; font-weight:600; }
    .badge.ok { background:#dcfce7; color:#166534; }
    .badge.bad { background:#fee2e2; color:#991b1b; }
    .badge.warn { background:#fef3c7; color:#92400e; }
    .badge.info { background:#dbeafe; color:#1e40af; }
    .file-row .issues { font-size:11px; color:#dc2626; }

    .progress-bar { width:100%; background:#e5e7eb; height:6px; border-radius:999px; overflow:hidden; }
    .progress-bar > div { background:#22c55e; height:100%; transition:width 0.3s; }

    .stat-grid { display:grid; grid-template-columns:repeat(6, 1fr); gap:8px; }
    .stat-cell { background:#f8fafc; border:1px solid #e2e8f0; padding:8px 12px; border-radius:8px; }
    .stat-cell .label { font-size:10.5px; color:#64748b; text-transform:uppercase; letter-spacing:0.05em; }
    .stat-cell .value { font-size:18px; font-weight:700; color:#0f172a; margin-top:2px; }
  </style>

  <div class="w-full flex flex-col gap-4 p-2">

    {{-- Step 1 — File picker --}}
    <div class="v2-card" id="step1">
      <div class="v2-card-header">
        <div class="v2-title">📂 Step 1 — Select files</div>
        <div class="flex gap-2">
          <a href="/jnt_upload_v2/history" class="v2-btn-ghost">📜 View History</a>
        </div>
      </div>
      <div class="p-4 space-y-3">
        <div id="dropZone" class="drop-zone">
          <div class="text-3xl mb-2">📦</div>
          <div class="font-semibold text-slate-700">Drag &amp; drop files here, or click to choose</div>
          <div class="text-xs text-slate-500 mt-1">Supports CSV, XLSX, ZIP — multiple files OK, hanggang 1GB each</div>
          <input id="fileInput" type="file" accept=".zip,.csv,.xlsx" multiple class="hidden" />
        </div>

        <div id="selectedList" class="hidden">
          <div class="text-xs font-semibold text-slate-600 mb-2">Selected files (<span id="selCount">0</span>):</div>
          <div id="selectedRows" class="space-y-1 text-sm"></div>
        </div>

        <div class="flex flex-wrap gap-3 items-end">
          <div>
            <label class="block text-xs font-semibold text-slate-600 mb-1">Optional Upload Date/Time</label>
            <input id="batchAt" type="datetime-local" class="border border-slate-300 rounded p-2 text-sm" />
            <div class="text-[10px] text-slate-400 mt-0.5">Empty = current time</div>
          </div>
          <button id="btnPrecheck" type="button" class="v2-btn" disabled>
            🔎 Validate Files
          </button>
          <button id="btnReset" type="button" class="v2-btn-ghost">Clear</button>
        </div>
      </div>
    </div>

    {{-- Step 2 — Precheck report --}}
    <div class="v2-card hidden" id="step2">
      <div class="v2-card-header">
        <div class="v2-title">🧪 Step 2 — Pre-check report</div>
        <div id="precheckSummary" class="text-xs text-slate-500"></div>
      </div>

      {{-- Validation progress panel (live habang nagva-validate) --}}
      <div id="validatePanel" class="px-4 py-3 border-b border-slate-100 bg-slate-50">
        <div class="flex items-center justify-between text-xs mb-1.5">
          <span class="font-semibold text-slate-700" id="validateLabel">Validating…</span>
          <span class="text-slate-500">
            <span id="validateDone">0</span> / <span id="validateTotal">0</span>
            (<span id="validatePct">0%</span>)
          </span>
        </div>
        <div class="progress-bar"><div id="validateBar" style="width:0%; background:#6366f1;"></div></div>
        <div class="text-[11px] text-slate-500 mt-1.5">
          ✓ <span id="validateOk" class="text-emerald-700 font-semibold">0</span> OK
          &nbsp;•&nbsp;
          ⚠ <span id="validateBad" class="text-red-600 font-semibold">0</span> with issues
          &nbsp;•&nbsp;
          🌀 <span id="validateInflight" class="text-slate-700 font-semibold">0</span> in-flight
          <span id="validateEta" class="text-slate-400"></span>
        </div>
      </div>

      {{-- Stats breakdown (lalabas after validation done) --}}
      <div id="statsPanel" class="hidden px-4 py-3 border-b border-slate-100 bg-white">
        <div class="text-xs font-semibold text-slate-700 mb-2">📊 Validation Summary</div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
          <div class="bg-emerald-50 border border-emerald-200 rounded p-2 flex items-center justify-between">
            <span class="text-sm text-emerald-700 font-semibold">✓ OK</span>
            <span class="text-2xl font-bold text-emerald-700" id="statsOkCount">0</span>
          </div>
          <div class="bg-red-50 border border-red-200 rounded p-2 flex items-center justify-between">
            <span class="text-sm text-red-700 font-semibold">⚠ With Issues</span>
            <span class="text-2xl font-bold text-red-700" id="statsBadCount">0</span>
          </div>
        </div>
        <div id="statsBreakdown" class="mt-2 hidden">
          <div class="text-[11px] font-semibold text-slate-600 mb-1.5 uppercase tracking-wide">Issue breakdown</div>
          <div id="statsBreakdownRows" class="space-y-1"></div>
        </div>
      </div>

      <div class="p-0">
        <div class="file-row" style="font-weight:600; background:#f8fafc; border-bottom:2px solid #e2e8f0;">
          <div></div>
          <div>FILE</div>
          <div>SIZE</div>
          <div>STATUS</div>
          <div>ISSUES</div>
          <div>ACTION</div>
        </div>
        <div id="precheckRows" style="max-height: 480px; overflow-y: auto;"></div>
      </div>
      <div class="p-3 border-t border-slate-200 flex gap-3 justify-between items-center">
        <div class="text-xs text-slate-500">
          <span id="okOnlyHint" class="hidden">Only OK files will be processed. Uncheck any you want to skip.</span>
        </div>
        <div class="flex gap-3">
          <button id="btnCancel" type="button" class="v2-btn-ghost">Cancel</button>
          <button id="btnConfirm" type="button" class="v2-btn" disabled>
            🚀 Confirm &amp; Process <span id="processCount">0</span> files
          </button>
        </div>
      </div>
    </div>

    {{-- Step 3 — Live progress --}}
    <div class="v2-card hidden" id="step3">
      <div class="v2-card-header">
        <div class="v2-title">⚡ Step 3 — Processing run #<span id="runIdDisplay">—</span></div>
        <div id="runStatusBadge" class="badge info">PROCESSING</div>
      </div>
      <div class="p-4 space-y-4">
        <div class="stat-grid">
          <div class="stat-cell"><div class="label">Files Done</div><div class="value" id="sFilesDone">0</div></div>
          <div class="stat-cell"><div class="label">Files Failed</div><div class="value" id="sFilesFailed">0</div></div>
          <div class="stat-cell"><div class="label">Inserted</div><div class="value" id="sInserted">0</div></div>
          <div class="stat-cell"><div class="label">Updated</div><div class="value" id="sUpdated">0</div></div>
          <div class="stat-cell"><div class="label">Skipped</div><div class="value" id="sSkipped">0</div></div>
          <div class="stat-cell"><div class="label">Errors</div><div class="value" id="sErrors">0</div></div>
        </div>

        <div>
          <div class="flex items-center justify-between text-xs text-slate-500 mb-1">
            <span>Overall progress (<span id="overallTerminal">0</span> / <span id="overallTotal">0</span> files)</span>
            <span id="overallPct">0%</span>
          </div>
          <div class="progress-bar"><div id="overallBar" style="width:0%"></div></div>
          <div class="grid grid-cols-2 md:grid-cols-4 gap-2 mt-2 text-[11px] text-slate-500">
            <div>⏱ Elapsed: <strong id="overallElapsed" class="text-slate-700">—</strong></div>
            <div>🎯 ETA: <strong id="overallEta" class="text-slate-700">—</strong></div>
            <div>⚡ Avg: <strong id="overallAvg" class="text-slate-700">—</strong> /file</div>
            <div>👷 Workers: <strong id="overallWorkers" class="text-slate-700">—</strong></div>
          </div>
        </div>

        <div id="liveFiles" class="space-y-2"></div>

        <div class="flex justify-end gap-3 pt-2">
          <a id="goHistoryDetail" href="#" class="v2-btn-ghost hidden">View in History →</a>
          <button id="btnNewRun" type="button" class="v2-btn hidden">New Upload</button>
        </div>
      </div>
    </div>

    {{-- Recent runs --}}
    @if($recentRuns && $recentRuns->count() > 0)
      <div class="v2-card">
        <div class="v2-card-header">
          <div class="v2-title">📜 Recent runs</div>
          <a href="/jnt_upload_v2/history" class="v2-btn-ghost">View all →</a>
        </div>
        <div class="p-0 overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-slate-50 text-slate-500 text-xs uppercase">
              <tr>
                <th class="text-left px-4 py-2">Run</th>
                <th class="text-left px-4 py-2">Date</th>
                <th class="text-left px-4 py-2">Files</th>
                <th class="text-left px-4 py-2">Inserted</th>
                <th class="text-left px-4 py-2">Updated</th>
                <th class="text-left px-4 py-2">Skipped</th>
                <th class="text-left px-4 py-2">Errors</th>
                <th class="text-left px-4 py-2">Status</th>
                <th class="text-left px-4 py-2"></th>
              </tr>
            </thead>
            <tbody>
              @foreach($recentRuns as $r)
                <tr class="border-t border-slate-100 hover:bg-slate-50">
                  <td class="px-4 py-2 font-mono">#{{ $r->id }}</td>
                  <td class="px-4 py-2 text-slate-600">{{ \Carbon\Carbon::parse($r->created_at)->format('M j, Y g:i A') }}</td>
                  <td class="px-4 py-2">{{ $r->total_files }}</td>
                  <td class="px-4 py-2 text-emerald-700 font-semibold">{{ number_format($r->total_inserted) }}</td>
                  <td class="px-4 py-2 text-blue-700 font-semibold">{{ number_format($r->total_updated) }}</td>
                  <td class="px-4 py-2 text-slate-500">{{ number_format($r->total_skipped) }}</td>
                  <td class="px-4 py-2 text-red-600">{{ number_format($r->total_errors) }}</td>
                  <td class="px-4 py-2">
                    @php
                      $statusBadge = match($r->status) {
                        'done' => 'ok',
                        'partial' => 'warn',
                        'failed' => 'bad',
                        'processing' => 'info',
                        default => 'info',
                      };
                    @endphp
                    <span class="badge {{ $statusBadge }}">{{ strtoupper($r->status) }}</span>
                  </td>
                  <td class="px-4 py-2">
                    <a href="/jnt_upload_v2/history/{{ $r->id }}" class="v2-btn-ghost">View</a>
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      </div>
    @endif
  </div>

  <script>
    const csrf = '{{ csrf_token() }}';

    const dropZone     = document.getElementById('dropZone');
    const fileInput    = document.getElementById('fileInput');
    const selectedList = document.getElementById('selectedList');
    const selectedRows = document.getElementById('selectedRows');
    const selCount     = document.getElementById('selCount');
    const btnPrecheck  = document.getElementById('btnPrecheck');
    const btnReset     = document.getElementById('btnReset');

    const step1 = document.getElementById('step1');
    const step2 = document.getElementById('step2');
    const step3 = document.getElementById('step3');

    const precheckRows = document.getElementById('precheckRows');
    const precheckSummary = document.getElementById('precheckSummary');
    const processCount = document.getElementById('processCount');
    const btnConfirm = document.getElementById('btnConfirm');
    const btnCancel = document.getElementById('btnCancel');

    const runIdDisplay = document.getElementById('runIdDisplay');
    const runStatusBadge = document.getElementById('runStatusBadge');
    const sFilesDone = document.getElementById('sFilesDone');
    const sFilesFailed = document.getElementById('sFilesFailed');
    const sInserted = document.getElementById('sInserted');
    const sUpdated = document.getElementById('sUpdated');
    const sSkipped = document.getElementById('sSkipped');
    const sErrors = document.getElementById('sErrors');
    const overallPct = document.getElementById('overallPct');
    const overallBar = document.getElementById('overallBar');
    const liveFiles = document.getElementById('liveFiles');
    const goHistoryDetail = document.getElementById('goHistoryDetail');
    const btnNewRun = document.getElementById('btnNewRun');

    let selectedFiles = [];
    let pollTimer = null;
    let currentRunId = null;
    let precheckResult = null;

    function fmtSize(bytes) {
      if (!bytes) return '—';
      if (bytes < 1024) return bytes + ' B';
      if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
      if (bytes < 1024 * 1024 * 1024) return (bytes / 1024 / 1024).toFixed(1) + ' MB';
      return (bytes / 1024 / 1024 / 1024).toFixed(2) + ' GB';
    }

    function renderSelectedList() {
      if (selectedFiles.length === 0) {
        selectedList.classList.add('hidden');
        btnPrecheck.disabled = true;
        return;
      }
      selectedList.classList.remove('hidden');
      btnPrecheck.disabled = false;
      selCount.textContent = selectedFiles.length;
      selectedRows.innerHTML = selectedFiles.map((f, i) => `
        <div class="flex items-center justify-between bg-slate-50 px-3 py-1.5 rounded">
          <span class="truncate">📄 <strong>${f.name}</strong></span>
          <span class="text-slate-500 text-xs">${fmtSize(f.size)} <button data-i="${i}" class="ml-3 text-red-500 hover:text-red-700 rmFile">✕</button></span>
        </div>
      `).join('');
      selectedRows.querySelectorAll('.rmFile').forEach(btn => {
        btn.addEventListener('click', () => {
          const idx = parseInt(btn.dataset.i, 10);
          selectedFiles.splice(idx, 1);
          renderSelectedList();
        });
      });
    }

    dropZone.addEventListener('click', () => fileInput.click());
    fileInput.addEventListener('change', () => {
      Array.from(fileInput.files).forEach(f => selectedFiles.push(f));
      fileInput.value = '';
      renderSelectedList();
    });

    ['dragenter', 'dragover'].forEach(ev => dropZone.addEventListener(ev, e => {
      e.preventDefault(); e.stopPropagation(); dropZone.classList.add('dragover');
    }));
    ['dragleave', 'drop'].forEach(ev => dropZone.addEventListener(ev, e => {
      e.preventDefault(); e.stopPropagation(); dropZone.classList.remove('dragover');
    }));
    dropZone.addEventListener('drop', e => {
      const files = Array.from(e.dataTransfer.files).filter(f => /\.(csv|xlsx|zip)$/i.test(f.name));
      files.forEach(f => selectedFiles.push(f));
      renderSelectedList();
    });

    btnReset.addEventListener('click', () => {
      selectedFiles = [];
      renderSelectedList();
    });

    // ===== PER-FILE concurrent precheck (5 in-flight max) =====
    const CONCURRENCY = 5;
    let validateAbort = false;
    let validateRunId = null;

    const validatePanel    = document.getElementById('validatePanel');
    const validateLabel    = document.getElementById('validateLabel');
    const validateDone     = document.getElementById('validateDone');
    const validateTotal    = document.getElementById('validateTotal');
    const validatePct      = document.getElementById('validatePct');
    const validateBar      = document.getElementById('validateBar');
    const validateOk       = document.getElementById('validateOk');
    const validateBad      = document.getElementById('validateBad');
    const validateInflight = document.getElementById('validateInflight');
    const validateEta      = document.getElementById('validateEta');
    const okOnlyHint       = document.getElementById('okOnlyHint');

    const statsPanel         = document.getElementById('statsPanel');
    const statsOkCount       = document.getElementById('statsOkCount');
    const statsBadCount      = document.getElementById('statsBadCount');
    const statsBreakdown     = document.getElementById('statsBreakdown');
    const statsBreakdownRows = document.getElementById('statsBreakdownRows');

    // Issue → count map, accumulated during validation
    let validateIssueCounts = {};

    function bumpIssue(label) {
      validateIssueCounts[label] = (validateIssueCounts[label] || 0) + 1;
    }

    /**
     * Take a server issue string and split it into one or more grouping labels.
     * "Missing required column(s): signingtime, status" → ["Missing signingtime", "Missing status"]
     * Other issues → keep as-is (trimmed).
     */
    function expandIssueToLabels(issueText) {
      const s = String(issueText || '').trim();
      if (!s) return ['Other issue'];

      const m = s.match(/^Missing required column\(s\):\s*(.+)$/i);
      if (m) {
        return m[1].split(',')
          .map(c => c.trim())
          .filter(Boolean)
          .map(c => 'Missing ' + c);
      }
      if (/^File is empty/i.test(s)) return ['Empty file'];
      if (/^Cannot parse file/i.test(s)) return ['Cannot parse file'];
      if (/^Cannot open ZIP/i.test(s)) return ['Cannot open ZIP'];
      if (/^No valid CSV\/XLSX inside ZIP/i.test(s)) return ['Empty / invalid ZIP'];
      if (/^Unsupported file type/i.test(s)) return ['Unsupported file type'];
      if (/^Read error/i.test(s)) return ['Read error'];
      if (/^HTTP \d+/i.test(s)) return ['Network / HTTP error'];

      return [s.length > 60 ? s.slice(0, 60) + '…' : s];
    }

    function renderStatsBreakdown() {
      const entries = Object.entries(validateIssueCounts).sort((a, b) => b[1] - a[1]);
      if (entries.length === 0) {
        statsBreakdown.classList.add('hidden');
        return;
      }
      statsBreakdown.classList.remove('hidden');
      statsBreakdownRows.innerHTML = entries.map(([label, count]) => `
        <div class="flex items-center justify-between text-xs px-3 py-1.5 bg-slate-50 border border-slate-200 rounded">
          <span class="text-slate-700">${escapeHtml(label)}</span>
          <span class="font-bold text-slate-900">${count.toLocaleString()}</span>
        </div>
      `).join('');
    }

    function renderRow(f) {
      const issues = (f.issues && f.issues.length) ? f.issues.join('; ') : '—';
      let innerNote = '';
      if (f.inner_files && f.inner_files.length) {
        const innerOk = f.inner_files.filter(i => i.ok).length;
        innerNote = `<div class="text-[10.5px] text-slate-400 mt-0.5">ZIP: ${innerOk}/${f.inner_files.length} inner files OK</div>`;
      }
      const checked = f.ok ? 'checked' : '';
      const disabled = f.ok ? '' : 'disabled';
      const rowClass = f.ok ? '' : 'bad';
      const statusBadge = f.ok
        ? '<span class="badge ok">OK</span>'
        : '<span class="badge bad">FAIL</span>';
      return `
        <div class="file-row ${rowClass}" data-log-id="${f.log_id || ''}">
          <div><input type="checkbox" class="precheckSel" data-id="${f.log_id}" ${checked} ${disabled} /></div>
          <div><div class="name">${escapeHtml(f.original_name)}</div>${innerNote}</div>
          <div class="size">${fmtSize(f.size)}</div>
          <div>${statusBadge}</div>
          <div class="issues">${escapeHtml(issues)}</div>
          <div></div>
        </div>
      `;
    }

    function renderErrorRow(name, size, errMsg) {
      return `
        <div class="file-row bad">
          <div></div>
          <div><div class="name">${escapeHtml(name)}</div></div>
          <div class="size">${fmtSize(size)}</div>
          <div><span class="badge bad">FAIL</span></div>
          <div class="issues">${escapeHtml(errMsg)}</div>
          <div></div>
        </div>
      `;
    }

    function escapeHtml(s) {
      return String(s ?? '').replace(/[&<>"']/g, c => ({
        '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
      }[c]));
    }

    btnPrecheck.addEventListener('click', async () => {
      if (selectedFiles.length === 0) return;

      btnPrecheck.disabled = true;
      btnPrecheck.textContent = '🔎 Validating…';

      // Switch to step2 with progress panel
      step1.classList.add('hidden');
      step2.classList.remove('hidden');
      step2.scrollIntoView({ behavior: 'smooth', block: 'start' });

      validatePanel.classList.remove('hidden');
      statsPanel.classList.add('hidden');
      statsBreakdown.classList.add('hidden');
      validateIssueCounts = {};
      precheckRows.innerHTML = '';
      precheckSummary.textContent = '';
      okOnlyHint.classList.add('hidden');

      const total = selectedFiles.length;
      validateTotal.textContent = total.toLocaleString();
      validateDone.textContent = '0';
      validatePct.textContent = '0%';
      validateBar.style.width = '0%';
      validateOk.textContent = '0';
      validateBad.textContent = '0';
      validateInflight.textContent = '0';
      validateEta.textContent = '';
      validateLabel.textContent = 'Validating…';
      validateAbort = false;
      validateRunId = null;

      const batchAt = document.getElementById('batchAt').value || '';

      let okCount = 0, badCount = 0, doneCount = 0, inflight = 0;
      const startedAt = Date.now();

      function updateStats() {
        validateDone.textContent = doneCount.toLocaleString();
        const pct = total > 0 ? Math.round(doneCount / total * 100) : 0;
        validatePct.textContent = pct + '%';
        validateBar.style.width = pct + '%';
        validateOk.textContent = okCount.toLocaleString();
        validateBad.textContent = badCount.toLocaleString();
        validateInflight.textContent = inflight.toLocaleString();
        if (doneCount >= 5) {
          const elapsed = (Date.now() - startedAt) / 1000;
          const rate = doneCount / elapsed; // files per second
          const remaining = total - doneCount;
          const etaSec = remaining > 0 && rate > 0 ? Math.ceil(remaining / rate) : 0;
          if (etaSec > 0) {
            const m = Math.floor(etaSec / 60);
            const s = etaSec % 60;
            validateEta.textContent = ` • ETA ~${m}m ${s}s`;
          }
        }
      }

      // Worker pool
      const queue = selectedFiles.map((f, i) => ({ idx: i, file: f }));
      let nextIdx = 0;

      async function worker() {
        while (!validateAbort) {
          const job = queue[nextIdx++];
          if (!job) return;
          inflight++;
          updateStats();
          try {
            const fd = new FormData();
            fd.append('files[]', job.file);
            if (batchAt) fd.append('batch_at', batchAt);
            if (validateRunId) fd.append('run_id', validateRunId);

            const res = await fetch('/jnt_upload_v2/precheck', {
              method: 'POST',
              headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
              body: fd
            });

            if (!res.ok) {
              const t = await res.text();
              throw new Error(`HTTP ${res.status}: ${t.slice(0, 120)}`);
            }
            const json = await res.json();
            if (!validateRunId) validateRunId = json.run_id;

            const reportFile = (json.files && json.files[0]) ? json.files[0] : null;
            if (reportFile) {
              precheckRows.insertAdjacentHTML('beforeend', renderRow(reportFile));
              if (reportFile.ok) {
                okCount++;
              } else {
                badCount++;
                const issues = (reportFile.issues && reportFile.issues.length) ? reportFile.issues : ['Unknown issue'];
                issues.forEach(iss => expandIssueToLabels(iss).forEach(bumpIssue));
              }
            } else {
              badCount++;
              bumpIssue('No response from server');
              precheckRows.insertAdjacentHTML('beforeend', renderErrorRow(job.file.name, job.file.size, 'No response from server'));
            }
          } catch (e) {
            badCount++;
            const msg = e.message || 'Upload error';
            expandIssueToLabels(msg).forEach(bumpIssue);
            precheckRows.insertAdjacentHTML('beforeend', renderErrorRow(job.file.name, job.file.size, msg));
          } finally {
            inflight--;
            doneCount++;
            updateStats();
          }
        }
      }

      const workers = [];
      for (let i = 0; i < CONCURRENCY; i++) workers.push(worker());
      await Promise.all(workers);

      // Done
      validateLabel.textContent = '✓ Validation complete';
      validateBar.style.background = '#22c55e';
      precheckSummary.textContent = `${okCount.toLocaleString()} OK, ${badCount.toLocaleString()} with issues`;
      btnPrecheck.disabled = false;
      btnPrecheck.textContent = '🔎 Validate Files';

      // Render stats breakdown
      statsOkCount.textContent = okCount.toLocaleString();
      statsBadCount.textContent = badCount.toLocaleString();
      renderStatsBreakdown();
      statsPanel.classList.remove('hidden');

      // Build precheckResult so confirm step works
      precheckResult = { run_id: validateRunId };

      // Wire checkboxes + auto-update confirm count
      precheckRows.querySelectorAll('.precheckSel').forEach(cb => cb.addEventListener('change', updateConfirmCount));
      okOnlyHint.classList.remove('hidden');
      updateConfirmCount();
    });

    function updateConfirmCount() {
      const n = precheckRows.querySelectorAll('.precheckSel:checked').length;
      processCount.textContent = n;
      btnConfirm.disabled = (n === 0);
    }

    btnCancel.addEventListener('click', () => {
      validateAbort = true;
      step2.classList.add('hidden');
      step1.classList.remove('hidden');
      precheckResult = null;
      validateRunId = null;
      validateBar.style.background = '#6366f1';
      validateLabel.textContent = 'Validating…';
      btnPrecheck.disabled = false;
      btnPrecheck.textContent = '🔎 Validate Files';
    });

    btnConfirm.addEventListener('click', async () => {
      const ids = Array.from(precheckRows.querySelectorAll('.precheckSel:checked')).map(cb => parseInt(cb.dataset.id, 10));
      if (!ids.length || !precheckResult) return;

      btnConfirm.disabled = true;
      btnConfirm.textContent = '🚀 Starting…';

      try {
        const fd = new FormData();
        fd.append('run_id', precheckResult.run_id);
        ids.forEach(id => fd.append('log_ids[]', id));

        const res = await fetch('/jnt_upload_v2/start', {
          method: 'POST',
          headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
          body: fd
        });
        if (!res.ok) {
          const t = await res.text();
          throw new Error(`Start failed (${res.status}): ${t.slice(0,200)}`);
        }
        const json = await res.json();
        currentRunId = json.run_id;
        runIdDisplay.textContent = '#' + currentRunId;
        goHistoryDetail.href = '/jnt_upload_v2/history/' + currentRunId;

        step2.classList.add('hidden');
        step3.classList.remove('hidden');
        step3.scrollIntoView({ behavior:'smooth', block:'start' });

        startPolling();
      } catch (e) {
        alert(e.message || 'Start error');
        btnConfirm.disabled = false;
        btnConfirm.textContent = '🚀 Confirm & Process';
      }
    });

    function startPolling() {
      if (pollTimer) clearInterval(pollTimer);
      pollTimer = setInterval(pollOnce, 2000);
      pollOnce();
    }

    async function pollOnce() {
      if (!currentRunId) return;
      try {
        const res = await fetch('/jnt_upload_v2/status/' + currentRunId, { headers: { 'Accept': 'application/json' } });
        if (!res.ok) return;
        const data = await res.json();
        renderProgress(data);

        if (['done', 'partial', 'failed'].includes(data.run.status)) {
          clearInterval(pollTimer);
          pollTimer = null;
          goHistoryDetail.classList.remove('hidden');
          btnNewRun.classList.remove('hidden');
        }
      } catch (e) {
        console.warn(e);
      }
    }

    function fmtDuration(seconds) {
      if (!isFinite(seconds) || seconds < 0) return '—';
      seconds = Math.round(seconds);
      const d = Math.floor(seconds / 86400);
      const h = Math.floor((seconds % 86400) / 3600);
      const m = Math.floor((seconds % 3600) / 60);
      const s = seconds % 60;
      if (d > 0) return `${d}d ${h}h ${m}m`;
      if (h > 0) return `${h}h ${m}m`;
      if (m > 0) return `${m}m ${s}s`;
      return `${s}s`;
    }

    function renderProgress(data) {
      const r = data.run;

      runStatusBadge.textContent = (r.status || '').toUpperCase();
      runStatusBadge.className = 'badge ' + ({
        'done': 'ok',
        'partial': 'warn',
        'failed': 'bad',
        'processing': 'info',
      }[r.status] || 'info');

      sFilesDone.textContent = r.files_done;
      sFilesFailed.textContent = r.files_failed;
      sInserted.textContent = r.total_inserted.toLocaleString();
      sUpdated.textContent = r.total_updated.toLocaleString();
      sSkipped.textContent = r.total_skipped.toLocaleString();
      sErrors.textContent = r.total_errors.toLocaleString();

      const totalFiles = data.files.length;
      const finishedFiles = data.files.filter(f => ['done','failed','skipped'].includes(f.status)).length;
      const inflightFiles = data.files.filter(f => f.status === 'processing').length;
      const pct = totalFiles > 0 ? Math.round(finishedFiles / totalFiles * 100) : 0;
      overallPct.textContent = pct + '%';
      overallBar.style.width = pct + '%';

      const overallTerminal = document.getElementById('overallTerminal');
      const overallTotal    = document.getElementById('overallTotal');
      const overallElapsed  = document.getElementById('overallElapsed');
      const overallEta      = document.getElementById('overallEta');
      const overallAvg      = document.getElementById('overallAvg');
      const overallWorkers  = document.getElementById('overallWorkers');

      if (overallTerminal) overallTerminal.textContent = finishedFiles.toLocaleString();
      if (overallTotal)    overallTotal.textContent    = totalFiles.toLocaleString();
      if (overallWorkers)  overallWorkers.textContent  = inflightFiles > 0 ? `~${inflightFiles}` : '—';

      // Compute ETA from started_at + finishedFiles
      if (r.started_at) {
        const startedMs = Date.parse(r.started_at.replace(' ', 'T') + (r.started_at.endsWith('Z') ? '' : '+08:00'));
        if (!isNaN(startedMs)) {
          const elapsedSec = (Date.now() - startedMs) / 1000;
          if (overallElapsed) overallElapsed.textContent = fmtDuration(elapsedSec);
          if (finishedFiles >= 3 && elapsedSec > 0) {
            const rate = finishedFiles / elapsedSec; // files per second
            const remaining = totalFiles - finishedFiles;
            const etaSec = rate > 0 ? remaining / rate : Infinity;
            const avgSec = elapsedSec / finishedFiles;
            if (overallEta) overallEta.textContent = (['done','partial','failed'].includes(r.status))
              ? '✓ done'
              : '~' + fmtDuration(etaSec);
            if (overallAvg) overallAvg.textContent = avgSec >= 1 ? avgSec.toFixed(1) + 's' : (avgSec * 1000).toFixed(0) + 'ms';
          } else if (overallEta) {
            overallEta.textContent = 'computing…';
          }
        }
      }

      liveFiles.innerHTML = data.files.map(f => {
        const stBadge = ({
          'done': 'ok',
          'failed': 'bad',
          'skipped': 'warn',
          'processing': 'info',
          'queued': 'info',
        }[f.status] || 'info');
        const fileePct = f.total_rows && f.total_rows > 0
          ? Math.round((f.processed_rows || 0) / f.total_rows * 100)
          : (f.status === 'done' ? 100 : 0);

        return `
          <div class="border border-slate-200 rounded p-3">
            <div class="flex items-center justify-between mb-1">
              <div class="font-semibold text-sm">${f.original_name}</div>
              <span class="badge ${stBadge}">${(f.status || '').toUpperCase()}</span>
            </div>
            <div class="progress-bar mb-2"><div style="width:${fileePct}%"></div></div>
            <div class="grid grid-cols-5 gap-2 text-xs text-slate-600">
              <div>Processed: <strong>${(f.processed_rows||0).toLocaleString()}</strong></div>
              <div>Inserted: <strong class="text-emerald-700">${(f.inserted||0).toLocaleString()}</strong></div>
              <div>Updated: <strong class="text-blue-700">${(f.updated||0).toLocaleString()}</strong></div>
              <div>Skipped: <strong class="text-slate-500">${(f.skipped||0).toLocaleString()}</strong></div>
              <div>Errors: <strong class="text-red-600">${(f.error_rows||0).toLocaleString()}</strong></div>
            </div>
            ${f.errors_path ? `<div class="text-[10.5px] text-red-600 mt-1">Errors CSV: ${f.errors_path}</div>` : ''}
            ${f.error_message ? `<div class="text-[10.5px] text-red-600 mt-1">⚠ ${f.error_message}</div>` : ''}
          </div>
        `;
      }).join('');
    }

    btnNewRun.addEventListener('click', () => location.reload());
  </script>
</x-layout>

<x-layout>
  <x-slot name="title">JNT V2 Pipeline Dashboard</x-slot>
  <x-slot name="heading">📊 JNT V2 Pipeline Dashboard</x-slot>

  <style>
    .pipe-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:14px; margin-bottom:14px; box-shadow:0 1px 2px rgba(0,0,0,0.04); }
    .pipe-card-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; }
    .pipe-title { font-size:15px; font-weight:700; color:#0f172a; }
    .pipe-count { font-size:24px; font-weight:700; color:#0f172a; font-variant-numeric:tabular-nums; }
    .pipe-count-label { font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:0.05em; }

    .pipe-stat-row { display:flex; gap:14px; padding:8px 12px; background:#f8fafc; border-radius:8px; margin-bottom:10px; }
    .pipe-stat-cell { flex:1; }

    .pipe-table { width:100%; font-size:11.5px; border-collapse:collapse; margin-top:8px; }
    .pipe-table th { background:#f8fafc; padding:5px 8px; text-align:left; font-weight:600; color:#475569; border-bottom:1px solid #e2e8f0; }
    .pipe-table td { padding:4px 8px; border-bottom:1px solid #f1f5f9; }
    .pipe-table .num { text-align:right; font-variant-numeric:tabular-nums; }
    .pipe-empty { text-align:center; padding:20px; color:#94a3b8; font-style:italic; }

    .pipe-btn { font-size:13px; padding:8px 16px; border-radius:8px; font-weight:600; cursor:pointer; border:none; }
    .pipe-btn:disabled { opacity:0.5; cursor:not-allowed; }
    .pipe-btn.primary { background:#3b82f6; color:#fff; }
    .pipe-btn.primary:hover:not(:disabled) { background:#2563eb; }
    .pipe-btn.danger { background:#fef2f2; color:#dc2626; border:1px solid #fecaca; }
    .pipe-btn.danger:hover:not(:disabled) { background:#fee2e2; }
    .pipe-btn.ghost { background:#fff; color:#475569; border:1px solid #e2e8f0; }

    .pipe-arrow { text-align:center; padding:10px; color:#64748b; font-size:24px; }

    .pipe-progress-card { background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:10px 12px; margin-top:8px; }
    .pipe-progress-label { font-size:11px; color:#1e40af; font-weight:600; margin-bottom:4px; }
    .pipe-progress-bar { width:100%; background:#e2e8f0; height:8px; border-radius:999px; overflow:hidden; }
    .pipe-progress-bar > div { height:100%; background:#3b82f6; transition:width 0.3s; }
    .pipe-progress-text { font-size:11.5px; color:#475569; margin-top:4px; }

    .badge { display:inline-flex; align-items:center; padding:2px 8px; border-radius:999px; font-size:10.5px; font-weight:600; }
    .badge.idle { background:#f1f5f9; color:#475569; }
    .badge.running { background:#dbeafe; color:#1e40af; }
    .badge.done { background:#dcfce7; color:#166534; }
    .badge.failed { background:#fee2e2; color:#991b1b; }
  </style>

  <div class="w-full flex flex-col gap-3 p-4" style="max-width:1100px;margin:0 auto;">

    <div class="flex justify-between items-center">
      <div class="text-xs text-slate-500">
        Manual control sa bawat phase. Click button to import to next stage. Walang auto-pipeline.
      </div>
      <div class="flex gap-2">
        <a href="{{ route('jnt.upload.v2.history') }}" class="pipe-btn ghost">← Back to History</a>
        <a href="{{ route('jnt.upload.v2.index') }}" class="pipe-btn ghost">+ New Upload</a>
        <button id="btnRefresh" class="pipe-btn ghost">⟳ Refresh</button>
      </div>
    </div>

    <!-- Active Phase Banner -->
    <div id="activePhaseCard" class="pipe-progress-card hidden">
      <div class="flex items-center justify-between mb-1">
        <div class="pipe-progress-label">
          <span id="activePhaseLabel">Phase X — running</span>
          <span id="activePhaseStatus" class="badge running ml-2">RUNNING</span>
        </div>
        <div id="activePhaseElapsed" class="text-xs text-slate-500">—</div>
      </div>
      <div class="pipe-progress-bar"><div id="activePhaseBar" style="width:0%;"></div></div>
      <div class="pipe-progress-text" id="activePhaseMessage">—</div>
    </div>

    <!-- Card 1: STAGING -->
    <div class="pipe-card" id="cardStaging">
      <div class="pipe-card-header">
        <div>
          <div class="pipe-title">📦 Step 1 — STAGING TABLE</div>
          <div class="text-xs text-slate-500">Source: parsed XLSX rows na nakaupo dito after upload</div>
        </div>
        <div class="text-right">
          <div class="pipe-count-label">Total Rows</div>
          <div class="pipe-count" id="stagingCount">—</div>
        </div>
      </div>

      <div class="pipe-stat-row">
        <div class="pipe-stat-cell">
          <div class="pipe-count-label">Per bulk_run_id</div>
          <div id="stagingPerRun" class="text-xs text-slate-700 mt-1">—</div>
        </div>
      </div>

      <div class="text-xs text-slate-500 mt-2 mb-1">Top 5 latest rows:</div>
      <div id="stagingPreview"><div class="pipe-empty">Loading...</div></div>

      <div class="flex gap-2 mt-3 items-center justify-between border-t pt-3">
        <button id="btnClearStaging" class="pipe-btn danger" type="button">🗑 Clear Staging (TRUNCATE)</button>
        <button id="btnRunPhase1" class="pipe-btn primary" type="button">▶ Import to Winners (Phase 1)</button>
      </div>
    </div>

    <div class="pipe-arrow">↓</div>

    <!-- Card 2: WINNERS -->
    <div class="pipe-card" id="cardWinners">
      <div class="pipe-card-header">
        <div>
          <div class="pipe-title">🏆 Step 2 — WINNERS TABLE</div>
          <div class="text-xs text-slate-500">Picked winners per waybill (priority: returned > delivered > others)</div>
        </div>
        <div class="text-right">
          <div class="pipe-count-label">Total Rows</div>
          <div class="pipe-count" id="winnersCount">—</div>
        </div>
      </div>

      <div class="pipe-stat-row">
        <div class="pipe-stat-cell">
          <div class="pipe-count-label">Per bulk_run_id</div>
          <div id="winnersPerRun" class="text-xs text-slate-700 mt-1">—</div>
        </div>
      </div>

      <div class="text-xs text-slate-500 mt-2 mb-1">Top 5 latest rows:</div>
      <div id="winnersPreview"><div class="pipe-empty">Loading...</div></div>

      <div class="flex gap-2 mt-3 items-center justify-between border-t pt-3">
        <button id="btnClearWinners" class="pipe-btn danger" type="button">🗑 Clear Winners (TRUNCATE)</button>
        <button id="btnRunPhase2" class="pipe-btn primary" type="button">▶ Import to from_jnts_2 (Phase 2)</button>
      </div>
    </div>

    <div class="pipe-arrow">↓</div>

    <!-- Card 3: FROM_JNTS_2 -->
    <div class="pipe-card" id="cardFinal">
      <div class="pipe-card-header">
        <div>
          <div class="pipe-title">✅ Step 3 — FROM_JNTS_2 (FINAL)</div>
          <div class="text-xs text-slate-500">Final destination — cumulative from all uploads</div>
        </div>
        <div class="text-right">
          <div class="pipe-count-label">Total Rows</div>
          <div class="pipe-count" id="finalCount">—</div>
        </div>
      </div>

      <div class="text-xs text-slate-500 mt-2 mb-1">Top 5 latest rows (by id):</div>
      <div id="finalPreview"><div class="pipe-empty">Loading...</div></div>
    </div>

  </div>

  <script>
    const fmtNum = n => Number(n || 0).toLocaleString();
    const escapeHtml = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    function renderTable(rows, columns) {
      if (!rows || !rows.length) {
        return '<div class="pipe-empty">No rows</div>';
      }
      const head = columns.map(c => `<th>${escapeHtml(c)}</th>`).join('');
      const body = rows.map(r => {
        const cells = columns.map(c => {
          const v = r[c];
          if (v === null || v === undefined) return '<td class="text-slate-400">—</td>';
          return `<td>${escapeHtml(String(v).slice(0, 60))}</td>`;
        }).join('');
        return `<tr>${cells}</tr>`;
      }).join('');
      return `<table class="pipe-table"><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table>`;
    }

    function renderPerRun(perRun) {
      if (!perRun || !perRun.length) return '<span class="text-slate-400 italic">—</span>';
      return perRun.map(r => {
        const rid = r.bulk_run_id ?? 'null';
        const cnt = fmtNum(r.rows_count);
        return `<span class="inline-block bg-slate-100 px-2 py-0.5 rounded mr-1 mb-1">Run #${rid}: ${cnt}</span>`;
      }).join('');
    }

    async function fetchTable(name, countId, perRunId, previewId) {
      try {
        const res = await fetch(`/jnt_upload_v2/pipeline/data/${name}`);
        const j = await res.json();
        document.getElementById(countId).textContent = fmtNum(j.count);
        if (perRunId && j.per_run) {
          document.getElementById(perRunId).innerHTML = renderPerRun(j.per_run);
        }
        document.getElementById(previewId).innerHTML = renderTable(j.rows, j.columns);
      } catch (e) {
        console.error('fetchTable error', name, e);
      }
    }

    async function refreshAll() {
      await Promise.all([
        fetchTable('staging', 'stagingCount', 'stagingPerRun', 'stagingPreview'),
        fetchTable('winners', 'winnersCount', 'winnersPerRun', 'winnersPreview'),
        fetchTable('final', 'finalCount', null, 'finalPreview'),
      ]);
    }

    async function pollProgress() {
      try {
        const res = await fetch('/jnt_upload_v2/pipeline/progress');
        const s = await res.json();
        const card = document.getElementById('activePhaseCard');
        const label = document.getElementById('activePhaseLabel');
        const status = document.getElementById('activePhaseStatus');
        const bar = document.getElementById('activePhaseBar');
        const msg = document.getElementById('activePhaseMessage');
        const elapsed = document.getElementById('activePhaseElapsed');

        if (!s.phase || s.status === 'idle') {
          card.classList.add('hidden');
        } else {
          card.classList.remove('hidden');
          const phaseName = s.phase === 'phase1' ? 'Phase 1 — Materialize Winners' : 'Phase 2 — Merge to from_jnts_2';
          label.textContent = phaseName;
          status.textContent = (s.status || '').toUpperCase();
          status.className = 'badge ' + (s.status || 'idle') + ' ml-2';
          const pct = s.pct ?? (s.status === 'done' ? 100 : 0);
          bar.style.width = pct + '%';
          msg.textContent = s.message || '—';

          if (s.started_at) {
            const startTs = new Date(s.started_at.replace(' ', 'T'));
            const elapsedSec = Math.max(0, Math.round((Date.now() - startTs.getTime()) / 1000));
            const m = Math.floor(elapsedSec / 60);
            const sec = elapsedSec % 60;
            elapsed.textContent = `Elapsed: ${m}m ${sec}s`;
          } else {
            elapsed.textContent = '—';
          }
        }

        // Update count fields with live values
        document.getElementById('stagingCount').textContent = fmtNum(s.stagingCount);
        document.getElementById('winnersCount').textContent = fmtNum(s.winnersCount);
        document.getElementById('finalCount').textContent = fmtNum(s.finalCount);

        // Disable buttons habang may running phase
        const isRunning = s.status === 'running';
        document.getElementById('btnRunPhase1').disabled = isRunning;
        document.getElementById('btnRunPhase2').disabled = isRunning;
        document.getElementById('btnClearStaging').disabled = isRunning;
        document.getElementById('btnClearWinners').disabled = isRunning;
      } catch (e) {
        console.warn('pollProgress error', e);
      }
    }

    async function postAction(url, confirmMsg) {
      if (confirmMsg && !confirm(confirmMsg)) return;
      try {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '{{ csrf_token() }}';
        const res = await fetch(url, {
          method: 'POST',
          headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
        });
        const j = await res.json();
        if (!res.ok || !j.ok) {
          alert('Failed: ' + (j.message || res.status));
          return;
        }
        alert(j.message || 'OK');
        // Trigger refresh agad
        refreshAll();
        pollProgress();
      } catch (e) {
        alert('Network error: ' + e.message);
      }
    }

    document.getElementById('btnRunPhase1').addEventListener('click', () => {
      postAction('/jnt_upload_v2/pipeline/run-phase1', 'Run Phase 1 — materialize winners from staging?');
    });
    document.getElementById('btnRunPhase2').addEventListener('click', () => {
      postAction('/jnt_upload_v2/pipeline/run-phase2', 'Run Phase 2 — merge winners to from_jnts_2?');
    });
    document.getElementById('btnClearStaging').addEventListener('click', () => {
      postAction('/jnt_upload_v2/pipeline/clear/staging', 'TRUNCATE staging table? Mawawala lahat ng rows.');
    });
    document.getElementById('btnClearWinners').addEventListener('click', () => {
      postAction('/jnt_upload_v2/pipeline/clear/winners', 'TRUNCATE winners table? Mawawala lahat ng rows.');
    });
    document.getElementById('btnRefresh').addEventListener('click', refreshAll);

    // Initial load
    refreshAll();
    pollProgress();

    // Live polling — every 3 sec for progress, every 10 sec for table data
    setInterval(pollProgress, 3000);
    setInterval(refreshAll, 10000);
  </script>
</x-layout>

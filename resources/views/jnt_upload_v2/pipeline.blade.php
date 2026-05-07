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

    {{-- Active phase banner removed — progress now shown INSIDE the relevant card (Step 1 or Step 2) --}}

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

      {{-- Phase 1 in-card status — shown only when phase1 is active --}}
      <div id="phase1StatusCard" class="pipe-progress-card hidden mt-3">
        <div class="flex items-center justify-between mb-1">
          <div class="pipe-progress-label">
            <span>Phase 1 — Materialize Winners</span>
            <span id="phase1Status" class="badge running ml-2">RUNNING</span>
          </div>
          <div id="phase1Elapsed" class="text-xs text-slate-500">—</div>
        </div>
        <div class="pipe-progress-bar"><div id="phase1Bar" style="width:0%;"></div></div>
        <div class="pipe-progress-text" id="phase1Message">—</div>
        <div class="text-[10.5px] text-slate-500 italic mt-1">
          Note: Phase 1 ay single SQL statement — walang incremental progress hanggang done. Hintayin lang.
        </div>
      </div>

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

      {{-- Phase 2 in-card status with breakdown — shown only when phase2 is active or done --}}
      <div id="phase2StatusCard" class="pipe-progress-card hidden mt-3">
        <div class="flex items-center justify-between mb-1">
          <div class="pipe-progress-label">
            <span>Phase 2 — Merge to from_jnts_2</span>
            <span id="phase2Status" class="badge running ml-2">RUNNING</span>
          </div>
          <div id="phase2Elapsed" class="text-xs text-slate-500">—</div>
        </div>
        <div class="pipe-progress-bar"><div id="phase2Bar" style="width:0%;"></div></div>
        <div class="pipe-progress-text" id="phase2Message">—</div>

        {{-- Per-status breakdown --}}
        <div class="grid grid-cols-3 gap-2 mt-2">
          <div class="bg-green-50 border border-green-200 rounded px-2 py-1.5">
            <div class="text-[10px] text-green-700 font-semibold uppercase">✅ Inserted (new)</div>
            <div class="text-base font-bold text-green-900" id="phase2Inserted">0</div>
          </div>
          <div class="bg-blue-50 border border-blue-200 rounded px-2 py-1.5">
            <div class="text-[10px] text-blue-700 font-semibold uppercase">🔄 Updated (existing)</div>
            <div class="text-base font-bold text-blue-900" id="phase2Updated">0</div>
          </div>
          <div class="bg-amber-50 border border-amber-200 rounded px-2 py-1.5">
            <div class="text-[10px] text-amber-700 font-semibold uppercase">⏭ Skipped (delivered/returned)</div>
            <div class="text-base font-bold text-amber-900" id="phase2Skipped">0</div>
          </div>
        </div>
      </div>

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

    function fmtElapsed(startedAt) {
      if (!startedAt) return '—';
      const startTs = new Date(startedAt.replace(' ', 'T'));
      const elapsedSec = Math.max(0, Math.round((Date.now() - startTs.getTime()) / 1000));
      const m = Math.floor(elapsedSec / 60);
      const sec = elapsedSec % 60;
      return `Elapsed: ${m}m ${sec}s`;
    }

    function updatePhase1Card(s) {
      const card    = document.getElementById('phase1StatusCard');
      const status  = document.getElementById('phase1Status');
      const bar     = document.getElementById('phase1Bar');
      const msg     = document.getElementById('phase1Message');
      const elapsed = document.getElementById('phase1Elapsed');

      // Show kapag may phase1 state (running, done, failed). Hide kapag idle or current is phase2.
      const showPhase1 = s.phase === 'phase1';
      if (!showPhase1) { card.classList.add('hidden'); return; }
      card.classList.remove('hidden');

      status.textContent = (s.status || '').toUpperCase();
      status.className = 'badge ' + (s.status || 'idle') + ' ml-2';
      const pct = s.pct ?? (s.status === 'done' ? 100 : 0);
      bar.style.width = pct + '%';
      msg.textContent = s.message || '—';
      elapsed.textContent = s.status === 'running' ? fmtElapsed(s.started_at) :
                            (s.elapsed_s ? `Elapsed: ${Math.floor(s.elapsed_s/60)}m ${Math.round(s.elapsed_s%60)}s` : '—');
    }

    function updatePhase2Card(s) {
      const card    = document.getElementById('phase2StatusCard');
      const status  = document.getElementById('phase2Status');
      const bar     = document.getElementById('phase2Bar');
      const msg     = document.getElementById('phase2Message');
      const elapsed = document.getElementById('phase2Elapsed');
      const ins     = document.getElementById('phase2Inserted');
      const upd     = document.getElementById('phase2Updated');
      const skp     = document.getElementById('phase2Skipped');

      const showPhase2 = s.phase === 'phase2';
      if (!showPhase2) { card.classList.add('hidden'); return; }
      card.classList.remove('hidden');

      status.textContent = (s.status || '').toUpperCase();
      status.className = 'badge ' + (s.status || 'idle') + ' ml-2';
      const pct = s.pct ?? (s.status === 'done' ? 100 : 0);
      bar.style.width = pct + '%';
      msg.textContent = s.message || '—';
      elapsed.textContent = s.status === 'running' ? fmtElapsed(s.started_at) :
                            (s.elapsed_s ? `Elapsed: ${Math.floor(s.elapsed_s/60)}m ${Math.round(s.elapsed_s%60)}s` : '—');

      ins.textContent = fmtNum(s.inserted_count || 0);
      upd.textContent = fmtNum(s.updated_count || 0);
      skp.textContent = fmtNum(s.skipped_count || 0);
    }

    async function pollProgress() {
      try {
        const res = await fetch('/jnt_upload_v2/pipeline/progress');
        const s = await res.json();

        // Update both phase cards (one will hide based on current phase)
        updatePhase1Card(s);
        updatePhase2Card(s);

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

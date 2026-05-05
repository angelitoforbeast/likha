<x-layout>
  <x-slot name="title">Run #{{ $run->id }} — Details</x-slot>
  <x-slot name="heading">JNT BULK UPLOAD V2 — Run #{{ $run->id }}</x-slot>

  <style>
    .v2-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 1px 2px rgba(0,0,0,0.04); }
    .v2-card-header { display:flex; align-items:center; justify-content:space-between; padding:10px 14px; border-bottom:1px solid #f1f5f9; flex-wrap:wrap; gap:8px; }
    .v2-title { font-size:13px; font-weight:600; color:#0f172a; }
    .v2-btn-ghost { display:inline-flex; align-items:center; gap:4px; background:transparent; color:#64748b; font-size:12px; padding:5px 10px; border-radius:6px; border:1px solid #e2e8f0; }
    .v2-btn-ghost:hover { background:#f1f5f9; color:#0f172a; }

    .badge { display:inline-flex; align-items:center; padding:2px 8px; border-radius:999px; font-size:10.5px; font-weight:600; }
    .badge.ok { background:#dcfce7; color:#166534; }
    .badge.bad { background:#fee2e2; color:#991b1b; }
    .badge.warn { background:#fef3c7; color:#92400e; }
    .badge.info { background:#dbeafe; color:#1e40af; }

    .meta-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:12px; padding:14px; }
    .meta-cell { background:#f8fafc; padding:10px 12px; border-radius:8px; border:1px solid #e2e8f0; }
    .meta-cell .label { font-size:10.5px; color:#64748b; text-transform:uppercase; letter-spacing:0.05em; }
    .meta-cell .value { font-size:18px; font-weight:700; color:#0f172a; margin-top:2px; }

    .file-card { border:1px solid #e2e8f0; border-radius:10px; padding:12px; }

    .progress-bar { width:100%; background:#e5e7eb; height:6px; border-radius:999px; overflow:hidden; }
    .progress-bar > div { background:#22c55e; height:100%; transition:width 0.3s; }

    .live-banner { background:linear-gradient(90deg, #dbeafe 0%, #eff6ff 100%); border:1px solid #93c5fd; }
  </style>

  @php
    $isLive = !in_array($run->status, ['done', 'partial', 'failed', 'cancelled'], true);
  @endphp

  <div class="w-full flex flex-col gap-4 p-2" data-run-id="{{ $run->id }}" data-is-live="{{ $isLive ? '1' : '0' }}">
    <div class="v2-card">
      <div class="v2-card-header">
        <div class="v2-title">📋 Run summary</div>
        <div class="flex gap-2 items-center">
          <span id="liveIndicator" class="badge info" @if(!$isLive) style="display:none;" @endif>
            🟢 LIVE — auto-refresh
          </span>
          <a href="/jnt_upload_v2/history" class="v2-btn-ghost">← Back to History</a>
          <a href="/jnt_upload_v2" class="v2-btn-ghost">+ New Upload</a>
          @if(!empty($canCancel))
            <button id="btnCancelRun" type="button" data-run-id="{{ $run->id }}"
                    class="v2-btn-ghost text-red-700 border-red-200 hover:bg-red-50">
              🛑 Cancel This Run
            </button>
          @endif
        </div>
      </div>
      <div class="meta-grid">
        <div class="meta-cell"><div class="label">Run ID</div><div class="value">#{{ $run->id }}</div></div>
        <div class="meta-cell">
          <div class="label">Status</div>
          @php
            $bc = match($run->status) {
              'done' => 'ok',
              'partial' => 'warn',
              'failed' => 'bad',
              'cancelled' => 'bad',
              'processing' => 'info',
              'consolidating' => 'info',
              default => 'info',
            };
          @endphp
          <div class="value"><span id="metaStatus" class="badge {{ $bc }}">{{ strtoupper($run->status) }}</span></div>
        </div>
        <div class="meta-cell">
          <div class="label">User</div>
          <div class="value" style="font-size:14px;">{{ $run->user?->name ?? '—' }}</div>
          <div style="font-size:10.5px;color:#94a3b8;">{{ $run->user?->email ?? '' }}</div>
        </div>
        <div class="meta-cell"><div class="label">Created</div><div class="value" style="font-size:14px;">{{ \Carbon\Carbon::parse($run->created_at)->format('M j, Y g:i A') }}</div></div>
        <div class="meta-cell"><div class="label">Started</div><div class="value" style="font-size:14px;">{{ $run->started_at ? \Carbon\Carbon::parse($run->started_at)->format('M j, Y g:i A') : '—' }}</div></div>
        <div class="meta-cell"><div class="label">Finished</div><div class="value" style="font-size:14px;" id="metaFinished">{{ $run->finished_at ? \Carbon\Carbon::parse($run->finished_at)->format('M j, Y g:i A') : '—' }}</div></div>
        <div class="meta-cell"><div class="label">Batch At</div><div class="value" style="font-size:14px;">{{ $run->batch_at ? \Carbon\Carbon::parse($run->batch_at)->format('M j, Y g:i A') : '—' }}</div></div>
        <div class="meta-cell"><div class="label">Total Files</div><div class="value">{{ $run->total_files }}</div></div>
        <div class="meta-cell"><div class="label">Files Done</div><div class="value" id="metaFilesDone">{{ $run->files_done }}</div></div>
        <div class="meta-cell"><div class="label">Files Failed</div><div class="value" style="color:#dc2626;" id="metaFilesFailed">{{ $run->files_failed }}</div></div>
        <div class="meta-cell"><div class="label">Inserted</div><div class="value" style="color:#15803d;" id="metaInserted">{{ number_format($run->total_inserted) }}</div></div>
        <div class="meta-cell"><div class="label">Updated</div><div class="value" style="color:#1d4ed8;" id="metaUpdated">{{ number_format($run->total_updated) }}</div></div>
        <div class="meta-cell"><div class="label">Skipped</div><div class="value" style="color:#64748b;" id="metaSkipped">{{ number_format($run->total_skipped) }}</div></div>
        <div class="meta-cell"><div class="label">Errors</div><div class="value" style="color:#dc2626;" id="metaErrors">{{ number_format($run->total_errors) }}</div></div>
      </div>
      @if($run->message)
        <div class="px-4 pb-3 text-xs text-slate-600">💬 {{ $run->message }}</div>
      @endif
    </div>

    {{-- Live progress card (only shown when run is still in progress) --}}
    @if($isLive)
      <div class="v2-card live-banner" id="liveProgressCard">
        <div class="v2-card-header" style="background:transparent;border-bottom:1px solid #93c5fd;">
          <div class="v2-title">⚡ Live progress</div>
          <span id="runStatusBadge" class="badge info">PROCESSING</span>
        </div>
        <div class="p-4 space-y-3">
          <div>
            <div class="flex items-center justify-between text-xs text-slate-600 mb-1">
              <span>Overall progress (<span id="overallTerminal">0</span> / <span id="overallTotal">0</span> files)</span>
              <span id="overallPct">0%</span>
            </div>
            <div class="progress-bar"><div id="overallBar" style="width:0%"></div></div>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-2 mt-2 text-[11px] text-slate-600">
              <div>⏱ Elapsed: <strong id="overallElapsed" class="text-slate-800">—</strong></div>
              <div>🎯 ETA: <strong id="overallEta" class="text-slate-800">—</strong></div>
              <div>⚡ Speed: <strong id="overallAvg" class="text-slate-800">—</strong></div>
              <div>👷 Workers: <strong id="overallWorkers" class="text-slate-800">—</strong></div>
            </div>
          </div>

          {{-- Staging stats — visible only during processing/consolidating --}}
          <div id="stagingStatsCard" class="hidden border border-indigo-200 bg-white rounded-lg p-3">
            <div class="text-xs font-semibold text-indigo-700 mb-2 uppercase tracking-wide">
              🗂 Staging Status (live count habang nag-pparse)
            </div>
            <div class="grid grid-cols-3 gap-2 text-xs">
              <div class="bg-slate-50 border border-slate-200 rounded p-2">
                <div class="text-[10px] text-slate-500 uppercase font-semibold">Total Staging Rows</div>
                <div class="text-lg font-bold text-indigo-700 mt-0.5" id="stgTotalRows">0</div>
              </div>
              <div class="bg-slate-50 border border-slate-200 rounded p-2">
                <div class="text-[10px] text-slate-500 uppercase font-semibold">Unique Waybills</div>
                <div class="text-lg font-bold text-emerald-700 mt-0.5" id="stgUniqueWaybills">0</div>
              </div>
              <div class="bg-slate-50 border border-slate-200 rounded p-2">
                <div class="text-[10px] text-slate-500 uppercase font-semibold">Duplicates Within Batch</div>
                <div class="text-lg font-bold text-amber-700 mt-0.5" id="stgDuplicates">0</div>
              </div>
            </div>
            <div class="text-[10.5px] text-slate-500 mt-2 italic">
              Duplicates = same waybill na lumalabas sa multiple files. Magko-consolidate yan sa Stage 2.
            </div>
          </div>
        </div>
      </div>
    @endif

    <div class="v2-card">
      <div class="v2-card-header">
        <div class="v2-title">📁 Per-file details (<span id="fileCount">{{ $files->count() }}</span>)</div>
      </div>
      <div class="p-3 space-y-3" id="filesContainer">
        @foreach($files as $f)
          @php
            $bc = match($f->status) {
              'done' => 'ok',
              'failed' => 'bad',
              'skipped' => 'warn',
              'cancelled' => 'warn',
              'precheck_failed' => 'bad',
              'processing' => 'info',
              default => 'info',
            };
            $fpct = ($f->total_rows ?? 0) > 0
              ? min(100, round(($f->processed_rows ?? 0) / $f->total_rows * 100))
              : ($f->status === 'done' ? 100 : 0);
          @endphp
          <div class="file-card" data-file-id="{{ $f->id }}" data-size="{{ $f->size }}">
            <div class="flex items-start justify-between mb-2">
              <div>
                <div class="font-semibold text-sm">📄 {{ $f->original_name }}</div>
                <div class="text-[11px] text-slate-500">
                  {{ number_format($f->size) }} bytes
                  <span class="ftStarted">@if($f->started_at) • Started {{ \Carbon\Carbon::parse($f->started_at)->format('g:i A') }} @endif</span>
                  <span class="ftFinished">@if($f->finished_at) • Finished {{ \Carbon\Carbon::parse($f->finished_at)->format('g:i A') }} @endif</span>
                </div>
              </div>
              <span class="badge {{ $bc }} fStatus">{{ strtoupper($f->status) }}</span>
            </div>
            <div class="progress-bar mb-2"><div class="fBar" style="width:{{ $fpct }}%"></div></div>
            <div class="grid grid-cols-5 gap-2 text-xs text-slate-600 mb-1">
              <div>Processed: <strong class="fProcessed">{{ number_format($f->processed_rows ?? 0) }}</strong></div>
              <div>Inserted: <strong class="text-emerald-700 fInserted">{{ number_format($f->inserted ?? 0) }}</strong></div>
              <div>Updated: <strong class="text-blue-700 fUpdated">{{ number_format($f->updated ?? 0) }}</strong></div>
              <div>Skipped: <strong class="text-slate-500 fSkipped">{{ number_format($f->skipped ?? 0) }}</strong></div>
              <div>Errors: <strong class="text-red-600 fErrors">{{ number_format($f->error_rows ?? 0) }}</strong></div>
            </div>
            <div class="fErrorMsg">
              @if($f->error_message)
                <div class="text-[11px] text-red-600 bg-red-50 px-2 py-1.5 rounded mb-1">⚠ {{ $f->error_message }}</div>
              @endif
            </div>
            <div class="fErrorsPath">
              @if($f->errors_path)
                <div class="text-[11px] text-slate-600">📎 Errors CSV: <code class="bg-slate-100 px-1.5 py-0.5 rounded">{{ $f->errors_path }}</code></div>
              @endif
            </div>
            @if($f->precheck_report && is_array($f->precheck_report) && !empty($f->precheck_report['issues']))
              <div class="text-[11px] text-amber-700 mt-1">
                Precheck issues: {{ implode('; ', $f->precheck_report['issues']) }}
              </div>
            @endif
          </div>
        @endforeach
      </div>
    </div>
  </div>

  <script>
    const csrfToken = '{{ csrf_token() }}';
    const RUN_ID    = {{ $run->id }};
    const IS_LIVE   = {{ $isLive ? 'true' : 'false' }};

    // ===== Cancel button =====
    const cancelBtn = document.getElementById('btnCancelRun');
    if (cancelBtn) {
      cancelBtn.addEventListener('click', async () => {
        if (!confirm(`Cancel run #${RUN_ID}?\n\nLahat ng pending at processing files mama-mark as cancelled. Yung mga natapos na, hindi babaliktad.`)) return;
        cancelBtn.disabled = true;
        cancelBtn.textContent = 'Cancelling…';
        try {
          const fd = new FormData();
          fd.append('_token', csrfToken);
          const res = await fetch('/jnt_upload_v2/cancel/' + RUN_ID, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
            body: fd
          });
          const json = await res.json();
          if (!res.ok || !json.ok) {
            alert('Cancel failed: ' + (json.message || 'unknown'));
            cancelBtn.disabled = false;
            cancelBtn.textContent = '🛑 Cancel This Run';
            return;
          }
          alert(`Run #${RUN_ID} cancelled.\n  ${json.cancelled_files} file(s) marked as cancelled\n  ${json.deleted_jobs} job(s) removed from queue`);
          location.reload();
        } catch (e) {
          alert('Cancel error: ' + e.message);
          cancelBtn.disabled = false;
          cancelBtn.textContent = '🛑 Cancel This Run';
        }
      });
    }

    // ===== Live polling (only kapag still in progress) =====
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

    function fmtBytesPerSec(bps) {
      if (!isFinite(bps) || bps <= 0) return '—';
      if (bps < 1024) return bps.toFixed(0) + ' B/s';
      if (bps < 1024 * 1024) return (bps / 1024).toFixed(1) + ' KB/s';
      if (bps < 1024 * 1024 * 1024) return (bps / 1024 / 1024).toFixed(2) + ' MB/s';
      return (bps / 1024 / 1024 / 1024).toFixed(2) + ' GB/s';
    }

    // Rolling bytes-snapshot store (5s window for current speed)
    const ROLL_WINDOW_MS = 5000;
    let bytesSnapshots = [];
    function pushBytesSnapshot(now, bytesDone) {
      bytesSnapshots.push({ t: now, b: bytesDone });
      while (bytesSnapshots.length > 1 && now - bytesSnapshots[0].t > ROLL_WINDOW_MS) {
        bytesSnapshots.shift();
      }
    }
    function rollingSpeed(now) {
      if (bytesSnapshots.length < 2) return null;
      const oldest = bytesSnapshots[0];
      const newest = bytesSnapshots[bytesSnapshots.length - 1];
      const dt = (newest.t - oldest.t) / 1000;
      const db = newest.b - oldest.b;
      if (dt <= 0) return null;
      if (db < 0) return 0;
      return db / dt;
    }

    function fmtTime(s) {
      if (!s) return '';
      try {
        const d = new Date(s.replace(' ', 'T') + (s.endsWith('Z') ? '' : '+08:00'));
        return d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
      } catch (e) { return s; }
    }

    function statusBadgeClass(status) {
      return ({
        'done': 'ok',
        'failed': 'bad',
        'skipped': 'warn',
        'cancelled': 'warn',
        'precheck_failed': 'bad',
        'precheck_duplicate': 'warn',
        'processing': 'info',
        'consolidating': 'info',
        'queued': 'info',
        'partial': 'warn',
      })[status] || 'info';
    }

    let pollTimer = null;

    async function pollRun() {
      try {
        const res = await fetch('/jnt_upload_v2/status/' + RUN_ID, { headers: { 'Accept': 'application/json' } });
        if (!res.ok) return;
        const data = await res.json();
        applyUpdate(data);

        // Stop polling kapag terminal
        if (['done', 'partial', 'failed', 'cancelled'].includes(data.run.status)) {
          clearInterval(pollTimer);
          pollTimer = null;
          // Tanggalin yung live banner — hindi na siya live
          const card = document.getElementById('liveProgressCard');
          if (card) card.style.display = 'none';
          const ind = document.getElementById('liveIndicator');
          if (ind) ind.style.display = 'none';
        }
      } catch (e) { console.warn('poll error', e); }
    }

    function applyUpdate(data) {
      const r = data.run;

      // ===== Run-level meta cells =====
      const metaStatus = document.getElementById('metaStatus');
      if (metaStatus) {
        metaStatus.textContent = (r.status || '').toUpperCase();
        metaStatus.className = 'badge ' + statusBadgeClass(r.status);
      }
      const setText = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
      setText('metaFilesDone',   (r.files_done || 0).toLocaleString());
      setText('metaFilesFailed', (r.files_failed || 0).toLocaleString());
      setText('metaInserted',    (r.total_inserted || 0).toLocaleString());
      setText('metaUpdated',     (r.total_updated || 0).toLocaleString());
      setText('metaSkipped',     (r.total_skipped || 0).toLocaleString());
      setText('metaErrors',      (r.total_errors || 0).toLocaleString());
      if (r.finished_at) {
        const elFinished = document.getElementById('metaFinished');
        if (elFinished && elFinished.textContent === '—') {
          elFinished.textContent = r.finished_at;
        }
      }

      // ===== Live progress card =====
      const runStatusBadge = document.getElementById('runStatusBadge');
      if (runStatusBadge) {
        runStatusBadge.textContent = (r.status || '').toUpperCase();
        runStatusBadge.className = 'badge ' + statusBadgeClass(r.status);
      }

      // Staging stats — visible during processing/consolidating
      const stagingCard = document.getElementById('stagingStatsCard');
      if (stagingCard && data.staging) {
        if (data.staging.enabled) {
          stagingCard.classList.remove('hidden');
          setText('stgTotalRows',      (data.staging.total_rows || 0).toLocaleString());
          setText('stgUniqueWaybills', (data.staging.unique_waybills || 0).toLocaleString());
          setText('stgDuplicates',     (data.staging.duplicates || 0).toLocaleString());
        } else {
          stagingCard.classList.add('hidden');
        }
      }

      const totalFiles    = data.files.length;
      // Include precheck_duplicate + precheck_failed as terminal — fixes
      // misleading progress count (e.g., 1/6 even when 5 are auto-skipped duplicates).
      const terminalFiles = data.files.filter(f => ['done','failed','skipped','cancelled','precheck_duplicate','precheck_failed'].includes(f.status));
      const finishedFiles = terminalFiles.length;
      const inflightFiles = data.files.filter(f => f.status === 'processing').length;
      const pct = totalFiles > 0 ? Math.round(finishedFiles / totalFiles * 100) : 0;
      setText('overallPct',      pct + '%');
      setText('overallTerminal', finishedFiles.toLocaleString());
      setText('overallTotal',    totalFiles.toLocaleString());
      setText('overallWorkers',  inflightFiles > 0 ? `~${inflightFiles}` : '—');
      const bar = document.getElementById('overallBar');
      if (bar) bar.style.width = pct + '%';

      // ETA — rolling 5-second bytes speed (more responsive than overall avg)
      if (r.started_at) {
        const startedMs = Date.parse(r.started_at.replace(' ', 'T') + (r.started_at.endsWith('Z') ? '' : '+08:00'));
        if (!isNaN(startedMs)) {
          const now = Date.now();
          const elapsedSec = (now - startedMs) / 1000;
          setText('overallElapsed', fmtDuration(elapsedSec));

          const realDone      = terminalFiles.filter(f => f.status !== 'cancelled');
          const realDoneBytes = realDone.reduce((sum, f) => sum + (f.size || 0), 0);
          const remainingBytes = data.files
            .filter(f => !['done','failed','skipped','cancelled'].includes(f.status))
            .reduce((sum, f) => sum + (f.size || 0), 0);

          pushBytesSnapshot(now, realDoneBytes);
          const rollSpeed = rollingSpeed(now);
          const fallbackSpeed = (elapsedSec > 0 && realDoneBytes > 0) ? realDoneBytes / elapsedSec : null;
          const speed = (rollSpeed !== null && rollSpeed > 0) ? rollSpeed : fallbackSpeed;

          const isTerminal = ['done','partial','failed','cancelled'].includes(r.status);

          if (isTerminal) {
            setText('overallEta', '✓ ' + r.status);
          } else if (speed !== null && speed > 0 && remainingBytes >= 0) {
            const etaSec = remainingBytes / speed;
            setText('overallEta', '~' + fmtDuration(etaSec));
          } else {
            setText('overallEta', 'computing…');
          }

          if (speed !== null && speed > 0) {
            setText('overallAvg', fmtBytesPerSec(speed));
          } else {
            setText('overallAvg', '—');
          }
        }
      }

      // ===== Per-file rows update =====
      data.files.forEach(f => {
        const card = document.querySelector(`.file-card[data-file-id="${f.id}"]`);
        if (!card) return;

        const setQ = (sel, val) => { const el = card.querySelector(sel); if (el) el.textContent = val; };
        setQ('.fStatus',    (f.status || '').toUpperCase());
        const stEl = card.querySelector('.fStatus');
        if (stEl) stEl.className = 'badge ' + statusBadgeClass(f.status) + ' fStatus';

        setQ('.fProcessed', (f.processed_rows || 0).toLocaleString());
        setQ('.fInserted',  (f.inserted || 0).toLocaleString());
        setQ('.fUpdated',   (f.updated || 0).toLocaleString());
        setQ('.fSkipped',   (f.skipped || 0).toLocaleString());
        setQ('.fErrors',    (f.error_rows || 0).toLocaleString());

        const fpct = (f.total_rows && f.total_rows > 0)
          ? Math.min(100, Math.round((f.processed_rows || 0) / f.total_rows * 100))
          : (f.status === 'done' ? 100 : 0);
        const barEl = card.querySelector('.fBar');
        if (barEl) barEl.style.width = fpct + '%';

        const startedEl  = card.querySelector('.ftStarted');
        const finishedEl = card.querySelector('.ftFinished');
        if (startedEl  && f.started_at)  startedEl.textContent  = ' • Started ' + fmtTime(f.started_at);
        if (finishedEl && f.finished_at) finishedEl.textContent = ' • Finished ' + fmtTime(f.finished_at);

        const errMsgWrap = card.querySelector('.fErrorMsg');
        if (errMsgWrap) {
          if (f.error_message) {
            errMsgWrap.innerHTML = `<div class="text-[11px] text-red-600 bg-red-50 px-2 py-1.5 rounded mb-1">⚠ ${f.error_message}</div>`;
          } else {
            errMsgWrap.innerHTML = '';
          }
        }
        const errPathWrap = card.querySelector('.fErrorsPath');
        if (errPathWrap) {
          if (f.errors_path) {
            errPathWrap.innerHTML = `<div class="text-[11px] text-slate-600">📎 Errors CSV: <code class="bg-slate-100 px-1.5 py-0.5 rounded">${f.errors_path}</code></div>`;
          } else {
            errPathWrap.innerHTML = '';
          }
        }
      });
    }

    if (IS_LIVE) {
      pollTimer = setInterval(pollRun, 2000);
      pollRun(); // initial fire
    }
  </script>
</x-layout>

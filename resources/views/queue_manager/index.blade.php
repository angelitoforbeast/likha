<x-layout>
  <x-slot name="title">Queue Manager</x-slot>
  <x-slot name="heading">QUEUE MANAGER</x-slot>

  <style>
    .qm-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 1px 2px rgba(0,0,0,0.04); }
    .qm-card-header { display:flex; align-items:center; justify-content:space-between; padding:12px 16px; border-bottom:1px solid #f1f5f9; flex-wrap:wrap; gap:8px; }
    .qm-title { font-size:14px; font-weight:600; color:#0f172a; }
    .qm-btn { display:inline-flex; align-items:center; gap:6px; background:#4f46e5; color:#fff; font-weight:600; font-size:13px; padding:8px 14px; border-radius:6px; }
    .qm-btn:hover { background:#4338ca; }
    .qm-btn:disabled { opacity:0.5; cursor:not-allowed; }
    .qm-btn-ghost { display:inline-flex; align-items:center; gap:4px; background:transparent; color:#64748b; font-size:12px; padding:6px 12px; border-radius:6px; border:1px solid #e2e8f0; }
    .qm-btn-ghost:hover { background:#f1f5f9; color:#0f172a; }
    .qm-btn-danger { background:#dc2626; color:#fff; font-weight:600; font-size:13px; padding:8px 14px; border-radius:6px; }
    .qm-btn-danger:hover { background:#b91c1c; }
    .qm-btn-warn { background:#f59e0b; color:#fff; font-weight:600; font-size:13px; padding:8px 14px; border-radius:6px; }
    .qm-btn-warn:hover { background:#d97706; }

    .qm-counter { background:#f8fafc; border:1px solid #e2e8f0; padding:14px 16px; border-radius:8px; }
    .qm-counter .label { font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:0.05em; font-weight:600; }
    .qm-counter .value { font-size:32px; font-weight:700; color:#0f172a; margin-top:4px; }
    .qm-counter.danger { background:#fef2f2; border-color:#fecaca; }
    .qm-counter.danger .value { color:#dc2626; }
    .qm-counter.success { background:#f0fdf4; border-color:#bbf7d0; }
    .qm-counter.success .value { color:#15803d; }

    .qm-table { width:100%; border-collapse:separate; border-spacing:0; font-size:12.5px; }
    .qm-table thead th { background:#f8fafc; color:#475569; font-size:10.5px; font-weight:600; text-transform:uppercase; letter-spacing:0.04em; padding:8px 10px; text-align:left; border-bottom:2px solid #e2e8f0; }
    .qm-table tbody td { padding:7px 10px; border-bottom:1px solid #f1f5f9; }
    .qm-table tbody tr:hover td { background:#f8fafc; }

    .badge { display:inline-flex; align-items:center; padding:2px 8px; border-radius:999px; font-size:10.5px; font-weight:600; }
    .badge.ok { background:#dcfce7; color:#166534; }
    .badge.bad { background:#fee2e2; color:#991b1b; }
    .badge.warn { background:#fef3c7; color:#92400e; }
    .badge.info { background:#dbeafe; color:#1e40af; }
  </style>

  <div class="w-full flex flex-col gap-4 p-2">

    {{-- ============= COUNTERS ============= --}}
    <div class="qm-card">
      <div class="qm-card-header">
        <div class="qm-title">📊 Counters (system-wide, auto-refresh 3s)</div>
        <div class="text-xs text-slate-500">Last update: <span id="lastUpdate">—</span></div>
      </div>
      <div class="p-4 grid grid-cols-1 md:grid-cols-3 gap-3">
        <div class="qm-counter">
          <div class="label">Pending Jobs</div>
          <div class="value" id="cPending">{{ number_format($data['counters']['pending']) }}</div>
          <div class="text-[11px] text-slate-400 mt-1">Lahat ng features na gumagamit ng queue</div>
        </div>
        <div class="qm-counter danger">
          <div class="label">Failed Jobs</div>
          <div class="value" id="cFailed">{{ number_format($data['counters']['failed']) }}</div>
          <div class="text-[11px] text-red-400 mt-1">Naka-exhaust ng retries</div>
        </div>
        <div class="qm-counter success">
          <div class="label">Active Workers</div>
          <div class="value" id="cWorkers">
            @if($data['counters']['active_workers'] === null)
              —
            @else
              {{ number_format($data['counters']['active_workers']) }}
            @endif
          </div>
          <div class="text-[11px] text-emerald-600 mt-1">Linux pgrep count</div>
        </div>
      </div>
    </div>

    {{-- ============= ACTIONS ============= --}}
    <div class="qm-card">
      <div class="qm-card-header">
        <div class="qm-title">🚨 Actions</div>
        <a href="{{ route('queue.manager.history') }}" class="qm-btn-ghost">📜 View Action History</a>
      </div>
      <div class="p-4 grid grid-cols-1 md:grid-cols-2 gap-3">
        <div class="border border-slate-200 rounded p-3">
          <div class="font-semibold text-sm mb-1">🔄 Restart Workers (graceful)</div>
          <div class="text-[11px] text-slate-500 mb-2">
            Sasabihin sa lahat ng workers na mag-exit pag-tapos ng kasalukuyang job. Yung mga pending sa queue ay <strong>maiiwan</strong>.
          </div>
          <button id="btnRestart" type="button" class="qm-btn">🔄 Send Restart Signal</button>
        </div>

        <div class="border border-slate-200 rounded p-3">
          <div class="font-semibold text-sm mb-1">🗑 Clear All Pending Jobs</div>
          <div class="text-[11px] text-slate-500 mb-2">
            Tatanggalin lahat ng nakapending sa <code>jobs</code> table. Lahat ng V2 in-flight runs ma-mark as cancelled.
          </div>
          <button id="btnClearPending" type="button" class="qm-btn-warn">🗑 Clear Pending</button>
        </div>

        <div class="border border-slate-200 rounded p-3">
          <div class="font-semibold text-sm mb-1">🗑 Clear All Failed Jobs</div>
          <div class="text-[11px] text-slate-500 mb-2">
            Tatanggalin lahat ng record sa <code>failed_jobs</code> table.
          </div>
          <button id="btnClearFailed" type="button" class="qm-btn-warn">🗑 Clear Failed</button>
        </div>

        @if($isCEO)
        <div class="border border-red-200 bg-red-50 rounded p-3">
          <div class="font-semibold text-sm mb-1 text-red-700">☢ NUCLEAR Reset (CEO only)</div>
          <div class="text-[11px] text-red-600 mb-2">
            Combo: restart workers + clear pending + clear failed + cancel all V2 runs.
          </div>
          <button id="btnNuclear" type="button" class="qm-btn-danger">☢ Nuclear Reset</button>
        </div>
        @endif
      </div>

      <div class="px-4 pb-3">
        <details class="text-xs text-slate-500">
          <summary class="cursor-pointer font-semibold">💡 Hindi kasama: Hard-kill workers + auto-launch new workers</summary>
          <div class="mt-2 space-y-1.5 pl-3">
            <div>Para mag-spawn ng <strong>fresh workers</strong> mag-SSH ka sa server at run mo:</div>
            <pre class="bg-slate-100 px-2 py-1 rounded text-[11px] overflow-x-auto"><code>cd /var/www/likha
sudo pkill -f "queue:work"
nohup sudo -u www-data php -d memory_limit=512M artisan queue:work --sleep=3 --tries=3 --timeout=1800 --max-time=3600 &gt; storage/logs/worker-1.log 2&gt;&amp;1 &amp;
# repeat for worker-2, worker-3, etc.</code></pre>
            <div class="text-[10.5px] italic text-slate-400">Para sa permanent setup, gamit Supervisor.</div>
          </div>
        </details>
      </div>
    </div>

    {{-- ============= BREAKDOWN ============= --}}
    <div class="qm-card">
      <div class="qm-card-header">
        <div class="qm-title">📋 Per Job Class Breakdown</div>
      </div>
      <div class="p-0 overflow-auto">
        <table class="qm-table">
          <thead><tr><th>Job Class</th><th style="text-align:right;">Count</th></tr></thead>
          <tbody id="byClassRows">
            @forelse($data['by_class'] as $row)
              <tr>
                <td><code>{{ $row['class'] }}</code></td>
                <td style="text-align:right;font-weight:600;">{{ number_format($row['count']) }}</td>
              </tr>
            @empty
              <tr><td colspan="2" style="text-align:center;color:#94a3b8;padding:24px;">— No pending jobs —</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>

    {{-- ============= RECENT PENDING ============= --}}
    <div class="qm-card">
      <div class="qm-card-header">
        <div class="qm-title">📜 Recent Pending (latest 50)</div>
      </div>
      <div class="p-0 overflow-auto" style="max-height:400px;">
        <table class="qm-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Class</th>
              <th>Queue</th>
              <th>Tries</th>
              <th>State</th>
              <th>Created</th>
            </tr>
          </thead>
          <tbody id="recentJobsRows">
            @forelse($data['recent_jobs'] as $j)
              <tr>
                <td style="font-family:monospace;">{{ $j['id'] }}</td>
                <td><code>{{ $j['class'] }}</code></td>
                <td>{{ $j['queue'] }}</td>
                <td>{{ $j['attempts'] }}</td>
                <td>
                  @if($j['reserved'])
                    <span class="badge info">RESERVED</span>
                  @else
                    <span class="badge warn">QUEUED</span>
                  @endif
                </td>
                <td style="font-size:11px;color:#64748b;">{{ $j['created_at'] }}</td>
              </tr>
            @empty
              <tr><td colspan="6" style="text-align:center;color:#94a3b8;padding:24px;">— No pending jobs —</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>

    {{-- ============= RECENT FAILED ============= --}}
    <div class="qm-card">
      <div class="qm-card-header">
        <div class="qm-title">❌ Recent Failed (latest 20)</div>
      </div>
      <div class="p-0 overflow-auto" style="max-height:400px;">
        <table class="qm-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Class</th>
              <th>Queue</th>
              <th>Failed At</th>
              <th>Error</th>
            </tr>
          </thead>
          <tbody id="recentFailedRows">
            @forelse($data['recent_failed'] as $f)
              <tr>
                <td style="font-family:monospace;">{{ $f['id'] }}</td>
                <td><code>{{ $f['class'] }}</code></td>
                <td>{{ $f['queue'] }}</td>
                <td style="font-size:11px;color:#64748b;">{{ $f['failed_at'] }}</td>
                <td style="font-size:11px;color:#dc2626;max-width:600px;word-break:break-word;">{{ $f['error'] }}</td>
              </tr>
            @empty
              <tr><td colspan="5" style="text-align:center;color:#94a3b8;padding:24px;">— No failed jobs —</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>

  </div>

  <script>
    const csrf = '{{ csrf_token() }}';

    const cPending = document.getElementById('cPending');
    const cFailed  = document.getElementById('cFailed');
    const cWorkers = document.getElementById('cWorkers');
    const lastUpdate = document.getElementById('lastUpdate');
    const byClassRows     = document.getElementById('byClassRows');
    const recentJobsRows  = document.getElementById('recentJobsRows');
    const recentFailedRows= document.getElementById('recentFailedRows');

    function escapeHtml(s) {
      return String(s ?? '').replace(/[&<>"']/g, c => ({
        '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
      }[c]));
    }

    function renderByClass(rows) {
      if (!rows.length) {
        byClassRows.innerHTML = '<tr><td colspan="2" style="text-align:center;color:#94a3b8;padding:24px;">— No pending jobs —</td></tr>';
        return;
      }
      byClassRows.innerHTML = rows.map(r => `
        <tr>
          <td><code>${escapeHtml(r.class)}</code></td>
          <td style="text-align:right;font-weight:600;">${r.count.toLocaleString()}</td>
        </tr>
      `).join('');
    }

    function renderRecentJobs(rows) {
      if (!rows.length) {
        recentJobsRows.innerHTML = '<tr><td colspan="6" style="text-align:center;color:#94a3b8;padding:24px;">— No pending jobs —</td></tr>';
        return;
      }
      recentJobsRows.innerHTML = rows.map(j => `
        <tr>
          <td style="font-family:monospace;">${j.id}</td>
          <td><code>${escapeHtml(j.class)}</code></td>
          <td>${escapeHtml(j.queue)}</td>
          <td>${j.attempts}</td>
          <td>${j.reserved ? '<span class="badge info">RESERVED</span>' : '<span class="badge warn">QUEUED</span>'}</td>
          <td style="font-size:11px;color:#64748b;">${escapeHtml(j.created_at || '')}</td>
        </tr>
      `).join('');
    }

    function renderRecentFailed(rows) {
      if (!rows.length) {
        recentFailedRows.innerHTML = '<tr><td colspan="5" style="text-align:center;color:#94a3b8;padding:24px;">— No failed jobs —</td></tr>';
        return;
      }
      recentFailedRows.innerHTML = rows.map(f => `
        <tr>
          <td style="font-family:monospace;">${f.id}</td>
          <td><code>${escapeHtml(f.class)}</code></td>
          <td>${escapeHtml(f.queue)}</td>
          <td style="font-size:11px;color:#64748b;">${escapeHtml(f.failed_at || '')}</td>
          <td style="font-size:11px;color:#dc2626;max-width:600px;word-break:break-word;">${escapeHtml(f.error || '')}</td>
        </tr>
      `).join('');
    }

    async function refresh() {
      try {
        const res = await fetch('/queue-manager/data', { headers: { 'Accept': 'application/json' } });
        if (!res.ok) return;
        const d = await res.json();
        cPending.textContent = (d.counters.pending || 0).toLocaleString();
        cFailed.textContent  = (d.counters.failed  || 0).toLocaleString();
        cWorkers.textContent = (d.counters.active_workers === null) ? '—' : d.counters.active_workers.toLocaleString();
        lastUpdate.textContent = d.now;
        renderByClass(d.by_class);
        renderRecentJobs(d.recent_jobs);
        renderRecentFailed(d.recent_failed);
      } catch (e) {
        console.warn('queue-manager refresh:', e);
      }
    }

    setInterval(refresh, 3000);

    // ===== Action buttons =====
    async function postAction(url, payload, label, btn, originalText) {
      btn.disabled = true;
      btn.textContent = label + '…';
      try {
        const fd = new FormData();
        fd.append('_token', csrf);
        Object.entries(payload || {}).forEach(([k, v]) => fd.append(k, v));
        const res = await fetch(url, {
          method: 'POST',
          headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
          body: fd
        });
        const json = await res.json();
        if (!res.ok || !json.ok) {
          alert(label + ' failed: ' + (json.message || 'unknown'));
          return null;
        }
        return json;
      } catch (e) {
        alert(label + ' error: ' + e.message);
        return null;
      } finally {
        btn.disabled = false;
        btn.textContent = originalText;
      }
    }

    document.getElementById('btnRestart').addEventListener('click', async (e) => {
      if (!confirm('Send restart signal to all workers?\n\nWorkers will gracefully exit after their current job. Pending jobs stay in queue.')) return;
      const btn = e.currentTarget;
      const json = await postAction('/queue-manager/restart-workers', {}, 'Restart', btn, '🔄 Send Restart Signal');
      if (json) {
        alert('Restart signal sent.\n' + (json.message || ''));
        refresh();
      }
    });

    document.getElementById('btnClearPending').addEventListener('click', async (e) => {
      if (!confirm('Clear ALL pending jobs?\n\nThis truncates the jobs table system-wide. Affects ALL features (V2 upload, conversation tracker, etc.). Irreversible.')) return;
      const typed = prompt('Type YES to confirm:');
      if (typed !== 'YES') { alert('Cancelled.'); return; }
      const btn = e.currentTarget;
      const json = await postAction('/queue-manager/clear-pending', { confirm: 'YES_CLEAR_PENDING' }, 'Clear', btn, '🗑 Clear Pending');
      if (json) {
        alert(`Cleared ${json.cleared.toLocaleString()} pending jobs.\nRuns cancelled: ${json.runs_cancelled}`);
        refresh();
      }
    });

    document.getElementById('btnClearFailed').addEventListener('click', async (e) => {
      if (!confirm('Clear ALL failed jobs records?')) return;
      const btn = e.currentTarget;
      const json = await postAction('/queue-manager/clear-failed', { confirm: 'YES_CLEAR_FAILED' }, 'Clear', btn, '🗑 Clear Failed');
      if (json) {
        alert(`Cleared ${json.cleared.toLocaleString()} failed jobs.`);
        refresh();
      }
    });

    @if($isCEO)
    document.getElementById('btnNuclear').addEventListener('click', async (e) => {
      const msg = `☢ NUCLEAR RESET — confirm?

This will:
  • Send restart signal to all workers
  • Truncate jobs + failed_jobs tables
  • Mark all in-flight V2 runs as cancelled
  • Affect ALL queue features (V2, conversation tracker, etc.)

Irreversible. Type "YES" sa next prompt.`;
      if (!confirm(msg)) return;
      const typed = prompt('Type YES to confirm nuclear reset:');
      if (typed !== 'YES') { alert('Cancelled — confirmation did not match.'); return; }
      const btn = e.currentTarget;
      const json = await postAction('/queue-manager/nuclear-reset', { confirm: 'YES_NUCLEAR_RESET' }, 'Nuclear', btn, '☢ Nuclear Reset');
      if (json) {
        alert(`Nuclear reset complete:
  Pending cleared: ${json.pending_cleared.toLocaleString()}
  Failed cleared:  ${json.failed_cleared.toLocaleString()}
  Runs cancelled:  ${json.runs_cancelled}`);
        refresh();
      }
    });
    @endif
  </script>
</x-layout>

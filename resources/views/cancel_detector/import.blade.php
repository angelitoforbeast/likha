<x-layout>
  <x-slot name="title">Cancel Detector — Import</x-slot>
  <x-slot name="heading">Conversation Cancel Detector — Import</x-slot>

  <style>
    .cd-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 1px 2px rgba(0,0,0,0.04); }
    .cd-card-header { display:flex; align-items:center; justify-content:space-between; padding:10px 14px; border-bottom:1px solid #f1f5f9; flex-wrap:wrap; gap:8px; }
    .cd-title { font-size:13px; font-weight:600; color:#0f172a; }
    .cd-btn { display:inline-flex; align-items:center; gap:6px; background:#dc2626; color:#fff; font-weight:600; font-size:13px; padding:9px 16px; border-radius:7px; box-shadow:0 1px 2px rgba(220,38,38,0.25); }
    .cd-btn:hover { background:#b91c1c; }
    .cd-btn:disabled { background:#fca5a5; cursor:not-allowed; box-shadow:none; }
    .cd-btn-secondary { display:inline-flex; align-items:center; gap:6px; background:#fff; color:#dc2626; font-weight:600; font-size:12px; padding:7px 12px; border:1px solid #fecaca; border-radius:6px; }
    .cd-btn-secondary:hover { background:#fef2f2; }

    .stat-pill { display:inline-flex; align-items:center; gap:5px; padding:4px 10px; border-radius:999px; font-size:11.5px; font-weight:600; background:#f1f5f9; color:#475569; }
    .stat-pill.queued { background:#fef3c7; color:#92400e; }
    .stat-pill.fetching, .stat-pill.processing, .stat-pill.writing, .stat-pill.running { background:#dbeafe; color:#1e40af; }
    .stat-pill.done { background:#dcfce7; color:#166534; }
    .stat-pill.failed { background:#fee2e2; color:#991b1b; }

    .cd-bigstat { display:flex; flex-direction:column; gap:4px; align-items:flex-start; padding:10px 14px; background:#f8fafc; border-radius:8px; }
    .cd-bigstat-label { font-size:10px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.06em; }
    .cd-bigstat-value { font-size:18px; font-weight:700; color:#0f172a; font-variant-numeric:tabular-nums; }
  </style>

  <div class="w-full flex flex-col gap-4 p-2">
    {{-- Top action bar --}}
    <div class="cd-card">
      <div class="cd-card-header">
        <div class="cd-title">🚀 Run Cancel Detector Import</div>
        <div class="flex items-center gap-2 flex-wrap">
          <a href="/conversation/cancel-detector/settings" class="cd-btn-secondary">⚙️ Settings ({{ count($settings) }})</a>
          <a href="/conversation/cancel-detector/view" class="cd-btn-secondary">📋 View Imported</a>
          <button id="btnStart" onclick="startImport()" class="cd-btn">🚀 Start Import</button>
        </div>
      </div>
      <div class="p-4 grid grid-cols-2 md:grid-cols-4 gap-3" id="globalStats">
        <div class="cd-bigstat"><div class="cd-bigstat-label">Active Settings</div><div class="cd-bigstat-value">{{ count($settings) }}</div></div>
        <div class="cd-bigstat"><div class="cd-bigstat-label">Last attempt</div><div class="cd-bigstat-value" style="font-size:13px;">{{ $lastAttemptRun ? optional($lastAttemptRun->started_at)->diffForHumans() : '—' }}</div></div>
        <div class="cd-bigstat"><div class="cd-bigstat-label">Last successful</div><div class="cd-bigstat-value" style="font-size:13px;">{{ $lastSuccessRun ? optional($lastSuccessRun->finished_at)->diffForHumans() : '—' }}</div></div>
        <div class="cd-bigstat"><div class="cd-bigstat-label">Status</div><div class="cd-bigstat-value" style="font-size:13px;" id="globalStatus">{{ $lastAttemptRun?->status ?? 'idle' }}</div></div>
      </div>
      <div style="padding:8px 14px;font-size:11.5px;color:#64748b;border-top:1px solid #f1f5f9;background:#fffbeb;">
        💡 <strong>Phase 1:</strong> Upload only. AI cancel-classification not yet active — <code>ai_analysis</code> remains NULL after import.
      </div>
    </div>

    {{-- Per-sheet status --}}
    <div class="cd-card">
      <div class="cd-card-header">
        <div class="cd-title">📋 Sheets ({{ count($settings) }})</div>
        <div id="runProgress" class="text-xs text-slate-500">No active import.</div>
      </div>
      <div class="p-3" id="sheetList">
        @forelse($settings as $s)
          <div class="border border-slate-200 rounded-lg p-3 mb-2" data-setting-id="{{ $s->id }}">
            <div class="flex items-start justify-between gap-2 flex-wrap">
              <div>
                <div style="font-size:13px;font-weight:600;color:#0f172a;">{{ $s->spreadsheet_title ?? '—' }}</div>
                <div style="font-size:11px;color:#64748b;margin-top:2px;">
                  Tab: <span style="background:#fef2f2;color:#b91c1c;padding:1px 8px;border-radius:999px;">{{ $s->selected_sheet_name ?: '—' }}</span>
                  · <span style="font-family:monospace;">{{ $s->range }}</span>
                </div>
                <div style="font-size:10.5px;color:#94a3b8;margin-top:2px;">
                  Last imported:
                  @if(isset($lastImportedMap[$s->id]))
                    {{ \Carbon\Carbon::parse($lastImportedMap[$s->id])->diffForHumans() }}
                  @else
                    —
                  @endif
                </div>
              </div>
              <span class="stat-pill" data-status>idle</span>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-2 mt-3 text-xs" data-counters>
              <div>processed: <strong data-c="processed">0</strong></div>
              <div>inserted: <strong data-c="inserted" style="color:#16a34a;">0</strong></div>
              <div>updated: <strong data-c="updated" style="color:#1e40af;">0</strong></div>
              <div>skipped: <strong data-c="skipped" style="color:#94a3b8;">0</strong></div>
            </div>
          </div>
        @empty
          <div class="text-center py-8 text-slate-400">
            No settings yet. <a href="/conversation/cancel-detector/settings" class="text-red-600 underline">Add one →</a>
          </div>
        @endforelse
      </div>
    </div>
  </div>

  <script>
    let pollTimer = null;
    let currentRunId = null;

    async function startImport() {
      const btn = document.getElementById('btnStart');
      btn.disabled = true;
      try {
        const res = await fetch('/conversation/cancel-detector/start', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
          body: JSON.stringify({}),
        });
        const data = await res.json();
        if (!res.ok || !data.ok) {
          alert('❌ ' + (data.error || ('HTTP ' + res.status)));
          btn.disabled = false;
          return;
        }
        currentRunId = data.run_id;
        document.getElementById('runProgress').textContent = `Running… (run #${currentRunId})`;
        document.getElementById('globalStatus').textContent = 'running';
        startPolling();
      } catch (e) {
        alert('❌ ' + e.message);
        btn.disabled = false;
      }
    }

    function startPolling() {
      if (pollTimer) clearInterval(pollTimer);
      pollTimer = setInterval(pollStatus, 2000);
      pollStatus();
    }

    async function pollStatus() {
      if (!currentRunId) return;
      try {
        const res = await fetch(`/conversation/cancel-detector/status?run_id=${currentRunId}`);
        if (!res.ok) return;
        const data = await res.json();

        document.getElementById('globalStatus').textContent = data.run.status;
        document.getElementById('runProgress').textContent =
          `Run #${data.run.id} · ${data.run.status} · processed ${data.run.total_processed}, inserted ${data.run.total_inserted}, updated ${data.run.total_updated}, skipped ${data.run.total_skipped}`;

        (data.sheets || []).forEach((s) => {
          const card = document.querySelector(`[data-setting-id="${s.setting_id}"]`);
          if (!card) return;
          const pill = card.querySelector('[data-status]');
          if (pill) {
            pill.textContent = s.status || 'idle';
            pill.className = 'stat-pill ' + (s.status || 'idle');
          }
          ['processed','inserted','updated','skipped'].forEach((k) => {
            const el = card.querySelector(`[data-c="${k}"]`);
            if (el) el.textContent = s[k] ?? 0;
          });
        });

        if (data.run.status === 'done' || data.run.status === 'failed') {
          clearInterval(pollTimer);
          pollTimer = null;
          document.getElementById('btnStart').disabled = false;
        }
      } catch (e) {
        // swallow polling errors
      }
    }
  </script>
</x-layout>

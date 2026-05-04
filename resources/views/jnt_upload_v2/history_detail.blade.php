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
  </style>

  <div class="w-full flex flex-col gap-4 p-2">
    <div class="v2-card">
      <div class="v2-card-header">
        <div class="v2-title">📋 Run summary</div>
        <div class="flex gap-2">
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
              'processing' => 'info',
              default => 'info',
            };
          @endphp
          <div class="value"><span class="badge {{ $bc }}">{{ strtoupper($run->status) }}</span></div>
        </div>
        <div class="meta-cell">
          <div class="label">User</div>
          <div class="value" style="font-size:14px;">{{ $run->user?->name ?? '—' }}</div>
          <div style="font-size:10.5px;color:#94a3b8;">{{ $run->user?->email ?? '' }}</div>
        </div>
        <div class="meta-cell"><div class="label">Created</div><div class="value" style="font-size:14px;">{{ \Carbon\Carbon::parse($run->created_at)->format('M j, Y g:i A') }}</div></div>
        <div class="meta-cell"><div class="label">Started</div><div class="value" style="font-size:14px;">{{ $run->started_at ? \Carbon\Carbon::parse($run->started_at)->format('M j, Y g:i A') : '—' }}</div></div>
        <div class="meta-cell"><div class="label">Finished</div><div class="value" style="font-size:14px;">{{ $run->finished_at ? \Carbon\Carbon::parse($run->finished_at)->format('M j, Y g:i A') : '—' }}</div></div>
        <div class="meta-cell"><div class="label">Batch At</div><div class="value" style="font-size:14px;">{{ $run->batch_at ? \Carbon\Carbon::parse($run->batch_at)->format('M j, Y g:i A') : '—' }}</div></div>
        <div class="meta-cell"><div class="label">Total Files</div><div class="value">{{ $run->total_files }}</div></div>
        <div class="meta-cell"><div class="label">Files Done</div><div class="value">{{ $run->files_done }}</div></div>
        <div class="meta-cell"><div class="label">Files Failed</div><div class="value" style="color:#dc2626;">{{ $run->files_failed }}</div></div>
        <div class="meta-cell"><div class="label">Inserted</div><div class="value" style="color:#15803d;">{{ number_format($run->total_inserted) }}</div></div>
        <div class="meta-cell"><div class="label">Updated</div><div class="value" style="color:#1d4ed8;">{{ number_format($run->total_updated) }}</div></div>
        <div class="meta-cell"><div class="label">Skipped</div><div class="value" style="color:#64748b;">{{ number_format($run->total_skipped) }}</div></div>
        <div class="meta-cell"><div class="label">Errors</div><div class="value" style="color:#dc2626;">{{ number_format($run->total_errors) }}</div></div>
      </div>
      @if($run->message)
        <div class="px-4 pb-3 text-xs text-slate-600">💬 {{ $run->message }}</div>
      @endif
    </div>

    <div class="v2-card">
      <div class="v2-card-header">
        <div class="v2-title">📁 Per-file details ({{ $files->count() }})</div>
      </div>
      <div class="p-3 space-y-3">
        @foreach($files as $f)
          @php
            $bc = match($f->status) {
              'done' => 'ok',
              'failed' => 'bad',
              'skipped' => 'warn',
              'precheck_failed' => 'bad',
              default => 'info',
            };
          @endphp
          <div class="file-card">
            <div class="flex items-start justify-between mb-2">
              <div>
                <div class="font-semibold text-sm">📄 {{ $f->original_name }}</div>
                <div class="text-[11px] text-slate-500">
                  {{ number_format($f->size) }} bytes
                  @if($f->started_at) • Started {{ \Carbon\Carbon::parse($f->started_at)->format('g:i A') }} @endif
                  @if($f->finished_at) • Finished {{ \Carbon\Carbon::parse($f->finished_at)->format('g:i A') }} @endif
                </div>
              </div>
              <span class="badge {{ $bc }}">{{ strtoupper($f->status) }}</span>
            </div>
            <div class="grid grid-cols-5 gap-2 text-xs text-slate-600 mb-2">
              <div>Processed: <strong>{{ number_format($f->processed_rows ?? 0) }}</strong></div>
              <div>Inserted: <strong class="text-emerald-700">{{ number_format($f->inserted ?? 0) }}</strong></div>
              <div>Updated: <strong class="text-blue-700">{{ number_format($f->updated ?? 0) }}</strong></div>
              <div>Skipped: <strong class="text-slate-500">{{ number_format($f->skipped ?? 0) }}</strong></div>
              <div>Errors: <strong class="text-red-600">{{ number_format($f->error_rows ?? 0) }}</strong></div>
            </div>
            @if($f->error_message)
              <div class="text-[11px] text-red-600 bg-red-50 px-2 py-1.5 rounded mb-1">
                ⚠ {{ $f->error_message }}
              </div>
            @endif
            @if($f->errors_path)
              <div class="text-[11px] text-slate-600">
                📎 Errors CSV: <code class="bg-slate-100 px-1.5 py-0.5 rounded">{{ $f->errors_path }}</code>
              </div>
            @endif
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
    const cancelBtn = document.getElementById('btnCancelRun');
    if (cancelBtn) {
      cancelBtn.addEventListener('click', async () => {
        const runId = cancelBtn.dataset.runId;
        if (!confirm(`Cancel run #${runId}?\n\nLahat ng pending at processing files mama-mark as cancelled. Yung mga natapos na, hindi babaliktad.`)) return;

        cancelBtn.disabled = true;
        cancelBtn.textContent = 'Cancelling…';

        try {
          const fd = new FormData();
          fd.append('_token', csrfToken);
          const res = await fetch('/jnt_upload_v2/cancel/' + runId, {
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
          alert(`Run #${runId} cancelled.\n  ${json.cancelled_files} file(s) marked as cancelled\n  ${json.deleted_jobs} job(s) removed from queue`);
          location.reload();
        } catch (e) {
          alert('Cancel error: ' + e.message);
          cancelBtn.disabled = false;
          cancelBtn.textContent = '🛑 Cancel This Run';
        }
      });
    }
  </script>
</x-layout>

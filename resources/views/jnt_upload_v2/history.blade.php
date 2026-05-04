<x-layout>
  <x-slot name="title">JNT Upload V2 — History</x-slot>
  <x-slot name="heading">JNT BULK UPLOAD V2 — History</x-slot>

  <style>
    .v2-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 1px 2px rgba(0,0,0,0.04); }
    .v2-card-header { display:flex; align-items:center; justify-content:space-between; padding:10px 14px; border-bottom:1px solid #f1f5f9; flex-wrap:wrap; gap:8px; }
    .v2-title { font-size:13px; font-weight:600; color:#0f172a; }
    .v2-input, .v2-select {
      padding:7px 10px; font-size:12.5px; color:#0f172a; background:#fff;
      border:1px solid #cbd5e1; border-radius:6px; width:100%;
    }
    .v2-btn { display:inline-flex; align-items:center; gap:5px; background:#4f46e5; color:#fff; font-weight:600; font-size:12px; padding:7px 12px; border-radius:6px; }
    .v2-btn:hover { background:#4338ca; }
    .v2-btn-ghost { display:inline-flex; align-items:center; gap:4px; background:transparent; color:#64748b; font-size:12px; padding:5px 10px; border-radius:6px; border:1px solid #e2e8f0; }
    .v2-btn-ghost:hover { background:#f1f5f9; color:#0f172a; }

    .l-table { width:100%; border-collapse:separate; border-spacing:0; font-size:12.5px; }
    .l-table thead th {
      position:sticky; top:0; z-index:1; background:#f8fafc; color:#475569;
      font-size:10.5px; font-weight:600; text-transform:uppercase; letter-spacing:0.04em;
      padding:9px 10px; text-align:left; border-bottom:2px solid #e2e8f0;
    }
    .l-table tbody td { padding:8px 10px; border-bottom:1px solid #f1f5f9; vertical-align:top; }
    .l-table tbody tr:hover td { background:#f8fafc; }

    .badge { display:inline-flex; align-items:center; padding:2px 8px; border-radius:999px; font-size:10.5px; font-weight:600; }
    .badge.ok { background:#dcfce7; color:#166534; }
    .badge.bad { background:#fee2e2; color:#991b1b; }
    .badge.warn { background:#fef3c7; color:#92400e; }
    .badge.info { background:#dbeafe; color:#1e40af; }
  </style>

  <div class="w-full flex flex-col gap-4 p-2">
    <div class="v2-card">
      <div class="v2-card-header">
        <div class="v2-title">📜 Bulk upload history ({{ $runs->total() }} runs)</div>
        <div class="flex gap-2">
          <a href="/jnt_upload_v2" class="v2-btn-ghost">← Back to Upload</a>
        </div>
      </div>

      <form method="GET" action="/jnt_upload_v2/history" class="grid grid-cols-2 md:grid-cols-5 gap-3 p-3">
        <div>
          <label class="block text-xs font-semibold text-slate-600 mb-1">User</label>
          <input type="text" name="user" value="{{ $userFilter }}" placeholder="name or email" class="v2-input" />
        </div>
        <div>
          <label class="block text-xs font-semibold text-slate-600 mb-1">Status</label>
          <select name="status" class="v2-select">
            <option value="">All</option>
            @foreach(['queued','precheck','processing','done','partial','failed'] as $s)
              <option value="{{ $s }}" @selected($statusFilter === $s)>{{ ucfirst($s) }}</option>
            @endforeach
          </select>
        </div>
        <div>
          <label class="block text-xs font-semibold text-slate-600 mb-1">From</label>
          <input type="date" name="from_date" value="{{ $fromDate }}" class="v2-input" />
        </div>
        <div>
          <label class="block text-xs font-semibold text-slate-600 mb-1">To</label>
          <input type="date" name="to_date" value="{{ $toDate }}" class="v2-input" />
        </div>
        <div class="flex gap-2 items-end">
          <button type="submit" class="v2-btn">Apply</button>
          <a href="/jnt_upload_v2/history" class="v2-btn-ghost">Reset</a>
        </div>
      </form>
    </div>

    <div class="v2-card overflow-hidden">
      <div class="overflow-auto" style="max-height:calc(100vh - 280px);">
        <table class="l-table">
          <thead>
            <tr>
              <th style="width:80px;">Run #</th>
              <th style="width:160px;">When</th>
              <th style="width:200px;">User</th>
              <th style="width:80px;">Files</th>
              <th style="width:120px;">Inserted</th>
              <th style="width:120px;">Updated</th>
              <th style="width:120px;">Skipped</th>
              <th style="width:100px;">Errors</th>
              <th style="width:100px;">Status</th>
              <th style="width:100px;"></th>
            </tr>
          </thead>
          <tbody>
            @forelse ($runs as $r)
              <tr>
                <td style="font-family:monospace;font-weight:600;">#{{ $r->id }}</td>
                <td>
                  <div style="font-weight:600;">{{ \Carbon\Carbon::parse($r->created_at)->format('M j, Y') }}</div>
                  <div style="font-size:10.5px;color:#94a3b8;">{{ \Carbon\Carbon::parse($r->created_at)->format('g:i A') }}</div>
                </td>
                <td>
                  @if(!empty($r->user_name))
                    <div style="font-weight:600;">{{ $r->user_name }}</div>
                    <div style="font-size:10.5px;color:#94a3b8;">{{ $r->user_email }}</div>
                  @else
                    <span style="color:#cbd5e1;">— anonymous —</span>
                  @endif
                </td>
                <td>{{ $r->total_files }}</td>
                <td style="color:#15803d;font-weight:600;">{{ number_format($r->total_inserted) }}</td>
                <td style="color:#1d4ed8;font-weight:600;">{{ number_format($r->total_updated) }}</td>
                <td style="color:#64748b;">{{ number_format($r->total_skipped) }}</td>
                <td style="color:#dc2626;">{{ number_format($r->total_errors) }}</td>
                <td>
                  @php
                    $bc = match($r->status) {
                      'done' => 'ok',
                      'partial' => 'warn',
                      'failed' => 'bad',
                      'processing' => 'info',
                      default => 'info',
                    };
                  @endphp
                  <span class="badge {{ $bc }}">{{ strtoupper($r->status) }}</span>
                </td>
                <td><a href="/jnt_upload_v2/history/{{ $r->id }}" class="v2-btn-ghost">View</a></td>
              </tr>
            @empty
              <tr><td colspan="10" style="text-align:center;padding:36px;color:#94a3b8;">No upload runs yet.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>

      @if ($runs->hasPages())
        <div class="p-3 border-t border-slate-100">
          {{ $runs->links() }}
        </div>
      @endif
    </div>
  </div>
</x-layout>

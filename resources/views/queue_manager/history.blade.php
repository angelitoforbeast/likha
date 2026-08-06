<x-layout>
  <x-slot name="title">Queue Manager — Action History</x-slot>
  <x-slot name="heading">QUEUE MANAGER · ACTION HISTORY</x-slot>

  <style>
    .qm-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 1px 2px rgba(0,0,0,0.04); }
    .qm-card-header { display:flex; align-items:center; justify-content:space-between; padding:12px 16px; border-bottom:1px solid #f1f5f9; flex-wrap:wrap; gap:8px; }
    .qm-title { font-size:14px; font-weight:600; color:#0f172a; }
    .qm-btn-ghost { display:inline-flex; align-items:center; gap:4px; background:transparent; color:#64748b; font-size:12px; padding:6px 12px; border-radius:6px; border:1px solid #e2e8f0; }
    .qm-btn-ghost:hover { background:#f1f5f9; color:#0f172a; }

    .qm-table { width:100%; border-collapse:separate; border-spacing:0; font-size:12.5px; }
    .qm-table thead th { background:#f8fafc; color:#475569; font-size:10.5px; font-weight:600; text-transform:uppercase; letter-spacing:0.04em; padding:8px 10px; text-align:left; border-bottom:2px solid #e2e8f0; }
    .qm-table tbody td { padding:8px 10px; border-bottom:1px solid #f1f5f9; vertical-align:top; }
    .qm-table tbody tr:hover td { background:#f8fafc; }

    .badge { display:inline-flex; align-items:center; gap:4px; padding:2px 8px; border-radius:999px; font-size:10.5px; font-weight:600; white-space:nowrap; }
    .badge.ok { background:#dcfce7; color:#166534; }
    .badge.bad { background:#fee2e2; color:#991b1b; }
    .badge.warn { background:#fef3c7; color:#92400e; }
    .badge.info { background:#dbeafe; color:#1e40af; }
  </style>

  <div class="w-full flex flex-col gap-4 p-2">

    <div class="qm-card">
      <div class="qm-card-header">
        <div class="qm-title">📜 Action History (latest 500, auto-refresh 5s)</div>
        <div class="flex items-center gap-3">
          <div class="text-xs text-slate-500">Last update: <span id="lastUpdate">—</span></div>
          <a href="{{ route('queue.manager.index') }}" class="qm-btn-ghost">← Back to Queue Manager</a>
        </div>
      </div>
      <div class="p-0 overflow-auto" style="max-height:75vh;">
        <table class="qm-table">
          <thead>
            <tr>
              <th style="width:150px;">Time (Manila)</th>
              <th>User</th>
              <th style="width:170px;">Action</th>
              <th>Details</th>
              <th style="width:120px;">IP</th>
            </tr>
          </thead>
          <tbody id="historyRows">
            @forelse($logs as $row)
              @php
                [$badgeClass, $badgeLabel] = match($row->action) {
                  'restart_workers' => ['info', '🔄 Restart Workers'],
                  'clear_pending'   => ['warn', '🗑 Clear Pending'],
                  'clear_failed'    => ['warn', '🗑 Clear Failed'],
                  'nuclear_reset'   => ['bad',  '☢ Nuclear Reset'],
                  default           => ['info', $row->action],
                };
                $d = $row->details ?? [];
                $detailParts = [];
                if (isset($d['cleared']))         $detailParts[] = number_format($d['cleared']) . ' jobs cleared';
                if (isset($d['pending_cleared']))  $detailParts[] = number_format($d['pending_cleared']) . ' pending cleared';
                if (isset($d['failed_cleared']))   $detailParts[] = number_format($d['failed_cleared']) . ' failed cleared';
                if (isset($d['runs_cancelled']))   $detailParts[] = number_format($d['runs_cancelled']) . ' runs cancelled';
                if (isset($d['message']))          $detailParts[] = $d['message'];
              @endphp
              <tr>
                <td style="font-size:11px;color:#64748b;white-space:nowrap;">{{ optional($row->created_at)->format('Y-m-d H:i:s') }}</td>
                <td>
                  <div style="font-weight:600;color:#0f172a;">{{ $row->user_name ?: '—' }}</div>
                  <div style="font-size:11px;color:#64748b;">{{ $row->user_email ?: '' }}</div>
                  @if($row->user_role)<div style="font-size:10.5px;color:#94a3b8;">{{ $row->user_role }}</div>@endif
                </td>
                <td><span class="badge {{ $badgeClass }}">{{ $badgeLabel }}</span></td>
                <td style="font-size:12px;color:#374151;">{{ $detailParts ? implode(' · ', $detailParts) : '—' }}</td>
                <td style="font-size:11px;color:#94a3b8;font-family:monospace;">{{ $row->ip ?: '—' }}</td>
              </tr>
            @empty
              <tr><td colspan="5" style="text-align:center;color:#94a3b8;padding:32px;">— Wala pang naitalang action —</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>

  </div>

  <script>
    const historyRows = document.getElementById('historyRows');
    const lastUpdate  = document.getElementById('lastUpdate');

    function escapeHtml(s) {
      return String(s ?? '').replace(/[&<>"']/g, c => ({
        '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
      }[c]));
    }

    function badgeFor(action) {
      switch (action) {
        case 'restart_workers': return ['info', '🔄 Restart Workers'];
        case 'clear_pending':   return ['warn', '🗑 Clear Pending'];
        case 'clear_failed':    return ['warn', '🗑 Clear Failed'];
        case 'nuclear_reset':   return ['bad',  '☢ Nuclear Reset'];
        default:                return ['info', action];
      }
    }

    function detailsFor(d) {
      d = d || {};
      const parts = [];
      if (d.cleared != null)         parts.push(d.cleared.toLocaleString() + ' jobs cleared');
      if (d.pending_cleared != null) parts.push(d.pending_cleared.toLocaleString() + ' pending cleared');
      if (d.failed_cleared != null)  parts.push(d.failed_cleared.toLocaleString() + ' failed cleared');
      if (d.runs_cancelled != null)  parts.push(d.runs_cancelled.toLocaleString() + ' runs cancelled');
      if (d.message != null)         parts.push(d.message);
      return parts.length ? parts.join(' · ') : '—';
    }

    function render(rows) {
      if (!rows.length) {
        historyRows.innerHTML = '<tr><td colspan="5" style="text-align:center;color:#94a3b8;padding:32px;">— Wala pang naitalang action —</td></tr>';
        return;
      }
      historyRows.innerHTML = rows.map(r => {
        const [cls, label] = badgeFor(r.action);
        const role = r.user_role ? `<div style="font-size:10.5px;color:#94a3b8;">${escapeHtml(r.user_role)}</div>` : '';
        return `
          <tr>
            <td style="font-size:11px;color:#64748b;white-space:nowrap;">${escapeHtml(r.created_at || '')}</td>
            <td>
              <div style="font-weight:600;color:#0f172a;">${escapeHtml(r.user_name || '—')}</div>
              <div style="font-size:11px;color:#64748b;">${escapeHtml(r.user_email || '')}</div>
              ${role}
            </td>
            <td><span class="badge ${cls}">${escapeHtml(label)}</span></td>
            <td style="font-size:12px;color:#374151;">${escapeHtml(detailsFor(r.details))}</td>
            <td style="font-size:11px;color:#94a3b8;font-family:monospace;">${escapeHtml(r.ip || '—')}</td>
          </tr>`;
      }).join('');
    }

    async function refresh() {
      try {
        const res = await fetch('/queue-manager/history/data', { headers: { 'Accept': 'application/json' } });
        if (!res.ok) return;
        const d = await res.json();
        render(d.rows || []);
        lastUpdate.textContent = d.now;
      } catch (e) {
        console.warn('history refresh:', e);
      }
    }

    setInterval(refresh, 5000);
  </script>
</x-layout>

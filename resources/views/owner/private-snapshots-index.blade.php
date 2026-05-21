<x-layout>
  <x-slot name="title">/owner/private Snapshots</x-slot>
  <x-slot name="heading">📸 /owner/private Snapshots — CEO Archive</x-slot>

  <style>
    .ss-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 1px 2px rgba(0,0,0,0.04); }
    .ss-card-header { display:flex; align-items:center; justify-content:space-between;
                       padding:10px 14px; border-bottom:1px solid #f1f5f9; flex-wrap:wrap; gap:8px; }
    .ss-title { font-size:13px; font-weight:600; color:#0f172a; }
    .ss-btn { display:inline-flex; align-items:center; gap:4px; background:transparent;
              color:#64748b; font-size:12px; padding:5px 10px; border-radius:6px; text-decoration:none; }
    .ss-btn:hover { background:#f1f5f9; color:#0f172a; }
    .ss-btn-danger { color:#dc2626; }
    .ss-btn-danger:hover { background:#fef2f2; color:#991b1b; }

    .ss-table { width:100%; border-collapse:separate; border-spacing:0; font-size:12.5px; }
    .ss-table thead th {
      position:sticky; top:0; z-index:1; background:#f8fafc; color:#475569;
      font-size:10.5px; font-weight:600; text-transform:uppercase; letter-spacing:0.04em;
      padding:9px 10px; text-align:left; border-bottom:2px solid #e2e8f0;
    }
    .ss-table tbody td { padding:9px 10px; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
    .ss-table tbody tr:hover td { background:#f8fafc; }
    .ss-table a.row-link { color:#2563eb; font-weight:600; text-decoration:none; }
    .ss-table a.row-link:hover { text-decoration:underline; }

    .pill { display:inline-flex; align-items:center; gap:4px; padding:2px 8px;
            border-radius:999px; font-size:10.5px; font-weight:600; }
    .pill.view-ceo       { background:#dbeafe; color:#1e40af; }
    .pill.view-marketing { background:#fef3c7; color:#92400e; }
    .pill.view-na        { background:#f1f5f9; color:#475569; }
  </style>

  <div class="w-full flex flex-col gap-4 p-2">
    <div class="ss-card">
      <div class="ss-card-header">
        <div class="ss-title">
          📸 Snapshots ({{ $snapshots->total() }} total)
          <span style="font-weight:400;color:#94a3b8;margin-left:6px;">
            — frozen captures of /owner/private's rendered state
          </span>
        </div>
        <a href="/owner/private" class="ss-btn">← Back to Page Summary</a>
      </div>

      <div style="overflow-x:auto;">
        <table class="ss-table">
          <thead>
            <tr>
              <th style="min-width:60px;">ID</th>
              <th style="min-width:170px;">Saved At</th>
              <th style="min-width:200px;">Saved By</th>
              <th style="min-width:180px;">Date Range</th>
              <th style="min-width:100px;">View Mode</th>
              <th style="min-width:80px;">Rows</th>
              <th style="min-width:90px;">Skipped</th>
              <th style="min-width:160px;text-align:right;">Actions</th>
            </tr>
          </thead>
          <tbody>
            @forelse($snapshots as $s)
              <tr>
                <td style="font-family:ui-monospace,monospace;color:#64748b;">#{{ $s->id }}</td>
                <td style="font-family:ui-monospace,monospace;font-size:11.5px;">
                  {{ \Carbon\Carbon::parse($s->snapshot_at)->format('Y-m-d H:i') }}
                  <div style="font-size:10px;color:#94a3b8;">
                    {{ \Carbon\Carbon::parse($s->snapshot_at)->diffForHumans() }}
                  </div>
                </td>
                <td>
                  <div style="font-size:11.5px;color:#475569;">{{ $s->user_email ?? '—' }}</div>
                </td>
                <td style="font-family:ui-monospace,monospace;font-size:11.5px;">
                  {{ $s->start_date }} → {{ $s->end_date }}
                  <div style="font-size:10px;color:#94a3b8;">
                    {{ \Carbon\Carbon::parse($s->start_date)->diffInDays(\Carbon\Carbon::parse($s->end_date)) + 1 }} day(s)
                  </div>
                </td>
                <td>
                  @php
                    $va = strtolower((string)($s->view_as ?? ''));
                    $cls = $va === 'ceo' ? 'view-ceo' : ($va === 'marketing' ? 'view-marketing' : 'view-na');
                  @endphp
                  <span class="pill {{ $cls }}">{{ $va !== '' ? strtoupper($va) : '—' }}</span>
                </td>
                <td style="font-family:ui-monospace,monospace;">{{ $s->rows_count }}</td>
                <td style="font-family:ui-monospace,monospace;color:#b45309;">{{ $s->skipped_count }}</td>
                <td style="text-align:right;">
                  <a href="{{ route('owner.private.snapshots.show', $s->id) }}" class="ss-btn"
                     style="background:#dbeafe;color:#1e40af;">📂 View</a>
                  <button type="button" class="ss-btn ss-btn-danger" onclick="deleteSnapshot({{ $s->id }})">🗑 Delete</button>
                </td>
              </tr>
            @empty
              <tr>
                <td colspan="8" style="text-align:center;padding:30px;color:#94a3b8;">
                  No snapshots yet. Save one via the 📸 button sa /owner/private.
                </td>
              </tr>
            @endforelse
          </tbody>
        </table>
      </div>

      <div style="padding:10px 14px;border-top:1px solid #f1f5f9;">
        {{ $snapshots->links() }}
      </div>
    </div>
  </div>

  <script>
    async function deleteSnapshot(id) {
      if (!confirm('Delete snapshot #' + id + '?\n\nThis is permanent and cannot be undone.')) return;
      try {
        const r = await fetch('/owner/private/snapshots/' + id, {
          method: 'DELETE',
          headers: {
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
          },
        });
        const j = await r.json();
        if (!r.ok || !j.ok) { alert(j.message || 'Delete failed'); return; }
        location.reload();
      } catch(e) {
        alert('Network error: ' + e.message);
      }
    }
  </script>
</x-layout>

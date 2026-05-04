<x-layout>
  <x-slot name="title">Edit Logs</x-slot>
  <x-slot name="heading">RTS / COGS — Edit Audit Logs</x-slot>

  <style>
    .ct-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 1px 2px rgba(0,0,0,0.04); }
    .ct-card-header { display:flex; align-items:center; justify-content:space-between; padding:10px 14px; border-bottom:1px solid #f1f5f9; flex-wrap:wrap; gap:8px; }
    .ct-title { font-size:13px; font-weight:600; color:#0f172a; }
    .ct-input, .ct-select {
      padding:7px 10px; font-size:12.5px; color:#0f172a; background:#fff;
      border:1px solid #cbd5e1; border-radius:6px; width:100%;
    }
    .ct-input:focus, .ct-select:focus { outline:none; border-color:#6366f1; box-shadow:0 0 0 3px rgba(99,102,241,0.12); }
    .ct-btn { display:inline-flex; align-items:center; gap:5px; background:#4f46e5; color:#fff; font-weight:600; font-size:12px; padding:7px 12px; border-radius:6px; }
    .ct-btn:hover { background:#4338ca; }
    .ct-btn-ghost { display:inline-flex; align-items:center; gap:4px; background:transparent; color:#64748b; font-size:12px; padding:5px 10px; border-radius:6px; }
    .ct-btn-ghost:hover { background:#f1f5f9; color:#0f172a; }

    .l-table { width:100%; border-collapse:separate; border-spacing:0; font-size:12.5px; }
    .l-table thead th {
      position:sticky; top:0; z-index:1; background:#f8fafc; color:#475569;
      font-size:10.5px; font-weight:600; text-transform:uppercase; letter-spacing:0.04em;
      padding:9px 10px; text-align:left; border-bottom:2px solid #e2e8f0;
    }
    .l-table tbody td { padding:8px 10px; border-bottom:1px solid #f1f5f9; vertical-align:top; word-wrap:break-word; }
    .l-table tbody tr:hover td { background:#f8fafc; }

    .pill { display:inline-flex; align-items:center; gap:4px; padding:2px 8px; border-radius:999px; font-size:10.5px; font-weight:600; }
    .pill.user   { background:#f1f5f9; color:#475569; }
    .pill.action-create { background:#dcfce7; color:#166534; }
    .pill.action-update { background:#dbeafe; color:#1e40af; }

    .delta { display:inline-flex; align-items:center; gap:4px; font-family:ui-monospace,monospace; font-size:11px; }
    .delta-old { color:#94a3b8; text-decoration:line-through; }
    .delta-arrow { color:#6366f1; }
    .delta-new { color:#0f172a; font-weight:600; }
    .delta-eq { color:#94a3b8; }
  </style>

  <div class="w-full flex flex-col gap-4 p-2">
    <!-- Filter card -->
    <div class="ct-card">
      <div class="ct-card-header">
        <div class="ct-title">📜 Edit logs ({{ $rows->total() }} total)</div>
        <div class="flex gap-2 flex-wrap">
          <a href="/owner/private" class="ct-btn-ghost">← Back to Page Summary</a>
        </div>
      </div>

      <form method="GET" action="/owner/private/edit-logs" class="grid grid-cols-2 md:grid-cols-6 gap-3 p-3">
        <div>
          <label class="block text-xs font-semibold text-slate-600 mb-1">User</label>
          <select name="user" class="ct-select">
            <option value="">All users</option>
            @foreach ($allUsers as $u)
              <option value="{{ $u }}" @selected($userFilter === $u)>{{ $u }}</option>
            @endforeach
          </select>
        </div>
        <div>
          <label class="block text-xs font-semibold text-slate-600 mb-1">Page</label>
          <select name="page" class="ct-select">
            <option value="">All pages</option>
            @foreach ($allPages as $p)
              <option value="{{ $p }}" @selected($pageFilter === $p)>{{ $p }}</option>
            @endforeach
          </select>
        </div>
        <div>
          <label class="block text-xs font-semibold text-slate-600 mb-1">Item (contains)</label>
          <input type="text" name="item" value="{{ $itemFilter }}" placeholder="e.g. MINI FLASHLIGHT" class="ct-input" />
        </div>
        <div>
          <label class="block text-xs font-semibold text-slate-600 mb-1">From</label>
          <input type="date" name="from_date" value="{{ $fromDate }}" class="ct-input" />
        </div>
        <div>
          <label class="block text-xs font-semibold text-slate-600 mb-1">To</label>
          <input type="date" name="to_date" value="{{ $toDate }}" class="ct-input" />
        </div>
        <div class="flex gap-2 items-end">
          <button type="submit" class="ct-btn">Apply</button>
          <a href="/owner/private/edit-logs" class="ct-btn-ghost">Reset</a>
        </div>
      </form>
    </div>

    <!-- Table -->
    <div class="ct-card overflow-hidden">
      <div class="overflow-auto" style="max-height:calc(100vh - 280px);">
        <table class="l-table">
          <thead>
            <tr>
              <th style="width:140px;">When</th>
              <th style="width:170px;">User</th>
              <th style="width:80px;">Action</th>
              <th style="width:140px;">Page</th>
              <th>Item</th>
              <th style="width:110px;">Effective</th>
              <th style="width:160px;">RTS%</th>
              <th style="width:160px;">Item Value (COGS)</th>
              <th>Comments</th>
            </tr>
          </thead>
          <tbody>
            @forelse ($rows as $r)
              <tr>
                <td>
                  <div style="font-weight:600;">{{ \Carbon\Carbon::parse($r->created_at)->format('M j, Y') }}</div>
                  <div style="font-size:10.5px;color:#94a3b8;">{{ \Carbon\Carbon::parse($r->created_at)->format('g:i A') }}</div>
                </td>
                <td>
                  @if ($r->user_name || $r->user_email)
                    <span class="pill user">{{ $r->user_name ?: $r->user_email }}</span>
                    @if ($r->user_name && $r->user_email)
                      <div style="font-size:10px;color:#94a3b8;margin-top:2px;">{{ $r->user_email }}</div>
                    @endif
                  @else
                    <span style="color:#cbd5e1;">— anonymous —</span>
                  @endif
                </td>
                <td>
                  <span class="pill action-{{ $r->action }}">{{ strtoupper($r->action) }}</span>
                </td>
                <td style="font-weight:600;">{{ $r->page_name }}</td>
                <td>{{ $r->item_name }}</td>
                <td style="font-family:monospace;font-size:11px;">{{ $r->effective_date }}</td>
                <td>
                  @php
                    $oldR = $r->old_rts_pct;
                    $newR = $r->new_rts_pct;
                  @endphp
                  @if ($oldR === null && $newR === null)
                    <span class="delta-eq">—</span>
                  @elseif ($oldR === null)
                    <span class="delta"><span class="delta-arrow">+</span><span class="delta-new">{{ number_format($newR, 1) }}%</span></span>
                  @elseif ($newR === null)
                    <span class="delta"><span class="delta-old">{{ number_format($oldR, 1) }}%</span><span class="delta-arrow">→</span><span style="color:#dc2626;font-weight:600;">deleted</span></span>
                  @elseif ((float)$oldR !== (float)$newR)
                    <span class="delta"><span class="delta-old">{{ number_format($oldR, 1) }}%</span><span class="delta-arrow">→</span><span class="delta-new">{{ number_format($newR, 1) }}%</span></span>
                  @else
                    <span class="delta-eq">{{ number_format($newR, 1) }}% (no change)</span>
                  @endif
                </td>
                <td>
                  @php
                    $oldV = $r->old_item_value;
                    $newV = $r->new_item_value;
                  @endphp
                  @if ($oldV === null && $newV === null)
                    <span class="delta-eq">—</span>
                  @elseif ($oldV === null)
                    <span class="delta"><span class="delta-arrow">+</span><span class="delta-new">₱{{ number_format($newV, 2) }}</span></span>
                  @elseif ($newV === null)
                    <span class="delta"><span class="delta-old">₱{{ number_format($oldV, 2) }}</span><span class="delta-arrow">→</span><span style="color:#94a3b8;">no change</span></span>
                  @elseif ((float)$oldV !== (float)$newV)
                    <span class="delta"><span class="delta-old">₱{{ number_format($oldV, 2) }}</span><span class="delta-arrow">→</span><span class="delta-new">₱{{ number_format($newV, 2) }}</span></span>
                  @else
                    <span class="delta-eq">₱{{ number_format($newV, 2) }} (no change)</span>
                  @endif
                </td>
                <td style="font-size:11px;color:#475569;">
                  @if ($r->comment)
                    <div>💬 {{ $r->comment }}</div>
                  @endif
                  @if ($r->item_value_comment)
                    <div>📦 {{ $r->item_value_comment }}</div>
                  @endif
                  @if (!$r->comment && !$r->item_value_comment)
                    <span style="color:#cbd5e1;">—</span>
                  @endif
                </td>
              </tr>
            @empty
              <tr><td colspan="9" style="text-align:center;padding:36px;color:#94a3b8;">No edits logged yet.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>

      @if ($rows->hasPages())
        <div class="p-3 border-t border-slate-100">
          {{ $rows->links() }}
        </div>
      @endif
    </div>
  </div>
</x-layout>

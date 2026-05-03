<x-layout>
  <x-slot name="title">Conversation Tracker — View</x-slot>
  <x-slot name="heading">Conversation Tracker — Imported Records</x-slot>

  <style>
    .ct-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 1px 2px rgba(0,0,0,0.04); }
    .ct-card-header { display:flex; align-items:center; justify-content:space-between; padding:10px 14px; border-bottom:1px solid #f1f5f9; flex-wrap:wrap; gap:8px; }
    .ct-title { font-size:13px; font-weight:600; color:#0f172a; }
    .ct-input, .ct-select {
      padding:7px 10px; font-size:12.5px; color:#0f172a; background:#fff;
      border:1px solid #cbd5e1; border-radius:6px; min-width:0;
    }
    .ct-input:focus, .ct-select:focus { outline:none; border-color:#6366f1; box-shadow:0 0 0 3px rgba(99,102,241,0.12); }
    .ct-btn { display:inline-flex; align-items:center; gap:5px; background:#4f46e5; color:#fff; font-weight:600; font-size:12px; padding:7px 12px; border-radius:6px; }
    .ct-btn:hover { background:#4338ca; }
    .ct-btn-ghost { display:inline-flex; align-items:center; gap:5px; background:transparent; color:#64748b; font-size:12px; padding:5px 10px; border-radius:6px; }
    .ct-btn-ghost:hover { background:#f1f5f9; color:#0f172a; }

    .ct-table { width:100%; border-collapse:separate; border-spacing:0; font-size:12.5px; table-layout:fixed; }
    .ct-table thead th { position:sticky; top:0; z-index:1; background:#f8fafc; color:#475569; font-size:10.5px; font-weight:600; text-transform:uppercase; letter-spacing:0.04em; padding:9px 10px; text-align:left; border-bottom:2px solid #e2e8f0; }
    .ct-table tbody td { padding:8px 10px; border-bottom:1px solid #f1f5f9; vertical-align:top; word-wrap:break-word; }
    .ct-table tbody tr:hover td { background:#f8fafc; }
    .ct-table colgroup col.c-date { width:130px; }
    .ct-table colgroup col.c-page { width:120px; }
    .ct-table colgroup col.c-name { width:140px; }
    .ct-table colgroup col.c-phone { width:110px; }
    .ct-table colgroup col.c-contact { width:120px; }
    .ct-table colgroup col.c-cx { width:22%; }
    .ct-table colgroup col.c-resp { width:auto; }
    .ct-table colgroup col.c-action { width:60px; }
    .row-del-btn { background:transparent; border:1px solid #fecaca; color:#dc2626; padding:3px 8px; border-radius:5px; font-size:11px; cursor:pointer; }
    .row-del-btn:hover { background:#fef2f2; }

    .text-block-pre { white-space:pre-wrap; font-size:11px; color:#475569; line-height:1.4; max-height:120px; overflow:auto; padding:6px; background:#f8fafc; border-radius:4px; border:1px solid #f1f5f9; }
    .pill-page { background:#eef2ff; color:#4338ca; padding:2px 8px; border-radius:999px; font-size:11px; font-weight:500; }
  </style>

  <div class="w-full flex flex-col gap-4 p-2">
    @if(session('status'))
      <div class="p-3 rounded bg-green-100 text-green-800 font-semibold text-center">{{ session('status') }}</div>
    @endif

    <!-- Filters -->
    <div class="ct-card">
      <div class="ct-card-header">
        <div class="ct-title">📊 Imported records ({{ $rows->total() }} total)</div>
        <div class="flex gap-2 flex-wrap">
          <a href="/conversation/tracker" class="ct-btn-ghost">← Back to Import</a>
          <a href="/conversation/tracker/stats" class="ct-btn-ghost">📊 Stats</a>
          <a href="/conversation/tracker/settings" class="ct-btn-ghost">⚙️ Settings</a>
        </div>
      </div>
      <form method="GET" action="/conversation/tracker/view" class="grid grid-cols-2 md:grid-cols-5 gap-2 p-3">
        <input type="text" name="search" value="{{ request('search') }}" placeholder="🔎 search name / phone / page…" class="ct-input md:col-span-2" />
        <select name="page_name" class="ct-select">
          <option value="">All pages</option>
          @foreach ($pages as $p)
            <option value="{{ $p }}" @selected(request('page_name') === $p)>{{ $p }}</option>
          @endforeach
        </select>
        <input type="date" name="subscription_date" value="{{ request('subscription_date') }}" class="ct-input" />
        <div class="flex gap-2">
          <button type="submit" class="ct-btn">Apply</button>
          <a href="/conversation/tracker/view" class="ct-btn-ghost">Reset</a>
        </div>
      </form>
    </div>

    <!-- Table -->
    <div class="ct-card overflow-hidden">
      <div class="overflow-auto" style="max-height:calc(100vh - 280px);">
        <table class="ct-table">
          <colgroup>
            <col class="c-date">
            <col class="c-page">
            <col class="c-name">
            <col class="c-phone">
            <col class="c-contact">
            <col class="c-cx">
            <col class="c-resp">
            <col class="c-action">
          </colgroup>
          <thead>
            <tr>
              <th>Subscription / Upload</th>
              <th>Page</th>
              <th>Name</th>
              <th>Phone</th>
              <th>Contact ID</th>
              <th>All CX Details</th>
              <th>Response Tracker</th>
              <th style="text-align:center;">Action</th>
            </tr>
          </thead>
          <tbody>
            @forelse ($rows as $r)
              <tr>
                <td>
                  <div style="font-size:11.5px;">📅 {{ $r->subscription_date ? $r->subscription_date->format('M j, Y g:i A') : '—' }}</div>
                  <div style="font-size:10.5px;color:#94a3b8;margin-top:3px;">⬆ {{ $r->upload_date ? $r->upload_date->format('M j, Y g:i A') : '—' }}</div>
                </td>
                <td><span class="pill-page">{{ $r->page_name ?: '—' }}</span></td>
                <td style="font-weight:600;">{{ $r->name ?: '—' }}</td>
                <td style="font-family:monospace;font-size:11.5px;">{{ $r->phone_number ?: '—' }}</td>
                <td style="font-family:monospace;font-size:10.5px;color:#475569;">
                  @if ($r->contact_id)
                    {{ \Illuminate\Support\Str::limit($r->contact_id, 18) }}
                  @else
                    <span style="color:#cbd5e1;">—</span>
                  @endif
                </td>
                <td>
                  @if ($r->all_cx_details)
                    <div class="text-block-pre">{{ $r->all_cx_details }}</div>
                  @else
                    <span style="color:#cbd5e1;">—</span>
                  @endif
                </td>
                <td>
                  @if ($r->response_tracker)
                    <div class="text-block-pre">{{ $r->response_tracker }}</div>
                  @else
                    <span style="color:#cbd5e1;">—</span>
                  @endif
                </td>
                <td style="text-align:center;">
                  <form method="POST" action="/conversation/tracker/view/{{ $r->id }}"
                        onsubmit="return confirm('Delete row #{{ $r->id }} ({{ $r->name ?: 'no name' }})?')"
                        class="inline">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="row-del-btn" title="Delete row">🗑️</button>
                  </form>
                </td>
              </tr>
            @empty
              <tr><td colspan="8" style="text-align:center;padding:36px;color:#94a3b8;">No records yet. Run an import from the <a href="/conversation/tracker" class="text-indigo-600 underline">Import page</a>.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>

      @if ($rows->hasPages())
        <div class="p-3 border-t border-slate-100">{{ $rows->links() }}</div>
      @endif
    </div>
  </div>
</x-layout>

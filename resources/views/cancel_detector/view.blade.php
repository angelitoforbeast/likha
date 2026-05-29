<x-layout>
  <x-slot name="title">Cancel Detector — View</x-slot>
  <x-slot name="heading">Cancel Detector — Imported Records</x-slot>

  <style>
    .cd-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 1px 2px rgba(0,0,0,0.04); }
    .cd-card-header { display:flex; align-items:center; justify-content:space-between; padding:10px 14px; border-bottom:1px solid #f1f5f9; flex-wrap:wrap; gap:8px; }
    .cd-title { font-size:13px; font-weight:600; color:#0f172a; }
    .cd-input, .cd-select {
      padding:7px 10px; font-size:12.5px; color:#0f172a; background:#fff;
      border:1px solid #cbd5e1; border-radius:6px; min-width:0;
    }
    .cd-input:focus, .cd-select:focus { outline:none; border-color:#dc2626; box-shadow:0 0 0 3px rgba(220,38,38,0.12); }
    .cd-btn { display:inline-flex; align-items:center; gap:5px; background:#dc2626; color:#fff; font-weight:600; font-size:12px; padding:7px 12px; border-radius:6px; }
    .cd-btn:hover { background:#b91c1c; }
    .cd-btn-ghost { display:inline-flex; align-items:center; gap:5px; background:transparent; color:#64748b; font-size:12px; padding:5px 10px; border-radius:6px; }
    .cd-btn-ghost:hover { background:#f1f5f9; color:#0f172a; }

    .cd-table { width:100%; border-collapse:separate; border-spacing:0; font-size:12.5px; table-layout:fixed; }
    .cd-table thead th { position:sticky; top:0; z-index:1; background:#f8fafc; color:#475569; font-size:10.5px; font-weight:600; text-transform:uppercase; letter-spacing:0.04em; padding:9px 10px; text-align:left; border-bottom:2px solid #e2e8f0; }
    .cd-table tbody td { padding:8px 10px; border-bottom:1px solid #f1f5f9; vertical-align:top; word-wrap:break-word; }
    .cd-table tbody tr:hover td { background:#f8fafc; }
    .cd-table colgroup col.c-page { width:130px; }
    .cd-table colgroup col.c-name { width:160px; }
    .cd-table colgroup col.c-phone { width:120px; }
    .cd-table colgroup col.c-shop { width:18%; }
    .cd-table colgroup col.c-conv { width:auto; }
    .cd-table colgroup col.c-ai { width:110px; }
    .cd-table colgroup col.c-action { width:60px; }
    .row-del-btn { background:transparent; border:1px solid #fecaca; color:#dc2626; padding:3px 8px; border-radius:5px; font-size:11px; cursor:pointer; }
    .row-del-btn:hover { background:#fef2f2; }

    .text-block-pre { white-space:pre-wrap; font-size:11px; color:#475569; line-height:1.4; max-height:160px; overflow:auto; padding:6px; background:#f8fafc; border-radius:4px; border:1px solid #f1f5f9; }
    .pill-page { background:#fef2f2; color:#b91c1c; padding:2px 8px; border-radius:999px; font-size:11px; font-weight:500; }

    .pill-ai { display:inline-flex; align-items:center; padding:3px 9px; border-radius:999px; font-size:10.5px; font-weight:700; }
    .pill-ai.cancel { background:#fee2e2; color:#991b1b; }
    .pill-ai.not_cancel { background:#dcfce7; color:#166534; }
    .pill-ai.unknown { background:#fef3c7; color:#92400e; }
    .pill-ai.pending { background:#f1f5f9; color:#64748b; font-style:italic; }
  </style>

  <div class="w-full flex flex-col gap-4 p-2">
    @if(session('status'))
      <div class="p-3 rounded bg-green-100 text-green-800 font-semibold text-center">{{ session('status') }}</div>
    @endif

    {{-- Filters --}}
    <div class="cd-card">
      <div class="cd-card-header">
        <div class="cd-title">📊 Imported records ({{ $rows->total() }} total)</div>
        <div class="flex gap-2 flex-wrap items-center">
          <a href="/conversation/cancel-detector" class="cd-btn-ghost">← Back to Import</a>
          <a href="/conversation/cancel-detector/settings" class="cd-btn-ghost">⚙️ Settings</a>
          <form method="POST" action="/conversation/cancel-detector/view"
                onsubmit="return confirm('🚨 PERMANENTLY DELETE ALL cancel_detectors records? This cannot be undone!')"
                class="inline">
            @csrf @method('DELETE')
            <button type="submit"
                    class="bg-red-600 hover:bg-red-700 text-white text-xs font-semibold px-3 py-1.5 rounded"
                    title="Debug: truncate the entire table">
              🗑️ Delete ALL
            </button>
          </form>
        </div>
      </div>
      <form method="GET" action="/conversation/cancel-detector/view" class="grid grid-cols-2 md:grid-cols-5 gap-2 p-3">
        <input type="text" name="search" value="{{ request('search') }}" placeholder="🔎 search name / phone / page / shop / conversation…" class="cd-input md:col-span-2" />
        <select name="page_name" class="cd-select">
          <option value="">All pages</option>
          @foreach ($pages as $p)
            <option value="{{ $p }}" @selected(request('page_name') === $p)>{{ $p }}</option>
          @endforeach
        </select>
        <select name="ai_analysis" class="cd-select">
          <option value="">All AI verdicts</option>
          <option value="pending"     @selected(request('ai_analysis') === 'pending')>⏳ Pending (NULL)</option>
          <option value="cancel"      @selected(request('ai_analysis') === 'cancel')>🚫 Cancel</option>
          <option value="not_cancel"  @selected(request('ai_analysis') === 'not_cancel')>✓ Not cancel</option>
          <option value="unknown"     @selected(request('ai_analysis') === 'unknown')>❓ Unknown</option>
        </select>
        <div class="flex gap-2">
          <button type="submit" class="cd-btn">Apply</button>
          <a href="/conversation/cancel-detector/view" class="cd-btn-ghost">Reset</a>
        </div>
      </form>
    </div>

    {{-- Table --}}
    <div class="cd-card overflow-hidden">
      <div class="overflow-auto" style="max-height:calc(100vh - 280px);">
        <table class="cd-table">
          <colgroup>
            <col class="c-page">
            <col class="c-name">
            <col class="c-phone">
            <col class="c-shop">
            <col class="c-conv">
            <col class="c-ai">
            <col class="c-action">
          </colgroup>
          <thead>
            <tr>
              <th>Page</th>
              <th>Name</th>
              <th>Phone</th>
              <th>Shop Details</th>
              <th>Conversation</th>
              <th style="text-align:center;">AI Verdict</th>
              <th style="text-align:center;">Action</th>
            </tr>
          </thead>
          <tbody>
            @forelse ($rows as $r)
              <tr>
                <td><span class="pill-page">{{ $r->page_name ?: '—' }}</span></td>
                <td style="font-weight:600;">{{ $r->name ?: '—' }}</td>
                <td style="font-family:monospace;font-size:11.5px;">{{ $r->phone_number ?: '—' }}</td>
                <td>
                  @if ($r->shop_details)
                    <div class="text-block-pre">{{ $r->shop_details }}</div>
                  @else
                    <span style="color:#cbd5e1;">—</span>
                  @endif
                </td>
                <td>
                  @if ($r->conversation)
                    <div class="text-block-pre">{{ $r->conversation }}</div>
                  @else
                    <span style="color:#cbd5e1;">—</span>
                  @endif
                </td>
                <td style="text-align:center;">
                  @if ($r->ai_analysis === 'cancel')
                    <span class="pill-ai cancel" title="AI verdict @ {{ optional($r->ai_analyzed_at)->format('M j H:i') }}">🚫 Cancel</span>
                  @elseif ($r->ai_analysis === 'not_cancel')
                    <span class="pill-ai not_cancel" title="AI verdict @ {{ optional($r->ai_analyzed_at)->format('M j H:i') }}">✓ Not cancel</span>
                  @elseif ($r->ai_analysis === 'unknown')
                    <span class="pill-ai unknown" title="AI verdict @ {{ optional($r->ai_analyzed_at)->format('M j H:i') }}">❓ Unknown</span>
                  @else
                    <span class="pill-ai pending" title="AI hasn't analyzed this row yet (Phase 2)">⏳ Pending</span>
                  @endif
                </td>
                <td style="text-align:center;">
                  <form method="POST" action="/conversation/cancel-detector/view/{{ $r->id }}"
                        onsubmit="return confirm('Delete row #{{ $r->id }}?')"
                        class="inline">
                    @csrf @method('DELETE')
                    <button type="submit" class="row-del-btn">🗑️</button>
                  </form>
                </td>
              </tr>
            @empty
              <tr>
                <td colspan="7" style="text-align:center;padding:30px;color:#94a3b8;">
                  No records found. <a href="/conversation/cancel-detector" class="text-red-600 underline">Run an import →</a>
                </td>
              </tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div style="padding:10px 14px;border-top:1px solid #f1f5f9;">
        {{ $rows->links() }}
      </div>
    </div>
  </div>
</x-layout>

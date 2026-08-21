<x-layout>
  <x-slot name="title">Prompt Generator — History</x-slot>
  <x-slot name="heading"><div class="text-xl font-bold">📜 Prompt Generator — History</div></x-slot>

  <style>
    .pg-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 1px 2px rgba(0,0,0,0.04); }
    .pg-btn-ghost { display:inline-flex; align-items:center; gap:4px; background:transparent; color:#64748b; font-size:12px; padding:6px 12px; border-radius:6px; border:1px solid #e2e8f0; }
    .pg-btn-ghost:hover { background:#f1f5f9; color:#0f172a; }
    .pg-table { width:100%; border-collapse:separate; border-spacing:0; font-size:12.5px; }
    .pg-table thead th { background:#f8fafc; color:#475569; font-size:10.5px; font-weight:600; text-transform:uppercase; letter-spacing:.04em; padding:8px 10px; text-align:left; border-bottom:2px solid #e2e8f0; }
    .pg-table tbody td { padding:8px 10px; border-bottom:1px solid #f1f5f9; vertical-align:top; }
    .pg-table tbody tr:hover td { background:#f8fafc; }
    .badge { display:inline-flex; padding:2px 8px; border-radius:999px; font-size:10.5px; font-weight:600; }
    .badge.tpl { background:#e0e7ff; color:#3730a3; } .badge.ai { background:#dcfce7; color:#166534; }
  </style>

  <div class="w-full flex flex-col gap-4 p-2">
    <div class="pg-card">
      <div class="flex items-center justify-between p-3 border-b border-slate-100">
        <div class="text-sm font-semibold text-slate-800">History (latest 300)</div>
        <a href="{{ route('prompt.generator.index') }}" class="pg-btn-ghost">← Prompt Generator</a>
      </div>
      <div class="p-0 overflow-auto" style="max-height:78vh;">
        <table class="pg-table">
          <thead>
            <tr>
              <th style="width:150px;">Time (Manila)</th>
              <th>User</th>
              <th style="width:90px;">Mode</th>
              <th>Shop</th>
              <th>Product</th>
              <th style="width:70px;"></th>
            </tr>
          </thead>
          <tbody>
            @forelse($rows as $r)
              <tr>
                <td style="font-size:11px;color:#64748b;white-space:nowrap;">{{ optional($r->created_at)->format('Y-m-d H:i:s') }}</td>
                <td>{{ $r->user_name ?: '—' }}</td>
                <td>
                  @if($r->mode === 'ai')<span class="badge ai">AI</span>@else<span class="badge tpl">Template</span>@endif
                  @if($r->model)<div class="text-[10px] text-slate-400 mt-0.5">{{ $r->model }}</div>@endif
                </td>
                <td>{{ $r->store_name ?: '—' }}</td>
                <td class="max-w-[280px] truncate">{{ $r->product_name ?: '—' }}</td>
                <td><a href="{{ route('prompt.generator.history.detail', $r->id) }}" class="pg-btn-ghost">View</a></td>
              </tr>
            @empty
              <tr><td colspan="6" style="text-align:center;color:#94a3b8;padding:32px;">— Wala pang na-generate —</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
</x-layout>

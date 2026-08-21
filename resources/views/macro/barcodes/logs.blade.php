<x-layout>
  <x-slot name="title">Barcode Print Logs</x-slot>
  <x-slot name="heading"><div class="text-xl font-bold">📜 Barcode Print Logs</div></x-slot>

  <style>
    .bc-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 1px 2px rgba(0,0,0,0.04); }
    .bc-card-header { display:flex; align-items:center; justify-content:space-between; padding:12px 16px; border-bottom:1px solid #f1f5f9; flex-wrap:wrap; gap:8px; }
    .bc-title { font-size:14px; font-weight:600; color:#0f172a; }
    .bc-btn-ghost { display:inline-flex; align-items:center; gap:4px; background:transparent; color:#64748b; font-size:12px; padding:6px 12px; border-radius:6px; border:1px solid #e2e8f0; }
    .bc-btn-ghost:hover { background:#f1f5f9; color:#0f172a; }
    .bc-table { width:100%; border-collapse:separate; border-spacing:0; font-size:12.5px; }
    .bc-table thead th { background:#f8fafc; color:#475569; font-size:10.5px; font-weight:600; text-transform:uppercase; letter-spacing:.04em; padding:8px 10px; text-align:left; border-bottom:2px solid #e2e8f0; }
    .bc-table tbody td { padding:8px 10px; border-bottom:1px solid #f1f5f9; vertical-align:top; }
    .bc-table tbody tr:hover td { background:#f8fafc; }
  </style>

  <div class="w-full flex flex-col gap-4 p-2">
    <div class="bc-card">
      <div class="bc-card-header">
        <div class="bc-title">📜 Print History (latest 500)</div>
        <a href="{{ route('macro.barcodes.index') }}" class="bc-btn-ghost">← Barcode Generator</a>
      </div>
      <div class="p-0 overflow-auto" style="max-height:75vh;">
        <table class="bc-table">
          <thead>
            <tr>
              <th style="width:160px;">Printed At (Manila)</th>
              <th>User</th>
              <th style="width:120px;">Target Date</th>
              <th style="width:90px; text-align:right;">Bundles</th>
              <th style="width:90px; text-align:right;">Waybills</th>
              <th style="width:120px;">IP</th>
            </tr>
          </thead>
          <tbody>
            @forelse($logs as $row)
              <tr>
                <td style="font-size:11px;color:#64748b;white-space:nowrap;">{{ optional($row->created_at)->format('Y-m-d H:i:s') }}</td>
                <td>
                  <div style="font-weight:600;color:#0f172a;">{{ $row->user_name ?: '—' }}</div>
                  <div style="font-size:11px;color:#64748b;">{{ $row->user_email ?: '' }}</div>
                  @if($row->user_role)<div style="font-size:10.5px;color:#94a3b8;">{{ $row->user_role }}</div>@endif
                </td>
                <td style="white-space:nowrap;">{{ optional($row->target_date)->format('Y-m-d') }}</td>
                <td style="text-align:right;font-weight:600;">{{ number_format($row->bundle_count) }}</td>
                <td style="text-align:right;">{{ number_format($row->waybill_count) }}</td>
                <td style="font-size:11px;color:#94a3b8;font-family:monospace;">{{ $row->ip ?: '—' }}</td>
              </tr>
            @empty
              <tr><td colspan="6" style="text-align:center;color:#94a3b8;padding:32px;">— Wala pang naitalang print —</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
</x-layout>

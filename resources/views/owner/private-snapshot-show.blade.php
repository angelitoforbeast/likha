<x-layout>
  <x-slot name="title">Snapshot #{{ $snapshot->id }}</x-slot>
  <x-slot name="heading">
    📸 Snapshot #{{ $snapshot->id }} —
    {{ $snapshot->start_date }} → {{ $snapshot->end_date }}
  </x-slot>

  <style>
    .sd-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 1px 2px rgba(0,0,0,0.04); }
    .sd-card-header { display:flex; align-items:center; justify-content:space-between;
                       padding:10px 14px; border-bottom:1px solid #f1f5f9; flex-wrap:wrap; gap:8px; }
    .sd-title { font-size:13px; font-weight:600; color:#0f172a; }
    .sd-meta { display:flex; gap:14px; flex-wrap:wrap; font-size:11px; color:#475569; padding:10px 14px; background:#f8fafc; border-bottom:1px solid #f1f5f9; }
    .sd-meta strong { color:#0f172a; }

    .sd-table { width:100%; border-collapse:separate; border-spacing:0; font-size:11.5px; }
    .sd-table thead th { background:#0f172a; color:#fff; padding:6px 8px; font-size:10px;
                         text-align:left; font-weight:700; white-space:nowrap; }
    .sd-table tbody td { padding:6px 8px; border-bottom:1px solid #f1f5f9; vertical-align:top; font-size:11px; }
    .sd-table tbody tr:hover td { background:#f8fafc; }
    .sd-num { text-align:right; font-family:ui-monospace,monospace; }
    .sd-pos { color:#16a34a; font-weight:600; }
    .sd-neg { color:#dc2626; font-weight:600; }
    .sd-muted { color:#94a3b8; }
  </style>

  @php
    $rows = $payload['rows'] ?? [];
    $tot  = $payload['totals'] ?? null;  // may exist kung kasama sa save
    $money = fn($v) => $v === null ? '—' : '₱' . number_format((float)$v, 2);
    $num   = fn($v) => $v === null ? '—' : number_format((float)$v);
    $pct   = fn($v) => $v === null ? '—' : number_format((float)$v, 1) . '%';
    $sign  = fn($v) => $v === null ? '' : ($v >= 0 ? 'sd-pos' : 'sd-neg');
  @endphp

  <div class="w-full flex flex-col gap-4 p-2">
    <div class="sd-card">
      <div class="sd-card-header">
        <div class="sd-title">📸 Snapshot details ({{ count($rows) }} rows)</div>
        <div style="display:flex;gap:8px;">
          <a href="{{ route('owner.private.snapshots.index') }}"
             style="font-size:12px;color:#64748b;text-decoration:none;padding:5px 10px;border-radius:6px;background:transparent;"
             onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='transparent'">← Back to list</a>
          <a href="/owner/private?start_date={{ $snapshot->start_date }}&end_date={{ $snapshot->end_date }}"
             style="font-size:12px;color:#2563eb;text-decoration:none;padding:5px 10px;border-radius:6px;background:#dbeafe;"
             title="Open /owner/private with this snapshot's date range — compare snapshot vs live data">↗ Open live /owner/private</a>
        </div>
      </div>

      <div class="sd-meta">
        <span>🆔 <strong>#{{ $snapshot->id }}</strong></span>
        <span>📅 <strong>{{ $snapshot->start_date }} → {{ $snapshot->end_date }}</strong>
          ({{ \Carbon\Carbon::parse($snapshot->start_date)->diffInDays(\Carbon\Carbon::parse($snapshot->end_date)) + 1 }} days)</span>
        <span>👁 <strong>{{ strtoupper($snapshot->view_as ?? 'CEO') }}</strong> view</span>
        <span>💾 Saved by <strong>{{ $snapshot->user_email ?? '—' }}</strong></span>
        <span>⏰ <strong>{{ \Carbon\Carbon::parse($snapshot->snapshot_at)->format('Y-m-d H:i:s') }}</strong>
          ({{ \Carbon\Carbon::parse($snapshot->snapshot_at)->diffForHumans() }})</span>
        @if($snapshot->skipped_count > 0)
          <span style="color:#b45309;">⚠ {{ $snapshot->skipped_count }} page(s) skipped at save-time</span>
        @endif
      </div>

      <div style="overflow-x:auto;max-height:calc(100vh - 280px);">
        <table class="sd-table">
          <thead>
            <tr>
              <th>Page</th>
              <th>Item</th>
              <th class="sd-num">Adspent</th>
              <th class="sd-num">Orders</th>
              <th class="sd-num">Orders (1D)</th>
              <th class="sd-num">Proceed</th>
              <th class="sd-num">CPP</th>
              <th class="sd-num">Price</th>
              <th class="sd-num">Item Val</th>
              <th class="sd-num">Shipping</th>
              <th class="sd-num">COD Fee</th>
              <th class="sd-num">Set RTS%</th>
              <th class="sd-num">JNT RTS%</th>
              <th class="sd-num">Proj. Profit</th>
              <th class="sd-num">Proj. % (1M)</th>
              <th class="sd-num">Proj. Profit (1D)</th>
              <th class="sd-num">Proj. % (1D)</th>
              <th>Promo</th>
              <th>Anchor Since</th>
            </tr>
          </thead>
          <tbody>
            @forelse($rows as $r)
              <tr>
                <td style="font-weight:600;">{{ $r['page_name'] ?? '—' }}</td>
                <td>{{ $r['item_name'] ?? '—' }}</td>
                <td class="sd-num">{{ $money($r['adspent'] ?? null) }}</td>
                <td class="sd-num">{{ $num($r['orders'] ?? null) }}</td>
                <td class="sd-num">{{ $num($r['orders_last_day'] ?? null) }}</td>
                <td class="sd-num">{{ $num($r['proceed_orders'] ?? null) }}</td>
                <td class="sd-num">{{ $money($r['cpp'] ?? null) }}</td>
                <td class="sd-num">{{ $money($r['price'] ?? null) }}</td>
                <td class="sd-num">{{ $money($r['item_value'] ?? null) }}</td>
                <td class="sd-num">{{ $money($r['shipping_fee'] ?? null) }}</td>
                <td class="sd-num">{{ $money($r['cod_fee'] ?? null) }}</td>
                <td class="sd-num">{{ ($r['rts_pct'] ?? null) !== null ? $pct($r['rts_pct']) : '—' }}</td>
                <td class="sd-num">{{ ($r['jnt_rts_pct'] ?? null) !== null ? $pct($r['jnt_rts_pct']) : '—' }}</td>
                <td class="sd-num {{ $sign($r['projected_profit'] ?? null) }}">{{ $money($r['projected_profit'] ?? null) }}</td>
                @php
                  $pp = ($r['projected_profit'] ?? null) !== null && ($r['gross_sales'] ?? 0) > 0
                      ? ($r['projected_profit'] / $r['gross_sales'] * 100) : null;
                @endphp
                <td class="sd-num {{ $sign($pp) }}">{{ $pp !== null ? number_format($pp, 1) . '%' : '—' }}</td>
                <td class="sd-num {{ $sign($r['projected_profit_last_day'] ?? null) }}">{{ $money($r['projected_profit_last_day'] ?? null) }}</td>
                <td class="sd-num {{ $sign($r['proj_pct_last_day'] ?? null) }}">{{ ($r['proj_pct_last_day'] ?? null) !== null ? number_format($r['proj_pct_last_day'], 1) . '%' : '—' }}</td>
                <td>{{ $r['promo'] ?? '—' }}</td>
                <td style="font-family:ui-monospace,monospace;font-size:10.5px;color:#7c3aed;">{{ $r['anchor_first_date'] ?? '—' }}</td>
              </tr>
            @empty
              <tr>
                <td colspan="19" style="text-align:center;padding:30px;color:#94a3b8;">Empty snapshot.</td>
              </tr>
            @endforelse
          </tbody>
        </table>
      </div>

      <div style="padding:8px 14px;border-top:1px solid #f1f5f9;font-size:10.5px;color:#94a3b8;">
        Frozen capture — values reflect what /owner/private displayed at <strong>{{ \Carbon\Carbon::parse($snapshot->snapshot_at)->format('Y-m-d H:i:s') }}</strong>.
        Underlying data (orders, RTS settings, COGS) may have changed since.
      </div>
    </div>
  </div>
</x-layout>

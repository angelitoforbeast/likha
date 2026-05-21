<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Snapshot #{{ $snapshot->id }} • /owner/private</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    * { box-sizing:border-box; margin:0; padding:0; }
    html, body { height:100%; background:#f1f5f9; }
    body { display:flex; flex-direction:column; overflow:hidden; font-family:system-ui,-apple-system,'Segoe UI',Roboto,sans-serif; }

    #nav {
      flex-shrink:0; height:52px; background:#1e293b;
      border-bottom:1px solid #334155;
      display:flex; align-items:center; padding:0 18px; gap:10px;
      position:relative; z-index:100;
      color:#e2e8f0;
    }
    #nav .nav-title { font-weight:700; font-size:13px; }
    #nav .nav-meta  { font-size:11px; color:#94a3b8; }
    #nav a { color:#94a3b8; font-size:12px; text-decoration:none; padding:5px 10px; border-radius:6px; border:1px solid #334155; }
    #nav a:hover { color:#e2e8f0; background:#0f172a; border-color:#475569; }
    #nav a.primary { color:#dbeafe; background:#1d4ed8; border-color:#2563eb; }
    #nav a.primary:hover { background:#1e40af; }

    .meta-bar {
      flex-shrink:0; background:#f8fafc; border-bottom:1px solid #e2e8f0;
      padding:8px 18px; display:flex; gap:18px; flex-wrap:wrap;
      font-size:11.5px; color:#475569;
    }
    .meta-bar .item { display:flex; align-items:center; gap:5px; }
    .meta-bar .item strong { color:#0f172a; }
    .meta-bar .pill {
      display:inline-flex; align-items:center; padding:2px 8px;
      border-radius:9999px; font-size:10.5px; font-weight:700;
    }
    .pill-ceo  { background:#dbeafe; color:#1e40af; }
    .pill-mkt  { background:#fef3c7; color:#92400e; }
    .pill-warn { background:#fef3c7; color:#b45309; }

    #scroll { flex:1; overflow:auto; padding:0 16px; min-width:0; }

    .card {
      background:#fff; border-radius:10px;
      box-shadow:0 1px 4px rgba(0,0,0,.09);
      min-width:900px; margin:14px 0;
    }

    table { width:100%; border-collapse:separate; border-spacing:0; }

    thead th {
      position:sticky; top:0; z-index:30;
      background:#1e293b; color:#94a3b8;
      font-size:11px; font-weight:600;
      text-transform:uppercase; letter-spacing:.05em;
      padding:9px 10px; white-space:nowrap;
      border-bottom:2px solid #0f172a;
      text-align:left;
    }
    thead th:first-child { border-radius:10px 0 0 0; }
    thead th:last-child  { border-radius:0 10px 0 0; }
    thead th.num { text-align:center; }

    tbody td {
      font-size:12.5px; color:#374151;
      padding:7px 10px; white-space:nowrap;
      vertical-align:middle;
      border-bottom:1px solid #f1f5f9;
    }
    tbody td.num { text-align:center; font-variant-numeric:tabular-nums; }
    tbody tr:hover td { background:#f8fafc; }

    tr.total-row td {
      position:sticky; bottom:0; z-index:20;
      font-weight:700; color:#0f172a;
      background:#f1f5f9; border-top:2px solid #cbd5e1;
    }

    .null-cell { background:#fef2f2; }
    .muted { color:#94a3b8; }
    .pos { color:#16a34a; font-weight:700; }
    .neg { color:#dc2626; font-weight:700; }
    .small { font-size:9px; color:#94a3b8; margin-top:2px; }
    .comment { font-size:9px; color:#64748b; margin-top:1px; font-style:italic; white-space:normal; max-width:120px; }

    .anchor-tag {
      font-family:ui-monospace,monospace; font-size:10.5px; color:#7c3aed;
    }

    .table-foot { padding:7px 12px; font-size:10px; color:#94a3b8; border-top:1px solid #f1f5f9; }
  </style>
</head>
<body>

@php
  use Carbon\Carbon;

  // ── Helpers para sa rendering (Blade-only, pure functions) ───────────
  $money = fn($v) => $v === null || $v === '' ? '—'
                   : '₱' . number_format((float)$v, 2);
  $moneyR = fn($v) => $v === null || $v === '' ? '—'
                    : '₱' . number_format(round((float)$v), 0);
  $num   = fn($v) => $v === null || $v === '' ? '—' : number_format((float)$v);
  $pct1  = fn($v) => $v === null || $v === '' ? '—' : number_format((float)$v, 1) . '%';

  // Profit background color (mimics pbStyle from frontend — green/red intensity)
  $pbStyle = function($v) {
    if ($v === null || $v === '') return '';
    $v = (float)$v;
    if ($v > 0)  return 'background:#dcfce7;';
    if ($v < 0)  return 'background:#fee2e2;';
    return '';
  };
  $pbColor = function($v) {
    if ($v === null || $v === '') return '#374151';
    $v = (float)$v;
    if ($v > 0)  return '#15803d';
    if ($v < 0)  return '#b91c1c';
    return '#374151';
  };

  // Proj.% background tint (mimics rppStyle — bands based on margin %)
  $rppStyle = function($v) {
    if ($v === null || $v === '') return '';
    $v = (float)$v;
    if ($v >= 10) return 'background:#bbf7d0;';
    if ($v >= 5)  return 'background:#dcfce7;';
    if ($v >  0)  return 'background:#f0fdf4;';
    if ($v == 0)  return '';
    if ($v >= -5) return 'background:#fef3c7;';
    return 'background:#fee2e2;';
  };

  $rows = $payload['rows'] ?? [];
  $rangeDays = $payload['range_days'] ?? (Carbon::parse($snapshot->start_date)->diffInDays(Carbon::parse($snapshot->end_date)) + 1);
  $isSingleDate = $payload['is_single_date'] ?? ($snapshot->start_date === $snapshot->end_date);

  // ── Totals (computed kasi hindi kasama sa current payload structure) ──
  $tot = [
    'adspent'      => 0, 'orders'    => 0, 'orders_last_day' => 0,
    'proceed_orders' => 0, 'projected_profit' => 0, 'gross_sales' => 0,
    'projected_profit_last_day' => 0, 'projected_profit_last_3d' => 0, 'projected_profit_last_7d' => 0,
    'gross_sales_last_day' => 0, 'gross_sales_last_3d' => 0, 'gross_sales_last_7d' => 0,
  ];
  $hasAnyVal = false;
  foreach ($rows as $r) {
    foreach ($tot as $k => $_) {
      if (isset($r[$k]) && $r[$k] !== null) { $tot[$k] += (float)$r[$k]; $hasAnyVal = true; }
    }
  }
  $totCpp        = $tot['orders'] > 0          ? $tot['adspent']  / $tot['orders']         : null;
  $totPcpp       = $tot['proceed_orders'] > 0  ? $tot['adspent']  / $tot['proceed_orders'] : null;
  $totTcpr       = $tot['orders'] > 0          ? (1 - $tot['proceed_orders'] / $tot['orders']) * 100 : null;
  $totProjPct    = $tot['gross_sales'] > 0     ? $tot['projected_profit']          / $tot['gross_sales']          * 100 : null;
  $totProjPct1d  = $tot['gross_sales_last_day'] > 0 ? $tot['projected_profit_last_day'] / $tot['gross_sales_last_day'] * 100 : null;
  $totProjPct3d  = $tot['gross_sales_last_3d'] > 0  ? $tot['projected_profit_last_3d'] / $tot['gross_sales_last_3d'] * 100 : null;
  $totProjPct7d  = $tot['gross_sales_last_7d'] > 0  ? $tot['projected_profit_last_7d'] / $tot['gross_sales_last_7d'] * 100 : null;
  $totPerOrder   = $tot['orders'] > 0          ? $tot['projected_profit'] / $tot['orders'] : null;
  $totNpPerOrder = $tot['orders_last_day'] > 0 ? $tot['projected_profit_last_day'] / $tot['orders_last_day'] : null;
@endphp

<div id="nav">
  <div>
    <div class="nav-title">📸 Snapshot #{{ $snapshot->id }} <span style="color:#fbbf24;">(frozen view)</span></div>
    <div class="nav-meta">
      {{ $snapshot->start_date }} → {{ $snapshot->end_date }} ·
      saved {{ Carbon::parse($snapshot->snapshot_at)->format('Y-m-d H:i') }} ·
      {{ Carbon::parse($snapshot->snapshot_at)->diffForHumans() }}
    </div>
  </div>
  <div style="margin-left:auto;display:flex;gap:8px;align-items:center;">
    <a href="{{ route('owner.private.snapshots.index') }}">📂 All Snapshots</a>
    <a href="/owner/private?start_date={{ $snapshot->start_date }}&end_date={{ $snapshot->end_date }}"
       class="primary" title="Open /owner/private with same date range — compare vs live">↗ Open live</a>
  </div>
</div>

<div class="meta-bar">
  <span class="item">🆔 <strong>#{{ $snapshot->id }}</strong></span>
  <span class="item">📅 <strong>{{ $snapshot->start_date }} → {{ $snapshot->end_date }}</strong>
    ({{ $rangeDays }} day{{ $rangeDays > 1 ? 's' : '' }}{{ $isSingleDate ? ', single-date' : '' }})</span>
  <span class="item">👁
    @php $va = strtolower((string)($snapshot->view_as ?? 'ceo')); @endphp
    <span class="pill {{ $va === 'marketing' ? 'pill-mkt' : 'pill-ceo' }}">{{ strtoupper($va) }}</span>
  </span>
  <span class="item">💾 <strong>{{ $snapshot->user_email ?? '—' }}</strong></span>
  <span class="item">📊 <strong>{{ count($rows) }}</strong> row{{ count($rows) !== 1 ? 's' : '' }}</span>
  @if($snapshot->skipped_count > 0)
    <span class="item"><span class="pill pill-warn">⚠ {{ $snapshot->skipped_count }} page(s) skipped at save</span></span>
  @endif
</div>

<div id="scroll">
  <div class="card">
    <table>
      <thead>
        <tr>
          <th style="min-width:160px;">Page</th>
          <th style="min-width:200px;">Item</th>
          <th class="num" style="min-width:90px;">Adspent</th>
          <th class="num" style="min-width:65px;">Orders</th>
          <th class="num" style="min-width:80px;">Orders (1D)</th>
          <th class="num" style="min-width:75px;">CPP</th>
          <th class="num" style="min-width:70px;">Proceed</th>
          <th class="num" style="min-width:75px;">P.CPP</th>
          <th class="num" style="min-width:65px;">TCPR</th>
          <th class="num" style="min-width:95px;">Prof.Profit</th>
          <th class="num" style="min-width:75px;">/Order</th>
          <th class="num" style="min-width:75px;">NP/O</th>
          <th class="num" style="min-width:75px;">Prof.%(1M)</th>
          <th class="num" style="min-width:75px;">Prof.%(1D)</th>
          <th class="num" style="min-width:75px;">Prof.%(3D)</th>
          <th class="num" style="min-width:75px;">Prof.%(7D)</th>
          <th class="num" style="min-width:105px;">Prof.Profit(1D)</th>
          <th class="num" style="min-width:105px;">Prof.Profit(3D)</th>
          <th class="num" style="min-width:105px;">Prof.Profit(7D)</th>
          <th class="num" style="min-width:100px;">RTS%</th>
          <th class="num" style="min-width:90px;">Del%</th>
          <th class="num" style="min-width:85px;">Transit%</th>
          <th class="num" style="min-width:110px;">Set RTS%</th>
          <th class="num" style="min-width:90px;">Promo</th>
          <th class="num" style="min-width:85px;">Price</th>
          <th class="num" style="min-width:80px;">Item Val.</th>
          <th class="num" style="min-width:90px;">Item Val. (CEO)</th>
          <th class="num" style="min-width:58px;">Ship</th>
          <th class="num" style="min-width:72px;">COD Fee</th>
          <th style="min-width:115px;">Anchor Since</th>
        </tr>
      </thead>

      <tbody>
        @forelse($rows as $r)
          @php
            $pp = (isset($r['projected_profit']) && $r['projected_profit'] !== null
                   && isset($r['gross_sales']) && (float)$r['gross_sales'] > 0)
                ? ($r['projected_profit'] / $r['gross_sales'] * 100) : null;
            $npo = (isset($r['projected_profit_last_day']) && $r['projected_profit_last_day'] !== null
                   && isset($r['orders_last_day']) && (int)$r['orders_last_day'] > 0)
                 ? ($r['projected_profit_last_day'] / $r['orders_last_day']) : null;
            $tcpr = (isset($r['orders']) && (int)$r['orders'] > 0)
                  ? (1 - (($r['proceed_orders'] ?? 0) / $r['orders'])) * 100 : null;
          @endphp
          <tr>
            <td style="font-weight:600;color:#0f172a;">{{ $r['page_name'] ?? '—' }}</td>
            <td>{{ $r['item_name'] ?? '—' }}</td>

            {{-- Adspent --}}
            <td class="num">{{ $money($r['adspent'] ?? null) }}</td>
            {{-- Orders --}}
            <td class="num">{{ $num($r['orders'] ?? null) }}</td>
            {{-- Orders (1D) --}}
            <td class="num">{{ $num($r['orders_last_day'] ?? null) }}</td>
            {{-- CPP --}}
            <td class="num">{{ $moneyR($r['cpp'] ?? null) }}</td>
            {{-- Proceed --}}
            <td class="num" style="font-weight:600;">{{ $num($r['proceed_orders'] ?? null) }}</td>
            {{-- P.CPP --}}
            <td class="num">{{ $moneyR($r['proceed_cpp'] ?? null) }}</td>
            {{-- TCPR --}}
            <td class="num">{{ $tcpr === null ? '—' : number_format($tcpr, 1) . '%' }}</td>

            {{-- Prof.Profit --}}
            <td class="num" style="{{ $pbStyle($r['projected_profit'] ?? null) }}">
              <span style="font-weight:700;color:{{ $pbColor($r['projected_profit'] ?? null) }};">
                {{ $moneyR($r['projected_profit'] ?? null) }}
              </span>
            </td>

            {{-- /Order --}}
            <td class="num">{{ $moneyR($r['proj_profit_per_order'] ?? null) }}</td>

            {{-- NP/O --}}
            <td class="num">
              @if($npo !== null)
                <span style="font-weight:600;color:{{ $pbColor($npo) }};">{{ $moneyR($npo) }}</span>
              @else
                <span class="muted">—</span>
              @endif
            </td>

            {{-- Prof.%(1M) --}}
            <td class="num" style="{{ $rppStyle($pp) }}">
              @if($pp !== null)
                <span style="font-weight:700;">{{ number_format($pp, 1) }}%</span>
              @else
                <span class="muted">—</span>
              @endif
            </td>

            {{-- Prof.%(1D) --}}
            <td class="num" style="{{ $rppStyle($r['proj_pct_last_day'] ?? null) }}">
              @if(($r['proj_pct_last_day'] ?? null) !== null)
                <span style="font-weight:700;">{{ number_format((float)$r['proj_pct_last_day'], 1) }}%</span>
              @else <span class="muted">—</span> @endif
            </td>

            {{-- Prof.%(3D) --}}
            <td class="num" style="{{ $rppStyle($r['proj_pct_last_3d'] ?? null) }}">
              @if(($r['proj_pct_last_3d'] ?? null) !== null)
                <span style="font-weight:700;">{{ number_format((float)$r['proj_pct_last_3d'], 1) }}%</span>
              @else <span class="muted">—</span> @endif
            </td>

            {{-- Prof.%(7D) --}}
            <td class="num" style="{{ $rppStyle($r['proj_pct_last_7d'] ?? null) }}">
              @if(($r['proj_pct_last_7d'] ?? null) !== null)
                <span style="font-weight:700;">{{ number_format((float)$r['proj_pct_last_7d'], 1) }}%</span>
              @else <span class="muted">—</span> @endif
            </td>

            {{-- Prof.Profit(1D/3D/7D) --}}
            <td class="num" style="{{ $pbStyle($r['projected_profit_last_day'] ?? null) }}">
              <span style="font-weight:700;color:{{ $pbColor($r['projected_profit_last_day'] ?? null) }};">
                {{ $moneyR($r['projected_profit_last_day'] ?? null) }}
              </span>
            </td>
            <td class="num" style="{{ $pbStyle($r['projected_profit_last_3d'] ?? null) }}">
              <span style="font-weight:700;color:{{ $pbColor($r['projected_profit_last_3d'] ?? null) }};">
                {{ $moneyR($r['projected_profit_last_3d'] ?? null) }}
              </span>
            </td>
            <td class="num" style="{{ $pbStyle($r['projected_profit_last_7d'] ?? null) }}">
              <span style="font-weight:700;color:{{ $pbColor($r['projected_profit_last_7d'] ?? null) }};">
                {{ $moneyR($r['projected_profit_last_7d'] ?? null) }}
              </span>
            </td>

            {{-- RTS% (JNT) --}}
            <td class="num">
              @if(($r['jnt_rts_pct'] ?? null) !== null)
                <span style="font-weight:700;">{{ number_format((float)$r['jnt_rts_pct'], 1) }}%<span style="color:#64748b;">({{ $r['jnt_rts_cnt'] ?? 0 }})</span></span>
              @else <span class="muted">—</span> @endif
            </td>
            {{-- Del% --}}
            <td class="num">
              @if(($r['jnt_del_pct'] ?? null) !== null)
                {{ number_format((float)$r['jnt_del_pct'], 1) }}%<span style="color:#64748b;">({{ $r['jnt_del_cnt'] ?? 0 }})</span>
              @else <span class="muted">—</span> @endif
            </td>
            {{-- Transit% --}}
            <td class="num">
              @if(($r['jnt_transit_pct'] ?? null) !== null)
                {{ number_format((float)$r['jnt_transit_pct'], 1) }}%<span style="color:#64748b;">({{ $r['jnt_transit_cnt'] ?? 0 }})</span>
              @else <span class="muted">—</span> @endif
            </td>

            {{-- Set RTS% --}}
            <td class="num {{ ($r['rts_pct'] ?? null) === null ? 'null-cell' : '' }}">
              @if(($r['rts_pct'] ?? null) !== null)
                <div><span style="font-weight:700;color:#000;">{{ number_format((float)$r['rts_pct'], 1) }}%</span></div>
                @if(!empty($r['settings_date']))
                  <div class="small">from {{ $r['settings_date'] }}</div>
                @endif
                @if(!empty($r['rts_comment']))
                  <div class="comment">💬 {{ $r['rts_comment'] }}</div>
                @endif
              @else
                <span style="color:#fca5a5;font-style:italic;font-size:11px;">—</span>
              @endif
            </td>

            {{-- Promo --}}
            <td class="num">
              @php $promoVal = $r['promo'] ?? null; @endphp
              @if($promoVal && strtoupper($promoVal) !== 'NONE' && $promoVal !== '-')
                {{ $promoVal }}
              @else
                <span class="muted">—</span>
              @endif
            </td>

            {{-- Price --}}
            <td class="num">
              @if(($r['price'] ?? null) !== null)
                <div>{{ $money($r['price']) }}</div>
                @if(($r['price_min'] ?? null) !== null)
                  <div class="small">↓ {{ $money($r['price_min']) }}</div>
                @endif
                @if(($r['price_max'] ?? null) !== null)
                  <div class="small">↑ {{ $money($r['price_max']) }}</div>
                @endif
              @else
                <span class="muted">—</span>
              @endif
            </td>

            {{-- Item Val. --}}
            <td class="num {{ ($r['item_value'] ?? null) === null ? 'null-cell' : '' }}">
              @if(($r['item_value'] ?? null) !== null)
                <div>{{ $money($r['item_value']) }}</div>
                @if(($r['item_value_source'] ?? null) === 'cogs')
                  <div class="small" style="color:#cbd5e1;">cogs</div>
                @elseif(($r['item_value_source'] ?? null) === 'manual' && !empty($r['settings_date']))
                  <div class="small">from {{ $r['settings_date'] }}</div>
                @endif
                @if(!empty($r['item_value_comment']) && ($r['item_value_source'] ?? null) === 'manual')
                  <div class="comment">💬 {{ $r['item_value_comment'] }}</div>
                @endif
              @else
                <span style="color:#fca5a5;font-style:italic;font-size:11px;">—</span>
              @endif
            </td>

            {{-- Item Val. (CEO) --}}
            <td class="num">
              @if(($r['item_value_ceo'] ?? null) !== null)
                {{ $money($r['item_value_ceo']) }}
              @else
                <span style="color:#fca5a5;font-style:italic;font-size:11px;">—</span>
              @endif
            </td>

            {{-- Ship --}}
            <td class="num">{{ ($r['shipping_fee'] ?? null) !== null ? $money($r['shipping_fee']) : '—' }}</td>
            {{-- COD Fee --}}
            <td class="num">{{ ($r['cod_fee'] ?? null) !== null ? $money($r['cod_fee']) : '—' }}</td>

            {{-- Anchor Since --}}
            <td>
              @if(!empty($r['anchor_first_date']))
                <span class="anchor-tag">▸ {{ $r['anchor_first_date'] }}</span>
              @else
                <span class="muted">—</span>
              @endif
            </td>
          </tr>
        @empty
          <tr>
            <td colspan="30" style="text-align:center;padding:48px;color:#94a3b8;font-size:13px;">
              Empty snapshot — walang rows na na-save.
            </td>
          </tr>
        @endforelse

        @if($hasAnyVal)
          <tr class="total-row">
            <td>TOTAL</td>
            <td></td>
            <td class="num">{{ $money($tot['adspent']) }}</td>
            <td class="num">{{ $num($tot['orders']) }}</td>
            <td class="num">{{ $num($tot['orders_last_day']) }}</td>
            <td class="num">{{ $totCpp !== null ? $moneyR($totCpp) : '—' }}</td>
            <td class="num">{{ $num($tot['proceed_orders']) }}</td>
            <td class="num">{{ $totPcpp !== null ? $moneyR($totPcpp) : '—' }}</td>
            <td class="num">{{ $totTcpr !== null ? number_format($totTcpr, 1) . '%' : '—' }}</td>
            <td class="num" style="{{ $pbStyle($tot['projected_profit']) }}">
              <span style="font-weight:700;color:{{ $pbColor($tot['projected_profit']) }};">{{ $moneyR($tot['projected_profit']) }}</span>
            </td>
            <td class="num">{{ $totPerOrder !== null ? $moneyR($totPerOrder) : '—' }}</td>
            <td class="num">{{ $totNpPerOrder !== null ? $moneyR($totNpPerOrder) : '—' }}</td>
            <td class="num" style="{{ $rppStyle($totProjPct) }}">{{ $totProjPct !== null ? number_format($totProjPct, 1) . '%' : '—' }}</td>
            <td class="num" style="{{ $rppStyle($totProjPct1d) }}">{{ $totProjPct1d !== null ? number_format($totProjPct1d, 1) . '%' : '—' }}</td>
            <td class="num" style="{{ $rppStyle($totProjPct3d) }}">{{ $totProjPct3d !== null ? number_format($totProjPct3d, 1) . '%' : '—' }}</td>
            <td class="num" style="{{ $rppStyle($totProjPct7d) }}">{{ $totProjPct7d !== null ? number_format($totProjPct7d, 1) . '%' : '—' }}</td>
            <td class="num" style="{{ $pbStyle($tot['projected_profit_last_day']) }}">{{ $moneyR($tot['projected_profit_last_day']) }}</td>
            <td class="num" style="{{ $pbStyle($tot['projected_profit_last_3d']) }}">{{ $moneyR($tot['projected_profit_last_3d']) }}</td>
            <td class="num" style="{{ $pbStyle($tot['projected_profit_last_7d']) }}">{{ $moneyR($tot['projected_profit_last_7d']) }}</td>
            <td colspan="11"></td>
          </tr>
        @endif
      </tbody>
    </table>

    <div class="table-foot">
      📸 Frozen capture — values reflect what /owner/private displayed at
      <strong>{{ Carbon::parse($snapshot->snapshot_at)->format('Y-m-d H:i:s') }}</strong>.
      Underlying data (orders, RTS settings, COGS, JNT updates) may have changed since.
      One row per page · Same column set + color coding ng live /owner/private.
    </div>
  </div>
</div>

</body>
</html>

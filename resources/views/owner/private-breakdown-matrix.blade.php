<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Breakdown Matrix · {{ $startDate }} → {{ $endDate }}</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    body{background:#f8fafc;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;}
    .cell{font-size:10px;line-height:1.2;padding:4px 6px;border:1px solid #e2e8f0;
          white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:120px;
          position:relative;}
    .cell-anchor{background:#dbeafe;color:#1e3a8a;font-weight:600;}
    .cell-mismatch{background:#fef3c7;color:#78350f;}
    .cell-empty{background:#f1f5f9;color:#cbd5e1;text-align:center;}
    .cell-end{border-right:3px solid #2563eb;}
    .cell-item-change{border-left:4px solid #dc2626 !important; padding-left:4px;}
    .cell-price-change{box-shadow: inset 3px 0 0 #a855f7;}
    .badge-new{position:absolute;top:1px;right:2px;background:#dc2626;color:#fff;
               font-size:8px;font-weight:700;padding:0 3px;border-radius:3px;letter-spacing:0.3px;}
    .badge-price{display:inline-block;font-size:9px;font-weight:700;padding:0 3px;border-radius:3px;
                 margin-left:2px;}
    .badge-price-up{background:#10b981;color:#fff;}
    .badge-price-down{background:#ef4444;color:#fff;}
    th.date-col{font-size:10px;padding:4px 6px;background:#0f172a;color:#fff;
                border:1px solid #1e293b;white-space:nowrap;font-family:monospace;}
    th.page-col{position:sticky;left:0;z-index:5;background:#fff;text-align:left;
                padding:4px 8px;border:1px solid #e2e8f0;font-size:11px;max-width:170px;
                white-space:normal;line-height:1.3;}
    th.page-col.mixed{background:#fffbeb;}
    .anchor-label{font-size:9px;color:#2563eb;font-weight:700;}
    thead th{position:sticky;top:0;z-index:4;}
    thead th.page-col{z-index:6;}
  </style>
</head>
<body class="min-h-screen">

  <!-- Header -->
  <div class="bg-slate-900 text-slate-100 px-4 py-3 flex items-center gap-3 shadow flex-wrap">
    <a href="{{ route('owner.private') }}?start_date={{ $startDate }}&end_date={{ $endDate }}"
       class="text-slate-400 hover:text-white text-sm">← Back to Daily Summary</a>
    <span class="text-sm font-bold ml-3">Breakdown Matrix</span>

    <form method="GET" action="{{ route('owner.private.breakdown') }}"
          class="flex items-center gap-2 ml-4">
      <label class="text-xs text-slate-400 font-semibold">From</label>
      <input type="date" name="start_date" value="{{ $startDate }}"
             class="bg-slate-800 border border-slate-600 rounded px-2 py-1 text-sm">
      <label class="text-xs text-slate-400 font-semibold">To</label>
      <input type="date" name="end_date" value="{{ $endDate }}"
             class="bg-slate-800 border border-slate-600 rounded px-2 py-1 text-sm">
      <button type="submit"
              class="bg-blue-600 hover:bg-blue-500 text-white rounded px-3 py-1 text-xs font-bold">Go</button>
      <a href="{{ route('owner.private.breakdown') }}"
         class="text-xs text-slate-400 hover:text-white ml-1"
         title="Reset to this month">↺ this month</a>
    </form>

    <div class="flex-1"></div>
    <span class="text-xs text-slate-400">{{ $startDate }} → {{ $endDate }} ({{ count($dates) }} days · {{ count($pages) }} pages)</span>
  </div>

  <!-- Legend -->
  <div class="max-w-7xl mx-auto px-4 pt-4 pb-2 text-xs text-slate-600 flex gap-4 items-center flex-wrap">
    <span class="inline-flex items-center gap-1"><span class="inline-block w-3 h-3 bg-blue-200 border border-slate-300"></span> anchor (matches end-date primary → included)</span>
    <span class="inline-flex items-center gap-1"><span class="inline-block w-3 h-3 bg-amber-100 border border-slate-300"></span> different primary → excluded</span>
    <span class="inline-flex items-center gap-1"><span class="inline-block w-3 h-3 bg-slate-100 border border-slate-300"></span> no data / tied</span>
    <span class="inline-flex items-center gap-1"><span class="inline-block w-3 h-3 bg-amber-50 border border-slate-300"></span> page with mixed primary</span>
    <span class="inline-flex items-center gap-1"><span class="inline-block w-1 h-3 bg-red-600"></span> item changed (vs prev day)</span>
    <span class="inline-flex items-center gap-1"><span class="inline-block w-1 h-3 bg-purple-500"></span> price changed (same item)</span>
    <span class="ml-auto text-slate-500">End-date column = blue right-border.</span>
  </div>

  <!-- Matrix -->
  <div class="max-w-full overflow-auto px-4 pb-8">
    <div class="bg-white rounded shadow inline-block min-w-full">
      @if(count($pages) === 0)
        <div class="p-8 text-center text-slate-400 text-sm">No data in this range.</div>
      @else
        <table class="border-collapse">
          <thead>
            <tr>
              <th class="page-col" style="min-width:170px;">Page</th>
              @foreach($dates as $d)
                <th class="date-col {{ $d === $endDate ? 'cell-end' : '' }}">
                  {{ \Carbon\Carbon::parse($d)->format('M d') }}
                  @if($d === $endDate)
                    <div class="anchor-label" style="color:#93c5fd;">anchor</div>
                  @endif
                </th>
              @endforeach
            </tr>
          </thead>
          <tbody>
            @foreach($pages as $p)
              <tr>
                <th class="page-col {{ $p['mixed'] ? 'mixed' : '' }}">
                  <a href="{{ route('owner.private.breakdown') }}?page_key={{ urlencode($p['page_key']) }}&start_date={{ $startDate }}&end_date={{ $endDate }}"
                     class="text-slate-900 hover:text-blue-600 font-semibold"
                     target="_blank">{{ $p['page_label'] }}</a>
                  @if($p['mixed'])
                    <div class="text-[10px] text-amber-700 font-semibold mt-0.5">
                      ⚠ {{ $p['distinct_count'] }} items · {{ $p['anchor_included_days'] }}/{{ count($dates) }} d
                    </div>
                  @endif
                  @if(($p['item_changes'] ?? 0) > 0 || ($p['price_changes'] ?? 0) > 0)
                    <div class="text-[10px] mt-0.5" style="color:#475569;">
                      @if($p['item_changes'] > 0)
                        <span style="color:#dc2626;font-weight:700;">● {{ $p['item_changes'] }} item Δ</span>
                      @endif
                      @if($p['price_changes'] > 0)
                        <span style="color:#a855f7;font-weight:700;">● {{ $p['price_changes'] }} price Δ</span>
                      @endif
                    </div>
                  @endif
                  @if($p['anchor_item'])
                    <div class="anchor-label mt-0.5" title="anchor item (end-date primary)">→ {{ $p['anchor_item'] }}</div>
                  @endif
                  @if($p['anchor_first_date'])
                    <div class="text-[10px] text-slate-500 mt-0.5"
                         title="Earliest date in range where this page's primary = anchor. Compute starts here.">
                      computed since {{ \Carbon\Carbon::parse($p['anchor_first_date'])->format('M d') }}
                    </div>
                  @endif
                </th>
                @foreach($dates as $d)
                  @php
                    $cell = $p['cells'][$d] ?? null;
                    $isAnchorCell = $cell && $p['anchor_item_key'] && $cell['item_key'] === $p['anchor_item_key'];
                    $class = 'cell';
                    if ($d === $endDate) $class .= ' cell-end';
                    if (!$cell) $class .= ' cell-empty';
                    elseif ($isAnchorCell) $class .= ' cell-anchor';
                    else $class .= ' cell-mismatch';
                    if ($cell && !empty($cell['item_changed']))  $class .= ' cell-item-change';
                    if ($cell && !empty($cell['price_changed'])) $class .= ' cell-price-change';
                    $tip = 'no data / tied';
                    if ($cell) {
                        $tip = $cell['item_name'].' · '.$cell['orders'].' orders'
                             . ($cell['mode_cod'] ? ' · ₱'.number_format($cell['mode_cod'],2) : '');
                        if (!empty($cell['item_changed']))  $tip .= ' · NEW ITEM vs previous day';
                        if (!empty($cell['price_changed'])) $tip .= ' · price Δ '.($cell['price_delta']>=0?'+':'').round($cell['price_delta']);
                    }
                  @endphp
                  <td class="{{ $class }}" title="{{ $tip }}">
                    @if($cell)
                      @if(!empty($cell['item_changed']))
                        <span class="badge-new">NEW</span>
                      @endif
                      {{ $cell['item_name'] }}
                      <div style="font-size:9px;color:#64748b;font-weight:400;">
                        {{ $cell['orders'] }}{{ $cell['mode_cod'] ? ' @ '.number_format($cell['mode_cod'],0) : '' }}
                        @if(!empty($cell['price_changed']) && $cell['price_delta'] !== null)
                          <span class="badge-price {{ $cell['price_delta'] >= 0 ? 'badge-price-up' : 'badge-price-down' }}">{{ $cell['price_delta']>=0 ? '▲ +' : '▼ ' }}{{ round($cell['price_delta']) }}</span>
                        @endif
                      </div>
                    @else
                      —
                    @endif
                  </td>
                @endforeach
              </tr>
            @endforeach
          </tbody>
        </table>
      @endif
    </div>
  </div>

</body>
</html>

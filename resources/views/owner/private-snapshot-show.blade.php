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
    }
    thead th:first-child { border-radius:10px 0 0 0; }
    thead th:last-child  { border-radius:0 10px 0 0; }

    tbody td {
      font-size:12.5px; color:#374151;
      padding:7px 10px; white-space:nowrap;
      vertical-align:middle;
      border-bottom:1px solid #f1f5f9;
    }
    tbody tr:hover td { background:#f8fafc; }

    tr.total-row td {
      position:sticky; bottom:0; z-index:20;
      font-weight:700; color:#0f172a;
      background:#f1f5f9; border-top:2px solid #cbd5e1;
    }

    .null-cell { background:#fef2f2; }
    .muted { color:#94a3b8; }
    .small { font-size:9px; color:#94a3b8; margin-top:2px; }
    .comment { font-size:9px; color:#64748b; margin-top:1px; font-style:italic; white-space:normal; max-width:120px; }

    .anchor-tag { font-family:ui-monospace,monospace; font-size:10.5px; color:#7c3aed; }

    .table-foot { padding:7px 12px; font-size:10px; color:#94a3b8; border-top:1px solid #f1f5f9; }
  </style>
</head>
<body>

@php
  use Carbon\Carbon;

  // ── Formatters (match live /owner/private behavior) ──────────────────
  // money() — peso w/ 2 decimals, ',' thousands
  $money = function($v) {
    if ($v === null || $v === '') return '—';
    return '₱' . number_format((float)$v, 2);
  };
  // md() — same as money() sa live (peso, 2 decimals). Live uses 2 decimals
  // for adspent/cpp/profit etc.
  $md = $money;
  $num   = fn($v) => $v === null || $v === '' ? '—' : number_format((float)$v);

  // ── pbStyle / pbColor (proj.profit cell background + text color) ────
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

  // pbStyleN — same as pbStyle but for N-day variants (1d/3d/7d).
  $pbStyleN = $pbStyle;

  // rppStyle — proj.% background tint (positive green, negative red, bands).
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
  $breakevenTargetPct = (float) ($payload['breakeven_target_pct'] ?? 5);
  $fees = $payload['fees'] ?? null;
  $feeSF = is_array($fees) && isset($fees['SF']) && is_numeric($fees['SF']) ? (float)$fees['SF'] : 37.0;
  $feeF  = is_array($fees) && isset($fees['F'])  && is_numeric($fees['F'])  ? (float)$fees['F']  : 0.0168;

  // ── Cols — gamitin yung saved cols (kapag walang saved, fallback sa default) ─
  $defaultCols = [
    ['id'=>'adspent',    'label'=>'Adspent',    'align'=>'center', 'minw'=>90],
    ['id'=>'orders',     'label'=>'Orders',     'align'=>'center', 'minw'=>65],
    ['id'=>'orders_1d',  'label'=>'Orders (1D)','align'=>'center', 'minw'=>80],
    ['id'=>'cpp',        'label'=>'CPP',        'align'=>'center', 'minw'=>75],
    ['id'=>'proceed',    'label'=>'Proceed',    'align'=>'center', 'minw'=>70],
    ['id'=>'pcpp',       'label'=>'P.CPP',      'align'=>'center', 'minw'=>75],
    ['id'=>'tcpr',       'label'=>'TCPR',       'align'=>'center', 'minw'=>65],
    ['id'=>'breakeven_cpp','label'=>'Breakeven CPP ('.number_format($breakevenTargetPct,0).'%)','align'=>'center','minw'=>115],
    ['id'=>'proj_profit','label'=>'Prof.Profit','align'=>'center', 'minw'=>95],
    ['id'=>'per_order',  'label'=>'/Order',     'align'=>'center', 'minw'=>75],
    ['id'=>'np_per_order','label'=>'NP/O',      'align'=>'center', 'minw'=>75],
    ['id'=>'proj_pct',     'label'=>'Prof.%(1M)','align'=>'center','minw'=>75],
    ['id'=>'proj_pct_1d',  'label'=>'Prof.%(1D)','align'=>'center','minw'=>75],
    ['id'=>'proj_pct_3d',  'label'=>'Prof.%(3D)','align'=>'center','minw'=>75],
    ['id'=>'proj_pct_7d',  'label'=>'Prof.%(7D)','align'=>'center','minw'=>75],
    ['id'=>'proj_prof_1d','label'=>'Prof.Profit(1D)','align'=>'center','minw'=>105],
    ['id'=>'proj_prof_3d','label'=>'Prof.Profit(3D)','align'=>'center','minw'=>105],
    ['id'=>'proj_prof_7d','label'=>'Prof.Profit(7D)','align'=>'center','minw'=>105],
    ['id'=>'jnt_rts',     'label'=>'RTS%',     'align'=>'center', 'minw'=>100],
    ['id'=>'jnt_del',     'label'=>'Del%',     'align'=>'center', 'minw'=>90],
    ['id'=>'jnt_transit', 'label'=>'Transit%', 'align'=>'center', 'minw'=>85],
    ['id'=>'rts_set',     'label'=>'Set RTS%', 'align'=>'center', 'minw'=>110],
    ['id'=>'promo',       'label'=>'Promo',    'align'=>'center', 'minw'=>90],
    ['id'=>'price',       'label'=>'Price',    'align'=>'center', 'minw'=>85],
    ['id'=>'item_val',    'label'=>'Item Val.','align'=>'center', 'minw'=>80],
    ['id'=>'item_val_ceo','label'=>'Item Val. (CEO)','align'=>'center','minw'=>90],
    ['id'=>'ship',        'label'=>'Ship',     'align'=>'center', 'minw'=>58],
    ['id'=>'cod_fee',     'label'=>'COD Fee',  'align'=>'center', 'minw'=>72],
  ];
  $cols = (!empty($payload['cols']) && is_array($payload['cols']))
        ? $payload['cols']
        : $defaultCols;

  // ── Helper functions for derived values ──────────────────────────────
  $tcprFor = function($r) {
    $o = (int)($r['orders'] ?? 0);
    if ($o <= 0) return null;
    return (1 - (($r['proceed_orders'] ?? 0) / $o)) * 100;
  };
  $projPctFor = function($r) {
    if (($r['projected_profit'] ?? null) === null) return null;
    $gs = (float)($r['gross_sales'] ?? 0);
    if ($gs <= 0) return null;
    return $r['projected_profit'] / $gs * 100;
  };
  $npoFor = function($r) {
    $ppld = $r['projected_profit_last_day'] ?? null;
    $old  = (int)($r['orders_last_day'] ?? 0);
    if ($ppld === null || $old <= 0) return null;
    return $ppld / $old;
  };
  // Breakeven CPP — mirrors frontend formula sa breakevenCppFor():
  //   procRate × [df × (price × (1 − F) − iv) − SF] − target × price
  $breakevenCppFor = function($r) use ($breakevenTargetPct, $feeSF, $feeF) {
    $o     = (int)($r['orders'] ?? 0);
    $p     = (int)($r['proceed_orders'] ?? 0);
    $price = (float)($r['price'] ?? 0);
    $iv    = $r['item_value'] ?? null;
    $rts   = $r['rts_pct'] ?? null;
    if ($o <= 0 || $price <= 0 || $iv === null || $rts === null) return null;
    $procRate = $p / $o;
    $df       = 1 - ((float)$rts / 100);
    $target   = $breakevenTargetPct / 100;
    return $procRate * ($df * ($price * (1 - $feeF) - (float)$iv) - $feeSF) - $target * $price;
  };

  // ── Renderer per column ──────────────────────────────────────────────
  $renderCell = function(array $col, array $r) use (
    $money, $md, $num, $pbStyle, $pbColor, $pbStyleN, $rppStyle,
    $tcprFor, $projPctFor, $npoFor, $breakevenCppFor
  ) {
    $id = $col['id'];
    $align = $col['align'] ?? 'center';

    // Base style + color tints
    $tdStyle = "text-align:{$align};";
    $extraBg = '';
    if ($id === 'rts_set' && ($r['rts_pct'] ?? null) === null) $extraBg = 'background:#fef2f2;';
    if ($id === 'item_val' && ($r['item_value'] ?? null) === null) $extraBg = 'background:#fef2f2;';
    if ($id === 'proj_profit')  $extraBg = $pbStyle($r['projected_profit'] ?? null);
    if ($id === 'proj_pct')     $extraBg = $rppStyle($projPctFor($r));
    if ($id === 'proj_pct_1d')  $extraBg = $rppStyle($r['proj_pct_last_day'] ?? null);
    if ($id === 'proj_pct_3d')  $extraBg = $rppStyle($r['proj_pct_last_3d'] ?? null);
    if ($id === 'proj_pct_7d')  $extraBg = $rppStyle($r['proj_pct_last_7d'] ?? null);
    if ($id === 'proj_prof_1d') $extraBg = $pbStyleN($r['projected_profit_last_day'] ?? null);
    if ($id === 'proj_prof_3d') $extraBg = $pbStyleN($r['projected_profit_last_3d'] ?? null);
    if ($id === 'proj_prof_7d') $extraBg = $pbStyleN($r['projected_profit_last_7d'] ?? null);
    $tdStyle .= $extraBg;

    // Numeric center for known num cols (font-variant tabular)
    $isNumeric = !in_array($id, ['page_name','item_name'], true);
    if ($isNumeric) $tdStyle .= 'font-variant-numeric:tabular-nums;';

    $html = '';
    switch ($id) {
      case 'adspent':
        $html = '<span style="color:#111;font-weight:500;">'.$money($r['adspent'] ?? null).'</span>';
        break;
      case 'orders':
        $html = '<span style="color:#111;">'.$num($r['orders'] ?? null).'</span>';
        break;
      case 'orders_1d':
        $html = '<span style="color:#111;">'.$num($r['orders_last_day'] ?? null).'</span>';
        break;
      case 'cpp':
        $html = '<span style="color:#111;">'.$md($r['cpp'] ?? null).'</span>';
        break;
      case 'proceed':
        $html = '<span style="color:#111;font-weight:600;">'.$num($r['proceed_orders'] ?? null).'</span>';
        break;
      case 'pcpp':
        $html = '<span style="color:#111;">'.$md($r['proceed_cpp'] ?? null).'</span>';
        break;
      case 'tcpr':
        $t = $tcprFor($r);
        $html = $t === null ? '—' : number_format($t, 1).'%';
        break;
      case 'breakeven_cpp':
        $be = $breakevenCppFor($r);
        $html = $be === null ? '<span class="muted">—</span>' : $md($be);
        break;
      case 'proj_profit':
        $v = $r['projected_profit'] ?? null;
        $html = '<span style="font-weight:700;color:'.$pbColor($v).';">'.$md($v).'</span>';
        break;
      case 'per_order':
        $html = '<span style="color:#111;">'.$md($r['proj_profit_per_order'] ?? null).'</span>';
        break;
      case 'np_per_order':
        $npo = $npoFor($r);
        if ($npo !== null) {
          $html = '<span style="font-weight:600;color:'.$pbColor($npo).';">'.$md($npo).'</span>';
        } else {
          $html = '<span style="color:#cbd5e1;">—</span>';
        }
        break;
      case 'proj_pct':
        $pp = $projPctFor($r);
        $html = $pp === null
          ? '<span style="color:#cbd5e1;">—</span>'
          : '<span style="font-weight:700;">'.number_format($pp, 1).'%</span>';
        break;
      case 'proj_pct_1d':
        $v = $r['proj_pct_last_day'] ?? null;
        $html = $v === null
          ? '<span style="color:#cbd5e1;">—</span>'
          : '<span style="font-weight:700;">'.number_format((float)$v, 1).'%</span>';
        break;
      case 'proj_pct_3d':
        $v = $r['proj_pct_last_3d'] ?? null;
        $html = $v === null
          ? '<span style="color:#cbd5e1;">—</span>'
          : '<span style="font-weight:700;">'.number_format((float)$v, 1).'%</span>';
        break;
      case 'proj_pct_7d':
        $v = $r['proj_pct_last_7d'] ?? null;
        $html = $v === null
          ? '<span style="color:#cbd5e1;">—</span>'
          : '<span style="font-weight:700;">'.number_format((float)$v, 1).'%</span>';
        break;
      case 'proj_prof_1d':
        $v = $r['projected_profit_last_day'] ?? null;
        $html = '<span style="font-weight:700;color:'.$pbColor($v).';">'.$md($v).'</span>';
        break;
      case 'proj_prof_3d':
        $v = $r['projected_profit_last_3d'] ?? null;
        $html = '<span style="font-weight:700;color:'.$pbColor($v).';">'.$md($v).'</span>';
        break;
      case 'proj_prof_7d':
        $v = $r['projected_profit_last_7d'] ?? null;
        $html = '<span style="font-weight:700;color:'.$pbColor($v).';">'.$md($v).'</span>';
        break;
      case 'jnt_rts':
        $v = $r['jnt_rts_pct'] ?? null;
        $html = $v === null
          ? '<span style="color:#cbd5e1;font-size:11px;">—</span>'
          : '<span style="color:#111;font-weight:700;font-size:12px;">'.number_format((float)$v,1).'%('.($r['jnt_rts_cnt'] ?? 0).')</span>';
        break;
      case 'jnt_del':
        $v = $r['jnt_del_pct'] ?? null;
        $html = $v === null
          ? '<span style="color:#cbd5e1;font-size:11px;">—</span>'
          : '<span style="color:#111;font-size:12px;">'.number_format((float)$v,1).'%('.($r['jnt_del_cnt'] ?? 0).')</span>';
        break;
      case 'jnt_transit':
        $v = $r['jnt_transit_pct'] ?? null;
        $html = $v === null
          ? '<span style="color:#cbd5e1;font-size:11px;">—</span>'
          : '<span style="color:#111;font-size:12px;">'.number_format((float)$v,1).'%('.($r['jnt_transit_cnt'] ?? 0).')</span>';
        break;
      case 'rts_set':
        $v = $r['rts_pct'] ?? null;
        if ($v === null) {
          $html = '<span style="color:#fca5a5;font-style:italic;font-size:11px;">—</span>';
        } else {
          $h  = '<div><span style="font-weight:700;color:#000;">'.number_format((float)$v,1).'%</span>';
          if (!empty($r['settings_date'])) $h .= '<div class="small">from '.htmlspecialchars($r['settings_date'], ENT_QUOTES).'</div>';
          if (!empty($r['rts_comment']))   $h .= '<div class="comment">💬 '.htmlspecialchars($r['rts_comment'], ENT_QUOTES).'</div>';
          $h .= '</div>';
          $html = $h;
        }
        break;
      case 'promo':
        $p = $r['promo'] ?? null;
        if (!$p || strtoupper($p) === 'NONE' || $p === '-') $html = '<span class="muted">—</span>';
        else $html = htmlspecialchars($p, ENT_QUOTES);
        break;
      case 'price':
        $v = $r['price'] ?? null;
        if ($v === null) { $html = '<span class="muted">—</span>'; break; }
        $h = '<div><span style="color:#374151;">'.$money($v).'</span>';
        if (($r['price_min'] ?? null) !== null) $h .= '<div class="small">↓ '.$money($r['price_min']).'</div>';
        if (($r['price_max'] ?? null) !== null) $h .= '<div class="small">↑ '.$money($r['price_max']).'</div>';
        $h .= '</div>';
        $html = $h;
        break;
      case 'item_val':
        $v = $r['item_value'] ?? null;
        if ($v === null) {
          $html = '<span style="color:#fca5a5;font-style:italic;font-size:11px;">—</span>';
        } else {
          $h = '<div><span style="color:#111;">'.$money($v).'</span>';
          $src = $r['item_value_source'] ?? null;
          if ($src === 'cogs') {
            $h .= '<div class="small" style="color:#cbd5e1;">cogs</div>';
          } elseif ($src === 'manual' && !empty($r['settings_date'])) {
            $h .= '<div class="small">from '.htmlspecialchars($r['settings_date'], ENT_QUOTES).'</div>';
          }
          if (!empty($r['item_value_comment']) && $src === 'manual') {
            $h .= '<div class="comment">💬 '.htmlspecialchars($r['item_value_comment'], ENT_QUOTES).'</div>';
          }
          $h .= '</div>';
          $html = $h;
        }
        break;
      case 'item_val_ceo':
        $v = $r['item_value_ceo'] ?? null;
        $html = $v === null
          ? '<span style="color:#fca5a5;font-style:italic;font-size:11px;">—</span>'
          : '<span style="color:#111;">'.$money($v).'</span>';
        break;
      case 'ship':
        $v = $r['shipping_fee'] ?? null;
        $html = $v === null ? '—' : '<span style="color:#111;">'.$money($v).'</span>';
        break;
      case 'cod_fee':
        $v = $r['cod_fee'] ?? null;
        $html = $v === null ? '—' : '<span style="color:#111;">'.$money($v).'</span>';
        break;
      default:
        $html = '';
    }
    return '<td style="'.$tdStyle.'">'.$html.'</td>';
  };

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

  // Total cell renderer per col (matches live's total row logic)
  $renderTotalCell = function(array $col) use (
    $money, $md, $num, $tot, $totCpp, $totPcpp, $totTcpr,
    $totProjPct, $totProjPct1d, $totProjPct3d, $totProjPct7d,
    $totPerOrder, $totNpPerOrder, $pbStyle, $pbColor, $rppStyle
  ) {
    $id = $col['id']; $align = $col['align'] ?? 'center';
    $tdStyle = "text-align:{$align};font-variant-numeric:tabular-nums;";
    $extraBg = '';
    if ($id === 'proj_profit')  $extraBg = $pbStyle($tot['projected_profit']);
    if ($id === 'proj_pct')     $extraBg = $rppStyle($totProjPct);
    if ($id === 'proj_pct_1d')  $extraBg = $rppStyle($totProjPct1d);
    if ($id === 'proj_pct_3d')  $extraBg = $rppStyle($totProjPct3d);
    if ($id === 'proj_pct_7d')  $extraBg = $rppStyle($totProjPct7d);
    if ($id === 'proj_prof_1d') $extraBg = $pbStyle($tot['projected_profit_last_day']);
    if ($id === 'proj_prof_3d') $extraBg = $pbStyle($tot['projected_profit_last_3d']);
    if ($id === 'proj_prof_7d') $extraBg = $pbStyle($tot['projected_profit_last_7d']);
    $tdStyle .= $extraBg;
    $h = '';
    switch ($id) {
      case 'adspent':       $h = $money($tot['adspent']); break;
      case 'orders':        $h = $num($tot['orders']); break;
      case 'orders_1d':     $h = $num($tot['orders_last_day']); break;
      case 'cpp':           $h = $totCpp !== null ? '<span style="color:#475569;">'.$md($totCpp).'</span>' : '—'; break;
      case 'proceed':       $h = $num($tot['proceed_orders']); break;
      case 'pcpp':          $h = $totPcpp !== null ? '<span style="color:#475569;">'.$md($totPcpp).'</span>' : '—'; break;
      case 'tcpr':          $h = $totTcpr !== null ? number_format($totTcpr, 1).'%' : '—'; break;
      case 'breakeven_cpp': $h = '<span style="color:#cbd5e1;">—</span>'; break;
      case 'proj_profit':   $h = '<span style="font-weight:700;">'.$md($tot['projected_profit']).'</span>'; break;
      case 'per_order':     $h = $totPerOrder !== null ? '<span style="color:#111;">'.$md($totPerOrder).'</span>' : '—'; break;
      case 'np_per_order':  $h = $totNpPerOrder !== null ? '<span style="color:#111;font-weight:700;">'.$md($totNpPerOrder).'</span>' : '—'; break;
      case 'proj_pct':      $h = $totProjPct !== null ? '<span style="font-weight:700;color:#111;">'.number_format($totProjPct, 1).'%</span>' : '—'; break;
      case 'proj_pct_1d':   $h = $totProjPct1d !== null ? '<span style="font-weight:700;color:#111;">'.number_format($totProjPct1d, 1).'%</span>' : '—'; break;
      case 'proj_pct_3d':   $h = $totProjPct3d !== null ? '<span style="font-weight:700;color:#111;">'.number_format($totProjPct3d, 1).'%</span>' : '—'; break;
      case 'proj_pct_7d':   $h = $totProjPct7d !== null ? '<span style="font-weight:700;color:#111;">'.number_format($totProjPct7d, 1).'%</span>' : '—'; break;
      case 'proj_prof_1d':  $h = '<span style="font-weight:700;">'.$md($tot['projected_profit_last_day']).'</span>'; break;
      case 'proj_prof_3d':  $h = '<span style="font-weight:700;">'.$md($tot['projected_profit_last_3d']).'</span>'; break;
      case 'proj_prof_7d':  $h = '<span style="font-weight:700;">'.$md($tot['projected_profit_last_7d']).'</span>'; break;
      default:              $h = '';
    }
    return '<td style="'.$tdStyle.'">'.$h.'</td>';
  };
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
          <th style="text-align:left;min-width:110px;">Page</th>
          <th style="text-align:left;min-width:160px;">Item</th>
          @foreach($cols as $col)
            <th style="text-align:{{ $col['align'] ?? 'center' }};min-width:{{ $col['minw'] ?? 80 }}px;">
              {{ $col['label'] ?? $col['id'] }}
            </th>
          @endforeach
        </tr>
      </thead>

      <tbody>
        @forelse($rows as $r)
          <tr>
            <td style="font-weight:600;color:#0f172a;">{{ $r['page_name'] ?? '—' }}</td>
            <td>{{ $r['item_name'] ?? '—' }}</td>
            @foreach($cols as $col)
              {!! $renderCell($col, $r) !!}
            @endforeach
          </tr>
        @empty
          <tr>
            <td colspan="{{ count($cols) + 2 }}" style="text-align:center;padding:48px;color:#94a3b8;font-size:13px;">
              Empty snapshot — walang rows na na-save.
            </td>
          </tr>
        @endforelse

        @if($hasAnyVal && count($rows) > 0)
          <tr class="total-row">
            <td>TOTAL</td>
            <td></td>
            @foreach($cols as $col)
              {!! $renderTotalCell($col) !!}
            @endforeach
          </tr>
        @endif
      </tbody>
    </table>

    <div class="table-foot">
      📸 Frozen capture — values reflect what /owner/private displayed at
      <strong>{{ Carbon::parse($snapshot->snapshot_at)->format('Y-m-d H:i:s') }}</strong>.
      Underlying data (orders, RTS settings, COGS, JNT updates) may have changed since.
      One row per page · Same column arrangement + color coding ng live /owner/private at save-time.
    </div>
  </div>
</div>

</body>
</html>

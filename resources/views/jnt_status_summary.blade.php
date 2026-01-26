<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>JNT Status Summary</title>
  <style>
    :root{
      --border:#cfcfcf;
      --head:#f5f5f5;
      --link:#0b57d0;
    }
    body{ font-family: Arial, sans-serif; margin:0; background:#fff; }

    .topbar{
      position: sticky;
      top: 0;
      z-index: 1000;
      background: #fff;
      border-bottom: 1px solid #eaeaea;
      padding: 10px 12px;
      display:flex;
      align-items:center;
      justify-content: space-between;
      gap: 12px;
      flex-wrap: wrap;
    }
    .topbar h2{ margin:0; font-size:18px; }
    .topbar form{
      display:flex; gap:10px; align-items:center; flex-wrap:wrap;
      margin:0;
    }
    .muted{ color:#666; font-size:12px; }

    .wrap{ padding: 10px 12px 16px 12px; }
    .grid{
      display:grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
      align-items: start;
    }
    @media(max-width: 1100px){
      .grid{ grid-template-columns: 1fr; }
    }

    .card{
      border:1px solid #e5e5e5;
      border-radius: 8px;
      overflow:hidden;
      background:#fff;
    }
    .card .title{
      padding: 8px 10px;
      background:#fafafa;
      border-bottom:1px solid #eee;
      font-weight:700;
      font-size:13px;
      letter-spacing:.2px;
      text-transform: uppercase;
    }

    table{ border-collapse: collapse; width:100%; table-layout: fixed; }
    th, td{
      border:1px solid var(--border);
      padding: 5px 7px;
      font-size: 12px;
      vertical-align: top;
      background:#fff;
      word-wrap: break-word;
    }
    th{ background: var(--head); font-weight:700; }
    .text-right{ text-align:right; }
    .text-bold{ font-weight:700; }
    .nowrap{ white-space:nowrap; }

    a.link{
      color: var(--link);
      text-decoration: underline;
      cursor: pointer;
    }

    .rts-na   { background:#f3f4f6; }
    .rts-ok   { background:#ecfdf5; }
    .rts-mid  { background:#fef9c3; }
    .rts-warn { background:#ffedd5; }
    .rts-bad  { background:#fee2e2; }
    .rts-cell { font-weight: 800; }

    #detailsModal{
      display:none;
      position: fixed;
      inset:0;
      background: rgba(0,0,0,.35);
      z-index: 9999;
    }
    #detailsModal .box{
      background:#fff;
      width: 94%;
      max-width: 1200px;
      margin: 36px auto;
      border-radius: 10px;
      overflow:hidden;
      box-shadow: 0 12px 30px rgba(0,0,0,.25);
    }
    #detailsModal .head{
      padding: 10px 12px;
      border-bottom: 1px solid #eee;
      display:flex;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
    }
    #detailsModal .body{
      padding: 12px;
      max-height: 78vh;
      overflow:auto;
    }
    #detailsClose{ padding: 6px 10px; cursor:pointer; }

    .kpi{
      margin-top: 8px;
      border:1px solid #e5e5e5;
      border-radius: 8px;
      overflow:hidden;
      background:#fff;
    }
    .kpi .khead{
      padding: 8px 10px;
      background:#fafafa;
      border-bottom:1px solid #eee;
      font-weight:800;
      font-size:13px;
      text-transform: uppercase;
    }
    .kpi table th, .kpi table td{ font-size:12px; }
    .kpi-big{
      font-size: 18px;
      font-weight: 900;
      letter-spacing: .2px;
    }
  </style>
</head>

<body>
  <div class="topbar">
    <h2>JNT Status Summary</h2>

    <form method="GET" action="{{ route('jnt.status-summary') }}">
      <label for="date" class="text-bold">Date:</label>
      <input type="date" name="date" id="date" value="{{ $date }}" required>

      <label for="rts_group" class="text-bold">RTS Group:</label>
      <select name="rts_group" id="rts_group">
        <option value="sender_item" {{ ($rtsGroup ?? 'sender_item') === 'sender_item' ? 'selected' : '' }}>Shop Name + Item Name</option>
        <option value="sender"      {{ ($rtsGroup ?? '') === 'sender' ? 'selected' : '' }}>Shop Name only</option>
        <option value="item"        {{ ($rtsGroup ?? '') === 'item' ? 'selected' : '' }}>Item Name only</option>
      </select>

      <button type="submit">Show</button>
    </form>


  </div>

  <div class="wrap">
    <div class="grid">

      {{-- LEFT COLUMN (Status Summary + KPI under it) --}}
      <div>
        <div class="card">
          <div class="title">Status Summary</div>

          <table>
            <thead>
              <tr>
                <th style="width:42%;">Date &amp; Time (upload)</th>
                <th style="width:14%;" class="text-right">Delivering</th>
                <th style="width:14%;" class="text-right">In Transit</th>
                <th style="width:15%;" class="text-right">Delivered</th>
                <th style="width:15%;" class="text-right">For Return</th>
              </tr>
            </thead>

            <tbody>
              @forelse($batches as $batch)
                <tr>
                  <td class="nowrap">{{ $batch['batch_at'] }}</td>

                  <td class="text-right">
                    <a href="#"
                       class="link js-batch-details"
                       data-date="{{ $date }}"
                       data-batch="{{ $batch['batch_at'] }}"
                       data-metric="delivering">
                      {{ $batch['delivering'] }}
                    </a>
                  </td>

                  <td class="text-right">
                    <a href="#"
                       class="link js-batch-details"
                       data-date="{{ $date }}"
                       data-batch="{{ $batch['batch_at'] }}"
                       data-metric="in_transit">
                      {{ $batch['in_transit'] }}
                    </a>
                  </td>

                  <td class="text-right">
                    <a href="#"
                       class="link js-batch-details"
                       data-date="{{ $date }}"
                       data-batch="{{ $batch['batch_at'] }}"
                       data-metric="delivered"
                       data-range-start="{{ $batch['range_start'] ?? '' }}"
                       data-range-end="{{ $batch['range_end'] ?? '' }}">
                      {{ $batch['delivered'] }}
                    </a>
                  </td>

                  <td class="text-right">
                    <a href="#"
                       class="link js-batch-details"
                       data-date="{{ $date }}"
                       data-batch="{{ $batch['batch_at'] }}"
                       data-metric="for_return">
                      {{ $batch['for_return'] }}
                    </a>
                  </td>
                </tr>
              @empty
                <tr><td colspan="5">No data for {{ $date }}.</td></tr>
              @endforelse

              @if(!empty($batches))
                <tr>
                  <td class="text-bold">TOTAL</td>
                  <td class="text-right text-bold">{{ $totals['delivering'] ?? 0 }}</td>
                  <td class="text-right text-bold">{{ $totals['in_transit'] ?? 0 }}</td>
                  <td class="text-right text-bold">{{ $totals['delivered'] ?? 0 }}</td>
                  <td class="text-right text-bold">{{ $totals['for_return'] ?? 0 }}</td>
                </tr>
              @endif
            </tbody>
          </table>
        </div>

        {{-- ✅ NEW: In Transit Breakdown (Day Total) --}}
        @php
          $bd = $inTransitBreakdown ?? ['luzon'=>0,'visayas'=>0,'mindanao'=>0,'total'=>0];
        @endphp

        <div class="kpi" style="margin-top:8px;">
          <div class="khead">In Transit Breakdown (Day Total)</div>
          <table>
            <thead>
              <tr>
                <th>Intransit</th>
                <th class="text-right" style="width:140px;">Count</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td class="text-bold">Luzon</td>
                <td class="text-right text-bold">{{ (int)($bd['luzon'] ?? 0) }}</td>
              </tr>
              <tr>
                <td class="text-bold">Visayas</td>
                <td class="text-right text-bold">{{ (int)($bd['visayas'] ?? 0) }}</td>
              </tr>
              <tr>
                <td class="text-bold">Mindanao</td>
                <td class="text-right text-bold">{{ (int)($bd['mindanao'] ?? 0) }}</td>
              </tr>
              <tr>
                <td class="text-bold">Total</td>
                <td class="text-right text-bold">{{ (int)($bd['total'] ?? 0) }}</td>
              </tr>
            </tbody>
          </table>

        </div>

        {{-- ✅ KPI directly under Status Summary (left side only) --}}
        @php
          $tDel = (int)($totals['delivered'] ?? 0);
          $tFR  = (int)($totals['for_return'] ?? 0);
          $den  = $tDel + $tFR;
          $kpiRate = $den > 0 ? (($tFR / $den) * 100) : 0;

          if ($den <= 0)          $kpiClass = 'rts-na';
          elseif ($kpiRate >= 50) $kpiClass = 'rts-bad';
          elseif ($kpiRate >= 25) $kpiClass = 'rts-warn';
          elseif ($kpiRate >= 10) $kpiClass = 'rts-mid';
          else                    $kpiClass = 'rts-ok';
        @endphp

        <div class="kpi">
          <div class="khead">RTS KPI (Most Important)</div>
          <table>
            <thead>
              <tr>
                <th style="width:40%;">Metric</th>
                <th class="text-right" style="width:20%;">Delivered</th>
                <th class="text-right" style="width:20%;">For Return</th>
                <th class="text-right" style="width:20%;">RTS Rate</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td class="text-bold">For Return / (For Return + Delivered)</td>
                <td class="text-right text-bold">{{ $tDel }}</td>
                <td class="text-right text-bold">{{ $tFR }}</td>
                <td class="text-right rts-cell {{ $kpiClass }}">
                  <span class="kpi-big">{{ number_format($kpiRate, 2) }}%</span>
                </td>
              </tr>
            </tbody>
          </table>
         
        </div>
      </div>

      {{-- RIGHT COLUMN (RTS SUMMARY only) --}}
      <div class="card">
        @php
          $titleMap = [
            'sender_item' => 'Shop Name + Item Name',
            'sender'      => 'Shop Name',
            'item'        => 'Item Name',
          ];
          $mode = $rtsGroup ?? 'sender_item';

          $rtsRowsSafe = is_array($rtsRows ?? null) ? $rtsRows : (is_iterable($rtsRows ?? null) ? collect($rtsRows)->toArray() : []);
          usort($rtsRowsSafe, function($a,$b){
            $av = (int)($a['volume'] ?? (($a['delivered'] ?? 0)+($a['for_return'] ?? 0)));
            $bv = (int)($b['volume'] ?? (($b['delivered'] ?? 0)+($b['for_return'] ?? 0)));
            return $bv <=> $av;
          });
        @endphp

        <div class="title">
          RTS Summary — {{ $titleMap[$mode] ?? 'Shop Name + Item Name' }}
        </div>

        <table>
          <thead>
            <tr>
              <th>Group</th>
              <th class="text-right" style="width:110px;">Delivered</th>
              <th class="text-right" style="width:110px;">For Return</th>
              <th class="text-right" style="width:110px;">RTS Rate</th>
            </tr>
          </thead>

          <tbody>
            @forelse($rtsRowsSafe as $r)
              @php
                $del = (int)($r['delivered'] ?? 0);
                $fr  = (int)($r['for_return'] ?? 0);
                $vol = (int)($r['volume'] ?? ($del + $fr));
                $rate = $vol > 0 ? (($fr / $vol) * 100) : 0;

                if ($vol <= 0)          $rateClass = 'rts-na';
                elseif ($rate >= 50)    $rateClass = 'rts-bad';
                elseif ($rate >= 25)    $rateClass = 'rts-warn';
                elseif ($rate >= 10)    $rateClass = 'rts-mid';
                else                    $rateClass = 'rts-ok';

                $label = ($mode === 'sender_item')
                  ? (trim(($r['sender'] ?? '')).' | '.trim(($r['item_name'] ?? '')))
                  : (string)($r['label'] ?? '');
              @endphp

              <tr>
                <td class="nowrap" title="{{ $label }}">{{ $label }}</td>

                <td class="text-right">
                  <a href="#"
                     class="link js-rts-details"
                     data-date="{{ $date }}"
                     data-rts-group="{{ $mode }}"
                     data-metric="delivered"
                     @if($mode === 'sender_item')
                       data-sender="{{ $r['sender'] ?? '' }}"
                       data-item-name="{{ $r['item_name'] ?? '' }}"
                     @else
                       data-grp="{{ $r['label'] ?? '' }}"
                     @endif
                  >{{ $del }}</a>
                </td>

                <td class="text-right">
                  <a href="#"
                     class="link js-rts-details"
                     data-date="{{ $date }}"
                     data-rts-group="{{ $mode }}"
                     data-metric="for_return"
                     @if($mode === 'sender_item')
                       data-sender="{{ $r['sender'] ?? '' }}"
                       data-item-name="{{ $r['item_name'] ?? '' }}"
                     @else
                       data-grp="{{ $r['label'] ?? '' }}"
                     @endif
                  >{{ $fr }}</a>
                </td>

                <td class="text-right rts-cell {{ $rateClass }}">
                  {{ number_format($rate, 2) }}%
                </td>
              </tr>
            @empty
              <tr><td colspan="4">No RTS data for {{ $date }}.</td></tr>
            @endforelse
          </tbody>
        </table>

        <div class="muted" style="padding:8px 10px;">
          sorted by volume (desc)
        </div>
      </div>

    </div>
  </div>

  {{-- MODAL --}}
  <div id="detailsModal">
    <div class="box">
      <div class="head">
        <strong id="detailsTitle">Details</strong>
        <button id="detailsClose" type="button">Close</button>
      </div>
      <div id="detailsBody" class="body">Loading...</div>
    </div>
  </div>

  <script>
    (function(){
      const modal = document.getElementById('detailsModal');
      const body  = document.getElementById('detailsBody');
      const title = document.getElementById('detailsTitle');
      const close = document.getElementById('detailsClose');

      function openModal(){ modal.style.display = 'block'; }
      function closeModal(){
        modal.style.display = 'none';
        body.innerHTML = '';
      }
      close.addEventListener('click', closeModal);
      modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });

      // TOP TABLE: batch details (existing)
      document.addEventListener('click', async (e) => {
        const a = e.target.closest('.js-batch-details');
        if (!a) return;
        e.preventDefault();

        const date    = a.dataset.date;
        const batchAt = a.dataset.batch;
        const metric  = a.dataset.metric;

        const params = new URLSearchParams({
          date: date,
          batch_at: batchAt,
          metric: metric
        });

        if (metric === 'delivered') {
          params.set('range_start', a.dataset.rangeStart || '');
          params.set('range_end',   a.dataset.rangeEnd || '');
        }

        title.textContent = `${metric.toUpperCase()} • ${batchAt}`;
        body.innerHTML = 'Loading...';
        openModal();

        try {
          const url = `{{ route('jnt.status-summary.details') }}?` + params.toString();
          const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }});
          body.innerHTML = await res.text();
        } catch (err) {
          body.innerHTML = `<div style="color:red;">Failed to load details.</div>`;
        }
      });

      // RTS TABLE: click Delivered / For Return numbers
      document.addEventListener('click', async (e) => {
        const a = e.target.closest('.js-rts-details');
        if (!a) return;
        e.preventDefault();

        const date     = a.dataset.date;
        const rtsGroup  = a.dataset.rtsGroup;
        const metric    = a.dataset.metric;

        const params = new URLSearchParams({
          date: date,
          rts_group: rtsGroup,
          metric: metric
        });

        let label = '';
        if (rtsGroup === 'sender_item') {
          params.set('sender', a.dataset.sender || '');
          params.set('item_name', a.dataset.itemName || '');
          label = `${a.dataset.sender || ''} | ${a.dataset.itemName || ''}`;
        } else {
          params.set('grp', a.dataset.grp || '');
          label = `${a.dataset.grp || ''}`;
        }

        title.textContent = `${metric.toUpperCase()} • ${label}`;
        body.innerHTML = 'Loading...';
        openModal();

        try {
          const url = `{{ route('jnt.status-summary.rts-details') }}?` + params.toString();
          const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }});
          body.innerHTML = await res.text();
        } catch (err) {
          body.innerHTML = `<div style="color:red;">Failed to load details.</div>`;
        }
      });
    })();
  </script>
</body>
</html>

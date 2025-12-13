<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>JNT Status</title>

  <!-- Flatpickr (CDN) -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
  <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

  <style>
    :root { --topbar-h: 0px; }

    body { font-family: Arial, sans-serif; margin:0; padding:16px; background:#fff; }

    /* ✅ Freeze top (title/meta/filters/buttons) */
    .topbar{
      position: sticky;
      top: 0;
      z-index: 1000;
      background: #fff;
      padding: 16px 16px 12px 16px;
      margin: -16px -16px 12px -16px;
      border-bottom: 1px solid #eee;
    }

    .row { display:flex; gap:12px; flex-wrap:wrap; align-items:end; }
    label { font-size:12px; display:block; margin-bottom:6px; color:#666; }
    select, input { padding:10px; border:1px solid #ddd; border-radius:8px; min-width:240px; }

    .meta { font-size:12px; color:#666; margin-top:8px; }
    .badge { display:inline-block; padding:2px 8px; border-radius:999px; background:#f2f2f2; font-size:12px; }
    .nowrap { white-space: nowrap; }

    table { width:100%; border-collapse: collapse; }
    th, td { border-bottom:1px solid #eee; padding:10px; text-align:left; font-size:13px; vertical-align:top; }
    thead th{
      position: sticky;
      top: var(--topbar-h);
      z-index: 900;
      background: #fafafa;
      border-bottom: 1px solid #ddd;
    }

    .badge-status{ display:inline-block; padding:2px 8px; border-radius:999px; background:#f2f2f2; font-size:12px; }

    /* ✅ buttons */
    .btn{
      display:inline-flex; align-items:center; justify-content:center;
      height:36px; padding:0 12px;
      border:1px solid #ddd; border-radius:10px;
      background:#111; color:#fff; font-size:13px;
      cursor:pointer; user-select:none;
    }
    .btn:disabled{ opacity:.6; cursor:not-allowed; }
    .btn-secondary{
      background:#fff; color:#111;
    }

    /* ✅ small pagination (Prev/Next only) */
    .pager{
      display:flex; gap:8px; align-items:center; flex-wrap:wrap;
      margin-top:12px;
    }
    .pager a, .pager span{
      display:inline-flex; align-items:center; justify-content:center;
      min-width:36px; height:34px; padding:0 10px;
      border:1px solid #ddd; border-radius:10px;
      background:#fff; color:#111; text-decoration:none; font-size:13px;
    }
    .pager .disabled{ opacity:.45; pointer-events:none; }

    /* toast */
    .toast{
      position: fixed;
      right: 16px;
      bottom: 16px;
      background: #111;
      color: #fff;
      padding: 10px 12px;
      border-radius: 10px;
      font-size: 13px;
      opacity: 0;
      transform: translateY(10px);
      transition: .18s ease;
      z-index: 2000;
    }
    .toast.show{
      opacity: 1;
      transform: translateY(0);
    }
  </style>
</head>
<body>

  <div class="topbar" id="topbar">
    <div class="row" style="justify-content:space-between; width:100%;">
      <h2 style="margin:0;">JNT Status</h2>

      <div class="row">
        <button type="button" class="btn btn-secondary" id="copyBtn">Copy Filtered Data</button>
      </div>
    </div>

    <div class="meta">
      Date range: <span class="badge">{{ $startAt->format('Y-m-d') }} → {{ $endAt->format('Y-m-d') }}</span>
      &nbsp; | &nbsp; Status: <span class="badge">{{ $status }}</span>
      &nbsp; | &nbsp; Total: <span class="badge">{{ $totalCount }}</span>
      @if($isPaginated)
        &nbsp; | &nbsp; Page: <span class="badge">{{ $currentPage }} / {{ $lastPage }}</span>
      @else
        &nbsp; | &nbsp; Mode: <span class="badge">All Rows Shown</span>
      @endif
    </div>

    <form id="filterForm" method="GET" action="{{ route('jnt.status') }}">
      <div class="row" style="margin-top:10px;">
        <div>
          <label>Status</label>
          <select name="status" id="statusSelect">
            @php
              $opts = ['All','Delivered','For Return','Returned','In Transit','Delivering','In Transit + Delivering'];
            @endphp
            @foreach($opts as $opt)
              <option value="{{ $opt }}" {{ $status === $opt ? 'selected' : '' }}>{{ $opt }}</option>
            @endforeach
          </select>
        </div>

        <div>
          <label>Date Range (submission_time)</label>
          <input type="text" name="date_range" id="dateRange"
                 value="{{ $dateRangeRaw }}"
                 placeholder="YYYY-MM-DD to YYYY-MM-DD"
                 autocomplete="off" />
        </div>
      </div>
    </form>
  </div>

  <table>
    <thead>
      <tr>
        <th class="nowrap">Submission Time</th>
        <th class="nowrap">Waybill</th>
        <th>Receiver</th>
        <th>Sender</th>
        <th class="nowrap">PAGE</th>
        <th class="nowrap">COD</th>
        <th class="nowrap">Status</th>
        <th class="nowrap">Signing Time</th>
        <th>Item</th>
        <th class="nowrap">Botcake PSID</th>
      </tr>
    </thead>
    <tbody>
      @forelse($rows as $r)
        <tr>
          <td class="nowrap">{{ $r->submission_time }}</td>
          <td class="nowrap">{{ $r->waybill_number }}</td>
          <td>{{ $r->receiver }}</td>
          <td>{{ $r->sender }}</td>
          <td class="nowrap">{{ $r->page }}</td>
          <td class="nowrap">{{ $r->cod }}</td>
          <td class="nowrap"><span class="badge-status">{{ $r->status }}</span></td>
          <td class="nowrap">{{ $r->signingtime }}</td>
          <td>{{ $r->item_name }}</td>
          <td class="nowrap">{{ $r->botcake_psid }}</td>
        </tr>
      @empty
        <tr>
          <td colspan="10" style="text-align:center; padding: 30px; color:#666;">
            No results for this filter/date range.
          </td>
        </tr>
      @endforelse
    </tbody>
  </table>

  {{-- ✅ Pagination only when All --}}
  @if($isPaginated)
    <div class="pager">
      @if($prevUrl)
        <a href="{{ $prevUrl }}">‹ Prev</a>
      @else
        <span class="disabled">‹ Prev</span>
      @endif

      <span>Page {{ $currentPage }} / {{ $lastPage }}</span>

      @if($nextUrl)
        <a href="{{ $nextUrl }}">Next ›</a>
      @else
        <span class="disabled">Next ›</span>
      @endif
    </div>
  @endif

  <div class="toast" id="toast">Copied!</div>

  <script>
    const form = document.getElementById('filterForm');

    document.getElementById('statusSelect').addEventListener('change', () => form.submit());

    flatpickr("#dateRange", {
      mode: "range",
      dateFormat: "Y-m-d",
      allowInput: true,
      onClose: function(selectedDates, dateStr) {
        if (dateStr && dateStr.includes(" to ")) form.submit();
      }
    });

    document.getElementById('dateRange').addEventListener('change', () => {
      const v = document.getElementById('dateRange').value.trim();
      if (v && v.includes(" to ")) form.submit();
    });

    // ✅ topbar height for sticky thead offset
    function applyTopbarHeight(){
      const topbar = document.getElementById('topbar');
      document.documentElement.style.setProperty('--topbar-h', (topbar?.offsetHeight || 0) + 'px');
    }
    window.addEventListener('load', applyTopbarHeight);
    window.addEventListener('resize', applyTopbarHeight);

    // ✅ Copy ALL filtered data (ignores pagination)
    const copyBtn = document.getElementById('copyBtn');
    const toast = document.getElementById('toast');

    function showToast(msg){
      toast.textContent = msg;
      toast.classList.add('show');
      setTimeout(() => toast.classList.remove('show'), 1300);
    }

    copyBtn.addEventListener('click', async () => {
      try {
        copyBtn.disabled = true;
        copyBtn.textContent = 'Copying...';

        const url = new URL(window.location.href);

        // remove pagination page param so copy always gets ALL
        url.searchParams.delete('page');

        // call same endpoint but with copy=1
        url.searchParams.set('copy', '1');

        const res = await fetch(url.toString(), { method: 'GET' });
        if (!res.ok) throw new Error('Fetch failed');

        const text = await res.text();

        await navigator.clipboard.writeText(text);
        showToast('Copied all filtered rows!');
      } catch (e) {
        showToast('Copy failed (check HTTPS/permissions)');
      } finally {
        copyBtn.disabled = false;
        copyBtn.textContent = 'Copy Filtered Data';
      }
    });
  </script>

</body>
</html>

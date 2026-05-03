<x-layout>
  <x-slot name="title">Conversation Tracker — Stats</x-slot>
  <x-slot name="heading">Conversation Tracker — Per-Flow Stats</x-slot>

  <style>
    .ct-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 1px 2px rgba(0,0,0,0.04); }
    .ct-card-header { display:flex; align-items:center; justify-content:space-between; padding:10px 14px; border-bottom:1px solid #f1f5f9; flex-wrap:wrap; gap:8px; }
    .ct-title { font-size:13px; font-weight:600; color:#0f172a; }
    .ct-input, .ct-select {
      padding:7px 10px; font-size:12.5px; color:#0f172a; background:#fff;
      border:1px solid #cbd5e1; border-radius:6px; min-width:0; width:100%;
    }
    .ct-input:focus, .ct-select:focus { outline:none; border-color:#6366f1; box-shadow:0 0 0 3px rgba(99,102,241,0.12); }
    .ct-label { display:block; font-size:11px; font-weight:600; color:#475569; margin-bottom:3px; }
    .ct-btn { display:inline-flex; align-items:center; gap:5px; background:#4f46e5; color:#fff; font-weight:600; font-size:12px; padding:7px 12px; border-radius:6px; }
    .ct-btn:hover { background:#4338ca; }
    .ct-btn-ghost { display:inline-flex; align-items:center; gap:5px; background:transparent; color:#64748b; font-size:12px; padding:5px 10px; border-radius:6px; }
    .ct-btn-ghost:hover { background:#f1f5f9; color:#0f172a; }

    .stats-table { width:100%; border-collapse:separate; border-spacing:0; font-size:13px; }
    .stats-table thead th { position:sticky; top:0; z-index:1; background:#f8fafc; color:#475569; font-size:10.5px; font-weight:600; text-transform:uppercase; letter-spacing:0.04em; padding:9px 12px; text-align:left; border-bottom:2px solid #e2e8f0; cursor:pointer; user-select:none; }
    .stats-table thead th:hover { background:#eef2ff; color:#3730a3; }
    .stats-table thead th.sorted::after { content:' ▾'; font-size:10px; color:#6366f1; }
    .stats-table thead th.sorted.asc::after { content:' ▴'; }
    .stats-table tbody td { padding:8px 12px; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
    .stats-table tbody tr:hover td { background:#f8fafc; }
    .stats-table .num { text-align:right; font-variant-numeric:tabular-nums; }
    .pill-flow { display:inline-block; background:#eef2ff; color:#4338ca; padding:3px 9px; border-radius:999px; font-size:11.5px; font-weight:600; }
    .pill-flow.loop { background:#fef3c7; color:#92400e; }
    .pill-flow.main { background:#dcfce7; color:#166534; }
    .pill-flow.seq  { background:#e0e7ff; color:#3730a3; }

    .summary-strip { display:flex; gap:12px; flex-wrap:wrap; padding:12px 14px; background:#f8fafc; border-bottom:1px solid #f1f5f9; }
    .summary-pill { background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:8px 14px; }
    .summary-pill-label { font-size:10px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.06em; }
    .summary-pill-value { font-size:18px; font-weight:700; color:#0f172a; font-variant-numeric:tabular-nums; }
  </style>

  <div class="w-full flex flex-col gap-4 p-2">
    <!-- Filter card -->
    <div class="ct-card">
      <div class="ct-card-header">
        <div class="ct-title">📊 Per-flow stats</div>
        <div class="flex gap-2 flex-wrap">
          <a href="/conversation/tracker" class="ct-btn-ghost">← Back to Import</a>
          <a href="/conversation/tracker/view" class="ct-btn-ghost">📋 View Records</a>
          <a href="/conversation/tracker/flows" class="ct-btn-ghost">🎯 Flows</a>
        </div>
      </div>

      <form method="GET" action="/conversation/tracker/stats" class="grid grid-cols-2 md:grid-cols-6 gap-3 p-3">
        <div>
          <label class="ct-label">Page</label>
          <select name="page_name" class="ct-select">
            <option value="">All pages</option>
            @foreach ($pages as $p)
              <option value="{{ $p }}" @selected($pageName === $p)>{{ $p }}</option>
            @endforeach
          </select>
        </div>
        <div>
          <label class="ct-label">From date</label>
          <input type="date" name="from_date" value="{{ $fromDate }}" class="ct-input" />
        </div>
        <div>
          <label class="ct-label">To date</label>
          <input type="date" name="to_date" value="{{ $toDate }}" class="ct-input" />
        </div>
        <div>
          <label class="ct-label" title="Prefix for reply lines (case-insensitive)">Reply flag</label>
          <input type="text" name="reply_flag" value="{{ $replyFlag }}" class="ct-input" />
        </div>
        <div>
          <label class="ct-label" title="Exact-line match for ordered events">Order flag</label>
          <input type="text" name="order_flag" value="{{ $orderFlag }}" class="ct-input" />
        </div>
        <div class="flex gap-2 items-end">
          <button type="submit" class="ct-btn">Recompute</button>
          <a href="/conversation/tracker/stats" class="ct-btn-ghost">Reset</a>
        </div>
      </form>
    </div>

    <!-- Summary -->
    <div class="ct-card">
      @php
        $sumTotal = collect($statsRows)->sum('total');
        $sumReplied = collect($statsRows)->sum('replied');
        $sumRepliedCells = collect($statsRows)->sum('replied_cells');
        $sumOrdered = collect($statsRows)->sum('ordered');
      @endphp
      <div class="summary-strip">
        <div class="summary-pill">
          <div class="summary-pill-label">Rows scanned</div>
          <div class="summary-pill-value">{{ number_format($totalRows) }}</div>
        </div>
        <div class="summary-pill">
          <div class="summary-pill-label">Total flow occurrences</div>
          <div class="summary-pill-value">{{ number_format($sumTotal) }}</div>
        </div>
        <div class="summary-pill">
          <div class="summary-pill-label">Replied (events)</div>
          <div class="summary-pill-value">{{ number_format($sumReplied) }}</div>
        </div>
        <div class="summary-pill">
          <div class="summary-pill-label">Replied cells</div>
          <div class="summary-pill-value">{{ number_format($sumRepliedCells) }}</div>
        </div>
        <div class="summary-pill">
          <div class="summary-pill-label">Customer ordered</div>
          <div class="summary-pill-value" style="color:#16a34a;">{{ number_format($sumOrdered) }}</div>
        </div>
      </div>
    </div>

    <!-- Table -->
    <div class="ct-card overflow-hidden">
      <div class="overflow-auto" style="max-height:calc(100vh - 320px);">
        <table class="stats-table" id="statsTable">
          <thead>
            <tr>
              <th data-sort="flow" data-type="flow">Flow</th>
              <th data-sort="total" data-type="num" class="num">Total</th>
              <th data-sort="replied" data-type="num" class="num">Replied</th>
              <th data-sort="replied_cells" data-type="num" class="num">Replied Cells</th>
              <th data-sort="ordered" data-type="num" class="num">Customer Ordered</th>
              <th data-sort="rate" data-type="num" class="num" title="Replied Cells / Total">Reply rate</th>
              <th data-sort="conv" data-type="num" class="num" title="Customer Ordered / Replied Cells">Conv rate</th>
            </tr>
          </thead>
          <tbody id="statsBody">
            @forelse ($statsRows as $r)
              @php
                $flow = $r['flow'];
                $cls = 'pill-flow';
                if (str_starts_with($flow, 'LOOP'))     $cls .= ' loop';
                elseif ($flow === 'MAIN FLOW')           $cls .= ' main';
                elseif (str_starts_with($flow, 'SEQUENCE')) $cls .= ' seq';
                $rate = $r['total'] > 0 ? ($r['replied_cells'] / $r['total'] * 100) : null;
                $conv = $r['replied_cells'] > 0 ? ($r['ordered'] / $r['replied_cells'] * 100) : null;
              @endphp
              <tr data-flow="{{ $flow }}" data-total="{{ $r['total'] }}" data-replied="{{ $r['replied'] }}" data-replied_cells="{{ $r['replied_cells'] }}" data-ordered="{{ $r['ordered'] }}" data-rate="{{ $rate ?? -1 }}" data-conv="{{ $conv ?? -1 }}">
                <td><span class="{{ $cls }}">{{ $flow }}</span></td>
                <td class="num">{{ number_format($r['total']) }}</td>
                <td class="num">{{ number_format($r['replied']) }}</td>
                <td class="num">{{ number_format($r['replied_cells']) }}</td>
                <td class="num" style="color:#16a34a;font-weight:600;">{{ number_format($r['ordered']) }}</td>
                <td class="num" style="color:#64748b;">{{ $rate !== null ? number_format($rate, 1) . '%' : '—' }}</td>
                <td class="num" style="color:#64748b;">{{ $conv !== null ? number_format($conv, 1) . '%' : '—' }}</td>
              </tr>
            @empty
              <tr><td colspan="7" style="text-align:center;padding:36px;color:#94a3b8;">No flows found. Adjust filters or import some records first.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <script>
    // Click-to-sort on column headers. Reads data-* attrs from rows.
    (function () {
      const table = document.getElementById('statsTable');
      if (!table) return;
      let lastSort = { col: null, asc: true };

      table.querySelectorAll('thead th[data-sort]').forEach((th) => {
        th.addEventListener('click', () => {
          const col = th.getAttribute('data-sort');
          const type = th.getAttribute('data-type');
          const asc = (lastSort.col === col) ? !lastSort.asc : true;
          lastSort = { col, asc };

          const tbody = document.getElementById('statsBody');
          const rows = [...tbody.querySelectorAll('tr[data-flow]')];
          rows.sort((a, b) => {
            const va = a.dataset[col] ?? '';
            const vb = b.dataset[col] ?? '';
            if (type === 'num') return (parseFloat(va) - parseFloat(vb)) * (asc ? 1 : -1);
            return va.localeCompare(vb) * (asc ? 1 : -1);
          });
          rows.forEach((r) => tbody.appendChild(r));

          // Update sorted indicator
          table.querySelectorAll('thead th').forEach((x) => x.classList.remove('sorted', 'asc'));
          th.classList.add('sorted');
          if (asc) th.classList.add('asc');
        });
      });
    })();
  </script>
</x-layout>

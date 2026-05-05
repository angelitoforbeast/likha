<x-layout>
  <x-slot name="title">JNT V2 — Date Coverage</x-slot>
  <x-slot name="heading">JNT V2 — SUBMISSION DATE COVERAGE</x-slot>

  <style>
    .cv-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 1px 2px rgba(0,0,0,0.04); }
    .cv-card-header { display:flex; align-items:center; justify-content:space-between; padding:12px 16px; border-bottom:1px solid #f1f5f9; flex-wrap:wrap; gap:8px; }
    .cv-title { font-size:14px; font-weight:600; color:#0f172a; }

    .cv-btn { display:inline-flex; align-items:center; gap:6px; background:#4f46e5; color:#fff; font-weight:600; font-size:13px; padding:8px 14px; border-radius:6px; }
    .cv-btn:hover { background:#4338ca; }
    .cv-btn-ghost { display:inline-flex; align-items:center; gap:4px; background:transparent; color:#64748b; font-size:12.5px; padding:6px 12px; border-radius:6px; border:1px solid #e2e8f0; }
    .cv-btn-ghost:hover { background:#f1f5f9; color:#0f172a; }
    .cv-btn-ghost.active { background:#4f46e5; color:#fff; border-color:#4f46e5; }

    .cv-stat { background:#f8fafc; border:1px solid #e2e8f0; padding:14px 16px; border-radius:8px; }
    .cv-stat .label { font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:0.05em; font-weight:600; }
    .cv-stat .value { font-size:32px; font-weight:700; color:#0f172a; margin-top:4px; }
    .cv-stat.danger { background:#fef2f2; border-color:#fecaca; }
    .cv-stat.danger .value { color:#dc2626; }
    .cv-stat.success { background:#f0fdf4; border-color:#bbf7d0; }
    .cv-stat.success .value { color:#15803d; }

    .miss-row {
      display:grid; grid-template-columns:140px 60px 1fr;
      padding:8px 14px; border-bottom:1px solid #f1f5f9; font-size:13px;
      font-family:ui-monospace,monospace;
    }
    .miss-row:hover { background:#fef9c3; }
    .miss-row .date { font-weight:600; color:#0f172a; }
    .miss-row .day { color:#64748b; }
    .miss-row.weekend { background:#fafafa; color:#94a3b8; }
    .miss-row.weekend:hover { background:#fef9c3; }

    .miss-header {
      display:grid; grid-template-columns:140px 60px 1fr;
      padding:9px 14px; background:#f8fafc; font-size:10.5px; font-weight:600;
      color:#475569; text-transform:uppercase; letter-spacing:0.04em; border-bottom:2px solid #e2e8f0;
    }

    select.year-picker {
      padding:7px 12px; border:1px solid #cbd5e1; border-radius:6px;
      font-size:13px; background:#fff; color:#0f172a; min-width:120px;
    }
  </style>

  <div class="w-full flex flex-col gap-4 p-2">

    {{-- ============= TOOLBAR ============= --}}
    <div class="cv-card">
      <div class="cv-card-header">
        <div class="cv-title">📅 Year Selector</div>
        <div class="flex items-center gap-2 flex-wrap">
          <a href="?year={{ $currentYear }}"
             class="cv-btn-ghost @if($year === $currentYear) active @endif">
            This Year ({{ $currentYear }})
          </a>
          <a href="?year={{ $currentYear - 1 }}"
             class="cv-btn-ghost @if($year === $currentYear - 1) active @endif">
            Last Year ({{ $currentYear - 1 }})
          </a>
          <span class="text-xs text-slate-400">|</span>
          <label class="text-xs text-slate-600">Pick year:</label>
          <select class="year-picker" onchange="window.location.href = '?year=' + this.value;">
            @for ($y = $currentYear; $y >= $minYear; $y--)
              <option value="{{ $y }}" @selected($year === $y)>{{ $y }}</option>
            @endfor
          </select>
        </div>
      </div>
    </div>

    {{-- ============= STATS ============= --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
      <div class="cv-stat">
        <div class="label">Total Days in {{ $year }}</div>
        <div class="value">{{ number_format($totalDays) }}</div>
        <div class="text-[11px] text-slate-400 mt-1">
          @if($year === $currentYear)
            From Jan 1 to today
          @else
            Whole year (Jan 1 to Dec 31)
          @endif
        </div>
      </div>

      <div class="cv-stat success">
        <div class="label">Days with Shipments</div>
        <div class="value">{{ number_format($daysWithData) }}</div>
        <div class="text-[11px] text-emerald-600 mt-1">
          @if($totalDays > 0)
            {{ number_format($daysWithData / $totalDays * 100, 1) }}% coverage
          @endif
        </div>
      </div>

      <div class="cv-stat danger">
        <div class="label">Days WITHOUT Shipments</div>
        <div class="value">{{ number_format($daysMissing) }}</div>
        <div class="text-[11px] text-red-400 mt-1">
          @if($daysMissing === 0)
            ✓ Walang missing days
          @else
            ⚠ Need to check these dates
          @endif
        </div>
      </div>
    </div>

    {{-- ============= MISSING DATES LIST ============= --}}
    <div class="cv-card">
      <div class="cv-card-header">
        <div class="cv-title">
          📋 Missing Dates — walang `submission_time` entries
        </div>
        @if(count($missing) > 0)
          <a href="?year={{ $year }}&export=csv"
             onclick="exportMissingCsv(event)"
             class="cv-btn-ghost">⬇ Export CSV</a>
        @endif
      </div>

      @if(count($missing) === 0)
        <div class="p-8 text-center text-emerald-600 font-semibold">
          ✓ Walang missing days for {{ $year }}!
          <div class="text-xs text-emerald-500 mt-1">Lahat ng araw may submission_time entries.</div>
        </div>
      @else
        <div class="miss-header">
          <div>DATE</div>
          <div>DAY</div>
          <div>NOTE</div>
        </div>
        <div style="max-height:600px; overflow-y:auto;">
          @foreach ($missing as $m)
            <div class="miss-row @if($m['is_weekend']) weekend @endif">
              <div class="date">{{ \Carbon\Carbon::parse($m['date'])->format('M j, Y') }}</div>
              <div class="day">{{ $m['day_name'] }}</div>
              <div>
                @if($m['is_weekend'])
                  <span class="text-slate-400 italic">Weekend</span>
                @else
                  <span class="text-red-600">Missing — kailangan i-check</span>
                @endif
              </div>
            </div>
          @endforeach
        </div>
      @endif
    </div>

  </div>

  <script>
    // CSV download client-side (since maliit yung data set)
    const missingData = @json($missing);

    function exportMissingCsv(e) {
      e.preventDefault();
      if (!missingData.length) return;

      const header = ['Date', 'Day', 'Is Weekend'];
      const rows = missingData.map(m => [m.date, m.day_name, m.is_weekend ? 'Yes' : 'No']);
      const csv = [header, ...rows].map(r => r.map(c => `"${(c+'').replace(/"/g, '""')}"`).join(',')).join('\n');

      const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = 'jnt_v2_missing_dates_{{ $year }}.csv';
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
    }
  </script>
</x-layout>

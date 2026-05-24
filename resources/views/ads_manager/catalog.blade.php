<x-layout>
  <x-slot name="title">Ad Catalog</x-slot>
  <x-slot name="heading">📋 Ad Catalog</x-slot>

  <style>
    .ct-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 1px 2px rgba(0,0,0,0.04); }
    .ct-card-header { display:flex; align-items:center; justify-content:space-between; padding:10px 14px; border-bottom:1px solid #f1f5f9; flex-wrap:wrap; gap:8px; }
    .ct-title { font-size:13px; font-weight:600; color:#0f172a; }
    .ct-input, .ct-select {
      padding:7px 10px; font-size:12.5px; color:#0f172a; background:#fff;
      border:1px solid #cbd5e1; border-radius:6px; width:100%;
    }
    .ct-input:focus, .ct-select:focus { outline:none; border-color:#6366f1; box-shadow:0 0 0 3px rgba(99,102,241,0.12); }
    .ct-btn { display:inline-flex; align-items:center; gap:5px; background:#4f46e5; color:#fff; font-weight:600; font-size:12px; padding:7px 12px; border-radius:6px; }
    .ct-btn:hover { background:#4338ca; }
    .ct-btn-ghost { display:inline-flex; align-items:center; gap:4px; background:transparent; color:#64748b; font-size:12px; padding:5px 10px; border-radius:6px; }
    .ct-btn-ghost:hover { background:#f1f5f9; color:#0f172a; }

    .stat-tile { background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px 14px; }
    .stat-label { font-size:10.5px; color:#64748b; text-transform:uppercase; letter-spacing:0.04em; }
    .stat-value { font-size:20px; font-weight:700; color:#0f172a; margin-top:2px; }

    .l-table { width:100%; border-collapse:separate; border-spacing:0; font-size:12.5px; }
    .l-table thead th {
      position:sticky; top:0; z-index:1; background:#f8fafc; color:#475569;
      font-size:10.5px; font-weight:600; text-transform:uppercase; letter-spacing:0.04em;
      padding:9px 10px; text-align:left; border-bottom:2px solid #e2e8f0;
      white-space:nowrap;
    }
    .l-table thead th a { color:#475569; text-decoration:none; }
    .l-table thead th a:hover { color:#0f172a; }
    .l-table thead th.active { color:#4f46e5; }
    .l-table tbody td { padding:8px 10px; border-bottom:1px solid #f1f5f9; vertical-align:top; }
    .l-table tbody tr:hover td { background:#f8fafc; }

    .mono { font-family:ui-monospace,monospace; font-size:11px; color:#64748b; }
    .pill { display:inline-flex; align-items:center; padding:2px 8px; border-radius:999px; font-size:10.5px; font-weight:600; background:#f1f5f9; color:#475569; }
    .name-cell { font-weight:600; color:#0f172a; line-height:1.3; }
    .name-cell-id { font-size:10px; color:#94a3b8; font-family:ui-monospace,monospace; margin-top:1px; }
    .date-cell { font-family:ui-monospace,monospace; font-size:11.5px; color:#0f172a; }
    .date-cell.empty { color:#cbd5e1; font-style:italic; }
  </style>

  @php
    // Helper para mag-flip sort dir per column
    $sortLink = function ($col, $label) use ($sortBy, $sortDir) {
      $newDir = ($sortBy === $col && $sortDir === 'asc') ? 'desc' : 'asc';
      $arrow  = ($sortBy === $col) ? ($sortDir === 'asc' ? ' ↑' : ' ↓') : '';
      $params = request()->all();
      $params['sort_by']  = $col;
      $params['sort_dir'] = $newDir;
      $href = url('/ads_manager/catalog') . '?' . http_build_query($params);
      $cls  = $sortBy === $col ? 'active' : '';
      return '<a href="' . $href . '" class="' . $cls . '">' . htmlspecialchars($label) . $arrow . '</a>';
    };
  @endphp

  <div class="w-full flex flex-col gap-4 p-2">

    @if (!empty($tableMissing))
      <div class="ct-card" style="padding:30px;text-align:center;">
        <p style="font-size:14px;color:#dc2626;font-weight:600;">⚠ ad_catalog table walang pa.</p>
        <p style="font-size:12px;color:#64748b;margin-top:8px;">Run migrations sa server: <code>php artisan migrate</code></p>
      </div>
    @else

      <!-- Stats summary -->
      <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div class="stat-tile">
          <div class="stat-label">Total Ads</div>
          <div class="stat-value">{{ number_format($totalAds) }}</div>
        </div>
        <div class="stat-tile">
          <div class="stat-label">Campaigns</div>
          <div class="stat-value">{{ number_format($totalCampaigns) }}</div>
        </div>
        <div class="stat-tile">
          <div class="stat-label">Ad Sets</div>
          <div class="stat-value">{{ number_format($totalAdSets) }}</div>
        </div>
        <div class="stat-tile">
          <div class="stat-label">Pages</div>
          <div class="stat-value">{{ number_format($totalPages) }}</div>
        </div>
      </div>

      <!-- Filters -->
      <div class="ct-card">
        <div class="ct-card-header">
          <div class="ct-title">🔎 Filters</div>
          <div class="flex gap-2 flex-wrap">
            <a href="/ads_manager/report" class="ct-btn-ghost">← Back to Report</a>
          </div>
        </div>

        <form method="GET" action="/ads_manager/catalog" class="grid grid-cols-2 md:grid-cols-6 gap-3 p-3">
          <div>
            <label class="block text-xs font-semibold text-slate-600 mb-1">Page</label>
            <select name="page" class="ct-select">
              <option value="">All pages</option>
              @foreach ($allPages as $p)
                <option value="{{ $p }}" @selected($pageFilter === $p)>{{ $p }}</option>
              @endforeach
            </select>
          </div>
          <div class="md:col-span-2">
            <label class="block text-xs font-semibold text-slate-600 mb-1">Search (campaign / adset / ad / IDs)</label>
            <input type="text" name="q" value="{{ $qFilter }}"
                   placeholder="e.g. MESH SEAT, camp001, headline keyword"
                   class="ct-input" />
          </div>
          <div>
            <label class="block text-xs font-semibold text-slate-600 mb-1">First Started ≥</label>
            <input type="date" name="from_date" value="{{ $fromDate }}" class="ct-input" />
          </div>
          <div>
            <label class="block text-xs font-semibold text-slate-600 mb-1">First Started ≤</label>
            <input type="date" name="to_date" value="{{ $toDate }}" class="ct-input" />
          </div>
          <div class="flex gap-2 items-end">
            <button type="submit" class="ct-btn">Apply</button>
            <a href="/ads_manager/catalog" class="ct-btn-ghost">Reset</a>
          </div>
        </form>
      </div>

      <!-- Table -->
      <div class="ct-card overflow-hidden">
        <div class="ct-card-header">
          <div class="ct-title">
            📋 Catalog ({{ number_format($rows->total()) }} ads)
            <span style="font-weight:400;color:#94a3b8;font-size:11.5px;margin-left:4px;">
              · auto-maintained from uploads
            </span>
          </div>
        </div>

        <div class="overflow-auto" style="max-height:calc(100vh - 360px);">
          <table class="l-table">
            <thead>
              <tr>
                <th style="width:120px;">{!! $sortLink('first_started', 'First Started') !!}</th>
                <th style="width:120px;">{!! $sortLink('first_spend_day', 'First Spend Day') !!}</th>
                <th style="width:220px;">{!! $sortLink('campaign_name', 'Campaign') !!}</th>
                <th style="width:200px;">{!! $sortLink('ad_set_name', 'Ad Set') !!}</th>
                <th>{!! $sortLink('ad_name', 'Ad (Headline)') !!}</th>
                <th style="width:160px;">{!! $sortLink('page_name', 'Page') !!}</th>
                <th style="width:130px;">{!! $sortLink('updated_at', 'Updated') !!}</th>
              </tr>
            </thead>
            <tbody>
              @forelse ($rows as $r)
                <tr>
                  <td>
                    @if ($r->first_started)
                      <div class="date-cell">{{ $r->first_started }}</div>
                    @else
                      <span class="date-cell empty">—</span>
                    @endif
                  </td>
                  <td>
                    @if ($r->first_spend_day)
                      <div class="date-cell">{{ $r->first_spend_day }}</div>
                    @else
                      <span class="date-cell empty">—</span>
                    @endif
                  </td>
                  <td>
                    <div class="name-cell">{{ $r->campaign_name ?: '—' }}</div>
                    @if ($r->campaign_id)
                      <div class="name-cell-id">{{ $r->campaign_id }}</div>
                    @endif
                  </td>
                  <td>
                    <div class="name-cell">{{ $r->ad_set_name ?: '—' }}</div>
                    @if ($r->ad_set_id)
                      <div class="name-cell-id">{{ $r->ad_set_id }}</div>
                    @endif
                  </td>
                  <td>
                    <div class="name-cell" style="white-space:normal;line-height:1.35;max-width:400px;">{{ $r->ad_name ?: '(no headline)' }}</div>
                    <div class="name-cell-id">{{ $r->ad_id }}</div>
                  </td>
                  <td>
                    @if ($r->page_name)
                      <span class="pill">{{ $r->page_name }}</span>
                    @else
                      <span class="mono">—</span>
                    @endif
                  </td>
                  <td>
                    <div class="mono">{{ $r->updated_at ? \Carbon\Carbon::parse($r->updated_at)->format('M j, g:i A') : '—' }}</div>
                  </td>
                </tr>
              @empty
                <tr>
                  <td colspan="7" style="text-align:center;padding:36px;color:#94a3b8;">
                    No ads in catalog matching filters.
                    <div style="font-size:11px;margin-top:6px;">Try adjusting filters or upload Excel sa /ads_manager/report.</div>
                  </td>
                </tr>
              @endforelse
            </tbody>
          </table>
        </div>

        @if ($rows->hasPages())
          <div class="p-3 border-t border-slate-100">
            {{ $rows->links() }}
          </div>
        @endif
      </div>

    @endif

  </div>
</x-layout>

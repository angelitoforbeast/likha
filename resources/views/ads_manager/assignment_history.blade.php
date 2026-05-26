<x-layout>
  <x-slot name="title">Campaign Assignment Log</x-slot>
  <x-slot name="heading">📜 Campaign Assignment History</x-slot>

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

    .l-table { width:100%; border-collapse:separate; border-spacing:0; font-size:12.5px; }
    .l-table thead th {
      position:sticky; top:0; z-index:1; background:#f8fafc; color:#475569;
      font-size:10.5px; font-weight:600; text-transform:uppercase; letter-spacing:0.04em;
      padding:9px 10px; text-align:left; border-bottom:2px solid #e2e8f0;
      white-space:nowrap;
    }
    .l-table tbody td { padding:8px 10px; border-bottom:1px solid #f1f5f9; vertical-align:top; word-wrap:break-word; }
    .l-table tbody tr:hover td { background:#f8fafc; }

    .pill { display:inline-flex; align-items:center; padding:2px 8px; border-radius:999px; font-size:10.5px; font-weight:600; }
    .pill.action-assigned   { background:#dcfce7; color:#166534; }
    .pill.action-reassigned { background:#dbeafe; color:#1e40af; }
    .pill.action-unassigned { background:#fee2e2; color:#991b1b; }
    .pill.role { background:#f1f5f9; color:#475569; font-size:9.5px; margin-left:4px; }

    .change-from { color:#94a3b8; text-decoration:line-through; font-size:11.5px; }
    .change-arrow { color:#6366f1; font-weight:700; margin:0 4px; }
    .change-to { color:#0f172a; font-weight:600; font-size:12.5px; }
    .deleted-tag { color:#dc2626; font-style:italic; }

    .mono { font-family:ui-monospace,monospace; font-size:11px; color:#64748b; }
    .small { font-size:10px; color:#94a3b8; }
  </style>

  <div class="w-full flex flex-col gap-4 p-2">

    <!-- Filters -->
    <div class="ct-card">
      <div class="ct-card-header">
        <div class="ct-title">🔎 Filters · {{ number_format($total) }} total entries</div>
        <div class="flex gap-2 flex-wrap">
          <a href="/ads_manager/campaigns/history" class="ct-btn-ghost">← Back to History page</a>
          <a href="/ads_manager/campaigns" class="ct-btn-ghost">Campaigns Manager</a>
        </div>
      </div>

      <form method="GET" action="/ads_manager/campaigns/assignments/log" class="grid grid-cols-2 md:grid-cols-6 gap-3 p-3">
        <div class="md:col-span-2">
          <label class="block text-xs font-semibold text-slate-600 mb-1">Campaign (ID or name)</label>
          <input type="text" name="campaign" value="{{ $campaignFilter }}"
                 placeholder="e.g. 120211234567890 or SALES VID"
                 class="ct-input" />
        </div>

        <div>
          <label class="block text-xs font-semibold text-slate-600 mb-1">Assigned Person</label>
          <select name="employee_id" class="ct-select">
            <option value="0">All</option>
            @foreach ($allEmployees as $emp)
              <option value="{{ $emp->id }}" @selected($employeeFilter === (int) $emp->id)>
                {{ $emp->name }} ({{ $emp->role }})
              </option>
            @endforeach
          </select>
        </div>

        <div>
          <label class="block text-xs font-semibold text-slate-600 mb-1">Action</label>
          <select name="action" class="ct-select">
            <option value="">All actions</option>
            <option value="assigned"   @selected($actionFilter === 'assigned')>Assigned (new)</option>
            <option value="reassigned" @selected($actionFilter === 'reassigned')>Reassigned</option>
            <option value="unassigned" @selected($actionFilter === 'unassigned')>Unassigned</option>
          </select>
        </div>

        <div>
          <label class="block text-xs font-semibold text-slate-600 mb-1">Changed By</label>
          <select name="changed_by" class="ct-select">
            <option value="0">All users</option>
            @foreach ($allChangers as $ch)
              <option value="{{ $ch->id }}" @selected($changedByFilter === (int) $ch->id)>{{ $ch->name }}</option>
            @endforeach
          </select>
        </div>

        <div>
          <label class="block text-xs font-semibold text-slate-600 mb-1">From</label>
          <input type="date" name="from_date" value="{{ $fromDate }}" class="ct-input" />
        </div>

        <div>
          <label class="block text-xs font-semibold text-slate-600 mb-1">To</label>
          <input type="date" name="to_date" value="{{ $toDate }}" class="ct-input" />
        </div>

        <div class="flex gap-2 items-end md:col-span-6">
          <button type="submit" class="ct-btn">Apply</button>
          <a href="/ads_manager/campaigns/assignments/log" class="ct-btn-ghost">Reset</a>
        </div>
      </form>
    </div>

    <!-- Log table -->
    <div class="ct-card overflow-hidden">
      <div class="overflow-auto" style="max-height:calc(100vh - 320px);">
        <table class="l-table">
          <thead>
            <tr>
              <th style="width:160px;">When</th>
              <th style="width:90px;">Action</th>
              <th>Campaign</th>
              <th style="width:300px;">Change</th>
              <th style="width:180px;">Changed By</th>
              <th>Note</th>
            </tr>
          </thead>
          <tbody>
            @forelse ($rows as $r)
              @php
                $action = null;
                if (is_null($r->old_employee_id) && !is_null($r->new_employee_id))      $action = 'assigned';
                elseif (!is_null($r->old_employee_id) && !is_null($r->new_employee_id)) $action = 'reassigned';
                elseif (!is_null($r->old_employee_id) && is_null($r->new_employee_id))  $action = 'unassigned';
              @endphp
              <tr>
                <td>
                  <div style="font-weight:600;font-size:12px;">{{ \Carbon\Carbon::parse($r->created_at)->format('M j, Y') }}</div>
                  <div class="small">{{ \Carbon\Carbon::parse($r->created_at)->format('g:i:s A') }}</div>
                  <div class="small" style="color:#cbd5e1;">{{ \Carbon\Carbon::parse($r->created_at)->diffForHumans() }}</div>
                </td>
                <td>
                  @if ($action)
                    <span class="pill action-{{ $action }}">{{ strtoupper($action) }}</span>
                  @else
                    <span class="pill" style="background:#f1f5f9;color:#94a3b8;">UNKNOWN</span>
                  @endif
                </td>
                <td>
                  <div style="font-weight:600;color:#0f172a;line-height:1.3;word-break:break-word;max-width:500px;">
                    {{ $r->campaign_name ?: '(no name)' }}
                  </div>
                  <div class="mono" style="margin-top:2px;">{{ $r->campaign_id }}</div>
                </td>
                <td>
                  @if ($action === 'assigned')
                    <span class="change-to">{{ $r->new_employee_name ?: '(deleted)' }}</span>
                    @if ($r->new_employee_role)<span class="pill role">{{ $r->new_employee_role }}</span>@endif
                  @elseif ($action === 'reassigned')
                    <span class="change-from">
                      {{ $r->old_employee_name ?: '(deleted)' }}
                      @if ($r->old_employee_role) ({{ $r->old_employee_role }}) @endif
                    </span>
                    <span class="change-arrow">→</span>
                    <span class="change-to">{{ $r->new_employee_name ?: '(deleted)' }}</span>
                    @if ($r->new_employee_role)<span class="pill role">{{ $r->new_employee_role }}</span>@endif
                  @elseif ($action === 'unassigned')
                    <span class="change-from">{{ $r->old_employee_name ?: '(deleted)' }}</span>
                    <span class="change-arrow">→</span>
                    <span style="color:#dc2626;font-weight:600;">(none)</span>
                  @else
                    <span class="small">—</span>
                  @endif
                </td>
                <td>
                  <div style="font-size:11.5px;color:#0f172a;">{{ $r->changed_by_name ?: '(unknown)' }}</div>
                  @if ($r->changed_by_email)
                    <div class="small mono">{{ $r->changed_by_email }}</div>
                  @endif
                </td>
                <td>
                  @if ($r->note)
                    <div style="font-size:11.5px;color:#475569;font-style:italic;">💬 {{ $r->note }}</div>
                  @else
                    <span style="color:#cbd5e1;">—</span>
                  @endif
                </td>
              </tr>
            @empty
              <tr>
                <td colspan="6" style="text-align:center;padding:36px;color:#94a3b8;font-size:13px;">
                  No assignment changes recorded yet matching filters.
                  <div style="font-size:11px;margin-top:6px;">
                    Try adjusting filters or assign people sa <a href="/ads_manager/campaigns/history" style="color:#1877f2;text-decoration:underline;">campaigns history page</a>.
                  </div>
                </td>
              </tr>
            @endforelse
          </tbody>
        </table>
      </div>

      @if (method_exists($rows, 'hasPages') && $rows->hasPages())
        <div class="p-3 border-t border-slate-100">
          {{ $rows->links() }}
        </div>
      @endif
    </div>

  </div>
</x-layout>

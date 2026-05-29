<x-layout>
  <x-slot name="title">Cancel Detector Settings</x-slot>
  <x-slot name="heading">Cancel Detector — Sheet Settings</x-slot>

  <style>
    .cd-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 1px 2px rgba(0,0,0,0.04); }
    .cd-card-header { display:flex; align-items:center; justify-content:space-between; padding:10px 14px; border-bottom:1px solid #f1f5f9; flex-wrap:wrap; gap:8px; }
    .cd-title { font-size:13px; font-weight:600; color:#0f172a; }
    .cd-input, .cd-select {
      width:100%; padding:8px 10px; font-size:13px; color:#0f172a; background:#fff;
      border:1px solid #cbd5e1; border-radius:6px; transition:border-color 0.15s, box-shadow 0.15s;
    }
    .cd-input:focus, .cd-select:focus {
      outline:none; border-color:#dc2626; box-shadow:0 0 0 3px rgba(220,38,38,0.12);
    }
    .cd-label { display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px; }
    .cd-hint { font-size:11px; color:#94a3b8; margin-top:4px; line-height:1.4; }
    .cd-btn { display:inline-flex; align-items:center; gap:6px; background:#dc2626; color:#fff; font-weight:600; font-size:13px; padding:8px 14px; border-radius:6px; }
    .cd-btn:hover { background:#b91c1c; }
    .cd-btn:disabled { background:#fca5a5; cursor:not-allowed; }
    .cd-btn-ghost { display:inline-flex; align-items:center; gap:5px; background:transparent; color:#64748b; font-size:12px; padding:5px 10px; border-radius:6px; }
    .cd-btn-ghost:hover { background:#f1f5f9; color:#0f172a; }
    .cd-btn-danger { background:transparent; color:#dc2626; font-size:12px; padding:5px 10px; border-radius:6px; }
    .cd-btn-danger:hover { background:#fef2f2; }
    .cd-btn-archive { background:transparent; color:#92400e; font-size:12px; padding:5px 10px; border-radius:6px; }
    .cd-btn-archive:hover { background:#fef3c7; }

    .cd-table { width:100%; border-collapse:separate; border-spacing:0; font-size:12.5px; }
    .cd-table thead th { background:#f8fafc; color:#475569; font-size:10.5px; font-weight:600; text-transform:uppercase; letter-spacing:0.04em; padding:9px 10px; text-align:left; border-bottom:2px solid #e2e8f0; }
    .cd-table tbody td { padding:8px 10px; border-bottom:1px solid #f1f5f9; vertical-align:top; word-wrap:break-word; }
    .cd-table tbody tr:hover td { background:#f8fafc; }
    .cd-table tbody tr.archived td { opacity:0.55; }
    .cd-fetch-status { font-size:11px; color:#64748b; }
    .cd-fetch-status.ok { color:#16a34a; }
    .cd-fetch-status.err { color:#dc2626; }
    .pill-archived { background:#fef3c7; color:#92400e; padding:2px 8px; border-radius:999px; font-size:10px; font-weight:700; }
  </style>

  <div class="w-full flex flex-col gap-4 p-2">
    @if(session('status'))
      <div class="p-3 rounded bg-green-100 text-green-800 font-semibold text-center">{{ session('status') }}</div>
    @endif

    {{-- Add new --}}
    <div class="cd-card">
      <div class="cd-card-header">
        <div class="cd-title">➕ Add new sheet</div>
        <div class="flex gap-2">
          <a href="/conversation/cancel-detector" class="cd-btn-ghost">← Back to Import</a>
          <a href="/conversation/cancel-detector/view" class="cd-btn-ghost">📊 View Imported</a>
        </div>
      </div>

      <form method="POST" action="/conversation/cancel-detector/settings" class="p-4 grid grid-cols-1 md:grid-cols-2 gap-4">
        @csrf

        <div class="md:col-span-2">
          <label class="cd-label" for="sheet_url">📄 Google Sheet URL</label>
          <div class="flex gap-2">
            <input id="sheet_url" name="sheet_url" type="url" required class="cd-input" placeholder="https://docs.google.com/spreadsheets/d/.../edit" />
            <button type="button" id="btnFetchSheets" onclick="fetchSheets()" class="cd-btn" style="white-space:nowrap;">🔍 Detect tabs</button>
          </div>
          <div id="fetchStatus" class="cd-fetch-status mt-1"></div>
        </div>

        <div>
          <label class="cd-label" for="selected_sheet_name">📑 Sheet tab</label>
          <select id="selected_sheet_name" name="selected_sheet_name" required class="cd-select">
            <option value="">— click "Detect tabs" first —</option>
          </select>
          <div class="cd-hint">Pre-selects first tab.</div>
        </div>

        <div>
          <label class="cd-label" for="range">Range</label>
          <input id="range" name="range" type="text" class="cd-input" value="A2:N" />
          <div class="cd-hint">Default <code>A2:N</code> — covers cols A–L (data) + col N (DONE flag). Col M reserved.</div>
        </div>

        <div class="md:col-span-2">
          <button type="submit" class="cd-btn">💾 Save setting</button>
        </div>
      </form>
    </div>

    {{-- Existing settings --}}
    <div class="cd-card overflow-hidden">
      <div class="cd-card-header">
        <div class="cd-title">📋 Saved sheets ({{ count($settings) }})</div>
        <div class="text-xs text-slate-500">Archived items hidden from import but kept here for reference.</div>
      </div>
      <div class="overflow-x-auto">
        <table class="cd-table">
          <thead>
            <tr>
              <th style="width:200px;">Spreadsheet</th>
              <th style="width:80px;">URL</th>
              <th style="width:160px;">Tab</th>
              <th style="width:90px;">Range</th>
              <th style="width:90px;">Status</th>
              <th style="width:180px;text-align:right;">Actions</th>
            </tr>
          </thead>
          <tbody>
            @forelse($settings as $s)
              <tr class="{{ $s->is_archived ? 'archived' : '' }}">
                <td>
                  <div style="font-weight:600;">{{ $s->spreadsheet_title ?? '—' }}</div>
                  <div style="font-size:10.5px;color:#94a3b8;font-family:monospace;">{{ \Illuminate\Support\Str::limit($s->sheet_id, 30) }}</div>
                </td>
                <td>
                  @if($s->sheet_url)
                    <a href="{{ $s->sheet_url }}" target="_blank" class="text-red-600 underline text-xs">Open ↗</a>
                  @else — @endif
                </td>
                <td><span style="background:#fef2f2;color:#b91c1c;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:500;">{{ $s->selected_sheet_name ?: '—' }}</span></td>
                <td style="font-family:monospace;font-size:11px;">{{ $s->range }}</td>
                <td>
                  @if($s->is_archived)
                    <span class="pill-archived">📦 Archived</span>
                  @else
                    <span style="color:#16a34a;font-size:11px;">● Active</span>
                  @endif
                </td>
                <td style="text-align:right;">
                  <form method="POST" action="/conversation/cancel-detector/settings/{{ $s->id }}/toggle-archive" class="inline">
                    @csrf
                    <button type="submit" class="cd-btn-archive">
                      {{ $s->is_archived ? '↩ Unarchive' : '📦 Archive' }}
                    </button>
                  </form>
                  <form method="POST" action="/conversation/cancel-detector/settings/{{ $s->id }}" onsubmit="return confirm('Delete this setting?')" class="inline">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="cd-btn-danger">🗑 Delete</button>
                  </form>
                </td>
              </tr>
            @empty
              <tr><td colspan="6" style="text-align:center;padding:30px;color:#94a3b8;">No settings yet — add one above.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <script>
    async function fetchSheets() {
      const url = document.getElementById('sheet_url').value.trim();
      const sel = document.getElementById('selected_sheet_name');
      const status = document.getElementById('fetchStatus');
      const btn = document.getElementById('btnFetchSheets');

      if (!url) {
        status.className = 'cd-fetch-status err';
        status.textContent = '⚠ Paste sheet URL first.';
        return;
      }

      btn.disabled = true;
      status.className = 'cd-fetch-status';
      status.textContent = 'Fetching tab list…';

      try {
        const qs = new URLSearchParams({ sheet_url: url });
        const res = await fetch(`/conversation/cancel-detector/settings/fetch-sheets?${qs.toString()}`, {
          headers: { Accept: 'application/json' },
        });
        const data = await res.json();
        if (!res.ok || !data.ok) {
          status.className = 'cd-fetch-status err';
          status.textContent = '❌ ' + (data.error || ('HTTP ' + res.status));
          return;
        }

        sel.innerHTML = '';
        const sheets = data.sheets || [];
        if (!sheets.length) {
          status.className = 'cd-fetch-status err';
          status.textContent = '⚠ No tabs found in this sheet.';
          return;
        }
        sheets.forEach((name) => {
          const opt = document.createElement('option');
          opt.value = name;
          opt.textContent = name;
          sel.appendChild(opt);
        });
        sel.value = sheets[0];

        status.className = 'cd-fetch-status ok';
        status.textContent = `✅ ${data.title || 'Spreadsheet'} — ${sheets.length} tab(s). Selected: ${sel.value}`;
      } catch (e) {
        status.className = 'cd-fetch-status err';
        status.textContent = '❌ ' + e.message;
      } finally {
        btn.disabled = false;
      }
    }
  </script>
</x-layout>

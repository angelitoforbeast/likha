<x-layout>
  <x-slot name="title">Conversation Tracker Settings</x-slot>
  <x-slot name="heading">Conversation Tracker — Sheet Settings</x-slot>

  <style>
    .ct-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 1px 2px rgba(0,0,0,0.04); }
    .ct-card-header { display:flex; align-items:center; justify-content:space-between; padding:10px 14px; border-bottom:1px solid #f1f5f9; flex-wrap:wrap; gap:8px; }
    .ct-title { font-size:13px; font-weight:600; color:#0f172a; }
    .ct-input, .ct-select {
      width:100%; padding:8px 10px; font-size:13px; color:#0f172a; background:#fff;
      border:1px solid #cbd5e1; border-radius:6px; transition:border-color 0.15s, box-shadow 0.15s;
    }
    .ct-input:focus, .ct-select:focus {
      outline:none; border-color:#6366f1; box-shadow:0 0 0 3px rgba(99,102,241,0.12);
    }
    .ct-label { display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px; }
    .ct-hint { font-size:11px; color:#94a3b8; margin-top:4px; line-height:1.4; }
    .ct-btn { display:inline-flex; align-items:center; gap:6px; background:#4f46e5; color:#fff; font-weight:600; font-size:13px; padding:8px 14px; border-radius:6px; }
    .ct-btn:hover { background:#4338ca; }
    .ct-btn:disabled { background:#a5b4fc; cursor:not-allowed; }
    .ct-btn-ghost { display:inline-flex; align-items:center; gap:5px; background:transparent; color:#64748b; font-size:12px; padding:5px 10px; border-radius:6px; }
    .ct-btn-ghost:hover { background:#f1f5f9; color:#0f172a; }
    .ct-btn-danger { background:transparent; color:#dc2626; font-size:12px; padding:5px 10px; border-radius:6px; }
    .ct-btn-danger:hover { background:#fef2f2; }

    .ct-table { width:100%; border-collapse:separate; border-spacing:0; font-size:12.5px; }
    .ct-table thead th { background:#f8fafc; color:#475569; font-size:10.5px; font-weight:600; text-transform:uppercase; letter-spacing:0.04em; padding:9px 10px; text-align:left; border-bottom:2px solid #e2e8f0; }
    .ct-table tbody td { padding:8px 10px; border-bottom:1px solid #f1f5f9; vertical-align:top; word-wrap:break-word; }
    .ct-table tbody tr:hover td { background:#f8fafc; }
    .ct-fetch-status { font-size:11px; color:#64748b; }
    .ct-fetch-status.ok { color:#16a34a; }
    .ct-fetch-status.err { color:#dc2626; }
  </style>

  <div class="w-full flex flex-col gap-4 p-2">
    @if(session('status'))
      <div class="p-3 rounded bg-green-100 text-green-800 font-semibold text-center">{{ session('status') }}</div>
    @endif

    <!-- Add new -->
    <div class="ct-card">
      <div class="ct-card-header">
        <div class="ct-title">➕ Add new sheet</div>
        <div class="flex gap-2">
          <a href="/conversation/tracker" class="ct-btn-ghost">← Back to Import</a>
          <a href="/conversation/tracker/view" class="ct-btn-ghost">📊 View Imported</a>
        </div>
      </div>

      <form method="POST" action="/conversation/tracker/settings" class="p-4 grid grid-cols-1 md:grid-cols-2 gap-4">
        @csrf

        <div class="md:col-span-2">
          <label class="ct-label" for="sheet_url">📄 Google Sheet URL</label>
          <div class="flex gap-2">
            <input id="sheet_url" name="sheet_url" type="url" required class="ct-input" placeholder="https://docs.google.com/spreadsheets/d/.../edit" />
            <button type="button" id="btnFetchSheets" onclick="fetchSheets()" class="ct-btn" style="white-space:nowrap;">🔍 Detect tabs</button>
          </div>
          <div id="fetchStatus" class="ct-fetch-status mt-1"></div>
        </div>

        <div>
          <label class="ct-label" for="selected_sheet_name">📑 Sheet tab</label>
          <select id="selected_sheet_name" name="selected_sheet_name" required class="ct-select">
            <option value="">— click "Detect tabs" first —</option>
          </select>
          <div class="ct-hint">Pre-selects "ALL ORDERS" if it exists, else first tab.</div>
        </div>

        <div>
          <label class="ct-label" for="range">Range</label>
          <input id="range" name="range" type="text" class="ct-input" value="A2:J" />
          <div class="ct-hint">Default <code>A2:J</code> — covers cols A–G (data) + col J (DONE flag).</div>
        </div>

        <div class="md:col-span-2">
          <button type="submit" class="ct-btn">💾 Save setting</button>
        </div>
      </form>
    </div>

    <!-- Existing settings -->
    <div class="ct-card overflow-hidden">
      <div class="ct-card-header">
        <div class="ct-title">📋 Saved sheets ({{ count($settings) }})</div>
      </div>
      <div class="overflow-x-auto">
        <table class="ct-table">
          <thead>
            <tr>
              <th style="width:200px;">Spreadsheet</th>
              <th style="width:80px;">URL</th>
              <th style="width:160px;">Tab</th>
              <th style="width:90px;">Range</th>
              <th style="width:100px;text-align:right;">Actions</th>
            </tr>
          </thead>
          <tbody>
            @forelse($settings as $s)
              <tr>
                <td>
                  <div style="font-weight:600;">{{ $s->spreadsheet_title ?? '—' }}</div>
                  <div style="font-size:10.5px;color:#94a3b8;font-family:monospace;">{{ \Illuminate\Support\Str::limit($s->sheet_id, 30) }}</div>
                </td>
                <td>
                  @if($s->sheet_url)
                    <a href="{{ $s->sheet_url }}" target="_blank" class="text-indigo-600 underline text-xs">Open ↗</a>
                  @else — @endif
                </td>
                <td><span style="background:#eef2ff;color:#4338ca;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:500;">{{ $s->selected_sheet_name ?: '—' }}</span></td>
                <td style="font-family:monospace;font-size:11px;">{{ $s->range }}</td>
                <td style="text-align:right;">
                  <form method="POST" action="/conversation/tracker/settings/{{ $s->id }}" onsubmit="return confirm('Delete this setting?')" class="inline">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="ct-btn-danger">🗑 Delete</button>
                  </form>
                </td>
              </tr>
            @empty
              <tr><td colspan="5" style="text-align:center;padding:30px;color:#94a3b8;">No settings yet — add one above.</td></tr>
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
        status.className = 'ct-fetch-status err';
        status.textContent = '⚠ Paste sheet URL first.';
        return;
      }

      btn.disabled = true;
      status.className = 'ct-fetch-status';
      status.textContent = 'Fetching tab list…';

      try {
        const qs = new URLSearchParams({ sheet_url: url });
        const res = await fetch(`/conversation/tracker/settings/fetch-sheets?${qs.toString()}`, {
          headers: { Accept: 'application/json' },
        });
        const data = await res.json();
        if (!res.ok || !data.ok) {
          status.className = 'ct-fetch-status err';
          status.textContent = '❌ ' + (data.error || ('HTTP ' + res.status));
          return;
        }

        sel.innerHTML = '';
        const sheets = data.sheets || [];
        if (!sheets.length) {
          status.className = 'ct-fetch-status err';
          status.textContent = '⚠ No tabs found in this sheet.';
          return;
        }
        sheets.forEach((name) => {
          const opt = document.createElement('option');
          opt.value = name;
          opt.textContent = name;
          sel.appendChild(opt);
        });
        // Pre-select "ALL ORDERS" if present, else first.
        const allOrders = sheets.find((n) => n.toLowerCase() === 'all orders');
        sel.value = allOrders || sheets[0];

        status.className = 'ct-fetch-status ok';
        status.textContent = `✅ ${data.title || 'Spreadsheet'} — ${sheets.length} tab(s). Selected: ${sel.value}`;
      } catch (e) {
        status.className = 'ct-fetch-status err';
        status.textContent = '❌ ' + e.message;
      } finally {
        btn.disabled = false;
      }
    }
  </script>
</x-layout>

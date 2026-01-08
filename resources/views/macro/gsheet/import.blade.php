<x-layout>
  <x-slot name="title">Import Macro Outputs</x-slot>
  <x-slot name="heading">Import From Google Sheetss</x-slot>

  @php
    $runId = request('run_id');
  @endphp

  {{-- Flash messages --}}
  @if (session('success'))
    <div class="bg-green-100 text-green-900 p-2 rounded mb-4">
      {{ session('success') }}
    </div>
  @elseif (session('error'))
    <div class="bg-red-100 text-red-800 p-2 rounded mb-4">
      {{ session('error') }}
    </div>
  @endif

  {{-- Controls --}}
  <div class="bg-white rounded shadow p-4 mb-4">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
      <div class="text-sm text-gray-600">
        Click the button below to import data from <b>ALL configured sheets</b>.
      </div>

      <div class="flex items-center gap-2">
        {{-- ✅ Settings button --}}
        <a href="{{ url('/macro/gsheet/settings') }}"
           class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded inline-flex items-center">
          Settings
        </a>

        <form method="POST" action="{{ url('/macro/gsheet/import') }}" onsubmit="return confirm('Are you sure you want to import?')">
          @csrf
          <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">
            Run Import Now
          </button>
        </form>
      </div>
    </div>

    {{-- Run Summary --}}
    <div id="runSummary" class="mt-4 hidden">
      <div class="grid grid-cols-1 md:grid-cols-6 gap-2 text-sm">
        <div class="border rounded p-2">
          <div class="text-gray-500">Run</div>
          <div class="font-semibold" id="runIdText">-</div>
        </div>
        <div class="border rounded p-2">
          <div class="text-gray-500">Status</div>
          <div class="font-semibold" id="runStatusText">-</div>
        </div>
        <div class="border rounded p-2">
          <div class="text-gray-500">Sheets</div>
          <div class="font-semibold"><span id="runProcessedSettings">0</span>/<span id="runTotalSettings">0</span></div>
        </div>
        <div class="border rounded p-2">
          <div class="text-gray-500">Processed</div>
          <div class="font-semibold" id="runProcessed">0</div>
        </div>
        <div class="border rounded p-2">
          <div class="text-gray-500">Inserted</div>
          <div class="font-semibold" id="runInserted">0</div>
        </div>
        <div class="border rounded p-2">
          <div class="text-gray-500">Updated</div>
          <div class="font-semibold" id="runUpdated">0</div>
        </div>
      </div>

      <div class="mt-2 text-xs text-gray-500">
        <span id="runMessage">-</span>
        <span class="ml-2">• Last refresh: <span id="lastRefresh">-</span></span>
      </div>
    </div>
  </div>

  {{-- Settings Table (static) --}}
  <div class="bg-white rounded shadow p-4">
    <div class="overflow-x-auto">
      <table class="table-auto w-full border text-sm">
        <thead class="bg-gray-100">
          <tr>
            <th class="border px-3 py-2 text-left">#</th>
            <th class="border px-3 py-2 text-left">Spreadsheet Title</th>
            <th class="border px-3 py-2 text-left">Sheet URL</th>
            <th class="border px-3 py-2 text-left">Range</th>
            <th class="border px-3 py-2 text-left">Status</th>
            <th class="border px-3 py-2 text-right">Processed</th>
            <th class="border px-3 py-2 text-right">Inserted</th>
            <th class="border px-3 py-2 text-right">Updated</th>
            <th class="border px-3 py-2 text-right">Skipped</th>
            <th class="border px-3 py-2 text-left">Message</th>
          </tr>
        </thead>
        <tbody id="sheetRows">
          @foreach ($settings as $i => $setting)
            <tr data-setting-id="{{ $setting->id }}">
              <td class="border px-3 py-2">{{ $i + 1 }}</td>
              <td class="border px-3 py-2">{{ $setting->gsheet_name ?? 'N/A' }}</td>
              <td class="border px-3 py-2">
                <a href="{{ $setting->sheet_url }}" target="_blank" class="text-blue-600 underline">Open</a>
              </td>
              <td class="border px-3 py-2">{{ $setting->sheet_range }}</td>
              <td class="border px-3 py-2">
                <span class="px-2 py-1 rounded text-xs bg-gray-100 text-gray-700">Idle</span>
              </td>
              <td class="border px-3 py-2 text-right">0</td>
              <td class="border px-3 py-2 text-right">0</td>
              <td class="border px-3 py-2 text-right">0</td>
              <td class="border px-3 py-2 text-right">0</td>
              <td class="border px-3 py-2 text-gray-500">-</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </div>

  <script>
    const runIdFromUrl = @json($runId);

    function badge(status) {
      const s = (status || '').toLowerCase();
      if (s === 'queued')  return `<span class="px-2 py-1 rounded text-xs bg-yellow-100 text-yellow-800">Queued</span>`;
      if (s === 'running') return `<span class="px-2 py-1 rounded text-xs bg-blue-100 text-blue-800">Running</span>`;
      if (s === 'done')    return `<span class="px-2 py-1 rounded text-xs bg-green-100 text-green-800">Done</span>`;
      if (s === 'failed')  return `<span class="px-2 py-1 rounded text-xs bg-red-100 text-red-800">Failed</span>`;
      if (s === 'skipped') return `<span class="px-2 py-1 rounded text-xs bg-gray-200 text-gray-700">Skipped</span>`;
      return `<span class="px-2 py-1 rounded text-xs bg-gray-100 text-gray-700">${status || 'Idle'}</span>`;
    }

    function setText(id, val) {
      const el = document.getElementById(id);
      if (el) el.textContent = val ?? '-';
    }

    let polling = null;

    async function poll(runId) {
      if (!runId) return;

      document.getElementById('runSummary').classList.remove('hidden');
      setText('runIdText', '#' + runId);

      try {
        const res = await fetch(`{{ route('macro.import.status') }}?run_id=${runId}`, {
          headers: { 'Accept': 'application/json' }
        });

        if (!res.ok) throw new Error('Status request failed: ' + res.status);
        const json = await res.json();
        if (!json.ok) throw new Error(json.message || 'Status not ok');

        const run = json.run;
        setText('runStatusText', run.status);
        setText('runTotalSettings', run.total_settings);
        setText('runProcessedSettings', run.processed_settings);
        setText('runProcessed', run.total_processed);
        setText('runInserted', run.total_inserted);
        setText('runUpdated', run.total_updated);
        setText('runMessage', run.message || '-');
        setText('lastRefresh', new Date().toLocaleTimeString());

        // Update rows
        const items = json.items || [];
        for (const it of items) {
          const tr = document.querySelector(`tr[data-setting-id="${it.setting_id}"]`);
          if (!tr) continue;

          const tds = tr.querySelectorAll('td');
          // columns: 0..9
          tds[4].innerHTML = badge(it.status);
          tds[5].textContent = it.processed ?? 0;
          tds[6].textContent = it.inserted ?? 0;
          tds[7].textContent = it.updated ?? 0;
          tds[8].textContent = it.skipped ?? 0;
          tds[9].textContent = it.message ?? '-';
        }

        // stop polling when done/failed
        if (run.status === 'done' || run.status === 'failed') {
          clearInterval(polling);
          polling = null;
        }

      } catch (e) {
        console.error(e);
        setText('runMessage', 'Error: ' + e.message);
      }
    }

    // Auto-start polling if run_id is in URL
    if (runIdFromUrl) {
      poll(runIdFromUrl);
      polling = setInterval(() => poll(runIdFromUrl), 1500);
    }
  </script>
</x-layout>

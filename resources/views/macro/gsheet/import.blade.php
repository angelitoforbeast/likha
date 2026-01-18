<x-layout>
  <x-slot name="title">Import Macro Output</x-slot>
  <x-slot name="heading">Import From Google Sheets</x-slot>

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
        <a href="{{ url('/macro/gsheet/settings') }}"
           class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded inline-flex items-center">
          Settings
        </a>

        {{-- NOTE: your current form posts to /macro/gsheet/import
           If you truly start via AJAX start endpoint, convert this to a button + JS.
           For now, keep your existing UI behavior. --}}
        <form method="POST" action="{{ url('/macro/gsheet/import') }}" onsubmit="return confirm('Are you sure you want to import?')">
          @csrf
          <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">
            Run Import Now
          </button>
        </form>
      </div>
    </div>

    {{-- ✅ Always-visible Last Import Summary --}}
    <div class="mt-4 rounded border bg-gray-50 p-3 text-sm">
      <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
        <div class="text-gray-700">
          <div>
            <span class="font-semibold">Last attempt:</span>
            <span id="lastAttemptText">
              @if(!empty($lastAttemptRun))
                {{ $lastAttemptRun->started_at ?? $lastAttemptRun->created_at }}
                <span class="ml-2 px-2 py-0.5 rounded bg-gray-200 text-gray-700 text-xs">
                  {{ strtoupper($lastAttemptRun->status ?? 'UNKNOWN') }}
                </span>
              @else
                -
              @endif
            </span>
          </div>

          <div class="mt-1">
            <span class="font-semibold">Last successful:</span>
            <span id="lastSuccessText">
              @if(!empty($lastSuccessRun))
                {{ $lastSuccessRun->finished_at ?? $lastSuccessRun->updated_at ?? $lastSuccessRun->created_at }}
              @else
                -
              @endif
            </span>
          </div>
        </div>

        <div class="text-xs text-gray-600">
          <div class="font-semibold text-gray-700 mb-1">Last message</div>
          <div id="lastMessageText">
            @if(!empty($lastAttemptRun) && !empty($lastAttemptRun->message))
              {{ $lastAttemptRun->message }}
            @else
              -
            @endif
          </div>
        </div>
      </div>
    </div>

    {{-- Run Summary (shows when polling / runId exists) --}}
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

  {{-- Settings Table --}}
  <div class="bg-white rounded shadow p-4">
    <div class="overflow-x-auto">
      <table class="table-auto w-full border text-sm">
        <thead class="bg-gray-100">
          <tr>
            <th class="border px-3 py-2 text-left">#</th>
            <th class="border px-3 py-2 text-left">Spreadsheet Title</th>
            <th class="border px-3 py-2 text-left">Sheet URL</th>
            <th class="border px-3 py-2 text-left">Range</th>

            {{-- ✅ optional but useful --}}
            <th class="border px-3 py-2 text-left">Last Imported</th>

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
              <td class="border px-3 py-2 titleCell">{{ $setting->gsheet_name ?? 'N/A' }}</td>
              <td class="border px-3 py-2">
                <a href="{{ $setting->sheet_url }}" target="_blank" class="text-blue-600 underline">Open</a>
              </td>
              <td class="border px-3 py-2 rangeCell">{{ $setting->sheet_range }}</td>

              {{-- ✅ from server map so it persists on refresh --}}
              <td class="border px-3 py-2 lastImportedCell text-xs text-gray-700">
                @php $ts = $lastImportedMap[$setting->id] ?? null; @endphp
                @if($ts) {{ $ts }} @else - @endif
              </td>

              <td class="border px-3 py-2 statusCell">
                <span class="px-2 py-1 rounded text-xs bg-gray-100 text-gray-700">Idle</span>
              </td>
              <td class="border px-3 py-2 text-right processedCell">0</td>
              <td class="border px-3 py-2 text-right insertedCell">0</td>
              <td class="border px-3 py-2 text-right updatedCell">0</td>
              <td class="border px-3 py-2 text-right skippedCell">0</td>
              <td class="border px-3 py-2 text-gray-500 messageCell">-</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </div>

  <script>
    const runIdFromUrl = @json($runId);
    let polling = null;

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

    function updateGlobalLastImport(run) {
      if (!run) return;

      // keep "Last attempt" live
      const lastAttempt = document.getElementById('lastAttemptText');
      const lastSuccess = document.getElementById('lastSuccessText');
      const lastMsg     = document.getElementById('lastMessageText');

      if (lastAttempt && run.started_at) {
        lastAttempt.innerHTML = `
          ${run.started_at}
          <span class="ml-2 px-2 py-0.5 rounded bg-gray-200 text-gray-700 text-xs">
            ${(run.status || 'UNKNOWN').toUpperCase()}
          </span>
        `;
      }
      if (lastMsg) lastMsg.textContent = run.message || '-';

      // only update lastSuccess when run done and has finished_at
      if (run.status === 'done' && run.finished_at && lastSuccess) {
        lastSuccess.textContent = run.finished_at;
      }
    }

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
        setText('runTotalSettings', run.total_settings ?? 0);
        setText('runProcessedSettings', run.processed_settings ?? 0);
        setText('runProcessed', run.total_processed ?? 0);
        setText('runInserted', run.total_inserted ?? 0);
        setText('runUpdated', run.total_updated ?? 0);
        setText('runMessage', run.message || '-');
        setText('lastRefresh', new Date().toLocaleTimeString());

        updateGlobalLastImport(run);

        // Update rows if items exist
        const items = json.items || [];
        for (const it of items) {
          const tr = document.querySelector(`tr[data-setting-id="${it.setting_id}"]`);
          if (!tr) continue;

          tr.querySelector('.statusCell').innerHTML = badge(it.status);
          tr.querySelector('.processedCell').textContent = it.processed ?? 0;
          tr.querySelector('.insertedCell').textContent = it.inserted ?? 0;
          tr.querySelector('.updatedCell').textContent = it.updated ?? 0;
          tr.querySelector('.skippedCell').textContent = it.skipped ?? 0;
          tr.querySelector('.messageCell').textContent = it.message ?? '-';

          // ✅ update last imported real-time when sheet done
          if ((it.status || '').toLowerCase() === 'done' && it.finished_at) {
            tr.querySelector('.lastImportedCell').textContent = it.finished_at;
          }
        }

        if (run.status === 'done' || run.status === 'failed') {
          clearInterval(polling);
          polling = null;
        }

      } catch (e) {
        console.error(e);
        setText('runMessage', 'Error: ' + e.message);
      }
    }

    if (runIdFromUrl) {
      poll(runIdFromUrl);
      polling = setInterval(() => poll(runIdFromUrl), 1500);
    }
  </script>
</x-layout>

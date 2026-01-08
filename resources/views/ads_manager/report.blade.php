<x-layout>
  <x-slot name="heading">Import Ads Manager Reports</x-slot>

  <div class="max-w-6xl mt-6 space-y-6">
    @if (session('status'))
      <div class="rounded bg-green-100 text-green-800 px-4 py-2">
        {{ session('status') }}
      </div>
    @endif

    {{-- MULTIPLE UPLOAD --}}
    <form action="{{ route('ads_manager.report.store') }}"
          method="POST"
          enctype="multipart/form-data"
          class="space-y-3"
          onsubmit="this.querySelector('button[type=submit]').disabled=true;">
      @csrf

      <div>
        <label class="block font-semibold mb-1">Upload files (CSV / XLSX / XLS)</label>
        <input type="file"
               name="files[]"
               accept=".csv,.txt,.xlsx,.xls"
               multiple
               class="border rounded w-full p-2"
               required>
        @error('files')
          <p class="text-red-600 text-sm mt-1">{{ $message }}</p>
        @enderror
        @error('files.*')
          <p class="text-red-600 text-sm mt-1">{{ $message }}</p>
        @enderror
      </div>

      <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">
        Upload (Multiple)
      </button>
    </form>

    {{-- LATEST SUMMARY (optional, keep) --}}
    <div class="border rounded p-4 bg-gray-50">
      <h2 class="font-semibold mb-2">Latest Status (Summary)</h2>

      <div id="latest-status-row" data-status="{{ $status }}">
        @php
          $badgeClass = [
            'queued'     => 'bg-yellow-100 text-yellow-800',
            'processing' => 'bg-blue-100 text-blue-800',
            'done'       => 'bg-green-100 text-green-800',
            'failed'     => 'bg-red-100 text-red-800',
            'idle'       => 'bg-gray-100 text-gray-800',
          ][$status] ?? 'bg-gray-100 text-gray-800';
        @endphp
        <span id="latest-status-badge" class="px-2 py-0.5 rounded {{ $badgeClass }}">{{ strtoupper($status) }}</span>
      </div>

      <div class="grid grid-cols-2 gap-4 mt-3">
        <div>
          <div class="text-sm text-gray-600">Inserted</div>
          <div id="latest-inserted" class="text-lg font-semibold">{{ $inserted }}</div>
        </div>
        <div>
          <div class="text-sm text-gray-600">Updated</div>
          <div id="latest-updated" class="text-lg font-semibold">{{ $updated }}</div>
        </div>
      </div>

      <p id="polling-note" class="text-xs text-gray-500 mt-3 hidden">Refreshing…</p>
    </div>

    {{-- LOGS TABLE --}}
    <div class="border rounded p-4">
      <div class="flex items-center justify-between mb-3">
        <h2 class="font-semibold">Upload Logs (latest 50)</h2>
        <button type="button" class="text-sm underline" onclick="location.reload()">Refresh</button>
      </div>

      <div class="overflow-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50">
            <tr class="text-left">
              <th class="p-2">Log ID</th>
              <th class="p-2">File</th>
              <th class="p-2">Status</th>
              <th class="p-2">Processed</th>
              <th class="p-2">Inserted</th>
              <th class="p-2">Updated</th>
              <th class="p-2">Skipped</th>
              <th class="p-2">Errors</th>
              <th class="p-2">Started</th>
              <th class="p-2">Finished</th>
            </tr>
          </thead>

          <tbody id="logs-body">
            @foreach($logs as $log)
              @php
                $s = strtolower($log->status ?? 'idle');
                $badgeClass = [
                  'queued'     => 'bg-yellow-100 text-yellow-800',
                  'processing' => 'bg-blue-100 text-blue-800',
                  'done'       => 'bg-green-100 text-green-800',
                  'failed'     => 'bg-red-100 text-red-800',
                  'idle'       => 'bg-gray-100 text-gray-800',
                ][$s] ?? 'bg-gray-100 text-gray-800';
              @endphp

              <tr class="border-t" data-log-id="{{ $log->id }}">
                <td class="p-2 font-mono">#{{ $log->id }}</td>
                <td class="p-2" data-col="original_name">{{ $log->original_name }}</td>

                <td class="p-2">
                  <span data-col="status_badge" class="px-2 py-0.5 rounded {{ $badgeClass }}">
                    {{ strtoupper($s) }}
                  </span>
                </td>

                <td class="p-2 font-mono" data-col="processed_rows">{{ (int)($log->processed_rows ?? 0) }}</td>
                <td class="p-2 font-mono" data-col="inserted">{{ (int)($log->inserted ?? 0) }}</td>
                <td class="p-2 font-mono" data-col="updated">{{ (int)($log->updated ?? 0) }}</td>
                <td class="p-2 font-mono" data-col="skipped">{{ (int)($log->skipped ?? 0) }}</td>
                <td class="p-2 font-mono" data-col="error_rows">{{ (int)($log->error_rows ?? 0) }}</td>

                <td class="p-2 font-mono text-xs" data-col="started_at">{{ $log->started_at }}</td>
                <td class="p-2 font-mono text-xs" data-col="finished_at">{{ $log->finished_at }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </div>

  </div>

  <script>
  (function(){
    const note = document.getElementById('polling-note');

    const latestBadge = document.getElementById('latest-status-badge');
    const latestInserted = document.getElementById('latest-inserted');
    const latestUpdated  = document.getElementById('latest-updated');

    const badgeClassMap = {
      queued:     'bg-yellow-100 text-yellow-800',
      processing: 'bg-blue-100 text-blue-800',
      done:       'bg-green-100 text-green-800',
      failed:     'bg-red-100 text-red-800',
      idle:       'bg-gray-100 text-gray-800',
    };

    function setBadge(el, status) {
      status = (status || 'idle').toLowerCase();
      el.className = 'px-2 py-0.5 rounded ' + (badgeClassMap[status] || badgeClassMap.idle);
      el.textContent = status.toUpperCase();
    }

    function anyRunning() {
      const rows = document.querySelectorAll('tr[data-log-id]');
      for (const r of rows) {
        const badge = r.querySelector('[data-col="status_badge"]');
        const s = (badge?.textContent || '').trim().toLowerCase();
        if (s === 'queued' || s === 'processing') return true;
      }
      return false;
    }

    async function pollOnce() {
      try {
        const res = await fetch("{{ route('ads_manager.report.status') }}", {
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await res.json();
        const logs = data.logs || [];

        // If may bagong logs na wala sa DOM, simplest: reload para lumabas sa table
        const knownIds = new Set([...document.querySelectorAll('tr[data-log-id]')].map(r => parseInt(r.dataset.logId)));
        let hasNew = false;

        for (const l of logs) {
          if (!knownIds.has(l.id)) { hasNew = true; break; }

          const row = document.querySelector('tr[data-log-id="'+l.id+'"]');
          if (!row) continue;

          const badge = row.querySelector('[data-col="status_badge"]');
          if (badge) setBadge(badge, l.status);

          const setText = (col, val) => {
            const el = row.querySelector('[data-col="'+col+'"]');
            if (el) el.textContent = (val ?? '');
          };

          setText('processed_rows', l.processed_rows ?? 0);
          setText('inserted', l.inserted ?? 0);
          setText('updated',  l.updated  ?? 0);
          setText('skipped',  l.skipped  ?? 0);
          setText('error_rows', l.error_rows ?? 0);
          setText('started_at',  l.started_at ?? '');
          setText('finished_at', l.finished_at ?? '');
        }

        if (hasNew) {
          location.reload();
          return;
        }

        // Update latest summary from first log (latest)
        if (logs.length) {
          setBadge(latestBadge, logs[0].status);
          latestInserted.textContent = (logs[0].inserted ?? 0);
          latestUpdated.textContent  = (logs[0].updated  ?? 0);
        }

        if (!anyRunning()) stop();

      } catch (e) {
        stop();
      }
    }

    let t = null;
    function start() {
      if (t) return;
      note.classList.remove('hidden');
      t = setInterval(pollOnce, 3000);
      pollOnce();
    }
    function stop() {
      if (t) { clearInterval(t); t = null; }
      note.classList.add('hidden');
    }

    // boot: poll only if may queued/processing
    if (anyRunning()) start();
  })();
  </script>
</x-layout>

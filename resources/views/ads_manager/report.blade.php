<x-layout>
  <x-slot name="heading">Import Ads Manager Reports</x-slot>

  <div class="max-w-xl mt-6 space-y-6">
    @if (session('status'))
      <div class="rounded bg-green-100 text-green-800 px-4 py-2">
        {{ session('status') }}
      </div>
    @endif

    <form action="{{ route('ads_manager.report.store') }}" method="POST" enctype="multipart/form-data" class="space-y-3"
          onsubmit="this.querySelector('button[type=submit]').disabled=true;">
      @csrf
      <div>
        <label class="block font-semibold mb-1">Upload file (CSV / XLSX / XLS)</label>
        <input type="file" name="file" accept=".csv,.txt,.xlsx,.xls" class="border rounded w-full p-2" required>
        @error('file')
          <p class="text-red-600 text-sm mt-1">{{ $message }}</p>
        @enderror
      </div>

      <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">
        Upload
      </button>
    </form>

    <div class="border rounded p-4 bg-gray-50">
      <h2 class="font-semibold mb-2">Status</h2>

      <div id="status-row" data-status="{{ $status }}">
        @php
          $badgeClass = [
            'queued'     => 'bg-yellow-100 text-yellow-800',
            'processing' => 'bg-blue-100 text-blue-800',
            'done'       => 'bg-green-100 text-green-800',
            'failed'     => 'bg-red-100 text-red-800',
            'idle'       => 'bg-gray-100 text-gray-800',
          ][$status] ?? 'bg-gray-100 text-gray-800';
        @endphp
        <span id="status-badge" class="px-2 py-0.5 rounded {{ $badgeClass }}">{{ strtoupper($status) }}</span>
      </div>

      <div class="grid grid-cols-2 gap-4 mt-3">
        <div>
          <div class="text-sm text-gray-600">Inserted</div>
          <div id="inserted" class="text-lg font-semibold">{{ $inserted }}</div>
        </div>
        <div>
          <div class="text-sm text-gray-600">Updated</div>
          <div id="updated" class="text-lg font-semibold">{{ $updated }}</div>
        </div>
      </div>

      <p id="polling-note" class="text-xs text-gray-500 mt-3 hidden">Refreshing…</p>
    </div>
  </div>

  <script>
  (function(){
    const statusRow = document.getElementById('status-row');
    const statusBadge = document.getElementById('status-badge');
    const insertedEl = document.getElementById('inserted');
    const updatedEl  = document.getElementById('updated');
    const note = document.getElementById('polling-note');

    const badgeClassMap = {
      queued:     'bg-yellow-100 text-yellow-800',
      processing: 'bg-blue-100 text-blue-800',
      done:       'bg-green-100 text-green-800',
      failed:     'bg-red-100 text-red-800',
      idle:       'bg-gray-100 text-gray-800',
    };

    function setBadge(status) {
      status = (status || 'idle').toLowerCase();
      statusBadge.className = 'px-2 py-0.5 rounded ' + (badgeClassMap[status] || badgeClassMap['idle']);
      statusBadge.textContent = (status || 'IDLE').toUpperCase();
      statusRow.dataset.status = status;
    }

    async function pollOnce() {
      try {
        const res = await fetch("{{ route('ads_manager.report.status') }}", {
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await res.json();
        setBadge(data.status);
        insertedEl.textContent = (data.inserted ?? 0);
        updatedEl.textContent  = (data.updated  ?? 0);

        const s = (data.status || '').toLowerCase();
        const keep = (s === 'queued' || s === 'processing');
        if (!keep) {
          stop();
        }
      } catch (e) {
        // stop polling on error (silent)
        stop();
      }
    }

    let t = null;
    function start() {
      if (t) return;
      note.classList.remove('hidden');
      t = setInterval(pollOnce, 3000);
    }
    function stop() {
      if (t) { clearInterval(t); t = null; }
      note.classList.add('hidden');
    }

    // boot: only poll when queued/processing
    const initial = (statusRow.dataset.status || '').toLowerCase();
    if (initial === 'queued' || initial === 'processing') start();
  })();
  </script>
</x-layout>

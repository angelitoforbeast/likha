<x-layout>
  <x-slot name="title">Jnt Upload</x-slot>
  <x-slot name="heading">UPDATE FROM JNT</x-slot>

  <div class="space-y-4 max-w-3xl">
    <form id="uploadForm" class="space-y-3" enctype="multipart/form-data" onsubmit="return false;">
      @csrf
      <div class="flex gap-3 items-center">
        {{-- NOTE: align with backend validation: zip,csv,xlsx (no xls) --}}
        <input id="file" name="file" type="file" accept=".zip,.csv,.xlsx"
               class="block w-full border border-gray-300 rounded p-2" />
        <button id="btnUpload" type="button"
                class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded disabled:opacity-50"
                disabled>UPLOAD</button>
      </div>
      <p id="selectedFile" class="text-sm text-gray-600"></p>
    </form>

    <div id="statusBox" class="hidden border rounded p-4">
      <div class="mb-2 flex items-center justify-between">
        <span class="font-semibold">Upload Status:</span>
        <span id="statusText" class="text-sm"></span>
      </div>

      <div class="w-full bg-gray-200 rounded h-2 mb-3">
        <div id="progressBar" class="bg-green-600 h-2 rounded" style="width:0%"></div>
      </div>

      <div class="grid grid-cols-2 gap-2 text-sm">
        <div>Processed Rows: <span id="processedRows">0</span></div>
        <div>Total Rows: <span id="totalRows">–</span></div>
        <div>Inserted: <span id="insertedRows">0</span></div>
        <div>Updated: <span id="updatedRows">0</span></div>
        <div>Skipped: <span id="skippedRows">0</span></div>
        <div>Error Rows: <span id="errorRows">0</span></div>
      </div>

      <div id="errorNote" class="text-sm text-red-600 mt-2 hidden"></div>
    </div>

    {{-- ✅ Pinaka-babang part: optional upload date/time --}}
    <div class="space-y-1">
      <label for="batch_at" class="block text-sm font-medium text-gray-700">
        Optional Upload Date/Time
      </label>
      <input id="batch_at" name="batch_at" type="datetime-local"
             class="block border border-gray-300 rounded p-2 w-full max-w-xs" />
      <p class="text-xs text-gray-500">
        Kapag empty, current date &amp; time ang gagamitin.
      </p>
    </div>
  </div>

  {{-- ───────────────────────────────────────────────────────────────────
       Upload history — permanently visible sa baba. Always shows the latest
       50 uploads from ALL users. Refreshes automatically after the user's
       own upload finishes (done/failed).
       ─────────────────────────────────────────────────────────────────── --}}
  <div class="mt-10 max-w-6xl">
    <div class="flex items-center justify-between mb-2">
      <h2 class="text-base font-semibold text-gray-800">Upload History</h2>
      <button id="btnRefreshHistory" type="button"
              class="text-xs text-blue-600 hover:text-blue-800 hover:underline">
        ↻ Refresh
      </button>
    </div>
    <div class="border border-gray-200 rounded overflow-hidden">
      <div class="overflow-x-auto" style="max-height:420px;overflow-y:auto;">
        <table class="min-w-full text-xs">
          <thead class="bg-gray-50 text-gray-600 sticky top-0">
            <tr>
              <th class="text-left  px-3 py-2 font-medium">Uploaded</th>
              <th class="text-left  px-3 py-2 font-medium">File</th>
              <th class="text-left  px-3 py-2 font-medium">By</th>
              <th class="text-left  px-3 py-2 font-medium">Status</th>
              <th class="text-right px-3 py-2 font-medium">Inserted</th>
              <th class="text-right px-3 py-2 font-medium">Updated</th>
              <th class="text-right px-3 py-2 font-medium">Skipped</th>
              <th class="text-right px-3 py-2 font-medium">Errors</th>
              <th class="text-right px-3 py-2 font-medium">Total</th>
            </tr>
          </thead>
          <tbody id="historyBody" class="divide-y divide-gray-100 bg-white">
            @forelse($recentUploads ?? [] as $log)
              @php
                $statusClass = match($log->status) {
                    'done'       => 'bg-green-100 text-green-800',
                    'processing' => 'bg-blue-100 text-blue-800',
                    'queued'     => 'bg-gray-100 text-gray-700',
                    'failed'     => 'bg-red-100 text-red-800',
                    default      => 'bg-gray-100 text-gray-700',
                };
                $when = $log->created_at ? \Carbon\Carbon::parse($log->created_at)->timezone('Asia/Manila')->format('M j, g:i A') : '—';
                $by   = $log->user_email ?: ($log->user_id ? ('User #' . $log->user_id) : '—');
              @endphp
              <tr class="hover:bg-gray-50">
                <td class="px-3 py-1.5 whitespace-nowrap text-gray-700">{{ $when }}</td>
                <td class="px-3 py-1.5 text-gray-900 max-w-[280px] truncate" title="{{ $log->original_name }}">
                  {{ $log->original_name }}
                </td>
                <td class="px-3 py-1.5 text-gray-700 max-w-[180px] truncate" title="{{ $by }}">{{ $by }}</td>
                <td class="px-3 py-1.5">
                  <span class="inline-block px-2 py-0.5 rounded text-[10px] font-semibold uppercase {{ $statusClass }}">
                    {{ $log->status }}
                  </span>
                </td>
                <td class="px-3 py-1.5 text-right tabular-nums text-gray-900">{{ number_format($log->inserted ?? 0) }}</td>
                <td class="px-3 py-1.5 text-right tabular-nums text-gray-900">{{ number_format($log->updated ?? 0) }}</td>
                <td class="px-3 py-1.5 text-right tabular-nums text-gray-700">{{ number_format($log->skipped ?? 0) }}</td>
                <td class="px-3 py-1.5 text-right tabular-nums {{ ($log->error_rows ?? 0) > 0 ? 'text-red-700 font-semibold' : 'text-gray-400' }}">
                  {{ number_format($log->error_rows ?? 0) }}
                </td>
                <td class="px-3 py-1.5 text-right tabular-nums text-gray-500">{{ $log->total_rows ? number_format($log->total_rows) : '—' }}</td>
              </tr>
            @empty
              <tr><td colspan="9" class="px-3 py-6 text-center text-gray-400">No uploads yet.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
    <p class="text-[11px] text-gray-500 mt-1">Latest 50 uploads · all users · auto-refreshes after own upload completes</p>
  </div>

  <script>
    const fileInput = document.getElementById('file');
    const btnUpload = document.getElementById('btnUpload');
    const selectedFile = document.getElementById('selectedFile');

    const statusBox = document.getElementById('statusBox');
    const statusText = document.getElementById('statusText');
    const progressBar = document.getElementById('progressBar');

    const processedRows = document.getElementById('processedRows');
    const totalRows = document.getElementById('totalRows');
    const insertedRows = document.getElementById('insertedRows');
    const updatedRows = document.getElementById('updatedRows');
    const skippedRows = document.getElementById('skippedRows');
    const errorRows = document.getElementById('errorRows');
    const errorNote = document.getElementById('errorNote');

    const batchAtInput = document.getElementById('batch_at'); // ✅ bagong field

    let pollTimer = null;
    let currentId = null;

    const csrf = '{{ csrf_token() }}';

    function resetStatusUI() {
      statusBox.classList.remove('hidden');
      statusText.textContent = 'Uploading...';
      progressBar.style.width = '0%';
      processedRows.textContent = '0';
      totalRows.textContent = '–';
      insertedRows.textContent = '0';
      updatedRows.textContent = '0';
      skippedRows.textContent = '0';
      errorRows.textContent = '0';
      errorNote.classList.add('hidden');
      errorNote.textContent = '';
    }

    async function safeJson(res) {
      const ct = res.headers.get('content-type') || '';
      if (ct.includes('application/json')) return await res.json();
      const text = await res.text();
      throw new Error(`Non-JSON response (${res.status}): ${text.slice(0, 200)}...`);
    }

    fileInput.addEventListener('change', () => {
      btnUpload.disabled = !fileInput.files.length;
      selectedFile.textContent = fileInput.files.length
        ? `Selected: ${fileInput.files[0].name}`
        : '';
    });

    btnUpload.addEventListener('click', async () => {
      if (!fileInput.files.length) return;

      btnUpload.disabled = true;
      resetStatusUI();

      const fd = new FormData();
      fd.append('file', fileInput.files[0]);

      // ✅ isama optional batch_at
      if (batchAtInput && batchAtInput.value) {
        fd.append('batch_at', batchAtInput.value);
      }

      try {
        // Relative URL to stay same-origin
        const res = await fetch('/jnt_upload', {
          method: 'POST',
          headers: { 'X-CSRF-TOKEN': csrf },
          body: fd
        });
        if (!res.ok) {
          const t = await res.text();
          throw new Error(`Upload failed (${res.status}): ${t.slice(0,200)}...`);
        }
        const json = await safeJson(res);
        currentId = json.id;
        statusText.textContent = 'QUEUED';

        startPolling();
      } catch (e) {
        statusText.textContent = 'FAILED';
        errorNote.classList.remove('hidden');
        errorNote.textContent = e.message || 'Upload error';
        btnUpload.disabled = false;
      }
    });

    function startPolling() {
      if (pollTimer) clearInterval(pollTimer);
      pollTimer = setInterval(async () => {
        try {
          const res = await fetch('/jnt_upload/status/' + currentId, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
          if (!res.ok) {
            const t = await res.text();
            throw new Error(`Status failed (${res.status}): ${t.slice(0,200)}...`);
          }
          const s = await safeJson(res);

          statusText.textContent = (s.status || '').toUpperCase();

          processedRows.textContent = s.processed_rows ?? 0;
          insertedRows.textContent = s.inserted ?? 0;
          updatedRows.textContent = s.updated ?? 0;
          skippedRows.textContent = s.skipped ?? 0;
          errorRows.textContent = s.error_rows ?? 0;
          totalRows.textContent = s.total_rows ?? '–';

          if (s.total_rows && s.total_rows > 0) {
            const pct = Math.max(0, Math.min(100, Math.round((s.processed_rows / s.total_rows) * 100)));
            progressBar.style.width = pct + '%';
          } else {
            const w = parseInt(progressBar.style.width) || 0;
            progressBar.style.width = ((w + 5) % 95) + '%';
          }

          if (s.errors_path) {
            errorNote.classList.remove('hidden');
            errorNote.textContent = 'Some rows were invalid. Errors saved at: ' + s.errors_path;
          }

          if (s.status === 'done' || s.status === 'failed') {
            clearInterval(pollTimer);
            pollTimer = null;
            btnUpload.disabled = false;
            if (s.status === 'done' && !s.total_rows) {
              progressBar.style.width = '100%';
            }
            // Refresh history para makita yung kakatapos lang na upload.
            refreshHistory();
          }
        } catch (e) {
          console.error(e);
          statusText.textContent = 'FAILED';
          errorNote.classList.remove('hidden');
          errorNote.textContent = e.message || 'Polling error';
          clearInterval(pollTimer);
          pollTimer = null;
          btnUpload.disabled = false;
          // Refresh kahit may error — baka may partial result na rumehistro.
          refreshHistory();
        }
      }, 2000);
    }

    // ── History table refresh ──────────────────────────────────────────
    // Re-renders the tbody#historyBody from /jnt_upload/history JSON. Called
    // after own upload finishes (done/failed) and via manual refresh button.
    const historyBody = document.getElementById('historyBody');
    const btnRefreshHistory = document.getElementById('btnRefreshHistory');

    function escapeHtml(s) {
      return String(s ?? '').replace(/[&<>"']/g, (c) => (
        { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]
      ));
    }
    function statusBadgeClass(st) {
      switch (st) {
        case 'done':       return 'bg-green-100 text-green-800';
        case 'processing': return 'bg-blue-100 text-blue-800';
        case 'queued':     return 'bg-gray-100 text-gray-700';
        case 'failed':     return 'bg-red-100 text-red-800';
        default:           return 'bg-gray-100 text-gray-700';
      }
    }
    function formatWhen(iso) {
      if (!iso) return '—';
      const d = new Date(iso);
      if (isNaN(d.getTime())) return '—';
      // Display in Asia/Manila (PH) — short month + 12-hour time.
      return d.toLocaleString('en-US', {
        timeZone: 'Asia/Manila',
        month: 'short', day: 'numeric',
        hour: 'numeric', minute: '2-digit', hour12: true,
      });
    }
    function fmtInt(n) {
      const v = Number(n || 0);
      return v.toLocaleString('en-US');
    }

    async function refreshHistory() {
      try {
        const res = await fetch('/jnt_upload/history', {
          headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
        });
        if (!res.ok) return;
        const json = await res.json();
        const rows = Array.isArray(json.rows) ? json.rows : [];
        if (!rows.length) {
          historyBody.innerHTML = '<tr><td colspan="9" class="px-3 py-6 text-center text-gray-400">No uploads yet.</td></tr>';
          return;
        }
        historyBody.innerHTML = rows.map((r) => {
          const by = r.user_email || (r.user_id ? ('User #' + r.user_id) : '—');
          const errorsCls = (Number(r.error_rows) || 0) > 0
            ? 'text-red-700 font-semibold'
            : 'text-gray-400';
          return `
            <tr class="hover:bg-gray-50">
              <td class="px-3 py-1.5 whitespace-nowrap text-gray-700">${escapeHtml(formatWhen(r.created_at))}</td>
              <td class="px-3 py-1.5 text-gray-900 max-w-[280px] truncate" title="${escapeHtml(r.original_name)}">${escapeHtml(r.original_name)}</td>
              <td class="px-3 py-1.5 text-gray-700 max-w-[180px] truncate" title="${escapeHtml(by)}">${escapeHtml(by)}</td>
              <td class="px-3 py-1.5">
                <span class="inline-block px-2 py-0.5 rounded text-[10px] font-semibold uppercase ${statusBadgeClass(r.status)}">
                  ${escapeHtml(r.status)}
                </span>
              </td>
              <td class="px-3 py-1.5 text-right tabular-nums text-gray-900">${fmtInt(r.inserted)}</td>
              <td class="px-3 py-1.5 text-right tabular-nums text-gray-900">${fmtInt(r.updated)}</td>
              <td class="px-3 py-1.5 text-right tabular-nums text-gray-700">${fmtInt(r.skipped)}</td>
              <td class="px-3 py-1.5 text-right tabular-nums ${errorsCls}">${fmtInt(r.error_rows)}</td>
              <td class="px-3 py-1.5 text-right tabular-nums text-gray-500">${r.total_rows ? fmtInt(r.total_rows) : '—'}</td>
            </tr>
          `;
        }).join('');
      } catch (e) {
        console.error('refreshHistory failed:', e);
      }
    }

    if (btnRefreshHistory) btnRefreshHistory.addEventListener('click', refreshHistory);
  </script>
</x-layout>

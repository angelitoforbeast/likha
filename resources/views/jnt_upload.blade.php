<x-layout>
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
          }
        } catch (e) {
          console.error(e);
          statusText.textContent = 'FAILED';
          errorNote.classList.remove('hidden');
          errorNote.textContent = e.message || 'Polling error';
          clearInterval(pollTimer);
          pollTimer = null;
          btnUpload.disabled = false;
        }
      }, 2000);
    }
  </script>
</x-layout>

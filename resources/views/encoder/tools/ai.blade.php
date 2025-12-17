<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>AI Tool</title>
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50">
  <div class="max-w-5xl mx-auto p-6 space-y-4">

    <div class="bg-white rounded-xl shadow p-4">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
          <div class="text-xl font-bold">/encoder/tools/ai</div>
          <div class="text-sm text-gray-600">Model: <b>{{ $defaultModel }}</b> • Reasoning effort default: <b>{{ $defaultEffort }}</b></div>
        </div>

        <div class="text-xs text-gray-500">
          Config files:
          <div><code class="bg-gray-100 px-1 rounded">/encoder/tools/apikey.txt</code></div>
          <div><code class="bg-gray-100 px-1 rounded">/encoder/tools/apimodel.txt</code></div>
        </div>
      </div>
    </div>

    <div class="bg-white rounded-xl shadow p-4 space-y-3">
      <label class="block text-sm font-semibold">Input</label>
      <textarea id="input" class="w-full border rounded-lg p-3 h-44" placeholder="Type your prompt here..."></textarea>

      <div class="flex flex-wrap items-center gap-3">
        <div>
          <label class="block text-sm font-semibold">Reasoning effort (optional override)</label>
          <select id="effort" class="border rounded-lg p-2">
            <option value="">(use from apimodel.txt)</option>
            <option value="low">low</option>
            <option value="medium">medium</option>
            <option value="high" selected>high</option>
          </select>
        </div>

        <label class="flex items-center gap-2 text-sm">
          <input id="useDb" type="checkbox" class="h-4 w-4" checked>
          Use DB learning (macro_output, STATUS=PROCEED)
        </label>

        <button id="runBtn" class="ml-auto bg-black text-white px-4 py-2 rounded-lg">
          Run
        </button>
      </div>

      <div id="status" class="text-sm text-gray-600"></div>
    </div>

    <div class="bg-white rounded-xl shadow p-4 space-y-2">
      <div class="flex items-center justify-between">
        <div class="text-sm font-semibold">Output</div>
        <button id="copyBtn" class="text-sm border px-3 py-1 rounded-lg">Copy</button>
      </div>
      <pre id="output" class="whitespace-pre-wrap text-sm bg-gray-50 border rounded-lg p-3 min-h-[120px]"></pre>

      <details class="text-sm">
        <summary class="cursor-pointer text-gray-700">Similar past cases used (macro_output)</summary>
        <pre id="matches" class="whitespace-pre-wrap text-xs bg-gray-50 border rounded-lg p-3 mt-2"></pre>
      </details>

      <details class="text-sm">
        <summary class="cursor-pointer text-gray-700">Raw JSON (debug)</summary>
        <pre id="raw" class="whitespace-pre-wrap text-xs bg-gray-50 border rounded-lg p-3 mt-2"></pre>
      </details>
    </div>

  </div>

<script>
const runBtn   = document.getElementById('runBtn');
const inputEl  = document.getElementById('input');
const effortEl = document.getElementById('effort');
const useDbEl  = document.getElementById('useDb');
const statusEl = document.getElementById('status');
const outEl    = document.getElementById('output');
const rawEl    = document.getElementById('raw');
const matchEl  = document.getElementById('matches');
const copyBtn  = document.getElementById('copyBtn');

copyBtn.addEventListener('click', async () => {
  await navigator.clipboard.writeText(outEl.textContent || '');
  copyBtn.textContent = 'Copied!';
  setTimeout(()=>copyBtn.textContent='Copy', 800);
});

runBtn.addEventListener('click', async () => {
  const input = inputEl.value.trim();
  if (!input) {
    alert('Please enter input.');
    return;
  }

  runBtn.disabled = true;
  runBtn.textContent = 'Running...';
  statusEl.textContent = 'Calling API...';
  outEl.textContent = '';
  rawEl.textContent = '';
  matchEl.textContent = '';

  try {
    const res = await fetch("{{ route('encoder.tools.ai.run') }}", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-CSRF-TOKEN": document.querySelector('meta[name="csrf-token"]').content,
        "Accept": "application/json",
      },
      body: JSON.stringify({
        input,
        effort: effortEl.value || null,
        use_db: !!useDbEl.checked,
      }),
    });

    const data = await res.json();

    if (!data.ok) {
      statusEl.textContent = 'Error.';
      outEl.textContent = JSON.stringify(data.error, null, 2);
      rawEl.textContent = JSON.stringify(data, null, 2);
      return;
    }

    statusEl.textContent = 'Done.';
    outEl.textContent = data.text || '(no text)';
    matchEl.textContent = JSON.stringify(data.matches || [], null, 2);
    rawEl.textContent = JSON.stringify(data.raw, null, 2);

  } catch (e) {
    statusEl.textContent = 'Request failed.';
    outEl.textContent = String(e);
  } finally {
    runBtn.disabled = false;
    runBtn.textContent = 'Run';
  }
});
</script>
</body>
</html>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Botcake PSID Import</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 text-gray-900">

<div class="max-w-3xl mx-auto p-6">
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-2xl font-semibold">Botcake PSID Import</h1>

        <a href="{{ route('botcake.psid.settings') }}"
           class="bg-gray-200 hover:bg-gray-300 text-gray-800 text-sm px-4 py-2 rounded">
            Go to Settings
        </a>
    </div>

    @if(session('success'))
        <div class="mb-3 px-4 py-2 rounded bg-green-100 text-green-800 text-sm">
            {{ session('success') }}
        </div>
    @endif

    @if(session('error'))
        <div class="mb-3 px-4 py-2 rounded bg-red-100 text-red-800 text-sm">
            {{ session('error') }}
        </div>
    @endif

    <div class="mb-5 bg-white p-4 rounded shadow text-sm text-gray-700">
        <ul class="list-disc ml-5 space-y-1">
            <li>Updates <code>macro_output.botcake_psid</code> using <b>PAGE (A) + FULL NAME (B)</b>.</li>
            <li>Writes <code>imported</code> or <code>not existing</code> into column <b>J</b> (blank rows only).</li>
            <li>Uses cell <b>K1</b> as starting seed (initial search).</li>
            <li>OPTIONAL cutoff uses <b>DATE (D)</b> on/before cutoff.</li>
        </ul>
    </div>

    <div class="mb-4 text-sm text-gray-700">
        <p class="font-semibold mb-1">Active Google Sheet settings:</p>
        <ul class="list-disc ml-5 space-y-1">
            @forelse($settings as $s)
                <li>
                    <span class="font-medium">{{ $s->gsheet_name }}</span>
                    – <span class="text-gray-600">{{ $s->sheet_range }}</span><br>
                    <a href="{{ $s->sheet_url }}" target="_blank" class="text-blue-600 underline text-xs">
                        Open sheet
                    </a>
                </li>
            @empty
                <li class="text-gray-500">
                    No settings configured yet. Go to <code>/botcake/psid/settings</code> first.
                </li>
            @endforelse
        </ul>
    </div>

    <form method="POST" action="{{ route('botcake.psid.import.run') }}" class="space-y-4">
        @csrf

        <div>
            <label for="cutoff_datetime" class="block text-sm font-medium text-gray-700">
                Import only rows with DATE (column D) on or before:
            </label>
            <input
                type="datetime-local"
                id="cutoff_datetime"
                name="cutoff_datetime"
                class="border rounded px-2 py-1 text-sm w-full"
                value="{{ old('cutoff_datetime', now()->subDay()->setTime(23, 59)->format('Y-m-d\TH:i')) }}"
            />
            <p class="text-xs text-gray-500 mt-1">
                Sample: <code>18:55:38 08-12-2025</code>. Blank cutoff = all eligible rows.
            </p>
            @error('cutoff_datetime')
            <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
            @enderror
        </div>

        <button type="submit"
                class="bg-blue-600 hover:bg-blue-700 text-white text-sm px-6 py-2 rounded disabled:opacity-50"
                @if($settings->isEmpty()) disabled @endif>
            IMPORT NOW
        </button>
    </form>

    {{-- Live Progress --}}
    <div class="mt-6 bg-white p-4 rounded shadow">
        <div class="flex items-center justify-between">
            <div class="font-semibold">Live Status</div>
            <div id="runStatusPill" class="text-xs px-2 py-1 rounded bg-gray-100 text-gray-700">Idle</div>
        </div>

        <div class="mt-3 grid grid-cols-2 gap-3 text-sm">
            <div><span class="text-gray-500">Run ID:</span> <span id="runId">-</span></div>
            <div><span class="text-gray-500">Cutoff:</span> <span id="cutoff">-</span></div>

            <div><span class="text-gray-500">K1 value:</span> <span id="k1">-</span></div>
            <div><span class="text-gray-500">Seed row:</span> <span id="seedRow">-</span></div>

            <div><span class="text-gray-500">Selected start row:</span> <span id="startRow">-</span></div>
            <div><span class="text-gray-500">Next scan from:</span> <span id="nextScan">-</span></div>

            <div><span class="text-gray-500">Batch:</span> <span id="batchNo">-</span></div>
            <div><span class="text-gray-500">Batch range:</span> <span id="batchRange">-</span></div>

            <div><span class="text-gray-500">Imported:</span> <span id="totalImported">0</span></div>
            <div><span class="text-gray-500">Not existing:</span> <span id="totalNotExisting">0</span></div>
            <div><span class="text-gray-500">Skipped:</span> <span id="totalSkipped">0</span></div>
            <div><span class="text-gray-500">Updated at:</span> <span id="updatedAt">-</span></div>
        </div>

        <div class="mt-3 text-sm">
            <div class="text-gray-500 mb-1">Current sheet:</div>
            <div id="currentSheet" class="font-medium">-</div>
        </div>

        <div class="mt-3 text-sm">
            <div class="text-gray-500 mb-1">Last message:</div>
            <div id="lastMsg" class="whitespace-pre-wrap">-</div>

            <div id="lastErrBox" class="hidden mt-2 px-3 py-2 rounded bg-red-50 text-red-700 text-xs whitespace-pre-wrap"></div>
        </div>

        <div class="mt-4">
            <div class="text-sm font-semibold mb-2">History (latest)</div>
            <div id="history" class="space-y-2 text-xs"></div>
        </div>
    </div>
</div>

<script>
(function () {
    // runId from session if available
    const runIdFromSession = @json(session('run_id') ?? null);
    // fallback to latestRun if you want auto-show last run:
    const latestRunId = @json(optional($latestRun)->id);

    let runId = runIdFromSession || latestRunId || null;
    let timer = null;

    const $ = (id) => document.getElementById(id);

    function setPill(status) {
        const pill = $('runStatusPill');
        pill.textContent = status || 'Idle';
        pill.className = 'text-xs px-2 py-1 rounded';

        if (status === 'running') pill.className += ' bg-blue-100 text-blue-700';
        else if (status === 'done') pill.className += ' bg-green-100 text-green-700';
        else if (status === 'failed') pill.className += ' bg-red-100 text-red-700';
        else pill.className += ' bg-gray-100 text-gray-700';
    }

    function renderHistory(events) {
        const box = $('history');
        box.innerHTML = '';
        (events || []).forEach(ev => {
            const div = document.createElement('div');
            div.className = 'border rounded p-2 bg-gray-50';
            div.innerHTML = `
                <div class="flex justify-between gap-2">
                    <div class="font-semibold">${ev.type || '-'}</div>
                    <div class="text-gray-500">${ev.time || ''}</div>
                </div>
                <div class="text-gray-700 mt-1">
                    ${ev.gsheet_name ? `<div><b>Sheet:</b> ${ev.gsheet_name} / ${ev.sheet_name || ''}</div>` : ''}
                    ${ev.batch_no ? `<div><b>Batch:</b> ${ev.batch_no} (${ev.start_row || '-'} → ${ev.end_row || '-'}) rows=${ev.rows ?? '-'}</div>` : ''}
                    ${(ev.imported != null || ev.not_existing != null || ev.skipped != null) ? `<div><b>Counts:</b> imported=${ev.imported ?? 0}, notExisting=${ev.not_existing ?? 0}, skipped=${ev.skipped ?? 0}</div>` : ''}
                    ${ev.message ? `<div class="text-gray-500 mt-1">${ev.message}</div>` : ''}
                </div>
            `;
            box.appendChild(div);
        });
    }

    async function poll() {
        if (!runId) return;

        try {
            const res = await fetch(`{{ url('/botcake/psid/import/status') }}/${runId}`, {
                headers: { 'Accept': 'application/json' }
            });

            if (!res.ok) return;
            const data = await res.json();

            $('runId').textContent = data.id ?? '-';
            $('cutoff').textContent = data.cutoff_datetime ?? '-';
            $('k1').textContent = data.k1_value ?? '-';
            $('seedRow').textContent = data.seed_row ?? '-';
            $('startRow').textContent = data.selected_start_row ?? '-';
            $('nextScan').textContent = data.next_scan_from ?? '-';

            $('batchNo').textContent = data.batch_no ?? '-';
            $('batchRange').textContent = (data.batch_start_row && data.batch_end_row) ? `${data.batch_start_row} → ${data.batch_end_row}` : '-';

            $('totalImported').textContent = data.total_imported ?? 0;
            $('totalNotExisting').textContent = data.total_not_existing ?? 0;
            $('totalSkipped').textContent = data.total_skipped ?? 0;
            $('updatedAt').textContent = data.updated_at ?? '-';

            $('currentSheet').textContent =
                (data.current_gsheet_name || data.current_sheet_name)
                ? `${data.current_gsheet_name || ''} ${data.current_sheet_name ? ' / ' + data.current_sheet_name : ''}`
                : '-';

            $('lastMsg').textContent = data.last_message ?? '-';

            if (data.last_error) {
                $('lastErrBox').classList.remove('hidden');
                $('lastErrBox').textContent = data.last_error;
            } else {
                $('lastErrBox').classList.add('hidden');
                $('lastErrBox').textContent = '';
            }

            setPill(data.status);
            renderHistory(data.events || []);

            // stop polling if done/failed
            if (data.status === 'done' || data.status === 'failed') {
                clearInterval(timer);
                timer = null;
            }
        } catch (e) {
            // ignore fetch errors (won't break UI)
        }
    }

    function startPolling() {
        if (!runId) return;
        poll();
        if (!timer) timer = setInterval(poll, 1500);
    }

    startPolling();
})();
</script>

</body>
</html>

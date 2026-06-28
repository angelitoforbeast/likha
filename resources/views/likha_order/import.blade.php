<x-layout>
    <x-slot name="heading">Likha Order Import</x-slot>

    <div class="bg-white p-6 rounded shadow-md w-full max-w-6xl mx-auto mt-6">

        {{-- ✅ Global last import always visible --}}
        <div class="mb-5 rounded border bg-gray-50 p-4 text-sm">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                <div class="text-gray-700">
                    <div>
                        <span class="font-semibold">Last attempt:</span>
                        <span id="lastAttemptText">
                            @if(!empty($lastAttemptRun))
                                {{ optional($lastAttemptRun->started_at)->toDateTimeString() }}
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
                                {{ optional($lastSuccessRun->finished_at)->toDateTimeString() }}
                            @else
                                -
                            @endif
                        </span>
                    </div>
                </div>

                <div class="text-xs text-gray-600">
                    <div class="font-semibold text-gray-700 mb-1">Run message</div>
                    <div id="runStatusText">
                        @if(!empty($lastAttemptRun) && $lastAttemptRun->message)
                            {{ $lastAttemptRun->message }}
                        @else
                            -
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <p class="text-gray-700 text-center mb-6">
            Click the button below to import data from <strong>ALL configured sheets</strong>.
        </p>

        <div class="overflow-x-auto mb-4">
            <table class="w-full table-auto border text-sm" id="settingsTable">
                <thead class="bg-gray-100">
                    <tr>
                        <th class="border px-3 py-2 text-left">#</th>
                        <th class="border px-3 py-2 text-left">Spreadsheet Title</th>
                        <th class="border px-3 py-2 text-left">Sheet URL</th>
                        <th class="border px-3 py-2 text-left">Range</th>

                        {{-- ✅ new column --}}
                        <th class="border px-3 py-2 text-left">Last Imported</th>

                        <th class="border px-3 py-2 text-left">Status</th>
                        <th class="border px-3 py-2 text-left">Processed</th>
                        <th class="border px-3 py-2 text-left">Inserted</th>
                        <th class="border px-3 py-2 text-left">Updated</th>
                        <th class="border px-3 py-2 text-left">Skipped</th>
                        <th class="border px-3 py-2 text-left">Message</th>
                        <th class="border px-3 py-2 text-left">Action</th>
                    </tr>
                </thead>

                <tbody>
                    @foreach($settings as $index => $s)
                        {{-- Archived rows greyed out — they stay visible so admin
                             knows they exist, but won't be picked up by the import
                             job (filtered in start()). Manage at /likha_order_import/settings. --}}
                        <tr data-setting-id="{{ $s->id }}"
                            class="{{ ($s->is_archived ?? false) ? 'bg-gray-100 text-gray-500' : '' }}">
                            <td class="border px-3 py-2">{{ $index + 1 }}</td>
                            <td class="border px-3 py-2 titleCell">
                                {{ $s->spreadsheet_title ?? '-' }}
                                @if($s->is_archived ?? false)
                                    <span class="ml-2 inline-block px-1.5 py-0.5 text-[10px] font-bold uppercase
                                                 bg-amber-200 text-amber-900 border border-amber-300 rounded"
                                          title="This sheet is archived — skipped on import. Unarchive at /likha_order_import/settings.">
                                        📦 Archived
                                    </span>
                                @endif
                            </td>

                            <td class="border px-3 py-2 urlCell">
                                @if($s->sheet_url)
                                    <a href="{{ $s->sheet_url }}" target="_blank" class="text-blue-600 underline">
                                        Open
                                    </a>
                                @else
                                    -
                                @endif
                            </td>

                            <td class="border px-3 py-2 rangeCell">{{ $s->range }}</td>

                            {{-- ✅ last imported from DB (won't disappear on refresh).
                                 Now reflects last run that actually processed data
                                 (processed_count > 0), not just last attempt. --}}
                            <td class="border px-3 py-2 lastImportedCell text-xs text-gray-700">
                                @php
                                    $ts = $lastImportedMap[$s->id] ?? null;
                                    $daysSince = $ts ? \Carbon\Carbon::parse($ts)->diffInDays(now()) : null;
                                @endphp
                                @if($ts)
                                    {{ \Carbon\Carbon::parse($ts)->toDateTimeString() }}
                                    @if($daysSince >= 14)
                                        {{-- Stale: no actual data in 14+ days. Candidate for archive. --}}
                                        <div class="mt-1 inline-block px-1.5 py-0.5 rounded text-[10px] font-bold uppercase
                                                    bg-amber-100 text-amber-800 border border-amber-300"
                                             title="No new data processed in {{ $daysSince }} days — consider archiving">
                                            ⚠ Stale {{ $daysSince }}d
                                        </div>
                                    @endif
                                @else
                                    <span class="inline-block px-1.5 py-0.5 rounded text-[10px] font-bold uppercase
                                                 bg-gray-200 text-gray-600 border border-gray-300"
                                          title="No import run has ever processed data from this sheet">
                                        🚫 Never
                                    </span>
                                @endif
                            </td>

                            <td class="border px-3 py-2 statusCell">
                                @if($s->is_archived ?? false)
                                    {{-- Archived sheets never enter the import job, so their
                                         Status stays "Archived" instead of "Idle". --}}
                                    <span class="px-2 py-1 rounded bg-amber-100 text-amber-800 font-semibold">Archived</span>
                                @else
                                    <span class="px-2 py-1 rounded bg-gray-100 text-gray-700">Idle</span>
                                @endif
                            </td>
                            <td class="border px-3 py-2 processedCell">0</td>
                            <td class="border px-3 py-2 insertedCell">0</td>
                            <td class="border px-3 py-2 updatedCell">0</td>
                            <td class="border px-3 py-2 skippedCell">0</td>
                            <td class="border px-3 py-2 messageCell text-xs text-gray-600">-</td>
                            <td class="border px-3 py-2">
                                @if(!($s->is_archived ?? false))
                                    <button class="sheet-import-btn bg-blue-600 text-white text-xs font-semibold px-3 py-1 rounded hover:bg-blue-700 whitespace-nowrap"
                                            data-setting-id="{{ $s->id }}">🔄 Import</button>
                                @else
                                    <span class="text-gray-400 text-xs">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="flex gap-3">
            <button id="runImportBtn"
                class="bg-blue-600 text-white font-semibold px-6 py-3 rounded hover:bg-blue-700 transition w-full">
                🔄 Run Import Now
            </button>
        </div>

        <div class="mt-6 flex justify-center gap-6">
            <a href="/likha_order_import/settings"
               class="text-sm text-blue-600 underline hover:text-blue-800">
                ⚙️ Edit GSheet Settings
            </a>

            <a href="/likha_order/view"
               class="text-sm text-green-600 underline hover:text-green-800">
                📄 View Imported Data
            </a>
        </div>
    </div>

    <script>
        let currentRunId = null;
        let pollTimer = null;

        // I-disable/enable lahat ng import controls (bulk + per-sheet) habang tumatakbo.
        function setImportButtonsDisabled(disabled) {
            const g = document.getElementById('runImportBtn');
            if (g) g.disabled = disabled;
            document.querySelectorAll('.sheet-import-btn').forEach(b => b.disabled = disabled);
        }

        function badge(status) {
            const map = {
                queued:      ['Queued',     'bg-gray-100 text-gray-700'],
                fetching:    ['Fetching',   'bg-yellow-100 text-yellow-800'],
                processing:  ['Processing', 'bg-blue-100 text-blue-800'],
                writing:     ['Writing',    'bg-indigo-100 text-indigo-800'],
                done:        ['Done',       'bg-green-100 text-green-800'],
                failed:      ['Failed',     'bg-red-100 text-red-800'],
            };
            const v = map[status] || [status, 'bg-gray-100 text-gray-700'];
            return `<span class="px-2 py-1 rounded ${v[1]}">${v[0]}</span>`;
        }

        function updateGlobal(run) {
            if (!run) return;

            const lastAttemptText = document.getElementById('lastAttemptText');
            const lastSuccessText = document.getElementById('lastSuccessText');
            const runStatusText   = document.getElementById('runStatusText');

            if (run.started_at) {
                lastAttemptText.innerHTML = `
                    ${run.started_at}
                    <span class="ml-2 px-2 py-0.5 rounded bg-gray-200 text-gray-700 text-xs">
                        ${(run.status || 'UNKNOWN').toUpperCase()}
                    </span>
                `;
            }

            runStatusText.textContent = run.message ? run.message : '-';

            if (run.status === 'done' && run.finished_at) {
                lastSuccessText.textContent = run.finished_at;
            }
        }

        function updateRow(item) {
            const tr = document.querySelector(`tr[data-setting-id="${item.setting_id}"]`);
            if (!tr) return;

            tr.querySelector('.statusCell').innerHTML = badge(item.status);
            tr.querySelector('.processedCell').textContent = item.processed ?? 0;
            tr.querySelector('.insertedCell').textContent = item.inserted ?? 0;
            tr.querySelector('.updatedCell').textContent = item.updated ?? 0;
            tr.querySelector('.skippedCell').textContent = item.skipped ?? 0;
            tr.querySelector('.messageCell').textContent = item.message ? item.message : '-';

            if (item.spreadsheet_title) {
                tr.querySelector('.titleCell').textContent = item.spreadsheet_title;
            }

            // ✅ update last imported real-time when done — BUT only if the
            // run actually processed data. Done-with-zero-processed runs no
            // longer refresh the cell (matches the server-side filter in
            // LikhaOrderImportController::index() which only counts processed
            // > 0 runs as "last imported").
            if (item.status === 'done' && item.finished_at && (item.processed ?? 0) > 0) {
                tr.querySelector('.lastImportedCell').textContent = item.finished_at;
            }
        }

        async function pollStatus() {
            if (!currentRunId) return;

            const res = await fetch(`/likha_order_import/status?run_id=${currentRunId}`, {
                headers: { 'Accept': 'application/json' }
            });
            const data = await res.json();

            if (data.run) updateGlobal(data.run);
            (data.sheets || []).forEach(updateRow);

            if (data.run && (data.run.status === 'done' || data.run.status === 'failed')) {
                clearInterval(pollTimer);
                pollTimer = null;
                setImportButtonsDisabled(false);
                document.getElementById('runImportBtn').textContent = '🔄 Run Import Now';
                document.querySelectorAll('.sheet-import-btn').forEach(b => b.textContent = '🔄 Import');
            }
        }

        async function startImport() {
            if (pollTimer) { alert('May import na tumatakbo — hintayin matapos.'); return; }
            const btn = document.getElementById('runImportBtn');
            setImportButtonsDisabled(true);
            btn.textContent = '⏳ Starting import...';

            // reset run UI (✅ DO NOT reset lastImportedCell)
            document.querySelectorAll('#settingsTable tbody tr').forEach(tr => {
                tr.querySelector('.statusCell').innerHTML = badge('queued');
                tr.querySelector('.processedCell').textContent = '0';
                tr.querySelector('.insertedCell').textContent = '0';
                tr.querySelector('.updatedCell').textContent = '0';
                tr.querySelector('.skippedCell').textContent = '0';
                tr.querySelector('.messageCell').textContent = '-';
            });

            const res = await fetch('/likha_order_import/start', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                },
                body: JSON.stringify({})
            });

            const data = await res.json();
            if (!data.ok) {
                setImportButtonsDisabled(false);
                btn.textContent = '🔄 Run Import Now';
                alert('Failed to start import');
                return;
            }

            currentRunId = data.run_id;
            btn.textContent = '⏳ Import running...';

            await pollStatus();
            pollTimer = setInterval(pollStatus, 1200);
        }

        // Manual import ng IISANG gsheet (Option A) — reuse ng status polling.
        async function startOneImport(settingId, btn) {
            if (pollTimer) { alert('May import na tumatakbo — hintayin matapos.'); return; }

            const tr = document.querySelector(`tr[data-setting-id="${settingId}"]`);
            if (tr) {
                tr.querySelector('.statusCell').innerHTML = badge('queued');
                tr.querySelector('.processedCell').textContent = '0';
                tr.querySelector('.insertedCell').textContent = '0';
                tr.querySelector('.updatedCell').textContent = '0';
                tr.querySelector('.skippedCell').textContent = '0';
                tr.querySelector('.messageCell').textContent = '-';
            }

            setImportButtonsDisabled(true);
            if (btn) btn.textContent = '⏳';

            try {
                const res = await fetch(`/likha_order_import/${settingId}/start`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    },
                    body: JSON.stringify({})
                });
                const data = await res.json();
                if (!data.ok) {
                    setImportButtonsDisabled(false);
                    if (btn) btn.textContent = '🔄 Import';
                    alert('Failed to start import');
                    return;
                }
                currentRunId = data.run_id;
                await pollStatus();
                pollTimer = setInterval(pollStatus, 1200);
            } catch (e) {
                setImportButtonsDisabled(false);
                if (btn) btn.textContent = '🔄 Import';
                alert('Network error');
            }
        }

        document.getElementById('runImportBtn').addEventListener('click', startImport);
        document.querySelectorAll('.sheet-import-btn').forEach(b => {
            b.addEventListener('click', () => startOneImport(b.dataset.settingId, b));
        });
    </script>
</x-layout>

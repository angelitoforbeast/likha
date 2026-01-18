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
                    </tr>
                </thead>

                <tbody>
                    @foreach($settings as $index => $s)
                        <tr data-setting-id="{{ $s->id }}">
                            <td class="border px-3 py-2">{{ $index + 1 }}</td>
                            <td class="border px-3 py-2 titleCell">{{ $s->spreadsheet_title ?? '-' }}</td>

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

                            {{-- ✅ last imported from DB (won’t disappear on refresh) --}}
                            <td class="border px-3 py-2 lastImportedCell text-xs text-gray-700">
                                @php $ts = $lastImportedMap[$s->id] ?? null; @endphp
                                @if($ts)
                                    {{ \Carbon\Carbon::parse($ts)->toDateTimeString() }}
                                @else
                                    -
                                @endif
                            </td>

                            <td class="border px-3 py-2 statusCell">
                                <span class="px-2 py-1 rounded bg-gray-100 text-gray-700">Idle</span>
                            </td>
                            <td class="border px-3 py-2 processedCell">0</td>
                            <td class="border px-3 py-2 insertedCell">0</td>
                            <td class="border px-3 py-2 updatedCell">0</td>
                            <td class="border px-3 py-2 skippedCell">0</td>
                            <td class="border px-3 py-2 messageCell text-xs text-gray-600">-</td>
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

            // ✅ update last imported real-time when done
            if (item.status === 'done' && item.finished_at) {
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
                document.getElementById('runImportBtn').disabled = false;
                document.getElementById('runImportBtn').textContent = '🔄 Run Import Now';
            }
        }

        async function startImport() {
            const btn = document.getElementById('runImportBtn');
            btn.disabled = true;
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
                btn.disabled = false;
                btn.textContent = '🔄 Run Import Now';
                alert('Failed to start import');
                return;
            }

            currentRunId = data.run_id;
            btn.textContent = '⏳ Import running...';

            await pollStatus();
            pollTimer = setInterval(pollStatus, 1200);
        }

        document.getElementById('runImportBtn').addEventListener('click', startImport);
    </script>
</x-layout>

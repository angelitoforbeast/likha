<x-layout>
    <x-slot name="heading">Likha Order Import</x-slot>

    <div class="bg-white p-6 rounded shadow-md w-full max-w-5xl mx-auto mt-6">
        <p class="text-gray-700 text-center mb-6">
            Click the button below to import data from <strong>ALL configured sheets</strong>.
        </p>

        {{-- Table of GSheet settings --}}
        <div class="overflow-x-auto mb-4">
            <table class="w-full table-auto border text-sm">
                <thead class="bg-gray-100">
                    <tr>
                        <th class="border px-3 py-2 text-left">#</th>
                        <th class="border px-3 py-2 text-left">Sheet ID</th>
                        <th class="border px-3 py-2 text-left">Range</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($settings as $index => $s)
                        <tr>
                            <td class="border px-3 py-2">{{ $index + 1 }}</td>
                            <td class="border px-3 py-2">{{ $s->sheet_id }}</td>
                            <td class="border px-3 py-2">{{ $s->range }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <form id="importForm" action="{{ url('/likha_order_import') }}" method="POST" onsubmit="return startImport()">
            @csrf
            <button type="submit"
                class="bg-blue-600 text-white font-semibold px-6 py-3 rounded hover:bg-blue-700 transition w-full">
                🔄 Run Import Now
            </button>
        </form>

        <div id="statusMessage" class="mt-4 hidden p-3 rounded bg-yellow-100 text-yellow-800 font-medium text-center">
            ⏳ Importing rows... Please wait.
        </div>

        <div id="doneMessage" class="mt-4 hidden p-3 rounded bg-green-100 text-green-800 font-medium text-center">
            ✅ Import complete!
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
        function startImport() {
            document.getElementById('statusMessage').classList.remove('hidden');
            document.getElementById('doneMessage').classList.add('hidden');
            return true;
        }

        function checkImportStatus() {
            fetch('{{ url("/likha_order_import") }}', {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(res => res.json())
            .then(data => {
                const statusDiv = document.getElementById('statusMessage');
                const doneDiv = document.getElementById('doneMessage');

                if (data.is_complete) {
                    statusDiv.classList.add('hidden');
                    doneDiv.classList.remove('hidden');
                } else {
                    statusDiv.classList.remove('hidden');
                    doneDiv.classList.add('hidden');
                }
            });
        }

        setInterval(checkImportStatus, 5000);
        checkImportStatus();
    </script>
</x-layout>

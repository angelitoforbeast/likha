<x-layout>
    <x-slot name="heading">Likha Order Import</x-slot>

    <div class="bg-white p-6 rounded shadow-md w-full max-w-xl mx-auto mt-6 text-center">
        <div class="mb-4 text-left">
            <p class="text-sm text-gray-700">📄 <strong>Sheet ID:</strong> {{ $setting->sheet_id ?? 'Not Set' }}</p>
            <p class="text-sm text-gray-700">📌 <strong>Range:</strong> {{ $setting->range ?? 'Not Set' }}</p>
        </div>

        <p class="mb-4 text-gray-700">
            Click the button below to import data from the Google Sheet to the <strong>Likha Orders</strong> table.
        </p>

        <form id="importForm" action="{{ url('/likha_order_import') }}" method="POST" onsubmit="return startImport()">
            @csrf
            <button type="submit"
                class="bg-blue-600 text-white font-semibold px-6 py-3 rounded hover:bg-blue-700 transition">
                🔄 Run Import Now
            </button>
        </form>

        {{-- Live Status --}}
        <div id="statusMessage" class="mt-4 hidden p-3 rounded bg-yellow-100 text-yellow-800 font-medium">
            ⏳ Importing rows... Please wait.
        </div>

        <div id="doneMessage" class="mt-4 hidden p-3 rounded bg-green-100 text-green-800 font-medium">
            ✅ Import complete!
        </div>

        {{-- Settings & View Links --}}
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

    {{-- Script for live status --}}
    <script>
        function startImport() {
            document.getElementById('statusMessage').classList.remove('hidden');
            document.getElementById('doneMessage').classList.add('hidden');
            return true;
        }

        function checkImportStatus() {
            fetch('{{ url("/likha_order_import") }}', {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
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
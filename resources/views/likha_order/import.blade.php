<x-layout>
    <x-slot name="heading">Likha Order Import</x-slot>
    <x-slot name="heading">Likha Order Import</x-slot>

    <div class="bg-white p-6 rounded shadow-md w-full max-w-5xl mx-auto mt-6">
        @if (session('import_message'))
    <div class="mb-4 p-3 rounded bg-green-100 text-green-800 font-semibold text-center">
        {{ session('import_message') }}
    </div>
@endif
@if (session('import_status'))
    <div class="my-2 text-sm text-blue-600 font-semibold">
        {{ session('import_status') }}
    </div>
@endif


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
    // Optional: Magpakita agad ng message habang naglo-load
    document.querySelector('form button[type="submit"]').disabled = true;
    return true;
}

    </script>
</x-layout>

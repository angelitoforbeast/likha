<x-layout>
    <x-slot name="heading">Import from Google Sheet</x-slot>

    <div class="bg-white p-6 rounded shadow-md w-full max-w-xl mx-auto mt-6 text-center">
        <div class="mb-4 text-left">
            <p class="text-sm text-gray-700">📝 <strong>Current Sheet ID:</strong> {{ $setting->sheet_id ?? 'Not Set' }}</p>
            <p class="text-sm text-gray-700">📌 <strong>Range:</strong> {{ $setting->range ?? 'Not Set' }}</p>
        </div>

        <p class="mb-4 text-gray-700">
            Click the button below to import data from your Google Sheet to the database.
        </p>

        <form action="{{ url('/import_gsheet') }}" method="POST" onsubmit="return confirm('Proceed with import?')">
            @csrf
            <button type="submit"
                class="bg-blue-600 text-white font-semibold px-6 py-3 rounded hover:bg-blue-700 transition">
                Run GSheet Import
            </button>
        </form>

        <a href="/import_gsheet/settings"
           class="inline-block mt-4 text-sm text-blue-600 underline hover:text-blue-800">
            ⚙️ Edit GSheet Settings
        </a>

        @if(session('status'))
            <div class="mt-4 p-3 rounded bg-green-100 text-green-800">
                {{ session('status') }}
            </div>
        @endif
    </div>
</x-layout>

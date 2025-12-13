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

        {{-- Button to Settings page --}}
        <a href="{{ route('botcake.psid.settings') }}"
           class="bg-gray-200 hover:bg-gray-300 text-gray-800 text-sm px-4 py-2 rounded">
            Go to Settings
        </a>
    </div>

    {{-- Alerts --}}
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

    {{-- Info --}}
    <div class="mb-5 bg-white p-4 rounded shadow text-sm text-gray-700">
        <p class="mb-2">
            The import job will:
        </p>
        <ul class="list-disc ml-5 space-y-1">
            <li>Read the configured Google Sheets in <strong>Botcake PSID settings</strong>.</li>
            <li>Use <strong>PAGE (col A) + FULL NAME (col B)</strong> to find matching rows in
                <code>macro_output</code> (<code>PAGE</code> and <code>fb_name</code>).
            </li>
            <li>Update <code>botcake_psid</code> with the value from column <strong>C</strong>.</li>
            <li>Write <code>imported</code> or <code>not existing</code> into column <strong>J</strong>.</li>
            <li>Use cell <strong>K1</strong> as starting row number (default 2 if blank).</li>
            <li>OPTIONAL: Filter by <strong>DATE (column D)</strong> on or before the cutoff you set below.</li>
        </ul>
    </div>

    {{-- Show active settings --}}
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

    {{-- Import form --}}
    <form method="POST" action="{{ route('botcake.psid.import.run') }}" class="space-y-4">
        @csrf

        {{-- Cutoff datetime --}}
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
                Column D sample format: <code>18:55:38 08-12-2025</code> (H:i:s d-m-Y).  
                If left blank, all rows (from K1 downward) will be eligible for import.
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
</div>

</body>
</html>

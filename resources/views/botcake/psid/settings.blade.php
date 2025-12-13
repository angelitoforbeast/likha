<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Botcake PSID – Google Sheet Settings</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 text-gray-900">

<div class="max-w-4xl mx-auto p-6">
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-2xl font-semibold">Botcake PSID – Google Sheet Settings</h1>

        {{-- Button to Import page --}}
        <a href="{{ route('botcake.psid.import') }}"
           class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm px-4 py-2 rounded">
            Go to Import
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

    {{-- Add setting --}}
    <form method="POST" action="{{ route('botcake.psid.settings.store') }}" class="mb-8 space-y-3 bg-white p-4 rounded shadow">
        @csrf

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">
                Sheet URL
            </label>
            <input type="text"
                   name="sheet_url"
                   class="border rounded w-full px-3 py-1.5 text-sm"
                   placeholder="https://docs.google.com/spreadsheets/d/..."
                   required>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">
                Sheet Range (e.g. <code>PSID!A:J</code>)
            </label>
            <input type="text"
                   name="sheet_range"
                   class="border rounded w-full px-3 py-1.5 text-sm"
                   placeholder="PSID!A:J"
                   required>
        </div>

        <button type="submit"
                class="bg-blue-600 hover:bg-blue-700 text-white text-sm px-4 py-2 rounded">
            Add Setting
        </button>
    </form>

    {{-- Existing settings --}}
    <h2 class="text-lg font-semibold mb-3">Existing Settings</h2>

    <div class="bg-white rounded shadow overflow-hidden">
        <table class="w-full text-xs border-collapse">
            <thead>
            <tr class="bg-gray-100 text-gray-700">
                <th class="border px-2 py-1 text-left">ID</th>
                <th class="border px-2 py-1 text-left">Sheet Name</th>
                <th class="border px-2 py-1 text-left">URL</th>
                <th class="border px-2 py-1 text-left">Range</th>
                <th class="border px-2 py-1 text-left w-32">Actions</th>
            </tr>
            </thead>
            <tbody>
            @forelse($settings as $s)
                <tr class="hover:bg-gray-50">
                    <td class="border px-2 py-1">{{ $s->id }}</td>
                    <td class="border px-2 py-1">{{ $s->gsheet_name }}</td>
                    <td class="border px-2 py-1 max-w-xs truncate">
                        <a href="{{ $s->sheet_url }}" target="_blank" class="text-blue-600 underline">
                            {{ $s->sheet_url }}
                        </a>
                    </td>
                    <td class="border px-2 py-1">{{ $s->sheet_range }}</td>
                    <td class="border px-2 py-1">
                        <div class="flex gap-1">
                            {{-- Resync name (keeps same URL & range) --}}
                            <form method="POST" action="{{ route('botcake.psid.settings.update', $s->id) }}">
                                @csrf
                                <input type="hidden" name="sheet_url" value="{{ $s->sheet_url }}">
                                <input type="hidden" name="sheet_range" value="{{ $s->sheet_range }}">
                                <button class="text-blue-600 text-xs hover:underline" type="submit">
                                    Resync name
                                </button>
                            </form>

                            {{-- Delete --}}
                            <form method="POST"
                                  action="{{ route('botcake.psid.settings.delete', $s->id) }}"
                                  onsubmit="return confirm('Delete this setting?');">
                                @csrf
                                @method('DELETE')
                                <button class="text-red-600 text-xs hover:underline" type="submit">
                                    Delete
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="border px-3 py-3 text-center text-gray-500 text-sm">
                        No settings yet. Add one above.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

</body>
</html>

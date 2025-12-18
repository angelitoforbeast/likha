<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>JNT Chatblast • GSheet Settings</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 text-gray-900">
  <div class="max-w-4xl mx-auto p-6 space-y-4">

    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-semibold">JNT Chatblast • Google Sheet Settings</h1>

      <a href="{{ route('jnt.status') }}"
         class="bg-gray-900 hover:bg-black text-white text-sm px-4 py-2 rounded">
        ← Back to JNT Status
      </a>
    </div>

    @if(session('success'))
      <div class="px-4 py-2 rounded bg-green-100 text-green-800 text-sm">{{ session('success') }}</div>
    @endif
    @if(session('error'))
      <div class="px-4 py-2 rounded bg-red-100 text-red-800 text-sm">{{ session('error') }}</div>
    @endif

    <div class="bg-white p-4 rounded shadow text-sm text-gray-700">
      <div class="font-semibold mb-1">Active setting (used by Export button):</div>
      <div>
        @if($active)
          <div><b>Sheet:</b> {{ $active->gsheet_name ?: '-' }}</div>
          <div><b>Range:</b> {{ $active->sheet_range }}</div>
          <div class="truncate"><b>URL:</b> <a class="text-blue-600 underline" target="_blank" href="{{ $active->sheet_url }}">{{ $active->sheet_url }}</a></div>
        @else
          <span class="text-red-600">No setting yet.</span>
        @endif
      </div>
    </div>

    <form method="POST" action="{{ route('jnt.chatblast.gsheet.settings.store') }}"
          class="bg-white p-4 rounded shadow space-y-3">
      @csrf

      <div>
        <label class="block text-sm font-medium mb-1">Sheet URL</label>
        <input name="sheet_url"
               class="border rounded w-full px-3 py-2 text-sm"
               placeholder="https://docs.google.com/spreadsheets/d/...."
               required>
      </div>

      <div>
        <label class="block text-sm font-medium mb-1">Sheet Range (append range)</label>
        <input name="sheet_range"
               class="border rounded w-full px-3 py-2 text-sm"
               placeholder="Export!A:K"
               required>
        <div class="text-xs text-gray-500 mt-1">
          Tip: gumawa ka ng tab (sheet) na “Export”, lagay headers sa row 1, then use <b>Export!A:K</b>
        </div>
      </div>

      <button class="bg-blue-600 hover:bg-blue-700 text-white text-sm px-4 py-2 rounded">
        Add Setting
      </button>
    </form>

    <div class="bg-white rounded shadow overflow-hidden">
      <table class="w-full text-xs">
        <thead class="bg-gray-100 text-gray-700">
          <tr>
            <th class="border px-2 py-2 text-left">ID</th>
            <th class="border px-2 py-2 text-left">Sheet Name</th>
            <th class="border px-2 py-2 text-left">URL</th>
            <th class="border px-2 py-2 text-left">Range</th>
            <th class="border px-2 py-2 text-left w-24">Action</th>
          </tr>
        </thead>
        <tbody>
          @forelse($settings as $s)
            <tr class="hover:bg-gray-50">
              <td class="border px-2 py-2">{{ $s->id }}</td>
              <td class="border px-2 py-2">{{ $s->gsheet_name ?: '-' }}</td>
              <td class="border px-2 py-2 max-w-xs truncate">
                <a class="text-blue-600 underline" target="_blank" href="{{ $s->sheet_url }}">{{ $s->sheet_url }}</a>
              </td>
              <td class="border px-2 py-2">{{ $s->sheet_range }}</td>
              <td class="border px-2 py-2">
                <form method="POST"
                      action="{{ route('jnt.chatblast.gsheet.settings.delete', $s->id) }}"
                      onsubmit="return confirm('Delete this setting?');">
                  @csrf
                  @method('DELETE')
                  <button class="text-red-600 hover:underline text-xs">Delete</button>
                </form>
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="5" class="border px-3 py-4 text-center text-gray-500">No settings yet.</td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>

  </div>
</body>
</html>

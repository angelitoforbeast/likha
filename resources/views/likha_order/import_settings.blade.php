<x-layout>
  <x-slot name="heading">Likha Order Import Settings</x-slot>

  <div class="bg-white p-6 rounded shadow-md w-full max-w-5xl mx-auto mt-6">
    @if(session('status'))
      <div class="mb-4 p-3 rounded bg-green-100 text-green-800 font-semibold text-center">
        {{ session('status') }}
      </div>
    @endif

    <form method="POST" action="/likha_order_import/settings" class="grid grid-cols-1 gap-3 mb-6">
      @csrf
      <input name="sheet_url" class="border rounded p-2" placeholder="Google Sheet URL" required>
      <input
  name="range"
  class="border rounded p-2"
  value="{{ old('range', 'TO WEBSITE!A2:I') }}"
  placeholder="TO WEBSITE!A2:I"
  required
>

      <button class="bg-blue-600 text-white px-4 py-2 rounded">Add Setting</button>
    </form>

    <div class="overflow-x-auto">
      <table class="w-full table-auto border text-sm">
        <thead class="bg-gray-100">
          <tr>
            <th class="border px-3 py-2">Title</th>
            <th class="border px-3 py-2">URL</th>
            <th class="border px-3 py-2">Sheet ID</th>
            <th class="border px-3 py-2">Range</th>
            <th class="border px-3 py-2">Actions</th>
          </tr>
        </thead>
        <tbody>
          @foreach($settings as $s)
            <tr>
              <td class="border px-3 py-2">{{ $s->spreadsheet_title ?? '-' }}</td>
              <td class="border px-3 py-2">
                @if($s->sheet_url)
                  <a href="{{ $s->sheet_url }}" target="_blank" class="text-blue-600 underline">Open</a>
                @else
                  -
                @endif
              </td>
              <td class="border px-3 py-2">{{ $s->sheet_id }}</td>
              <td class="border px-3 py-2">{{ $s->range }}</td>
              <td class="border px-3 py-2">
                {{-- ikaw bahala UI for edit; important is update route uses PUT --}}
                <form method="POST" action="/likha_order_import/settings/{{ $s->id }}" onsubmit="return confirm('Delete?')" class="inline">
                  @csrf
                  @method('DELETE')
                  <button class="text-red-600 underline">Delete</button>
                </form>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>

  </div>
</x-layout>

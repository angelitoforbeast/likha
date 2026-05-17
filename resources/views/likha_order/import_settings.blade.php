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

    {{-- Archive visibility toggle. Default hides archived rows (cleaner view);
         link with ?show_archived=1 brings them back, shown greyed + flagged. --}}
    <div class="flex items-center justify-between mb-2 text-sm">
      <div class="text-gray-600">
        Showing <strong>{{ $settings->where('is_archived', false)->count() }}</strong> active
        @if($archivedCount > 0)
          + <strong>{{ ($showArchived ?? false) ? $archivedCount : 0 }}</strong> archived
          @if(!($showArchived ?? false))
            <span class="text-gray-400">({{ $archivedCount }} hidden)</span>
          @endif
        @endif
      </div>
      <div>
        @if(($showArchived ?? false))
          <a href="/likha_order_import/settings" class="text-blue-600 hover:underline">Hide archived</a>
        @else
          @if($archivedCount > 0)
            <a href="/likha_order_import/settings?show_archived=1" class="text-blue-600 hover:underline">
              Show archived ({{ $archivedCount }})
            </a>
          @endif
        @endif
      </div>
    </div>

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
            <tr class="{{ $s->is_archived ? 'bg-gray-100 text-gray-500' : '' }}">
              <td class="border px-3 py-2">
                {{ $s->spreadsheet_title ?? '-' }}
                @if($s->is_archived)
                  <span class="ml-2 inline-block px-2 py-0.5 text-[10px] font-bold uppercase bg-amber-200 text-amber-900 rounded">📦 Archived</span>
                @endif
              </td>
              <td class="border px-3 py-2">
                @if($s->sheet_url)
                  <a href="{{ $s->sheet_url }}" target="_blank" class="text-blue-600 underline">Open</a>
                @else
                  -
                @endif
              </td>
              <td class="border px-3 py-2 font-mono text-xs">{{ $s->sheet_id }}</td>
              <td class="border px-3 py-2 font-mono text-xs">{{ $s->range }}</td>
              <td class="border px-3 py-2 space-x-2 whitespace-nowrap">
                @if($s->is_archived)
                  {{-- Archived → show Unarchive button to bring back. --}}
                  <form method="POST" action="/likha_order_import/settings/{{ $s->id }}/unarchive" class="inline">
                    @csrf
                    <button class="text-green-700 hover:underline" title="Bring back into import job">↩️ Unarchive</button>
                  </form>
                @else
                  {{-- Active → Archive (soft-skip from imports) or Delete. --}}
                  <form method="POST" action="/likha_order_import/settings/{{ $s->id }}/archive"
                        onsubmit="return confirm('Archive this sheet? It will stay visible but won\'t be imported until unarchived.')"
                        class="inline">
                    @csrf
                    <button class="text-amber-700 hover:underline" title="Skip from import job — keep config for later">📦 Archive</button>
                  </form>
                @endif
                <form method="POST" action="/likha_order_import/settings/{{ $s->id }}" onsubmit="return confirm('Delete permanently?')" class="inline">
                  @csrf
                  @method('DELETE')
                  <button class="text-red-600 hover:underline">Delete</button>
                </form>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>

  </div>
</x-layout>

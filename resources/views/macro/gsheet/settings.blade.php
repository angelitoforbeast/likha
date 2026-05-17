<x-layout>
  <x-slot name="heading">Macro GSheet Settings</x-slot>

  <div class="max-w-4xl mx-auto mt-6">
    @if(session('success'))
      <div class="bg-green-100 text-green-800 p-3 rounded mb-4">{{ session('success') }}</div>
    @endif

    @if(session('error'))
      <div class="bg-red-100 text-red-800 p-3 rounded mb-4">{{ session('error') }}</div>
    @endif

    {{-- Add New Setting --}}
    <form method="POST" action="{{ route('macro.settings.store') }}" class="space-y-4 bg-white p-4 rounded shadow mb-6">
      @csrf

      <div>
        <label class="text-sm font-medium">Google Sheet URL</label>
        <input type="url" name="sheet_url" class="w-full border rounded px-3 py-2 mt-1" required>
      </div>

      <div>
        <label class="text-sm font-medium">Range</label>
        <input
          type="text"
          name="sheet_range"
          value="DATABASE - MIRRORED!A2:Q"
          class="w-full border rounded px-3 py-2 mt-1"
          required
        >
      </div>

      <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Add Setting</button>
    </form>

    {{-- Counter + Show/Hide archived toggle --}}
    <div class="flex items-center justify-between mb-2 text-sm">
      <div class="text-gray-600">
        Showing <strong>{{ $settings->where('is_archived', false)->count() }}</strong> active
        @if(($archivedCount ?? 0) > 0)
          + <strong>{{ ($showArchived ?? false) ? $archivedCount : 0 }}</strong> archived
          @if(!($showArchived ?? false))
            <span class="text-gray-400">({{ $archivedCount }} hidden)</span>
          @endif
        @endif
      </div>
      <div>
        @if(($showArchived ?? false))
          <a href="{{ route('macro.settings') }}" class="text-blue-600 hover:underline">Hide archived</a>
        @elseif(($archivedCount ?? 0) > 0)
          <a href="{{ route('macro.settings') }}?show_archived=1" class="text-blue-600 hover:underline">
            Show archived ({{ $archivedCount }})
          </a>
        @endif
      </div>
    </div>

    {{-- Settings Table --}}
    <div class="bg-white p-4 rounded shadow">
      @if($settings->count())
        <table class="min-w-full text-sm border border-gray-300">
          <thead class="bg-gray-100">
            <tr>
              <th class="border px-4 py-2 text-left">ID</th>
              <th class="border px-4 py-2 text-left">Sheet Name</th>
              <th class="border px-4 py-2 text-left">Google Sheet URL</th>
              <th class="border px-4 py-2 text-left">Range</th>
              <th class="border px-4 py-2 text-center">Actions</th>
            </tr>
          </thead>
          <tbody>
            @foreach ($settings as $setting)
              <tr class="{{ $setting->is_archived ? 'bg-gray-100 text-gray-500' : '' }}">
                <form method="POST" action="{{ route('macro.settings.update', $setting->id) }}">
                  @csrf
                  @method('PUT')

                  <td class="border px-4 py-2">{{ $setting->id }}</td>

                  <td class="border px-4 py-2">
                    <div class="font-semibold">{{ $setting->gsheet_name ?? '-' }}</div>
                    @if($setting->is_archived)
                      <span class="mt-1 inline-block px-1.5 py-0.5 text-[10px] font-bold uppercase
                                   bg-amber-200 text-amber-900 border border-amber-300 rounded">
                        📦 Archived
                      </span>
                    @endif
                  </td>

                  <td class="border px-4 py-2">
                    <input type="text" name="sheet_url" value="{{ $setting->sheet_url }}" class="w-full border rounded px-2 py-1">
                  </td>

                  <td class="border px-4 py-2">
                    <input type="text" name="sheet_range" value="{{ $setting->sheet_range }}" class="w-full border rounded px-2 py-1">
                  </td>

                  <td class="border px-4 py-2 text-center space-x-2 whitespace-nowrap">
                    <button type="submit" class="bg-yellow-500 text-white px-3 py-1 rounded">Update</button>
                </form>

                {{-- Archive / Unarchive — replaces destructive Delete button.
                     Delete route still exists for emergency manual purge. --}}
                @if($setting->is_archived)
                  <form method="POST" action="{{ route('macro.settings.unarchive', $setting->id) }}" class="inline-block">
                    @csrf
                    <button type="submit" class="bg-green-600 text-white px-3 py-1 rounded" title="Bring back into import job">↩️ Unarchive</button>
                  </form>
                @else
                  <form method="POST" action="{{ route('macro.settings.archive', $setting->id) }}"
                        onsubmit="return confirm('Archive this sheet? It will stay visible but won\'t be imported until unarchived.')"
                        class="inline-block">
                    @csrf
                    <button type="submit" class="bg-amber-500 text-white px-3 py-1 rounded" title="Skip from import job — keep config for later">📦 Archive</button>
                  </form>
                @endif
                  </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      @else
        <p class="text-gray-600">No settings found.</p>
      @endif
    </div>
  </div>
</x-layout>

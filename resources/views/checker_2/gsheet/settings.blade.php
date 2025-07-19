<x-layout>
  <x-slot name="heading">Checker 2 GSheet Settings</x-slot>

  <div class="max-w-4xl mx-auto mt-6">
    @if(session('success'))
      <div class="bg-green-100 text-green-800 p-3 rounded mb-4">{{ session('success') }}</div>
    @endif

    @if(session('error'))
      <div class="bg-red-100 text-red-800 p-3 rounded mb-4">{{ session('error') }}</div>
    @endif

    {{-- Add New Setting --}}
    <form method="POST" action="{{ route('checker2.settings.store') }}" class="space-y-4 bg-white p-4 rounded shadow mb-6">
      @csrf
      <div>
        <label class="text-sm font-medium">Google Sheet URL</label>
        <input type="url" name="sheet_url" class="w-full border rounded px-3 py-2 mt-1" required>
      </div>
      <div>
        <label class="text-sm font-medium">Range (e.g. Sheet1!A2:Q)</label>
        <input type="text" name="sheet_range" class="w-full border rounded px-3 py-2 mt-1" required>
      </div>
      <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Add Setting</button>
    </form>

    {{-- Settings Table --}}
    <div class="bg-white p-4 rounded shadow">
      @if($settings->count())
        <table class="min-w-full text-sm border border-gray-300">
          <thead class="bg-gray-100">
            <tr>
              <th class="border px-4 py-2 text-left">ID</th>
              <th class="border px-4 py-2 text-left">Gsheet Name</th>
              <th class="border px-4 py-2 text-left">Google Sheet URL</th>
              <th class="border px-4 py-2 text-left">Range</th>
              <th class="border px-4 py-2 text-center">Actions</th>
            </tr>
          </thead>
          <tbody>
            @foreach ($settings as $setting)
              <tr>
                <form method="POST" action="{{ route('checker2.settings.update', $setting->id) }}">
                  @csrf
                  @method('PUT')
                  <td class="border px-4 py-2">{{ $setting->id }}</td>
                  <td class="border px-4 py-2">
                    <input type="text" name="gsheet_name" value="{{ $setting->gsheet_name }}" class="w-full border rounded px-2 py-1" readonly>
                  </td>
                  <td class="border px-4 py-2">
                    <input type="text" name="sheet_url" value="{{ $setting->sheet_url }}" class="w-full border rounded px-2 py-1">
                  </td>
                  <td class="border px-4 py-2">
                    <input type="text" name="sheet_range" value="{{ $setting->sheet_range }}" class="w-full border rounded px-2 py-1">
                  </td>
                  <td class="border px-4 py-2 text-center space-x-2">
                    <button type="submit" class="bg-yellow-500 text-white px-3 py-1 rounded">Update</button>
                </form>

                <form method="POST" action="{{ route('checker2.settings.delete', $setting->id) }}" class="inline-block">
                  @csrf
                  @method('DELETE')
                  <button type="submit" onclick="return confirm('Delete this setting?')" class="bg-red-600 text-white px-3 py-1 rounded">Delete</button>
                </form>
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

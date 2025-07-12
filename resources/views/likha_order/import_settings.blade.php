<x-layout>
  <x-slot name="heading">Likha Order GSheet Settings</x-slot>

  <div class="max-w-4xl mx-auto mt-6">
    @if(session('status'))
      <div class="bg-green-100 text-green-800 p-3 rounded mb-4">{{ session('status') }}</div>
    @endif

    {{-- Add New Setting --}}
    <form method="POST" action="{{ url('/likha_order_import/settings') }}" class="space-y-4 bg-white p-4 rounded shadow mb-6">
      @csrf
      <div>
        <label class="text-sm font-medium">Google Sheet ID</label>
        <input type="text" name="sheet_id" class="w-full border rounded px-3 py-2 mt-1" required>
      </div>
      <div>
        <label class="text-sm font-medium">Range (e.g. Sheet1!A2:H)</label>
        <input type="text" name="range" class="w-full border rounded px-3 py-2 mt-1" required>
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
              <th class="border px-4 py-2 text-left">Google Sheet ID</th>
              <th class="border px-4 py-2 text-left">Range</th>
              <th class="border px-4 py-2 text-center">Actions</th>
            </tr>
          </thead>
          <tbody>
            @foreach ($settings as $setting)
              <tr>
                <form method="POST" action="{{ url('/likha_order_import/settings/' . $setting->id) }}">
                  @csrf
                  @method('PUT')
                  <td class="border px-4 py-2">{{ $setting->id }}</td>
                  <td class="border px-4 py-2">
                    <input type="text" name="sheet_id" value="{{ $setting->sheet_id }}" class="w-full border rounded px-2 py-1">
                  </td>
                  <td class="border px-4 py-2">
                    <input type="text" name="range" value="{{ $setting->range }}" class="w-full border rounded px-2 py-1">
                  </td>
                  <td class="border px-4 py-2 text-center space-x-2">
                    <button type="submit" class="bg-yellow-500 text-white px-3 py-1 rounded">Update</button>
                </form>

                <form method="POST" action="{{ url('/likha_order_import/settings/' . $setting->id) }}" class="inline-block">
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

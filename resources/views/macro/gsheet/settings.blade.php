<x-layout>
  <x-slot name="heading">Google Sheet Settings</x-slot>

  @if(session('success'))
    <div class="bg-green-100 text-green-800 p-2 rounded mb-4">
      {{ session('success') }}
    </div>
  @endif

  {{-- Add New Setting --}}
  <form method="POST" action="{{ route('macro.settings.store') }}" class="mb-6">
    @csrf
    <div class="flex flex-col gap-2 max-w-xl">
      <input type="url" name="sheet_url" placeholder="Google Sheet URL" required class="border p-2 rounded">
      <input type="text" name="sheet_range" placeholder="Range (e.g., Sheet1!A1:D100)" required class="border p-2 rounded">
      <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Add Setting</button>
    </div>
  </form>

  {{-- List of Settings --}}
  <table class="table-auto w-full border text-sm">
    <thead class="bg-gray-100">
      <tr>
        <th class="border px-4 py-2">ID</th>
        <th class="border px-4 py-2">Sheet URL</th>
        <th class="border px-4 py-2">Range</th>
        <th class="border px-4 py-2">Action</th>
      </tr>
    </thead>
    <tbody>
      @foreach ($settings as $setting)
        <tr>
          <td class="border px-4 py-2 text-center">{{ $setting->id }}</td>
          <td class="border px-4 py-2">{{ $setting->sheet_url }}</td>
          <td class="border px-4 py-2">{{ $setting->sheet_range }}</td>
          <td class="border px-4 py-2 text-center">
            <form action="{{ route('macro.settings.delete', $setting->id) }}" method="POST" onsubmit="return confirm('Delete this setting?')">
              @csrf
              @method('DELETE')
              <button class="text-red-600 hover:underline">Delete</button>
            </form>
          </td>
        </tr>
      @endforeach
    </tbody>
  </table>
</x-layout>

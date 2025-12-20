<x-layout>
  <x-slot name="title">Import Macro Output</x-slot>
  <x-slot name="heading">Import From Google Sheets</x-slot>

  {{-- Flash messages --}}
  @if (session('success'))
    <div class="bg-green-100 text-green-900 p-2 rounded mb-4">
      {{ session('success') }}
    </div>
  @elseif (session('error'))
    <div class="bg-red-100 text-red-800 p-2 rounded mb-4">
      {{ session('error') }}
    </div>
  @endif

  {{-- Show active settings --}}
  <div class="mb-6">
    <h2 class="font-semibold mb-2">Active Google Sheet Settings</h2>
    <table class="table-auto w-full border text-sm">
      <thead class="bg-gray-100">
        <tr>
          <th class="border px-4 py-2">ID</th>
          <th class="border px-4 py-2">Gsheet Name</th>
          <th class="border px-4 py-2">Range</th>
        </tr>
      </thead>
      <tbody>
        @forelse ($settings as $setting)
          <tr>
            <td class="border px-4 py-1 text-center">{{ $setting->id }}</td>
            <td class="border px-4 py-1">{{ $setting->gsheet_name ?? 'N/A' }}</td>
            <td class="border px-4 py-1">{{ $setting->sheet_range }}</td>
          </tr>
        @empty
          <tr>
            <td colspan="3" class="text-center border px-4 py-2">No settings available</td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>

  {{-- Import button --}}
  <form method="POST" action="{{ url('/macro/gsheet/import') }}" onsubmit="return confirm('Are you sure you want to import?')">
    @csrf
    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">IMPORT NOW</button>
  </form>

  {{-- Auto-continue on ?continue=1 --}}
  @if (request()->has('continue'))
    <div class="text-sm text-gray-500 mt-4">
      ⏳ Processing next row...
    </div>
    <script>
      setTimeout(() => {
        window.location.href = "{{ url()->current() }}?continue=1";
      }, 1000);
    </script>
  @endif
</x-layout>

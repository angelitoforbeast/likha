<x-layout>
  <x-slot name="heading">Sender Name Mapping</x-slot>

  <div class="p-4 space-y-6">

    {{-- Paste Form --}}
    <form method="POST" action="/jnt/sender-name">
      @csrf
      <label class="block text-sm font-medium mb-1">Paste from Google Sheets (Page [Tab] Sender Name)</label>
      <textarea name="bulk_data" rows="12" class="w-full border border-gray-300 rounded p-2" placeholder="e.g.
Hanna Dela Vega	Hanna D.
Hanna Dela Vega	Hanna Shop
Jade Sarmiento	Jade S."></textarea>

      <button type="submit" class="mt-4 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">
        Save
      </button>
    </form>

    {{-- Feedback --}}
    @if(session('success'))
      <div class="text-green-600 font-medium">{{ session('success') }}</div>
    @endif

    @if(session('error'))
      <div class="text-red-600 font-medium">{{ session('error') }}</div>
    @endif
    <form method="GET" action="/jnt/sender-name" class="flex gap-2">
      <input
        type="text"
        name="search"
        value="{{ request('search') }}"
        class="border border-gray-300 rounded px-3 py-2 w-64"
        placeholder="Search PAGE or SENDER NAME..."
      />
      <button type="submit" class="bg-gray-700 text-white px-4 py-2 rounded">Search</button>
    </form>
    {{-- Table of Existing Mappings --}}
        {{-- Table of Existing Mappings --}}
    <div class="mt-8">
      <h2 class="text-lg font-semibold mb-2">Existing Mappings ({{ $mappings->total() }})</h2>
      <div class="overflow-x-auto border rounded">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-100">
            <tr>
              <th class="text-left px-4 py-2 border-b">PAGE</th>
              <th class="text-left px-4 py-2 border-b">SENDER NAME</th>
              <th class="text-left px-4 py-2 border-b">Added</th>
              <th class="text-left px-4 py-2 border-b">Action</th>
            </tr>
          </thead>
          <tbody>
            @forelse($mappings as $row)
              <tr class="border-b hover:bg-gray-50">
                <td class="px-4 py-2">{{ $row->PAGE }}</td>
                <td class="px-4 py-2">{{ $row->SENDER_NAME }}</td>
                <td class="px-4 py-2 text-gray-500">{{ \Carbon\Carbon::parse($row->created_at)->diffForHumans() }}</td>
                <td class="px-4 py-2">
                  <form method="POST" action="/jnt/sender-name/delete/{{ $row->id }}">
                    @csrf
                    <button type="submit" class="text-red-600 hover:underline text-xs">Delete</button>
                  </form>
                </td>
              </tr>
            @empty
              <tr>
                <td colspan="4" class="px-4 py-4 text-center text-gray-500">No mappings found.</td>
              </tr>
            @endforelse
          </tbody>
        </table>
      </div>

      {{-- Pagination Links --}}
      <div class="mt-4">
        {{ $mappings->withQueryString()->links() }}
      </div>
    </div>
  </div>
</x-layout>

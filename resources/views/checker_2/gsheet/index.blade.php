<x-layout>
  <x-slot name="heading">Macro Output Data</x-slot>

  @if(session('success'))
    <div class="bg-green-100 text-green-800 p-2 rounded mb-4">
      {{ session('success') }}
    </div>
  @endif

  {{-- Search Form --}}
  <form method="GET" action="{{ route('macro.index') }}" class="mb-4">
    <input 
      type="text" 
      name="search" 
      placeholder="Search..." 
      value="{{ request('search') }}"
      class="border px-2 py-1 rounded w-64"
    >
    <button type="submit" class="bg-blue-600 text-white px-3 py-1 rounded">Search</button>
  </form>

  <div class="mb-4 text-sm text-gray-700">
    <strong>Total Records:</strong> {{ $totalCount }}
  </div>

  <form method="POST" action="{{ route('macro.import') }}" onsubmit="return confirm('Import from Google Sheet?')" class="mt-4">
    @csrf
    <button type="submit" class="bg-green-600 text-white px-4 py-2 rounded">IMPORT NOW</button>
  </form>

  <form method="POST" action="{{ route('macro.deleteAll') }}" onsubmit="return confirm('Delete all records?')" class="mb-4">
    @csrf
    @method('DELETE')
    <button type="submit" class="bg-red-600 text-white px-4 py-2 rounded">DELETE ALL</button>
  </form>

  <div class="overflow-x-auto">
    <table class="table-auto text-sm w-full border">
      <thead class="bg-gray-100">
        <tr>
          @foreach ($records->first()?->getAttributes() ?? [] as $key => $val)
            <th class="border px-2 py-1">{{ $key }}</th>
          @endforeach
        </tr>
      </thead>
      <tbody>
        @foreach ($records as $row)
          <tr>
            @foreach ($row->getAttributes() as $cell)
              <td class="border px-2 py-1">{{ $cell }}</td>
            @endforeach
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>

  <div class="mt-4">
    {{ $records->appends(request()->query())->links() }}
  </div>
</x-layout>

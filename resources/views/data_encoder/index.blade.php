<x-layout>
  <x-slot name="heading">MES Segregator</x-slot>

  <div class="p-4">
    <h1 class="text-xl font-bold mb-4">Upload and Segregate Excel File</h1>

    <form action="{{ route('mes.segregate') }}" method="POST" enctype="multipart/form-data" class="mb-6">
      @csrf
      <input type="file" name="file" class="border px-2 py-1 rounded" required accept=".xlsx,.xls,.csv">
      <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded ml-2">
        SEGREGATE
      </button>
    </form>

    @if (isset($downloads) && count($downloads))
      <div class="mt-6 space-y-2">
        <h2 class="text-lg font-semibold mb-2">Download Segregated Files:</h2>
        @foreach($downloads as $file)
          <a href="{{ $file['url'] }}"
             class="inline-block bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded">
            Download: {{ $file['label'] }}
          </a>
        @endforeach
      </div>
    @elseif(isset($downloads) && count($downloads) === 0)
      <div class="bg-yellow-100 text-yellow-800 px-4 py-2 rounded">
        ⚠️ Walang laman o walang unique values sa Column N.
      </div>
    @endif
  </div>
</x-layout>

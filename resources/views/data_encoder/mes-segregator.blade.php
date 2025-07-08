<x-layout>
  <x-slot name="heading">MES Segregator</x-slot>

  <div class="p-4">
    <h1 class="text-xl font-bold mb-4">Upload and Segregate Excel File</h1>

    {{-- Show validation errors --}}
    @if ($errors->any())
      <div class="bg-red-100 text-red-800 px-4 py-2 rounded mb-4">
        {{ $errors->first() }}
      </div>
    @endif

    {{-- Upload Form --}}
    <form action="{{ route('mes.segregate') }}" method="POST" enctype="multipart/form-data" class="mb-6">
      @csrf
      <input type="file" name="file" class="border px-2 py-1 rounded" required accept=".xlsx,.xls,.csv">
      <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded ml-2">
        SEGREGATE
      </button>
    </form>

    {{-- Download Section --}}
    @if (isset($downloads) && count($downloads))
      <div class="space-y-2">
        @foreach($downloads as $file)
          <a href="{{ $file['url'] }}" download
             class="block bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded w-full">
            {{ $file['label'] }}
          </a>
        @endforeach
      </div>
    @endif
  </div>
</x-layout>

<x-layout>
  <x-slot name="heading">Upload Attendance Files</x-slot>

  <div class="max-w-4xl mx-auto mt-6">
    @if(session('status'))
      <div class="bg-green-100 text-green-800 p-3 rounded mb-4">{{ session('status') }}</div>
    @endif

    <form action="{{ route('attendance.upload.store') }}" method="POST" enctype="multipart/form-data" class="space-y-4 bg-white p-4 rounded shadow">
      @csrf

      <div>
        <label class="text-sm font-medium">user.dat</label>
        <input type="file" name="user_dat" accept=".dat" class="mt-1 w-full border rounded px-3 py-2">
        @error('user_dat')
          <p class="text-red-600 text-sm mt-1">{{ $message }}</p>
        @enderror
      </div>

      <div>
        <label class="text-sm font-medium">attlog.dat</label>
        <input type="file" name="attlog_dat" accept=".dat" class="mt-1 w-full border rounded px-3 py-2">
        @error('attlog_dat')
          <p class="text-red-600 text-sm mt-1">{{ $message }}</p>
        @enderror
      </div>

      <div class="flex items-center gap-3">
        <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Upload</button>
        <a href="{{ route('attendance.index') }}" class="text-blue-700 hover:underline">Back to Attendance</a>
      </div>
    </form>
  </div>
</x-layout>

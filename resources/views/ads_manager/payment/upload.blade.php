<x-layout>
  <x-slot name="title">Upload Ad Payment</x-slot>
  <x-slot name="heading">Upload Payment Activity (CSV/XLSX)</x-slot>

  @if (session('status'))
    <div class="mb-4 p-3 rounded bg-green-50 text-green-700">
      {{ session('status') }}
    </div>
  @endif

  @if ($errors->any())
    <div class="mb-4 p-3 rounded bg-red-50 text-red-700">
      <ul class="list-disc list-inside">
        @foreach ($errors->all() as $e)
          <li>{{ $e }}</li>
        @endforeach
      </ul>
    </div>
  @endif

  <div class="bg-white rounded shadow p-4">
    <form method="POST"
          action="{{ route('ads_payment.upload.store') }}"
          enctype="multipart/form-data"
          class="space-y-4">
      @csrf

      <div>
        <label class="block font-medium mb-1">Files</label>
        <input
          type="file"
          name="files[]"
          multiple
          accept=".csv,.xlsx,.txt"
          class="border rounded px-3 py-2 w-full"
        >
        <p class="text-sm text-gray-500 mt-1">
          You can select multiple files (CSV/XLSX/TXT). Max 20MB each.
        </p>
      </div>

      <div class="flex flex-wrap items-center gap-2">
        <button class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
          Queue Upload
        </button>

        <a class="text-gray-700 underline"
           href="{{ route('ads_payment.records.index') }}">
          View Records
        </a>

        {{-- NEW: Ad Account ID button --}}
        <a href="{{ url('/ads_manager/ad_account') }}"
           class="px-4 py-2 rounded border border-gray-300 text-gray-800 hover:bg-gray-100">
          Ad Account ID
        </a>
      </div>
    </form>
  </div>
</x-layout>

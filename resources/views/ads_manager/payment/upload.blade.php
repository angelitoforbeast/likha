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

  {{-- Upload Logs Section --}}
  @if(!empty($logs) && count($logs) > 0)
  <div class="bg-white rounded shadow p-4 mt-6">
    <h3 class="text-lg font-semibold mb-3">Upload History</h3>

    <div class="overflow-x-auto">
      <table class="w-full text-sm border-collapse">
        <thead>
          <tr class="bg-gray-100 text-left">
            <th class="px-3 py-2 border">File Name</th>
            <th class="px-3 py-2 border">Status</th>
            <th class="px-3 py-2 border text-right">Total Rows</th>
            <th class="px-3 py-2 border text-right">Mapped</th>
            <th class="px-3 py-2 border text-right">Inserted</th>
            <th class="px-3 py-2 border text-right">Skipped</th>
            <th class="px-3 py-2 border text-right">Errors</th>
            <th class="px-3 py-2 border">Uploaded</th>
            <th class="px-3 py-2 border">Duration</th>
          </tr>
        </thead>
        <tbody>
          @foreach($logs as $log)
          <tr class="border-b hover:bg-gray-50">
            <td class="px-3 py-2 border font-medium">{{ $log->original_name }}</td>
            <td class="px-3 py-2 border">
              @if($log->status === 'done')
                <span class="inline-block px-2 py-0.5 rounded text-xs font-semibold bg-green-100 text-green-700">Done</span>
              @elseif($log->status === 'processing')
                <span class="inline-block px-2 py-0.5 rounded text-xs font-semibold bg-blue-100 text-blue-700">Processing</span>
              @elseif($log->status === 'queued')
                <span class="inline-block px-2 py-0.5 rounded text-xs font-semibold bg-yellow-100 text-yellow-700">Queued</span>
              @elseif($log->status === 'failed')
                <span class="inline-block px-2 py-0.5 rounded text-xs font-semibold bg-red-100 text-red-700">Failed</span>
              @else
                <span class="inline-block px-2 py-0.5 rounded text-xs font-semibold bg-gray-100 text-gray-700">{{ $log->status }}</span>
              @endif
            </td>
            <td class="px-3 py-2 border text-right">{{ number_format($log->total_rows ?? 0) }}</td>
            <td class="px-3 py-2 border text-right">{{ number_format($log->processed_rows ?? 0) }}</td>
            <td class="px-3 py-2 border text-right font-semibold text-green-700">{{ number_format($log->inserted ?? 0) }}</td>
            <td class="px-3 py-2 border text-right text-gray-500">{{ number_format($log->skipped ?? 0) }}</td>
            <td class="px-3 py-2 border text-right {{ ($log->error_rows ?? 0) > 0 ? 'text-red-600 font-semibold' : 'text-gray-500' }}">{{ number_format($log->error_rows ?? 0) }}</td>
            <td class="px-3 py-2 border text-gray-600">
              {{ $log->created_at ? $log->created_at->timezone('Asia/Manila')->format('M d, Y h:i A') : '-' }}
            </td>
            <td class="px-3 py-2 border text-gray-600">
              @if($log->started_at && $log->finished_at)
                {{ $log->started_at->diffForHumans($log->finished_at, true) }}
              @elseif($log->status === 'processing')
                <span class="text-blue-600">In progress...</span>
              @else
                -
              @endif
            </td>
          </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </div>
  @endif
</x-layout>

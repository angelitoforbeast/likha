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

        {{-- NEW: Ad Account ID button (kapareho ng access ng /ads_manager/ad_account) --}}
        @if (\App\Support\NavAccess::allows(auth()->user()?->employeeProfile?->role ?? auth()->user()?->role, \App\Http\Controllers\AdAccountController::NAV_URL))
        <a href="{{ url('/ads_manager/ad_account') }}"
           class="px-4 py-2 rounded border border-gray-300 text-gray-800 hover:bg-gray-100">
          Ad Account ID
        </a>
        @endif
      </div>
    </form>
  </div>

  {{-- Upload Logs Section. Spec 297: also the Meta hourly sync runs (meta_ads_hourly), newest first, with a filter. --}}
  @php($show = $show ?? 'all')
  @if((!empty($logs) && count($logs) > 0) || $show !== 'all')
  <div class="bg-white rounded shadow p-4 mt-6" id="upload-history">
    <div class="flex flex-wrap items-center gap-2 mb-3">
      <h3 class="text-lg font-semibold mr-2">Upload History</h3>
      @foreach(['all' => 'All', 'payment' => 'Payment uploads', 'hourly' => 'Hourly sync'] as $key => $label)
        <a href="{{ route('ads_payment.upload', $key === 'all' ? [] : ['show' => $key]) }}#upload-history"
           class="text-sm px-3 py-1 rounded border {{ $show === $key ? 'bg-gray-800 text-white border-gray-800' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50' }}"
           @if($show === $key) aria-current="true" @endif>{{ $label }}</a>
      @endforeach
      <span class="text-xs text-gray-500 ml-auto">
        @if($show === 'payment')
          Latest {{ \App\Http\Controllers\PaymentActivityController::HISTORY_PAYMENT }} payment uploads.
        @elseif($show === 'hourly')
          Latest {{ \App\Http\Controllers\PaymentActivityController::HISTORY_HOURLY_ONLY }} hourly sync runs (every hour at :10).
        @else
          Latest {{ \App\Http\Controllers\PaymentActivityController::HISTORY_PAYMENT }} payment uploads and
          {{ \App\Http\Controllers\PaymentActivityController::HISTORY_HOURLY_ALL }} hourly sync runs (every hour at :10).
        @endif
      </span>
    </div>

    <div class="overflow-x-auto">
      <table class="w-full text-sm border-collapse">
        <thead>
          <tr class="bg-gray-100 text-left">
            <th class="px-3 py-2 border">Type</th>
            <th class="px-3 py-2 border">File Name</th>
            <th class="px-3 py-2 border">Status</th>
            <th class="px-3 py-2 border text-right">Total Rows</th>
            <th class="px-3 py-2 border text-right">Mapped</th>
            <th class="px-3 py-2 border text-right">Inserted</th>
            <th class="px-3 py-2 border text-right">Updated</th>
            <th class="px-3 py-2 border text-right">Skipped</th>
            <th class="px-3 py-2 border text-right">Errors</th>
            <th class="px-3 py-2 border">Uploaded / Started</th>
            <th class="px-3 py-2 border">Finished</th>
            <th class="px-3 py-2 border">Duration</th>
          </tr>
        </thead>
        <tbody>
          @forelse($logs as $log)
          @php($isHourly = $log->type === \App\Services\MetaAds\HourlySpend::LOG_TYPE)
          <tr class="border-b hover:bg-gray-50" data-log-type="{{ $isHourly ? 'hourly' : 'payment' }}">
            <td class="px-3 py-2 border whitespace-nowrap">
              @if($isHourly)
                <span class="inline-block px-2 py-0.5 rounded text-xs font-semibold bg-indigo-50 text-indigo-700">Meta hourly sync</span>
              @else
                <span class="inline-block px-2 py-0.5 rounded text-xs font-semibold bg-gray-100 text-gray-700">Payment upload</span>
              @endif
            </td>
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
              @elseif(in_array($log->status, ['partial', 'skipped'], true))
                {{-- Hourly sync: some accounts failed (partial) or Meta blocked / rate limited the run (skipped). --}}
                <span class="inline-block px-2 py-0.5 rounded text-xs font-semibold bg-amber-100 text-amber-800">{{ ucfirst($log->status) }}</span>
              @else
                <span class="inline-block px-2 py-0.5 rounded text-xs font-semibold bg-gray-100 text-gray-700">{{ $log->status }}</span>
              @endif
              @if($log->error_message && $isHourly)
                {{-- First line in the row; the full text on hover and in the expand (Meta's error text, escaped). --}}
                @php($errLines = preg_split('/\R/', trim((string) $log->error_message)))
                @php($errFirst = \Illuminate\Support\Str::limit($errLines[0] ?? '', 160))
                <div class="mt-1 text-xs {{ $log->status === 'failed' ? 'text-red-600' : 'text-amber-800' }}" title="{{ $log->error_message }}" data-error-first>{{ $errFirst }}</div>
                @if(count($errLines) > 1 || $errFirst !== ($errLines[0] ?? ''))
                  <details class="mt-1 text-xs text-gray-700">
                    <summary class="cursor-pointer text-gray-500">Full error ({{ count($errLines) }} {{ count($errLines) === 1 ? 'line' : 'lines' }})</summary>
                    <pre class="mt-1 whitespace-pre-wrap break-words font-mono text-[11px]" data-error-full>{{ $log->error_message }}</pre>
                  </details>
                @endif
              @elseif($log->error_message)
                <div class="mt-1 text-xs text-red-600">{{ $log->error_message }}</div>
              @endif
            </td>
            @if($isHourly)
              {{-- A run writes cpp_snapshots rows (processed_rows); the file counts do not apply. --}}
              <td class="px-3 py-2 border text-right" title="Rows written to the hourly CPP timeline">{{ number_format($log->processed_rows ?? 0) }}</td>
              <td class="px-3 py-2 border text-right text-gray-400">–</td>
              <td class="px-3 py-2 border text-right text-gray-400">–</td>
              <td class="px-3 py-2 border text-right text-gray-400">–</td>
              <td class="px-3 py-2 border text-right text-gray-400">–</td>
              <td class="px-3 py-2 border text-right text-gray-400">–</td>
            @else
              <td class="px-3 py-2 border text-right">{{ number_format($log->total_rows ?? 0) }}</td>
              <td class="px-3 py-2 border text-right">{{ number_format($log->processed_rows ?? 0) }}</td>
              <td class="px-3 py-2 border text-right font-semibold text-green-700">{{ number_format($log->inserted ?? 0) }}</td>
              <td class="px-3 py-2 border text-right font-semibold text-blue-700">{{ number_format($log->updated ?? 0) }}</td>
              <td class="px-3 py-2 border text-right text-gray-500">{{ number_format($log->skipped ?? 0) }}</td>
              <td class="px-3 py-2 border text-right {{ ($log->error_rows ?? 0) > 0 ? 'text-red-600 font-semibold' : 'text-gray-500' }}">{{ number_format($log->error_rows ?? 0) }}</td>
            @endif
            <td class="px-3 py-2 border text-gray-600 whitespace-nowrap">
              @php($begun = $isHourly ? ($log->started_at ?? $log->created_at) : $log->created_at)
              {{ $begun ? $begun->timezone('Asia/Manila')->format('M d, Y h:i A') : '-' }}
            </td>
            <td class="px-3 py-2 border text-gray-600 whitespace-nowrap">
              {{ $log->finished_at ? $log->finished_at->timezone('Asia/Manila')->format('M d, Y h:i A') : '-' }}
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
          @empty
          <tr><td colspan="12" class="px-3 py-6 border text-center text-gray-400">Nothing to show for this filter.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>
  @endif
</x-layout>

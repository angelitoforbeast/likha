{{-- resources/views/jnt/segregate-stickers.blade.php --}}
<x-layout>
  <x-slot name="title">Segregate J&amp;T Stickers</x-slot>
  <x-slot name="heading">JNT Sticker Segregator (Group by Goods)</x-slot>

  <div class="max-w-6xl mx-auto mt-6 bg-white shadow-md rounded-lg p-6 space-y-4">

    <div class="text-sm text-gray-600">
      Upload multiple PDF stickers. Each page is treated as 1 waybill.
      Output will be grouped by <b>Goods</b> and downloadable as separate PDFs (ZIP).
      <br>
      <b>Note:</b> Works best if the PDF has a text layer (not scanned images).
    </div>

    {{-- Upload --}}
    <div class="border rounded-lg p-4">
      <form method="POST" action="{{ route('jnt.stickers.submit') }}" enctype="multipart/form-data" class="space-y-3">
        @csrf

        <div>
          <label class="block text-sm font-bold text-gray-700 mb-1">Upload PDF files (multiple)</label>

          <input
            type="file"
            name="pdfs[]"
            accept="application/pdf"
            multiple
            required
            class="block w-full text-sm text-gray-700 border border-gray-300 rounded-md shadow-sm p-2
                   @error('pdfs') border-red-400 @enderror @error('pdfs.*') border-red-400 @enderror"
          >

          @error('pdfs')
            <div class="mt-2 text-sm text-red-700">{{ $message }}</div>
          @enderror
          @error('pdfs.*')
            <div class="mt-2 text-sm text-red-700">{{ $message }}</div>
          @enderror

          <div class="mt-2 text-xs text-gray-500">
            Each page should be 1 sticker. Pages will be grouped into output PDFs by the <b>"Goods:"</b> value.
          </div>
        </div>

        <button class="px-4 py-2 rounded bg-gray-900 text-white text-sm font-semibold">
          Start Segregation
        </button>
      </form>
    </div>

    {{-- Run Status --}}
    @if($run)
      @php
        $status = $run['status'] ?? 'unknown';
        $badge = match($status) {
          'queued' => 'bg-gray-100 text-gray-800',
          'processing' => 'bg-orange-100 text-orange-800',
          'done' => 'bg-emerald-100 text-emerald-800',
          'failed' => 'bg-red-100 text-red-800',
          default => 'bg-gray-100 text-gray-800'
        };

        $progress = $run['progress'] ?? null;
        $pct = (int)($progress['percent'] ?? 0);
        if ($pct < 0) $pct = 0;
        if ($pct > 100) $pct = 100;
      @endphp

      <div class="border rounded-lg p-4 space-y-3">

        <div class="flex flex-wrap items-center justify-between gap-3">
          <div>
            <div class="text-xs font-bold text-gray-600">Run ID</div>
            <div class="font-mono text-sm">{{ $run['run_id'] ?? '' }}</div>
          </div>

          <div>
            <div class="text-xs font-bold text-gray-600">Status</div>
            <span class="inline-flex px-2 py-1 rounded-full text-xs font-bold {{ $badge }}">
              {{ strtoupper($status) }}
            </span>
          </div>

          <div class="text-xs text-gray-500">
            Updated: {{ $run['updated_at'] ?? '' }}
          </div>
        </div>

        {{-- Progress block --}}
        @if(in_array($status, ['queued','processing'], true))
          <div class="p-3 rounded border border-indigo-200 bg-indigo-50 text-indigo-900 text-sm space-y-2">
            <div class="font-bold">
              {{ $progress['message'] ?? 'Processing…' }}
            </div>

            <div class="w-full h-2 bg-indigo-100 rounded overflow-hidden">
              <div class="h-full bg-gray-900" style="width: {{ $pct }}%"></div>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-2 text-xs text-indigo-900">
              <div class="p-2 bg-white rounded border">
                <div class="font-bold">Stage</div>
                <div class="font-mono">{{ $progress['stage'] ?? '-' }}</div>
              </div>
              <div class="p-2 bg-white rounded border">
                <div class="font-bold">File</div>
                <div class="font-mono">
                  {{ $progress['current_file'] ?? 0 }}/{{ $progress['total_files'] ?? 0 }}
                </div>
              </div>
              <div class="p-2 bg-white rounded border">
                <div class="font-bold">Page</div>
                <div class="font-mono">
                  {{ $progress['current_page'] ?? 0 }}/{{ $progress['pages_in_current_file'] ?? 0 }}
                </div>
              </div>
              <div class="p-2 bg-white rounded border">
                <div class="font-bold">Overall</div>
                <div class="font-mono">
                  {{ $pct }}% ({{ $progress['processed_pages_total'] ?? 0 }}/{{ $progress['total_pages_estimate'] ?? 0 }})
                </div>
              </div>
            </div>

            <div class="text-xs text-indigo-700">
              Auto-refreshing…
            </div>
          </div>

          <script>
            setTimeout(() => window.location.reload(), 2500);
          </script>
        @endif

        {{-- Error --}}
        @if(!empty($run['error']))
          <div class="p-3 rounded border border-red-200 bg-red-50 text-red-900 text-sm">
            <div class="font-bold">Error:</div>
            <pre class="mt-1 whitespace-pre-wrap break-words text-xs font-mono">{{ $run['error'] }}</pre>
          </div>
        @endif

        {{-- Done outputs --}}
        @if($status === 'done')
          <div class="flex items-center justify-between flex-wrap gap-2 pt-2">
            <div class="font-extrabold">Outputs</div>
            <a class="px-4 py-2 rounded border text-sm font-semibold"
               href="{{ route('jnt.stickers.downloadZip', ['runId' => $run['run_id']]) }}">
              Download All (ZIP)
            </a>
          </div>

          @if(empty($run['outputs']))
            <div class="text-sm text-gray-500">No outputs generated.</div>
          @else

            <div class="overflow-auto">
              <table class="min-w-full text-sm">
                <thead class="text-xs text-gray-500">
                  <tr class="border-b">
                    <th class="text-left py-2 pr-2">Goods</th>
                    <th class="text-left py-2 pr-2">Pages</th>
                    <th class="text-left py-2 pr-2">Download</th>
                  </tr>
                </thead>
                <tbody class="align-top">
                  @foreach($run['outputs'] as $out)
                    <tr class="border-b">
                      <td class="py-2 pr-2">
                        <span class="inline-flex px-2 py-1 rounded-full text-xs font-bold {{ ($out['goods'] ?? 'UNKNOWN') === 'UNKNOWN' ? 'bg-red-100 text-red-800' : 'bg-gray-100 text-gray-800' }}">
                          {{ $out['goods'] ?? 'UNKNOWN' }}
                        </span>
                      </td>
                      <td class="py-2 pr-2">{{ $out['page_count'] ?? 0 }}</td>
                      <td class="py-2 pr-2">
                        <a class="px-3 py-1 rounded bg-emerald-600 text-white text-xs font-bold"
                           href="{{ route('jnt.stickers.download', ['runId' => $run['run_id'], 'file' => $out['filename']]) }}">
                          Download PDF
                        </a>
                        <span class="text-xs text-gray-500 ml-2 font-mono">{{ $out['filename'] ?? '' }}</span>
                      </td>
                    </tr>
                  @endforeach
                </tbody>
              </table>
            </div>

            @php
              $totalOutputFiles = is_array($run['outputs'] ?? null) ? count($run['outputs']) : 0;
              $totalPages = 0;
              foreach (($run['outputs'] ?? []) as $o) {
                $totalPages += (int)($o['page_count'] ?? 0);
              }
            @endphp

            <div class="mt-4 flex flex-wrap gap-3 items-center justify-between">
  <div class="text-gray-900 text-base md:text-lg font-extrabold">
    Total Output PDFs:
    <span class="font-mono">{{ $totalOutputFiles }}</span>
  </div>

  <div class="text-gray-900 text-base md:text-lg font-extrabold">
    Total Pages:
    <span class="font-mono">{{ $totalPages }}</span>
  </div>
</div>


          @endif
        @endif

      </div>
    @endif

  </div>
</x-layout>

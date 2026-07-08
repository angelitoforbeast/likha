{{-- ✅ resources/views/jnt/waybills/files.blade.php --}}
<x-layout>
  <x-slot name="title">J&T Waybill Files</x-slot>
  <x-slot name="heading">J&T Waybill Files</x-slot>

  <div class="max-w-7xl mx-auto p-4">
    {{-- Flash messages --}}
    @if (session('success'))
      <div class="mb-3 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-green-800">
        {{ session('success') }}
      </div>
    @endif

    @if (session('error'))
      <div class="mb-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-red-800">
        {{ session('error') }}
      </div>
    @endif

    <div class="mb-3 flex items-center justify-between gap-2">
      <div class="text-sm text-gray-600">
        Storage path: <span class="font-mono">{{ $baseDir ?? 'jnt_waybills/bulk_runs' }}</span>
        @php
          $tb = $totalBytes ?? 0;
          $units = ['B', 'KB', 'MB', 'GB', 'TB'];
          $ui = 0; $uv = $tb;
          while ($uv >= 1024 && $ui < count($units) - 1) { $uv /= 1024; $ui++; }
          $totalHuman = number_format($uv, 2) . ' ' . $units[$ui];
        @endphp
        <div class="mt-1">
          <span class="inline-flex items-center rounded-lg border border-gray-300 bg-gray-50 px-2.5 py-1 text-sm font-semibold text-gray-800">
            📦 Total: {{ number_format($totalCount ?? 0) }} file(s) · {{ $totalHuman }}
          </span>
        </div>
      </div>
      <div class="flex items-center gap-2">
        <form
          method="POST"
          action="{{ route('jnt.waybills.files.destroyOld') }}"
          onsubmit="return confirm('Delete all files older than 1 day (≥24 hours ago)?\n\nThis cannot be undone.')"
        >
          @csrf
          <button
            type="submit"
            class="inline-flex items-center rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm font-semibold text-red-700 hover:bg-red-100"
          >
            🗑 Delete All (1 day+)
          </button>
        </form>
        <a href="{{ route('jnt.waybills.files') }}"
           class="inline-flex items-center rounded-lg border px-3 py-2 text-sm hover:bg-gray-50">
          Refresh
        </a>
      </div>
    </div>

    <div class="overflow-x-auto rounded-xl border bg-white shadow-sm">
      <table class="min-w-full text-sm">
        <thead class="bg-gray-50 text-gray-700">
          <tr>
            <th class="px-4 py-3 text-left font-semibold">Run ID</th>
            <th class="px-4 py-3 text-left font-semibold">File</th>
            <th class="px-4 py-3 text-left font-semibold">Type</th>
            <th class="px-4 py-3 text-right font-semibold">Size</th>
            <th class="px-4 py-3 text-left font-semibold">Start</th>
            <th class="px-4 py-3 text-left font-semibold">End</th>
            <th class="px-4 py-3 text-right font-semibold">Duration</th>
            <th class="px-4 py-3 text-right font-semibold">Pages</th>
            <th class="px-4 py-3 text-right font-semibold">Pages/min</th>
            <th class="px-4 py-3 text-right font-semibold">Actions</th>
          </tr>
        </thead>

        <tbody class="divide-y">
          @php
            $files = $files ?? collect();
          @endphp

          @if ($files->isEmpty())
            <tr>
              <td colspan="10" class="px-4 py-8 text-center text-gray-500">
                No PDF/ZIP/TXT found in storage.
              </td>
            </tr>
          @else
            @foreach ($files as $f)
              @php
                $runId = $f['run_id'] ?? null;
                $filename = $f['filename'] ?? null;
                $ext = strtoupper($f['ext'] ?? '');
                $size = (int) ($f['size'] ?? 0);
                $mtime = $f['mtime'] ?? null;

                // size format
                $kb = $size / 1024;
                $mb = $kb / 1024;
                $sizeText = $mb >= 1 ? number_format($mb, 2).' MB' : number_format($kb, 1).' KB';

                // last modified format
                $mtimeText = $mtime ? \Carbon\Carbon::createFromTimestamp($mtime, 'Asia/Manila')->format('Y-m-d H:i:s') : '-';

                // timing metrics (attached by controller from the run row)
                $startAt = $f['started_at'] ?? null;
                $endAt   = $f['finished_at'] ?? null;
                $durSec  = $f['duration_sec'] ?? null;
                $pages   = $f['pages'] ?? null;
                $ppm     = $f['ppm'] ?? null;

                $startText = $startAt ? $startAt->format('Y-m-d H:i:s') : '—';
                $endText   = $endAt ? $endAt->format('H:i:s') : '—';
                if ($durSec === null) {
                    $durText = '—';
                } elseif ($durSec >= 60) {
                    $m = intdiv($durSec, 60); $s = $durSec % 60;
                    $durText = "{$m}m {$s}s";
                } else {
                    $durText = "{$durSec}s";
                }

                // badge color by extension
                $badgeClass = match (strtolower($f['ext'] ?? '')) {
                  'pdf' => 'bg-blue-50 text-blue-700 border-blue-200',
                  'zip' => 'bg-purple-50 text-purple-700 border-purple-200',
                  'txt' => 'bg-amber-50 text-amber-700 border-amber-200',
                  default => 'bg-gray-50 text-gray-700 border-gray-200',
                };
              @endphp

              <tr class="hover:bg-gray-50/60">
                <td class="px-4 py-3 font-mono text-gray-800">
                  {{ $runId }}
                </td>

                <td class="px-4 py-3">
                  <div class="font-medium text-gray-900">
                    {{ $f['name'] ?? basename($filename ?? '') }}
                  </div>
                  <div class="mt-0.5 font-mono text-xs text-gray-500 break-all">
                    {{ $f['rel'] ?? '' }}
                  </div>
                </td>

                <td class="px-4 py-3">
                  <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs {{ $badgeClass }}">
                    {{ $ext ?: '-' }}
                  </span>
                </td>

                <td class="px-4 py-3 text-right tabular-nums">
                  {{ $sizeText }}
                </td>

                <td class="px-4 py-3 text-gray-700 whitespace-nowrap" title="Last modified: {{ $mtimeText }}">
                  {{ $startText }}
                </td>

                <td class="px-4 py-3 text-gray-700 whitespace-nowrap">
                  {{ $endText }}
                </td>

                <td class="px-4 py-3 text-right tabular-nums whitespace-nowrap">
                  {{ $durText }}
                </td>

                <td class="px-4 py-3 text-right tabular-nums">
                  {{ $pages !== null ? number_format($pages) : '—' }}
                </td>

                <td class="px-4 py-3 text-right tabular-nums font-semibold {{ $ppm !== null ? 'text-emerald-700' : 'text-gray-400' }}">
                  {{ $ppm !== null ? number_format($ppm, 1) : '—' }}
                </td>

                <td class="px-4 py-3">
                  <div class="flex items-center justify-end gap-2">
                    @if ($runId !== null && $filename)
                      <a
                        href="{{ route('jnt.waybills.files.download', ['runId' => $runId, 'filename' => $filename]) }}"
                        class="inline-flex items-center rounded-lg bg-gray-900 px-3 py-2 text-xs font-semibold text-white hover:bg-black"
                      >
                        Download
                      </a>

                      <form
                        method="POST"
                        action="{{ route('jnt.waybills.files.destroy', ['runId' => $runId, 'filename' => $filename]) }}"
                        onsubmit="return confirm('Delete this file?\n\nRun: {{ $runId }}\nFile: {{ $f['name'] ?? basename($filename) }}')"
                      >
                        @csrf
                        @method('DELETE')
                        <button
                          type="submit"
                          class="inline-flex items-center rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs font-semibold text-red-700 hover:bg-red-100"
                        >
                          Delete
                        </button>
                      </form>
                    @else
                      <span class="text-xs text-gray-400">Invalid row</span>
                    @endif
                  </div>
                </td>
              </tr>
            @endforeach
          @endif
        </tbody>
      </table>
    </div>

    <div class="mt-4 text-xs text-gray-500">
      Tip: Files are read from <span class="font-mono">{{ $baseDir ?? 'jnt_waybills/bulk_runs' }}</span> under <span class="font-mono">storage/app</span>.
    </div>
  </div>
</x-layout>

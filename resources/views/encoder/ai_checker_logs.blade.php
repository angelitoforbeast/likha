<x-layout>
  <x-slot name="title">AI Checker Logs</x-slot>
  <x-slot name="heading">AI Checker / AI Fix — Logs</x-slot>

  @php
    $fmtTs = fn ($ts) => $ts ? \Carbon\Carbon::parse($ts)->timezone('Asia/Manila')->format('Y-m-d H:i') : '—';
    $dur   = function ($a, $b) {
        if (!$a || !$b) return '—';
        $s = \Carbon\Carbon::parse($a)->diffInSeconds(\Carbon\Carbon::parse($b));
        if ($s < 60) return $s.'s';
        return intdiv($s, 60).'m '.($s % 60).'s';
    };
    $outBadge = function ($o) {
        $map = [
            'fixed'   => 'bg-green-100 text-green-700',
            'partial' => 'bg-yellow-100 text-yellow-800',
            'failed'  => 'bg-red-100 text-red-700',
        ];
        $cls = $map[$o] ?? 'bg-gray-100 text-gray-700';
        return "<span class='inline-block rounded px-2 py-0.5 text-xs font-semibold {$cls}'>{$o}</span>";
    };
  @endphp

  <div class="max-w-7xl mx-auto p-4 space-y-8">

    <p class="text-xs text-gray-500">
      Bawat processed na order (AI Checker bulk <em>at</em> AI Fix per row) ay nalo-log dito.
      Naka-prune sa huling <strong>90 araw</strong>. Oras = Asia/Manila.
    </p>

    {{-- ════════ AI Checker batches (grouped by batch_id) ════════ --}}
    <div>
      <h2 class="text-base font-bold text-gray-800 mb-2">🤖 AI Checker — Batches</h2>
      <div class="overflow-x-auto rounded-xl border bg-white shadow-sm">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 text-gray-700">
            <tr>
              <th class="px-3 py-2 text-left font-semibold">Simula (PH)</th>
              <th class="px-3 py-2 text-left font-semibold">Sino</th>
              <th class="px-3 py-2 text-left font-semibold">Page</th>
              <th class="px-3 py-2 text-right font-semibold">Processed / Target</th>
              <th class="px-3 py-2 text-right font-semibold">✅</th>
              <th class="px-3 py-2 text-right font-semibold">⚠</th>
              <th class="px-3 py-2 text-right font-semibold">❌</th>
              <th class="px-3 py-2 text-right font-semibold">Avg/row</th>
              <th class="px-3 py-2 text-right font-semibold">Tagal</th>
              <th class="px-3 py-2 text-left font-semibold">Status</th>
            </tr>
          </thead>
          <tbody class="divide-y">
            @forelse ($batches as $b)
              @php
                $target    = (int) ($b->target ?? 0);
                $processed = (int) $b->processed;
                $tapos     = ($target === 0 || $processed >= $target);
              @endphp
              <tr class="hover:bg-gray-50/60">
                <td class="px-3 py-2 whitespace-nowrap text-gray-700">{{ $fmtTs($b->started_at) }}</td>
                <td class="px-3 py-2 text-gray-700">{{ $b->user_name ?? '—' }}</td>
                <td class="px-3 py-2 text-gray-600">{{ $b->page ?? '—' }}</td>
                <td class="px-3 py-2 text-right tabular-nums font-semibold">{{ $processed }}{{ $target ? ' / '.$target : '' }}</td>
                <td class="px-3 py-2 text-right tabular-nums text-green-700">{{ (int) $b->fixed }}</td>
                <td class="px-3 py-2 text-right tabular-nums text-yellow-700">{{ (int) $b->partial }}</td>
                <td class="px-3 py-2 text-right tabular-nums text-red-700">{{ (int) $b->failed }}</td>
                <td class="px-3 py-2 text-right tabular-nums text-gray-500">{{ $b->avg_ms !== null ? number_format($b->avg_ms / 1000, 1).'s' : '—' }}</td>
                <td class="px-3 py-2 text-right tabular-nums text-gray-500">{{ $dur($b->started_at, $b->finished_at) }}</td>
                <td class="px-3 py-2">
                  @if ($tapos)
                    <span class="inline-block rounded px-2 py-0.5 text-xs font-semibold bg-green-100 text-green-700">Tapos</span>
                  @else
                    <span class="inline-block rounded px-2 py-0.5 text-xs font-semibold bg-amber-100 text-amber-800">Hindi natapos</span>
                  @endif
                </td>
              </tr>
            @empty
              <tr><td colspan="10" class="px-4 py-6 text-center text-gray-500">Wala pang AI Checker batch run.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>

    {{-- ════════ Single AI Fix (per-row clicks) ════════ --}}
    <div>
      <h2 class="text-base font-bold text-gray-800 mb-2">👆 AI Fix — Per-row (recent 200)</h2>
      <div class="overflow-x-auto rounded-xl border bg-white shadow-sm">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 text-gray-700">
            <tr>
              <th class="px-3 py-2 text-left font-semibold">Oras (PH)</th>
              <th class="px-3 py-2 text-left font-semibold">Sino</th>
              <th class="px-3 py-2 text-left font-semibold">Page</th>
              <th class="px-3 py-2 text-left font-semibold">Item</th>
              <th class="px-3 py-2 text-left font-semibold">Result</th>
              <th class="px-3 py-2 text-right font-semibold">Tagal</th>
            </tr>
          </thead>
          <tbody class="divide-y">
            @forelse ($singles as $s)
              <tr class="hover:bg-gray-50/60">
                <td class="px-3 py-2 whitespace-nowrap text-gray-700">{{ $fmtTs($s->created_at) }}</td>
                <td class="px-3 py-2 text-gray-700">{{ $s->user_name ?? '—' }}</td>
                <td class="px-3 py-2 text-gray-600">{{ $s->page ?? '—' }}</td>
                <td class="px-3 py-2 text-gray-600">{{ $s->item ?? '—' }}</td>
                <td class="px-3 py-2">{!! $outBadge($s->outcome) !!} <span class="text-xs text-gray-400">{{ $s->final_code }}</span></td>
                <td class="px-3 py-2 text-right tabular-nums text-gray-500">{{ $s->duration_ms !== null ? number_format($s->duration_ms / 1000, 1).'s' : '—' }}</td>
              </tr>
            @empty
              <tr><td colspan="6" class="px-4 py-6 text-center text-gray-500">Wala pang per-row AI Fix.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>

  </div>
</x-layout>

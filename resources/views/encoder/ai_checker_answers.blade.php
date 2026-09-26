<x-layout>
  <x-slot name="title">AI Answers</x-slot>
  <x-slot name="heading">AI Checker / AI Fix — Ano ang sagot ng AI (CEO)</x-slot>

  @php
    $php   = 58;   // ₱ kada $ (estimate lang, para sa mabilis na tingin)
    $fmtTs = fn ($ts) => $ts ? \Carbon\Carbon::parse($ts)->timezone('Asia/Manila')->format('Y-m-d H:i:s') : '—';
    $badge = function ($o) {
        $map = ['fixed' => 'bg-green-100 text-green-700', 'partial' => 'bg-yellow-100 text-yellow-800', 'failed' => 'bg-red-100 text-red-700'];
        $cls = $map[$o] ?? 'bg-gray-100 text-gray-700';
        return "<span class='inline-block rounded px-2 py-0.5 text-xs font-semibold {$cls}'>{$o}</span>";
    };
    $place = fn ($a) => implode(', ', array_filter([$a['barangay'] ?? '', $a['city'] ?? '', $a['province'] ?? ''])) ?: '—';
  @endphp

  <div class="max-w-7xl mx-auto p-4 space-y-6">

    <div class="flex items-start justify-between gap-3">
      <p class="text-xs text-gray-500">
        Bawat takbo ng AI Fix / AI Checker sa isang row: ang <strong>aktwal na sagot ng AI</strong> (RESOLVE + candidates),
        ang napili ng <strong>MAP</strong> mula sa J&amp;T list, ang desisyon ng <strong>GUARD</strong>, ang hatol ng <strong>VERIFYK</strong>,
        mga web search at source, before → after, at gastos. CEO lang. Oras = Asia/Manila.
      </p>
      <a href="{{ route('macro_checker.logs') }}" class="shrink-0 text-sm font-semibold text-blue-600 hover:underline">← Logs</a>
    </div>

    {{-- ════════ Filters ════════ --}}
    <form method="GET" class="rounded-xl border bg-white shadow-sm p-3 flex flex-wrap items-end gap-3 text-sm">
      <label class="flex flex-col gap-1">
        <span class="text-xs text-gray-500">Petsa (PH)</span>
        <input type="date" name="date" value="{{ $filters['date'] }}" class="border rounded px-2 py-1">
      </label>
      <label class="flex flex-col gap-1">
        <span class="text-xs text-gray-500">Page</span>
        <input type="text" name="page" value="{{ $filters['page'] }}" placeholder="hal. Bianca Santos" class="border rounded px-2 py-1 w-44">
      </label>
      <label class="flex flex-col gap-1">
        <span class="text-xs text-gray-500">Row ID (macro_output)</span>
        <input type="text" name="mid" value="{{ $filters['mid'] }}" placeholder="hal. 365051" class="border rounded px-2 py-1 w-32">
      </label>
      <label class="flex flex-col gap-1">
        <span class="text-xs text-gray-500">Resulta</span>
        <select name="outcome" class="border rounded px-2 py-1">
          <option value="">lahat</option>
          @foreach (['fixed' => '✅ fixed (PROCEED)', 'partial' => '⚠ partial (tao)', 'failed' => '❌ failed'] as $k => $v)
            <option value="{{ $k }}" @selected($filters['outcome'] === $k)>{{ $v }}</option>
          @endforeach
        </select>
      </label>
      <label class="flex items-center gap-2 pb-1">
        <input type="checkbox" name="escalated" value="1" @checked($filters['escalated'])>
        <span>escalated lang (gpt-6-astra)</span>
      </label>
      <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded px-4 py-1.5">Filter</button>
      <a href="{{ route('macro_checker.answers') }}" class="text-gray-500 hover:underline">reset</a>
    </form>

    {{-- ════════ Totals ════════ --}}
    <div class="grid grid-cols-2 md:grid-cols-6 gap-3 text-sm">
      @foreach ([
        ['Rows', number_format($totals['count'])],
        ['✅ PROCEED', number_format($totals['fixed'])],
        ['Escalated', number_format($totals['escalated'])],
        ['Web searches', number_format($totals['searches'])],
        ['Gastos', '$' . number_format($totals['cost'], 2) . ' ≈ ₱' . number_format($totals['cost'] * $php, 0)],
        ['Avg oras/row', $totals['count'] ? round($totals['avg_ms'] / 1000, 1) . ' s' : '—'],
      ] as [$label, $val])
        <div class="rounded-xl border bg-white shadow-sm p-3">
          <div class="text-xs text-gray-500">{{ $label }}</div>
          <div class="text-lg font-bold text-gray-800">{{ $val }}</div>
        </div>
      @endforeach
    </div>

    {{-- ════════ Entries ════════ --}}
    @forelse ($logs as $l)
      @php
        $d      = $l->detail ?? [];
        $sum    = $d['summary'] ?? [];
        $passes = $d['passes'] ?? [];
        $usd    = (float) ($l->cost_usd ?? 0);
        $tokIn  = (int) ($l->tokens_in ?? 0);
        $tokOut = (int) ($l->tokens_out ?? 0);
      @endphp
      <div class="rounded-xl border bg-white shadow-sm p-4 space-y-3 text-sm">
        {{-- header --}}
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
          <span class="font-semibold">{{ $fmtTs($l->created_at) }}</span>
          <span>👤 {{ $l->user_name }}</span>
          <span class="text-gray-500">{{ $l->source }}{{ $l->batch_id ? ' · batch' : '' }}</span>
          <span>row <a class="text-blue-600 hover:underline" href="{{ url('/encoder/checker_1') }}?q={{ $l->macro_output_id }}">#{{ $l->macro_output_id }}</a></span>
          <span class="text-gray-700">{{ $l->page }} · {{ $l->item }}</span>
          <span>{!! $badge($l->outcome) !!} <b>{{ $l->final_code }}</b></span>
          @if (!empty($l->model))
            <span>model: <b>{{ $l->model }}</b>@if (!empty($l->escalated)) <span class="ml-1 rounded bg-amber-100 text-amber-800 px-2 py-0.5 text-xs font-semibold">ESCALATED</span>@endif</span>
            <span>searches: {{ (int) $l->searches }}</span>
            <span>tokens: {{ number_format($tokIn) }} in / {{ number_format($tokOut) }} out</span>
            <span>gastos: ${{ number_format($usd, 3) }} ≈ ₱{{ number_format($usd * $php, 2) }}</span>
          @endif
          <span class="text-gray-500">{{ number_format((int) $l->duration_ms / 1000, 1) }} s</span>
        </div>

        @if (empty($l->detail))
          <div class="text-gray-500 italic">Walang detalye — takbo bago ang logging ng sagot ng AI.</div>
        @else
          {{-- per pass --}}
          @foreach ($passes as $p)
            <div class="rounded-lg border border-gray-100 bg-gray-50 p-3 space-y-1">
              <div class="text-xs text-gray-500">Pass {{ $p['pass'] ?? '?' }} · chat {{ number_format((int) ($p['chat_chars'] ?? 0)) }} chars · {{ round(((int) ($p['elapsed_ms'] ?? 0)) / 1000, 1) }} s · resulta <b>{{ $p['final_code'] ?? '' }}</b>{{ !empty($p['proceed']) ? ' → PROCEED' : '' }}</div>
              @foreach (($p['resolve'] ?? []) as $rs)
                @php $a = $rs['answer'] ?? []; $m = $rs['map'] ?? []; $g = $rs['assess'] ?? []; @endphp
                <div>
                  <b>RESOLVE ({{ $rs['model'] ?? '' }}):</b> {{ $place($a) }}
                  <i class="text-gray-600">[{{ $a['confidence'] ?? '' }}]</i>
                  @if (count($a['city_candidates'] ?? []) > 1) <span class="text-amber-700">· city?: {{ implode(' / ', $a['city_candidates']) }}</span> @endif
                  @if (count($a['barangay_candidates'] ?? []) > 1) <span class="text-amber-700">· brgy?: {{ implode(' / ', $a['barangay_candidates']) }}</span> @endif
                  @if (!empty($a['evidence'])) <div class="text-gray-600 text-xs pl-4">— {{ $a['evidence'] }}</div> @endif
                  @if (!empty($m['note'])) <div class="pl-4"><b>MAP:</b> {{ $m['note'] }}</div> @endif
                  @if (!empty($g['reasons'])) <div class="pl-4"><b>GUARD:</b> {{ implode(' · ', $g['reasons']) }}</div> @endif
                </div>
              @endforeach
              @foreach (($p['fallbacks'] ?? []) as $f)
                <div><b>{{ $f['step'] ?? 'FALLBACK' }} (walang search):</b> "{{ $f['hint'] ?? '' }}" → {{ $f['answer'] ?? 'UNKNOWN' }}</div>
              @endforeach
              @if (!empty($p['verify']))
                @php $v = $p['verify']; @endphp
                <div><b>VERIFYK:</b>
                  province {{ !empty($v['province_ok']) ? '✅' : '❌' }} ·
                  city {{ !empty($v['city_ok']) ? '✅' : '❌' }} ·
                  barangay {{ !empty($v['barangay_ok']) ? '✅' : '❌' }}
                  @if (!empty($v['evidence'])) <span class="text-gray-600">— {{ $v['evidence'] }}</span> @endif
                </div>
              @endif
              @php $changed = array_values(array_diff((array) ($p['updated'] ?? []), ['APP SCRIPT CHECKER', 'STATUS'])); @endphp
              @if ($changed)
                <div><b>Binago:</b>
                  @foreach ($changed as $k)
                    <span class="inline-block mr-3">{{ $k }}: "{{ $p['before'][$k] ?? '' }}" → <b>"{{ $p['after'][$k] ?? '' }}"</b></span>
                  @endforeach
                </div>
              @endif
              @if (!empty($p['gate']['hard']) || !empty($p['gate']['soft']))
                <div class="text-red-700"><b>GATE:</b> {{ implode('; ', array_merge($p['gate']['hard'] ?? [], $p['gate']['soft'] ?? [])) }}</div>
              @endif
            </div>
          @endforeach

          {{-- searches --}}
          @if (!empty($d['searches']))
            <details>
              <summary class="cursor-pointer text-blue-700">Web searches at sources ({{ count($d['searches']) }} call{{ count($d['searches']) === 1 ? '' : 's' }})</summary>
              <ul class="list-disc pl-6 text-xs text-gray-700 space-y-1 mt-1">
                @foreach ($d['searches'] as $s)
                  <li><b>{{ $s['step'] ?? '' }}</b> ({{ $s['model'] ?? '' }}): {{ implode(' | ', $s['queries'] ?? []) ?: '—' }}
                    @foreach (array_slice($s['sources'] ?? [], 0, 6) as $u)
                      <br><a class="text-blue-600 hover:underline break-all" href="{{ $u }}" target="_blank" rel="noopener">{{ $u }}</a>
                    @endforeach
                  </li>
                @endforeach
              </ul>
            </details>
          @endif

          {{-- evidence + raw --}}
          @if (!empty($l->evidence))
            <details>
              <summary class="cursor-pointer text-blue-700">Evidence lines</summary>
              <pre class="whitespace-pre-wrap break-words text-xs bg-white border rounded p-2 mt-1 max-h-96 overflow-auto">{{ $l->evidence }}</pre>
            </details>
          @endif
          <details>
            <summary class="cursor-pointer text-blue-700">Raw JSON</summary>
            <pre class="whitespace-pre-wrap break-words text-xs bg-gray-900 text-gray-100 rounded p-2 mt-1 max-h-96 overflow-auto">{{ json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
          </details>
        @endif
      </div>
    @empty
      <div class="rounded-xl border bg-white shadow-sm p-6 text-center text-gray-500">Walang takbo ng AI sa filter na ito.</div>
    @endforelse

    @if ($logs->count() >= $limit)
      <p class="text-xs text-gray-500">Ipinapakita ang huling {{ $limit }} lang — gamitin ang filter para paliitin.</p>
    @endif
  </div>
</x-layout>

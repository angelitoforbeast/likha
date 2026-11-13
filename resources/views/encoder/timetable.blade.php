{{-- resources/views/encoder/timetable.blade.php (spec 265): anong oras natapos ang encoding, J&T upload at waybill kada order date --}}
@php
  use App\Http\Controllers\Encoder\EncoderTimetableController as TT;
  $gapText = fn ($m) => $m === null ? '—' : (($m >= 0 ? '+' : '−') . abs($m) . ' min');
@endphp
<x-layout>
  <x-slot name="title">Encoding Timetable</x-slot>
  <x-slot name="heading">
    <div class="text-xl font-bold">⏱️ Encoding Timetable (per order date)</div>
  </x-slot>

  <form method="GET" action="{{ route('encoder.timetable') }}" class="mt-4 mb-4 px-4 flex gap-4 flex-wrap items-end">
    <div>
      <label class="text-sm font-medium" for="date_from">Order date from</label>
      <input id="date_from" type="date" name="date_from" value="{{ $from }}" class="border rounded px-2 py-1" />
    </div>
    <div>
      <label class="text-sm font-medium" for="date_to">Order date to</label>
      <input id="date_to" type="date" name="date_to" value="{{ $to }}" class="border rounded px-2 py-1" />
    </div>
    <button type="submit" class="bg-gray-700 text-white px-4 py-2 rounded hover:bg-gray-800">Filter</button>
    <p class="text-xs text-gray-500">Ang trabaho sa isang araw ay para sa orders ng nakaraang araw. Oras lang kapag kinabukasan ng order date; may petsa kapag ibang araw. Hanggang {{ TT::MAX_DAYS }} araw.</p>
  </form>

  @if ($error)
    <div class="mx-4 mb-3 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-800" data-test="range-error">{{ $error }}</div>
  @endif

  {{-- Summary ng ipinakitang range --}}
  <div class="mx-4 mb-4 flex flex-wrap gap-3 text-sm" data-test="summary">
    @foreach ($summary as $key => $s)
      <div class="rounded border bg-white px-3 py-2" data-step="{{ $key }}">
        <div class="font-semibold">{{ $s['label'] }}</div>
        <div>Average finish: <span class="font-mono" data-test="avg">{{ TT::offsetClock($s['avg']) }}</span>
          <span class="text-xs text-gray-500">({{ $s['days'] }} araw)</span></div>
        <div>Pinakahuli: <span class="font-mono" data-test="worst">{{ $s['worst_date'] ? TT::clock($s['worst_date'], $s['worst_at']) : '—' }}</span>
          @if ($s['worst_date'])<span class="text-xs text-gray-500">(order date {{ \Carbon\Carbon::parse($s['worst_date'])->format('M j') }})</span>@endif
        </div>
      </div>
    @endforeach
    <div class="self-center text-xs text-gray-500">
      <span class="inline-block rounded bg-red-100 px-1 text-red-800">pula</span> = higit {{ TT::LATE_MINUTES }} min na mas huli sa average.
      @if ($oldestDay) Encoding record mula {{ \Carbon\Carbon::parse($oldestDay)->format('M j, Y') }} lang (90 araw). @endif
    </div>
  </div>

  <div class="overflow-x-auto px-4 pb-8">
    <table class="min-w-full text-sm border" id="timetable">
      <thead class="bg-gray-200">
        <tr>
          <th class="border px-3 py-2 text-left">Order date</th>
          <th class="border px-3 py-2 text-left">Encoding</th>
          <th class="border px-3 py-2 text-center">Gap</th>
          <th class="border px-3 py-2 text-left">J&amp;T upload</th>
          <th class="border px-3 py-2 text-center">Gap</th>
          <th class="border px-3 py-2 text-left">Waybill</th>
          <th class="border px-3 py-2 text-left">Excel download</th>
        </tr>
      </thead>
      <tbody>
        @foreach ($rows as $r)
          @php
            $enc = $r['encoding']; $j = $r['jnt']; $w = $r['waybill']; $x = $r['excel'];
            $late = $r['late'] ?? [];
          @endphp
          <tr data-date="{{ $r['date'] }}" class="align-top">
            <td class="border px-3 py-2 whitespace-nowrap font-semibold">{{ \Carbon\Carbon::parse($r['date'])->format('D, M j') }}</td>

            <td class="border px-3 py-2 {{ !empty($late['encoding']) ? 'bg-red-100' : '' }}" data-col="encoding">
              @if ($enc)
                <span class="font-mono">{{ TT::clock($r['date'], $enc['start']) }} – <b>{{ TT::clock($r['date'], $enc['finish']) }}</b></span>
                <div class="text-xs text-gray-600">{{ number_format($enc['orders']) }} orders · huli: {{ $enc['finisher'] ?? '?' }}</div>
              @elseif ($r['no_record'])
                <span class="text-gray-500 italic">wala nang record</span>
              @else
                —
              @endif
            </td>

            <td class="border px-3 py-2 text-center font-mono text-xs" data-col="gap-enc-jnt">{{ $gapText($r['gap_enc_jnt']) }}</td>

            <td class="border px-3 py-2 {{ !empty($late['jnt']) ? 'bg-red-100' : '' }}" data-col="jnt">
              @if ($j)
                <b class="font-mono">{{ TT::clock($r['date'], $j['finish']) }}</b>
                <div class="text-xs text-gray-600">{{ $j['done'] }}/{{ $j['runs'] }} runs tapos</div>
                @if ($j['done'] < $j['runs'])
                  <div class="text-xs font-semibold text-red-700" data-test="jnt-flag">⚠ {{ $j['runs'] - $j['done'] }} hindi tapos{{ $j['failed'] ? ' (' . $j['failed'] . ' failed/stopped)' : '' }}</div>
                @endif
              @else
                —
              @endif
            </td>

            <td class="border px-3 py-2 text-center font-mono text-xs" data-col="gap-jnt-waybill">{{ $gapText($r['gap_jnt_waybill']) }}</td>

            <td class="border px-3 py-2 {{ !empty($late['waybill']) ? 'bg-red-100' : '' }}" data-col="waybill">
              @if ($w)
                <b class="font-mono">{{ TT::clock($r['date'], $w['finish']) }}</b>
                <div class="text-xs text-gray-600">{{ $w['runs'] }} print runs</div>
                @if ($w['done'] < $w['runs'])
                  <div class="text-xs font-semibold text-red-700" data-test="waybill-flag">⚠ {{ $w['runs'] - $w['done'] }} hindi tapos{{ $w['failed'] ? ' (' . $w['failed'] . ' failed)' : '' }}</div>
                @endif
              @else
                —
              @endif
            </td>

            <td class="border px-3 py-2" data-col="excel">
              @if ($x)
                <span class="font-mono">{{ TT::clock($r['date'], $x['at']) }}</span>
                <div class="text-xs text-gray-600">{{ $x['by'] }}</div>
              @else
                —
              @endif
            </td>
          </tr>

          @if ($r['pages'] || ($w['items'] ?? []))
            <tr data-detail="{{ $r['date'] }}">
              <td colspan="7" class="border px-3 py-1 bg-gray-50">
                <details>
                  <summary class="cursor-pointer text-xs text-blue-700">Per page ({{ count($r['pages']) }}) at waybill per filter ({{ count($w['items'] ?? []) }})</summary>
                  <div class="flex flex-wrap gap-6 py-2">
                    <table class="text-xs border" data-test="pages">
                      <thead class="bg-gray-100">
                        <tr>
                          <th class="border px-2 py-1 text-left">Page</th>
                          <th class="border px-2 py-1 text-left">Encoding finish</th>
                          <th class="border px-2 py-1 text-right">Orders</th>
                          <th class="border px-2 py-1 text-left">J&amp;T finish</th>
                          <th class="border px-2 py-1 text-left">Runs</th>
                        </tr>
                      </thead>
                      <tbody>
                        @foreach ($r['pages'] as $p)
                          <tr data-page="{{ $p['page'] }}">
                            <td class="border px-2 py-1">{{ $p['page'] }}</td>
                            <td class="border px-2 py-1 font-mono">{{ $p['encoding'] ? TT::clock($r['date'], $p['encoding']['finish']) : '—' }}</td>
                            <td class="border px-2 py-1 text-right">{{ $p['encoding']['orders'] ?? '—' }}</td>
                            <td class="border px-2 py-1 font-mono">{{ $p['jnt'] ? TT::clock($r['date'], $p['jnt']['finish']) : '—' }}</td>
                            <td class="border px-2 py-1 {{ $p['jnt'] && $p['jnt']['done'] < $p['jnt']['runs'] ? 'text-red-700 font-semibold' : '' }}">
                              {{ $p['jnt'] ? $p['jnt']['done'] . '/' . $p['jnt']['runs'] : '—' }}
                            </td>
                          </tr>
                        @endforeach
                      </tbody>
                    </table>

                    @if ($w['items'] ?? [])
                      <table class="text-xs border" data-test="waybill-items">
                        <thead class="bg-gray-100">
                          <tr>
                            <th class="border px-2 py-1 text-left">Waybill filter</th>
                            <th class="border px-2 py-1 text-left">Finish</th>
                            <th class="border px-2 py-1 text-left">Runs</th>
                          </tr>
                        </thead>
                        <tbody>
                          @foreach ($w['items'] as $it)
                            <tr data-item="{{ $it['label'] }}">
                              <td class="border px-2 py-1">{{ $it['label'] }} <span class="text-gray-400">({{ $it['by'] }})</span></td>
                              <td class="border px-2 py-1 font-mono">{{ TT::clock($r['date'], $it['finish']) }}</td>
                              <td class="border px-2 py-1 {{ $it['done'] < $it['runs'] ? 'text-red-700 font-semibold' : '' }}">{{ $it['done'] }}/{{ $it['runs'] }}</td>
                            </tr>
                          @endforeach
                        </tbody>
                      </table>
                    @endif
                  </div>
                </details>
              </td>
            </tr>
          @endif
        @endforeach
      </tbody>
    </table>
  </div>
</x-layout>

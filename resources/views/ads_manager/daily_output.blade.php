{{-- resources/views/ads_manager/daily_output.blade.php (spec 266): ano ang ginawa sa ads sa isang araw --}}
@php
  use App\Http\Controllers\AdsDailyOutputController as Output;
  $peso = fn ($v) => '₱' . number_format((float) $v, 2);
  $budget = fn ($v) => $v === null ? '—' : '₱' . number_format((float) $v, 0);
  $dayLabel = \Carbon\Carbon::parse($day)->format('D, M j, Y');
  $tileDefs = [
    'new' => ['New', 'bagong ads', 'text-green-700'],
    'off' => ['Turned off', 'ads', 'text-red-700'],
    'on' => ['Back on', 'ads', 'text-blue-700'],
    'budget_up' => ['Budget up', 'ad sets', 'text-emerald-700'],
    'budget_down' => ['Budget down', 'ad sets', 'text-amber-700'],
  ];
@endphp
<x-layout>
  <x-slot name="title">Ads Daily Output</x-slot>
  <x-slot name="heading">
    <div class="text-xl font-bold">📣 Ads Daily Output</div>
  </x-slot>

  <form method="GET" action="{{ route('ads_manager.daily_output') }}" class="mt-4 mb-4 px-4 flex gap-3 flex-wrap items-end">
    <a href="{{ route('ads_manager.daily_output', ['day' => $prevDay]) }}" class="border rounded px-3 py-2 bg-white hover:bg-gray-100" data-test="prev-day">‹ {{ \Carbon\Carbon::parse($prevDay)->format('M j') }}</a>
    <div>
      <label class="text-sm font-medium" for="day">Araw</label>
      <input id="day" type="date" name="day" value="{{ $day }}" class="border rounded px-2 py-1" />
    </div>
    <button type="submit" class="bg-gray-700 text-white px-4 py-2 rounded hover:bg-gray-800">Tingnan</button>
    @if ($nextDay)
      <a href="{{ route('ads_manager.daily_output', ['day' => $nextDay]) }}" class="border rounded px-3 py-2 bg-white hover:bg-gray-100" data-test="next-day">{{ \Carbon\Carbon::parse($nextDay)->format('M j') }} ›</a>
    @endif
    <div class="text-sm">
      <div class="font-semibold">{{ $dayLabel }}@if ($isToday) <span class="text-xs text-gray-500">(ngayon)</span>@endif</div>
      <div class="text-xs text-gray-600" data-test="last-sync">Huling sync: <span class="font-mono">{{ $syncedAt ? \Carbon\Carbon::parse($syncedAt)->format('M j H:i') : '—' }}</span>
        @if ($isToday) · hindi pa buo ang araw @endif</div>
    </div>
  </form>

  {{-- Summary tiles --}}
  <div class="mx-4 mb-4 flex flex-wrap gap-3 text-sm" data-test="tiles">
    @foreach ($tileDefs as $key => [$label, $unit, $color])
      <div class="rounded border bg-white px-4 py-2 min-w-[8rem]" data-tile="{{ $key }}">
        <div class="text-xs uppercase tracking-wide text-gray-500">{{ $label }}</div>
        <div class="text-2xl font-bold {{ $color }}" data-test="count">{{ $tiles[$key] }}</div>
        <div class="text-xs text-gray-500">{{ $unit }}@if ($key === 'off' && $tiles['off']) sa {{ $tiles['off_campaigns'] }} campaigns @endif</div>
      </div>
    @endforeach
    <div class="rounded border bg-white px-4 py-2 min-w-[8rem]" data-tile="running">
      <div class="text-xs uppercase tracking-wide text-gray-500">Running</div>
      <div class="text-2xl font-bold" data-test="count">{{ $tiles['running_ads'] }}</div>
      <div class="text-xs text-gray-500">ads sa <span data-test="pages">{{ $tiles['running_pages'] }}</span> pages</div>
    </div>
  </div>
  <p class="mx-4 mb-3 text-xs text-gray-500">Mula sa gastos (spend) ng bawat ad: <b>Turned off</b> = may gastos kahapon, wala ngayon; <b>Back on</b> = walang gastos kahapon, may gastos ngayon (lumang ad); <b>New</b> = unang nakita ngayon; <b>Budget</b> = iba ang ad set budget kaysa kahapon. Hindi naka-tala kung sino ang gumawa.</p>

  {{-- 14 araw --}}
  <div class="mx-4 mb-6 overflow-x-auto">
    <table class="text-sm border" data-test="mini">
      <thead class="bg-gray-200">
        <tr>
          <th class="border px-3 py-1 text-left">Araw</th>
          <th class="border px-3 py-1 text-right">New</th>
          <th class="border px-3 py-1 text-right">Off</th>
          <th class="border px-3 py-1 text-right">Back on</th>
          <th class="border px-3 py-1 text-right">Budget changes</th>
        </tr>
      </thead>
      <tbody>
        @foreach ($mini as $m)
          <tr data-mini="{{ $m['day'] }}" class="{{ $m['day'] === $day ? 'bg-yellow-50 font-semibold' : '' }}">
            <td class="border px-3 py-1 whitespace-nowrap"><a class="text-blue-700 hover:underline" href="{{ route('ads_manager.daily_output', ['day' => $m['day']]) }}">{{ \Carbon\Carbon::parse($m['day'])->format('D, M j') }}</a></td>
            <td class="border px-3 py-1 text-right" data-col="new">{{ $m['new'] }}</td>
            <td class="border px-3 py-1 text-right" data-col="off">{{ $m['off'] }}</td>
            <td class="border px-3 py-1 text-right" data-col="on">{{ $m['on'] }}</td>
            <td class="border px-3 py-1 text-right" data-col="budget">{{ $m['budget_up'] + $m['budget_down'] }}
              @if ($m['budget_up'] + $m['budget_down'])<span class="text-xs text-gray-500">(▲{{ $m['budget_up'] }} ▼{{ $m['budget_down'] }})</span>@endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>

  {{-- Isang listahan bawat uri: page → campaign → ad set --}}
  <div class="px-4 pb-8 space-y-6">
    @foreach (Output::KINDS as $kind => $label)
      @php $isBudget = str_starts_with($kind, 'budget'); @endphp
      <section data-list="{{ $kind }}">
        <h2 class="text-lg font-semibold mb-2">{{ $label }} <span class="text-sm font-normal text-gray-500">({{ $tiles[$kind] }})</span></h2>
        @if (!$lists[$kind])
          <div class="text-sm text-gray-500 italic" data-test="empty">Walang galaw</div>
        @else
          <div class="overflow-x-auto">
            <table class="min-w-full text-sm border">
              <thead class="bg-gray-100">
                <tr>
                  <th class="border px-2 py-1 text-left">Page</th>
                  <th class="border px-2 py-1 text-left">Campaign</th>
                  <th class="border px-2 py-1 text-left">Promo</th>
                  <th class="border px-2 py-1 text-left">Ad set</th>
                  <th class="border px-2 py-1 text-right">Ads</th>
                  @if ($isBudget)<th class="border px-2 py-1 text-right">Budget</th>@endif
                  <th class="border px-2 py-1 text-right">Spend kahapon</th>
                  <th class="border px-2 py-1 text-right">Spend ngayon</th>
                </tr>
              </thead>
              <tbody>
                @foreach ($lists[$kind] as $page => $campaigns)
                  @foreach ($campaigns as $campaign => $c)
                    @foreach ($c['rows'] as $i => $r)
                      <tr data-page="{{ $page }}" data-campaign="{{ $campaign }}" class="align-top">
                        <td class="border px-2 py-1 whitespace-nowrap">{{ $page }}</td>
                        <td class="border px-2 py-1">{{ $campaign }}</td>
                        <td class="border px-2 py-1 whitespace-nowrap" data-col="promo">{{ $c['promo'] ?? '—' }}</td>
                        <td class="border px-2 py-1">{{ $r['ad_set'] }}</td>
                        <td class="border px-2 py-1 text-right" data-col="ads">{{ $r['ads'] }}</td>
                        @if ($isBudget)
                          <td class="border px-2 py-1 text-right whitespace-nowrap font-mono" data-col="budget">{{ $budget($r['prev_budget']) }} → {{ $budget($r['budget']) }}</td>
                        @endif
                        <td class="border px-2 py-1 text-right font-mono" data-col="prev-spend">{{ $peso($r['prev_spend']) }}</td>
                        <td class="border px-2 py-1 text-right font-mono" data-col="spend">{{ $peso($r['spend']) }}</td>
                      </tr>
                    @endforeach
                  @endforeach
                @endforeach
              </tbody>
            </table>
          </div>
        @endif
      </section>
    @endforeach
  </div>
</x-layout>

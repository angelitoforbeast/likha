<x-layout>
  <x-slot name="heading">Order encode: Likha vs the final order (all pages)</x-slot>

  @php
    $fmt = fn ($v, $suffix = '%') => $v === null ? '–' : rtrim(rtrim(number_format($v, 1), '0'), '.') . $suffix;
    $label = fn ($f) => ucwords(str_replace('_', ' ', $f));
    $q = fn (array $over) => route('order-encode.final', array_filter($over + $filters, fn ($v) => $v !== '' && $v !== null));
    $arrow = fn ($col) => $filters['sort'] === $col ? ($filters['dir'] === 'asc' ? ' ▲' : ' ▼') : '';
    $next = fn ($col) => $q(['sort' => $col, 'dir' => $filters['sort'] === $col && $filters['dir'] === 'asc' ? 'desc' : 'asc']);
    $rowLabels = ['untouched' => 'PROCEED, untouched', 'edited' => 'PROCEED, after staff edit', 'not' => 'Not PROCEED'];
  @endphp

  <div class="mb-4 flex gap-2 text-sm">
    <a href="{{ route('order-encode.index') }}" class="rounded border px-3 py-1 text-gray-700">Sheet compare</a>
    <a href="{{ route('order-encode.final') }}" class="rounded bg-blue-500 px-3 py-1 text-white">All pages (vs final order)</a>
  </div>

  @if (session('status'))<div class="mb-3 rounded bg-green-100 px-3 py-2 text-sm text-green-800">{{ session('status') }}</div>@endif
  @if ($errors->any())<div class="mb-3 rounded bg-red-100 px-3 py-2 text-sm text-red-800">{{ $errors->first() }}</div>@endif

  {{-- Import shadow: the switch, the cap and the skip list --}}
  <div class="mb-4 rounded bg-white p-3 text-sm shadow">
    <div class="mb-2 font-semibold">Import shadow (every page, from the Likha import)</div>
    <div class="mb-2 flex flex-wrap gap-x-6 gap-y-1 text-gray-700">
      <span>Switch: <b>{{ $import['on'] ? 'on' : 'off' }}</b></span>
      <span>Daily cap: <b>{{ $import['cap'] }}</b></span>
      <span>Skipped pages: <b>{{ $import['skip_pages'] !== '' ? $import['skip_pages'] : 'none' }}</b></span>
      <span>Encoded today: <b>{{ $import['encoded_today'] }}</b></span>
      <span>Skipped today: <b>{{ $import['skipped_cap'] }}</b> over the cap, <b>{{ $import['skipped_pages'] }}</b> skipped page</span>
      <span>Rules: <b>{{ $import['rules'] }}</b></span>
      <span>Estimated GPT cost of the orders below: <b>${{ number_format($import['cost'], 2) }}</b></span>
    </div>
    @if ($import['can_edit'])
      <form method="POST" action="{{ route('order-encode.import-settings') }}" class="flex flex-wrap items-end gap-3">
        @csrf
        <label class="flex flex-col">Switch
          <select name="switch" class="border rounded px-2 py-1">
            <option value="off" @selected(!$import['on'])>off</option>
            <option value="on" @selected($import['on'])>on</option>
          </select>
        </label>
        <label class="flex flex-col">Rules
          <select name="rules" class="border rounded px-2 py-1">
            <option value="improved" @selected($import['rules'] === 'improved')>improved</option>
            <option value="sheet" @selected($import['rules'] === 'sheet')>sheet (exact script copy)</option>
          </select>
        </label>
        <label class="flex flex-col">Daily cap
          <input type="number" name="cap" min="0" max="100000" value="{{ $import['cap'] }}" class="border rounded px-2 py-1 w-28">
        </label>
        <label class="flex flex-col">Skip pages (comma list)
          <input type="text" name="skip_pages" value="{{ $import['skip_pages'] }}" class="border rounded px-2 py-1 w-96">
        </label>
        <button type="submit" class="bg-blue-500 text-white px-3 py-1 rounded">Save</button>
      </form>
    @endif
  </div>

  {{-- Filters --}}
  <form method="GET" action="{{ route('order-encode.final') }}" class="mb-4 flex flex-wrap items-end gap-3 text-sm">
    <label class="flex flex-col">From
      <input type="date" name="from" value="{{ $filters['from'] }}" class="border rounded px-2 py-1">
    </label>
    <label class="flex flex-col">To
      <input type="date" name="to" value="{{ $filters['to'] }}" class="border rounded px-2 py-1">
    </label>
    <label class="flex flex-col">Page
      <select name="page" class="border rounded px-2 py-1">
        <option value="">All pages</option>
        @foreach ($pages as $p)
          <option value="{{ $p }}" @selected($filters['page'] === $p)>{{ $p }}</option>
        @endforeach
      </select>
    </label>
    <button type="submit" class="bg-blue-500 text-white px-3 py-1 rounded">Apply</button>
  </form>

  {{-- Tiles --}}
  <div class="mb-4 grid grid-cols-2 gap-3 text-sm md:grid-cols-4 lg:grid-cols-6">
    <div class="rounded bg-white p-3 shadow"><div class="text-gray-500">Orders</div><div class="text-xl font-semibold">{{ $tiles['orders'] }}</div><div class="text-xs text-gray-500">{{ $tiles['compared'] }} compared</div></div>
    <div class="rounded bg-white p-3 shadow"><div class="text-gray-500">Location equal</div><div class="text-xl font-semibold">{{ $fmt($tiles['location_pct']) }}</div><div class="text-xs text-gray-500">province, city and barangay</div></div>
    <div class="rounded bg-white p-3 shadow"><div class="text-gray-500">Address equal</div><div class="text-xl font-semibold">{{ $fmt($tiles['address_pct']) }}</div></div>
    <div class="rounded bg-white p-3 shadow"><div class="text-gray-500">Item and COD equal</div><div class="text-xl font-semibold">{{ $fmt($tiles['item_cod_pct']) }}</div></div>
    <div class="rounded bg-white p-3 shadow"><div class="text-gray-500">Waiting for the final order</div><div class="text-xl font-semibold">{{ $tiles['waiting'] }}</div></div>
    <div class="rounded bg-white p-3 shadow"><div class="text-gray-500">Sheet never finished</div><div class="text-xl font-semibold">{{ $tiles['never'] }}</div><div class="text-xs text-gray-500">encode failed: {{ $tiles['failed'] }}</div></div>
  </div>

  <div class="mb-4 rounded bg-white p-3 text-sm shadow">
    <span class="font-semibold">Learned notes and rules in this range:</span>
    NOTE_HIT <b>{{ $tiles['note_hits'] }}</b>
    @forelse ($ruleHolds as $rule => $n) · held by {{ $rule }} <b>{{ $n }}</b> @empty · held by a rule <b>0</b> @endforelse
    <a href="{{ route('order-encode.notes') }}" class="ml-3 text-blue-600 underline">Address notes</a>
  </div>

  {{-- PROCEED table --}}
  <div class="mb-4 overflow-x-auto rounded bg-white shadow">
    <table class="min-w-full text-sm">
      <thead class="bg-gray-100 text-left">
        <tr><th class="px-3 py-1">Final order</th><th class="px-3 py-1">Likha would PROCEED</th><th class="px-3 py-1">Likha would hold</th></tr>
      </thead>
      <tbody>
        @foreach ($rowLabels as $key => $text)
          @php($sum = $proceed[$key]['proceed'] + $proceed[$key]['hold'])
          <tr class="border-t">
            <td class="px-3 py-1">{{ $text }} <span class="text-gray-500">({{ $sum }})</span></td>
            @foreach (['proceed', 'hold'] as $col)
              <td class="px-3 py-1">{{ $proceed[$key][$col] }} <span class="text-gray-500">{{ $sum > 0 ? '(' . $fmt($proceed[$key][$col] / $sum * 100) . ')' : '' }}</span></td>
            @endforeach
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>

  {{-- Per page --}}
  <div class="mb-4 overflow-x-auto rounded bg-white shadow">
    <table class="min-w-full text-sm">
      <thead class="bg-gray-100 text-left">
        <tr>
          <th class="px-3 py-1"><a href="{{ $next('page') }}">Page{{ $arrow('page') }}</a></th>
          <th class="px-3 py-1"><a href="{{ $next('orders') }}">Orders{{ $arrow('orders') }}</a></th>
          <th class="px-3 py-1"><a href="{{ $next('location_pct') }}">Location equal{{ $arrow('location_pct') }}</a></th>
          <th class="px-3 py-1"><a href="{{ $next('proceed_pct') }}">PROCEED agreement{{ $arrow('proceed_pct') }}</a></th>
        </tr>
      </thead>
      <tbody>
        @forelse ($pageRows as $r)
          <tr class="border-t"><td class="px-3 py-1">{{ $r['page'] }}</td><td class="px-3 py-1">{{ $r['orders'] }}</td><td class="px-3 py-1">{{ $fmt($r['location_pct']) }}</td><td class="px-3 py-1">{{ $fmt($r['proceed_pct']) }}</td></tr>
        @empty
          <tr><td class="px-3 py-4 text-center text-gray-500" colspan="4">No orders in this range.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>

  {{-- Disagreements --}}
  <div class="mb-1 font-semibold text-sm">Disagreements ({{ $paginator->total() }})</div>
  <div class="overflow-x-auto rounded bg-white shadow">
    <table class="min-w-full text-xs">
      <thead class="bg-gray-100 text-left">
        <tr>
          <th class="px-2 py-1">Order</th>
          @foreach (array_keys($rows[0]['cells'] ?? []) as $f)<th class="px-2 py-1">{{ $label($f) }}</th>@endforeach
        </tr>
      </thead>
      <tbody>
        @forelse ($rows as $r)
          <tr class="border-t align-top">
            <td class="px-2 py-1 whitespace-nowrap" title="{{ $r['title'] }}">
              #{{ $r['id'] }} · {{ $r['source'] }} · macro_output #{{ $r['macro_id'] }}<br>{{ $r['page'] }}<br><span class="text-gray-500">{{ $r['at'] }}</span>
              @if ($r['note_hit'])<div class="mt-1 rounded bg-blue-100 px-1 text-blue-800">NOTE_HIT</div>@endif
              @if ($r['hold'])<div class="mt-1 rounded bg-orange-100 px-1 text-orange-800" title="{{ $r['hold']['reason'] ?? '' }}">held by {{ $r['hold']['rule'] ?? '' }}: {{ $r['hold']['reason'] ?? '' }}</div>@endif
              @if ($r['edited'])<div class="mt-1 rounded bg-yellow-100 px-1 text-yellow-800" title="{{ implode(', ', $r['edited_flags']) }}">edited by staff</div>@endif
            </td>
            @foreach ($r['cells'] as $f => $c)
              @php($bad = $c['result'] !== null && $c['result'] !== 'same')
              <td class="px-2 py-1 {{ $bad ? 'bg-red-100' : '' }}" title="{{ $c['result'] }}">
                <div><span class="text-gray-400">L</span> {{ $c['likha'] }}</div>
                <div><span class="text-gray-400">F</span> {{ $c['final'] }}</div>
              </td>
            @endforeach
          </tr>
        @empty
          <tr><td class="px-2 py-4 text-center text-gray-500" colspan="9">No disagreements in this range.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>

  <div class="mt-3">{{ $paginator->links() }}</div>
</x-layout>

<x-layout>
  <x-slot name="heading">Order encode: Likha vs the Google Sheet</x-slot>

  @php
    $fmt = fn ($v, $suffix = '%') => $v === null ? '–' : rtrim(rtrim(number_format($v, 1), '0'), '.') . $suffix;
    $label = fn ($f) => ucwords(str_replace('_', ' ', $f));
  @endphp

  {{-- Filters --}}
  <form method="GET" action="{{ route('order-encode.index') }}" class="mb-4 flex flex-wrap items-end gap-3 text-sm">
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
    <label class="flex flex-col">Field
      <select name="field" class="border rounded px-2 py-1">
        <option value="">Any field</option>
        @foreach ($fields as $f)
          <option value="{{ $f }}" @selected($filters['field'] === $f)>{{ $label($f) }}</option>
        @endforeach
      </select>
    </label>
    <label class="flex items-center gap-1 pb-1"><input type="checkbox" name="only_diff" value="1" @checked($filters['only_diff'])> Only differences</label>
    <button type="submit" class="bg-blue-500 text-white px-3 py-1 rounded">Apply</button>
  </form>

  {{-- Summary --}}
  <div class="mb-4 grid grid-cols-2 gap-3 text-sm md:grid-cols-4 lg:grid-cols-6">
    <div class="rounded bg-white p-3 shadow"><div class="text-gray-500">Orders</div><div class="text-xl font-semibold">{{ $stats['orders'] }}</div></div>
    <div class="rounded bg-white p-3 shadow"><div class="text-gray-500">Compared</div><div class="text-xl font-semibold">{{ $stats['compared'] }}</div></div>
    <div class="rounded bg-white p-3 shadow"><div class="text-gray-500">Fully equal</div><div class="text-xl font-semibold">{{ $fmt($stats['equal_pct']) }}</div><div class="text-xs text-gray-500">{{ $stats['equal'] }} of {{ $stats['compared'] }}</div></div>
    <div class="rounded bg-white p-3 shadow"><div class="text-gray-500">Waiting</div><div class="text-xl font-semibold">{{ $stats['waiting'] }}</div></div>
    <div class="rounded bg-white p-3 shadow"><div class="text-gray-500">Sheet not done</div><div class="text-xl font-semibold">{{ $stats['sheet_not_done'] }}</div></div>
    <div class="rounded bg-white p-3 shadow"><div class="text-gray-500">Row mismatch</div><div class="text-xl font-semibold">{{ $stats['row_mismatch'] }}</div></div>
    <div class="rounded bg-white p-3 shadow"><div class="text-gray-500">Encode failed</div><div class="text-xl font-semibold">{{ $stats['failed'] }}</div></div>
    <div class="rounded bg-white p-3 shadow"><div class="text-gray-500">GPT calls per order</div><div class="text-xl font-semibold">{{ $stats['calls_per_order'] === null ? '–' : number_format($stats['calls_per_order'], 2) }}</div></div>
    <div class="rounded bg-white p-3 shadow"><div class="text-gray-500">Estimated cost per order</div><div class="text-xl font-semibold">{{ $stats['cost_per_order'] === null ? '–' : '$' . number_format($stats['cost_per_order'], 4) }}</div></div>
  </div>

  <div class="mb-4 flex flex-wrap gap-2 text-xs">
    @foreach ($stats['field_pct'] as $f => $p)
      <span class="rounded bg-white px-2 py-1 shadow">{{ $label($f) }}: <b>{{ $fmt($p) }}</b> same</span>
    @endforeach
  </div>

  {{-- Orders --}}
  <div class="overflow-x-auto rounded bg-white shadow">
    <table class="min-w-full text-xs">
      <thead class="bg-gray-100 text-left">
        <tr>
          <th class="px-2 py-1">Order</th>
          <th class="px-2 py-1">State</th>
          @foreach ($fields as $f)<th class="px-2 py-1">{{ $label($f) }}</th>@endforeach
        </tr>
      </thead>
      <tbody>
        @forelse ($rows as $r)
          <tr class="border-t align-top">
            <td class="px-2 py-1 whitespace-nowrap" title="{{ $r['title'] }}">
              #{{ $r['id'] }} · row {{ $r['row'] }}<br>{{ $r['page'] }}<br><span class="text-gray-500">{{ $r['at'] }}</span>
            </td>
            <td class="px-2 py-1 whitespace-nowrap" @if($r['error']) title="{{ $r['error'] }}" @endif>{{ $r['state'] }}</td>
            @foreach ($r['cells'] as $f => $c)
              @php($bad = in_array($c['result'], ['different', 'sheet_blank', 'likha_blank'], true))
              <td class="px-2 py-1 {{ $bad ? 'bg-red-100' : '' }}" title="{{ $c['result'] }}">
                <div><span class="text-gray-400">L</span> {{ $c['likha'] }}</div>
                <div><span class="text-gray-400">S</span> {{ $c['sheet'] }}</div>
              </td>
            @endforeach
          </tr>
        @empty
          <tr><td class="px-2 py-4 text-center text-gray-500" colspan="{{ count($fields) + 2 }}">No orders in this range.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>

  <div class="mt-3">{{ $paginator->links() }}</div>
</x-layout>

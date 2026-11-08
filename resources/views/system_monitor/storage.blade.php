{{-- Storage view (spec 242): read-only numbers for the CEO and the marketing OIC; no buttons, nothing changes here. --}}
@php
  $gb = fn ($bytes) => number_format($bytes / 1024 ** 3, 1) . ' GB';
@endphp
<x-layout>
  <x-slot name="title">Storage</x-slot>
  <x-slot name="heading">Server storage</x-slot>

  <div class="flex flex-col gap-4">
    <div class="rounded-lg border border-gray-200 bg-white">
      <div class="border-b border-gray-100 px-4 py-2 text-sm font-semibold text-gray-900">Disk (/)</div>
      @if ($disk)
        <dl class="grid grid-cols-2 gap-4 p-4 sm:grid-cols-4">
          <div><dt class="text-xs uppercase text-gray-500">Total</dt><dd class="text-lg font-bold text-gray-900">{{ $gb($disk['total']) }}</dd></div>
          <div><dt class="text-xs uppercase text-gray-500">Used</dt><dd class="text-lg font-bold text-gray-900">{{ $gb($disk['used']) }}</dd></div>
          <div><dt class="text-xs uppercase text-gray-500">Free</dt><dd class="text-lg font-bold text-gray-900">{{ $gb($disk['free']) }}</dd></div>
          <div><dt class="text-xs uppercase text-gray-500">Used %</dt><dd class="text-lg font-bold text-gray-900">{{ number_format($disk['percent'], 1) }}%</dd></div>
        </dl>
      @else
        <p class="p-4 text-sm text-gray-500">Not available</p>
      @endif
    </div>

    <div class="rounded-lg border border-gray-200 bg-white">
      <div class="border-b border-gray-100 px-4 py-2 text-sm font-semibold text-gray-900">Database</div>
      <dl class="grid grid-cols-1 gap-4 p-4 sm:grid-cols-2">
        <div>
          <dt class="text-xs uppercase text-gray-500">Database size</dt>
          <dd class="text-lg font-bold text-gray-900">{{ $databaseBytes === null ? 'Not available' : $gb($databaseBytes) }}</dd>
        </div>
        <div>
          <dt class="text-xs uppercase text-gray-500">MySQL binary logs</dt>
          <dd class="text-lg font-bold text-gray-900">
            @if ($binaryLogs === null)
              Not available
            @else
              {{ $gb($binaryLogs['bytes']) }} <span class="text-sm font-normal text-gray-500">in {{ $binaryLogs['files'] }} {{ $binaryLogs['files'] === 1 ? 'file' : 'files' }}</span>
            @endif
          </dd>
        </div>
      </dl>
    </div>
  </div>
</x-layout>

{{-- Storage view (spec 242, 243): read-only numbers for the roles checked for Storage in nav settings; no buttons, nothing changes here. --}}
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

    {{-- Server stats (spec 243 S4): the CEO monitor's numbers and formats. Its reader leaves 0 (or null for the load)
         where /proc or sys_getloadavg could not be read, so those values mean "Not available" here. --}}
    @php
      $memoryRead = ($server['memory_total_mb'] ?? 0) > 0;
      $mbOrGb = fn ($mb) => $mb < 1024 ? number_format($mb) . ' MB' : number_format($mb / 1024, 2) . ' GB';
      $uptime = (int) ($server['uptime_seconds'] ?? 0);
      $uptimeText = intdiv($uptime, 86400) > 0
          ? intdiv($uptime, 86400) . 'd ' . intdiv($uptime % 86400, 3600) . 'h ' . intdiv($uptime % 3600, 60) . 'm'
          : (intdiv($uptime, 3600) > 0 ? intdiv($uptime, 3600) . 'h ' . intdiv($uptime % 3600, 60) . 'm' : intdiv($uptime, 60) . 'm');
    @endphp
    <div class="rounded-lg border border-gray-200 bg-white">
      <div class="border-b border-gray-100 px-4 py-2 text-sm font-semibold text-gray-900">Server stats</div>
      <dl class="grid grid-cols-2 gap-4 p-4 sm:grid-cols-5">
        <div>
          <dt class="text-xs uppercase text-gray-500">Memory</dt>
          @if ($memoryRead)
            <dd class="text-lg font-bold text-gray-900">{{ number_format($server['memory_used_mb']) }} / {{ number_format($server['memory_total_mb']) }} MB</dd>
            <dd class="text-sm text-gray-500">{{ $server['memory_used_percent'] }}%</dd>
          @else
            <dd class="text-lg font-bold text-gray-900">Not available</dd>
          @endif
        </div>
        <div>
          <dt class="text-xs uppercase text-gray-500">Memory available</dt>
          <dd class="text-lg font-bold text-gray-900">{{ $memoryRead ? $mbOrGb($server['memory_available_mb']) : 'Not available' }}</dd>
        </div>
        <div>
          <dt class="text-xs uppercase text-gray-500">Swap</dt>
          <dd class="text-lg font-bold text-gray-900">{{ $memoryRead ? number_format($server['swap_used_mb']) . ' / ' . number_format($server['swap_total_mb']) . ' MB' : 'Not available' }}</dd>
        </div>
        <div>
          <dt class="text-xs uppercase text-gray-500">CPU load</dt>
          @if (($server['load_1m'] ?? null) !== null)
            <dd class="text-lg font-bold text-gray-900">{{ $server['load_1m'] }} / {{ $server['load_5m'] }} / {{ $server['load_15m'] }}</dd>
            <dd class="text-sm text-gray-500">1 / 5 / 15 min{{ ($server['cpu_count'] ?? 0) > 0 ? ', ' . $server['cpu_count'] . ' cores' : '' }}</dd>
          @else
            <dd class="text-lg font-bold text-gray-900">Not available</dd>
          @endif
        </div>
        <div>
          <dt class="text-xs uppercase text-gray-500">Uptime</dt>
          <dd class="text-lg font-bold text-gray-900">{{ $uptime > 0 ? $uptimeText : 'Not available' }}</dd>
        </div>
      </dl>
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

{{-- One sync run (MetaAdsController::runSummary). Drawn on the page and again by the status poll, so both look the same. --}}
@php
  $badge = [
    'queued'  => 'bg-gray-100 text-gray-700',
    'running' => 'bg-blue-100 text-blue-800',
    'done'    => 'bg-green-100 text-green-800',
    'partial' => 'bg-amber-100 text-amber-900',
    'failed'  => 'bg-red-100 text-red-800',
  ][$run['state']] ?? 'bg-gray-100 text-gray-700';
  $label = ['queued' => 'Queued', 'running' => 'Running', 'done' => 'Done', 'partial' => 'Partial', 'failed' => 'Failed'][$run['state']] ?? $run['state'];
  $problemText = fn ($p) => $p['account'] ? (($p['name'] ? $p['name'] . ' (' . $p['account'] . ')' : $p['account']) . ': ' . $p['error']) : $p['error'];
  $shown = array_slice($run['problems'], 0, 3);
  $more  = array_slice($run['problems'], 3);
@endphp
<div id="meta-sync-run" class="text-sm border rounded p-2 space-y-1" data-state="{{ $run['state'] }}" data-id="{{ $run['id'] }}"
     data-status-url="{{ route('ads_manager.meta.sync.status', ['id' => $run['id']]) }}">
  <div class="flex flex-wrap items-center gap-2">
    <span class="font-semibold">Last sync #{{ $run['id'] }}</span>
    <span class="text-xs px-2 py-0.5 rounded {{ $badge }}" data-run-state>{{ $label }}</span>
    <span class="text-xs text-gray-600">{{ $run['range'] }} · {{ $run['who'] }}</span>
  </div>

  @if ($run['state'] === 'queued')
    <div class="text-xs text-gray-600">Waiting for the worker (queued {{ $run['queued_at'] }}).</div>
  @elseif ($run['state'] === 'running')
    <div class="text-xs text-gray-700" data-run-progress>
      @if ($run['accounts_total'] !== null)
        Accounts {{ $run['accounts_done'] }} of {{ $run['accounts_total'] }} ·
      @endif
      rows so far {{ number_format($run['rows']) }} · +{{ number_format($run['inserted']) }} new / ~{{ number_format($run['updated']) }} updated
      @if ($run['account']) · reading {{ $run['account'] }} @endif
      @if ($run['duration']) · {{ $run['duration'] }} @endif
    </div>
  @else
    <div class="text-xs text-gray-700" data-run-result>
      +{{ number_format($run['inserted']) }} new / ~{{ number_format($run['updated']) }} updated / skip {{ number_format($run['skipped']) }}
      · {{ number_format($run['rows']) }} rows
      @if ($run['duration']) · {{ $run['duration'] }} @endif
      @if ($run['finished_at']) · ended {{ $run['finished_at'] }} @endif
    </div>
  @endif

  @if ($run['problems'])
    <ul class="text-xs text-red-700 list-disc pl-5" data-run-problems>
      @foreach ($shown as $p)
        <li>{{ $problemText($p) }}</li>
      @endforeach
    </ul>
    @if ($more)
      <details class="text-xs text-red-700 pl-5" data-run-more>
        <summary class="cursor-pointer">{{ count($more) }} more</summary>
        <ul class="list-disc pl-5">
          @foreach ($more as $p)
            <li>{{ $problemText($p) }}</li>
          @endforeach
        </ul>
      </details>
    @endif
  @endif
</div>

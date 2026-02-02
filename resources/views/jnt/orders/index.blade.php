{{-- ✅ resources/views/jnt/orders/index.blade.php --}}
<x-layout>
  <x-slot name="title">J&T Orders</x-slot>
  <x-slot name="heading">J&T Orders</x-slot>

  @php
    $selectedDate = $date ?? request('date') ?? now('Asia/Manila')->toDateString();
    $selectedPage = $page ?? request('page') ?? '';
    $runId        = request('run_id');

    $pages = $pages ?? [];

    // rows always exists (preview OR run)
    $rows = $rows ?? [];

    $run = $run ?? null;

    $runStatus = data_get($run, 'status', ''); // initial only
    $isRunView = !empty($runId);
    $isRunning = $isRunView && in_array((string)$runStatus, ['running','queued','processing'], true);

    // initial stats (optional)
    $total   = (int) data_get($runStats, 'total', 0);
    $ok      = (int) data_get($runStats, 'ok', 0);
    $fail    = (int) data_get($runStats, 'fail', 0);
    $pending = (int) data_get($runStats, 'pending', 0);
    $processed = $ok + $fail;

    $prettyJson = function($v) {
      if ($v === null || $v === '') return '';
      if (is_array($v)) return json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
      if (is_object($v)) return json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

      if (is_string($v)) {
        $decoded = json_decode($v, true);
        if (json_last_error() === JSON_ERROR_NONE) {
          return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }
        return $v;
      }
      return (string) $v;
    };
  @endphp

  <style>
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
    .btn { display:inline-flex; align-items:center; gap:.5rem; border-radius:.5rem; padding:.5rem .75rem; font-size:.875rem; }
    .btn-blue { background:#2563eb; color:#fff; }
    .btn-blue:hover { background:#1d4ed8; }
    .btn-red { background:#dc2626; color:#fff; }
    .btn-red:hover { background:#b91c1c; }
    .btn-gray { background:#e5e7eb; color:#111827; }
    .btn-gray:hover { background:#d1d5db; }
    .btn-disabled { background:#e5e7eb; color:#6b7280; cursor:not-allowed; }
    .card { background:#fff; border:1px solid #e5e7eb; border-radius:1rem; }
    .muted { color:#6b7280; }
    .badge { font-size:.75rem; padding:.25rem .5rem; border-radius:999px; display:inline-flex; align-items:center; }
    .badge-green { background:#dcfce7; color:#166534; }
    .badge-red { background:#fee2e2; color:#991b1b; }
    .badge-yellow { background:#fef9c3; color:#854d0e; }
    .badge-gray { background:#f3f4f6; color:#374151; }
    pre { white-space:pre-wrap; word-break:break-word; }
    details > summary { cursor:pointer; }
  </style>

  {{-- Flash messages --}}
  @if(session('success'))
    <div class="mb-4 card p-3 border-green-200 bg-green-50 text-green-800">
      {{ session('success') }}
    </div>
  @endif
  @if(session('error'))
    <div class="mb-4 card p-3 border-red-200 bg-red-50 text-red-800">
      {{ session('error') }}
    </div>
  @endif

  {{-- Filter bar --}}
  <div class="card p-4 mb-4">
    <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
      <form method="GET" action="{{ url('jnt/orders') }}" class="flex flex-col gap-3 md:flex-row md:items-end">
        <div>
          <label class="block text-sm font-medium mb-1">Date</label>
          <input type="date" name="date" value="{{ $selectedDate }}"
                 class="border rounded-lg px-3 py-2 text-sm w-52">
        </div>

        <div>
          <label class="block text-sm font-medium mb-1">Page</label>
          <select name="page" class="border rounded-lg px-3 py-2 text-sm w-64">
            <option value="">-- Select Page --</option>
            @foreach(($pages ?? []) as $pVal)
              @if($pVal !== '')
                <option value="{{ $pVal }}" @selected($selectedPage === $pVal)>{{ $pVal }}</option>
              @endif
            @endforeach
          </select>
        </div>

        <div>
          <label class="block text-sm font-medium mb-1">Run ID</label>
          <input type="number" name="run_id" value="{{ $runId }}"
                 placeholder="(optional)"
                 class="border rounded-lg px-3 py-2 text-sm w-32">
        </div>

        <div class="flex gap-2">
          <button type="submit" class="btn btn-blue">Filter</button>

          @if($isRunView)
            <a class="btn btn-gray"
               href="{{ url('jnt/orders') }}?date={{ urlencode($selectedDate) }}&page={{ urlencode($selectedPage) }}">
              Back to Preview
            </a>
          @endif
        </div>
      </form>

      <div class="flex flex-col gap-2 md:items-end">
        @if($isRunView)
          <div class="text-sm">
            <div class="font-semibold">
              Viewing Run #{{ $runId }}
              <span class="muted">— status:</span>
              <span class="mono" id="jsStatus">{{ $runStatus ?? 'unknown' }}</span>
              <span class="muted">— processed</span>
              <span class="mono" id="jsProcessed">{{ $processed }}/{{ $total }}</span>
              <span class="muted">(ok</span> <span class="mono" id="jsOk">{{ $ok }}</span>,
              <span class="muted">fail</span> <span class="mono" id="jsFail">{{ $fail }}</span>,
              <span class="muted">pending</span> <span class="mono" id="jsPending">{{ $pending }}</span><span class="muted">)</span>
            </div>

            @if($isRunning)
              <div class="text-xs muted mt-1">
                Auto-refresh is ON while running.
                <button type="button" id="pauseAutoRefresh" class="underline ml-2">Pause</button>
              </div>
            @endif
          </div>

          <div class="flex gap-2">
            {{-- Print Run ZIP --}}
            <form method="POST" action="{{ url("jnt/orders/batch/{$runId}/print-zip") }}">
              @csrf
              <button type="submit" class="btn btn-gray">Print Run (ZIP)</button>
            </form>

            {{-- Stop run --}}
            <form method="POST" action="{{ url("jnt/orders/batch/{$runId}/stop") }}">
              @csrf
              <button type="submit" class="btn btn-red">Stop Run</button>
            </form>
          </div>
        @endif
      </div>
    </div>
  </div>

  {{-- Create batch card --}}
  <div class="card p-4 mb-4">
    <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
      <div>
        <div class="font-semibold">Batch</div>
        <div class="text-sm muted">
          Create shipments from Macro Output (date + page), then queue Create Order.
        </div>
      </div>

      <form method="POST" action="{{ url('jnt/orders/batch') }}" class="flex gap-2">
        @csrf
        <input type="hidden" name="date" value="{{ $selectedDate }}">
        <input type="hidden" name="page" value="{{ $selectedPage }}">
        <button type="submit"
          class="btn btn-blue {{ ($selectedDate && $selectedPage) ? '' : 'btn-disabled' }}"
          {{ ($selectedDate && $selectedPage) ? '' : 'disabled' }}>
          Create Batch (Queue)
        </button>
      </form>
    </div>
  </div>

  {{-- Main table --}}
  <div class="card overflow-x-auto">
    <table class="min-w-full text-sm">
      <thead class="bg-gray-50 text-gray-700">
        <tr>
          <th class="p-2 text-left">Shipment ID</th>
          <th class="p-2 text-left">Macro ID</th>
          <th class="p-2 text-left">Date</th>
          <th class="p-2 text-left">Page</th>
          <th class="p-2 text-left">Receiver</th>
          <th class="p-2 text-left">Prov / City / Brgy</th>
          <th class="p-2 text-left">Item</th>
          <th class="p-2 text-left">COD</th>
          <th class="p-2 text-left">Mailno</th>
          <th class="p-2 text-left">TX</th>
          <th class="p-2 text-left">Success</th>
          <th class="p-2 text-left">Reason</th>
          <th class="p-2 text-left">Print</th>
          <th class="p-2 text-left">Debug</th>
        </tr>
      </thead>

      <tbody class="divide-y">
        @php
          $list = $rows; // ✅ FIX: works for preview and run
        @endphp

        @forelse($list as $r)
          @php
            $shipmentId = data_get($r,'shipment_id', data_get($r,'id','-'));
            $macroId    = data_get($r,'macro_id', data_get($r,'macro_output_id', data_get($r,'macro_id','-')));

            $rowDate    = data_get($r,'ts_date','-');
            $rowPage    = data_get($r,'page','-');

            $name       = data_get($r,'full_name','-');
            $phone      = data_get($r,'phone_number','-');
            $addr       = data_get($r,'address','-');

            $prov       = data_get($r,'province','-');
            $city       = data_get($r,'city','-');
            $brgy       = data_get($r,'barangay','-');

            $item       = data_get($r,'item_name','-');
            $cod        = data_get($r,'cod','-');

            $mailno     = data_get($r,'mailno', null);
            $tx         = data_get($r,'txlogisticid', '-');

            $successVal = data_get($r,'success', null);
            $reason     = data_get($r,'reason', '-');

            $isSuccess  = ($successVal === true || (string)$successVal === '1');
            $debugHas   = false; // (optional mo later)
          @endphp

          <tr class="align-top">
            <td class="p-2 mono text-xs">{{ $shipmentId }}</td>
            <td class="p-2 mono text-xs">{{ $macroId }}</td>
            <td class="p-2 mono text-xs">{{ $rowDate }}</td>
            <td class="p-2 text-xs">{{ $rowPage }}</td>

            <td class="p-2">
              <div class="font-medium">{{ $name }}</div>
              <div class="mono text-xs muted">{{ $phone }}</div>
              <div class="text-xs mt-1">{{ $addr }}</div>
            </td>

            <td class="p-2">
              <div class="mono text-xs">{{ $prov }}</div>
              <div class="mono text-xs">{{ $city }}</div>
              <div class="mono text-xs">{{ $brgy }}</div>
            </td>

            <td class="p-2 text-xs">{{ $item }}</td>
            <td class="p-2 mono text-xs">{{ $cod }}</td>

            <td class="p-2 mono text-xs">{{ $mailno ?? '-' }}</td>
            <td class="p-2 mono text-xs">{{ $tx ?? '-' }}</td>

            <td class="p-2">
              @if($isRunView)
                @if($isSuccess)
                  <span class="badge badge-green">YES</span>
                @elseif($successVal === null || $successVal === '')
                  <span class="badge badge-yellow">PENDING</span>
                @else
                  <span class="badge badge-red">NO</span>
                @endif
              @else
                <span class="badge badge-gray">PREVIEW</span>
              @endif
            </td>

            <td class="p-2">
              <span class="text-xs text-gray-700">
                {{ $reason ?? '-' }}
              </span>
            </td>

            {{-- Print --}}
            <td class="p-2">
              @if($isRunView && !empty($mailno))
                <form method="POST" action="{{ url('jnt/orders/print-one') }}" target="_blank">
                  @csrf
                  <input type="hidden" name="shipment_id" value="{{ $shipmentId }}">
                  <button type="submit" class="btn btn-blue" style="padding:.35rem .6rem; font-size:.75rem;">
                    Print
                  </button>
                </form>
              @else
                <button disabled class="btn btn-disabled" style="padding:.35rem .6rem; font-size:.75rem;">
                  Print
                </button>
              @endif
            </td>

            {{-- Debug --}}
            <td class="p-2">
              <span class="text-xs muted">-</span>
            </td>
          </tr>
        @empty
          <tr>
            <td colspan="14" class="p-6 text-center muted">
              No rows found. Select Date + Page then press Filter.
            </td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>

  {{-- Auto-refresh --}}
  @if($isRunView)
    <script>
      (function () {
        const runId = @json((string)$runId);
        const statusUrl = @json(url("jnt/orders/batch/{$runId}/status"));
        const isRunning = @json($isRunning);

        let paused = false;

        const pauseBtn = document.getElementById('pauseAutoRefresh');
        if (pauseBtn) {
          pauseBtn.addEventListener('click', function () {
            paused = true;
            pauseBtn.textContent = 'Paused';
            pauseBtn.classList.add('text-gray-400');
          });
        }

        if (!isRunning) return;

        let lastProcessed = Number((document.getElementById('jsProcessed')?.textContent || '0/0').split('/')[0] || 0);

        async function tick() {
          if (paused) return;

          try {
            const res = await fetch(statusUrl, { headers: { 'Accept': 'application/json' } });
            if (!res.ok) return;

            const j = await res.json();

            // ✅ works with flat OR nested
            const status = (j.status ?? j.run?.status ?? '').toString();
            const processed = Number(j.processed ?? j.stats?.processed ?? 0);
            const total = Number(j.total ?? j.stats?.total ?? 0);
            const ok = Number(j.ok ?? j.stats?.ok ?? 0);
            const fail = Number(j.fail ?? j.stats?.fail ?? 0);
            const pending = Number(j.pending ?? j.stats?.pending ?? 0);

            const elStatus = document.getElementById('jsStatus');
            const elProcessed = document.getElementById('jsProcessed');
            const elOk = document.getElementById('jsOk');
            const elFail = document.getElementById('jsFail');
            const elPending = document.getElementById('jsPending');

            if (elStatus) elStatus.textContent = status || 'unknown';
            if (elProcessed) elProcessed.textContent = processed + '/' + total;
            if (elOk) elOk.textContent = ok;
            if (elFail) elFail.textContent = fail;
            if (elPending) elPending.textContent = pending;

            if (processed !== lastProcessed) {
              lastProcessed = processed;
              window.location.reload();
              return;
            }

            if (['finished','done','stopped'].includes(status)) {
              window.location.reload();
              return;
            }
          } catch (e) {}
        }

        setInterval(tick, 3000);
      })();
    </script>
  @endif
</x-layout>

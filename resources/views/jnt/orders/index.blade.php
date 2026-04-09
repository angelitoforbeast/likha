{{-- ✅ resources/views/jnt/orders/index.blade.php --}}
<x-layout>
  <x-slot name="title">J&T Orderss</x-slot>
  <x-slot name="heading">J&T Orderss</x-slot>

  @php
    $selectedDate = $date ?? request('date') ?? now('Asia/Manila')->toDateString();
    $selectedPage = $page ?? request('page') ?? '';
    $runId        = request('run_id');

    $pages = $pages ?? [];
    $rows  = $rows ?? [];
    $run   = $run ?? null;

    $senderPreview = $senderPreview ?? null;
    $accountCheck  = $accountCheck ?? (object)['ok' => true, 'account' => null, 'message' => null];

    // ✅ statusGate shape coming from controller:
    // enabled, ok, total_all, missing_status, proceed_total, invalid_proceed, message
    $statusGate = $statusGate ?? (object)[
      'enabled' => false,
      'ok' => true,
      'total_all' => 0,
      'missing_status' => 0,
      'proceed_total' => 0,
      'invalid_proceed' => 0,
      'message' => null,
    ];

    // ✅ if opened via ?run_id=123 only, recover date/page from run->filters
    if (!empty($runId) && $run && !empty(data_get($run, 'filters'))) {
      $runFilters = json_decode((string) data_get($run, 'filters'), true) ?: [];
      $selectedDate = $runFilters['date'] ?? $selectedDate;
      $selectedPage = $runFilters['page'] ?? $selectedPage;
    }

    $runStatus = data_get($run, 'status', '');
    $isRunView = !empty($runId);
    $isRunning = $isRunView && in_array((string)$runStatus, ['running','queued','processing'], true);

    $total   = (int) data_get($runStats, 'total', 0);
    $ok      = (int) data_get($runStats, 'ok', 0);
    $fail    = (int) data_get($runStats, 'fail', 0);
    $pending = (int) data_get($runStats, 'pending', 0);
    $processed = $ok + $fail;

    // ✅ Gate behavior:
    // - Preview view only (no run_id)
    // - Page must be selected
    // - If gate enabled and not ok -> block table + disable batch
    $gateEnabled = (!$isRunView && $selectedPage !== '' && (bool) data_get($statusGate, 'enabled', false));
    $gateOk      = (!$gateEnabled) ? true : (bool) data_get($statusGate, 'ok', true);
    $gateBlocked = $gateEnabled && !$gateOk;

    $accountOk      = (bool) data_get($accountCheck, 'ok', true);
    $canCreateBatch = ($selectedDate && $selectedPage && !$gateBlocked && $accountOk);
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
    .btn-yellow { background:#d97706; color:#fff; }
    .btn-yellow:hover { background:#b45309; }
    .card { background:#fff; border:1px solid #e5e7eb; border-radius:1rem; }
    .muted { color:#6b7280; }
    .badge { font-size:.75rem; padding:.25rem .5rem; border-radius:999px; display:inline-flex; align-items:center; }
    .badge-green { background:#dcfce7; color:#166534; }
    .badge-red { background:#fee2e2; color:#991b1b; }
    .badge-yellow { background:#fef9c3; color:#854d0e; }
    .badge-gray { background:#f3f4f6; color:#374151; }
  </style>

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

  {{-- ✅ Gate banner (preview only) --}}
  @if($gateBlocked)
    <div class="mb-4 card p-4 border-red-200 bg-red-50 text-red-800">
      <div class="font-semibold">BLOCKED</div>
      <div class="text-sm mt-1">
        {{ data_get($statusGate,'message') ?? 'STATUS/VALIDATION gate blocked.' }}
      </div>

      {{-- Optional details --}}
      <div class="text-xs mt-2">
        <div>
          Filter:
          <span class="mono">{{ $selectedDate }}</span>
          +
          <span class="mono">{{ $selectedPage }}</span>
        </div>
        <div class="mt-1">
          total_all: <span class="mono">{{ (int) data_get($statusGate,'total_all',0) }}</span>,
          missing_status: <span class="mono font-semibold">{{ (int) data_get($statusGate,'missing_status',0) }}</span>,
          proceed_total: <span class="mono">{{ (int) data_get($statusGate,'proceed_total',0) }}</span>,
          invalid_proceed: <span class="mono font-semibold">{{ (int) data_get($statusGate,'invalid_proceed',0) }}</span>
        </div>
      </div>
    </div>
  @endif

  {{-- Filter bar --}}
  <div class="card p-4 mb-4">
    <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
      <form id="filterForm" method="GET" action="{{ url('jnt/orders') }}" class="flex flex-col gap-3 md:flex-row md:items-end">
        <div>
          <label class="block text-sm font-medium mb-1">Date</label>
          <input id="jsDate" type="date" name="date" value="{{ $selectedDate }}" class="border rounded-lg px-3 py-2 text-sm w-52">
        </div>

        <div>
          <label class="block text-sm font-medium mb-1">Page</label>
          <select id="jsPage" name="page" class="border rounded-lg px-3 py-2 text-sm w-64">
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
          <input id="jsRunId" type="number" name="run_id" value="{{ $runId }}" placeholder="(optional)"
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

          <div class="flex gap-2 flex-wrap">
            <form method="POST" action="{{ url("jnt/orders/batch/{$runId}/print-zip") }}">
              @csrf
              <button type="submit" class="btn btn-gray">Print Run (ZIP)</button>
            </form>

            <form method="POST" action="{{ url("jnt/orders/batch/{$runId}/stop") }}">
              @csrf
              <button type="submit" class="btn btn-red">Stop Run</button>
            </form>

            @if(($fail ?? 0) > 0)
              <form method="POST" action="{{ url("jnt/orders/batch/{$runId}/retry-failed") }}"
                    onsubmit="return confirm('Retry {{ $fail }} failed shipment(s) in Run #{{ $runId }}?')">
                @csrf
                <button type="submit" class="btn btn-yellow">Retry Failed ({{ $fail }})</button>
              </form>
            @endif
          </div>
        @endif
      </div>
    </div>
  </div>

  {{-- ✅ Auto-submit behavior: date/page change = clear run_id (switch to preview) --}}
  <script>
    (function () {
      const form = document.getElementById('filterForm');
      const elDate = document.getElementById('jsDate');
      const elPage = document.getElementById('jsPage');
      const elRun  = document.getElementById('jsRunId');

      if (!form || !elDate || !elPage || !elRun) return;

      elDate.addEventListener('change', function () {
        elPage.value = '';
        elRun.value = '';
        form.submit();
      });

      elPage.addEventListener('change', function () {
        elRun.value = '';
        form.submit();
      });
    })();
  </script>

  {{-- ✅ Sender Preview card (BEFORE Create Batch) --}}
  <div class="card p-4 mb-4">
    <div class="flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
      <div>
        <div class="font-semibold">Sender Preview</div>
        <div class="text-sm muted">
          Makikita mo muna dito yung sender details na gagamitin bago mag Create Batch (Queue).
        </div>
      </div>

      <div class="text-xs muted">
        src: {{ data_get($senderPreview,'source_mapping','-') }} + {{ data_get($senderPreview,'source_addr','-') }}
      </div>
    </div>

    <div class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-3">
      <div class="p-3 border rounded-lg">
        <div class="text-xs muted mb-1">Sender Name</div>
        <div class="font-medium">{{ data_get($senderPreview,'sender_name','-') }}</div>
        @if(empty($selectedPage))
          <div class="text-xs muted mt-1">Tip: Select a Page para makita yung mapped SENDER_NAME.</div>
        @endif
      </div>

      <div class="p-3 border rounded-lg">
        <div class="text-xs muted mb-1">Sender Phone</div>
        <div class="mono text-sm">{{ data_get($senderPreview,'sender_phone','-') ?: '-' }}</div>
      </div>

      <div class="p-3 border rounded-lg">
        <div class="text-xs muted mb-1">Prov / City / Brgy</div>
        <div class="mono text-xs">{{ data_get($senderPreview,'sender_prov','-') ?: '-' }}</div>
        <div class="mono text-xs">{{ data_get($senderPreview,'sender_city','-') ?: '-' }}</div>
        <div class="mono text-xs">{{ data_get($senderPreview,'sender_brgy','-') ?: '-' }}</div>
      </div>

      <div class="p-3 border rounded-lg">
        <div class="text-xs muted mb-1">Sender Address</div>
        <div class="text-sm">{{ data_get($senderPreview,'sender_address','-') ?: '-' }}</div>
      </div>

      {{-- JNT Account indicator --}}
      @if($selectedPage !== '' && !$isRunView)
        <div class="p-3 border rounded-lg md:col-span-2
          {{ $accountOk ? 'border-green-200 bg-green-50' : 'border-red-200 bg-red-50' }}">
          <div class="text-xs mb-1 {{ $accountOk ? 'text-green-700' : 'text-red-700' }} font-medium">
            JNT Account
          </div>
          @if($accountOk && data_get($accountCheck,'account'))
            <div class="text-sm font-semibold text-green-800">
              {{ data_get($accountCheck,'account')->label }}
            </div>
            <div class="text-xs text-green-700 font-mono mt-0.5">
              {{ data_get($accountCheck,'account')->eccompanyid }}
              /
              {{ data_get($accountCheck,'account')->customerid }}
            </div>
          @else
            <div class="text-sm font-semibold text-red-700">
              ⚠️ No account mapped
            </div>
            <div class="text-xs text-red-600 mt-0.5">
              {{ data_get($accountCheck,'message') }}
              <a href="{{ route('jnt.accounts.mapping') }}" class="underline font-medium ml-1">
                Go to Page Mapping →
              </a>
            </div>
          @endif
        </div>
      @endif

    </div>
  </div>

  {{-- Create batch card --}}
  <div class="card p-4 mb-4">
    <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
      <div>
        <div class="font-semibold">Batch</div>
        <div class="text-sm muted">
          Create shipments from Macro Output (date + page), then queue Create Order.
          (STATUS=PROCEED only, validate_1/validate_2/item_checker must be 1)
        </div>
      </div>

      <div class="flex gap-2 flex-wrap">
        <form method="POST" action="{{ url('jnt/orders/batch') }}">
          @csrf
          <input type="hidden" name="date" value="{{ $selectedDate }}">
          <input type="hidden" name="page" value="{{ $selectedPage }}">
          <button type="submit"
            class="btn btn-blue {{ $canCreateBatch ? '' : 'btn-disabled' }}"
            {{ $canCreateBatch ? '' : 'disabled' }}>
            Create Batch (Queue)
          </button>
        </form>

        @if(!empty($retryRun) && $retryRun->fail_count > 0)
          <form method="POST"
                action="{{ url("jnt/orders/batch/{$retryRun->id}/retry-failed") }}"
                onsubmit="return confirm('Retry {{ $retryRun->fail_count }} failed shipment(s) from Run #{{ $retryRun->id }}?')">
            @csrf
            <button type="submit" class="btn btn-yellow">
              Retry Failed ({{ $retryRun->fail_count }}) — Run #{{ $retryRun->id }}
            </button>
          </form>
        @endif
      </div>
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
        @php $list = $rows; @endphp

        @if($gateBlocked)
          <tr>
            <td colspan="14" class="p-6 text-center text-red-700">
              {{ data_get($statusGate,'message') ?? 'BLOCKED — walang preview data.' }}
            </td>
          </tr>
        @else
          @forelse($list as $r)
            @php
              $shipmentId = data_get($r,'shipment_id', data_get($r,'id','-'));
              $macroId    = data_get($r,'macro_id', data_get($r,'macro_output_id', '-'));

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
              $tx         = data_get($r,'txlogisticid', null);
              $reason     = data_get($r,'reason', null);

              $hasMailno  = !empty($mailno);
              $hasReason  = !empty($reason);

              $hasResult  = $hasMailno || $hasReason || !empty($tx) || ((string)$shipmentId !== '-' && $shipmentId !== null);
            @endphp

            <tr class="align-top">
              <td class="p-2 mono text-xs">{{ $shipmentId ?? '-' }}</td>
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
                @if(!$hasResult)
                  <span class="badge badge-gray">PREVIEW</span>
                @else
                  @if($hasMailno)
                    <span class="badge badge-green">YES</span>
                  @elseif($hasReason)
                    <span class="badge badge-red">NO</span>
                  @else
                    <span class="badge badge-yellow">PENDING</span>
                  @endif
                @endif
              </td>

              <td class="p-2">
                <span class="text-xs text-gray-700">{{ $reason ?? '-' }}</span>
              </td>

              <td class="p-2">
                @if(!empty($mailno) && !empty($shipmentId) && (string)$shipmentId !== '-')
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

              <td class="p-2">
                @if(!empty($shipmentId) && (string)$shipmentId !== '-')
                  <a href="{{ url('jnt/orders/debug/' . $shipmentId) }}" target="_blank"
                     class="underline text-xs">Debug</a>
                @else
                  <span class="text-xs muted">-</span>
                @endif
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="14" class="p-6 text-center muted">
                No rows found. (Only STATUS=PROCEED + validate_1/validate_2/item_checker=1 are shown)
              </td>
            </tr>
          @endforelse
        @endif
      </tbody>
    </table>
  </div>

  {{-- ✅ Auto-refresh (LESS aggressive reload) --}}
  @if($isRunView)
    <script>
      (function () {
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

        let lastReloadAt = Date.now();
        let lastReloadProcessed = lastProcessed;

        const RELOAD_EVERY_MS = 15000;
        const RELOAD_EVERY_N  = 25;

        async function tick() {
          if (paused) return;

          try {
            const res = await fetch(statusUrl, { headers: { 'Accept': 'application/json' } });
            if (!res.ok) return;

            const j = await res.json();

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

            const now = Date.now();
            const shouldReloadByCount = (processed - lastReloadProcessed) >= RELOAD_EVERY_N;
            const shouldReloadByTime  = (now - lastReloadAt) >= RELOAD_EVERY_MS;

            if (shouldReloadByCount || shouldReloadByTime) {
              lastReloadAt = now;
              lastReloadProcessed = processed;
              window.location.reload();
              return;
            }

            if (['finished','done','stopped'].includes(status)) {
              window.location.reload();
              return;
            }

            lastProcessed = processed;
          } catch (e) {}
        }

        setInterval(tick, 3000);
      })();
    </script>
  @endif

</x-layout>

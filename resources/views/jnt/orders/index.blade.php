{{-- resources/views/jnt/orders/index.blade.php --}}
<x-layout>
  <x-slot name="title">J&T Orders</x-slot>
  <x-slot name="heading">J&T Orders</x-slot>

  <style>
    .card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; }
    .btn { padding:.45rem .75rem; border-radius:.5rem; font-size:.875rem; border:1px solid #d1d5db; background:#fff; }
    .btn-primary { background:#111827; color:#fff; border-color:#111827; }
    .btn-danger { background:#ef4444; color:#fff; border-color:#ef4444; }
    .tbl th, .tbl td { padding:.5rem; vertical-align:top; }
    .tbl th { font-size:.75rem; color:#6b7280; text-transform:uppercase; letter-spacing:.04em; }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
    .debug-pre { white-space: pre-wrap; word-break: break-word; }
    .debug-box { background:#0b1020; color:#e5e7eb; border-radius:10px; padding:12px; }
    .link { color:#2563eb; text-decoration: underline; cursor:pointer; }
  </style>

  @php
    $qDate = request('date', $date ?? now()->format('Y-m-d'));
    $qPage = request('page', $page ?? '');
    $qRunId = request('run_id', $runId ?? '');
    $rows = $rows ?? collect();
    $run = $run ?? null;
    $flash = session('status') ?? session('message');
  @endphp

  @if($flash)
    <div class="mb-3 card p-3 text-sm">
      {{ $flash }}
    </div>
  @endif

  {{-- FILTER BAR --}}
  <div class="card p-3 mb-3">
    <form method="GET" action="{{ url('/jnt/orders') }}" class="flex flex-wrap items-end gap-3">
      <div>
        <div class="text-xs text-gray-500 mb-1">Date</div>
        <input type="date" name="date" value="{{ $qDate }}" class="border rounded px-2 py-1 text-sm">
      </div>

      <div>
        <div class="text-xs text-gray-500 mb-1">Page</div>
        <select name="page" class="border rounded px-2 py-1 text-sm min-w-[220px]">
          <option value="">—</option>
          @foreach(($pages ?? []) as $p)
            <option value="{{ $p }}" @selected($qPage === $p)>{{ $p }}</option>
          @endforeach
        </select>
      </div>

      <div>
        <div class="text-xs text-gray-500 mb-1">Run ID</div>
        <input type="number" name="run_id" value="{{ $qRunId }}" placeholder="(optional)" class="border rounded px-2 py-1 text-sm w-[120px]">
      </div>

      <div class="flex gap-2">
        <button class="btn btn-primary" type="submit">Filter</button>

        @if(!empty($qRunId))
          <a class="btn" href="{{ url('/jnt/orders?date='.$qDate.'&page='.urlencode($qPage)) }}">Back to Preview</a>
        @endif
      </div>
    </form>
  </div>

  {{-- RUN HEADER --}}
  @if($run)
    @php
      $runStatus = (string) data_get($run, 'status', 'running');
      $runTotal = (int) data_get($run, 'total', $rows->count());
      $runIdShow = (int) data_get($run, 'id', (int)$qRunId);
    @endphp

    <div class="card p-3 mb-3">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
          <div class="text-sm font-semibold">
            Viewing Run #{{ $runIdShow }}
            <span class="text-xs text-gray-500">— status: <b id="runStatusText">{{ $runStatus }}</b></span>
          </div>
          <div class="text-xs text-gray-600 mt-1">
            processed <span id="runProcessedText">0</span>/<span id="runTotalText">{{ $runTotal }}</span>
            (ok <span id="runOkText">0</span>, fail <span id="runFailText">0</span>, pending <span id="runPendingText">0</span>)
          </div>
        </div>

        <div class="flex gap-2">
          <form method="POST" action="{{ url('jnt/orders/batch') }}">
            @csrf
            <input type="hidden" name="date" value="{{ $qDate }}">
            <input type="hidden" name="page" value="{{ $qPage }}">
            <button type="submit" class="btn btn-primary">Create Batch (Queue)</button>
          </form>

          <form method="POST" action="{{ url('jnt/orders/batch/'.$runIdShow.'/stop') }}"
                onsubmit="return confirm('Stop this run?');">
            @csrf
            <button type="submit" class="btn btn-danger">Stop Run</button>
          </form>
        </div>
      </div>
    </div>
  @else
    <div class="card p-3 mb-3">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <div class="text-sm text-gray-700">
          Preview for <b>{{ $qDate }}</b>
          @if($qPage) — Page: <b>{{ $qPage }}</b> @endif
          <span class="text-xs text-gray-500 ml-2">({{ $rows->count() }} rows)</span>
        </div>

        <form method="POST" action="{{ url('jnt/orders/batch') }}">
          @csrf
          <input type="hidden" name="date" value="{{ $qDate }}">
          <input type="hidden" name="page" value="{{ $qPage }}">
          <button type="submit" class="btn btn-primary">Create Batch (Queue)</button>
        </form>
      </div>
    </div>
  @endif

  {{-- TABLE --}}
  <div class="card overflow-x-auto">
    <table class="tbl w-full">
      <thead class="bg-gray-50">
        <tr>
          <th>Shipment ID</th>
          <th>Macro ID</th>
          <th>Date</th>
          <th>Page</th>
          <th>Receiver</th>
          <th>Prov / City / Brgy</th>
          <th>Item</th>
          <th>COD</th>
          <th>Mailno</th>
          <th>TX</th>
          <th>Success</th>
          <th>Reason</th>
          <th>API Debug</th>
        </tr>
      </thead>

      <tbody>
        @forelse($rows as $r)
          @php
            $shipmentId = (string) data_get($r, 'shipment_id', data_get($r, 'id', ''));
            $macroId    = (string) data_get($r, 'macro_id', '-');
            $tsDate     = (string) data_get($r, 'ts_date', data_get($r, 'date', '-'));
            $pageName   = (string) data_get($r, 'page', data_get($r, 'PAGE', '-'));

            $fullName   = (string) data_get($r, 'full_name', '');
            $phone      = (string) data_get($r, 'phone_number', '');
            $addr       = (string) data_get($r, 'address', '');

            $prov       = (string) data_get($r, 'province', '');
            $city       = (string) data_get($r, 'city', '');
            $brgy       = (string) data_get($r, 'barangay', '');

            $itemName   = (string) data_get($r, 'item_name', '');
            $cod        = (string) data_get($r, 'cod', '');

            $mailno     = (string) data_get($r, 'mailno', '');
            $tx         = (string) data_get($r, 'txlogisticid', '');
            $reason     = (string) data_get($r, 'reason', '');

            $succ       = data_get($r, 'success'); // can be null
            $pending = (trim($mailno)==='' && trim($tx)==='' && trim($reason)==='' && ($succ === null || (string)$succ === '0'));
            $isOk = (!$pending) && ((string)$succ === '1' || $succ === 1 || $succ === true);
          @endphp

          <tr class="border-t" data-shipment-id="{{ $shipmentId }}">
            <td class="mono text-xs">{{ $shipmentId ?: '-' }}</td>
            <td class="mono text-xs">{{ $macroId }}</td>
            <td class="text-xs">{{ $tsDate }}</td>
            <td class="text-xs">{{ $pageName }}</td>

            <td class="text-xs">
              <div class="font-semibold">{{ $fullName ?: '-' }}</div>
              <div class="mono">{{ $phone ?: '-' }}</div>
              <div class="text-gray-700 whitespace-pre-line">{{ $addr ?: '-' }}</div>
            </td>

            <td class="text-xs whitespace-pre-line">
              <div>{{ $prov ?: '-' }}</div>
              <div>{{ $city ?: '-' }}</div>
              <div>{{ $brgy ?: '-' }}</div>
            </td>

            <td class="text-xs whitespace-pre-line">{{ $itemName ?: '-' }}</td>
            <td class="mono text-xs">{{ $cod ?: '-' }}</td>

            <td class="mono text-xs cell-mailno">{{ trim($mailno) !== '' ? $mailno : '-' }}</td>
            <td class="mono text-xs cell-tx">{{ trim($tx) !== '' ? $tx : '-' }}</td>

            <td class="text-xs">
              <span class="cell-success">
                @if($pending)
                  <span class="px-2 py-1 rounded bg-gray-100 text-gray-800 text-xs">PENDING</span>
                @elseif($isOk)
                  <span class="px-2 py-1 rounded bg-green-100 text-green-800 text-xs">YES</span>
                @else
                  <span class="px-2 py-1 rounded bg-red-100 text-red-800 text-xs">NO</span>
                @endif
              </span>
            </td>

            <td class="text-xs cell-reason {{ $isOk ? 'text-gray-700' : 'text-red-700' }}">
              {{ trim($reason) !== '' ? $reason : '-' }}
            </td>

            <td class="text-xs">
              <span class="link js-debug-toggle" data-id="{{ $shipmentId }}">API Debug</span>
            </td>
          </tr>

          {{-- hidden debug row --}}
          <tr class="border-t hidden" id="debug-row-{{ $shipmentId }}">
            <td colspan="13" class="p-3">
              <div class="text-xs text-gray-600 mb-2">
                Shipment #{{ $shipmentId }} — Debug payloads (request/response)
              </div>

              <div class="debug-box">
                <div class="mono text-xs debug-pre" id="debug-pre-{{ $shipmentId }}">Loading...</div>
              </div>
            </td>
          </tr>

        @empty
          <tr>
            <td colspan="13" class="p-4 text-sm text-gray-500">No rows found for this filter.</td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>

  {{-- AUTO POLL ONLY WHEN RUN VIEW --}}
  @if($run)
    @php
      $runIdShow = (int) data_get($run, 'id', (int)$qRunId);
    @endphp
    <script>
      (function () {
        const runId = {{ $runIdShow }};
        const statusUrl = "{{ url('jnt/orders/batch/'.$runIdShow.'/status') }}";

        function getVisibleShipmentIds() {
          return Array.from(document.querySelectorAll('tr[data-shipment-id]'))
            .map(tr => tr.getAttribute('data-shipment-id'))
            .filter(v => v && v !== '');
        }

        function setBadge(el, mailno, tx, success, reason) {
          const pending = (!mailno && !tx && !reason && (success === null || String(success) === '0'));
          const ok = (!pending) && (String(success) === '1' || success === true || success === 1);

          if (pending) {
            el.innerHTML = '<span class="px-2 py-1 rounded bg-gray-100 text-gray-800 text-xs">PENDING</span>';
          } else if (ok) {
            el.innerHTML = '<span class="px-2 py-1 rounded bg-green-100 text-green-800 text-xs">YES</span>';
          } else {
            el.innerHTML = '<span class="px-2 py-1 rounded bg-red-100 text-red-800 text-xs">NO</span>';
          }
        }

        async function poll() {
          try {
            const ids = getVisibleShipmentIds();
            const url = statusUrl + (ids.length ? ('?ids=' + encodeURIComponent(ids.join(','))) : '');

            const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
            if (!res.ok) return;

            const data = await res.json();

            if (data.stats) {
              document.getElementById('runProcessedText').textContent = data.stats.processed ?? 0;
              document.getElementById('runTotalText').textContent = data.stats.total ?? 0;
              document.getElementById('runOkText').textContent = data.stats.ok ?? 0;
              document.getElementById('runFailText').textContent = data.stats.fail ?? 0;
              document.getElementById('runPendingText').textContent = data.stats.pending ?? 0;
            }
            if (data.run && data.run.status) {
              document.getElementById('runStatusText').textContent = data.run.status;
            }

            (data.shipments || []).forEach(s => {
              const tr = document.querySelector('tr[data-shipment-id="' + s.id + '"]');
              if (!tr) return;

              const mailno = (s.mailno || '').trim();
              const tx = (s.txlogisticid || '').trim();
              const reason = (s.reason || '').trim();
              const success = (s.success === undefined ? null : s.success);

              const mailCell = tr.querySelector('.cell-mailno');
              const txCell = tr.querySelector('.cell-tx');
              const reasonCell = tr.querySelector('.cell-reason');
              const successWrap = tr.querySelector('.cell-success');

              if (mailCell) mailCell.textContent = mailno ? mailno : '-';
              if (txCell) txCell.textContent = tx ? tx : '-';
              if (reasonCell) reasonCell.textContent = reason ? reason : '-';
              if (successWrap) setBadge(successWrap, mailno, tx, success, reason);
            });

            const pending = data?.stats?.pending ?? 999;
            const status = data?.run?.status ?? '';
            if (pending === 0 || status === 'finished') clearInterval(timer);
          } catch (e) {}
        }

        poll();
        const timer = setInterval(poll, 2000);
      })();
    </script>
  @endif

  {{-- API DEBUG TOGGLE --}}
  <script>
    (function(){
      const loaded = new Set();

      async function loadDebug(id) {
        const pre = document.getElementById('debug-pre-' + id);
        if (!pre) return;

        if (loaded.has(id)) return;

        try {
          const res = await fetch("{{ url('jnt/orders/debug') }}/" + id, {
            headers: { 'Accept': 'application/json' }
          });

          if (!res.ok) {
            pre.textContent = "No debug found (HTTP " + res.status + ").";
            loaded.add(id);
            return;
          }

          const d = await res.json();

          // Pretty print: show logistics_interface as raw string, request/response JSON pretty if array/object.
          let out = "";
          out += "=== API DEBUG ===\n";
          out += "shipment_id: " + (d.id ?? id) + "\n\n";

          if (d.data_digest) out += "data_digest:\n" + d.data_digest + "\n\n";
          if (d.logistics_interface) out += "logistics_interface (EXACT JSON STRING SENT):\n" + d.logistics_interface + "\n\n";

          if (d.request_payload) {
            out += "request_payload (ARRAY/JSON):\n";
            out += JSON.stringify(d.request_payload, null, 2) + "\n\n";
          }

          if (d.response_raw) out += "response_raw (EXACT):\n" + d.response_raw + "\n\n";

          if (d.response_json) {
            out += "response_json (PARSED):\n";
            out += JSON.stringify(d.response_json, null, 2) + "\n\n";
          }

          if (!out.trim()) out = "No debug fields saved for this shipment.";

          pre.textContent = out;
          loaded.add(id);

        } catch (e) {
          pre.textContent = "Failed to load debug: " + (e?.message || e);
          loaded.add(id);
        }
      }

      document.addEventListener('click', async (ev) => {
        const el = ev.target.closest('.js-debug-toggle');
        if (!el) return;

        const id = el.getAttribute('data-id');
        const row = document.getElementById('debug-row-' + id);
        if (!row) return;

        // toggle
        const isHidden = row.classList.contains('hidden');
        if (isHidden) {
          row.classList.remove('hidden');
          await loadDebug(id);
        } else {
          row.classList.add('hidden');
        }
      });
    })();
  </script>

</x-layout>

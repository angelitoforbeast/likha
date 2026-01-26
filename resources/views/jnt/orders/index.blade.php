<x-layout>
  <x-slot name="title">J&T Orders</x-slot>
  <x-slot name="heading">J&T Orders (Create Batch from Macro Output)</x-slot>

  {{-- Flash message --}}
  @if (session('success'))
    <div class="max-w-7xl mx-auto mt-4 bg-green-100 text-green-900 p-3 rounded">
      {{ session('success') }}
    </div>
  @endif

  {{-- FILTER + ACTIONS --}}
  <div class="max-w-7xl mx-auto mt-6 bg-white rounded shadow p-4 mb-4">

    {{-- FILTER FORM --}}
    <form method="GET" action="{{ url('/jnt/orders') }}" class="flex flex-wrap items-end gap-3">
      <div>
        <label class="block text-xs text-gray-600 mb-1">Date</label>
        <input type="date"
               name="date"
               value="{{ $date }}"
               class="border rounded px-2 py-1 text-sm">
      </div>

      <div>
        <label class="block text-xs text-gray-600 mb-1">PAGE</label>
        <select name="page" class="border rounded px-2 py-1 text-sm">
          <option value="">-- All --</option>
          @foreach($pages as $p)
            <option value="{{ $p }}" @selected($page === $p)>{{ $p }}</option>
          @endforeach
        </select>
      </div>

      {{-- ❗ INTENTIONALLY NO run_id HERE --}}
      <button class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded text-sm">
        Apply Filter
      </button>
    </form>

    {{-- CREATE BATCH --}}
    <div class="mt-3 flex flex-wrap gap-2">
      <form method="POST" action="{{ url('/jnt/orders/batch') }}">
        @csrf
        <input type="hidden" name="date" value="{{ $date }}">
        <input type="hidden" name="page" value="{{ $page }}">
        <button class="bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-2 rounded text-sm">
          Create Batch (Use Current Filter)
        </button>
      </form>

      <div class="text-xs text-gray-600 self-center">
        Creates placeholder shipments then queues J&T order jobs.
      </div>
    </div>

    {{-- RUN STATUS --}}
    @if(!empty($run))
      <div class="mt-4 text-sm">
        <div class="font-semibold">
          Live Run:
          <span id="run-id">{{ $run->id }}</span>
          | Status: <span id="run-status">{{ $run->status }}</span>
          | Total: <span id="run-total">{{ (int)$run->total }}</span>
          | Processed: <span id="run-processed">{{ (int)$run->processed }}</span>
          | OK: <span id="run-ok">{{ (int)$run->success_count }}</span>
          | Fail: <span id="run-fail">{{ (int)$run->fail_count }}</span>
        </div>
        <div id="run-note" class="text-xs text-gray-500 mt-1">
          Waiting for updates...
        </div>
      </div>
    @endif
  </div>

  {{-- TABLE --}}
  <div class="max-w-7xl mx-auto bg-white rounded shadow overflow-x-auto">
    <table class="min-w-full text-sm">
      <thead class="bg-gray-100 text-gray-700">
        <tr>
          <th class="px-3 py-2 text-left">ID</th>
          <th class="px-3 py-2 text-left">Date</th>
          <th class="px-3 py-2 text-left">PAGE</th>
          <th class="px-3 py-2 text-left">Full Name</th>
          <th class="px-3 py-2 text-left">Phone</th>
          <th class="px-3 py-2 text-left">Address</th>
          <th class="px-3 py-2 text-left">Province</th>
          <th class="px-3 py-2 text-left">City</th>
          <th class="px-3 py-2 text-left">Barangay</th>
          <th class="px-3 py-2 text-left">Item</th>
          <th class="px-3 py-2 text-left">COD</th>
          <th class="px-3 py-2 text-left">J&T Mailno</th>
          <th class="px-3 py-2 text-left">TxLogisticId</th>
          <th class="px-3 py-2 text-left">Success</th>
          <th class="px-3 py-2 text-left">Reason</th>
        </tr>
      </thead>

      <tbody>
        @forelse($rows as $r)
          @php
            $isRunRow = isset($r->shipment_id);
            $mailno = $isRunRow ? ($r->mailno ?: '-') : '-';
            $tx = $isRunRow ? ($r->txlogisticid ?: '-') : '-';
            $succ = $isRunRow ? ((int)$r->success === 1) : false;
            $reason = $isRunRow && trim((string)$r->reason) !== '' ? $r->reason : '-';
          @endphp

          <tr class="border-t"
              @if($isRunRow) data-shipment-id="{{ $r->shipment_id }}" @endif>
            <td class="px-3 py-2 whitespace-nowrap">{{ $r->macro_id }}</td>
            <td class="px-3 py-2 whitespace-nowrap">{{ $r->ts_date }}</td>
            <td class="px-3 py-2 whitespace-nowrap">{{ $r->page }}</td>
            <td class="px-3 py-2 whitespace-nowrap">{{ $r->full_name }}</td>
            <td class="px-3 py-2 whitespace-nowrap">{{ $r->phone_number }}</td>
            <td class="px-3 py-2 max-w-[380px] truncate" title="{{ $r->address }}">{{ $r->address }}</td>
            <td class="px-3 py-2 whitespace-nowrap">{{ $r->province }}</td>
            <td class="px-3 py-2 whitespace-nowrap">{{ $r->city }}</td>
            <td class="px-3 py-2 whitespace-nowrap">{{ $r->barangay }}</td>
            <td class="px-3 py-2 whitespace-nowrap">{{ $r->item_name }}</td>
            <td class="px-3 py-2 whitespace-nowrap">{{ $r->cod }}</td>

            <td class="px-3 py-2 whitespace-nowrap jnt-mailno">{{ $mailno }}</td>
            <td class="px-3 py-2 whitespace-nowrap jnt-tx">{{ $tx }}</td>

            <td class="px-3 py-2 whitespace-nowrap jnt-success">
              @if($isRunRow)
                @if($succ)
                  <span class="text-emerald-700 font-semibold">YES</span>
                @else
                  <span class="text-red-700 font-semibold">NO</span>
                @endif
              @else
                -
              @endif
            </td>

            <td class="px-3 py-2 max-w-[320px] truncate jnt-reason" title="{{ $reason }}">
              {{ $reason }}
            </td>
          </tr>
        @empty
          <tr>
            <td colspan="15" class="px-3 py-6 text-center text-gray-500">
              No records found.
            </td>
          </tr>
        @endforelse
      </tbody>
    </table>

    <div class="p-3">
      {{ $rows->links() }}
    </div>
  </div>

  {{-- LIVE POLLING --}}
  @if(!empty($run))
  <script>
    (function () {
      const runId = {{ (int)$run->id }};
      let lastServerTime = null;

      async function poll() {
        try {
          const res = await fetch(`{{ url('/jnt/orders/batch') }}/${runId}/status`);
          if (!res.ok) return;

          const data = await res.json();
          const r = data.run;

          document.getElementById('run-status').textContent = r.status;
          document.getElementById('run-total').textContent = r.total;
          document.getElementById('run-processed').textContent = r.processed;
          document.getElementById('run-ok').textContent = r.ok;
          document.getElementById('run-fail').textContent = r.fail;

          const note = document.getElementById('run-note');
          if (data.server_time !== lastServerTime) {
            note.textContent = `Updated: ${data.server_time}`;
            lastServerTime = data.server_time;
          }

          const map = new Map();
          (data.latest || []).forEach(s => map.set(String(s.id), s));

          document.querySelectorAll('tr[data-shipment-id]').forEach(tr => {
            const sid = tr.getAttribute('data-shipment-id');
            const s = map.get(sid);
            if (!s) return;

            if (s.mailno) tr.querySelector('.jnt-mailno').textContent = s.mailno;
            if (s.txlogisticid) tr.querySelector('.jnt-tx').textContent = s.txlogisticid;

            const suc = tr.querySelector('.jnt-success');
            suc.innerHTML = String(s.success) === '1'
              ? '<span class="text-emerald-700 font-semibold">YES</span>'
              : '<span class="text-red-700 font-semibold">NO</span>';

            const reason = s.reason && s.reason.trim() !== '' ? s.reason : '-';
            const rea = tr.querySelector('.jnt-reason');
            rea.textContent = reason;
            rea.title = reason;
          });

          if (r.status === 'finished' || r.status === 'stopped') return;
          setTimeout(poll, 1500);
        } catch {
          setTimeout(poll, 2500);
        }
      }

      if (document.getElementById('run-status').textContent === 'running') {
        poll();
      }
    })();
  </script>
  @endif
</x-layout>

<x-layout>
  <x-slot name="title">J&T Waybills</x-slot>
  <x-slot name="heading">J&T Created Orders (ORDERQUERY)</x-slot>

  <div class="max-w-7xl mx-auto mt-6 bg-white rounded shadow p-4 mb-4">

    <form method="GET" action="{{ url('/jnt/waybills') }}" class="flex flex-wrap gap-3 items-end">
      <div>
        <label class="block text-xs text-gray-600 mb-1">Date</label>
        <input type="date" name="date" value="{{ $date }}"
               class="border rounded px-2 py-1 text-sm">
      </div>

      <div>
        <label class="block text-xs text-gray-600 mb-1">PAGE</label>
        <select name="page" class="border rounded px-2 py-1 text-sm">
          <option value="">-- All --</option>
          @foreach($pages as $p)
            <option value="{{ $p }}" @selected($page===$p)>{{ $p }}</option>
          @endforeach
        </select>
      </div>

      <button class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded text-sm">
        Apply
      </button>
    </form>

    <div class="mt-3 flex items-center gap-2">
      <button id="btnQuery"
              type="button"
              class="bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-2 rounded text-sm">
        Query J&T (One by One)
      </button>

      <button id="btnStop"
              type="button"
              class="bg-gray-200 hover:bg-gray-300 text-gray-900 px-3 py-2 rounded text-sm hidden">
        Stop
      </button>

      <div id="statusText" class="text-sm text-gray-600"></div>
    </div>
  </div>

  <div class="max-w-7xl mx-auto bg-white rounded shadow overflow-x-auto">
    <table class="min-w-full text-sm" id="tbl">
      <thead class="bg-gray-100">
        <tr>
          <th class="px-3 py-2 text-left">TxLogisticId (Serial)</th>
          <th class="px-3 py-2 text-left">Mailno</th>
          <th class="px-3 py-2 text-left">Sender</th>
          <th class="px-3 py-2 text-left">Receiver Address</th>
          <th class="px-3 py-2 text-left">Weight</th>
          <th class="px-3 py-2 text-left">Freight</th>
          <th class="px-3 py-2 text-left">COD</th>
          <th class="px-3 py-2 text-left">Order Status</th>
          <th class="px-3 py-2 text-left">UI</th>
        </tr>
      </thead>

      <tbody>
        @foreach($rows as $r)
          <tr
            data-serial="{{ $r->txlogisticid }}"
            class="border-t"
          >
            <td class="px-3 py-2 font-mono text-xs">{{ $r->txlogisticid }}</td>
            <td class="px-3 py-2">{{ $r->mailno ?? '-' }}</td>

            <td class="px-3 py-2 sender">-</td>
            <td class="px-3 py-2 address">-</td>
            <td class="px-3 py-2 weight">-</td>
            <td class="px-3 py-2 freight">-</td>
            <td class="px-3 py-2">{{ $r->cod }}</td>
            <td class="px-3 py-2 ostatus">-</td>

            <td class="px-3 py-2">
              <span class="badge text-xs px-2 py-1 rounded bg-gray-100 text-gray-700">Queued</span>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>

    <div class="p-3">
      {{ $rows->links() }}
    </div>
  </div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const btnQuery = document.getElementById('btnQuery');
  const btnStop  = document.getElementById('btnStop');
  const statusEl = document.getElementById('statusText');

  if (!btnQuery) return;

  let stopFlag = false;

  function setStatus(text) {
    if (statusEl) statusEl.textContent = text || '';
  }

  function setBadge(tr, text, kind) {
    const b = tr.querySelector('.badge');
    if (!b) return;

    b.textContent = text;

    // reset
    b.className = 'badge text-xs px-2 py-1 rounded';

    if (kind === 'ok') {
      b.classList.add('bg-emerald-100','text-emerald-800');
    } else if (kind === 'warn') {
      b.classList.add('bg-amber-100','text-amber-800');
    } else if (kind === 'err') {
      b.classList.add('bg-red-100','text-red-800');
    } else if (kind === 'run') {
      b.classList.add('bg-blue-100','text-blue-800');
    } else {
      b.classList.add('bg-gray-100','text-gray-700');
    }
  }

  async function postJson(url, payload) {
    const res = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': '{{ csrf_token() }}'
      },
      body: JSON.stringify(payload)
    });

    // Ensure we don't crash on HTML error pages
    const text = await res.text();
    let json = null;
    try { json = JSON.parse(text); } catch (e) {}

    if (!res.ok) {
      const msg = (json && json.message) ? json.message : ('HTTP ' + res.status + ' ' + text.slice(0,120));
      throw new Error(msg);
    }

    if (!json) throw new Error('Invalid JSON response');
    return json;
  }

  btnQuery.addEventListener('click', async function () {
    const rows = Array.from(document.querySelectorAll('tr[data-serial]'));
    const serials = rows.map(r => (r.dataset.serial || '').trim()).filter(Boolean);

    if (serials.length === 0) {
      alert('No txlogisticid found to query (ORDERQUERY needs serialnumber).');
      return;
    }

    stopFlag = false;
    btnQuery.disabled = true;
    btnQuery.textContent = 'Querying...';
    btnStop.classList.remove('hidden');

    let found = 0;
    let notFound = 0;
    let failed = 0;

    try {
      for (let i = 0; i < rows.length; i++) {
        if (stopFlag) break;

        const tr = rows[i];
        const serial = (tr.dataset.serial || '').trim();
        if (!serial) continue;

        setBadge(tr, 'Querying', 'run');
        setStatus(`Querying ${i+1} / ${rows.length} ... Found ${found}, Not Found ${notFound}, Failed ${failed}`);

        try {
          const data = await postJson('{{ url('/jnt/waybills/query-one') }}', { serial });

          const item = data.item;

          if (!item) {
            notFound++;
            setBadge(tr, 'Not found', 'warn');
            tr.querySelector('.sender').textContent  = '-';
            tr.querySelector('.address').textContent = '-';
            tr.querySelector('.weight').textContent  = '-';
            tr.querySelector('.freight').textContent = '-';
            tr.querySelector('.ostatus').textContent = '-';
            continue;
          }

          found++;

          // Extract fields from ORDERQUERY response (your PS output shows these)
          const senderName = item.sender?.name ?? '-';

          const recv = item.receiver ?? {};
          const recvAddr =
            [recv.address, recv.area, recv.city, recv.prov]
              .filter(Boolean)
              .join(', ') || '-';

          const weight  = item.weight ?? item.chargeWeight ?? '-';
          const freight = item.sumfreight ?? item.freight ?? item.totalservicefee ?? '-';
          const ostatus = item.orderStatus ?? '-';

          tr.querySelector('.sender').textContent  = senderName;
          tr.querySelector('.address').textContent = recvAddr;
          tr.querySelector('.weight').textContent  = weight;
          tr.querySelector('.freight').textContent = freight;
          tr.querySelector('.ostatus').textContent = ostatus;

          setBadge(tr, 'Found', 'ok');

        } catch (err) {
          failed++;
          setBadge(tr, 'Error', 'err');
          console.error('Query failed for serial', serial, err);
        }
      }

      setStatus(`Done. Found ${found} / ${rows.length}. Not Found ${notFound}. Failed ${failed}.`);

    } finally {
      btnQuery.disabled = false;
      btnQuery.textContent = 'Query J&T (One by One)';
      btnStop.classList.add('hidden');
    }
  });

  btnStop.addEventListener('click', function () {
    stopFlag = true;
    setStatus('Stopping...');
  });
});
</script>
</x-layout>

<x-layout>
  <x-slot name="title">J&T Order Management</x-slot>
  <x-slot name="heading">J&T Order Management (ORDERQUERY)</x-slot>

  <div class="max-w-7xl mx-auto mt-6 bg-white rounded shadow p-4 mb-4">

    <form method="GET" action="{{ url('/jnt/order-management') }}" class="flex flex-col md:flex-row gap-3 md:items-end">
      <div>
        <label class="block text-xs text-gray-600 mb-1">Upload Date (jnt_shipments.created_at)</label>
        <input type="date" name="upload_date" value="{{ $upload_date }}"
               class="border rounded px-2 py-1 text-sm">
      </div>

      <div>
        <label class="block text-xs text-gray-600 mb-1">PAGE</label>
        <select name="page" class="border rounded px-2 py-1 text-sm min-w-[220px]">
          <option value="">-- All --</option>
          @foreach($pages as $p)
            <option value="{{ $p }}" @selected($page===$p)>{{ $p }}</option>
          @endforeach
        </select>
      </div>

      <div class="flex gap-2">
        <button class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded text-sm">
          Apply
        </button>

        <button id="btnQuery"
                type="button"
                class="bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-2 rounded text-sm">
          Query J&T (ORDERQUERY)
        </button>
      </div>
    </form>

    <div class="mt-3 text-sm text-gray-600">
      <div id="queryStatus"></div>
    </div>
  </div>

  <div class="max-w-7xl mx-auto bg-white rounded shadow overflow-x-auto">
    <table class="min-w-[1400px] w-full text-sm" id="tbl">
      <thead class="bg-gray-100">
        <tr>
          <th class="px-3 py-2 text-left">Upload At</th>
          <th class="px-3 py-2 text-left">PAGE</th>
          <th class="px-3 py-2 text-left">Mailno</th>
          <th class="px-3 py-2 text-left">TxLogisticId</th>

          <th class="px-3 py-2 text-left">OrderStatus</th>
          <th class="px-3 py-2 text-left">Receiver</th>
          <th class="px-3 py-2 text-left">Receiver Address</th>

          <th class="px-3 py-2 text-left">Weight</th>
          <th class="px-3 py-2 text-left">ChargeWeight</th>
          <th class="px-3 py-2 text-left">Freight</th>
          <th class="px-3 py-2 text-left">SumFreight</th>
          <th class="px-3 py-2 text-left">OfferFee</th>
          <th class="px-3 py-2 text-left">GoodsValue</th>
          <th class="px-3 py-2 text-left">GoodsNames</th>

          <th class="px-3 py-2 text-left">CreateOrderTime</th>
          <th class="px-3 py-2 text-left">SendStart</th>
          <th class="px-3 py-2 text-left">SendEnd</th>

          <th class="px-3 py-2 text-left">RAW</th>
        </tr>
      </thead>

      <tbody>
        @foreach($rows as $r)
          <tr class="border-t align-top"
              data-serial="{{ $r->txlogisticid }}">

            <td class="px-3 py-2 whitespace-nowrap text-gray-700">
              {{ \Carbon\Carbon::parse($r->uploaded_at)->timezone('Asia/Manila')->format('Y-m-d H:i:s') }}
            </td>

            <td class="px-3 py-2 whitespace-nowrap">{{ $r->page }}</td>

            <td class="px-3 py-2 whitespace-nowrap font-medium">{{ $r->mailno ?? '-' }}</td>

            <td class="px-3 py-2 whitespace-nowrap">
              <span class="font-mono text-xs">{{ $r->txlogisticid }}</span>
            </td>

            <td class="px-3 py-2 orderStatus">-</td>
            <td class="px-3 py-2 receiverName">-</td>
            <td class="px-3 py-2 receiverAddr">-</td>

            <td class="px-3 py-2 weight">-</td>
            <td class="px-3 py-2 chargeWeight">-</td>
            <td class="px-3 py-2 freight">-</td>
            <td class="px-3 py-2 sumfreight">-</td>
            <td class="px-3 py-2 offerFee">-</td>
            <td class="px-3 py-2 goodsValue">-</td>
            <td class="px-3 py-2 goodsNames">-</td>

            <td class="px-3 py-2 createOrderTime">-</td>
            <td class="px-3 py-2 sendStart">-</td>
            <td class="px-3 py-2 sendEnd">-</td>

            <td class="px-3 py-2">
              <button type="button" class="btnRaw text-xs px-2 py-1 rounded bg-gray-100 hover:bg-gray-200">
                View
              </button>
              <div class="rawBox mt-2 hidden">
                <pre class="rawPre text-xs bg-gray-50 border rounded p-2 overflow-x-auto max-w-[560px]"></pre>
              </div>
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

  document.querySelectorAll('.btnRaw').forEach(btn => {
    btn.addEventListener('click', () => {
      const td = btn.closest('td');
      const box = td.querySelector('.rawBox');
      box.classList.toggle('hidden');
    });
  });

  const btn = document.getElementById('btnQuery');
  const status = document.getElementById('queryStatus');
  if (!btn) return;

  function setStatus(msg) { if (status) status.textContent = msg; }

  function chunk(arr, size) {
    const out = [];
    for (let i = 0; i < arr.length; i += size) out.push(arr.slice(i, i + size));
    return out;
  }

  btn.addEventListener('click', async function () {

    const trs = Array.from(document.querySelectorAll('tr[data-serial]'));
    const serials = trs.map(r => r.dataset.serial).filter(Boolean);

    if (serials.length === 0) {
      alert('No txlogisticid to query.');
      return;
    }

    btn.disabled = true;
    btn.textContent = 'Querying...';

    const BATCH_SIZE = 5;

    try {
      const batches = chunk(serials, BATCH_SIZE);
      let done = 0;

      for (const b of batches) {
        setStatus(`Querying ${done + 1}-${Math.min(done + b.length, serials.length)} of ${serials.length}...`);

        const res = await fetch('{{ url('/jnt/order-management/query') }}', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json', // ✅ IMPORTANT (prevents HTML responses)
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
          },
          body: JSON.stringify({ serials: b })
        });

        const text = await res.text();
        const trimmed = (text || '').trim();

        // HTML response means redirect/login/exception page
        if (trimmed.startsWith('<!DOCTYPE') || trimmed.startsWith('<html') || trimmed.startsWith('<')) {
          throw new Error(
            "Server returned HTML instead of JSON. Usually redirect/login/exception.\n\n" +
            trimmed.slice(0, 500)
          );
        }

        let data;
        try {
          data = JSON.parse(text);
        } catch (e) {
          throw new Error("Invalid JSON from server:\n\n" + trimmed.slice(0, 500));
        }

        if (!res.ok || data?.ok !== true) {
          throw new Error(data?.message || ('HTTP ' + res.status));
        }

        // update only the rows that match returned items
        trs.forEach(tr => {
          const serial = tr.dataset.serial;
          const o = data.items?.[serial];
          if (!o) return;

          tr.querySelector('.orderStatus').textContent = o.orderStatus ?? '-';
          tr.querySelector('.receiverName').textContent = o.receiver?.name ?? '-';

          const rcvrAddr =
            (o.receiver?.prov ? (o.receiver.prov + ' ') : '') +
            (o.receiver?.city ? (o.receiver.city + ' ') : '') +
            (o.receiver?.area ? (o.receiver.area + ' ') : '') +
            (o.receiver?.address ?? '');
          tr.querySelector('.receiverAddr').textContent = (rcvrAddr.trim() || '-');

          tr.querySelector('.weight').textContent = o.weight ?? '-';
          tr.querySelector('.chargeWeight').textContent = o.chargeWeight ?? '-';
          tr.querySelector('.freight').textContent = o.freight ?? (o.totalservicefee ?? '-');
          tr.querySelector('.sumfreight').textContent = o.sumfreight ?? '-';
          tr.querySelector('.offerFee').textContent = o.offerFee ?? '-';
          tr.querySelector('.goodsValue').textContent = o.goodsValue ?? '-';
          tr.querySelector('.goodsNames').textContent = o.goodsNames ?? '-';

          tr.querySelector('.createOrderTime').textContent = o.createordertime ?? '-';
          tr.querySelector('.sendStart').textContent = o.sendstarttime ?? '-';
          tr.querySelector('.sendEnd').textContent = o.sendendtime ?? '-';

          const pre = tr.querySelector('.rawPre');
          if (pre) pre.textContent = JSON.stringify(o, null, 2);
        });

        done += b.length;
      }

      setStatus(`Done. Attempted ${serials.length} row(s).`);

    } catch (e) {
      console.error(e);
      alert(String(e.message || e));
      setStatus('');
    } finally {
      btn.disabled = false;
      btn.textContent = 'Query J&T (ORDERQUERY)';
    }
  });
});
</script>

</x-layout>

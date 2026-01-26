<x-layout>
  <x-slot name="title">J&T Batch Run</x-slot>
  <x-slot name="heading">J&T Batch Run #{{ $run->id }}</x-slot>

  @if(session('success'))
    <div class="bg-green-100 text-green-900 p-2 rounded mb-3">{{ session('success') }}</div>
  @endif

  <div class="bg-white rounded shadow p-4 mb-4">
    <div class="text-sm text-gray-700 space-y-1">
      <div>Status: <b id="runStatus">{{ $run->status }}</b></div>
      <div>Total: <b id="runTotal">{{ $run->total }}</b></div>
      <div>Processed: <b id="runProcessed">{{ $run->processed }}</b></div>
      <div>Success: <b id="runOk">{{ $run->success_count }}</b> | Fail: <b id="runFail">{{ $run->fail_count }}</b></div>
    </div>
  </div>

  <div class="bg-white rounded shadow p-4">
    <div class="overflow-auto">
      <table class="w-full text-sm border">
        <thead class="bg-gray-50">
          <tr>
            <th class="p-2 border text-left">Shipment ID</th>
            <th class="p-2 border text-left">Macro ID</th>
            <th class="p-2 border text-left">txlogisticid</th>
            <th class="p-2 border text-left">mailno</th>
            <th class="p-2 border text-left">sortingcode</th>
            <th class="p-2 border text-left">sortingNo</th>
            <th class="p-2 border text-left">success</th>
            <th class="p-2 border text-left">reason</th>
          </tr>
        </thead>
        <tbody>
          @foreach($shipments as $s)
            <tr>
              <td class="p-2 border">{{ $s->id }}</td>
              <td class="p-2 border">{{ $s->macro_output_id }}</td>
              <td class="p-2 border">{{ $s->txlogisticid }}</td>
              <td class="p-2 border font-semibold">{{ $s->mailno }}</td>
              <td class="p-2 border">{{ $s->sortingcode }}</td>
              <td class="p-2 border">{{ $s->sortingNo }}</td>
              <td class="p-2 border">{{ $s->success ? 'YES' : 'NO' }}</td>
              <td class="p-2 border">{{ $s->reason }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>

    <div class="mt-3">
      {{ $shipments->links() }}
    </div>
  </div>

  <script>
    async function poll() {
      try {
        const res = await fetch("{{ url('/jnt/orders/batch/'.$run->id.'/status') }}", { headers: { 'Accept': 'application/json' }});
        const data = await res.json();

        document.getElementById('runStatus').textContent = data.run.status;
        document.getElementById('runTotal').textContent = data.run.total;
        document.getElementById('runProcessed').textContent = data.run.processed;
        document.getElementById('runOk').textContent = data.run.success_count;
        document.getElementById('runFail').textContent = data.run.fail_count;
      } catch (e) {}
      setTimeout(poll, 1500);
    }
    poll();
  </script>
</x-layout>

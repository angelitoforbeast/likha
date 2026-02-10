<x-layout>
  <x-slot name="title">Waybills Bulk Print Run</x-slot>
  <x-slot name="heading">Waybills Bulk Print Run</x-slot>

  <div class="bg-white border rounded-xl p-4">
    <div class="font-semibold">Run #{{ $run->id }}</div>
    <div class="text-sm text-gray-600 mt-1" id="jsMsg">{{ $run->message ?? '' }}</div>

    <div class="mt-3 text-sm">
      Status: <span class="font-mono" id="jsStatus">{{ $run->status }}</span><br>
      Progress: <span class="font-mono" id="jsProcessed">{{ (int)$run->processed }}</span>/<span class="font-mono" id="jsTotal">{{ (int)$run->total }}</span><br>
      OK: <span class="font-mono" id="jsOk">{{ (int)$run->ok_count }}</span> |
      FAIL: <span class="font-mono" id="jsFail">{{ (int)$run->fail_count }}</span>
    </div>

    <div class="mt-4 flex gap-2">
      <a class="px-3 py-2 rounded-lg bg-gray-200 text-sm" href="{{ url('/jnt/waybills/print') }}">Back</a>

      <a id="jsDownload" class="px-3 py-2 rounded-lg bg-blue-600 text-white text-sm hidden"
         href="{{ url("/jnt/waybills/print-bulk/download/{$run->id}") }}" target="_blank">
         Download Output
      </a>

      <form method="POST" action="{{ url("/jnt/waybills/print-bulk/cancel/{$run->id}") }}">
        @csrf
        <button type="submit" class="px-3 py-2 rounded-lg bg-red-600 text-white text-sm">Cancel</button>
      </form>
    </div>

    <div class="mt-4">
      <div class="text-xs text-gray-500">Recent failures (last 10):</div>
      <pre class="text-xs bg-gray-50 border rounded-lg p-3 overflow-auto" id="jsFails"></pre>
    </div>
  </div>

  <script>
    (function(){
      const statusUrl = @json(url("/jnt/waybills/print-bulk/status/{$run->id}"));
      const downloadUrl = @json(url("/jnt/waybills/print-bulk/download/{$run->id}"));

      const elStatus = document.getElementById('jsStatus');
      const elMsg = document.getElementById('jsMsg');
      const elProcessed = document.getElementById('jsProcessed');
      const elTotal = document.getElementById('jsTotal');
      const elOk = document.getElementById('jsOk');
      const elFail = document.getElementById('jsFail');
      const elDownload = document.getElementById('jsDownload');
      const elFails = document.getElementById('jsFails');

      async function tick(){
        try{
          const res = await fetch(statusUrl, {headers:{'Accept':'application/json'}});
          if(!res.ok) return;
          const j = await res.json();

          elStatus.textContent = j.status || '';
          elMsg.textContent = j.message || '';
          elProcessed.textContent = j.processed ?? 0;
          elTotal.textContent = j.total ?? 0;
          elOk.textContent = j.ok ?? 0;
          elFail.textContent = j.fail ?? 0;

          elFails.textContent = (j.recent_fails || []).join("\n");

          if (j.status === 'finished' && j.output_path) {
            elDownload.classList.remove('hidden');
            elDownload.href = downloadUrl;

            // optional auto-open once finished:
            // window.location.href = downloadUrl;
          }
        }catch(e){}
      }

      tick();
      setInterval(tick, 2000);
    })();
  </script>
</x-layout>

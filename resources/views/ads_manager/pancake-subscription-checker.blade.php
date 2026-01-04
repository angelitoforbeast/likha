<x-layout>
  <x-slot name="heading">Pancake Subscription Checkers010525 0136</x-slot>

  {{-- Flatpickr styles --}}
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
  <style>.flatpickr-calendar{z-index:9999!important}</style>

  {{-- Single Range Filter (auto-submit) --}}
  <form method="GET" class="mt-4 mb-6 bg-white p-4 shadow rounded" id="pscFilterForm">
    <div class="flex flex-wrap items-end gap-3">
      <div class="min-w-[260px]">
        <label class="block text-sm font-semibold mb-1">Date range</label>
        <input id="dateRange" type="text" placeholder="Select date range"
               class="w-full border border-gray-300 p-2 rounded-md shadow-sm cursor-pointer bg-white" readonly>
        <input type="hidden" name="from" id="from" value="{{ $from }}">
        <input type="hidden" name="to"   id="to"   value="{{ $to }}">
      </div>
    </div>
  </form>

  {{-- Table --}}
  <div class="overflow-auto bg-white p-4 shadow rounded">
    <table class="min-w-full border text-sm">
      <thead class="bg-gray-200">
        <tr>
          <th class="border px-2 py-1 text-left">Page</th>
          <th class="border px-2 py-1 text-right">Purchase (Ads)</th>
          <th class="border px-2 py-1 text-right">Order (Macro)</th>
        </tr>
      </thead>
      <tbody>
        @forelse ($rows as $r)
          @php
  $purchases = (int) $r['purchases'];
  $orders    = (int) $r['orders'];

  if ($orders > 0) {
      // macro_output as reference
      $diff = abs($purchases - $orders) / $orders * 100;
  } elseif ($purchases > 0) {
      // fallback to purchases when orders == 0
      $diff = abs($purchases - $orders) / $purchases * 100; // => 100%
  } else {
      // both zero
      $diff = 0;
  }

  $color = '';
  if ($diff >= 60)      $color = 'bg-red-200';
  elseif ($diff >= 40)  $color = 'bg-orange-200';
  elseif ($diff >= 20)  $color = 'bg-yellow-200';
@endphp

          <tr class="{{ $color }}">
            <td class="border px-2 py-1">{{ $r['page'] }}</td>
            <td class="border px-2 py-1 text-right">{{ number_format($purchases) }}</td>
            <td class="border px-2 py-1 text-right">{{ number_format($orders) }}</td>
          </tr>
        @empty
          <tr><td colspan="3" class="border px-2 py-3 text-center text-gray-500">No data.</td></tr>
        @endforelse
      </tbody>
      <tfoot class="bg-gray-100 font-semibold">
        <tr>
          <td class="border px-2 py-1 text-right">TOTAL</td>
          <td class="border px-2 py-1 text-right">{{ number_format($totals['purchases'] ?? 0) }}</td>
          <td class="border px-2 py-1 text-right">{{ number_format($totals['orders'] ?? 0) }}</td>
        </tr>
      </tfoot>
    </table>
  </div>

  {{-- Scripts --}}
  <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.js"></script>
  <script>
  function ymd(d){
    const p=n=>String(n).padStart(2,'0');
    return d ? d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate()) : '';
  }

  function initFP(){
    const form    = document.getElementById('pscFilterForm');
    const fromEl  = document.getElementById('from');
    const toEl    = document.getElementById('to');
    const fromInit = fromEl.value || null;
    const toInit   = toEl.value   || null;

    flatpickr('#dateRange', {
      mode: 'range',
      dateFormat: 'Y-m-d',
      defaultDate: (fromInit && toInit) ? [fromInit, toInit] : undefined,
      onReady(selectedDates, dateStr, instance){
        if (fromInit && toInit){
          instance.input.value = fromInit + ' to ' + toInit;
        }
      },
      // HUWAG mag-submit dito; minsan 1 date pa lang ang nasa array.
      // onChange: wala na tayong gagawin dito.

      onClose(selectedDates, dateStr, instance){
        const sel = instance.selectedDates;
        if (sel.length === 2){
          const [start, end] = sel;
          fromEl.value = ymd(start);
          toEl.value   = ymd(end);
          form.submit();
        } else if (sel.length === 1){
          const d = sel[0];
          fromEl.value = ymd(d);
          toEl.value   = ymd(d);
          form.submit();
        }
        // kapag walang napili, do nothing
      }
    });
  }

  if (window.flatpickr) initFP(); else {
    const s=document.createElement('script');
    s.src='https://unpkg.com/flatpickr@4.6.13/dist/flatpickr.min.js';
    s.onload=initFP; document.body.appendChild(s);
  }
</script>

</x-layout>

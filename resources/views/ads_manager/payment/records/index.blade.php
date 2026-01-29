<x-layout>
  <x-slot name="title">Payment of Ads</x-slot>
  <x-slot name="heading">Payment Activity Records</x-slot>

  @php
    $fmtTotal = number_format($totalAmount ?? 0, 2);
    $safeDate = function ($v) {
      if (!$v) return '';
      if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v)) return (string)$v;
      try { return \Illuminate\Support\Carbon::parse($v)->toDateString(); } catch (\Throwable $e) { return (string)$v; }
    };
  @endphp

  <style>
    .remarks-input{
      min-height: 34px;
      height: 34px;
      white-space: pre-wrap;
      word-break: break-word;
    }
  </style>

  <div class="bg-white p-4 rounded shadow mb-4">
    <form method="GET" class="grid md:grid-cols-12 gap-3 items-end">
      {{-- Date range --}}
      <div class="md:col-span-3">
        <label class="block text-sm text-gray-600 mb-1">Start date</label>
        <input id="start_date" type="text" name="start_date" value="{{ $start }}"
               class="w-full border rounded px-2 py-1" autocomplete="off">
      </div>
      <div class="md:col-span-3">
        <label class="block text-sm text-gray-600 mb-1">End date</label>
        <input id="end_date" type="text" name="end_date" value="{{ $end }}"
               class="w-full border rounded px-2 py-1" autocomplete="off">
      </div>

      {{-- Ad Account (dependent on payment method + date) --}}
      <div class="md:col-span-3">
        <label class="block text-sm text-gray-600 mb-1">Ad Account</label>
        <select name="ad_account" class="w-full border rounded px-2 py-1">
          <option value="">All</option>
          @foreach ($adAccountOptions as $opt)
            @php $label = $opt->name ? $opt->name : '(no name) ' . $opt->id; @endphp
            <option value="{{ $opt->id }}" {{ ($adAccountSel ?? '') == $opt->id ? 'selected' : '' }}>
              {{ $label }}
            </option>
          @endforeach
        </select>
      </div>

      {{-- Payment Method (dependent on ad account + date) --}}
      <div class="md:col-span-2">
        <label class="block text-sm text-gray-600 mb-1">Payment Method</label>
        <select name="payment_method" class="w-full border rounded px-2 py-1">
          <option value="">All</option>
          @foreach ($paymentMethodOptions as $pm)
            <option value="{{ $pm->method }}" {{ ($paymentMethodSel ?? '') === $pm->method ? 'selected' : '' }}>
              {{ $pm->method }}
            </option>
          @endforeach
        </select>
      </div>

      {{-- Buttons --}}
      <div class="md:col-span-2 flex gap-2">
        <button class="bg-blue-600 text-white px-3 py-2 rounded hover:bg-blue-700 w-full">Filter</button>
        <a href="{{ route('ads_payment.records.index') }}"
           class="bg-gray-200 px-3 py-2 rounded hover:bg-gray-300 w-full text-center">Reset</a>
      </div>

      {{-- Total --}}
      <div class="md:col-span-2 md:col-start-11 flex justify-end">
        <div class="text-sm text-gray-700">
          <span class="font-medium">Total Amount:</span>
          <span class="font-mono">{{ $fmtTotal }}</span>
        </div>
      </div>
    </form>
  </div>

  <div class="overflow-x-auto bg-white rounded shadow">
    <table class="min-w-full border">
      <thead class="bg-gray-100">
        <tr>
          <th class="p-2 border-b text-left">Date</th>
          <th class="p-2 border-b text-left">Transaction ID</th>
          <th class="p-2 border-b text-right">Amount</th>
          <th class="p-2 border-b text-left">Ad Account</th>
          <th class="p-2 border-b text-left">Payment Method</th>
          <th class="p-2 border-b text-left">Source</th>
          <th class="p-2 border-b text-left">Batch</th>
          <th class="p-2 border-b text-left">Remarks 1</th>
          <th class="p-2 border-b text-left">Remarks 2</th>
        </tr>
      </thead>

      <tbody>
        @forelse ($rows as $r)
          <tr class="hover:bg-gray-50 align-top">
            <td class="p-2 border-b">{{ $safeDate($r->date ?? '') }}</td>
            <td class="p-2 border-b font-mono text-sm">{{ $r->transaction_id ?? '' }}</td>
            <td class="p-2 border-b text-right">{{ isset($r->amount) ? number_format($r->amount, 2) : '0.00' }}</td>
            <td class="p-2 border-b">{{ $r->ad_account_name ?? '' }}</td>
            <td class="p-2 border-b">{{ $r->payment_method ?? '' }}</td>
            <td class="p-2 border-b text-gray-600 text-sm">{{ $r->source_filename ?? '' }}</td>
            <td class="p-2 border-b text-xs font-mono">{{ $r->import_batch_id ?? '' }}</td>

            {{-- Remarks 1 --}}
            <td class="p-2 border-b align-top">
              <textarea
                class="remarks-input w-full rounded-md border border-gray-200 bg-white px-2 py-1 text-sm leading-5
                       focus:border-blue-400 focus:ring-2 focus:ring-blue-100 focus:outline-none
                       resize-none overflow-hidden"
                rows="1"
                data-id="{{ $r->id }}"
                data-field="remarks_1"
                placeholder="Type remarks..."
              >{{ $r->remarks_1 ?? '' }}</textarea>
              <div class="text-xs text-gray-500 mt-1 hidden" data-saving="{{ $r->id }}-remarks_1">saving...</div>
            </td>

            {{-- Remarks 2 --}}
            <td class="p-2 border-b align-top">
              <textarea
                class="remarks-input w-full rounded-md border border-gray-200 bg-white px-2 py-1 text-sm leading-5
                       focus:border-blue-400 focus:ring-2 focus:ring-blue-100 focus:outline-none
                       resize-none overflow-hidden"
                rows="1"
                data-id="{{ $r->id }}"
                data-field="remarks_2"
                placeholder="Type remarks..."
              >{{ $r->remarks_2 ?? '' }}</textarea>
              <div class="text-xs text-gray-500 mt-1 hidden" data-saving="{{ $r->id }}-remarks_2">saving...</div>
            </td>
          </tr>
        @empty
          <tr>
            <td colspan="9" class="text-center p-4 text-gray-500">No records found.</td>
          </tr>
        @endforelse
      </tbody>
    </table>

    <div class="p-3">
      @if(method_exists($rows, 'links'))
        {{ $rows->links() }}
      @endif
    </div>
  </div>

  {{-- Flatpickr (CDN) --}}
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
  <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
  <script>
    flatpickr('#start_date', { dateFormat: 'Y-m-d', defaultDate: '{{ $start }}' });
    flatpickr('#end_date',   { dateFormat: 'Y-m-d', defaultDate: '{{ $end }}' });
  </script>

  <meta name="csrf-token" content="{{ csrf_token() }}">

  <script>
    const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    function autosize(el){
      el.style.height = '0px';
      el.style.height = el.scrollHeight + 'px';
    }

    document.querySelectorAll('textarea.remarks-input').forEach(el => {
      autosize(el);

      el.addEventListener('input', () => autosize(el));
      el.addEventListener('paste', () => setTimeout(() => autosize(el), 0));

      el.addEventListener('blur', async () => {
        const id = el.dataset.id;
        const field = el.dataset.field;
        const value = el.value;

        const savingEl = document.querySelector(`[data-saving="${id}-${field}"]`);
        if (savingEl) savingEl.classList.remove('hidden');

        try {
          const res = await fetch("{{ route('ads_payment.records.update_remarks') }}", {
            method: "POST",
            headers: {
              "Content-Type": "application/json",
              "X-CSRF-TOKEN": csrf,
              "Accept": "application/json",
            },
            body: JSON.stringify({ id, field, value })
          });

          if (!res.ok) throw new Error("Save failed");
          el.classList.remove('border-red-500');
        } catch (e) {
          el.classList.add('border-red-500');
          console.error(e);
        } finally {
          if (savingEl) savingEl.classList.add('hidden');
        }
      });
    });
  </script>
</x-layout>

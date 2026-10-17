<x-layout>
  <x-slot name="title">Card check</x-slot>
  <x-slot name="heading">Card check: Meta ad payments vs card statement</x-slot>

  @if (!$ready)
    <div class="bg-yellow-50 border border-yellow-300 text-yellow-800 p-3 rounded mb-4">Kailangan pa i-migrate ang card_statements tables.</div>
  @endif

  <div class="grid md:grid-cols-3 gap-4 mb-4">
    <form method="POST" action="{{ route('ads_payment.reconcile.store') }}" enctype="multipart/form-data" class="bg-white p-4 rounded shadow md:col-span-2 space-y-3">
      @csrf
      <h2 class="font-semibold">Upload ng card statement</h2>
      <p class="text-sm text-gray-600">PDF (text) o screenshot (PNG/JPG). Ang file ay hindi sine-save; ang mga nabasang linya lang, naka-mask ang card number.</p>
      <div>
        <input type="file" name="statement" accept=".pdf,.png,.jpg,.jpeg" required class="block w-full text-sm">
        @error('statement') <div class="text-red-600 text-sm mt-1">{{ $message }}</div> @enderror
      </div>
      <div class="grid md:grid-cols-2 gap-3">
        <label class="block text-sm text-gray-600">Card
          <select name="card_label" class="w-full border rounded px-2 py-1">
            <option value="">— hindi sigurado (imumungkahi) —</option>
            @foreach ($cards as $c)
              <option value="{{ $c }}" @selected(old('card_label') === $c)>{{ $c }}</option>
            @endforeach
          </select>
          @error('card_label') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
        </label>
        <label class="block text-sm text-gray-600">Bangko (para sa PDF)
          <select name="bank" class="w-full border rounded px-2 py-1">
            <option value="auto">Auto</option>
            @foreach ($banks as $b)
              <option value="{{ $b }}" @selected(old('bank') === $b)>{{ strtoupper($b) }}</option>
            @endforeach
          </select>
        </label>
      </div>
      <button class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Basahin</button>
    </form>

    <div class="bg-white p-4 rounded shadow">
      <h2 class="font-semibold mb-1">Suriin</h2>
      <p class="text-xs text-gray-600 mb-2">Meta records na lampas sa araw na ito ang petsa (baka napagpalit ang araw at buwan). Hindi binabago rito.</p>
      @forelse ($suspicious as $r)
        <div class="text-sm border-t py-1">
          <span class="font-mono text-red-700">{{ $r->date }}</span> · {{ $r->transaction_id }} · ₱{{ number_format((float) $r->amount, 2) }}
          <div class="text-xs text-gray-500">{{ $r->ad_account }} · {{ $r->payment_method }}</div>
        </div>
      @empty
        <div class="text-sm text-gray-500">Wala.</div>
      @endforelse
    </div>
  </div>

  <div class="overflow-x-auto bg-white rounded shadow">
    <table class="min-w-full border text-sm">
      <thead class="bg-gray-100">
        <tr>
          <th class="p-2 border-b text-left">Card</th>
          <th class="p-2 border-b text-left">Period</th>
          <th class="p-2 border-b text-left">Bangko</th>
          <th class="p-2 border-b text-right">Linya</th>
          <th class="p-2 border-b text-right">Tugma</th>
          <th class="p-2 border-b text-right">Diperensya (card − Meta)</th>
          <th class="p-2 border-b text-left">Status</th>
        </tr>
      </thead>
      <tbody>
        @forelse ($statements as $s)
          <tr class="hover:bg-gray-50">
            <td class="p-2 border-b"><a class="text-blue-700 hover:underline" href="{{ route('ads_payment.reconcile.show', $s) }}">{{ $s->card_label ?: '—' }}</a></td>
            <td class="p-2 border-b font-mono">{{ $s->period_start?->toDateString() }} – {{ $s->period_end?->toDateString() }}</td>
            <td class="p-2 border-b">{{ strtoupper($s->bank) }}</td>
            <td class="p-2 border-b text-right">{{ $s->lines_count }}</td>
            <td class="p-2 border-b text-right">{{ $s->status === 'matched' && $s->ads_count > 0 ? round($s->matched_count * 100 / $s->ads_count) . '%' : '—' }}</td>
            <td class="p-2 border-b text-right font-mono {{ $s->status === 'matched' && (float) $s->difference_total != 0 ? 'text-red-700 font-semibold' : '' }}">{{ $s->status === 'matched' ? number_format((float) $s->difference_total, 2, '.', ',') : '—' }}</td>
            <td class="p-2 border-b">{{ $s->status === 'matched' ? 'Naitugma' : 'Preview' }}</td>
          </tr>
        @empty
          <tr><td colspan="7" class="p-4 text-center text-gray-500">Wala pang statement.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</x-layout>

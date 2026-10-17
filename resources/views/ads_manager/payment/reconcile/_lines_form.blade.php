{{-- Mga linyang nabasa: pwedeng itama ang petsa, amount at flags bago itugma. --}}
<form method="POST" action="{{ route('ads_payment.reconcile.match', $s) }}" class="space-y-3">
  @csrf
  <div class="flex flex-wrap items-end gap-3">
    <label class="block text-sm text-gray-600">Card
      <select name="card_label" required class="border rounded px-2 py-1">
        <option value="">— piliin —</option>
        @foreach ($cards as $c)
          <option value="{{ $c }}" @selected($c === $selectedCard)>{{ $c }}</option>
        @endforeach
      </select>
    </label>
    <button class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">{{ $s->status === 'matched' ? 'Itugma ulit' : 'Itugma' }}</button>
  </div>
  @if ($errors->any())
    <div class="text-red-600 text-sm">@foreach ($errors->all() as $e) <div>{{ $e }}</div> @endforeach</div>
  @endif

  <div class="overflow-x-auto">
    <table class="min-w-full border text-sm">
      <thead class="bg-gray-100">
        <tr>
          <th class="p-2 border-b text-left">#</th>
          <th class="p-2 border-b text-left">Sale date</th>
          <th class="p-2 border-b text-left">Post date</th>
          <th class="p-2 border-b text-left">Description</th>
          <th class="p-2 border-b text-right">Amount</th>
          <th class="p-2 border-b text-center">Credit</th>
          <th class="p-2 border-b text-center">Ads</th>
        </tr>
      </thead>
      <tbody>
        @foreach ($lines as $l)
          <tr class="{{ $l->is_ads ? '' : 'text-gray-500' }}">
            <td class="p-1 border-b">{{ $l->line_no }}</td>
            <td class="p-1 border-b"><input type="date" name="lines[{{ $l->id }}][sale_date]" value="{{ $l->sale_date?->toDateString() }}" class="border rounded px-1"></td>
            <td class="p-1 border-b"><input type="date" name="lines[{{ $l->id }}][post_date]" value="{{ $l->post_date?->toDateString() }}" class="border rounded px-1"></td>
            <td class="p-1 border-b font-mono">{{ $l->description }}</td>
            <td class="p-1 border-b text-right"><input type="number" step="0.01" min="0" name="lines[{{ $l->id }}][amount]" value="{{ $l->amount }}" class="border rounded px-1 w-28 text-right"></td>
            <td class="p-1 border-b text-center">
              <input type="hidden" name="lines[{{ $l->id }}][is_credit]" value="0">
              <input type="checkbox" name="lines[{{ $l->id }}][is_credit]" value="1" @checked($l->is_credit)>
            </td>
            <td class="p-1 border-b text-center">
              <input type="hidden" name="lines[{{ $l->id }}][is_ads]" value="0">
              <input type="checkbox" name="lines[{{ $l->id }}][is_ads]" value="1" @checked($l->is_ads)>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
</form>

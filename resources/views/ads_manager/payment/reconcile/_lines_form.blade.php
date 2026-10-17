{{-- Mga linyang nabasa: pwedeng itama ang petsa, amount at flags bago itugma. Ang mga cell ay walang name;
     sa submit, isang JSON field ("edits") ang ipinapadala, para hindi maputol ng max_input_vars ang mahabang statement. --}}
<form method="POST" action="{{ route('ads_payment.reconcile.match', $s) }}" class="space-y-3"
      x-data="{ collect(form) {
        const out = {};
        form.querySelectorAll('tr[data-line]').forEach(tr => {
          const row = {};
          tr.querySelectorAll('[data-field]').forEach(i => {
            row[i.dataset.field] = i.type === 'checkbox' ? i.checked : (i.value === '' ? null : i.value);
          });
          out[tr.dataset.line] = row;
        });
        return JSON.stringify(out);
      } }"
      @submit="$refs.edits.value = collect($el)">
  @csrf
  <input type="hidden" name="edits" x-ref="edits" value="">
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
  @if ($formPages > 1)
    <div class="text-sm flex items-center gap-2">
      <span class="text-gray-600">Linya {{ $lines->first()?->line_no }}–{{ $lines->last()?->line_no }} ({{ $formPage }} / {{ $formPages }}); ang mga linya sa page na ito lang ang ipapadala.</span>
      @if ($formPage > 1) <a class="text-blue-700 hover:underline" href="{{ route('ads_payment.reconcile.show', [$s, 'lp' => $formPage - 1]) }}">← Nauna</a> @endif
      @if ($formPage < $formPages) <a class="text-blue-700 hover:underline" href="{{ route('ads_payment.reconcile.show', [$s, 'lp' => $formPage + 1]) }}">Susunod →</a> @endif
    </div>
  @endif
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
          <tr data-line="{{ $l->id }}" class="{{ $l->is_ads ? '' : 'text-gray-500' }}">
            <td class="p-1 border-b">{{ $l->line_no }}</td>
            <td class="p-1 border-b"><input type="date" data-field="sale_date" value="{{ $l->sale_date?->toDateString() }}" aria-label="Sale date, linya {{ $l->line_no }}" class="border rounded px-1"></td>
            <td class="p-1 border-b"><input type="date" data-field="post_date" value="{{ $l->post_date?->toDateString() }}" aria-label="Post date, linya {{ $l->line_no }}" class="border rounded px-1"></td>
            <td class="p-1 border-b font-mono">{{ $l->description }}</td>
            <td class="p-1 border-b text-right"><input type="number" step="0.01" min="0" required data-field="amount" value="{{ $l->amount }}" aria-label="Amount, linya {{ $l->line_no }}" class="border rounded px-1 w-28 text-right"></td>
            <td class="p-1 border-b text-center"><input type="checkbox" data-field="is_credit" @checked($l->is_credit) aria-label="Credit, linya {{ $l->line_no }}"></td>
            <td class="p-1 border-b text-center"><input type="checkbox" data-field="is_ads" @checked($l->is_ads) aria-label="Ads, linya {{ $l->line_no }}"></td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
</form>

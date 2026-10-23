<x-layout>
  <x-slot name="heading">Order encode: address notes (what the encoder learned)</x-slot>

  @php
    $q = fn (array $over) => route('order-encode.notes', array_filter($over + $filters, fn ($v) => $v !== '' && $v !== null));
    $currentStatus = $filters['status'] ?? 'all';
    $answer = fn ($p, $c, $b) => $p . ' / ' . $c . ' / ' . $b;
  @endphp

  <div class="mb-4 flex gap-2 text-sm">
    <a href="{{ route('order-encode.index') }}" class="rounded border px-3 py-1 text-gray-700">Sheet compare</a>
    <a href="{{ route('order-encode.final') }}" class="rounded border px-3 py-1 text-gray-700">All pages (vs final order)</a>
    <a href="{{ route('order-encode.notes') }}" class="rounded bg-blue-500 px-3 py-1 text-white">Address notes</a>
  </div>

  @if (session('status'))<div class="mb-3 rounded bg-green-100 px-3 py-2 text-sm text-green-800">{{ session('status') }}</div>@endif
  @if ($errors->any())<div class="mb-3 rounded bg-red-100 px-3 py-2 text-sm text-red-800">{{ $errors->first() }}</div>@endif

  <p class="mb-3 text-sm text-gray-600">
    A note links what the customer wrote (barangay, city, province) to the J&amp;T address the order ended with. The encoder uses <b>approved</b> notes only:
    an exact match sets the address; otherwise the closest ones are shown to GPT as past corrections. Notes hold address parts and order ids, never names or phone numbers.
  </p>

  <div class="mb-4 flex flex-wrap gap-2 text-sm">
    @foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $key => $text)
      <a href="{{ $q(['status' => $key]) }}" class="rounded px-3 py-1 {{ $currentStatus === $key ? 'bg-blue-500 text-white' : 'border text-gray-700' }}">{{ $text }} <b>{{ $counts[$key] }}</b></a>
    @endforeach
    <a href="{{ $q(['status' => 'all']) }}" class="rounded px-3 py-1 {{ $currentStatus === 'all' ? 'bg-blue-500 text-white' : 'border text-gray-700' }}">All <b>{{ array_sum($counts) }}</b></a>
  </div>

  {{-- Filters --}}
  <form method="GET" action="{{ route('order-encode.notes') }}" class="mb-4 flex flex-wrap items-end gap-3 text-sm">
    <label class="flex flex-col">Search (written text or place)
      <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" class="border rounded px-2 py-1 w-64">
    </label>
    <label class="flex flex-col">Status
      <select name="status" class="border rounded px-2 py-1">
        <option value="all" @selected($currentStatus === 'all')>all</option>
        @foreach ($statuses as $s)<option value="{{ $s }}" @selected($currentStatus === $s)>{{ $s }}</option>@endforeach
      </select>
    </label>
    <label class="flex flex-col">Source
      <select name="source" class="border rounded px-2 py-1">
        <option value="">any</option>
        @foreach ($sources as $s)<option value="{{ $s }}" @selected(($filters['source'] ?? '') === $s)>{{ $s }}</option>@endforeach
      </select>
    </label>
    <label class="flex flex-col">Conflict
      <select name="conflict" class="border rounded px-2 py-1">
        <option value="">any</option>
        <option value="yes" @selected(($filters['conflict'] ?? '') === 'yes')>yes</option>
        <option value="no" @selected(($filters['conflict'] ?? '') === 'no')>no</option>
      </select>
    </label>
    <label class="flex flex-col">Delivered at least
      <input type="number" name="delivered" min="0" value="{{ $filters['delivered'] ?? '' }}" class="border rounded px-2 py-1 w-24">
    </label>
    <label class="flex flex-col">Seen at least
      <input type="number" name="seen" min="0" value="{{ $filters['seen'] ?? '' }}" class="border rounded px-2 py-1 w-24">
    </label>
    <button type="submit" class="bg-blue-500 text-white px-3 py-1 rounded">Apply</button>
  </form>

  {{-- Bulk approve: the ticked notes, or every pending note without a conflict that matches the filter above --}}
  <form id="bulk" method="POST" action="{{ route('order-encode.notes.bulk') }}" class="mb-2 flex flex-wrap items-end gap-3 text-sm">
    @csrf
    @foreach ($filters as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
    <label class="flex flex-col">Note for the review (optional)
      <input type="text" name="review_note" maxlength="500" class="border rounded px-2 py-1 w-72">
    </label>
    <button type="submit" name="scope" value="selected" class="bg-green-600 text-white px-3 py-1 rounded">Approve ticked</button>
    <button type="submit" name="scope" value="filtered" class="bg-green-700 text-white px-3 py-1 rounded"
            onclick="return confirm('Approve all {{ $filterCount }} pending notes without a conflict that match this filter?')">Approve all {{ $filterCount }} pending without a conflict in this filter</button>
  </form>

  <div class="mb-1 font-semibold text-sm">Notes ({{ $notes->total() }})</div>
  <div class="overflow-x-auto rounded bg-white shadow">
    <table class="min-w-full text-xs">
      <thead class="bg-gray-100 text-left">
        <tr>
          <th class="px-2 py-1"></th>
          <th class="px-2 py-1">Customer wrote</th>
          <th class="px-2 py-1">Answer (J&amp;T)</th>
          <th class="px-2 py-1">Source · seen · delivered</th>
          <th class="px-2 py-1">Other answers</th>
          <th class="px-2 py-1">Orders</th>
          <th class="px-2 py-1">Status</th>
          <th class="px-2 py-1">Actions</th>
        </tr>
      </thead>
      <tbody>
        @forelse ($notes as $n)
          <tr class="border-t align-top">
            <td class="px-2 py-1"><input type="checkbox" name="ids[]" value="{{ $n->id }}" form="bulk"></td>
            <td class="px-2 py-1"><div>{{ $n->written_example }}</div><div class="text-gray-400">{{ $n->written_key }}</div></td>
            <td class="px-2 py-1 font-medium">{{ $answer($n->province, $n->city, $n->barangay) }}</td>
            <td class="px-2 py-1 whitespace-nowrap">{{ str_replace(',', ', ', $n->source) }}<br>seen {{ $n->seen }} · delivered {{ $n->delivered }}</td>
            <td class="px-2 py-1">
              @if ($n->conflict)
                <span class="rounded bg-red-100 px-1 text-red-800">conflict</span>
                @foreach ((array) $n->conflicts as $o)
                  <div>{{ $answer($o['province'], $o['city'], $o['barangay']) }} <span class="text-gray-500">(seen {{ $o['seen'] }}, delivered {{ $o['delivered'] }})</span></div>
                @endforeach
              @else
                <span class="text-gray-400">none</span>
              @endif
            </td>
            <td class="px-2 py-1">
              @foreach ((array) $n->order_ids as $id)
                <a href="{{ route('macro_output.index', array_filter(['date' => $dates[$id] ?? null])) }}" class="text-blue-700 underline" title="macro_output #{{ $id }}">#{{ $id }}</a>
              @endforeach
            </td>
            <td class="px-2 py-1 whitespace-nowrap">
              <b>{{ $n->status }}</b>
              @if ($n->reviewed_by)<div class="text-gray-500">{{ $n->reviewed_by }}{{ $n->reviewed_at ? ' · ' . $n->reviewed_at->format('Y-m-d H:i') : '' }}</div>@endif
              @if ($n->review_note)<div class="text-gray-500">{{ $n->review_note }}</div>@endif
            </td>
            <td class="px-2 py-1">
              <div class="flex gap-1">
                @if ($n->status !== 'approved')
                  <form method="POST" action="{{ route('order-encode.notes.approve', $n) }}">@csrf<button class="rounded bg-green-600 px-2 py-0.5 text-white">Approve</button></form>
                @endif
                @if ($n->status !== 'rejected')
                  <form method="POST" action="{{ route('order-encode.notes.reject', $n) }}">@csrf<button class="rounded bg-red-600 px-2 py-0.5 text-white">Reject</button></form>
                @endif
              </div>
              <details class="mt-1">
                <summary class="cursor-pointer text-blue-700">Edit answer</summary>
                <form method="POST" action="{{ route('order-encode.notes.edit', $n) }}" class="mt-1 flex flex-col gap-1">
                  @csrf
                  <input type="text" name="province" value="{{ $n->province }}" placeholder="Province" class="border rounded px-1 py-0.5" required>
                  <input type="text" name="city" value="{{ $n->city }}" placeholder="City" class="border rounded px-1 py-0.5" required>
                  <input type="text" name="barangay" value="{{ $n->barangay }}" placeholder="Barangay" class="border rounded px-1 py-0.5" required>
                  <input type="text" name="review_note" placeholder="Why (optional)" maxlength="500" class="border rounded px-1 py-0.5">
                  <button class="rounded bg-blue-500 px-2 py-0.5 text-white">Save (must be a J&amp;T combination)</button>
                </form>
              </details>
            </td>
          </tr>
        @empty
          <tr><td class="px-2 py-4 text-center text-gray-500" colspan="8">No notes match. Notes are built daily from corrected and delivered orders (order-encode:notes-build).</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>

  <div class="mt-3">{{ $notes->links() }}</div>
</x-layout>

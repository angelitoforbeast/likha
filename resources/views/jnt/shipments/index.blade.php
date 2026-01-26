<x-layout>
  <x-slot name="title">J&T Shipments</x-slot>
  <x-slot name="heading">J&T Shipments (Phase 6)</x-slot>

  @if (session('success'))
    <div class="bg-green-100 text-green-900 p-2 rounded mb-3">{{ session('success') }}</div>
  @endif
  @if (session('error'))
    <div class="bg-red-100 text-red-900 p-2 rounded mb-3">{{ session('error') }}</div>
  @endif

  <div class="max-w-7xl mx-auto mt-6 space-y-4">

    <div class="bg-white rounded shadow p-4">
      <form class="flex flex-wrap gap-2 items-end" method="GET">
        <div>
          <label class="block text-xs text-gray-600 mb-1">Status</label>
          <input name="status" value="{{ request('status') }}" class="border rounded px-2 py-1 text-sm" placeholder="CREATED">
        </div>
        <div>
          <label class="block text-xs text-gray-600 mb-1">Mailno</label>
          <input name="mailno" value="{{ request('mailno') }}" class="border rounded px-2 py-1 text-sm" placeholder="JT0000...">
        </div>
        <div>
          <label class="block text-xs text-gray-600 mb-1">Macro Output ID</label>
          <input name="macro_output_id" value="{{ request('macro_output_id') }}" class="border rounded px-2 py-1 text-sm" placeholder="123">
        </div>
        <button class="px-3 py-2 rounded bg-gray-900 text-white text-sm">Filter</button>
      </form>
    </div>

    <div class="bg-white rounded shadow p-4">
      <div class="font-semibold mb-2">Bulk Create (Queue)</div>
      <form method="POST" action="{{ url('/jnt/shipments/bulk-create') }}" class="flex flex-wrap gap-2 items-end">
        @csrf
        <div>
          <label class="block text-xs text-gray-600 mb-1">Date (optional)</label>
          <input type="date" name="date" class="border rounded px-2 py-1 text-sm">
        </div>
        <div>
          <label class="block text-xs text-gray-600 mb-1">Page (optional)</label>
          <input name="page" class="border rounded px-2 py-1 text-sm" placeholder="Page Name">
        </div>
        <button class="px-3 py-2 rounded bg-blue-600 text-white text-sm">Queue Bulk Create</button>
      </form>

      <div class="text-xs text-gray-500 mt-2">
        Note: currently limited to 1000 IDs per click (adjustable).
      </div>
    </div>

    <div class="bg-white rounded shadow overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-gray-100">
          <tr>
            <th class="text-left p-2">ID</th>
            <th class="text-left p-2">Macro Output</th>
            <th class="text-left p-2">Status</th>
            <th class="text-left p-2">TX</th>
            <th class="text-left p-2">Mailno</th>
            <th class="text-left p-2">Receiver</th>
            <th class="text-left p-2">Updated</th>
            <th class="text-left p-2">Actions</th>
          </tr>
        </thead>
        <tbody>
          @foreach ($shipments as $s)
            <tr class="border-t">
              <td class="p-2">{{ $s->id }}</td>
              <td class="p-2">{{ $s->macro_output_id }}</td>
              <td class="p-2">
                <span class="px-2 py-1 rounded bg-gray-100">{{ $s->status }}</span>
                @if($s->last_reason)
                  <span class="ml-2 text-xs text-red-700">reason: {{ $s->last_reason }}</span>
                @endif
              </td>
              <td class="p-2 font-mono text-xs">{{ $s->txlogisticid }}</td>
              <td class="p-2 font-mono text-xs">{{ $s->mailno }}</td>
              <td class="p-2">
                <div class="font-medium">{{ $s->receiver_name }}</div>
                <div class="text-xs text-gray-600">{{ $s->receiver_phone }}</div>
                <div class="text-xs text-gray-600">{{ $s->receiver_prov }} / {{ $s->receiver_city }} / {{ $s->receiver_area }}</div>
              </td>
              <td class="p-2 text-xs text-gray-600">{{ $s->updated_at }}</td>
              <td class="p-2">
                <div class="flex flex-wrap gap-2">
                  @if($s->macro_output_id)
                    <form method="POST" action="{{ url('/jnt/shipments/create/'.$s->macro_output_id) }}">
                      @csrf
                      <button class="px-2 py-1 rounded bg-blue-600 text-white text-xs">Re-Queue Create</button>
                    </form>
                  @endif

                  <form method="POST" action="{{ url('/jnt/shipments/track/'.$s->id) }}">
                    @csrf
                    <button class="px-2 py-1 rounded bg-gray-900 text-white text-xs">Track</button>
                  </form>

                  <form method="POST" action="{{ url('/jnt/shipments/cancel/'.$s->id) }}">
                    @csrf
                    <button class="px-2 py-1 rounded bg-red-600 text-white text-xs">Cancel</button>
                  </form>
                </div>

                <details class="mt-2">
                  <summary class="cursor-pointer text-xs text-gray-700">Debug</summary>
                  <pre class="text-xs bg-gray-50 p-2 rounded overflow-x-auto">{{ json_encode($s->response_payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre>
                </details>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>

    <div class="bg-white rounded shadow p-3">
      {{ $shipments->links() }}
    </div>

  </div>
</x-layout>

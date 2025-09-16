<x-layout>
  <x-slot name="title">Return Scanned • Likha</x-slot>
  <x-slot name="heading"><div class="text-xl font-bold">📦 J&T Return Scanned</div></x-slot>

  <div class="space-y-6" x-data="scanUI('{{ $start }}','{{ $end }}')">

    {{-- Upload --}}
    <section class="bg-white rounded-xl shadow p-4">
      <h2 class="font-semibold mb-2">Upload Scanned Waybills</h2>
      <form method="POST" action="{{ route('jnt.return.scanned.upload') }}" class="space-y-3">
        @csrf
        <div>
          <label class="block text-sm font-semibold mb-1">Waybills (one per line)</label>
          <textarea name="waybills" rows="5" class="w-full border rounded p-2" required></textarea>
        </div>
        <div>
          <label class="block text-sm font-semibold mb-1">Scanned At</label>
          <input id="scannedAt" name="scanned_at" type="text"
                 class="w-48 border rounded p-2 bg-white cursor-pointer" readonly required>
        </div>
        <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Upload</button>
      </form>
    </section>

    {{-- Filters --}}
    <section class="bg-white rounded-xl shadow p-4">
      <div class="flex flex-wrap gap-3 items-end">
        <div>
          <label class="block text-sm font-semibold mb-1">Date Range</label>
          <input id="filterRange" type="text"
                 class="border rounded p-2 bg-white cursor-pointer" readonly>
        </div>
        <div>
          <label class="block text-sm font-semibold mb-1">Search Waybill</label>
          <form method="GET" action="{{ route('jnt.return.scanned') }}">
            <input type="hidden" name="start_date" :value="filters.start_date">
            <input type="hidden" name="end_date" :value="filters.end_date">
            <input type="text" name="search" value="{{ request('search') }}"
                   class="border rounded p-2" placeholder="Waybill no.">
            <button class="ml-2 bg-gray-700 text-white px-3 py-2 rounded">Search</button>
          </form>
        </div>
      </div>
    </section>

    {{-- Table --}}
    <section class="bg-white rounded-xl shadow p-4">
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm border border-gray-200">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-3 py-2 border-b text-left">Waybill Number</th>
              <th class="px-3 py-2 border-b text-left">Scanned By</th>
              <th class="px-3 py-2 border-b text-left">Scanned At</th>
              <th class="px-3 py-2 border-b"></th>
            </tr>
          </thead>
          <tbody>
            @forelse($rows as $row)
              <tr class="hover:bg-gray-50">
                <td class="px-3 py-2 border-b">{{ $row->waybill_number }}</td>
                <td class="px-3 py-2 border-b">{{ $row->scanned_by }}</td>
                <td class="px-3 py-2 border-b">{{ $row->scanned_at }}</td>
                <td class="px-3 py-2 border-b text-right">
                  <form method="POST" action="{{ route('jnt.return.scanned.delete',$row->id) }}"
                        onsubmit="return confirm('Delete this scan?')">
                    @csrf @method('DELETE')
                    <button class="text-red-600 hover:underline">Delete</button>
                  </form>
                </td>
              </tr>
            @empty
              <tr><td colspan="4" class="px-3 py-4 text-center text-gray-500">No records</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="mt-3">{{ $rows->links() }}</div>
    </section>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">

  <script>
    function scanUI(startDefault, endDefault){
      return {
        filters: { start_date: startDefault, end_date: endDefault },
        init(){
          // Upload date picker
          flatpickr("#scannedAt", { dateFormat: "Y-m-d", defaultDate: new Date() });

          // Filter range picker
          flatpickr("#filterRange", {
            mode: "range",
            dateFormat: "Y-m-d",
            defaultDate: [this.filters.start_date, this.filters.end_date],
            onClose: (sel) => {
              if(sel.length===2){
                this.filters.start_date = sel[0].toISOString().split('T')[0];
                this.filters.end_date   = sel[1].toISOString().split('T')[0];
              } else if(sel.length===1){
                this.filters.start_date = this.filters.end_date = sel[0].toISOString().split('T')[0];
              } else { return; }
              // reload page with filters
              const params = new URLSearchParams({
                start_date: this.filters.start_date,
                end_date: this.filters.end_date,
                search: "{{ request('search') }}"
              });
              window.location = "{{ route('jnt.return.scanned') }}?" + params.toString();
            }
          });
        }
      }
    }
  </script>
</x-layout>

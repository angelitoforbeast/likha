<x-layout>
  <x-slot name="heading">FROM JNT Records</x-slot>

  <div class="overflow-auto">
    <table class="table-auto w-full border text-sm">
      <thead>
        <tr class="bg-gray-200">
          <th class="px-2 py-1 border">Submission Time</th>
          <th class="px-2 py-1 border">Waybill Number</th>
          <th class="px-2 py-1 border">Receiver</th>
          <th class="px-2 py-1 border">Receiver Cellphone</th>
          <th class="px-2 py-1 border">Sender</th>
          <th class="px-2 py-1 border">Item Name</th>
          <th class="px-2 py-1 border">COD</th>
          <th class="px-2 py-1 border">Remarks</th>
          <th class="px-2 py-1 border">Status</th>
          <th class="px-2 py-1 border">SigningTime</th>
        </tr>
      </thead>
      <tbody>
        @forelse ($data as $row)
          <tr>
            <td class="px-2 py-1 border">{{ $row->submission_time }}</td>
            <td class="px-2 py-1 border">{{ $row->waybill_number }}</td>
            <td class="px-2 py-1 border">{{ $row->receiver }}</td>
            <td class="px-2 py-1 border">{{ $row->receiver_cellphone }}</td>
            <td class="px-2 py-1 border">{{ $row->sender }}</td>
            <td class="px-2 py-1 border">{{ $row->item_name }}</td>
            <td class="px-2 py-1 border">{{ $row->cod }}</td>
            <td class="px-2 py-1 border">{{ $row->remarks }}</td>
            <td class="px-2 py-1 border">{{ $row->status }}</td>
            <td class="px-2 py-1 border">{{ $row->signingtime }}</td>
          </tr>
        @empty
          <tr>
            <td colspan="10" class="text-center py-4 text-gray-500">No data available.</td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>
  <div class="mt-4">
    {{ $data->links() }}
</div>

</x-layout>

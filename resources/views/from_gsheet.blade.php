<x-layout>
    <x-slot name="heading">
        From GSheet Table
    </x-slot>

    <div class="overflow-auto bg-white p-4 rounded shadow">
        <table class="table-auto w-full text-sm border border-collapse">
            <thead class="bg-gray-200 text-gray-700">
                <tr>
                    <th class="px-2 py-1 border">ID</th>
                    <th class="px-2 py-1 border">Column 1</th>
                    <th class="px-2 py-1 border">Column 2</th>
                    <th class="px-2 py-1 border">Column 3</th>
                    <th class="px-2 py-1 border">Column 4</th>
                    <th class="px-2 py-1 border">Created At</th>
                    <th class="px-2 py-1 border">Updated At</th>
                </tr>
            </thead>
            <tbody>
                @foreach($data as $row)
                    <tr class="hover:bg-gray-100">
                        <td class="px-2 py-1 border">{{ $row->id }}</td>
                        <td class="px-2 py-1 border">{{ $row->column1 }}</td>
                        <td class="px-2 py-1 border">{{ $row->column2 }}</td>
                        <td class="px-2 py-1 border">{{ $row->column3 }}</td>
                        <td class="px-2 py-1 border">{{ $row->column4 }}</td>
                        <td class="px-2 py-1 border">{{ $row->created_at }}</td>
                        <td class="px-2 py-1 border">{{ $row->updated_at }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="mt-4">
  {{ $data->links() }}
    </div>

</x-layout>

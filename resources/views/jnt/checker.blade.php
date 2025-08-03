<x-layout>
    <x-slot name="heading">J&T Order Checker</x-slot>

    <form method="POST" action="{{ route('jnt.checker.upload') }}" enctype="multipart/form-data" class="p-4 space-y-4">
    @csrf
    <label class="block">
        <span class="text-gray-700">Upload Excel File (.xlsx)</span>
        <input type="file" name="excel_file" accept=".xlsx,.xls" required class="block mt-1 border border-gray-300 px-3 py-2 rounded w-full">
    </label>

    <label class="block">
    <span class="text-gray-700">Filter by Date (range optional)</span>
    <div class="flex gap-2 mt-1">
        <input type="date" name="filter_date_start" value="{{ old('filter_date_start', $filter_date_start ?? '') }}" class="border border-gray-300 px-3 py-2 rounded w-full">
        <span class="self-center">to</span>
        <input type="date" name="filter_date_end" value="{{ old('filter_date_end', $filter_date_end ?? '') }}" class="border border-gray-300 px-3 py-2 rounded w-full">
    </div>
</label>


    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">
        Check Orders
    </button>
</form>

@if(isset($matchedCount))
    <div class="mb-4 text-sm bg-gray-100 p-3 rounded border">
        ✅ Matched: <strong>{{ $matchedCount }}</strong> &nbsp;&nbsp;
        ❌ Not Matched: <strong>{{ $notMatchedCount }}</strong>
    </div>

    @if(!empty($filter_date))
        <div class="mb-2 text-sm text-gray-600">
            📅 Filtered by date: <strong>{{ \Carbon\Carbon::parse($filter_date)->format('F d, Y') }}</strong>
        </div>
    @endif
@endif
@if(isset($notInExcelCount))
    <div class="mb-2 text-sm text-red-600">
        📦 MacroOutput entries with no match in uploaded Excel: <strong>{{ $notInExcelCount }}</strong>
    </div>
@endif
    @if(isset($notInExcelCount) && $notInExcelCount > 0)
    <div class="mt-6">
        <h2 class="font-semibold text-sm mb-2 text-red-700">📦 Entries in MacroOutput with no match in uploaded Excel ({{ $notInExcelCount }})</h2>
        <table class="w-full border text-sm">
    <thead class="bg-red-100">
        <tr>
            <th class="border px-2 py-1">Page</th>
            <th class="border px-2 py-1">Full Name</th>
            <th class="border px-2 py-1">Phone Number</th>
            <th class="border px-2 py-1">Item Name</th>
            <th class="border px-2 py-1">COD</th>
            <th class="border px-2 py-1">Timestamp</th>
        </tr>
    </thead>
    <tbody>
        @foreach($notInExcelRows as $row)
            <tr>
                <td class="border px-2 py-1">{{ $row->PAGE }}</td>
                <td class="border px-2 py-1">{{ $row->{'FULL NAME'} }}</td>
                <td class="border px-2 py-1">{{ $row->{'PHONE NUMBER'} }}</td>
                <td class="border px-2 py-1">{{ $row->ITEM_NAME }}</td>
                <td class="border px-2 py-1">{{ $row->COD }}</td>
                <td class="border px-2 py-1">{{ $row->TIMESTAMP }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

    </div>
@endif


    @if(isset($results))
    <div class="mt-6">
        <table class="w-full border text-sm">
            <thead class="bg-gray-200">
                <tr>
                    <th class="border px-2 py-1">Sender</th>
                    <th class="border px-2 py-1">Mapped Page</th>
                    <th class="border px-2 py-1">Receiver</th>
                    <th class="border px-2 py-1">Item</th>
                    <th class="border px-2 py-1">COD</th>
                    <th class="border px-2 py-1">Match</th>
                </tr>
            </thead>
            <tbody>
                @foreach($results as $row)
                    <tr class="{{ $row['matched'] ? 'bg-green-50' : 'bg-red-50' }}">
                        <td class="border px-2 py-1">{{ $row['sender'] }}</td>
                        <td class="border px-2 py-1">{{ $row['page'] }}</td>
                        <td class="border px-2 py-1">{{ $row['receiver'] }}</td>
                        <td class="border px-2 py-1">{{ $row['item'] }}</td>
                        <td class="border px-2 py-1">{{ $row['cod'] }}</td>
                        <td class="border px-2 py-1 text-center">
                            {{ $row['matched'] ? '✔️ MATCHED' : '❌ NOT FOUND' }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif
</x-layout>

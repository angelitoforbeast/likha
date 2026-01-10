<x-layout>
    <x-slot name="heading">J&T Order Checker</x-slot>

    {{-- Flash messages --}}
    @if(session('error'))
        <div class="mb-4 bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded">
            ❌ {{ session('error') }}
        </div>
    @endif

    @if(session('success'))
        <div class="mb-4 bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded">
            ✅ {{ session('success') }}
        </div>
    @endif

    <form method="POST" action="{{ route('jnt.checker.upload') }}" enctype="multipart/form-data" class="p-4 space-y-4">
        @csrf

        <label class="block">
            <span class="text-gray-700">Upload Excel File (.xlsx / .xls) OR ZIP (.zip)</span>
            <input type="file" name="excel_file" accept=".xlsx,.xls,.zip" required
                   class="block mt-1 border border-gray-300 px-3 py-2 rounded w-full">
            <div class="text-xs text-gray-500 mt-1">
                ZIP support: ilagay mo sa ZIP ang 1 or multiple Excel files. Lahat ipi-process.
            </div>
        </label>

        <label class="block">
            <span class="text-gray-700">Filter by Date (range optional)</span>
            <div class="flex gap-2 mt-1">
                <input type="date" name="filter_date_start"
                       value="{{ old('filter_date_start', $filter_date_start ?? '') }}"
                       class="border border-gray-300 px-3 py-2 rounded w-full">
                <span class="self-center">to</span>
                <input type="date" name="filter_date_end"
                       value="{{ old('filter_date_end', $filter_date_end ?? '') }}"
                       class="border border-gray-300 px-3 py-2 rounded w-full">
            </div>
        </label>

        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">
            Check Orders
        </button>
    </form>

    @if(isset($matchedCount))
        <div class="mb-4 text-sm bg-gray-100 p-3 rounded border space-y-1">
            <div>
                ✅ Matched: <strong>{{ $matchedCount }}</strong>
                &nbsp;&nbsp; ❌ Not Matched: <strong>{{ $notMatchedCount }}</strong>
                @if(isset($mappingMissingCount))
                    &nbsp;&nbsp; 🟡 Mapping Missing: <strong>{{ $mappingMissingCount }}</strong>
                @endif
            </div>

            @if(isset($notInExcelCount))
                <div class="text-red-700">
                    📦 MacroOutput entries with no match in uploaded Excel: <strong>{{ $notInExcelCount }}</strong>
                </div>
            @endif

            @if(isset($skippedCancelCount))
                <div class="text-gray-700">
                    🚫 Skipped "Cancel Order" rows from Excel: <strong>{{ $skippedCancelCount }}</strong>
                    @if(isset($processedFilesCount))
                        <span class="text-gray-500"> (Files processed: {{ $processedFilesCount }})</span>
                    @endif
                </div>
            @endif

            @if(!empty($filter_date_start) || !empty($filter_date_end))
                <div class="text-gray-600">
                    📅 Filtered by date:
                    <strong>
                        @if(!empty($filter_date_start) && !empty($filter_date_end))
                            {{ \Carbon\Carbon::parse($filter_date_start)->format('F d, Y') }}
                            —
                            {{ \Carbon\Carbon::parse($filter_date_end)->format('F d, Y') }}
                        @elseif(!empty($filter_date_start))
                            {{ \Carbon\Carbon::parse($filter_date_start)->format('F d, Y') }}
                        @elseif(!empty($filter_date_end))
                            {{ \Carbon\Carbon::parse($filter_date_end)->format('F d, Y') }}
                        @endif
                    </strong>
                </div>
            @endif

            @if(isset($perfectMatch))
                <div>
                    @if($perfectMatch)
                        <span class="inline-flex items-center px-2 py-1 rounded bg-green-100 text-green-800 text-xs font-semibold">
                            ✅ PERFECT 1-to-1 MATCH
                        </span>
                    @else
                        <span class="inline-flex items-center px-2 py-1 rounded bg-yellow-100 text-yellow-800 text-xs font-semibold">
                            ⚠️ NOT PERFECT (duplicates / missing / extra exist)
                        </span>
                    @endif
                </div>
            @endif
        </div>
    @endif

    @if(isset($updatableCount) && $updatableCount > 0)
        <form method="POST" action="{{ route('jnt.checker.update') }}" class="mt-4">
            @csrf
            <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded">
                UPDATE ORDERS (set WAYBILL)
            </button>
            <div class="text-xs text-gray-600 mt-1">
                Will update <strong>{{ $updatableCount }}</strong> matched MacroOutput row(s) with WAYBILL from Excel.
            </div>
        </form>
    @endif

    @if(isset($notInExcelCount) && $notInExcelCount > 0)
        <div class="mt-6">
            <h2 class="font-semibold text-sm mb-2 text-red-700">
                📦 Entries in MacroOutput with no match in uploaded Excel ({{ $notInExcelCount }})
            </h2>

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
                    <th class="border px-2 py-1">Source File</th>
                    <th class="border px-2 py-1">Order Status</th>
                    <th class="border px-2 py-1">Sender</th>
                    <th class="border px-2 py-1">Mapped Page</th>
                    <th class="border px-2 py-1">Receiver</th>
                    <th class="border px-2 py-1">Item</th>
                    <th class="border px-2 py-1">COD</th>
                    <th class="border px-2 py-1">Waybill</th>
                    <th class="border px-2 py-1">Matched ID</th>
                    <th class="border px-2 py-1">Match</th>
                </tr>
                </thead>
                <tbody>
                @foreach($results as $row)
                    @php
                        $isMappingMissing = ($row['page'] ?? '') === '❌ Not Found in Mapping';
                        $bg = $isMappingMissing ? 'bg-yellow-50' : ($row['matched'] ? 'bg-green-50' : 'bg-red-50');
                    @endphp
                    <tr class="{{ $bg }}">
                        <td class="border px-2 py-1">{{ $row['source_file'] ?? '-' }}</td>
                        <td class="border px-2 py-1">{{ $row['order_status'] ?? '-' }}</td>
                        <td class="border px-2 py-1">{{ $row['sender'] }}</td>
                        <td class="border px-2 py-1">{{ $row['page'] }}</td>
                        <td class="border px-2 py-1">{{ $row['receiver'] }}</td>
                        <td class="border px-2 py-1">{{ $row['item'] }}</td>
                        <td class="border px-2 py-1">{{ $row['cod'] }}</td>
                        <td class="border px-2 py-1">{{ $row['waybill'] ?? '' }}</td>
                        <td class="border px-2 py-1">{{ $row['matched_id'] ?? '' }}</td>
                        <td class="border px-2 py-1 text-center">
                            @if($isMappingMissing)
                                🟡 MAPPING MISSING
                            @else
                                {{ $row['matched'] ? '✔️ MATCHED' : '❌ NOT FOUND' }}
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-layout>

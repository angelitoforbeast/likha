<x-layout>
    <x-slot name="heading">Likha Order Records</x-slot>

    <form method="GET" class="mb-4 flex flex-wrap gap-2 items-center">
        <input type="text" name="search" value="{{ request('search') }}"
            placeholder="Search name, phone, page..."
            class="border rounded px-3 py-1 text-sm" />

        <input type="date" name="date" value="{{ request('date') }}"
            class="border rounded px-3 py-1 text-sm" />

        <select name="page_name" class="border rounded px-3 py-1 text-sm">
            <option value="">All Pages</option>
            @foreach($pages as $page)
                <option value="{{ $page }}" {{ request('page_name') == $page ? 'selected' : '' }}>
                    {{ $page }}
                </option>
            @endforeach
        </select>

        <button type="submit"
            class="bg-blue-600 text-white text-sm px-3 py-1 rounded hover:bg-blue-700">
            🔍 Filter
        </button>

        <a href="{{ url('/likha_order/view') }}"
            class="text-sm text-gray-500 underline ml-2">Reset</a>
    </form>
    
    {{-- Pagination --}}
    <div class="mt-4">
        {{ $orders->withQueryString()->links() }}
    </div>
    <div class="overflow-auto">
        <table class="min-w-full border text-sm">
            <thead class="bg-gray-200">
                <tr>
                    <th class="border px-2 py-1">#</th>
                    <th class="border px-2 py-1">Date</th>
                    <th class="border px-2 py-1">Page Name</th>
                    <th class="border px-2 py-1">Name</th>
                    <th class="border px-2 py-1">Phone</th>
                    <th class="border px-2 py-1">All Input</th>
                    <th class="border px-2 py-1">Shop Details</th>
                    <th class="border px-2 py-1">Extracted Details</th>
                    <th class="border px-2 py-1">Price</th>
                </tr>
            </thead>
            <tbody>
                @forelse($orders as $order)
                    <tr>
                        <td class="border px-2 py-1">{{ $loop->iteration + ($orders->currentPage() - 1) * $orders->perPage() }}</td>
                        <td class="border px-2 py-1">{{ $order->date }}</td>
                        <td class="border px-2 py-1">{{ $order->page_name }}</td>
                        <td class="border px-2 py-1">{{ $order->name }}</td>
                        <td class="border px-2 py-1">{{ $order->phone_number }}</td>
                        <td class="border px-2 py-1 whitespace-pre-wrap break-words max-w-[300px]">{{ $order->all_user_input }}</td>
                        <td class="border px-2 py-1">{{ $order->shop_details }}</td>
                        <td class="border px-2 py-1">{{ $order->extracted_details }}</td>
                        <td class="border px-2 py-1">{{ $order->price }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="border px-2 py-4 text-center text-gray-500">No records found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

</x-layout>

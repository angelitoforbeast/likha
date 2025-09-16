{{-- resources/views/jnt/remittance.blade.php --}}
<x-layout>
    <x-slot name="heading">
        <div class="text-xl font-bold">📦 J&T Remittance</div>
    </x-slot>

    <form method="GET" action="{{ route('jnt.remittance') }}" class="mt-4 mb-6 px-4 flex gap-4 flex-wrap items-end">
        <div>
            <label class="text-sm font-medium">Start Date</label>
            <input type="date" name="start_date" value="{{ $start }}" class="border rounded px-2 py-1" />
        </div>
        <div>
            <label class="text-sm font-medium">End Date</label>
            <input type="date" name="end_date" value="{{ $end }}" class="border rounded px-2 py-1" />
        </div>
        <div>
            <button type="submit" class="bg-gray-800 text-white px-4 py-2 rounded hover:bg-black">
                Filter
            </button>
        </div>
        <div class="text-sm text-gray-500 ml-auto px-4">
            Default: Yesterday (Asia/Manila)
        </div>
    </form>

    <div class="overflow-x-auto mt-2 px-4">
        <table class="min-w-full border border-gray-200 bg-white">
            <thead class="bg-gray-50">
                <tr class="text-left">
                    <th class="px-3 py-2 border-b">Date</th>
                    <th class="px-3 py-2 border-b text-right">Number of Delivered</th>
                    <th class="px-3 py-2 border-b text-right">COD Sum</th>
                    <th class="px-3 py-2 border-b text-right">COD Fee (1.5%)</th>
                    <th class="px-3 py-2 border-b text-right">Parcels Picked up</th>
                    <th class="px-3 py-2 border-b text-right">Total Shipping Cost</th>
                    <th class="px-3 py-2 border-b text-right">Remittance</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $r)
                    <tr class="hover:bg-gray-50">
                        <td class="px-3 py-2 border-b whitespace-nowrap">{{ $r['date'] }}</td>
                        <td class="px-3 py-2 border-b text-right">{{ number_format($r['delivered']) }}</td>
                        <td class="px-3 py-2 border-b text-right">{{ number_format($r['cod_sum'], 2) }}</td>
                        <td class="px-3 py-2 border-b text-right">{{ number_format($r['cod_fee'], 2) }}</td>
                        <td class="px-3 py-2 border-b text-right">{{ number_format($r['picked']) }}</td>
                        <td class="px-3 py-2 border-b text-right">{{ number_format($r['ship_cost'], 2) }}</td>
                        <td class="px-3 py-2 border-b text-right font-semibold">{{ number_format($r['remittance'], 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td class="px-3 py-6 text-center text-gray-500" colspan="7">
                            No data for the selected date(s).
                        </td>
                    </tr>
                @endforelse
            </tbody>
            <tfoot class="bg-gray-50">
                <tr>
                    <th class="px-3 py-2 border-t text-right">TOTAL</th>
                    <th class="px-3 py-2 border-t text-right">{{ number_format($totals['delivered']) }}</th>
                    <th class="px-3 py-2 border-t text-right">{{ number_format($totals['cod_sum'], 2) }}</th>
                    <th class="px-3 py-2 border-t text-right">{{ number_format($totals['cod_fee'], 2) }}</th>
                    <th class="px-3 py-2 border-t text-right">{{ number_format($totals['picked']) }}</th>
                    <th class="px-3 py-2 border-t text-right">{{ number_format($totals['ship_cost'], 2) }}</th>
                    <th class="px-3 py-2 border-t text-right font-semibold">{{ number_format($totals['remittance'], 2) }}</th>
                </tr>
            </tfoot>
        </table>
    </div>
</x-layout>

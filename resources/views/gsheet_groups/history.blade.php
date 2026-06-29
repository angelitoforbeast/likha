<x-layout>
    <x-slot name="heading">GSheet Deletion History</x-slot>

    <div class="w-full max-w-6xl mx-auto mt-6 px-4">

        <div class="mb-4 flex items-center justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold text-gray-800">Deletion history</h2>
                <p class="text-sm text-gray-500">Lahat ng row-deletions &middot; before/after &middot; per-tab result</p>
            </div>
            <a href="/gsheet_groups"
               class="inline-flex items-center gap-1 px-3 py-1.5 text-sm rounded border border-gray-300 hover:bg-gray-50">← Back to groups</a>
        </div>

        @if($runs->isEmpty())
            <p class="text-gray-500 text-sm">Wala pang deletion na naitala.</p>
        @else
            <div class="overflow-x-auto bg-white border rounded-xl">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-gray-600 text-xs">
                        <tr>
                            <th class="px-3 py-2 text-left">When</th>
                            <th class="px-3 py-2 text-left">Group</th>
                            <th class="px-3 py-2 text-left">By</th>
                            <th class="px-3 py-2 text-left">Rows</th>
                            <th class="px-3 py-2 text-left">Last row (before → after)</th>
                            <th class="px-3 py-2 text-left">Status</th>
                            <th class="px-3 py-2 text-left">Per-tab result</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($runs as $r)
                            @php
                                $cls = match($r->status) {
                                    'done' => 'bg-green-100 text-green-800',
                                    'done_with_errors' => 'bg-yellow-100 text-yellow-800',
                                    'failed' => 'bg-red-100 text-red-800',
                                    'running' => 'bg-blue-100 text-blue-800',
                                    default => 'bg-gray-100 text-gray-700',
                                };
                                $bLast = data_get($r->before, 'after_lastrow');
                                $aLast = data_get($r->after, 'after_lastrow');
                            @endphp
                            <tr class="border-t align-top">
                                <td class="px-3 py-2 whitespace-nowrap text-gray-600">{{ optional($r->created_at)->format('Y-m-d H:i') }}</td>
                                <td class="px-3 py-2">{{ $r->group_name ?? ('#'.$r->group_id) }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $r->user_name ?? '—' }}</td>
                                <td class="px-3 py-2 whitespace-nowrap">3 → {{ $r->end_row }} <span class="text-gray-400">(~{{ number_format($r->deleted_total) }})</span></td>
                                <td class="px-3 py-2 whitespace-nowrap font-mono">
                                    {{ is_numeric($bLast) ? number_format($bLast) : '—' }} → {{ is_numeric($aLast) ? number_format($aLast) : '—' }}
                                </td>
                                <td class="px-3 py-2"><span class="px-2 py-0.5 rounded text-xs {{ $cls }}">{{ $r->status }}</span></td>
                                <td class="px-3 py-2 text-xs text-gray-700">
                                    @foreach(($r->result ?? []) as $e)
                                        <div>
                                            <span class="text-gray-500">{{ $e['spreadsheet'] ?? '' }} · {{ $e['tab'] ?? '' }}:</span>
                                            @if(($e['status'] ?? '') === 'ok')
                                                ✅ {{ $e['deleted'] ?? 0 }} <span class="text-gray-400">(before {{ $e['rows_before'] ?? '—' }})</span>
                                            @elseif(($e['status'] ?? '') === 'error')
                                                <span class="text-red-600">⚠️ {{ $e['error'] ?? 'error' }}</span>
                                            @elseif(($e['status'] ?? '') === 'not_found')
                                                not found
                                            @elseif(($e['status'] ?? '') === 'empty')
                                                walang data
                                            @else
                                                {{ $e['status'] ?? '?' }}
                                            @endif
                                        </div>
                                    @endforeach
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">{{ $runs->links() }}</div>
        @endif
    </div>
</x-layout>

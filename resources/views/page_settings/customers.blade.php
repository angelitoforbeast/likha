@extends('layouts.chatblast')

@section('title', 'Botcake customers · ' . $page)
@section('heading', 'Botcake customers · ' . $page)

@section('actions')
    <a href="{{ route('page-settings.psid-coverage.orders', ['page_name' => $page]) }}" class="text-blue-700 hover:underline">Orders ng page</a>
    <a href="{{ route('page-settings.index') }}" class="text-gray-600 hover:underline">Page Settings</a>
@endsection

@section('content')
<div>

    <form method="GET" action="{{ route('page-settings.customers') }}" class="flex flex-wrap items-center gap-2 mb-4 text-sm">
        <input type="hidden" name="page" value="{{ $page }}">
        <input name="q" value="{{ $search }}" placeholder="Pangalan (simula) o phone (09…)" class="border rounded px-2 py-1 w-64">
        <select name="status" class="border rounded px-2 py-1">
            @foreach(['all' => 'Lahat ng status', 'available' => 'Available', 'inactive' => 'Inactive'] as $v => $label)
                <option value="{{ $v }}" @selected($status === $v)>{{ $label }}</option>
            @endforeach
        </select>
        <select name="ad" class="border rounded px-2 py-1">
            @foreach(['all' => 'May ad id o wala', 'with' => 'May ad id', 'without' => 'Walang ad id'] as $v => $label)
                <option value="{{ $v }}" @selected($ad === $v)>{{ $label }}</option>
            @endforeach
        </select>
        <button class="bg-gray-200 hover:bg-gray-300 px-3 py-1 rounded">Filter</button>
        <span class="text-gray-500">{{ number_format($customers->total()) }} customer</span>
    </form>

    <div class="bg-white rounded shadow overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-left text-gray-500">
                <tr>
                    <th class="px-4 py-2">FB name</th><th class="px-2 py-2">PSID</th><th class="px-2 py-2">Ad ID</th><th class="px-2 py-2">Phone</th>
                    <th class="px-2 py-2">Subscribed</th><th class="px-2 py-2">Huling interaction</th><th class="px-2 py-2">Huling nakita</th>
                    <th class="px-2 py-2">Status</th><th class="px-2 py-2 text-right">Orders</th>
                </tr>
            </thead>
            <tbody>
            @forelse($customers as $c)
                @php($others = array_values(array_diff((array) ($c->ad_ids ?? []), [$c->ad_id])))
                <tr class="border-t align-top">
                    <td class="px-4 py-1">{{ $c->fb_name }}</td>
                    <td class="px-2 py-1 font-mono">{{ $c->psid }}</td>
                    <td class="px-2 py-1 font-mono whitespace-nowrap">
                        {{ $c->ad_id }}
                        @if($others)
                            <span class="ml-1 text-xs text-blue-700 cursor-help" title="{{ implode(', ', (array) $c->ad_ids) }}">+{{ count($others) }}</span>
                        @endif
                    </td>
                    <td class="px-2 py-1 whitespace-nowrap">{{ implode(', ', (array) ($c->phone_numbers ?? [])) }}</td>
                    <td class="px-2 py-1 whitespace-nowrap">{{ $c->subscribed_at?->timezone('Asia/Manila')->format('Y-m-d H:i') }}</td>
                    <td class="px-2 py-1 whitespace-nowrap">{{ $c->last_interactive_at?->timezone('Asia/Manila')->format('Y-m-d H:i') }}</td>
                    <td class="px-2 py-1 whitespace-nowrap">{{ $c->last_seen_at?->timezone('Asia/Manila')->format('Y-m-d H:i') }}</td>
                    <td class="px-2 py-1">{{ $c->status }}</td>
                    <td class="px-2 py-1 text-right">{{ $orderCounts[$c->psid] ?? 0 }}</td>
                </tr>
            @empty
                <tr><td colspan="9" class="px-4 py-2 text-gray-500">Walang customer.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-3 text-sm flex gap-4">
        @if($customers->previousPageUrl())<a class="text-blue-700 hover:underline" href="{{ $customers->previousPageUrl() }}">← Nauna</a>@endif
        <span class="text-gray-500">Page {{ $customers->currentPage() }} / {{ max(1, $customers->lastPage()) }}</span>
        @if($customers->nextPageUrl())<a class="text-blue-700 hover:underline" href="{{ $customers->nextPageUrl() }}">Susunod →</a>@endif
    </div>
</div>
@endsection

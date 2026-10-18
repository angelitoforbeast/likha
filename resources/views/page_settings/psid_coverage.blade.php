@extends('layouts.chatblast')

@section('title', 'PSID coverage')
@section('heading', 'PSID coverage')

@section('actions')
    <a href="{{ route('page-settings.index') }}" class="text-sm text-blue-700 hover:underline">Page Settings</a>
@endsection

@section('content')
<div class="max-w-6xl">

    <form method="GET" action="{{ route('page-settings.psid-coverage') }}" class="flex flex-wrap items-center gap-2 mb-2 text-sm">
        <label>Mula <input type="date" name="from" value="{{ $from }}" class="border rounded px-2 py-1"></label>
        <label>Hanggang <input type="date" name="to" value="{{ $to }}" class="border rounded px-2 py-1"></label>
        <button class="bg-gray-200 hover:bg-gray-300 px-3 py-1 rounded">Ipakita</button>
    </form>
    <p class="text-xs text-gray-500 mb-4">
        Batay sa petsa ng order (ts_date), hanggang 31 araw. I-click ang page para sa mga order na walang PSID at ang dahilan
        (ambiguous / walang tugma / maikakabit pa).
    </p>

    <div class="bg-white rounded shadow overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-left text-gray-500">
                <tr>
                    <th class="px-4 py-2">Page</th>
                    <th class="px-2 py-2 text-right">Orders</th>
                    <th class="px-2 py-2 text-right">May PSID</th>
                    <th class="px-2 py-2 text-right">%</th>
                    <th class="px-2 py-2 text-right">Walang PSID</th>
                </tr>
            </thead>
            <tbody>
            @forelse($summary as $row)
                <tr class="border-t">
                    <td class="px-4 py-1">
                        <a class="text-blue-700 hover:underline" href="{{ route('page-settings.psid-coverage.orders', ['page_name' => $row['page_name'], 'from' => $from, 'to' => $to]) }}">{{ $row['page_name'] }}</a>
                    </td>
                    <td class="px-2 py-1 text-right">{{ number_format($row['orders']) }}</td>
                    <td class="px-2 py-1 text-right">{{ number_format($row['with_psid']) }}</td>
                    <td class="px-2 py-1 text-right">{{ number_format($row['pct'], 1) }}</td>
                    <td class="px-2 py-1 text-right">{{ number_format($row['without_psid']) }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-2 text-gray-500">Walang order sa range na ito.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

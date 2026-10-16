<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Walang PSID · {{ $pageName }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 text-gray-900">

<div class="max-w-6xl mx-auto p-4 sm:p-6">
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-xl font-semibold">Walang PSID · {{ $pageName }}</h1>
        <a href="{{ route('page-settings.psid-coverage', ['from' => $from, 'to' => $to]) }}" class="text-sm text-blue-700 hover:underline">Lahat ng page</a>
    </div>

    <form method="GET" action="{{ route('page-settings.psid-coverage.orders') }}" class="flex flex-wrap items-center gap-2 mb-4 text-sm">
        <input type="hidden" name="page_name" value="{{ $pageName }}">
        <label>Mula <input type="date" name="from" value="{{ $from }}" class="border rounded px-2 py-1"></label>
        <label>Hanggang <input type="date" name="to" value="{{ $to }}" class="border rounded px-2 py-1"></label>
        <select name="jnt" class="border rounded px-2 py-1">
            @foreach(['all' => 'Lahat ng J&T status', 'In Transit' => 'In Transit', 'Delivering' => 'Delivering'] as $v => $label)
                <option value="{{ $v }}" @selected($jnt === $v)>{{ $label }}</option>
            @endforeach
        </select>
        <select name="reason" class="border rounded px-2 py-1">
            @foreach(['all' => 'Lahat ng dahilan', 'ambiguous' => 'Ambiguous', 'no_match' => 'Walang tugma', 'not_synced' => 'Maikakabit pa / hindi pa nasusuri'] as $v => $label)
                <option value="{{ $v }}" @selected($reason === $v)>{{ $label }}</option>
            @endforeach
        </select>
        <button class="bg-gray-200 hover:bg-gray-300 px-3 py-1 rounded">Filter</button>
        <span class="text-gray-500">{{ number_format($orders->total()) }} order</span>
    </form>

    <p class="text-sm mb-1">
        Ambiguous: {{ number_format($split['ambiguous']) }} · Walang tugma: {{ number_format($split['no_match']) }} ·
        Maikakabit pa / hindi pa nasusuri: {{ number_format($split['not_synced']) }}
    </p>
    <p class="text-xs text-gray-500 mb-4">
        Mula sa mga available na Botcake customer ng page ngayon. Ambiguous = dalawa o higit pang customer ang tugma;
        maikakabit pa = isang customer ang tugma pero hindi pa dumaan ang matching, o walang Botcake customers ang page.
    </p>

    <div class="bg-white rounded shadow overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-left text-gray-500">
                <tr><th class="px-4 py-2">Order</th><th class="px-2 py-2">Petsa</th><th class="px-2 py-2">FB name</th><th class="px-2 py-2">Phone</th><th class="px-2 py-2">Waybill</th><th class="px-2 py-2">J&amp;T</th><th class="px-2 py-2">Dahilan</th></tr>
            </thead>
            <tbody>
            @forelse($orders as $o)
                <tr class="border-t">
                    <td class="px-4 py-1">#{{ $o->id }}</td>
                    <td class="px-2 py-1 whitespace-nowrap">{{ $o->ts_date }}</td>
                    <td class="px-2 py-1">{{ $o->fb_name }}</td>
                    <td class="px-2 py-1">{{ $o->phone }}</td>
                    <td class="px-2 py-1">{{ $o->waybill }}</td>
                    <td class="px-2 py-1">{{ $o->jnt_status }}</td>
                    <td class="px-2 py-1">{{ ['ambiguous' => 'Ambiguous', 'no_match' => 'Walang tugma'][$o->reason] ?? 'Maikakabit pa / hindi pa nasusuri' }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-2 text-gray-500">Walang order.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-3 text-sm flex gap-4">
        @if($orders->previousPageUrl())<a class="text-blue-700 hover:underline" href="{{ $orders->previousPageUrl() }}">← Nauna</a>@endif
        <span class="text-gray-500">Page {{ $orders->currentPage() }} / {{ max(1, $orders->lastPage()) }}</span>
        @if($orders->nextPageUrl())<a class="text-blue-700 hover:underline" href="{{ $orders->nextPageUrl() }}">Susunod →</a>@endif
    </div>
</div>
</body>
</html>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Page Settings</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 text-gray-900">

<div class="max-w-6xl mx-auto p-4 sm:p-6">
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-2xl font-semibold">Page Settings</h1>
        <a href="{{ url('/') }}" class="text-sm text-gray-600 hover:underline">Home</a>
    </div>

    @if(session('success'))
        <div class="mb-3 px-4 py-2 rounded bg-green-100 text-green-800 text-sm">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-3 px-4 py-2 rounded bg-red-100 text-red-800 text-sm">{{ session('error') }}</div>
    @endif
    @if($errors->any())
        <div class="mb-3 px-4 py-2 rounded bg-red-100 text-red-800 text-sm">
            @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
        </div>
    @endif

    {{-- Dagdag o palit: kapag may active na ang page + provider + key, ang bagong value ang papalit (ang luma = history). --}}
    <form method="POST" action="{{ route('page-settings.store') }}" class="bg-white p-4 rounded shadow mb-5 grid grid-cols-1 sm:grid-cols-6 gap-2 text-sm">
        @csrf
        <input name="page_name" value="{{ old('page_name') }}" list="page-names" placeholder="Page (gaya ng PAGE sa orders)" class="border rounded px-2 py-1 sm:col-span-2" required>
        <input name="provider" value="{{ old('provider', 'botcake') }}" placeholder="provider" class="border rounded px-2 py-1" required>
        <input name="key" value="{{ old('key') }}" list="known-keys" placeholder="key (hal. page_id)" class="border rounded px-2 py-1" required>
        <input name="value" placeholder="value" autocomplete="off" class="border rounded px-2 py-1" required>
        <div class="flex items-center gap-3">
            <label class="flex items-center gap-1"><input type="checkbox" name="is_secret" value="1"> secret</label>
            <button class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded">Save</button>
        </div>
        <p class="sm:col-span-6 text-xs text-gray-500">
            Kilalang botcake keys: <code>page_id</code> (numero), <code>token</code> (laging secret), <code>flow.&lt;purpose&gt;</code> (numero; hal. on_delivery, in_transit, broadcast, shipping),
            <code>field.&lt;name&gt;</code> (numero). Ang <code>token_status</code> ay isinusulat ng system. Ibang key = malayang text.
        </p>
    </form>
    <datalist id="page-names">@foreach($pages as $p)<option value="{{ $p['name'] }}">@endforeach</datalist>
    <datalist id="known-keys">
        <option value="page_id"><option value="token"><option value="flow.on_delivery"><option value="flow.in_transit"><option value="flow.broadcast"><option value="flow.shipping">
    </datalist>

    <form method="GET" action="{{ route('page-settings.index') }}" class="flex flex-wrap gap-2 mb-4 text-sm">
        <input name="page" value="{{ $filters['page'] }}" placeholder="Filter page" class="border rounded px-2 py-1">
        <select name="provider" class="border rounded px-2 py-1">
            <option value="">Lahat ng provider</option>
            @foreach($providers as $prov)<option value="{{ $prov }}" @selected($filters['provider'] === $prov)>{{ $prov }}</option>@endforeach
        </select>
        <input name="key" value="{{ $filters['key'] }}" placeholder="Filter key" class="border rounded px-2 py-1">
        <button class="bg-gray-200 hover:bg-gray-300 px-3 py-1 rounded">Filter</button>
    </form>

    @forelse($pages as $pageKey => $p)
        <div class="bg-white rounded shadow mb-4">
            <div class="px-4 py-2 border-b font-semibold">{{ $p['name'] }}</div>

            @includeIf('page_settings._botcake_panel', ['pageName' => $p['name']])

            <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-gray-500">
                    <tr><th class="px-4 py-1">Provider</th><th class="px-2 py-1">Key</th><th class="px-2 py-1">Value</th><th class="px-2 py-1">Mula</th><th class="px-2 py-1">Palitan</th><th></th></tr>
                </thead>
                <tbody>
                @foreach($p['active'] as $row)
                    <tr class="border-t align-top">
                        <td class="px-4 py-1">{{ $row->provider }}</td>
                        <td class="px-2 py-1 font-mono">{{ $row->key }}</td>
                        <td class="px-2 py-1 font-mono break-all">{{ $row->displayValue() }}</td>
                        <td class="px-2 py-1 text-gray-500 whitespace-nowrap">{{ $row->created_at?->timezone('Asia/Manila')->format('Y-m-d H:i') }} · {{ $row->created_by ? 'user #' . $row->created_by : 'system' }}</td>
                        <td class="px-2 py-1">
                            @unless($row->fullKey() === 'botcake.token_status')
                            <form method="POST" action="{{ route('page-settings.store') }}" class="flex gap-1">
                                @csrf
                                <input type="hidden" name="page_name" value="{{ $row->page_name }}">
                                <input type="hidden" name="provider" value="{{ $row->provider }}">
                                <input type="hidden" name="key" value="{{ $row->key }}">
                                @if($row->is_secret)<input type="hidden" name="is_secret" value="1">@endif
                                <input name="value" autocomplete="off" placeholder="{{ $row->is_secret ? 'bagong secret' : 'bagong value' }}" class="border rounded px-2 py-0.5 w-36" required>
                                <button class="bg-gray-200 hover:bg-gray-300 px-2 rounded">Save</button>
                            </form>
                            @endunless
                        </td>
                        <td class="px-2 py-1">
                            <form method="POST" action="{{ route('page-settings.deactivate', $row->id) }}" onsubmit="return confirm('I-deactivate ang {{ $row->fullKey() }}?')">
                                @csrf
                                <button class="text-red-600 hover:underline">Deactivate</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                @foreach($p['history'] as $fullKey => $old)
                    <tr class="border-t">
                        <td colspan="6" class="px-4 py-1">
                            <details>
                                <summary class="cursor-pointer text-gray-500">History: {{ $fullKey }} ({{ $old->count() }})</summary>
                                <ul class="ml-4 text-xs text-gray-600">
                                    @foreach($old as $h)
                                        <li class="font-mono">{{ $h->displayValue() }} · {{ $h->created_at?->timezone('Asia/Manila')->format('Y-m-d H:i') }} → {{ $h->replaced_at?->timezone('Asia/Manila')->format('Y-m-d H:i') }} · {{ $h->created_by ? 'user #' . $h->created_by : 'system' }}</li>
                                    @endforeach
                                </ul>
                            </details>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        </div>
    @empty
        <div class="text-sm text-gray-500">Wala pang settings.</div>
    @endforelse
</div>
</body>
</html>

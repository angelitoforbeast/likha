@php($run = $panel['last_run'])
<div class="px-4 py-2 border-b bg-gray-50 text-sm flex flex-wrap items-center gap-x-4 gap-y-2">
    <span>Customers: {{ $panel['customers'] }}</span>
    <span>Token: {{ $panel['token_status'] ?? 'hindi pa nasusubukan' }}</span>
    <span>
        Huling sync:
        @if($run)
            {{ $run->created_at?->timezone('Asia/Manila')->format('Y-m-d H:i') }} · {{ $run->status }} ·
            {{ $run->batches_read }} batch ({{ $run->stop_reason ?? '-' }}), {{ $run->customers_new }} bago, {{ $run->customers_updated }} na-update ·
            match {{ $run->matched_by_name }} pangalan, {{ $run->matched_by_phone }} phone, {{ $run->ambiguous }} ambiguous, {{ $run->no_match }} wala
            @if($run->last_error)<span class="text-red-600">· {{ $run->last_error }}</span>@endif
        @else
            wala pa
        @endif
    </span>
    <form method="POST" action="{{ route('page-settings.test-token') }}">
        @csrf
        <input type="hidden" name="page_name" value="{{ $pageName }}">
        <button class="bg-gray-200 hover:bg-gray-300 px-2 py-0.5 rounded">Test token</button>
    </form>
    <form method="POST" action="{{ route('page-settings.sync') }}">
        @csrf
        <input type="hidden" name="page_name" value="{{ $pageName }}">
        <button class="bg-blue-600 hover:bg-blue-700 text-white px-2 py-0.5 rounded">Sync Botcake customers now</button>
    </form>
    <a href="{{ route('page-settings.psid-coverage.orders', ['page_name' => $pageName]) }}" class="text-blue-700 hover:underline">Orders na wala pang PSID</a>
</div>


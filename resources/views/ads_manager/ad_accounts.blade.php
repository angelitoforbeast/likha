{{-- Spec 284: ang pera at kalusugan ng bawat Meta ad account. Walang token dito; ang balance at payment ay sa may "Ads" access lang. --}}
@php
  use App\Services\MetaAds\AccountHealth;
  $rows = $health['rows'] ?? [];
  $warnings = AccountHealth::warnings($health);
  $na = 'not available';
  $time = fn ($iso) => $iso ? \Carbon\Carbon::parse($iso)->timezone('Asia/Manila')->format('M j, Y g:i A') : $na;
@endphp
<x-layout>
  <x-slot name="heading">Ad Accounts</x-slot>

  <div class="max-w-6xl mt-6 space-y-4" id="ad-accounts-page">
    <div class="flex items-center justify-between flex-wrap gap-2">
      <a href="{{ route('ads_manager.report') }}" class="text-sm text-blue-700 underline">← Ads Manager report</a>
      @if (empty($health['none']))
        <form method="POST" action="{{ route('ads_manager.meta.ad_accounts.refresh') }}" onsubmit="this.querySelector('button').disabled=true;">
          @csrf
          <button class="bg-blue-600 hover:bg-blue-700 text-white text-sm px-3 py-1.5 rounded">Refresh now</button>
        </form>
      @endif
    </div>

    @if (session('status'))
      <div class="rounded bg-green-100 text-green-800 px-3 py-2 text-sm">{{ session('status') }}</div>
    @endif

    @if (!empty($health['none']))
      <div class="rounded border bg-gray-50 px-4 py-6 text-sm text-gray-700" id="ad-accounts-empty">
        No Meta connection on this site. Connect a Facebook account on the Ads Manager report page to see the ad accounts here.
      </div>
    @else
      @foreach ($warnings as $w)
        <div class="rounded border px-3 py-2 text-sm {{ $w['level'] === 'red' ? 'border-red-300 bg-red-50 text-red-800' : 'border-amber-300 bg-amber-50 text-amber-800' }}"
             role="alert" data-warning="{{ $w['level'] }}">
          ⚠ <span class="font-semibold break-words">{{ $w['name'] }}</span> <span class="font-mono break-all">{{ $w['id'] }}</span>: {{ $w['warning'] }}
        </div>
      @endforeach

      @if (!empty($health['errors']))
        <div class="rounded border border-red-300 bg-red-50 text-red-800 px-3 py-2 text-sm space-y-1" id="ad-accounts-errors">
          <div class="font-semibold">Last read from Meta failed for some connections (showing the last good numbers with their time):</div>
          @foreach ($health['errors'] as $e)<div class="break-words">{{ $e }}</div>@endforeach
          <div class="text-xs">Tried {{ $time(isset($health['attempted_at']) ? date('c', $health['attempted_at']) : null) }}</div>
        </div>
      @endif

      @if (!$rows)
        <p class="text-sm text-gray-600">No ad account could be read yet.</p>
      @else
        {{-- Wide screens: one table. --}}
        <div class="hidden md:block overflow-x-auto border rounded">
          <table class="min-w-full text-sm" id="ad-accounts-table">
            <thead class="bg-gray-50 text-left text-xs uppercase text-gray-600">
              <tr>
                <th class="px-3 py-2">Ad account</th>
                <th class="px-3 py-2">Connection</th>
                <th class="px-3 py-2">Status</th>
                <th class="px-3 py-2 text-right">Balance (unpaid)</th>
                <th class="px-3 py-2 text-right">Spent (lifetime)</th>
                <th class="px-3 py-2 text-right">Spend cap</th>
                <th class="px-3 py-2">Payment method</th>
                <th class="px-3 py-2">Read at</th>
              </tr>
            </thead>
            <tbody class="divide-y">
              @foreach ($rows as $r)
                @php $p = AccountHealth::present($r); $cur = $r['currency'] ?? null; @endphp
                <tr data-account="{{ $r['id'] }}" class="{{ $p['level'] === 'red' ? 'bg-red-50' : ($p['level'] === 'amber' ? 'bg-amber-50' : '') }}">
                  <td class="px-3 py-2 align-top">
                    <div class="font-medium break-words">{{ $r['name'] }}</div>
                    <div class="font-mono text-xs text-gray-500">{{ $r['id'] }} · {{ $cur ?? 'currency ' . $na }}</div>
                    @if (!empty($r['problem']))<div class="text-xs text-red-700 break-words">{{ $r['problem'] }}</div>@endif
                  </td>
                  <td class="px-3 py-2 align-top text-xs">{{ implode(', ', $r['who'] ?? []) ?: $na }}</td>
                  <td class="px-3 py-2 align-top">
                    <span class="{{ $p['level'] === 'red' ? 'text-red-700 font-semibold' : '' }}">{{ $p['status'] }}</span>
                    @if ($p['reason'])<div class="text-xs text-gray-600">Reason: {{ $p['reason'] }}</div>@endif
                  </td>
                  <td class="px-3 py-2 align-top text-right whitespace-nowrap">{{ $p['balance'] !== null ? $cur . ' ' . $p['balance'] : $na }}</td>
                  <td class="px-3 py-2 align-top text-right whitespace-nowrap">{{ $p['spent'] !== null ? $cur . ' ' . $p['spent'] : $na }}</td>
                  <td class="px-3 py-2 align-top text-right whitespace-nowrap">
                    @if ($p['no_limit']) No limit
                    @elseif ($p['cap'] === null) {{ $na }}
                    @else
                      {{ $cur }} {{ $p['cap'] }}
                      @if ($p['pct'] !== null)<div class="text-xs {{ $p['level'] ? 'font-semibold' : 'text-gray-600' }}">{{ $cur }} {{ $p['remaining'] }} left · {{ rtrim(rtrim(number_format($p['pct'], 1), '0'), '.') }}% used</div>@endif
                    @endif
                  </td>
                  <td class="px-3 py-2 align-top break-words">{{ $r['payment'] ?? $na }}</td>
                  <td class="px-3 py-2 align-top text-xs whitespace-nowrap">{{ $time($r['read_at'] ?? null) }}</td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>

        {{-- Phones: one card per account. --}}
        <div class="md:hidden space-y-3" id="ad-accounts-cards">
          @foreach ($rows as $r)
            @php $p = AccountHealth::present($r); $cur = $r['currency'] ?? null; @endphp
            <div class="border rounded p-3 text-sm space-y-1 {{ $p['level'] === 'red' ? 'border-red-300 bg-red-50' : ($p['level'] === 'amber' ? 'border-amber-300 bg-amber-50' : '') }}">
              <div class="font-medium break-words">{{ $r['name'] }}</div>
              <div class="font-mono text-xs text-gray-500 break-all">{{ $r['id'] }} · {{ $cur ?? 'currency ' . $na }}</div>
              @if (!empty($r['problem']))<div class="text-xs text-red-700 break-words">{{ $r['problem'] }}</div>@endif
              <dl class="grid grid-cols-2 gap-x-2 gap-y-1">
                <dt class="text-gray-600">Status</dt>
                <dd class="{{ $p['level'] === 'red' ? 'text-red-700 font-semibold' : '' }}">{{ $p['status'] }}@if ($p['reason'])<span class="block text-xs font-normal text-gray-600">Reason: {{ $p['reason'] }}</span>@endif</dd>
                <dt class="text-gray-600">Balance (unpaid)</dt><dd>{{ $p['balance'] !== null ? $cur . ' ' . $p['balance'] : $na }}</dd>
                <dt class="text-gray-600">Spent (lifetime)</dt><dd>{{ $p['spent'] !== null ? $cur . ' ' . $p['spent'] : $na }}</dd>
                <dt class="text-gray-600">Spend cap</dt>
                <dd>
                  @if ($p['no_limit']) No limit
                  @elseif ($p['cap'] === null) {{ $na }}
                  @else {{ $cur }} {{ $p['cap'] }}@if ($p['pct'] !== null)<span class="block text-xs">{{ $cur }} {{ $p['remaining'] }} left · {{ rtrim(rtrim(number_format($p['pct'], 1), '0'), '.') }}% used</span>@endif
                  @endif
                </dd>
                <dt class="text-gray-600">Payment method</dt><dd class="break-words">{{ $r['payment'] ?? $na }}</dd>
                <dt class="text-gray-600">Connection</dt><dd class="break-words text-xs">{{ implode(', ', $r['who'] ?? []) ?: $na }}</dd>
                <dt class="text-gray-600">Read at</dt><dd class="text-xs">{{ $time($r['read_at'] ?? null) }}</dd>
              </dl>
            </div>
          @endforeach
        </div>
      @endif

      @if (!empty($health['notes']))
        <div class="text-xs text-gray-600 space-y-0.5" id="ad-accounts-notes">
          <div class="font-semibold">Why some fields are "not available":</div>
          @foreach ($health['notes'] as $n)<div class="break-words">{{ $n }}</div>@endforeach
        </div>
      @endif
      <p class="text-xs text-gray-500">Read from Meta at most every {{ AccountHealth::FRESH_MINUTES }} minutes, every hour and after each scheduled sync. Read-only: nothing is changed in Meta.</p>
    @endif
  </div>
</x-layout>

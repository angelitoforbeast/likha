<x-layout>
  <x-slot name="heading">Order encode: orders not sent to Forbeast</x-slot>

  <div class="mb-4 flex gap-2 text-sm">
    <a href="{{ route('order-encode.index') }}" class="rounded border px-3 py-1 text-gray-700">Sheet compare</a>
    <a href="{{ route('order-encode.final') }}" class="rounded border px-3 py-1 text-gray-700">All pages (vs final order)</a>
    <a href="{{ route('order-encode.notes') }}" class="rounded border px-3 py-1 text-gray-700">Address notes</a>
    <a href="{{ route('order-encode.forbeast') }}" class="rounded bg-blue-500 px-3 py-1 text-white">Forbeast sends</a>
  </div>

  @if (session('status'))<div class="mb-3 rounded bg-green-100 px-3 py-2 text-sm text-green-800">{{ session('status') }}</div>@endif
  @if (session('error'))<div class="mb-3 rounded bg-red-100 px-3 py-2 text-sm text-red-800">{{ session('error') }}</div>@endif

  <p class="mb-3 text-sm text-gray-600">
    Every order written to the macro is also sent to Forbeast, to the page's gateway. Listed here: sends Forbeast refused
    (<b>failed</b>) and sends held because the gateway refused this site's key (<b>paused</b>; they resume by themselves once the key works).
    @unless ($live) <b>Sending is off</b> (no go-live time is set). @endunless
  </p>

  @if ($rows->isEmpty())
    <p class="text-sm text-gray-500">Nothing failed or paused.</p>
  @else
    <table class="w-full text-left text-sm">
      <thead class="border-b text-gray-500">
        <tr><th class="py-1 pr-3">Order</th><th class="py-1 pr-3">Page</th><th class="py-1 pr-3">State</th><th class="py-1 pr-3">Reason</th><th class="py-1 pr-3">Time</th><th class="py-1"></th></tr>
      </thead>
      <tbody>
        @foreach ($rows as $r)
          <tr class="border-b align-top">
            <td class="py-1 pr-3 font-mono">{{ $r['ref'] }}<div class="text-xs text-gray-500">row {{ $r['row'] }} · {{ $r['gateway'] }} · {{ $r['tries'] }} {{ $r['tries'] === 1 ? 'try' : 'tries' }}</div></td>
            <td class="py-1 pr-3">{{ $r['page'] }}</td>
            <td class="py-1 pr-3">{{ $r['state'] }}</td>
            <td class="py-1 pr-3">{{ $r['reason'] }}</td>
            <td class="py-1 pr-3 whitespace-nowrap">{{ $r['at'] !== '' ? \Carbon\Carbon::parse($r['at'])->setTimezone('Asia/Manila')->format('Y-m-d H:i') : '' }}</td>
            <td class="py-1">
              <form method="POST" action="{{ route('order-encode.forbeast.resend', $r['id']) }}">
                @csrf
                <button type="submit" class="rounded border px-2 py-0.5 text-blue-700">Send again</button>
              </form>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endif
</x-layout>

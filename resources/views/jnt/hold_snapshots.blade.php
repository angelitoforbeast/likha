<x-layout>
  <x-slot name="title">HOLD Snapshots</x-slot>
  <x-slot name="heading">HOLD Snapshots (per item, per day)</x-slot>

  <div class="max-w-5xl mx-auto p-4">

    @if (session('success'))
      <div class="mb-3 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-green-800">{{ session('success') }}</div>
    @endif
    @if (session('error'))
      <div class="mb-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-red-800">{{ session('error') }}</div>
    @endif

    <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-4">
      <div class="text-sm font-semibold text-amber-900 mb-1">📸 Manual Snapshot (para makapag-test agad)</div>
      <div class="text-xs text-amber-800 mb-3">
        Ang daily cron (6 AM PH) ang awtomatikong kukuha nito — pero habang wala pang
        <code>schedule:run</code> sa server, dito ka muna mag-capture nang manual.
        Kino-compute ang HOLD as-of ngayon (current J&T state) para sa napiling date.
      </div>
      <form method="POST" action="{{ route('jnt.hold-snapshots.run') }}" class="flex flex-wrap items-end gap-3">
        @csrf
        <div>
          <label class="block text-[11px] font-semibold text-amber-900 mb-1">Snapshot date (= end_date sa /owner/private)</label>
          <input type="date" name="date" value="{{ $defaultDate }}"
                 class="border border-amber-300 rounded px-3 py-2 text-sm bg-white">
        </div>
        <div>
          <label class="block text-[11px] font-semibold text-amber-900 mb-1">Window (days)</label>
          <input type="number" name="window" value="60" min="1" max="365"
                 class="border border-amber-300 rounded px-3 py-2 text-sm bg-white w-24">
        </div>
        <button type="submit"
                class="inline-flex items-center rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700">
          📸 Snapshot now
        </button>
      </form>
    </div>

    <div class="mb-3 flex items-center justify-between gap-2">
      <form method="GET" action="{{ route('jnt.hold-snapshots') }}" class="flex items-center gap-2">
        <label class="text-sm text-gray-600">View date:</label>
        <select name="date" onchange="this.form.submit()"
                class="border border-gray-300 rounded px-3 py-2 text-sm bg-white">
          @forelse ($dates as $d)
            <option value="{{ $d }}" @selected($d === $selected)>{{ $d }}</option>
          @empty
            <option value="{{ $selected }}">{{ $selected }} (wala pang snapshot)</option>
          @endforelse
        </select>
      </form>
      <div class="text-sm text-gray-600">
        Total held units: <span class="font-bold text-gray-900">{{ number_format($totalUnits) }}</span>
        · {{ $rows->count() }} item(s)
      </div>
    </div>

    <div class="overflow-x-auto rounded-xl border bg-white shadow-sm">
      <table class="min-w-full text-sm">
        <thead class="bg-gray-50 text-gray-700">
          <tr>
            <th class="px-4 py-3 text-left font-semibold">Item (base)</th>
            <th class="px-4 py-3 text-right font-semibold">HOLD (units)</th>
            <th class="px-4 py-3 text-left font-semibold">Captured at</th>
          </tr>
        </thead>
        <tbody class="divide-y">
          @forelse ($rows as $r)
            <tr class="hover:bg-gray-50/60">
              <td class="px-4 py-2 text-gray-900">{{ $r->item_name }}</td>
              <td class="px-4 py-2 text-right tabular-nums font-semibold {{ (int)$r->hold_units > 0 ? 'text-red-600' : 'text-gray-400' }}">
                {{ number_format((int) $r->hold_units) }}
              </td>
              <td class="px-4 py-2 text-gray-500">
                {{ $r->captured_at ? \Carbon\Carbon::parse($r->captured_at)->timezone('Asia/Manila')->format('Y-m-d H:i') : '—' }}
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="3" class="px-4 py-8 text-center text-gray-500">
                Walang snapshot para sa {{ $selected }}. Pindutin ang "📸 Snapshot now" sa taas para mag-capture.
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>

    <div class="mt-4 text-xs text-gray-500">
      HOLD = orders na may waybill pero wala pa sa J&T (<code>from_jnts</code>) — units per base item.
      Lalabas din ito sa <code>/owner/private</code> HOLD column (mapped sa primary item ng page, gamit ang snapshot ng end_date).
    </div>
  </div>
</x-layout>

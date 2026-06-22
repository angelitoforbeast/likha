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

    {{-- Cron time (editable sa UI — hindi hardcoded) --}}
    <div class="mb-4 flex items-center justify-between rounded-lg border bg-slate-50 px-4 py-2">
      <div class="text-sm text-slate-700">⏰ Daily cron time: <span class="font-bold">{{ $scheduleTime ?? '06:00' }}</span> <span class="text-slate-400">(PH)</span></div>
      <a href="{{ route('jnt.hold-snapshots.schedule') }}" class="text-sm font-semibold text-blue-600 hover:underline">✏️ I-edit ang oras →</a>
    </div>

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

    {{-- Run history (logs) — kelan tumakbo ang snapshot, cron/manual, success/error --}}
    <div class="mt-6">
      <div class="text-sm font-semibold text-gray-800 mb-2">🧾 Run history (logs)</div>
      <div class="overflow-x-auto rounded-xl border bg-white shadow-sm">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 text-gray-700">
            <tr>
              <th class="px-3 py-2 text-left font-semibold">Tumakbo (PH)</th>
              <th class="px-3 py-2 text-left font-semibold">Snapshot date</th>
              <th class="px-3 py-2 text-left font-semibold">Source</th>
              <th class="px-3 py-2 text-left font-semibold">Status</th>
              <th class="px-3 py-2 text-right font-semibold">Items</th>
              <th class="px-3 py-2 text-right font-semibold">Units</th>
              <th class="px-3 py-2 text-right font-semibold">Window</th>
              <th class="px-3 py-2 text-right font-semibold">Tagal</th>
              <th class="px-3 py-2 text-left font-semibold">Message</th>
            </tr>
          </thead>
          <tbody class="divide-y">
            @forelse ($logs ?? [] as $lg)
              <tr class="hover:bg-gray-50/60">
                <td class="px-3 py-2 text-gray-700 whitespace-nowrap">{{ $lg->created_at ? \Carbon\Carbon::parse($lg->created_at)->timezone('Asia/Manila')->format('Y-m-d H:i') : '—' }}</td>
                <td class="px-3 py-2 text-gray-700 whitespace-nowrap">{{ \Carbon\Carbon::parse($lg->snapshot_date)->toDateString() }}</td>
                <td class="px-3 py-2">
                  <span class="inline-block rounded px-2 py-0.5 text-xs font-semibold {{ $lg->source === 'cron' ? 'bg-indigo-100 text-indigo-700' : 'bg-gray-100 text-gray-700' }}">{{ $lg->source }}</span>
                </td>
                <td class="px-3 py-2">
                  <span class="inline-block rounded px-2 py-0.5 text-xs font-semibold {{ $lg->status === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">{{ $lg->status }}</span>
                </td>
                <td class="px-3 py-2 text-right tabular-nums">{{ number_format((int) $lg->items) }}</td>
                <td class="px-3 py-2 text-right tabular-nums">{{ number_format((int) $lg->units) }}</td>
                <td class="px-3 py-2 text-right tabular-nums">{{ (int) $lg->window }}d</td>
                <td class="px-3 py-2 text-right tabular-nums text-gray-500">{{ $lg->duration_ms !== null ? number_format((int) $lg->duration_ms).'ms' : '—' }}</td>
                <td class="px-3 py-2 text-gray-600">{{ $lg->message }}</td>
              </tr>
            @empty
              <tr><td colspan="9" class="px-4 py-6 text-center text-gray-500">Wala pang run log. Tatakbo ito sa susunod na snapshot (cron o manual).</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="mt-2 text-xs text-gray-500">
        Isang row = isang takbo. <strong>cron</strong> = automatic (6 AM PH) · <strong>manual</strong> = "Snapshot now" / CLI.
        Kung <strong>walang bagong <code>cron</code> rows araw-araw</strong> → hindi naka-setup ang <code>schedule:run</code> sa server crontab.
      </div>
    </div>
  </div>
</x-layout>

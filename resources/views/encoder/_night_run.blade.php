{{--
  🌙 Night run (handoff 007, spec §9, §10): huling 14 na gabi + pulang banner.
  Lahat ng text (sheet name, mensahe, reason, code, page, item) ay galing sa labas: {{ }} o x-text lang (walang raw output).
  Inaasahan: $night = ['nights' => [...], 'banner' => [...]], $isCeo, $nightSettings (CEO lang, kung hindi ay null).
--}}
@php
  $nightCeo   = !empty($isCeo);
  $nightDay   = fn ($d) => \Carbon\Carbon::parse($d)->format('D, M j');
  $nightShort = fn ($d) => \Carbon\Carbon::parse($d)->format('M j');
  $nightPlural = fn (int $n, string $one, ?string $many = null) => $n . ' ' . ($n === 1 ? $one : ($many ?? $one . 's'));
  $nightLength = function (?int $s) {
      if ($s === null) return null;
      if ($s < 60) return $s . 's';
      if ($s < 3600) return intdiv($s, 60) . 'm ' . ($s % 60) . 's';
      return intdiv($s, 3600) . 'h ' . intdiv($s % 3600, 60) . 'm';
  };
  // Pinakamasama muna: Failed > Done with failed sheets > Running > Skipped > Done.
  $importRank = ['failed' => 5, 'done_with_failures' => 4, 'running' => 3, 'skipped' => 2, 'done' => 1];
  $importWords = ['failed' => 'Failed', 'done_with_failures' => 'Done with failed sheets', 'running' => 'Running', 'skipped' => 'Skipped', 'done' => 'Done'];
  $importColor = [
      'failed' => 'bg-red-100 text-red-700', 'done_with_failures' => 'bg-yellow-100 text-yellow-800', 'running' => 'bg-blue-100 text-blue-700',
      'skipped' => 'bg-gray-100 text-gray-700', 'done' => 'bg-green-100 text-green-700',
  ];
  $astraColor = [
      'waiting' => 'bg-gray-100 text-gray-700', 'waiting_for_worker' => 'bg-amber-100 text-amber-800', 'running' => 'bg-blue-100 text-blue-700',
      'finished' => 'bg-green-100 text-green-700', 'stopped' => 'bg-red-100 text-red-700', 'did_not_run' => 'bg-red-100 text-red-700',
  ];
  $badge = 'inline-block rounded px-2 py-0.5 text-xs font-semibold';
  $yesterday = now('Asia/Manila')->subDay()->toDateString();
@endphp

<div>
  @if (!empty($night['banner']))
    <div data-night-banner role="alert" class="mb-3 rounded-xl border border-red-300 bg-red-50 p-3 text-sm font-semibold text-red-800 space-y-1">
      @foreach ($night['banner'] as $line)
        <div>{{ $line }}</div>
      @endforeach
    </div>
  @endif

  @if (session('success'))
    <div class="mb-3 rounded-xl border border-green-200 bg-green-50 p-3 text-sm text-green-800">{{ session('success') }}</div>
  @endif
  @if (session('error'))
    <div class="mb-3 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800">{{ session('error') }}</div>
  @endif
  @if ($errors->has('date'))
    <div class="mb-3 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800">{{ $errors->first('date') }}</div>
  @endif

  <h2 class="text-base font-bold text-gray-800 mb-2">🌙 Night run</h2>

  <div class="rounded-xl border bg-white shadow-sm divide-y">
    @forelse ($night['nights'] as $n)
      @php
        $imports = $n['imports'] ?? [];
        $a       = $n['astra'] ?? null;
        $worst   = collect($imports)->sortByDesc(fn ($i) => $importRank[$i['status']] ?? 0)->first();
        $doneCount = collect($imports)->whereIn('status', ['done', 'done_with_failures'])->count();
      @endphp
      <div class="p-3 text-sm"
           x-data="{ open: false, rows: null, loading: false, failed: false,
                     load() {
                       if (this.rows !== null || this.loading) return;
                       this.loading = true; this.failed = false;
                       fetch(this.$root.dataset.rowsUrl, { headers: { 'Accept': 'application/json' } })
                         .then(r => r.ok ? r.json() : Promise.reject())
                         .then(d => { this.rows = d.rows || []; })
                         .catch(() => { this.failed = true; })
                         .finally(() => { this.loading = false; });
                     },
                     href(u) {
                       if (typeof u !== 'string') return null;
                       return ((u.startsWith('/') && !u.startsWith('//')) || u.startsWith(location.origin + '/')) ? u : null;
                     },
                     result(r) {
                       if (r.state === 'done') return r.proceed ? 'PROCEED' : (r.code || 'Done');
                       return ({ failed: 'Failed', skipped: 'Skipped', not_run: 'Not run', queued: 'Queued', running: 'Running' })[r.state] || r.state;
                     } }"
           @if ($nightCeo && $a) data-rows-url="{{ route('night_run.rows', ['step' => $a['step_id']]) }}" @endif>
        {{-- Isang linya kada gabi: wrap sa maliit na screen (date, imports, Astra ay nagpapatong-patong) --}}
        <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
          <div class="w-full sm:w-44">
            <div class="font-semibold text-gray-800">{{ $nightDay($n['night_date']) }}</div>
            <div class="text-xs text-gray-500">orders of {{ $nightShort($n['orders_date']) }}</div>
          </div>
          <div class="w-full sm:w-auto sm:min-w-[13rem]">
            <span class="text-xs text-gray-500 mr-1">Imports</span>
            @if ($worst)
              <span class="{{ $badge }} {{ $importColor[$worst['status']] ?? 'bg-gray-100 text-gray-700' }}">{{ $importWords[$worst['status']] ?? $worst['state'] }}</span>
              <span class="text-xs text-gray-500">{{ $doneCount }} of {{ count($imports) }} done</span>
            @else
              —
            @endif
          </div>
          <div class="w-full sm:flex-1 sm:min-w-[16rem]">
            <span class="text-xs text-gray-500 mr-1">Astra</span>
            @if ($a)
              <span class="{{ $badge }} {{ $astraColor[$a['status']] ?? 'bg-gray-100 text-gray-700' }}">{{ $a['state'] }}</span>
              <span class="text-gray-700">{{ $nightPlural($a['rows_found'], 'row') }} · {{ $a['proceed'] }} PROCEED · {{ $a['for_person'] }} for a person · {{ $a['failed'] }} failed</span>
            @else
              —
            @endif
          </div>
          <button type="button" @click="open = !open" :aria-expanded="open.toString()" aria-expanded="false"
                  aria-label="Show details for {{ $nightDay($n['night_date']) }}"
                  class="ml-auto rounded border px-3 py-1 text-base leading-none hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 min-h-[36px] min-w-[36px]">
            <span :class="open ? 'inline-block rotate-90' : 'inline-block'">›</span>
          </button>
        </div>

        {{-- Detalye: nakatago hangga't hindi binubuksan --}}
        <div x-show="open" x-cloak class="mt-3 space-y-4 border-t pt-3">
          <div>
            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Imports</div>
            @forelse ($imports as $i)
              <div class="py-1">
                <span class="font-semibold">{{ $i['label'] }}</span>
                <span class="text-gray-500">{{ $i['time'] }}</span>
                <span class="{{ $badge }} {{ $importColor[$i['status']] ?? 'bg-gray-100 text-gray-700' }}">{{ $i['state'] }}</span>
                @if (in_array($i['status'], ['done', 'done_with_failures', 'failed', 'running'], true))
                  <span class="text-gray-600">{{ $i['processed'] }} processed / {{ $i['inserted'] }} inserted / {{ $i['updated'] }} updated</span>
                @endif
                @if ($i['message'])
                  <div class="text-xs text-gray-600">{{ $i['message'] }}</div>
                @endif
                @foreach ($i['failed_sheets'] as $sheet)
                  <div class="text-xs text-red-700">Failed sheet: {{ $sheet['name'] }}@if (!empty($sheet['message'])) — {{ $sheet['message'] }}@endif</div>
                @endforeach
              </div>
            @empty
              <div class="text-gray-500">No import record.</div>
            @endforelse
          </div>

          @if ($a)
            <div>
              <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Astra</div>
              <ul class="space-y-0.5 text-gray-700">
                <li>{{ $nightPlural($a['rows_found'], 'row') }} found</li>
                <li>{{ $a['proceed'] }} PROCEED</li>
                <li>
                  {{ $a['for_person'] }} left for a person
                  @if (!empty($a['for_person_by_code']))
                    ({{ collect($a['for_person_by_code'])->map(fn ($c, $code) => $code . ' ' . $c)->implode(' · ') }})
                  @endif
                </li>
                <li>{{ $a['failed'] }} failed</li>
                <li>{{ $a['skipped'] }} skipped {{ $a['no_chat_text'] > 0 ? '(' . $a['no_chat_text'] . ' with no chat text)' : '' }}</li>
                <li>{{ $a['not_run'] }} not run</li>
                @if ($a['over_max'] > 0)<li>{{ $a['over_max'] }} not run: over the safety maximum</li>@endif
                @if ($a['queued'] > 0)<li>{{ $a['queued'] }} still queued</li>@endif
                @if ($a['running'] > 0)<li>{{ $a['running'] }} running now</li>@endif
                @if ($nightLength($a['duration_seconds']) !== null)<li>Took {{ $nightLength($a['duration_seconds']) }}</li>@endif
                @if ($a['reason'])<li>{{ $a['reason'] }}</li>@endif
                <li>Started {{ $a['trigger'] === 'manual' ? 'by hand' : 'by schedule' }}@if ($a['started_at']) at {{ $a['started_at'] }}@endif</li>
                @if ($nightCeo && array_key_exists('cost_usd', $a))
                  <li>≈ ${{ number_format($a['cost_usd'], 2) }} estimated{{ $a['cost_complete'] ? '' : ', some rows have no price' }}</li>
                @endif
              </ul>

              @if ($nightCeo)
                <div class="mt-2 flex flex-wrap items-center gap-2">
                  @if ($a['can_retry'])
                    <form method="POST" action="{{ route('night_run.retry_failed', ['step' => $a['step_id']]) }}"
                          onsubmit="return confirm('Retry the failed and not-run rows of this night?')">
                      @csrf
                      <button type="submit" class="rounded border border-amber-300 bg-amber-50 px-3 py-1.5 text-sm font-semibold text-amber-900 hover:bg-amber-100 min-h-[36px]">Retry failed</button>
                    </form>
                  @endif
                  <button type="button" @click="load()" :disabled="loading"
                          class="rounded border px-3 py-1.5 text-sm font-semibold hover:bg-gray-50 min-h-[36px]">Show rows</button>
                </div>

                <div x-show="loading" x-cloak class="mt-2 text-xs text-gray-500">Loading rows…</div>
                <div x-show="failed" x-cloak class="mt-2 text-xs text-red-700">Could not load the rows. Click Show rows to try again.</div>
                <div x-show="rows !== null && rows.length === 0" x-cloak class="mt-2 text-xs text-gray-500">No rows for this night.</div>
                <ul x-show="rows !== null && rows.length > 0" x-cloak class="mt-2 divide-y rounded border text-xs">
                  <template x-for="r in (rows || [])" :key="r.id">
                    <li class="flex flex-wrap items-center gap-x-4 gap-y-1 p-2">
                      <span class="font-mono" x-text="'#' + r.macro_output_id"></span>
                      <span class="text-gray-600" x-text="r.page"></span>
                      <span class="font-semibold" x-text="result(r)"></span>
                      <span class="text-gray-600" x-text="r.reason"></span>
                      <a x-show="href(r.log_url)" :href="href(r.log_url)" class="text-blue-600 hover:underline">log</a>
                    </li>
                  </template>
                </ul>
              @endif
            </div>
          @endif
        </div>
      </div>
    @empty
      <div class="p-4 text-center text-sm text-gray-500">
        No night run yet.
        @if ($nightCeo)
          Turn it on in <a href="{{ route('encoder.checker1.settings') }}" class="text-blue-600 hover:underline">Checker 1 settings</a>.
        @endif
      </div>
    @endforelse
  </div>

  {{-- Run now: CEO lang. Ang petsa ay ang petsa ng mga order (kahapon o mas maaga). --}}
  @if ($nightCeo)
    <form method="POST" action="{{ route('night_run.run_now') }}" class="mt-3 flex flex-wrap items-end gap-3 rounded-xl border bg-white p-3 shadow-sm"
          onsubmit="return confirm('Run Astra now on the blank orders of ' + this.date.value + '? This is the run for that date; the scheduled run will not start again for it.')">
      @csrf
      <div>
        <label for="night-run-date" class="block text-xs font-semibold text-gray-600 mb-1">Orders of</label>
        <input type="date" id="night-run-date" name="date" required value="{{ $yesterday }}" max="{{ $yesterday }}"
               class="rounded border px-3 py-1.5 text-sm min-h-[36px]">
      </div>
      <button type="submit" class="rounded bg-blue-600 px-4 py-1.5 text-sm font-semibold text-white hover:bg-blue-700 min-h-[36px]">Run now</button>
    </form>
  @endif
</div>

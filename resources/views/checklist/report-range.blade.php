<x-layout>
  <x-slot name="heading">Checklist Report</x-slot>
  <x-slot name="title">Checklist — Over several days</x-slot>

  @php
    $csvParams = array_filter(['view' => 'range', 'from' => $from, 'to' => $to, 'dept' => $dept]);
    $cellClass = ['done' => 'bg-green-500', 'missing' => 'bg-red-400', 'none' => 'bg-gray-100'];
    $cellLabel = ['done' => 'done', 'missing' => 'missing', 'none' => 'not due'];
  @endphp

  <div class="min-h-screen bg-gray-50 mt-16">

    {{-- ===== HEADER: range + department + CSV ===== --}}
    <div class="sticky top-16 z-30 bg-white border-b border-gray-200 shadow-sm">
      <form method="GET" action="{{ route('checklist.report') }}"
            class="max-w-screen-2xl mx-auto px-4 py-2.5 flex flex-wrap items-center gap-2 text-sm">
        <input type="hidden" name="view" value="range">
        <a href="{{ route('checklist.report') }}"
           class="text-xs font-medium text-blue-600 hover:underline whitespace-nowrap">← One day</a>
        <span class="font-bold text-gray-800 mr-2">Over several days</span>

        <label class="text-xs text-gray-500">From</label>
        <input type="date" name="from" value="{{ $from }}" max="{{ now()->toDateString() }}"
               class="text-xs border border-gray-200 rounded-lg px-2 py-1">
        <label class="text-xs text-gray-500">To</label>
        <input type="date" name="to" value="{{ $to }}" max="{{ now()->toDateString() }}"
               class="text-xs border border-gray-200 rounded-lg px-2 py-1">

        @if(count($allDepts) > 0)
          <select name="dept" class="text-xs border border-gray-200 rounded-lg px-2 py-1">
            <option value="">All depts</option>
            @foreach($allDepts as $d)
              <option value="{{ $d }}" @selected(mb_strtolower($d) === mb_strtolower($dept))>{{ $d }}</option>
            @endforeach
          </select>
        @endif

        <button type="submit"
                class="text-xs font-semibold bg-blue-600 text-white rounded-lg px-3 py-1 hover:bg-blue-700">Show</button>
        <span class="text-xs text-gray-400">{{ count($days) }} days · max 62</span>

        <span class="ml-auto flex gap-2">
          <a href="{{ route('checklist.report', $csvParams + ['csv' => 'tasks']) }}"
             class="text-xs border border-gray-200 rounded-lg px-2 py-1 text-gray-600 hover:bg-gray-50">⬇ CSV per task</a>
          <a href="{{ route('checklist.report', $csvParams + ['csv' => 'people']) }}"
             class="text-xs border border-gray-200 rounded-lg px-2 py-1 text-gray-600 hover:bg-gray-50">⬇ CSV per person</a>
        </span>
      </form>
    </div>

    <div class="max-w-screen-2xl mx-auto px-4 py-5 space-y-6">

      {{-- ===== SUMMARY PER DAY ===== --}}
      <section class="bg-white rounded-xl border border-gray-200 shadow-sm">
        <h2 class="px-4 pt-3 pb-2 text-sm font-bold text-gray-700">Per day</h2>
        <div class="overflow-x-auto">
          <table class="min-w-full text-xs">
            <thead class="bg-gray-50 text-gray-500">
              <tr>
                <th class="px-3 py-2 text-left font-semibold">Date</th>
                <th class="px-3 py-2 text-right font-semibold">Due</th>
                <th class="px-3 py-2 text-right font-semibold">Done</th>
                <th class="px-3 py-2 text-right font-semibold">Completion</th>
                <th class="px-3 py-2 text-right font-semibold">Approved</th>
                <th class="px-3 py-2 text-right font-semibold">Not approved</th>
                <th class="px-3 py-2 text-right font-semibold">Unknown</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              @foreach($summary as $day => $s)
                <tr>
                  <td class="px-3 py-1.5 whitespace-nowrap">
                    <a href="{{ route('checklist.report', ['date' => $day]) }}" class="text-blue-600 hover:underline">
                      {{ \Carbon\Carbon::parse($day)->format('D, M j') }}
                    </a>
                  </td>
                  <td class="px-3 py-1.5 text-right">{{ $s['due'] }}</td>
                  <td class="px-3 py-1.5 text-right">{{ $s['done'] }}</td>
                  <td class="px-3 py-1.5 text-right font-semibold {{ $s['pct'] === null ? 'text-gray-300' : ($s['pct'] >= 90 ? 'text-green-600' : ($s['pct'] >= 60 ? 'text-amber-600' : 'text-red-600')) }}">
                    {{ $s['pct'] === null ? '—' : $s['pct'] . '%' }}
                  </td>
                  <td class="px-3 py-1.5 text-right text-green-700">{{ $s['approved'] }}</td>
                  <td class="px-3 py-1.5 text-right text-red-600">{{ $s['notApproved'] }}</td>
                  <td class="px-3 py-1.5 text-right text-gray-500">{{ $s['unknown'] }}</td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      </section>

      {{-- ===== PER TASK (day grid; scrolls inside its own box, task name sticky) ===== --}}
      <section class="bg-white rounded-xl border border-gray-200 shadow-sm">
        <div class="px-4 pt-3 pb-2 flex flex-wrap items-center gap-3">
          <h2 class="text-sm font-bold text-gray-700">Per task</h2>
          <span class="text-xs text-gray-400">weakest first</span>
          <span class="ml-auto flex items-center gap-3 text-xs text-gray-500">
            <span class="flex items-center gap-1"><span class="inline-block w-3 h-3 rounded-sm bg-green-500"></span>done</span>
            <span class="flex items-center gap-1"><span class="inline-block w-3 h-3 rounded-sm bg-red-400"></span>missing</span>
            <span class="flex items-center gap-1"><span class="inline-block w-3 h-3 rounded-sm bg-gray-100 border border-gray-200"></span>not due</span>
          </span>
        </div>
        @if(empty($taskRows))
          <p class="px-4 pb-4 text-sm text-gray-400">Walang task na due sa range na ito.</p>
        @else
          <div class="overflow-x-auto max-w-full">
            <table class="text-xs border-separate border-spacing-0">
              <thead class="text-gray-500">
                <tr>
                  <th class="sticky left-0 z-10 bg-gray-50 px-3 py-2 text-left font-semibold min-w-[12rem] max-w-[16rem] border-b border-gray-200">Task</th>
                  @foreach($days as $day)
                    @php $dObj = \Carbon\Carbon::parse($day); @endphp
                    <th class="bg-gray-50 px-0.5 py-1 text-center font-normal border-b border-gray-200 {{ $dObj->isSunday() ? 'border-l border-l-gray-300' : '' }}"
                        title="{{ $dObj->format('D, M j, Y') }}">
                      <div class="leading-none">{{ $dObj->format('j') }}</div>
                      <div class="leading-none text-[10px] text-gray-400">{{ substr($dObj->format('D'), 0, 1) }}</div>
                    </th>
                  @endforeach
                  <th class="sticky right-16 z-10 bg-gray-50 px-2 py-2 text-right font-semibold w-16 border-b border-l border-gray-200">Done</th>
                  <th class="sticky right-0 z-10 bg-gray-50 px-2 py-2 text-right font-semibold w-16 border-b border-gray-200" title="Not approved % of its submissions">Not appr.</th>
                </tr>
              </thead>
              <tbody>
                @foreach($taskRows as $row)
                  <tr class="hover:bg-gray-50">
                    <td class="sticky left-0 z-10 bg-white px-3 py-1 border-b border-gray-100 min-w-[12rem] max-w-[16rem]">
                      <div class="truncate font-medium text-gray-800" title="{{ $row['task']->title }}">{{ $row['task']->title }}</div>
                      @if($row['task']->department)
                        <div class="truncate text-[10px] text-gray-400">{{ $row['task']->department }}</div>
                      @endif
                    </td>
                    @foreach($row['cells'] as $day => $cell)
                      <td class="px-0.5 py-1 border-b border-gray-100 text-center">
                        <span class="inline-block w-3.5 h-3.5 rounded-sm align-middle {{ $cellClass[$cell] }}"
                              title="{{ $day }}: {{ $cellLabel[$cell] }}"></span>
                      </td>
                    @endforeach
                    <td class="sticky right-16 z-10 bg-white px-2 py-1 text-right font-semibold w-16 border-b border-l border-gray-100 {{ $row['pct'] === null ? 'text-gray-300' : ($row['pct'] >= 90 ? 'text-green-600' : ($row['pct'] >= 60 ? 'text-amber-600' : 'text-red-600')) }}"
                        title="{{ $row['done'] }} of {{ $row['due'] }} due days">
                      {{ $row['pct'] === null ? '—' : $row['pct'] . '%' }}
                    </td>
                    <td class="sticky right-0 z-10 bg-white px-2 py-1 text-right w-16 border-b border-gray-100 {{ ($row['notApprovedPct'] ?? 0) > 0 ? 'text-red-600' : 'text-gray-400' }}"
                        title="{{ $row['notApproved'] }} of {{ $row['subs'] }} submissions">
                      {{ $row['notApprovedPct'] === null ? '—' : $row['notApprovedPct'] . '%' }}
                    </td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
        @endif
      </section>

      {{-- ===== PER PERSON ===== --}}
      <section class="bg-white rounded-xl border border-gray-200 shadow-sm">
        <div class="px-4 pt-3 pb-2 flex items-center gap-3">
          <h2 class="text-sm font-bold text-gray-700">Per person</h2>
          <span class="text-xs text-gray-400">most missed days first · missed = a day with a due task and no submission</span>
        </div>
        @if(empty($people))
          <p class="px-4 pb-4 text-sm text-gray-400">Walang naka-assign na tao sa mga task na ito.</p>
        @else
          <div class="overflow-x-auto">
            <table class="min-w-full text-xs">
              <thead class="bg-gray-50 text-gray-500">
                <tr>
                  <th class="px-3 py-2 text-left font-semibold">Person</th>
                  <th class="px-3 py-2 text-right font-semibold">Submissions</th>
                  <th class="px-3 py-2 text-right font-semibold">Approved</th>
                  <th class="px-3 py-2 text-right font-semibold">Not approved</th>
                  <th class="px-3 py-2 text-right font-semibold">Days with a due task</th>
                  <th class="px-3 py-2 text-right font-semibold">Missed days</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
                @foreach($people as $p)
                  <tr>
                    <td class="px-3 py-1.5 font-medium text-gray-800">{{ $p['user']->name }}</td>
                    <td class="px-3 py-1.5 text-right">{{ $p['subs'] }}</td>
                    <td class="px-3 py-1.5 text-right text-green-700">{{ $p['approved'] }}</td>
                    <td class="px-3 py-1.5 text-right text-red-600">{{ $p['notApproved'] }}</td>
                    <td class="px-3 py-1.5 text-right">{{ $p['dueDays'] }}</td>
                    <td class="px-3 py-1.5 text-right font-semibold {{ $p['missed'] > 0 ? 'text-red-600' : 'text-gray-400' }}">{{ $p['missed'] }}</td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
        @endif
      </section>

    </div>
  </div>
</x-layout>

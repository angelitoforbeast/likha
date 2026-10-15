<x-layout>
  <x-slot name="heading">Checklist History</x-slot>
  <x-slot name="title">Checklist — Task history</x-slot>

  <div class="min-h-screen bg-gray-50 mt-16">

    {{-- ===== HEADER: range + user ===== --}}
    <div class="sticky top-16 z-30 bg-white border-b border-gray-200 shadow-sm">
      <form method="GET" action="{{ route('checklist.history') }}"
            class="max-w-screen-2xl mx-auto px-4 py-2.5 flex flex-wrap items-center gap-2 text-sm">
        <a href="{{ route('checklist.manage') }}"
           class="text-xs font-medium text-blue-600 hover:underline whitespace-nowrap">← Manage</a>
        <span class="font-bold text-gray-800 mr-2">
          History{{ $task ? ':' : ' of all tasks' }}
          @if($task)<span class="font-semibold text-gray-600">{{ $task->title }}</span>@endif
        </span>
        @if($task)
          <input type="hidden" name="task" value="{{ $task->id }}">
          <a href="{{ route('checklist.history') }}" class="text-xs text-gray-500 hover:underline">All tasks</a>
        @endif

        <label class="text-xs text-gray-500">From</label>
        <input type="date" name="from" value="{{ $from }}" max="{{ now()->toDateString() }}"
               class="text-xs border border-gray-200 rounded-lg px-2 py-1">
        <label class="text-xs text-gray-500">To</label>
        <input type="date" name="to" value="{{ $to }}" max="{{ now()->toDateString() }}"
               class="text-xs border border-gray-200 rounded-lg px-2 py-1">
        <select name="user" class="text-xs border border-gray-200 rounded-lg px-2 py-1">
          <option value="">Everyone</option>
          @foreach($users as $u)
            <option value="{{ $u->id }}" @selected($userId === $u->id)>{{ $u->name }}</option>
          @endforeach
        </select>
        <button type="submit"
                class="text-xs font-semibold bg-blue-600 text-white rounded-lg px-3 py-1 hover:bg-blue-700">Show</button>
        <span class="text-xs text-gray-400">{{ $changes->total() }} change(s), newest first</span>
      </form>
    </div>

    <div class="max-w-screen-2xl mx-auto px-4 py-5">
      <section class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <table class="w-full text-xs" style="table-layout:fixed">
          <colgroup>
            <col style="width:128px"><col style="width:144px"><col style="width:144px">
            @unless($task)<col style="width:220px">@endunless
            <col>
          </colgroup>
          <thead class="bg-gray-50 text-gray-500">
            <tr>
              <th class="px-3 py-2 text-left font-semibold">When</th>
              <th class="px-3 py-2 text-left font-semibold">Who</th>
              <th class="px-3 py-2 text-left font-semibold">What</th>
              @unless($task)<th class="px-3 py-2 text-left font-semibold">Task</th>@endunless
              <th class="px-3 py-2 text-left font-semibold">Before → after</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            @forelse($rows as $r)
              <tr class="align-top">
                <td class="px-3 py-2 whitespace-nowrap text-gray-600">{{ $r['when']?->format('M j, Y g:i A') }}</td>
                <td class="px-3 py-2 font-semibold text-gray-700 break-words">{{ $r['who'] }}</td>
                <td class="px-3 py-2">
                  <span class="px-1.5 py-0.5 rounded-full {{ in_array($r['action'], ['Disabled', 'Deleted']) ? 'bg-red-50 text-red-600' : 'bg-gray-100 text-gray-600' }}">{{ $r['action'] }}</span>
                </td>
                @unless($task)
                  <td class="px-3 py-2 text-gray-700 break-words">{{ $r['task'] }}</td>
                @endunless
                <td class="px-3 py-2 space-y-0.5 break-words">
                  @foreach($r['lines'] as $line)
                    <div>
                      <span class="text-gray-500">{{ $line['label'] }}:</span>
                      <span class="text-gray-400 line-through decoration-gray-300">{{ $line['before'] }}</span>
                      <span class="text-gray-400">→</span>
                      <span class="text-gray-800">{{ $line['after'] }}</span>
                    </div>
                  @endforeach
                </td>
              </tr>
            @empty
              <tr>
                <td colspan="5" class="px-4 py-10 text-center text-sm text-gray-400">Walang pagbago sa range na ito.</td>
              </tr>
            @endforelse
          </tbody>
        </table>
      </section>
      <div class="mt-4">{{ $changes->links() }}</div>
    </div>
  </div>
</x-layout>

<x-layout>
  <x-slot name="heading">🧑‍🤝‍🧑 Team Tasks</x-slot>

  @if(session('success'))
    <div class="bg-green-100 text-green-800 px-4 py-2 rounded mb-4">
      {{ session('success') }}
    </div>
  @endif

  <form method="GET" action="{{ route('task.team-tasks') }}" class="mb-4 flex gap-2 items-center">
    <label class="text-sm font-medium">Filter by Due Date Range:</label>
    <input type="date" name="start_date" value="{{ request('start_date', $start) }}" class="border px-2 py-1 text-sm rounded">
    <span class="text-sm">to</span>
    <input type="date" name="end_date" value="{{ request('end_date', $end) }}" class="border px-2 py-1 text-sm rounded">
    <button type="submit" class="bg-blue-500 text-white px-3 py-1 rounded text-sm">Filter</button>
  </form>

  <div class="w-full px-2 overflow-x-auto">
    <table class="table-fixed w-full text-sm border-collapse">
      <thead class="bg-gray-100 sticky top-0 z-10">
        <tr>
          <th class="border px-2 py-2">Created At</th>
          <th class="border px-2 py-2">Task Name</th>
          <th class="border px-2 py-2">Description</th>
          <th class="border px-2 py-2">Priority</th>
          <th class="border px-2 py-2">Due Date & Time</th>
          <th class="border px-2 py-2">Assigned To</th>
          <th class="border px-2 py-2">Status</th>
          <th class="border px-2 py-2">Assignee Remarks</th>
          <th class="border px-2 py-2">Completed At</th>
          <th class="border px-2 py-2">Creator Remarks</th>
          <th class="border px-2 py-2">Assigned By</th>
        </tr>
      </thead>
      <tbody>
        @foreach ($groupedTasks as $group)
          @php
            $first = $group->first();
            $rowspan = $group->count();
          @endphp

          @foreach ($group as $i => $task)
            <tr class="{{ $task->status === 'completed' ? 'bg-green-50' : ($task->status === 'in_progress' ? 'bg-blue-50' : '') }}">
              @if ($i === 0)
                <td class="border px-2 py-2 text-center align-middle" rowspan="{{ $rowspan }}">
                  {{ \Carbon\Carbon::parse($task->created_at)->timezone('Asia/Manila')->format('Y-m-d H:i') }}
                </td>
                <td class="border px-2 py-2 break-words align-middle" rowspan="{{ $rowspan }}">{{ $task->task_name }}</td>
                <td class="border px-2 py-2 break-words align-middle" rowspan="{{ $rowspan }}">{{ $task->description }}</td>
                <td class="border px-2 py-2 text-center align-middle" rowspan="{{ $rowspan }}">{{ $task->priority_score ?? '-' }}</td>
                <td class="border px-2 py-2 text-center align-middle" rowspan="{{ $rowspan }}">
                  {{ $task->due_date }}
                  @if ($task->due_time)
                    <br><span class="text-xs text-gray-500">({{ \Carbon\Carbon::parse($task->due_time)->format('H:i') }})</span>
                  @endif
                </td>
              @endif

              <td class="border px-2 py-2 text-center">
                {{ $task->name ?? '-' }}
                @if ($task->employeeProfile && $task->employeeProfile->role)
                  <br><span class="text-xs text-gray-500">({{ $task->employeeProfile->role }})</span>
                @endif
              </td>

              <td class="border px-2 py-2 capitalize">{{ str_replace('_', ' ', $task->status) }}</td>
              <td class="border px-2 py-2 break-words">{{ $task->assignee_remarks }}</td>
              <td class="border px-2 py-2 text-center">
                {{ $task->completed_at ? \Carbon\Carbon::parse($task->completed_at)->timezone('Asia/Manila')->format('Y-m-d H:i') : '-' }}
              </td>
              <td class="border px-2 py-2 break-words">{{ $task->creator_remarks }}</td>

              @if ($i === 0)
                <td class="border px-2 py-2 text-center align-middle" rowspan="{{ $rowspan }}">
                  @if ($task->creator)
                    {{ $task->creator->name }}
                    @if ($task->creator->employeeProfile && $task->creator->employeeProfile->role)
                      <br><span class="text-xs text-gray-500">({{ $task->creator->employeeProfile->role }})</span>
                    @endif
                  @else
                    -
                  @endif
                </td>
              @endif
            </tr>
          @endforeach
        @endforeach
      </tbody>
    </table>
  </div>
</x-layout>

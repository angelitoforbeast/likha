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
          @php $columnWidth = 'w-[9%]'; @endphp
          <th class="border px-2 py-2 {{ $columnWidth }}">Created At</th>
          <th class="border px-2 py-2 {{ $columnWidth }}">Task Name</th>
          <th class="border px-2 py-2 {{ $columnWidth }}">Description</th>
          <th class="border px-2 py-2 {{ $columnWidth }}">Priority</th>
          <th class="border px-2 py-2 {{ $columnWidth }}">Due Date & Time</th>
          <th class="border px-2 py-2 {{ $columnWidth }}">Status</th>
          <th class="border px-2 py-2 {{ $columnWidth }}">Assignee Remarks</th>
          <th class="border px-2 py-2 {{ $columnWidth }}">Creator Remarks</th>
          <th class="border px-2 py-2 {{ $columnWidth }}">Assigned To</th>
          <th class="border px-2 py-2 {{ $columnWidth }}">Assigned By</th>
<th class="border px-2 py-2 {{ $columnWidth }}">Completed At</th>
        </tr>
      </thead>
      <tbody>
        @forelse ($tasks as $task)
          @php
            $rowClass = match($task->status) {
              'pending' => '',
              'in_progress' => 'bg-blue-50 hover:bg-blue-100',
              'completed' => 'bg-green-50 hover:bg-green-100',
              default => 'bg-white'
            };
          @endphp

          <tr class="{{ $rowClass }} h-14 align-middle text-center">
            <td class="border px-2 py-2">
              {{ \Carbon\Carbon::parse($task->created_at)->timezone('Asia/Manila')->format('Y-m-d H:i') }}
            </td>

            <td class="border px-2 py-2 break-words">{{ $task->task_name }}</td>
            <td class="border px-2 py-2 break-words">{{ $task->description }}</td>
            <td class="border px-2 py-2">{{ $task->priority_score ?? '-' }}</td>

            <td class="border px-2 py-2">
              {{ $task->due_date }}
              @if ($task->due_time)
                <span class="text-gray-500 text-xs">({{ \Carbon\Carbon::parse($task->due_time)->format('H:i') }})</span>
              @endif
            </td>

            <td class="border px-2 py-2 capitalize">{{ str_replace('_', ' ', $task->status) }}</td>

            <td class="border px-2 py-2 break-words">{{ $task->assignee_remarks }}</td>
            <td class="border px-2 py-2 break-words">{{ $task->creator_remarks }}</td>

            <td class="border px-2 py-2">
  {{ $task->name ?? '-' }}
  @if ($task->employeeProfile && $task->employeeProfile->role)
    <br><span class="text-xs text-gray-500">({{ $task->employeeProfile->role }})</span>
  @endif
</td>
<td class="border px-2 py-2">
  @if ($task->creator)
    {{ $task->creator->name }}
    @if ($task->creator->employeeProfile && $task->creator->employeeProfile->role)
      <br><span class="text-xs text-gray-500">({{ $task->creator->employeeProfile->role }})</span>
    @endif
  @else
    -
  @endif
</td>


            <td class="border px-2 py-2">
              {{ $task->completed_at ? \Carbon\Carbon::parse($task->completed_at)->timezone('Asia/Manila')->format('Y-m-d H:i') : '-' }}
            </td>
          </tr>
        @empty
          <tr>
            <td colspan="10" class="text-center py-4 text-gray-500">No tasks found for selected date range.</td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>

  <div class="mt-4">
    {{ $tasks->links() }}
  </div>
</x-layout>

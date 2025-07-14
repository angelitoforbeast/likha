<x-layout>
  <x-slot name="heading">🧑‍💻 My Tasks</x-slot>

  @if(session('success'))
    <div class="bg-green-100 text-green-800 px-4 py-2 rounded mb-4">
      {{ session('success') }}
    </div>
  @endif

  <form method="GET" action="{{ route('task.my-tasks') }}" class="mb-4 flex gap-2 items-center">
    <label class="text-sm font-medium">Filter by Due Date Range:</label>
    <input type="date" name="start_date" value="{{ request('start_date') ?? '' }}" class="border px-2 py-1 text-sm rounded">
    <span class="text-sm">to</span>
    <input type="date" name="end_date" value="{{ request('end_date') ?? '' }}" class="border px-2 py-1 text-sm rounded">
    <button type="submit" class="bg-blue-500 text-white px-3 py-1 rounded text-sm">Filter</button>
  </form>

  <div class="w-full px-2 overflow-x-auto">
    <table class="table-fixed w-full text-sm border-collapse">
      <thead class="bg-gray-100 sticky top-0 z-10">
        <tr>
          <th class="border px-2 py-2 w-[9%]">Created At</th>
          <th class="border px-2 py-2 w-[9%]">Task Name</th>
          <th class="border px-2 py-2 w-[18%]">Description</th> <!-- ×2 width -->
          <th class="border px-2 py-2 w-[4.5%]">Priority</th> <!-- ÷2 width -->
          <th class="border px-2 py-2 w-[9%]">Due Date & Time</th>
          <th class="border px-2 py-2 w-[9%]">Status</th>
          <th class="border px-2 py-2 w-[9%]">Assignee Remarks</th>
          <th class="border px-2 py-2 w-[9%]">Creator Remarks</th>
          <th class="border px-2 py-2 w-[9%]">Created By</th>
          <th class="border px-2 py-2 w-[9%]">Completed At</th>
          <th class="border px-2 py-2 w-[9%]">Action</th>
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
            <form method="POST" action="{{ route('task.updateStatus') }}" onsubmit="return confirm('Are you sure you want to update this task?')">
              @csrf
              <input type="hidden" name="task_id" value="{{ $task->id }}">

              <td class="border px-2 py-2">
                {{ \Carbon\Carbon::parse($task->created_at)->timezone('Asia/Manila')->format('Y-m-d H:i') }}
              </td>

              <td class="border px-2 py-2 break-words">{{ $task->task_name }}</td>
              <td class="border px-2 py-2 break-words w-[18%]">{{ $task->description }}</td>
              <td class="border px-2 py-2 w-[4.5%]">{{ $task->priority_score ?? '-' }}</td>

              <td class="border px-2 py-2">
                {{ $task->due_date }}
                @if ($task->due_time)
                  <span class="text-gray-500 text-xs">({{ \Carbon\Carbon::parse($task->due_time)->format('H:i') }})</span>
                @endif
              </td>

              <td class="border px-2 py-2">
                <select name="status" class="border rounded w-full px-1 py-1 text-sm">
                  <option value="pending" {{ $task->status === 'pending' ? 'selected' : '' }}>Pending</option>
                  <option value="in_progress" {{ $task->status === 'in_progress' ? 'selected' : '' }}>In Progress</option>
                  <option value="completed" {{ $task->status === 'completed' ? 'selected' : '' }}>Completed</option>
                </select>
              </td>

              <td class="border px-2 py-2">
                <textarea name="assignee_remarks"
                  class="border w-full rounded px-1 py-1 text-sm resize-none max-h-16 overflow-y-auto h-10">{{ $task->assignee_remarks }}</textarea>
              </td>

              <td class="border px-2 py-2 break-words">{{ $task->creator_remarks }}</td>

              <td class="border px-2 py-2">
                @if ($task->creator)
                  {{ $task->creator->name }}
                  @if ($task->creator->employeeProfile && $task->creator->employeeProfile->role)
                    ({{ $task->creator->employeeProfile->role }})
                  @endif
                @else
                  -
                @endif
              </td>

              <td class="border px-2 py-2">
                {{ $task->completed_at ? \Carbon\Carbon::parse($task->completed_at)->timezone('Asia/Manila')->format('Y-m-d H:i') : '-' }}
              </td>

              <td class="border px-2 py-2">
                <button type="submit" class="bg-blue-500 text-white px-3 py-1 rounded text-xs hover:bg-blue-600">
                  Update
                </button>
              </td>
            </form>
          </tr>
        @empty
          <tr>
            <td colspan="11" class="text-center py-4 text-gray-500">No tasks found for selected date.</td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>

  <div class="mt-4">
    {{ $tasks->links() }}
  </div>
</x-layout>

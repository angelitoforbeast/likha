<x-layout>
  <x-slot name="heading">🧑‍💻 My Tasks</x-slot>

  @if(session('success'))
    <div class="bg-green-100 text-green-800 px-4 py-2 rounded mb-4">
      {{ session('success') }}
    </div>
  @endif

  <div class="w-full px-2 overflow-x-auto">
    <table class="table-fixed w-full text-sm border-collapse">
      <thead class="bg-gray-100 sticky top-0 z-10">
        <tr>
          @php $columnWidth = 'w-[9%]'; @endphp
          <th class="border px-2 py-2 {{ $columnWidth }}">Created At</th>
          <th class="border px-2 py-2 {{ $columnWidth }}">Task Name</th>
          <th class="border px-2 py-2 {{ $columnWidth }}">Description</th>
          <th class="border px-2 py-2 {{ $columnWidth }}">Priority Score</th>
          <th class="border px-2 py-2 {{ $columnWidth }}">Due Date & Time</th>
          <th class="border px-2 py-2 {{ $columnWidth }}">Status</th>
          <th class="border px-2 py-2 {{ $columnWidth }}">Assignee Remarks</th>
          <th class="border px-2 py-2 {{ $columnWidth }}">Creator Remarks</th>
          <th class="border px-2 py-2 {{ $columnWidth }}">Created By</th>
          <th class="border px-2 py-2 {{ $columnWidth }}">Completed At</th>
          <th class="border px-2 py-2 {{ $columnWidth }}">Action</th>
        </tr>
      </thead>
      <tbody>
        @foreach ($tasks as $task)
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
              <td class="border px-2 py-2 break-words">{{ $task->description }}</td>
              <td class="border px-2 py-2">{{ $task->priority_score ?? '-' }}</td>

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
        @endforeach
      </tbody>
    </table>
  </div>
</x-layout>

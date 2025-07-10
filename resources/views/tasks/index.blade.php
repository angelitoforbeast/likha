<x-layout>
  <x-slot name="heading">📋 All Tasks</x-slot>

  @if(session('success'))
    <div class="bg-green-100 text-green-800 px-4 py-2 rounded mb-4">
      {{ session('success') }}
    </div>
  @endif

  <div class="mb-4">
    <a href="{{ route('task.create') }}"
       class="inline-block bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold px-4 py-2 rounded">
      ➕ Create Task
    </a>
  </div>

  <table class="table-auto w-full border text-xs mt-4">
    <thead class="bg-gray-100">
      <tr>
        <th class="border px-2 py-1">Created At</th>
        <th class="border px-2 py-1">ID</th>
        <th class="border px-2 py-1">User ID</th>
        <th class="border px-2 py-1">Name</th>
        <th class="border px-2 py-1">Role</th>
        <th class="border px-2 py-1">Task Name</th>
        <th class="border px-2 py-1">Description</th>
        <th class="border px-2 py-1">Type</th>
        <th class="border px-2 py-1">Repeats?</th>
        <th class="border px-2 py-1">Priority</th>
        <th class="border px-2 py-1">Due Date</th>
        <th class="border px-2 py-1">Status</th>
        <th class="border px-2 py-1">Notified?</th>
        <th class="border px-2 py-1">Completed At</th>
        <th class="border px-2 py-1">Assignee Remarks</th>
        <th class="border px-2 py-1">Remarks Created By</th>
        <th class="border px-2 py-1">Created By</th>
        <th class="border px-2 py-1">Creator Remarks</th>
        <th class="border px-2 py-1">Remarks</th>
        <th class="border px-2 py-1">Updated At</th>
      </tr>
    </thead>
    <tbody>
      @foreach ($tasks as $task)
        <tr>
          <td class="border px-2 py-1">{{ $task->created_at }}</td>
          <td class="border px-2 py-1 text-center">{{ $task->id }}</td>
          <td class="border px-2 py-1 text-center">{{ $task->user_id }}</td>
          <td class="border px-2 py-1">{{ $task->name }}</td>
          <td class="border px-2 py-1">{{ $task->role_target }}</td>
          <td class="border px-2 py-1">{{ $task->task_name }}</td>
          <td class="border px-2 py-1">{{ $task->description }}</td>
          <td class="border px-2 py-1">{{ $task->type }}</td>
          <td class="border px-2 py-1">{{ $task->is_repeating ? 'Yes' : 'No' }}</td>
          <td class="border px-2 py-1">P{{ $task->priority_score }}</td>
          <td class="border px-2 py-1">{{ $task->due_date }}</td>
          <td class="border px-2 py-1">{{ $task->status }}</td>
          <td class="border px-2 py-1">{{ $task->is_notified ? 'Yes' : 'No' }}</td>
          <td class="border px-2 py-1">{{ $task->completed_at ?? '-' }}</td>
          <td class="border px-2 py-1">{{ $task->assignee_remarks }}</td>
          <td class="border px-2 py-1">{{ $task->remarks_created_by }}</td>
          <td class="border px-2 py-1">
    @if ($task->creator)
        {{ $task->creator->name }}
        @if ($task->creator->employeeProfile && $task->creator->employeeProfile->role)
            ({{ $task->creator->employeeProfile->role }})
        @endif
    @else
        Unknown
    @endif
</td>

          <td class="border px-2 py-1">{{ $task->creator_remarks }}</td>
          <td class="border px-2 py-1">{{ $task->remarks }}</td>
          <td class="border px-2 py-1">{{ $task->updated_at }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>
</x-layout>

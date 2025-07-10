<x-layout>
  <x-slot name="heading">🧑‍💻 My Tasks</x-slot>

  @if(session('success'))
    <div class="bg-green-100 text-green-800 px-4 py-2 rounded mb-4">
      {{ session('success') }}
    </div>
  @endif

  <table class="table-auto w-full border text-sm">
    <thead class="bg-gray-100">
      <tr>
        <th class="border px-2 py-1">Created At</th>
        <th class="border px-2 py-1">Task Name</th>
        
        <th class="border px-2 py-1">Priority Level</th>
        <th class="border px-2 py-1">Due Date</th>
        <th class="border px-2 py-1">Status</th>
        <th class="border px-2 py-1">Assignee Remarks</th>
        <th class="border px-2 py-1">Creator Remarks</th>
        <th class="border px-2 py-1">Action</th>
      </tr>
    </thead>
    <tbody>
      @foreach ($tasks as $task)
        <tr>
          <form method="POST" action="{{ route('task.updateStatus') }}">
            @csrf
            <input type="hidden" name="task_id" value="{{ $task->id }}">

            <td class="border px-2 py-1">{{ $task->created_at }}</td>
            <td class="border px-2 py-1">{{ $task->task_name }}</td>
            
            <td class="border px-2 py-1">{{ $task->priority_level ?? '-' }}</td>
            <td class="border px-2 py-1">{{ $task->due_date }}</td>

            <td class="border px-2 py-1">
              <select name="status" class="border px-1 py-1 rounded w-full">
                <option value="pending" {{ $task->status === 'pending' ? 'selected' : '' }}>Pending</option>
                <option value="in_progress" {{ $task->status === 'in_progress' ? 'selected' : '' }}>In Progress</option>
                <option value="completed" {{ $task->status === 'completed' ? 'selected' : '' }}>Completed</option>
              </select>
            </td>

            <td class="border px-2 py-1">
              <textarea name="assignee_remarks" class="border w-full rounded px-1 py-1" rows="1">{{ $task->assignee_remarks }}</textarea>
            </td>

            <td class="border px-2 py-1">{{ $task->creator_remarks }}</td>

            <td class="border px-2 py-1 text-center">
              <button type="submit" class="bg-blue-500 text-white px-2 py-1 rounded text-xs hover:bg-blue-600">
                Update
              </button>
            </td>
          </form>
        </tr>
      @endforeach
    </tbody>
  </table>
</x-layout>

<x-layout>
  <x-slot name="heading">🧑‍🤝‍🧑 Team Tasks</x-slot>

  @if(session('success'))
    <div class="bg-green-100 text-green-800 px-4 py-2 rounded mb-4">
      {{ session('success') }}
    </div>
  @endif

  {{-- Filter and Create Button --}}
  <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
    <form method="GET" action="{{ route('task.team-tasks') }}" class="flex items-center gap-2 flex-wrap">
      <label class="text-sm font-medium">Filter by Due Date Range:</label>
      <input type="date" name="start_date" value="{{ request('start_date', $start) }}" class="border px-2 py-1 text-sm rounded">
      <span class="text-sm">to</span>
      <input type="date" name="end_date" value="{{ request('end_date', $end) }}" class="border px-2 py-1 text-sm rounded">
      <button type="submit" class="bg-blue-500 text-white px-3 py-1 rounded text-sm">Filter</button>
    </form>

    <a href="{{ route('task.create') }}"
       class="inline-block bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold px-4 py-2 rounded">
      ➕ Create Task
    </a>
  </div>

  {{-- Scrollable Table Only --}}
  <div class="overflow-auto max-h-[70vh] w-full border rounded">
    <table class="table-fixed w-full text-sm border-collapse">
      <thead class="bg-gray-100 sticky top-0 z-10">
        <tr>
          <th class="border px-2 py-2" style="width: 9%;">Created At</th>
          <th class="border px-2 py-2" style="width: 9%;">Task Name</th>
          <th class="border px-2 py-2" style="width: 18%;">Description</th>
          <th class="border px-2 py-2" style="width: 4.5%;">Priority</th>
          <th class="border px-2 py-2" style="width: 9%;">Due Date & Time</th>
          <th class="border px-2 py-2" style="width: 9%;">Assigned To</th>
          <th class="border px-2 py-2" style="width: 9%;">Status</th>
          <th class="border px-2 py-2" style="width: 9%;">Assignee Remarks</th>
          <th class="border px-2 py-2" style="width: 9%;">Completed At</th>
          <th class="border px-2 py-2" style="width: 9%;">Creator Remarks</th>
          <th class="border px-2 py-2" style="width: 9%;">Assigned By</th>
          <th class="border px-2 py-2" style="width: 9%;">Action</th>
        </tr>
      </thead>
      <tbody>
        @foreach ($groupedTasks as $groupIndex => $group)
          @php
            $first = $group->first();
            $rowspan = $group->count();
          @endphp

          <form method="POST" action="{{ route('task.updateTeamTask') }}" id="update-form-{{ $groupIndex }}">
            @csrf
            <input type="hidden" name="task_id" value="{{ $first->id }}">

            @foreach ($group as $i => $task)
              @php
                $statusColor = match($task->status) {
                  'pending' => 'bg-yellow-100',
                  'in_progress' => 'bg-blue-100',
                  'completed' => 'bg-green-100',
                  default => ''
                };
              @endphp
              <tr class="border-t border-gray-300 {{ $statusColor }}">
                @if ($i === 0)
                  <td rowspan="{{ $rowspan }}" class="border px-2 py-2 align-top">
                    {{ \Carbon\Carbon::parse($task->created_at)->format('Y-m-d H:i') }}
                  </td>
                  <td rowspan="{{ $rowspan }}" class="border px-2 py-2 align-top">
                    <input type="text" name="task_name" value="{{ $task->task_name }}" class="w-full border rounded px-1 py-1 text-xs">
                  </td>
                  <td rowspan="{{ $rowspan }}" class="border px-2 py-2 align-top">
                    <textarea name="description"
                      class="w-full border rounded px-1 py-1 text-xs resize-none overflow-hidden auto-expand"
                      oninput="autoResize(this)">{{ $task->description }}</textarea>
                  </td>
                  <td rowspan="{{ $rowspan }}" class="border px-2 py-2 text-center align-top">
                    {{ $task->priority_score ?? '-' }}
                  </td>
                  <td rowspan="{{ $rowspan }}" class="border px-2 py-2 text-center align-top">
                    {{ $task->due_date }}
                    @if ($task->due_time)
                      <br><span class="text-xs text-gray-500">({{ \Carbon\Carbon::parse($task->due_time)->format('H:i') }})</span>
                    @endif
                  </td>
                @endif

                <td class="border px-2 py-2">{{ $task->name ?? '-' }}</td>
                <td class="border px-2 py-2">
                  <select name="statuses[{{ $task->id }}]" class="w-full border rounded text-xs px-1 py-1">
                    @foreach(['pending', 'in_progress', 'completed'] as $status)
                      <option value="{{ $status }}" {{ $task->status === $status ? 'selected' : '' }}>
                        {{ ucwords(str_replace('_', ' ', $status)) }}
                      </option>
                    @endforeach
                  </select>
                </td>
                <td class="border px-2 py-2">{{ $task->assignee_remarks }}</td>
                <td class="border px-2 py-2">
                  {{ $task->completed_at ? \Carbon\Carbon::parse($task->completed_at)->format('Y-m-d H:i') : '' }}
                </td>
                <td class="border px-2 py-2">
                  <textarea name="creator_remarks[{{ $task->id }}]" class="w-full border rounded px-1 py-1 text-xs auto-expand"
                    oninput="autoResize(this)">{{ $task->creator_remarks }}</textarea>
                </td>

                @if ($i === 0)
                  <td rowspan="{{ $rowspan }}" class="border px-2 py-2 align-top">
                    {{ $task->creator->name ?? '-' }}
                    @if ($task->creator && $task->creator->employeeProfile && $task->creator->employeeProfile->role)
                      <br><span class="text-xs text-gray-500">({{ $task->creator->employeeProfile->role }})</span>
                    @endif
                  </td>
                  <td rowspan="{{ $rowspan }}" class="border px-2 py-2 text-center align-middle">
                    <div class="flex items-center justify-center h-full">
                      <button type="submit"
                        class="bg-lime-600 hover:bg-lime-700 text-white font-bold text-xs px-4 py-1 rounded shadow w-full max-w-[100px]">
                        ✅ UPDATE
                      </button>
                    </div>
                  </td>
                @endif
              </tr>
            @endforeach
          </form>
        @endforeach
      </tbody>
    </table>
  </div>

  <script>
    function autoResize(textarea) {
      textarea.style.height = 'auto';
      textarea.style.height = textarea.scrollHeight + 'px';
    }

    window.addEventListener('DOMContentLoaded', () => {
      document.querySelectorAll('.auto-expand').forEach(textarea => autoResize(textarea));
    });
  </script>
</x-layout>

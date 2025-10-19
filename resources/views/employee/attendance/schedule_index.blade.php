<x-layout>
  <x-slot name="heading">Employee Schedule Settings</x-slot>

  <div class="max-w-6xl mx-auto mt-6">

    @if(session('status'))
      <div class="bg-green-100 text-green-800 p-3 rounded mb-4">
        {{ session('status') }}
      </div>
    @endif

    <div class="flex justify-between mb-4">
      <form method="GET" class="flex gap-2">
        <input type="text" name="search" value="{{ $search }}" placeholder="Search employee..."
               class="border rounded px-3 py-1 w-64">
        <button class="bg-blue-600 text-white px-4 py-1 rounded">Search</button>
      </form>

      <a href="{{ route('attendance.schedule.create') }}" class="bg-green-600 text-white px-4 py-1 rounded">
        + Add Schedule
      </a>
    </div>

    <div class="bg-white rounded shadow">
      <table class="min-w-full border border-gray-300 text-sm">
        <thead class="bg-gray-100">
          <tr>
            <th class="border px-3 py-2 text-left">Employee</th>
            <th class="border px-3 py-2 text-left">Shift</th>
            <th class="border px-3 py-2 text-center">Time In</th>
            <th class="border px-3 py-2 text-center">Time Out</th>
            <th class="border px-3 py-2 text-center">Lunch</th>
            <th class="border px-3 py-2 text-center">Effective Dates</th>
            <th class="border px-3 py-2 text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          @forelse ($schedules as $s)
            <tr>
              <td class="border px-3 py-2">{{ $s->employee->name ?? '—' }}</td>
              <td class="border px-3 py-2">{{ $s->shift_name ?? '-' }}</td>
              <td class="border px-3 py-2 text-center">{{ $s->time_in }}</td>
              <td class="border px-3 py-2 text-center">{{ $s->time_out }}</td>
              <td class="border px-3 py-2 text-center">
                {{ $s->lunch_start }} - {{ $s->lunch_end }}
              </td>
              <td class="border px-3 py-2 text-center">
                {{ $s->effective_from }} → {{ $s->effective_to ?? 'Present' }}
              </td>
              <td class="border px-3 py-2 text-center space-x-2">
                <a href="{{ route('attendance.schedule.edit', $s->id) }}" class="bg-yellow-500 text-white px-3 py-1 rounded">Edit</a>
                <form method="POST" action="{{ route('attendance.schedule.destroy', $s->id) }}" class="inline">
                  @csrf @method('DELETE')
                  <button onclick="return confirm('Delete this schedule?')" class="bg-red-600 text-white px-3 py-1 rounded">Delete</button>
                </form>
              </td>
            </tr>
          @empty
            <tr><td colspan="7" class="border px-3 py-2 text-center text-gray-500">No schedules found.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>

    <div class="mt-3">
      {{ $schedules->links() }}
    </div>
  </div>
</x-layout>

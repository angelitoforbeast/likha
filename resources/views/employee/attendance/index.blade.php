<x-layout>
  <x-slot name="heading">Attendance (Raw Preview)</x-slot>

  <div class="max-w-7xl mx-auto mt-6 space-y-4">
    @if(session('status'))
      <div class="bg-green-100 text-green-800 p-3 rounded">{{ session('status') }}</div>
    @endif

    {{-- FILTER BAR --}}
    <div class="bg-white p-4 rounded shadow">
      <form method="GET" action="{{ route('attendance.index') }}" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
        {{-- User filter --}}
        <div>
          <label class="text-sm font-medium block">User (Biometric ID)</label>
          <select name="user_id" class="mt-1 w-full border rounded px-2 py-2">
            <option value="">All Users</option>
            @foreach($users as $u)
              @php $label = $u->name_clean ?: $u->name_raw ?: $u->zk_user_id; @endphp
              <option value="{{ $u->zk_user_id }}" {{ (string)$userId === (string)$u->zk_user_id ? 'selected' : '' }}>
                {{ $u->zk_user_id }} — {{ $label }}
              </option>
            @endforeach
          </select>
        </div>

        {{-- Date range (Flatpickr-style) --}}
        <div class="md:col-span-2">
          <label class="text-sm font-medium block">Date Range</label>
          <input
            type="text"
            id="dateRange"
            name="date_range"
            value="{{ $dateRange }}"
            placeholder="Select date range"
            class="mt-1 w-full border rounded px-3 py-2"
            autocomplete="off">
          <p class="text-xs text-gray-500 mt-1">Format: YYYY-MM-DD to YYYY-MM-DD</p>
        </div>

        <div class="flex gap-2">
          <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Filter</button>
          <a href="{{ route('attendance.index') }}" class="px-4 py-2 rounded border">Reset</a>
          <a class="ml-auto bg-emerald-600 text-white px-4 py-2 rounded hover:bg-emerald-700"
             href="{{ route('attendance.upload.form') }}">
            Upload user.dat / attlog.dat
          </a>
        </div>
      </form>
    </div>

    {{-- TABLE --}}
    <div class="bg-white rounded shadow overflow-x-auto">
      <table class="min-w-full text-sm border border-gray-200">
        <thead class="bg-gray-100">
          <tr>
            <th class="border px-3 py-2 text-left">#</th>
            <th class="border px-3 py-2 text-left">Biometric ID</th>
            <th class="border px-3 py-2 text-left">Name</th>
            <th class="border px-3 py-2 text-left">Date</th>
            <th class="border px-3 py-2 text-left">Time</th>
            <th class="border px-3 py-2 text-left">DateTime (raw)</th>
            <th class="border px-3 py-2 text-left">Batch</th>
          </tr>
        </thead>
        <tbody>
          @forelse($logs as $i => $log)
            @php
              $user = $userMap[$log->zk_user_id] ?? null;
              $name = $user->name_clean ?? $user->name_raw ?? '';
            @endphp
            <tr class="odd:bg-white even:bg-gray-50">
              <td class="border px-3 py-2">{{ $logs->firstItem() + $i }}</td>
              <td class="border px-3 py-2 font-mono">{{ $log->zk_user_id }}</td>
              <td class="border px-3 py-2">{{ $name }}</td>
              <td class="border px-3 py-2">{{ \Illuminate\Support\Str::of($log->date)->substr(0,10) }}</td>
              <td class="border px-3 py-2">{{ $log->time }}</td>
              <td class="border px-3 py-2">{{ $log->datetime_raw }}</td>
              <td class="border px-3 py-2">{{ $log->upload_batch }}</td>
            </tr>
          @empty
            <tr>
              <td colspan="7" class="border px-3 py-6 text-center text-gray-600">No logs found.</td>
            </tr>
          @endforelse
        </tbody>
      </table>

      <div class="p-3">
        {{ $logs->links() }}
      </div>
    </div>
  </div>

  {{-- Flatpickr assets (CDN) --}}
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
  <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      const input = document.getElementById('dateRange');
      if (input) {
        flatpickr(input, {
          mode: 'range',
          dateFormat: 'Y-m-d',
          allowInput: true,

          // Keep the "YYYY-MM-DD to YYYY-MM-DD" format for the controller parser
          onClose: function(selectedDates, dateStr, instance) {
            if (selectedDates.length === 2) {
              const [start, end] = selectedDates;
              const pad = n => String(n).padStart(2, '0');
              const fmt = d => `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`;
              input.value = fmt(start) + ' to ' + fmt(end);
            }
          }
        });
      }
    });
  </script>
</x-layout>

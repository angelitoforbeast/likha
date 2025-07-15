<x-layout>
    <x-slot name="heading">📝 My Everyday Tasks</x-slot>

    @if(session('success'))
        <div class="bg-green-100 text-green-800 px-4 py-2 rounded mb-4">{{ session('success') }}</div>
    @endif

    {{-- Go to Create Page --}}
    <div class="mb-4 text-right">
        <a href="{{ route('everyday-task.create-form') }}"
           class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 transition">
            ➕ Create New Task
        </a>
    </div>

    {{-- Existing Tasks --}}
    <table class="table-auto w-full border text-sm mt-6">
        <thead class="bg-gray-100">
            <tr>
                <th class="border px-2 py-1">Task</th>
                <th class="border px-2 py-1">Priority</th>
                <th class="border px-2 py-1">Due Time</th>
                <th class="border px-2 py-1">Description</th>
                <th class="border px-2 py-1">Actions</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($tasks as $task)
                <tr>
                    <form method="POST" action="{{ route('everyday-tasks.update', $task->id) }}">
                        @csrf
                        @method('PUT')

                        <td class="border px-2 py-1">
                            <input name="task_name" value="{{ old('task_name', $task->task_name) }}" class="w-full" />
                        </td>

                        <td class="border px-2 py-1">
                            <select name="priority_score" class="w-full">
                                @for ($i = 1; $i <= 5; $i++)
                                    <option value="{{ $i }}" {{ (old('priority_score', $task->priority_score) == $i) ? 'selected' : '' }}>
                                        P{{ $i }}
                                    </option>
                                @endfor
                            </select>
                        </td>

                        <td class="border px-2 py-1">
                            <input name="due_time" type="time" value="{{ old('due_time', optional($task->due_time)->format('H:i')) }}" class="w-full" />
                        </td>

                        <td class="border px-2 py-1">
                            <textarea name="description" class="w-full" rows="2">{{ old('description', $task->description) }}</textarea>
                        </td>

                        <td class="border px-2 py-1 flex gap-2">
                            <button type="submit" class="bg-yellow-400 px-2 py-1 text-xs rounded hover:bg-yellow-500 transition">Save</button>
                    </form>

                    <form method="POST" action="{{ route('everyday-tasks.destroy', $task->id) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="bg-red-500 text-white px-2 py-1 text-xs rounded hover:bg-red-600 transition">Delete</button>
                    </form>
                        </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</x-layout>

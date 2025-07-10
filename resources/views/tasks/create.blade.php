<x-layout>
    <x-slot name="heading">📝 Create New Task</x-slot>

    <form method="POST" action="{{ route('task.create') }}" class="max-w-xl mx-auto space-y-4">
        @csrf

        <div>
            <label class="font-semibold">Task Name</label>
            <input type="text" name="name" required class="w-full border p-2 rounded" />
        </div>

        <div>
            <label class="font-semibold">Task Type</label>
            <select name="type" required class="w-full border p-2 rounded">
                <option value="everyday">Everyday</option>
                <option value="manual">Manual</option>
                <option value="scheduled">Scheduled</option>
            </select>
        </div>

        <div>
    <label class="font-semibold">Priority Score</label>
    <select name="priority_score" class="w-full border p-2 rounded" required>
        <option value="1" selected>P1</option>
        <option value="2">P2</option>
        <option value="3">P3</option>
        <option value="4">P4</option>
        <option value="5">P5</option>
    </select>
</div>


        <div>
            <label for="role_target" class="block font-semibold mb-1">Target Roles:</label>
            <div x-data="{ open: false, selected: [] }" class="relative">
                <button type="button" @click="open = !open"
                    class="w-full border px-4 py-2 text-left rounded bg-white shadow">
                    <template x-if="selected.length === 0">
                        <span class="text-gray-400">Select roles...</span>
                    </template>
                    <template x-if="selected.length > 0">
                        <span x-text="selected.join(', ')"></span>
                    </template>
                </button>

                <div x-show="open" @click.outside="open = false"
                    class="absolute mt-1 w-full bg-white border shadow rounded z-10 max-h-60 overflow-y-auto">
                    @foreach ($roles as $role)
                        <label class="block px-4 py-2 hover:bg-gray-100">
                            <input type="checkbox" value="{{ $role }}" x-model="selected" name="role_target[]"
                                class="mr-2">
                            {{ $role }}
                        </label>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="font-semibold">Due Date</label>
                <input type="date" name="due_date" value="{{ date('Y-m-d') }}" class="w-full border p-2 rounded" />
            </div>

            <div>
                <label class="font-semibold">Due Time</label>
                <input type="time" name="due_time"  class="w-full border p-2 rounded" />
            </div>
        </div>

        <div>
            <label class="font-semibold">Reminder At (optional)</label>
            <input type="datetime-local" name="reminder_at" class="w-full border p-2 rounded" />
        </div>

        <div>
            <label class="font-semibold">Collaborators (optional)</label>
            <input type="text" name="collaborators" placeholder="Comma-separated user IDs or names" class="w-full border p-2 rounded" />
        </div>

        <div>
            <label class="font-semibold">Description</label>
            <textarea name="description" class="w-full border p-2 rounded" rows="3"></textarea>
        </div>

        <div>
            <label class="font-semibold">Remarks</label>
            <textarea name="remarks" class="w-full border p-2 rounded" rows="2"></textarea>
        </div>

        <div class="flex items-center space-x-2">
            <input type="checkbox" name="is_repeating" id="repeat" />
            <label for="repeat">This task repeats every day</label>
        </div>

        <div class="text-center">
            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Save Task</button>
        </div>
    </form>
</x-layout>

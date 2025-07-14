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

        {{-- Target Roles and Users --}}
        <div x-data="{
    open: false,
    roles: {{ Js::from($usersByRole) }},
    selectedUsers: [],
    toggleUser(id) {
        if (this.selectedUsers.includes(id)) {
            this.selectedUsers = this.selectedUsers.filter(uid => uid !== id);
        } else {
            this.selectedUsers.push(id);
        }
    },
    toggleAll(role) {
        const ids = this.roles[role].map(u => u.id);
        const allSelected = ids.every(id => this.selectedUsers.includes(id));
        if (allSelected) {
            this.selectedUsers = this.selectedUsers.filter(id => !ids.includes(id));
        } else {
            this.selectedUsers = [...new Set([...this.selectedUsers, ...ids])];
        }
    },
    isChecked(id) {
        return this.selectedUsers.includes(id);
    },
    getSelectedNames() {
        let names = [];
        for (const role in this.roles) {
            for (const user of this.roles[role]) {
                if (this.selectedUsers.includes(user.id)) {
                    names.push(user.name);
                }
            }
        }
        return names.join(', ');
    }
}" class="relative">
    <label class="font-semibold block mb-1">Target Users</label>

    <!-- Trigger -->
    <button type="button" @click="open = !open"
        class="w-full border px-4 py-2 text-left rounded bg-white shadow text-sm">
        <template x-if="selectedUsers.length === 0">
            <span class="text-gray-400">Select roles & users...</span>
        </template>
        <template x-if="selectedUsers.length > 0">
            <span x-text="getSelectedNames()"></span>
        </template>
    </button>

    <!-- Dropdown -->
    <div x-show="open" @click.outside="open = false"
        class="absolute z-10 mt-1 w-full bg-white border rounded shadow max-h-64 overflow-y-auto text-sm">

        <template x-for="(users, role) in roles" :key="role">
            <div class="border-b px-4 py-2">
                <label class="font-semibold flex items-center">
                    <input type="checkbox"
                           @change="toggleAll(role)"
                           :checked="roles[role].every(u => selectedUsers.includes(u.id))"
                           class="mr-2">
                    <span x-text="role"></span>
                </label>

                <div class="ml-5 mt-1 space-y-1">
                    <template x-for="user in users" :key="user.id">
                        <label class="flex items-center">
                            <input type="checkbox"
                                   :value="user.id"
                                   name="target_users[]"
                                   @change="toggleUser(user.id)"
                                   :checked="isChecked(user.id)"
                                   class="mr-2">
                            <span x-text="user.name"></span>
                        </label>
                    </template>
                </div>
            </div>
        </template>
    </div>
</div>




        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="font-semibold">Due Date</label>
                <input type="date" name="due_date" value="{{ date('Y-m-d') }}" class="w-full border p-2 rounded" />
            </div>

            <div>
                <label class="font-semibold">Due Time</label>
                <input type="time" name="due_time" class="w-full border p-2 rounded" />
            </div>
        </div>

        <div>
            <label class="font-semibold">Reminder At (optional)</label>
            <input type="datetime-local" name="reminder_at" class="w-full border p-2 rounded" />
        </div>

        <div>
            <label class="font-semibold">Collaborators (optional)</label>
            <input type="text" name="collaborators" placeholder="Comma-separated user IDs or names"
                class="w-full border p-2 rounded" />
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

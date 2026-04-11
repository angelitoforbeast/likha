<x-layout>
  <x-slot name="heading">Manage Checklist Tasks</x-slot>
  <x-slot name="title">Manage Checklist Tasks</x-slot>

  <div class="p-4 max-w-3xl mx-auto space-y-4">

    {{-- Header --}}
    <div class="flex items-center justify-between flex-wrap gap-2">
      <div>
        <h1 class="text-xl font-bold text-gray-800">Manage Tasks</h1>
        <p class="text-sm text-gray-500">Add, edit, reorder, or delete checklist tasks.</p>
      </div>
      <div class="flex items-center gap-2">
        <a href="{{ route('checklist.report') }}"
           class="flex items-center gap-1.5 text-sm px-3 py-1.5 rounded-lg border border-gray-300 hover:bg-gray-50 text-gray-700">
          📋 View Report
        </a>
        <a href="{{ route('checklist.index') }}"
           class="flex items-center gap-1.5 text-sm px-3 py-1.5 rounded-lg border border-gray-300 hover:bg-gray-50 text-gray-700">
          ← Checklist
        </a>
      </div>
    </div>

    {{-- Alerts --}}
    @if(session('success'))
      <div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg text-sm">✓ {{ session('success') }}</div>
    @endif
    @if(session('error'))
      <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg text-sm">{{ session('error') }}</div>
    @endif
    @if($errors->any())
      <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg text-sm">
        @foreach($errors->all() as $e)<div>• {{ $e }}</div>@endforeach
      </div>
    @endif

    {{-- ====== ADD TASK FORM ====== --}}
    <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-4 space-y-3">
      <h2 class="font-semibold text-gray-800 text-sm">Add New Task</h2>

      <form method="POST" action="{{ route('checklist.store-task') }}" class="space-y-3">
        @csrf
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
          <input type="text" name="title" placeholder="Task title..." required
                 class="sm:col-span-2 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-blue-400">
          <select name="type" class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-blue-400">
            <option value="any">📎 Any (photo or note)</option>
            <option value="photo">📸 Photo required</option>
            <option value="note">📝 Note only</option>
            <option value="both">📸📝 Photo + Note (both required)</option>
          </select>
        </div>
        <input type="text" name="description" placeholder="Description (optional)..."
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-blue-400">

        <div>
          <label class="text-xs text-gray-500 mb-1.5 block">Assign to (leave blank = anyone can submit):</label>
          <div class="flex flex-wrap gap-2">
            @foreach($allUsers as $u)
              <label class="flex items-center gap-1.5 text-xs cursor-pointer px-2 py-1.5 rounded-lg border border-gray-200 hover:bg-gray-50">
                <input type="checkbox" name="assigned_users[]" value="{{ $u->id }}" class="accent-blue-600">
                {{ $u->name }}
              </label>
            @endforeach
          </div>
        </div>

        <button type="submit"
                class="px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700 font-medium">
          + Add Task
        </button>
      </form>
    </div>

    {{-- ====== TASK LIST ====== --}}
    <div class="bg-white border border-gray-200 rounded-xl shadow-sm divide-y divide-gray-100">
      <div class="px-4 py-3 flex items-center justify-between">
        <h2 class="font-semibold text-gray-800 text-sm">All Tasks ({{ $allTasks->count() }})</h2>
        @if($allTasks->count() > 1)
          <span class="text-xs text-gray-400">Drag to reorder</span>
        @endif
      </div>

      @forelse($allTasks as $t)
        @php $assignedIds = $t->assignedUsers->pluck('id')->toArray(); @endphp

        <div x-data="{ editing: false }"
             class="{{ $t->is_active ? '' : 'opacity-60 bg-gray-50' }}">

          {{-- View Row --}}
          <div class="flex items-center gap-3 px-4 py-3" x-show="!editing">
            <div class="w-2 h-2 rounded-full flex-shrink-0 {{ $t->is_active ? 'bg-green-500' : 'bg-gray-300' }}"></div>
            <div class="flex-1 min-w-0">
              <div class="flex items-center flex-wrap gap-1.5">
                <span class="text-sm font-medium text-gray-800">{{ $t->title }}</span>
                <span class="text-xs px-1.5 py-0.5 rounded-full
                  {{ $t->type === 'photo' ? 'bg-blue-100 text-blue-700' : ($t->type === 'note' ? 'bg-yellow-100 text-yellow-700' : 'bg-gray-100 text-gray-600') }}">
                  {{ $t->type === 'photo' ? '📸' : ($t->type === 'note' ? '📝' : '📎') }}
                  {{ ucfirst($t->type) }}
                </span>
                @if(!$t->is_active)
                  <span class="text-xs text-gray-400 italic">(inactive)</span>
                @endif
              </div>
              @if($t->description)
                <p class="text-xs text-gray-400 mt-0.5">{{ $t->description }}</p>
              @endif
              @if($t->assignedUsers->count())
                <p class="text-xs text-indigo-500 mt-0.5">→ {{ $t->assignedUsers->pluck('name')->implode(', ') }}</p>
              @else
                <p class="text-xs text-gray-400 mt-0.5">→ Anyone</p>
              @endif
            </div>
            <div class="flex gap-1 flex-shrink-0">
              <button @click="editing = true"
                      class="text-xs px-2 py-1 rounded border border-gray-300 hover:bg-gray-50 text-gray-600">Edit</button>
              <form method="POST" action="{{ route('checklist.update-task', $t) }}">
                @csrf @method('PATCH')
                <input type="hidden" name="title"       value="{{ $t->title }}">
                <input type="hidden" name="description" value="{{ $t->description }}">
                <input type="hidden" name="type"        value="{{ $t->type }}">
                <input type="hidden" name="is_active"   value="{{ $t->is_active ? '0' : '1' }}">
                @foreach($assignedIds as $uid)
                  <input type="hidden" name="assigned_users[]" value="{{ $uid }}">
                @endforeach
                <button type="submit"
                        class="text-xs px-2 py-1 rounded border {{ $t->is_active ? 'border-amber-300 text-amber-700 hover:bg-amber-50' : 'border-green-300 text-green-700 hover:bg-green-50' }}">
                  {{ $t->is_active ? 'Disable' : 'Enable' }}
                </button>
              </form>
              <form method="POST" action="{{ route('checklist.destroy-task', $t) }}"
                    onsubmit="return confirm('Delete \'{{ addslashes($t->title) }}\'? This cannot be undone.')">
                @csrf @method('DELETE')
                <button type="submit"
                        class="text-xs px-2 py-1 rounded border border-red-300 text-red-600 hover:bg-red-50">
                  Delete
                </button>
              </form>
            </div>
          </div>

          {{-- Edit Mode --}}
          <form method="POST" action="{{ route('checklist.update-task', $t) }}"
                class="px-4 py-3 space-y-2 bg-blue-50/30" x-show="editing" x-transition>
            @csrf @method('PATCH')
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
              <input type="text" name="title" value="{{ $t->title }}" required
                     class="sm:col-span-2 border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-blue-400">
              <select name="type" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-blue-400">
                <option value="any"   {{ $t->type === 'any'   ? 'selected' : '' }}>📎 Any</option>
                <option value="photo" {{ $t->type === 'photo' ? 'selected' : '' }}>📸 Photo</option>
                <option value="note"  {{ $t->type === 'note'  ? 'selected' : '' }}>📝 Note</option>
                <option value="both"  {{ $t->type === 'both'  ? 'selected' : '' }}>📸📝 Photo + Note</option>
              </select>
            </div>
            <input type="text" name="description" value="{{ $t->description }}"
                   placeholder="Description (optional)..."
                   class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-blue-400">
            <input type="hidden" name="is_active" value="{{ $t->is_active ? '1' : '0' }}">
            <div>
              <label class="text-xs text-gray-500 mb-1.5 block">Assigned to (blank = anyone):</label>
              <div class="flex flex-wrap gap-2">
                @foreach($allUsers as $u)
                  <label class="flex items-center gap-1.5 text-xs cursor-pointer px-2 py-1.5 rounded-lg border border-gray-200 hover:bg-gray-100 bg-white">
                    <input type="checkbox" name="assigned_users[]" value="{{ $u->id }}"
                           {{ in_array($u->id, $assignedIds) ? 'checked' : '' }}
                           class="accent-blue-600">
                    {{ $u->name }}
                  </label>
                @endforeach
              </div>
            </div>
            <div class="flex gap-2 pt-1">
              <button type="submit" class="px-3 py-1.5 bg-blue-600 text-white text-xs rounded-lg hover:bg-blue-700 font-medium">Save Changes</button>
              <button type="button" @click="editing = false" class="px-3 py-1.5 bg-gray-100 text-gray-700 text-xs rounded-lg hover:bg-gray-200">Cancel</button>
            </div>
          </form>
        </div>
      @empty
        <div class="px-4 py-10 text-center text-sm text-gray-400">No tasks yet. Add one above.</div>
      @endforelse
    </div>

  </div>
</x-layout>

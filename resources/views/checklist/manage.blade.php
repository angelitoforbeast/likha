<x-layout>
  <x-slot name="heading">Manage Checklist Tasks</x-slot>
  <x-slot name="title">Manage Checklist Tasks</x-slot>

  @php
    $tasksJson = $allTasks->map(fn($t) => [
        'id'              => $t->id,
        'title'           => strtolower($t->title),
        'type'            => $t->type,
        'status'          => $t->is_active ? 'active' : 'inactive',
        'submission_type' => $t->submission_type ?? 'group',
        'assigned_ids'    => $t->assignedUsers->pluck('id')->values()->toArray(),
    ])->values();
  @endphp

  <div x-data="manageChecklist()" class="p-4 max-w-screen-xl mx-auto space-y-4 mt-16">

    {{-- HEADER --}}
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

    {{-- ALERTS --}}
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

    {{-- ADD TASK (collapsible) --}}
    <div x-data="{ open: {{ $allTasks->isEmpty() ? 'true' : 'false' }} }"
         class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
      <button @click="open = !open"
              class="w-full flex items-center justify-between px-4 py-3 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
        <span>+ Add New Task</span>
        <svg :class="open ? 'rotate-180' : ''" class="w-4 h-4 text-gray-400 transition-transform duration-200"
             fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
        </svg>
      </button>
      <div x-show="open" x-transition class="border-t border-gray-100 p-4 space-y-3">
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
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
            <input type="text" name="description" placeholder="Description (optional)..."
                   class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-blue-400">
            <div class="grid grid-cols-2 gap-2">
              <input type="time" name="scheduled_time"
                     class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-sky-400">
              <select name="submission_type"
                      class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-blue-400">
                <option value="group">👥 Group</option>
                <option value="individual">👤 Individual</option>
              </select>
            </div>
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
            <div>
              <label class="text-xs text-gray-500 mb-1 block">AI Prompt <span class="text-gray-400">(optional)</span></label>
              <textarea name="ai_prompt" rows="2" placeholder="e.g. Check if the workstation is clean..."
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-purple-400 resize-none"></textarea>
            </div>
            <div>
              <label class="text-xs text-gray-500 mb-1 block">Approval Prompt <span class="text-gray-400">(optional)</span></label>
              <textarea name="approval_prompt" rows="2" placeholder="e.g. Check if budget report shows all 3 slots..."
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-400 resize-none"></textarea>
            </div>
          </div>
          <div>
            <label class="text-xs text-gray-500 mb-1.5 block">Assign to <span class="text-gray-400">(blank = anyone)</span></label>
            <div class="flex flex-wrap gap-2">
              @foreach($allUsers as $u)
                <label class="flex items-center gap-1.5 text-xs cursor-pointer px-2 py-1.5 rounded-lg border border-gray-200 hover:bg-gray-50">
                  <input type="checkbox" name="assigned_users[]" value="{{ $u->id }}" class="accent-blue-600">
                  {{ $u->name }}
                </label>
              @endforeach
            </div>
          </div>
          <button type="submit" class="px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700 font-medium">
            + Add Task
          </button>
        </form>
      </div>
    </div>

    {{-- BULK ACTION BAR --}}
    <div x-show="selected.length > 0" x-transition
         class="bg-indigo-50 border border-indigo-200 rounded-xl px-4 py-2.5 flex items-center gap-3 flex-wrap">
      <span class="text-sm font-semibold text-indigo-700" x-text="selected.length + ' task(s) selected'"></span>

      {{-- Assign To --}}
      <div class="relative" x-data="{open: false}">
        <button @click="open = !open"
                class="text-xs px-3 py-1.5 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 flex items-center gap-1 font-medium">
          👤 Assign To
          <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
          </svg>
        </button>
        <div x-show="open" x-transition @click.outside="open = false"
             class="absolute left-0 top-full mt-1 z-30 bg-white border border-gray-200 rounded-xl shadow-lg p-3 min-w-[220px]">
          <form method="POST" action="{{ route('checklist.bulk-assign') }}" x-data="{clearAll: false}">
            @csrf
            <template x-for="id in $store.manage.selected" :key="id">
              <input type="hidden" name="task_ids[]" :value="id">
            </template>
            <p class="text-xs font-semibold text-gray-500 mb-2">Assign selected tasks to:</p>
            <div class="space-y-1 max-h-52 overflow-y-auto mb-3 pr-1">
              <label class="flex items-center gap-2 text-xs cursor-pointer hover:bg-amber-50 px-2 py-1.5 rounded-lg border border-amber-100">
                <input type="checkbox" name="clear_assign" value="1" x-model="clearAll" class="accent-amber-500">
                <span class="text-amber-700 font-medium">Anyone (remove assignment)</span>
              </label>
              <div :class="clearAll ? 'opacity-40 pointer-events-none' : ''">
                @foreach($allUsers as $u)
                  <label class="flex items-center gap-2 text-xs cursor-pointer hover:bg-indigo-50 px-2 py-1.5 rounded-lg">
                    <input type="checkbox" name="user_ids[]" value="{{ $u->id }}" class="accent-indigo-600">
                    {{ $u->name }}
                  </label>
                @endforeach
              </div>
            </div>
            <button type="submit"
                    class="w-full px-3 py-1.5 bg-indigo-600 text-white text-xs rounded-lg hover:bg-indigo-700 font-medium">
              Apply
            </button>
          </form>
        </div>
      </div>

      <button @click="selected = []; $store.manage.selected = []"
              class="text-xs text-indigo-500 hover:text-indigo-700 ml-auto">
        ✕ Clear selection
      </button>
    </div>

    {{-- TASK TABLE --}}
    <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">

      {{-- Filter / Header Bar --}}
      <div class="px-4 py-2.5 border-b border-gray-100 flex items-center gap-2 flex-wrap bg-gray-50/50">
        <span class="text-sm font-semibold text-gray-700 whitespace-nowrap">
          Tasks (<span x-text="visibleCount"></span>/{{ $allTasks->count() }})
        </span>
        <div class="w-px h-4 bg-gray-200 mx-1 hidden sm:block"></div>
        {{-- Title search --}}
        <input x-model.debounce.200="filters.title" type="text" placeholder="Search title…"
               class="text-xs border border-gray-200 rounded-lg px-2.5 py-1.5 focus:outline-none focus:ring-1 focus:ring-blue-400 w-36">
        {{-- Type --}}
        <select x-model="filters.type"
                class="text-xs border border-gray-200 rounded-lg px-2 py-1.5 focus:outline-none focus:ring-1 focus:ring-blue-400">
          <option value="">All types</option>
          <option value="any">📎 Any</option>
          <option value="photo">📸 Photo</option>
          <option value="note">📝 Note</option>
          <option value="both">📸📝 Both</option>
        </select>
        {{-- Status --}}
        <select x-model="filters.status"
                class="text-xs border border-gray-200 rounded-lg px-2 py-1.5 focus:outline-none focus:ring-1 focus:ring-blue-400">
          <option value="">All status</option>
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </select>
        {{-- Submission Type --}}
        <select x-model="filters.submission_type"
                class="text-xs border border-gray-200 rounded-lg px-2 py-1.5 focus:outline-none focus:ring-1 focus:ring-blue-400">
          <option value="">All submission</option>
          <option value="group">👥 Group</option>
          <option value="individual">👤 Individual</option>
        </select>
        {{-- Assigned To --}}
        <select x-model="filters.assigned"
                class="text-xs border border-gray-200 rounded-lg px-2 py-1.5 focus:outline-none focus:ring-1 focus:ring-blue-400">
          <option value="">All assigned</option>
          <option value="anyone">Anyone (unassigned)</option>
          @foreach($allUsers as $u)
            <option value="{{ $u->id }}">{{ $u->name }}</option>
          @endforeach
        </select>
        <button x-show="hasFilters" @click="clearFilters()"
                class="text-xs text-red-400 hover:text-red-600 px-2 py-1 rounded hover:bg-red-50 transition">
          ✕ Clear
        </button>
        <span class="ml-auto text-xs text-gray-400 hidden sm:block">⠿ drag · # to reorder</span>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-sm border-collapse">
          <thead>
            <tr class="border-b border-gray-100 bg-gray-50/60 text-xs text-gray-400 uppercase tracking-wide font-semibold">
              <th class="px-3 py-2.5 w-8">
                <input type="checkbox"
                       @change="toggleAll($event.target.checked)"
                       class="accent-indigo-600 cursor-pointer">
              </th>
              <th class="px-1 py-2.5 w-12 text-center">#</th>
              <th class="px-1 py-2.5 w-5"></th>
              <th class="text-left px-3 py-2.5">Title</th>
              <th class="text-left px-3 py-2.5 w-24">Type</th>
              <th class="text-left px-3 py-2.5 w-36">Assigned To</th>
              <th class="text-left px-3 py-2.5 w-20">Status</th>
              <th class="text-left px-3 py-2.5 w-24">Submission</th>
              <th class="text-left px-3 py-2.5 w-20">Time</th>
              <th class="text-left px-3 py-2.5 w-48">Actions</th>
            </tr>
          </thead>

          <tbody id="task-sort-list">
          @forelse($allTasks as $index => $t)
            @php $assignedIds = $t->assignedUsers->pluck('id')->toArray(); @endphp

            {{-- Each task = one tbody so both data+edit rows share x-data scope --}}
            <tbody x-data="{ editing: false }"
                   class="task-row"
                   data-id="{{ $t->id }}"
                   x-show="rowVisible({{ $t->id }})"
            >

              {{-- DATA ROW --}}
              <tr class="border-b border-gray-50 hover:bg-gray-50/50 transition-colors {{ $t->is_active ? '' : 'opacity-60' }}">

                {{-- Checkbox --}}
                <td class="px-3 py-3 align-middle">
                  <input type="checkbox" :value="{{ $t->id }}" x-model="selected"
                         class="accent-indigo-600 cursor-pointer">
                </td>

                {{-- Sort # --}}
                <td class="px-1 py-3 align-middle text-center">
                  <input type="number" value="{{ $index + 1 }}" min="1" max="{{ $allTasks->count() }}"
                         title="Type position number + Enter to reorder"
                         class="w-10 text-center text-xs border border-gray-200 rounded px-1 py-0.5 focus:outline-none focus:ring-1 focus:ring-blue-300 bg-gray-50"
                         @keydown.enter.prevent="moveToPosition($el, $el.value)"
                         @blur="moveToPosition($el, $el.value)">
                </td>

                {{-- Drag handle --}}
                <td class="px-1 py-3 align-middle">
                  <span class="drag-handle cursor-grab active:cursor-grabbing text-gray-300 hover:text-gray-500 select-none text-base leading-none"
                        title="Drag to reorder">⠿</span>
                </td>

                {{-- Title --}}
                <td class="px-3 py-3 align-top">
                  <p class="font-medium text-gray-800 leading-snug">{{ $t->title }}</p>
                  @if($t->description)
                    <p class="text-xs text-gray-400 mt-0.5 truncate max-w-xs">{{ $t->description }}</p>
                  @endif
                  @if($t->ai_prompt)
                    <p class="text-xs text-purple-400 mt-0.5 truncate max-w-xs" title="{{ $t->ai_prompt }}">🤖 {{ $t->ai_prompt }}</p>
                  @endif
                  @if($t->approval_prompt)
                    <p class="text-xs text-emerald-500 mt-0.5 truncate max-w-xs" title="{{ $t->approval_prompt }}">✔ {{ $t->approval_prompt }}</p>
                  @endif
                </td>

                {{-- Type --}}
                <td class="px-3 py-3 align-middle">
                  <span class="text-xs px-1.5 py-0.5 rounded-full whitespace-nowrap
                    {{ $t->type === 'photo' ? 'bg-blue-100 text-blue-700' : ($t->type === 'note' ? 'bg-yellow-100 text-yellow-700' : ($t->type === 'both' ? 'bg-purple-100 text-purple-700' : 'bg-gray-100 text-gray-600')) }}">
                    {{ $t->type === 'photo' ? '📸 Photo' : ($t->type === 'note' ? '📝 Note' : ($t->type === 'both' ? '📸📝 Both' : '📎 Any')) }}
                  </span>
                </td>

                {{-- Assigned To --}}
                <td class="px-3 py-3 align-middle">
                  @if($t->assignedUsers->count())
                    <p class="text-xs text-indigo-600 leading-snug">{{ $t->assignedUsers->pluck('name')->implode(', ') }}</p>
                  @else
                    <span class="text-xs text-gray-400">Anyone</span>
                  @endif
                </td>

                {{-- Status --}}
                <td class="px-3 py-3 align-middle">
                  <span class="text-xs px-1.5 py-0.5 rounded-full {{ $t->is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                    {{ $t->is_active ? 'Active' : 'Inactive' }}
                  </span>
                </td>

                {{-- Submission Type --}}
                <td class="px-3 py-3 align-middle">
                  @if(($t->submission_type ?? 'group') === 'individual')
                    <span class="text-xs px-1.5 py-0.5 rounded-full bg-violet-100 text-violet-700">👤 Individual</span>
                  @else
                    <span class="text-xs px-1.5 py-0.5 rounded-full bg-gray-100 text-gray-500">👥 Group</span>
                  @endif
                </td>

                {{-- Time --}}
                <td class="px-3 py-3 align-middle whitespace-nowrap">
                  @if($t->scheduled_time)
                    <span class="text-xs text-sky-600">🕐 {{ date('g:i A', strtotime($t->scheduled_time)) }}</span>
                  @else
                    <span class="text-gray-300 text-xs">—</span>
                  @endif
                </td>

                {{-- Actions --}}
                <td class="px-3 py-3 align-middle">
                  <div class="flex gap-1 flex-wrap">
                    <button @click="editing = !editing"
                            class="text-xs px-2 py-1 rounded border border-gray-300 hover:bg-gray-50 text-gray-600">
                      Edit
                    </button>
                    <form method="POST" action="{{ route('checklist.duplicate-task', $t) }}">
                      @csrf
                      <button type="submit"
                              class="text-xs px-2 py-1 rounded border border-blue-300 text-blue-600 hover:bg-blue-50">
                        Dup
                      </button>
                    </form>
                    <form method="POST" action="{{ route('checklist.update-task', $t) }}">
                      @csrf @method('PATCH')
                      <input type="hidden" name="title"           value="{{ $t->title }}">
                      <input type="hidden" name="description"     value="{{ $t->description }}">
                      <input type="hidden" name="type"            value="{{ $t->type }}">
                      <input type="hidden" name="scheduled_time"  value="{{ $t->scheduled_time }}">
                      <input type="hidden" name="submission_type" value="{{ $t->submission_type }}">
                      <input type="hidden" name="ai_prompt"       value="{{ $t->ai_prompt }}">
                      <input type="hidden" name="approval_prompt" value="{{ $t->approval_prompt }}">
                      <input type="hidden" name="is_active"       value="{{ $t->is_active ? '0' : '1' }}">
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
                        Del
                      </button>
                    </form>
                  </div>
                </td>
              </tr>

              {{-- EDIT ROW --}}
              <tr x-show="editing" x-transition class="border-b border-blue-100 bg-blue-50/20">
                <td colspan="10" class="px-4 py-3">
                  <form method="POST" action="{{ route('checklist.update-task', $t) }}" class="space-y-2">
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
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                      <input type="text" name="description" value="{{ $t->description }}"
                             placeholder="Description (optional)..."
                             class="sm:col-span-1 border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-blue-400">
                      <input type="time" name="scheduled_time" value="{{ $t->scheduled_time }}"
                             class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-sky-400">
                      <select name="submission_type"
                              class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-blue-400">
                        <option value="group"      {{ ($t->submission_type ?? 'group') === 'group'      ? 'selected' : '' }}>👥 Group</option>
                        <option value="individual" {{ ($t->submission_type ?? 'group') === 'individual' ? 'selected' : '' }}>👤 Individual</option>
                      </select>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                      <div>
                        <label class="text-xs text-gray-500 mb-1 block">AI Prompt <span class="text-gray-400">(optional)</span></label>
                        <textarea name="ai_prompt" rows="2"
                                  class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-purple-400 resize-none">{{ $t->ai_prompt }}</textarea>
                      </div>
                      <div>
                        <label class="text-xs text-gray-500 mb-1 block">Approval Prompt <span class="text-gray-400">(optional)</span></label>
                        <textarea name="approval_prompt" rows="2"
                                  class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-400 resize-none">{{ $t->approval_prompt }}</textarea>
                      </div>
                    </div>
                    <input type="hidden" name="is_active" value="{{ $t->is_active ? '1' : '0' }}">
                    <div>
                      <label class="text-xs text-gray-500 mb-1.5 block">Assigned to <span class="text-gray-400">(blank = anyone)</span></label>
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
                </td>
              </tr>

            </tbody>
          @empty
            <tbody>
              <tr>
                <td colspan="10" class="px-4 py-10 text-center text-sm text-gray-400">No tasks yet. Add one above.</td>
              </tr>
            </tbody>
          @endforelse
          </tbody>{{-- closes #task-sort-list --}}

        </table>
      </div>

    </div>
  </div>

  <script>
    // Alpine store for shared selected state (needed for bulk assign form)
    document.addEventListener('alpine:init', () => {
      Alpine.store('manage', { selected: [] });
    });

    function manageChecklist() {
      return {
        get selected() { return Alpine.store('manage').selected; },
        set selected(v) { Alpine.store('manage').selected = v; },

        filters: { title: '', type: '', status: '', submission_type: '', assigned: '' },

        tasks: {!! \Illuminate\Support\Js::from($tasksJson) !!},

        get hasFilters() {
          return Object.values(this.filters).some(v => v !== '');
        },

        get visibleCount() {
          return this.tasks.filter(t => this.isVisible(t)).length;
        },

        isVisible(task) {
          if (this.filters.title && !task.title.includes(this.filters.title.toLowerCase())) return false;
          if (this.filters.type && task.type !== this.filters.type) return false;
          if (this.filters.status && task.status !== this.filters.status) return false;
          if (this.filters.submission_type && task.submission_type !== this.filters.submission_type) return false;
          if (this.filters.assigned) {
            if (this.filters.assigned === 'anyone' && task.assigned_ids.length > 0) return false;
            if (this.filters.assigned !== 'anyone' && !task.assigned_ids.includes(parseInt(this.filters.assigned))) return false;
          }
          return true;
        },

        rowVisible(id) {
          const task = this.tasks.find(t => t.id === id);
          return task ? this.isVisible(task) : true;
        },

        clearFilters() {
          this.filters = { title: '', type: '', status: '', submission_type: '', assigned: '' };
        },

        toggleAll(checked) {
          if (checked) {
            this.selected = this.tasks.filter(t => this.isVisible(t)).map(t => t.id);
          } else {
            this.selected = [];
          }
        },
      };
    }

    function moveToPosition(input, rawVal) {
      const list  = document.getElementById('task-sort-list');
      const rows  = [...list.querySelectorAll('tbody.task-row')];
      const total = rows.length;
      const pos   = Math.max(1, Math.min(total, parseInt(rawVal) || 1)) - 1;
      const row   = input.closest('tbody.task-row');
      const curIdx = rows.indexOf(row);

      if (curIdx === pos) { input.value = pos + 1; return; }

      row.parentNode.removeChild(row);
      const updated = [...list.querySelectorAll('tbody.task-row')];
      if (pos >= updated.length) {
        list.appendChild(row);
      } else {
        list.insertBefore(row, updated[pos]);
      }

      // Refresh all position inputs
      [...list.querySelectorAll('tbody.task-row')].forEach((r, i) => {
        const inp = r.querySelector('input[type=number]');
        if (inp) inp.value = i + 1;
      });

      saveOrder();
    }

    function saveOrder() {
      const list = document.getElementById('task-sort-list');
      const ids  = [...list.querySelectorAll('tbody.task-row')].map(r => r.dataset.id);
      fetch('{{ route('checklist.reorder') }}', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '{{ csrf_token() }}',
        },
        body: JSON.stringify({ order: ids }),
      });
    }

    // Drag to reorder
    (function () {
      const list = document.getElementById('task-sort-list');
      if (!list) return;
      let dragging = null;

      function initRow(row) {
        row.setAttribute('draggable', 'false');
        const handle = row.querySelector('.drag-handle');
        if (handle) {
          handle.addEventListener('mousedown', () => row.setAttribute('draggable', 'true'));
          handle.addEventListener('mouseup',   () => row.setAttribute('draggable', 'false'));
        }
        row.addEventListener('dragstart', e => {
          dragging = row;
          row.style.opacity = '0.4';
          e.dataTransfer.effectAllowed = 'move';
        });
        row.addEventListener('dragend', () => {
          row.setAttribute('draggable', 'false');
          row.style.opacity = '';
          list.querySelectorAll('tbody.task-row').forEach(r => r.style.outline = '');
          dragging = null;
          [...list.querySelectorAll('tbody.task-row')].forEach((r, i) => {
            const inp = r.querySelector('input[type=number]');
            if (inp) inp.value = i + 1;
          });
          saveOrder();
        });
        row.addEventListener('dragover', e => {
          e.preventDefault();
          if (!dragging || dragging === row) return;
          const rect = row.getBoundingClientRect();
          list.querySelectorAll('tbody.task-row').forEach(r => r.style.outline = '');
          if (e.clientY < rect.top + rect.height / 2) {
            row.style.outline = '2px solid #3b82f6';
            list.insertBefore(dragging, row);
          } else {
            row.style.outline = '2px solid #3b82f6';
            list.insertBefore(dragging, row.nextSibling);
          }
        });
      }

      list.querySelectorAll('tbody.task-row').forEach(initRow);
    })();
  </script>

</x-layout>

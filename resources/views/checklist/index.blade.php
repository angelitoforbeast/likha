<x-layout>
  <x-slot name="heading">Daily Checklist</x-slot>
  <x-slot name="title">Daily Checklist</x-slot>

  <div class="p-4 max-w-3xl mx-auto space-y-4" x-data="{ manageTasks: false }">

    {{-- Header --}}
    <div class="flex items-center justify-between flex-wrap gap-2">
      <div>
        <h1 class="text-xl font-bold text-gray-800">Daily Checklist</h1>
        <p class="text-sm text-gray-500">{{ now()->format('l, F j, Y') }}</p>
      </div>
      <button @click="manageTasks = !manageTasks"
              class="flex items-center gap-1.5 text-sm px-3 py-1.5 rounded-lg border border-gray-300 hover:bg-gray-50 text-gray-700">
        ⚙ Manage Tasks
      </button>
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

    {{-- Overall Progress --}}
    <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-4">
      <div class="flex items-center justify-between mb-2">
        <span class="text-sm font-semibold text-gray-700">Today's Progress</span>
        <span class="text-sm font-bold {{ $doneCount === $totalTasks && $totalTasks > 0 ? 'text-green-600' : 'text-amber-600' }}">
          {{ $doneCount }} / {{ $totalTasks }} tasks done
        </span>
      </div>
      <div class="w-full bg-gray-100 rounded-full h-2">
        <div class="bg-green-500 h-2 rounded-full transition-all duration-300"
             style="width: {{ $totalTasks > 0 ? round($doneCount / $totalTasks * 100) : 0 }}%"></div>
      </div>
    </div>

    {{-- ====== MANAGE TASKS PANEL ====== --}}
    <div x-show="manageTasks" x-transition
         class="bg-white border border-gray-200 rounded-xl shadow-sm p-4 space-y-4">

      <h2 class="font-semibold text-gray-800">Manage Tasks</h2>

      {{-- Add Task --}}
      <form method="POST" action="{{ route('checklist.store-task') }}"
            class="p-3 bg-gray-50 border border-gray-200 rounded-lg space-y-2">
        @csrf
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
          <input type="text" name="title" placeholder="Task title..." required
                 class="sm:col-span-2 border border-gray-300 rounded-lg px-3 py-2 text-sm">
          <select name="type" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
            <option value="any">📎 Any (photo or note)</option>
            <option value="photo">📸 Photo required</option>
            <option value="note">📝 Note only</option>
          </select>
        </div>
        <input type="text" name="description" placeholder="Description (optional)..."
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
        {{-- Assign users --}}
        <div>
          <label class="text-xs text-gray-500 mb-1 block">Assign to (leave blank = anyone can submit):</label>
          <div class="flex flex-wrap gap-2">
            @foreach($allUsers as $u)
              <label class="flex items-center gap-1.5 text-xs cursor-pointer px-2 py-1 rounded border border-gray-200 hover:bg-gray-100">
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

      {{-- Task List --}}
      <div class="space-y-2">
        @forelse($allTasks as $t)
          @php $assignedIds = $t->assignedUsers->pluck('id')->toArray(); @endphp
          <div x-data="{ editing: false }"
               class="border rounded-lg {{ $t->is_active ? 'bg-white' : 'bg-gray-50 opacity-60' }}">

            {{-- View row --}}
            <div class="flex items-center gap-3 p-3" x-show="!editing">
              <div class="w-2 h-2 rounded-full flex-shrink-0 {{ $t->is_active ? 'bg-green-500' : 'bg-gray-300' }}"></div>
              <div class="flex-1 min-w-0">
                <span class="text-sm font-medium text-gray-800">{{ $t->title }}</span>
                @if($t->description)
                  <span class="text-xs text-gray-400 ml-1">— {{ $t->description }}</span>
                @endif
                <span class="ml-1.5 text-xs px-1.5 py-0.5 rounded-full
                  {{ $t->type === 'photo' ? 'bg-blue-100 text-blue-700' : ($t->type === 'note' ? 'bg-yellow-100 text-yellow-700' : 'bg-gray-100 text-gray-600') }}">
                  {{ $t->type === 'photo' ? '📸' : ($t->type === 'note' ? '📝' : '📎') }}
                </span>
                @if($t->assignedUsers->count())
                  <span class="ml-1.5 text-xs text-indigo-600">
                    → {{ $t->assignedUsers->pluck('name')->implode(', ') }}
                  </span>
                @else
                  <span class="ml-1.5 text-xs text-gray-400">→ anyone</span>
                @endif
                @if(!$t->is_active)
                  <span class="ml-1 text-xs text-gray-400">(inactive)</span>
                @endif
              </div>
              <div class="flex gap-1 flex-shrink-0">
                <button @click="editing=true"
                        class="text-xs px-2 py-1 rounded border border-gray-300 hover:bg-gray-50 text-gray-600">Edit</button>
                <form method="POST" action="{{ route('checklist.update-task', $t) }}">
                  @csrf @method('PATCH')
                  <input type="hidden" name="title" value="{{ $t->title }}">
                  <input type="hidden" name="description" value="{{ $t->description }}">
                  <input type="hidden" name="type" value="{{ $t->type }}">
                  <input type="hidden" name="is_active" value="{{ $t->is_active ? '0' : '1' }}">
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

            {{-- Edit mode --}}
            <form method="POST" action="{{ route('checklist.update-task', $t) }}"
                  class="p-3 space-y-2" x-show="editing">
              @csrf @method('PATCH')
              <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                <input type="text" name="title" value="{{ $t->title }}" required
                       class="sm:col-span-2 border border-gray-300 rounded px-2 py-1 text-sm">
                <select name="type" class="border border-gray-300 rounded px-2 py-1 text-sm">
                  <option value="any"  {{ $t->type === 'any'   ? 'selected' : '' }}>Any</option>
                  <option value="photo"{{ $t->type === 'photo' ? 'selected' : '' }}>Photo</option>
                  <option value="note" {{ $t->type === 'note'  ? 'selected' : '' }}>Note</option>
                </select>
              </div>
              <input type="text" name="description" value="{{ $t->description }}"
                     placeholder="Description..." class="w-full border border-gray-300 rounded px-2 py-1 text-sm">
              <input type="hidden" name="is_active" value="{{ $t->is_active ? '1' : '0' }}">
              {{-- Assign users --}}
              <div>
                <label class="text-xs text-gray-500 mb-1 block">Assigned to (blank = anyone):</label>
                <div class="flex flex-wrap gap-2">
                  @foreach($allUsers as $u)
                    <label class="flex items-center gap-1.5 text-xs cursor-pointer px-2 py-1 rounded border border-gray-200 hover:bg-gray-100">
                      <input type="checkbox" name="assigned_users[]" value="{{ $u->id }}"
                             {{ in_array($u->id, $assignedIds) ? 'checked' : '' }}
                             class="accent-blue-600">
                      {{ $u->name }}
                    </label>
                  @endforeach
                </div>
              </div>
              <div class="flex gap-2">
                <button type="submit" class="px-3 py-1.5 bg-blue-600 text-white text-xs rounded hover:bg-blue-700">Save</button>
                <button type="button" @click="editing=false" class="px-3 py-1.5 bg-gray-200 text-gray-700 text-xs rounded">Cancel</button>
              </div>
            </form>
          </div>
        @empty
          <p class="text-sm text-gray-400 text-center py-4">No tasks yet.</p>
        @endforelse
      </div>
    </div>

    {{-- ====== TASK CARDS ====== --}}
    @php $isCeo = Auth::user()?->employeeProfile?->role === 'CEO'; @endphp

    <div x-show="!manageTasks">
    @forelse($tasks as $task)
      @php
        $sub          = $submissionsByTask->get($task->id);
        $done         = $sub !== null;
        $isMine       = $sub && $sub->user_id === Auth::id();
        $assignedIds  = $task->assignedUsers->pluck('id')->toArray();
        $isAssigned   = empty($assignedIds) || in_array(Auth::id(), $assignedIds);
        $canSubmit    = !$done && $isAssigned;
      @endphp

      <div class="bg-white border {{ $done ? 'border-green-200' : ($isAssigned ? 'border-amber-200' : 'border-gray-200') }} rounded-xl shadow-sm overflow-hidden"
           x-data="{ showForm: {{ $canSubmit ? 'true' : 'false' }} }">

        {{-- Card Header --}}
        <div class="flex items-center justify-between px-4 py-3 {{ $done ? 'bg-green-50' : ($isAssigned ? 'bg-amber-50' : 'bg-gray-50') }}">
          <div class="flex items-center gap-2 flex-wrap">
            <span class="font-semibold text-gray-800 text-sm">{{ $task->title }}</span>
            <span class="text-xs px-1.5 py-0.5 rounded-full
              {{ $task->type === 'photo' ? 'bg-blue-100 text-blue-700' : ($task->type === 'note' ? 'bg-yellow-100 text-yellow-700' : 'bg-gray-100 text-gray-600') }}">
              {{ $task->type === 'photo' ? '📸 Photo' : ($task->type === 'note' ? '📝 Note' : '📎 Any') }}
            </span>
            @if($task->description)
              <span class="text-xs text-gray-500">{{ $task->description }}</span>
            @endif
            @if($task->assignedUsers->count())
              <span class="text-xs text-indigo-500">
                → {{ $task->assignedUsers->pluck('name')->implode(', ') }}
              </span>
            @endif
          </div>
          <span class="text-xs font-semibold flex-shrink-0
            {{ $done ? 'text-green-600' : ($isAssigned ? 'text-amber-600' : 'text-gray-400') }}">
            {{ $done ? '✓ Done' : ($isAssigned ? '⚠ Pending' : 'Not assigned') }}
          </span>
        </div>

        {{-- Submission (if exists) --}}
        @if($sub)
          <div class="px-4 py-3 {{ $isMine ? 'bg-green-50/40' : 'bg-gray-50/60' }} border-b border-gray-100">
            <div class="flex items-start justify-between gap-3">
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 mb-1 flex-wrap">
                  <div class="w-6 h-6 rounded-full bg-gray-200 flex items-center justify-center text-xs font-bold text-gray-500 flex-shrink-0">
                    {{ strtoupper(substr($sub->user->name ?? '?', 0, 1)) }}
                  </div>
                  <span class="text-xs font-semibold text-gray-700">
                    {{ $sub->user->name ?? 'Unknown' }}
                    @if($isMine) <span class="text-gray-400 font-normal">(you)</span> @endif
                  </span>
                  <span class="text-xs text-gray-400">{{ $sub->created_at->format('h:i A') }}</span>
                  @if($sub->updated_at->gt($sub->created_at))
                    <span class="text-xs text-gray-400">· updated {{ $sub->updated_at->format('h:i A') }}</span>
                  @endif
                </div>

                @if($sub->notes)
                  <p class="text-sm text-gray-700 whitespace-pre-line mt-1">{{ $sub->notes }}</p>
                @endif

                @if($sub->file_path)
                  @if($sub->isImage())
                    <a href="{{ Storage::url($sub->file_path) }}" target="_blank" class="inline-block mt-2">
                      <img src="{{ Storage::url($sub->file_path) }}"
                           class="h-28 w-auto rounded-lg border border-gray-200 object-cover hover:opacity-80 transition">
                    </a>
                  @else
                    <a href="{{ Storage::url($sub->file_path) }}" target="_blank"
                       class="mt-1 inline-flex items-center gap-1 text-xs text-blue-600 hover:underline">
                      📎 {{ $sub->file_original_name }}
                    </a>
                  @endif
                @endif
              </div>

              @if($isMine || $isCeo)
                <div class="flex gap-1 flex-shrink-0">
                  @if($isMine)
                    <button @click="showForm = !showForm"
                            class="text-xs px-2 py-1 rounded border border-gray-300 hover:bg-gray-50 text-gray-600">
                      Edit
                    </button>
                  @endif
                  <form method="POST" action="{{ route('checklist.delete-submission', $sub) }}"
                        onsubmit="return confirm('Remove this submission?')">
                    @csrf @method('DELETE')
                    <button type="submit"
                            class="text-xs px-2 py-1 rounded border border-red-300 text-red-600 hover:bg-red-50">
                      Remove
                    </button>
                  </form>
                </div>
              @endif
            </div>
          </div>
        @endif

        {{-- Submit / Edit Form --}}
        @if($canSubmit || $isMine)
          <div x-show="showForm" x-transition class="px-4 py-3"
               x-data="{ fileName: '', previewUrl: '' }">
            <form method="POST" action="{{ route('checklist.submit', $task) }}" enctype="multipart/form-data">
              @csrf

              @if(in_array($task->type, ['note', 'any']))
                <textarea name="notes" rows="2" placeholder="Notes / remarks..."
                          class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm mb-2 resize-none focus:outline-none focus:ring-1 focus:ring-blue-400">{{ $sub?->notes }}</textarea>
              @endif

              @if(in_array($task->type, ['photo', 'any']))
                <div class="flex items-center gap-3 mb-2 flex-wrap">
                  <label class="cursor-pointer flex items-center gap-2 px-3 py-1.5 border border-dashed border-gray-300 rounded-lg hover:bg-gray-50 text-sm text-gray-600">
                    📎 <span x-text="fileName || 'Choose file'"></span>
                    <input type="file" name="file" class="hidden"
                           accept="{{ $task->type === 'photo' ? 'image/*' : 'image/*,.pdf,.doc,.docx,.xls,.xlsx,.csv' }}"
                           @change="
                             const f = $event.target.files[0];
                             fileName = f?.name || '';
                             previewUrl = f && f.type.startsWith('image/') ? URL.createObjectURL(f) : '';
                           ">
                  </label>
                  <span class="text-xs text-gray-400">
                    {{ $task->type === 'photo' ? 'Image required' : 'Optional — image or document' }}
                  </span>
                </div>
                <template x-if="previewUrl">
                  <img :src="previewUrl" class="h-20 w-auto rounded-lg border border-gray-200 object-cover mb-2">
                </template>
              @endif

              <div class="flex items-center gap-2">
                <button type="submit"
                        class="px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700 font-medium">
                  {{ $sub ? 'Update' : 'Submit' }}
                </button>
                @if($sub)
                  <button type="button" @click="showForm = false"
                          class="px-3 py-2 text-sm text-gray-500 hover:text-gray-700">Cancel</button>
                @endif
              </div>
            </form>
          </div>
        @elseif($done && !$isMine)
          <div class="px-4 py-2 text-xs text-gray-400">
            Submitted by {{ $sub->user->name ?? 'someone' }} — no further submission needed.
          </div>
        @elseif(!$isAssigned)
          <div class="px-4 py-2 text-xs text-gray-400">This task is assigned to someone else.</div>
        @endif

      </div>
    @empty
      <div class="bg-white border border-gray-200 rounded-xl p-10 text-center text-gray-400">
        <p class="text-lg mb-1">No active tasks</p>
        <p class="text-sm">Click "⚙ Manage Tasks" above to add tasks.</p>
      </div>
    @endforelse
    </div> {{-- end x-show="!manageTasks" --}}

  </div>
</x-layout>

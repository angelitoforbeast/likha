<x-layout>
  <x-slot name="heading">Daily Checklist</x-slot>
  <x-slot name="title">Daily Checklist</x-slot>

  <div class="p-4 max-w-4xl mx-auto space-y-4" x-data="{ manageTasks: false }">

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

    {{-- My Progress --}}
    <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-4">
      <div class="flex items-center justify-between mb-2">
        <span class="text-sm font-semibold text-gray-700">My Progress Today</span>
        <span class="text-sm font-bold {{ $myDoneCount === $totalTasks && $totalTasks > 0 ? 'text-green-600' : 'text-amber-600' }}">
          {{ $myDoneCount }} / {{ $totalTasks }} tasks
        </span>
      </div>
      <div class="w-full bg-gray-100 rounded-full h-2">
        <div class="bg-green-500 h-2 rounded-full transition-all duration-300"
             style="width: {{ $totalTasks > 0 ? round($myDoneCount / $totalTasks * 100) : 0 }}%"></div>
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
        <div class="flex gap-2">
          <input type="text" name="description" placeholder="Description (optional)..."
                 class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm">
          <button type="submit"
                  class="px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700 font-medium">
            + Add Task
          </button>
        </div>
      </form>

      {{-- Task List --}}
      <div class="space-y-2">
        @forelse($allTasks as $t)
          <div x-data="{ editing: false }"
               class="flex items-center gap-3 p-3 border rounded-lg {{ $t->is_active ? 'bg-white' : 'bg-gray-50 opacity-60' }}">

            <div class="w-2 h-2 rounded-full flex-shrink-0 {{ $t->is_active ? 'bg-green-500' : 'bg-gray-300' }}"></div>

            {{-- View mode --}}
            <div class="flex-1 min-w-0" x-show="!editing">
              <span class="text-sm font-medium text-gray-800">{{ $t->title }}</span>
              @if($t->description)
                <span class="text-xs text-gray-400 ml-1">— {{ $t->description }}</span>
              @endif
              <span class="ml-1.5 text-xs px-1.5 py-0.5 rounded-full
                {{ $t->type === 'photo' ? 'bg-blue-100 text-blue-700' : ($t->type === 'note' ? 'bg-yellow-100 text-yellow-700' : 'bg-gray-100 text-gray-600') }}">
                {{ $t->type === 'photo' ? '📸 Photo' : ($t->type === 'note' ? '📝 Note' : '📎 Any') }}
              </span>
              @if(!$t->is_active)
                <span class="ml-1 text-xs text-gray-400">(inactive)</span>
              @endif
            </div>

            {{-- Edit mode --}}
            <form method="POST" action="{{ route('checklist.update-task', $t) }}"
                  class="flex-1 flex gap-2" x-show="editing">
              @csrf @method('PATCH')
              <input type="text" name="title" value="{{ $t->title }}"
                     class="flex-1 border border-gray-300 rounded px-2 py-1 text-sm">
              <input type="text" name="description" value="{{ $t->description }}"
                     placeholder="Description..." class="flex-1 border border-gray-300 rounded px-2 py-1 text-sm">
              <select name="type" class="border border-gray-300 rounded px-2 py-1 text-sm">
                <option value="any"  {{ $t->type === 'any'   ? 'selected' : '' }}>Any</option>
                <option value="photo"{{ $t->type === 'photo' ? 'selected' : '' }}>Photo</option>
                <option value="note" {{ $t->type === 'note'  ? 'selected' : '' }}>Note</option>
              </select>
              <input type="hidden" name="is_active" value="{{ $t->is_active ? '1' : '0' }}">
              <button type="submit" class="px-3 py-1 bg-blue-600 text-white text-xs rounded hover:bg-blue-700">Save</button>
              <button type="button" @click="editing=false" class="px-3 py-1 bg-gray-200 text-gray-700 text-xs rounded">Cancel</button>
            </form>

            {{-- Actions --}}
            <div class="flex gap-1 flex-shrink-0" x-show="!editing">
              <button @click="editing=true"
                      class="text-xs px-2 py-1 rounded border border-gray-300 hover:bg-gray-50 text-gray-600">Edit</button>

              <form method="POST" action="{{ route('checklist.update-task', $t) }}">
                @csrf @method('PATCH')
                <input type="hidden" name="title" value="{{ $t->title }}">
                <input type="hidden" name="description" value="{{ $t->description }}">
                <input type="hidden" name="type" value="{{ $t->type }}">
                <input type="hidden" name="is_active" value="{{ $t->is_active ? '0' : '1' }}">
                <button type="submit"
                        class="text-xs px-2 py-1 rounded border {{ $t->is_active ? 'border-amber-300 text-amber-700 hover:bg-amber-50' : 'border-green-300 text-green-700 hover:bg-green-50' }}">
                  {{ $t->is_active ? 'Disable' : 'Enable' }}
                </button>
              </form>
            </div>
          </div>
        @empty
          <p class="text-sm text-gray-400 text-center py-4">No tasks yet.</p>
        @endforelse
      </div>
    </div>

    {{-- ====== TASK CARDS ====== --}}
    @forelse($tasks as $task)
      @php
        $taskSubs   = $submissionsByTask->get($task->id, collect());
        $mySub      = $mySubmissions->get($task->id);
        $submitted  = $mySub !== null;
      @endphp

      <div class="bg-white border {{ $submitted ? 'border-green-200' : 'border-amber-200' }} rounded-xl shadow-sm overflow-hidden"
           x-data="{ showForm: {{ $submitted ? 'false' : 'true' }}, showAll: false }">

        {{-- Card Header --}}
        <div class="flex items-center justify-between px-4 py-3 {{ $submitted ? 'bg-green-50' : 'bg-amber-50' }}">
          <div class="flex items-center gap-2 flex-wrap">
            <span class="font-semibold text-gray-800 text-sm">{{ $task->title }}</span>
            <span class="text-xs px-1.5 py-0.5 rounded-full
              {{ $task->type === 'photo' ? 'bg-blue-100 text-blue-700' : ($task->type === 'note' ? 'bg-yellow-100 text-yellow-700' : 'bg-gray-100 text-gray-600') }}">
              {{ $task->type === 'photo' ? '📸 Photo' : ($task->type === 'note' ? '📝 Note' : '📎 Any') }}
            </span>
            @if($task->description)
              <span class="text-xs text-gray-500">{{ $task->description }}</span>
            @endif
          </div>
          <div class="flex items-center gap-3 flex-shrink-0">
            <span class="text-xs font-semibold {{ $submitted ? 'text-green-600' : 'text-amber-600' }}">
              {{ $submitted ? '✓ Done' : '⚠ Pending' }}
            </span>
            <span class="text-xs text-gray-400">{{ $taskSubs->count() }} submitted</span>
          </div>
        </div>

        {{-- My Submission (if exists) --}}
        @if($mySub)
          <div class="px-4 py-3 bg-green-50/40 border-b border-green-100">
            <div class="flex items-start justify-between gap-3">
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 mb-1">
                  <span class="text-xs font-semibold text-green-700">Your submission</span>
                  <span class="text-xs text-gray-400">{{ $mySub->created_at->format('h:i A') }}</span>
                </div>
                @if($mySub->notes)
                  <p class="text-sm text-gray-700 whitespace-pre-line">{{ $mySub->notes }}</p>
                @endif
                @if($mySub->file_path)
                  @if($mySub->isImage())
                    <a href="{{ Storage::url($mySub->file_path) }}" target="_blank" class="inline-block mt-2">
                      <img src="{{ Storage::url($mySub->file_path) }}"
                           class="h-24 w-auto rounded-lg border border-gray-200 object-cover hover:opacity-80 transition">
                    </a>
                  @else
                    <a href="{{ Storage::url($mySub->file_path) }}" target="_blank"
                       class="mt-1 inline-flex items-center gap-1 text-xs text-blue-600 hover:underline">
                      📎 {{ $mySub->file_original_name }}
                    </a>
                  @endif
                @endif
              </div>
              <div class="flex gap-1 flex-shrink-0">
                <button @click="showForm = !showForm"
                        class="text-xs px-2 py-1 rounded border border-gray-300 hover:bg-gray-50 text-gray-600">
                  Edit
                </button>
                <form method="POST" action="{{ route('checklist.delete-submission', $mySub) }}"
                      onsubmit="return confirm('Remove your submission?')">
                  @csrf @method('DELETE')
                  <button type="submit"
                          class="text-xs px-2 py-1 rounded border border-red-300 text-red-600 hover:bg-red-50">
                    Remove
                  </button>
                </form>
              </div>
            </div>
          </div>
        @endif

        {{-- Submit / Edit Form --}}
        <div x-show="showForm" x-transition class="px-4 py-3 border-b border-gray-100"
             x-data="{ fileName: '', previewUrl: '' }">
          <form method="POST" action="{{ route('checklist.submit', $task) }}" enctype="multipart/form-data">
            @csrf

            @if(in_array($task->type, ['note', 'any']))
              <textarea name="notes" rows="2"
                        placeholder="Notes / remarks..."
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm mb-2 resize-none focus:outline-none focus:ring-1 focus:ring-blue-400">{{ $mySub?->notes }}</textarea>
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
                  {{ $task->type === 'photo' ? 'Image required (jpg, png, gif, webp)' : 'Optional — image or document' }}
                </span>
              </div>
              {{-- Image preview --}}
              <template x-if="previewUrl">
                <img :src="previewUrl" class="h-20 w-auto rounded-lg border border-gray-200 object-cover mb-2">
              </template>
            @endif

            <div class="flex items-center gap-2">
              <button type="submit"
                      class="px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700 font-medium">
                {{ $mySub ? 'Update' : 'Submit' }}
              </button>
              @if($mySub)
                <button type="button" @click="showForm = false"
                        class="px-3 py-2 text-sm text-gray-500 hover:text-gray-700">Cancel</button>
              @endif
            </div>
          </form>
        </div>

        {{-- Other Submissions --}}
        @php $otherSubs = $taskSubs->where('user_id', '!=', auth()->id()); @endphp
        @if($otherSubs->count() > 0)
          <div class="px-4 py-3">
            <button @click="showAll = !showAll"
                    class="text-xs text-gray-500 hover:text-gray-700 flex items-center gap-1 mb-2">
              <span x-text="showAll ? '▾' : '▸'"></span>
              <span>Other submissions ({{ $otherSubs->count() }})</span>
            </button>
            <div x-show="showAll" class="space-y-3">
              @foreach($otherSubs as $sub)
                <div class="flex items-start gap-3">
                  <div class="flex-shrink-0 w-7 h-7 rounded-full bg-gray-200 flex items-center justify-center text-xs font-bold text-gray-500">
                    {{ strtoupper(substr($sub->user->name ?? '?', 0, 1)) }}
                  </div>
                  <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2">
                      <span class="text-xs font-semibold text-gray-700">{{ $sub->user->name ?? 'Unknown' }}</span>
                      <span class="text-xs text-gray-400">{{ $sub->created_at->format('h:i A') }}</span>
                    </div>
                    @if($sub->notes)
                      <p class="text-xs text-gray-600 mt-0.5">{{ \Illuminate\Support\Str::limit($sub->notes, 120) }}</p>
                    @endif
                    @if($sub->file_path)
                      @if($sub->isImage())
                        <a href="{{ Storage::url($sub->file_path) }}" target="_blank" class="inline-block mt-1">
                          <img src="{{ Storage::url($sub->file_path) }}"
                               class="h-14 w-auto rounded border border-gray-200 object-cover hover:opacity-80">
                        </a>
                      @else
                        <a href="{{ Storage::url($sub->file_path) }}" target="_blank"
                           class="text-xs text-blue-600 hover:underline">
                          📎 {{ $sub->file_original_name }}
                        </a>
                      @endif
                    @endif
                  </div>
                </div>
              @endforeach
            </div>
          </div>
        @elseif(!$submitted && $taskSubs->isEmpty())
          <div class="px-4 py-2 text-xs text-gray-400">No submissions yet for this task today.</div>
        @endif

      </div>
    @empty
      <div class="bg-white border border-gray-200 rounded-xl p-10 text-center text-gray-400">
        <p class="text-lg mb-1">No active tasks</p>
        <p class="text-sm">Click "⚙ Manage Tasks" above to add tasks.</p>
      </div>
    @endforelse

    {{-- ====== TEAM SUMMARY ====== --}}
    @if($tasks->count() > 0)
      <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-4"
           x-data="{ show: true }">
        <button @click="show = !show" class="flex items-center justify-between w-full mb-3">
          <span class="font-semibold text-gray-800">Team Summary</span>
          <span class="text-xs text-gray-400" x-text="show ? '▾ collapse' : '▸ expand'"></span>
        </button>

        <div x-show="show" class="overflow-x-auto">
          <table class="min-w-full text-xs">
            <thead>
              <tr class="border-b border-gray-200">
                <th class="text-left py-2 pr-4 text-gray-500 font-medium min-w-[140px]">Member</th>
                @foreach($tasks as $t)
                  <th class="text-center py-2 px-2 text-gray-500 font-medium">
                    <div class="max-w-[72px] truncate mx-auto" title="{{ $t->title }}">
                      {{ \Illuminate\Support\Str::limit($t->title, 10) }}
                    </div>
                  </th>
                @endforeach
                <th class="text-center py-2 px-2 text-gray-500 font-medium">Done</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
              @foreach($users as $u)
                @php $done = 0; @endphp
                <tr class="{{ $u->id === auth()->id() ? 'bg-blue-50 font-semibold' : 'hover:bg-gray-50' }}">
                  <td class="py-2 pr-4">
                    <div class="text-gray-800">{{ $u->name }}</div>
                    @if($u->employeeProfile?->role)
                      <div class="text-gray-400 font-normal">{{ $u->employeeProfile->role }}</div>
                    @endif
                  </td>
                  @foreach($tasks as $t)
                    @php
                      $userSub = $submissionsByTask->get($t->id, collect())->firstWhere('user_id', $u->id);
                      if ($userSub) $done++;
                    @endphp
                    <td class="text-center py-2 px-2">
                      @if($userSub)
                        <span class="text-green-500 font-bold" title="{{ $userSub->created_at->format('h:i A') }}">✓</span>
                      @else
                        <span class="text-gray-300">—</span>
                      @endif
                    </td>
                  @endforeach
                  <td class="text-center py-2 px-2">
                    <span class="{{ $done === $tasks->count() ? 'text-green-600' : ($done > 0 ? 'text-amber-600' : 'text-red-400') }}">
                      {{ $done }}/{{ $tasks->count() }}
                    </span>
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      </div>
    @endif

  </div>
</x-layout>

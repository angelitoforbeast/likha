<x-layout>
  <x-slot name="heading">Daily Checklist</x-slot>
  <x-slot name="title">Daily Checklist</x-slot>

  <div class="min-h-screen bg-gray-50/60">

    {{-- ===== TOP HEADER ===== --}}
    <div class="bg-white border-b border-gray-200 shadow-sm">
      <div class="max-w-5xl mx-auto px-4 py-4 flex items-center justify-between gap-3 flex-wrap">
        <div>
          <h1 class="text-lg font-bold text-gray-800 leading-tight">Daily Checklist</h1>
          <p class="text-xs text-gray-400">{{ now()->format('l, F j, Y') }}</p>
        </div>

        <div class="flex items-center gap-3 flex-wrap">
          {{-- Progress --}}
          <div class="flex items-center gap-2">
            <div class="w-32 h-2 bg-gray-100 rounded-full overflow-hidden">
              <div class="h-2 rounded-full transition-all duration-500 {{ $doneCount === $totalTasks && $totalTasks > 0 ? 'bg-green-500' : 'bg-blue-500' }}"
                   style="width: {{ $totalTasks > 0 ? round($doneCount / $totalTasks * 100) : 0 }}%"></div>
            </div>
            <span class="text-xs font-semibold {{ $doneCount === $totalTasks && $totalTasks > 0 ? 'text-green-600' : 'text-gray-500' }}">
              {{ $doneCount }}/{{ $totalTasks }}
            </span>
          </div>

          <a href="{{ route('checklist.report') }}"
             class="text-xs px-3 py-1.5 rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50 transition">
            📋 Report
          </a>
          <a href="{{ route('checklist.manage') }}"
             class="text-xs px-3 py-1.5 rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50 transition">
            ⚙ Manage
          </a>
        </div>
      </div>
    </div>

    <div class="max-w-5xl mx-auto px-4 py-5 space-y-4">

      {{-- Alerts --}}
      @if(session('success'))
        <div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl text-sm flex items-center gap-2">
          <span class="text-green-500">✓</span> {{ session('success') }}
        </div>
      @endif
      @if(session('error'))
        <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-xl text-sm">{{ session('error') }}</div>
      @endif
      @if($errors->any())
        <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-xl text-sm space-y-0.5">
          @foreach($errors->all() as $e)<div>• {{ $e }}</div>@endforeach
        </div>
      @endif

      {{-- ===== TASK GRID ===== --}}
      @php $isCeo = Auth::user()?->employeeProfile?->role === 'CEO'; @endphp

      @if($tasks->isEmpty())
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-14 text-center">
          <p class="text-3xl mb-3">📋</p>
          <p class="text-gray-500 font-medium">No active tasks</p>
          <p class="text-sm text-gray-400 mt-1">
            Go to <a href="{{ route('checklist.manage') }}" class="text-blue-500 hover:underline">Manage Tasks</a> to add tasks.
          </p>
        </div>
      @else
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          @foreach($tasks as $task)
            @php
              $sub         = $submissionsByTask->get($task->id);
              $done        = $sub !== null;
              $isMine      = $sub && $sub->user_id === Auth::id();
              $assignedIds = $task->assignedUsers->pluck('id')->toArray();
              $isAssigned  = empty($assignedIds) || in_array(Auth::id(), $assignedIds);
              $canSubmit   = !$done && $isAssigned;
              $hasImage    = $done && $sub->file_path && $sub->isImage();
            @endphp

            <div
              x-data="{
                showForm: false,
                fileName: '',
                previewUrl: '',
                setFile(file) {
                  if (!file) return;
                  try {
                    const dt = new DataTransfer();
                    dt.items.add(file);
                    this.$refs.fileInput.files = dt.files;
                  } catch(e) {}
                  this.fileName = file.name || 'pasted-image.png';
                  this.previewUrl = file.type.startsWith('image/') ? URL.createObjectURL(file) : '';
                },
                handlePaste(e) {
                  if (!this.showForm) return;
                  const img = [...(e.clipboardData?.items || [])].find(i => i.type.startsWith('image/'));
                  if (img) { e.preventDefault(); this.setFile(img.getAsFile()); }
                }
              }"
              @paste.window="handlePaste($event)"
              class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden flex flex-col
                     {{ $done ? 'ring-1 ring-green-200' : ($canSubmit ? 'ring-1 ring-blue-100' : '') }}"
            >

              {{-- Colored top strip --}}
              <div class="h-1 w-full {{ $done ? 'bg-green-400' : ($canSubmit ? 'bg-blue-400' : 'bg-gray-200') }}"></div>

              {{-- ── CARD HEADER ── --}}
              <div class="px-4 pt-3 pb-2 flex items-start justify-between gap-2">
                <div class="flex items-start gap-2.5 min-w-0">
                  {{-- Status circle --}}
                  <div class="mt-0.5 w-5 h-5 rounded-full flex-shrink-0 flex items-center justify-center
                              {{ $done ? 'bg-green-500' : ($canSubmit ? 'bg-blue-100 border-2 border-blue-300' : 'bg-gray-100 border-2 border-gray-200') }}">
                    @if($done)
                      <svg class="w-3 h-3 text-white" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                      </svg>
                    @endif
                  </div>
                  <div class="min-w-0">
                    <p class="font-semibold text-gray-800 text-sm leading-snug">{{ $task->title }}</p>
                    @if($task->description)
                      <p class="text-xs text-gray-400 mt-0.5 leading-snug">{{ $task->description }}</p>
                    @endif
                    @if($task->assignedUsers->count())
                      <p class="text-xs text-indigo-400 mt-0.5">→ {{ $task->assignedUsers->pluck('name')->implode(', ') }}</p>
                    @endif
                  </div>
                </div>
                <div class="flex-shrink-0 flex items-center gap-1.5">
                  <span class="text-xs px-1.5 py-0.5 rounded-full
                    {{ $task->type === 'photo' ? 'bg-blue-50 text-blue-500' : ($task->type === 'note' ? 'bg-amber-50 text-amber-600' : 'bg-gray-100 text-gray-500') }}">
                    {{ $task->type === 'photo' ? '📸' : ($task->type === 'note' ? '📝' : '📎') }}
                  </span>
                  @if($done)
                    <span class="text-xs font-semibold text-green-600">Done</span>
                  @elseif($canSubmit)
                    <span class="text-xs font-medium text-blue-400">Pending</span>
                  @else
                    <span class="text-xs text-gray-300">—</span>
                  @endif
                </div>
              </div>

              {{-- ── SUBMITTED IMAGE (big, full width) ── --}}
              @if($hasImage)
                <a href="{{ Storage::url($sub->file_path) }}" target="_blank" class="block group mx-3 mb-2 rounded-xl overflow-hidden">
                  <img src="{{ Storage::url($sub->file_path) }}"
                       class="w-full object-cover max-h-56 group-hover:brightness-90 transition duration-200"
                       alt="{{ $sub->file_original_name }}">
                </a>
              @endif

              {{-- ── SUBMISSION BODY (notes + file) ── --}}
              @if($done)
                <div class="px-4 pb-2 flex-1">
                  @if($sub->notes)
                    <p class="text-sm text-gray-700 whitespace-pre-line leading-relaxed">{{ $sub->notes }}</p>
                  @endif
                  @if($sub->file_path && !$sub->isImage())
                    <a href="{{ Storage::url($sub->file_path) }}" target="_blank"
                       class="mt-2 inline-flex items-center gap-1.5 text-xs text-blue-600 hover:underline">
                      📎 {{ $sub->file_original_name }}
                    </a>
                  @endif
                  @if(!$sub->notes && !$sub->file_path)
                    <p class="text-xs text-gray-300 italic">No notes or file attached.</p>
                  @endif
                </div>

                {{-- ── SUBMITTER FOOTER ── --}}
                <div class="mt-auto border-t border-gray-100 px-4 py-2.5 flex items-center justify-between gap-2">
                  <div class="flex items-center gap-2">
                    <div class="w-6 h-6 rounded-full bg-indigo-100 flex items-center justify-center text-xs font-bold text-indigo-600 flex-shrink-0">
                      {{ strtoupper(substr($sub->user->name ?? '?', 0, 1)) }}
                    </div>
                    <div>
                      <p class="text-xs font-medium text-gray-700 leading-none">
                        {{ $sub->user->name ?? 'Unknown' }}
                        @if($isMine)<span class="text-gray-400 font-normal"> (you)</span>@endif
                      </p>
                      <p class="text-xs text-gray-400 leading-none mt-0.5">
                        {{ $sub->created_at->format('h:i A') }}
                        @if($sub->updated_at->gt($sub->created_at))
                          · updated {{ $sub->updated_at->format('h:i A') }}
                        @endif
                      </p>
                    </div>
                  </div>
                  @if($isMine || $isCeo)
                    <div class="flex gap-1 flex-shrink-0">
                      @if($isMine)
                        <button @click="showForm = !showForm"
                                class="text-xs px-2 py-1 rounded-lg border border-gray-200 text-gray-500 hover:bg-gray-50 transition">
                          Edit
                        </button>
                      @endif
                      <form method="POST" action="{{ route('checklist.delete-submission', $sub) }}"
                            onsubmit="return confirm('Remove this submission?')">
                        @csrf @method('DELETE')
                        <button type="submit"
                                class="text-xs px-2 py-1 rounded-lg border border-red-200 text-red-500 hover:bg-red-50 transition">
                          Remove
                        </button>
                      </form>
                    </div>
                  @endif
                </div>

              @elseif(!$isAssigned)
                {{-- NOT ASSIGNED --}}
                <div class="px-4 pb-4 flex-1">
                  <p class="text-xs text-gray-300">This task is assigned to someone else.</p>
                </div>

              @else
                {{-- PENDING: show submit button to open form --}}
                <div class="px-4 pb-3 flex-1 flex flex-col">
                  <button @click="showForm = !showForm"
                          x-show="!showForm"
                          class="w-full py-2 rounded-xl border-2 border-dashed border-blue-200 text-sm text-blue-400 hover:border-blue-400 hover:text-blue-600 hover:bg-blue-50/50 transition font-medium">
                    + Submit
                  </button>
                </div>
              @endif

              {{-- ── SUBMIT / EDIT FORM ── --}}
              @if($canSubmit || $isMine)
                <div x-show="showForm" x-transition class="border-t border-gray-100">
                  <form method="POST" action="{{ route('checklist.submit', $task) }}" enctype="multipart/form-data"
                        class="p-4 space-y-3">
                    @csrf

                    @if(in_array($task->type, ['note', 'any']))
                      <textarea name="notes" rows="3" placeholder="Notes / remarks..."
                                class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm resize-none focus:outline-none focus:ring-2 focus:ring-blue-300 text-gray-700">{{ $sub?->notes }}</textarea>
                    @endif

                    @if(in_array($task->type, ['photo', 'any']))
                      {{-- Drop / Paste / Click zone --}}
                      <div
                        x-ref="dropzone"
                        tabindex="0"
                        @click="$refs.fileInput.click()"
                        @keydown.enter.prevent="$refs.fileInput.click()"
                        @dragover.prevent="$el.classList.add('!border-blue-400','bg-blue-50')"
                        @dragleave.prevent="$el.classList.remove('!border-blue-400','bg-blue-50')"
                        @drop.prevent="
                          $el.classList.remove('!border-blue-400','bg-blue-50');
                          const file = $event.dataTransfer.files[0];
                          if (file) setFile(file);
                        "
                        class="relative border-2 border-dashed border-gray-200 rounded-xl p-5 text-center cursor-pointer hover:border-blue-300 hover:bg-blue-50/40 focus:outline-none focus:border-blue-400 transition-colors"
                      >
                        <template x-if="!previewUrl">
                          <div class="space-y-1 pointer-events-none select-none">
                            <p class="text-2xl">{{ $task->type === 'note' ? '📝' : '📸' }}</p>
                            <p class="text-sm font-medium text-gray-500">Click, drag, or <kbd class="px-1.5 py-0.5 text-xs bg-gray-100 border border-gray-300 rounded font-mono">Ctrl+V</kbd> to paste</p>
                            <p class="text-xs text-gray-300">
                              {{ $task->type === 'photo' ? 'Image required' : 'Image or document (optional)' }}
                            </p>
                          </div>
                        </template>
                        <template x-if="previewUrl">
                          <div class="pointer-events-none select-none">
                            <img :src="previewUrl" class="max-h-44 mx-auto rounded-lg object-cover shadow-sm">
                            <p class="text-xs text-gray-400 mt-2 truncate" x-text="fileName"></p>
                          </div>
                        </template>
                        <input
                          type="file"
                          x-ref="fileInput"
                          name="file"
                          class="hidden"
                          accept="{{ $task->type === 'photo' ? 'image/*' : 'image/*,.pdf,.doc,.docx,.xls,.xlsx,.csv' }}"
                          @change="
                            const f = $event.target.files[0];
                            if (f) setFile(f);
                          "
                        >
                      </div>

                      {{-- Clear button --}}
                      <template x-if="previewUrl || fileName">
                        <button type="button"
                                @click="previewUrl=''; fileName=''; $refs.fileInput.value='';"
                                class="text-xs text-gray-400 hover:text-red-500 transition">
                          ✕ Clear file
                        </button>
                      </template>
                    @endif

                    <div class="flex items-center gap-2 pt-1">
                      <button type="submit"
                              class="flex-1 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm rounded-xl font-semibold transition">
                        {{ $sub ? 'Update' : 'Submit' }}
                      </button>
                      <button type="button" @click="showForm = false"
                              class="px-4 py-2 text-sm text-gray-400 hover:text-gray-600 rounded-xl hover:bg-gray-100 transition">
                        Cancel
                      </button>
                    </div>
                  </form>
                </div>
              @endif

              {{-- Done, submitted by someone else --}}
              @if($done && !$isMine && !$isCeo)
                {{-- Already handled above, nothing extra needed --}}
              @endif

            </div>
          @endforeach
        </div>

        {{-- Bottom note --}}
        @if($doneCount === $totalTasks && $totalTasks > 0)
          <div class="text-center py-4">
            <p class="text-sm text-green-600 font-semibold">🎉 All tasks done for today!</p>
          </div>
        @endif

      @endif
    </div>
  </div>
</x-layout>

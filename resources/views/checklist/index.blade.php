<x-layout>
  <x-slot name="heading">Daily Checklist</x-slot>
  <x-slot name="title">Daily Checklist</x-slot>

  <div class="min-h-screen bg-gray-50">

    {{-- ===== HEADER ===== --}}
    <div class="bg-white border-b border-gray-200 shadow-sm">
      <div class="max-w-6xl mx-auto px-4 py-3 flex items-center justify-between gap-3 flex-wrap">
        <div>
          <h1 class="text-base font-bold text-gray-800">Daily Checklist</h1>
          <p class="text-xs text-gray-400">{{ now()->format('l, F j, Y') }}</p>
        </div>
        <div class="flex items-center gap-3">
          {{-- Progress --}}
          <div class="flex items-center gap-2">
            <div class="w-28 h-1.5 bg-gray-100 rounded-full overflow-hidden">
              <div class="h-1.5 rounded-full transition-all duration-500 {{ $doneCount === $totalTasks && $totalTasks > 0 ? 'bg-green-500' : 'bg-blue-500' }}"
                   style="width: {{ $totalTasks > 0 ? round($doneCount / $totalTasks * 100) : 0 }}%"></div>
            </div>
            <span class="text-xs font-semibold text-gray-500">{{ $doneCount }}/{{ $totalTasks }}</span>
          </div>
          <a href="{{ route('checklist.report') }}"
             class="text-xs px-3 py-1.5 rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50 transition">📋 Report</a>
          <a href="{{ route('checklist.manage') }}"
             class="text-xs px-3 py-1.5 rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50 transition">⚙ Manage</a>
        </div>
      </div>
    </div>

    <div class="max-w-6xl mx-auto px-4 py-5 space-y-4">

      {{-- Alerts --}}
      @if(session('success'))
        <div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl text-sm flex gap-2 items-center">
          <span class="text-green-500 font-bold">✓</span> {{ session('success') }}
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

      {{-- ===== TABLE ===== --}}
      @php $isCeo = Auth::user()?->employeeProfile?->role === 'CEO'; @endphp

      @if($tasks->isEmpty())
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-14 text-center">
          <p class="text-3xl mb-3">📋</p>
          <p class="text-gray-500 font-medium">No active tasks</p>
          <p class="text-sm text-gray-400 mt-1">Go to <a href="{{ route('checklist.manage') }}" class="text-blue-500 hover:underline">Manage Tasks</a> to add tasks.</p>
        </div>
      @else
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
          <div class="overflow-x-auto">
            <table class="w-full text-sm border-collapse">
              <thead>
                <tr class="border-b border-gray-100 bg-gray-50/80 text-xs text-gray-400 uppercase tracking-wide font-semibold">
                  <th class="text-left px-4 py-3 w-8"></th>
                  <th class="text-left px-3 py-3 min-w-[160px]">Task</th>
                  <th class="text-left px-3 py-3 min-w-[140px]">Description</th>
                  <th class="text-left px-3 py-3 w-[110px]">Image</th>
                  <th class="text-left px-3 py-3 min-w-[180px]">Notes</th>
                  <th class="text-left px-3 py-3 min-w-[130px]">Submitted by</th>
                  <th class="px-3 py-3 w-[90px]"></th>
                </tr>
              </thead>

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

                <tbody
                  x-data="{
                    showForm: false,
                    fileName: '',
                    previewUrl: '',
                    setFile(file) {
                      if (!file) return;
                      try { const dt = new DataTransfer(); dt.items.add(file); this.$refs.fileInput.files = dt.files; } catch(e) {}
                      this.fileName = file.name || 'pasted-image.png';
                      this.previewUrl = file.type.startsWith('image/') ? URL.createObjectURL(file) : '';
                    },
                    handlePaste(e) {
                      if (!this.showForm) return;
                      const img = [...(e.clipboardData?.items||[])].find(i => i.type.startsWith('image/'));
                      if (img) { e.preventDefault(); this.setFile(img.getAsFile()); }
                    }
                  }"
                  @paste.window="handlePaste($event)"
                >

                  {{-- ── DATA ROW ── --}}
                  <tr class="border-b border-gray-50 hover:bg-gray-50/50 transition-colors {{ $done ? 'bg-green-50/20' : '' }}">

                    {{-- Status dot --}}
                    <td class="px-4 py-3">
                      <div class="w-2.5 h-2.5 rounded-full mx-auto {{ $done ? 'bg-green-400' : ($canSubmit ? 'bg-amber-300' : 'bg-gray-200') }}"></div>
                    </td>

                    {{-- Task --}}
                    <td class="px-3 py-3">
                      <p class="font-semibold text-gray-800 leading-snug">{{ $task->title }}</p>
                      @if($task->assignedUsers->count())
                        <p class="text-xs text-indigo-400 mt-0.5">→ {{ $task->assignedUsers->pluck('name')->implode(', ') }}</p>
                      @endif
                      <span class="text-xs px-1.5 py-0.5 rounded-full mt-1 inline-block
                        {{ $task->type === 'photo' ? 'bg-blue-50 text-blue-500' : ($task->type === 'note' ? 'bg-amber-50 text-amber-500' : 'bg-gray-100 text-gray-400') }}">
                        {{ $task->type === 'photo' ? '📸' : ($task->type === 'note' ? '📝' : '📎') }}
                        {{ ucfirst($task->type) }}
                      </span>
                    </td>

                    {{-- Description --}}
                    <td class="px-3 py-3 text-gray-500 text-sm align-top">
                      {{ $task->description ?: '—' }}
                    </td>

                    {{-- Image --}}
                    <td class="px-3 py-3 align-middle">
                      @if($hasImage)
                        <a href="{{ Storage::url($sub->file_path) }}" target="_blank" class="block group">
                          <img src="{{ Storage::url($sub->file_path) }}"
                               class="w-20 h-14 object-cover rounded-lg border border-gray-100 group-hover:opacity-80 transition shadow-sm"
                               alt="{{ $sub->file_original_name }}">
                        </a>
                      @elseif($done && $sub->file_path)
                        <a href="{{ Storage::url($sub->file_path) }}" target="_blank"
                           class="inline-flex items-center gap-1 text-xs text-blue-500 hover:underline">
                          📎 <span class="truncate max-w-[80px]">{{ $sub->file_original_name }}</span>
                        </a>
                      @else
                        <span class="text-gray-200 text-lg">—</span>
                      @endif
                    </td>

                    {{-- Notes --}}
                    <td class="px-3 py-3 align-top">
                      @if($done && $sub->notes)
                        <p class="text-sm text-gray-600 leading-relaxed line-clamp-3">{{ $sub->notes }}</p>
                      @else
                        <span class="text-gray-200 text-lg">—</span>
                      @endif
                    </td>

                    {{-- Submitted by --}}
                    <td class="px-3 py-3 align-middle">
                      @if($done)
                        <div class="flex items-center gap-2">
                          <div class="w-7 h-7 rounded-full bg-indigo-100 flex items-center justify-center text-xs font-bold text-indigo-600 flex-shrink-0">
                            {{ strtoupper(substr($sub->user->name ?? '?', 0, 1)) }}
                          </div>
                          <div>
                            <p class="text-xs font-semibold text-gray-700 leading-none">{{ $sub->user->name ?? 'Unknown' }}</p>
                            <p class="text-xs text-gray-400 leading-none mt-0.5">{{ $sub->created_at->format('h:i A') }}</p>
                          </div>
                        </div>
                      @else
                        <span class="text-gray-200 text-lg">—</span>
                      @endif
                    </td>

                    {{-- Actions --}}
                    <td class="px-3 py-3 align-middle">
                      <div class="flex items-center gap-1 justify-end">
                        @if($canSubmit)
                          <button @click="showForm = !showForm"
                                  class="text-xs px-2.5 py-1.5 rounded-lg bg-blue-600 text-white hover:bg-blue-700 transition font-medium whitespace-nowrap">
                            + Submit
                          </button>
                        @endif
                        @if($done && $isMine)
                          <button @click="showForm = !showForm"
                                  class="text-xs px-2 py-1.5 rounded-lg border border-gray-200 text-gray-500 hover:bg-gray-50 transition">
                            Edit
                          </button>
                        @endif
                        @if($done && ($isMine || $isCeo))
                          <form method="POST" action="{{ route('checklist.delete-submission', $sub) }}"
                                onsubmit="return confirm('Remove this submission?')">
                            @csrf @method('DELETE')
                            <button type="submit"
                                    class="text-xs px-2 py-1.5 rounded-lg border border-red-200 text-red-400 hover:bg-red-50 transition">
                              ✕
                            </button>
                          </form>
                        @endif
                        @if(!$isAssigned && !$done)
                          <span class="text-xs text-gray-300 italic">Not yours</span>
                        @endif
                      </div>
                    </td>
                  </tr>

                  {{-- ── INLINE FORM ROW ── --}}
                  @if($canSubmit || $isMine)
                    <tr x-show="showForm" x-transition class="border-b border-blue-100 bg-blue-50/30">
                      <td colspan="7" class="px-6 py-4">
                        <form method="POST" action="{{ route('checklist.submit', $task) }}" enctype="multipart/form-data">
                          @csrf
                          <div class="flex gap-4 items-start flex-wrap">

                            {{-- Notes (if applicable) --}}
                            @if(in_array($task->type, ['note', 'any']))
                              <div class="flex-1 min-w-[200px]">
                                <label class="text-xs text-gray-400 mb-1 block">Notes</label>
                                <textarea name="notes" rows="3" placeholder="Add notes or remarks..."
                                          class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm resize-none focus:outline-none focus:ring-2 focus:ring-blue-300 bg-white">{{ $sub?->notes }}</textarea>
                              </div>
                            @endif

                            {{-- Upload zone (if applicable) --}}
                            @if(in_array($task->type, ['photo', 'any']))
                              <div class="flex-shrink-0">
                                <label class="text-xs text-gray-400 mb-1 block">
                                  {{ $task->type === 'photo' ? 'Photo (required)' : 'File (optional)' }}
                                </label>
                                <div
                                  tabindex="0"
                                  @click="$refs.fileInput.click()"
                                  @keydown.enter.prevent="$refs.fileInput.click()"
                                  @dragover.prevent="$el.classList.add('!border-blue-400','!bg-blue-50')"
                                  @dragleave.prevent="$el.classList.remove('!border-blue-400','!bg-blue-50')"
                                  @drop.prevent="
                                    $el.classList.remove('!border-blue-400','!bg-blue-50');
                                    const f = $event.dataTransfer.files[0];
                                    if (f) setFile(f);
                                  "
                                  class="relative w-44 h-28 border-2 border-dashed border-gray-200 rounded-xl flex flex-col items-center justify-center cursor-pointer hover:border-blue-300 hover:bg-blue-50/40 focus:outline-none focus:border-blue-400 transition-colors overflow-hidden"
                                >
                                  <template x-if="!previewUrl">
                                    <div class="text-center pointer-events-none select-none px-2">
                                      <p class="text-xl mb-1">{{ $task->type === 'photo' ? '📸' : '📎' }}</p>
                                      <p class="text-xs text-gray-400 leading-tight">Click, drag, or</p>
                                      <p class="text-xs font-semibold text-blue-400">Ctrl+V to paste</p>
                                    </div>
                                  </template>
                                  <template x-if="previewUrl">
                                    <div class="w-full h-full relative pointer-events-none">
                                      <img :src="previewUrl" class="w-full h-full object-cover">
                                      <div class="absolute bottom-0 left-0 right-0 bg-black/40 text-white text-xs truncate px-1.5 py-1" x-text="fileName"></div>
                                    </div>
                                  </template>
                                  <input type="file" x-ref="fileInput" name="file" class="hidden"
                                         accept="{{ $task->type === 'photo' ? 'image/*' : 'image/*,.pdf,.doc,.docx,.xls,.xlsx,.csv' }}"
                                         @change="const f=$event.target.files[0]; if(f) setFile(f);">
                                </div>
                                <template x-if="fileName">
                                  <button type="button" @click.stop="previewUrl=''; fileName=''; $refs.fileInput.value='';"
                                          class="text-xs text-gray-400 hover:text-red-500 mt-1 transition">✕ Clear</button>
                                </template>
                              </div>
                            @endif

                            {{-- Submit / Cancel --}}
                            <div class="flex flex-col gap-2 justify-end pt-5">
                              <button type="submit"
                                      class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm rounded-xl font-semibold transition whitespace-nowrap">
                                {{ $sub ? 'Update' : 'Submit' }}
                              </button>
                              <button type="button" @click="showForm = false"
                                      class="px-5 py-2 text-sm text-gray-400 hover:text-gray-600 rounded-xl hover:bg-gray-100 transition">
                                Cancel
                              </button>
                            </div>

                          </div>
                        </form>
                      </td>
                    </tr>
                  @endif

                </tbody>
              @endforeach

            </table>
          </div>

          {{-- Table footer --}}
          @if($doneCount === $totalTasks && $totalTasks > 0)
            <div class="px-6 py-3 border-t border-gray-100 bg-green-50/50 text-center">
              <p class="text-sm text-green-600 font-semibold">🎉 All tasks completed for today!</p>
            </div>
          @endif
        </div>
      @endif
    </div>
  </div>
</x-layout>

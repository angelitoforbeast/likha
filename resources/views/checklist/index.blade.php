<x-layout>
  <x-slot name="heading">Daily Checklist</x-slot>
  <x-slot name="title">Daily Checklist</x-slot>

  <div class="min-h-screen bg-gray-50"
       x-data="{ lightbox: false, lightSrc: '' }"
       @keydown.escape.window="lightbox = false">

    {{-- ===== HEADER ===== --}}
    <div class="bg-white border-b border-gray-200 shadow-sm">
      <div class="max-w-6xl mx-auto px-4 py-3 flex items-center justify-between gap-3 flex-wrap">
        <div>
          <h1 class="text-base font-bold text-gray-800">Daily Checklist</h1>
          <p class="text-xs text-gray-400">{{ now()->format('l, F j, Y') }}</p>
        </div>
        <div class="flex items-center gap-3">
          <div class="flex items-center gap-2">
            <div class="w-28 h-1.5 bg-gray-100 rounded-full overflow-hidden">
              <div class="h-1.5 rounded-full transition-all duration-500 {{ $doneCount === $totalTasks && $totalTasks > 0 ? 'bg-green-500' : 'bg-blue-500' }}"
                   style="width: {{ $totalTasks > 0 ? round($doneCount / $totalTasks * 100) : 0 }}%"></div>
            </div>
            <span class="text-xs font-semibold text-gray-500">{{ $doneCount }}/{{ $totalTasks }}</span>
          </div>
          <a href="{{ route('checklist.report') }}" class="text-xs px-3 py-1.5 rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50 transition">📋 Report</a>
          <a href="{{ route('checklist.manage') }}" class="text-xs px-3 py-1.5 rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50 transition">⚙ Manage</a>
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
                  <th class="text-left px-3 py-3 min-w-[130px]">Description</th>
                  <th class="text-left px-3 py-3 w-[160px]">Images</th>
                  <th class="text-left px-3 py-3 min-w-[180px]">Notes</th>
                  <th class="text-left px-3 py-3 min-w-[130px]">Submitted by</th>
                  <th class="px-3 py-3 w-[100px]"></th>
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
                  $subFiles    = $done ? $sub->files : collect();
                  $imageFiles  = $subFiles->filter(fn($f) => $f->isImage());
                  $otherFiles  = $subFiles->filter(fn($f) => !$f->isImage());
                @endphp

                <tbody
                  x-data="{
                    showForm: false,
                    queue: [],
                    addFiles(fileList) {
                      for (const f of fileList) {
                        this.queue.push({
                          name: f.name,
                          url: f.type.startsWith('image/') ? URL.createObjectURL(f) : '',
                          file: f,
                          isImg: f.type.startsWith('image/')
                        });
                      }
                      this.syncInput();
                    },
                    removeQueued(i) {
                      if (this.queue[i].url) URL.revokeObjectURL(this.queue[i].url);
                      this.queue.splice(i, 1);
                      this.syncInput();
                    },
                    syncInput() {
                      try {
                        const dt = new DataTransfer();
                        this.queue.forEach(q => dt.items.add(q.file));
                        this.$refs.fileInput.files = dt.files;
                      } catch(e) {}
                    },
                    handlePaste(e) {
                      if (!this.showForm) return;
                      const imgs = [...(e.clipboardData?.items||[])].filter(i => i.type.startsWith('image/'));
                      if (imgs.length) {
                        e.preventDefault();
                        this.addFiles(imgs.map(i => i.getAsFile()));
                      }
                    }
                  }"
                  @paste.window="handlePaste($event)"
                >

                  {{-- DATA ROW --}}
                  <tr class="border-b border-gray-50 hover:bg-gray-50/40 transition-colors">

                    {{-- Status --}}
                    <td class="px-4 py-3 align-middle">
                      <div class="w-2.5 h-2.5 rounded-full mx-auto {{ $done ? 'bg-green-400' : ($canSubmit ? 'bg-amber-300' : 'bg-gray-200') }}"></div>
                    </td>

                    {{-- Task --}}
                    <td class="px-3 py-3 align-top">
                      <p class="font-semibold text-gray-800 leading-snug">{{ $task->title }}</p>
                      @if($task->assignedUsers->count())
                        <p class="text-xs text-indigo-400 mt-0.5">→ {{ $task->assignedUsers->pluck('name')->implode(', ') }}</p>
                      @endif
                      <span class="text-xs px-1.5 py-0.5 rounded-full mt-1 inline-block
                        {{ $task->type === 'photo' ? 'bg-blue-50 text-blue-500' : ($task->type === 'note' ? 'bg-amber-50 text-amber-500' : ($task->type === 'both' ? 'bg-purple-50 text-purple-500' : 'bg-gray-100 text-gray-400')) }}">
                        {{ $task->type === 'photo' ? '📸' : ($task->type === 'note' ? '📝' : ($task->type === 'both' ? '📸📝' : '📎')) }}
                        {{ $task->type === 'both' ? 'Photo + Note' : ucfirst($task->type) }}
                      </span>
                    </td>

                    {{-- Description --}}
                    <td class="px-3 py-3 text-gray-400 text-sm align-top">
                      {{ $task->description ?: '—' }}
                    </td>

                    {{-- Images --}}
                    <td class="px-3 py-3 align-top">
                      @if($imageFiles->count() > 0)
                        <div class="flex flex-wrap gap-1">
                          @foreach($imageFiles as $f)
                            <div class="relative group">
                              <img src="{{ Storage::url($f->file_path) }}"
                                   @click="lightSrc='{{ Storage::url($f->file_path) }}'; lightbox=true"
                                   class="w-14 h-14 object-cover rounded-lg border border-gray-100 hover:opacity-80 transition shadow-sm cursor-zoom-in"
                                   alt="{{ $f->file_original_name }}">
                              @if($isMine || $isCeo)
                                <form method="POST" action="{{ route('checklist.delete-file', $f) }}"
                                      onsubmit="return confirm('Remove this image?')"
                                      class="absolute -top-1.5 -right-1.5 transition"
                                      x-show="showForm">
                                  @csrf @method('DELETE')
                                  <button type="submit"
                                          class="w-5 h-5 bg-red-500 hover:bg-red-600 text-white rounded-full text-xs flex items-center justify-center shadow leading-none">✕</button>
                                </form>
                              @endif
                            </div>
                          @endforeach
                        </div>
                        @foreach($otherFiles as $f)
                          <div class="relative group inline-flex items-center gap-1 mt-1">
                            <a href="{{ Storage::url($f->file_path) }}" target="_blank"
                               class="text-xs text-blue-500 hover:underline flex items-center gap-1">
                              📎 <span class="truncate max-w-[80px]">{{ $f->file_original_name }}</span>
                            </a>
                            @if($isMine || $isCeo)
                              <form method="POST" action="{{ route('checklist.delete-file', $f) }}"
                                    onsubmit="return confirm('Remove this file?')"
                                    x-show="showForm">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-xs text-red-400 hover:text-red-600">✕</button>
                              </form>
                            @endif
                          </div>
                        @endforeach
                      @elseif($done && $sub->file_path)
                        {{-- Legacy single file fallback --}}
                        @if($sub->isImage())
                          <img src="{{ Storage::url($sub->file_path) }}"
                               @click="lightSrc='{{ Storage::url($sub->file_path) }}'; lightbox=true"
                               class="w-14 h-14 object-cover rounded-lg border border-gray-100 hover:opacity-80 transition cursor-zoom-in">
                        @else
                          <a href="{{ Storage::url($sub->file_path) }}" target="_blank"
                             class="text-xs text-blue-500 hover:underline">📎 {{ $sub->file_original_name }}</a>
                        @endif
                      @else
                        <span class="text-gray-200">—</span>
                      @endif
                    </td>

                    {{-- Notes --}}
                    <td class="px-3 py-3 align-top">
                      @if($done && $sub->notes)
                        <p class="text-sm text-gray-600 leading-relaxed line-clamp-3">{{ $sub->notes }}</p>
                      @else
                        <span class="text-gray-200">—</span>
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
                        <span class="text-gray-200">—</span>
                      @endif
                    </td>

                    {{-- Actions --}}
                    <td class="px-3 py-3 align-middle">
                      <div class="flex items-center gap-1 justify-end">
                        @if($canSubmit)
                          <button @click="showForm = !showForm"
                                  class="text-xs px-2.5 py-1.5 rounded-lg bg-blue-600 text-white hover:bg-blue-700 transition font-medium">
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
                                onsubmit="return confirm('Remove entire submission?')">
                            @csrf @method('DELETE')
                            <button type="submit"
                                    class="text-xs px-2 py-1.5 rounded-lg border border-red-200 text-red-400 hover:bg-red-50 transition">✕</button>
                          </form>
                        @endif
                        @if(!$isAssigned && !$done)
                          <span class="text-xs text-gray-300 italic">Not yours</span>
                        @endif
                      </div>
                    </td>
                  </tr>

                  {{-- INLINE FORM ROW --}}
                  @if($canSubmit || $isMine)
                    <tr x-show="showForm" x-transition class="border-b border-blue-100 bg-blue-50/20">
                      <td colspan="7" class="px-6 py-4">
                        <form method="POST" action="{{ route('checklist.submit', $task) }}" enctype="multipart/form-data">
                          @csrf
                          <div class="flex gap-4 items-start flex-wrap">

                            {{-- Notes --}}
                            @if(in_array($task->type, ['note', 'any', 'both']))
                              <div class="flex-1 min-w-[200px]">
                                <label class="text-xs text-gray-400 mb-1 block font-medium">
                                  Notes {{ $task->type === 'both' ? '<span class="text-red-400">*</span>' : '' }}
                                </label>
                                <textarea name="notes" rows="3" placeholder="Add notes or remarks..."
                                          class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm resize-none focus:outline-none focus:ring-2 focus:ring-blue-300 bg-white">{{ $sub?->notes }}</textarea>
                              </div>
                            @endif

                            {{-- Upload zone --}}
                            @if(in_array($task->type, ['photo', 'any', 'both']))
                              <div class="flex-shrink-0 min-w-[240px]">
                                <label class="text-xs text-gray-400 mb-1 block font-medium">
                                  @if($task->type === 'both') Photos <span class="text-red-400">*</span>
                                  @elseif($task->type === 'photo') Photos (required)
                                  @else Images / Files (optional)
                                  @endif
                                  <span class="text-gray-300">· up to 10 files</span>
                                </label>

                                {{-- Drop zone --}}
                                <div
                                  @click="$refs.fileInput.click()"
                                  @dragover.prevent="$el.classList.add('!border-blue-400','!bg-blue-50')"
                                  @dragleave.prevent="$el.classList.remove('!border-blue-400','!bg-blue-50')"
                                  @drop.prevent="
                                    $el.classList.remove('!border-blue-400','!bg-blue-50');
                                    addFiles($event.dataTransfer.files);
                                  "
                                  class="border-2 border-dashed border-gray-200 rounded-xl p-4 text-center cursor-pointer hover:border-blue-300 hover:bg-blue-50/40 transition-colors mb-2"
                                >
                                  <p class="text-xl mb-1">📸</p>
                                  <p class="text-xs text-gray-400">Click, drag, or <span class="font-semibold text-blue-400">Ctrl+V</span> to paste</p>
                                  <p class="text-xs text-gray-300 mt-0.5">Multiple files supported</p>
                                </div>

                                {{-- Hidden input --}}
                                <input type="file" x-ref="fileInput" name="files[]" class="hidden"
                                       multiple
                                       accept="{{ in_array($task->type, ['photo','both']) ? 'image/*' : 'image/*,.pdf,.doc,.docx,.xls,.xlsx,.csv' }}"
                                       @change="addFiles($event.target.files); $event.target.value='';">

                                {{-- Preview queue --}}
                                <template x-if="queue.length > 0">
                                  <div class="flex flex-wrap gap-2 mt-2">
                                    <template x-for="(item, i) in queue" :key="i">
                                      <div class="relative group">
                                        <template x-if="item.isImg">
                                          <img :src="item.url" class="w-16 h-16 object-cover rounded-lg border border-gray-200 shadow-sm">
                                        </template>
                                        <template x-if="!item.isImg">
                                          <div class="w-16 h-16 flex flex-col items-center justify-center rounded-lg border border-gray-200 bg-gray-50 text-xs text-gray-400 text-center px-1">
                                            <span class="text-xl">📎</span>
                                            <span class="truncate w-full text-center leading-tight" x-text="item.name.split('.').pop().toUpperCase()"></span>
                                          </div>
                                        </template>
                                        <button type="button" @click.stop="removeQueued(i)"
                                                class="absolute -top-1.5 -right-1.5 w-5 h-5 bg-red-500 hover:bg-red-600 text-white rounded-full text-xs flex items-center justify-center shadow opacity-0 group-hover:opacity-100 transition">✕</button>
                                      </div>
                                    </template>
                                    <button type="button" @click="$refs.fileInput.click()"
                                            class="w-16 h-16 border-2 border-dashed border-gray-200 rounded-lg flex items-center justify-center text-gray-300 hover:border-blue-300 hover:text-blue-400 transition text-2xl">+</button>
                                  </div>
                                </template>
                              </div>
                            @endif

                            {{-- Buttons --}}
                            <div class="flex flex-col gap-2 justify-end" style="padding-top: 1.35rem">
                              <button type="submit"
                                      class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm rounded-xl font-semibold transition">
                                {{ $sub ? 'Update' : 'Submit' }}
                              </button>
                              <button type="button" @click="showForm = false; queue = [];"
                                      class="px-5 py-2 text-sm text-gray-400 hover:text-gray-600 rounded-xl hover:bg-gray-100 transition text-center">
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

          @if($doneCount === $totalTasks && $totalTasks > 0)
            <div class="px-6 py-3 border-t border-gray-100 bg-green-50/50 text-center">
              <p class="text-sm text-green-600 font-semibold">🎉 All tasks completed for today!</p>
            </div>
          @endif
        </div>
      @endif
    </div>
  </div>

  {{-- ===== LIGHTBOX ===== --}}
  <div x-show="lightbox"
       x-transition.opacity
       @click="lightbox = false"
       class="fixed inset-0 z-50 bg-black/90 flex items-center justify-center p-4 cursor-zoom-out"
       style="display:none">
    <button @click="lightbox = false"
            class="absolute top-4 right-4 w-9 h-9 bg-white/10 hover:bg-white/20 text-white rounded-full flex items-center justify-center text-lg transition">✕</button>
    <img :src="lightSrc"
         class="max-w-full max-h-full rounded-xl shadow-2xl object-contain cursor-default"
         @click.stop>
  </div>

</x-layout>

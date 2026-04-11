<x-layout>
  <x-slot name="heading">Daily Report</x-slot>
  <x-slot name="title">Daily Report</x-slot>

  <div class="min-h-screen bg-slate-50"
       x-data="{ lightbox: false, lightSrc: '' }"
       @keydown.escape.window="lightbox = false">

    {{-- ===== STICKY HEADER ===== --}}
    <div class="sticky top-0 z-30 bg-white/95 backdrop-blur border-b border-gray-100 shadow-sm">
      <div class="max-w-5xl mx-auto px-4 py-0">
        <div class="flex items-stretch gap-0 divide-x divide-gray-100">

          {{-- Prev day --}}
          <a href="{{ route('checklist.report', ['date' => $prevDate]) }}"
             class="flex items-center px-4 py-3.5 text-gray-400 hover:text-gray-700 hover:bg-gray-50 transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
          </a>

          {{-- Date --}}
          <div class="flex-1 flex items-center justify-center gap-3 px-4 py-3">
            <div class="text-center">
              <p class="font-bold text-gray-800 text-sm leading-tight">{{ $dateObj->format('l, F j, Y') }}</p>
              @if($isToday)
                <span class="inline-block text-xs bg-green-100 text-green-700 font-semibold px-2 py-0.5 rounded-full leading-none mt-0.5">Today</span>
              @endif
            </div>
          </div>

          {{-- Next day --}}
          <a href="{{ route('checklist.report', ['date' => $nextDate]) }}"
             class="flex items-center px-4 py-3.5 transition {{ $isToday ? 'text-gray-200 pointer-events-none' : 'text-gray-400 hover:text-gray-700 hover:bg-gray-50' }}">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
          </a>

          {{-- Progress + back --}}
          <div class="flex items-center gap-3 px-4 py-3">
            <div class="flex items-center gap-2">
              @php $pct = $totalTasks > 0 ? round($doneCount / $totalTasks * 100) : 0; @endphp
              <svg class="w-8 h-8 -rotate-90" viewBox="0 0 36 36">
                <circle cx="18" cy="18" r="15.9" fill="none" stroke="#e5e7eb" stroke-width="3"/>
                <circle cx="18" cy="18" r="15.9" fill="none"
                        stroke="{{ $doneCount === $totalTasks && $totalTasks > 0 ? '#22c55e' : '#3b82f6' }}"
                        stroke-width="3"
                        stroke-dasharray="{{ $pct }}, 100"
                        stroke-linecap="round"/>
              </svg>
              <div class="leading-none">
                <p class="text-sm font-bold {{ $doneCount === $totalTasks && $totalTasks > 0 ? 'text-green-600' : 'text-gray-700' }}">{{ $doneCount }}/{{ $totalTasks }}</p>
                <p class="text-xs text-gray-400">tasks</p>
              </div>
            </div>
            <a href="{{ route('checklist.index') }}"
               class="text-xs px-3 py-1.5 rounded-lg border border-gray-200 text-gray-500 hover:bg-gray-50 transition whitespace-nowrap">
              ← Back
            </a>
          </div>

        </div>
      </div>
    </div>

    {{-- ===== CONTENT ===== --}}
    <div class="max-w-5xl mx-auto px-4 py-6 space-y-4">

      @if($totalTasks === 0)
        <div class="text-center py-24 text-gray-400">
          <p class="text-5xl mb-4">📋</p>
          <p class="font-semibold text-lg text-gray-500">No active tasks</p>
          <p class="text-sm mt-1">Add tasks in <a href="{{ route('checklist.manage') }}" class="text-blue-500 hover:underline">Manage Tasks</a>.</p>
        </div>
      @else

        {{-- Summary strip --}}
        <div class="grid grid-cols-3 gap-3">
          <div class="bg-white rounded-xl border border-gray-100 shadow-sm px-4 py-3 text-center">
            <p class="text-2xl font-bold {{ $doneCount > 0 ? 'text-green-600' : 'text-gray-300' }}">{{ $doneCount }}</p>
            <p class="text-xs text-gray-400 mt-0.5">Completed</p>
          </div>
          <div class="bg-white rounded-xl border border-gray-100 shadow-sm px-4 py-3 text-center">
            <p class="text-2xl font-bold {{ ($totalTasks - $doneCount) > 0 ? 'text-amber-500' : 'text-gray-300' }}">{{ $totalTasks - $doneCount }}</p>
            <p class="text-xs text-gray-400 mt-0.5">Pending</p>
          </div>
          <div class="bg-white rounded-xl border border-gray-100 shadow-sm px-4 py-3 text-center">
            <p class="text-2xl font-bold text-gray-700">{{ $totalTasks }}</p>
            <p class="text-xs text-gray-400 mt-0.5">Total Tasks</p>
          </div>
        </div>

        {{-- Task cards --}}
        @foreach($tasks as $task)
          @php
            $sub        = $submissionsByTask->get($task->id);
            $done       = $sub !== null;
            $subFiles   = $done ? $sub->files : collect();
            $imgFiles   = $subFiles->filter(fn($f) => $f->isImage());
            $otherFiles = $subFiles->filter(fn($f) => !$f->isImage());
            $hasNewFiles = $subFiles->count() > 0;
          @endphp

          <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">

            {{-- ── TASK HEADER ── --}}
            <div class="flex items-center justify-between px-5 py-3 border-b border-gray-100">
              <div class="flex items-center gap-2.5 flex-wrap min-w-0">
                <div class="w-2 h-2 rounded-full flex-shrink-0 {{ $done ? 'bg-green-500' : 'bg-gray-300' }}"></div>
                <h2 class="font-semibold text-gray-800">{{ $task->title }}</h2>
                @if($task->description)
                  <span class="text-xs text-gray-400 hidden sm:inline">{{ $task->description }}</span>
                @endif
                <span class="text-xs px-2 py-0.5 rounded-full
                  {{ $task->type === 'photo' ? 'bg-blue-50 text-blue-600' : ($task->type === 'note' ? 'bg-amber-50 text-amber-600' : 'bg-gray-100 text-gray-500') }}">
                  {{ $task->type === 'photo' ? '📸' : ($task->type === 'note' ? '📝' : '📎') }}
                  {{ ucfirst($task->type) }}
                </span>
                @if($task->assignedUsers->count())
                  <span class="text-xs text-indigo-400">→ {{ $task->assignedUsers->pluck('name')->implode(', ') }}</span>
                @endif
              </div>
              @if($done)
                <span class="flex items-center gap-1 text-xs font-semibold text-green-600 flex-shrink-0 ml-2">
                  <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                  Done
                </span>
              @else
                <span class="text-xs text-gray-300 flex-shrink-0 ml-2 italic">No submission</span>
              @endif
            </div>

            @if($done)
              {{-- ── IMAGES ── --}}
              @if($imgFiles->count() === 1)
                <div class="cursor-zoom-in" @click="lightSrc='{{ Storage::url($imgFiles->first()->file_path) }}'; lightbox=true">
                  <img src="{{ Storage::url($imgFiles->first()->file_path) }}"
                       class="w-full object-cover max-h-[420px] hover:brightness-95 transition duration-200"
                       alt="{{ $imgFiles->first()->file_original_name }}">
                </div>

              @elseif($imgFiles->count() === 2)
                <div class="grid grid-cols-2 gap-0.5">
                  @foreach($imgFiles as $f)
                    <div class="cursor-zoom-in aspect-video overflow-hidden"
                         @click="lightSrc='{{ Storage::url($f->file_path) }}'; lightbox=true">
                      <img src="{{ Storage::url($f->file_path) }}"
                           class="w-full h-full object-cover hover:brightness-95 transition duration-200">
                    </div>
                  @endforeach
                </div>

              @elseif($imgFiles->count() === 3)
                <div class="grid grid-cols-3 gap-0.5">
                  @foreach($imgFiles as $f)
                    <div class="cursor-zoom-in aspect-square overflow-hidden"
                         @click="lightSrc='{{ Storage::url($f->file_path) }}'; lightbox=true">
                      <img src="{{ Storage::url($f->file_path) }}"
                           class="w-full h-full object-cover hover:brightness-95 transition duration-200">
                    </div>
                  @endforeach
                </div>

              @elseif($imgFiles->count() >= 4)
                @php $mainImg = $imgFiles->first(); $rest = $imgFiles->skip(1); $extraCount = max(0, $imgFiles->count() - 4); @endphp
                <div class="grid grid-cols-3 gap-0.5">
                  {{-- First image — spans 2 rows --}}
                  <div class="row-span-2 cursor-zoom-in overflow-hidden"
                       style="aspect-ratio: 1/1"
                       @click="lightSrc='{{ Storage::url($mainImg->file_path) }}'; lightbox=true">
                    <img src="{{ Storage::url($mainImg->file_path) }}"
                         class="w-full h-full object-cover hover:brightness-95 transition duration-200" style="height:100%">
                  </div>
                  {{-- Rest (up to 3 more) --}}
                  @foreach($rest->take(3) as $i => $f)
                    <div class="cursor-zoom-in overflow-hidden relative"
                         style="aspect-ratio:1/1"
                         @click="lightSrc='{{ Storage::url($f->file_path) }}'; lightbox=true">
                      <img src="{{ Storage::url($f->file_path) }}"
                           class="w-full h-full object-cover hover:brightness-95 transition duration-200">
                      @if($loop->last && $extraCount > 0)
                        <div class="absolute inset-0 bg-black/50 flex items-center justify-center">
                          <span class="text-white font-bold text-xl">+{{ $extraCount }}</span>
                        </div>
                      @endif
                    </div>
                  @endforeach
                </div>

              @elseif(!$hasNewFiles && $sub->file_path && $sub->isImage())
                {{-- Legacy --}}
                <div class="cursor-zoom-in" @click="lightSrc='{{ Storage::url($sub->file_path) }}'; lightbox=true">
                  <img src="{{ Storage::url($sub->file_path) }}"
                       class="w-full object-cover max-h-[420px] hover:brightness-95 transition duration-200">
                </div>
              @endif

              {{-- ── NON-IMAGE FILES ── --}}
              @if($otherFiles->count() > 0 || (!$hasNewFiles && $sub->file_path && !$sub->isImage()))
                <div class="px-5 py-3 border-b border-gray-100 flex flex-wrap gap-2">
                  @if($otherFiles->count() > 0)
                    @foreach($otherFiles as $f)
                      <a href="{{ Storage::url($f->file_path) }}" target="_blank"
                         class="inline-flex items-center gap-2 text-sm text-blue-600 hover:text-blue-800 bg-blue-50 px-3 py-1.5 rounded-lg hover:bg-blue-100 transition">
                        <span>📎</span> {{ $f->file_original_name }}
                      </a>
                    @endforeach
                  @elseif(!$hasNewFiles && $sub->file_path && !$sub->isImage())
                    <a href="{{ Storage::url($sub->file_path) }}" target="_blank"
                       class="inline-flex items-center gap-2 text-sm text-blue-600 hover:text-blue-800 bg-blue-50 px-3 py-1.5 rounded-lg hover:bg-blue-100 transition">
                      <span>📎</span> {{ $sub->file_original_name }}
                    </a>
                  @endif
                </div>
              @endif

              {{-- ── NOTES ── --}}
              @if($sub->notes)
                <div class="px-5 py-4">
                  <div class="border-l-4 border-gray-200 pl-4">
                    <p class="text-sm text-gray-700 whitespace-pre-line leading-relaxed">{{ $sub->notes }}</p>
                  </div>
                </div>
              @endif

              {{-- No content --}}
              @if(!$hasNewFiles && !$sub->file_path && !$sub->notes)
                <div class="px-5 py-4">
                  <p class="text-sm text-gray-400 italic">Marked as done — no notes or file attached.</p>
                </div>
              @endif

              {{-- ── SUBMITTED BY ── --}}
              <div class="flex items-center justify-between px-5 py-3 bg-gray-50 border-t border-gray-100">
                <div class="flex items-center gap-2.5">
                  <div class="w-7 h-7 rounded-full bg-indigo-100 flex items-center justify-center text-xs font-bold text-indigo-600 flex-shrink-0">
                    {{ strtoupper(substr($sub->user->name ?? '?', 0, 1)) }}
                  </div>
                  <div>
                    <p class="text-xs font-semibold text-gray-700 leading-none">{{ $sub->user->name ?? 'Unknown' }}</p>
                    <p class="text-xs text-gray-400 leading-none mt-0.5">
                      {{ $sub->created_at->format('h:i A') }}
                      @if($sub->updated_at->gt($sub->created_at))
                        · updated {{ $sub->updated_at->format('h:i A') }}
                      @endif
                    </p>
                  </div>
                </div>
                <span class="text-xs text-gray-300">{{ $sub->created_at->diffForHumans() }}</span>
              </div>

            @else
              {{-- ── NOT SUBMITTED ── --}}
              <div class="px-5 py-6 flex items-center gap-3 text-gray-300">
                <div class="w-10 h-10 rounded-full border-2 border-dashed border-gray-200 flex items-center justify-center text-lg flex-shrink-0">
                  {{ $task->type === 'photo' ? '📸' : ($task->type === 'note' ? '📝' : '📎') }}
                </div>
                <div>
                  <p class="text-sm font-medium text-gray-400">Not yet submitted</p>
                  @if($task->assignedUsers->count())
                    <p class="text-xs text-gray-300">Assigned to {{ $task->assignedUsers->pluck('name')->implode(', ') }}</p>
                  @endif
                </div>
              </div>
            @endif

          </div>
        @endforeach

        {{-- Footer --}}
        <div class="text-center py-6">
          @if($doneCount === $totalTasks && $totalTasks > 0)
            <p class="text-sm font-semibold text-green-600">🎉 All tasks completed for {{ $dateObj->format('F j') }}!</p>
          @else
            <p class="text-sm text-gray-400">
              {{ $totalTasks - $doneCount }} {{ Str::plural('task', $totalTasks - $doneCount) }} still pending
            </p>
          @endif
        </div>

      @endif
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

  </div>
</x-layout>

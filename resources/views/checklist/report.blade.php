<x-layout>
  <x-slot name="heading">Checklist Report</x-slot>
  <x-slot name="title">Checklist Report</x-slot>

  <div class="min-h-screen bg-gray-50">

    {{-- ===== STICKY HEADER ===== --}}
    <div class="sticky top-0 z-20 bg-white border-b border-gray-200 shadow-sm">
      <div class="max-w-4xl mx-auto px-4 py-3 flex items-center justify-between gap-3 flex-wrap">

        {{-- Date nav --}}
        <div class="flex items-center gap-2">
          <a href="{{ route('checklist.report', ['date' => $prevDate]) }}"
             class="p-1.5 rounded-lg hover:bg-gray-100 text-gray-500 transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
          </a>
          <div class="text-center">
            <p class="text-sm font-bold text-gray-800">{{ $dateObj->format('l, F j, Y') }}</p>
            @if($isToday)
              <p class="text-xs text-green-600 font-medium leading-none">Today</p>
            @endif
          </div>
          <a href="{{ route('checklist.report', ['date' => $nextDate]) }}"
             class="p-1.5 rounded-lg hover:bg-gray-100 text-gray-500 transition {{ $isToday ? 'opacity-30 pointer-events-none' : '' }}">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
          </a>
        </div>

        {{-- Progress pill --}}
        <div class="flex items-center gap-3">
          <div class="flex items-center gap-2 bg-gray-100 rounded-full px-3 py-1.5">
            <div class="flex gap-0.5">
              @for($i = 0; $i < $totalTasks; $i++)
                <div class="w-2 h-2 rounded-full {{ $i < $doneCount ? 'bg-green-500' : 'bg-gray-300' }}"></div>
              @endfor
            </div>
            <span class="text-xs font-semibold {{ $doneCount === $totalTasks && $totalTasks > 0 ? 'text-green-700' : 'text-gray-600' }}">
              {{ $doneCount }}/{{ $totalTasks }}
            </span>
          </div>

          <a href="{{ route('checklist.index') }}"
             class="text-xs px-3 py-1.5 rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50 transition">
            ← Checklist
          </a>
        </div>
      </div>
    </div>

    {{-- ===== CONTENT ===== --}}
    <div class="max-w-4xl mx-auto px-4 py-6 space-y-5">

      @if($totalTasks === 0)
        <div class="text-center py-20 text-gray-400">
          <p class="text-4xl mb-3">📋</p>
          <p class="font-medium">No active tasks</p>
          <p class="text-sm mt-1">Add tasks in <a href="{{ route('checklist.manage') }}" class="text-blue-500 hover:underline">Manage Tasks</a>.</p>
        </div>
      @else
        @foreach($tasks as $task)
          @php
            $sub  = $submissionsByTask->get($task->id);
            $done = $sub !== null;
          @endphp

          <div class="bg-white rounded-2xl shadow-sm border {{ $done ? 'border-green-100' : 'border-gray-100' }} overflow-hidden">

            {{-- Card top bar --}}
            <div class="flex items-center justify-between px-5 py-3.5 border-b {{ $done ? 'border-green-100 bg-green-50/50' : 'border-gray-100 bg-gray-50/60' }}">
              <div class="flex items-center gap-2.5 flex-wrap">
                {{-- Status dot --}}
                <div class="w-2.5 h-2.5 rounded-full flex-shrink-0 {{ $done ? 'bg-green-500' : 'bg-gray-300' }}"></div>

                <span class="font-semibold text-gray-800">{{ $task->title }}</span>

                <span class="text-xs px-2 py-0.5 rounded-full font-medium
                  {{ $task->type === 'photo' ? 'bg-blue-100 text-blue-700' : ($task->type === 'note' ? 'bg-amber-100 text-amber-700' : 'bg-gray-100 text-gray-600') }}">
                  {{ $task->type === 'photo' ? '📸 Photo' : ($task->type === 'note' ? '📝 Note' : '📎 Any') }}
                </span>

                @if($task->description)
                  <span class="text-xs text-gray-400">{{ $task->description }}</span>
                @endif
              </div>

              @if($done)
                <span class="text-xs font-semibold text-green-600 flex items-center gap-1 flex-shrink-0">
                  <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                  Done
                </span>
              @else
                <span class="text-xs font-medium text-gray-400 flex-shrink-0">No submission</span>
              @endif
            </div>

            @if($done)
              {{-- ===== SUBMITTED CONTENT ===== --}}
              <div>
                {{-- Photo — full width, prominent --}}
                @if($sub->file_path && $sub->isImage())
                  <a href="{{ Storage::url($sub->file_path) }}" target="_blank" class="block group relative">
                    <img src="{{ Storage::url($sub->file_path) }}"
                         class="w-full object-cover max-h-96 group-hover:opacity-95 transition duration-200"
                         alt="{{ $sub->file_original_name }}">
                    <div class="absolute inset-0 bg-black/0 group-hover:bg-black/10 transition duration-200 flex items-center justify-center">
                      <span class="opacity-0 group-hover:opacity-100 transition bg-black/50 text-white text-xs px-3 py-1.5 rounded-full">
                        View full size ↗
                      </span>
                    </div>
                  </a>
                @endif

                {{-- Non-image file --}}
                @if($sub->file_path && !$sub->isImage())
                  <div class="px-5 py-3 border-b border-gray-100">
                    <a href="{{ Storage::url($sub->file_path) }}" target="_blank"
                       class="inline-flex items-center gap-2 text-sm text-blue-600 hover:text-blue-700 font-medium hover:underline">
                      <span class="w-8 h-8 bg-blue-100 rounded-lg flex items-center justify-center text-base flex-shrink-0">📎</span>
                      {{ $sub->file_original_name }}
                      <svg class="w-3.5 h-3.5 opacity-60" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                    </a>
                  </div>
                @endif

                {{-- Notes --}}
                @if($sub->notes)
                  <div class="px-5 py-4 {{ $sub->file_path ? 'border-t border-gray-100' : '' }}">
                    <p class="text-sm text-gray-700 whitespace-pre-line leading-relaxed">{{ $sub->notes }}</p>
                  </div>
                @endif

                {{-- No content message --}}
                @if(!$sub->file_path && !$sub->notes)
                  <div class="px-5 py-4">
                    <p class="text-sm text-gray-400 italic">Marked as done — no notes or file attached.</p>
                  </div>
                @endif

                {{-- Submitted by footer --}}
                <div class="flex items-center justify-between px-5 py-3 bg-gray-50/80 border-t border-gray-100">
                  <div class="flex items-center gap-2.5">
                    <div class="w-7 h-7 rounded-full bg-indigo-100 flex items-center justify-center text-xs font-bold text-indigo-600 flex-shrink-0">
                      {{ strtoupper(substr($sub->user->name ?? '?', 0, 1)) }}
                    </div>
                    <div>
                      <p class="text-xs font-semibold text-gray-700">{{ $sub->user->name ?? 'Unknown' }}</p>
                      <p class="text-xs text-gray-400 leading-none mt-0.5">{{ $sub->created_at->format('h:i A') }}
                        @if($sub->updated_at->gt($sub->created_at))
                          · updated {{ $sub->updated_at->format('h:i A') }}
                        @endif
                      </p>
                    </div>
                  </div>
                  <span class="text-xs text-gray-400">{{ $sub->created_at->diffForHumans() }}</span>
                </div>
              </div>

            @else
              {{-- ===== NO SUBMISSION ===== --}}
              <div class="px-5 py-8 flex flex-col items-center justify-center text-center text-gray-300">
                <div class="w-12 h-12 rounded-full border-2 border-dashed border-gray-200 flex items-center justify-center mb-3">
                  <span class="text-xl">
                    {{ $task->type === 'photo' ? '📸' : ($task->type === 'note' ? '📝' : '📎') }}
                  </span>
                </div>
                <p class="text-sm font-medium text-gray-400">Not yet submitted</p>
                @if($task->assignedUsers->count())
                  <p class="text-xs text-gray-300 mt-1">Assigned to {{ $task->assignedUsers->pluck('name')->implode(', ') }}</p>
                @endif
              </div>
            @endif

          </div>
        @endforeach

        {{-- Summary footer --}}
        <div class="text-center pt-2 pb-8">
          @if($doneCount === $totalTasks && $totalTasks > 0)
            <p class="text-sm text-green-600 font-semibold">✓ All tasks completed for {{ $dateObj->format('F j') }}!</p>
          @else
            <p class="text-sm text-gray-400">{{ $totalTasks - $doneCount }} task{{ $totalTasks - $doneCount !== 1 ? 's' : '' }} pending on {{ $dateObj->format('F j') }}</p>
          @endif
        </div>
      @endif

    </div>
  </div>

  {{-- ===== LIGHTBOX (click image to expand) ===== --}}
  <div x-data="{ open: false, src: '' }"
       @open-lightbox.window="open = true; src = $event.detail"
       x-show="open"
       x-transition.opacity
       @click="open = false"
       @keydown.escape.window="open = false"
       class="fixed inset-0 z-50 bg-black/80 flex items-center justify-center p-4 cursor-zoom-out"
       style="display:none">
    <img :src="src" class="max-w-full max-h-full rounded-xl shadow-2xl object-contain cursor-default" @click.stop>
  </div>

</x-layout>

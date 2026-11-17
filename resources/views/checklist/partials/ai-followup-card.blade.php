{{-- Approved AI follow-ups para sa naka-login (spec 293): paalala lang mula sa Marketing head, hindi checklist item. --}}
@if($aiFollowups->isNotEmpty())
  <style>
    tbody.ai-target > tr > td { background-color: #f5f3ff; transition: background-color .4s ease-out; }
    tbody.ai-target > tr:first-child > td { box-shadow: inset 0 2px 0 #c4b5fd; }
    tbody[id^="task-"]:focus { outline: none; }
  </style>
  <section aria-label="Follow-ups from Mira" class="max-w-screen-2xl mx-auto px-4 py-4 space-y-3">
    @foreach($aiFollowups as $f)
      <article data-ai-followup="{{ $f->id }}" class="bg-white rounded-2xl border border-violet-200 shadow-sm shadow-violet-900/5 p-4 sm:p-5 max-w-2xl">
        <p class="flex items-center gap-2 text-xs font-medium text-violet-800">
          <span class="w-5 h-5 rounded-md bg-violet-600 text-white flex items-center justify-center flex-shrink-0" aria-hidden="true">
            <i class="fa-solid fa-wand-magic-sparkles text-[10px]"></i>
          </span>
          From Mira · approved by {{ \App\Services\AiFollowups::firstName($f->reviewer) ?: 'the CEO' }}
        </p>
        <p class="mt-2 text-[15px] leading-relaxed text-gray-900" style="text-wrap: pretty">{{ $f->description }}</p>
        <div class="mt-3 flex items-center justify-between gap-3 flex-wrap">
          <span class="inline-flex items-center gap-1 text-xs font-medium px-2 py-0.5 rounded-full border bg-white text-gray-700 border-gray-200" style="font-variant-numeric: tabular-nums">
            <i class="fa-regular fa-clock text-[10px]" aria-hidden="true"></i>by {{ \App\Services\AiFollowups::clock($f->deadline_time) }}
          </span>
          <a href="#task-{{ $f->follows_task_id }}"
             x-data
             @click.prevent="
               const row = document.getElementById('task-{{ $f->follows_task_id }}') || document.getElementById('checklist-body-scroll');
               if (row) {
                 const head = document.querySelector('.sticky.top-16');
                 row.style.scrollMarginTop = (64 + (head ? head.offsetHeight : 0) + 12) + 'px';
                 row.scrollIntoView({ block: 'start', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
                 row.setAttribute('tabindex', '-1');
                 row.focus({ preventScroll: true });
                 row.classList.add('ai-target');
                 setTimeout(() => row.classList.remove('ai-target'), 2000);
                 history.replaceState(null, '', '#task-{{ $f->follows_task_id }}');
               }
             "
             class="inline-flex items-center gap-1.5 min-h-[44px] sm:min-h-0 text-sm font-semibold text-blue-700 hover:text-blue-800 hover:underline underline-offset-2 rounded px-1 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500">
            Open task <i class="fa-solid fa-arrow-down text-xs" aria-hidden="true"></i>
          </a>
        </div>
      </article>
    @endforeach
  </section>
@endif

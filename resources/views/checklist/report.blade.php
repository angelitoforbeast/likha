<x-layout>
  <x-slot name="heading">Daily Report</x-slot>
  <x-slot name="title">Daily Report</x-slot>

  @php
    $allImageUrls = [];
    foreach($tasks as $task) {
        $sub = $submissionsByTask->get($task->id);
        if (!$sub) continue;
        foreach($sub->files->filter(fn($f) => $f->isImage()) as $f) {
            $allImageUrls[] = Storage::url($f->file_path);
        }
        if ($sub->files->count() === 0 && $sub->file_path) {
            $allImageUrls[] = Storage::url($sub->file_path);
        }
    }
  @endphp

  <div class="min-h-screen bg-gray-50 mt-16">

    {{-- ===== STICKY HEADER ===== --}}
    <div class="sticky top-0 z-30 bg-white border-b border-gray-200 shadow-sm">
      <div class="max-w-screen-2xl mx-auto px-4 py-0">
        <div class="flex items-stretch gap-0 divide-x divide-gray-100">

          {{-- Prev day --}}
          <a href="{{ route('checklist.report', ['date' => $prevDate]) }}"
             class="flex items-center px-4 py-3.5 text-gray-400 hover:text-gray-700 hover:bg-gray-50 transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
          </a>

          {{-- Date + picker --}}
          <div class="flex-1 flex items-center justify-center gap-3 px-4 py-3">
            <div class="text-center">
              <p class="font-bold text-gray-800 text-sm leading-tight">{{ $dateObj->format('l, F j, Y') }}</p>
              @if($isToday)
                <span class="inline-block text-xs bg-green-100 text-green-700 font-semibold px-2 py-0.5 rounded-full leading-none mt-0.5">Today</span>
              @else
                <form method="GET" action="{{ route('checklist.report') }}" class="inline">
                  <input type="date" name="date" value="{{ $dateObj->toDateString() }}"
                         onchange="this.form.submit()"
                         max="{{ now()->toDateString() }}"
                         class="text-xs text-blue-500 border-0 bg-transparent cursor-pointer focus:outline-none mt-0.5">
                </form>
              @endif
            </div>
          </div>

          {{-- Next day --}}
          <a href="{{ route('checklist.report', ['date' => $nextDate]) }}"
             class="flex items-center px-4 py-3.5 transition {{ $isToday ? 'text-gray-200 pointer-events-none' : 'text-gray-400 hover:text-gray-700 hover:bg-gray-50' }}">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
          </a>

          {{-- Progress + links --}}
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
              ← Checklist
            </a>
          </div>

        </div>
      </div>
    </div>

    {{-- ===== CONTENT ===== --}}
    <div class="max-w-screen-2xl mx-auto px-4 py-5 space-y-4">

      @if($totalTasks === 0)
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-14 text-center">
          <p class="text-3xl mb-3">📋</p>
          <p class="text-gray-500 font-medium">No active tasks</p>
          <p class="text-sm text-gray-400 mt-1">Go to <a href="{{ route('checklist.manage') }}" class="text-blue-500 hover:underline">Manage Tasks</a> to add tasks.</p>
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

        {{-- Table --}}
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
          <div class="overflow-x-auto">
            <table class="w-full text-sm border-collapse">
              <thead>
                <tr class="border-b border-gray-100 bg-gray-50/80 text-xs text-gray-400 uppercase tracking-wide font-semibold">
                  <th class="text-left px-4 py-3 w-8"></th>
                  <th class="text-left px-3 py-3 min-w-[160px]">Task</th>
                  <th class="text-left px-3 py-3 min-w-[130px]">Description</th>
                  <th class="text-left px-3 py-3 w-[180px]">Images</th>
                  <th class="text-left px-3 py-3 min-w-[200px]">Notes</th>
                  <th class="text-left px-3 py-3 min-w-[140px]">Submitted by</th>
                  <th class="text-left px-3 py-3 min-w-[220px]">AI Analysis</th>
                  <th class="text-left px-3 py-3 min-w-[200px]">Approval Check</th>
                  <th class="text-left px-3 py-3 w-[110px]">Analyze</th>
                </tr>
              </thead>

              @foreach($tasks as $task)
                @php
                  $sub           = $submissionsByTask->get($task->id);
                  $done          = $sub !== null;
                  $subFiles      = $done ? $sub->files : collect();
                  $imageFiles    = $subFiles->filter(fn($f) => $f->isImage());
                  $otherFiles    = $subFiles->filter(fn($f) => !$f->isImage());
                  $analyzeUrl    = $done ? '/checklist/submission/'.$sub->id.'/analyze' : '';
                  $logsUrl       = $done ? '/checklist/submission/'.$sub->id.'/analysis-logs' : '';
                  $savedAnalysis = $done ? $sub->latestAnalysis : null;
                  $analysisCount = $done ? ($sub->analysis_logs_count ?? 0) : 0;
                  $approvalUrl      = $done ? '/checklist/submission/'.$sub->id.'/approval-check' : '';
                  $approvalLogsUrl  = $done ? '/checklist/submission/'.$sub->id.'/approval-logs' : '';
                  $savedApproval    = $done ? $sub->latestApproval : null;
                  $approvalCount    = $done ? ($sub->approval_logs_count ?? 0) : 0;
                @endphp

                <tbody
                  x-data="{
                    analyzeUrl: '{{ $analyzeUrl }}',
                    logsUrl: '{{ $logsUrl }}',
                    analyzing: false,
                    analysis: {!! $savedAnalysis ? \Illuminate\Support\Js::from($savedAnalysis->analysis_result) : 'null' !!},
                    promptUsed: {!! $savedAnalysis ? \Illuminate\Support\Js::from($savedAnalysis->prompt_used) : 'null' !!},
                    analyzedBy: {!! $savedAnalysis ? \Illuminate\Support\Js::from($savedAnalysis->user?->name ?? 'Unknown') : 'null' !!},
                    analyzedAt: {!! $savedAnalysis ? \Illuminate\Support\Js::from($savedAnalysis->created_at->format('M j, g:i A')) : 'null' !!},
                    analysisCount: {{ $analysisCount }},
                    analysisError: null,
                    analysisExpanded: false,
                    showPrompt: false,
                    showHistory: false,
                    historyLogs: [],
                    historyLoading: false,
                    approvalUrl: '{{ $approvalUrl }}',
                    approvalLogsUrl: '{{ $approvalLogsUrl }}',
                    approving: false,
                    approval: {!! $savedApproval ? \Illuminate\Support\Js::from($savedApproval->analysis_result) : 'null' !!},
                    approvalVerdict: {!! $savedApproval ? \Illuminate\Support\Js::from($savedApproval->verdict ?? 'unknown') : 'null' !!},
                    approvalPromptUsed: {!! $savedApproval ? \Illuminate\Support\Js::from($savedApproval->prompt_used) : 'null' !!},
                    approvalCheckedBy: {!! $savedApproval ? \Illuminate\Support\Js::from($savedApproval->user?->name ?? 'Unknown') : 'null' !!},
                    approvalCheckedAt: {!! $savedApproval ? \Illuminate\Support\Js::from($savedApproval->created_at->format('M j, g:i A')) : 'null' !!},
                    approvalCount: {{ $approvalCount }},
                    approvalError: null,
                    approvalExpanded: false,
                    showApprovalPrompt: false,
                    showApprovalHistory: false,
                    approvalHistoryLogs: [],
                    approvalHistoryLoading: false,
                    async runAll() {
                      await Promise.all([
                        this.analyzeUrl  ? this.analyze()       : Promise.resolve(),
                        this.approvalUrl ? this.checkApproval() : Promise.resolve(),
                      ]);
                    },
                    async analyze() {
                      this.analyzing    = true;
                      this.analysis     = null;
                      this.analysisError = null;
                      this.showHistory  = false;
                      try {
                        const res = await fetch(this.analyzeUrl, {
                          method: 'POST',
                          headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || ''
                          }
                        });
                        if (!res.ok) {
                          this.analysisError = 'Server error (' + res.status + '). Please try again.';
                          this.analyzing = false; return;
                        }
                        const data = await res.json();
                        this.analysis      = data.analysis    ?? null;
                        this.promptUsed    = data.prompt_used ?? null;
                        this.analyzedBy    = data.analyzed_by ?? null;
                        this.analyzedAt    = data.analyzed_at ?? null;
                        this.analysisError = data.error       ?? null;
                        this.analysisCount += 1;
                        this.historyLogs   = [];
                      } catch(e) {
                        this.analysisError = 'Request failed: ' + e.message;
                      }
                      this.analyzing = false;
                    },
                    async toggleHistory() {
                      this.showHistory = !this.showHistory;
                      if (this.showHistory && this.historyLogs.length === 0) {
                        this.historyLoading = true;
                        try {
                          const res  = await fetch(this.logsUrl);
                          const data = await res.json();
                          this.historyLogs = data.logs ?? [];
                        } catch(e) {}
                        this.historyLoading = false;
                      }
                    },
                    async checkApproval() {
                      this.approving      = true;
                      this.approval       = null;
                      this.approvalError  = null;
                      this.showApprovalHistory = false;
                      try {
                        const res = await fetch(this.approvalUrl, {
                          method: 'POST',
                          headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || ''
                          }
                        });
                        if (!res.ok) {
                          this.approvalError = 'Server error (' + res.status + '). Please try again.';
                          this.approving = false; return;
                        }
                        const data = await res.json();
                        this.approval           = data.analysis    ?? null;
                        this.approvalVerdict    = data.verdict     ?? null;
                        this.approvalPromptUsed = data.prompt_used ?? null;
                        this.approvalCheckedBy  = data.checked_by  ?? null;
                        this.approvalCheckedAt  = data.checked_at  ?? null;
                        this.approvalError      = data.error       ?? null;
                        this.approvalCount     += 1;
                        this.approvalHistoryLogs = [];
                      } catch(e) {
                        this.approvalError = 'Request failed: ' + e.message;
                      }
                      this.approving = false;
                    },
                    async toggleApprovalHistory() {
                      this.showApprovalHistory = !this.showApprovalHistory;
                      if (this.showApprovalHistory && this.approvalHistoryLogs.length === 0) {
                        this.approvalHistoryLoading = true;
                        try {
                          const res  = await fetch(this.approvalLogsUrl);
                          const data = await res.json();
                          this.approvalHistoryLogs = data.logs ?? [];
                        } catch(e) {}
                        this.approvalHistoryLoading = false;
                      }
                    }
                  }"
                >

                  {{-- DATA ROW --}}
                  <tr class="border-b border-gray-50 hover:bg-gray-50/40 transition-colors">

                    {{-- Status --}}
                    <td class="px-4 py-3 align-middle">
                      <div class="w-2.5 h-2.5 rounded-full mx-auto {{ $done ? 'bg-green-400' : 'bg-gray-200' }}"></div>
                    </td>

                    {{-- Task --}}
                    <td class="px-3 py-3 align-top">
                      <div class="flex items-center gap-1.5 flex-wrap">
                        <p class="font-semibold text-gray-800 leading-snug">{{ $task->title }}</p>
                        @if($task->scheduled_time)
                          <span class="text-xs px-1.5 py-0.5 rounded-full bg-sky-50 text-sky-500 font-medium leading-none">🕐 {{ $task->scheduled_time }}</span>
                        @endif
                        @if($task->deleted_at)
                          <span class="text-xs px-1.5 py-0.5 rounded-full bg-red-50 text-red-400 leading-none">deleted</span>
                        @elseif(!$task->is_active)
                          <span class="text-xs px-1.5 py-0.5 rounded-full bg-gray-100 text-gray-400 leading-none">inactive</span>
                        @endif
                      </div>
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
                            <img src="{{ Storage::url($f->file_path) }}"
                                 @click="$dispatch('open-lightbox', '{{ Storage::url($f->file_path) }}')"
                                 class="w-14 h-14 object-cover rounded-lg border border-gray-100 hover:opacity-80 transition shadow-sm cursor-zoom-in"
                                 alt="{{ $f->file_original_name }}">
                          @endforeach
                        </div>
                        @foreach($otherFiles as $f)
                          <a href="{{ Storage::url($f->file_path) }}" target="_blank"
                             class="text-xs text-blue-500 hover:underline flex items-center gap-1 mt-1">
                            📎 <span class="truncate max-w-[90px]">{{ $f->file_original_name }}</span>
                          </a>
                        @endforeach
                      @elseif($done && $sub->file_path)
                        <img src="{{ Storage::url($sub->file_path) }}"
                             @click="$dispatch('open-lightbox', '{{ Storage::url($sub->file_path) }}')"
                             class="w-14 h-14 object-cover rounded-lg border border-gray-100 hover:opacity-80 transition cursor-zoom-in">
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
                    <td class="px-3 py-3 align-top">
                      @if($done)
                        @php
                          $editLogs  = $sub->logs->where('action', 'updated');
                          $lastEdit  = $editLogs->first();
                          $editCount = $editLogs->count();
                        @endphp
                        <div class="flex items-center gap-2">
                          <div class="w-6 h-6 rounded-full bg-indigo-100 flex items-center justify-center text-xs font-bold text-indigo-600 flex-shrink-0">
                            {{ strtoupper(substr($sub->user->name ?? '?', 0, 1)) }}
                          </div>
                          <div>
                            <p class="text-xs font-semibold text-gray-700 leading-none">{{ $sub->user->name ?? 'Unknown' }}</p>
                            <p class="text-xs text-gray-400 leading-none mt-0.5">{{ $sub->created_at->format('h:i A') }}</p>
                          </div>
                        </div>
                        @if($lastEdit)
                          <div class="flex items-center gap-1.5 mt-1.5 pt-1.5 border-t border-gray-100">
                            <div class="w-5 h-5 rounded-full bg-amber-100 flex items-center justify-center text-xs font-bold text-amber-600 flex-shrink-0">
                              {{ strtoupper(substr($lastEdit->user->name ?? '?', 0, 1)) }}
                            </div>
                            <div>
                              <p class="text-xs text-amber-600 leading-none">edited by {{ $lastEdit->user->name ?? 'Unknown' }}</p>
                              <p class="text-xs text-gray-400 leading-none mt-0.5">
                                {{ \Carbon\Carbon::parse($lastEdit->created_at)->format('h:i A') }}
                                {{ $editCount > 1 ? '&middot; '.$editCount.' edits' : '' }}
                              </p>
                            </div>
                          </div>
                        @endif
                      @else
                        <span class="text-gray-200">—</span>
                      @endif
                    </td>

                    {{-- AI Analysis (inline) --}}
                    <td class="px-3 py-3 align-top min-w-[220px]">
                      @if($done)
                        {{-- Loading --}}
                        <div x-show="analyzing" class="flex items-center gap-1.5 text-xs text-purple-500">
                          <svg class="animate-spin w-3 h-3 flex-shrink-0" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
                          </svg>
                          Analyzing…
                        </div>
                        {{-- Result --}}
                        <div x-show="!analyzing && analysis">
                          <p class="text-xs text-gray-700 leading-relaxed whitespace-pre-line"
                             :class="analysisExpanded ? '' : 'line-clamp-4'"
                             x-text="analysis"></p>
                          <div class="flex items-center gap-2 mt-1 flex-wrap">
                            <button @click="analysisExpanded = !analysisExpanded"
                                    class="text-xs text-purple-400 hover:text-purple-600"
                                    x-text="analysisExpanded ? 'collapse' : 'expand'"></button>
                            <span class="text-xs text-gray-300">·</span>
                            <span class="text-xs text-gray-400"
                                  x-text="analyzedBy ? 'by ' + analyzedBy + (analyzedAt ? ' · ' + analyzedAt : '') : ''"></span>
                          </div>
                          {{-- Prompt used toggle --}}
                          <div x-show="promptUsed" class="mt-1">
                            <button @click="showPrompt = !showPrompt"
                                    class="text-xs text-gray-300 hover:text-gray-500 underline underline-offset-2"
                                    x-text="showPrompt ? 'hide prompt' : 'show prompt'"></button>
                            <pre x-show="showPrompt"
                                 class="mt-1 text-xs text-gray-400 bg-gray-50 border border-gray-100 rounded px-2 py-1 whitespace-pre-wrap"
                                 x-text="promptUsed"></pre>
                          </div>
                          {{-- History toggle --}}
                          <div x-show="analysisCount > 0" class="mt-1">
                            <button @click="toggleHistory()"
                                    class="text-xs text-purple-300 hover:text-purple-500"
                                    x-text="showHistory ? 'hide history' : analysisCount + (analysisCount === 1 ? ' analysis' : ' analyses')"></button>
                            <div x-show="showHistory" class="mt-1.5 space-y-2 border-t border-purple-100 pt-1.5">
                              <div x-show="historyLoading" class="text-xs text-gray-400">Loading…</div>
                              <template x-for="(log, i) in historyLogs" :key="log.id">
                                <div class="text-xs">
                                  <span class="text-gray-500 font-medium" x-text="'#'+(historyLogs.length-i)+' '+log.user+' · '+log.created_at"></span>
                                  <p class="text-gray-600 mt-0.5 line-clamp-3 whitespace-pre-line" x-text="log.analysis"></p>
                                </div>
                              </template>
                            </div>
                          </div>
                        </div>
                        {{-- Error --}}
                        <div x-show="!analyzing && analysisError" class="flex items-center gap-1 text-xs text-red-500">
                          <span>⚠</span><span x-text="analysisError"></span>
                        </div>
                        {{-- Empty --}}
                        <span x-show="!analyzing && !analysis && !analysisError" class="text-gray-200 text-xs">—</span>
                      @else
                        <span class="text-gray-200 text-xs">—</span>
                      @endif
                    </td>

                    {{-- Approval Check (inline) --}}
                    <td class="px-3 py-3 align-top min-w-[200px]">
                      @if($done)
                        {{-- Loading --}}
                        <div x-show="approving" class="flex items-center gap-1.5 text-xs text-emerald-600">
                          <svg class="animate-spin w-3 h-3 flex-shrink-0" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
                          </svg>
                          Checking…
                        </div>
                        {{-- Result --}}
                        <div x-show="!approving && approval">
                          <div class="mb-1">
                            <span x-show="approvalVerdict === 'approved'"
                                  class="text-xs font-bold px-2 py-0.5 rounded-full bg-green-100 text-green-700">✅ Approved</span>
                            <span x-show="approvalVerdict === 'not_approved'"
                                  class="text-xs font-bold px-2 py-0.5 rounded-full bg-red-100 text-red-700">❌ Not Approved</span>
                            <span x-show="approvalVerdict === 'unknown' || !approvalVerdict"
                                  class="text-xs font-bold px-2 py-0.5 rounded-full bg-gray-100 text-gray-600">⚠ Unknown</span>
                          </div>
                          <p class="text-xs text-gray-700 leading-relaxed whitespace-pre-line"
                             :class="approvalExpanded ? '' : 'line-clamp-3'"
                             x-text="approval"></p>
                          <div class="flex items-center gap-2 mt-1 flex-wrap">
                            <button @click="approvalExpanded = !approvalExpanded"
                                    class="text-xs text-emerald-400 hover:text-emerald-600"
                                    x-text="approvalExpanded ? 'collapse' : 'expand'"></button>
                            <span class="text-xs text-gray-300">·</span>
                            <span class="text-xs text-gray-400"
                                  x-text="approvalCheckedBy ? 'by '+approvalCheckedBy+(approvalCheckedAt?' · '+approvalCheckedAt:'') : ''"></span>
                          </div>
                          {{-- Prompt used toggle --}}
                          <div x-show="approvalPromptUsed" class="mt-1">
                            <button @click="showApprovalPrompt = !showApprovalPrompt"
                                    class="text-xs text-gray-300 hover:text-gray-500 underline underline-offset-2"
                                    x-text="showApprovalPrompt ? 'hide prompt' : 'show prompt'"></button>
                            <pre x-show="showApprovalPrompt"
                                 class="mt-1 text-xs text-gray-400 bg-gray-50 border border-gray-100 rounded px-2 py-1 whitespace-pre-wrap"
                                 x-text="approvalPromptUsed"></pre>
                          </div>
                          {{-- History --}}
                          <div x-show="approvalCount > 0" class="mt-1">
                            <button @click="toggleApprovalHistory()"
                                    class="text-xs text-emerald-300 hover:text-emerald-500"
                                    x-text="showApprovalHistory ? 'hide history' : approvalCount + (approvalCount === 1 ? ' check' : ' checks')"></button>
                            <div x-show="showApprovalHistory" class="mt-1.5 space-y-2 border-t border-emerald-100 pt-1.5">
                              <div x-show="approvalHistoryLoading" class="text-xs text-gray-400">Loading…</div>
                              <template x-for="(log, i) in approvalHistoryLogs" :key="log.id">
                                <div class="text-xs">
                                  <div class="flex items-center gap-1">
                                    <span x-show="log.verdict === 'approved'" class="text-green-600">✅</span>
                                    <span x-show="log.verdict === 'not_approved'" class="text-red-600">❌</span>
                                    <span x-show="log.verdict === 'unknown' || !log.verdict" class="text-gray-400">⚠</span>
                                    <span class="text-gray-500 font-medium" x-text="'#'+(approvalHistoryLogs.length-i)+' '+log.user+' · '+log.created_at"></span>
                                  </div>
                                  <p class="text-gray-600 mt-0.5 line-clamp-2 whitespace-pre-line" x-text="log.analysis"></p>
                                </div>
                              </template>
                            </div>
                          </div>
                        </div>
                        {{-- Error --}}
                        <div x-show="!approving && approvalError" class="flex items-center gap-1 text-xs text-red-500">
                          <span>⚠</span><span x-text="approvalError"></span>
                        </div>
                        {{-- Empty --}}
                        <span x-show="!approving && !approval && !approvalError" class="text-gray-200 text-xs">—</span>
                      @endif
                    </td>

                    {{-- Analyze button (single, triggers both) --}}
                    <td class="px-3 py-3 align-middle">
                      @if($done)
                        <button @click="runAll()"
                                :disabled="analyzing || approving"
                                :class="(analyzing || approving) ? 'opacity-60 cursor-not-allowed' : 'hover:bg-indigo-700'"
                                class="flex items-center gap-1.5 text-xs px-2.5 py-1.5 rounded-lg bg-indigo-600 text-white transition font-medium whitespace-nowrap">
                          <span x-show="!analyzing && !approving"
                                x-text="(analysisCount > 0 || approvalCount > 0) ? '↻ Re-analyze' : '✦ Analyze'"></span>
                          <span x-show="analyzing || approving" class="flex items-center gap-1">
                            <svg class="animate-spin w-3 h-3" fill="none" viewBox="0 0 24 24">
                              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
                            </svg>
                            Analyzing…
                          </span>
                        </button>
                      @else
                        <span class="text-gray-200 text-xs">—</span>
                      @endif
                    </td>

                  </tr>

                </tbody>
              @endforeach

            </table>
          </div>

          @if($doneCount === $totalTasks && $totalTasks > 0)
            <div class="px-6 py-3 border-t border-gray-100 bg-green-50/50 text-center">
              <p class="text-sm text-green-600 font-semibold">🎉 All tasks completed for {{ $dateObj->format('F j') }}!</p>
            </div>
          @elseif($doneCount === 0)
            <div class="px-6 py-4 border-t border-gray-100 text-center">
              <p class="text-sm text-gray-400 italic">No submissions yet for {{ $dateObj->format('F j') }}.</p>
            </div>
          @endif
        </div>

      @endif
    </div>
  </div>

  {{-- ===== LIGHTBOX (dispatch pattern — shared across all tbody scopes) ===== --}}
  <div x-data="{
           lightbox: false,
           images: {{ json_encode($allImageUrls) }},
           currentIndex: 0,
           get lightSrc() { return this.images[this.currentIndex] ?? ''; },
           open(src) {
               const idx = this.images.indexOf(src);
               this.currentIndex = idx >= 0 ? idx : 0;
               this.lightbox = true;
           },
           prev() { if (this.currentIndex > 0) this.currentIndex--; },
           next() { if (this.currentIndex < this.images.length - 1) this.currentIndex++; }
       }"
       @open-lightbox.window="open($event.detail)"
       @keydown.escape.window="lightbox = false"
       @keydown.arrow-left.window="if (lightbox) prev()"
       @keydown.arrow-right.window="if (lightbox) next()"
       x-show="lightbox"
       x-transition.opacity
       @click="lightbox = false"
       class="fixed inset-0 z-50 bg-black/90 flex items-center justify-center p-4"
       style="display:none">

    <button @click="lightbox = false"
            class="absolute top-4 right-4 w-9 h-9 bg-white/10 hover:bg-white/20 text-white rounded-full flex items-center justify-center text-lg transition z-10">✕</button>

    <template x-if="images.length > 1">
      <div class="absolute top-4 left-1/2 -translate-x-1/2 bg-black/50 text-white text-xs px-3 py-1 rounded-full z-10"
           x-text="(currentIndex + 1) + ' / ' + images.length"></div>
    </template>

    <template x-if="images.length > 1">
      <button @click.stop="prev()"
              :class="currentIndex === 0 ? 'opacity-20 pointer-events-none' : 'opacity-80 hover:opacity-100'"
              class="absolute left-4 top-1/2 -translate-y-1/2 w-11 h-11 bg-white/10 hover:bg-white/20 text-white rounded-full flex items-center justify-center text-xl transition z-10">‹</button>
    </template>

    <img :src="lightSrc"
         class="max-w-full max-h-full rounded-xl shadow-2xl object-contain"
         @click.stop>

    <template x-if="images.length > 1">
      <button @click.stop="next()"
              :class="currentIndex === images.length - 1 ? 'opacity-20 pointer-events-none' : 'opacity-80 hover:opacity-100'"
              class="absolute right-4 top-1/2 -translate-y-1/2 w-11 h-11 bg-white/10 hover:bg-white/20 text-white rounded-full flex items-center justify-center text-xl transition z-10">›</button>
    </template>
  </div>

</x-layout>

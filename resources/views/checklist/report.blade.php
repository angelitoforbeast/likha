<x-layout>
  <x-slot name="heading">Daily Report</x-slot>
  <x-slot name="title">Daily Report</x-slot>

  @php
    // ── Filter data for Alpine store ──────────────────────────────────────────
    $taskFilterData = [];
    foreach ($tasks as $task) {
        if ($task->submission_type === 'individual') {
            $fSubs = $submissionsGroupedByTask->get($task->id) ?? collect();
            $fDone = $fSubs->isNotEmpty();
            $fUsers = $fSubs->map(fn($s) => strtolower($s->user->name ?? ''))->filter()->values()->all();
            $fVerdicts = $fSubs->map(fn($s) => $s->latestApproval?->verdict ?? '')->filter()->unique()->values()->all();
        } else {
            $fSub  = $submissionsByTask->get($task->id);
            $fDone = $fSub !== null;
            $fUsers = ($fDone && ($fSub->user ?? null)) ? [strtolower($fSub->user->name)] : [];
            $fVerdict = $fDone ? ($fSub->latestApproval?->verdict ?? '') : '';
            $fVerdicts = $fVerdict !== '' ? [$fVerdict] : [];
        }
        $taskFilterData[$task->id] = [
            'done'     => $fDone,
            'title'    => strtolower($task->title),
            'dept'     => strtolower($task->department ?? ''),
            'users'    => $fUsers,
            'verdicts' => $fVerdicts,
        ];
    }
    $approvedCount    = collect($taskFilterData)->filter(fn($t) => in_array('approved',     $t['verdicts']))->count();
    $notApprovedCount = collect($taskFilterData)->filter(fn($t) => in_array('not_approved', $t['verdicts']))->count();
    $allDepts = $tasks->pluck('department')->filter()->unique()->sort()->values()->all();
    $allSubmitters = collect();
    foreach ($tasks as $task) {
        if ($task->submission_type === 'individual') {
            $fSubs2 = $submissionsGroupedByTask->get($task->id) ?? collect();
            $allSubmitters = $allSubmitters->merge($fSubs2->pluck('user.name')->filter());
        } else {
            $fSub2 = $submissionsByTask->get($task->id);
            if ($fSub2 && ($fSub2->user ?? null)) $allSubmitters->push($fSub2->user->name);
        }
    }
    $allSubmitters = $allSubmitters->unique()->sort()->values()->all();
    // ─────────────────────────────────────────────────────────────────────────

    $allImageUrls = [];
    foreach($tasks as $task) {
        if ($task->submission_type === 'individual') {
            $taskSubs = $submissionsGroupedByTask->get($task->id) ?? collect();
            foreach ($taskSubs as $iSub) {
                foreach($iSub->files->filter(fn($f) => $f->isImage()) as $f) {
                    $allImageUrls[] = Storage::url($f->file_path);
                }
            }
        } else {
            $sub = $submissionsByTask->get($task->id);
            if (!$sub) continue;
            foreach($sub->files->filter(fn($f) => $f->isImage()) as $f) {
                $allImageUrls[] = Storage::url($f->file_path);
            }
            if ($sub->files->count() === 0 && $sub->file_path) {
                $allImageUrls[] = Storage::url($sub->file_path);
            }
        }
    }
  @endphp

  <div class="min-h-screen bg-gray-50 mt-16">

    {{-- ===== STICKY HEADER ===== --}}
    <div class="sticky top-16 z-30 bg-white border-b border-gray-200 shadow-sm"
         x-data="{
           get filter()  { return $store.report.filter; },
           set filter(v) { $store.report.filter = v; },
           get search()  { return $store.report.search; },
           set search(v) { $store.report.search = v; },
           get dept()    { return $store.report.dept; },
           set dept(v)   { $store.report.dept = v; },
           get user()    { return $store.report.user; },
           set user(v)   { $store.report.user = v; },
           get verdict() { return $store.report.verdict; },
           set verdict(v){ $store.report.verdict = v; },
           get hasFilters() { return this.filter !== 'all' || this.search !== '' || this.dept !== '' || this.user !== '' || this.verdict !== 'all'; }
         }">

      {{-- Row 1: Date nav --}}
      <div class="flex items-stretch gap-0 divide-x divide-gray-100">

        {{-- Prev day --}}
        <a href="{{ route('checklist.report', ['date' => $prevDate]) }}"
           class="flex items-center px-4 py-3 text-gray-400 hover:text-gray-700 hover:bg-gray-50 transition">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        </a>

        {{-- Date + picker --}}
        <div class="flex-1 flex items-center justify-center gap-3 px-4 py-2.5">
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
           class="flex items-center px-4 py-3 transition {{ $isToday ? 'text-gray-200 pointer-events-none' : 'text-gray-400 hover:text-gray-700 hover:bg-gray-50' }}">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
        </a>

        {{-- Progress + links --}}
        <div class="flex items-center gap-3 px-4 py-2.5">
          @php $pct = $totalTasks > 0 ? round($doneCount / $totalTasks * 100) : 0; @endphp
          <svg class="w-7 h-7 -rotate-90" viewBox="0 0 36 36">
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
          <a href="{{ route('checklist.index') }}"
             class="text-xs px-3 py-1.5 rounded-lg border border-gray-200 text-gray-500 hover:bg-gray-50 transition whitespace-nowrap">
            ← Checklist
          </a>
        </div>

      </div>

      {{-- Row 2: Filters --}}
      <div class="border-t border-gray-100 px-3 py-2 flex items-center gap-2 flex-wrap bg-gray-50/60">

        {{-- Status filter chips --}}
        <button @click="filter = 'all'"
                :class="filter === 'all' ? 'bg-gray-700 text-white' : 'bg-white text-gray-600 border border-gray-200 hover:bg-gray-50'"
                class="text-xs px-3 py-1.5 rounded-full font-medium transition whitespace-nowrap">
          All <span class="opacity-70">{{ $totalTasks }}</span>
        </button>
        <button @click="filter = filter === 'done' ? 'all' : 'done'"
                :class="filter === 'done' ? 'bg-green-600 text-white' : 'bg-white text-green-700 border border-green-200 hover:bg-green-50'"
                class="text-xs px-3 py-1.5 rounded-full font-medium transition whitespace-nowrap">
          ✅ Done <span :class="filter === 'done' ? 'opacity-70' : ''">{{ $doneCount }}</span>
        </button>
        <button @click="filter = filter === 'pending' ? 'all' : 'pending'"
                :class="filter === 'pending' ? 'bg-amber-500 text-white' : 'bg-white text-amber-700 border border-amber-200 hover:bg-amber-50'"
                class="text-xs px-3 py-1.5 rounded-full font-medium transition whitespace-nowrap">
          ⏳ Pending <span :class="filter === 'pending' ? 'opacity-70' : ''">{{ $totalTasks - $doneCount }}</span>
        </button>

        <div class="w-px h-4 bg-gray-200 mx-1 hidden sm:block"></div>

        {{-- Approval verdict chips --}}
        <button @click="verdict = verdict === 'approved' ? 'all' : 'approved'"
                :class="verdict === 'approved' ? 'bg-emerald-600 text-white' : 'bg-white text-emerald-700 border border-emerald-200 hover:bg-emerald-50'"
                class="text-xs px-3 py-1.5 rounded-full font-medium transition whitespace-nowrap">
          ✅ Approved <span :class="verdict === 'approved' ? 'opacity-70' : ''">{{ $approvedCount }}</span>
        </button>
        <button @click="verdict = verdict === 'not_approved' ? 'all' : 'not_approved'"
                :class="verdict === 'not_approved' ? 'bg-red-500 text-white' : 'bg-white text-red-600 border border-red-200 hover:bg-red-50'"
                class="text-xs px-3 py-1.5 rounded-full font-medium transition whitespace-nowrap">
          ❌ Not Approved <span :class="verdict === 'not_approved' ? 'opacity-70' : ''">{{ $notApprovedCount }}</span>
        </button>

        <div class="w-px h-4 bg-gray-200 mx-1 hidden sm:block"></div>

        {{-- Search --}}
        <div class="relative flex items-center">
          <svg class="w-3.5 h-3.5 text-gray-400 absolute left-2.5 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0"/></svg>
          <input x-model.debounce.200="search" type="text" placeholder="Search task…"
                 class="text-xs border border-gray-200 rounded-full pl-7 pr-3 py-1.5 focus:outline-none focus:ring-1 focus:ring-blue-400 bg-white w-36">
        </div>

        {{-- Department filter --}}
        @if(count($allDepts) > 0)
          <select x-model="dept"
                  class="text-xs border border-gray-200 rounded-full px-3 py-1.5 focus:outline-none focus:ring-1 focus:ring-blue-400 bg-white">
            <option value="">All depts</option>
            @foreach($allDepts as $d)
              <option value="{{ $d }}">{{ $d }}</option>
            @endforeach
          </select>
        @endif

        {{-- User filter --}}
        @if(count($allSubmitters) > 0)
          <select x-model="user"
                  class="text-xs border border-gray-200 rounded-full px-3 py-1.5 focus:outline-none focus:ring-1 focus:ring-blue-400 bg-white">
            <option value="">All users</option>
            @foreach($allSubmitters as $u)
              <option value="{{ strtolower($u) }}">{{ $u }}</option>
            @endforeach
          </select>
        @endif

        {{-- Clear --}}
        <button x-show="hasFilters" x-transition
                @click="filter = 'all'; search = ''; dept = ''; user = ''; verdict = 'all'"
                class="text-xs text-red-400 hover:text-red-600 px-2 py-1 rounded-full hover:bg-red-50 transition">
          ✕ Clear
        </button>

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
                  $isIndividual = $task->submission_type === 'individual';

                  if ($isIndividual) {
                      $subs             = $submissionsGroupedByTask->get($task->id) ?? collect();
                      $submittedUserIds = $subs->pluck('user_id');
                      $unsubmittedUsers = $task->assignedUsers->whereNotIn('id', $submittedUserIds->toArray());
                      $done             = $subs->isNotEmpty();
                  } else {
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
                  }
                @endphp

                @if(!$isIndividual)
                <tbody
                  x-show="$store.report.isVisible({{ $task->id }})"
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
                          <span class="text-xs px-1.5 py-0.5 rounded-full bg-sky-50 text-sky-500 font-medium leading-none">🕐 {{ date('g:i A', strtotime($task->scheduled_time)) }}</span>
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
                @else
                {{-- ===== INDIVIDUAL TASK ===== --}}
                {{-- Collapsible header row --}}
                <tbody x-show="$store.report.isVisible({{ $task->id }})">
                  <tr class="border-b border-gray-100 bg-gray-50/60 cursor-pointer hover:bg-gray-100/60 transition"
                      onclick="window.dispatchEvent(new CustomEvent('toggle-task-{{ $task->id }}'))">
                    <td class="px-4 py-2.5">
                      <div class="w-2.5 h-2.5 rounded-full mx-auto {{ $done ? 'bg-green-400' : 'bg-gray-200' }}"></div>
                    </td>
                    <td class="px-3 py-2.5" colspan="6">
                      <div class="flex items-center gap-2 flex-wrap">
                        <p class="font-semibold text-gray-800 text-sm">{{ $task->title }}</p>
                        <span class="text-xs px-1.5 py-0.5 rounded-full bg-violet-50 text-violet-500 font-medium">👤 Individual</span>
                        @if($task->scheduled_time)
                          <span class="text-xs px-1.5 py-0.5 rounded-full bg-sky-50 text-sky-500">🕐 {{ date('g:i A', strtotime($task->scheduled_time)) }}</span>
                        @endif
                        @if($task->deleted_at)
                          <span class="text-xs px-1.5 py-0.5 rounded-full bg-red-50 text-red-400">deleted</span>
                        @elseif(!$task->is_active)
                          <span class="text-xs px-1.5 py-0.5 rounded-full bg-gray-100 text-gray-400">inactive</span>
                        @endif
                        <span class="text-xs text-gray-400">
                          {{ $subs->count() }}/{{ $task->assignedUsers->count() }} submitted · click to expand/collapse
                        </span>
                      </div>
                    </td>
                    <td class="px-3 py-2.5" colspan="3"></td>
                  </tr>
                </tbody>

                {{-- Per-user submitted sub-rows --}}
                @foreach($subs as $userSub)
                  @php
                    $userSubFiles      = $userSub->files;
                    $userImageFiles    = $userSubFiles->filter(fn($f) => $f->isImage());
                    $userOtherFiles    = $userSubFiles->filter(fn($f) => !$f->isImage());
                    $userAnalyzeUrl    = '/checklist/submission/'.$userSub->id.'/analyze';
                    $userLogsUrl       = '/checklist/submission/'.$userSub->id.'/analysis-logs';
                    $userSavedAnalysis = $userSub->latestAnalysis;
                    $userAnalysisCount = $userSub->analysis_logs_count ?? 0;
                    $userApprovalUrl     = '/checklist/submission/'.$userSub->id.'/approval-check';
                    $userApprovalLogsUrl = '/checklist/submission/'.$userSub->id.'/approval-logs';
                    $userSavedApproval   = $userSub->latestApproval;
                    $userApprovalCount   = $userSub->approval_logs_count ?? 0;
                    $userEditLogs        = $userSub->logs->where('action', 'updated');
                    $userLastEdit        = $userEditLogs->first();
                    $userEditCount       = $userEditLogs->count();
                  @endphp
                  <tbody
                    x-show="$store.report.isVisible({{ $task->id }})"
                    x-data="{
                      open: true,
                      analyzeUrl: '{{ $userAnalyzeUrl }}',
                      logsUrl: '{{ $userLogsUrl }}',
                      analyzing: false,
                      analysis: {!! $userSavedAnalysis ? \Illuminate\Support\Js::from($userSavedAnalysis->analysis_result) : 'null' !!},
                      promptUsed: {!! $userSavedAnalysis ? \Illuminate\Support\Js::from($userSavedAnalysis->prompt_used) : 'null' !!},
                      analyzedBy: {!! $userSavedAnalysis ? \Illuminate\Support\Js::from($userSavedAnalysis->user?->name ?? 'Unknown') : 'null' !!},
                      analyzedAt: {!! $userSavedAnalysis ? \Illuminate\Support\Js::from($userSavedAnalysis->created_at->format('M j, g:i A')) : 'null' !!},
                      analysisCount: {{ $userAnalysisCount }},
                      analysisError: null,
                      analysisExpanded: false,
                      showPrompt: false,
                      showHistory: false,
                      historyLogs: [],
                      historyLoading: false,
                      approvalUrl: '{{ $userApprovalUrl }}',
                      approvalLogsUrl: '{{ $userApprovalLogsUrl }}',
                      approving: false,
                      approval: {!! $userSavedApproval ? \Illuminate\Support\Js::from($userSavedApproval->analysis_result) : 'null' !!},
                      approvalVerdict: {!! $userSavedApproval ? \Illuminate\Support\Js::from($userSavedApproval->verdict ?? 'unknown') : 'null' !!},
                      approvalPromptUsed: {!! $userSavedApproval ? \Illuminate\Support\Js::from($userSavedApproval->prompt_used) : 'null' !!},
                      approvalCheckedBy: {!! $userSavedApproval ? \Illuminate\Support\Js::from($userSavedApproval->user?->name ?? 'Unknown') : 'null' !!},
                      approvalCheckedAt: {!! $userSavedApproval ? \Illuminate\Support\Js::from($userSavedApproval->created_at->format('M j, g:i A')) : 'null' !!},
                      approvalCount: {{ $userApprovalCount }},
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
                        this.analyzing    = true; this.analysis = null; this.analysisError = null; this.showHistory = false;
                        try {
                          const res = await fetch(this.analyzeUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '' } });
                          if (!res.ok) { this.analysisError = 'Server error (' + res.status + '). Please try again.'; this.analyzing = false; return; }
                          const data = await res.json();
                          this.analysis = data.analysis ?? null; this.promptUsed = data.prompt_used ?? null;
                          this.analyzedBy = data.analyzed_by ?? null; this.analyzedAt = data.analyzed_at ?? null;
                          this.analysisError = data.error ?? null; this.analysisCount += 1; this.historyLogs = [];
                        } catch(e) { this.analysisError = 'Request failed: ' + e.message; }
                        this.analyzing = false;
                      },
                      async toggleHistory() {
                        this.showHistory = !this.showHistory;
                        if (this.showHistory && this.historyLogs.length === 0) {
                          this.historyLoading = true;
                          try { const res = await fetch(this.logsUrl); const data = await res.json(); this.historyLogs = data.logs ?? []; } catch(e) {}
                          this.historyLoading = false;
                        }
                      },
                      async checkApproval() {
                        this.approving = true; this.approval = null; this.approvalError = null; this.showApprovalHistory = false;
                        try {
                          const res = await fetch(this.approvalUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '' } });
                          if (!res.ok) { this.approvalError = 'Server error (' + res.status + '). Please try again.'; this.approving = false; return; }
                          const data = await res.json();
                          this.approval = data.analysis ?? null; this.approvalVerdict = data.verdict ?? null;
                          this.approvalPromptUsed = data.prompt_used ?? null; this.approvalCheckedBy = data.checked_by ?? null;
                          this.approvalCheckedAt = data.checked_at ?? null; this.approvalError = data.error ?? null;
                          this.approvalCount += 1; this.approvalHistoryLogs = [];
                        } catch(e) { this.approvalError = 'Request failed: ' + e.message; }
                        this.approving = false;
                      },
                      async toggleApprovalHistory() {
                        this.showApprovalHistory = !this.showApprovalHistory;
                        if (this.showApprovalHistory && this.approvalHistoryLogs.length === 0) {
                          this.approvalHistoryLoading = true;
                          try { const res = await fetch(this.approvalLogsUrl); const data = await res.json(); this.approvalHistoryLogs = data.logs ?? []; } catch(e) {}
                          this.approvalHistoryLoading = false;
                        }
                      }
                    }"
                    @toggle-task-{{ $task->id }}.window="open = !open"
                  >
                    <tr x-show="open" x-transition class="border-b border-gray-50 hover:bg-violet-50/20 bg-violet-50/10">
                      {{-- Status --}}
                      <td class="px-4 py-3 align-middle">
                        <div class="w-2.5 h-2.5 rounded-full mx-auto bg-green-400"></div>
                      </td>
                      {{-- User name --}}
                      <td class="px-3 py-3 align-top">
                        <div class="flex items-center gap-1.5">
                          <div class="w-5 h-5 rounded-full bg-violet-100 flex items-center justify-center text-xs font-bold text-violet-600 flex-shrink-0">
                            {{ strtoupper(substr($userSub->user->name ?? '?', 0, 1)) }}
                          </div>
                          <span class="text-xs font-medium text-gray-700">{{ $userSub->user->name ?? 'Unknown' }}</span>
                        </div>
                        <span class="text-xs px-1.5 py-0.5 rounded-full mt-1 inline-block
                          {{ $task->type === 'photo' ? 'bg-blue-50 text-blue-500' : ($task->type === 'note' ? 'bg-amber-50 text-amber-500' : ($task->type === 'both' ? 'bg-purple-50 text-purple-500' : 'bg-gray-100 text-gray-400')) }}">
                          {{ $task->type === 'photo' ? '📸' : ($task->type === 'note' ? '📝' : ($task->type === 'both' ? '📸📝' : '📎')) }}
                          {{ $task->type === 'both' ? 'Photo + Note' : ucfirst($task->type) }}
                        </span>
                      </td>
                      {{-- Description --}}
                      <td class="px-3 py-3 text-gray-400 text-xs align-top">{{ $task->description ?: '—' }}</td>
                      {{-- Images --}}
                      <td class="px-3 py-3 align-top">
                        @if($userImageFiles->count() > 0)
                          <div class="flex flex-wrap gap-1">
                            @foreach($userImageFiles as $f)
                              <img src="{{ Storage::url($f->file_path) }}"
                                   @click="$dispatch('open-lightbox', '{{ Storage::url($f->file_path) }}')"
                                   class="w-14 h-14 object-cover rounded-lg border border-gray-100 hover:opacity-80 transition shadow-sm cursor-zoom-in"
                                   alt="{{ $f->file_original_name }}">
                            @endforeach
                          </div>
                          @foreach($userOtherFiles as $f)
                            <a href="{{ Storage::url($f->file_path) }}" target="_blank"
                               class="text-xs text-blue-500 hover:underline flex items-center gap-1 mt-1">
                              📎 <span class="truncate max-w-[90px]">{{ $f->file_original_name }}</span>
                            </a>
                          @endforeach
                        @else
                          <span class="text-gray-200">—</span>
                        @endif
                      </td>
                      {{-- Notes --}}
                      <td class="px-3 py-3 align-top">
                        @if($userSub->notes)
                          <p class="text-sm text-gray-600 leading-relaxed line-clamp-3">{{ $userSub->notes }}</p>
                        @else
                          <span class="text-gray-200">—</span>
                        @endif
                      </td>
                      {{-- Submitted by --}}
                      <td class="px-3 py-3 align-top">
                        <div class="flex items-center gap-2">
                          <div class="w-6 h-6 rounded-full bg-indigo-100 flex items-center justify-center text-xs font-bold text-indigo-600 flex-shrink-0">
                            {{ strtoupper(substr($userSub->user->name ?? '?', 0, 1)) }}
                          </div>
                          <div>
                            <p class="text-xs font-semibold text-gray-700 leading-none">{{ $userSub->user->name ?? 'Unknown' }}</p>
                            <p class="text-xs text-gray-400 leading-none mt-0.5">{{ $userSub->created_at->format('h:i A') }}</p>
                          </div>
                        </div>
                        @if($userLastEdit)
                          <div class="flex items-center gap-1.5 mt-1.5 pt-1.5 border-t border-gray-100">
                            <div class="w-5 h-5 rounded-full bg-amber-100 flex items-center justify-center text-xs font-bold text-amber-600 flex-shrink-0">
                              {{ strtoupper(substr($userLastEdit->user->name ?? '?', 0, 1)) }}
                            </div>
                            <div>
                              <p class="text-xs text-amber-600 leading-none">edited by {{ $userLastEdit->user->name ?? 'Unknown' }}</p>
                              <p class="text-xs text-gray-400 leading-none mt-0.5">
                                {{ \Carbon\Carbon::parse($userLastEdit->created_at)->format('h:i A') }}
                                @if($userEditCount > 1) · {{ $userEditCount }} edits @endif
                              </p>
                            </div>
                          </div>
                        @endif
                      </td>
                      {{-- AI Analysis --}}
                      <td class="px-3 py-3 align-top min-w-[220px]">
                        <div x-show="analyzing" class="flex items-center gap-1.5 text-xs text-purple-500">
                          <svg class="animate-spin w-3 h-3 flex-shrink-0" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                          Analyzing…
                        </div>
                        <div x-show="!analyzing && analysis">
                          <p class="text-xs text-gray-700 leading-relaxed whitespace-pre-line" :class="analysisExpanded ? '' : 'line-clamp-4'" x-text="analysis"></p>
                          <div class="flex items-center gap-2 mt-1 flex-wrap">
                            <button @click="analysisExpanded = !analysisExpanded" class="text-xs text-purple-400 hover:text-purple-600" x-text="analysisExpanded ? 'collapse' : 'expand'"></button>
                            <span class="text-xs text-gray-300">·</span>
                            <span class="text-xs text-gray-400" x-text="analyzedBy ? 'by ' + analyzedBy + (analyzedAt ? ' · ' + analyzedAt : '') : ''"></span>
                          </div>
                          <div x-show="promptUsed" class="mt-1">
                            <button @click="showPrompt = !showPrompt" class="text-xs text-gray-300 hover:text-gray-500 underline underline-offset-2" x-text="showPrompt ? 'hide prompt' : 'show prompt'"></button>
                            <pre x-show="showPrompt" class="mt-1 text-xs text-gray-400 bg-gray-50 border border-gray-100 rounded px-2 py-1 whitespace-pre-wrap" x-text="promptUsed"></pre>
                          </div>
                          <div x-show="analysisCount > 0" class="mt-1">
                            <button @click="toggleHistory()" class="text-xs text-purple-300 hover:text-purple-500" x-text="showHistory ? 'hide history' : analysisCount + (analysisCount === 1 ? ' analysis' : ' analyses')"></button>
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
                        <div x-show="!analyzing && analysisError" class="flex items-center gap-1 text-xs text-red-500"><span>⚠</span><span x-text="analysisError"></span></div>
                        <span x-show="!analyzing && !analysis && !analysisError" class="text-gray-200 text-xs">—</span>
                      </td>
                      {{-- Approval Check --}}
                      <td class="px-3 py-3 align-top min-w-[200px]">
                        <div x-show="approving" class="flex items-center gap-1.5 text-xs text-emerald-600">
                          <svg class="animate-spin w-3 h-3 flex-shrink-0" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                          Checking…
                        </div>
                        <div x-show="!approving && approval">
                          <div class="mb-1">
                            <span x-show="approvalVerdict === 'approved'" class="text-xs font-bold px-2 py-0.5 rounded-full bg-green-100 text-green-700">✅ Approved</span>
                            <span x-show="approvalVerdict === 'not_approved'" class="text-xs font-bold px-2 py-0.5 rounded-full bg-red-100 text-red-700">❌ Not Approved</span>
                            <span x-show="approvalVerdict === 'unknown' || !approvalVerdict" class="text-xs font-bold px-2 py-0.5 rounded-full bg-gray-100 text-gray-600">⚠ Unknown</span>
                          </div>
                          <p class="text-xs text-gray-700 leading-relaxed whitespace-pre-line" :class="approvalExpanded ? '' : 'line-clamp-3'" x-text="approval"></p>
                          <div class="flex items-center gap-2 mt-1 flex-wrap">
                            <button @click="approvalExpanded = !approvalExpanded" class="text-xs text-emerald-400 hover:text-emerald-600" x-text="approvalExpanded ? 'collapse' : 'expand'"></button>
                            <span class="text-xs text-gray-300">·</span>
                            <span class="text-xs text-gray-400" x-text="approvalCheckedBy ? 'by '+approvalCheckedBy+(approvalCheckedAt?' · '+approvalCheckedAt:'') : ''"></span>
                          </div>
                          <div x-show="approvalPromptUsed" class="mt-1">
                            <button @click="showApprovalPrompt = !showApprovalPrompt" class="text-xs text-gray-300 hover:text-gray-500 underline underline-offset-2" x-text="showApprovalPrompt ? 'hide prompt' : 'show prompt'"></button>
                            <pre x-show="showApprovalPrompt" class="mt-1 text-xs text-gray-400 bg-gray-50 border border-gray-100 rounded px-2 py-1 whitespace-pre-wrap" x-text="approvalPromptUsed"></pre>
                          </div>
                          <div x-show="approvalCount > 0" class="mt-1">
                            <button @click="toggleApprovalHistory()" class="text-xs text-emerald-300 hover:text-emerald-500" x-text="showApprovalHistory ? 'hide history' : approvalCount + (approvalCount === 1 ? ' check' : ' checks')"></button>
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
                        <div x-show="!approving && approvalError" class="flex items-center gap-1 text-xs text-red-500"><span>⚠</span><span x-text="approvalError"></span></div>
                        <span x-show="!approving && !approval && !approvalError" class="text-gray-200 text-xs">—</span>
                      </td>
                      {{-- Analyze button --}}
                      <td class="px-3 py-3 align-middle">
                        <button @click="runAll()"
                                :disabled="analyzing || approving"
                                :class="(analyzing || approving) ? 'opacity-60 cursor-not-allowed' : 'hover:bg-indigo-700'"
                                class="flex items-center gap-1.5 text-xs px-2.5 py-1.5 rounded-lg bg-indigo-600 text-white transition font-medium whitespace-nowrap">
                          <span x-show="!analyzing && !approving" x-text="(analysisCount > 0 || approvalCount > 0) ? '↻ Re-analyze' : '✦ Analyze'"></span>
                          <span x-show="analyzing || approving" class="flex items-center gap-1">
                            <svg class="animate-spin w-3 h-3" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                            Analyzing…
                          </span>
                        </button>
                      </td>
                    </tr>
                  </tbody>
                @endforeach

                {{-- Unsubmitted users --}}
                @if(isset($unsubmittedUsers) && $unsubmittedUsers->count())
                  <tbody x-show="$store.report.isVisible({{ $task->id }})" x-data="{open: true}" @toggle-task-{{ $task->id }}.window="open = !open">
                    @foreach($unsubmittedUsers as $u)
                      <tr x-show="open" x-transition class="border-b border-gray-50 opacity-50">
                        <td class="px-4 py-2"></td>
                        <td class="px-3 py-2" colspan="8">
                          <div class="flex items-center gap-1.5">
                            <div class="w-5 h-5 rounded-full bg-gray-100 flex items-center justify-center text-xs font-bold text-gray-400">
                              {{ strtoupper(substr($u->name, 0, 1)) }}
                            </div>
                            <span class="text-xs text-gray-400">{{ $u->name }} — not yet submitted</span>
                          </div>
                        </td>
                      </tr>
                    @endforeach
                  </tbody>
                @endif
                @endif
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

  {{-- ===== ALPINE REPORT FILTER STORE ===== --}}
  <script>
    document.addEventListener('alpine:init', () => {
      Alpine.store('report', {
        filter:  'all',
        search:  '',
        dept:    '',
        user:    '',
        verdict: 'all',
        tasks:   {!! \Illuminate\Support\Js::from($taskFilterData) !!},
        isVisible(taskId) {
          const t = this.tasks[taskId];
          if (!t) return true;
          if (this.filter === 'done'    && !t.done) return false;
          if (this.filter === 'pending' &&  t.done) return false;
          if (this.search && !t.title.includes(this.search.toLowerCase())) return false;
          if (this.dept   && t.dept !== this.dept.toLowerCase()) return false;
          if (this.user   && !t.users.some(u => u.includes(this.user))) return false;
          if (this.verdict === 'approved'     && !(t.verdicts ?? []).includes('approved'))     return false;
          if (this.verdict === 'not_approved' && !(t.verdicts ?? []).includes('not_approved')) return false;
          return true;
        }
      });
    });
  </script>

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

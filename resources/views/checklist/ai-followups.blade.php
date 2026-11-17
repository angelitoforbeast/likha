<x-layout>
  <x-slot name="heading">Marketing head</x-slot>
  <x-slot name="title">Marketing head · AI follow-ups</x-slot>

  @php
    $draftCards = $drafts->map($card)->values();
    $earliest   = $window ? \App\Services\ChecklistDueToday::hhmm($window[0]) : '21:00';
    $capLabel   = '₱' . (fmod($budget['cap'], 1.0) == 0.0 ? number_format($budget['cap']) : number_format($budget['cap'], 2));
    $budgetText = match ($budget['tone']) {
        'red'   => 'Budget reached: no new drafts this month',
        'amber' => 'Close to the cap',
        default => 'Within budget',
    };
    $tabs = [
        'drafts'   => ['Drafts', $drafts->count()],
        'sent'     => ['Sent', $sent->count()],
        'rejected' => ['Rejected', $rejected->count()],
    ];
    $chip = 'inline-flex items-center gap-1 text-xs font-medium px-2 py-0.5 rounded-full border';
  @endphp

  @php $settingErrors = $errors->hasAny(['monthly_cap_php', 'php_per_usd', 'input_usd_per_1m', 'output_usd_per_1m']); @endphp

  <style>
    .ai-inbox ::selection { background: #ede9fe; color: #4c1d95; }
    .ai-inbox textarea { caret-color: #7c3aed; }
    .ai-inbox .tabular { font-variant-numeric: tabular-nums; }
    .ai-inbox .pretty { text-wrap: pretty; }
    .ai-inbox kbd { font: 600 11px/1 ui-sans-serif, system-ui, sans-serif; color: #374151; background: #fff; border: 1px solid #d1d5db; border-bottom-width: 2px; border-radius: 4px; padding: 2px 5px; }
    @media (prefers-reduced-motion: reduce) { .ai-inbox * { transition: none !important; } }
  </style>

  <div class="ai-inbox min-h-screen bg-gray-50 mt-16" x-data="aiInbox({{ $drafts->count() }}, @js($settingErrors))" @keydown.window="key($event)" @pagehide.window="flush()">

    {{-- ===== HEADER ===== --}}
    <div class="bg-white border-b border-gray-200 shadow-sm">
      <div class="max-w-3xl mx-auto px-4 pt-4 pb-0">
        <div class="flex items-start justify-between gap-4">
          <div class="min-w-0">
            <h1 class="text-base font-bold text-gray-800">Marketing head</h1>
            <p class="text-xs text-gray-500 pretty">Mira, the AI marketing head, drafts follow-ups for late or not approved tasks. Staff see one only after you approve it.</p>
          </div>
          <a href="{{ route('checklist.index') }}" class="flex-shrink-0 text-xs px-3 py-1.5 rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500">Checklist</a>
        </div>

        <div class="mt-3 grid gap-2 sm:gap-3 sm:grid-cols-2">
          {{-- Switch --}}
          <form method="POST" action="{{ route('checklist.ai-followups.settings') }}" class="flex items-center gap-3 rounded-xl bg-gray-50 px-3 py-2.5">
            @csrf
            <input type="hidden" name="enabled" value="{{ $settings['enabled'] ? 0 : 1 }}">
            <button type="submit" role="switch" aria-checked="{{ $settings['enabled'] ? 'true' : 'false' }}" aria-label="Marketing head"
                    class="relative inline-flex h-6 w-11 flex-shrink-0 rounded-full transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 focus-visible:ring-offset-2 {{ $settings['enabled'] ? 'bg-violet-600' : 'bg-gray-500' }}">
              <span class="absolute top-0.5 h-5 w-5 rounded-full bg-white shadow transition {{ $settings['enabled'] ? 'left-[22px]' : 'left-0.5' }}"></span>
            </button>
            <div class="min-w-0">
              <p class="text-sm font-semibold text-gray-800">{{ $settings['enabled'] ? 'On' : 'Off' }}</p>
              <p class="text-xs text-gray-500">{{ $settings['enabled'] ? 'On: drafts every 30 minutes, 9 AM to 9 PM' : 'Off: the Marketing head drafts nothing' }}</p>
            </div>
          </form>

          {{-- Budget --}}
          <div class="rounded-xl bg-gray-50 px-3 py-2.5" data-budget="{{ $budget['tone'] }}">
            <div class="flex items-baseline justify-between gap-2">
              <p class="text-sm font-semibold text-gray-800 tabular">₱{{ number_format($budget['spend'], 2) }} of {{ $capLabel }} this month</p>
              <button type="button" @click="settingsOpen = !settingsOpen" :aria-expanded="settingsOpen.toString()" aria-controls="ai-settings"
                      class="-my-1.5 -mr-2 px-2 py-1.5 text-xs font-medium text-blue-700 hover:underline underline-offset-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 rounded">Settings</button>
            </div>
            <div class="mt-1.5 h-1.5 rounded-full bg-gray-200 overflow-hidden" role="meter" aria-valuemin="0" aria-valuemax="{{ $budget['cap'] }}" aria-valuenow="{{ round($budget['spend'], 2) }}" aria-label="Spend this month">
              <div class="h-1.5 rounded-full {{ ['red' => 'bg-red-500', 'amber' => 'bg-amber-500', 'blue' => 'bg-blue-500'][$budget['tone']] }}" style="width: {{ min(100, round($budget['pct'], 1)) }}%"></div>
            </div>
            <p class="mt-1 text-xs {{ ['red' => 'text-red-700 font-medium', 'amber' => 'text-amber-800 font-medium', 'blue' => 'text-gray-500'][$budget['tone']] }}">{{ $budgetText }}</p>
          </div>
        </div>

        {{-- Settings (CEO only, like the whole page) --}}
        <form id="ai-settings" x-show="settingsOpen" x-cloak @if(!$settingErrors) style="display:none" @endif method="POST" action="{{ route('checklist.ai-followups.settings') }}"
              class="mt-3 rounded-xl border border-gray-100 bg-white p-3 grid grid-cols-2 sm:grid-cols-4 gap-3">
          @csrf
          @foreach([
            ['monthly_cap_php', 'Monthly cap (₱)', $settings['monthly_cap_php'], '1'],
            ['php_per_usd', '₱ per US$', $settings['php_per_usd'], '0.001'],
            ['input_usd_per_1m', 'Input US$ / 1M tokens', $settings['input_usd_per_1m'], '0.001'],
            ['output_usd_per_1m', 'Output US$ / 1M tokens', $settings['output_usd_per_1m'], '0.001'],
          ] as [$name, $label, $value, $step])
            <label class="block">
              <span class="block text-xs text-gray-500 mb-1">{{ $label }}</span>
              <input type="number" name="{{ $name }}" value="{{ old($name, $value) }}" step="{{ $step }}" min="0" required
                     class="w-full text-sm tabular border border-gray-200 rounded-lg px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-blue-500">
            </label>
          @endforeach
          <div class="col-span-2 sm:col-span-4 flex items-center justify-between gap-3 flex-wrap">
            <p class="text-xs text-gray-500">Model: {{ $settings['model'] }}. The cap is checked before every AI call.</p>
            <button type="submit" class="text-sm font-medium px-3 py-1.5 rounded-lg bg-blue-600 text-white hover:bg-blue-700 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">Save</button>
          </div>
        </form>

        {{-- Tabs --}}
        <nav class="mt-3 -mb-px flex gap-5" aria-label="Follow-ups">
          @foreach($tabs as $key => [$label, $count])
            <a href="{{ route('checklist.ai-followups', $key === 'drafts' ? [] : ['tab' => $key]) }}" @if($tab === $key) aria-current="page" @endif
               class="inline-flex items-center gap-1.5 border-b-2 px-0.5 pb-2.5 pt-1.5 text-sm transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-blue-500 {{ $tab === $key ? 'border-blue-600 text-gray-900 font-semibold' : 'border-transparent text-gray-500 hover:text-gray-800' }}">
              {{ $label }}
              <span class="tabular text-xs rounded-full px-1.5 {{ $tab === $key ? 'bg-blue-50 text-blue-700' : 'bg-gray-100 text-gray-600' }}" @if($key === 'drafts') x-text="count" @endif>{{ $count }}</span>
            </a>
          @endforeach
        </nav>
      </div>
    </div>

    <div class="max-w-3xl mx-auto px-4 pt-5 pb-24 space-y-3">

      @if(session('success'))
        <div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl text-sm">{{ session('success') }}</div>
      @endif
      @if(session('error'))
        <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-xl text-sm">{{ session('error') }}</div>
      @endif
      @if($errors->any())
        <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-xl text-sm">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
      @endif

      {{-- ===== DRAFTS ===== --}}
      @if($tab === 'drafts')
        @if($drafts->isNotEmpty())
          <p class="hidden sm:block text-xs text-gray-500"><kbd>J</kbd> <kbd>K</kbd> move between drafts · <kbd>A</kbd> approve · <kbd>E</kbd> edit · <kbd>R</kbd> reject · <kbd>U</kbd> undo</p>
        @endif

        @foreach($draftCards as $c)
          <article data-card="{{ $c['id'] }}" tabindex="-1" aria-label="Draft for {{ $c['name'] }}"
                   x-data="draftCard(@js(['id' => $c['id'], 'message' => $c['message'], 'deadline' => $c['deadline'], 'name' => $c['name']]))"
                   x-show="!gone"
                   x-transition:leave="transition ease-out duration-200" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-1"
                   @card-action="act($event.detail)"
                   class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 sm:p-5 outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 focus-visible:ring-offset-gray-50">
            {{-- Person and why --}}
            <div class="flex items-start justify-between gap-3">
              <div class="flex items-center gap-2.5 min-w-0">
                <span class="w-8 h-8 rounded-full bg-gray-100 text-gray-700 text-xs font-bold flex items-center justify-center flex-shrink-0" aria-hidden="true">{{ $c['initials'] }}</span>
                <div class="min-w-0">
                  <p class="text-sm font-semibold text-gray-900 truncate">{{ $c['name'] }}</p>
                  <p class="text-xs text-gray-500">{{ $c['department'] ?: 'Marketing' }}</p>
                </div>
              </div>
              @if($c['reason'] === 'late')
                <span class="{{ $chip }} bg-amber-50 text-amber-800 border-amber-200 flex-shrink-0 tabular"><i class="fa-solid fa-clock text-[10px]" aria-hidden="true"></i>{{ $c['late_since'] ? 'Late since ' . $c['late_since'] : 'Late' }}</span>
              @else
                <span @if($c['verdict']) title="{{ $c['verdict'] }}" @endif class="{{ $chip }} bg-red-50 text-red-700 border-red-200 flex-shrink-0"><i class="fa-solid fa-circle-xmark text-[10px]" aria-hidden="true"></i>Not approved</span>
              @endif
            </div>

            <p class="mt-2 text-xs text-gray-500">Task: <span class="text-gray-700">{{ $c['task'] }}</span></p>
            @if($c['reason'] === 'not_approved' && $c['verdict'])
              <p class="mt-1 text-xs leading-relaxed text-gray-700 pretty"><span class="font-semibold text-red-700">Why not approved:</span> {{ $c['verdict'] }}</p>
            @endif

            {{-- The draft (the AI's words) --}}
            <div class="mt-3">
              <p x-show="mode !== 'edit'" class="rounded-xl bg-violet-50 px-3.5 py-3 text-[15px] leading-relaxed text-gray-900 pretty">{{ $c['message'] }}</p>
              <template x-if="mode === 'edit'">
                <div>
                  <label class="sr-only" :for="'msg-' + id">Message</label>
                  <textarea :id="'msg-' + id" x-model="message" rows="3" x-init="$nextTick(() => $el.focus())" @keydown.ctrl.enter.prevent="saveEdit()" @keydown.meta.enter.prevent="saveEdit()"
                            class="w-full rounded-xl bg-white border border-violet-300 px-3.5 py-3 text-[15px] leading-relaxed text-gray-900 focus:outline-none focus:ring-2 focus:ring-violet-500"></textarea>
                  <div class="mt-2 flex items-end justify-between gap-3 flex-wrap">
                    <label class="block">
                      <span class="block text-xs text-gray-500 mb-1">Deadline today</span>
                      <input type="time" x-model="deadline" @change="syncClock()" min="{{ $earliest }}" max="21:00" step="60"
                             class="text-sm tabular border border-gray-200 rounded-lg px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-blue-500">
                      <span class="block text-xs text-gray-500 mt-1">Between {{ \App\Services\AiFollowups::clock($earliest) }} and 9:00 PM</span>
                    </label>
                    <p class="text-xs tabular" :class="message.trim().length > {{ \App\Services\AiFollowups::MAX_CHARS }} ? 'text-red-700 font-semibold' : 'text-gray-500'"
                       x-text="message.trim().length + ' / {{ \App\Services\AiFollowups::MAX_CHARS }}'"></p>
                  </div>
                </div>
              </template>
            </div>

            {{-- Deadline and cost --}}
            <div x-show="mode !== 'edit'" class="mt-2.5 flex items-center gap-2 flex-wrap">
              <span class="{{ $chip }} bg-white text-gray-700 border-gray-200 tabular"><i class="fa-regular fa-clock text-[10px]" aria-hidden="true"></i>by {{ $c['clock'] }}</span>
              <span class="text-xs text-gray-500 tabular">{{ $c['cost'] }}@if($c['model']) · {{ $c['model'] }}@endif · {{ $c['date'] }}</span>
            </div>

            {{-- Reject reason --}}
            <template x-if="mode === 'reject'">
              <div class="mt-3">
                <label :for="'note-' + id" class="block text-xs text-gray-600 mb-1">Why not? The Marketing head learns from this.</label>
                <textarea :id="'note-' + id" x-model="note" rows="2" x-init="$nextTick(() => $el.focus())" @keydown.ctrl.enter.prevent="saveReject()" @keydown.meta.enter.prevent="saveReject()"
                          class="w-full rounded-xl border border-gray-200 px-3 py-2 text-sm text-gray-900 focus:outline-none focus:ring-2 focus:ring-red-500"></textarea>
                <p class="text-xs tabular text-right" :class="note.trim().length > {{ \App\Services\AiFollowups::MAX_NOTE }} ? 'text-red-700 font-semibold' : 'text-gray-500'"
                   x-text="note.trim().length + ' / {{ \App\Services\AiFollowups::MAX_NOTE }}'"></p>
              </div>
            </template>

            <p x-show="error" x-text="error" class="mt-2 text-xs text-red-700" role="alert"></p>

            {{-- Actions --}}
            <div class="mt-3 flex items-center gap-2 flex-wrap">
              <template x-if="mode === 'view'">
                <div class="flex items-center gap-2 flex-wrap">
                  <button type="button" @click="act('approve')" :disabled="busy"
                          class="min-h-[44px] sm:min-h-[40px] text-sm font-semibold px-4 py-2 rounded-lg bg-blue-600 text-white hover:bg-blue-700 disabled:opacity-60 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">Approve</button>
                  <button type="button" @click="act('edit')"
                          class="min-h-[44px] sm:min-h-[40px] text-sm font-medium px-3.5 py-2 rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-50 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500">Edit</button>
                  <button type="button" @click="act('reject')"
                          class="min-h-[44px] sm:min-h-[40px] text-sm font-medium px-3.5 py-2 rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-50 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500">Reject</button>
                </div>
              </template>
              <template x-if="mode === 'edit'">
                <div class="flex items-center gap-2 flex-wrap">
                  <button type="button" @click="saveEdit()" :disabled="busy || !message.trim() || message.trim().length > {{ \App\Services\AiFollowups::MAX_CHARS }} || !deadline"
                          class="min-h-[44px] sm:min-h-[40px] text-sm font-semibold px-4 py-2 rounded-lg bg-blue-600 text-white hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">Save and approve</button>
                  <button type="button" @click="cancel()"
                          class="min-h-[44px] sm:min-h-[40px] text-sm font-medium px-3.5 py-2 rounded-lg text-gray-600 hover:bg-gray-100 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500">Cancel</button>
                </div>
              </template>
              <template x-if="mode === 'reject'">
                <div class="flex items-center gap-2 flex-wrap">
                  <button type="button" @click="saveReject()" :disabled="busy || !note.trim() || note.trim().length > {{ \App\Services\AiFollowups::MAX_NOTE }}"
                          class="min-h-[44px] sm:min-h-[40px] text-sm font-semibold px-4 py-2 rounded-lg border border-red-300 text-red-700 bg-white hover:bg-red-50 disabled:opacity-50 disabled:cursor-not-allowed transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2">Reject</button>
                  <button type="button" @click="cancel()"
                          class="min-h-[44px] sm:min-h-[40px] text-sm font-medium px-3.5 py-2 rounded-lg text-gray-600 hover:bg-gray-100 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500">Cancel</button>
                </div>
              </template>
            </div>
          </article>
        @endforeach

        {{-- Empty state (also after the last draft is cleared) --}}
        <div x-show="count === 0" @if($drafts->isNotEmpty()) style="display:none" @endif
             class="bg-white rounded-2xl border border-gray-100 shadow-sm px-6 py-14 text-center">
          <span class="mx-auto mb-3 w-10 h-10 rounded-xl {{ $budget['reached'] ? 'bg-red-50 text-red-600' : 'bg-violet-50 text-violet-600' }} flex items-center justify-center" aria-hidden="true">
            <i class="fa-solid {{ $budget['reached'] ? 'fa-wallet' : 'fa-wand-magic-sparkles' }} text-sm"></i>
          </span>
          @if($budget['reached'])
            <p class="text-gray-800 font-semibold">Budget reached.</p>
            <p class="text-sm text-gray-500 mt-1">No new drafts until next month, or raise the cap.</p>
          @elseif(!$settings['enabled'])
            <p class="text-gray-800 font-semibold">No drafts.</p>
            <p class="text-sm text-gray-500 mt-1">The Marketing head is off. Switch it on to start drafting.</p>
          @else
            <p class="text-gray-800 font-semibold">No drafts.</p>
            <p class="text-sm text-gray-500 mt-1">The Marketing head checks every 30 minutes from 9 AM to 9 PM.</p>
          @endif
        </div>
      @endif

      {{-- ===== SENT ===== --}}
      @if($tab === 'sent')
        @if($sent->isEmpty())
          <div class="bg-white rounded-2xl border border-gray-100 shadow-sm px-6 py-14 text-center">
            <span class="mx-auto mb-3 w-10 h-10 rounded-xl bg-gray-100 text-gray-500 flex items-center justify-center" aria-hidden="true"><i class="fa-solid fa-paper-plane text-sm"></i></span>
            <p class="text-gray-800 font-semibold">Nothing sent yet.</p>
            <p class="text-sm text-gray-500 mt-1">Approved follow-ups show here with Waiting, Done or Expired.</p>
          </div>
        @else
          <ul class="bg-white rounded-2xl border border-gray-100 shadow-sm divide-y divide-gray-100">
            @foreach($sent as $f)
              @php
                $c = $card($f);
                $state = $states[$f->id];
                [$stateLabel, $stateClass, $stateHint] = [
                    'waiting' => ['Waiting', 'bg-blue-50 text-blue-700 border-blue-200', 'Not done yet'],
                    'done'    => ['Done', 'bg-green-50 text-green-700 border-green-200', 'The task was approved'],
                    'expired' => ['Expired', 'bg-gray-100 text-gray-600 border-gray-200', 'The day ended first'],
                ][$state];
              @endphp
              <li class="px-4 py-3.5 flex gap-3">
                <span class="w-8 h-8 rounded-full bg-gray-100 text-gray-700 text-xs font-bold flex items-center justify-center flex-shrink-0" aria-hidden="true">{{ $c['initials'] }}</span>
                <div class="min-w-0 flex-1">
                  <div class="flex items-center justify-between gap-2 flex-wrap">
                    <p class="text-sm font-semibold text-gray-900">{{ $c['name'] }} <span class="font-normal text-xs text-gray-500">· {{ $c['task'] }}</span></p>
                    <span class="text-xs text-gray-500 tabular">{{ $c['date'] }} · by {{ $c['clock'] }}</span>
                  </div>
                  <p class="mt-1 text-sm text-gray-800 leading-relaxed pretty">{{ $c['message'] }}</p>
                  <div class="mt-1.5 flex items-center gap-2 flex-wrap">
                    <span data-state="{{ $state }}" title="{{ $stateHint }}" class="{{ $chip }} {{ $stateClass }}">{{ $stateLabel }}</span>
                    @if($c['edited'])
                      <span class="{{ $chip }} bg-white text-gray-700 border-gray-300">Edited by you</span>
                    @endif
                    <span class="text-xs text-gray-500">{{ $state === 'waiting' ? 'Approved ' . $c['reviewed'] : $stateHint }}</span>
                  </div>
                  @if($c['edited'])
                    <details class="mt-1.5">
                      <summary class="text-xs text-gray-500 cursor-pointer hover:text-gray-800 w-fit rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500">Show the AI's draft</summary>
                      <p class="mt-1 rounded-lg bg-violet-50 px-3 py-2 text-xs text-gray-700 leading-relaxed">{{ $c['draft'] }}</p>
                    </details>
                  @endif
                </div>
              </li>
            @endforeach
          </ul>
        @endif
      @endif

      {{-- ===== REJECTED ===== --}}
      @if($tab === 'rejected')
        @if($rejected->isEmpty())
          <div class="bg-white rounded-2xl border border-gray-100 shadow-sm px-6 py-14 text-center">
            <span class="mx-auto mb-3 w-10 h-10 rounded-xl bg-gray-100 text-gray-500 flex items-center justify-center" aria-hidden="true"><i class="fa-solid fa-inbox text-sm"></i></span>
            <p class="text-gray-800 font-semibold">Nothing rejected.</p>
            <p class="text-sm text-gray-500 mt-1">When you reject a draft, your reason teaches the next ones.</p>
          </div>
        @else
          <ul class="bg-white rounded-2xl border border-gray-100 shadow-sm divide-y divide-gray-100">
            @foreach($rejected as $f)
              @php $c = $card($f); @endphp
              <li class="px-4 py-3.5 flex gap-3">
                <span class="w-8 h-8 rounded-full bg-gray-100 text-gray-700 text-xs font-bold flex items-center justify-center flex-shrink-0" aria-hidden="true">{{ $c['initials'] }}</span>
                <div class="min-w-0 flex-1">
                  <div class="flex items-center justify-between gap-2 flex-wrap">
                    <p class="text-sm font-semibold text-gray-900">{{ $c['name'] }} <span class="font-normal text-xs text-gray-500">· {{ $c['task'] }}</span></p>
                    <span class="text-xs text-gray-500 tabular">{{ $c['date'] }}</span>
                  </div>
                  <p class="mt-1 rounded-lg bg-violet-50 px-3 py-2 text-sm text-gray-700 leading-relaxed pretty">{{ $c['draft'] ?: $c['message'] }}</p>
                  @if($c['status'] === 'expired')
                    <p class="mt-1.5"><span class="{{ $chip }} bg-gray-100 text-gray-600 border-gray-200">Expired, not reviewed</span></p>
                  @else
                    <p class="mt-1.5 text-sm text-gray-800"><span class="text-xs font-semibold text-gray-600">Your reason:</span> {{ $c['note'] }}</p>
                    <p class="text-xs text-gray-500 mt-0.5">Rejected by {{ $c['reviewer'] }} · {{ $c['reviewed'] }}</p>
                  @endif
                </div>
              </li>
            @endforeach
          </ul>
        @endif
      @endif
    </div>

    {{-- Undo toast --}}
    <div role="status" aria-live="polite" class="sr-only" x-text="toast ? toast.text : ''"></div>
    <div x-show="toast" x-cloak style="display:none" x-transition.opacity.duration.150ms @mouseenter="hold(true)" @mouseleave="hold(false)" @focusin="hold(true)" @focusout="hold(false)"
         class="fixed inset-x-4 bottom-4 sm:inset-x-auto sm:left-1/2 sm:-translate-x-1/2 z-50 flex items-center gap-3 rounded-xl bg-gray-900 text-white pl-4 pr-2 py-2 shadow-lg shadow-gray-900/20 sm:min-w-[380px]">
      <p class="text-sm flex-1 py-1.5" x-text="toast ? toast.text : ''" aria-hidden="true"></p>
      <button type="button" x-show="toast && toast.undo" @click="undo()"
              class="min-h-[44px] sm:min-h-[36px] px-3 text-sm font-semibold text-blue-300 hover:text-blue-200 rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-300">Undo <kbd class="ml-1 !bg-gray-800 !text-gray-200 !border-gray-600">U</kbd></button>
    </div>
  </div>

  <script>
    const AI_FOLLOWUP_URL = @js(url('/checklist/ai-followups'));
    const UNDO_MS = 5000;

    function aiPost(path, body = {}, keepalive = false) {
      return fetch(`${AI_FOLLOWUP_URL}/${path}`, {
        method: 'POST',
        keepalive,
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
        },
        body: JSON.stringify(body),
      }).then(async (r) => {
        const data = await r.json().catch(() => ({}));
        if (!r.ok) {
          const errors = data.errors ? Object.values(data.errors).flat() : [];
          throw new Error(errors[0] || data.message || `Could not save (${r.status}).`);
        }
        return data;
      });
    }

    // Ang page: bilang ng drafts, keyboard (J/K/A/E/R/Esc), at ang Approve na may 5 segundong Undo:
    // ang approve ay ipinapadala lang pagkatapos ng 5 segundo (o agad kapag umalis sa page), kaya ang na-undo ay hindi umaabot sa staff.
    const REDUCED_MOTION = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const clock = (hhmm) => {
      const [h, m] = String(hhmm || '').split(':').map(Number);
      if (Number.isNaN(h) || Number.isNaN(m)) return '';
      return `${(h % 12) || 12}:${String(m).padStart(2, '0')} ${h < 12 ? 'AM' : 'PM'}`;
    };

    function aiInbox(count, settingsOpen) {
      return {
        count,
        settingsOpen,
        toast: null,
        pending: null,
        cards() { return [...document.querySelectorAll('[data-card]')].filter((el) => el.style.display !== 'none'); },
        focused() { return document.activeElement?.closest?.('[data-card]'); },
        focusAfter(el) {
          const list = this.cards();
          const i = list.indexOf(el);
          const next = list[i + 1] || list[i - 1];
          next ? next.focus() : null;
        },
        move(step) {
          const list = this.cards();
          if (!list.length) return;
          const i = list.indexOf(this.focused());
          const next = list[Math.max(0, Math.min(list.length - 1, i < 0 ? 0 : i + step))];
          next.focus();
          next.scrollIntoView({ block: 'nearest', behavior: REDUCED_MOTION ? 'auto' : 'smooth' });
        },
        key(e) {
          if (e.metaKey || e.ctrlKey || e.altKey) return;
          const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName) || e.target.isContentEditable;
          if (e.key === 'Escape') { this.focused()?.dispatchEvent(new CustomEvent('card-action', { detail: 'cancel' })); return; }
          if (typing) return;
          const k = e.key.toLowerCase();
          if (k === 'u' && this.pending) { e.preventDefault(); this.undo(); return; }
          if (k === 'j' || k === 'k') { e.preventDefault(); this.move(k === 'j' ? 1 : -1); return; }
          const map = { a: 'approve', e: 'edit', r: 'reject' };
          if (!map[k]) return;
          // Kumikilos lang sa draft na mismong naka-focus; kapag wala pa, ang unang pindot ay nagfo-focus lang ng unang draft
          const card = document.activeElement?.matches?.('[data-card]') ? document.activeElement : null;
          e.preventDefault();
          if (!card) {
            if (!this.focused() && !['BUTTON', 'A', 'SUMMARY'].includes(e.target.tagName)) this.move(0);
            return;
          }
          card.dispatchEvent(new CustomEvent('card-action', { detail: map[k] }));
        },
        left(el) {
          this.count = Math.max(0, this.count - 1);
          this.focusAfter(el);
        },
        approveLater(card, el) {
          this.flush();
          this.left(el);
          this.pending = { card, el, timer: setTimeout(() => this.send(), UNDO_MS) };
          this.toast = { text: `Approved · ${card.name.split(' ')[0]} will see it on the checklist. Press U to undo.`, undo: true };
        },
        send(keepalive = false) {
          const p = this.pending;
          if (!p) return;
          clearTimeout(p.timer);
          this.pending = null;
          this.toast = null;
          aiPost(`${p.card.id}/approve`, {}, keepalive).catch((err) => {
            p.card.gone = false;
            p.card.error = err.message;
            this.count++;
          });
        },
        flush() { if (this.pending) this.send(true); },
        // Habang nasa toast ang mouse o focus, hindi tumatakbo ang 5 segundo; nagsisimula ulit paglabas
        hold(on) {
          const p = this.pending;
          if (!p) return;
          clearTimeout(p.timer);
          p.timer = on ? null : setTimeout(() => this.send(), UNDO_MS);
        },
        undo() {
          const p = this.pending;
          if (!p) return;
          clearTimeout(p.timer);
          this.pending = null;
          this.toast = null;
          p.card.gone = false;
          this.count++;
          this.$nextTick(() => p.el.focus());
        },
        notify(text) {
          this.toast = { text, undo: false };
          setTimeout(() => { if (this.toast && !this.toast.undo) this.toast = null; }, 2500);
        },
      };
    }

    function draftCard(init) {
      return {
        ...init,
        original: { message: init.message, deadline: init.deadline },
        mode: 'view',
        note: '',
        error: '',
        busy: false,
        gone: false,
        act(action) {
          if (this.gone || this.busy) return;
          if (action === 'cancel') return this.cancel();
          if (this.mode !== 'view') return;
          this.error = '';
          if (action === 'approve') { this.gone = true; this.approveLater(this, this.$el); }
          if (action === 'edit') this.mode = 'edit';
          if (action === 'reject') this.mode = 'reject';
        },
        // Ang oras na nakasulat sa mensahe ay sumusunod sa deadline, para hindi magkaiba ang text at ang "by" chip ng staff
        shownDeadline: init.deadline,
        syncClock() {
          const before = clock(this.shownDeadline);
          const after = clock(this.deadline);
          if (before && after && before !== after && this.message.includes(before)) {
            this.message = this.message.split(before).join(after);
          }
          if (after) this.shownDeadline = this.deadline;
        },
        cancel() {
          this.shownDeadline = this.original.deadline;
          this.mode = 'view';
          this.message = this.original.message;
          this.deadline = this.original.deadline;
          this.note = '';
          this.error = '';
          this.$el.focus();
        },
        async save(path, body, text) {
          this.busy = true;
          this.error = '';
          try {
            await aiPost(`${this.id}/${path}`, body);
            this.gone = true;
            this.left(this.$el);
            this.notify(text);
          } catch (err) {
            this.error = err.message;
          } finally {
            this.busy = false;
          }
        },
        saveEdit() { this.save('edit', { message: this.message.trim(), deadline: this.deadline }, `Edited and approved · ${this.name.split(' ')[0]} will see it`); },
        saveReject() { this.save('reject', { note: this.note.trim() }, 'Rejected · your reason is kept for the next drafts'); },
      };
    }
  </script>
</x-layout>

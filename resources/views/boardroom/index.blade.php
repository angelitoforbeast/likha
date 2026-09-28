<x-layout>
  <x-slot name="title">AI Boardroom</x-slot>
  <x-slot name="heading">AI Boardroom</x-slot>

  {{-- /boardroom — CEO lang. Ang lahat ng output ng model ay ipinapakita bilang TEXT (x-text), hindi bilang markup.
       Walang API key na dumadaan sa page na ito; nasa /boardroom/agents ang settings ng bawat role. --}}
  <style>
    .br-body { white-space: pre-wrap; overflow-wrap: anywhere; }
    .br-dots span { animation: br-blink 1.2s infinite both; }
    .br-dots span:nth-child(2) { animation-delay: .2s; }
    .br-dots span:nth-child(3) { animation-delay: .4s; }
    @keyframes br-blink { 0%, 80%, 100% { opacity: .2; } 40% { opacity: 1; } }
    @media (prefers-reduced-motion: reduce) { .br-dots span { animation: none; } }
    .br-flash { animation: br-flash 1.4s ease-out 1; }
    @keyframes br-flash { 0% { background: #fef3c7; } 100% { background: transparent; } }
    /* Phone: 16px ang font ng mga input para hindi mag-zoom ang iOS kapag nag-focus */
    @media (max-width: 767px) { .br-page input, .br-page select, .br-page textarea { font-size: 16px; } }
  </style>

  {{-- Mobile (< md): isang pane lang ang nakikita — listahan O usapan. Ang status panel ay drawer hanggang lg. --}}
  <div class="br-page mt-16 flex bg-gray-50 text-gray-900" style="height: calc(100vh - 4rem); height: calc(100dvh - 4rem);" x-data="boardroom()" x-init="init()" x-cloak>

    {{-- ═════════════ KALIWA: projects + meetings ═════════════ --}}
    <aside class="w-full shrink-0 flex-col border-r border-gray-200 bg-white md:flex md:w-72" x-bind:class="pane === 'list' ? 'flex' : 'hidden'">
      <div class="px-4 pt-4 pb-3 border-b border-gray-100">
        <div class="flex items-center justify-between">
          <h2 class="text-base font-semibold tracking-tight">AI Boardroom</h2>
          <a href="{{ route('boardroom.agents') }}" class="text-xs font-medium text-indigo-700 hover:underline">Agents / Roles</a>
        </div>
        <div class="mt-3 flex gap-2">
          <button type="button" class="flex-1 rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
                  x-on:click="openMeetingForm()" x-bind:disabled="!projects.length">+ Meeting</button>
          <button type="button" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50"
                  x-on:click="modal = 'project'">+ Project</button>
        </div>
      </div>

      <div class="flex-1 overflow-y-auto px-2 py-2">
        <template x-if="!loading && !projects.length">
          <p class="px-3 py-6 text-sm text-gray-500">Wala pang project. Gumawa muna ng project, tapos magdagdag ng meeting.</p>
        </template>
        <template x-for="p in projects" x-bind:key="'p' + p.id">
          <div class="mb-3">
            <div class="flex items-center justify-between px-2 py-1">
              <span class="truncate text-xs font-semibold uppercase tracking-wide text-gray-500" x-text="p.name"></span>
              <button type="button" class="text-xs text-gray-400 hover:text-indigo-700" title="Bagong meeting sa project na ito"
                      x-on:click="openMeetingForm(p.id)">+</button>
            </div>
            <template x-if="!p.meetings.length">
              <p class="px-3 py-1 text-xs text-gray-400">Walang meeting.</p>
            </template>
            <template x-for="m in p.meetings" x-bind:key="'m' + m.id">
              <button type="button" class="flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm hover:bg-gray-100"
                      x-bind:class="selectedId === m.id ? 'bg-indigo-50 text-indigo-900' : 'text-gray-700'" x-on:click="select(m.id)">
                <span class="h-2 w-2 shrink-0 rounded-full" x-bind:class="dot(m.status)"></span>
                <span class="min-w-0 flex-1 truncate" x-text="m.title"></span>
                <span class="shrink-0 text-[11px] text-gray-400" x-text="statusLabel(m.status)"></span>
              </button>
            </template>
          </div>
        </template>
      </div>

      <div class="flex flex-wrap gap-x-4 gap-y-1 border-t border-gray-100 px-4 py-3">
        <button type="button" class="text-xs font-medium text-gray-600 hover:text-indigo-700" x-on:click="openKnowledge()">Company knowledge</button>
        <a href="{{ route('boardroom.resources') }}" class="text-xs font-medium text-gray-600 hover:text-indigo-700">Resources</a>
      </div>
    </aside>

    {{-- ═════════════ GITNA: usapan ═════════════ --}}
    <section class="min-w-0 flex-1 flex-col md:flex" x-bind:class="pane === 'chat' ? 'flex' : 'hidden'">
      <template x-if="!state">
        <div class="flex flex-1 items-center justify-center p-6 text-center sm:p-10">
          <div class="max-w-md">
            <button type="button" class="mb-4 rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 md:hidden" x-on:click="pane = 'list'">‹ Mga meeting</button>
            <p class="text-lg font-semibold">Pumili o gumawa ng meeting</p>
            <p class="mt-2 text-sm text-gray-500">Bawat role (CEO, CTO, COO, Reviewer) ay sumasagot gamit ang sarili nitong hiwalay na API call at sarili nitong API key.</p>
            <p class="mt-4 text-sm text-red-700" x-show="error" x-text="error"></p>
          </div>
        </div>
      </template>

      <template x-if="state">
        <div class="flex min-h-0 flex-1 flex-col">
          {{-- Header ng meeting --}}
          <header class="border-b border-gray-200 bg-white px-3 py-3 sm:px-5">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
              <button type="button" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-gray-300 text-lg text-gray-700 hover:bg-gray-50 md:hidden"
                      x-on:click="pane = 'list'" aria-label="Bumalik sa listahan ng mga meeting">‹</button>
              <div class="min-w-[9rem] flex-1">
                <h1 class="truncate text-base font-semibold" x-text="state.meeting.title"></h1>
                <p class="mt-0.5 line-clamp-2 text-xs text-gray-500" x-text="state.meeting.objective"></p>
              </div>
              <span class="rounded-full px-2.5 py-1 text-xs font-medium" x-bind:class="badge(state.meeting.status)" x-text="statusLabel(state.meeting.status)"></span>
              <div class="flex flex-wrap gap-2">
                <button type="button" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium hover:bg-gray-50 lg:hidden"
                        x-on:click="info = true" x-text="'Status' + (openIssues().length ? ' · ' + openIssues().length + ' isyu' : '')"></button>
                <button type="button" class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
                        x-show="state.meeting.status === 'draft'" x-bind:disabled="busy" x-on:click="act('start')">Start</button>
                <button type="button" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium hover:bg-gray-50 disabled:opacity-50"
                        x-show="state.meeting.status === 'running'" x-bind:disabled="busy" x-on:click="act('pause')">Pause</button>
                <button type="button" class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
                        x-show="state.meeting.status === 'paused'" x-bind:disabled="busy" x-on:click="act('resume')">Resume</button>
                <button type="button" class="rounded-md bg-amber-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-amber-700 disabled:opacity-50"
                        x-show="state.meeting.status === 'failed'" x-bind:disabled="busy" x-on:click="act('retry')">Retry</button>
                <button type="button" class="rounded-md border border-red-300 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-50 disabled:opacity-50"
                        x-show="['running','paused','failed','needs_input','draft'].includes(state.meeting.status)" x-bind:disabled="busy" x-on:click="stop()">Stop</button>
              </div>
            </div>

            {{-- Phase stepper --}}
            <ol class="mt-3 flex flex-wrap items-center gap-x-1 gap-y-1 text-[11px]">
              <template x-for="(ph, i) in phases" x-bind:key="ph.key">
                <li class="flex items-center gap-1">
                  <span class="rounded px-1.5 py-0.5 font-medium" x-bind:class="phaseClass(ph.key)" x-text="ph.label"></span>
                  <span class="text-gray-300" x-show="i < phases.length - 1">›</span>
                </li>
              </template>
            </ol>

            <p class="mt-2 rounded-md bg-red-50 px-3 py-2 text-xs text-red-800" x-show="state.meeting.last_error && state.meeting.status === 'failed'">
              <span class="font-semibold">Nahinto:</span> <span x-text="state.meeting.last_error"></span>
              <span class="block mt-1 text-red-700">Ayusin ang sanhi (hal. API key o model sa Agents / Roles), tapos pindutin ang Retry. Walang awtomatikong paglipat sa ibang model o key.</span>
            </p>
            <p class="mt-2 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-900" x-show="state.meeting.status === 'needs_input'">
              Naghihintay ang meeting sa sagot mo. I-type ang sagot sa ibaba para magpatuloy.
            </p>
            <template x-if="startErrors.length">
              <ul class="mt-2 list-disc rounded-md bg-red-50 py-2 pl-7 pr-3 text-xs text-red-800">
                <template x-for="e in startErrors" x-bind:key="e"><li x-text="e"></li></template>
              </ul>
            </template>
          </header>

          {{-- Mga message --}}
          <div class="min-h-0 flex-1 overflow-y-auto px-3 py-4 sm:px-5" x-ref="scroller">
            <template x-if="!state.messages.length && !state.pending.length">
              <p class="py-10 text-center text-sm text-gray-500" x-text="state.meeting.status === 'draft' ? 'Draft pa ang meeting. Pindutin ang Start para magsimula ang brief.' : 'Wala pang message.'"></p>
            </template>

            <template x-for="msg in state.messages" x-bind:key="'msg' + msg.id">
              <article class="mb-4 flex gap-3 rounded-lg" x-bind:id="'msg-' + msg.id" x-bind:class="flash === msg.id ? 'br-flash' : ''">
                <div class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-[11px] font-bold text-white"
                     x-bind:class="avatar(msg)" x-text="initials(msg)"></div>
                <div class="min-w-0 flex-1">
                  <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                    <span class="text-sm font-semibold" x-text="author(msg)"></span>
                    <span class="text-xs text-indigo-700" x-show="msg.recipient_handle" x-text="'→ @' + msg.recipient_handle"></span>
                    <span class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-gray-600" x-text="kindLabel(msg.kind)"></span>
                    <span class="rounded bg-rose-50 px-1.5 py-0.5 text-[10px] font-medium text-rose-700" x-show="msg.issue_code" x-text="msg.issue_code"></span>
                    <span class="text-[11px] text-gray-400" x-show="msg.cycle" x-text="'cycle ' + msg.cycle"></span>
                    <span class="text-[11px] text-gray-400" x-show="msg.model" x-text="msg.provider + ' · ' + msg.model"></span>
                    <span class="text-[11px] text-gray-400" x-text="time(msg.created_at)"></span>
                    <span class="text-[11px] text-gray-300" x-text="'#' + msg.id"></span>
                  </div>
                  <button type="button" class="mt-0.5 text-[11px] text-gray-500 hover:text-indigo-700 hover:underline" x-show="msg.reply_to_message_id"
                          x-on:click="jump(msg.reply_to_message_id)" x-text="'Sagot sa #' + msg.reply_to_message_id + replyName(msg.reply_to_message_id)"></button>

                  {{-- pre-wrap ay nasa bawat span (hindi sa container) para hindi lumabas ang whitespace ng template --}}
                  <div class="mt-1 rounded-lg px-3.5 py-2.5 text-sm leading-relaxed" x-bind:class="bubble(msg)">
                    <template x-for="(seg, i) in segments(msg.body)" x-bind:key="i">
                      <span x-bind:class="seg.tag ? tagClass(seg.tag) : 'br-body'" x-text="seg.text"></span>
                    </template>
                  </div>
                  <p class="mt-1 text-[11px] text-amber-700" x-show="msg.truncated">Naputol ang sagot sa max output tokens ng role na ito.</p>
                  <p class="mt-1 text-[11px] text-gray-500" x-show="msg.repaired">Naayos matapos ang isang repair call (hindi valid ang unang structured output).</p>

                  {{-- Kusang natutunan: walang approval, kaya laging may paraan para bawiin --}}
                  <template x-if="msg.lesson">
                    <div class="mt-1.5 flex flex-wrap items-center gap-2 text-[11px]">
                      <span class="rounded px-1.5 py-0.5 font-medium" x-bind:class="lessonClass(msg.lesson.status)" x-text="lessonLabel(msg.lesson.status)"></span>
                      <button type="button" class="rounded border border-gray-300 px-2 py-0.5 font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                              x-show="msg.lesson.status === 'active'" x-bind:disabled="busy" x-on:click="setLesson(msg.lesson.id, 'disabled')">I-undo</button>
                      <button type="button" class="rounded border border-gray-300 px-2 py-0.5 font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                              x-show="msg.lesson.status === 'disabled'" x-bind:disabled="busy" x-on:click="setLesson(msg.lesson.id, 'active')">Ibalik</button>
                      <a class="text-indigo-700 hover:underline" href="{{ route('boardroom.agents') }}">I-edit sa Agents / Roles</a>
                    </div>
                  </template>

                  {{-- Mga itinala ng AI sa Resources: bawat isa ay may sariling undo --}}
                  <template x-if="msg.changes && msg.changes.length">
                    <ul class="mt-1.5 space-y-1">
                      <template x-for="c in msg.changes" x-bind:key="'chg' + c.id">
                        <li class="flex flex-wrap items-center gap-2 text-[11px]">
                          <span class="rounded px-1.5 py-0.5 font-medium" x-bind:class="c.undone ? 'bg-gray-200 text-gray-700' : 'bg-emerald-100 text-emerald-800'"
                                x-text="c.undone ? 'Na-undo' : 'Naka-save'"></span>
                          <span class="min-w-0 text-gray-600" x-bind:class="c.undone ? 'line-through' : ''" x-text="c.label"></span>
                          <button type="button" class="rounded border border-gray-300 px-2 py-0.5 font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                                  x-show="!c.undone" x-bind:disabled="busy" x-on:click="undoChange(c.id)">I-undo</button>
                        </li>
                      </template>
                      <li class="text-[11px]"><a class="text-indigo-700 hover:underline" href="{{ route('boardroom.resources') }}">Tingnan sa Resources</a></li>
                    </ul>
                  </template>
                </div>
              </article>
            </template>

            {{-- Mga turn na hindi pa tapos / nabigo --}}
            <template x-for="t in state.pending" x-bind:key="'turn' + t.turn_id">
              <article class="mb-4 flex gap-3">
                <div class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-[11px] font-bold text-white opacity-70"
                     x-bind:class="avatar(t)" x-text="initials(t)"></div>
                <div class="min-w-0 flex-1">
                  <div class="flex flex-wrap items-baseline gap-x-2">
                    <span class="text-sm font-semibold" x-text="t.display_name + ' (' + t.handle + ')'"></span>
                    <span class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-gray-600" x-text="kindLabel(t.purpose)"></span>
                    <span class="text-[11px] text-gray-400" x-text="t.provider + ' · ' + t.model"></span>
                  </div>
                  <div class="mt-1 rounded-lg border px-3.5 py-2.5 text-sm" x-bind:class="t.status === 'failed' ? 'border-red-200 bg-red-50 text-red-900' : 'border-dashed border-gray-300 bg-white text-gray-600'">
                    <template x-if="t.status === 'generating'">
                      <span>Sumusulat<span class="br-dots"><span>.</span><span>.</span><span>.</span></span></span>
                    </template>
                    <template x-if="t.status === 'queued'"><span>Nakapila — naghihintay ng worker.</span></template>
                    <template x-if="t.status === 'paused'"><span>Naka-pause — hindi pa tumatawag ng model.</span></template>
                    <template x-if="t.status === 'failed'">
                      <span>
                        <span class="font-semibold" x-text="'Nabigo (' + t.error_code + ')'"></span>
                        <span class="br-body block" x-text="t.error_message"></span>
                        <span class="mt-1 block text-xs text-red-700" x-text="t.retryable ? 'Pansamantala ito — pwedeng i-Retry.' : 'Kailangang ayusin muna bago i-Retry.'"></span>
                      </span>
                    </template>
                  </div>
                </div>
              </article>
            </template>
          </div>

          {{-- Composer --}}
          <footer class="border-t border-gray-200 bg-white px-3 py-3 sm:px-5">
            <template x-if="notes.length">
              <ul class="mb-2 list-disc pl-5 text-xs text-amber-800">
                <template x-for="n in notes" x-bind:key="n"><li x-text="n"></li></template>
              </ul>
            </template>
            <div class="flex items-end gap-2">
              <div class="min-w-0 flex-1">
                <label for="br-composer" class="sr-only">Instruction para sa meeting</label>
                <textarea id="br-composer" rows="2" maxlength="8000" x-model="draft" x-ref="composer"
                          class="block w-full resize-none rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 disabled:bg-gray-100"
                          placeholder="Mag-type… @HANDLE para tanungin ang isang role. Para turuan: &quot;@CEO next time, ganito dapat…&quot;"
                          x-bind:disabled="sending"
                          x-on:keydown.enter="onEnter($event)"></textarea>
                <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                  <span class="text-[11px] text-gray-400">I-address:</span>
                  <template x-for="a in state.members" x-bind:key="'mention' + a.agent_id">
                    <button type="button" class="rounded-full border border-gray-200 px-2 py-0.5 text-[11px] text-gray-600 hover:border-indigo-300 hover:text-indigo-700"
                            x-on:click="mention(a.handle)" x-text="'@' + a.handle"></button>
                  </template>
                </div>
                {{-- Paraan ng sagot sa tanong mo. Hiwalay ito sa limit ng meeting. --}}
                <div class="mt-1.5 flex flex-wrap items-center gap-1.5" x-show="state.meeting.status !== 'draft'">
                  <label for="br-reply-mode" class="text-[11px] text-gray-400">Sagot:</label>
                  <select id="br-reply-mode" x-model.number="cycles" class="rounded-md border border-gray-300 px-2 py-1 text-xs text-gray-700">
                    <option value="0">Sagot lang — tig-isang sagot ang na-mention</option>
                    <option value="1">Pag-usapan — 1 cycle</option>
                    <option value="2">Pag-usapan — hanggang 2 cycle</option>
                    <option value="3">Pag-usapan — hanggang 3 cycle</option>
                  </select>
                  <span class="text-[11px] text-gray-400" x-text="modeHint()"></span>
                </div>
              </div>
              <button type="button" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
                      x-bind:disabled="sending || !draft.trim()" x-on:click="send()">Send</button>
            </div>
            <p class="mt-1 text-xs text-red-700" x-show="error" x-text="error"></p>
          </footer>
        </div>
      </template>
    </section>

    {{-- ═════════════ KANAN: status, usage, issues, decisions ═════════════ --}}
    {{-- lg pataas: nakapirmi sa kanan. Mas maliit: full-screen drawer na binubuksan ng "Status" button. --}}
    <aside class="shrink-0 overflow-y-auto border-l border-gray-200 bg-white lg:static lg:block lg:w-80" x-show="state"
           x-bind:class="info ? 'fixed inset-x-0 bottom-0 top-16 z-40 block w-full' : 'hidden'">
      <template x-if="state">
        <div class="divide-y divide-gray-100">
          <div class="sticky top-0 z-10 flex items-center justify-between border-b border-gray-200 bg-white px-4 py-2.5 lg:hidden">
            <span class="truncate text-sm font-semibold" x-text="state.meeting.title"></span>
            <button type="button" class="rounded-md border border-gray-300 px-3 py-1 text-sm font-medium text-gray-700 hover:bg-gray-50" x-on:click="info = false">Isara</button>
          </div>
          <section class="px-4 py-4">
            <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Run status</h3>
            <dl class="mt-2 space-y-1.5 text-sm">
              <div class="flex justify-between"><dt class="text-gray-500">Phase</dt><dd class="font-medium" x-text="phaseLabel(state.meeting.phase)"></dd></div>
              <div class="flex justify-between"><dt class="text-gray-500">Review cycle</dt><dd class="font-medium tabular-nums" x-text="state.meeting.cycle + ' / ' + state.meeting.max_cycles"></dd></div>
              <div class="flex justify-between"><dt class="text-gray-500">Model calls ng meeting</dt>
                <dd class="font-medium tabular-nums" x-text="state.meeting.calls_used + ' / ' + state.meeting.max_calls + (state.meeting.reserved_calls ? ' (+' + state.meeting.reserved_calls + ' nakareserba)' : '')"></dd></div>
              <div class="flex justify-between"><dt class="text-gray-500">Mga tanong mo</dt>
                <dd class="font-medium tabular-nums" x-text="state.meeting.question_calls + ' call'"></dd></div>
            </dl>
            <p class="mt-1 text-[11px] text-gray-400">Ang limit ay para sa kusang takbo ng meeting. Ang mga tanong mo ay hiwalay at hindi ibinabawas dito.</p>
            <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-gray-100" role="img" x-bind:aria-label="'Nagamit na model calls: ' + state.meeting.calls_used + ' sa ' + state.meeting.max_calls">
              <div class="h-full rounded-full bg-indigo-500" x-bind:style="'width:' + Math.min(100, 100 * state.meeting.calls_used / state.meeting.max_calls) + '%'"></div>
            </div>
            <p class="mt-2 text-xs text-gray-500" x-show="state.meeting.stop_reason" x-text="'Natapos nang maaga: ' + reasonLabel(state.meeting.stop_reason)"></p>
          </section>

          <section class="px-4 py-4">
            <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Usage</h3>
            <dl class="mt-2 space-y-1.5 text-sm">
              <div class="flex justify-between"><dt class="text-gray-500">Input tokens</dt><dd class="font-medium tabular-nums" x-text="num(state.meeting.tokens_in + state.meeting.question_tokens_in)"></dd></div>
              <div class="flex justify-between"><dt class="text-gray-500">Output tokens</dt><dd class="font-medium tabular-nums" x-text="num(state.meeting.tokens_out + state.meeting.question_tokens_out)"></dd></div>
              <div class="flex justify-between"><dt class="text-gray-500">Tantiyang gastos</dt><dd class="font-medium tabular-nums" x-text="cost(state.meeting.est_cost_usd + state.meeting.question_cost_usd, unpriced())"></dd></div>
              <div class="flex justify-between text-xs" x-show="state.meeting.question_calls > 0"><dt class="text-gray-400">· meeting</dt><dd class="tabular-nums text-gray-500" x-text="cost(state.meeting.est_cost_usd, state.meeting.unpriced_calls)"></dd></div>
              <div class="flex justify-between text-xs" x-show="state.meeting.question_calls > 0"><dt class="text-gray-400">· mga tanong mo</dt><dd class="tabular-nums text-gray-500" x-text="cost(state.meeting.question_cost_usd, state.meeting.question_unpriced_calls)"></dd></div>
            </dl>
            <p class="mt-1 text-[11px] text-gray-400">Tantiya lang mula sa presyo sa model registry. Ang aktwal na singil ay nasa dashboard ng provider, kada API key.</p>
            <p class="mt-1 text-[11px] text-amber-700" x-show="unpriced() > 0"
               x-text="unpriced() + ' call ang walang alam na presyo — hindi kasama sa tantiya.'"></p>

            <table class="mt-3 w-full text-xs" x-show="state.usage.length">
              <thead><tr class="text-left text-gray-400"><th class="py-1 font-medium">Role</th><th class="py-1 text-right font-medium">Calls</th><th class="py-1 text-right font-medium">Out</th><th class="py-1 text-right font-medium">USD</th></tr></thead>
              <tbody>
                <template x-for="u in state.usage" x-bind:key="'u' + u.agent_id">
                  <tr class="border-t border-gray-100">
                    <td class="py-1"><span class="font-medium" x-text="u.handle"></span><span class="block text-[10px] text-gray-400" x-text="u.model"></span></td>
                    <td class="py-1 text-right tabular-nums" x-text="u.calls"></td>
                    <td class="py-1 text-right tabular-nums" x-text="num(u.tokens_out)"></td>
                    <td class="py-1 text-right tabular-nums" x-text="u.cost_known ? '≈ ' + u.est_cost_usd.toFixed(4) : 'hindi alam'"></td>
                  </tr>
                </template>
              </tbody>
            </table>
          </section>

          <section class="px-4 py-4">
            <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Mga isyu <span class="font-normal normal-case text-gray-400" x-text="'(' + openIssues().length + ' hindi pa resolved)'"></span></h3>
            <p class="mt-2 text-xs text-gray-400" x-show="!state.issues.length">Wala pang naitalang isyu.</p>
            <ul class="mt-2 space-y-2">
              <template x-for="i in sortedIssues()" x-bind:key="'i' + i.id">
                <li class="rounded-md border border-gray-200 p-2">
                  <div class="flex items-center gap-1.5">
                    <span class="rounded bg-rose-50 px-1.5 py-0.5 text-[10px] font-semibold text-rose-700" x-text="i.code"></span>
                    <span class="rounded px-1.5 py-0.5 text-[10px] font-medium" x-bind:class="severity(i.severity)" x-text="i.severity"></span>
                    <span class="ml-auto rounded px-1.5 py-0.5 text-[10px] font-medium" x-bind:class="issueStatus(i.status)" x-text="issueLabel(i.status)"></span>
                  </div>
                  <p class="mt-1 text-xs font-medium" x-text="i.title"></p>
                  <p class="br-body mt-0.5 text-[11px] text-gray-500" x-show="i.detail" x-text="i.detail"></p>
                  <p class="mt-0.5 text-[11px] text-gray-400" x-show="i.target" x-text="'Para kay @' + i.target"></p>
                  <p class="br-body mt-0.5 text-[11px] text-emerald-700" x-show="i.resolution" x-text="i.resolution"></p>
                </li>
              </template>
            </ul>
          </section>

          <section class="px-4 py-4">
            <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Mga desisyong kailangan ng approval mo</h3>
            <p class="mt-2 text-xs text-gray-400" x-show="!state.decisions.length">Lalabas dito pagkatapos ng final recommendation.</p>
            <ul class="mt-2 space-y-2">
              <template x-for="d in state.decisions" x-bind:key="'d' + d.id">
                <li class="rounded-md border border-gray-200 p-2">
                  <p class="text-xs font-medium" x-text="d.title"></p>
                  <p class="br-body mt-0.5 text-[11px] text-gray-500" x-show="d.detail" x-text="d.detail"></p>
                  <div class="mt-1.5 flex items-center gap-1.5">
                    <span class="rounded px-1.5 py-0.5 text-[10px] font-medium" x-bind:class="decisionClass(d.status)" x-text="decisionLabel(d.status)"></span>
                    <button type="button" class="ml-auto rounded border border-emerald-300 px-2 py-0.5 text-[11px] font-medium text-emerald-800 hover:bg-emerald-50"
                            x-show="d.status !== 'approved'" x-on:click="decide(d.id, 'approved')">Approve</button>
                    <button type="button" class="rounded border border-gray-300 px-2 py-0.5 text-[11px] font-medium text-gray-700 hover:bg-gray-50"
                            x-show="d.status !== 'rejected'" x-on:click="decide(d.id, 'rejected')">Reject</button>
                  </div>
                </li>
              </template>
            </ul>
            <p class="mt-2 text-[11px] text-gray-400">Ang aprubadong desisyon lang ang isinasama sa mga susunod na meeting ng project na ito.</p>
          </section>

          <section class="px-4 py-4">
            <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Mga kasali</h3>
            <ul class="mt-2 space-y-1.5">
              <template x-for="a in state.members" x-bind:key="'mem' + a.agent_id">
                <li class="flex items-center gap-2 text-xs">
                  <span class="flex h-6 w-6 items-center justify-center rounded-full text-[9px] font-bold text-white" x-bind:class="avatar(a)" x-text="initials(a)"></span>
                  <span class="min-w-0 flex-1"><span class="font-medium" x-text="a.display_name"></span>
                    <span class="block truncate text-[10px] text-gray-400" x-text="a.provider + ' · ' + a.model + (a.settings.effort ? ' · ' + a.settings.effort : '')"></span></span>
                </li>
              </template>
            </ul>
            <p class="mt-2 text-[11px] text-gray-400">Config na naka-snapshot noong nagsimula ang meeting.</p>
          </section>
        </div>
      </template>
    </aside>

    {{-- ═════════════ MODAL: bagong project ═════════════ --}}
    <div class="fixed inset-0 z-[60] flex items-center justify-center bg-black/40 p-4" x-show="modal === 'project'" x-on:keydown.escape.window="modal = null">
      <div class="w-full max-w-md rounded-lg bg-white p-5 shadow-xl" role="dialog" aria-modal="true" aria-labelledby="br-project-title" x-on:click.outside="modal = null">
        <h3 id="br-project-title" class="text-base font-semibold">Bagong project</h3>
        <label class="mt-3 block text-sm font-medium" for="br-project-name">Pangalan</label>
        <input id="br-project-name" type="text" maxlength="160" x-model="projectForm.name" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
        <label class="mt-3 block text-sm font-medium" for="br-project-desc">Paglalarawan (opsyonal)</label>
        <textarea id="br-project-desc" rows="2" x-model="projectForm.description" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm"></textarea>
        <p class="mt-2 text-xs text-red-700" x-show="formError" x-text="formError"></p>
        <div class="mt-4 flex justify-end gap-2">
          <button type="button" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm" x-on:click="modal = null">Cancel</button>
          <button type="button" class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50" x-bind:disabled="busy || !projectForm.name.trim()" x-on:click="createProject()">Save</button>
        </div>
      </div>
    </div>

    {{-- ═════════════ MODAL: bagong meeting ═════════════ --}}
    <div class="fixed inset-0 z-[60] flex items-start justify-center overflow-y-auto bg-black/40 p-3 sm:items-center sm:p-4" x-show="modal === 'meeting'" x-on:keydown.escape.window="modal = null">
      <div class="my-2 w-full max-w-2xl rounded-lg bg-white p-4 shadow-xl sm:my-8 sm:p-5" role="dialog" aria-modal="true" aria-labelledby="br-meeting-title">
        <h3 id="br-meeting-title" class="text-base font-semibold">Bagong meeting</h3>

        <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div>
            <label class="block text-sm font-medium" for="br-m-project">Project</label>
            <select id="br-m-project" x-model="meetingForm.project_id" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
              <template x-for="p in projects" x-bind:key="'opt' + p.id"><option x-bind:value="String(p.id)" x-text="p.name"></option></template>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium" for="br-m-title">Title</label>
            <input id="br-m-title" type="text" maxlength="200" x-model="meetingForm.title" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
          </div>
        </div>

        <label class="mt-3 block text-sm font-medium" for="br-m-objective">Objective</label>
        <textarea id="br-m-objective" rows="3" maxlength="8000" x-model="meetingForm.objective" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                  placeholder="Hal. Mag-propose ng warehouse packing monitoring workflow. Suriin ang duplicate scans, packing verification, exceptions, at audit trail."></textarea>

        <label class="mt-3 block text-sm font-medium" for="br-m-constraints">Constraints (opsyonal)</label>
        <textarea id="br-m-constraints" rows="2" maxlength="8000" x-model="meetingForm.constraints" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                  placeholder="Hal. budget, deadline, mga bagay na hindi pwedeng galawin."></textarea>

        <fieldset class="mt-3">
          <legend class="text-sm font-medium">Mga role na kasali</legend>
          <div class="mt-1 flex flex-wrap gap-2 text-xs">
            <template x-for="g in groups" x-bind:key="'g' + g.id">
              <button type="button" class="rounded-full border border-gray-300 px-2.5 py-0.5 text-gray-700 hover:bg-gray-50" x-on:click="useGroup(g)" x-text="'Gamitin: ' + g.name"></button>
            </template>
          </div>
          <div class="mt-2 grid grid-cols-1 gap-1.5 sm:grid-cols-2">
            <template x-for="a in agents" x-bind:key="'a' + a.id">
              <label class="flex items-start gap-2 rounded-md border border-gray-200 px-3 py-2 text-sm" x-bind:class="a.enabled ? '' : 'opacity-50'">
                <input type="checkbox" class="mt-0.5" x-bind:value="String(a.id)" x-model="meetingForm.agent_ids" x-bind:disabled="!a.enabled">
                <span class="min-w-0 flex-1">
                  <span class="font-medium" x-text="a.display_name"></span>
                  <span class="ml-1 text-[11px] text-gray-400" x-text="a.role_type"></span>
                  <span class="block truncate text-[11px] text-gray-500" x-text="a.provider + ' · ' + (a.model || 'walang model')"></span>
                  <span class="block text-[11px] text-red-700" x-show="!a.has_key">Walang API key — ilagay sa Agents / Roles.</span>
                  <span class="block text-[11px] text-gray-500" x-show="!a.enabled">Naka-disable.</span>
                </span>
              </label>
            </template>
          </div>
          <p class="mt-1 text-[11px] text-gray-500">Kailangan: isang moderator, 1–4 na contributor, at hanggang isang reviewer.</p>
        </fieldset>

        <fieldset class="mt-3">
          <legend class="text-sm font-medium">Mga limit</legend>
          <div class="mt-1 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div><label class="block text-[11px] text-gray-500" for="br-m-calls">Max model calls</label>
              <input id="br-m-calls" type="number" min="4" x-bind:max="limits.max_calls" x-model.number="meetingForm.max_calls" class="mt-0.5 block w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm"></div>
            <div><label class="block text-[11px] text-gray-500" for="br-m-cycles">Max review cycles</label>
              <input id="br-m-cycles" type="number" min="1" x-bind:max="limits.max_cycles" x-model.number="meetingForm.max_cycles" class="mt-0.5 block w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm"></div>
            <div><label class="block text-[11px] text-gray-500" for="br-m-tokens">Max output tokens (kabuuan)</label>
              <input id="br-m-tokens" type="number" min="1000" placeholder="walang limit" x-model="meetingForm.max_total_output_tokens" class="mt-0.5 block w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm"></div>
            <div><label class="block text-[11px] text-gray-500" for="br-m-spend">Spending limit (USD)</label>
              <input id="br-m-spend" type="number" min="0.01" step="0.01" placeholder="walang limit" x-model="meetingForm.spend_limit_usd" class="mt-0.5 block w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm"></div>
          </div>
          <p class="mt-1 text-[11px] text-gray-500">Binibilang ang lahat ng call (routing, review, repair, final). Laging may nakatabing isang call para sa final recommendation.</p>
        </fieldset>

        <template x-if="formErrors.length">
          <ul class="mt-3 list-disc rounded-md bg-red-50 py-2 pl-7 pr-3 text-xs text-red-800">
            <template x-for="e in formErrors" x-bind:key="e"><li x-text="e"></li></template>
          </ul>
        </template>

        <div class="mt-4 flex flex-wrap justify-end gap-2">
          <button type="button" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm" x-on:click="modal = null">Cancel</button>
          <button type="button" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium disabled:opacity-50" x-bind:disabled="busy" x-on:click="createMeeting(false)">Save as draft</button>
          <button type="button" class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50" x-bind:disabled="busy" x-on:click="createMeeting(true)">Create &amp; start</button>
        </div>
      </div>
    </div>

    {{-- ═════════════ MODAL: company knowledge ═════════════ --}}
    <div class="fixed inset-0 z-[60] flex items-start justify-center overflow-y-auto bg-black/40 p-3 sm:items-center sm:p-4" x-show="modal === 'knowledge'" x-on:keydown.escape.window="modal = null">
      <div class="my-2 w-full max-w-2xl rounded-lg bg-white p-4 shadow-xl sm:my-8 sm:p-5" role="dialog" aria-modal="true" aria-labelledby="br-know-title">
        <div class="flex items-center justify-between">
          <h3 id="br-know-title" class="text-base font-semibold">Company knowledge</h3>
          <button type="button" class="text-sm text-gray-500 hover:text-gray-900" x-on:click="modal = null">Close</button>
        </div>
        <p class="mt-1 text-xs text-gray-500">Ang naka-APPROVE lang ang nakikita ng mga role. Hiwalay ito sa history ng meeting at sa instructions ng bawat role.</p>

        <ul class="mt-3 max-h-64 space-y-2 overflow-y-auto">
          <template x-for="k in knowledge" x-bind:key="'k' + k.id">
            <li class="rounded-md border border-gray-200 p-2.5">
              <div class="flex items-center gap-2">
                <span class="min-w-0 flex-1 truncate text-sm font-medium" x-text="k.title"></span>
                <span class="rounded px-1.5 py-0.5 text-[10px] font-medium" x-bind:class="k.approved ? 'bg-emerald-50 text-emerald-800' : 'bg-gray-100 text-gray-600'" x-text="k.approved ? 'Approved' : 'Draft'"></span>
                <span class="text-[10px] text-gray-400" x-text="k.project_id ? projectName(k.project_id) : 'Buong kumpanya'"></span>
                <button type="button" class="text-xs text-indigo-700 hover:underline" x-on:click="editKnowledge(k)">Edit</button>
                <button type="button" class="text-xs text-red-700 hover:underline" x-on:click="deleteKnowledge(k)">Delete</button>
              </div>
              <p class="br-body mt-1 line-clamp-3 text-xs text-gray-600" x-text="k.body"></p>
            </li>
          </template>
          <li class="text-xs text-gray-400" x-show="!knowledge.length">Wala pang knowledge.</li>
        </ul>

        <div class="mt-4 border-t border-gray-100 pt-3">
          <p class="text-sm font-medium" x-text="knowledgeForm.id ? 'I-edit' : 'Magdagdag'"></p>
          <div class="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div><label class="block text-[11px] text-gray-500" for="br-k-title">Title</label>
              <input id="br-k-title" type="text" maxlength="200" x-model="knowledgeForm.title" class="mt-0.5 block w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm"></div>
            <div><label class="block text-[11px] text-gray-500" for="br-k-project">Saklaw</label>
              <select id="br-k-project" x-model="knowledgeForm.project_id" class="mt-0.5 block w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm">
                <option value="">Buong kumpanya</option>
                <template x-for="p in projects" x-bind:key="'kp' + p.id"><option x-bind:value="String(p.id)" x-text="p.name"></option></template>
              </select></div>
          </div>
          <label class="mt-2 block text-[11px] text-gray-500" for="br-k-body">Nilalaman</label>
          <textarea id="br-k-body" rows="3" maxlength="12000" x-model="knowledgeForm.body" class="mt-0.5 block w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm"></textarea>
          <label class="mt-2 flex items-center gap-2 text-sm"><input type="checkbox" x-model="knowledgeForm.approved"> Approved (ipapakita sa mga role)</label>
          <p class="mt-2 text-xs text-red-700" x-show="formError" x-text="formError"></p>
          <div class="mt-3 flex justify-end gap-2">
            <button type="button" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm" x-show="knowledgeForm.id" x-on:click="resetKnowledge()">Bago</button>
            <button type="button" class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50"
                    x-bind:disabled="busy || !knowledgeForm.title.trim() || !knowledgeForm.body.trim()" x-on:click="saveKnowledge()">Save</button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script>
    /* boardroom-chat */
    function boardroom() {
      const API  = @json(url('/boardroom/api'));
      const csrf = () => document.querySelector('meta[name="csrf-token"]').content;

      return {
        loading: true, busy: false, sending: false, error: '', formError: '', formErrors: [], startErrors: [], notes: [],
        projects: [], agents: [], groups: [], limits: { max_calls: 16, max_cycles: 3 },
        selectedId: null, state: null, timer: null, flash: null, modal: null, draft: '',
        pane: 'list', info: false,   // para sa phone: aling pane ang nakikita, at kung bukas ang status drawer
        cycles: 0,                   // paraan ng sagot sa tanong: 0 = sagot lang; 1..3 = pag-usapan, hanggang ganito karaming cycle
        knowledge: [],
        projectForm: { name: '', description: '' },
        meetingForm: { project_id: '', title: '', objective: '', constraints: '', agent_ids: [], max_calls: 16, max_cycles: 3, max_total_output_tokens: '', spend_limit_usd: '' },
        knowledgeForm: { id: null, title: '', body: '', project_id: '', approved: true },
        phases: [
          { key: 'brief', label: 'Brief' }, { key: 'proposals', label: 'Proposals' }, { key: 'review', label: 'Review' },
          { key: 'discussion', label: 'Discussion' }, { key: 'revision', label: 'Revision' }, { key: 'final', label: 'Final' },
        ],

        async api(method, path, body) {
          const options = { method, headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' };
          if (body !== undefined) { options.headers['Content-Type'] = 'application/json'; options.body = JSON.stringify(body); }
          let response;
          try { response = await fetch(API + path, options); }
          catch (e) { return { ok: false, status: 0, data: { message: 'Hindi maabot ang server. Tingnan ang koneksyon.' } }; }
          let data = {};
          try { data = await response.json(); } catch (e) { data = { message: 'Hindi inaasahang sagot ng server (HTTP ' + response.status + ').' }; }
          return { ok: response.ok, status: response.status, data };
        },

        messagesOf(data) {
          const out = [];
          if (data && data.errors) {
            if (Array.isArray(data.errors)) out.push(...data.errors);
            else Object.values(data.errors).forEach(list => out.push(...[].concat(list)));
          }
          if (!out.length && data && data.message) out.push(data.message);
          return out.length ? out : ['May error. Subukan ulit.'];
        },

        async init() {
          await this.loadBootstrap();
          const match = /m=(\d+)/.exec(window.location.hash);
          if (match) this.select(Number(match[1]));
          document.addEventListener('visibilitychange', () => { if (!document.hidden && this.selectedId) this.refresh(); });
        },

        async loadBootstrap() {
          const r = await this.api('GET', '/bootstrap');
          this.loading = false;
          if (!r.ok) { this.error = this.messagesOf(r.data)[0]; return; }
          this.projects = r.data.projects; this.agents = r.data.agents; this.groups = r.data.groups; this.limits = r.data.limits;
        },

        async select(id) {
          this.selectedId = id; this.state = null; this.error = ''; this.notes = []; this.startErrors = [];
          this.pane = 'chat'; this.info = false;
          window.location.hash = 'm=' + id;
          await this.refresh(true);
        },

        async refresh(scroll) {
          if (!this.selectedId) return;
          clearTimeout(this.timer);
          const id = this.selectedId;
          const r = await this.api('GET', '/meetings/' + id);
          if (id !== this.selectedId) return;
          if (!r.ok) { this.error = r.status === 404 ? 'Hindi makita ang meeting.' : this.messagesOf(r.data)[0]; this.state = null; return; }
          this.apply(r.data, scroll);
        },

        apply(data, scroll) {
          const before = this.state ? this.state.messages.length + this.state.pending.length : -1;
          const el = this.$refs.scroller;
          const nearBottom = !el || (el.scrollHeight - el.scrollTop - el.clientHeight < 140);
          this.state = { meeting: data.meeting, members: data.members, messages: data.messages, pending: data.pending, issues: data.issues, decisions: data.decisions, usage: data.usage };
          this.syncRow(data.meeting);
          const after = data.messages.length + data.pending.length;
          if (scroll || (after !== before && nearBottom)) this.$nextTick(() => { const s = this.$refs.scroller; if (s) s.scrollTop = s.scrollHeight; });
          this.schedule();
        },

        schedule() {
          clearTimeout(this.timer);
          if (!this.state) return;
          const active = this.state.meeting.status === 'running' || this.state.pending.some(t => t.status === 'queued' || t.status === 'generating');
          this.timer = setTimeout(() => this.refresh(), document.hidden ? 15000 : (active ? 2500 : 12000));
        },

        syncRow(meeting) {
          this.projects.forEach(p => p.meetings.forEach(m => { if (m.id === meeting.id) { m.status = meeting.status; m.phase = meeting.phase; m.title = meeting.title; } }));
        },

        async act(action) {
          if (!this.state || this.busy) return;
          this.busy = true; this.error = ''; this.startErrors = [];
          const r = await this.api('POST', '/meetings/' + this.selectedId + '/' + action);
          this.busy = false;
          if (r.data && r.data.meeting) this.apply(r.data, true);
          if (!r.ok) { if (action === 'start') this.startErrors = this.messagesOf(r.data); else this.error = this.messagesOf(r.data)[0]; }
        },

        stop() {
          if (window.confirm('Itigil ang meeting? Hindi na ito maitutuloy pagkatapos. Ang mga natapos na message ay mananatili.')) this.act('stop');
        },

        async send() {
          const body = this.draft.trim();
          if (!body || this.sending || !this.state) return;
          this.sending = true; this.error = ''; this.notes = [];
          const r = await this.api('POST', '/meetings/' + this.selectedId + '/messages', { body, cycles: Number(this.cycles) || 0 });
          this.sending = false;
          if (!r.ok) { this.error = this.messagesOf(r.data)[0]; return; }
          this.draft = ''; this.notes = r.data.notes || [];
          this.apply(r.data, true);
        },

        // Desktop: Enter = send, Shift+Enter = bagong linya. Phone (touch keyboard): Enter = bagong linya; Send button ang gamit.
        onEnter(event) {
          const touch = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
          if (event.shiftKey || touch) return;
          event.preventDefault();
          this.send();
        },

        // Ang mga @mention ay nasa unahan ng message, AYON SA PAGKAKASUNOD ng pagpindot.
        // Pagpindot ulit sa naka-mention na = tanggalin.
        mention(handle) {
          this.draft = this.withMention(this.draft, handle);
          this.$nextTick(() => this.$refs.composer && this.$refs.composer.focus());
        },
        withMention(draft, handle) {
          const lead = /^\s*(?:@[A-Za-z][A-Za-z0-9_-]*(?:\s+|$))*/.exec(draft)[0];
          const rest = draft.slice(lead.length);
          const list = lead.split(/\s+/).filter(Boolean);
          const at   = list.findIndex(m => m.toLowerCase() === '@' + handle.toLowerCase());
          if (at !== -1) list.splice(at, 1);
          else if (!new RegExp('@' + handle + '(?![A-Za-z0-9_-])', 'i').test(rest)) list.push('@' + handle);
          return (list.length ? list.join(' ') + ' ' : '') + rest;
        },

        // I-undo / Ibalik ang isang aral na kusang natutunan sa chat.
        async setLesson(id, status) {
          this.busy = true;
          const r = await this.api('PUT', '/lessons/' + id, { status, meeting_id: this.selectedId });
          this.busy = false;
          if (r.ok && r.data.state) this.apply(r.data.state); else if (!r.ok) this.error = this.messagesOf(r.data)[0];
        },
        lessonLabel(s) {
          return { active: 'Naka-save sa playbook', disabled: 'Na-undo — hindi na ginagamit', replaced: 'Napalitan ng mas bagong aral', deleted: 'Binura' }[s] || s;
        },
        lessonClass(s) { return s === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-gray-200 text-gray-700'; },
        // I-undo ang isang pagbabagong itinala ng AI sa Resources.
        async undoChange(id) {
          this.busy = true; this.error = '';
          const r = await this.api('POST', '/changes/' + id + '/undo', { meeting_id: this.selectedId });
          this.busy = false;
          if (r.data && r.data.state) this.apply(r.data.state);
          if (!r.ok) this.error = this.messagesOf(r.data)[0];
        },

        async decide(id, status) {
          const r = await this.api('POST', '/decisions/' + id, { status });
          if (r.ok) this.apply(r.data); else this.error = this.messagesOf(r.data)[0];
        },

        async createProject() {
          this.busy = true; this.formError = '';
          const r = await this.api('POST', '/projects', this.projectForm);
          this.busy = false;
          if (!r.ok) { this.formError = this.messagesOf(r.data)[0]; return; }
          this.projects.unshift(r.data.project);
          this.projectForm = { name: '', description: '' };
          this.modal = null;
        },

        openMeetingForm(projectId) {
          const group = this.groups.find(g => g.is_default) || this.groups[0];
          this.formErrors = [];
          this.meetingForm = {
            project_id: String(projectId || (this.projects[0] ? this.projects[0].id : '')), title: '', objective: '', constraints: '',
            agent_ids: [], max_calls: this.limits.max_calls, max_cycles: this.limits.max_cycles, max_total_output_tokens: '', spend_limit_usd: '',
          };
          if (group) this.useGroup(group);
          this.modal = 'meeting';
        },

        useGroup(group) {
          const enabled = this.agents.filter(a => a.enabled).map(a => a.id);
          this.meetingForm.agent_ids = group.agent_ids.filter(id => enabled.includes(id)).map(String);
        },

        async createMeeting(start) {
          const f = this.meetingForm;
          this.formErrors = [];
          if (!f.title.trim()) this.formErrors.push('Kailangan ng title.');
          if (!f.objective.trim()) this.formErrors.push('Kailangan ng objective.');
          if (f.agent_ids.length < 2) this.formErrors.push('Pumili ng hindi bababa sa dalawang role.');
          if (this.formErrors.length) return;

          this.busy = true;
          const r = await this.api('POST', '/meetings', {
            project_id: Number(f.project_id), title: f.title, objective: f.objective, constraints: f.constraints || null,
            agent_ids: f.agent_ids.map(Number), max_calls: f.max_calls || null, max_cycles: f.max_cycles || null,
            max_total_output_tokens: f.max_total_output_tokens === '' ? null : Number(f.max_total_output_tokens),
            spend_limit_usd: f.spend_limit_usd === '' ? null : Number(f.spend_limit_usd),
          });
          this.busy = false;
          if (!r.ok) { this.formErrors = this.messagesOf(r.data); return; }

          const project = this.projects.find(p => p.id === r.data.meeting.project_id);
          if (project) project.meetings.unshift(r.data.meeting);
          this.modal = null;
          await this.select(r.data.meeting.id);
          if (start) await this.act('start');
        },

        async openKnowledge() {
          this.modal = 'knowledge'; this.formError = ''; this.resetKnowledge();
          const r = await this.api('GET', '/knowledge');
          if (r.ok) this.knowledge = r.data.knowledge;
        },
        resetKnowledge() { this.knowledgeForm = { id: null, title: '', body: '', project_id: '', approved: true }; },
        editKnowledge(k) { this.knowledgeForm = { id: k.id, title: k.title, body: k.body, project_id: k.project_id ? String(k.project_id) : '', approved: k.approved }; },
        async saveKnowledge() {
          const f = this.knowledgeForm;
          this.busy = true; this.formError = '';
          const r = await this.api('POST', '/knowledge', { id: f.id, title: f.title, body: f.body, project_id: f.project_id === '' ? null : Number(f.project_id), approved: !!f.approved });
          this.busy = false;
          if (!r.ok) { this.formError = this.messagesOf(r.data)[0]; return; }
          this.knowledge = r.data.knowledge; this.resetKnowledge();
        },
        async deleteKnowledge(k) {
          if (!window.confirm('Burahin ang "' + k.title + '"?')) return;
          const r = await this.api('DELETE', '/knowledge/' + k.id);
          if (r.ok) this.knowledge = r.data.knowledge;
        },
        projectName(id) { const p = this.projects.find(x => x.id === id); return p ? p.name : 'Project'; },

        // ── Pagpapakita ──
        jump(id) {
          const el = document.getElementById('msg-' + id);
          if (!el) return;
          el.scrollIntoView({ behavior: 'smooth', block: 'center' });
          this.flash = id; setTimeout(() => { this.flash = null; }, 1500);
        },
        replyName(id) {
          const m = this.state && this.state.messages.find(x => x.id === id);
          return m ? ' (' + (m.author_type === 'user' ? 'Ikaw' : (m.handle || 'System')) + ')' : '';
        },
        author(m) {
          if (m.author_type === 'user') return 'Ikaw';
          if (m.author_type === 'system') return 'System';
          return (m.display_name || 'Agent') + ' (' + (m.handle || '?') + ')';
        },
        initials(m) {
          if (m.author_type === 'user') return 'IKAW';
          if (m.author_type === 'system') return 'SYS';
          return String(m.handle || '?').slice(0, 3);
        },
        avatar(m) {
          if (m.author_type === 'user') return 'bg-gray-700';
          if (m.author_type === 'system') return 'bg-gray-400';
          return { moderator: 'bg-indigo-600', contributor: 'bg-emerald-600', reviewer: 'bg-rose-600' }[m.role_type] || 'bg-slate-500';
        },
        bubble(m) {
          if (m.author_type === 'user') return 'bg-gray-800 text-white';
          if (m.kind === 'lesson' || m.kind === 'changes') return 'bg-emerald-50 text-emerald-950 border border-emerald-200';
          if (m.author_type === 'system') return 'bg-amber-50 text-amber-900 border border-amber-200';
          if (m.kind === 'final') return 'bg-indigo-50 border border-indigo-200';
          return 'bg-white border border-gray-200';
        },
        segments(body) {
          const out = []; const re = /\[(VERIFIED FACT|ASSUMPTION|PROPOSAL|APPROVED DECISION)\]/g;
          const text = String(body || ''); let last = 0; let m;
          while ((m = re.exec(text)) !== null) {
            if (m.index > last) out.push({ text: text.slice(last, m.index), tag: null });
            out.push({ text: m[1], tag: m[1] });
            last = m.index + m[0].length;
          }
          if (last < text.length) out.push({ text: text.slice(last), tag: null });
          return out;
        },
        tagClass(tag) {
          const base = 'mx-0.5 rounded px-1 py-px text-[10px] font-semibold uppercase tracking-wide ';
          return base + ({ 'VERIFIED FACT': 'bg-emerald-100 text-emerald-900', 'ASSUMPTION': 'bg-amber-100 text-amber-900',
            'PROPOSAL': 'bg-sky-100 text-sky-900', 'APPROVED DECISION': 'bg-indigo-100 text-indigo-900' }[tag] || 'bg-gray-100');
        },
        kindLabel(k) {
          return { brief: 'Brief', proposal: 'Proposal', review: 'Review', question: 'Tanong', answer: 'Sagot', revision: 'Revision', final: 'Final',
            instruction: 'Instruction', notice: 'Paalala', routing: 'Susunod na hakbang', route: 'Routing', repair: 'Repair', direct: 'Sagot sa tanong mo',
            summary: 'Buod', qreview: 'Review ng mga sagot', qsummary: 'Buod', lesson: 'Natutunan', changes: 'Itinala' }[k] || k;
        },
        statusLabel(s) {
          return { draft: 'Draft', running: 'Tumatakbo', paused: 'Paused', stopped: 'Stopped', completed: 'Tapos', failed: 'Nahinto',
            needs_input: 'Kailangan ka', blocked: 'Blocked' }[s] || s;
        },
        badge(s) {
          return { draft: 'bg-gray-100 text-gray-700', running: 'bg-sky-100 text-sky-800', paused: 'bg-amber-100 text-amber-800', stopped: 'bg-gray-200 text-gray-700',
            completed: 'bg-emerald-100 text-emerald-800', failed: 'bg-red-100 text-red-800', needs_input: 'bg-amber-100 text-amber-900', blocked: 'bg-rose-100 text-rose-800' }[s] || 'bg-gray-100';
        },
        dot(s) {
          return { draft: 'bg-gray-300', running: 'bg-sky-500', paused: 'bg-amber-500', stopped: 'bg-gray-400', completed: 'bg-emerald-500',
            failed: 'bg-red-500', needs_input: 'bg-amber-500', blocked: 'bg-rose-500' }[s] || 'bg-gray-300';
        },
        phaseLabel(p) { const f = this.phases.find(x => x.key === p); return f ? f.label : (p === 'done' ? 'Tapos' : p); },
        phaseClass(key) {
          const order = this.phases.map(p => p.key); const now = this.state.meeting.phase;
          if (now === 'done') return 'bg-emerald-50 text-emerald-700';
          const a = order.indexOf(key), b = order.indexOf(now);
          if (a === b) return 'bg-indigo-600 text-white';
          return a < b ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-400';
        },
        reasonLabel(r) {
          const key = String(r).replace('limit:', '');
          return { user: 'itinigil ng user', call_limit: 'limit sa bilang ng model call', token_limit: 'limit sa output tokens', spend_limit: 'spending limit',
            price_unknown: 'may model na walang alam na presyo', cycle_limit: 'pinakamaraming review cycle', blocked: 'idineklarang blocked ng moderator' }[key] || key;
        },
        openIssues() { return this.state.issues.filter(i => i.status !== 'resolved'); },
        sortedIssues() { return [...this.state.issues].sort((a, b) => (a.status === 'resolved') - (b.status === 'resolved') || a.id - b.id); },
        severity(s) { return { high: 'bg-red-100 text-red-800', medium: 'bg-amber-100 text-amber-800', low: 'bg-gray-100 text-gray-700' }[s] || 'bg-gray-100'; },
        issueLabel(s) {
          return { open: 'Bukas', answered: 'May sagot', resolved: 'Resolved', unresolved: 'Hindi resolved', needs_user_input: 'Kailangan ka', blocked: 'Blocked' }[s] || s;
        },
        issueStatus(s) { return s === 'resolved' ? 'bg-emerald-100 text-emerald-800' : (s === 'answered' ? 'bg-sky-100 text-sky-800' : 'bg-rose-100 text-rose-800'); },
        decisionLabel(s) { return { proposed: 'Naghihintay', approved: 'Approved', rejected: 'Rejected' }[s] || s; },
        decisionClass(s) { return { proposed: 'bg-amber-100 text-amber-800', approved: 'bg-emerald-100 text-emerald-800', rejected: 'bg-gray-200 text-gray-700' }[s] || 'bg-gray-100'; },
        num(n) { return Number(n || 0).toLocaleString('en-US'); },
        unpriced() { return (this.state.meeting.unpriced_calls || 0) + (this.state.meeting.question_unpriced_calls || 0); },
        // Ilang model call ang aabutin ng tanong, batay sa na-mention at sa napiling paraan.
        modeHint() {
          const members = this.state ? this.state.members : [];
          const mentioned = members.filter(a => new RegExp('@' + a.handle + '(?![A-Za-z0-9_-])', 'i').test(this.draft));
          if (!Number(this.cycles)) {
            return mentioned.length ? mentioned.length + ' model call' : 'Walang @mention = walang model call (instruction lang).';
          }
          const contributors = members.filter(a => a.role_type === 'contributor');
          const who = mentioned.filter(a => a.role_type === 'contributor');
          const n = (who.length ? who : contributors).length;
          const reviewer = members.some(a => a.role_type === 'reviewer');
          const c = Number(this.cycles);
          const max = reviewer ? n * c + c + 1 : n + 1;
          return 'Hanggang ' + max + ' model call: sagot, review, tapos buod ng CEO. Titigil nang mas maaga kapag ayos na sa reviewer.';
        },
        cost(usd, unpriced) {
          if (!usd && unpriced > 0) return 'hindi alam';
          return '≈ $' + Number(usd || 0).toFixed(4);
        },
        time(iso) {
          if (!iso) return '';
          const d = new Date(iso);
          return d.toLocaleString('en-PH', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
        },
      };
    }
  </script>
</x-layout>

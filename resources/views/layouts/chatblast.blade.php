{{--
  Ang Chatblast bilang sariling app: sariling top bar (brand, To send, History, Messages, Sequences, Page settings),
  walang main nav ng Likha. Top bar at hindi sidebar, para buo ang lapad ng page settings grid (walang horizontal
  scroll). Ang bawat page: @section('title'), @section('heading'), @section('description') (isang linya sa ilalim ng
  title), @section('actions') (sariling links), @section('content'), at @push('styles') / @push('scripts').
  Nakapirmi ang top bar, ang header ng page (title, filter, chip, button) at ang header row ng table; ang data lang ang
  nag-i-scroll. Ang laman ng page ay isang .cb-page na column; ang isang .cb-scroll sa loob nito ang tanging may scroll.
--}}
@php
  $cbRole = Auth::user()?->employeeProfile?->role ?? Auth::user()?->role ?? '';
  $cbItems = \App\Support\ChatblastNav::items($cbRole);
  // Ang app (Chatblast o Chatbot) ay ayon sa URL: may sariling kulay, at ang title ng tab ay laging nagsisimula rito.
  $cbApp = request()->is('chatbot', 'chatbot/*') ? 'chatbot' : 'chatblast';
  $cbAppName = $cbApp === 'chatbot' ? 'Chatbot' : 'Chatblast';
  $cbTitle = trim(preg_replace('/^(Chatblast|Chatbot)\s*·\s*/u', '', trim($__env->yieldContent('title', $cbAppName))));
  $cbTitle = $cbTitle === '' || $cbTitle === $cbAppName ? $cbAppName : $cbAppName . ' · ' . $cbTitle;
  // Ang Chatbot ay para sa may /chatblast access (gaya ng mga route nito); ang iba ay walang link papunta roon.
  $cbChatbotOpen = \App\Support\NavAccess::allows(preg_replace('/\s+/u', ' ', trim((string) $cbRole)), '/chatblast');
  // Ang mga page na may problema (pumapalya, hindi nasagot, lipat ng bot, mabagal, setup, walang chat): para sa may Chatbot menu lang.
  $cbProblems = $cbChatbotOpen ? app(\App\Services\Chatbot\ProblemsPanel::class)->panel() : null;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $cbTitle }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    {{-- Iisang itsura sa lahat ng page: isang primary na button (asul), ang iba ay neutral; chip na pill; card na may
         manipis na border; table na may magaan na hati ng row at walang mabigat na band sa header. --}}
    <style type="text/tailwindcss">
        /* Sa components layer: ang mga utility (hal. hidden) ay nananaig pa rin. */
        @layer components {
        .cb-btn { @apply inline-flex items-center gap-1 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50; }
        .cb-btn-primary { @apply border-blue-600 bg-blue-600 text-white hover:bg-blue-700; }
        .cb-btn-sm { @apply px-2 py-0.5 text-xs; }
        .cb-card { @apply rounded-lg border border-slate-200 bg-white; }
        .cb-table { @apply w-full text-sm; }
        .cb-table thead th { @apply border-b border-slate-200 bg-transparent px-3 py-2 text-left text-xs font-medium uppercase tracking-wide text-slate-500; }
        .cb-table tbody td { @apply border-b border-slate-100 px-3 py-2.5 align-top; }
        .cb-table tbody tr:last-child td { @apply border-b-0; }
        .cb-table tbody tr:hover td { @apply bg-slate-50; }
        .cb-filters label { @apply text-slate-500; }
        .cb-filters input, .cb-filters select { @apply rounded-md border-slate-300; }
        /* Buong taas na column: ang .cb-scroll lang ang nag-i-scroll (min 16rem; kapag maliit ang screen, ang main ang
           nag-i-scroll). Ang header row ng table ay nakadikit sa taas nito: puti, isang manipis na linya, at magaan na
           anino lang kapag na-scroll na ang mga row. Ang linya ay box-shadow kasi hindi sumasama ang border ng th sa sticky. */
        .cb-page { @apply flex min-h-0 flex-1 flex-col; }
        .cb-page > * { @apply shrink-0; }
        .cb-page > .cb-scroll { @apply min-h-[16rem] flex-1 basis-0 overflow-auto; }
        .cb-scroll thead th { @apply sticky top-0 z-10 border-b-0 bg-white; box-shadow: inset 0 -1px 0 #e2e8f0; }
        .cb-scroll.is-scrolled thead th { box-shadow: inset 0 -1px 0 #e2e8f0, 0 2px 4px -2px rgb(15 23 42 / 0.08); }
        }
    </style>
    @stack('styles')
</head>
<body class="flex h-screen flex-col overflow-hidden bg-slate-50 text-gray-900" data-chatblast-app>

<header class="shrink-0 border-b border-slate-200 bg-white">
    <div class="flex h-14 items-center gap-3 px-4 sm:gap-6 sm:px-6">
        {{-- Ang switcher: ang kasalukuyang app ay puno (sariling kulay), ang isa ay simpleng link sa home nito. --}}
        @php
            $cbApps = [
                'chatblast' => ['name' => 'Chatblast', 'short' => 'Blast', 'url' => '/chatblast', 'tile' => 'from-violet-600 to-indigo-600',
                    'icon' => '<path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4 20-7z"/>'],
                'chatbot' => ['name' => 'Chatbot', 'short' => 'Bot', 'url' => '/chatbot', 'tile' => 'from-emerald-500 to-teal-600',
                    'icon' => '<path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/>'],
            ];
        @endphp
        <div class="flex shrink-0 items-center gap-0.5 rounded-xl border border-slate-200 bg-slate-50 p-0.5" role="group" aria-label="App" data-app-switch data-current="{{ $cbApp }}">
            @foreach($cbApps as $key => $a)
                @php($current = $key === $cbApp)
                @if($current || $key === 'chatblast' || $cbChatbotOpen)
                    <a href="{{ url($a['url']) }}" data-app="{{ $key }}" @if($current) aria-current="page" @endif title="{{ $a['name'] }}"
                       class="flex items-center gap-1.5 rounded-lg py-1 pl-1 pr-2 text-sm font-semibold {{ $current ? 'bg-white text-slate-900 shadow-sm ring-1 ring-slate-200' : 'text-slate-500 hover:bg-white hover:text-slate-800' }}">
                        <span class="inline-flex h-7 w-7 items-center justify-center rounded-lg {{ $current ? 'bg-gradient-to-br ' . $a['tile'] . ' text-white' : 'bg-slate-200 text-slate-600' }}">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $a['icon'] !!}</svg>
                        </span>
                        <span class="sm:hidden">{{ $a['short'] }}</span><span class="hidden sm:inline">{{ $a['name'] }}</span>
                    </a>
                @endif
            @endforeach
        </div>

        <nav class="flex min-w-0 items-center gap-1 overflow-x-auto text-sm" aria-label="{{ $cbAppName }}">
            @foreach($cbItems as $item)
                <a href="{{ url($item['url']) }}" data-cb-nav="{{ $item['label'] }}" @if($item['active']) aria-current="page" @endif
                   class="whitespace-nowrap rounded-md px-3 py-1.5 font-medium {{ $item['active'] ? ($cbApp === 'chatbot' ? 'bg-emerald-50 text-emerald-700' : 'bg-violet-50 text-violet-700') : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}">{{ $item['label'] }}</a>
            @endforeach
        </nav>

        <div class="ml-auto flex shrink-0 items-center gap-4 text-sm">
            <a href="{{ url('/') }}" class="text-slate-500 hover:text-slate-900">← Likha</a>
            @auth
                <span class="hidden text-slate-700 md:inline">{{ Auth::user()->name }}</span>
            @endauth
        </div>
    </div>
</header>

@if($cbProblems)
    {{-- Isang linya muna; ang pag-click ay nagbubukas ng listahan dito rin (walang lipat ng page). --}}
    <div class="shrink-0 border-b {{ $cbProblems['urgent'] ? 'border-red-300 bg-red-600 text-white' : 'border-slate-300 bg-slate-100 text-slate-800' }} text-sm" role="alert" data-alert="problems">
        <button type="button" id="cb-problems-toggle" aria-expanded="false" aria-controls="cb-problems-list"
                class="block w-full truncate px-4 py-1.5 text-left font-semibold sm:px-6">⚠ {{ count($cbProblems['pages']) }} {{ count($cbProblems['pages']) === 1 ? 'page' : 'pages' }} with problems ({{ $cbProblems['urgent'] }} urgent) <span aria-hidden="true">▾</span></button>
        <ol id="cb-problems-list" class="max-h-[60vh] overflow-y-auto break-words border-t border-slate-200 bg-white px-4 py-2 text-slate-800 sm:px-6" hidden>
            @foreach($cbProblems['pages'] as $p)
                <li data-page="{{ $p['page'] }}" data-level="{{ $p['level'] }}" class="py-1.5">
                    <div class="font-semibold">{{ $p['page'] }}</div>
                    <ul class="space-y-0.5 pl-1">
                        @foreach($p['lines'] as $l)
                            <li data-level="{{ $l['level'] }}" data-kind="{{ $l['kind'] ?? '' }}">{{ \App\Services\Chatbot\ProblemsPanel::LEVELS[$l['level']] }} {{ $l['text'] }} @foreach($l['links'] as [$label, $href]) <a href="{{ $href }}" class="text-blue-700 underline hover:text-blue-900">{{ $label }}</a>@endforeach
                                @if(isset($l['ack']))
                                    <span class="text-xs text-amber-700" data-ack-back>({{ $l['ack']['status'] === 'done' ? 'Done' : 'Acknowledged' }} by {{ $l['ack']['by'] }} {{ $l['ack']['at'] }}: "{{ $l['ack']['note'] }}"; {{ $l['ack']['back'] }})</span>
                                @endif
                                @if(!empty($l['kind']))
                                    <span data-ack-controls><button type="button" class="cb-ack ml-1 rounded border border-slate-300 px-1.5 text-xs text-slate-700 hover:bg-slate-100" data-status="ack" data-page="{{ $p['page'] }}" data-kind="{{ $l['kind'] }}" data-level="{{ $l['level'] }}">Acknowledge</button>
                                    <button type="button" class="cb-ack rounded border border-slate-300 px-1.5 text-xs text-slate-700 hover:bg-slate-100" data-status="done" data-page="{{ $p['page'] }}" data-kind="{{ $l['kind'] }}" data-level="{{ $l['level'] }}">Done</button></span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </li>
            @endforeach
            @if(!empty($cbProblems['acked']))
                <li class="pt-2" data-acked>
                    <details>
                        <summary class="cursor-pointer text-xs font-semibold text-slate-600">Acknowledged and done ({{ array_sum(array_map(fn ($a) => count($a['lines']), $cbProblems['acked'])) }})</summary>
                        <ul class="mt-1 space-y-1 pl-1 text-xs text-slate-600">
                            @foreach($cbProblems['acked'] as $a)
                                @foreach($a['lines'] as $l)
                                    <li data-kind="{{ $l['kind'] }}">
                                        <span class="font-semibold">{{ $a['page'] }}</span>: {{ $l['text'] }}
                                        <span class="block pl-3">{{ $l['ack']['status'] === 'done' ? '✅ Done' : '👀 Acknowledged' }} by {{ $l['ack']['by'] }}, {{ $l['ack']['at'] }}@if($l['ack']['until']) · until {{ $l['ack']['until'] }}@endif: "{{ $l['ack']['note'] }}"
                                            <button type="button" class="cb-ack ml-1 underline" data-status="clear" data-page="{{ $a['page'] }}" data-kind="{{ $l['kind'] }}">Undo</button></span>
                                    </li>
                                @endforeach
                            @endforeach
                        </ul>
                    </details>
                </li>
            @endif
            {{-- Ang "bago" ay mula sa talaan ng Likha lang, kaya sinasabi rito kung ano ang kasama (kapag may linya ng doble). --}}
            @if($cbProblems['doubles'] ?? false)
                <li class="pt-1 text-xs text-slate-500" data-help="doubles">Main flow double: a new customer (first message to the page in Likha's records, in the last {{ \App\Services\Chatbot\ChatEchoes::HEALTH_MINUTES }} min) answered by both CBB (Chatbot Builder or Botcake) and the Likha bot's main flow. A customer already known to Chatbot Builder but new to Likha counts as new. Leftover: every other double reply, e.g. old customers still in a CBB sequence or loop; not urgent.</li>
            @endif
        </ol>
        {{-- Sa ilalim ng listahan (kasabay nitong bukas/sarado): ang Acknowledge all / Done all (CEO lang) at ang iisang form
             ng Acknowledge/Done (note, at sa Acknowledge, hanggang kailan), na inililipat sa ilalim ng pinindot na linya. --}}
        <div id="cb-problems-actions" class="border-t border-slate-200 bg-white px-4 py-1.5 text-slate-800 sm:px-6" hidden>
            @php($cbIsCeo = preg_replace('/\s+/u', ' ', trim((string) $cbRole)) === 'CEO')
            @if($cbIsCeo && count($cbProblems['pages']))
                <div class="flex flex-wrap gap-2" data-ack-all>
                    <button type="button" class="cb-ack-all rounded border border-slate-300 px-2 py-0.5 text-xs font-semibold text-slate-700 hover:bg-slate-100" data-status="ack">Acknowledge all</button>
                    <button type="button" class="cb-ack-all rounded border border-slate-300 px-2 py-0.5 text-xs font-semibold text-slate-700 hover:bg-slate-100" data-status="done">Done all</button>
                </div>
            @endif
            <div id="cb-ack-form" class="my-1 rounded border border-slate-300 bg-slate-50 p-2 text-xs text-slate-800" hidden>
                <div id="cb-ack-title" class="mb-1 font-semibold"></div>
                <label class="block">Note <span class="text-slate-500">(what was done, or who is on it)</span>
                    <textarea id="cb-ack-note" rows="2" maxlength="{{ \App\Services\Chatbot\ProblemAcks::MAX_NOTE }}" class="mt-0.5 block w-full rounded border border-slate-300 px-1 py-0.5 text-xs"></textarea></label>
                <div id="cb-ack-until-row" class="mt-1 flex flex-wrap items-center gap-2">
                    <span>Hide until</span>
                    <select id="cb-ack-until" class="rounded border border-slate-300 px-1 py-0.5 text-xs">
                        <option value="1">1 day</option><option value="7">7 days</option><option value="30">30 days</option><option value="date">a date…</option>
                    </select>
                    <input id="cb-ack-date" type="date" class="rounded border border-slate-300 px-1 py-0.5 text-xs" hidden>
                    <span class="text-slate-500">It comes back sooner if it gets worse.</span>
                </div>
                <div id="cb-ack-done-help" class="mt-1 text-slate-500" hidden>Hidden for 30 minutes; it comes back marked if the problem is still found.</div>
                <div class="mt-2 flex gap-2"><button type="button" id="cb-ack-save" class="rounded bg-slate-800 px-2 py-0.5 text-white">Save</button><button type="button" id="cb-ack-cancel" class="rounded border border-slate-300 px-2 py-0.5">Cancel</button><span id="cb-ack-error" class="text-red-600"></span></div>
            </div>
        </div>
    </div>
@endif

<main class="flex min-h-0 flex-1 flex-col overflow-y-auto px-4 py-4 sm:px-6">
    <div class="mb-3 flex shrink-0 flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold">@yield('heading')</h1>
            @hasSection('description')
                <p class="mt-0.5 max-w-5xl text-sm text-slate-500">@yield('description')</p>
            @endif
        </div>
        <div class="flex flex-wrap items-center gap-4 pt-1 text-sm">@yield('actions')</div>
    </div>

    @yield('content')
</main>

<script>
    // Ang anino sa ilalim ng header row: lalabas lang kapag na-scroll na ang mga row.
    for (const s of document.querySelectorAll('.cb-scroll')) {
        s.addEventListener('scroll', () => s.classList.toggle('is-scrolled', s.scrollTop > 0), {passive: true});
    }
    // Ang banner ng mga problema: bukas o sarado, naaalala ng browser (walang localStorage sa ilang browser: sarado na lang).
    (() => {
        const btn = document.getElementById('cb-problems-toggle'), list = document.getElementById('cb-problems-list');
        if (!btn || !list) return;
        const actions = document.getElementById('cb-problems-actions');
        const show = open => { list.hidden = !open; if (actions) actions.hidden = !open; btn.setAttribute('aria-expanded', open ? 'true' : 'false'); };
        try { show(localStorage.getItem('cb-problems-open') === '1'); } catch (e) {}
        btn.addEventListener('click', () => {
            const open = list.hidden;
            show(open);
            try { localStorage.setItem('cb-problems-open', open ? '1' : '0'); } catch (e) {}
        });
        // Acknowledge / Done (isang linya, o lahat: CEO lang) sa iisang form: note (kailangan), at sa Acknowledge, hanggang kailan.
        // Ang Undo ay walang form.
        const form = document.getElementById('cb-ack-form'), $ = id => document.getElementById(id);
        let target = null;
        const post = async (url, body) => {
            const res = await fetch(url, {method: 'POST', headers: {'Content-Type': 'application/json', 'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content}, body: JSON.stringify(body)});
            const data = await res.json().catch(() => ({}));
            return res.ok ? null : (data.error || data.message || 'Not saved.');
        };
        const untilValue = () => {
            const v = $('cb-ack-until').value;
            if (v === 'date') return $('cb-ack-date').value;
            const d = new Date(Date.now() + Number(v) * 86400000);
            return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0') + ' '
                + String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
        };
        $('cb-ack-until')?.addEventListener('change', () => { $('cb-ack-date').hidden = $('cb-ack-until').value !== 'date'; });
        $('cb-ack-cancel')?.addEventListener('click', () => { form.hidden = true; target = null; });
        $('cb-ack-save')?.addEventListener('click', async () => {
            if (!target) return;
            const note = $('cb-ack-note').value.trim();
            if (!note) { $('cb-ack-error').textContent = 'Add a note first.'; return; }
            const until = target.status === 'ack' ? untilValue() : '';
            if (target.status === 'ack' && !until) { $('cb-ack-error').textContent = 'Pick a date.'; return; }
            $('cb-ack-save').disabled = true;
            try {
                const err = target.all
                    ? await post(@json(route('chatbot.problems.ack-all')), {status: target.status, note, until})
                    : await post(@json(route('chatbot.problems.ack')), {page: target.page, kind: target.kind, status: target.status, note, until, level: target.level});
                if (err) { $('cb-ack-error').textContent = err; $('cb-ack-save').disabled = false; return; }
                location.reload();
            } catch (e) { $('cb-ack-error').textContent = 'No connection: not saved.'; $('cb-ack-save').disabled = false; }
        });
        list.parentElement.addEventListener('click', async e => {
            const b = e.target.closest('.cb-ack, .cb-ack-all');
            if (!b || !form) return;
            const status = b.dataset.status, all = b.classList.contains('cb-ack-all');
            if (status === 'clear') {
                b.disabled = true;
                const err = await post(@json(route('chatbot.problems.ack')), {page: b.dataset.page, kind: b.dataset.kind, status: 'clear'}).catch(() => 'No connection: not saved.');
                if (err) { alert(err); b.disabled = false; return; }
                location.reload();
                return;
            }
            target = {status, all, page: b.dataset.page, kind: b.dataset.kind, level: b.dataset.level || ''};
            $('cb-ack-title').textContent = (status === 'done' ? (all ? 'Done all: every problem shown now' : 'Done') : (all ? 'Acknowledge all: every problem shown now' : 'Acknowledge'))
                + (all ? '' : ': ' + b.dataset.page);
            $('cb-ack-until-row').hidden = status !== 'ack';
            $('cb-ack-done-help').hidden = status !== 'done';
            $('cb-ack-error').textContent = '';
            $('cb-ack-note').value = '';
            $('cb-ack-save').disabled = false;
            // Sa ilalim ng pinindot (sa linya, o sa itaas para sa lahat).
            if (all) b.closest('[data-ack-all]').after(form); else b.closest('li').append(form);
            form.hidden = false;
            $('cb-ack-note').focus();
        });
    })();
</script>
@stack('scripts')
</body>
</html>

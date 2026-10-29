@extends('layouts.chatbot-tv')

@section('title', 'Live')

@php
    $c = $feed['counts'];
    // Ang mga hanay ng bawat bot: [pangalan, paliwanag, kulay].
    $bots = [
        'forbeast' => ['Bot 1', 'Forbeast', 'b1'],
        'incepxionbot' => ['Bot 2', 'Incepxionbot', 'b2'],
        'botcake' => ['Botcake', 'fallback', 'bc'],
    ];
    $linked = $c['linked'] + ['botcake' => true];
    // Ang paliwanag sa ilalim ng average na reply time, ayon sa estado ng Forbeast (iisa sa server at sa script).
    $notes = [
        'ok' => 'customer sent → our reply sent, on Meta’s clock (Forbeast pages)',
        'none' => 'No Forbeast replies yet today',
        'not_linked' => 'Not linked to Forbeast',
        'error' => 'Forbeast did not answer; trying again within a minute',
    ];
    $rt = $c['reply_time'];
    $rtNote = $notes[$rt['state'] === 'ok' && $rt['seconds'] === null ? 'none' : $rt['state']] ?? $notes['error'];
    $last = $c['last_order'];
    $lastText = $last ? $last['page'] . ' · ' . $last['at'] . ($last['bot'] ? ' · ' . $bots[$last['bot']][0] : '') : 'No order yet today';
    $n = fn ($v) => number_format((int) $v);
@endphp

@section('content')
<div class="lv" data-live data-live-board>
    <header class="lv-top">
        <h1>LIKHA LIVE</h1>
        <span class="lv-status" data-live-status="live"><i aria-hidden="true"></i><span data-live-state>LIVE</span></span>
        <form method="GET" action="{{ route('chatbot.live') }}" class="lv-filter" aria-label="Filters">
            <label for="lv-page">Page</label>
            <select id="lv-page" name="page_id" data-live-page>
                <option value="">All pages</option>
                @foreach($pages as $name => $id)<option value="{{ $id }}" @selected((string) $page === (string) $id)>{{ $name }}</option>@endforeach
            </select>
            <noscript><button>Show</button></noscript>
        </form>
        <span class="lv-sp">Today (Manila) · last update <time data-live-updated>{{ $feed['now'] }}</time></span>
        <a href="{{ route('chatbot.speed') }}" class="lv-exit" data-tv-exit>Exit TV mode</a>
    </header>

    <section class="lv-kpis" aria-label="Today">
        <div class="lv-kpi r"><h2>Replies today</h2><b data-live-count="replies">{{ $n($c['confirmed']['today']) }}</b><small>+<span data-live-recent="replies">{{ $n($c['confirmed']['recent']) }}</span> in last 5 min</small></div>
        <div class="lv-kpi t"><h2>Avg reply time</h2><b><span data-live-count="reply_time">{{ $rt['seconds'] === null ? '—' : number_format($rt['seconds'], 1) }}</span><span class="lv-unit" data-live-unit @if($rt['seconds'] === null) hidden @endif>s</span></b><small data-live-note="reply_time">{{ $rtNote }}</small></div>
        <div class="lv-kpi o"><h2>Orders today</h2><b data-live-count="orders">{{ $n($c['orders']['today']) }}</b><small>+<span data-live-recent="orders">{{ $n($c['orders']['recent']) }}</span> in last 5 min</small></div>
        <div class="lv-kpi c"><h2>Active chats</h2><b data-live-count="active">{{ $n($c['active']) }}</b><small>customers who messaged in last 5 min</small></div>
    </section>

    <main class="lv-board">
        <section class="lv-stages" aria-label="Flow from input to orders">
            <div class="lv-sh lv-sh-in">INPUT <span class="lv-arr" aria-hidden="true">▶</span><em>Customer nag-message · <span data-live-count="messages">{{ $n($c['messages']['today']) }}</span> today</em></div>
            <div class="lv-sh lv-sh-pr">PROCESS <span class="lv-arr" aria-hidden="true">▶</span><em>Bot nag-iisip (flow + AI)</em></div>
            <div class="lv-sh lv-sh-out">OUTPUT <span class="lv-arr" aria-hidden="true">▶</span><em>Bot sumagot · confirmed ng Meta</em></div>
            <div class="lv-sh lv-sh-ord">ORDERS<em>Resulta</em></div>

            @foreach($bots as $bot => [$name, $sub, $tone])
                <div class="lv-cell lv-in {{ $tone }}" style="grid-row: {{ $loop->iteration + 1 }}" data-bot-row="{{ $bot }}" data-linked="{{ $linked[$bot] ? 1 : 0 }}" data-cell="in-{{ $bot }}">
                    <span class="lv-tag">{{ strtoupper($name) }}<em>{{ $sub }}</em></span>
                    <b data-live-count="in-{{ $bot }}">{{ $n($c['bots']['in'][$bot]) }}</b><span class="lv-off">not linked</span>
                    <span class="lv-sub">messages in</span>
                </div>
                <div class="lv-rail {{ $tone }}" style="grid-row: {{ $loop->iteration + 1 }}" data-rail="{{ $bot }}" aria-hidden="true"></div>
                <div class="lv-cell lv-out {{ $tone }}" style="grid-row: {{ $loop->iteration + 1 }}" data-linked="{{ $linked[$bot] ? 1 : 0 }}" data-cell="out-{{ $bot }}">
                    <span class="lv-tag">{{ strtoupper($name) }}<em>{{ $sub }}</em></span>
                    <b data-live-count="out-{{ $bot }}">{{ $n($c['bots']['out'][$bot]) }}</b><span class="lv-off">not linked</span>
                    <span class="lv-sub">replies confirmed</span>
                </div>
            @endforeach
            <p class="lv-unknown lv-unknown-in" data-live-unknown="in" @if(!$c['bots']['in']['unknown']) hidden @endif>+<span data-live-count="in-unknown">{{ $n($c['bots']['in']['unknown']) }}</span> from before the bot was recorded</p>
            <p class="lv-unknown lv-unknown-out" data-live-unknown="out" @if(!$c['bots']['out']['unknown']) hidden @endif>+<span data-live-count="out-unknown">{{ $n($c['bots']['out']['unknown']) }}</span> with no bot recorded</p>

            <div class="lv-core" data-live-core>
                <div class="lv-ring"><span>LIKHA BOT</span></div>
                <h3>AI ANSWERS TODAY</h3>
                <div class="lv-ai" data-live-flash="ai"><span data-live-count="ai">{{ $n($c['ai']['today']) }}</span></div>
                <h3>FOLLOW-UPS SENT</h3>
                <div class="lv-fu" data-live-flash="follow_ups"><span data-live-count="follow_ups">{{ $n($c['follow_ups']['today']) }}</span></div>
            </div>

            <div class="lv-ords" data-live-flash="orders">
                <h3>ORDERS SAVED</h3>
                <b data-live-count="orders">{{ $n($c['orders']['today']) }}</b>
                <div class="lv-last">Last order<br><span data-live-last-order>{{ $lastText }}</span></div>
            </div>
        </section>

        <aside class="lv-stream" aria-labelledby="lv-h-log">
            <h2 id="lv-h-log">LATEST</h2>
            <ol data-live-log><li class="lv-empty" data-live-empty>Waiting for the next event…</li></ol>
        </aside>
    </main>
</div>

<script type="application/json" id="live-data">{!! json_encode(['feed' => route('chatbot.live.feed', [], false), 'page' => $page, 'cursor' => $feed['cursor'], 'counts' => $feed['counts'],
    'interval' => 5000, 'bots' => array_map(fn ($b) => $b[0], $bots), 'notes' => $notes], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endsection

@push('styles')
<style>
    /* Scoreboard sa TV: itim, malalaking numero na nababasa mula sa kabilang dulo ng kuwarto; 1920 × 1080 ang target, at
       isang column sa makitid na screen (390 px). Ang laki ay sumusunod sa lapad ng screen (rem mula sa vw). */
    :root { --bg: #05070a; --panel: #0b1016; --ink: #f3efe6; --mut: #8b94a3; --line: #1c2430;
        --b1: #38bdf8; --b2: #a3e635; --bc: #94a3b8; --ai: #f472b6; --fu: #a78bfa; --ord: #fbbf24; --ok: #34d399;
        --f: "Bahnschrift", "DIN Condensed", "Arial Narrow", "Segoe UI", system-ui, sans-serif; }
    * { box-sizing: border-box; margin: 0; }
    html { font-size: clamp(14px, .5vw + 8.5px, 20px); }
    body { background: var(--bg); color: var(--ink); font-family: var(--f); min-height: 100vh; overflow-x: hidden; font-variant-numeric: tabular-nums; }
    .lv { min-height: 100vh; display: flex; flex-direction: column; }
    [hidden] { display: none !important; }

    .lv-top { display: flex; align-items: center; gap: .6rem 1.2rem; padding: .7rem 1.4rem; border-bottom: 1px solid var(--line); flex-wrap: wrap; }
    .lv-top h1 { font-size: 1.3rem; letter-spacing: .2em; font-weight: 700; }
    .lv-status { display: inline-flex; align-items: center; gap: .5rem; color: var(--ok); font-weight: 700; letter-spacing: .15em; }
    .lv-status i { width: .7rem; height: .7rem; border-radius: 50%; background: currentColor; animation: lv-ping 2s infinite; }
    .lv-status[data-live-status="hidden"] { color: var(--ord); }
    .lv-status[data-live-status="error"], .lv-status[data-live-status="off"] { color: #f87171; }
    .lv-status:not([data-live-status="live"]) i { animation: none; }
    .lv-filter { display: flex; align-items: center; gap: .4rem; min-width: 0; color: var(--mut); font-size: .85rem; }
    .lv-filter select, .lv-filter button { min-width: 0; max-width: 14rem; border-radius: .3rem; border: 1px solid var(--line); background: var(--panel); color: var(--ink); padding: .2rem .4rem; font: inherit; }
    .lv-sp { margin-left: auto; color: var(--mut); font-size: .9rem; }
    .lv-exit { color: var(--mut); font-size: .8rem; text-decoration: underline; text-underline-offset: .2em; }
    .lv-exit:hover, .lv-exit:focus-visible { color: var(--ink); }
    .lv-filter select:focus-visible, .lv-exit:focus-visible { outline: 2px solid var(--b1); outline-offset: 2px; }

    .lv-kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); border-bottom: 1px solid var(--line); }
    .lv-kpi { min-width: 0; padding: 1rem 1.4rem; border-right: 1px solid var(--line); }
    .lv-kpi:last-child { border-right: 0; }
    .lv-kpi h2 { font-size: .95rem; letter-spacing: .16em; color: var(--mut); text-transform: uppercase; font-weight: 400; }
    .lv-kpi b { display: block; font-size: clamp(3rem, 5.8vw, 9.5rem); line-height: 1; font-weight: 700; letter-spacing: -.01em; margin-top: .15em; white-space: nowrap; }
    .lv-kpi .lv-unit { font-size: .4em; margin-left: .15em; }
    .lv-kpi small { display: block; margin-top: .3em; color: var(--mut); font-size: 1rem; }
    .lv-kpi.r b { color: var(--ok); } .lv-kpi.t b { color: #fff; } .lv-kpi.o b { color: var(--ord); } .lv-kpi.c b { color: var(--b1); }

    .lv-board { flex: 1; display: grid; grid-template-columns: minmax(0, 3fr) minmax(0, 1fr); min-height: 0; }
    .lv-stages { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.05fr) minmax(0, 1fr) minmax(0, .85fr); grid-template-rows: auto repeat(3, minmax(0, 1fr)) auto;
        position: relative; border-right: 1px solid var(--line); }
    .lv-sh { grid-row: 1; padding: .6rem 1.2rem; font-size: 1rem; letter-spacing: .2em; font-weight: 700; border-bottom: 1px solid var(--line); color: var(--mut); min-width: 0; }
    .lv-sh em { display: block; font-style: normal; letter-spacing: .02em; font-weight: 400; font-size: .8rem; opacity: .8; }
    .lv-arr { float: right; color: var(--line); }
    .lv-sh-in { grid-column: 1; } .lv-sh-pr { grid-column: 2; } .lv-sh-out { grid-column: 3; } .lv-sh-ord { grid-column: 4; }
    .b1 { --c: var(--b1); } .b2 { --c: var(--b2); } .bc { --c: var(--bc); }
    .lv-cell { min-width: 0; padding: .6rem 1.2rem; border-bottom: 1px solid var(--line); position: relative; z-index: 2; display: flex; flex-direction: column; justify-content: flex-start; }
    .lv-in { grid-column: 1; } .lv-out { grid-column: 3; }
    .lv-tag { font-size: .95rem; letter-spacing: .12em; font-weight: 700; color: var(--c); }
    .lv-tag em { font-style: normal; color: var(--mut); letter-spacing: .02em; font-weight: 400; margin-left: .4em; }
    .lv-cell b { font-size: clamp(2.4rem, 3.7vw, 6.2rem); line-height: 1.05; font-weight: 700; color: var(--c); transition: color .5s; white-space: nowrap; }
    .lv-cell.is-flash b { color: #fff; }
    .lv-sub { font-size: .85rem; color: var(--mut); }
    .lv-off { display: none; font-size: 1.4rem; color: var(--mut); letter-spacing: .08em; padding: .6em 0; }
    .lv-cell[data-linked="0"] b, .lv-cell[data-linked="0"] .lv-sub { display: none; }
    .lv-cell[data-linked="0"] .lv-off { display: block; }
    .lv-cell[data-linked="0"] .lv-tag { opacity: .55; }
    .lv-rail { grid-column: 1 / 4; align-self: end; height: 2px; margin-bottom: 1.1rem; background: linear-gradient(90deg, var(--c), transparent 90%); opacity: .5; position: relative; z-index: 1; }
    .lv-pk { position: absolute; top: -5px; left: 0; width: 12px; height: 12px; margin-left: -6px; border-radius: 50%; background: var(--c); box-shadow: 0 0 14px var(--c); }
    .lv-unknown { grid-row: 5; font-size: .8rem; color: var(--mut); padding: .3rem 1.2rem; min-width: 0; }
    .lv-unknown-in { grid-column: 1; } .lv-unknown-out { grid-column: 3; }

    .lv-core { grid-column: 2; grid-row: 2 / 6; z-index: 3; min-width: 0; background: radial-gradient(circle at 50% 42%, #0f2a22, var(--panel) 70%);
        border-left: 1px solid var(--line); border-right: 1px solid var(--line); display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; gap: .3rem; padding: 1rem; }
    .lv-ring { margin-bottom: 1.2rem; width: min(12rem, 60%); aspect-ratio: 1; border-radius: 50%; border: 3px solid var(--ok); display: grid; place-items: center; position: relative; animation: lv-beat 3.6s ease-in-out infinite; transition: box-shadow .4s; }
    .lv-ring::after { content: ""; position: absolute; inset: -14px; border-radius: 50%; border: 2px dashed rgba(52, 211, 153, .4); animation: lv-spin 30s linear infinite; }
    .lv-ring span { font-size: 1.3rem; font-weight: 700; letter-spacing: .12em; }
    .lv-core.is-busy .lv-ring { box-shadow: 0 0 40px rgba(52, 211, 153, .55); }
    .lv-core h3, .lv-ords h3 { font-size: .9rem; letter-spacing: .16em; color: var(--mut); font-weight: 400; }
    .lv-ai { font-size: clamp(2.2rem, 3.4vw, 5.6rem); font-weight: 700; color: var(--ai); line-height: 1; transition: color .5s; }
    .lv-fu { font-size: 1.8rem; font-weight: 700; color: var(--fu); transition: color .5s; }
    .lv-ai.is-flash, .lv-fu.is-flash { color: #fff; }

    .lv-ords { grid-column: 4; grid-row: 2 / 6; z-index: 3; min-width: 0; display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center; padding: 1rem; gap: .4rem;
        background: linear-gradient(180deg, rgba(251, 191, 36, .05), transparent); }
    .lv-ords b { font-size: clamp(4rem, 8vw, 13rem); line-height: 1; color: var(--ord); font-weight: 700; transition: transform .3s; white-space: nowrap; }
    .lv-ords.is-flash b { transform: scale(1.06); }
    .lv-last { font-size: .85rem; color: var(--mut); min-height: 2.6em; }
    .lv-last span { font-size: 1.05rem; color: var(--ord); overflow-wrap: anywhere; }

    .lv-stream { padding: .6rem 1.2rem; display: flex; flex-direction: column; min-width: 0; }
    .lv-stream h2 { font-size: 1rem; letter-spacing: .2em; color: var(--mut); padding-bottom: .5rem; border-bottom: 1px solid var(--line); margin-bottom: .4rem; font-weight: 700; }
    .lv-stream ol { list-style: none; padding: 0; display: grid; grid-template-rows: repeat(9, minmax(0, 1fr)); flex: 1; }
    .lv-stream li { display: grid; grid-template-columns: auto 6.6em minmax(0, 1fr); gap: .7rem; align-items: baseline; border-bottom: 1px solid #0f151d; font-size: 1rem; min-width: 0; animation: lv-in .6s both; }
    .lv-stream li:nth-child(n+6) { opacity: .7; } .lv-stream li:nth-child(n+8) { opacity: .45; }
    .lv-stream li.lv-empty { display: block; color: var(--mut); border: 0; }
    .lv-stream time { color: var(--mut); }
    .lv-stream b { letter-spacing: .1em; font-size: .85rem; }
    .lv-stream span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .t-message b { color: var(--b1); } .t-confirmed b { color: var(--ok); } .t-ai b { color: var(--ai); } .t-follow_up b { color: var(--fu); } .t-order b { color: var(--ord); }
    .t-order { font-weight: 700; }

    @keyframes lv-ping { 0% { box-shadow: 0 0 0 0 rgba(52, 211, 153, .6); } 100% { box-shadow: 0 0 0 .8rem rgba(52, 211, 153, 0); } }
    @keyframes lv-beat { 50% { transform: scale(1.04); } }
    @keyframes lv-spin { to { transform: rotate(360deg); } }
    @keyframes lv-in { from { opacity: 0; } }

    /* Malapad: isang screen, walang scroll ng page (ang stream ay 9 na linya lang). */
    @media (min-width: 901px) { .lv { height: 100vh; } .lv-board { overflow: hidden; } }
    /* Makitid (telepono, 390 px): isang column, ayon sa daloy: input, process, output, orders, saka ang stream. */
    @media (max-width: 900px) {
        .lv-top { padding: .6rem 1rem; }
        .lv-sp { margin-left: 0; }
        .lv-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .lv-kpi { border-bottom: 1px solid var(--line); padding: .8rem 1rem; }
        .lv-kpi:nth-child(2) { border-right: 0; }
        .lv-kpi b { font-size: clamp(2rem, 10vw, 3rem); }
        .lv-board { display: block; }
        .lv-stages { display: flex; flex-direction: column; border-right: 0; }
        .lv-sh-in { order: 1; } .lv-in { order: 2; } .lv-unknown-in { order: 3; } .lv-sh-pr { order: 4; } .lv-core { order: 5; }
        .lv-sh-out { order: 6; } .lv-out { order: 7; } .lv-unknown-out { order: 8; } .lv-sh-ord { order: 9; } .lv-ords { order: 10; }
        .lv-rail, .lv-arr { display: none; }
        .lv-cell { padding: .6rem 1rem; flex-direction: row; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: .5rem; }
        .lv-cell b { font-size: 2.4rem; }
        .lv-cell .lv-sub { display: none; }
        .lv-off { padding: 0; font-size: 1rem; }
        .lv-core, .lv-ords { padding: 1.2rem; }
        .lv-ords b { font-size: 5rem; }
        .lv-stream { padding: .8rem 1rem; }
        .lv-stream ol { grid-template-rows: none; }
        .lv-stream li { padding: .4rem 0; grid-template-columns: auto 5.6em minmax(0, 1fr); gap: .5rem; }
    }
    /* Reduced motion: walang gumagalaw (walang tuldok sa riles, tibok, ikot o fade); ang kulay lang ang nagbabago, at ang
       mga bilang ay gumagana pa rin. */
    @media (prefers-reduced-motion: reduce) {
        *, *::before, *::after { animation: none !important; transition: none !important; }
    }
</style>
@endpush

@push('scripts')
<script>
// /chatbot/live: ang feed ay pino-poll kada 5 segundo (tumitigil kapag nakatago ang tab), at ang bawat bagong event ay may
// maikling galaw. Tumatakbo ito buong araw sa TV: walang listahang lumalaki (ang stream ay 9 na linya, ang mga tuldok sa
// riles ay may hangganan at inaalis pagdating). Ang text mula sa server (pangalan ng page) ay textContent lang.
(() => {
    const boot = JSON.parse(document.getElementById('live-data').textContent);
    const root = document.querySelector('[data-live]');
    const mq = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
    const live = window.likhaLive = {reduced: !!(mq && mq.matches), cursor: boot.cursor, counts: boot.counts, timer: null, inflight: false, fail: 0, dots: 0};
    const MAX_DOTS = 24, LOG_LINES = 9;
    const LABEL = {message: 'MESSAGE', confirmed: 'REPLY', ai: 'AI', follow_up: 'FOLLOW-UP', order: 'ORDER'};
    const fmt = n => Number(n || 0).toLocaleString('en-US');
    const one = s => root.querySelector(s);

    function put(key, value, attr = 'data-live-count') {
        for (const e of root.querySelectorAll('[' + attr + '="' + key + '"]')) if (e.textContent !== value) e.textContent = value;
    }
    function setCounts(c) {
        live.counts = c;
        put('replies', fmt(c.confirmed.today)); put('replies', fmt(c.confirmed.recent), 'data-live-recent');
        put('orders', fmt(c.orders.today)); put('orders', fmt(c.orders.recent), 'data-live-recent');
        put('active', fmt(c.active));
        put('messages', fmt(c.messages.today));
        put('ai', fmt(c.ai.today));
        put('follow_ups', fmt(c.follow_ups.today));
        const rt = c.reply_time || {state: 'error', seconds: null};
        put('reply_time', rt.seconds === null ? '—' : Number(rt.seconds).toFixed(1));
        one('[data-live-unit]').hidden = rt.seconds === null;
        put('reply_time', boot.notes[rt.state === 'ok' && rt.seconds === null ? 'none' : rt.state] || boot.notes.error, 'data-live-note');
        for (const side of ['in', 'out']) {
            for (const bot of Object.keys(boot.bots)) put(side + '-' + bot, fmt(c.bots[side][bot]));
            put(side + '-unknown', fmt(c.bots[side].unknown));
            one('[data-live-unknown="' + side + '"]').hidden = !c.bots[side].unknown;
        }
        for (const bot of Object.keys(boot.bots)) {
            const on = bot === 'botcake' || !!(c.linked && c.linked[bot]);
            for (const side of ['in', 'out']) one('[data-cell="' + side + '-' + bot + '"]').dataset.linked = on ? '1' : '0';
        }
        const o = c.last_order;
        one('[data-live-last-order]').textContent = o ? o.page + ' · ' + o.at + (o.bot && boot.bots[o.bot] ? ' · ' + boot.bots[o.bot] : '') : 'No order yet today';
    }

    function flash(e, ms = 700) {
        if (!e) return;
        e.classList.add('is-flash');
        clearTimeout(e._lvT);
        e._lvT = setTimeout(() => e.classList.remove('is-flash'), ms);
    }
    function busy() {
        const core = one('[data-live-core]');
        core.classList.add('is-busy');
        clearTimeout(live.busyT);
        live.busyT = setTimeout(() => core.classList.remove('is-busy'), 1600);
    }
    // Isang tuldok sa riles ng bot, mula input hanggang output, kasing-tagal ng average na reply time (1.2 hanggang 8 s).
    function ride(bot) {
        const rail = one('[data-rail="' + bot + '"]');
        if (live.reduced || document.hidden || live.dots >= MAX_DOTS || !rail || !rail.offsetParent) return;
        const dot = document.createElement('i');
        if (!dot.animate) return;
        dot.className = 'lv-pk';
        rail.appendChild(dot);
        live.dots++;
        const s = live.counts.reply_time && live.counts.reply_time.seconds;
        const ms = Math.min(8000, Math.max(1200, (s || 2.5) * 1000));
        const a = dot.animate([{left: '0%'}, {left: '100%'}], {duration: ms, easing: 'linear'});
        const done = () => { if (dot.isConnected) { dot.remove(); live.dots--; } };
        a.onfinish = done;
        a.oncancel = done;
    }
    function show(e) {
        const bot = e.bot && boot.bots[e.bot] ? e.bot : null;
        if (e.type === 'message') { if (bot) { flash(one('[data-cell="in-' + bot + '"]')); ride(bot); } busy(); }
        else if (e.type === 'confirmed') { if (bot) flash(one('[data-cell="out-' + bot + '"]')); }
        else if (e.type === 'ai') flash(one('[data-live-flash="ai"]'));
        else if (e.type === 'follow_up') flash(one('[data-live-flash="follow_ups"]'));
        else if (e.type === 'order') flash(one('[data-live-flash="orders"]'), 900);
    }
    // Ang mga event ng isang poll ay ikinakalat sa loob ng ~4 segundo, ayon sa pagkakasunod.
    function play(events) {
        const gap = Math.min(600, 4200 / Math.max(1, events.length));
        events.forEach((e, i) => setTimeout(() => show(e), i * gap));
    }
    const logEl = one('[data-live-log]');
    function log(events) {
        if (!events.length) return;
        const empty = logEl.querySelector('[data-live-empty]');
        if (empty) empty.remove();
        for (const e of events.slice(-LOG_LINES)) {
            const li = document.createElement('li');
            li.className = 't-' + (LABEL[e.type] ? e.type : 'message');
            const t = document.createElement('time'); t.textContent = String(e.at).slice(11, 19);
            const b = document.createElement('b'); b.textContent = LABEL[e.type] || 'EVENT';
            const s = document.createElement('span'); s.textContent = e.page + (e.bot && boot.bots[e.bot] ? ' · ' + boot.bots[e.bot] : '');
            li.append(t, b, s);
            logEl.prepend(li);
        }
        while (logEl.children.length > LOG_LINES) logEl.lastElementChild.remove();
    }

    const statusEl = one('[data-live-status]');
    function setStatus(state, label) {
        statusEl.dataset.liveStatus = state;
        statusEl.querySelector('[data-live-state]').textContent = label;
    }
    async function poll() {
        clearTimeout(live.timer);
        if (document.hidden || live.inflight) return;
        live.inflight = true;
        const q = new URLSearchParams();
        if (boot.page) q.set('page_id', boot.page);
        for (const [k, v] of Object.entries(live.cursor || {})) q.set('cursor[' + k + ']', v);
        try {
            const res = await fetch(boot.feed + '?' + q, {headers: {Accept: 'application/json'}, credentials: 'same-origin', cache: 'no-store'});
            if ([401, 403, 419].includes(res.status)) { setStatus('off', 'SIGNED OUT: RELOAD'); return; }
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const data = await res.json();
            live.cursor = data.cursor;
            live.fail = 0;
            setCounts(data.counts);
            one('[data-live-updated]').textContent = data.now;
            log(data.events);
            play(data.events);
            setStatus('live', data.more > 0 ? 'LIVE · +' + fmt(data.more) + ' MORE' : 'LIVE');
        } catch (e) {
            live.fail++;
            setStatus('error', 'RECONNECTING…');
        } finally {
            live.inflight = false;
        }
        if (!document.hidden && statusEl.dataset.liveStatus !== 'off') live.timer = setTimeout(poll, live.fail ? Math.min(30000, boot.interval * 2 ** live.fail) : boot.interval);
    }
    document.addEventListener('visibilitychange', () => {
        if (statusEl.dataset.liveStatus === 'off') return;
        if (document.hidden) { clearTimeout(live.timer); setStatus('hidden', 'PAUSED (TAB HIDDEN)'); } else { setStatus('live', 'LIVE'); poll(); }
    });
    one('[data-live-page]').addEventListener('change', e => e.target.form.submit());
    if (mq && mq.addEventListener) mq.addEventListener('change', e => { live.reduced = e.matches; });

    setCounts(boot.counts);
    live.timer = setTimeout(poll, boot.interval);
})();
</script>
@endpush

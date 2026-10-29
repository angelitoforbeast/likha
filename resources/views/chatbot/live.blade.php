@extends('layouts.chatbot-tv')

@section('title', 'Live')

@php
    $c = $feed['counts'];
    // Ang mga bot: [pangalan, paliwanag, kulay].
    $bots = [
        'forbeast' => ['Bot 1', 'Forbeast', 'b1'],
        'incepxionbot' => ['Bot 2', 'Incepxionbot', 'b2'],
        'botcake' => ['Botcake', 'fallback', 'bc'],
    ];
    $linked = $c['linked'] + ['botcake' => true];
    // Ang pangalan ng site (Likha o Incepxion), ayon sa APP_URL.
    $site = \App\Services\Chatbot\ForbeastSite::label(\App\Services\Chatbot\ForbeastSite::self());
    // Ang paliwanag sa ilalim ng average na reply time, ayon sa estado ng Forbeast (iisa sa server at sa script).
    $notes = [
        'ok' => 'customer sent → our reply sent, on Meta’s clock (Forbeast pages)',
        'none' => 'No Forbeast replies yet today',
        'not_linked' => 'Not linked to Forbeast',
        'not_on_forbeast' => 'This page is not on Forbeast',
        'error' => 'Forbeast did not answer; trying again within a minute',
    ];
    $rt = $c['reply_time'];
    $rtNote = $notes[$rt['state'] === 'ok' && $rt['seconds'] === null ? 'none' : $rt['state']] ?? $notes['error'];
    $last = $c['last_order'];
    $lastText = $last ? mb_strimwidth($last['page'], 0, 24, '…') . ' · ' . $last['at'] . ($last['bot'] ? ' · ' . $bots[$last['bot']][0] : '') : 'no order yet today';
    $n = fn ($v) => number_format((int) $v);
    // Ang dalawang ayos ng neural map (viewBox units): malapad para sa TV, makitid (patayo) para sa telepono.
    $maps = [
        'wide' => ['key' => 'wide', 'w' => 1200, 'h' => 660, 'core' => [600, 330, 96], 'hub' => 24, 'scale' => 1, 'big' => 30, 'inq' => [40, 40],
            'bots' => ['forbeast' => [200, 185], 'incepxionbot' => [200, 350], 'botcake' => [200, 515]], 'fu' => [1000, 175], 'ord' => [1000, 480],
            'paths' => ['forbeast' => 'M 224 185 C 360 185 420 300 506 318', 'incepxionbot' => 'M 224 350 C 340 350 420 336 504 334',
                'botcake' => 'M 224 515 C 360 515 420 370 506 345', 'fu' => 'M 694 318 C 790 300 860 175 974 175', 'ord' => 'M 694 345 C 790 360 860 480 974 480'],
            'replies' => ['forbeast' => [432, 258], 'incepxionbot' => [410, 326], 'botcake' => [432, 424]], 'unknown' => [40, 640], 'last' => [1000, 594]],
        'narrow' => ['key' => 'narrow', 'w' => 400, 'h' => 830, 'core' => [200, 430, 74], 'hub' => 18, 'scale' => .38, 'big' => 22, 'inq' => [20, 34],
            'bots' => ['forbeast' => [70, 190], 'incepxionbot' => [200, 190], 'botcake' => [330, 190]], 'fu' => [100, 690], 'ord' => [300, 690],
            'paths' => ['forbeast' => 'M 70 272 C 70 330 150 340 186 360', 'incepxionbot' => 'M 200 272 L 200 356', 'botcake' => 'M 330 272 C 330 330 250 340 214 360',
                'fu' => 'M 185 503 C 160 580 110 600 100 672', 'ord' => 'M 215 503 C 240 580 290 600 300 672'],
            'replies' => ['forbeast' => [70, 258], 'incepxionbot' => [200, 258], 'botcake' => [330, 258]], 'unknown' => [20, 74], 'last' => [200, 808]],
    ];
@endphp

@section('content')
<div class="lv" data-live data-live-board>
    <header class="lv-top">
        <h1>{{ strtoupper($site) }} LIVE</h1>
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
        <section class="lv-map" aria-label="Neural map">
            <div class="lv-map-head"><span>NEURAL MAP</span><em>live activity · today's totals</em></div>
            @foreach($maps as $L)
                @include('chatbot.partials.live-map', ['L' => $L])
            @endforeach
            <ul class="lv-legend" aria-label="Legend">
                <li><i class="b1"></i><i class="b2"></i><i class="bc"></i>message → core</li>
                <li><i class="ok"></i>reply confirmed → bot</li>
                <li><i class="fu"></i>follow-up</li>
                <li><i class="ord"></i>order</li>
            </ul>
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
    /* Scoreboard sa TV: madilim na navy, malalaking numero sa itaas na nababasa mula sa kabilang dulo ng kuwarto, ang neural
       map sa gitna at ang 9 na linya ng Latest sa kanan; 1920 × 1080 ang target, at isang column sa telepono (390 px). */
    :root { --bg: #0a101c; --panel: #0d1626; --ink: #eef2f7; --mut: #8592a6; --line: #1a2638; --net: #2b4566;
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
    .lv-map { min-width: 0; min-height: 0; display: flex; flex-direction: column; border-right: 1px solid var(--line); padding: .5rem .8rem .3rem; }
    .lv-map-head { display: flex; gap: .8rem; align-items: baseline; font-size: .85rem; letter-spacing: .2em; font-weight: 700; color: var(--b1); }
    .lv-map-head em { font-style: normal; font-weight: 400; color: var(--mut); letter-spacing: .1em; font-size: .75rem; }
    .lv-legend { display: flex; flex-wrap: wrap; justify-content: center; gap: .3rem 1.4rem; list-style: none; padding: .3rem 0 0; font-size: .75rem; color: var(--mut); }
    .lv-legend i { display: inline-block; width: 1.2em; height: 2px; margin-right: .3em; vertical-align: middle; background: var(--c); }
    .lv-legend i + i { margin-left: -.1em; }

    /* Ang neural map (SVG). */
    .nm { display: block; width: 100%; flex: 1; min-height: 0; margin-top: .3rem; font-family: var(--f); }
    .nm-narrow { display: none; }
    .b1 { --c: var(--b1); } .b2 { --c: var(--b2); } .bc { --c: var(--bc); } .fu { --c: var(--fu); } .ord { --c: var(--ord); } .ok { --c: var(--ok); }
    .nm-bg { fill: var(--panel); }
    .nm-gridline { fill: none; stroke: #131f33; stroke-width: 1; }
    .nm-bracket { fill: none; stroke: #2a3a52; stroke-width: 2; }
    .nm-line { stroke: var(--c); stroke-opacity: .22; stroke-width: 1; }
    .nm-node { fill: var(--panel); stroke: var(--c); stroke-opacity: .7; stroke-width: 1.4; }
    .nm-dot { fill: var(--c); fill-opacity: .8; }
    .nm-tw { animation: nm-tw 5s ease-in-out infinite alternate; }
    .nm-path { fill: none; stroke: var(--c); stroke-opacity: .35; stroke-width: 1.6; }
    .nm-halo { fill: none; stroke: var(--c); stroke-opacity: .25; stroke-width: 1; stroke-dasharray: 3 5; }
    .nm-hub { fill: color-mix(in srgb, var(--c) 14%, var(--panel)); stroke: var(--c); stroke-width: 2; transition: fill .4s; }
    .nm-icon { fill: none; stroke: var(--c); stroke-width: 2; stroke-linejoin: round; stroke-linecap: round; }
    .nm-cluster.is-hit .nm-hub { fill: color-mix(in srgb, var(--c) 55%, var(--panel)); }
    .nm-cluster.is-hit .nm-halo { stroke-opacity: .9; }
    .nm text { font-family: var(--f); }
    .nm-h { fill: var(--c, var(--b1)); font-size: 20px; font-weight: 700; letter-spacing: .2em; }
    .nm-cluster .nm-h { text-anchor: middle; }
    .nm-sub { fill: var(--mut); font-size: 12px; letter-spacing: .08em; }
    .nm-mid { text-anchor: middle; }
    .nm-tag { fill: var(--c); font-size: 14px; font-weight: 700; letter-spacing: .12em; text-anchor: middle; }
    .nm-tag2 { fill: var(--mut); font-weight: 400; font-size: 11px; }
    .nm-count { fill: var(--c); font-weight: 700; text-anchor: middle; }
    .nm-off { display: none; fill: var(--mut); font-size: 14px; letter-spacing: .08em; text-anchor: middle; }
    [data-linked="0"] .nm-count, .nm-reply[data-linked="0"] { display: none; }
    [data-linked="0"] .nm-off { display: inline; }
    .nm-cluster[data-linked="0"] { opacity: .5; }
    .nm-reply { fill: var(--ok); font-size: 14px; font-weight: 700; text-anchor: middle; }
    .nm-last { fill: var(--ord); font-size: 15px; text-anchor: middle; }
    .nm-core-arc { fill: none; stroke: var(--b1); stroke-opacity: .3; stroke-width: 2; stroke-dasharray: 120 40 30 60; }
    .nm-core-spin { fill: none; stroke: var(--fu); stroke-opacity: .55; stroke-width: 2.5; stroke-dasharray: 90 30 10 30; transform-box: fill-box; transform-origin: center; animation: nm-spin 40s linear infinite; }
    .nm-core-ring { stroke: var(--b1); stroke-opacity: .8; stroke-width: 2.5; transform-box: fill-box; transform-origin: center; animation: nm-beat 4s ease-in-out infinite; transition: stroke .4s; }
    .nm-core-inner { fill: none; stroke: var(--fu); stroke-opacity: .35; stroke-width: 1; stroke-dasharray: 2 6; }
    .nm-core-name { fill: #fff; font-weight: 700; letter-spacing: .14em; text-anchor: middle; }
    .nm-core-sub { fill: var(--mut); letter-spacing: .45em; text-anchor: middle; }
    .nm-core-ai { fill: var(--ai); font-weight: 700; text-anchor: middle; letter-spacing: .05em; }
    .nm-core.is-hit .nm-core-ring { stroke: #fff; filter: drop-shadow(0 0 10px rgba(56, 189, 248, .8)); }
    .nm-narrow .nm-h { font-size: 16px; }
    .nm-narrow .nm-tag { font-size: 12px; }
    .nm-narrow .nm-reply { font-size: 12px; }
    .nm-narrow .nm-sub { font-size: 11px; }
    .nm-narrow .nm-last { font-size: 13px; }

    .lv-stream { padding: .6rem 1.2rem; display: flex; flex-direction: column; min-width: 0; }
    .lv-stream h2 { font-size: 1rem; letter-spacing: .2em; color: var(--mut); padding-bottom: .5rem; border-bottom: 1px solid var(--line); margin-bottom: .4rem; font-weight: 700; }
    .lv-stream ol { list-style: none; padding: 0; display: grid; grid-template-rows: repeat(9, minmax(0, 1fr)); flex: 1; }
    .lv-stream li { display: grid; grid-template-columns: auto 6.6em minmax(0, 1fr); gap: .7rem; align-items: baseline; border-bottom: 1px solid #111b2b; font-size: 1rem; min-width: 0; animation: lv-in .6s both; }
    .lv-stream li:nth-child(n+6) { opacity: .7; } .lv-stream li:nth-child(n+8) { opacity: .45; }
    .lv-stream li.lv-empty { display: block; color: var(--mut); border: 0; }
    .lv-stream time { color: var(--mut); }
    .lv-stream b { letter-spacing: .1em; font-size: .85rem; }
    .lv-stream span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .t-message b { color: var(--b1); } .t-confirmed b { color: var(--ok); } .t-ai b { color: var(--ai); } .t-follow_up b { color: var(--fu); } .t-order b { color: var(--ord); }
    .t-order { font-weight: 700; }

    @keyframes lv-ping { 0% { box-shadow: 0 0 0 0 rgba(52, 211, 153, .6); } 100% { box-shadow: 0 0 0 .8rem rgba(52, 211, 153, 0); } }
    @keyframes lv-in { from { opacity: 0; } }
    @keyframes nm-tw { from { opacity: .35; } to { opacity: 1; } }
    @keyframes nm-spin { to { transform: rotate(360deg); } }
    @keyframes nm-beat { 50% { transform: scale(1.025); } }

    /* Malapad: isang screen, walang scroll ng page (ang stream ay 9 na linya lang). */
    @media (min-width: 901px) { .lv { height: 100vh; } .lv-board { overflow: hidden; } }
    /* Makitid (telepono, 390 px): isang column; ang mapa ay patayo (bot sa itaas, core sa gitna, follow-up at orders sa ibaba). */
    @media (max-width: 900px) {
        .lv-top { padding: .6rem 1rem; }
        .lv-sp { margin-left: 0; }
        .lv-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .lv-kpi { border-bottom: 1px solid var(--line); padding: .8rem 1rem; }
        .lv-kpi:nth-child(2) { border-right: 0; }
        .lv-kpi b { font-size: clamp(2rem, 10vw, 3rem); }
        .lv-board { display: block; }
        .lv-map { border-right: 0; padding: .5rem .5rem .3rem; }
        .nm-wide { display: none; }
        .nm-narrow { display: block; flex: none; height: auto; }
        .lv-stream { padding: .8rem 1rem; }
        .lv-stream ol { grid-template-rows: none; }
        .lv-stream li { padding: .4rem 0; grid-template-columns: auto 5.6em minmax(0, 1fr); gap: .5rem; }
    }
    /* Reduced motion: walang gumagalaw (walang ilaw sa path, tibok, ikot o kislap); ang kulay lang ng tinamaan ang
       nagbabago, at ang mga bilang ay gumagana pa rin. */
    @media (prefers-reduced-motion: reduce) {
        *, *::before, *::after { animation: none !important; transition: none !important; }
    }
</style>
@endpush

@push('scripts')
<script>
// /chatbot/live: ang feed ay pino-poll kada 5 segundo (tumitigil kapag nakatago ang tab); ang bawat bagong event ay isang
// ilaw sa neural map: ang mensahe mula sa cluster ng bot papunta sa core; ang kumpirmadong sagot, follow-up at order mula
// sa core palabas. Tumatakbo ito buong araw sa TV, kaya walang lumalaking DOM: hanggang 30 ilaw na ginagamit muli (ang
// sobra ay hindi na iginuguhit), iisang animation loop, at 9 na linya lang sa stream. Ang text mula sa server (pangalan ng
// page) ay textContent lang.
(() => {
    const boot = JSON.parse(document.getElementById('live-data').textContent);
    const root = document.querySelector('[data-live]');
    const mq = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
    const live = window.likhaLive = {reduced: !!(mq && mq.matches), cursor: boot.cursor, counts: boot.counts, timer: null, inflight: false, fail: 0, active: 0, sampled: 0};
    const LIGHTS = 30, LOG_LINES = 9, MAX_PER_POLL = 60, TRAVEL = 1800;
    const LABEL = {message: 'MESSAGE', confirmed: 'REPLY', ai: 'AI', follow_up: 'FOLLOW-UP', order: 'ORDER'};
    const COLOR = {forbeast: '--b1', incepxionbot: '--b2', botcake: '--bc', confirmed: '--ok', follow_up: '--fu', order: '--ord'};
    const fmt = n => Number(n || 0).toLocaleString('en-US');
    const all = s => root.querySelectorAll(s);
    const css = v => getComputedStyle(root).getPropertyValue(v).trim() || '#fff';
    const toggle = (e, on) => on ? e.removeAttribute('hidden') : e.setAttribute('hidden', '');

    function put(key, value, attr = 'data-live-count') {
        for (const e of all('[' + attr + '="' + key + '"]')) if (e.textContent !== value) e.textContent = value;
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
        for (const e of all('[data-live-unit]')) toggle(e, rt.seconds !== null);
        put('reply_time', boot.notes[rt.state === 'ok' && rt.seconds === null ? 'none' : rt.state] || boot.notes.error, 'data-live-note');
        for (const side of ['in', 'out']) {
            for (const bot of Object.keys(boot.bots)) put(side + '-' + bot, fmt(c.bots[side][bot]));
            put(side + '-unknown', fmt(c.bots[side].unknown));
            for (const e of all('[data-live-unknown="' + side + '"]')) toggle(e, !!c.bots[side].unknown);
        }
        for (const bot of Object.keys(boot.bots)) {
            const on = bot === 'botcake' || !!(c.linked && c.linked[bot]) ? '1' : '0';
            for (const e of all('[data-bot-row="' + bot + '"], [data-reply-row="' + bot + '"]')) e.setAttribute('data-linked', on);
        }
        const o = c.last_order, page = o ? (o.page.length > 24 ? o.page.slice(0, 23) + '…' : o.page) : '';
        const last = o ? page + ' · ' + o.at + (o.bot && boot.bots[o.bot] ? ' · ' + boot.bots[o.bot] : '') : 'no order yet today';
        for (const e of all('[data-live-last-order]')) if (e.textContent !== last) e.textContent = last;
    }

    // Ang nakikitang mapa (malapad o makitid) at ang mga ilaw nito: iisang hanay ng LIGHTS na bilog, ginagamit muli.
    const maps = {};
    for (const svg of all('[data-map]')) {
        const layer = svg.querySelector('[data-lights]'), pool = [];
        for (let i = 0; i < LIGHTS; i++) {
            const g = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
            g.setAttribute('r', svg.dataset.map === 'wide' ? 5 : 4);
            g.setAttribute('filter', 'url(#nm-glow-' + svg.dataset.map + ')');
            g.style.display = 'none';
            layer.appendChild(g);
            pool.push({el: g, busy: false});
        }
        const paths = {};
        // Ang haba ng path ay kinukuha sa unang ilaw (ang nakatagong mapa ay walang haba sa ilang browser); hindi ito pumipigil sa pahina.
        for (const p of svg.querySelectorAll('[data-path]')) paths[p.dataset.path] = {el: p, len: 0};
        maps[svg.dataset.map] = {svg, pool, paths};
    }
    const visible = () => Object.values(maps).find(m => m.svg.getBoundingClientRect().width > 0);
    const moving = [];
    let raf = 0;
    function frame(now) {
        for (let i = moving.length - 1; i >= 0; i--) {
            const m = moving[i], k = Math.min(1, (now - m.t0) / m.dur);
            const pt = m.path.el.getPointAtLength(m.path.len * (m.out ? k : 1 - k));
            m.light.el.setAttribute('cx', pt.x); m.light.el.setAttribute('cy', pt.y);
            if (k >= 1) {
                m.light.el.style.display = 'none';
                m.light.busy = false;
                moving.splice(i, 1);
                live.active--;
                hit(m.map, m.target);
            }
        }
        raf = moving.length ? requestAnimationFrame(frame) : 0;
    }
    // Isang ilaw sa path: out = mula sa core palabas, kung hindi ay papasok sa core. Walang bakanteng ilaw = hindi iginuguhit.
    function travel(map, pathKey, out, color, target) {
        const path = map.paths[pathKey];
        if (!path) return;
        if (!path.len) { try { path.len = path.el.getTotalLength(); } catch (e) { path.len = 0; } }
        if (live.reduced || document.hidden || !path.len) { hit(map, target); return; }
        const light = map.pool.find(l => !l.busy);
        if (!light) { live.sampled++; hit(map, target); return; }
        light.busy = true;
        light.el.setAttribute('fill', css(color));
        light.el.style.display = '';
        live.active++;
        moving.push({map, path, light, out, target, t0: performance.now(), dur: TRAVEL});
        if (!raf) raf = requestAnimationFrame(frame);
    }
    function hit(map, target) {
        const g = map.svg.querySelector('[data-hit="' + target + '"]');
        if (!g) return;
        g.classList.add('is-hit');
        clearTimeout(g._lvT);
        g._lvT = setTimeout(() => g.classList.remove('is-hit'), 600);
    }
    function show(e) {
        const map = visible();
        if (!map) return;
        const bot = e.bot && boot.bots[e.bot] ? e.bot : null;
        if (e.type === 'message') bot ? travel(map, bot, false, COLOR[bot], 'core') : hit(map, 'core');
        else if (e.type === 'confirmed') { if (bot) travel(map, bot, true, COLOR.confirmed, bot); }
        else if (e.type === 'follow_up') travel(map, 'fu', true, COLOR.follow_up, 'fu');
        else if (e.type === 'order') travel(map, 'ord', true, COLOR.order, 'ord');
        else if (e.type === 'ai') hit(map, 'core');
    }
    // Ang mga event ng isang poll ay ikinakalat sa loob ng ~4 segundo; kapag sobrang dami, isa sa bawat ilan lang ang ilaw
    // (ang mga order ay laging kasama).
    function play(events) {
        const step = Math.ceil(events.length / MAX_PER_POLL);
        const shown = step > 1 ? events.filter((e, i) => e.type === 'order' || i % step === 0) : events;
        live.sampled += events.length - shown.length;
        const gap = Math.min(600, 4200 / Math.max(1, shown.length));
        shown.forEach((e, i) => setTimeout(() => show(e), i * gap));
    }
    const logEl = root.querySelector('[data-live-log]');
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

    const statusEl = root.querySelector('[data-live-status]');
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
            // Ang request na nabitin (hal. nawala ang Wi-Fi ng TV) ay pinuputol pagkalipas ng 15 s, kaya tuloy ang ulit.
            const res = await fetch(boot.feed + '?' + q, {headers: {Accept: 'application/json'}, credentials: 'same-origin', cache: 'no-store',
                signal: window.AbortSignal && AbortSignal.timeout ? AbortSignal.timeout(15000) : undefined});
            if ([401, 403, 419].includes(res.status)) { setStatus('off', 'SIGNED OUT: RELOAD'); return; }
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const data = await res.json();
            live.cursor = data.cursor;
            live.fail = 0;
            setCounts(data.counts);
            for (const e of all('[data-live-updated]')) e.textContent = data.now;
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
    root.querySelector('[data-live-page]').addEventListener('change', e => e.target.form.submit());
    if (mq && mq.addEventListener) mq.addEventListener('change', e => { live.reduced = e.matches; });
    live.show = show;

    setCounts(boot.counts);
    live.timer = setTimeout(poll, boot.interval);
})();
</script>
@endpush

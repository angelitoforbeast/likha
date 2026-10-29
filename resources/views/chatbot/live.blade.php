@extends('layouts.chatblast')

@section('title', 'Chatbot · Live')
@section('heading', 'Live')

@section('description')
    What the bot is doing right now: customer messages coming in, the bot and the AI working on them, replies confirmed by Meta, follow-ups going out and orders saved. Counts are for today (Manila) and refresh every few seconds. No customer names or messages are shown on this screen.
@endsection

@php
    $c = $feed['counts'];
    // Ang mga bilang sa itaas: [label, kulay ng type].
    $tiles = [
        'messages' => ['Messages received', 'inq'],
        'confirmed' => ['Bot replies confirmed by Meta', 'core'],
        'follow_ups' => ['Follow-ups sent', 'fu'],
        'ai' => ['AI answers', 'ai'],
        'orders' => ['Orders saved', 'ord'],
    ];
@endphp

@section('content')
<div class="lv shrink-0 space-y-4 pb-6" data-live>
    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
        <span class="lv-status" data-live-status="live"><span class="lv-dot" aria-hidden="true"></span><span data-live-state>Live</span></span>
        <span class="lv-muted">last update <time class="tabular-nums" data-live-updated>{{ $feed['now'] }}</time></span>
        <form method="GET" action="{{ route('chatbot.live') }}" class="flex min-w-0 items-center gap-2" aria-label="Filters">
            <label for="lv-page" class="lv-muted">Page</label>
            <select id="lv-page" name="page_id" class="lv-select" data-live-page>
                <option value="">All pages</option>
                @foreach($pages as $name => $id)<option value="{{ $id }}" @selected((string) $page === (string) $id)>{{ $name }}</option>@endforeach
            </select>
            <noscript><button class="lv-btn">Show</button></noscript>
        </form>
        <div class="ml-auto flex gap-2">
            <button type="button" class="lv-btn" data-live-pause aria-pressed="false">Pause</button>
            <button type="button" class="lv-btn" data-live-preview>Preview</button>
        </div>
    </div>

    <section aria-label="Today" class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
        @foreach($tiles as $key => [$label, $tone])
            <div class="lv-tile lv-tone-{{ $tone }} {{ $loop->last ? 'col-span-2 sm:col-span-1' : '' }}" data-tile="{{ $key }}">
                <div class="lv-tile-label">{{ $label }}</div>
                <div class="mt-1 flex flex-wrap items-baseline gap-x-2">
                    <span class="lv-num tabular-nums" data-live-count="{{ $key }}">{{ number_format($c[$key]['today']) }}</span>
                    <span class="lv-recent tabular-nums">+<span data-live-recent="{{ $key }}">{{ number_format($c[$key]['recent']) }}</span> in 5 min</span>
                </div>
                @if($key === 'confirmed')
                    <div class="lv-sub tabular-nums"><span data-live-count="unconfirmed">{{ number_format($c['unconfirmed']['today']) }}</span> sent, not confirmed yet</div>
                @endif
            </div>
        @endforeach
    </section>

    <section class="lv-stage" aria-label="Live map">
        <div id="live-map" class="lv-map" role="img" aria-label="Live map: customer messages flow from Inquiries into the Likha Bot core, the AI node answers, and the core sends follow-ups and saves orders."></div>
        <p class="lv-badge" data-live-badge hidden></p>
        <ul class="lv-legend" aria-label="Legend">
            <li><i class="lv-tone-inq"></i>Message in</li>
            <li><i class="lv-tone-ai"></i>AI answer</li>
            <li><i class="lv-tone-core"></i>Reply confirmed by Meta</li>
            <li><i class="lv-tone-fu"></i>Follow-up sent</li>
            <li><i class="lv-tone-ord"></i>Order saved</li>
        </ul>
    </section>

    <section aria-labelledby="lv-h-log">
        <h2 id="lv-h-log" class="mb-2 text-sm font-semibold text-slate-200">Latest events</h2>
        <ol class="lv-log" data-live-log><li class="lv-muted" data-live-empty>Waiting for the next event…</li></ol>
    </section>
</div>

<script type="application/json" id="live-data">{!! json_encode(['feed' => route('chatbot.live.feed', [], false), 'page' => $page, 'cursor' => $feed['cursor'], 'counts' => $feed['counts'], 'interval' => 5000],
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endsection

@push('styles')
<style>
    /* Madilim ang page na ito lang (ang top bar ng app ay gaya ng dati). */
    :root { --lv-bg: #050912; --lv-panel: rgba(15, 23, 42, .72); --lv-line: #1e293b; --lv-text: #e2e8f0; --lv-muted: #94a3b8;
        --lv-inq: #22d3ee; --lv-core: #34d399; --lv-fu: #a78bfa; --lv-ord: #fbbf24; --lv-ai: #f472b6; }
    body[data-chatblast-app] main { background: radial-gradient(1100px 620px at 50% 38%, #0c1b31 0%, var(--lv-bg) 62%); color: var(--lv-text); }
    body[data-chatblast-app] main h1 { color: #f8fafc; }
    body[data-chatblast-app] main p.text-slate-500 { color: var(--lv-muted); }
    .lv-muted { color: var(--lv-muted); }
    .lv-select { max-width: 14rem; min-width: 0; border-radius: .375rem; border: 1px solid #334155; background: #0f172a; color: var(--lv-text); padding: .25rem .5rem; }
    .lv-btn { border-radius: .375rem; border: 1px solid #334155; background: #0f172a; color: var(--lv-text); padding: .375rem .75rem; font-weight: 500; }
    .lv-btn:hover { background: #1e293b; }
    .lv-btn:focus-visible, .lv-select:focus-visible { outline: 2px solid var(--lv-inq); outline-offset: 2px; }
    .lv-btn[aria-pressed="true"] { border-color: var(--lv-ord); color: var(--lv-ord); }
    .lv-btn:disabled { opacity: .5; cursor: not-allowed; }
    .lv-status { display: inline-flex; align-items: center; gap: .4rem; font-weight: 600; color: var(--lv-core); }
    .lv-dot { width: .6rem; height: .6rem; border-radius: 9999px; background: currentColor; box-shadow: 0 0 0 0 currentColor; animation: lv-ping 2s ease-out infinite; }
    .lv-status[data-live-status="paused"], .lv-status[data-live-status="hidden"] { color: var(--lv-ord); }
    .lv-status[data-live-status="error"], .lv-status[data-live-status="off"] { color: #f87171; }
    .lv-status:not([data-live-status="live"]) .lv-dot { animation: none; }
    .lv-tone-inq { --tone: var(--lv-inq); } .lv-tone-core { --tone: var(--lv-core); } .lv-tone-fu { --tone: var(--lv-fu); }
    .lv-tone-ord { --tone: var(--lv-ord); } .lv-tone-ai { --tone: var(--lv-ai); }
    .lv-tile { min-width: 0; border: 1px solid var(--lv-line); border-top: 2px solid var(--tone); border-radius: .5rem; background: var(--lv-panel); padding: .6rem .75rem; }
    .lv-tile-label { font-size: .75rem; color: var(--lv-muted); line-height: 1.2; }
    .lv-num { font-size: 1.6rem; font-weight: 700; color: #f8fafc; line-height: 1.1; transition: color .6s; }
    .lv-num.is-bump { color: var(--tone); }
    .lv-recent { font-size: .75rem; color: var(--tone); }
    .lv-sub { margin-top: .15rem; font-size: .7rem; color: var(--lv-muted); }
    .lv-stage { position: relative; border: 1px solid var(--lv-line); border-radius: .75rem; background: rgba(2, 6, 17, .55); padding: .5rem; overflow: hidden; }
    .lv-map { width: 100%; }
    .lv-svg { display: block; width: 100%; height: auto; max-height: 72vh; margin: 0 auto; }
    .lv-badge { position: absolute; top: .6rem; left: 50%; transform: translateX(-50%); max-width: calc(100% - 1rem); border-radius: 9999px; border: 1px solid #334155;
        background: rgba(15, 23, 42, .9); padding: .15rem .7rem; font-size: .75rem; color: var(--lv-text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .lv-legend { display: flex; flex-wrap: wrap; gap: .35rem 1rem; padding: .25rem .5rem .1rem; font-size: .72rem; color: var(--lv-muted); }
    .lv-legend i { display: inline-block; width: .55rem; height: .55rem; border-radius: 9999px; background: var(--tone); margin-right: .35rem; vertical-align: middle; }
    .lv-log { display: grid; gap: .2rem; font-size: .8rem; }
    .lv-log li { display: flex; gap: .6rem; min-width: 0; }
    .lv-log time { color: var(--lv-muted); font-variant-numeric: tabular-nums; }
    .lv-log b { color: var(--tone); font-weight: 600; white-space: nowrap; }
    .lv-log span { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--lv-text); }

    /* Ang mapa (iginuguhit ng script). */
    .lv-link { fill: none; stroke: #1f3550; stroke-width: 2; }
    .lv-flow { fill: none; stroke: var(--tone); stroke-opacity: .35; stroke-width: 2; stroke-dasharray: 2 12; animation: lv-flow 2.4s linear infinite; }
    .lv-halo { fill: color-mix(in srgb, var(--tone) 7%, transparent); stroke: var(--tone); stroke-opacity: .35; stroke-width: 1.5; stroke-dasharray: 4 6; }
    .lv-node-dot { fill: var(--tone); opacity: .55; transition: opacity .3s; }
    .lv-node-dot.is-blink { opacity: 1; }
    .lv-label { fill: #e2e8f0; font-weight: 600; text-anchor: middle; font-family: ui-sans-serif, system-ui, sans-serif; }
    .lv-count { fill: var(--tone); font-weight: 700; text-anchor: middle; font-family: ui-sans-serif, system-ui, sans-serif; font-variant-numeric: tabular-nums; }
    .lv-small { fill: var(--lv-muted); text-anchor: middle; font-family: ui-sans-serif, system-ui, sans-serif; }
    .lv-cluster.is-hit .lv-halo { fill: color-mix(in srgb, var(--tone) 28%, transparent); stroke-opacity: 1; }
    .lv-cluster .lv-halo { transition: fill .5s, stroke-opacity .5s; }
    .lv-heart { fill: url(#lv-core-grad); stroke: var(--lv-core); stroke-width: 2; transform-box: fill-box; transform-origin: center; animation: lv-beat 3.6s ease-in-out infinite; transition: filter .4s; }
    .lv-core.is-busy .lv-heart { filter: url(#lv-glow) brightness(1.5); }
    .lv-spin { fill: none; stroke: var(--lv-core); stroke-opacity: .45; stroke-width: 2; stroke-dasharray: 3 9; transform-box: fill-box; transform-origin: center; animation: lv-spin 40s linear infinite; }
    .lv-core.is-busy .lv-spin { stroke-opacity: .9; animation-duration: 6s; }
    .lv-ring { fill: none; stroke: #4ade80; stroke-opacity: .18; stroke-width: 2; transform-box: fill-box; transform-origin: center; }
    .lv-ring.is-flash { animation: lv-ringflash .9s ease-out; }
    .lv-ai-node { fill: color-mix(in srgb, var(--lv-ai) 14%, #050912); stroke: var(--lv-ai); stroke-width: 2; transition: fill .4s; }
    .lv-ai.is-hit .lv-ai-node { fill: color-mix(in srgb, var(--lv-ai) 45%, #050912); }
    .lv-pulse { pointer-events: none; }
    .lv-spark { stroke: var(--lv-ai); stroke-width: 2; stroke-linecap: round; }
    @keyframes lv-ping { 0% { box-shadow: 0 0 0 0 color-mix(in srgb, currentColor 60%, transparent); } 100% { box-shadow: 0 0 0 .5rem transparent; } }
    @keyframes lv-flow { to { stroke-dashoffset: -28; } }
    @keyframes lv-beat { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.035); } }
    @keyframes lv-spin { to { transform: rotate(360deg); } }
    @keyframes lv-ringflash { 0% { stroke-opacity: 1; stroke-width: 7; transform: scale(1); } 100% { stroke-opacity: .18; stroke-width: 2; transform: scale(1.14); } }

    /* Reduced motion: walang gumagalaw (walang travelling pulse, heartbeat, ikot o daloy); ilaw at kulay lang ang nagbabago,
       at ang mga bilang ay gumagana pa rin. Ang .lv-reduced ay inilalagay ng script mula sa parehong media query. */
    @media (prefers-reduced-motion: reduce) {
        .lv-dot, .lv-flow, .lv-heart, .lv-spin, .lv-ring.is-flash { animation: none !important; }
    }
    .lv-reduced .lv-dot, .lv-reduced .lv-flow, .lv-reduced .lv-heart, .lv-reduced .lv-spin, .lv-reduced .lv-ring.is-flash { animation: none !important; }
    .lv-reduced .lv-ring.is-flash { stroke-opacity: 1; stroke-width: 4; }
</style>
@endpush

@push('scripts')
<script>
// /chatbot/live: ang mapa ay iginuguhit dito (SVG), ang feed ay pino-poll kada 5 segundo (tumitigil kapag nakatago ang tab),
// at ang bawat bagong event ay may sariling galaw. Ang text mula sa server (pangalan ng page) ay textContent lang.
(() => {
    const boot = JSON.parse(document.getElementById('live-data').textContent);
    const root = document.querySelector('[data-live]');
    const mapEl = document.getElementById('live-map');
    const NS = 'http://www.w3.org/2000/svg';
    const mq = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
    const live = window.likhaLive = {reduced: false, paused: false, cursor: boot.cursor, active: 0, busy: 0, timer: null, inflight: false, fail: 0};
    const fmt = n => Number(n || 0).toLocaleString('en-US');
    const LABEL = {message: 'Message in', confirmed: 'Reply confirmed', follow_up: 'Follow-up sent', ai: 'AI answer', order: 'Order saved'};
    const TONE = {message: 'inq', confirmed: 'core', follow_up: 'fu', ai: 'ai', order: 'ord'};
    const color = t => getComputedStyle(root).getPropertyValue('--lv-' + t).trim() || '#fff';

    // Dalawang ayos: malapad (kumpol sa kaliwa at kanan) at makitid na screen (patayo: Inquiries sa itaas, Follow-up at
    // Orders sa ibaba), para mabasa ang mga label sa 390 px.
    const LAYOUTS = {
        wide: {w: 1000, h: 520, font: 18, big: 24, pulse: 7,
            core: [500, 275, 74], ai: [500, 92, 32], inq: [165, 275, 96], fu: [842, 138, 84], ord: [842, 410, 84],
            links: {inq: 'M 262 275 C 330 275 360 275 424 275', fu: 'M 558 230 C 640 170 690 140 757 138', ord: 'M 558 320 C 640 380 690 410 757 410', ai: 'M 500 199 L 500 126'}},
        narrow: {w: 400, h: 660, font: 16, big: 21, pulse: 6,
            core: [200, 335, 64], ai: [342, 335, 30], inq: [200, 100, 84], fu: [100, 562, 78], ord: [300, 562, 78],
            links: {inq: 'M 200 185 C 200 220 200 240 200 269', fu: 'M 165 389 C 140 430 115 460 104 483', ord: 'M 235 389 C 260 430 285 460 296 483', ai: 'M 266 335 L 310 335'}},
    };
    let L, layoutKey, svg, layer, paths = {}, nodes = {}, counts = {};
    const el = (name, attrs = {}, parent) => {
        const e = document.createElementNS(NS, name);
        for (const [k, v] of Object.entries(attrs)) e.setAttribute(k, v);
        if (parent) parent.appendChild(e);
        return e;
    };
    const text = (parent, x, y, cls, size, value) => { const t = el('text', {x, y, class: cls, 'font-size': size}, parent); t.textContent = value; return t; };

    function cluster(key, name, tone, countKey) {
        const [x, y, r] = L[key];
        const g = el('g', {class: 'lv-cluster lv-tone-' + tone, 'data-node': key}, svg);
        el('circle', {cx: x, cy: y, r, class: 'lv-halo'}, g);
        const dots = [];
        for (let i = 0; i < 10; i++) {
            const a = (i / 10) * Math.PI * 2 + .3;
            dots.push(el('circle', {cx: x + Math.cos(a) * r * .8, cy: y + Math.sin(a) * r * .8, r: i % 3 ? 3 : 4.5, class: 'lv-node-dot'}, g));
        }
        text(g, x, y - 4, 'lv-label', L.font, name);
        counts[countKey] = text(g, x, y + L.big, 'lv-count', L.big, '0');
        nodes[key] = {g, dots};
    }

    function build() {
        const key = mapEl.clientWidth < 620 ? 'narrow' : 'wide';
        if (key === layoutKey) return;
        layoutKey = key;
        L = LAYOUTS[key];
        mapEl.textContent = '';
        paths = {}; nodes = {}; counts = {};
        svg = el('svg', {viewBox: `0 0 ${L.w} ${L.h}`, class: 'lv-svg', 'aria-hidden': 'true', focusable: 'false'}, mapEl);
        const defs = el('defs', {}, svg);
        const grad = el('radialGradient', {id: 'lv-core-grad'}, defs);
        el('stop', {offset: '0%', 'stop-color': '#34d399', 'stop-opacity': '.55'}, grad);
        el('stop', {offset: '70%', 'stop-color': '#0f766e', 'stop-opacity': '.35'}, grad);
        el('stop', {offset: '100%', 'stop-color': '#042f2e', 'stop-opacity': '.6'}, grad);
        const glow = el('filter', {id: 'lv-glow', x: '-50%', y: '-50%', width: '200%', height: '200%'}, defs);
        el('feGaussianBlur', {stdDeviation: '3.5', result: 'b'}, glow);
        const merge = el('feMerge', {}, glow);
        el('feMergeNode', {in: 'b'}, merge);
        el('feMergeNode', {in: 'SourceGraphic'}, merge);
        const pat = el('pattern', {id: 'lv-grid', width: 28, height: 28, patternUnits: 'userSpaceOnUse'}, defs);
        el('circle', {cx: 2, cy: 2, r: 1, fill: '#1e293b'}, pat);
        el('rect', {width: L.w, height: L.h, fill: 'url(#lv-grid)'}, svg);

        for (const [k, d] of Object.entries(L.links)) {
            paths[k] = el('path', {d, class: 'lv-link'}, svg);
            el('path', {d, class: 'lv-flow lv-tone-' + ({inq: 'inq', fu: 'fu', ord: 'ord', ai: 'ai'})[k]}, svg);
        }
        cluster('inq', 'Inquiries', 'inq', 'messages');
        cluster('fu', 'Follow-up', 'fu', 'follow_ups');
        cluster('ord', 'Orders', 'ord', 'orders');

        const [ax, ay, ar] = L.ai;
        const ai = el('g', {class: 'lv-ai lv-tone-ai', 'data-node': 'ai'}, svg);
        el('circle', {cx: ax, cy: ay, r: ar, class: 'lv-ai-node'}, ai);
        text(ai, ax, ay + 2, 'lv-label', L.font, 'AI');
        counts.ai = text(ai, ax, ay + ar + L.font + 2, 'lv-count', L.font, '0');
        nodes.ai = {g: ai};

        const [cx, cy, cr] = L.core;
        const core = el('g', {class: 'lv-core', 'data-node': 'core'}, svg);
        const ring = el('circle', {cx, cy, r: cr + 16, class: 'lv-ring'}, core);
        el('circle', {cx, cy, r: cr + 7, class: 'lv-spin'}, core);
        el('circle', {cx, cy, r: cr, class: 'lv-heart'}, core);
        text(core, cx, cy - 6, 'lv-label', L.font + 2, 'Likha Bot');
        const status = text(core, cx, cy + L.font, 'lv-small', L.font - 4, 'listening');
        counts.confirmed = text(core, cx, cy + cr + 16 + L.font + 6, 'lv-small', L.font - 3, '');
        nodes.core = {g: core, ring, status};
        layer = el('g', {class: 'lv-pulses'}, svg);
        if (live.last) setCounts(live.last);
    }

    function setCounts(c) {
        live.last = c;
        for (const [k, t] of Object.entries(counts)) {
            if (k === 'confirmed') t.textContent = fmt(c.confirmed.today) + ' confirmed today';
            else if (c[k]) t.textContent = fmt(c[k].today);
        }
        for (const e of root.querySelectorAll('[data-live-count]')) {
            const k = e.dataset.liveCount, v = fmt(c[k] ? c[k].today : 0);
            if (e.textContent !== v) { e.textContent = v; bump(e); }
        }
        for (const e of root.querySelectorAll('[data-live-recent]')) e.textContent = fmt(c[e.dataset.liveRecent] ? c[e.dataset.liveRecent].recent : 0);
    }
    function bump(e) { e.classList.add('is-bump'); setTimeout(() => e.classList.remove('is-bump'), 900); }
    function flash(e, cls, ms = 700) {
        if (!e) return;
        e.classList.remove(cls);
        void e.getBoundingClientRect();
        e.classList.add(cls);
        clearTimeout(e._lvT);
        e._lvT = setTimeout(() => e.classList.remove(cls), ms);
    }

    // Isang pulse na naglalakbay sa isang linya (wala sa reduced motion, sa nakatagong tab o kapag sobrang dami na).
    function pulse(link, tone, reverse, done) {
        const path = paths[link];
        if (live.reduced || document.hidden || !path || live.active > 60) { if (done) done(); return; }
        const len = path.getTotalLength();
        const c = color(tone);
        const tail = el('circle', {r: L.pulse * .6, fill: c, opacity: .45, class: 'lv-pulse'}, layer);
        const dot = el('circle', {r: L.pulse, fill: c, filter: 'url(#lv-glow)', class: 'lv-pulse'}, layer);
        live.active++;
        const t0 = performance.now(), dur = 1100;
        const at = p => path.getPointAtLength(len * (reverse ? 1 - p : p));
        const step = now => {
            const k = Math.min(1, (now - t0) / dur), e = k < .5 ? 2 * k * k : 1 - Math.pow(-2 * k + 2, 2) / 2;
            const a = at(e), b = at(Math.max(0, e - .07));
            dot.setAttribute('cx', a.x); dot.setAttribute('cy', a.y);
            tail.setAttribute('cx', b.x); tail.setAttribute('cy', b.y);
            if (k < 1) return requestAnimationFrame(step);
            dot.remove(); tail.remove(); live.active--;
            if (done) done();
        };
        requestAnimationFrame(step);
    }
    function busy() {
        const n = nodes.core;
        live.busy++;
        n.g.classList.add('is-busy');
        n.status.textContent = 'processing';
        setTimeout(() => { if (--live.busy <= 0) { live.busy = 0; n.g.classList.remove('is-busy'); n.status.textContent = 'listening'; } }, 1600);
    }
    function spark() {
        if (live.reduced || document.hidden) return;
        const [x, y, r] = L.ai;
        for (let i = 0; i < 7; i++) {
            const a = (i / 7) * Math.PI * 2;
            const s = el('line', {class: 'lv-spark'}, layer);
            const t0 = performance.now();
            const step = now => {
                const k = Math.min(1, (now - t0) / 550), d1 = r + 3 + k * 14, d2 = r + 9 + k * 20;
                s.setAttribute('x1', x + Math.cos(a) * d1); s.setAttribute('y1', y + Math.sin(a) * d1);
                s.setAttribute('x2', x + Math.cos(a) * d2); s.setAttribute('y2', y + Math.sin(a) * d2);
                s.setAttribute('opacity', 1 - k);
                k < 1 ? requestAnimationFrame(step) : s.remove();
            };
            requestAnimationFrame(step);
        }
    }

    // Ang galaw ng bawat uri ng event.
    function show(type) {
        if (!svg) return;
        if (type === 'message') {
            const dots = nodes.inq.dots;
            flash(dots[Math.floor(Math.random() * dots.length)], 'is-blink', 500);
            if (live.reduced) { flash(nodes.inq.g, 'is-hit'); busy(); }
            else pulse('inq', 'inq', false, busy);
        } else if (type === 'ai') {
            flash(nodes.ai.g, 'is-hit');
            spark();
            pulse('ai', 'ai', true, live.reduced ? null : busy);
        } else if (type === 'confirmed') {
            flash(nodes.core.ring, 'is-flash', 900);
        } else if (type === 'follow_up' || type === 'order') {
            const key = type === 'order' ? 'ord' : 'fu';
            if (live.reduced) flash(nodes[key].g, 'is-hit');
            else pulse(key, key, false, () => { flash(nodes[key].g, 'is-hit'); flash(nodes[key].dots[Math.floor(Math.random() * 10)], 'is-blink', 500); });
        }
    }
    // Ang mga event ng isang poll ay ikinakalat sa loob ng ~4 segundo, ayon sa pagkakasunod.
    function play(events) {
        const gap = Math.min(600, 4200 / Math.max(1, events.length));
        events.forEach((e, i) => setTimeout(() => { if (!live.paused) show(e.type); }, i * gap));
    }

    const badgeEl = root.querySelector('[data-live-badge]');
    function badge(msg, ms = 3500) {
        badgeEl.textContent = msg;
        badgeEl.hidden = false;
        clearTimeout(badgeEl._lvT);
        badgeEl._lvT = setTimeout(() => { badgeEl.hidden = true; }, ms);
    }
    const logEl = root.querySelector('[data-live-log]');
    function log(events) {
        if (!events.length) return;
        const empty = logEl.querySelector('[data-live-empty]');
        if (empty) empty.remove();
        for (const e of events.slice(-12)) {
            const li = document.createElement('li');
            li.className = 'lv-tone-' + (TONE[e.type] || 'core');
            const t = document.createElement('time'); t.textContent = String(e.at).slice(11, 19);
            const b = document.createElement('b'); b.textContent = LABEL[e.type] || e.type;
            const s = document.createElement('span'); s.textContent = e.page;
            li.append(t, b, s);
            logEl.prepend(li);
        }
        while (logEl.children.length > 12) logEl.lastElementChild.remove();
    }

    const statusEl = root.querySelector('[data-live-status]');
    function setStatus(state, label) {
        statusEl.dataset.liveStatus = state;
        statusEl.querySelector('[data-live-state]').textContent = label;
    }
    function liveStatus() { setStatus(live.paused ? 'paused' : 'live', live.paused ? 'Paused (still counting)' : 'Live'); }

    async function poll() {
        clearTimeout(live.timer);
        if (document.hidden || live.inflight) return;
        live.inflight = true;
        const q = new URLSearchParams();
        if (boot.page) q.set('page_id', boot.page);
        for (const [k, v] of Object.entries(live.cursor || {})) q.set('cursor[' + k + ']', v);
        try {
            const res = await fetch(boot.feed + '?' + q, {headers: {Accept: 'application/json'}, credentials: 'same-origin', cache: 'no-store'});
            if ([401, 403, 419].includes(res.status)) { setStatus('off', 'Signed out: reload the page'); return; }
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const data = await res.json();
            live.cursor = data.cursor;
            live.fail = 0;
            setCounts(data.counts);
            root.querySelector('[data-live-updated]').textContent = data.now;
            log(data.events);
            if (!live.paused) play(data.events);
            if (data.more > 0) badge('+' + fmt(data.more) + ' more events');
            liveStatus();
        } catch (e) {
            live.fail++;
            setStatus('error', 'Reconnecting…');
        } finally {
            live.inflight = false;
        }
        if (!document.hidden && statusEl.dataset.liveStatus !== 'off') live.timer = setTimeout(poll, live.fail ? Math.min(30000, boot.interval * 2 ** live.fail) : boot.interval);
    }
    document.addEventListener('visibilitychange', () => {
        if (statusEl.dataset.liveStatus === 'off') return;
        if (document.hidden) { clearTimeout(live.timer); setStatus('hidden', 'Paused while the tab is hidden'); } else { liveStatus(); poll(); }
    });

    const pauseBtn = root.querySelector('[data-live-pause]');
    pauseBtn.addEventListener('click', () => {
        live.paused = !live.paused;
        pauseBtn.setAttribute('aria-pressed', live.paused ? 'true' : 'false');
        pauseBtn.textContent = live.paused ? 'Resume' : 'Pause';
        if (statusEl.dataset.liveStatus !== 'off') liveStatus();
    });
    // Preview: isang maikling demo ng bawat uri ng event (hindi binibilang, walang tawag sa server).
    const DEMO = [['message', 0], ['message', 300], ['ai', 900], ['confirmed', 1500], ['message', 1900], ['follow_up', 2400], ['order', 3100],
        ['ai', 3700], ['confirmed', 4200], ['follow_up', 4700], ['message', 5000], ['order', 5500]];
    const previewBtn = root.querySelector('[data-live-preview]');
    previewBtn.addEventListener('click', () => {
        previewBtn.disabled = true;
        badge('Preview: demo events, not counted', 6800);
        for (const [type, at] of DEMO) setTimeout(() => show(type), at);
        setTimeout(() => { previewBtn.disabled = false; }, 6800);
    });
    root.querySelector('[data-live-page]').addEventListener('change', e => e.target.form.submit());

    live.setReduced = on => { live.reduced = !!on; root.classList.toggle('lv-reduced', live.reduced); };
    live.setReduced(mq && mq.matches);
    if (mq && mq.addEventListener) mq.addEventListener('change', e => live.setReduced(e.matches));
    live.show = show;

    build();
    let rt;
    window.addEventListener('resize', () => { clearTimeout(rt); rt = setTimeout(build, 150); });
    setCounts(boot.counts);
    live.timer = setTimeout(poll, boot.interval);
})();
</script>
@endpush

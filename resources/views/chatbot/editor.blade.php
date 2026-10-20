@extends('layouts.chatblast')

@section('title', 'Chatbot · ' . $flow->name)
@section('heading', $flow->name)

@section('description')
    Drag from a port (the dot on the right of a step) to another step to wire them. Click a step to edit it. Changes save to a draft by themselves; customers get them only after you press Publish.
@endsection

@section('actions')
    <a href="{{ route('chatbot.index') }}" class="text-blue-700 hover:underline">← All flows</a>
@endsection

@push('styles')
<style>
    /* Ang canvas lang ang gumagalaw (pan at zoom sa loob ng kahon); ang page ay hindi patagilid. */
    #cv { height: calc(100vh - 17rem); min-height: 22rem; touch-action: none; background-color: #f8fafc;
          background-image: radial-gradient(#cbd5e1 1px, transparent 1px); background-size: 20px 20px; }
    #world { position: absolute; left: 0; top: 0; transform-origin: 0 0; }
    .nd { position: absolute; width: 220px; background: #fff; border: 1px solid #cbd5e1; border-radius: 10px; box-shadow: 0 1px 2px rgb(0 0 0 / .06); font-size: 12px; }
    .nd.sel { border-color: #2563eb; box-shadow: 0 0 0 2px #bfdbfe; }
    .nd-h { cursor: grab; padding: 6px 10px; border-bottom: 1px solid #e2e8f0; border-radius: 10px 10px 0 0; font-weight: 600; display: flex; justify-content: space-between; gap: 6px; }
    .nd-b { padding: 6px 10px; color: #475569; max-height: 5.5em; overflow: hidden; white-space: pre-line; word-break: break-word; }
    .nd-p { display: flex; justify-content: flex-end; align-items: center; gap: 6px; padding: 2px 0 2px 10px; color: #334155; }
    .dot { width: 14px; height: 14px; border-radius: 999px; border: 2px solid #64748b; background: #fff; margin-right: -8px; cursor: crosshair; flex: none; }
    .dot.on { background: #64748b; }
    .in { position: absolute; left: -8px; top: 9px; width: 14px; height: 14px; border-radius: 999px; border: 2px solid #64748b; background: #fff; }
    #wires path { fill: none; stroke: #64748b; stroke-width: 2; pointer-events: stroke; cursor: pointer; }
    #wires path.sel { stroke: #2563eb; stroke-width: 3; }
    .t-start .nd-h { background: #ecfdf5; } .t-condition .nd-h { background: #fef3c7; } .t-send .nd-h { background: #eff6ff; }
    .t-set .nd-h { background: #f5f3ff; } .t-ai .nd-h { background: #fdf2f8; } .t-sheet .nd-h { background: #f0fdf4; } .t-end .nd-h { background: #f1f5f9; }
</style>
@endpush

@section('content')
@include('chatbot.partials.switch', ['repliesOn' => $repliesOn])

<div id="ed" data-graph-url="{{ route('chatbot.flows.graph', $flow->id) }}" data-save-url="{{ route('chatbot.flows.save', $flow->id) }}"
     data-publish-url="{{ route('chatbot.flows.publish', $flow->id) }}" data-discard-url="{{ route('chatbot.flows.discard', $flow->id) }}"
     data-versions-url="{{ route('chatbot.flows.versions', $flow->id) }}" data-restore-url="{{ route('chatbot.flows.restore', [$flow->id, 0]) }}"
     data-test-url="{{ route('chatbot.test', ['flow' => $flow->id, 'version' => 'draft']) }}"
     data-sheet-url="{{ route('chatbot.sheet.info') }}" data-sheet-customers-url="{{ route('chatbot.flows.sheet-customers', $flow->id) }}"
     data-sheet-preview-url="{{ route('chatbot.flows.sheet-preview', $flow->id) }}" data-max-columns="{{ \App\Services\Chatbot\FlowGraph::MAX_COLUMNS }}">
    {{-- Ang draft: ang mga pagbabago ay hindi live hanggang Publish. --}}
    <div class="mb-2 flex flex-wrap items-center gap-2 text-sm">
        <span id="chip" class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700" aria-live="polite">…</span>
        <button type="button" class="cb-btn cb-btn-sm" id="undo" title="Undo (Ctrl+Z)" disabled>Undo</button>
        <button type="button" class="cb-btn cb-btn-sm" id="redo" title="Redo (Ctrl+Shift+Z or Ctrl+Y)" disabled>Redo</button>
        <span class="mx-1 text-slate-300">|</span>
        <button type="button" class="cb-btn cb-btn-sm" id="save-draft">Save draft</button>
        <button type="button" class="cb-btn cb-btn-sm cb-btn-primary" id="publish">Publish</button>
        <button type="button" class="cb-btn cb-btn-sm" id="discard" disabled>Discard draft</button>
        <button type="button" class="cb-btn cb-btn-sm" id="history">History</button>
        <a href="{{ route('chatbot.test', ['flow' => $flow->id, 'version' => 'draft']) }}" class="cb-btn cb-btn-sm" id="preview">Preview / Test</a>
        <span id="status" class="text-xs text-slate-500" aria-live="polite"></span>
    </div>
    <div id="conflict" class="mb-2 hidden flex-wrap items-center gap-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900" role="alert">
        <span>This draft changed since you opened it. Reload, or overwrite?</span>
        <button type="button" class="cb-btn cb-btn-sm" id="c-reload">Reload</button>
        <button type="button" class="cb-btn cb-btn-sm text-red-700" id="c-overwrite">Overwrite</button>
    </div>

    <div class="mb-2 flex flex-wrap items-center gap-2 text-sm">
        <span class="text-xs text-slate-500">Add:</span>
        <button type="button" class="cb-btn cb-btn-sm" data-add="condition">Condition</button>
        <button type="button" class="cb-btn cb-btn-sm" data-add="send">Send message</button>
        <button type="button" class="cb-btn cb-btn-sm" data-add="set">Set field</button>
        <button type="button" class="cb-btn cb-btn-sm" data-add="ai">AI</button>
        <button type="button" class="cb-btn cb-btn-sm" data-add="sheet">Google Sheet</button>
        <button type="button" class="cb-btn cb-btn-sm" data-add="sequence">Sequence</button>
        <button type="button" class="cb-btn cb-btn-sm" data-add="end">End</button>
        <span class="mx-1 text-slate-300">|</span>
        <button type="button" class="cb-btn cb-btn-sm" id="arrange">Arrange</button>
        <button type="button" class="cb-btn cb-btn-sm" id="zoom-out" aria-label="Zoom out">−</button>
        <button type="button" class="cb-btn cb-btn-sm" id="zoom-in" aria-label="Zoom in">+</button>
        <button type="button" class="cb-btn cb-btn-sm" id="fit">Fit</button>
        <button type="button" class="cb-btn cb-btn-sm hidden" id="del-wire">Remove wire</button>
    </div>

    <div id="cv" class="cb-card relative overflow-hidden">
        <div id="world">
            <svg id="wires" style="position:absolute; left:0; top:0; overflow:visible" width="1" height="1"></svg>
        </div>
    </div>

    {{-- Ang form ng isang step: modal sa gitna. --}}
    <div id="form-box" class="fixed inset-0 z-50 hidden overflow-y-auto bg-slate-900/30 p-4 sm:p-8" role="dialog" aria-modal="true" aria-labelledby="form-title">
        <div class="mx-auto w-full max-w-2xl rounded-xl bg-white p-5 text-sm shadow-lg">
            <div class="mb-3 flex items-center justify-between gap-3">
                <div id="form-title" class="font-semibold"></div>
                <button type="button" data-form-close class="px-2 text-slate-500 hover:text-slate-800" aria-label="Close">✕</button>
            </div>
            <div id="form-body" class="space-y-3"></div>
            <div class="mt-4">
                <div class="mb-1 text-xs text-slate-500">Variables (click to put one in the last field you were in):</div>
                <div id="vars" class="flex flex-wrap gap-1"></div>
            </div>
            <div class="mt-4 flex flex-wrap gap-2">
                <button type="button" id="form-apply" class="cb-btn cb-btn-primary">Done</button>
                <button type="button" data-form-close class="cb-btn">Cancel</button>
                <button type="button" id="form-duplicate" class="cb-btn ml-auto">Duplicate step</button>
                <button type="button" id="form-delete" class="cb-btn text-red-700">Delete step</button>
            </div>
        </div>
    </div>

    {{-- Publish: kumpirma, ilang page ang gumagamit, at isang note para sa History. --}}
    <div id="pub-box" class="fixed inset-0 z-50 hidden overflow-y-auto bg-slate-900/30 p-4 sm:p-8" role="dialog" aria-modal="true" aria-labelledby="pub-title">
        <div class="mx-auto w-full max-w-md rounded-xl bg-white p-5 text-sm shadow-lg">
            <div id="pub-title" class="mb-2 font-semibold">Publish this flow?</div>
            <p id="pub-pages" class="mb-3 text-slate-700"></p>
            <label class="block text-xs text-slate-600"><span class="mb-0.5 block">Note (optional, shown in History)</span>
                <input id="pub-note" maxlength="200" autocomplete="off" class="w-full rounded-md border border-slate-300 px-2 py-1 text-sm" placeholder="e.g. New price reply">
            </label>
            <p id="pub-error" class="mt-2 hidden rounded-md bg-red-50 px-2 py-1 text-red-700" role="alert"></p>
            <div class="mt-4 flex flex-wrap gap-2">
                <button type="button" id="pub-go" class="cb-btn cb-btn-primary">Publish now</button>
                <button type="button" data-pub-close class="cb-btn">Cancel</button>
            </div>
        </div>
    </div>

    {{-- History: ang huling 20 Publish; ang Restore ay sa draft lang. --}}
    <div id="hist-box" class="fixed inset-0 z-50 hidden bg-slate-900/30" role="dialog" aria-modal="true" aria-labelledby="hist-title">
        <aside class="ml-auto flex h-full w-full max-w-sm flex-col bg-white p-4 text-sm shadow-lg">
            <div class="mb-2 flex items-center justify-between gap-3">
                <div id="hist-title" class="font-semibold">History</div>
                <button type="button" data-hist-close class="px-2 text-slate-500 hover:text-slate-800" aria-label="Close">✕</button>
            </div>
            <p class="text-xs text-slate-500">The last 20 publishes. Restore puts a version in the draft; it goes live only when you publish.</p>
            <ol id="hist-list" class="mt-3 min-h-0 flex-1 space-y-2 overflow-y-auto"></ol>
        </aside>
    </div>
</div>

<script>
(function () {
    const ed = document.getElementById('ed'), $ = id => document.getElementById(id);
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const cv = $('cv'), world = $('world'), svg = $('wires');
    const W = 220, NS = 'http://www.w3.org/2000/svg';
    const OPEN = String.fromCharCode(123, 123), CLOSE = String.fromCharCode(125, 125); // hindi isinusulat nang buo: may sariling echo ang Blade
    const LABEL = {start: 'Start', condition: 'Condition', send: 'Send message', set: 'Set field', ai: 'AI', sheet: 'Google Sheet', sequence: 'Sequence', end: 'End'};
    const PORT_LABEL = {next: 'next', continue: 'continue', reply: 'reply', skipped: 'skipped (no reply in time)', no: 'no match', success: 'success', failed: 'failed'};
    let graph = {nodes: [], edges: []}, variables = [], rules = {}, sequences = [];
    let view = {x: 40, y: 40, z: 1}, selWire = null, saveTimer = null, lastField = null;
    // Ang draft: version (para sa check ng dalawang nag-e-edit), kalagayan, at kung may lokal na pagbabagong hindi pa naise-save.
    let version = 0, state = 'published', pages = [], dirty = false, conflict = false, leaving = false, pending = Promise.resolve();
    // Undo/Redo: mga snapshot (JSON) ng graph, sa browser lang.
    const HISTORY = 100;
    let undoStack = [], redoStack = [], current = null;

    function el(tag, attrs = {}, children = []) {
        const e = document.createElement(tag);
        for (const [k, v] of Object.entries(attrs)) {
            if (v == null || v === false) continue;
            if (k === 'class') e.className = v; else if (k === 'text') e.textContent = v; else e.setAttribute(k, v === true ? '' : v);
        }
        for (const c of [].concat(children)) if (c != null) e.append(c);
        return e;
    }
    const status = (t, cls = 'text-slate-500') => { $('status').className = 'text-xs ' + cls; $('status').textContent = t; };

    // ---- ports (kapareho ng FlowGraph::ports) ----
    function ports(n) {
        const c = n.config || {};
        switch (n.type) {
            case 'start': case 'set': case 'sequence': return ['next'];
            case 'condition': return [...(c.rows || []).map((_, i) => 'row_' + (i + 1)), 'no'];
            case 'send': return c.wait ? (skipMinutes(c) ? ['reply', 'skipped'] : ['reply']) : ['continue'];
            case 'ai': return ['success', 'failed'];
            case 'sheet': return ['next', 'failed'];
            default: return [];
        }
    }
    // Kapareho ng FlowGraph::skipMinutes at skipLabel.
    const skipMinutes = c => c.skip_after && parseInt(c.skip_after.amount, 10) >= 1 ? parseInt(c.skip_after.amount, 10) * (c.skip_after.unit === 'hours' ? 60 : 1) : 0;
    const skipLabel = m => [Math.floor(m / 60) ? Math.floor(m / 60) + ' h' : '', m % 60 ? m % 60 + ' min' : ''].filter(Boolean).join(' ');
    function portLabel(n, p) {
        if (p.startsWith('row_')) {
            const r = (n.config.rows || [])[parseInt(p.slice(4), 10) - 1] || {};
            return (r.variable || '?') + ' ' + (rules[r.rule] || r.rule || '') + (r.value ? ' ' + r.value.split(/\r?\n/)[0].slice(0, 18) : '');
        }
        return PORT_LABEL[p] || p;
    }
    function summary(n) {
        const c = n.config || {};
        switch (n.type) {
            case 'send': return (c.items || []).map(i => (i.type === 'image' ? '🖼 ' : '') + (i.value || '')).join('\n') + (c.wait ? '\n⏳ waits for reply' + (c.save_to ? ' → ' + c.save_to : '') + (skipMinutes(c) ? ', skips after ' + skipLabel(skipMinutes(c)) : '') : '');
            case 'set': return (c.ops || []).map(o => o.op === 'clear_all' ? 'clear all fields' : o.op + ' ' + (o.field || '?') + (o.op === 'set' || o.op === 'append' ? ' = ' + (o.value || '') : '')).join('\n');
            case 'ai': return (c.model || 'gpt-4o-mini') + (c.save_to ? ' → ' + c.save_to : '') + (c.send ? ', sends the answer' : '');
            case 'sheet': return (c.tab || 'Sheet1') + ': ' + (c.columns || []).length + ' column(s)';
            case 'sequence': {
                const s = sequences.find(x => x.id === c.sequence_id);
                const name = c.sequence_id ? (s ? s.name : 'a deleted sequence') : (c.action === 'unsubscribe' ? 'all sequences' : 'Pick a sequence');
                return (c.action === 'unsubscribe' ? 'Unsubscribe from ' : 'Subscribe to ') + name;
            }
            case 'condition': return (c.rows || []).length ? '' : 'Add rows to check';
            default: return '';
        }
    }

    // ---- render ----
    function applyView() { world.style.transform = `translate(${view.x}px, ${view.y}px) scale(${view.z})`; }
    function nodeEl(n) { return world.querySelector(`.nd[data-id="${n.id}"]`); }
    function render() {
        world.querySelectorAll('.nd').forEach(e => e.remove());
        for (const n of graph.nodes) {
            const card = el('div', {class: 'nd t-' + n.type, 'data-id': n.id, style: `left:${n.x}px; top:${n.y}px`, tabindex: '0', role: 'button', 'aria-label': LABEL[n.type] + ' step'}, [
                n.type === 'start' ? null : el('span', {class: 'in'}),
                el('div', {class: 'nd-h'}, [el('span', {text: LABEL[n.type]}), el('span', {class: 'font-mono text-[10px] text-slate-400', text: n.id})]),
                summary(n) ? el('div', {class: 'nd-b', text: summary(n)}) : null,
                ...ports(n).map(p => el('div', {class: 'nd-p'}, [el('span', {class: 'truncate', text: portLabel(n, p)}),
                    el('span', {class: 'dot' + (graph.edges.some(e => e.from === n.id && e.port === p) ? ' on' : ''), 'data-port': p, title: 'Drag to the next step'})])),
            ]);
            card.addEventListener('keydown', ev => { if (ev.key === 'Enter') openForm(n); });
            world.append(card);
        }
        drawWires();
    }
    function portPos(n, p) {
        const card = nodeEl(n), dot = card?.querySelector(`.dot[data-port="${p}"]`);
        if (!dot) return {x: n.x + W, y: n.y + 20};
        return {x: n.x + W, y: n.y + dot.offsetTop + dot.offsetHeight / 2 + dot.parentElement.offsetTop - dot.parentElement.offsetTop};
    }
    function dotPos(n, p) {
        const card = nodeEl(n), dot = card?.querySelector(`.dot[data-port="${p}"]`);
        return dot ? {x: n.x + dot.offsetLeft + 7, y: n.y + dot.offsetTop + 7} : {x: n.x + W, y: n.y + 20};
    }
    const curve = (a, b) => { const d = Math.max(40, Math.abs(b.x - a.x) / 2); return `M${a.x},${a.y} C${a.x + d},${a.y} ${b.x - d},${b.y} ${b.x},${b.y}`; };
    function drawWires(temp) {
        svg.replaceChildren();
        graph.edges.forEach((e, i) => {
            const a = graph.nodes.find(n => n.id === e.from), b = graph.nodes.find(n => n.id === e.to);
            if (!a || !b) return;
            const p = document.createElementNS(NS, 'path');
            p.setAttribute('d', curve(dotPos(a, e.port), {x: b.x, y: b.y + 16}));
            if (selWire === i) p.setAttribute('class', 'sel');
            p.addEventListener('pointerdown', ev => { ev.stopPropagation(); selWire = i; $('del-wire').classList.remove('hidden'); drawWires(); });
            svg.append(p);
        });
        if (temp) { const p = document.createElementNS(NS, 'path'); p.setAttribute('d', curve(temp.a, temp.b)); p.setAttribute('stroke-dasharray', '5 4'); p.style.pointerEvents = 'none'; svg.append(p); /* hindi sasaluhin ang pagbitaw */ }
    }

    // ---- pointer: pan, drag a step, wire from a port ----
    const toWorld = (cx, cy) => { const r = cv.getBoundingClientRect(); return {x: (cx - r.left - view.x) / view.z, y: (cy - r.top - view.y) / view.z}; };
    let drag = null;
    cv.addEventListener('pointerdown', ev => {
        const dot = ev.target.closest('.dot'), head = ev.target.closest('.nd-h'), card = ev.target.closest('.nd');
        cv.setPointerCapture(ev.pointerId);
        if (dot) {
            const n = graph.nodes.find(x => x.id === card.dataset.id);
            drag = {kind: 'wire', n, port: dot.dataset.port};
        } else if (head) {
            const n = graph.nodes.find(x => x.id === card.dataset.id), w = toWorld(ev.clientX, ev.clientY);
            drag = {kind: 'move', n, dx: w.x - n.x, dy: w.y - n.y, moved: false};
        } else if (card) {
            drag = {kind: 'click', n: graph.nodes.find(x => x.id === card.dataset.id)};
        } else {
            drag = {kind: 'pan', sx: ev.clientX - view.x, sy: ev.clientY - view.y};
            if (selWire !== null) { selWire = null; $('del-wire').classList.add('hidden'); drawWires(); }
        }
    });
    cv.addEventListener('pointermove', ev => {
        if (!drag) return;
        const w = toWorld(ev.clientX, ev.clientY);
        if (drag.kind === 'pan') { view.x = ev.clientX - drag.sx; view.y = ev.clientY - drag.sy; applyView(); }
        else if (drag.kind === 'move') { drag.n.x = Math.round(w.x - drag.dx); drag.n.y = Math.round(w.y - drag.dy); drag.moved = true; const c = nodeEl(drag.n); c.style.left = drag.n.x + 'px'; c.style.top = drag.n.y + 'px'; drawWires(); }
        else if (drag.kind === 'wire') { drawWires({a: dotPos(drag.n, drag.port), b: w}); }
    });
    cv.addEventListener('pointerup', ev => {
        const d = drag; drag = null;
        if (!d) return;
        if (d.kind === 'move') { if (d.moved) changed(); else openForm(d.n); }
        else if (d.kind === 'click') openForm(d.n);
        else if (d.kind === 'wire') {
            const hit = document.elementFromPoint(ev.clientX, ev.clientY)?.closest('.nd');
            const to = hit && graph.nodes.find(x => x.id === hit.dataset.id);
            if (to && to.id !== d.n.id && to.type !== 'start') {
                graph.edges = graph.edges.filter(e => !(e.from === d.n.id && e.port === d.port));
                graph.edges.push({from: d.n.id, port: d.port, to: to.id});
                render(); changed();
            } else drawWires();
        }
    });
    cv.addEventListener('wheel', ev => {
        ev.preventDefault();
        const r = cv.getBoundingClientRect(), mx = ev.clientX - r.left, my = ev.clientY - r.top;
        const z = Math.min(2, Math.max(0.3, view.z * (ev.deltaY < 0 ? 1.1 : 0.9)));
        view.x = mx - (mx - view.x) * z / view.z; view.y = my - (my - view.y) * z / view.z; view.z = z; applyView();
    }, {passive: false});
    const zoomBy = f => { const r = cv.getBoundingClientRect(), mx = r.width / 2, my = r.height / 2, z = Math.min(2, Math.max(0.3, view.z * f));
        view.x = mx - (mx - view.x) * z / view.z; view.y = my - (my - view.y) * z / view.z; view.z = z; applyView(); };
    $('zoom-in').addEventListener('click', () => zoomBy(1.2));
    $('zoom-out').addEventListener('click', () => zoomBy(1 / 1.2));
    function fit() {
        if (!graph.nodes.length) return;
        const xs = graph.nodes.map(n => n.x), ys = graph.nodes.map(n => n.y), r = cv.getBoundingClientRect();
        const minX = Math.min(...xs), minY = Math.min(...ys), w = Math.max(...xs) + W - minX + 60, h = Math.max(...ys) + 200 - minY + 60;
        view.z = Math.min(1.2, Math.max(0.3, Math.min(r.width / w, r.height / h)));
        view.x = 30 - minX * view.z; view.y = 30 - minY * view.z; applyView();
    }
    $('fit').addEventListener('click', fit);
    $('del-wire').addEventListener('click', () => { if (selWire === null) return; graph.edges.splice(selWire, 1); selWire = null; $('del-wire').classList.add('hidden'); render(); changed(); });
    document.addEventListener('keydown', ev => {
        if ((ev.key === 'Delete' || ev.key === 'Backspace') && selWire !== null && !$('form-box').contains(document.activeElement) && document.activeElement?.tagName !== 'INPUT') {
            graph.edges.splice(selWire, 1); selWire = null; $('del-wire').classList.add('hidden'); render(); changed();
        }
        if (ev.key === 'Escape' && !$('form-box').classList.contains('hidden')) closeForm();
        if (ev.key === 'Escape') { closePublish(); closeHistory(); }
        // Ctrl+Z, Ctrl+Shift+Z, Ctrl+Y; hindi sa loob ng field o ng bukas na kahon (doon ang sariling undo ng browser).
        const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName);
        const boxOpen = ['form-box', 'pub-box', 'hist-box'].some(id => !$(id).classList.contains('hidden'));
        if ((ev.ctrlKey || ev.metaKey) && !ev.altKey && !typing && !boxOpen) {
            const k = ev.key.toLowerCase();
            if (k === 'z' && !ev.shiftKey) { ev.preventDefault(); undo(); }
            else if ((k === 'z' && ev.shiftKey) || k === 'y') { ev.preventDefault(); redo(); }
        }
    });

    // ---- auto-arrange: mga hanay ayon sa layo mula sa Start ----
    $('arrange').addEventListener('click', () => {
        const start = graph.nodes.find(n => n.type === 'start'), depth = {}, order = [];
        if (!start) return;
        depth[start.id] = 0; order.push(start.id);
        for (let i = 0; i < order.length; i++) {
            const id = order[i];
            graph.edges.filter(e => e.from === id).forEach(e => { if (!(e.to in depth)) { depth[e.to] = depth[id] + 1; order.push(e.to); } });
        }
        const maxD = Math.max(0, ...Object.values(depth));
        graph.nodes.forEach(n => { if (!(n.id in depth)) depth[n.id] = maxD + 1; });
        const rowsAt = {};
        [...order, ...graph.nodes.map(n => n.id).filter(id => !order.includes(id))].forEach(id => {
            const n = graph.nodes.find(x => x.id === id), d = depth[id];
            rowsAt[d] = (rowsAt[d] || 0) + 1;
            n.x = 40 + d * 300; n.y = 40 + (rowsAt[d] - 1) * 220;
        });
        render(); fit(); changed();
    });

    // ---- add a step ----
    const DEFAULTS = {
        condition: {rows: [{variable: 'last_input', rule: 'contains', value: ''}]}, send: {items: [{type: 'text', value: ''}], wait: false, save_to: ''},
        set: {ops: [{op: 'set', field: '', value: '', separator: ', '}]}, ai: {model: 'gpt-4o-mini', system: '', user: OPEN + 'last_input' + CLOSE, history: 100, max_tokens: 250, save_to: '', send: false},
        sheet: {sheet: '', tab: '', columns: []}, sequence: {action: 'subscribe', sequence_id: 0}, end: {},
    };
    document.querySelectorAll('[data-add]').forEach(b => b.addEventListener('click', () => {
        const next = 1 + Math.max(0, ...graph.nodes.map(n => parseInt(n.id.slice(1), 10) || 0));
        const r = cv.getBoundingClientRect(), c = toWorld(r.left + r.width / 2, r.top + r.height / 3);
        const n = {id: 'n' + next, type: b.dataset.add, x: Math.round(c.x - W / 2), y: Math.round(c.y), config: JSON.parse(JSON.stringify(DEFAULTS[b.dataset.add]))};
        graph.nodes.push(n); render(); changed(); openForm(n);
    }));

    // ---- the form of a step ----
    let editing = null;
    const input = (attrs, value) => { const i = el(attrs.tag || 'input', Object.assign({class: 'w-full rounded-md border border-slate-300 px-2 py-1 text-sm'}, attrs, {tag: null})); i.value = value ?? ''; i.addEventListener('focus', () => lastField = i); return i; };
    const label = (text, control) => el('label', {class: 'block text-xs text-slate-600'}, [el('span', {class: 'mb-0.5 block', text}), control]);
    const listId = 'var-list';
    function rowsEditor(items, make, max, addText) {
        const box = el('div', {class: 'space-y-2'});
        const draw = () => {
            box.replaceChildren(...items.map((it, i) => el('div', {class: 'rounded-md border border-slate-200 p-2'}, [make(it, i),
                el('button', {type: 'button', class: 'mt-1 text-xs text-red-700 hover:underline', text: 'Remove'})])));
            box.querySelectorAll('button').forEach((b, i) => b.addEventListener('click', () => { items.splice(i, 1); draw(); }));
            if (items.length < max) {
                const add = el('button', {type: 'button', class: 'cb-btn cb-btn-sm', text: addText});
                add.addEventListener('click', () => { items.push(make.blank()); draw(); });
                box.append(add);
            }
        };
        draw();
        return box;
    }
    function openForm(n) {
        editing = {n, c: JSON.parse(JSON.stringify(n.config || {}))};
        const c = editing.c, body = $('form-body');
        $('form-title').textContent = LABEL[n.type] + ' · ' + n.id;
        $('form-delete').classList.toggle('hidden', n.type === 'start');
        $('form-duplicate').classList.toggle('hidden', n.type === 'start');
        body.replaceChildren();
        body.append(el('datalist', {id: listId}, variables.map(v => el('option', {value: v}))));
        if (n.type === 'start') body.append(el('p', {class: 'text-slate-600', text: 'Every chat that is not waiting for a reply starts here.'}));
        if (n.type === 'end') body.append(el('p', {class: 'text-slate-600', text: 'The run stops here.'}));
        if (n.type === 'condition') {
            const make = r => el('div', {class: 'grid gap-2 sm:grid-cols-3'}, [
                label('Variable', bind(input({list: listId, maxlength: 100}, r.variable), v => r.variable = v)),
                label('Rule', bind(el('select', {class: 'w-full rounded-md border border-slate-300 px-2 py-1 text-sm'}, Object.entries(rules).map(([k, t]) => el('option', {value: k, text: t, selected: r.rule === k}))), v => r.rule = v)),
                label('Value (contains any of: one per line or comma)', bind(input({tag: 'textarea', rows: 2, maxlength: 2000}, r.value), v => r.value = v)),
            ]);
            make.blank = () => ({variable: 'last_input', rule: 'contains', value: ''});
            body.append(el('p', {class: 'text-xs text-slate-500', text: 'The first row that matches wins; none matches → "no match".'}), rowsEditor(c.rows = c.rows || [], make, 20, '+ Row'));
        }
        if (n.type === 'send') {
            const make = it => el('div', {class: 'grid gap-2 sm:grid-cols-[8rem_1fr]'}, [
                label('Type', bind(el('select', {class: 'w-full rounded-md border border-slate-300 px-2 py-1 text-sm'}, [el('option', {value: 'text', text: 'Text', selected: it.type !== 'image'}), el('option', {value: 'image', text: 'Image link', selected: it.type === 'image'})]), v => it.type = v)),
                label('Text or image link (variables allowed)', bind(input({tag: 'textarea', rows: 3, maxlength: 2000}, it.value), v => it.value = v)),
            ]);
            make.blank = () => ({type: 'text', value: ''});
            body.append(rowsEditor(c.items = c.items || [], make, 10, '+ Message'));
            body.append(el('label', {class: 'flex items-center gap-2 text-sm'}, [bind(el('input', {type: 'checkbox', checked: !!c.wait}), v => c.wait = v, true), 'Wait for the reply (exits on "reply")']));
            // Skip: walang sagot sa takdang oras → "skipped". Hanggang 23 oras (ang padala ay nasa 24 oras ng Messenger pa).
            const skip = c.skip_after || {amount: '', unit: 'hours'};
            const amount = input({type: 'number', min: 1, max: skip.unit === 'hours' ? 23 : 1380, placeholder: 'never', 'aria-label': 'Skip after', class: 'w-24 rounded-md border border-slate-300 px-2 py-1 text-sm'}, skip.amount);
            const unit = el('select', {class: 'rounded-md border border-slate-300 px-2 py-1 text-sm', 'aria-label': 'Unit'}, [el('option', {value: 'minutes', text: 'minutes', selected: skip.unit === 'minutes'}), el('option', {value: 'hours', text: 'hours', selected: skip.unit !== 'minutes'})]);
            const setSkip = () => { amount.max = unit.value === 'hours' ? 23 : 1380; c.skip_after = parseInt(amount.value, 10) >= 1 ? {amount: parseInt(amount.value, 10), unit: unit.value} : null; };
            amount.addEventListener('input', setSkip); unit.addEventListener('change', setSkip);
            body.append(el('div', {class: 'text-sm'}, [el('div', {class: 'flex flex-wrap items-center gap-2'}, [el('span', {text: 'Skip if no reply after'}), amount, unit]),
                el('p', {class: 'mt-0.5 text-xs text-slate-500', text: 'Only with "Wait for the reply". Empty = wait forever. Up to 23 hours; then it exits on "skipped".'})]));
            body.append(label('Save the reply into the customer field', bind(input({list: listId, maxlength: 100, placeholder: 'e.g. FORM REPLY'}, c.save_to), v => c.save_to = v)));
        }
        if (n.type === 'set') {
            const make = o => el('div', {class: 'grid gap-2 sm:grid-cols-[8rem_1fr_1fr_5rem]'}, [
                label('Do', bind(el('select', {class: 'w-full rounded-md border border-slate-300 px-2 py-1 text-sm'}, [['set', 'Set'], ['append', 'Append'], ['clear', 'Clear'], ['clear_all', 'Clear all fields']].map(([k, t]) => el('option', {value: k, text: t, selected: o.op === k}))), v => o.op = v)),
                label('Customer field', bind(input({list: listId, maxlength: 100}, o.field), v => o.field = v)),
                label('Value', bind(input({maxlength: 2000}, o.value), v => o.value = v)),
                label('Separator', bind(input({maxlength: 10}, o.separator ?? ', '), v => o.separator = v)),
            ]);
            make.blank = () => ({op: 'set', field: '', value: '', separator: ', '});
            body.append(rowsEditor(c.ops = c.ops || [], make, 20, '+ Operation'));
        }
        if (n.type === 'ai') {
            body.append(el('p', {class: 'rounded-md bg-amber-50 px-2 py-1 text-xs text-amber-900', text: 'Calls OpenAI with the server\'s key (also in Test).'}));
            body.append(label('Model', bind(input({maxlength: 60}, c.model || 'gpt-4o-mini'), v => c.model = v)));
            body.append(label('System prompt (e.g. ' + OPEN + 'Prompt 1' + CLOSE + ')', bind(input({tag: 'textarea', rows: 4, maxlength: 10000}, c.system), v => c.system = v)));
            body.append(label('User message', bind(input({tag: 'textarea', rows: 2, maxlength: 10000}, c.user || OPEN + 'last_input' + CLOSE), v => c.user = v)));
            body.append(el('div', {class: 'grid gap-2 sm:grid-cols-3'}, [
                label('Remember the last N messages of this customer on this page (0 = off, max 100)', bind(input({type: 'number', min: 0, max: 100}, c.history ?? 0), v => c.history = parseInt(v, 10) || 0)),
                label('Max tokens', bind(input({type: 'number', min: 1, max: 4000}, c.max_tokens ?? 250), v => c.max_tokens = parseInt(v, 10) || 250)),
                label('Save the answer into', bind(input({list: listId, maxlength: 100, placeholder: 'e.g. GPT INPUT'}, c.save_to), v => c.save_to = v)),
            ]));
            body.append(el('label', {class: 'flex items-center gap-2 text-sm'}, [bind(el('input', {type: 'checkbox', checked: !!c.send}), v => c.send = v, true), 'Send the answer to the customer']));
        }
        if (n.type === 'sheet') sheetForm(c, body);
        if (n.type === 'sequence') {
            const pick = el('select', {class: 'w-full rounded-md border border-slate-300 px-2 py-1 text-sm'});
            const fill = () => {
                const opts = (c.action === 'unsubscribe' ? [[0, 'All sequences']] : [[0, '— Pick a sequence —']]).concat(sequences.map(s => [s.id, s.name]));
                pick.replaceChildren(...opts.map(([id, t]) => el('option', {value: id, text: t, selected: id === c.sequence_id})));
            };
            fill();
            body.append(label('Do', bind(el('select', {class: 'w-full rounded-md border border-slate-300 px-2 py-1 text-sm'},
                [['subscribe', 'Subscribe to a sequence'], ['unsubscribe', 'Unsubscribe']].map(([k, t]) => el('option', {value: k, text: t, selected: c.action === k}))), v => { c.action = v; fill(); })));
            body.append(label('Sequence', bind(pick, v => c.sequence_id = parseInt(v, 10) || 0)));
            body.append(el('p', {class: 'text-xs text-slate-500', text: 'Subscribing while the customer is already in it does nothing; after an unsubscribe it starts again from step 1. Sequences are made in Chatbot → Sequences.'}));
        }
        $('vars').replaceChildren(...variables.map(v => { const b = el('button', {type: 'button', class: 'rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] hover:bg-slate-200', text: OPEN + v + CLOSE});
            b.addEventListener('click', () => { if (!lastField) return; const s = lastField.selectionStart ?? lastField.value.length; lastField.value = lastField.value.slice(0, s) + OPEN + v + CLOSE + lastField.value.slice(s); lastField.dispatchEvent(new Event('input')); lastField.focus(); }); return b; }));
        $('form-box').classList.remove('hidden');
        body.querySelector('input, textarea, select')?.focus();
    }
    // ---- the Google Sheet step: ang pangalan, ang mga tab, ang row 1 bilang header, at ang preview ng row ----
    // Ang naka-save ay ang dati pa ring listahan ng columns (A, B, C …; blangko = walang laman ang cell).
    const MAX_COLUMNS = parseInt(ed.dataset.maxColumns, 10) || 50;
    const colLetter = i => { let s = ''; for (let k = i + 1; k > 0; k = Math.floor((k - 1) / 26)) s = String.fromCharCode(65 + (k - 1) % 26) + s; return s; };
    let customers = null; // isang basa kada bukas ng editor
    function sheetForm(c, body) {
        c.columns = Array.isArray(c.columns) ? c.columns : [];
        let info = null, headers = [], extra = 0, cells = null, timer = null, seq = 0;
        const box = el('div', {class: 'space-y-1'}), note = el('div', {class: 'text-xs', 'aria-live': 'polite'});
        const link = bind(input({maxlength: 300, placeholder: 'https://docs.google.com/spreadsheets/d/…'}, c.sheet), v => { c.sheet = v; clearTimeout(timer); timer = setTimeout(() => load(false), 600); });
        const refresh = el('button', {type: 'button', class: 'cb-btn cb-btn-sm', text: 'Refresh', title: 'Read the sheet again (skips the 5-minute cache)'});
        refresh.addEventListener('click', () => load(true));
        const tabBox = el('div'), table = el('div'), preview = el('div', {class: 'flex flex-wrap items-center gap-2'});
        box.append(label('Spreadsheet link or ID', el('div', {class: 'flex gap-2'}, [link, refresh])), note);
        body.append(box, tabBox, table, preview);

        async function load(fresh) {
            clearTimeout(timer);
            const mine = ++seq;
            if (!(c.sheet || '').trim()) { info = null; headers = []; note.replaceChildren(); drawTab(); drawTable(); return; }
            note.className = 'text-xs text-slate-500'; note.textContent = 'Reading the sheet…';
            const q = new URLSearchParams({sheet: c.sheet, tab: c.tab || ''});
            if (fresh) q.set('refresh', '1');
            const r = await fetch(ed.dataset.sheetUrl + '?' + q, {headers: {'Accept': 'application/json'}}).then(async r => ({ok: r.ok, data: await r.json().catch(() => ({}))})).catch(() => ({ok: false, data: {}}));
            if (mine !== seq || !editing || editing.c !== c) return; // may mas bagong basa, o sarado na ang form
            if (r.ok) {
                info = r.data; headers = r.data.tab ? r.data.tab.headers : [];
                note.className = 'text-xs text-slate-700';
                note.replaceChildren(el('span', {class: 'text-slate-500', text: 'Spreadsheet: '}), el('b', {class: 'break-words', text: info.title || '(no name)'}));
            } else {
                info = null; headers = [];
                note.className = 'text-xs';
                note.replaceChildren(r.data.share ? shareBox(r.data.email) : el('span', {class: 'text-red-700', text: r.data.error || 'Could not read the sheet.'}));
            }
            drawTab(); drawTable();
        }
        function shareBox(email) {
            const copy = el('button', {type: 'button', class: 'cb-btn cb-btn-sm', text: 'Copy email'});
            const again = el('button', {type: 'button', class: 'cb-btn cb-btn-sm', text: 'Check again'});
            const mail = el('b', {class: 'break-all font-mono text-[11px]', text: email});
            copy.addEventListener('click', () => {
                const done = () => { copy.textContent = 'Copied'; setTimeout(() => copy.textContent = 'Copy email', 1500); };
                const fallback = () => { getSelection().selectAllChildren(mail); document.execCommand('copy'); done(); }; // http: walang navigator.clipboard
                if (navigator.clipboard) navigator.clipboard.writeText(email).then(done, fallback); else fallback();
            });
            again.addEventListener('click', () => load(true));
            return el('div', {class: 'space-y-1 rounded-md border border-amber-300 bg-amber-50 p-2 text-amber-900', role: 'alert'}, [
                el('div', {}, ['Google won\'t open this sheet. Share this sheet with ', mail, ' as Editor (Share → paste the email → Editor), then press Check again.']),
                el('div', {class: 'flex flex-wrap items-center gap-2'}, [copy, again]),
            ]);
        }
        function drawTab() {
            if (!info) {
                tabBox.replaceChildren(label('Tab', bind(input({maxlength: 100, placeholder: 'Sheet1'}, c.tab), v => c.tab = v)));
                return;
            }
            const names = info.tabs.map(t => t.title), missing = !!c.tab && !names.includes(c.tab);
            const opts = (c.tab ? [] : [['', '— Pick a tab —']]).concat(missing ? [[c.tab, c.tab + ' (missing)']] : [], names.map(t => [t, t]));
            const sel = bind(el('select', {class: 'w-full rounded-md border px-2 py-1 text-sm ' + (missing ? 'border-red-400 text-red-700' : 'border-slate-300')},
                opts.map(([v, t]) => el('option', {value: v, text: t, selected: v === (c.tab || '')}))), v => { c.tab = v; cells = null; load(false); });
            tabBox.replaceChildren(...[label('Tab', sel), missing ? el('p', {class: 'mt-0.5 text-xs text-red-700', text: 'The tab "' + c.tab + '" is missing from this sheet. Pick another tab.'}) : null].filter(Boolean));
        }
        function drawTable() {
            const rows = Math.min(MAX_COLUMNS, Math.max(headers.length, c.columns.length, 1) + extra);
            const th = (t, cls = '') => el('th', {class: 'px-1 py-1 text-left font-medium ' + cls, text: t});
            const head = el('tr', {class: 'text-xs text-slate-500'}, [th('', 'w-8'), th('Header (row 1)', 'w-[30%]'), th('Template'), cells ? th('Preview', 'w-[30%]') : null]);
            const trs = [];
            for (let i = 0; i < rows; i++) {
                const tpl = bind(input({maxlength: 2000, 'aria-label': 'Column ' + colLetter(i) + ' template'}, c.columns[i]), v => { while (c.columns.length < i) c.columns.push(''); c.columns[i] = v; });
                trs.push(el('tr', {class: 'border-t border-slate-100 align-top'}, [
                    el('td', {class: 'px-1 py-1.5 font-mono text-xs text-slate-500', text: colLetter(i)}),
                    el('td', {class: 'break-words px-1 py-1.5 text-xs ' + (headers[i] ? 'text-slate-800' : 'text-slate-400'), text: headers[i] || '—'}),
                    el('td', {class: 'px-1 py-1'}, tpl),
                    cells ? el('td', {class: 'break-words px-1 py-1.5 font-mono text-[11px] text-slate-700', text: cells[i] ?? ''}) : null,
                ]));
            }
            const add = rows < MAX_COLUMNS ? el('button', {type: 'button', class: 'cb-btn cb-btn-sm mt-1', text: '+ Column'}) : null;
            add?.addEventListener('click', () => { extra++; drawTable(); });
            table.replaceChildren(...[el('div', {class: 'mb-0.5 text-xs text-slate-600', text: 'Columns: the template of each column (A, B, C …). A cell with a variable is never a formula; empty = the cell stays empty.'}),
                el('table', {class: 'w-full table-fixed text-sm'}, [el('thead', {}, head), el('tbody', {}, trs)]), add].filter(Boolean));
        }
        function drawPreview() {
            const btn = el('button', {type: 'button', class: 'cb-btn cb-btn-sm', text: 'Preview row', disabled: !customers || !customers.length});
            const pick = el('select', {class: 'w-full rounded-md border border-slate-300 px-2 py-1 text-sm sm:w-auto sm:min-w-0 sm:flex-1', 'aria-label': 'Customer for the preview'},
                (customers || []).map(x => el('option', {value: x.chat_id, text: x.label})));
            const msg = el('span', {class: 'text-xs text-slate-500', 'aria-live': 'polite', text: customers === null ? 'Loading customers…' : (customers.length ? 'Preview only: nothing is written.' : 'No chats yet on the pages that use this flow.')});
            btn.addEventListener('click', async () => {
                msg.textContent = 'Filling…';
                const r = await send(ed.dataset.sheetPreviewUrl, 'POST', {chat_id: parseInt(pick.value, 10), columns: c.columns}).catch(() => ({ok: false, data: {}}));
                if (!editing || editing.c !== c) return;
                if (!r.ok) { msg.textContent = r.data.error || 'Could not make the preview.'; return; }
                cells = r.data.cells; msg.textContent = 'Preview only: nothing is written.'; drawTable();
            });
            preview.replaceChildren(...[customers && customers.length ? pick : null, btn, msg].filter(Boolean));
        }
        drawTab(); drawTable(); drawPreview(); load(false);
        if (customers === null) fetch(ed.dataset.sheetCustomersUrl, {headers: {'Accept': 'application/json'}}).then(r => r.ok ? r.json() : {customers: []})
            .then(d => { customers = d.customers || []; if (editing && editing.c === c) drawPreview(); }).catch(() => { customers = []; });
    }
    function bind(control, set, checkbox = false) {
        const fire = () => set(checkbox ? control.checked : control.value);
        control.addEventListener(checkbox ? 'change' : 'input', fire);
        if (control.tagName === 'SELECT') control.addEventListener('change', fire);
        return control;
    }
    function closeForm() { editing = null; $('form-box').classList.add('hidden'); }
    document.querySelectorAll('[data-form-close]').forEach(b => b.addEventListener('click', closeForm));
    $('form-apply').addEventListener('click', () => {
        if (!editing) return;
        editing.n.config = editing.c;
        const keep = ports(editing.n);
        graph.edges = graph.edges.filter(e => e.from !== editing.n.id || keep.includes(e.port)); // ang nawalang port ay walang wire
        closeForm(); render(); changed();
    });
    $('form-duplicate').addEventListener('click', () => {
        if (!editing || editing.n.type === 'start') return;
        const src = editing.n, next = 1 + Math.max(0, ...graph.nodes.map(n => parseInt(n.id.slice(1), 10) || 0));
        const n = {id: 'n' + next, type: src.type, x: src.x + 40, y: src.y + 40, config: JSON.parse(JSON.stringify(src.config || {}))}; // walang wire
        graph.nodes.push(n); closeForm(); render(); changed();
    });
    $('form-delete').addEventListener('click', () => {
        if (!editing || editing.n.type === 'start' || !confirm('Delete this step and its wires?')) return;
        const id = editing.n.id;
        graph.nodes = graph.nodes.filter(n => n.id !== id);
        graph.edges = graph.edges.filter(e => e.from !== id && e.to !== id);
        closeForm(); render(); changed();
    });

    // ---- undo / redo ----
    const snapshot = () => JSON.stringify(graph);
    function updateUndo() { $('undo').disabled = !undoStack.length; $('redo').disabled = !redoStack.length; }
    /** Ang bagong graph ay isang hakbang na mababawi (pagbabago sa canvas, Discard, Restore). */
    function remember() {
        const now = snapshot();
        if (now === current) return false;
        if (current !== null) { undoStack.push(current); if (undoStack.length > HISTORY) undoStack.shift(); }
        redoStack = []; current = now; updateUndo();
        return true;
    }
    function jump(from, to) {
        if (!from.length) return;
        to.push(current); current = from.pop(); graph = JSON.parse(current);
        selWire = null; $('del-wire').classList.add('hidden');
        render(); updateUndo(); schedule();
    }
    const undo = () => jump(undoStack, redoStack), redo = () => jump(redoStack, undoStack);
    $('undo').addEventListener('click', undo);
    $('redo').addEventListener('click', redo);

    // ---- the draft: autosave, Save draft, Publish, Discard, History ----
    const CHIP = {
        never: ['Never published', 'bg-slate-100 text-slate-700'],
        draft: ['Draft (unpublished changes)', 'bg-amber-100 text-amber-900'],
        published: ['Published', 'bg-green-100 text-green-800'],
    };
    function setState(data) {
        if (data.version != null) version = data.version;
        if (data.status) state = data.status;
        if (data.pages) pages = data.pages;
        const [text, cls] = CHIP[state] || CHIP.draft;
        $('chip').className = 'rounded-full px-2 py-0.5 text-xs font-medium ' + cls;
        $('chip').textContent = text;
        $('chip').title = data.published_at ? 'Last published ' + data.published_at : '';
        $('discard').disabled = state === 'never' || (state !== 'draft' && !dirty); // walang published na mababalikan
    }
    const send = (url, method, body) => fetch(url, {method, headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf}, body: JSON.stringify(body)})
        .then(async r => ({ok: r.ok, code: r.status, data: await r.json().catch(() => ({}))}));
    function showConflict(on) { conflict = on; $('conflict').classList.toggle('hidden', !on); $('conflict').classList.toggle('flex', on); }

    function changed() { if (remember()) schedule(); }
    function schedule() { dirty = true; setState({}); status('Unsaved…'); clearTimeout(saveTimer); saveTimer = setTimeout(() => save(), 800); }
    /** Iisang save sa isang pagkakataon, sunod-sunod; ang huling graph ang ipinapadala. */
    function save(overwrite = false) { clearTimeout(saveTimer); saveTimer = null; pending = pending.then(() => saveNow(overwrite)); return pending; }
    async function saveNow(overwrite) {
        if ((!dirty && !overwrite) || (conflict && !overwrite)) return;
        const body = snapshot();
        status('Saving…');
        try {
            const {ok, code, data} = await send(ed.dataset.saveUrl, 'PUT', {graph: JSON.parse(body), version, overwrite});
            if (ok) {
                showConflict(false);
                dirty = snapshot() !== body;
                setState(data);
                status(dirty ? 'Unsaved…' : 'Draft saved ' + data.saved_at, dirty ? 'text-slate-500' : 'text-green-700');
            } else if (code === 409) { showConflict(true); status('Not saved', 'text-red-600'); }
            else status('Not saved: ' + (data.error || data.message || code), 'text-red-600');
        } catch (e) { status('Not saved: no connection', 'text-red-600'); }
    }
    $('save-draft').addEventListener('click', () => { if (!dirty) return status('No changes to save.'); save(); });
    $('c-reload').addEventListener('click', () => { leaving = true; location.reload(); });
    $('c-overwrite').addEventListener('click', () => save(true));
    window.addEventListener('beforeunload', ev => { if (!leaving && (dirty || saveTimer)) { ev.preventDefault(); ev.returnValue = ''; } });

    /** Isang bagong graph mula sa server (Discard, Restore): mababawi ng Undo, at naka-save na. */
    function replaceGraph(g) {
        graph = g; graph.nodes.forEach(n => n.config = n.config && !Array.isArray(n.config) ? n.config : {});
        remember(); dirty = false; selWire = null; $('del-wire').classList.add('hidden'); render();
    }

    function closePublish() { $('pub-box').classList.add('hidden'); }
    $('publish').addEventListener('click', () => {
        $('pub-pages').textContent = pages.length
            ? 'Live customers on ' + pages.length + ' page' + (pages.length === 1 ? '' : 's') + ' (' + pages.join(', ') + ') get this version right away.'
            : 'No page uses this flow yet. It goes live once a page picks it.';
        $('pub-note').value = ''; $('pub-error').classList.add('hidden'); $('pub-go').disabled = false;
        $('pub-box').classList.remove('hidden'); $('pub-note').focus();
    });
    document.querySelectorAll('[data-pub-close]').forEach(b => b.addEventListener('click', closePublish));
    $('pub-go').addEventListener('click', async () => {
        const fail = t => { $('pub-error').textContent = t; $('pub-error').classList.remove('hidden'); $('pub-go').disabled = false; };
        $('pub-go').disabled = true;
        await save();
        if (conflict) return fail('This draft changed since you opened it. Reload, or overwrite, then publish.');
        if (dirty) return fail('The draft is not saved yet. Try again.');
        const {ok, code, data} = await send(ed.dataset.publishUrl, 'POST', {version, note: $('pub-note').value});
        if (code === 409) { showConflict(true); return fail(data.error); }
        if (!ok) return fail(data.error || data.message || 'Not published.');
        setState(data); closePublish();
        status('Published ' + (data.published_at || '').slice(11), 'text-green-700');
    });

    $('discard').addEventListener('click', async () => {
        if (!confirm('Discard the draft and go back to the published version?')) return;
        clearTimeout(saveTimer); saveTimer = null; await pending;
        const {ok, code, data} = await send(ed.dataset.discardUrl, 'POST', {version});
        if (code === 409) return showConflict(true);
        if (!ok) return status('Not discarded: ' + (data.error || data.message || code), 'text-red-600');
        replaceGraph(data.graph); setState(data); status('Draft discarded', 'text-green-700');
    });

    function closeHistory() { $('hist-box').classList.add('hidden'); }
    document.querySelectorAll('[data-hist-close]').forEach(b => b.addEventListener('click', closeHistory));
    $('hist-box').addEventListener('click', ev => { if (ev.target === $('hist-box')) closeHistory(); });
    $('history').addEventListener('click', async () => {
        $('hist-list').replaceChildren(el('li', {class: 'text-xs text-slate-500', text: 'Loading…'}));
        $('hist-box').classList.remove('hidden');
        try {
            const data = await fetch(ed.dataset.versionsUrl, {headers: {'Accept': 'application/json'}}).then(r => r.json());
            const list = data.versions || [];
            $('hist-list').replaceChildren(...(list.length ? list.map(v => {
                const b = el('button', {type: 'button', class: 'cb-btn cb-btn-sm mt-1', text: 'Restore'});
                b.addEventListener('click', () => restore(v));
                return el('li', {class: 'rounded-md border border-slate-200 p-2'}, [
                    el('div', {class: 'font-medium', text: v.at || ''}), el('div', {class: 'text-xs text-slate-500', text: v.by || 'Unknown'}),
                    v.note ? el('div', {class: 'break-words text-slate-700', text: v.note}) : null, b]);
            }) : [el('li', {class: 'text-xs text-slate-500', text: 'No publishes yet.'})]));
            $('hist-list').querySelector('button')?.focus();
        } catch (e) { $('hist-list').replaceChildren(el('li', {class: 'text-xs text-red-700', text: 'Could not load the history.'})); }
    });
    async function restore(v) {
        if (!confirm('Put the version of ' + v.at + ' in the draft? It goes live only when you publish.')) return;
        clearTimeout(saveTimer); saveTimer = null; await pending;
        const {ok, code, data} = await send(ed.dataset.restoreUrl.replace(/\/versions\/0\/restore$/, '/versions/' + v.id + '/restore'), 'POST', {version});
        if (code === 409) { closeHistory(); return showConflict(true); }
        if (!ok) return status('Not restored: ' + (data.error || data.message || code), 'text-red-600');
        replaceGraph(data.graph); setState(data); closeHistory(); fit();
        status('Version of ' + v.at + ' restored to the draft', 'text-green-700');
    }

    $('preview').addEventListener('click', async ev => {
        ev.preventDefault();
        await save();
        if (dirty || conflict) return status('Save the draft first, then test it.', 'text-red-600');
        leaving = true; location.href = ed.dataset.testUrl;
    });

    fetch(ed.dataset.graphUrl, {headers: {'Accept': 'application/json'}}).then(r => r.json()).then(data => {
        graph = data.graph; variables = data.variables || []; rules = data.rules || {}; sequences = data.sequences || [];
        graph.nodes.forEach(n => n.config = n.config && !Array.isArray(n.config) ? n.config : {});
        current = snapshot(); setState(data);
        applyView(); render(); fit();
    }).catch(() => status('Could not load the flow.', 'text-red-600'));
})();
</script>
@endsection

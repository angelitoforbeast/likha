@extends('layouts.chatblast')

@section('title', 'Chatbot · ' . $flow->name)
@section('heading', $flow->name)

@section('description')
    Drag from a port (the dot on the right of a step) to another step to wire them. Click a step to edit it. Changes save by themselves.
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

<div id="ed" data-graph-url="{{ route('chatbot.flows.graph', $flow->id) }}" data-save-url="{{ route('chatbot.flows.save', $flow->id) }}">
    <div class="mb-2 flex flex-wrap items-center gap-2 text-sm">
        <span class="text-xs text-slate-500">Add:</span>
        <button type="button" class="cb-btn cb-btn-sm" data-add="condition">Condition</button>
        <button type="button" class="cb-btn cb-btn-sm" data-add="send">Send message</button>
        <button type="button" class="cb-btn cb-btn-sm" data-add="set">Set field</button>
        <button type="button" class="cb-btn cb-btn-sm" data-add="ai">AI</button>
        <button type="button" class="cb-btn cb-btn-sm" data-add="sheet">Google Sheet</button>
        <button type="button" class="cb-btn cb-btn-sm" data-add="end">End</button>
        <span class="mx-1 text-slate-300">|</span>
        <button type="button" class="cb-btn cb-btn-sm" id="arrange">Arrange</button>
        <button type="button" class="cb-btn cb-btn-sm" id="zoom-out" aria-label="Zoom out">−</button>
        <button type="button" class="cb-btn cb-btn-sm" id="zoom-in" aria-label="Zoom in">+</button>
        <button type="button" class="cb-btn cb-btn-sm" id="fit">Fit</button>
        <button type="button" class="cb-btn cb-btn-sm hidden" id="del-wire">Remove wire</button>
        <span id="status" class="text-xs text-slate-500" aria-live="polite"></span>
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
                <button type="button" id="form-delete" class="cb-btn ml-auto text-red-700">Delete step</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const ed = document.getElementById('ed'), $ = id => document.getElementById(id);
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const cv = $('cv'), world = $('world'), svg = $('wires');
    const W = 220, NS = 'http://www.w3.org/2000/svg';
    const OPEN = '{{', CLOSE = '}}'; // hindi isinusulat nang buo: may sariling echo ang Blade
    const LABEL = {start: 'Start', condition: 'Condition', send: 'Send message', set: 'Set field', ai: 'AI', sheet: 'Google Sheet', end: 'End'};
    const PORT_LABEL = {next: 'next', continue: 'continue', reply: 'reply', no: 'no match', success: 'success', failed: 'failed'};
    let graph = {nodes: [], edges: []}, variables = [], rules = {};
    let view = {x: 40, y: 40, z: 1}, selWire = null, saveTimer = null, saving = false, again = false, lastField = null;

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
            case 'start': case 'set': return ['next'];
            case 'condition': return [...(c.rows || []).map((_, i) => 'row_' + (i + 1)), 'no'];
            case 'send': return c.wait ? ['reply'] : ['continue'];
            case 'ai': return ['success', 'failed'];
            case 'sheet': return ['next', 'failed'];
            default: return [];
        }
    }
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
            case 'send': return (c.items || []).map(i => (i.type === 'image' ? '🖼 ' : '') + (i.value || '')).join('\n') + (c.wait ? '\n⏳ waits for reply' + (c.save_to ? ' → ' + c.save_to : '') : '');
            case 'set': return (c.ops || []).map(o => o.op === 'clear_all' ? 'clear all fields' : o.op + ' ' + (o.field || '?') + (o.op === 'set' || o.op === 'append' ? ' = ' + (o.value || '') : '')).join('\n');
            case 'ai': return (c.model || 'gpt-4o-mini') + (c.save_to ? ' → ' + c.save_to : '') + (c.send ? ', sends the answer' : '');
            case 'sheet': return (c.tab || 'Sheet1') + ': ' + (c.columns || []).length + ' column(s)';
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
        if (temp) { const p = document.createElementNS(NS, 'path'); p.setAttribute('d', curve(temp.a, temp.b)); p.setAttribute('stroke-dasharray', '5 4'); svg.append(p); }
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
        set: {ops: [{op: 'set', field: '', value: '', separator: ', '}]}, ai: {model: 'gpt-4o-mini', system: '', user: OPEN + 'last_input' + CLOSE, history: 0, max_tokens: 250, save_to: '', send: false},
        sheet: {sheet: '', tab: '', columns: []}, end: {},
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
                label('Remember the last N messages (0 = off)', bind(input({type: 'number', min: 0, max: 20}, c.history ?? 0), v => c.history = parseInt(v, 10) || 0)),
                label('Max tokens', bind(input({type: 'number', min: 1, max: 4000}, c.max_tokens ?? 250), v => c.max_tokens = parseInt(v, 10) || 250)),
                label('Save the answer into', bind(input({list: listId, maxlength: 100, placeholder: 'e.g. GPT INPUT'}, c.save_to), v => c.save_to = v)),
            ]));
            body.append(el('label', {class: 'flex items-center gap-2 text-sm'}, [bind(el('input', {type: 'checkbox', checked: !!c.send}), v => c.send = v, true), 'Send the answer to the customer']));
        }
        if (n.type === 'sheet') {
            body.append(label('Spreadsheet link or ID', bind(input({maxlength: 300, placeholder: 'https://docs.google.com/spreadsheets/d/…'}, c.sheet), v => c.sheet = v)));
            body.append(label('Tab', bind(input({maxlength: 100, placeholder: 'Sheet1'}, c.tab), v => c.tab = v)));
            body.append(label('Columns: one template per line (A, B, C …). A cell with a variable is never a formula.', bind(input({tag: 'textarea', rows: 8}, (c.columns || []).join('\n')), v => c.columns = v.split(/\r?\n/))));
        }
        $('vars').replaceChildren(...variables.map(v => { const b = el('button', {type: 'button', class: 'rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] hover:bg-slate-200', text: OPEN + v + CLOSE});
            b.addEventListener('click', () => { if (!lastField) return; const s = lastField.selectionStart ?? lastField.value.length; lastField.value = lastField.value.slice(0, s) + OPEN + v + CLOSE + lastField.value.slice(s); lastField.dispatchEvent(new Event('input')); lastField.focus(); }); return b; }));
        $('form-box').classList.remove('hidden');
        body.querySelector('input, textarea, select')?.focus();
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
    $('form-delete').addEventListener('click', () => {
        if (!editing || editing.n.type === 'start' || !confirm('Delete this step and its wires?')) return;
        const id = editing.n.id;
        graph.nodes = graph.nodes.filter(n => n.id !== id);
        graph.edges = graph.edges.filter(e => e.from !== id && e.to !== id);
        closeForm(); render(); changed();
    });

    // ---- autosave ----
    function changed() { status('Unsaved…'); clearTimeout(saveTimer); saveTimer = setTimeout(save, 800); }
    async function save() {
        if (saving) { again = true; return; }
        saving = true; status('Saving…');
        try {
            const res = await fetch(ed.dataset.saveUrl, {method: 'PUT', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf}, body: JSON.stringify({graph})});
            const data = await res.json().catch(() => ({}));
            if (res.ok) status('Saved ' + data.saved_at, 'text-green-700'); else status('Not saved: ' + (data.error || data.message || res.status), 'text-red-600');
        } catch (e) { status('Not saved: no connection', 'text-red-600'); }
        saving = false;
        if (again) { again = false; save(); }
    }
    window.addEventListener('beforeunload', ev => { if (saveTimer && $('status').textContent === 'Unsaved…') { ev.preventDefault(); ev.returnValue = ''; } });

    fetch(ed.dataset.graphUrl, {headers: {'Accept': 'application/json'}}).then(r => r.json()).then(data => {
        graph = data.graph; variables = data.variables || []; rules = data.rules || {};
        graph.nodes.forEach(n => n.config = n.config && !Array.isArray(n.config) ? n.config : {});
        applyView(); render(); fit();
    }).catch(() => status('Could not load the flow.', 'text-red-600'));
})();
</script>
@endsection

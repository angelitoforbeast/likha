@extends('layouts.chatblast')

@section('title', 'Chatbot · ' . $flow->name)
@section('heading', $flow->name)

@section('description')
    Drag from a port (the dot on the right of a step) to another step to wire them. Click a step to edit it; drag it to move it. Drag the empty canvas to pan, scroll or pinch to zoom. Changes save to a draft by themselves; customers get them only after you press Publish.
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
    /* Habang nagda-drag o nagpa-pan: walang napi-piling text (ang mga form sa modal ay nasa labas ng #cv, kaya pwede pa rin doon). */
    #cv.moving, #cv.moving * { user-select: none; -webkit-user-select: none; cursor: grabbing !important; }
    /* Ang posisyon ay transform lang (walang layout habang nagda-drag); ang buong card ang hawakan. */
    .nd { position: absolute; left: 0; top: 0; width: 220px; background: #fff; border: 1px solid #cbd5e1; border-radius: 10px; box-shadow: 0 1px 2px rgb(0 0 0 / .06); font-size: 12px; }
    #cv .nd { cursor: grab; } /* mas tiyak kaysa sa [role=button] { cursor: pointer } ng Tailwind */
    .nd.dragging { box-shadow: 0 8px 20px rgb(0 0 0 / .15); z-index: 1; will-change: transform; }
    .nd.sel { border-color: #2563eb; box-shadow: 0 0 0 2px #bfdbfe; }
    .nd-h { cursor: grab; padding: 6px 10px; border-bottom: 1px solid #e2e8f0; border-radius: 10px 10px 0 0; font-weight: 600; display: flex; justify-content: space-between; gap: 6px; }
    .nd-b { padding: 6px 10px; color: #475569; max-height: 5.5em; overflow: hidden; white-space: pre-line; word-break: break-word; }
    /* Ang Set field: ang mga linya ng value ay nakikita, hanggang 3 linya. */
    .t-set .nd-b { display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 3; padding-bottom: 0; margin-bottom: 6px; }
    .nd-p { display: flex; justify-content: flex-end; align-items: center; gap: 6px; padding: 2px 0 2px 10px; color: #334155; }
    .dot { width: 14px; height: 14px; border-radius: 999px; border: 2px solid #64748b; background: #fff; margin-right: -8px; cursor: crosshair; flex: none; }
    .dot.on { background: #64748b; }
    .in { position: absolute; left: -8px; top: 9px; width: 14px; height: 14px; border-radius: 999px; border: 2px solid #64748b; background: #fff; }
    #wires path { fill: none; stroke: #64748b; stroke-width: 2; pointer-events: none; transition: opacity .12s; }
    #wires path.hit { stroke: transparent; stroke-width: 12; pointer-events: stroke; cursor: pointer; }
    #wires path.sel { stroke: #2563eb; stroke-width: 3; }
    /* Naka-hover sa step: ang mga wire nito lang ang malinaw. */
    #cv.hovering #wires path.w:not(.hl) { opacity: .15; }
    #wires path.hl { stroke: #2563eb; stroke-width: 2.5; }
    #wires .x { cursor: pointer; pointer-events: all; }
    #minimap { position: absolute; right: 8px; bottom: 8px; width: 180px; height: 120px; background: rgb(255 255 255 / .92); border: 1px solid #cbd5e1; border-radius: 8px; cursor: pointer; touch-action: none; }
    @media (max-width: 640px) { #minimap { width: 120px; height: 80px; } }
    .t-start .nd-h { background: #ecfdf5; } .t-condition .nd-h { background: #fef3c7; } .t-send .nd-h { background: #eff6ff; }
    .t-set .nd-h { background: #f5f3ff; } .t-ai .nd-h { background: #fdf2f8; } .t-clear_ai .nd-h { background: #fff1f2; } .t-sheet .nd-h { background: #f0fdf4; } .t-encode_order .nd-h { background: #ecfeff; } .t-end .nd-h { background: #f1f5f9; }
</style>
@endpush

@section('content')
@include('chatbot.partials.switch', ['repliesOn' => $repliesOn])

<div id="ed" data-graph-url="{{ route('chatbot.flows.graph', $flow->id) }}" data-save-url="{{ route('chatbot.flows.save', $flow->id) }}"
     data-publish-url="{{ route('chatbot.flows.publish', $flow->id) }}" data-discard-url="{{ route('chatbot.flows.discard', $flow->id) }}"
     data-versions-url="{{ route('chatbot.flows.versions', $flow->id) }}" data-restore-url="{{ route('chatbot.flows.restore', [$flow->id, 0]) }}"
     data-test-url="{{ route('chatbot.test', ['flow' => $flow->id, 'version' => 'draft']) }}"
     data-sheet-url="{{ route('chatbot.sheet.info') }}" data-sheet-customers-url="{{ route('chatbot.flows.sheet-customers', $flow->id) }}"
     data-sheet-preview-url="{{ route('chatbot.flows.sheet-preview', $flow->id) }}" data-max-columns="{{ \App\Services\Chatbot\FlowGraph::MAX_COLUMNS }}"
     data-encode-models="{{ json_encode($encodeModels) }}" data-encode-url="{{ \Illuminate\Support\Facades\Route::has('order-encode.index') ? route('order-encode.index') : '' }}">
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
        <button type="button" class="cb-btn cb-btn-sm" data-add="clear_ai">Clear AI memory</button>
        <button type="button" class="cb-btn cb-btn-sm" data-add="sheet">Google Sheet</button>
        <button type="button" class="cb-btn cb-btn-sm" data-add="encode_order" title="Encodes and checks the order in Likha and saves it to the orders (macro output). Replaces the Google Sheet step.">Encode order</button>
        <button type="button" class="cb-btn cb-btn-sm" data-add="sequence">Sequence</button>
        <button type="button" class="cb-btn cb-btn-sm" data-add="end">End</button>
        <span class="mx-1 text-slate-300">|</span>
        <button type="button" class="cb-btn cb-btn-sm" id="arrange">Arrange</button>
        <button type="button" class="cb-btn cb-btn-sm" id="zoom-out" aria-label="Zoom out">−</button>
        <button type="button" class="cb-btn cb-btn-sm" id="zoom-in" aria-label="Zoom in">+</button>
        <button type="button" class="cb-btn cb-btn-sm" id="fit">Fit</button>
        <label class="flex items-center gap-1 text-xs text-slate-600" title="Drop steps on a 16 px grid"><input type="checkbox" id="snap" checked> Snap</label>
        <button type="button" class="cb-btn cb-btn-sm" id="minimap-toggle" aria-pressed="true" aria-controls="minimap">Map</button>
        <button type="button" class="cb-btn cb-btn-sm hidden" id="del-wire">Remove wire</button>
    </div>

    <div id="cv" class="cb-card relative overflow-hidden">
        <div id="world">
            <svg id="wires" style="position:absolute; left:0; top:0; overflow:visible" width="1" height="1"></svg>
        </div>
        <canvas id="minimap" role="img" aria-label="Map of the whole flow; click to move there"></canvas>
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
                <div class="mb-1 flex flex-wrap items-center justify-between gap-2">
                    <div class="text-xs text-slate-500">Variables (click to put one in the last field you were in):</div>
                    <input id="vars-search" type="search" placeholder="Search variables" aria-label="Search variables" class="w-full rounded-md border border-slate-300 px-2 py-1 text-xs sm:w-48">
                </div>
                <div id="vars" class="space-y-2"></div>
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
    const LABEL = {start: 'Start', condition: 'Condition', send: 'Send message', set: 'Set field', ai: 'AI', clear_ai: 'Clear AI memory', sheet: 'Google Sheet', encode_order: 'Encode order', sequence: 'Sequence', end: 'End'};
    const PORT_LABEL = {next: 'next', continue: 'continue', reply: 'reply', skipped: 'skipped (no reply in time)', no: 'no match', success: 'success', failed: 'failed'};
    let graph = {nodes: [], edges: []}, variables = [], variableGroups = [], rules = {}, sequences = [];
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
            case 'start': case 'set': case 'sequence': case 'clear_ai': return ['next'];
            case 'condition': return [...(c.rows || []).map((_, i) => 'row_' + (i + 1)), 'no'];
            case 'send': return c.wait ? (skipMinutes(c) ? ['reply', 'skipped'] : ['reply']) : ['continue'];
            case 'ai': return ['success', 'failed'];
            case 'sheet': case 'encode_order': return ['next', 'failed'];
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
            case 'clear_ai': return 'Forgets the conversation so far' + ((c.fields || []).filter(Boolean).length ? '\nclears ' + c.fields.filter(Boolean).join(', ') : '');
            case 'sheet': return (c.tab || 'Sheet1') + ': ' + (c.columns || []).length + ' column(s)';
            case 'encode_order': return 'Encodes the order in Likha' + (c.also_sheet ? ' + writes the sheet' : '') + '\nOn failure: ' + (c.on_failure === 'continue' ? 'continue without saving' : 'write to the sheet');
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
    function applyView() { world.style.transform = `translate(${view.x}px, ${view.y}px) scale(${view.z})`; drawMap(); }
    function nodeEl(n) { return world.querySelector(`.nd[data-id="${n.id}"]`); }
    const place = (card, n) => card.style.transform = `translate(${n.x}px, ${n.y}px)`;
    // Ang taas ng bawat card at ang puwesto ng mga port nito: sinusukat isang beses kada render, hindi habang nagda-drag.
    let box = {};
    function measure() {
        box = {};
        world.querySelectorAll('.nd').forEach(c => {
            const ports = {};
            c.querySelectorAll('.dot').forEach(d => ports[d.dataset.port] = {x: d.offsetLeft + 7, y: d.offsetTop + 7});
            box[c.dataset.id] = {h: c.offsetHeight, ports};
        });
    }
    function render() {
        world.querySelectorAll('.nd').forEach(e => e.remove());
        for (const n of graph.nodes) {
            const card = el('div', {class: 'nd t-' + n.type, 'data-id': n.id, style: `transform: translate(${n.x}px, ${n.y}px)`, tabindex: '0', role: 'button', 'aria-label': LABEL[n.type] + ' step'}, [
                n.type === 'start' ? null : el('span', {class: 'in'}),
                el('div', {class: 'nd-h'}, [el('span', {text: LABEL[n.type]}), el('span', {class: 'font-mono text-[10px] text-slate-400', text: n.id})]),
                summary(n) ? el('div', {class: 'nd-b', text: summary(n)}) : null,
                ...ports(n).map(p => el('div', {class: 'nd-p'}, [el('span', {class: 'truncate', text: portLabel(n, p)}),
                    el('span', {class: 'dot' + (graph.edges.some(e => e.from === n.id && e.port === p) ? ' on' : ''), 'data-port': p, title: 'Drag to the next step'})])),
            ]);
            card.addEventListener('keydown', ev => { if (ev.key === 'Enter') openForm(n); });
            world.append(card);
        }
        measure();
        drawWires();
    }
    function portPos(n, p) {
        const card = nodeEl(n), dot = card?.querySelector(`.dot[data-port="${p}"]`);
        if (!dot) return {x: n.x + W, y: n.y + 20};
        return {x: n.x + W, y: n.y + dot.offsetTop + dot.offsetHeight / 2 + dot.parentElement.offsetTop - dot.parentElement.offsetTop};
    }
    function dotPos(n, p) {
        const q = box[n.id]?.ports[p];
        return q ? {x: n.x + q.x, y: n.y + q.y} : {x: n.x + W, y: n.y + 20};
    }
    // Bezier mula sa gilid ng port papunta sa input ng step; ang wire na pabalik (sa kaliwa) ay umiikot nang mas malapad.
    const ctrl = (a, b) => { const d = b.x < a.x + 40 ? Math.max(120, Math.abs(b.y - a.y) / 2) : Math.max(40, (b.x - a.x) / 2); return [a, {x: a.x + d, y: a.y}, {x: b.x - d, y: b.y}, b]; };
    const curve = (a, b) => { const [, c1, c2] = ctrl(a, b); return `M${a.x},${a.y} C${c1.x},${c1.y} ${c2.x},${c2.y} ${b.x},${b.y}`; };
    const ends = e => { const a = graph.nodes.find(n => n.id === e.from), b = graph.nodes.find(n => n.id === e.to); return a && b ? [dotPos(a, e.port), {x: b.x, y: b.y + 16}] : null; };
    const svgEl = (tag, attrs) => { const x = document.createElementNS(NS, tag); for (const [k, v] of Object.entries(attrs)) x.setAttribute(k, v); return x; };
    let wires = [], hoverId = null;
    function drawWires(temp) {
        svg.replaceChildren(); wires = [];
        graph.edges.forEach((e, i) => {
            const ab = ends(e);
            if (!ab) return;
            const d = curve(...ab), p = svgEl('path', {d, class: 'w' + (selWire === i ? ' sel' : ''), 'data-from': e.from, 'data-to': e.to}), hit = svgEl('path', {d, class: 'hit'});
            hit.addEventListener('pointerdown', ev => { ev.stopPropagation(); selWire = i; $('del-wire').classList.remove('hidden'); drawWires(); });
            svg.append(p, hit); wires.push({e, i, p, hit});
        });
        // Ang napiling wire: may "×" sa gitna para tanggalin (kapareho ng Remove wire).
        const s = wires.find(w => w.i === selWire);
        if (s) {
            const [a, c1, c2, b] = ctrl(...ends(s.e)), m = {x: (a.x + 3 * c1.x + 3 * c2.x + b.x) / 8, y: (a.y + 3 * c1.y + 3 * c2.y + b.y) / 8};
            const x = svgEl('g', {class: 'x', transform: `translate(${m.x},${m.y})`}); // pang-pointer lang; sa keyboard ay ang Delete key at ang Remove wire
            x.append(svgEl('circle', {r: 9, fill: '#fff', stroke: '#2563eb', 'stroke-width': 1.5}), svgEl('path', {d: 'M-3.5,-3.5 L3.5,3.5 M3.5,-3.5 L-3.5,3.5', style: 'stroke:#2563eb; stroke-width:1.5'}));
            x.addEventListener('pointerdown', ev => { ev.stopPropagation(); $('del-wire').click(); });
            svg.append(x);
        }
        if (temp) { const p = svgEl('path', {d: curve(temp.a, temp.b), 'stroke-dasharray': '5 4'}); svg.append(p); /* walang pointer-events: hindi sasaluhin ang pagbitaw */ }
        hover(); drawMap();
    }
    /** Habang dina-drag ang isang step: ang mga wire lang nito ang ginagalaw. */
    function moveWires(n) {
        for (const w of wires) if (w.e.from === n.id || w.e.to === n.id) { const d = curve(...ends(w.e)); w.p.setAttribute('d', d); w.hit.setAttribute('d', d); }
    }
    function hover() {
        cv.classList.toggle('hovering', !!hoverId);
        for (const w of wires) w.p.classList.toggle('hl', !!hoverId && (w.e.from === hoverId || w.e.to === hoverId));
    }
    cv.addEventListener('pointerover', ev => { if (drag) return; const id = ev.target.closest('.nd')?.dataset.id || null; if (id !== hoverId) { hoverId = id; hover(); } });
    cv.addEventListener('pointerleave', () => { if (drag) return; hoverId = null; hover(); });

    // ---- pointer: pan, drag a step (ang buong card), wire from a port, pinch ----
    const ZMIN = 0.2, ZMAX = 2, GRID = 16, SLOP = 4;
    const clampZ = z => Math.min(ZMAX, Math.max(ZMIN, z));
    const toWorld = (cx, cy, r = cv.getBoundingClientRect()) => ({x: (cx - r.left - view.x) / view.z, y: (cy - r.top - view.y) / view.z});
    const unselect = () => { const s = getSelection(); if (s && s.rangeCount && !s.isCollapsed) s.removeAllRanges(); };
    const pts = new Map();
    let drag = null;
    // Walang pagpili ng text na nagsisimula habang may hawak sa canvas (pati double-click).
    cv.addEventListener('selectstart', ev => { if (drag) ev.preventDefault(); });
    cv.addEventListener('pointerdown', ev => {
        if (ev.pointerType === 'mouse' && ev.button !== 0) return;
        cv.setPointerCapture(ev.pointerId);
        pts.set(ev.pointerId, {x: ev.clientX, y: ev.clientY});
        if (pts.size === 2) { endDrag(); drag = pinch(); return; }
        if (pts.size > 2 || drag) return;
        const dot = ev.target.closest('.dot'), card = ev.target.closest('.nd'), r = cv.getBoundingClientRect(), id = ev.pointerId;
        if (dot) {
            drag = {kind: 'wire', id, r, n: graph.nodes.find(x => x.id === card.dataset.id), port: dot.dataset.port};
        } else if (card) {
            const n = graph.nodes.find(x => x.id === card.dataset.id), w = toWorld(ev.clientX, ev.clientY, r);
            drag = {kind: 'move', id, r, n, card, sx: ev.clientX, sy: ev.clientY, dx: w.x - n.x, dy: w.y - n.y, moved: false};
        } else {
            drag = {kind: 'pan', id, sx: ev.clientX - view.x, sy: ev.clientY - view.y};
            cv.classList.add('moving');
            if (selWire !== null) { selWire = null; $('del-wire').classList.add('hidden'); drawWires(); }
        }
    });
    cv.addEventListener('pointermove', ev => {
        if (pts.has(ev.pointerId)) pts.set(ev.pointerId, {x: ev.clientX, y: ev.clientY});
        if (!drag) return;
        if (drag.kind === 'pinch') return pinchMove();
        if (ev.pointerId !== drag.id) return;
        if (drag.kind === 'pan') { unselect(); view.x = ev.clientX - drag.sx; view.y = ev.clientY - drag.sy; applyView(); }
        else if (drag.kind === 'move') {
            // Kaunting galaw muna (SLOP) bago maging drag, para ang click ay nagbubukas pa rin ng step.
            if (!drag.moved) {
                if (Math.hypot(ev.clientX - drag.sx, ev.clientY - drag.sy) < SLOP) return;
                drag.moved = true; cv.classList.add('moving'); drag.card.classList.add('dragging');
            }
            unselect();
            const w = toWorld(ev.clientX, ev.clientY, drag.r);
            drag.n.x = Math.round(w.x - drag.dx); drag.n.y = Math.round(w.y - drag.dy);
            place(drag.card, drag.n); moveWires(drag.n); drawMap();
        }
        else if (drag.kind === 'wire') { drawWires({a: dotPos(drag.n, drag.port), b: toWorld(ev.clientX, ev.clientY, drag.r)}); }
    });
    /** Ibinaba ang step: sa grid kung naka-Snap, saka isang hakbang na mababawi. */
    function drop(d) {
        d.card.classList.remove('dragging');
        if ($('snap').checked) { d.n.x = Math.round(d.n.x / GRID) * GRID; d.n.y = Math.round(d.n.y / GRID) * GRID; place(d.card, d.n); }
        drawWires(); changed();
    }
    /** Ang hawak ay pinutol (pangalawang daliri, o kinansela ng browser): ang nagalaw na ay nananatili. */
    function endDrag() {
        const d = drag; drag = null; cv.classList.remove('moving');
        if (d?.kind === 'move' && d.moved) drop(d); else if (d?.kind === 'wire') drawWires();
    }
    function release(ev) {
        pts.delete(ev.pointerId);
        const d = drag;
        if (!d) return;
        if (d.kind === 'pinch') {
            // Isang daliri ang natira: ito na ang nagpa-pan.
            if (pts.size === 1) { const [[id, q]] = pts; drag = {kind: 'pan', id, sx: q.x - view.x, sy: q.y - view.y}; }
            else if (!pts.size) { drag = null; cv.classList.remove('moving'); }
            return;
        }
        if (ev.pointerId !== d.id) return;
        if (ev.type === 'pointercancel') return endDrag();
        drag = null; cv.classList.remove('moving');
        if (d.kind === 'move') { if (d.moved) drop(d); else openForm(d.n); }
        else if (d.kind === 'wire') {
            const hit = document.elementFromPoint(ev.clientX, ev.clientY)?.closest('.nd');
            const to = hit && graph.nodes.find(x => x.id === hit.dataset.id);
            if (to && to.id !== d.n.id && to.type !== 'start') {
                graph.edges = graph.edges.filter(e => !(e.from === d.n.id && e.port === d.port));
                graph.edges.push({from: d.n.id, port: d.port, to: to.id});
                render(); changed();
            } else drawWires();
        }
    }
    cv.addEventListener('pointerup', release);
    cv.addEventListener('pointercancel', release);
    // Dalawang daliri: ang puntong nasa gitna nila ay nananatili sa ilalim nila habang nag-zoom at nagpa-pan.
    const two = () => { const [p, q] = [...pts.values()]; return {d: Math.hypot(p.x - q.x, p.y - q.y) || 1, x: (p.x + q.x) / 2, y: (p.y + q.y) / 2}; };
    function pinch() {
        const r = cv.getBoundingClientRect(), t = two();
        cv.classList.add('moving');
        return {kind: 'pinch', r, d0: t.d, z0: view.z, w: toWorld(t.x, t.y, r)};
    }
    function pinchMove() {
        const t = two(), z = clampZ(drag.z0 * t.d / drag.d0);
        view.z = z; view.x = t.x - drag.r.left - drag.w.x * z; view.y = t.y - drag.r.top - drag.w.y * z; applyView();
    }
    const zoomAt = (mx, my, f) => { const z = clampZ(view.z * f); view.x = mx - (mx - view.x) * z / view.z; view.y = my - (my - view.y) * z / view.z; view.z = z; applyView(); };
    // Wheel at trackpad: zoom sa puwesto ng pointer; ang pinch ng trackpad (Ctrl+wheel) ay mas pino ang hakbang.
    cv.addEventListener('wheel', ev => {
        ev.preventDefault();
        const r = cv.getBoundingClientRect(), dy = ev.deltaY * (ev.deltaMode === 1 ? 33 : ev.deltaMode === 2 ? 400 : 1);
        zoomAt(ev.clientX - r.left, ev.clientY - r.top, Math.exp(-Math.max(-100, Math.min(100, dy)) * (ev.ctrlKey ? 0.01 : 0.002)));
    }, {passive: false});
    const zoomBy = f => { const r = cv.getBoundingClientRect(); zoomAt(r.width / 2, r.height / 2, f); };
    $('zoom-in').addEventListener('click', () => zoomBy(1.2));
    $('zoom-out').addEventListener('click', () => zoomBy(1 / 1.2));
    function fit() {
        if (!graph.nodes.length) return;
        const xs = graph.nodes.map(n => n.x), ys = graph.nodes.map(n => n.y), r = cv.getBoundingClientRect();
        // 30 px na gilid sa screen sa bawat panig (hindi sa world, para kasya rin kapag maliit ang zoom).
        const minX = Math.min(...xs), minY = Math.min(...ys), w = Math.max(...xs) + W - minX, h = Math.max(...graph.nodes.map(n => n.y + (box[n.id]?.h || 200))) - minY;
        view.z = Math.min(1.2, clampZ(Math.min((r.width - 60) / w, (r.height - 60) / h)));
        view.x = 30 - minX * view.z; view.y = 30 - minY * view.z; applyView();
    }
    $('fit').addEventListener('click', fit);
    // Snap at Map: naaalala ng browser.
    const remembered = (k, v) => { try { if (v === undefined) return localStorage.getItem(k); localStorage.setItem(k, v); } catch (e) { return null; } };
    $('snap').checked = remembered('chatbot.snap') !== '0';
    $('snap').addEventListener('change', () => remembered('chatbot.snap', $('snap').checked ? '1' : '0'));
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

    // ---- auto-arrange: kaliwa pakanan ayon sa takbo mula sa Start; ang mga sanga ay patong-patong, walang nagsasapawan ----
    $('arrange').addEventListener('click', () => {
        const start = graph.nodes.find(n => n.type === 'start');
        if (!start) return;
        const byId = Object.fromEntries(graph.nodes.map(n => [n.id, n])), into = new Set(graph.edges.map(e => e.to));
        const out = n => graph.edges.filter(e => e.from === n.id && byId[e.to]).sort((a, b) => ports(n).indexOf(a.port) - ports(n).indexOf(b.port));
        const depth = {}, parent = {}, order = [];
        // Mula sa Start muna; saka ang mga step na walang pumapasok na wire, at ang natira (mga ikot na hiwalay sa Start).
        for (const root of [start, ...graph.nodes.filter(n => !into.has(n.id)), ...graph.nodes]) {
            if (root.id in depth) continue;
            depth[root.id] = 0; order.push(root.id);
            for (let i = order.length - 1; i < order.length; i++) {
                out(byId[order[i]]).forEach(e => { if (!(e.to in depth)) { depth[e.to] = depth[order[i]] + 1; parent[e.to] = order[i]; order.push(e.to); } });
            }
        }
        // Bawat hanay: ang step ay kapantay ng pinanggalingan nito kung may puwang, kung wala ay sa ilalim ng huli sa hanay.
        const COL = 20 * GRID, GAP = 2 * GRID, top = 3 * GRID, bottom = {};
        for (const id of order) {
            const n = byId[id], d = depth[id], want = parent[id] ? byId[parent[id]].y : top;
            n.x = top + d * COL;
            n.y = Math.ceil(Math.max(want, bottom[d] ?? top) / GRID) * GRID;
            bottom[d] = n.y + (box[id]?.h || 120) + GAP;
        }
        render(); fit(); changed();
    });

    // ---- minimap: ang buong flow at ang kasalukuyang tanaw; ang click ay lilipat doon ----
    const map = $('minimap');
    let mapQueued = false;
    function drawMap() { if (mapQueued) return; mapQueued = true; requestAnimationFrame(() => { mapQueued = false; paintMap(); }); }
    function mapFrame() {
        const vw = {x: -view.x / view.z, y: -view.y / view.z, w: cv.clientWidth / view.z, h: cv.clientHeight / view.z};
        let x0 = vw.x, y0 = vw.y, x1 = vw.x + vw.w, y1 = vw.y + vw.h;
        for (const n of graph.nodes) { x0 = Math.min(x0, n.x); y0 = Math.min(y0, n.y); x1 = Math.max(x1, n.x + W); y1 = Math.max(y1, n.y + (box[n.id]?.h || 60)); }
        const mw = map.clientWidth, mh = map.clientHeight, s = Math.min((mw - 8) / (x1 - x0), (mh - 8) / (y1 - y0));
        return {vw, mw, mh, s, ox: (mw - (x1 - x0) * s) / 2 - x0 * s, oy: (mh - (y1 - y0) * s) / 2 - y0 * s};
    }
    function paintMap() {
        if (map.classList.contains('hidden') || !map.clientWidth) return;
        const f = mapFrame(), dpr = window.devicePixelRatio || 1, g = map.getContext('2d');
        map.width = f.mw * dpr; map.height = f.mh * dpr; g.setTransform(dpr, 0, 0, dpr, 0, 0);
        for (const n of graph.nodes) {
            g.fillStyle = n.type === 'start' ? '#10b981' : '#94a3b8';
            g.fillRect(f.ox + n.x * f.s, f.oy + n.y * f.s, Math.max(2, W * f.s), Math.max(2, (box[n.id]?.h || 60) * f.s));
        }
        g.fillStyle = 'rgb(37 99 235 / .08)'; g.strokeStyle = '#2563eb'; g.lineWidth = 1.5;
        g.fillRect(f.ox + f.vw.x * f.s, f.oy + f.vw.y * f.s, f.vw.w * f.s, f.vw.h * f.s);
        g.strokeRect(f.ox + f.vw.x * f.s, f.oy + f.vw.y * f.s, f.vw.w * f.s, f.vw.h * f.s);
    }
    let mapDrag = null;
    const mapGo = ev => {
        const r = map.getBoundingClientRect(), f = mapDrag, wx = (ev.clientX - r.left - f.ox) / f.s, wy = (ev.clientY - r.top - f.oy) / f.s;
        view.x = cv.clientWidth / 2 - wx * view.z; view.y = cv.clientHeight / 2 - wy * view.z; applyView();
    };
    map.addEventListener('pointerdown', ev => { ev.stopPropagation(); map.setPointerCapture(ev.pointerId); mapDrag = mapFrame(); mapGo(ev); });
    map.addEventListener('pointermove', ev => { if (mapDrag) mapGo(ev); });
    map.addEventListener('pointerup', () => mapDrag = null);
    map.addEventListener('pointercancel', () => mapDrag = null);
    const showMap = on => { map.classList.toggle('hidden', !on); $('minimap-toggle').setAttribute('aria-pressed', String(on)); remembered('chatbot.minimap', on ? '1' : '0'); drawMap(); };
    $('minimap-toggle').addEventListener('click', () => showMap(map.classList.contains('hidden')));
    showMap(remembered('chatbot.minimap') !== '0');
    window.addEventListener('resize', drawMap);

    // ---- add a step ----
    const DEFAULTS = {
        condition: {rows: [{variable: 'last_input', rule: 'contains', value: ''}]}, send: {items: [{type: 'text', value: ''}], wait: false, save_to: ''},
        set: {ops: [{op: 'set', field: '', value: '', separator: ', '}]}, ai: {model: 'gpt-4o-mini', system: '', user: OPEN + 'last_input' + CLOSE, history: 100, max_tokens: 250, save_to: '', send: false},
        clear_ai: {fields: []}, sheet: {sheet: '', tab: '', columns: []},
        encode_order: {date: '', page: '', name: '', phone: '', input: '', shop: '', extracted: '', price: '', also_sheet: false, sheet: '', tab: '', on_failure: 'sheet'}, sequence: {action: 'subscribe', sequence_id: 0}, end: {},
    };
    document.querySelectorAll('[data-add]').forEach(b => b.addEventListener('click', () => {
        const next = 1 + Math.max(0, ...graph.nodes.map(n => parseInt(n.id.slice(1), 10) || 0));
        const r = cv.getBoundingClientRect(), c = toWorld(r.left + r.width / 2, r.top + r.height / 3);
        const n = {id: 'n' + next, type: b.dataset.add, x: Math.round(c.x - W / 2), y: Math.round(c.y), config: JSON.parse(JSON.stringify(DEFAULTS[b.dataset.add]))};
        if (n.type === 'encode_order') prefillEncode(n.config);
        graph.nodes.push(n); render(); changed(); openForm(n);
    }));

    // ---- the form of a step ----
    let editing = null;
    const input = (attrs, value) => { const i = el(attrs.tag || 'input', Object.assign({class: 'w-full rounded-md border border-slate-300 px-2 py-1 text-sm'}, attrs, {tag: null})); i.value = value ?? ''; i.addEventListener('focus', () => lastField = i); return i; };
    // Textarea na lumalaki ayon sa dami ng linya (2 hanggang 8).
    const grow = t => { const fit = () => t.rows = Math.min(8, Math.max(2, t.value.split('\n').length)); fit(); t.addEventListener('input', fit); return t; };
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
            // Ang AI-output variable: ang save_to ng mga AI step; ang mensaheng may ganito ay sariling sagot ng AI ang naaalala nito.
            const aiOutputIn = text => { const outs = graph.nodes.filter(x => x.type === 'ai' && x.config?.save_to).map(x => x.config.save_to.toLowerCase().replace(/[\s_]+/g, ' ').trim()); return [...String(text || '').matchAll(/\{\{\s*([^{}]{1,100}?)\s*\}\}/g)].some(m => outs.includes(m[1].toLowerCase().replace(/[\s_]+/g, ' ').trim())); };
            const make = it => {
                const note = el('p', {class: 'text-xs text-slate-500 sm:col-span-2', text: 'The AI remembers only its own answer here'});
                const box = el('label', {class: 'flex items-center gap-2 text-xs text-slate-600 sm:col-span-2'}, [bind(el('input', {type: 'checkbox', checked: it.ai === true}), v => { if (v) it.ai = true; else delete it.ai; }, true), 'Show this message to the AI']);
                const sync = () => { const own = aiOutputIn(it.value); note.classList.toggle('hidden', !own); box.classList.toggle('hidden', own); };
                sync();
                return el('div', {class: 'grid gap-2 sm:grid-cols-[8rem_1fr]'}, [
                    label('Type', bind(el('select', {class: 'w-full rounded-md border border-slate-300 px-2 py-1 text-sm'}, [el('option', {value: 'text', text: 'Text', selected: it.type !== 'image'}), el('option', {value: 'image', text: 'Image link', selected: it.type === 'image'})]), v => it.type = v)),
                    label('Text or image link (variables allowed)', bind(input({tag: 'textarea', rows: 3, maxlength: 2000}, it.value), v => { it.value = v; sync(); })),
                    note, box,
                ]);
            };
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
                label('Value', bind(grow(input({tag: 'textarea', rows: 2, maxlength: 2000}, o.value)), v => o.value = v)),
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
        if (n.type === 'clear_ai') {
            body.append(el('p', {class: 'text-slate-600', text: 'The AI forgets this customer\'s conversation so far: AI steps and ' + OPEN + 'ALL USER INPUT' + CLOSE + ' only see messages after this step. Nothing is deleted from the Inbox or Chats.'}));
            const make = (f, i) => label('Customer field', bind(input({list: listId, maxlength: 100, placeholder: 'e.g. GPT INPUT'}, f), v => c.fields[i] = v));
            make.blank = () => '';
            body.append(el('p', {class: 'text-xs text-slate-500', text: 'Also clear these fields (optional):'}), rowsEditor(c.fields = c.fields || [], make, 20, '+ Field'));
        }
        if (n.type === 'sheet') sheetForm(c, body);
        if (n.type === 'encode_order') encodeForm(c, body);
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
        // Results: ang order ay binibilang kapag dumaan ang takbo sa step na ito (isa kada customer kada 24 oras).
        if (['send', 'set', 'sheet', 'encode_order'].includes(n.type)) {
            body.append(el('div', {class: 'border-t border-slate-200 pt-2 text-sm'}, [
                el('label', {class: 'flex items-center gap-2'}, [bind(el('input', {type: 'checkbox', checked: !!c.counts_order}), v => c.counts_order = v, true), 'Counts as an order']),
                el('p', {class: 'mt-0.5 text-xs text-slate-500', text: 'When a customer passes this step, Chatbot → Results counts one order (once per customer per 24 hours).'})]));
        }
        $('vars-search').value = '';
        drawVars();
        $('form-box').classList.remove('hidden');
        body.querySelector('input, textarea, select')?.focus();
    }
    // ---- ang listahan ng variable: System, Botfields, Customer fields; may kulay at tooltip kung saan galing, "!" kapag
    // nasa dalawang grupo ang pangalan, at ang Seq / Seq Img ay nakatiklop sa "Sequences" ----
    const VAR_GROUPS = [
        ['system', 'System', 'bg-slate-100 text-slate-700 hover:bg-slate-200'],
        ['botfield', 'Botfields · per page', 'bg-purple-100 text-purple-800 hover:bg-purple-200'],
        ['customer', 'Customer fields · per customer', 'bg-teal-100 text-teal-800 hover:bg-teal-200'],
    ];
    const SEQ_VAR = /^Seq (Img )?\d+$/;
    let seqOpen = false;
    function putVar(v) {
        if (!lastField) return;
        const s = lastField.selectionStart ?? lastField.value.length;
        lastField.value = lastField.value.slice(0, s) + OPEN + v + CLOSE + lastField.value.slice(s); lastField.dispatchEvent(new Event('input')); lastField.focus();
    }
    function drawVars() {
        const q = $('vars-search').value.trim().toLowerCase();
        const chip = (v, cls) => {
            const b = el('button', {type: 'button', class: 'rounded px-1.5 py-0.5 font-mono text-[11px] ' + cls, title: v.tip, 'data-var': v.name},
                [OPEN + v.name + CLOSE, v.also.length ? el('span', {class: 'ml-0.5 font-sans font-bold text-amber-600', text: '!'}) : null]);
            b.addEventListener('click', () => putVar(v.name));
            return b;
        };
        const boxes = VAR_GROUPS.map(([g, title, cls]) => {
            const list = variableGroups.filter(v => v.group === g && (!q || v.name.toLowerCase().includes(q)));
            if (!list.length) return null;
            // Habang naghahanap, kita agad ang tugmang Seq; kung hindi, nakatiklop hanggang i-click.
            const seq = q ? [] : list.filter(v => g === 'botfield' && SEQ_VAR.test(v.name));
            const row = el('div', {class: 'flex flex-wrap gap-1'}, list.filter(v => !seq.includes(v)).map(v => chip(v, cls)));
            if (seq.length) {
                const t = el('button', {type: 'button', 'data-seq-toggle': true, 'aria-expanded': String(seqOpen), class: 'rounded border border-purple-200 px-1.5 py-0.5 text-[11px] text-purple-800 hover:bg-purple-50',
                    text: (seqOpen ? '▾ ' : '▸ ') + 'Sequences (' + seq.length + ')'});
                t.addEventListener('click', () => { seqOpen = !seqOpen; drawVars(); $('vars').querySelector('[data-seq-toggle]')?.focus(); });
                row.append(t, ...(seqOpen ? seq.map(v => chip(v, cls)) : []));
            }
            return el('div', {}, [el('div', {class: 'mb-0.5 text-[11px] font-semibold uppercase tracking-wide text-slate-500', text: title + ' (' + list.length + ')'}), row]);
        }).filter(Boolean);
        $('vars').replaceChildren(...(boxes.length ? boxes : [el('p', {class: 'text-xs text-slate-500', text: 'No variable matches.'})]));
    }
    $('vars-search').addEventListener('input', drawVars);
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
            // Gaya ng takbo at ng server, ang tab ay tinatanggalan ng espasyo sa dulo bago hanapin.
            const tab = (c.tab || '').trim(), names = info.tabs.map(t => t.title), missing = !!tab && !names.includes(tab);
            const opts = (tab ? [] : [['', '— Pick a tab —']]).concat(missing ? [[tab, tab + ' (missing)']] : [], names.map(t => [t, t]));
            const sel = el('select', {class: 'w-full rounded-md border px-2 py-1 text-sm ' + (missing ? 'border-red-400 text-red-700' : 'border-slate-300')},
                opts.map(([v, t]) => el('option', {value: v, text: t, selected: v === tab})));
            sel.addEventListener('change', () => { c.tab = sel.value; cells = null; load(false); }); // change lang: isang basa kada pili
            tabBox.replaceChildren(...[label('Tab', sel), missing ? el('p', {class: 'mt-0.5 text-xs text-red-700', text: 'The tab "' + tab + '" is missing from this sheet. Pick another tab.'}) : null].filter(Boolean));
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
                el('table', {class: 'w-full table-fixed text-sm'}, [el('thead', {}, head), el('tbody', {}, trs)]), add,
                headers.length > MAX_COLUMNS ? el('p', {class: 'text-xs text-slate-500', text: 'This tab has ' + headers.length + ' headers; a step writes at most ' + MAX_COLUMNS + ' columns (A to ' + colLetter(MAX_COLUMNS - 1) + ').'}) : null].filter(Boolean));
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
    // ---- the Encode order step: ang mga cell A..H ng order (parehong variable chips ng Sheet step), ang kasabay na sulat sa sheet at ang gagawin kapag pumalya ----
    const ENCODE_FIELDS = [['date', 'Date (A)'], ['page', 'Page (B)'], ['name', 'Name: FB name (C)'], ['phone', 'Phone (D)'], ['input', 'All user input (E)'],
        ['shop', 'Shop details (F)'], ['extracted', 'Extracted details (G)'], ['price', 'Price (H)']];
    const SHEET_OK = s => /^[a-zA-Z0-9_-]{20,}$/.test((s || '').trim()) || /\/spreadsheets\/d\/[a-zA-Z0-9_-]{20,}/.test(s || '');
    // Ang bagong step ay may laman na ang mga variable ng Sheet step ng "All Orders" ng flow na ito (A..H), kung may ganoon; kung wala, blangko.
    function prefillEncode(c) {
        const s = graph.nodes.find(x => x.type === 'sheet' && ((x.config || {}).tab || '').trim().toLowerCase() === 'all orders');
        if (!s) return;
        const cols = Array.isArray(s.config.columns) ? s.config.columns : [];
        ENCODE_FIELDS.forEach(([k], i) => c[k] = cols[i] || '');
        c.sheet = s.config.sheet || ''; c.tab = s.config.tab || '';
        if (s.config.counts_order) c.counts_order = true; // Results ay patuloy na nagbibilang kapag ito ang pumalit sa Sheet step
    }
    function encodeForm(c, body) {
        body.append(el('p', {class: 'rounded-md bg-cyan-50 px-2 py-1 text-xs text-cyan-900', text: 'Encodes and checks the order in Likha and saves it to the orders (macro output). Replaces the Google Sheet step.'}));
        body.append(el('p', {class: 'text-xs text-slate-500', text: 'The order (A to H of the row the Google Sheet step would write). The flow goes on at once; the encode runs in the background.'}));
        body.append(el('div', {class: 'grid gap-2 sm:grid-cols-2'}, ENCODE_FIELDS.map(([k, t]) => label(t, bind(grow(input({tag: 'textarea', rows: 2, maxlength: 2000}, c[k])), v => c[k] = v)))));
        const m = JSON.parse(ed.dataset.encodeModels || '{}');
        body.append(el('div', {class: 'rounded-md border border-slate-200 px-2 py-1 text-xs text-slate-600'}, [
            el('div', {text: 'Models in use (read only): extract ' + (m.extract || '?') + ', checker ' + (m.checker || '?')}),
            el('div', {}, ['Set in app_settings (order_encode_model_extract, order_encode_model_checker). Watch the results on ', el('a', {href: ed.dataset.encodeUrl, class: 'text-blue-700 underline', target: '_blank', rel: 'noopener', text: 'Order encode'}), '.'])]));
        const sheetBox = el('div', {class: 'space-y-1'}), hint = el('p', {class: 'text-xs text-red-700', role: 'alert'});
        const need = () => c.on_failure !== 'continue' || !!c.also_sheet;
        const drawHint = () => { hint.textContent = need() && !SHEET_OK(c.sheet) ? 'A Google Sheet link is needed here, or the flow cannot be published. Pick "Continue without saving" and leave "Also write" off to go without a sheet.' : ''; };
        const drawSheet = () => {
            sheetBox.replaceChildren(...(need() ? [
                label('Google Sheet link or ID', bind(input({maxlength: 300, placeholder: 'https://docs.google.com/spreadsheets/d/…'}, c.sheet), v => { c.sheet = v; drawHint(); })),
                label('Tab', bind(input({maxlength: 100, placeholder: 'All Orders'}, c.tab), v => c.tab = v)),
            ] : []));
            drawHint();
        };
        body.append(el('label', {class: 'flex items-center gap-2 text-sm'}, [bind(el('input', {type: 'checkbox', checked: !!c.also_sheet}), v => { c.also_sheet = v; drawSheet(); }, true), 'Also write to Google Sheet (while changing over)']));
        body.append(label('If the encode fails', bind(el('select', {class: 'w-full rounded-md border border-slate-300 px-2 py-1 text-sm'},
            [['sheet', 'Write to Google Sheet instead'], ['continue', 'Continue without saving (shows a warning)']].map(([k, t]) => el('option', {value: k, text: t, selected: (c.on_failure || 'sheet') === k}))), v => { c.on_failure = v; drawSheet(); })));
        body.append(sheetBox, hint);
        drawSheet();
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
        graph = data.graph; variables = data.variables || []; variableGroups = data.variable_groups || []; rules = data.rules || {}; sequences = data.sequences || [];
        graph.nodes.forEach(n => n.config = n.config && !Array.isArray(n.config) ? n.config : {});
        current = snapshot(); setState(data);
        applyView(); render(); fit();
    }).catch(() => status('Could not load the flow.', 'text-red-600'));
})();
</script>
@endsection

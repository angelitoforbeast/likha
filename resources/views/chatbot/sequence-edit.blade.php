@extends('layouts.chatblast')

@section('title', 'Chatbot · Sequence · ' . $sequence->name)
@section('heading', $sequence->name)

@section('description')
    Each step runs its flow's published version from Start, then waits the next step's delay. Times are Manila time.
@endsection

@section('actions')
    <a href="{{ route('chatbot.sequences.index') }}" class="text-blue-700 hover:underline">← All sequences</a>
    <a href="{{ route('chatbot.sequences.subscribers', $sequence->id) }}" class="ml-3 text-blue-700 hover:underline">Subscribers</a>
@endsection

@section('content')
@include('chatbot.partials.switch', ['repliesOn' => $repliesOn])

<form id="sq" class="space-y-4 text-sm" data-url="{{ route('chatbot.sequences.update', $sequence->id) }}" novalidate>
    <section class="cb-card grid gap-3 p-4 sm:grid-cols-2">
        <label class="flex flex-col gap-1 text-xs text-slate-500">Name
            <input name="name" maxlength="120" required value="{{ $sequence->name }}" class="rounded-md border border-slate-300 px-2 py-1 text-sm text-gray-900">
        </label>
        <label class="flex flex-col gap-1 text-xs text-slate-500">Description
            <input name="description" maxlength="1000" value="{{ $sequence->description }}" class="rounded-md border border-slate-300 px-2 py-1 text-sm text-gray-900">
        </label>
        <div class="flex flex-col gap-2">
            <label class="flex items-center gap-2"><input type="checkbox" name="active" @checked($sequence->active)> Active (sends steps)</label>
            <label class="flex items-center gap-2"><input type="checkbox" name="exit_on_reply" @checked($sequence->exit_on_reply)> Exit when the customer replies</label>
        </div>
        <div class="flex flex-wrap items-end gap-3">
            <label class="flex flex-col gap-1 text-xs text-slate-500">Send from
                <input type="time" name="send_from" required value="{{ $sequence->send_from }}" class="rounded-md border border-slate-300 px-2 py-1 text-sm text-gray-900">
            </label>
            <label class="flex flex-col gap-1 text-xs text-slate-500">Send until
                <input type="time" name="send_to" required value="{{ $sequence->send_to }}" class="rounded-md border border-slate-300 px-2 py-1 text-sm text-gray-900">
            </label>
            <label class="flex flex-col gap-1 text-xs text-slate-500">Outside the 24-hour window
                <select name="outside_window" class="rounded-md border border-slate-300 px-2 py-1 text-sm text-gray-900">
                    <option value="skip" @selected($sequence->outside_window === 'skip')>Skip the step and continue</option>
                    <option value="stop" @selected($sequence->outside_window === 'stop')>Stop the sequence</option>
                </select>
            </label>
        </div>
    </section>

    <section class="cb-card p-4" aria-labelledby="sq-steps-title">
        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
            <h2 id="sq-steps-title" class="font-semibold">Steps <span id="sq-count" class="text-xs font-normal text-slate-500"></span></h2>
            <button type="button" id="sq-add" class="cb-btn cb-btn-sm">+ Add step</button>
        </div>
        <ol id="sq-steps" class="space-y-2"></ol>
        <p id="sq-empty" class="hidden text-slate-500">No steps yet. Add one: it waits its delay, then runs the flow you pick.</p>
    </section>

    <div class="flex flex-wrap items-center gap-3">
        <button id="sq-save" class="cb-btn cb-btn-primary">Save</button>
        <span id="sq-note" class="text-xs" aria-live="polite"></span>
    </div>
</form>

<script>
(function () {
    const form = document.getElementById('sq'), $ = id => document.getElementById(id);
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const FLOWS = @json($flows), MAX = {{ \App\Models\ChatbotSequence::MAX_STEPS }};
    let steps = @json($steps), dirty = false;

    function el(tag, attrs = {}, children = []) {
        const e = document.createElement(tag);
        for (const [k, v] of Object.entries(attrs)) { if (v == null || v === false) continue; if (k === 'class') e.className = v; else if (k === 'text') e.textContent = v; else e.setAttribute(k, v === true ? '' : v); }
        for (const c of [].concat(children)) if (c != null) e.append(c);
        return e;
    }
    const UNIT = {minutes: 'minute', hours: 'hour', days: 'day'};
    const delayText = (s, i) => {
        const from = i === 0 ? 'after subscribe' : 'after the previous step';
        return +s.delay_amount === 0 ? 'Right ' + from + ' (inside the send hours)' : s.delay_amount + ' ' + UNIT[s.delay_unit] + (+s.delay_amount === 1 ? '' : 's') + ' ' + from;
    };
    const touch = () => { dirty = true; note('Unsaved changes.', 'text-amber-700'); };

    function render() {
        $('sq-count').textContent = '(' + steps.length + ' of ' + MAX + ')';
        $('sq-empty').classList.toggle('hidden', steps.length > 0);
        $('sq-add').disabled = steps.length >= MAX;
        $('sq-steps').replaceChildren(...steps.map((s, i) => {
            const amount = el('input', {type: 'number', min: 0, max: 999, value: s.delay_amount, 'aria-label': 'Delay of step ' + (i + 1), class: 'w-20 rounded-md border border-slate-300 px-2 py-1'});
            const unit = el('select', {'aria-label': 'Unit of step ' + (i + 1), class: 'rounded-md border border-slate-300 px-2 py-1'},
                Object.keys(UNIT).map(u => el('option', {value: u, text: u, selected: s.delay_unit === u})));
            const flow = el('select', {'aria-label': 'Flow of step ' + (i + 1), class: 'w-full min-w-0 rounded-md border border-slate-300 px-2 py-1 sm:w-auto sm:flex-1'},
                [el('option', {value: '', text: '— Pick a flow —'})].concat(FLOWS.map(f => el('option', {value: f.id, text: f.name + (f.published ? '' : ' (not published)'), selected: f.id === s.flow_id}))));
            const active = el('input', {type: 'checkbox', checked: !!s.active, 'aria-label': 'Step ' + (i + 1) + ' active'});
            const counted = el('div', {class: 'text-xs text-slate-500', text: delayText(s, i)});
            amount.addEventListener('input', () => { s.delay_amount = amount.value === '' ? '' : parseInt(amount.value, 10); counted.textContent = delayText(s, i); touch(); });
            unit.addEventListener('change', () => { s.delay_unit = unit.value; counted.textContent = delayText(s, i); touch(); });
            flow.addEventListener('change', () => { s.flow_id = flow.value ? parseInt(flow.value, 10) : null; touch(); });
            active.addEventListener('change', () => { s.active = active.checked; touch(); });
            const btn = (text, label, fn, disabled) => { const b = el('button', {type: 'button', class: 'cb-btn cb-btn-sm', text, 'aria-label': label, disabled}); b.addEventListener('click', fn); return b; };
            const move = d => { const j = i + d; [steps[i], steps[j]] = [steps[j], steps[i]]; render(); touch(); };
            const missing = s.flow_id && !FLOWS.some(f => f.id === s.flow_id);
            return el('li', {class: 'rounded-md border border-slate-200 p-2' + (s.active ? '' : ' bg-slate-50 opacity-75')}, [
                el('div', {class: 'flex flex-wrap items-center gap-2'}, [
                    el('span', {class: 'w-14 font-semibold', text: 'Step ' + (i + 1)}), amount, unit, flow,
                    el('label', {class: 'flex items-center gap-1 text-xs'}, [active, 'Active']),
                    btn('↑', 'Move step ' + (i + 1) + ' up', () => move(-1), i === 0),
                    btn('↓', 'Move step ' + (i + 1) + ' down', () => move(1), i === steps.length - 1),
                    btn('✕', 'Delete step ' + (i + 1), () => { if (confirm('Delete step ' + (i + 1) + '?')) { steps.splice(i, 1); render(); touch(); } }, false),
                ]),
                counted, missing ? el('div', {class: 'text-xs text-red-700', text: 'Its flow was deleted: pick another.'}) : null,
            ]);
        }));
    }
    function note(t, cls) { $('sq-note').className = 'text-xs ' + cls; $('sq-note').textContent = t; }

    $('sq-add').addEventListener('click', () => {
        if (steps.length >= MAX) return;
        steps.push({delay_amount: steps.length ? 1 : 0, delay_unit: steps.length ? 'days' : 'minutes', flow_id: null, active: true});
        render(); touch();
        $('sq-steps').lastElementChild?.querySelector('select[aria-label^="Flow"]')?.focus();
    });
    form.addEventListener('input', e => { if (!e.target.closest('#sq-steps')) touch(); });
    form.addEventListener('submit', async e => {
        e.preventDefault();
        if (steps.some(s => !s.flow_id)) return note('Pick a flow for every step.', 'text-red-700');
        const f = new FormData(form);
        const body = {name: f.get('name'), description: f.get('description'), active: form.active.checked, exit_on_reply: form.exit_on_reply.checked,
            send_from: f.get('send_from'), send_to: f.get('send_to'), outside_window: f.get('outside_window'),
            steps: steps.map(s => ({delay_amount: s.delay_amount === '' ? null : s.delay_amount, delay_unit: s.delay_unit, flow_id: s.flow_id, active: !!s.active}))};
        $('sq-save').disabled = true; note('Saving…', 'text-slate-500');
        const res = await fetch(form.dataset.url, {method: 'PUT', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf}, body: JSON.stringify(body)});
        const data = await res.json().catch(() => ({}));
        $('sq-save').disabled = false;
        if (!res.ok) {
            const first = data.errors ? Object.entries(data.errors)[0] : null;
            const where = first && first[0].startsWith('steps.') ? 'Step ' + (parseInt(first[0].split('.')[1], 10) + 1) + ': ' : '';
            return note(res.status === 429 ? 'Too many saves. Wait a minute.' : where + (first ? first[1][0] : (data.message || 'Not saved.')), 'text-red-700');
        }
        dirty = false; note('Saved ' + data.saved_at + '.', 'text-green-700');
        document.querySelector('h1')?.replaceChildren(body.name.trim());
    });
    window.addEventListener('beforeunload', e => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
    render();
})();
</script>
@endsection

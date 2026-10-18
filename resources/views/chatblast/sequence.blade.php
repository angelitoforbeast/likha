<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Chatblast · Sequences</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 text-gray-900">
<div class="p-4 sm:p-6" id="cs"
     data-data-url="{{ route('chatblast.sequence.data') }}"
     data-base-url="{{ url('/chatblast/sequence') }}"
     data-messages-url="{{ route('chatblast.campaigns.index') }}">

    <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
        <h1 class="text-2xl font-semibold">Sequences</h1>
        <div class="flex gap-4 text-sm">
            <a href="{{ route('chatblast.campaigns.index') }}" class="text-blue-700 hover:underline">‹ Messages</a>
            <a href="{{ route('chatblast.index') }}" class="text-blue-700 hover:underline">Chatblast (sending)</a>
            <a href="{{ url('/') }}" class="text-gray-600 hover:underline">Home</a>
        </div>
    </div>
    <p class="text-xs text-gray-500 mb-3 max-w-4xl">
        Like sequences in Botcake: each sequence has a trigger (J&T status, pages, window), steps (Day 1 → Day 2 → …; each step's message
        comes from <a href="{{ route('chatblast.campaigns.index') }}" class="text-blue-700 hover:underline">Messages</a>), and a schedule. One message per
        waybill per day. Every field saves at once (when you leave the box, press Enter, or pick an option).
    </p>

    <p id="notice" class="text-xs mb-2 min-h-[1rem]" aria-live="polite"></p>
    <div id="overlaps" class="hidden mb-3 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-900" role="status"></div>

    <details id="new-box" class="mb-4 bg-white rounded shadow px-4 py-2 text-sm">
        <summary class="cursor-pointer font-medium">+ New sequence</summary>
        <form id="new-form" class="grid md:grid-cols-2 gap-3 mt-2">
            <label class="flex flex-col text-xs text-gray-600">Name<input name="name" maxlength="100" placeholder="e.g. Problematic" class="border rounded px-2 py-1 text-sm text-gray-900"></label>
            <label class="flex flex-col text-xs text-gray-600">Window (how many days back the order is)<input name="window_days" type="number" min="1" max="60" value="7" class="border rounded px-2 py-1 text-sm text-gray-900 w-28"></label>
            <fieldset class="text-xs text-gray-600"><legend>Trigger: J&T status (at least one)</legend><div id="new-statuses" class="flex flex-wrap gap-3 mt-1 text-sm text-gray-900"></div></fieldset>
            <label class="flex flex-col text-xs text-gray-600">Day 1 message<select name="message_id" class="border rounded px-2 py-1 text-sm text-gray-900"></select></label>
            <div class="md:col-span-2"><button class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded">Create (manual, on)</button></div>
        </form>
    </details>

    <div id="blocks" class="space-y-4"></div>
</div>

<script>
(() => {
    const url = document.getElementById('cs').dataset;
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const DAYS = [[1, 'Mon'], [2, 'Tue'], [3, 'Wed'], [4, 'Thu'], [5, 'Fri'], [6, 'Sat'], [7, 'Sun']];
    let data = {campaigns: [], messages: [], statuses: [], pages: [], overlaps: []};

    function el(tag, attrs = {}, children = []) {
        const e = document.createElement(tag);
        for (const [k, v] of Object.entries(attrs)) {
            if (v == null || v === false) continue;
            if (k === 'class') e.className = v; else if (k === 'text') e.textContent = v; else e.setAttribute(k, v);
        }
        for (const c of [].concat(children)) if (c != null) e.append(c);
        return e;
    }
    const fmt = n => Number(n || 0).toLocaleString('en-US');
    function notice(text, cls = 'text-gray-600') {
        const n = document.getElementById('notice');
        n.className = 'text-xs mb-2 min-h-[1rem] ' + cls;
        n.textContent = text;
    }
    async function call(method, u, body = {}) {
        const res = await fetch(u, {method, headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf}, body: JSON.stringify(body)});
        const d = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(d.error || d.message || ('HTTP ' + res.status));
        return d;
    }
    const post = (u, body) => call('POST', u, body);
    const clock = t => { const [h, m] = t.split(':').map(Number); return ((h % 12) || 12) + ':' + String(m).padStart(2, '0') + (h < 12 ? ' AM' : ' PM'); };

    async function load() {
        try {
            data = await (await fetch(url.dataUrl, {headers: {'Accept': 'application/json'}})).json();
            draw();
        } catch (e) { notice('Could not load: ' + e.message, 'text-red-600'); }
    }

    const mark = () => el('span', {class: 'text-xs ml-1', 'aria-live': 'polite'});
    const setMark = (m, state, text) => { m.className = 'text-xs ml-1 ' + ({saving: 'text-gray-500', ok: 'text-green-700', err: 'text-red-600'}[state]); m.textContent = text; };

    // Isang field: ipadala ang body, ipakita ang marka; reload pagkatapos (para sa buod at susunod na takbo).
    async function saveField(m, u, body, reload = true) {
        setMark(m, 'saving', 'Saving…');
        try { await post(u, body); setMark(m, 'ok', 'Saved'); if (reload) setTimeout(load, 400); return true; }
        catch (e) { setMark(m, 'err', e.message); return false; }
    }

    function checks(options, chosen, onChange) {
        const box = el('div', {class: 'flex flex-wrap gap-x-3 gap-y-1 text-sm'});
        for (const o of [...new Set([...options, ...(chosen || [])])]) {
            const i = el('input', {type: 'checkbox', value: o});
            i.checked = (chosen || []).includes(o);
            i.addEventListener('change', () => onChange([...box.querySelectorAll('input:checked')].map(x => x.value)));
            box.append(el('label', {class: 'flex items-center gap-1'}, [i, o]));
        }
        return box;
    }

    function chips(get, onSave, emptyText) {
        const times = get();
        const box = el('span', {class: 'inline-flex flex-wrap items-center gap-1'});
        for (const t of times) {
            const x = el('button', {type: 'button', class: 'ml-1 text-gray-500 hover:text-red-600', 'aria-label': 'Remove ' + t, text: '×'});
            x.addEventListener('click', () => onSave(get().filter(y => y !== t)));
            box.append(el('span', {class: 'px-2 py-0.5 rounded bg-blue-100 text-blue-800 text-xs'}, [clock(t), x]));
        }
        if (!times.length) box.append(el('span', {class: 'text-xs text-gray-400', text: emptyText}));
        const add = el('input', {type: 'time', class: 'border rounded px-1 py-0.5 text-xs', 'aria-label': 'New time'});
        const ok = el('button', {type: 'button', class: 'text-xs text-blue-700 hover:underline', text: '+ time'});
        ok.addEventListener('click', () => { if (add.value && !get().includes(add.value)) onSave([...get(), add.value]); });
        box.append(add, ok);
        return box;
    }

    function draw() {
        const ov = document.getElementById('overlaps');
        ov.classList.toggle('hidden', !(data.overlaps || []).length);
        ov.replaceChildren(el('p', {class: 'font-medium', text: 'Overlapping sequences: a waybill gets only one message per day for the same status, so one of them skips what the other already sent.'}),
            el('ul', {class: 'list-disc ml-4'}, (data.overlaps || []).map(x => el('li', {text: x}))));

        const nf = document.getElementById('new-form');
        document.getElementById('new-statuses').replaceChildren(...data.statuses.map(s => el('label', {class: 'flex items-center gap-1'}, [el('input', {type: 'checkbox', name: 'statuses', value: s}), s])));
        nf.message_id.replaceChildren(...data.messages.map(m => el('option', {value: String(m.id), text: m.name})));

        document.getElementById('blocks').replaceChildren(...data.campaigns.map(block));
    }

    function block(c) {
        const base = url.baseUrl + '/' + c.id;
        // Ang listahang field (oras, araw, status, page, bawat oras): kasama ang nakita ng page (old); itinatala agad ang
        // bago para ang susunod na pindot ay mula rito. Kapag tinanggihan (nabago na ng iba), ibinabalik at nire-reload.
        const saveList = async (m, field, value) => {
            const old = c[field];
            c[field] = value;
            if (!await saveField(m, base, {[field]: value, old: {[field]: old}})) c[field] = old; // ang marka ang nagsasabing i-reload
        };
        const row = (labelText, ...content) => el('div', {class: 'flex flex-wrap items-center gap-2 py-1'}, [el('span', {class: 'text-xs text-gray-500 w-36', text: labelText}), ...content]);

        // Pangalan
        const name = el('input', {value: c.name, maxlength: '100', class: 'font-semibold border rounded px-2 py-1 w-64', 'aria-label': 'Sequence name'});
        const nm = mark();
        name.addEventListener('change', () => saveField(nm, base, {name: name.value}));

        // On/off at mode
        const on = el('input', {type: 'checkbox', 'aria-label': 'On/off'});
        on.checked = c.active;
        const onM = mark();
        on.addEventListener('change', () => {
            if (!on.checked && !confirm('Turn off "' + c.name + '"? Fetching and sending stop at once, including what is queued.')) { on.checked = true; return; }
            saveField(onM, base, {active: on.checked});
        });
        const modeM = mark();
        const mode = el('select', {class: 'border rounded px-2 py-0.5 text-sm', 'aria-label': 'Mode'}, [el('option', {value: 'manual', text: 'manual'}), el('option', {value: 'auto', text: 'auto'})]);
        mode.value = c.mode;
        mode.addEventListener('change', () => {
            if (mode.value === 'auto' && !confirm('Switch "' + c.name + '" to AUTO? It will fetch and send to customers by itself on this schedule: ' + c.summary + '.\n\n'
                + fmt(c.ready) + ' are ready now: auto will NOT send them. Only new ones from now on (today) are sent; send the backlog from /chatblast if you want.')) { mode.value = c.mode; return; }
            saveField(modeM, base, mode.value === 'auto' ? {mode: 'auto', confirm: true} : {mode: 'manual'});
        });
        const del = el('button', {type: 'button', class: 'text-xs text-red-600 hover:underline', text: 'remove sequence'});
        del.addEventListener('click', async () => {
            if (!confirm('Remove "' + c.name + '"?')) return;
            try { await call('DELETE', base); notice('Removed.', 'text-green-700'); } catch (e) { notice(e.message, 'text-red-600'); } load();
        });

        // Trigger
        const stM = mark(), pgM = mark(), wM = mark(), odM = mark();
        const pageSearch = el('input', {placeholder: 'search a page', class: 'border rounded px-2 py-0.5 text-xs'});
        const pages = checks(data.pages, c.pages || [], v => saveList(pgM, 'pages', v));
        pageSearch.addEventListener('input', () => { for (const l of pages.children) l.classList.toggle('hidden', !l.textContent.toLowerCase().includes(pageSearch.value.toLowerCase())); });
        const win = el('input', {type: 'number', min: '1', max: '60', value: String(c.window_days), class: 'border rounded px-2 py-0.5 text-sm w-20', 'aria-label': 'Window'});
        win.addEventListener('change', () => saveField(wM, base, {window_days: win.value}));
        const od = el('input', {type: 'checkbox', 'aria-label': 'Requires On Delivery'});
        od.checked = c.requires_on_delivery;
        od.addEventListener('change', () => saveField(odM, base, {requires_on_delivery: od.checked}));

        // Schedule
        const tM = mark(), wdM = mark(), capM = mark(), rM = mark();
        // Bawat oras: simula, dulo (kasama kapag tumapat) at bawat N oras; naka-save bilang range, hindi isa-isang oras.
        const r = c.send_range || {start: '09:00', end: '21:00', every: 1};
        const rOn = el('input', {type: 'checkbox', 'aria-label': 'Every N hours'});
        rOn.checked = !!c.send_range;
        const rStart = el('input', {type: 'time', value: r.start, class: 'border rounded px-1 py-0.5 text-xs', 'aria-label': 'Start'});
        const rEnd = el('input', {type: 'time', value: r.end, class: 'border rounded px-1 py-0.5 text-xs', 'aria-label': 'End'});
        const rEvery = el('input', {type: 'number', min: '1', max: '12', value: String(r.every), class: 'border rounded px-1 py-0.5 text-xs w-14', 'aria-label': 'Every how many hours'});
        const saveRange = () => saveList(rM, 'send_range', rOn.checked ? {start: rStart.value, end: rEnd.value, every: Number(rEvery.value)} : null);
        for (const i of [rOn, rStart, rEnd, rEvery]) i.addEventListener('change', saveRange);
        const range = el('span', {class: 'inline-flex flex-wrap items-center gap-1 text-xs'}, [el('label', {class: 'flex items-center gap-1'}, [rOn, 'Every']), rEvery, 'hour(s), from', rStart, 'to', rEnd]);
        const weekdays = el('span', {class: 'inline-flex gap-2 text-sm'}, DAYS.map(([n, lbl]) => {
            const i = el('input', {type: 'checkbox', value: String(n)});
            i.checked = !c.weekdays || c.weekdays.includes(n);
            i.addEventListener('change', () => saveList(wdM, 'weekdays', [...weekdays.querySelectorAll('input:checked')].map(x => Number(x.value))));
            return el('label', {class: 'flex items-center gap-1'}, [i, lbl]);
        }));
        const cap = el('input', {type: 'number', min: '1', value: c.daily_cap ?? '', placeholder: 'none', class: 'border rounded px-2 py-0.5 text-sm w-24', 'aria-label': 'Cap per page per day'});
        cap.addEventListener('change', () => saveField(capM, base, {daily_cap: cap.value === '' ? null : cap.value}));
        const greyed = c.mode !== 'auto' ? ' opacity-60' : '';

        // Mga step
        const steps = c.steps.map((s, i) => {
            const sm = mark();
            const pick = el('select', {class: 'border rounded px-2 py-0.5 text-sm max-w-xs', 'aria-label': 'Day ' + s.day + ' message'},
                data.messages.map(m => el('option', {value: String(m.id), text: m.name})));
            if (s.message_id) pick.value = String(s.message_id); else pick.prepend(el('option', {value: '', text: '(no message)', selected: 'selected'}));
            pick.addEventListener('change', () => saveField(sm, base + '/step-message', {day: s.day, message_id: Number(pick.value), expect: s.message_id, steps: c.steps.length}));
            const btn = (text, fn, cls = 'text-blue-700') => { const b = el('button', {type: 'button', class: 'text-xs hover:underline ml-2 ' + cls, text}); b.addEventListener('click', fn); return b; };
            const seen = {expect: s.message_id, steps: c.steps.length}; // para hindi mabago ang maling step kapag luma na ang page
            const act = (path, body, ok) => async () => { try { await post(base + path, {...body, ...seen}); notice(ok, 'text-green-700'); } catch (e) { notice(e.message, 'text-red-600'); } load(); };
            const t = s.today;
            return el('div', {class: 'border-l-4 border-blue-300 bg-gray-50 rounded px-3 py-2'}, [
                el('div', {class: 'flex flex-wrap items-center gap-2'}, [
                    el('span', {class: 'font-semibold text-sm', text: 'Day ' + s.day}), pick, sm,
                    el('span', {class: 'text-xs text-gray-500', text: 'today: ' + fmt(t.built) + ' built · ' + fmt(t.sent) + ' sent · ' + fmt(t.failed) + ' failed · ' + fmt(t.skipped) + ' skipped'}),
                    i > 0 ? btn('↑', act('/move', {day: s.day, dir: 'up'}, 'Moved.')) : null,
                    i < c.steps.length - 1 ? btn('↓', act('/move', {day: s.day, dir: 'down'}, 'Moved.')) : null,
                    btn('+ step after', act('/step', {after: s.day}, 'Step added (same message; pick another to change it).')),
                    c.steps.length > 1 ? btn('remove', () => { if (confirm('Remove Day ' + s.day + '? The later steps move up.')) act('/remove-step', {day: s.day}, 'Step removed.')(); }, 'text-red-600') : null,
                ]),
                el('div', {class: 'mt-1 space-y-0.5'}, s.versions.map((v, j) => el('p', {class: 'text-xs text-gray-700 whitespace-pre-wrap'}, [el('span', {class: 'font-medium mr-1', text: String.fromCharCode(65 + j) + ':'}), v]))),
                el('div', {class: 'mt-1 text-xs text-gray-600' + greyed}, ['Own times: ', chips(() => s.times || [], async v => { const old = s.times || []; s.times = v; if (!await saveField(sm, base + '/step-times', {day: s.day, times: v, old, expect: s.message_id, steps: c.steps.length})) s.times = old; }, 'the sequence times')]),
            ]);
        });

        return el('section', {class: 'bg-white rounded shadow p-4' + (c.active ? '' : ' opacity-75')}, [
            el('div', {class: 'flex flex-wrap items-center gap-2 mb-1'}, [el('span', {class: 'text-gray-500', text: 'Sequence for'}), name, nm,
                el('label', {class: 'flex items-center gap-1 text-sm ml-2'}, [on, 'on', onM]), el('label', {class: 'flex items-center gap-1 text-sm'}, ['mode', mode, modeM]), del]),
            el('p', {class: 'text-sm text-gray-700 mb-1' + greyed, text: c.summary + (c.next_run ? ' · next run: ' + c.next_run : (c.mode === 'auto' && c.active ? '' : ' · manual'))}),
            el('p', {class: 'text-xs text-gray-500 mb-2', text: 'Today: ' + fmt(c.today.built) + ' built · ' + fmt(c.today.sent) + ' sent · ' + fmt(c.today.failed) + ' failed · ' + fmt(c.today.skipped) + ' skipped'}),
            el('div', {class: 'grid lg:grid-cols-2 gap-4'}, [
                el('div', {}, [
                    el('h3', {class: 'text-sm font-semibold mb-1', text: 'Trigger'}),
                    row('J&T status', checks(data.statuses, c.statuses, v => saveList(stM, 'statuses', v)), stM),
                    row('Pages (none = all)', el('div', {}, [pageSearch, pages]), pgM),
                    row('Window (days)', win, wM),
                    row('Requires On Delivery', od, el('span', {class: 'text-xs text-gray-500', text: 'tracked before sending; On Delivery only; Delivering trigger only'}), odM),
                    el('h3', {class: 'text-sm font-semibold mt-3 mb-1', text: 'Schedule' + (c.mode === 'auto' ? '' : ' (manual: not followed)')}),
                    el('div', {class: greyed}, [
                        row('Send times', chips(() => c.send_times, v => saveList(tM, 'send_times', v), 'no times'), tM),
                        row('Every N hours', range, rM),
                        row('Days', weekdays, wdM),
                        row('Cap per page / day', cap, capM),
                    ]),
                ]),
                el('div', {}, [el('h3', {class: 'text-sm font-semibold mb-1', text: 'Steps'}), el('div', {class: 'space-y-2'}, steps)]),
            ]),
        ]);
    }

    document.getElementById('new-form').addEventListener('submit', async e => {
        e.preventDefault();
        const f = e.target;
        const statuses = [...f.querySelectorAll('input[name="statuses"]:checked')].map(i => i.value);
        try { await post(url.baseUrl, {name: f.name.value, window_days: f.window_days.value, statuses, message_id: Number(f.message_id.value)}); notice('Sequence created (manual).', 'text-green-700'); f.reset(); document.getElementById('new-box').open = false; }
        catch (err) { notice(err.message, 'text-red-600'); }
        load();
    });

    load();
})();
</script>
</body>
</html>

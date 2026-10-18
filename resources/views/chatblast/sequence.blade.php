<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Chatblast · Mga sequence</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 text-gray-900">
<div class="p-4 sm:p-6" id="cs"
     data-data-url="{{ route('chatblast.sequence.data') }}"
     data-base-url="{{ url('/chatblast/sequence') }}"
     data-messages-url="{{ route('chatblast.campaigns.index') }}">

    <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
        <h1 class="text-2xl font-semibold">Mga sequence</h1>
        <div class="flex gap-4 text-sm">
            <a href="{{ route('chatblast.campaigns.index') }}" class="text-blue-700 hover:underline">‹ Mga mensahe</a>
            <a href="{{ route('chatblast.index') }}" class="text-blue-700 hover:underline">Chatblast (pagpapadala)</a>
            <a href="{{ url('/') }}" class="text-gray-600 hover:underline">Home</a>
        </div>
    </div>
    <p class="text-xs text-gray-500 mb-3 max-w-4xl">
        Gaya ng sequence sa Botcake: bawat sequence ay may trigger (J&T status, mga page, window), mga step (Day 1 → Day 2 → …; ang mensahe ng bawat
        step ay mula sa <a href="{{ route('chatblast.campaigns.index') }}" class="text-blue-700 hover:underline">Mga mensahe</a>), at schedule. Isang mensahe kada
        waybill kada araw. Ang bawat field ay nase-save agad (pag-alis sa kahon, pag-Enter, o pagpili).
    </p>

    <p id="notice" class="text-xs mb-2 min-h-[1rem]" aria-live="polite"></p>
    <div id="overlaps" class="hidden mb-3 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-900" role="status"></div>

    <details id="new-box" class="mb-4 bg-white rounded shadow px-4 py-2 text-sm">
        <summary class="cursor-pointer font-medium">+ Bagong sequence</summary>
        <form id="new-form" class="grid md:grid-cols-2 gap-3 mt-2">
            <label class="flex flex-col text-xs text-gray-600">Pangalan<input name="name" maxlength="100" placeholder="hal. Problematic" class="border rounded px-2 py-1 text-sm text-gray-900"></label>
            <label class="flex flex-col text-xs text-gray-600">Window (ilang araw pabalik ang order)<input name="window_days" type="number" min="1" max="60" value="7" class="border rounded px-2 py-1 text-sm text-gray-900 w-28"></label>
            <fieldset class="text-xs text-gray-600"><legend>Trigger: J&T status (kahit isa)</legend><div id="new-statuses" class="flex flex-wrap gap-3 mt-1 text-sm text-gray-900"></div></fieldset>
            <label class="flex flex-col text-xs text-gray-600">Mensahe ng Day 1<select name="message_id" class="border rounded px-2 py-1 text-sm text-gray-900"></select></label>
            <div class="md:col-span-2"><button class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded">Gumawa (manual, naka-on)</button></div>
        </form>
    </details>

    <div id="blocks" class="space-y-4"></div>
</div>

<script>
(() => {
    const url = document.getElementById('cs').dataset;
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const DAYS = [[1, 'Lun'], [2, 'Mar'], [3, 'Miy'], [4, 'Huw'], [5, 'Biy'], [6, 'Sab'], [7, 'Lin']];
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
        } catch (e) { notice('Hindi ma-load: ' + e.message, 'text-red-600'); }
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

    function chips(times, onSave, emptyText) {
        const box = el('span', {class: 'inline-flex flex-wrap items-center gap-1'});
        for (const t of times) {
            const x = el('button', {type: 'button', class: 'ml-1 text-gray-500 hover:text-red-600', 'aria-label': 'Alisin ang ' + t, text: '×'});
            x.addEventListener('click', () => onSave(times.filter(y => y !== t)));
            box.append(el('span', {class: 'px-2 py-0.5 rounded bg-blue-100 text-blue-800 text-xs'}, [clock(t), x]));
        }
        if (!times.length) box.append(el('span', {class: 'text-xs text-gray-400', text: emptyText}));
        const add = el('input', {type: 'time', class: 'border rounded px-1 py-0.5 text-xs', 'aria-label': 'Bagong oras'});
        const ok = el('button', {type: 'button', class: 'text-xs text-blue-700 hover:underline', text: '+ oras'});
        ok.addEventListener('click', () => { if (add.value) onSave([...times, add.value]); });
        box.append(add, ok);
        return box;
    }

    function draw() {
        const ov = document.getElementById('overlaps');
        ov.classList.toggle('hidden', !(data.overlaps || []).length);
        ov.replaceChildren(el('p', {class: 'font-medium', text: 'Magkapatong na sequence: isang mensahe lang kada waybill kada araw para sa parehong status, kaya lalaktawan ng isa ang naipadala na ng kabila.'}),
            el('ul', {class: 'list-disc ml-4'}, (data.overlaps || []).map(x => el('li', {text: x}))));

        const nf = document.getElementById('new-form');
        document.getElementById('new-statuses').replaceChildren(...data.statuses.map(s => el('label', {class: 'flex items-center gap-1'}, [el('input', {type: 'checkbox', name: 'statuses', value: s}), s])));
        nf.message_id.replaceChildren(...data.messages.map(m => el('option', {value: String(m.id), text: m.name})));

        document.getElementById('blocks').replaceChildren(...data.campaigns.map(block));
    }

    function block(c) {
        const base = url.baseUrl + '/' + c.id;
        const row = (labelText, ...content) => el('div', {class: 'flex flex-wrap items-center gap-2 py-1'}, [el('span', {class: 'text-xs text-gray-500 w-36', text: labelText}), ...content]);

        // Pangalan
        const name = el('input', {value: c.name, maxlength: '100', class: 'font-semibold border rounded px-2 py-1 w-64', 'aria-label': 'Pangalan ng sequence'});
        const nm = mark();
        name.addEventListener('change', () => saveField(nm, base, {name: name.value}));

        // On/off at mode
        const on = el('input', {type: 'checkbox', 'aria-label': 'On/off'});
        on.checked = c.active;
        const onM = mark();
        on.addEventListener('change', () => {
            if (!on.checked && !confirm('I-off ang "' + c.name + '"? Hihinto agad ang pagkuha at pagpapadala nito, kasama ang naka-queue.')) { on.checked = true; return; }
            saveField(onM, base, {active: on.checked});
        });
        const modeM = mark();
        const mode = el('select', {class: 'border rounded px-2 py-0.5 text-sm', 'aria-label': 'Mode'}, [el('option', {value: 'manual', text: 'manual'}), el('option', {value: 'auto', text: 'auto'})]);
        mode.value = c.mode;
        mode.addEventListener('change', () => {
            if (mode.value === 'auto' && !confirm('Gawing AUTO ang "' + c.name + '"? Kusa itong kukuha at magpapadala sa customer ayon sa schedule: ' + c.summary + '.\n\n'
                + fmt(c.ready) + ' ang ready ngayon: HINDI ito ipapadala ng auto. Ang bago lang mula ngayon (ngayong araw) ang ipapadala; ang naipon ay sa /chatblast kung gusto.')) { mode.value = c.mode; return; }
            saveField(modeM, base, mode.value === 'auto' ? {mode: 'auto', confirm: true} : {mode: 'manual'});
        });
        const del = el('button', {type: 'button', class: 'text-xs text-red-600 hover:underline', text: 'alisin ang sequence'});
        del.addEventListener('click', async () => {
            if (!confirm('Alisin ang "' + c.name + '"?')) return;
            try { await call('DELETE', base); notice('Naalis.', 'text-green-700'); } catch (e) { notice(e.message, 'text-red-600'); } load();
        });

        // Trigger
        const stM = mark(), pgM = mark(), wM = mark(), odM = mark();
        const pageSearch = el('input', {placeholder: 'hanapin ang page', class: 'border rounded px-2 py-0.5 text-xs'});
        const pages = checks(data.pages, c.pages || [], v => saveField(pgM, base, {pages: v}));
        pageSearch.addEventListener('input', () => { for (const l of pages.children) l.classList.toggle('hidden', !l.textContent.toLowerCase().includes(pageSearch.value.toLowerCase())); });
        const win = el('input', {type: 'number', min: '1', max: '60', value: String(c.window_days), class: 'border rounded px-2 py-0.5 text-sm w-20', 'aria-label': 'Window'});
        win.addEventListener('change', () => saveField(wM, base, {window_days: win.value}));
        const od = el('input', {type: 'checkbox', 'aria-label': 'Requires On Delivery'});
        od.checked = c.requires_on_delivery;
        od.addEventListener('change', () => saveField(odM, base, {requires_on_delivery: od.checked}));

        // Schedule
        const tM = mark(), wdM = mark(), capM = mark();
        const weekdays = el('span', {class: 'inline-flex gap-2 text-sm'}, DAYS.map(([n, lbl]) => {
            const i = el('input', {type: 'checkbox', value: String(n)});
            i.checked = !c.weekdays || c.weekdays.includes(n);
            i.addEventListener('change', () => saveField(wdM, base, {weekdays: [...weekdays.querySelectorAll('input:checked')].map(x => Number(x.value))}));
            return el('label', {class: 'flex items-center gap-1'}, [i, lbl]);
        }));
        const cap = el('input', {type: 'number', min: '1', value: c.daily_cap ?? '', placeholder: 'wala', class: 'border rounded px-2 py-0.5 text-sm w-24', 'aria-label': 'Cap kada page kada araw'});
        cap.addEventListener('change', () => saveField(capM, base, {daily_cap: cap.value === '' ? null : cap.value}));
        const greyed = c.mode !== 'auto' ? ' opacity-60' : '';

        // Mga step
        const steps = c.steps.map((s, i) => {
            const sm = mark();
            const pick = el('select', {class: 'border rounded px-2 py-0.5 text-sm max-w-xs', 'aria-label': 'Mensahe ng Day ' + s.day},
                data.messages.map(m => el('option', {value: String(m.id), text: m.name})));
            if (s.message_id) pick.value = String(s.message_id); else pick.prepend(el('option', {value: '', text: '(walang mensahe)', selected: 'selected'}));
            pick.addEventListener('change', () => saveField(sm, base + '/step-message', {day: s.day, message_id: Number(pick.value), expect: s.message_id, steps: c.steps.length}));
            const btn = (text, fn, cls = 'text-blue-700') => { const b = el('button', {type: 'button', class: 'text-xs hover:underline ml-2 ' + cls, text}); b.addEventListener('click', fn); return b; };
            const seen = {expect: s.message_id, steps: c.steps.length}; // para hindi mabago ang maling step kapag luma na ang page
            const act = (path, body, ok) => async () => { try { await post(base + path, {...body, ...seen}); notice(ok, 'text-green-700'); } catch (e) { notice(e.message, 'text-red-600'); } load(); };
            const t = s.today;
            return el('div', {class: 'border-l-4 border-blue-300 bg-gray-50 rounded px-3 py-2'}, [
                el('div', {class: 'flex flex-wrap items-center gap-2'}, [
                    el('span', {class: 'font-semibold text-sm', text: 'Day ' + s.day}), pick, sm,
                    el('span', {class: 'text-xs text-gray-500', text: 'ngayon: ' + fmt(t.built) + ' nabuo · ' + fmt(t.sent) + ' naipadala · ' + fmt(t.failed) + ' failed · ' + fmt(t.skipped) + ' nilaktawan'}),
                    i > 0 ? btn('↑', act('/move', {day: s.day, dir: 'up'}, 'Nailipat.')) : null,
                    i < c.steps.length - 1 ? btn('↓', act('/move', {day: s.day, dir: 'down'}, 'Nailipat.')) : null,
                    btn('+ step pagkatapos', act('/step', {after: s.day}, 'Nadagdagan ng step (parehong mensahe; palitan sa pagpili).')),
                    c.steps.length > 1 ? btn('alisin', () => { if (confirm('Alisin ang Day ' + s.day + '? Uusad ang mga kasunod.')) act('/remove-step', {day: s.day}, 'Naalis ang step.')(); }, 'text-red-600') : null,
                ]),
                el('div', {class: 'mt-1 space-y-0.5'}, s.versions.map((v, j) => el('p', {class: 'text-xs text-gray-700 whitespace-pre-wrap'}, [el('span', {class: 'font-medium mr-1', text: String.fromCharCode(65 + j) + ':'}), v]))),
                el('div', {class: 'mt-1 text-xs text-gray-600' + greyed}, ['Sariling oras: ', chips(s.times || [], v => saveField(sm, base + '/step-times', {day: s.day, times: v, expect: s.message_id, steps: c.steps.length}), 'ang oras ng sequence')]),
            ]);
        });

        return el('section', {class: 'bg-white rounded shadow p-4' + (c.active ? '' : ' opacity-75')}, [
            el('div', {class: 'flex flex-wrap items-center gap-2 mb-1'}, [el('span', {class: 'text-gray-500', text: 'Sequence for'}), name, nm,
                el('label', {class: 'flex items-center gap-1 text-sm ml-2'}, [on, 'on', onM]), el('label', {class: 'flex items-center gap-1 text-sm'}, ['mode', mode, modeM]), del]),
            el('p', {class: 'text-sm text-gray-700 mb-1' + greyed, text: c.summary + (c.next_run ? ' · susunod: ' + c.next_run : (c.mode === 'auto' && c.active ? '' : ' · mano-mano'))}),
            el('p', {class: 'text-xs text-gray-500 mb-2', text: 'Ngayon: ' + fmt(c.today.built) + ' nabuo · ' + fmt(c.today.sent) + ' naipadala · ' + fmt(c.today.failed) + ' failed · ' + fmt(c.today.skipped) + ' nilaktawan'}),
            el('div', {class: 'grid lg:grid-cols-2 gap-4'}, [
                el('div', {}, [
                    el('h3', {class: 'text-sm font-semibold mb-1', text: 'Trigger'}),
                    row('J&T status', checks(data.statuses, c.statuses, v => saveField(stM, base, {statuses: v})), stM),
                    row('Mga page (wala = lahat)', el('div', {}, [pageSearch, pages]), pgM),
                    row('Window (araw)', win, wM),
                    row('Requires On Delivery', od, el('span', {class: 'text-xs text-gray-500', text: 'i-track bago ipadala; On Delivery lang; Delivering lang ang trigger'}), odM),
                    el('h3', {class: 'text-sm font-semibold mt-3 mb-1', text: 'Schedule' + (c.mode === 'auto' ? '' : ' (manual: hindi sinusunod)')}),
                    el('div', {class: greyed}, [
                        row('Oras ng padala', chips(c.send_times, v => saveField(tM, base, {send_times: v}), 'walang oras'), tM),
                        row('Mga araw', weekdays, wdM),
                        row('Cap kada page / araw', cap, capM),
                    ]),
                ]),
                el('div', {}, [el('h3', {class: 'text-sm font-semibold mb-1', text: 'Mga step'}), el('div', {class: 'space-y-2'}, steps)]),
            ]),
        ]);
    }

    document.getElementById('new-form').addEventListener('submit', async e => {
        e.preventDefault();
        const f = e.target;
        const statuses = [...f.querySelectorAll('input[name="statuses"]:checked')].map(i => i.value);
        try { await post(url.baseUrl, {name: f.name.value, window_days: f.window_days.value, statuses, message_id: Number(f.message_id.value)}); notice('Nagawa ang sequence (manual).', 'text-green-700'); f.reset(); document.getElementById('new-box').open = false; }
        catch (err) { notice(err.message, 'text-red-600'); }
        load();
    });

    load();
})();
</script>
</body>
</html>

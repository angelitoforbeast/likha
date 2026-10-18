<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Chatblast · Mga mensahe</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 text-gray-900">
<div class="p-4 sm:p-6" id="cm"
     data-data-url="{{ route('chatblast.campaigns.data') }}"
     data-base-url="{{ url('/chatblast/messages') }}"
     data-flow-url="{{ route('chatblast.campaigns.flow') }}">

    <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
        <h1 class="text-2xl font-semibold">Mga mensahe</h1>
        <div class="flex gap-4 text-sm">
            <a href="{{ route('chatblast.sequence.index') }}" class="text-blue-700 hover:underline">Mga sequence ›</a>
            <a href="{{ route('chatblast.index') }}" class="text-blue-700 hover:underline">Chatblast (pagpapadala)</a>
            <a href="{{ url('/') }}" class="text-gray-600 hover:underline">Home</a>
        </div>
    </div>
    <p class="text-xs text-gray-500 mb-3 max-w-4xl">
        Gaya ng mga flow sa Botcake: bawat mensahe ay may pangalan at mga bersyon (A, B, …) na iniikot sa mga customer. Ang
        <a href="{{ route('chatblast.sequence.index') }}" class="text-blue-700 hover:underline">sequence</a> ang pumipili kung aling mensahe ang ipapadala sa bawat araw.
        Nase-save ang text pag-alis sa kahon o pag-Enter (Shift+Enter: bagong linya), at binabago rin ang text ng mga hindi pa naipapadala.
    </p>

    <p id="notice" class="text-xs mb-2 min-h-[1rem]" aria-live="polite"></p>

    <div class="flex flex-wrap items-end gap-3 mb-3 text-sm">
        <label class="flex flex-col text-xs text-gray-600">Hanapin<input id="q" placeholder="pangalan o text" class="border rounded px-2 py-1 text-sm text-gray-900 w-56"></label>
        <label class="flex flex-col text-xs text-gray-600">Sequence
            <select id="seq" class="border rounded px-2 py-1 text-sm text-gray-900"><option value="">Lahat</option><option value="none">Hindi ginagamit</option></select>
        </label>
        <label class="flex flex-col text-xs text-gray-600">Preview sa page (template line)
            <select id="preview-page" class="border rounded px-2 py-1 text-sm text-gray-900"><option value="">(walang template line)</option></select>
        </label>
        <span id="template-line" class="text-xs text-gray-500 max-w-md"></span>
    </div>

    <div class="text-xs text-gray-600 mb-3">Mga placeholder (pindutin para ilagay sa huling kahon):
        @foreach($placeholders as $ph)<button type="button" class="chip ml-1 px-2 py-0.5 rounded bg-gray-200 hover:bg-gray-300 font-mono" data-ph="{{ '{' . $ph . '}' }}">{{ '{' . $ph . '}' }}</button>@endforeach
    </div>

    <details id="new-box" class="mb-3 bg-white rounded shadow px-4 py-2 text-sm">
        <summary class="cursor-pointer font-medium">+ Bagong mensahe</summary>
        <form id="new-form" class="grid md:grid-cols-3 gap-2 mt-2">
            <label class="flex flex-col text-xs text-gray-600">Pangalan<input name="name" maxlength="140" placeholder="hal. In transit · Day 3 · bayad" class="border rounded px-2 py-1 text-sm text-gray-900"></label>
            <label class="flex flex-col text-xs text-gray-600 md:col-span-2">Text ng bersyon A<textarea name="text" rows="2" maxlength="1000" class="border rounded px-2 py-1 text-sm font-mono text-gray-900"></textarea></label>
            <div><button class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded">Gumawa</button></div>
        </form>
    </details>

    <details id="flow-box" class="mb-4 bg-white rounded shadow px-4 py-2 text-sm">
        <summary class="cursor-pointer font-medium">Flow at template (kada page)</summary>
        <p class="text-xs text-gray-500 mt-1">
            Ang flow ay ang <code>flow.shipping</code> ng page; ang mensahe ay isinusulat sa <code>field.message</code> at lumalabas sa @{{1}} ng template.
            Ang Botcake API ay nagbibigay lang ng pangalan ng flow, hindi ng laman ng mga hakbang nito: ang template ay ayon sa page setting na
            <code>botcake template.shipping</code>, o sa pangalan ng flow. Naka-cache nang 10 minuto; "Refresh" para kunin ulit.
        </p>
        <div id="flow-cards" class="grid lg:grid-cols-2 gap-3 mt-2"></div>
    </details>

    <div id="list" class="space-y-3"></div>
</div>

<script>
(() => {
    const url = document.getElementById('cm').dataset;
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    let data = {messages: [], campaigns: [], pages: []}, template = null, lastArea = null;

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
    const label = i => String.fromCharCode(65 + i);

    // Kapareho ng Chatblast::render(): ang placeholder ay pinapalitan; walang laman ang wala.
    const SAMPLE = {name: 'Juan Dela Cruz', item: 'Sample Item x2', cod: '499', waybill: 'JT0000000001', page: 'Sample Page', rider: 'Rider Sample', rider_phone: '09170000000'};
    const render = text => text.replace(/\{(name|item|cod|waybill|page|rider|rider_phone)\}/g, (m, k) => String(SAMPLE[k] || '')).trim();
    const preview = text => template && template.body ? template.body.split('@{{1}}').join(render(text)) : render(text);

    async function load() {
        try {
            data = await (await fetch(url.dataUrl, {headers: {'Accept': 'application/json'}})).json();
            const seq = document.getElementById('seq'), keep = seq.value;
            seq.replaceChildren(el('option', {value: '', text: 'Lahat'}), el('option', {value: 'none', text: 'Hindi ginagamit'}),
                ...data.campaigns.map(c => el('option', {value: String(c.id), text: c.name})));
            seq.value = keep;
            const pp = document.getElementById('preview-page'), keepP = pp.value;
            pp.replaceChildren(el('option', {value: '', text: '(walang template line)'}), ...data.pages.map(p => el('option', {value: p, text: p})));
            pp.value = keepP;
            draw();
        } catch (e) { notice('Hindi ma-load: ' + e.message, 'text-red-600'); }
    }

    // Ang marka ng isang field: Saving… / Saved / ang error.
    const mark = () => el('span', {class: 'text-xs text-gray-500 ml-2', 'aria-live': 'polite'});
    const setMark = (m, state, text) => { m.className = 'text-xs ml-2 ' + ({saving: 'text-gray-500', ok: 'text-green-700', err: 'text-red-600', dirty: 'text-amber-700'}[state]); m.textContent = text; };

    function draw() {
        const q = document.getElementById('q').value.trim().toLowerCase(), seq = document.getElementById('seq').value;
        const list = data.messages.filter(m => (!q || m.name.toLowerCase().includes(q) || m.versions.some(v => v.toLowerCase().includes(q)))
            && (!seq || (seq === 'none' ? !m.used.length : m.used.some(u => String(u.campaign_id) === seq))));
        document.getElementById('list').replaceChildren(...(list.length ? list.map(card) : [el('p', {class: 'text-sm text-gray-500', text: 'Walang mensahe sa filter na ito.'})]));
    }

    function card(m) {
        const base = url.baseUrl + '/' + m.id;
        const name = el('input', {value: m.name, maxlength: '140', class: 'font-medium border rounded px-2 py-1 text-sm w-80', 'aria-label': 'Pangalan ng mensahe'});
        const nameMark = mark();
        let savedName = m.name;
        const saveName = async () => {
            if (name.value.trim() === savedName) return;
            setMark(nameMark, 'saving', 'Saving…');
            try { const d = await post(base, {name: name.value}); savedName = m.name = d.name; name.value = d.name; setMark(nameMark, 'ok', 'Saved'); }
            catch (e) { setMark(nameMark, 'err', e.message); }
        };
        name.addEventListener('blur', saveName);
        name.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); saveName(); } });

        const used = m.used.length ? m.used.map(u => el('span', {class: 'text-xs px-2 py-0.5 rounded bg-blue-50 text-blue-800 mr-1', text: u.name + ' Day ' + u.day}))
            : [el('span', {class: 'text-xs text-gray-400', text: 'hindi ginagamit'})];
        const btn = (text, cls, fn) => { const b = el('button', {type: 'button', class: 'text-xs hover:underline ml-3 ' + cls, text}); b.addEventListener('click', fn); return b; };
        const actions = [
            btn('+ bersyon', 'text-blue-700', async () => { try { await post(base + '/version'); notice('Nadagdagan ng bersyon (kopya ng A).', 'text-green-700'); } catch (e) { notice(e.message, 'text-red-600'); } load(); }),
            btn('kopyahin', 'text-blue-700', async () => { try { const d = await post(base + '/duplicate'); notice('Nakopya: ' + d.message.name, 'text-green-700'); } catch (e) { notice(e.message, 'text-red-600'); } load(); }),
            btn('alisin', 'text-red-600', async () => {
                if (!confirm('Alisin ang mensaheng "' + m.name + '"?')) return;
                try { await call('DELETE', base); notice('Naalis.', 'text-green-700'); } catch (e) { notice(e.message, 'text-red-600'); } load();
            }),
        ];

        const versions = m.versions.map((text, i) => {
            const area = el('textarea', {rows: '2', maxlength: '1000', class: 'w-full border rounded px-2 py-1 font-mono text-sm', 'aria-label': m.name + ' bersyon ' + label(i)});
            area.value = text;
            const vm = mark();
            const pv = el('div', {class: 'text-xs text-gray-700 bg-gray-50 rounded px-2 py-1 whitespace-pre-wrap'});
            const show = () => { pv.textContent = preview(area.value) || '(walang laman)'; };
            show();
            let saved = text, busy = false, again = false;
            const commit = async () => {
                if (busy) { again = true; return; }
                if (area.value === saved) return;
                busy = true;
                const sending = area.value;
                setMark(vm, 'saving', 'Saving…');
                try {
                    // old: ang text na nakita; kapag nabago na ng iba, hindi ito pinapalitan.
                    const d = await post(base + '/text', {version: label(i), text: sending, old: saved});
                    saved = m.versions[i] = d.versions[i];
                    if (area.value === sending) { area.value = saved; show(); }
                    setMark(vm, 'ok', 'Saved' + (d.changed ? ' (' + fmt(d.changed) + ' mensahe ang nabago)' : ''));
                } catch (e) { setMark(vm, 'err', e.message); }
                busy = false;
                if (again) { again = false; commit(); }
            };
            area.addEventListener('focus', () => { lastArea = area; });
            area.addEventListener('input', () => { show(); setMark(vm, 'dirty', area.value === saved ? '' : 'hindi pa naka-save'); });
            area.addEventListener('blur', commit);
            area.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); commit(); } });
            const rm = m.versions.length > 1 ? btn('alisin', 'text-red-600', async () => {
                if (!confirm('Alisin ang bersyon ' + label(i) + ' ng "' + m.name + '"?')) return;
                try { await post(base + '/remove-version', {version: label(i), old: saved}); notice('Naalis ang bersyon.', 'text-green-700'); } catch (e) { notice(e.message, 'text-red-600'); }
                load();
            }) : null;
            return el('div', {class: 'grid md:grid-cols-2 gap-2 items-start'}, [
                el('div', {}, [el('div', {class: 'text-xs text-gray-600 mb-0.5'}, [el('span', {class: 'font-medium', text: 'Bersyon ' + label(i)}),
                    el('span', {class: 'ml-2', text: 'naipadala (' + data.sent_days + ' araw) ' + fmt(m.sent[label(i)])}), rm, vm]), area]),
                el('div', {}, [el('div', {class: 'text-xs text-gray-500 mb-0.5', text: 'Makikita ng customer'}), pv]),
            ]);
        });

        return el('div', {class: 'bg-white rounded shadow p-3'}, [
            el('div', {class: 'flex flex-wrap items-center gap-2 mb-2'}, [name, nameMark, el('span', {class: 'text-xs text-gray-500', text: 'naipadala (huling ' + data.sent_days + ' araw) ' + fmt(m.sent_total)}), ...actions]),
            el('div', {class: 'mb-2'}, [el('span', {class: 'text-xs text-gray-500 mr-1', text: 'Ginagamit sa:'}), ...used]),
            el('div', {class: 'space-y-2'}, versions),
        ]);
    }

    document.getElementById('q').addEventListener('input', draw);
    document.getElementById('seq').addEventListener('change', draw);
    for (const chip of document.querySelectorAll('.chip')) {
        chip.addEventListener('click', () => {
            if (!lastArea) { notice('Pumili muna ng kahon ng text.', 'text-gray-600'); return; }
            const [s, e] = [lastArea.selectionStart, lastArea.selectionEnd];
            lastArea.value = lastArea.value.slice(0, s) + chip.dataset.ph + lastArea.value.slice(e);
            lastArea.focus();
            lastArea.dispatchEvent(new Event('input'));
        });
    }

    // Ang template line ng napiling page (mula sa Botcake, naka-cache nang 10 minuto).
    document.getElementById('preview-page').addEventListener('change', async e => {
        template = null;
        const line = document.getElementById('template-line');
        line.textContent = '';
        if (e.target.value) {
            try {
                const res = await fetch(url.flowUrl + '?' + new URLSearchParams({page: e.target.value}), {headers: {'Accept': 'application/json'}});
                const d = await res.json().catch(() => ({}));
                if (!res.ok) throw new Error(d.error || ('HTTP ' + res.status));
                template = d.template;
                line.textContent = template ? 'Template: ' + template.name + (d.check.length ? ' (suriin: ' + d.check.join(' ') + ')' : '') : 'Hindi matukoy ang template ng page.';
            } catch (err) { line.textContent = err.message; }
        }
        draw();
    });

    document.getElementById('new-form').addEventListener('submit', async e => {
        e.preventDefault();
        const f = e.target;
        try { const d = await post(url.baseUrl, {name: f.name.value, text: f.text.value}); notice('Nagawa: ' + d.message.name, 'text-green-700'); f.reset(); document.getElementById('new-box').open = false; }
        catch (err) { notice(err.message, 'text-red-600'); }
        load();
    });

    // ---- flow at template kada page (kinukuha isa-isa pagbukas) ----
    let flowsLoaded = false;
    document.getElementById('flow-box').addEventListener('toggle', async e => {
        if (!e.target.open || flowsLoaded) return;
        flowsLoaded = true;
        const box = document.getElementById('flow-cards');
        if (!data.pages.length) { box.replaceChildren(el('p', {class: 'text-gray-500', text: 'Walang page na may kumpletong Botcake settings.'})); return; }
        const cards = data.pages.map(p => [p, el('div', {class: 'border rounded p-2'}, el('p', {class: 'font-medium', text: p + ' …'}))]);
        box.replaceChildren(...cards.map(([, c]) => c));
        for (const [p, c] of cards) await flowCard(p, c, false);
    });
    async function flowCard(page, card, refresh) {
        card.replaceChildren(el('p', {class: 'font-medium', text: page + ' …'}));
        let d;
        try {
            const res = await fetch(url.flowUrl + '?' + new URLSearchParams({page, ...(refresh ? {refresh: 1} : {})}), {headers: {'Accept': 'application/json'}});
            d = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(d.error || d.message || ('HTTP ' + res.status));
        } catch (e) { card.replaceChildren(el('p', {class: 'font-medium', text: page}), el('p', {class: 'text-red-600 text-xs', text: e.message})); return; }
        const refreshBtn = el('button', {type: 'button', class: 'text-xs text-blue-700 hover:underline ml-2', text: 'Refresh'});
        refreshBtn.addEventListener('click', () => flowCard(page, card, true));
        const chip = d.check.length ? el('span', {class: 'ml-2 px-2 py-0.5 rounded text-xs bg-amber-100 text-amber-800', text: 'suriin'})
            : el('span', {class: 'ml-2 px-2 py-0.5 rounded text-xs bg-green-100 text-green-800', text: 'OK'});
        const line = (lbl, value, error) => el('p', {class: 'text-xs'}, [el('span', {class: 'text-gray-500', text: lbl + ': '}), value, error ? el('span', {class: 'text-red-600 ml-1', text: '(' + error + ')'}) : null]);
        const t = d.template;
        card.replaceChildren(
            el('p', {class: 'font-medium'}, [page, chip, refreshBtn, el('span', {class: 'text-xs text-gray-400 ml-2', text: d.fetched_at})]),
            d.check.length ? el('ul', {class: 'text-xs text-amber-800 list-disc ml-4'}, d.check.map(x => el('li', {text: x}))) : null,
            line('Flow', (d.flow.name || '?') + ' (' + d.flow.id + ')', d.flow.error),
            line('Template', t ? t.name + ' · ' + t.status + ' · ' + t.category + ' (ayon sa ' + t.source + ')' : 'hindi matukoy', d.template_error),
            line('Field', (d.field.name || '?') + ' (' + d.field.id + ')', d.field.error),
            el('p', {class: 'text-xs text-gray-500 mt-1', text: 'Makikita ng customer' + (d.sample.campaign ? ' (halimbawa: ' + d.sample.campaign + ', Day 1 · A)' : '') + ':'}),
            el('div', {class: 'text-xs bg-gray-50 rounded px-2 py-1 whitespace-pre-wrap', text: d.preview || '(walang template: ang mensahe lang) ' + d.sample.text}),
        );
    }

    load();
})();
</script>
</body>
</html>

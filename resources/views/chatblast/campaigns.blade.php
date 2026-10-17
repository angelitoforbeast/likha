<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Chatblast Campaigns</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        #sheet thead th, #camps thead th { position: sticky; top: 0; z-index: 2; }
        #sheet textarea { min-width: 22rem; }
    </style>
</head>
<body class="bg-gray-50 text-gray-900">
<div class="p-4 sm:p-6" id="cc"
     data-data-url="{{ route('chatblast.campaigns.data') }}"
     data-base-url="{{ url('/chatblast/campaigns') }}">

    <div class="flex items-center justify-between mb-3">
        <h1 class="text-2xl font-semibold">Chatblast Campaigns</h1>
        <div class="flex gap-4 text-sm">
            <a href="{{ route('chatblast.index') }}" class="text-blue-700 hover:underline">‹ Chatblast (pagpapadala)</a>
            <a href="{{ url('/') }}" class="text-gray-600 hover:underline">Home</a>
        </div>
    </div>

    <p id="notice" class="text-xs mb-2 min-h-[1rem]" aria-live="polite"></p>

    {{-- Mga campaign --}}
    <div class="bg-white rounded shadow overflow-auto mb-3">
        <table id="camps" class="text-sm border-collapse min-w-full">
            <thead>
            <tr class="bg-gray-100 text-left text-xs text-gray-600">
                @foreach(['Campaign', 'Trigger (J&T status)', 'Mga page', 'Window', 'Oras ng padala', 'Mode', 'On/off', 'Ngayon: nabuo / naipadala / failed', ''] as $h)
                    <th class="bg-gray-100 px-2 py-2 border-b whitespace-nowrap">{{ $h }}</th>
                @endforeach
            </tr>
            </thead>
            <tbody id="camp-rows"></tbody>
        </table>
    </div>

    <details id="form-box" class="mb-4 bg-white rounded shadow px-4 py-2 text-sm">
        <summary class="cursor-pointer font-medium" id="form-title">+ Bagong campaign</summary>
        <form id="camp-form" class="grid md:grid-cols-2 gap-3 mt-2">
            <input type="hidden" name="id" value="">
            <label class="flex flex-col text-xs text-gray-600">Pangalan<input name="name" maxlength="100" class="border rounded px-2 py-1 text-sm text-gray-900"></label>
            <label class="flex flex-col text-xs text-gray-600">Window (ilang araw pabalik ang order)<input name="window_days" type="number" min="1" max="60" value="7" class="border rounded px-2 py-1 text-sm text-gray-900 w-28"></label>
            <fieldset class="text-xs text-gray-600"><legend>Trigger: J&T status (kahit isa)</legend><div id="f-statuses" class="flex flex-wrap gap-3 mt-1 text-sm text-gray-900"></div></fieldset>
            <fieldset class="text-xs text-gray-600"><legend>Mga page (walang napili = lahat ng page na may Botcake settings)</legend><div id="f-pages" class="flex flex-wrap gap-3 mt-1 text-sm text-gray-900"></div></fieldset>
            <label class="flex flex-col text-xs text-gray-600">Oras ng padala (Manila, para sa auto; hal. 09:00, 15:00)<input name="send_times" placeholder="09:00, 15:00" class="border rounded px-2 py-1 text-sm text-gray-900"></label>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="requires_on_delivery"> Requires On Delivery (i-track bago ipadala; On Delivery lang ang huling scan; bagong rider)</label>
            <label id="f-text-box" class="flex flex-col text-xs text-gray-600 md:col-span-2">Text ng Araw 1 · A (mas marami pang araw at bersyon sa sheet sa ibaba)
                <textarea name="text" rows="2" maxlength="1000" class="border rounded px-2 py-1 text-sm text-gray-900 font-mono"></textarea></label>
            <div class="md:col-span-2 flex gap-2">
                <button class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded">I-save ang campaign</button>
                <button type="button" id="form-reset" class="bg-gray-200 hover:bg-gray-300 px-3 py-1 rounded">Bago</button>
            </div>
        </form>
    </details>

    {{-- Ang sheet: lahat ng text ng lahat ng campaign --}}
    <div class="flex flex-wrap items-end gap-3 mb-2 text-sm">
        <h2 class="text-lg font-semibold mr-2">Mga mensahe</h2>
        <label class="flex flex-col text-xs text-gray-600">Campaign
            <select id="sheet-filter" class="border rounded px-2 py-1 text-sm text-gray-900"><option value="">Lahat</option></select>
        </label>
        <p class="text-xs text-gray-500 max-w-3xl">
            Mga placeholder: @foreach($placeholders as $ph)<code>{{ '{' . $ph . '}' }}</code> @endforeach.
            Ang {rider} at {rider_phone} ay mula sa J&T kapag na-track ang waybill; kapag wala, ibang bersyon ng araw ang ginagamit
            (walang config kapag lahat ng bersyon ay may rider). Nase-save ang text pag-alis sa cell o pag-Enter (Shift+Enter: bagong linya),
            at binabago rin ang text ng mga hindi pa naipapadala. Ang preview ay may halimbawang order.
        </p>
    </div>
    <div class="bg-white rounded shadow overflow-auto max-h-[75vh]">
        <table id="sheet" class="text-sm border-collapse min-w-full">
            <thead>
            <tr class="bg-gray-100 text-left text-xs text-gray-600">
                @foreach(['Campaign', 'Araw', 'Bersyon', 'Laman', 'Preview', 'Naipadala', ''] as $h)
                    <th class="bg-gray-100 px-2 py-2 border-b whitespace-nowrap">{{ $h }}</th>
                @endforeach
            </tr>
            </thead>
            <tbody id="sheet-rows"></tbody>
        </table>
    </div>
</div>

<script>
(() => {
    const url = document.getElementById('cc').dataset;
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    let data = {campaigns: [], sheet: [], statuses: [], pages: []};

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

    // Kapareho ng Chatblast::render(): ang placeholder ay pinapalitan; walang laman ang wala.
    const SAMPLE = {name: 'Juan Dela Cruz', item: 'Sample Item x2', cod: '499', waybill: 'JT0000000001', page: 'Sample Page', rider: 'Rider Sample', rider_phone: '09170000000'};
    const render = text => text.replace(/\{(name|item|cod|waybill|page|rider|rider_phone)\}/g, (m, k) => String(SAMPLE[k] || '')).trim();

    async function load() {
        try {
            data = await (await fetch(url.dataUrl, {headers: {'Accept': 'application/json'}})).json();
            drawCampaigns();
            drawSheet();
        } catch (e) { notice('Hindi ma-load: ' + e.message, 'text-red-600'); }
    }

    // ---- mga campaign ----

    function drawCampaigns() {
        const f = document.getElementById('sheet-filter'), keep = f.value;
        f.replaceChildren(el('option', {value: '', text: 'Lahat'}), ...data.campaigns.map(c => el('option', {value: String(c.id), text: c.name})));
        f.value = keep;

        document.getElementById('camp-rows').replaceChildren(...data.campaigns.map(c => {
            const mode = el('button', {type: 'button', class: 'text-xs px-2 py-0.5 rounded ' + (c.mode === 'auto' ? 'bg-amber-100 text-amber-800' : 'bg-gray-100 text-gray-700'),
                text: c.mode === 'auto' ? 'auto (gawing manual)' : 'manual (gawing auto)', title: c.mode_changed_at ? 'Huling palit: ' + c.mode_changed_at : null});
            mode.addEventListener('click', () => {
                const auto = c.mode !== 'auto';
                if (auto && !confirm('Gawing AUTO ang "' + c.name + '"? Kusa itong kukuha at magpapadala sa customer sa ' + ((c.send_times || []).join(', ') || '(walang oras)') + ' araw-araw (Manila).')) return;
                save(c.id, auto ? {mode: 'auto', confirm: true} : {mode: 'manual'});
            });
            const on = el('input', {type: 'checkbox', 'aria-label': 'On/off ng ' + c.name});
            on.checked = c.active;
            on.addEventListener('change', () => {
                if (!on.checked && !confirm('I-off ang "' + c.name + '"? Hihinto agad ang pagkuha at pagpapadala nito, kasama ang naka-queue.')) { on.checked = true; return; }
                save(c.id, {active: on.checked});
            });
            const edit = el('button', {type: 'button', class: 'text-xs text-blue-700 hover:underline', text: 'baguhin'});
            edit.addEventListener('click', () => fillForm(c));
            const del = el('button', {type: 'button', class: 'text-xs text-red-600 hover:underline ml-2', text: 'alisin'});
            del.addEventListener('click', async () => {
                if (!confirm('Alisin ang "' + c.name + '"?')) return;
                try { await call('DELETE', url.baseUrl + '/' + c.id); notice('Naalis.', 'text-green-700'); load(); } catch (e) { notice(e.message, 'text-red-600'); }
            });
            const td = (x, cls = '') => el('td', {class: 'px-2 py-1 border-b align-top ' + cls}, x);
            return el('tr', {class: c.active ? '' : 'text-gray-400'}, [
                td(el('span', {class: 'font-medium', text: c.name})), td(c.statuses.join(', ')), td(c.pages && c.pages.length ? c.pages.join(', ') : 'lahat'),
                td(c.window_days + ' araw'), td((c.send_times || []).join(', ') || '—'), td(mode), td(on),
                td(fmt(c.today.built) + ' / ' + fmt(c.today.sent) + ' / ' + fmt(c.today.failed), 'whitespace-nowrap'), td([edit, del]),
            ]);
        }));
    }

    async function save(id, body) {
        try { await post(url.baseUrl + '/' + id, body); notice('Na-save.', 'text-green-700'); }
        catch (e) { notice(e.message, 'text-red-600'); }
        load();
    }

    const form = document.getElementById('camp-form');
    function checks(box, name, options, chosen) {
        const all = [...new Set([...options, ...(chosen || [])])];
        document.getElementById(box).replaceChildren(...(all.length ? all.map(o => {
            const i = el('input', {type: 'checkbox', name, value: o});
            i.checked = (chosen || []).includes(o);
            return el('label', {class: 'flex items-center gap-1'}, [i, o]);
        }) : [el('span', {class: 'text-gray-500', text: '(wala)'})]));
    }
    function fillForm(c) {
        form.id.value = c ? c.id : '';
        form.name.value = c ? c.name : '';
        form.window_days.value = c ? c.window_days : 7;
        form.send_times.value = c ? (c.send_times || []).join(', ') : '09:00';
        form.requires_on_delivery.checked = c ? c.requires_on_delivery : false;
        checks('f-statuses', 'statuses', data.statuses, c ? c.statuses : []);
        checks('f-pages', 'pages', data.pages, c ? c.pages : []);
        document.getElementById('f-text-box').classList.toggle('hidden', !!c);
        document.getElementById('form-title').textContent = c ? 'Baguhin: ' + c.name : '+ Bagong campaign';
        document.getElementById('form-box').open = true;
    }
    document.getElementById('form-reset').addEventListener('click', () => fillForm(null));
    document.getElementById('form-box').addEventListener('toggle', e => { if (e.target.open && !form.id.value && !document.getElementById('f-statuses').children.length) fillForm(null); });
    form.addEventListener('submit', async e => {
        e.preventDefault();
        const picked = n => [...form.querySelectorAll('input[name="' + n + '"]:checked')].map(i => i.value);
        const body = {
            name: form.name.value, window_days: form.window_days.value, statuses: picked('statuses'), pages: picked('pages'),
            send_times: form.send_times.value.split(',').map(s => s.trim()).filter(Boolean), requires_on_delivery: form.requires_on_delivery.checked,
        };
        try {
            if (form.id.value) await post(url.baseUrl + '/' + form.id.value, body);
            else await post(url.baseUrl, {...body, text: form.text.value});
            notice('Na-save ang campaign.', 'text-green-700');
            form.text.value = '';
            fillForm(null);
            document.getElementById('form-box').open = false;
            load();
        } catch (err) { notice(err.message, 'text-red-600'); }
    });

    // ---- ang sheet ----

    document.getElementById('sheet-filter').addEventListener('change', drawSheet);

    async function structural(u, body, ok) {
        try { await post(u, body); notice(ok, 'text-green-700'); } catch (e) { notice(e.message, 'text-red-600'); }
        load();
    }

    function drawSheet() {
        const only = document.getElementById('sheet-filter').value;
        const rows = data.sheet.filter(r => !only || String(r.campaign_id) === only);
        const lastDay = {};
        for (const r of data.sheet) lastDay[r.campaign_id] = Math.max(lastDay[r.campaign_id] || 0, r.day);

        document.getElementById('sheet-rows').replaceChildren(...rows.map(r => {
            const base = url.baseUrl + '/' + r.campaign_id;
            const area = el('textarea', {rows: '2', maxlength: '1000', class: 'w-full border rounded px-2 py-1 font-mono text-sm', 'aria-label': r.name + ' Day ' + r.day + ' ' + r.version});
            area.value = r.text;
            const mark = el('span', {class: 'text-xs text-gray-500', 'aria-live': 'polite'});
            const preview = el('div', {class: 'text-xs text-gray-700 whitespace-pre-wrap max-w-md'});
            const show = () => { preview.textContent = render(area.value) || '(walang laman)'; };
            show();
            let saved = r.text, busy = false;
            let again = false;
            const commit = async () => {
                if (busy) { again = true; return; } // ise-save pagkatapos ng kasalukuyan
                if (area.value === saved) return;
                busy = true;
                const sending = area.value;
                mark.className = 'text-xs text-gray-500'; mark.textContent = 'Saving…';
                try {
                    // old: ang text na nakita; kapag nabago na ng iba (o nausog ang araw), hindi ito pinapalitan.
                    const d = await post(base + '/text', {day: r.day, version: r.version, text: sending, old: saved});
                    saved = r.text = d.days[r.day - 1][r.version.charCodeAt(0) - 65];
                    if (area.value === sending) { area.value = saved; show(); } // ang tinype habang nagse-save ay hindi binubura
                    mark.className = 'text-xs text-green-700'; mark.textContent = 'Saved' + (d.changed ? ' (' + fmt(d.changed) + ' mensahe ang nabago)' : '');
                } catch (e) { mark.className = 'text-xs text-red-600'; mark.textContent = e.message; }
                busy = false;
                if (again) { again = false; commit(); }
            };
            area.addEventListener('input', () => { show(); mark.textContent = area.value === saved ? '' : 'hindi pa naka-save'; mark.className = 'text-xs text-amber-700'; });
            area.addEventListener('blur', commit);
            area.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); commit(); } });

            const btn = (text, cls, fn) => { const b = el('button', {type: 'button', class: 'text-xs hover:underline ' + cls, text}); b.addEventListener('click', fn); return b; };
            const acts = [
                btn('+ bersyon', 'text-blue-700', () => structural(base + '/version', {day: r.day}, 'Nadagdagan ng bersyon ang Day ' + r.day + ' (kopya ng A).')),
                r.day === lastDay[r.campaign_id] && r.version === 'A' ? btn('+ araw', 'text-blue-700 ml-2', () => structural(base + '/day', {}, 'Nadagdagan ng araw (kopya ng huling araw).')) : null,
                btn('alisin', 'text-red-600 ml-2', () => {
                    if (!confirm('Alisin ang Day ' + r.day + ' · ' + r.version + ' ng "' + r.name + '"? (Kapag ito na lang ang bersyon, maaalis ang araw at uusad ang mga kasunod.)')) return;
                    structural(base + '/remove', {day: r.day, version: r.version, old: r.text}, 'Naalis.');
                }),
            ];
            const td = (x, cls = '') => el('td', {class: 'px-2 py-1 border-b align-top ' + cls}, x);
            return el('tr', {}, [td(r.name, 'whitespace-nowrap'), td('Day ' + r.day, 'whitespace-nowrap'), td(r.version), td([area, mark]), td(preview), td(fmt(r.sent), 'text-right'), td(acts, 'whitespace-nowrap')]);
        }));
    }

    load();
})();
</script>
</body>
</html>

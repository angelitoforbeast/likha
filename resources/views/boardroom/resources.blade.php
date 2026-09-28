<x-layout>
  <x-slot name="title">Resources — AI Boardroom</x-slot>
  <x-slot name="heading">Resources</x-slot>

  {{-- /boardroom/resources — CEO lang. Ang registry ay itinatala ng AI mula sa chat; dito ito nakikita at naitatama.
       Naka-mask ang account number sa listahan. Ang buong numero ay lumalabas lang kapag pinindot ang "Ipakita". --}}
  <style>
    @media (max-width: 767px) { .br-page input, .br-page select, .br-page textarea { font-size: 16px; } }
    .br-wrap { white-space: pre-wrap; overflow-wrap: anywhere; }
  </style>

  <div class="br-page mt-16 min-h-screen bg-gray-50 text-gray-900" x-data="boardroomResources()" x-init="init()" x-cloak>
    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">

      <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
          <a href="{{ route('boardroom.index') }}" class="text-xs font-medium text-indigo-700 hover:underline">← Bumalik sa Boardroom</a>
          <h1 class="mt-1 text-2xl font-semibold tracking-tight">Resources</h1>
          <p class="mt-1 max-w-3xl text-sm text-gray-600">
            Saan nakalagay ang impormasyon at sino ang mga contact. Nakikita ito ng lahat ng role. Ang AI ang nagtatala mula sa sinabi mo sa chat;
            dito mo ito makikita, maitatama, at mabubura.
          </p>
        </div>
        <button type="button" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700" x-on:click="edit(null)">+ Resource</button>
      </div>

      <p class="mt-3 rounded-md border border-gray-200 bg-white px-3 py-2 text-xs text-gray-600">
        Hindi kayang buksan ng AI ang mga ito — listahan lang ito ng kung ano ang meron at para saan. Archive lang ang kaya ng AI; ang tuluyang pagbura ay dito lang.
        Huling 4 na digit lang ng account number ang ipinapadala sa AI.
      </p>
      <p class="mt-2 text-xs text-amber-800" x-show="active > maxInContext"
         x-text="'Ang pinakabagong ' + maxInContext + ' aktibong resource lang ang isinasama sa bawat call. I-archive ang mga hindi na ginagamit.'"></p>
      <p class="mt-2 text-sm text-red-700" x-show="error" x-text="error"></p>

      {{-- Filter --}}
      <div class="mt-4 flex flex-wrap items-center gap-2">
        <label class="sr-only" for="rs-search">Hanapin</label>
        <input id="rs-search" type="search" x-model="search" placeholder="Hanapin ang pangalan, para saan, o tag"
               class="w-full rounded-md border border-gray-300 px-3 py-1.5 text-sm sm:w-72">
        <label class="sr-only" for="rs-type">Klase</label>
        <select id="rs-type" x-model="filterType" class="rounded-md border border-gray-300 px-2 py-1.5 text-sm">
          <option value="">Lahat ng klase</option>
          <template x-for="t in types" x-bind:key="'ft' + t"><option x-bind:value="t" x-text="typeLabel(t)"></option></template>
        </select>
        <label class="flex items-center gap-1.5 text-sm text-gray-700"><input type="checkbox" x-model="showArchived"> Isama ang naka-archive</label>
        <span class="text-xs text-gray-500" x-text="shown().length + ' sa ' + resources.length"></span>
      </div>

      <div class="mt-4 grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1fr)_22rem]">

        {{-- ── Listahan ── --}}
        <section>
          <p class="rounded-lg border border-dashed border-gray-300 bg-white px-4 py-8 text-center text-sm text-gray-500" x-show="!loading && !resources.length">
            Wala pang naitala. Sa chat ng isang meeting, sabihin halimbawa:<br>
            <span class="font-mono text-xs text-gray-700">@@CEO pag maghahanap ng supplier, sa Messenger. Eto ang mga names: …</span>
          </p>

          <ul class="space-y-2">
            <template x-for="r in shown()" x-bind:key="'r' + r.id">
              <li class="rounded-lg border bg-white p-3" x-bind:class="r.status === 'active' ? 'border-gray-200' : 'border-gray-200 opacity-60'">
                <div class="flex flex-wrap items-center gap-1.5 text-[11px]">
                  <span class="rounded px-1.5 py-0.5 font-medium" x-bind:class="typeClass(r.type)" x-text="typeLabel(r.type)"></span>
                  <span class="rounded bg-gray-200 px-1.5 py-0.5 text-gray-700" x-show="r.status !== 'active'">Naka-archive</span>
                  <span class="rounded bg-gray-100 px-1.5 py-0.5 text-gray-600" x-text="r.project ? 'Project: ' + r.project : 'Lahat ng project'"></span>
                  <span class="rounded bg-gray-100 px-1.5 py-0.5 text-gray-600" x-text="r.source === 'chat' ? 'Itinala ng AI' + (r.recorded_by ? ' · ' + r.recorded_by : '') : 'Mano-mano'"></span>
                  <span class="rounded bg-amber-50 px-1.5 py-0.5 text-amber-800" x-show="!r.in_context">Hindi na isinasama (lampas sa limit)</span>
                  <span class="font-mono text-gray-400" x-text="'#' + r.id"></span>
                </div>
                <p class="mt-1 text-sm font-semibold" x-text="r.name"></p>
                <p class="text-xs text-gray-500" x-show="r.parent" x-text="'Kabilang sa: ' + r.parent"></p>
                <p class="br-wrap mt-0.5 text-sm text-gray-700" x-show="r.purpose" x-text="r.purpose"></p>
                <p class="br-wrap mt-0.5 text-xs text-gray-600" x-show="r.location"><span class="text-gray-400">Nasaan:</span> <span x-text="r.location"></span></p>
                <p class="mt-0.5 text-xs text-gray-600" x-show="r.holder"><span class="text-gray-400">May hawak:</span> <span x-text="r.holder"></span></p>
                <p class="br-wrap mt-0.5 text-xs text-gray-600" x-show="r.details" x-text="r.details"></p>
                <div class="mt-1 flex flex-wrap gap-1" x-show="r.tags.length">
                  <template x-for="t in r.tags" x-bind:key="'t' + r.id + t"><span class="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] text-gray-600" x-text="t"></span></template>
                </div>

                {{-- Payment accounts --}}
                <ul class="mt-2 space-y-1" x-show="r.accounts.length">
                  <template x-for="a in r.accounts" x-bind:key="'a' + a.id">
                    <li class="flex flex-wrap items-center gap-2 rounded-md bg-gray-50 px-2 py-1.5 text-xs" x-bind:class="a.status === 'active' ? '' : 'opacity-60'">
                      <span class="font-medium" x-text="a.method"></span>
                      <span class="text-gray-600" x-show="a.account_name" x-text="a.account_name"></span>
                      <span class="font-mono" x-text="revealed[a.id] || a.masked"></span>
                      <span class="rounded bg-gray-200 px-1.5 py-0.5 text-[10px] text-gray-700" x-show="a.status !== 'active'">Naka-archive</span>
                      <span class="text-gray-500" x-show="a.notes" x-text="a.notes"></span>
                      <span class="ml-auto flex gap-3">
                        <button type="button" class="font-medium text-indigo-700 hover:underline" x-show="!revealed[a.id]" x-on:click="reveal(a)">Ipakita</button>
                        <button type="button" class="font-medium text-indigo-700 hover:underline" x-show="revealed[a.id]" x-on:click="hide(a)">Itago</button>
                        <button type="button" class="font-medium text-gray-700 hover:underline" x-on:click="editAccount(r, a)">Edit</button>
                        <button type="button" class="font-medium text-red-700 hover:underline" x-on:click="deleteAccount(a)">Burahin</button>
                      </span>
                    </li>
                  </template>
                </ul>

                <div class="mt-2 flex flex-wrap gap-3 text-xs">
                  <button type="button" class="font-medium text-indigo-700 hover:underline" x-on:click="edit(r)">Edit</button>
                  <button type="button" class="font-medium text-indigo-700 hover:underline" x-on:click="editAccount(r, null)">+ Account</button>
                  <button type="button" class="font-medium text-gray-700 hover:underline" x-show="r.status === 'active'" x-on:click="archive(r, false)">I-archive</button>
                  <button type="button" class="font-medium text-gray-700 hover:underline" x-show="r.status !== 'active'" x-on:click="archive(r, true)">Ibalik</button>
                  <button type="button" class="font-medium text-red-700 hover:underline" x-on:click="destroy(r)">Burahin nang tuluyan</button>
                </div>
              </li>
            </template>
          </ul>
        </section>

        {{-- ── Kasaysayan ── --}}
        <aside class="rounded-lg border border-gray-200 bg-white p-4 lg:sticky lg:top-20 lg:max-h-[calc(100vh-6rem)] lg:overflow-y-auto">
          <h2 class="text-sm font-semibold">Kasaysayan ng mga pagbabago</h2>
          <p class="mt-1 text-[11px] text-gray-500">Pinakabago sa itaas. Ang undo ng "idinagdag" ay archive, hindi bura.</p>
          <p class="mt-3 text-xs text-gray-400" x-show="!history.length">Wala pang pagbabago.</p>
          <ul class="mt-3 space-y-2">
            <template x-for="h in history" x-bind:key="'h' + h.id">
              <li class="border-b border-gray-100 pb-2 text-xs last:border-0">
                <p x-bind:class="h.undone ? 'text-gray-400 line-through' : 'text-gray-800'" x-text="h.label"></p>
                <p class="mt-0.5 flex flex-wrap items-center gap-2 text-[11px] text-gray-500">
                  <span x-text="h.actor"></span><span x-text="h.at"></span>
                  <span class="rounded bg-gray-200 px-1.5 py-px text-gray-700" x-show="h.undone">Na-undo</span>
                  <button type="button" class="font-medium text-indigo-700 hover:underline disabled:opacity-50" x-show="h.can_undo" x-bind:disabled="busy" x-on:click="undo(h)">I-undo</button>
                </p>
              </li>
            </template>
          </ul>
        </aside>
      </div>
    </div>

    {{-- ═════════════ MODAL: resource ═════════════ --}}
    <div class="fixed inset-0 z-[60] flex items-start justify-center overflow-y-auto bg-black/40 p-3 sm:items-center sm:p-4" x-show="modal === 'resource'" x-on:keydown.escape.window="modal = null">
      <div class="my-2 w-full max-w-2xl rounded-lg bg-white p-4 shadow-xl sm:my-8 sm:p-5" role="dialog" aria-modal="true" aria-labelledby="rs-form-title">
        <h3 id="rs-form-title" class="text-base font-semibold" x-text="form.id ? 'I-edit ang resource' : 'Bagong resource'"></h3>
        <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div><label class="block text-sm font-medium" for="rf-type">Klase</label>
            <select id="rf-type" x-model="form.type" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
              <template x-for="t in types" x-bind:key="'rt' + t"><option x-bind:value="t" x-text="typeLabel(t)"></option></template>
            </select></div>
          <div><label class="block text-sm font-medium" for="rf-name">Pangalan</label>
            <input id="rf-name" type="text" maxlength="200" x-model="form.name" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm"></div>
          <div class="sm:col-span-2"><label class="block text-sm font-medium" for="rf-purpose">Para saan</label>
            <input id="rf-purpose" type="text" maxlength="500" x-model="form.purpose" placeholder="hal. Paghahanap at pakikipag-usap sa supplier" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm"></div>
          <div class="sm:col-span-2"><label class="block text-sm font-medium" for="rf-location">Nasaan (link, username, o paglalarawan)</label>
            <input id="rf-location" type="text" maxlength="1000" x-model="form.location" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm"></div>
          <div><label class="block text-sm font-medium" for="rf-parent">Kabilang sa</label>
            <select id="rf-parent" x-model="form.parent_id" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
              <option value="">(wala)</option>
              <template x-for="p in resources.filter(x => x.id !== form.id && x.status === 'active')" x-bind:key="'rp' + p.id">
                <option x-bind:value="String(p.id)" x-text="p.name + ' · ' + typeLabel(p.type)"></option>
              </template>
            </select></div>
          <div><label class="block text-sm font-medium" for="rf-holder">Sino ang may access</label>
            <input id="rf-holder" type="text" maxlength="200" x-model="form.holder" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm"></div>
          <div><label class="block text-sm font-medium" for="rf-tags">Tags (comma)</label>
            <input id="rf-tags" type="text" x-model="form.tags" placeholder="supplier, fan, bayad" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm"></div>
          <div><label class="block text-sm font-medium" for="rf-project">Saklaw</label>
            <select id="rf-project" x-model="form.project_id" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
              <option value="">Lahat ng project</option>
              <template x-for="p in projects" x-bind:key="'rpj' + p.id"><option x-bind:value="String(p.id)" x-text="'Sa project lang: ' + p.name"></option></template>
            </select></div>
          <div class="sm:col-span-2"><label class="block text-sm font-medium" for="rf-details">Iba pang tala</label>
            <textarea id="rf-details" rows="3" maxlength="4000" x-model="form.details" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm"></textarea></div>
        </div>
        <p class="mt-2 text-xs text-red-700" x-show="formError" x-text="formError"></p>
        <div class="mt-4 flex justify-end gap-2">
          <button type="button" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm" x-on:click="modal = null">Cancel</button>
          <button type="button" class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50" x-bind:disabled="busy || !String(form.name || '').trim()" x-on:click="save()">Save</button>
        </div>
      </div>
    </div>

    {{-- ═════════════ MODAL: payment account ═════════════ --}}
    <div class="fixed inset-0 z-[60] flex items-start justify-center overflow-y-auto bg-black/40 p-3 sm:items-center sm:p-4" x-show="modal === 'account'" x-on:keydown.escape.window="modal = null">
      <div class="my-2 w-full max-w-md rounded-lg bg-white p-4 shadow-xl sm:my-8 sm:p-5" role="dialog" aria-modal="true" aria-labelledby="ac-form-title">
        <h3 id="ac-form-title" class="text-base font-semibold" x-text="(acct.id ? 'I-edit ang account ni ' : 'Bagong account ni ') + acct.owner"></h3>
        <label class="mt-3 block text-sm font-medium" for="af-method">Paraan ng bayad</label>
        <input id="af-method" type="text" maxlength="60" x-model="acct.method" placeholder="hal. GCash, BDO" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
        <label class="mt-3 block text-sm font-medium" for="af-name">Pangalan sa account</label>
        <input id="af-name" type="text" maxlength="200" x-model="acct.account_name" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
        <label class="mt-3 block text-sm font-medium" for="af-number" x-text="acct.id ? 'Bagong account number (iwanang blangko kung hindi babaguhin)' : 'Account number'"></label>
        <input id="af-number" type="text" inputmode="text" autocomplete="off" spellcheck="false" maxlength="60" x-model="acct.account_number"
               class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 font-mono text-sm">
        <label class="mt-3 block text-sm font-medium" for="af-notes">Tala</label>
        <input id="af-notes" type="text" maxlength="500" x-model="acct.notes" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
        <p class="mt-2 text-[11px] text-gray-500">Huwag maglagay ng password, PIN, OTP, o CVV. Hindi kailangan ang mga iyon para malaman kung sino ang babayaran.</p>
        <p class="mt-2 text-xs text-red-700" x-show="formError" x-text="formError"></p>
        <div class="mt-4 flex flex-wrap justify-end gap-2">
          <button type="button" class="mr-auto rounded-md border border-gray-300 px-3 py-1.5 text-sm" x-show="acct.id"
                  x-on:click="acct.status = acct.status === 'active' ? 'archived' : 'active'" x-text="acct.status === 'active' ? 'I-archive pagka-save' : 'Gawing aktibo pagka-save'"></button>
          <button type="button" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm" x-on:click="modal = null">Cancel</button>
          <button type="button" class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50" x-bind:disabled="busy || !String(acct.method || '').trim()" x-on:click="saveAccount()">Save</button>
        </div>
      </div>
    </div>
  </div>

  <script>
    /* boardroom-resources */
    function boardroomResources() {
      const API  = @json(url('/boardroom/api'));
      const csrf = () => document.querySelector('meta[name="csrf-token"]').content;

      return {
        loading: true, busy: false, error: '', formError: '', modal: null,
        resources: [], history: [], types: [], projects: [], active: 0, maxInContext: 80,
        search: '', filterType: '', showArchived: false,
        revealed: {},   // nasa memory lang ng page; nawawala pag-refresh
        form: {}, acct: {},

        async api(method, path, body) {
          const options = { method, headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' };
          if (body !== undefined) { options.headers['Content-Type'] = 'application/json'; options.body = JSON.stringify(body); }
          let response;
          try { response = await fetch(API + path, options); }
          catch (e) { return { ok: false, status: 0, data: { message: 'Hindi maabot ang server. Tingnan ang koneksyon.' } }; }
          let data = {};
          try { data = await response.json(); } catch (e) { data = { message: 'Hindi inaasahang sagot ng server (HTTP ' + response.status + ').' }; }
          return { ok: response.ok, status: response.status, data };
        },
        messagesOf(data) {
          const out = [];
          if (data && data.errors) Object.values(data.errors).forEach(list => out.push(...[].concat(list)));
          if (!out.length && data && data.message) out.push(data.message);
          return out.length ? out : ['May error. Subukan ulit.'];
        },

        async init() {
          const r = await this.api('GET', '/resources');
          this.loading = false;
          if (!r.ok) { this.error = this.messagesOf(r.data)[0]; return; }
          this.load(r.data);
        },
        load(d) {
          if (!d || !d.resources) return;
          this.resources = d.resources; this.history = d.history; this.types = d.types; this.projects = d.projects;
          this.active = d.active; this.maxInContext = d.max_in_context;
        },
        done(r, target) {
          this.busy = false;
          if (!r.ok) { this[target || 'error'] = this.messagesOf(r.data).join(' '); if (r.data && r.data.resources) this.load(r.data); return false; }
          this.load(r.data); this.modal = null; this.error = ''; this.formError = '';
          return true;
        },

        shown() {
          const q = this.search.trim().toLowerCase();
          return this.resources.filter(r => {
            if (!this.showArchived && r.status !== 'active') return false;
            if (this.filterType && r.type !== this.filterType) return false;
            if (!q) return true;
            return [r.name, r.purpose, r.location, r.details, r.holder, r.parent || '', r.tags.join(' ')].join(' ').toLowerCase().includes(q);
          });
        },

        edit(r) {
          this.formError = '';
          this.form = r
            ? { id: r.id, type: r.type, name: r.name, purpose: r.purpose, location: r.location, parent_id: r.parent_id ? String(r.parent_id) : '',
                holder: r.holder, tags: r.tags.join(', '), project_id: r.project_id ? String(r.project_id) : '', details: r.details }
            : { id: null, type: 'contact', name: '', purpose: '', location: '', parent_id: '', holder: '', tags: '', project_id: '', details: '' };
          this.modal = 'resource';
        },
        async save() {
          const f = this.form;
          const body = { type: f.type, name: f.name, purpose: f.purpose || null, location: f.location || null,
            parent_id: f.parent_id === '' ? null : Number(f.parent_id), holder: f.holder || null,
            tags: String(f.tags || '').split(',').map(t => t.trim()).filter(Boolean), project_id: f.project_id === '' ? null : Number(f.project_id), details: f.details || null };
          this.busy = true; this.formError = '';
          this.done(f.id ? await this.api('PUT', '/resources/' + f.id, body) : await this.api('POST', '/resources', body), 'formError');
        },
        async archive(r, restore) {
          this.busy = true;
          this.done(await this.api('POST', '/resources/' + r.id + '/archive', { restore }));
        },
        async destroy(r) {
          if (!window.confirm('Burahin nang TULUYAN ang "' + r.name + '"' + (r.accounts.length ? ' at ang ' + r.accounts.length + ' account nito' : '') + '? Hindi na ito maibabalik. Kung gusto mo lang itong itago, gamitin ang I-archive.')) return;
          this.busy = true;
          this.done(await this.api('DELETE', '/resources/' + r.id));
        },

        editAccount(r, a) {
          this.formError = '';
          this.acct = a
            ? { id: a.id, resource_id: r.id, owner: r.name, method: a.method, account_name: a.account_name, account_number: '', notes: a.notes, status: a.status }
            : { id: null, resource_id: r.id, owner: r.name, method: '', account_name: '', account_number: '', notes: '', status: 'active' };
          this.modal = 'account';
        },
        async saveAccount() {
          const a = this.acct;
          const body = { method: a.method, account_name: a.account_name || null, notes: a.notes || null };
          if (String(a.account_number || '').trim() !== '') body.account_number = a.account_number.trim();
          if (a.id) body.status = a.status;
          this.busy = true; this.formError = '';
          const ok = this.done(a.id ? await this.api('PUT', '/accounts/' + a.id, body) : await this.api('POST', '/resources/' + a.resource_id + '/accounts', body), 'formError');
          if (ok && a.id) delete this.revealed[a.id];
          this.acct.account_number = '';
        },
        async deleteAccount(a) {
          if (!window.confirm('Burahin nang tuluyan ang account na ' + a.method + ' ' + a.masked + '? Hindi na ito maibabalik.')) return;
          this.busy = true;
          this.done(await this.api('DELETE', '/accounts/' + a.id));
        },
        async reveal(a) {
          const r = await this.api('POST', '/accounts/' + a.id + '/reveal');
          if (r.ok) this.revealed = Object.assign({}, this.revealed, { [a.id]: r.data.account_number });
          else this.error = this.messagesOf(r.data)[0];
        },
        hide(a) { const next = Object.assign({}, this.revealed); delete next[a.id]; this.revealed = next; },

        async undo(h) {
          this.busy = true;
          this.done(await this.api('POST', '/changes/' + h.id + '/undo'));
        },

        typeLabel(t) {
          return { channel: 'Channel', group_chat: 'Group chat', website: 'Website', page: 'Page', contact: 'Contact', link: 'Link', other: 'Iba pa' }[t] || t;
        },
        typeClass(t) {
          return { channel: 'bg-sky-100 text-sky-900', group_chat: 'bg-sky-100 text-sky-900', website: 'bg-indigo-100 text-indigo-900', page: 'bg-indigo-100 text-indigo-900',
            contact: 'bg-emerald-100 text-emerald-900', link: 'bg-amber-100 text-amber-900' }[t] || 'bg-gray-100 text-gray-700';
        },
      };
    }
  </script>
</x-layout>

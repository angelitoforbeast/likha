<x-layout>
  <x-slot name="title">Agents / Roles — AI Boardroom</x-slot>
  <x-slot name="heading">Agents / Roles</x-slot>

  {{-- /boardroom/agents — CEO lang. Ang API key ay WRITE-ONLY: tinatanggap papasok, hindi kailanman ibinabalik.
       Naka-mask na anyo lang (huling 4 na character) ang ipinapakita. Walang key na itinatago sa browser storage. --}}
  <div class="mt-16 min-h-screen bg-gray-50 text-gray-900" x-data="boardroomAgents()" x-init="init()" x-cloak>
    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">

      <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
          <a href="{{ route('boardroom.index') }}" class="text-xs font-medium text-indigo-700 hover:underline">← Bumalik sa Boardroom</a>
          <h1 class="mt-1 text-2xl font-semibold tracking-tight">Agents / Roles</h1>
          <p class="mt-1 max-w-3xl text-sm text-gray-600">Bawat role ay may sariling provider, model, settings, at API key. Walang global key at walang awtomatikong paglipat sa ibang model, provider, o key.</p>
        </div>
        <button type="button" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700" x-on:click="newAgent()">+ Bagong role</button>
      </div>

      <p class="mt-3 rounded-md border border-gray-200 bg-white px-3 py-2 text-xs text-gray-600">
        Naka-encrypt ang mga API key bago i-save. Encryption key: <span class="font-mono font-medium" x-text="encryption"></span> (nasa .env ng server, hiwalay sa database).
        Hindi na maipapakita ulit ang key pagkatapos i-save — palitan lang o tanggalin.
      </p>
      <p class="mt-2 text-sm text-red-700" x-show="error" x-text="error"></p>

      <div class="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-[18rem_minmax(0,1fr)]">

        {{-- ── Listahan ng mga role ── --}}
        <nav class="space-y-1.5" aria-label="Mga role">
          <template x-for="a in agents" x-bind:key="'a' + a.id">
            <button type="button" class="flex w-full items-start gap-2 rounded-md border px-3 py-2 text-left"
                    x-bind:class="form.id === a.id ? 'border-indigo-400 bg-indigo-50' : 'border-gray-200 bg-white hover:bg-gray-50'" x-on:click="edit(a)">
              <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-[10px] font-bold text-white" x-bind:class="avatar(a.role_type)" x-text="a.handle.slice(0, 3)"></span>
              <span class="min-w-0 flex-1">
                <span class="block truncate text-sm font-medium" x-text="a.display_name"></span>
                <span class="block truncate text-[11px] text-gray-500" x-text="a.provider + ' · ' + (a.model || 'walang model')"></span>
                <span class="mt-0.5 flex flex-wrap gap-1">
                  <span class="rounded bg-gray-100 px-1.5 py-px text-[10px] text-gray-600" x-text="a.role_type"></span>
                  <span class="rounded px-1.5 py-px text-[10px]" x-bind:class="a.credential ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-red-800'" x-text="a.credential ? 'May key ' + a.credential.masked.slice(-4) : 'Walang key'"></span>
                  <span class="rounded bg-gray-200 px-1.5 py-px text-[10px] text-gray-700" x-show="!a.enabled">disabled</span>
                  <span class="rounded bg-gray-200 px-1.5 py-px text-[10px] text-gray-700" x-show="a.archived">archived</span>
                  <span class="rounded bg-amber-50 px-1.5 py-px text-[10px] text-amber-800" x-show="a.flags.some(f => f.level === 'warn')">may babala</span>
                </span>
              </span>
            </button>
          </template>
        </nav>

        {{-- ── Editor ng napiling role ── --}}
        <section class="rounded-lg border border-gray-200 bg-white p-5" x-show="form.open">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-base font-semibold" x-text="form.id ? 'I-edit: ' + form.display_name : 'Bagong role'"></h2>
            <span class="text-[11px] text-gray-400" x-show="form.id">Ang pag-save dito ay sa role na ito LANG — walang ibang role na nagagalaw.</span>
          </div>

          <template x-if="current && current.flags.length">
            <ul class="mt-3 space-y-1">
              <template x-for="f in current.flags" x-bind:key="f.text">
                <li class="rounded-md px-3 py-1.5 text-xs" x-bind:class="f.level === 'error' ? 'bg-red-50 text-red-800' : (f.level === 'warn' ? 'bg-amber-50 text-amber-900' : 'bg-gray-100 text-gray-700')" x-text="f.text"></li>
              </template>
            </ul>
          </template>

          <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <label class="block text-sm font-medium" for="ag-name">Display name</label>
              <input id="ag-name" type="text" maxlength="120" x-model="form.display_name" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
            </div>
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="block text-sm font-medium" for="ag-handle">Handle (para sa @mention)</label>
                <input id="ag-handle" type="text" maxlength="40" x-model="form.handle" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 font-mono text-sm uppercase">
              </div>
              <div>
                <label class="block text-sm font-medium" for="ag-type">Papel sa meeting</label>
                <select id="ag-type" x-model="form.role_type" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                  <option value="moderator">Moderator (brief, routing, final)</option>
                  <option value="contributor">Contributor (proposal)</option>
                  <option value="reviewer">Reviewer (review)</option>
                </select>
              </div>
            </div>
          </div>

          <label class="mt-4 block text-sm font-medium" for="ag-desc">Description</label>
          <textarea id="ag-desc" rows="2" maxlength="2000" x-model="form.description" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm"></textarea>

          <label class="mt-4 block text-sm font-medium" for="ag-instr">System instructions</label>
          <textarea id="ag-instr" rows="6" maxlength="20000" x-model="form.instructions" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 font-mono text-xs leading-relaxed"></textarea>

          <label class="mt-3 flex items-center gap-2 text-sm"><input type="checkbox" x-model="form.enabled"> Enabled (pwedeng isali sa meeting)</label>

          {{-- Provider + model --}}
          <h3 class="mt-6 border-t border-gray-100 pt-4 text-sm font-semibold">Provider at model</h3>
          <div class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <label class="block text-sm font-medium" for="ag-provider">Provider</label>
              <select id="ag-provider" x-model="form.provider" x-on:change="providerChanged()" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                <template x-for="(label, key) in providers" x-bind:key="key"><option x-bind:value="key" x-text="label"></option></template>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium" for="ag-model">Model</label>
              <select id="ag-model" x-model="form.model_choice" x-on:change="modelChanged()" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                <template x-for="c in modelsFor(form.provider)" x-bind:key="c.model">
                  <option x-bind:value="c.model" x-text="(c.label || c.model) + ' — ' + c.model + (c.verified ? '' : ' (unverified)')"></option>
                </template>
                <option value="__manual__">Manual na model ID…</option>
              </select>
              <input type="text" maxlength="120" x-show="form.model_choice === '__manual__'" x-model="form.model_manual" x-on:input="modelChanged()"
                     aria-label="Eksaktong model ID" placeholder="eksaktong model ID" class="mt-2 block w-full rounded-md border border-gray-300 px-3 py-2 font-mono text-sm">
            </div>
          </div>

          <div class="mt-2 rounded-md bg-gray-50 px-3 py-2 text-xs text-gray-600">
            <template x-if="cap().known">
              <span>
                <span class="font-medium" x-bind:class="cap().verified ? 'text-emerald-800' : 'text-amber-800'" x-text="cap().verified ? 'Verified sa opisyal na docs (' + (cap().verified_at || '—') + ')' : 'UNVERIFIED sa registry'"></span>
                <span x-text="' · endpoint: ' + (cap().endpoint || '—')"></span>
                <span x-text="cap().max_output_tokens ? ' · max output ' + num(cap().max_output_tokens) : ''"></span>
                <span x-text="cap().price_in !== null ? ' · $' + cap().price_in + ' / $' + cap().price_out + ' kada 1M' : ' · walang alam na presyo'"></span>
                <span class="block" x-bind:class="cap().live_verified_at ? 'text-emerald-800' : 'text-amber-800'"
                      x-text="cap().live_verified_at ? 'Napatunayan sa totoong request: ' + cap().live_verified_at : 'Hindi pa napapatunayan sa totoong request — gamitin ang Test Connection.'"></span>
                <span class="block text-gray-500" x-show="cap().notes" x-text="cap().notes"></span>
                <a class="text-indigo-700 hover:underline" x-show="cap().doc_url" x-bind:href="cap().doc_url" target="_blank" rel="noopener noreferrer">Doc source</a>
              </span>
            </template>
            <template x-if="!cap().known">
              <span class="text-amber-800">Wala sa registry ang model ID na ito — UNVERIFIED. Pwede itong gamitin pero walang advanced parameter na ipapadala hangga't hindi ito nailalagay at nave-verify sa model registry sa ibaba.</span>
            </template>
          </div>
          <p class="mt-1 text-xs text-indigo-800" x-show="refreshed" x-text="refreshed"></p>

          <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div x-show="advanced() && cap().efforts.length">
              <label class="block text-sm font-medium" for="ag-effort">Reasoning effort</label>
              <select id="ag-effort" x-model="form.settings.effort" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                <option value="">(default ng provider)</option>
                <template x-for="e in cap().efforts" x-bind:key="e"><option x-bind:value="e" x-text="e"></option></template>
              </select>
            </div>
            <div x-show="advanced() && cap().thinking_modes.length">
              <label class="block text-sm font-medium" for="ag-thinking">Thinking mode</label>
              <select id="ag-thinking" x-model="form.settings.thinking" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                <option value="">(default ng provider)</option>
                <template x-for="t in cap().thinking_modes" x-bind:key="t"><option x-bind:value="t" x-text="t"></option></template>
              </select>
            </div>
            <div x-show="advanced() && cap().optional_params.thinking_budget_tokens && form.settings.thinking === 'enabled'">
              <label class="block text-sm font-medium" for="ag-budget">Thinking budget tokens</label>
              <input id="ag-budget" type="number" min="1024" x-model="form.settings.thinking_budget_tokens" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
            </div>
            <div>
              <label class="block text-sm font-medium" for="ag-maxout">Max output tokens</label>
              <input id="ag-maxout" type="number" min="16" x-model="form.settings.max_output_tokens" x-bind:placeholder="String(defaults.max_output_tokens)" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
              <span class="mt-0.5 block text-[11px] text-gray-500" x-show="form.provider === 'openai'">Kasama rito ang reasoning tokens.</span>
            </div>
            <div>
              <label class="block text-sm font-medium" for="ag-timeout">Request timeout (segundo)</label>
              <input id="ag-timeout" type="number" min="10" x-bind:max="defaults.timeout_max" x-model="form.settings.timeout_s" x-bind:placeholder="String(defaults.timeout_s)" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
            </div>
          </div>
          <p class="mt-2 text-xs text-gray-500" x-show="!advanced()">Walang ipinapakitang effort o thinking option dahil hindi verified ang model. Ang mga hindi supported na setting ay hindi kailanman ipinapadala sa provider.</p>

          {{-- Credential --}}
          <h3 class="mt-6 border-t border-gray-100 pt-4 text-sm font-semibold">API key ng role na ito <span class="font-normal text-gray-500" x-text="'(' + (providers[form.provider] || form.provider) + ')'"></span></h3>
          <div class="mt-2 text-sm">
            <template x-if="credential()">
              <div class="flex flex-wrap items-center gap-2">
                <span class="rounded bg-gray-100 px-2 py-1 font-mono text-xs" x-text="credential().masked"></span>
                <span class="text-xs text-gray-500" x-text="'na-save: ' + (credential().updated_at || '—')"></span>
                <span class="rounded px-1.5 py-0.5 text-[11px]" x-show="credential().last_tested_at"
                      x-bind:class="credential().last_test_ok ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-red-800'"
                      x-text="(credential().last_test_ok ? 'Test OK' : 'Test failed') + ' · ' + credential().last_tested_at"></span>
                <button type="button" class="text-xs font-medium text-red-700 hover:underline" x-on:click="removeKey()">Tanggalin ang key</button>
              </div>
            </template>
            <template x-if="!credential()">
              <p class="text-xs text-red-700" x-text="form.id ? 'Wala pang API key ang role na ito para sa provider na ito.' : 'I-save muna ang role, o ilagay na ang key sa ibaba.'"></p>
            </template>
          </div>
          <div class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <label class="block text-sm font-medium" for="ag-key" x-text="credential() ? 'Palitan ang API key' : 'API key'"></label>
              <input id="ag-key" type="password" autocomplete="new-password" spellcheck="false" maxlength="500" x-model="form.api_key"
                     placeholder="I-paste dito — hindi na ito maipapakita ulit" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 font-mono text-sm">
            </div>
            <div>
              <label class="block text-sm font-medium" for="ag-keylabel">Label ng key (opsyonal)</label>
              <input id="ag-keylabel" type="text" maxlength="120" x-model="form.key_label" placeholder="hal. OpenAI — CTO" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
            </div>
          </div>
          <p class="mt-1 text-[11px] text-gray-500">Gumamit ng hiwalay na key kada role para makita sa dashboard ng provider ang aktwal na gastos ng bawat isa.</p>

          <template x-if="formErrors.length">
            <ul class="mt-4 list-disc rounded-md bg-red-50 py-2 pl-7 pr-3 text-xs text-red-800">
              <template x-for="e in formErrors" x-bind:key="e"><li x-text="e"></li></template>
            </ul>
          </template>
          <p class="mt-4 rounded-md bg-emerald-50 px-3 py-2 text-xs text-emerald-900" x-show="saved" x-text="saved"></p>

          <div class="mt-5 flex flex-wrap items-center gap-2">
            <button type="button" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50" x-bind:disabled="busy" x-on:click="save()">Save</button>
            <button type="button" class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium hover:bg-gray-50 disabled:opacity-50"
                    x-show="form.id" x-bind:disabled="busy || dirtyKey()" x-on:click="test()">Test Connection</button>
            <button type="button" class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium hover:bg-gray-50 disabled:opacity-50"
                    x-show="form.id" x-bind:disabled="busy || dirtyKey()" x-on:click="fetchModels()">Kunin ang mga model ID</button>
            <span class="text-[11px] text-gray-500" x-show="form.id && dirtyKey()">I-save muna ang bagong key bago mag-test.</span>
            <button type="button" class="ml-auto text-xs font-medium text-gray-500 hover:text-red-700" x-show="form.id && current && !current.archived" x-on:click="archive(false)">I-archive ang role</button>
            <button type="button" class="ml-auto text-xs font-medium text-indigo-700 hover:underline" x-show="form.id && current && current.archived" x-on:click="archive(true)">Ibalik mula sa archive</button>
          </div>
          <p class="mt-1 text-[11px] text-gray-500" x-show="form.id">Ang Test Connection ay gumagawa ng maliit pero TOTOONG request — maaari itong kumonsumo ng kaunting credits.</p>

          {{-- Resulta ng test --}}
          <template x-if="testResult">
            <div class="mt-4 rounded-md border px-3 py-2 text-xs" x-bind:class="testResult.ok ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-red-200 bg-red-50 text-red-900'">
              <p class="font-semibold" x-text="testResult.ok ? 'Test Connection: OK (totoong request)' : 'Test Connection: nabigo' + (testResult.error_code ? ' (' + testResult.error_code + ')' : '')"></p>
              <p class="mt-0.5" style="white-space: pre-wrap; overflow-wrap: anywhere;" x-text="testResult.message"></p>
              <p class="mt-1" x-show="testResult.model_reported" x-text="'Model ayon sa provider: ' + testResult.model_reported"></p>
              <p class="mt-1" x-show="testResult.usage" x-text="'Usage: ' + (testResult.usage ? testResult.usage.tokens_in + ' in / ' + testResult.usage.tokens_out + ' out' : '')"></p>
              <p class="mt-1 font-mono" x-show="testResult.sent" x-text="'Ipinadala: ' + JSON.stringify(testResult.sent)"></p>
              <template x-for="n in (testResult.not_sent || [])" x-bind:key="n"><p class="mt-0.5 text-amber-900" x-text="'Hindi ipinadala: ' + n"></p></template>
            </div>
          </template>

          {{-- Mga model na naa-access ng key --}}
          <template x-if="fetched">
            <div class="mt-4 rounded-md border border-gray-200 px-3 py-2">
              <p class="text-xs font-semibold" x-text="fetched.ok ? fetched.models.length + ' model ID ang naa-access ng key na ito' : 'Hindi nakuha ang listahan'"></p>
              <p class="mt-0.5 text-xs text-red-700" x-show="!fetched.ok" x-text="fetched.message"></p>
              <div class="mt-2 flex max-h-40 flex-wrap gap-1 overflow-y-auto">
                <template x-for="m in fetched.models" x-bind:key="m.id">
                  <button type="button" class="rounded border px-1.5 py-0.5 font-mono text-[11px] hover:bg-gray-50"
                          x-bind:class="m.verified ? 'border-emerald-300 text-emerald-900' : 'border-gray-300 text-gray-700'"
                          x-bind:title="m.verified ? 'Verified sa registry' : (m.known ? 'Nasa registry pero unverified' : 'Wala sa registry (unverified)')"
                          x-on:click="pickModel(m.id)" x-text="m.id"></button>
                </template>
              </div>
              <p class="mt-1 text-[11px] text-gray-500">Berde = verified sa registry. Ang iba ay magagamit bilang manual ID pero walang advanced parameter.</p>
            </div>
          </template>
        </section>
      </div>

      {{-- ── Default group ── --}}
      <section class="mt-8 rounded-lg border border-gray-200 bg-white p-5">
        <h2 class="text-base font-semibold">Mga group</h2>
        <p class="mt-1 text-xs text-gray-500">Ang group ay paunang pili ng mga role kapag gumagawa ng meeting. Ang aktwal na kasali ay pinipili pa rin kada meeting.</p>
        <template x-for="g in groups" x-bind:key="'g' + g.id">
          <div class="mt-3 rounded-md border border-gray-200 p-3">
            <div class="flex flex-wrap items-center gap-2">
              <input type="text" maxlength="120" x-model="g.name" x-bind:aria-label="'Pangalan ng group ' + g.id" class="rounded-md border border-gray-300 px-2 py-1 text-sm font-medium">
              <span class="rounded bg-indigo-50 px-1.5 py-0.5 text-[10px] text-indigo-800" x-show="g.is_default">default</span>
              <button type="button" class="ml-auto rounded-md border border-gray-300 px-2.5 py-1 text-xs font-medium hover:bg-gray-50" x-on:click="saveGroup(g)">Save group</button>
            </div>
            <div class="mt-2 flex flex-wrap gap-3">
              <template x-for="a in agents.filter(x => !x.archived)" x-bind:key="'ga' + g.id + '-' + a.id">
                <label class="flex items-center gap-1.5 text-xs"><input type="checkbox" x-bind:value="a.id" x-model.number="g.agent_ids"> <span x-text="a.display_name"></span></label>
              </template>
            </div>
          </div>
        </template>
      </section>

      {{-- ── Model-capability registry ── --}}
      <section class="mt-8 rounded-lg border border-gray-200 bg-white p-5">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <div>
            <h2 class="text-base font-semibold">Model-capability registry</h2>
            <p class="mt-1 max-w-3xl text-xs text-gray-500">Ito ang pinagbabatayan kung anong settings ang pwedeng ipadala sa bawat model. Ang wala rito ay hindi ipinapadala. Para markahang verified, kailangan ng doc source at petsa.</p>
          </div>
          <button type="button" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium hover:bg-gray-50" x-on:click="editCap(null)">+ Model</button>
        </div>

        <div class="mt-3 overflow-x-auto">
          <table class="min-w-full text-left text-xs">
            <thead class="text-gray-500">
              <tr class="border-b border-gray-200">
                <th class="py-2 pr-3 font-medium">Provider</th><th class="py-2 pr-3 font-medium">Model ID</th><th class="py-2 pr-3 font-medium">Endpoint</th>
                <th class="py-2 pr-3 font-medium">Efforts</th><th class="py-2 pr-3 font-medium">Thinking</th><th class="py-2 pr-3 font-medium">JSON</th>
                <th class="py-2 pr-3 text-right font-medium">Max out</th><th class="py-2 pr-3 text-right font-medium">$ in / out</th>
                <th class="py-2 pr-3 font-medium">Docs</th><th class="py-2 pr-3 font-medium">Live</th><th class="py-2 font-medium"></th>
              </tr>
            </thead>
            <tbody>
              <template x-for="c in capabilities" x-bind:key="'c' + c.id">
                <tr class="border-b border-gray-100 align-top">
                  <td class="py-2 pr-3" x-text="providers[c.provider] || c.provider"></td>
                  <td class="py-2 pr-3 font-mono" x-text="c.model"></td>
                  <td class="py-2 pr-3" x-text="c.endpoint || '—'"></td>
                  <td class="py-2 pr-3" x-text="c.efforts.join(', ') || '—'"></td>
                  <td class="py-2 pr-3" x-text="c.thinking_modes.join(', ') || '—'"></td>
                  <td class="py-2 pr-3" x-text="c.structured_output"></td>
                  <td class="py-2 pr-3 text-right tabular-nums" x-text="c.max_output_tokens ? num(c.max_output_tokens) : '—'"></td>
                  <td class="py-2 pr-3 text-right tabular-nums" x-text="c.price_in !== null ? c.price_in + ' / ' + c.price_out : 'hindi alam'"></td>
                  <td class="py-2 pr-3">
                    <span class="rounded px-1.5 py-0.5" x-bind:class="c.verified ? 'bg-emerald-50 text-emerald-800' : 'bg-amber-50 text-amber-800'" x-text="c.verified ? 'verified ' + (c.verified_at || '') : 'unverified'"></span>
                  </td>
                  <td class="py-2 pr-3">
                    <span class="rounded px-1.5 py-0.5" x-bind:class="c.live_verified_at ? 'bg-emerald-50 text-emerald-800' : 'bg-gray-100 text-gray-600'" x-text="c.live_verified_at ? 'oo · ' + c.live_verified_at.slice(0, 10) : 'hindi pa'"></span>
                  </td>
                  <td class="py-2 text-right whitespace-nowrap">
                    <button type="button" class="text-indigo-700 hover:underline" x-on:click="editCap(c)">Edit</button>
                    <button type="button" class="ml-2 text-red-700 hover:underline" x-on:click="deleteCap(c)">Delete</button>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>

        <div class="mt-4 rounded-md border border-gray-200 p-4" x-show="capForm.open">
          <h3 class="text-sm font-semibold" x-text="capForm.id ? 'I-edit ang model' : 'Magdagdag ng model'"></h3>
          <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div><label class="block text-[11px] text-gray-500" for="cp-provider">Provider</label>
              <select id="cp-provider" x-model="capForm.provider" class="mt-0.5 block w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm">
                <template x-for="(label, key) in providers" x-bind:key="'cp' + key"><option x-bind:value="key" x-text="label"></option></template>
              </select></div>
            <div><label class="block text-[11px] text-gray-500" for="cp-model">Eksaktong model ID</label>
              <input id="cp-model" type="text" maxlength="120" x-model="capForm.model" class="mt-0.5 block w-full rounded-md border border-gray-300 px-2 py-1.5 font-mono text-sm"></div>
            <div><label class="block text-[11px] text-gray-500" for="cp-label">Label</label>
              <input id="cp-label" type="text" maxlength="160" x-model="capForm.label" class="mt-0.5 block w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm"></div>
            <div><label class="block text-[11px] text-gray-500" for="cp-struct">Structured output</label>
              <select id="cp-struct" x-model="capForm.structured_output" class="mt-0.5 block w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm">
                <option value="json_schema">json_schema</option><option value="json_object">json_object</option><option value="none">none (prompt lang)</option>
              </select></div>
            <div><label class="block text-[11px] text-gray-500" for="cp-efforts">Allowed efforts (comma)</label>
              <input id="cp-efforts" type="text" x-model="capForm.efforts" placeholder="low, medium, high" class="mt-0.5 block w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm"></div>
            <div><label class="block text-[11px] text-gray-500" for="cp-defeffort">Default effort</label>
              <input id="cp-defeffort" type="text" maxlength="20" x-model="capForm.default_effort" class="mt-0.5 block w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm"></div>
            <div><label class="block text-[11px] text-gray-500" for="cp-thinking">Thinking modes (comma)</label>
              <input id="cp-thinking" type="text" x-model="capForm.thinking_modes" placeholder="adaptive, disabled" class="mt-0.5 block w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm"></div>
            <div><label class="block text-[11px] text-gray-500" for="cp-defthinking">Default thinking</label>
              <input id="cp-defthinking" type="text" maxlength="20" x-model="capForm.default_thinking" class="mt-0.5 block w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm"></div>
            <div><label class="block text-[11px] text-gray-500" for="cp-maxout">Max output tokens</label>
              <input id="cp-maxout" type="number" min="16" x-model="capForm.max_output_tokens" class="mt-0.5 block w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm"></div>
            <div><label class="block text-[11px] text-gray-500" for="cp-context">Context window</label>
              <input id="cp-context" type="number" min="1000" x-model="capForm.context_window" class="mt-0.5 block w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm"></div>
            <div><label class="block text-[11px] text-gray-500" for="cp-pin">USD kada 1M input</label>
              <input id="cp-pin" type="number" min="0" step="0.0001" x-model="capForm.price_in" placeholder="hindi alam" class="mt-0.5 block w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm"></div>
            <div><label class="block text-[11px] text-gray-500" for="cp-pout">USD kada 1M output</label>
              <input id="cp-pout" type="number" min="0" step="0.0001" x-model="capForm.price_out" placeholder="hindi alam" class="mt-0.5 block w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm"></div>
            <div class="sm:col-span-2"><label class="block text-[11px] text-gray-500" for="cp-doc">Doc source (URL)</label>
              <input id="cp-doc" type="url" maxlength="500" x-model="capForm.doc_url" class="mt-0.5 block w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm"></div>
            <div><label class="block text-[11px] text-gray-500" for="cp-date">Petsa ng verification</label>
              <input id="cp-date" type="date" x-model="capForm.verified_at" class="mt-0.5 block w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm"></div>
            <div class="flex items-end"><label class="flex items-center gap-2 text-sm"><input type="checkbox" x-model="capForm.verified"> Verified sa docs</label></div>
            <div class="sm:col-span-2 lg:col-span-4"><label class="block text-[11px] text-gray-500" for="cp-notes">Notes</label>
              <textarea id="cp-notes" rows="2" maxlength="2000" x-model="capForm.notes" class="mt-0.5 block w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm"></textarea></div>
          </div>
          <p class="mt-2 text-xs text-red-700" x-show="capError" x-text="capError"></p>
          <div class="mt-3 flex justify-end gap-2">
            <button type="button" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm" x-on:click="capForm.open = false">Cancel</button>
            <button type="button" class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50" x-bind:disabled="busy" x-on:click="saveCap()">Save model</button>
          </div>
        </div>
      </section>
    </div>
  </div>

  <script>
    /* boardroom-agents */
    function boardroomAgents() {
      const API  = @json(url('/boardroom/api'));
      const csrf = () => document.querySelector('meta[name="csrf-token"]').content;
      const blankCap = () => ({ known: false, verified: false, efforts: [], thinking_modes: [], optional_params: {}, endpoint: null,
        max_output_tokens: null, price_in: null, price_out: null, default_effort: null, default_thinking: null, doc_url: null,
        verified_at: null, live_verified_at: null, notes: null });

      return {
        busy: false, error: '', saved: '', refreshed: '', formErrors: [], capError: '',
        agents: [], providers: {}, capabilities: [], groups: [], encryption: 'APP_KEY',
        defaults: { max_output_tokens: 16000, timeout_s: 240, timeout_max: 540 },
        current: null, testResult: null, fetched: null,
        form: { open: false, id: null, settings: {} },
        capForm: { open: false },

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
          const r = await this.api('GET', '/agents');
          if (!r.ok) { this.error = this.messagesOf(r.data)[0]; return; }
          this.load(r.data);
          if (this.agents.length) this.edit(this.agents[0]);
        },
        load(data) {
          if (data.agents) this.agents = data.agents;
          if (data.providers) this.providers = data.providers;
          if (data.capabilities) this.capabilities = data.capabilities;
          if (data.groups) this.groups = data.groups.map(g => ({ id: g.id, name: g.name, is_default: g.is_default, agent_ids: [...g.agent_ids] }));
          if (data.defaults) this.defaults = data.defaults;
          if (data.encryption) this.encryption = data.encryption;
          if (this.form.id) this.current = this.agents.find(a => a.id === this.form.id) || null;
        },
        replace(agent) {
          const i = this.agents.findIndex(a => a.id === agent.id);
          if (i === -1) this.agents.push(agent); else this.agents.splice(i, 1, agent);
          this.current = agent;
        },

        modelsFor(provider) { return this.capabilities.filter(c => c.provider === provider); },
        modelId() { return this.form.model_choice === '__manual__' ? String(this.form.model_manual || '').trim() : this.form.model_choice; },
        cap() { return this.capabilities.find(c => c.provider === this.form.provider && c.model === this.modelId()) || blankCap(); },
        advanced() { const c = this.cap(); return c.known && c.verified; },
        credential() { return this.current && this.current.provider === this.form.provider ? this.current.credential : null; },
        dirtyKey() { return String(this.form.api_key || '').trim() !== ''; },

        fill(a) {
          const known = this.capabilities.some(c => c.provider === a.provider && c.model === a.model);
          const s = a.settings || {};
          this.form = {
            open: true, id: a.id || null, handle: a.handle || '', display_name: a.display_name || '', role_type: a.role_type || 'contributor',
            description: a.description || '', instructions: a.instructions || '', enabled: a.enabled !== false,
            provider: a.provider || 'openai', model_choice: known ? a.model : '__manual__', model_manual: known ? '' : (a.model || ''),
            settings: { effort: s.effort || '', thinking: s.thinking || '', thinking_budget_tokens: s.thinking_budget_tokens || '',
              max_output_tokens: s.max_output_tokens || '', timeout_s: s.timeout_s || '' },
            api_key: '', key_label: '',
          };
        },
        edit(a) { this.current = a; this.fill(a); this.reset(); },
        newAgent() {
          this.current = null;
          const first = this.modelsFor('openai').find(c => c.verified);
          this.fill({ provider: 'openai', model: first ? first.model : '', role_type: 'contributor', enabled: true,
            settings: { effort: first ? first.default_effort : '' } });
          this.reset();
        },
        reset() { this.formErrors = []; this.saved = ''; this.refreshed = ''; this.testResult = null; this.fetched = null; },

        providerChanged() {
          const first = this.modelsFor(this.form.provider).find(c => c.verified) || this.modelsFor(this.form.provider)[0];
          this.form.model_choice = first ? first.model : '__manual__';
          this.form.model_manual = '';
          this.form.api_key = '';
          this.modelChanged();
        },
        // Pagpalit ng provider/model: i-refresh ang settings para sa mga value na supported lang ng bagong model.
        modelChanged() {
          const c = this.cap(); const s = this.form.settings; const changed = [];
          const fit = (key, list, fallback) => {
            if (s[key] && !list.includes(s[key])) { changed.push(key + ' "' + s[key] + '"'); s[key] = list.includes(fallback) ? fallback : ''; }
            else if (!s[key] && this.advanced() && list.includes(fallback)) { s[key] = fallback; }
          };
          fit('effort', this.advanced() ? c.efforts : [], c.default_effort);
          fit('thinking', this.advanced() ? c.thinking_modes : [], c.default_thinking);
          if (c.max_output_tokens && Number(s.max_output_tokens) > c.max_output_tokens) { changed.push('max output tokens'); s.max_output_tokens = c.max_output_tokens; }
          this.refreshed = changed.length ? 'Na-refresh para sa bagong model (hindi supported ang dati): ' + changed.join(', ') + '.' : '';
          this.testResult = null;
        },
        pickModel(id) {
          const known = this.capabilities.some(c => c.provider === this.form.provider && c.model === id);
          this.form.model_choice = known ? id : '__manual__';
          this.form.model_manual = known ? '' : id;
          this.modelChanged();
        },

        payload() {
          const f = this.form; const s = {};
          Object.entries(f.settings).forEach(([k, v]) => { if (v !== '' && v !== null && v !== undefined) s[k] = (k === 'effort' || k === 'thinking') ? v : Number(v); });
          const body = { handle: String(f.handle).toUpperCase().trim(), display_name: f.display_name, role_type: f.role_type, description: f.description || null,
            instructions: f.instructions || null, provider: f.provider, model: this.modelId() || null, enabled: !!f.enabled, settings: s };
          if (this.dirtyKey()) { body.api_key = f.api_key.trim(); body.key_label = f.key_label || null; }
          return body;
        },

        async save() {
          this.busy = true; this.reset();
          const r = this.form.id ? await this.api('PUT', '/agents/' + this.form.id, this.payload()) : await this.api('POST', '/agents', this.payload());
          this.busy = false;
          this.form.api_key = '';   // huwag nang itago sa page ang na-type na key, matagumpay man o hindi
          if (!r.ok) { this.formErrors = this.messagesOf(r.data); return; }
          this.replace(r.data.agent);
          this.fill(r.data.agent);
          this.saved = 'Na-save ang ' + r.data.agent.display_name + '.';
        },

        async removeKey() {
          if (!window.confirm('Tanggalin ang API key ng role na ito? Hindi na ito makakasali sa meeting hangga\'t walang bagong key.')) return;
          const r = await this.api('DELETE', '/agents/' + this.form.id + '/credential');
          if (r.ok) { this.replace(r.data.agent); this.saved = 'Natanggal ang key.'; } else this.formErrors = this.messagesOf(r.data);
        },

        async test() {
          if (!window.confirm('Gagawa ito ng maliit pero TOTOONG request sa provider gamit ang key ng role na ito. Maaari itong kumonsumo ng kaunting credits. Ituloy?')) return;
          this.busy = true; this.testResult = null;
          const r = await this.api('POST', '/agents/' + this.form.id + '/test');
          this.busy = false;
          this.testResult = r.data && typeof r.data.ok !== 'undefined' ? r.data : { ok: false, message: this.messagesOf(r.data)[0] };
          if (r.data && r.data.agent) this.replace(r.data.agent);
          const reload = await this.api('GET', '/agents');
          if (reload.ok) this.load(reload.data);
        },

        async fetchModels() {
          this.busy = true; this.fetched = null;
          const r = await this.api('POST', '/agents/' + this.form.id + '/models');
          this.busy = false;
          this.fetched = { ok: !!r.data.ok, models: r.data.models || [], message: r.data.message || this.messagesOf(r.data)[0] };
        },

        async archive(restore) {
          if (!restore && !window.confirm('I-archive ang role na ito? Hindi na ito maisasali sa bagong meeting. Mananatili ang history.')) return;
          const r = await this.api('POST', '/agents/' + this.form.id + '/archive', { restore });
          if (r.ok) { this.load(r.data); this.saved = restore ? 'Naibalik ang role.' : 'Na-archive ang role.'; } else this.formErrors = this.messagesOf(r.data);
        },

        async saveGroup(g) {
          const r = await this.api('PUT', '/groups/' + g.id, { name: g.name, agent_ids: g.agent_ids.map(Number) });
          if (r.ok) this.load(r.data); else this.error = this.messagesOf(r.data)[0];
        },

        editCap(c) {
          this.capError = '';
          const list = v => (v || []).join(', ');
          this.capForm = c ? { open: true, id: c.id, provider: c.provider, model: c.model, label: c.label || '', structured_output: c.structured_output,
              efforts: list(c.efforts), default_effort: c.default_effort || '', thinking_modes: list(c.thinking_modes), default_thinking: c.default_thinking || '',
              max_output_tokens: c.max_output_tokens || '', context_window: c.context_window || '', price_in: c.price_in === null ? '' : c.price_in,
              price_out: c.price_out === null ? '' : c.price_out, doc_url: c.doc_url || '', verified_at: c.verified_at || '', verified: !!c.verified, notes: c.notes || '' }
            : { open: true, id: null, provider: 'openai', model: '', label: '', structured_output: 'none', efforts: '', default_effort: '', thinking_modes: '',
              default_thinking: '', max_output_tokens: '', context_window: '', price_in: '', price_out: '', doc_url: '', verified_at: '', verified: false, notes: '' };
        },
        async saveCap() {
          const f = this.capForm;
          const split = v => String(v || '').split(',').map(x => x.trim().toLowerCase()).filter(Boolean);
          const n = v => (v === '' || v === null ? null : Number(v));
          this.busy = true; this.capError = '';
          const r = await this.api('POST', '/capabilities', { id: f.id, provider: f.provider, model: f.model.trim(), label: f.label || null,
            structured_output: f.structured_output, efforts: split(f.efforts), default_effort: f.default_effort || null, thinking_modes: split(f.thinking_modes),
            default_thinking: f.default_thinking || null, max_output_tokens: n(f.max_output_tokens), context_window: n(f.context_window),
            price_in: n(f.price_in), price_out: n(f.price_out), doc_url: f.doc_url || null, verified_at: f.verified_at || null, verified: !!f.verified, notes: f.notes || null });
          this.busy = false;
          if (!r.ok) { this.capError = this.messagesOf(r.data).join(' '); return; }
          this.load(r.data); this.capForm.open = false;
        },
        async deleteCap(c) {
          if (!window.confirm('Tanggalin ang ' + c.model + ' sa registry? Ang mga role na gumagamit nito ay magiging unverified.')) return;
          const r = await this.api('DELETE', '/capabilities/' + c.id);
          if (r.ok) this.load(r.data);
        },

        avatar(type) { return { moderator: 'bg-indigo-600', contributor: 'bg-emerald-600', reviewer: 'bg-rose-600' }[type] || 'bg-slate-500'; },
        num(n) { return Number(n || 0).toLocaleString('en-US'); },
      };
    }
  </script>
</x-layout>

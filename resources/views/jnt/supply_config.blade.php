<x-layout>
  <x-slot name="title">Supply — Config</x-slot>
  <x-slot name="heading">Supply Planner · Configuration</x-slot>

  <style>
    .inline-num { width:60px; border:1px solid #d1d5db; border-radius:4px; padding:2px 6px;
                  font-size:12px; text-align:center; background:white; }
    .inline-num:focus { outline:none; border-color:#2563eb; }
    .class-badge { display:inline-block; padding:2px 10px; border-radius:9999px; font-size:12px; font-weight:800; cursor:default; letter-spacing:.04em; }
    .save-toast { position:fixed; bottom:24px; right:24px; background:#16a34a; color:white;
                  padding:8px 18px; border-radius:8px; font-size:13px; font-weight:600;
                  display:none; z-index:9999; box-shadow:0 4px 12px rgba(0,0,0,.15); }
  </style>

  <div class="mx-auto px-4 py-4" style="max-width:100%;">

    <div class="mb-4 flex items-center gap-3 flex-wrap">
      <a href="{{ url('/jnt/supply') }}"
         class="text-sm px-3 py-1.5 rounded-md border border-gray-300 hover:bg-gray-50 text-gray-700">
        ← Back to Supply
      </a>
      <h2 class="text-lg font-semibold text-gray-800">Classification Rules &amp; Other Settings</h2>
      <a href="{{ url('/jnt/supply/excluded-pages') }}"
         class="ml-auto text-sm px-3 py-1.5 rounded-md border border-rose-300 bg-rose-50 hover:bg-rose-100 text-rose-700 font-semibold">
        <i class="fa-solid fa-ban mr-1"></i> Excluded Pages (multi-item)
      </a>
    </div>

    {{-- ================================================================== --}}
    {{-- Classification Rules CRUD                                           --}}
    {{-- ================================================================== --}}
    <details class="bg-white rounded-lg shadow border border-indigo-200 mb-4" open>
      <summary class="px-4 py-3 font-semibold text-sm text-indigo-700 cursor-pointer select-none flex items-center gap-2">
        <i class="fa-solid fa-sliders text-indigo-500"></i>
        Classification Rules
        <span class="text-xs font-normal text-gray-400 ml-1">CEO only — create / edit / delete / reorder</span>
      </summary>
      <div class="px-4 pb-4 pt-2" id="class-rules-panel">
        <p class="text-xs text-gray-500 mb-3">
          <strong>Evaluated in priority order</strong> (smaller sort_order = evaluated first). First match wins.
          Special classes: <strong>Y</strong> = age-based (new item), <strong>X</strong> = catch-all. Lahat ng iba ay velocity tiers.
          <span class="text-rose-600">class_key</span> immutable once created — label/thresholds editable anytime.
        </p>
        <table class="text-sm border-collapse w-full mb-4" id="class-rules-table">
          <thead>
            <tr class="bg-gray-50">
              <th class="px-2 py-2 text-center text-xs font-semibold text-gray-500 uppercase border border-gray-200 w-14">Prio</th>
              <th class="px-2 py-2 text-center text-xs font-semibold text-gray-500 uppercase border border-gray-200 w-16">Key</th>
              <th class="px-2 py-2 text-left  text-xs font-semibold text-gray-500 uppercase border border-gray-200 w-48">Label</th>
              <th class="px-2 py-2 text-center text-xs font-semibold text-gray-500 uppercase border border-gray-200 w-36">Badge color</th>
              <th class="px-2 py-2 text-center text-xs font-semibold text-gray-500 uppercase border border-gray-200 w-20">Type</th>
              <th class="px-2 py-2 text-center text-xs font-semibold text-gray-500 uppercase border border-gray-200 w-24">Min u/day</th>
              <th class="px-2 py-2 text-center text-xs font-semibold text-gray-500 uppercase border border-gray-200 w-20">Window (d)</th>
              <th class="px-2 py-2 text-center text-xs font-semibold text-emerald-600 uppercase border border-gray-200 w-24">Alive min</th>
              <th class="px-2 py-2 text-center text-xs font-semibold text-emerald-600 uppercase border border-gray-200 w-20">Alive win</th>
              <th class="px-2 py-2 text-center text-xs font-semibold text-orange-600 uppercase border border-gray-200 w-24">Age &lt; days</th>
              <th class="px-2 py-2 border border-gray-200 w-28"></th>
            </tr>
          </thead>
          <tbody id="class-rules-tbody">
            @foreach($classRules as $cr)
              @php
                $isAgeLt   = $cr->rule_type === 'age_lt';
                $isCatch   = $cr->rule_type === 'catch_all';
                $isVelTier = $cr->rule_type === 'velocity_tier';
                $rowBg     = $cr->is_fixed ? ($isCatch ? 'bg-gray-50' : 'bg-orange-50') : '';
              @endphp
              <tr class="border-b border-gray-100 rule-row {{ $rowBg }}" data-rule-id="{{ $cr->id }}" data-rule-type="{{ $cr->rule_type }}" data-fixed="{{ $cr->is_fixed ? '1' : '0' }}">
                <td class="px-2 py-2 border border-gray-200 text-center">
                  <input type="number" class="inline-num rule-f" data-f="sort_order" style="width:50px;"
                         value="{{ $cr->sort_order }}" min="1" max="998" {{ $isCatch ? 'readonly' : '' }}>
                </td>
                <td class="px-2 py-2 border border-gray-200 text-center font-mono text-gray-600 text-xs">
                  {{ $cr->class_key }}
                  @if($cr->is_fixed)<div class="text-[9px] text-orange-600 uppercase">fixed</div>@endif
                </td>
                <td class="px-2 py-2 border border-gray-200">
                  <div class="flex items-center gap-1">
                    <span class="class-badge rule-badge-preview {{ $cr->badge_tailwind }}">{{ $cr->label }}</span>
                  </div>
                  <input type="text" class="inline-num rule-f mt-1 w-full" data-f="label"
                         value="{{ $cr->label }}" style="min-width:160px;">
                </td>
                <td class="px-2 py-2 border border-gray-200">
                  <select class="inline-num rule-f w-full" data-f="badge_tailwind" style="font-size:11px;">
                    @foreach($classPalette as $cls => $name)
                      <option value="{{ $cls }}" {{ $cr->badge_tailwind === $cls ? 'selected' : '' }}>{{ $name }}</option>
                    @endforeach
                  </select>
                </td>
                <td class="px-2 py-2 border border-gray-200 text-center text-xs uppercase text-gray-500">
                  @if($isAgeLt)<span class="text-orange-600">age&lt;</span>
                  @elseif($isCatch)<span class="text-gray-500">catch</span>
                  @else<span class="text-indigo-600">tier</span>@endif
                </td>
                @if($isVelTier)
                  <td class="px-2 py-2 border border-gray-200 text-center">
                    <input type="number" class="inline-num rule-f" data-f="min_velocity" style="width:70px;"
                           value="{{ $cr->min_velocity }}" min="0" step="0.1">
                  </td>
                  <td class="px-2 py-2 border border-gray-200 text-center">
                    <input type="number" class="inline-num rule-f" data-f="window_days" style="width:60px;"
                           value="{{ $cr->window_days }}" min="1" max="365" step="1">
                  </td>
                  <td class="px-2 py-2 border border-gray-200 text-center bg-emerald-50">
                    <input type="number" class="inline-num rule-f" data-f="alive_min" style="width:60px;"
                           value="{{ $cr->alive_min }}" min="0" step="0.1">
                  </td>
                  <td class="px-2 py-2 border border-gray-200 text-center bg-emerald-50">
                    <input type="number" class="inline-num rule-f" data-f="alive_window" style="width:55px;"
                           value="{{ $cr->alive_window }}" min="1" max="365" step="1">
                  </td>
                  <td class="px-2 py-2 border border-gray-200 text-center text-gray-300">—</td>
                @elseif($isAgeLt)
                  <td class="px-2 py-2 border border-gray-200 text-center text-gray-300">—</td>
                  <td class="px-2 py-2 border border-gray-200 text-center bg-yellow-50" title="Non-bearing for classification — used only for Proj Profit% window.">
                    <input type="number" class="inline-num rule-f" data-f="window_days" style="width:60px;"
                           value="{{ $cr->window_days ?? 30 }}" min="1" max="365" step="1">
                  </td>
                  <td class="px-2 py-2 border border-gray-200 text-center text-gray-300" colspan="2">—</td>
                  <td class="px-2 py-2 border border-gray-200 text-center bg-orange-50">
                    <input type="number" class="inline-num rule-f" data-f="age_threshold" style="width:60px;"
                           value="{{ $cr->age_threshold }}" min="1" max="365" step="1">
                  </td>
                @else
                  {{-- catch_all: only window_days (for Profit% compute) --}}
                  <td class="px-2 py-2 border border-gray-200 text-center text-gray-300">—</td>
                  <td class="px-2 py-2 border border-gray-200 text-center bg-yellow-50" title="Non-bearing for classification — used only for Proj Profit% window.">
                    <input type="number" class="inline-num rule-f" data-f="window_days" style="width:60px;"
                           value="{{ $cr->window_days ?? 30 }}" min="1" max="365" step="1">
                  </td>
                  <td class="px-2 py-2 border border-gray-200 text-center text-gray-500 italic" colspan="3">Catch-all — always matches (last resort)</td>
                @endif
                <td class="px-2 py-2 border border-gray-200 text-center">
                  <div class="flex items-center gap-1 justify-center">
                    <button type="button" class="rule-save-btn text-xs bg-indigo-600 hover:bg-indigo-700 text-white px-2 py-1 rounded">Save</button>
                    @if(!$cr->is_fixed)
                      <button type="button" class="rule-delete-btn text-xs bg-red-600 hover:bg-red-700 text-white px-2 py-1 rounded" title="Delete rule">×</button>
                    @endif
                  </div>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>

        {{-- Create new tier form --}}
        <details class="bg-emerald-50 rounded-lg border border-emerald-200 p-3 mb-3">
          <summary class="text-sm font-semibold text-emerald-700 cursor-pointer">+ Add new velocity tier</summary>
          <div class="mt-3 grid grid-cols-1 md:grid-cols-4 gap-3" id="new-rule-form">
            <div>
              <label class="block text-xs text-gray-500 mb-1">Key (e.g. G) — <span class="text-rose-600">immutable</span></label>
              <input type="text" id="nr-key" class="border rounded px-2 py-1 w-full uppercase" maxlength="10" placeholder="G">
            </div>
            <div>
              <label class="block text-xs text-gray-500 mb-1">Label</label>
              <input type="text" id="nr-label" class="border rounded px-2 py-1 w-full" maxlength="80" placeholder="G · Premium">
            </div>
            <div>
              <label class="block text-xs text-gray-500 mb-1">Badge color</label>
              <select id="nr-badge" class="border rounded px-2 py-1 w-full">
                @foreach($classPalette as $cls => $name)
                  <option value="{{ $cls }}">{{ $name }}</option>
                @endforeach
              </select>
            </div>
            <div>
              <label class="block text-xs text-gray-500 mb-1">Priority (sort)</label>
              <input type="number" id="nr-sort" class="border rounded px-2 py-1 w-full" value="{{ ($classRules->where('rule_type','velocity_tier')->max('sort_order') ?? 0) + 10 }}" min="1" max="998">
            </div>
            <div>
              <label class="block text-xs text-gray-500 mb-1">Min u/day</label>
              <input type="number" id="nr-min" class="border rounded px-2 py-1 w-full" min="0" step="0.1" value="10">
            </div>
            <div>
              <label class="block text-xs text-gray-500 mb-1">Window (days)</label>
              <input type="number" id="nr-win" class="border rounded px-2 py-1 w-full" min="1" max="365" value="14">
            </div>
            <div>
              <label class="block text-xs text-gray-500 mb-1">Alive min</label>
              <input type="number" id="nr-alivemin" class="border rounded px-2 py-1 w-full" min="0" step="0.1" value="5">
            </div>
            <div>
              <label class="block text-xs text-gray-500 mb-1">Alive window</label>
              <input type="number" id="nr-alivewin" class="border rounded px-2 py-1 w-full" min="1" max="365" value="7">
            </div>
            <div class="md:col-span-4 flex justify-end">
              <button type="button" id="nr-create-btn" class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-1.5 rounded text-sm">
                Create Class
              </button>
            </div>
          </div>
        </details>

        <p class="text-xs text-gray-400">
          After saving any change, <a href="{{ url('/jnt/supply') }}" class="text-indigo-600 underline">go back to Supply</a> to see items reclassified. Delete is blocked kung may items pa referencing the class.
        </p>
      </div>
    </details>

    {{-- ================================================================== --}}
    {{-- Other Settings (lifecycle + velocity non-classification defaults)   --}}
    {{-- ================================================================== --}}
    @php
      $otherSettings = $supplySettingsAll->filter(fn($s) => !in_array($s->group, ['class_a','class_b','class_c','class_d','class_e','class_f','class_abc']));
    @endphp
    @if($otherSettings->count() > 0)
    <details class="bg-white rounded-lg shadow border border-emerald-200 mb-4" open>
      <summary class="px-4 py-3 font-semibold text-sm text-emerald-700 cursor-pointer select-none flex items-center gap-2">
        <i class="fa-solid fa-gears text-emerald-500"></i>
        Other Settings (Lifecycle &amp; Velocity defaults)
        <span class="text-xs font-normal text-gray-400 ml-1">CEO only</span>
      </summary>
      <div class="px-4 pb-4 pt-2">
        <table class="text-sm border-collapse w-full">
          <thead>
            <tr class="bg-gray-50">
              <th class="px-3 py-1 text-left text-xs font-semibold text-gray-500 uppercase border border-gray-200">Setting</th>
              <th class="px-3 py-1 text-left text-xs font-semibold text-gray-500 uppercase border border-gray-200 w-32">Value</th>
              <th class="px-3 py-1 border border-gray-200 w-16"></th>
            </tr>
          </thead>
          <tbody>
            @foreach($otherSettings as $s)
            <tr class="border-b border-gray-100">
              <td class="px-3 py-1.5 border border-gray-200 text-gray-700">
                {{ $s->label }}
                <code class="ml-1 text-[10px] text-gray-400">{{ $s->key }}</code>
              </td>
              <td class="px-3 py-1.5 border border-gray-200">
                <input type="number" class="inline-num supply-kv-input" style="width:100px;"
                       step="{{ $s->data_type === 'int' ? '1' : '0.001' }}" min="0"
                       data-key="{{ $s->key }}" value="{{ $s->value }}">
              </td>
              <td class="px-3 py-1.5 border border-gray-200 text-center">
                <button type="button"
                        class="save-kv-btn text-xs bg-emerald-600 hover:bg-emerald-700 text-white px-2 py-1 rounded"
                        data-key="{{ $s->key }}">Save</button>
              </td>
            </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </details>
    @endif

    {{-- ================================================================== --}}
    {{-- Days Last Order — Color Rules                                       --}}
    {{-- ================================================================== --}}
    @if($isCeo)
    <details class="bg-white rounded-lg shadow border border-amber-200 mb-4" open
             x-data="dloColorRulesEditor()">
      <summary class="cursor-pointer px-4 py-3 font-semibold text-amber-800 bg-amber-50 rounded-t-lg flex items-center justify-between">
        <span>🎨 Days Last Order — Color Rules</span>
        <span class="text-xs font-normal text-amber-700">Conditional formatting para sa "Days Last Order" column sa /jnt/supply</span>
      </summary>
      <div class="p-4">
        <p class="text-xs text-gray-600 mb-3">
          Bawat rule may <b>operator</b>, <b>value (days)</b>, at <b>color</b>. Top-to-bottom evaluation —
          <b>first match wins</b>. Halimbawa: gusto mo red kapag ≥5 days, ilagay sa unang row na operator <code>≥</code>, value <code>5</code>, color red.
          Drag <span class="text-gray-400">⋮⋮</span> to reorder. Empty rule list = walang coloring.
        </p>

        <table class="w-full text-sm border-collapse">
          <thead>
            <tr class="bg-gray-50 text-xs text-gray-600 uppercase tracking-wide">
              <th class="px-2 py-1 text-left  border border-gray-200 w-8"></th>
              <th class="px-2 py-1 text-left  border border-gray-200">If days last order is</th>
              <th class="px-2 py-1 text-left  border border-gray-200 w-24">Operator</th>
              <th class="px-2 py-1 text-left  border border-gray-200 w-24">Value (days)</th>
              <th class="px-2 py-1 text-left  border border-gray-200 w-32">Color</th>
              <th class="px-2 py-1 text-left  border border-gray-200">Label (optional)</th>
              <th class="px-2 py-1 text-center border border-gray-200 w-16">Bold?</th>
              <th class="px-2 py-1 text-center border border-gray-200 w-24">Preview</th>
              <th class="px-2 py-1 text-center border border-gray-200 w-16"></th>
            </tr>
          </thead>
          <tbody>
            <template x-for="(r, i) in rules" :key="i">
              <tr class="border-b border-gray-100">
                <td class="px-2 py-1 border border-gray-200 text-gray-400 cursor-move select-none"
                    draggable="true"
                    @dragstart="dragIdx = i"
                    @dragover.prevent
                    @drop="onDrop(i)">⋮⋮</td>
                <td class="px-2 py-1 border border-gray-200 text-gray-500">if days</td>
                <td class="px-2 py-1 border border-gray-200">
                  <select x-model="r.op" class="w-full border rounded px-1 py-0.5 text-sm">
                    <option value=">=">≥ (greater or equal)</option>
                    <option value=">">&gt; (greater than)</option>
                    <option value="=">= (equal to)</option>
                    <option value="<=">≤ (less or equal)</option>
                    <option value="<">&lt; (less than)</option>
                  </select>
                </td>
                <td class="px-2 py-1 border border-gray-200">
                  <input type="number" min="0" step="1" x-model.number="r.value"
                         class="w-full border rounded px-2 py-0.5 text-sm">
                </td>
                <td class="px-2 py-1 border border-gray-200">
                  <input type="color" x-model="r.color" class="w-12 h-7 cursor-pointer border rounded">
                  <input type="text"  x-model="r.color" maxlength="7"
                         class="w-20 border rounded px-1 py-0.5 text-xs ml-1 font-mono"
                         placeholder="#rrggbb">
                </td>
                <td class="px-2 py-1 border border-gray-200">
                  <input type="text" x-model="r.label" maxlength="40"
                         placeholder="e.g. Cold"
                         class="w-full border rounded px-2 py-0.5 text-sm">
                </td>
                <td class="px-2 py-1 border border-gray-200 text-center">
                  <input type="checkbox" x-model="r.bold">
                </td>
                <td class="px-2 py-1 border border-gray-200 text-center"
                    :style="'background:'+r.color+';color:'+previewTextColor(r.color)+';'+(r.bold?'font-weight:700;':'')">
                  <span x-text="r.value ?? 0"></span>
                </td>
                <td class="px-2 py-1 border border-gray-200 text-center">
                  <button type="button" @click="rules.splice(i,1)"
                          class="text-red-600 hover:text-red-800 text-xs">✕</button>
                </td>
              </tr>
            </template>
            <template x-if="rules.length === 0">
              <tr><td colspan="9" class="px-2 py-4 text-center text-gray-400 italic border border-gray-200">
                No rules configured — Days Last Order column will use default text color.
              </td></tr>
            </template>
          </tbody>
        </table>

        <div class="flex gap-2 mt-3">
          <button type="button" @click="addRule()"
                  class="text-sm px-3 py-1.5 rounded-md border border-gray-300 hover:bg-gray-50 text-gray-700">
            + Add rule
          </button>
          <button type="button" @click="resetDefaults()"
                  class="text-sm px-3 py-1.5 rounded-md border border-gray-300 hover:bg-gray-50 text-gray-700">
            Reset to defaults
          </button>
          <div class="flex-1"></div>
          <button type="button" @click="save()"
                  :disabled="saving"
                  class="text-sm px-4 py-1.5 rounded-md bg-amber-600 hover:bg-amber-700 text-white font-semibold disabled:opacity-50">
            <span x-show="!saving">💾 Save Rules</span>
            <span x-show="saving">Saving…</span>
          </button>
        </div>
      </div>
    </details>
    @endif

  </div>

  <div class="save-toast" id="saveToast">✓ Saved</div>

  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script>
    const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';

    let toastTimer;
    function showToast(msg = '✓ Saved') {
      const t = document.getElementById('saveToast');
      t.textContent = msg;
      t.style.display = 'block';
      clearTimeout(toastTimer);
      toastTimer = setTimeout(() => t.style.display = 'none', 2000);
    }
    function rulePanelErr(msg) { alert(msg); }

    // --- Save (update) existing rule ---
    document.addEventListener('click', function (e) {
      if (!e.target.classList.contains('rule-save-btn')) return;
      const tr = e.target.closest('tr.rule-row');
      if (!tr) return;
      const id = tr.dataset.ruleId;
      const payload = {};
      tr.querySelectorAll('.rule-f').forEach(inp => {
        const f = inp.dataset.f;
        let v = inp.value;
        if (inp.type === 'number') v = v === '' ? null : Number(v);
        payload[f] = v;
      });
      e.target.disabled = true;
      fetch(`/jnt/supply/class-rules/${id}`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
        body: JSON.stringify(payload),
      })
      .then(r => r.json())
      .then(d => {
        e.target.disabled = false;
        if (d.success) {
          const preview = tr.querySelector('.rule-badge-preview');
          if (preview && d.rule) {
            preview.className = 'class-badge rule-badge-preview ' + d.rule.badge_tailwind;
            preview.textContent = d.rule.label;
          }
          showToast('✓ Saved rule ' + (d.rule?.class_key || ''));
        } else {
          rulePanelErr(d.error || 'Save failed');
        }
      })
      .catch(err => { console.error(err); e.target.disabled = false; rulePanelErr('Network error'); });
    });

    // --- Delete rule ---
    document.addEventListener('click', function (e) {
      if (!e.target.classList.contains('rule-delete-btn')) return;
      const tr = e.target.closest('tr.rule-row');
      if (!tr) return;
      const id = tr.dataset.ruleId;
      const key = tr.querySelector('td:nth-child(2)')?.textContent.trim().split('\n')[0] || '';
      if (!confirm(`Delete class "${key}"? Items referencing this class must be reassigned first, otherwise server will block.`)) return;
      e.target.disabled = true;
      fetch(`/jnt/supply/class-rules/${id}`, {
        method: 'DELETE',
        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
      })
      .then(r => r.json())
      .then(d => {
        e.target.disabled = false;
        if (d.success) {
          tr.remove();
          showToast('✓ Deleted ' + key);
        } else {
          rulePanelErr(d.error || 'Delete failed');
        }
      })
      .catch(err => { console.error(err); e.target.disabled = false; rulePanelErr('Network error'); });
    });

    // --- Create new rule ---
    document.getElementById('nr-create-btn')?.addEventListener('click', function () {
      const payload = {
        class_key:      (document.getElementById('nr-key').value || '').trim().toUpperCase(),
        label:          document.getElementById('nr-label').value.trim(),
        badge_tailwind: document.getElementById('nr-badge').value,
        sort_order:     parseInt(document.getElementById('nr-sort').value, 10),
        min_velocity:   parseFloat(document.getElementById('nr-min').value),
        window_days:    parseInt(document.getElementById('nr-win').value, 10),
        alive_min:      parseFloat(document.getElementById('nr-alivemin').value),
        alive_window:   parseInt(document.getElementById('nr-alivewin').value, 10),
      };
      if (!payload.class_key || !payload.label) {
        rulePanelErr('Key and label required'); return;
      }
      this.disabled = true;
      fetch('/jnt/supply/class-rules', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
        body: JSON.stringify(payload),
      })
      .then(r => r.json())
      .then(d => {
        this.disabled = false;
        if (d.success) {
          showToast('✓ Created class ' + d.rule.class_key + ' — reloading');
          setTimeout(() => location.reload(), 800);
        } else {
          rulePanelErr(d.error || 'Create failed');
        }
      })
      .catch(err => { console.error(err); this.disabled = false; rulePanelErr('Network error'); });
    });

    // --- Live preview ---
    document.addEventListener('input', function (e) {
      const tr = e.target.closest?.('tr.rule-row');
      if (!tr) return;
      if (!e.target.classList.contains('rule-f')) return;
      const preview = tr.querySelector('.rule-badge-preview');
      if (!preview) return;
      const f = e.target.dataset.f;
      if (f === 'label') preview.textContent = e.target.value;
      if (f === 'badge_tailwind') preview.className = 'class-badge rule-badge-preview ' + e.target.value;
    });
    document.addEventListener('change', function (e) {
      if (!e.target.classList.contains('rule-f')) return;
      const tr = e.target.closest('tr.rule-row');
      if (!tr) return;
      const preview = tr.querySelector('.rule-badge-preview');
      if (!preview) return;
      const f = e.target.dataset.f;
      if (f === 'badge_tailwind') preview.className = 'class-badge rule-badge-preview ' + e.target.value;
    });

    // --- Other Settings KV save ---
    document.addEventListener('click', function (e) {
      if (!e.target.classList.contains('save-kv-btn')) return;
      const key   = e.target.dataset.key;
      const input = document.querySelector(`.supply-kv-input[data-key="${key}"]`);
      if (!input) return;
      const val = input.value.trim();
      if (val === '' || isNaN(parseFloat(val))) { alert('Invalid value'); return; }

      e.target.disabled = true;
      fetch('/jnt/supply/setting-kv', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
        body: JSON.stringify({ key, value: val }),
      })
      .then(r => r.json())
      .then(d => {
        e.target.disabled = false;
        if (d.success) showToast('✓ ' + key + ' = ' + d.value);
        else alert(d.error || 'Save failed');
      })
      .catch(err => { console.error(err); e.target.disabled = false; });
    });

    // ─── Days Last Order — Color Rules editor ─────────────────────────────
    function dloColorRulesEditor() {
      return {
        rules: @json($colorRules ?? []),
        saving: false,
        dragIdx: -1,
        // Auto-pick black/white text based on background luminance (gsheet trick).
        previewTextColor(hex) {
          const m = /^#([0-9a-f]{6})$/i.exec(hex || '');
          if (!m) return '#111827';
          const r = parseInt(m[1].slice(0,2), 16);
          const g = parseInt(m[1].slice(2,4), 16);
          const b = parseInt(m[1].slice(4,6), 16);
          const yiq = (r*299 + g*587 + b*114) / 1000;
          return yiq >= 150 ? '#111827' : '#ffffff';
        },
        addRule() {
          this.rules.push({ op:'>=', value:0, color:'#fde68a', label:'', bold:false });
        },
        resetDefaults() {
          if (!confirm('Reset to default color rules? Hindi pa nase-save until you click Save.')) return;
          // Background-friendly palette — light enough to read black text on,
          // matches gsheet conditional-formatting defaults.
          this.rules = [
            { op:'>=', value:30, color:'#fecaca', label:'Cold (≥30d)',   bold:true  },
            { op:'>=', value:7,  color:'#fed7aa', label:'Slowing (≥7d)', bold:false },
            { op:'>=', value:2,  color:'#fef3c7', label:'Normal (≥2d)',  bold:false },
            { op:'<',  value:2,  color:'#bbf7d0', label:'Fresh (<2d)',   bold:true  },
          ];
        },
        onDrop(targetIdx) {
          if (this.dragIdx < 0 || this.dragIdx === targetIdx) return;
          const moved = this.rules.splice(this.dragIdx, 1)[0];
          this.rules.splice(targetIdx, 0, moved);
          this.dragIdx = -1;
        },
        async save() {
          this.saving = true;
          try {
            const r = await fetch('{{ route('jnt.supply.dlo-color-rules.save') }}', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
              body: JSON.stringify({ rules: this.rules }),
            });
            const j = await r.json();
            if (j.ok) {
              this.rules = j.rules;
              showToast('✓ Color rules saved');
            } else {
              alert(j.error || 'Save failed');
            }
          } catch(e) {
            console.error(e); alert('Save failed: '+e.message);
          } finally {
            this.saving = false;
          }
        },
      };
    }
  </script>
</x-layout>

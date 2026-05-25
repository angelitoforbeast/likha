{{--
  Reusable conditional-formatting section. Used twice on /owner/column-settings:
    - one for owner_private (Page Summary)
    - one for campaigns (Campaigns / Ad Sets / Ads)

  Required scope:
    $sectionTitle    — h3 text
    $sectionKey      — small monospace label (storage key hint)
    $tableId         — 'owner_private' | 'campaigns'
    $tableCatalog    — array of {id,label} for this table's columns
    $initialGroups   — pre-saved groups for this table
    $note            — HTML note string
    $allowRefValues  — bool, when true a rule's value can be a cross-table ref

  The Alpine component below is unique per section because of x-data scope.
--}}
<div class="col-section"
     x-data="colFormatEditor(@js($tableId), @js($tableCatalog), @js($catalog['owner_private'] ?? []), @js($initialGroups), {{ $breakevenTargetPct ?? 5 }})">
  <div class="flex items-baseline justify-between mb-1">
    <h3>{!! $sectionTitle !!}</h3>
    <span class="text-[10px] text-slate-400">{{ $sectionKey }}</span>
  </div>
  <p class="note">{!! $note !!}</p>

  <div class="flex items-center gap-2 mt-3">
    <button class="reset-btn" @click="addGroup()">+ New rule group</button>
    <div class="flex-1"></div>
    <button class="save-btn" :disabled="saving" @click="save()">
      <span x-show="!saving">💾 Save formatting</span>
      <span x-show="saving">Saving…</span>
    </button>
  </div>

  <div x-show="groups.length === 0"
       class="text-xs text-slate-400 italic px-3 py-6 text-center mt-3"
       style="border:1px dashed #cbd5e1;border-radius:6px;">
    Walang rule groups pa. Click <b>"+ New rule group"</b> para magsimula.
  </div>

  <template x-for="(g, gIdx) in groups" :key="'g-'+gIdx">
    <div class="rule-group" x-data="{ open: false }">
      <div class="rule-group-header" style="cursor:pointer;align-items:flex-start;flex-wrap:wrap;gap:6px;" @click="open = !open">
        <div class="text-xs font-semibold text-slate-700 flex items-center flex-wrap gap-2" style="flex:1;">
          <span class="cf-chevron" :style="open ? 'transform:rotate(90deg)' : ''" style="transition:transform .15s ease;display:inline-block;">▶</span>
          📑 Group #<span x-text="gIdx + 1"></span>
          <span class="text-slate-500 font-normal" x-show="g.rules.length > 0">·
            <span x-text="g.rules.length"></span> rule(s) ·
          </span>
          {{-- Show applied column LABELS as chip-like preview (visible even when collapsed) --}}
          <template x-if="g.cols.length > 0">
            <span class="flex flex-wrap gap-1 items-center">
              <span class="text-[10px] text-slate-500">applies to:</span>
              <template x-for="cid in g.cols" :key="'preview-'+gIdx+'-'+cid">
                <span class="text-[10px] font-semibold px-1.5 py-0.5 rounded"
                      style="background:#e0e7ff;color:#3730a3;border:1px solid #c7d2fe;"
                      x-text="labelForCol(cid)"></span>
              </template>
            </span>
          </template>
          <span class="text-[10px] text-slate-400" x-show="!open">(click to expand)</span>
        </div>
        <div class="flex items-center gap-2">
          <button class="text-blue-600 hover:text-blue-800 text-xs font-semibold"
                  @click.stop="duplicateGroup(gIdx)"
                  title="Duplicate this group with all its columns + rules">📋 Duplicate</button>
          <button class="text-red-600 hover:text-red-800 text-xs font-semibold"
                  @click.stop="if(confirm('Delete this group?')) removeGroup(gIdx)">× Delete group</button>
        </div>
      </div>

      <div x-show="open" x-transition>
      <div class="rule-group-section">
        <div class="text-[11px] font-semibold text-slate-500 uppercase tracking-wide mb-1">
          Apply to columns (click to toggle)
        </div>
        <div class="target-chip-row">
          <template x-for="c in tableCatalog" :key="'g-'+gIdx+'-c-'+c.id">
            <span :class="'target-chip ' + (g.cols.includes(c.id) ? 'active' : '')"
                  @click="toggleGroupCol(gIdx, c.id)"
                  x-text="c.label"></span>
          </template>
        </div>
      </div>

      <div class="rule-group-section">
        <div class="text-[11px] font-semibold text-slate-500 uppercase tracking-wide mb-1">
          Rules (top-to-bottom · first match wins)
        </div>
        <div class="col-list" x-show="g.rules.length > 0">
          <template x-for="(r, rIdx) in g.rules" :key="'g-'+gIdx+'-r-'+rIdx">
            <div class="col-item" style="cursor:default;flex-wrap:wrap;">
              <span class="col-handle"
                    draggable="true"
                    @dragstart="ruleDragStart(gIdx, rIdx, $event)"
                    @dragover.prevent
                    @drop="ruleDrop(gIdx, rIdx)">⋮⋮</span>
              <span class="text-xs text-slate-500" style="min-width:30px;">if</span>
              {{-- Active-state filter: rule applies only when campaign is on/off/any.
                   Existing rules default to 'active' (Active only). --}}
              <select :value="r.active_state || 'active'"
                      @change="r.active_state = $event.target.value"
                      class="border border-slate-300 rounded px-1 py-0.5 text-xs"
                      style="max-width:130px;"
                      title="Apply rule only when campaign is in this state">
                <option value="active">⚡ Active only</option>
                <option value="off">⏸ Off only</option>
                <option value="any">✓ Any state</option>
              </select>
              {{-- Evaluate-against dropdown: "self" (default) or another column
                   sa same table. When set, the rule fires based on THAT column's
                   value sa same row, not yung cell's own value. --}}
              <select :value="r.compare_col || ''"
                      @change="r.compare_col = $event.target.value"
                      class="border border-slate-300 rounded px-1 py-0.5 text-xs"
                      style="max-width:160px;"
                      title="Which column's value to evaluate (Self = the cell's own value)">
                <option value="">Self (own value)</option>
                <template x-for="tc in tableCatalog" :key="'cmp-'+tc.id">
                  <option :value="tc.id" x-text="tc.label"></option>
                </template>
              </select>
              <span class="text-xs text-slate-500" x-text="(r.op === 'is_null' || r.op === 'is_not_null') ? '' : 'value'"></span>
              <select x-model="r.op" class="border border-slate-300 rounded px-1 py-0.5 text-xs">
                <option value=">=">≥</option>
                <option value=">">&gt;</option>
                <option value="=">=</option>
                <option value="<=">≤</option>
                <option value="<">&lt;</option>
                <option value="is_null">∅ is empty / null</option>
                <option value="is_not_null">✓ is not empty</option>
              </select>

              {{-- Value input / kind selector — HIDDEN for null-check ops
                   (is_null / is_not_null don't compare against any value). --}}
              <template x-if="r.op !== 'is_null' && r.op !== 'is_not_null'">
                <span class="inline-flex items-center gap-1" style="flex-wrap:wrap;">
                  <select :value="ruleValueKind(r)"
                          @change="setRuleValueKind(r, $event.target.value)"
                          class="border border-slate-300 rounded px-1 py-0.5 text-xs"
                          title="Choose: literal number, ref to Page Summary column, or formula">
                    <option value="literal">📐 Literal</option>
                    @if(!empty($allowRefValues))
                    <option value="ref">🔗 Ref → Page Summary</option>
                    @endif
                    <option value="formula">🧮 Formula</option>
                  </select>

                  <template x-if="ruleValueKind(r) === 'literal'">
                    <input type="number" step="0.01"
                           :value="r.value"
                           @input="r.value = ($event.target.value === '' ? 0 : parseFloat($event.target.value))"
                           class="border border-slate-300 rounded px-2 py-0.5 text-xs w-24 text-right">
                  </template>
                  <template x-if="ruleValueKind(r) === 'ref'">
                    <select :value="r.value && r.value.col ? r.value.col : ''"
                            @change="r.value = { type:'ref', table:'owner_private', col:$event.target.value }"
                            class="border border-slate-300 rounded px-1 py-0.5 text-xs"
                            style="max-width:180px;">
                      <option value="" disabled>— pick column —</option>
                      <template x-for="oc in ownerPrivateCatalog" :key="'ref-'+oc.id">
                        <option :value="oc.id" x-text="oc.label"></option>
                      </template>
                    </select>
                  </template>
                </span>
              </template>
              {{-- Formula input with [[column]] autocomplete. Hidden for null ops. --}}
              <template x-if="(r.op !== 'is_null' && r.op !== 'is_not_null') && ruleValueKind(r) === 'formula'">
                <span class="relative" style="display:inline-block;">
                  <input type="text"
                         :value="r.value && r.value.expr ? r.value.expr : ''"
                         @input="onFormulaInput(r, $event)"
                         @keydown.escape="closeAutocomplete()"
                         @keydown.enter.prevent="acceptFirstAutocomplete(r)"
                         @blur="setTimeout(() => closeAutocomplete(), 150)"
                         placeholder="e.g., [[rts]] + [[op:cpp]] - 1"
                         class="border border-slate-300 rounded px-2 py-0.5 text-xs"
                         style="width:280px;font-family:ui-monospace,monospace;">
                  {{-- Autocomplete dropdown — shown when user types double-brackets --}}
                  <div x-show="autocompleteOpen && autocompleteFor === r"
                       x-cloak
                       class="absolute z-50 bg-white border border-slate-300 shadow-lg rounded mt-1 max-h-48 overflow-auto"
                       style="min-width:240px;font-size:11px;">
                    <template x-for="(opt, idx) in autocompleteResults" :key="'ac-'+idx">
                      <div @mousedown.prevent="selectAutocomplete(r, opt)"
                           class="px-2 py-1 hover:bg-blue-50 cursor-pointer flex items-center gap-2"
                           :style="idx === 0 ? 'background:#eff6ff;' : ''">
                        <code class="text-[10px] text-slate-600" x-text="opt.token"></code>
                        <span class="text-slate-700" x-text="opt.label"></span>
                      </div>
                    </template>
                    <template x-if="autocompleteResults.length === 0">
                      <div class="px-2 py-1 text-slate-400 italic">No matches</div>
                    </template>
                  </div>
                </span>
              </template>

              <span class="text-xs text-slate-500">→ bg</span>
              <input type="color" x-model="r.bg" class="w-12 h-7 cursor-pointer border rounded">
              <input type="text" x-model="r.bg" maxlength="7"
                     class="border border-slate-300 rounded px-1 py-0.5 text-xs w-20 font-mono">
              {{-- Optional text color. Empty = inherit (black by default).
                   Click the swatch to override; click ✕ to clear back to default. --}}
              <span class="text-xs text-slate-500">font</span>
              <input type="color"
                     :value="r.color || '#111827'"
                     @input="r.color = $event.target.value"
                     class="w-12 h-7 cursor-pointer border rounded"
                     :title="r.color ? ('Custom: '+r.color) : 'Default (black) — click to override'">
              <button type="button" @click="r.color = ''"
                      class="text-[10px] text-slate-400 hover:text-slate-700"
                      style="background:none;border:none;padding:0 2px;cursor:pointer;"
                      x-show="r.color"
                      title="Reset font color to default (black)">✕</button>
              <label class="text-xs flex items-center gap-1">
                <input type="checkbox" x-model="r.bold"> bold
              </label>
              <input type="text" x-model="r.label" placeholder="label (optional)"
                     class="flex-1 border border-slate-300 rounded px-2 py-0.5 text-xs"
                     style="min-width:120px;"
                     maxlength="40">
              <span class="px-2 py-0.5 text-xs rounded"
                    :style="'background:'+r.bg+';color:'+(r.color || '#111827')+';'+(r.bold?'font-weight:700;':'')"
                    x-text="rulePreviewText(r)"></span>
              <button class="text-red-600 hover:text-red-800 text-sm"
                      @click="removeRule(gIdx, rIdx)" title="Remove rule">✕</button>
            </div>
          </template>
        </div>
        <div x-show="g.rules.length === 0"
             class="text-xs text-slate-400 italic px-3 py-3 text-center"
             style="border:1px dashed #cbd5e1;border-radius:6px;">
          Walang rules pa sa group na ito.
        </div>
        <div class="mt-2">
          <button class="reset-btn" @click="addRuleToGroup(gIdx)">+ Add rule to this group</button>
        </div>
      </div>
      </div> {{-- end x-show=open wrapper --}}
    </div>
  </template>
</div>
